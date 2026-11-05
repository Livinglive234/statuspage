# Status Pages

> ## ⚠️ This plugin does not work on its own
>
> Installing the plugin only adds the settings and a token-protected feed inside your panel. **The public status pages are served by a separate status service that you must install and run yourself**, with a reverse proxy, a wildcard DNS record and a wildcard HTTPS certificate in front of it.
>
> Without that extra setup, nobody can see a status page. Follow the [status service README](https://github.com/Livinglive234/statuspage/blob/main/status-service/README.md) after installing the plugin.

Give each Minecraft server an optional public status page, with online state, version, players and (optionally) plugins.

This plugin manages which servers have a status page, what each page is allowed to show, and a token-protected feed with that information. A separate status service reads the feed and builds the public pages.

## Setup

1. Open **Settings** on this plugin, click the refresh icon to generate a token, then save. This writes `STATUSPAGE_TOKEN` to `.env` and clears the config cache.
2. In the same Settings screen, choose what status pages show **by default**: version, player count, player names, MOTD and plugin list.
3. Give servers a public address. If the **Subdomains** plugin is installed and enabled, every server with a subdomain created there gets a status page automatically, and renaming the subdomain updates the page with no extra step. You can also add a manual entry under **Status Pages** in the admin sidebar to give a server an extra address (or an address when Subdomains isn't installed).
4. Give the same token to your status service. Install steps, including the reverse-proxy setup for nginx, Apache and Caddy, are in the [status service README](https://github.com/Livinglive234/statuspage/blob/main/status-service/README.md).

**Limitation:** Subdomains-plugin domains that use a prefix (for example `name.mc.example.com`) are not supported. Those addresses have two labels before the base domain, and a standard wildcard certificate (`*.example.com`) only covers one, so their status pages won't work over HTTPS.

If no token is set, the feed is disabled and returns 503.

## Who can change what

- **Admins** can add manual entries, set the query port, and change everything.
- **Server owners** get a **Status Page** item in their server's sidebar once the server has a public address. There they can see their page's address, turn the page on or off, and choose Show, Hide or Use default for each aspect, and say whether the server is behind a proxy that needs the PROXY protocol header. Changes save automatically. Admins always see this item.

## Status Pages entries

| Field | Meaning |
|---|---|
| Server | The server to publish. One entry per server. |
| Subdomain | Optional. An extra address under your base domain. Lowercase letters, numbers and hyphens. Must be unique and not reserved (www, panel, api, mail, ssh and similar). |
| Query port | Query port of the server. Empty means the primary allocation port. |
| Edition | Java (default), Bedrock (modern) or Bedrock Legacy (before 1.21.60 / 26.60). Modern Bedrock (NetherNet) is checked with an unauthenticated HTTP request to the server's TCP port (that TCP port must be reachable from the status service). Legacy Bedrock is pinged over UDP (RakNet). Both show MOTD, version and player count; Query port is the port to check if it differs from the primary allocation. Owners can change this too. |
| Server uses PROXY protocol | For proxies like Velocity with `haproxy-protocol` enabled, which reject connections without the header. Leave off for normal servers. Owners can also change this on their server's Status Page. |
| What the page shows | Per aspect: Use default, Show or Hide. "Use default" follows the plugin Settings. |
| Enabled | Turn the page off without deleting the entry. |

Servers with a Subdomains-plugin address need no entry. Their settings are created the first time an owner or admin saves them, and until then they use the defaults.

Deleting a server removes its entry automatically.

## Feed

`GET /api/statuspage/feed`

Header: `Authorization: Bearer <token>`

```json
{
  "app_name": "My Hosting",
  "servers": [
    {
      "hostnames": ["survival.example.com"],
      "node": "wing1",
      "port": 25565,
      "edition": "java",
      "query_port": null,
      "proxy_protocol": false,
      "show": {
        "version": true,
        "players": true,
        "player_names": false,
        "motd": true,
        "plugins": false
      }
    }
  ]
}
```

- `hostnames` are the public addresses of the server (manual subdomain plus Subdomains-plugin addresses under the configured base domain). One server can have several.
- `app_name` is the panel's App Name, which the status service shows in its page footer.
- `port` is the server's primary allocation port.
- `edition` is `java`, `bedrock` (modern, NetherNet) or `bedrock_legacy` (RakNet). Older services ignore the fields and treat every server as Java.
- `node` is the name of the node the server runs on.
- `proxy_protocol` is `true` when the server needs a PROXY protocol v2 header before the normal handshake; a status service must send one, or the server will drop the connection. Query is generally not available on such servers.
- `show` is already resolved (the server's own choice, otherwise the default). A status service must not display anything marked `false`.
- The feed never includes IP addresses, owners, UUIDs, tokens or the panel's server names.
- Responses are `Cache-Control: no-store` and rate limited to 60 requests per minute.

Responses: `200` ok, `401` bad or missing token, `503` no token configured.

Test it from a terminal:

```
curl -H "Accept: application/json" -H "Authorization: Bearer <token>" https://your-panel/api/statuspage/feed
```

## Notes for status service authors

- Player names and the plugin list come from the Minecraft Query protocol, which needs `enable-query=true` in `server.properties` on each server.
- Escape every value you display (server name, MOTD, player and plugin names) before putting it in a page.
- Keep the token secret. To rotate it, generate a new one in Settings and update your status service.
