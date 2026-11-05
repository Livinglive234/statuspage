# Status service

A small Python service that turns the [Status Pages plugin](../README.md) feed into public pages like `https://survival.example.com`. It reads the feed, pings each server (Server List Ping and Query), and renders an HTML page per hostname.

It only listens on `127.0.0.1`. A reverse proxy in front of it handles HTTPS. This guide covers that proxy for **nginx**, **Apache** and **Caddy**, the three webservers Pelican documents ([Pelican webserver config](https://pelican.dev/docs/panel/webserver-config)).

## What you need

- Python 3.8 or newer (standard library only, nothing to pip install).
- A machine that can reach your panel over HTTPS and your Wings nodes on the server ports. This can be the panel machine itself or a separate public host.
- Wildcard DNS: `*.example.com` pointing at the machine running the reverse proxy.
- A wildcard certificate for `*.example.com` (single label only, so `survival.example.com` works and `survival.mc.example.com` does not).
- The plugin's token (plugin **Settings** page).

## 1. Install the service

```
sudo mkdir -p /opt/livenet-status /etc/livenet-status
sudo cp statusd.py /opt/livenet-status/
sudo cp config.example.json /etc/livenet-status/config.json
sudo cp env.example /etc/livenet-status/env
sudo chmod 600 /etc/livenet-status/env
sudo cp livenet-status.service /etc/systemd/system/
```

Edit `/etc/livenet-status/config.json`:

| Key | Meaning |
|---|---|
| `panel_url` | Your panel's URL. |
| `domain` | The base domain of the status pages. |
| `nodes` | Maps the node names in the feed to an address this machine can reach, for example `{"wing1": "203.0.113.10"}`. A node missing here is shown offline. |
| `listen_host`, `listen_port` | Where the service listens. Keep `127.0.0.1`. |
| `feed_ttl`, `status_ttl`, `timeout` | Feed cache, status cache and ping timeout, in seconds. |

Put the token in `/etc/livenet-status/env` as `STATUSPAGE_TOKEN=...`, then start it:

```
sudo systemctl daemon-reload
sudo systemctl enable --now livenet-status
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: survival.example.com' http://127.0.0.1:8081/
```

You should get `200` for a hostname that has a status page and `404` for one that does not. Logs: `journalctl -u livenet-status`.

## 2. Certificate

Get a wildcard certificate for `*.example.com` with the DNS challenge (certbot with your DNS provider's plugin works). The examples below assume the certificate lives in `/etc/letsencrypt/live/statuspage-wildcard/`. Change the paths if yours differs.

If the certbot machine is not the proxy machine, copy `fullchain.pem` and `privkey.pem` over in a certbot deploy hook and reload the webserver afterwards.

## 3. Reverse proxy

Pick your webserver. Each example proxies every `*.example.com` name to the service, keeps the `Host` header, and redirects HTTP to HTTPS. Replace `example.com` with your domain.

If your panel is on the same machine, its exact name (`panel.example.com`) must still reach the panel and not the wildcard. Each webserver below handles that a little differently, noted in each section.

### nginx

Config: [`webservers/nginx.conf`](webservers/nginx.conf)

```
sudo cp webservers/nginx.conf /etc/nginx/sites-available/statuspage
sudo ln -s /etc/nginx/sites-available/statuspage /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

- Exact `server_name` values beat wildcards, so the panel is unaffected.
- `http2 on;` needs nginx 1.25.1 or newer. On older versions remove that line and change the listen line to `listen 443 ssl http2;`.
- Requests are rate limited to 5 per second per IP (burst 20) by `limit_req`.

### Apache

Config: [`webservers/apache.conf`](webservers/apache.conf)

```
sudo a2enmod proxy proxy_http ssl headers rewrite
sudo cp webservers/apache.conf /etc/apache2/sites-available/zz-statuspage.conf
sudo a2ensite zz-statuspage.conf
sudo apache2ctl configtest && sudo systemctl reload apache2
```

- Apache uses the first virtual host that matches. The panel's vhost has to load first, which is why the file is named `zz-`. A panel vhost that names `panel.example.com` exactly will win.
- Apache has no built-in per-IP rate limit. Consider `mod_evasive` or rate limiting in front of it.

### Caddy

Config: [`webservers/Caddyfile`](webservers/Caddyfile)

Add the block to `/etc/caddy/Caddyfile` next to your panel's block, then:

```
sudo caddy validate --config /etc/caddy/Caddyfile
sudo systemctl reload caddy
```

- Caddy picks the most specific site address, so `panel.example.com` keeps working.
- The example uses a certificate you already have. To let Caddy issue the wildcard certificate itself, you need a Caddy build that includes your DNS provider's module (see the comment in the file).
- Caddy has no built-in rate limiting. Use a rate-limit module or limit upstream.

### Other webservers

Anything that can reverse proxy works. Requirements:

1. Match `*.example.com` and forward to `http://127.0.0.1:8081`.
2. Pass the original `Host` header unchanged. The service uses it to decide which server's page to show.
3. Terminate HTTPS with the wildcard certificate.

## 4. Check it

```
curl -I https://survival.example.com
```

Expect `HTTP/2 200` (or `HTTP/1.1 200`). A hostname with no status page returns `404`.

## Notes

- **Velocity / BungeeCord behind PROXY protocol:** tick the PROXY protocol option on the status page entry in the panel so the service sends a PROXY v2 header when pinging.
- **Bedrock servers:** choose *Bedrock (modern)* for current versions (NetherNet): the service sends an unauthenticated `GET /v1/join` over TCP to the allocation port (or the entry's query port) and shows MOTD, version and player count. That TCP port must be reachable from the status host (Pelican normally publishes it); otherwise the page shows Offline. Choose *Bedrock Legacy* for servers before 1.21.60 / 26.60: a RakNet ping over UDP. Neither has a player list, plugin list or icon, nor PROXY protocol.
- **Player names and plugins** need `enable-query=true` on the Minecraft server.
- **Offline everywhere?** Check that `nodes` maps each node name and that this machine can reach the server ports.
- **Security:** the service never shows IPs, owners or tokens, escapes everything it displays, and sends strict CSP headers. Keep `listen_host` on `127.0.0.1` so only your reverse proxy can reach it.
- **Branding:** the page footer shows your panel's App Name (Admin > Settings). This needs plugin v1.0.5 or newer; with an older plugin it shows your domain instead.
