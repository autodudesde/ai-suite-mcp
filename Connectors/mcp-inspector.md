# MCP Inspector as a debugging client

This document covers **every step required to wire the AI Suite MCP server into the official MCP Inspector**.

The MCP Inspector is the canonical debugging tool for MCP servers — a small browser-based UI launched on demand via `npx`. It is the fastest way to hand-test the AI Suite MCP server during development without going through an LLM frontend: you call tools directly with hand-built arguments and see the raw JSON-RPC traffic.

The Inspector runs locally — the connection to the MCP server is made from your machine, **not** from a hosted backend. The AI Suite MCP server therefore does **not** need to be publicly reachable: localhost, `*.ddev.site`, internal DNS names, and self-signed certificates all work, as long as your local OS trusts the CA.

For background refer to the official docs:

- **MCP Inspector** — [modelcontextprotocol.io/docs/tools/inspector](https://modelcontextprotocol.io/docs/tools/inspector), [GitHub](https://github.com/modelcontextprotocol/inspector)
- **Model Context Protocol** — [Specification](https://modelcontextprotocol.io/), [Anthropic MCP overview](https://docs.anthropic.com/en/docs/agents-and-tools/mcp)

## Conventions used in this guide

- **`<typo3-url>`** — base URL of the TYPO3 instance that has the AI Suite MCP extension installed and is reachable **from your local machine** (e.g. `https://typo3.example.com`, `https://my-project.ddev.site`, or `http://localhost:8080`). Self-signed certificates work as long as the issuing CA is trusted by your OS.
- **Inspector ports:** the Inspector binds the UI to `http://localhost:6274` and an internal proxy to `http://localhost:6277` by default. Both are covered by the AI Suite localhost exception, so no allowlist entries are needed.

## Service URLs

| Service | URL |
|---|---|
| AI Suite MCP endpoint | `<typo3-url>/aisuite-mcp` |
| AI Suite OAuth discovery | `<typo3-url>/.well-known/oauth-authorization-server` |
| MCP Inspector UI (after launch) | `http://localhost:6274` |

## Prerequisites before launching the Inspector

1. **Enable the AI Suite MCP extension** — TYPO3 backend → *Admin Tools → Settings → Extension Configuration → `ai_suite_mcp`*:

   | Setting | Value |
   |---|---|
   | `enableMcp` | `1` |
   | `mcpAllowedRedirectUris` | not required — `http://localhost`, `http://127.0.0.1`, and `http://[::1]` are always accepted regardless of this setting (see `AuthorizationEndpoint::validateRedirectUri`) |
   | `mcpAllowedOrigins` | not required for the same reason |
   | `mcpAllowHttp` | `1` *only* if `<typo3-url>` is plain HTTP (e.g. local non-TLS dev). Production: keep `0` |

2. **BE user group permissions** — see [Required BE-group permissions](../README.md#required-be-group-permissions) in the main README for the full list of feature flags to grant.

3. **Node.js 18+ is installed** on your local machine — `node --version` must report ≥18. The Inspector itself does not need to be installed; `npx` fetches it on demand.

4. **The TYPO3 host is reachable from your local machine** — verify in a terminal on the same machine that runs the Inspector:
   ```bash
   curl -sS <typo3-url>/aisuite-mcp/health
   ```
   Must respond `200`. If you use a self-signed certificate (e.g. DDEV's mkcert), make sure the CA is installed in your OS trust store (`mkcert -install`).

## Step 1 — Launch the Inspector

In a terminal on your local machine, run:

```bash
npx @modelcontextprotocol/inspector
```

The first launch downloads the package (~30 MB). It then prints the UI URL — typically:

```
🔍 MCP Inspector is up and running at http://localhost:6274
```

The Inspector also opens this URL in your default browser. If it does not, open it manually.

## Step 2 — Configure the connection

In the Inspector UI, fill in the connection panel on the left:

| Field | Value |
|---|---|
| **Transport Type** | `Streamable HTTP` |
| **URL** | `<typo3-url>/aisuite-mcp` |
| **Authentication** | `OAuth 2.1` *(or "OAuth" — exact label depends on the Inspector version)* |

Click **Connect**. The Inspector performs OAuth Dynamic Client Registration against `<typo3-url>/aisuite-mcp/oauth/register`, then opens the AI Suite consent screen at `<typo3-url>/aisuite-mcp/oauth/authorize?...` in a new browser tab.

## Step 3 — Complete the OAuth flow

1. If you are not yet logged in: TYPO3 backend login.
2. The **consent screen** lists the requested scopes (`mcp:read`, `mcp:write`, `mcp:generate`, `mcp:translate`, `mcp:image`, `mcp:media`, `mcp:workflow`) → confirm.
3. The browser redirects back to `http://localhost:6274/oauth/callback` with the authorization code → the Inspector exchanges it for an access token and stores it in localStorage.
4. The connection panel switches to *"Connected"* and the **Tools**, **Resources**, **Prompts** tabs become available.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| **Connect** does nothing / generic OAuth error in the Inspector, no entry in the TYPO3 log | The MCP server is not reachable from your local machine, or DNS / TLS fails locally | Verify with `curl <typo3-url>/aisuite-mcp/health` from the same machine. For self-signed certs run `mkcert -install` and restart your browser |
| Browser shows *"Your connection is not private"* or `NET::ERR_CERT_AUTHORITY_INVALID` on the OAuth redirect | The TYPO3 host uses a self-signed certificate whose CA is not trusted by your browser | Trust the CA at the OS level (e.g. `mkcert -install` for DDEV) and restart the browser. Browsers are stricter than `curl` for OAuth redirects, so a `curl -k` workaround does not help here |

For state-gate, RateLimiter DI-cache, Apache `Authorization`-header strip and empty-tools-list issues that affect any client, see [Common troubleshooting](../README.md#common-troubleshooting) in the main README.

## Live logs while debugging

- **MCP Inspector** — the terminal that runs `npx @modelcontextprotocol/inspector` prints request/response payloads at debug level. The browser DevTools console adds client-side errors.
- **AI Suite MCP** — TYPO3 exception pages are returned directly in the HTTP response. The Inspector renders them in the result pane. Stack traces also land in `/var/log/` where applicable.
- **Webserver access log** — useful to confirm the request reached the right path. Watch for `404` on `/aisuite-mcp` (site-prefix problem) or repeated `401` (Authorization-header problem).

## Persistence notes

- OAuth tokens are stored in the AI Suite MCP database tables (`tx_aisuite_oauth_codes`, `tx_aisuite_oauth_tokens`, `tx_aisuite_oauth_consents`). Token lifetime is controlled by `mcpTokenLifetimeDays` in the extension configuration (default `30`).
- The Inspector stores its OAuth client credentials and access tokens in browser **localStorage** for the `http://localhost:6274` origin. Closing the tab does not lose them; clearing site data does.
- A fresh `npx @modelcontextprotocol/inspector` invocation reuses the same localStorage if the browser is the same — no re-authentication needed unless the token expired or was revoked.
