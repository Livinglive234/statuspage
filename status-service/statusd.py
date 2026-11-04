#!/usr/bin/env python3
"""LiveNet status page service.

Reads the server list from the Pelican status-page feed, pings each server
(Server List Ping over TCP, Query over UDP) and renders a public page per
subdomain. Standard library only.
"""
import html
import json
import logging
import os
import re
import socket
import struct
import sys
import threading
import time
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

CONFIG_PATH = os.environ.get("STATUSD_CONFIG", "/etc/livenet-status/config.json")
log = logging.getLogger("statusd")

# ---------------------------------------------------------------- config

def load_config():
    with open(CONFIG_PATH) as f:
        cfg = json.load(f)
    cfg.setdefault("listen_host", "127.0.0.1")
    cfg.setdefault("listen_port", 8081)
    cfg.setdefault("domain", "example.com")
    cfg.setdefault("feed_ttl", 30)
    cfg.setdefault("status_ttl", 15)
    cfg.setdefault("timeout", 3)
    cfg.setdefault("nodes", {})
    token = os.environ.get("STATUSPAGE_TOKEN") or cfg.get("token", "")
    if not token:
        sys.exit("No token: set STATUSPAGE_TOKEN in the environment file")
    cfg["token"] = token
    return cfg

# ---------------------------------------------------------------- feed

class Feed:
    def __init__(self, cfg):
        self.cfg = cfg
        self.lock = threading.Lock()
        self.fetched = 0.0
        self.servers = {}
        self.app_name = ""

    def get(self, hostname):
        now = time.time()
        if now - self.fetched > self.cfg["feed_ttl"]:
            with self.lock:
                if time.time() - self.fetched > self.cfg["feed_ttl"]:
                    self._refresh()
        return self.servers.get(hostname)

    def _refresh(self):
        req = urllib.request.Request(
            self.cfg["panel_url"].rstrip("/") + "/api/statuspage/feed",
            headers={
                "Authorization": "Bearer " + self.cfg["token"],
                "Accept": "application/json",
                # urllib's default user agent is blocked by many WAFs (403)
                "User-Agent": "livenet-status/1.0",
            },
        )
        try:
            with urllib.request.urlopen(req, timeout=10) as r:
                data = json.load(r)
            servers = {}
            for s in data.get("servers", []):
                names = s.get("hostnames") or ([f'{s["subdomain"]}.{self.cfg["domain"]}'] if s.get("subdomain") else [])
                for name in names:
                    servers[str(name).lower()] = s
            self.servers = servers
            self.app_name = str(data.get("app_name") or "")[:100]
            self.fetched = time.time()
        except Exception as e:  # keep serving the last good list
            log.warning("feed refresh failed: %s", e)
            # retry soon, but not on every request
            self.fetched = time.time() - self.cfg["feed_ttl"] + 5

# ---------------------------------------------------------------- minecraft

def _varint(n):
    n &= 0xFFFFFFFF
    out = b""
    while True:
        b = n & 0x7F
        n >>= 7
        out += bytes([b | (0x80 if n else 0)])
        if not n:
            return out

def _read_exact(sock, n):
    buf = b""
    while len(buf) < n:
        chunk = sock.recv(n - len(buf))
        if not chunk:
            raise ConnectionError("closed")
        buf += chunk
    return buf

def _read_varint(sock):
    n = shift = 0
    while True:
        b = _read_exact(sock, 1)[0]
        n |= (b & 0x7F) << shift
        if not b & 0x80:
            return n
        shift += 7
        if shift > 35:
            raise ValueError("varint too long")

PROXY_V2_SIG = b"\r\n\r\n\x00\r\nQUIT\n"

def _proxy_v2_header(sock):
    """PROXY protocol v2 header (IPv4), for servers such as Velocity with haproxy-protocol on."""
    src_ip, src_port = sock.getsockname()[:2]
    dst_ip, dst_port = sock.getpeername()[:2]
    addrs = socket.inet_aton(src_ip) + socket.inet_aton(dst_ip) + struct.pack(">HH", src_port, dst_port)
    return PROXY_V2_SIG + b"\x21\x11" + struct.pack(">H", len(addrs)) + addrs

def server_list_ping(host, port, timeout, proxy_protocol=False, server_name=None):
    with socket.create_connection((host, port), timeout=timeout) as s:
        s.settimeout(timeout)
        if proxy_protocol:
            s.sendall(_proxy_v2_header(s))
        # Send the public hostname a real player would use: proxies like Velocity route and answer by it
        hb = (server_name or host).encode()
        data = b"\x00" + _varint(765) + _varint(len(hb)) + hb + struct.pack(">H", port) + _varint(1)
        s.sendall(_varint(len(data)) + data + b"\x01\x00")
        length = _read_varint(s)
        if length > 4 * 1024 * 1024:
            raise ValueError("response too large")
        body = _read_exact(s, length)
    # body: varint packet id, varint string length, json
    i = 0
    while body[i] & 0x80:
        i += 1
    i += 1  # packet id
    n = shift = 0
    while True:
        b = body[i]; i += 1
        n |= (b & 0x7F) << shift
        if not b & 0x80:
            break
        shift += 7
    return json.loads(body[i:i + n].decode("utf-8", "replace"))

RAKNET_MAGIC = bytes.fromhex("00ffff00fefefefefdfdfdfd12345678")

def bedrock_ping(host, port, timeout):
    """RakNet unconnected ping (UDP) as sent by the Bedrock server list. Returns the parsed pong."""
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as s:
        s.settimeout(timeout)
        s.connect((host, port))
        s.send(b"\x01" + struct.pack(">q", int(time.time() * 1000) & 0x7FFFFFFFFFFFFFFF)
               + RAKNET_MAGIC + struct.pack(">q", 0x4C6976654E657421))
        resp = s.recv(4096)
    # 0x1c, time(8), server guid(8), magic(16), uint16 length, string
    if len(resp) < 35 or resp[0] != 0x1C or resp[17:33] != RAKNET_MAGIC:
        raise ValueError("not a RakNet pong")
    n = struct.unpack(">H", resp[33:35])[0]
    f = resp[35:35 + n].decode("utf-8", "replace").split(";")
    if len(f) < 6 or f[0] not in ("MCPE", "MCEE"):
        raise ValueError("unexpected pong")
    def num(i):
        try:
            return int(f[i])
        except (IndexError, ValueError):
            return 0
    return {"motd": f[1], "protocol": num(2), "version": f[3], "online": num(4), "max": num(5),
            "level": f[7] if len(f) > 7 else "", "gamemode": f[8] if len(f) > 8 else ""}

def query(host, port, timeout):
    """Full-stat Query. Returns dict with players (list) and plugins (list), or raises."""
    sid = 0x01020304 & 0x0F0F0F0F
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as s:
        s.settimeout(timeout)
        s.connect((host, port))
        s.send(b"\xfe\xfd\x09" + struct.pack(">i", sid))
        resp = s.recv(2048)
        token = int(resp[5:].split(b"\x00")[0])
        s.send(b"\xfe\xfd\x00" + struct.pack(">i", sid) + struct.pack(">i", token) + b"\x00\x00\x00\x00")
        resp = s.recv(65535)
    body = resp[5 + 11:]  # type + session id + "splitnum\0\x80\0"
    kv_part, _, players_part = body.partition(b"\x00\x01player_\x00\x00")
    items = kv_part.split(b"\x00")
    kv = {items[i].decode("utf-8", "replace"): items[i + 1].decode("utf-8", "replace")
          for i in range(0, len(items) - 1, 2)}
    players = [p.decode("utf-8", "replace") for p in players_part.split(b"\x00") if p]
    plugins = []
    raw = kv.get("plugins", "")
    if ":" in raw:
        software, _, plist = raw.partition(":")
        plugins = [p.strip() for p in plist.split(";") if p.strip()]
        kv["software"] = software.strip()
    return {"players": players, "plugins": plugins, "kv": kv}

# ---------------------------------------------------------------- motd

COLORS = {
    "0": "#000000", "1": "#0000aa", "2": "#00aa00", "3": "#00aaaa", "4": "#aa0000",
    "5": "#aa00aa", "6": "#ffaa00", "7": "#aaaaaa", "8": "#555555", "9": "#5555ff",
    "a": "#55ff55", "b": "#55ffff", "c": "#ff5555", "d": "#ff55ff", "e": "#ffff55", "f": "#ffffff",
}
NAMED = {
    "black": "0", "dark_blue": "1", "dark_green": "2", "dark_aqua": "3", "dark_red": "4",
    "dark_purple": "5", "gold": "6", "gray": "7", "dark_gray": "8", "blue": "9",
    "green": "a", "aqua": "b", "red": "c", "light_purple": "d", "yellow": "e", "white": "f",
}
HEX = re.compile(r"^#[0-9a-fA-F]{6}$")

def _flatten(desc, color=None, out=None):
    """Chat component -> list of (text, color)."""
    out = [] if out is None else out
    if isinstance(desc, str):
        out.append((desc, color))
    elif isinstance(desc, dict):
        c = desc.get("color", color)
        if "text" in desc:
            out.append((str(desc["text"]), c))
        for extra in desc.get("extra", []) or []:
            _flatten(extra, c, out)
    elif isinstance(desc, list):
        for d in desc:
            _flatten(d, color, out)
    return out

def render_motd(desc):
    parts = []
    for text, color in _flatten(desc):
        cur = None
        if color in NAMED:
            cur = COLORS[NAMED[color]]
        elif isinstance(color, str) and HEX.match(color):
            cur = color
        buf = ""
        def flush():
            nonlocal buf
            if buf:
                style = f' style="color:{cur}"' if cur else ""
                parts.append(f"<span{style}>{html.escape(buf)}</span>")
                buf = ""
        i = 0
        while i < len(text):
            ch = text[i]
            if ch == "§" and i + 1 < len(text):
                code = text[i + 1].lower()
                flush()
                if code in COLORS:
                    cur = COLORS[code]
                elif code == "r":
                    cur = None
                i += 2
                continue
            buf += ch
            i += 1
        flush()
    return "".join(parts)

# ---------------------------------------------------------------- status

class Checker:
    def __init__(self, cfg):
        self.cfg = cfg
        self.cache = {}
        self.locks = {}
        self.guard = threading.Lock()

    def check(self, entry, hostname):
        key = hostname
        with self.guard:
            lock = self.locks.setdefault(key, threading.Lock())
        with lock:  # one probe per server at a time, others reuse its result
            hit = self.cache.get(key)
            if hit and time.time() - hit[0] < self.cfg["status_ttl"]:
                return hit[1]
            result = self._probe(entry, hostname)
            self.cache[key] = (time.time(), result)
            return result

    def _probe(self, entry, hostname):
        ip = self.cfg["nodes"].get(entry.get("node"))
        if not ip:
            log.warning("no address configured for node %r", entry.get("node"))
            return {"online": False}
        t = self.cfg["timeout"]
        port = int(entry["port"])
        if entry.get("edition") == "bedrock":
            try:
                b = bedrock_ping(ip, int(entry.get("query_port") or port), t)
            except Exception as e:
                log.info("%s offline (bedrock): %s", hostname, e)
                return {"online": False}
            return {
                "online": True,
                "version": ("Bedrock " + b["version"]).strip(),
                "online_players": b["online"],
                "max_players": b["max"],
                "sample": [],
                "motd": b["motd"],
                "favicon": "",
                "player_names": None,   # Bedrock does not expose these
                "plugins": None,
            }
        try:
            proxied = bool(entry.get("proxy_protocol"))
            st = server_list_ping(ip, port, t, proxy_protocol=proxied,
                                  server_name=hostname)
        except Exception as e:
            log.info("%s offline: %s", hostname, e)
            return {"online": False}
        players = st.get("players") or {}
        result = {
            "online": True,
            "version": (st.get("version") or {}).get("name", ""),
            "online_players": int(players.get("online", 0) or 0),
            "max_players": int(players.get("max", 0) or 0),
            "sample": [p.get("name", "") for p in (players.get("sample") or []) if isinstance(p, dict)],
            "motd": st.get("description", ""),
            "favicon": st.get("favicon", ""),
            "player_names": None,
            "plugins": None,
        }
        show = entry.get("show", {})
        # Query is not available through a proxy such as Velocity
        if (show.get("player_names") or show.get("plugins")) and not proxied:
            try:
                q = query(ip, int(entry.get("query_port") or port), t)
                result["player_names"] = q["players"]
                result["plugins"] = q["plugins"]
            except Exception as e:
                log.info("%s query failed: %s", hostname, e)
                result["player_names"] = result["sample"] or None
        return result

# ---------------------------------------------------------------- page

PAGE = """<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="refresh" content="30">
<title>{title}</title>
<style>
:root{{--bg:#0f1318;--card:#171d25;--line:#252e3a;--text:#e8edf3;--mute:#8b98a8;--accent:#52a2ff;--ok:#43c463;--bad:#e5534b}}
@media (prefers-color-scheme:light){{:root{{--bg:#eef2f7;--card:#fff;--line:#dbe3ec;--text:#18202a;--mute:#5d6b7c;--accent:#2f7fe0;--ok:#1f9d44;--bad:#cf3b33}}}}
*{{box-sizing:border-box}}
body{{margin:0;background:var(--bg);color:var(--text);font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;padding:24px 16px}}
main{{max-width:640px;margin:0 auto}}
.card{{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:20px;margin-bottom:16px}}
.head{{display:flex;gap:16px;align-items:center}}
.icon{{width:64px;height:64px;border-radius:10px;image-rendering:pixelated;flex:none;background:var(--line)}}
h1{{font-size:1.4rem;margin:0 0 4px;overflow-wrap:anywhere}}
.badge{{display:inline-flex;align-items:center;gap:6px;font-weight:600;font-size:.9rem}}
.dot{{width:10px;height:10px;border-radius:50%;background:var(--bad)}}
.on .dot{{background:var(--ok);box-shadow:0 0 0 4px color-mix(in srgb,var(--ok) 25%,transparent)}}
.on{{color:var(--ok)}} .off{{color:var(--bad)}}
.motd{{margin-top:16px;padding:12px 14px;border-radius:10px;background:#111;color:#aaa;font-family:ui-monospace,Menlo,Consolas,monospace;white-space:pre-wrap;overflow-wrap:anywhere}}
.stats{{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-top:16px}}
.stat{{background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:10px 14px}}
.stat small{{display:block;color:var(--mute);font-size:.75rem;text-transform:uppercase;letter-spacing:.05em}}
.stat b{{font-size:1.15rem;overflow-wrap:anywhere}}
h2{{font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;color:var(--mute);margin:0 0 12px}}
.chips{{display:flex;flex-wrap:wrap;gap:8px}}
.chip{{background:var(--bg);border:1px solid var(--line);border-radius:999px;padding:3px 12px;font-size:.9rem;overflow-wrap:anywhere}}
.icon-bg{{fill:var(--line)}}.icon-fg{{stroke:var(--accent)}}.icon-dot{{fill:var(--accent)}}
footer{{text-align:center;color:var(--mute);font-size:.8rem;margin-top:8px}}
footer a{{color:var(--accent);text-decoration:none}}
</style></head><body><main>
{body}
<footer>Hosted by <a href="{link}">{site}</a> &middot; refreshes every 30 seconds</footer>
</main></body></html>"""

# Generic placeholder for servers without an icon: a small server-rack glyph in the accent color.
PLACEHOLDER_ICON = (
    '<svg class="icon" viewBox="0 0 64 64" role="img" aria-label="Server icon">'
    '<rect class="icon-bg" width="64" height="64" rx="10"/>'
    '<g class="icon-fg" fill="none" stroke-width="3" stroke-linejoin="round">'
    '<rect x="13" y="11" width="38" height="12" rx="3"/>'
    '<rect x="13" y="26" width="38" height="12" rx="3"/>'
    '<rect x="13" y="41" width="38" height="12" rx="3"/>'
    '</g>'
    '<g class="icon-dot"><circle cx="21" cy="17" r="2"/><circle cx="21" cy="32" r="2"/><circle cx="21" cy="47" r="2"/></g>'
    '</svg>'
)

FAVICON = re.compile(r"^data:image/png;base64,[A-Za-z0-9+/=]+$")

def render(entry, st, hostname, link, site):
    show = entry.get("show", {})
    # The page is headed by its public address, not the panel's server name
    name = html.escape(hostname)
    icon = ""
    fav = st.get("favicon", "") if st.get("online") else ""
    if isinstance(fav, str) and len(fav) < 200000 and FAVICON.match(fav):
        icon = f'<img class="icon" alt="" src="{fav}">'
    else:
        icon = PLACEHOLDER_ICON
    if st["online"]:
        badge = '<span class="badge on"><span class="dot"></span>Online</span>'
    else:
        badge = '<span class="badge off"><span class="dot"></span>Offline</span>'
    body = [f'<div class="card"><div class="head">{icon}<div><h1>{name}</h1>{badge}</div></div>']
    if st["online"] and show.get("motd"):
        m = render_motd(st["motd"])
        if m.strip():
            body.append(f'<div class="motd">{m}</div>')
    if st["online"]:
        stats = []
        if show.get("players"):
            stats.append(("Players", f'{st["online_players"]} / {st["max_players"]}'))
        if show.get("version") and st["version"]:
            stats.append(("Version", html.escape(re.sub(r"§.", "", st["version"]))))
        if stats:
            body.append('<div class="stats">' + "".join(
                f'<div class="stat"><small>{k}</small><b>{v}</b></div>' for k, v in stats) + "</div>")
    else:
        body.append('<p style="color:var(--mute);margin:16px 0 0">This server is currently offline.</p>')
    body.append("</div>")
    if st["online"] and show.get("player_names") and st.get("player_names"):
        chips = "".join(f'<span class="chip">{html.escape(p)}</span>' for p in st["player_names"][:100])
        body.append(f'<div class="card"><h2>Players online</h2><div class="chips">{chips}</div></div>')
    if st["online"] and show.get("plugins") and st.get("plugins"):
        chips = "".join(f'<span class="chip">{html.escape(p)}</span>' for p in st["plugins"][:200])
        body.append(f'<div class="card"><h2>Plugins ({len(st["plugins"])})</h2><div class="chips">{chips}</div></div>')
    return PAGE.format(title=f"{name} &middot; Status", body="\n".join(body), link=html.escape(link), site=html.escape(site)).encode()

def not_found(link, site):
  return PAGE.format(title="Not found", link=html.escape(link), site=html.escape(site), body=(
    '<div class="card"><h1>Page not found</h1><p style="color:var(--mute)">There is no status page at this address.</p></div>'
  )).encode()

# ---------------------------------------------------------------- http

HEADERS = {
    "Content-Security-Policy": "default-src 'none'; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
    "X-Content-Type-Options": "nosniff",
    "Referrer-Policy": "no-referrer",
    "Cache-Control": "no-store",
}
HOST = re.compile(r"^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$")

def make_handler(cfg, feed, checker):
    class H(BaseHTTPRequestHandler):
        server_version = "livenet-status"
        sys_version = ""

        def log_message(self, fmt, *args):
            log.debug(fmt, *args)

        def _send(self, code, body, ctype="text/html; charset=utf-8"):
            self.send_response(code)
            self.send_header("Content-Type", ctype)
            self.send_header("Content-Length", str(len(body)))
            for k, v in HEADERS.items():
                self.send_header(k, v)
            self.end_headers()
            if self.command != "HEAD":
                self.wfile.write(body)

        do_HEAD = None

        def do_GET(self):
            if self.path == "/healthz":
                return self._send(200, b"ok", "text/plain")
            host = (self.headers.get("Host") or "").split(":")[0].lower()
            if len(host) > 253 or not HOST.match(host):
                return self._send(404, not_found(cfg["panel_url"], feed.app_name or cfg["domain"]))
            entry = feed.get(host)
            site = feed.app_name or cfg["domain"]
            if not entry:
                return self._send(404, not_found(cfg["panel_url"], site))
            try:
                st = checker.check(entry, host)
                self._send(200, render(entry, st, host, cfg["panel_url"], site))
            except Exception:
                log.exception("render failed for %s", host)
                self._send(500, b"error", "text/plain")

    H.do_HEAD = H.do_GET
    return H

def main():
    logging.basicConfig(level=os.environ.get("LOG_LEVEL", "INFO"),
                        format="%(asctime)s %(levelname)s %(message)s")
    cfg = load_config()
    feed = Feed(cfg)
    checker = Checker(cfg)
    srv = ThreadingHTTPServer((cfg["listen_host"], cfg["listen_port"]), make_handler(cfg, feed, checker))
    srv.daemon_threads = True
    log.info("listening on %s:%s", cfg["listen_host"], cfg["listen_port"])
    srv.serve_forever()

if __name__ == "__main__":
    main()
