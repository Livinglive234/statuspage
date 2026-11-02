# Status Pages

Give each Minecraft server an optional public status page, with online state, version, players and (optionally) plugins.

This plugin manages which servers have a status page, what each page is allowed to show, and a token-protected feed with that information. A separate status service reads the feed and builds the public pages.

## Setup

1. Open **Settings** on this plugin, click the refresh icon to generate a token, then save. This writes `STATUSPAGE_TOKEN` to `.env` and clears the config cache.
2. In the same Settings screen, choose what status pages show **by default**: version, player count, player names, MOTD and plugin list.
3. Open **Status Pages** in the admin sidebar and add an entry for each server by giving it a subdomain.
4. Give the same token to your status service.

If no token is set, the feed is disabled and returns 503.

## Who can change what

- **Admins** create entries, set the subdomain and query port, and can change everything.
- **Server owners** get a **Status Page** item in their server's sidebar once an admin has given the server a subdomain. There they can see their page's address, turn the page on or off, and choose Show, Hide or Use default for each aspect, and say whether the server is behind a proxy that needs the PROXY protocol header. Changes save automatically. Admins always see this item.

## Status Pages entries

| Field | Meaning |
|---|---|
| Server | The server to publish. One entry per server. |
| Subdomain | Lowercase letters, numbers and hyphens. Must be unique and not reserved (www, panel, api, mail, ssh and similar). |
| Query port | Query port of the server. Empty means the primary allocation port. |
| Server uses PROXY protocol | For proxies like Velocity with `haproxy-protocol` enabled, which reject connections without the header. Leave off for normal servers. Owners can also change this on their server's Status Page. |
| What the page shows | Per aspect: Use default, Show or Hide. "Use default" follows the plugin Settings. |
| Enabled | Turn the page off without deleting the entry. |

Deleting a server removes its entry automatically.

## Feed

`GET /api/statuspage/feed`

Header: `Authorization: Bearer <token>`

```json
{
  "servers": [
    {
      "subdomain": "survival",
      "name": "Survival",
      "node": "wing1",
      "port": 25565,
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

- `port` is the server's primary allocation port.
- `node` is the name of the node the server runs on.
- `proxy_protocol` is `true` when the server needs a PROXY protocol v2 header before the normal handshake; a status service must send one, or the server will drop the connection. Query is generally not available on such servers.
- `show` is already resolved (the server's own choice, otherwise the default). A status service must not display anything marked `false`.
- The feed never includes IP addresses, owners, UUIDs or tokens.
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
