# Claude.ai as a remote MCP client

This document covers **every step in the Claude.ai web UI** required to test the AI Suite MCP server end-to-end with Claude.ai as the frontend.

Claude.ai is hosted by Anthropic — there is no local client to install. In return the AI Suite MCP server has to be **publicly reachable over HTTPS with a CA-trusted certificate**, so Anthropic's connector backend can reach it. This is not covered here. For background on the connector itself refer to the official docs:

- **Claude.ai custom connectors** — [Getting started with custom connectors using remote MCP](https://support.anthropic.com/en/articles/11175166-getting-started-with-custom-connectors-using-remote-mcp)
- **Model Context Protocol** — [Specification](https://modelcontextprotocol.io/), [Anthropic MCP overview](https://docs.anthropic.com/en/docs/agents-and-tools/mcp)

## Conventions used in this guide

- **`<typo3-url>`** — base URL of the TYPO3 instance that has the AI Suite MCP extension installed and reachable from the public internet (e.g. `https://typo3.example.com`). Must resolve to a CA-trusted HTTPS endpoint — Anthropic cannot reach `localhost`, `*.ddev.site`, IP allowlisted hosts, or self-signed certificates.
- **Claude.ai plan:** custom connectors require a paid plan (Pro, Team, or Enterprise). The free tier does not expose the connector configuration UI.

## Service URLs

| Service | URL |
|---|---|
| AI Suite MCP endpoint | `<typo3-url>/aisuite-mcp` |
| AI Suite OAuth discovery | `<typo3-url>/.well-known/oauth-authorization-server` |

## Prerequisites before adding the connector

1. **Enable the AI Suite MCP extension** — TYPO3 backend → *Admin Tools → Settings → Extension Configuration → `ai_suite_mcp`*:

   | Setting | Value |
   |---|---|
   | `enableMcp` | `1` |
   | `mcpAllowedRedirectUris` | `https://claude.ai/api/mcp/auth_callback` |
   | `mcpAllowedOrigins` | `https://claude.ai` |
   | `mcpAllowedClientIds` | leave empty (otherwise the DCR-generated client_id has to be added manually) |

2. **BE user group permissions** — see [Required BE-group permissions](../README.md#required-be-group-permissions) in the main README for the full list of feature flags to grant.

3. **The TYPO3 host is publicly reachable on HTTPS with a CA-trusted certificate** — verify from a machine outside your network:
   ```bash
   curl -sS <typo3-url>/aisuite-mcp/health
   curl -sS <typo3-url>/.well-known/oauth-authorization-server
   ```
   Both must respond `200`. If either is blocked by IP allowlist, basic auth, or a self-signed cert, Claude.ai will silently fail to connect.

4. **The `Authorization` header reaches PHP** — on Apache + mod_php / FCGI the header is often stripped before it reaches the application, which causes silent 401 loops after a successful OAuth dance. TYPO3's default `.htaccess` ships the required rewrite, but verify it has not been removed (the exact rule is in [Common troubleshooting](../README.md#common-troubleshooting) in the main README).

## Step 1 — Open the Claude.ai connector settings

1. Sign in to `https://claude.ai` with a Pro / Team / Enterprise account.
2. Top right, click your **profile icon** → **Settings** → **Connectors** *(label may vary; on some plans it is reached via "Profile → Feature preview → Custom connectors")*.

## Step 2 — Add the MCP connector

1. Click **Add custom connector** (or **+ Add connector**).
2. Enter the configuration:

   | Field | Value |
   |---|---|
   | **Name** | e.g. `AI Suite` |
   | **Server URL** | `<typo3-url>/aisuite-mcp` *(use the **root URL only** — site prefixes like `<typo3-url>/<site>/aisuite-mcp` return 404, see Troubleshooting)* |

3. Click **Connect** — Claude.ai performs OAuth Dynamic Client Registration against `<typo3-url>/aisuite-mcp/oauth/register`, then opens an authorization popup pointing at `<typo3-url>/aisuite-mcp/oauth/authorize?...`.
4. If you are not yet logged in: TYPO3 backend login.
5. The **consent screen** lists the requested scopes (`mcp:read`, `mcp:write`, `mcp:generate`, `mcp:translate`, `mcp:image`, `mcp:workflow`, `mcp:easy-language`, `mcp:glossary`, `mcp:manage`) → confirm.
6. Redirect back to Claude.ai → token is stored. Connector status: *"Connected"*.

## Step 3 — Activate the connector per chat

Custom connectors are **not enabled in every chat by default** — they have to be turned on per conversation:

1. Start a new chat.
2. Click the **Search & tools** button (or the connector / paperclip icon) below the chat input.
3. In the popup find **AI Suite** and toggle it on.
4. Only now does Claude.ai include the tool definitions in the model's context.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| **Connect** does nothing / generic OAuth error in Claude.ai, with no entry in the TYPO3 log | The MCP server is not publicly reachable from Anthropic's network, or TLS is not CA-trusted (self-signed, expired, hostname mismatch) | Verify from an external host that `<typo3-url>/.well-known/oauth-authorization-server` and `<typo3-url>/aisuite-mcp/health` both return `200` over HTTPS with a publicly trusted certificate |
| Model says *"I have no access to MCP"* even though the connector shows as connected | Connector isn't enabled **per chat** | Step 3 above: the **Search & tools** menu under the chat input → toggle AI Suite |

For state-gate, RateLimiter DI-cache, Apache `Authorization`-header strip and empty-tools-list issues that affect any client, see [Common troubleshooting](../README.md#common-troubleshooting) in the main README.

## Live logs while debugging

- **Claude.ai** — no logs accessible (Anthropic-hosted). The browser DevTools network tab can show the OAuth redirect chain but stops at Anthropic's backend boundary.
- **AI Suite MCP** — TYPO3 exception pages are returned directly in the HTTP response. Stack traces also land in `/var/log/` where applicable.
- **Webserver access log** — invaluable for Claude.ai issues. Watch for `404` on `/aisuite-mcp` (site-prefix problem) or repeated `401` on `/aisuite-mcp` (Authorization-header problem). A `200` on `/aisuite-mcp/oauth/token` should immediately be followed by a `200` on `POST /aisuite-mcp` — anything else points at one of the entries above.

## Persistence notes

- Client credentials and OAuth tokens are stored in the AI Suite MCP database tables (`tx_aisuite_oauth_codes`, `tx_aisuite_oauth_tokens`, `tx_aisuite_oauth_consents`). Access-token lifetime is controlled by `mcpTokenLifetimeDays` in the extension configuration (default `30`); refresh tokens extend that automatically as long as the connector stays in use.
- The Claude.ai-side state (which connector is configured per user, OAuth refresh-token cache) lives in each user's Claude.ai account and is not under your control.
- Revoking access can be done from either side — in Claude.ai by removing the connector, or in the TYPO3 backend via the **MCP dashboard → Revoke Token**.
