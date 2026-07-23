# ChatGPT as a remote MCP client

This document covers **every step in the ChatGPT web UI** required to test the AI Suite MCP server end-to-end with ChatGPT as the frontend.

ChatGPT is hosted by OpenAI — there is no local client to install. In return the AI Suite MCP server has to be **publicly reachable over HTTPS with a CA-trusted certificate**, so OpenAI's connector backend can reach it. This is not covered here. For background on the connector itself refer to the official docs:

- **ChatGPT custom connectors** — [Connectors in ChatGPT (OpenAI Help Center)](https://help.openai.com/en/articles/11487775-connectors-in-chatgpt)
- **Model Context Protocol** — [Specification](https://modelcontextprotocol.io/), [OpenAI MCP overview](https://platform.openai.com/docs/mcp)

## Conventions used in this guide

- **`<typo3-url>`** — base URL of the TYPO3 instance that has the AI Suite MCP extension installed and reachable from the public internet (e.g. `https://typo3.example.com`). Must resolve to a CA-trusted HTTPS endpoint — OpenAI cannot reach `localhost`, `*.ddev.site`, IP-allowlisted hosts, or self-signed certificates.
- **ChatGPT plan:** custom connectors require a paid plan (Plus, Pro, Team, Business, or Enterprise). The free tier does not expose the connector configuration UI.

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
   | `mcpAllowedRedirectUris` | `https://chatgpt.com/connector_platform_oauth_redirect` |
   | `mcpAllowedOrigins` | `https://chatgpt.com` |
   | `mcpAllowedClientIds` | leave empty (otherwise the DCR-generated client_id has to be added manually) |

2. **BE user group permissions** — see [Required BE-group permissions](../README.md#required-be-group-permissions) in the main README for the full list of feature flags to grant.

3. **The TYPO3 host is publicly reachable on HTTPS with a CA-trusted certificate** — verify from a machine outside your network:
   ```bash
   curl -sS <typo3-url>/aisuite-mcp/health
   curl -sS <typo3-url>/.well-known/oauth-authorization-server
   ```
   Both must respond `200`. If either is blocked by IP allowlist, basic auth, or a self-signed cert, ChatGPT will silently fail to connect.

4. **The `Authorization` header reaches PHP** — on Apache + mod_php / FCGI the header is often stripped before it reaches the application, which causes silent 401 loops after a successful OAuth dance. TYPO3's default `.htaccess` ships the required rewrite, but verify it has not been removed (the exact rule is in [Common troubleshooting](../README.md#common-troubleshooting) in the main README).

## Step 1 — Open the ChatGPT connector settings

1. Sign in to `https://chatgpt.com` with a Plus / Pro / Team / Business / Enterprise account.
2. Top right, click your **profile icon** → **Settings** → **Connectors** *(label may vary by plan; on some plans it is reached via "Profile → Settings → Apps & Connectors" or "Profile → Connectors")*.

## Step 2 — Add the MCP connector

1. Click **Add custom connector** (or **+ New connector**).
2. Enter the configuration:

   | Field | Value |
   |---|---|
   | **Name** | e.g. `AI Suite` |
   | **MCP Server URL** | `<typo3-url>/aisuite-mcp` *(use the **root URL only** — site prefixes like `<typo3-url>/<site>/aisuite-mcp` return 404, see Troubleshooting)* |
   | **Authentication** | OAuth |

3. Click **Connect** — ChatGPT performs OAuth Dynamic Client Registration against `<typo3-url>/aisuite-mcp/oauth/register`, then opens an authorization popup pointing at `<typo3-url>/aisuite-mcp/oauth/authorize?...`.
4. If you are not yet logged in: TYPO3 backend login.
5. The **consent screen** lists the requested scopes (`mcp:read`, `mcp:write`, `mcp:generate`, `mcp:translate`, `mcp:image`, `mcp:media`, `mcp:workflow`) → confirm.
6. Redirect back to ChatGPT → token is stored. Connector status: *"Connected"*.

## Step 3 — Activate the connector per chat

Custom connectors are **not enabled in every chat by default** — they have to be turned on per conversation:

1. Start a new chat.
2. Click the **`+` icon** (or **Tools** / **Apps & Connectors** button) below the chat input.
3. In the popup find **AI Suite** and toggle it on.
4. Only now does ChatGPT include the tool definitions in the model's context.

## Connector mode vs. Deep Research

This guide covers ChatGPT **custom connectors** (the *Apps & Connectors* flow above), which expose the **full AI Suite tool set** — read, write, generate, translate, images, workflow — to the model in a normal chat.

ChatGPT's separate **Deep Research** connectors are a narrower mode that, by OpenAI's convention, only calls two tools named `search` and `fetch`. AI Suite deliberately does not register those two aliases (they would leak into every other MCP client and cannot be verified against the live Deep Research runtime), so **AI Suite is not usable as a Deep Research source**. Use the custom connector for content operations; the closest read equivalents to `search`/`fetch` are `searchContent` and `readPageContent` / `readRecords`, which the model can call directly in a normal chat.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| **Connect** does nothing / generic OAuth error in ChatGPT, with no entry in the TYPO3 log | The MCP server is not publicly reachable from OpenAI's network, or TLS is not CA-trusted (self-signed, expired, hostname mismatch) | Verify from an external host that `<typo3-url>/.well-known/oauth-authorization-server` and `<typo3-url>/aisuite-mcp/health` both return `200` over HTTPS with a publicly trusted certificate |
| Model says *"I have no access to MCP"* even though the connector shows as connected | Connector isn't enabled **per chat** | Step 3 above: the **`+`** / **Tools** menu under the chat input → toggle AI Suite |

For state-gate, RateLimiter DI-cache, Apache `Authorization`-header strip and empty-tools-list issues that affect any client, see [Common troubleshooting](../README.md#common-troubleshooting) in the main README.

## Live logs while debugging

- **ChatGPT** — no logs accessible (OpenAI-hosted). The browser DevTools network tab can show the OAuth redirect chain but stops at OpenAI's backend boundary.
- **AI Suite MCP** — TYPO3 exception pages are returned directly in the HTTP response. Stack traces also land in `/var/log/` where applicable.
- **Webserver access log** — invaluable for ChatGPT issues. Watch for `404` on `/aisuite-mcp` (site-prefix problem) or repeated `401` on `/aisuite-mcp` (Authorization-header problem). A `200` on `/aisuite-mcp/oauth/token` should immediately be followed by a `200` on `POST /aisuite-mcp` — anything else points at one of the entries above.

## Persistence notes

- Client credentials and OAuth tokens are stored in the AI Suite MCP database tables (`tx_aisuite_oauth_codes`, `tx_aisuite_oauth_tokens`, `tx_aisuite_oauth_consents`). Access-token lifetime is controlled by `mcpTokenLifetimeDays` in the extension configuration (default `30`); refresh tokens extend that automatically as long as the connector stays in use.
- The ChatGPT-side state (which connector is configured per user, OAuth refresh-token cache) lives in each user's ChatGPT account and is not under your control.
- Revoking access can be done from either side — in ChatGPT by removing the connector, or in the TYPO3 backend via the **MCP dashboard → Revoke Token**.
