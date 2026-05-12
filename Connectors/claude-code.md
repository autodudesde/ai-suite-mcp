# Claude Code CLI as a local MCP client

This document covers **every step required to wire the AI Suite MCP server into Claude Code**, Anthropic's official terminal-based agent.

Claude Code runs locally on macOS / Windows / Linux — connections to MCP servers happen from the user's machine. The AI Suite MCP server therefore does **not** need to be publicly reachable: localhost, `*.ddev.site`, internal DNS names, and self-signed certificates all work, as long as your local OS trusts the CA.

For background on Claude Code and MCP refer to the official docs:

- **Claude Code documentation** — [docs.claude.com/claude-code](https://docs.claude.com/en/docs/claude-code/overview), [MCP integration](https://docs.claude.com/en/docs/claude-code/mcp)
- **Claude Code GitHub** — [anthropics/claude-code](https://github.com/anthropics/claude-code)
- **Model Context Protocol** — [Specification](https://modelcontextprotocol.io/), [Anthropic MCP overview](https://docs.anthropic.com/en/docs/agents-and-tools/mcp)

## Conventions used in this guide

- **`<typo3-url>`** — base URL of the TYPO3 instance that has the AI Suite MCP extension installed and is reachable **from your local machine** (e.g. `https://typo3.example.com`, `https://my-project.ddev.site`, or `http://localhost:8080`). Self-signed certificates work as long as the issuing CA is trusted by your OS.
- **`<token>`** — the bearer token created in Step 1 of the static-token path (Path A). Not used in the OAuth path (Path B).
- **Authentication paths:** Claude Code supports two ways to authenticate with the AI Suite MCP server:
  - **Path A — Static bearer token** (simplest, no browser): create a token via the AI Suite backend, register it on the CLI with a single command. Best for CI, scripted setups, headless machines.
  - **Path B — OAuth 2.1 with localhost callback** (browser-based): Claude Code opens a browser the first time the MCP server is contacted, runs the full OAuth dance against an ephemeral localhost port. Best for interactive desktops where you want short-lived tokens with refresh.

## Service URLs

| Service | URL |
|---|---|
| AI Suite MCP endpoint | `<typo3-url>/aisuite-mcp` |
| AI Suite OAuth discovery (Path B only) | `<typo3-url>/.well-known/oauth-authorization-server` |

## Prerequisites before configuring Claude Code

1. **Enable the AI Suite MCP extension** — TYPO3 backend → *Admin Tools → Settings → Extension Configuration → `ai_suite_mcp`*:

   | Setting | Value |
   |---|---|
   | `enableMcp` | `1` |
   | `mcpAllowedRedirectUris` | not required — Claude Code uses `http://localhost:<ephemeral-port>/callback`, which is covered by the localhost exception in `AuthorizationEndpoint::validateRedirectUri` |
   | `mcpAllowedOrigins` | not required (no browser-side fetch from a third-party origin) |
   | `mcpAllowHttp` | `1` *only* if `<typo3-url>` is plain HTTP (e.g. local non-TLS dev). Production: keep `0` |

2. **BE user group permissions** — see [Required BE-group permissions](../README.md#required-be-group-permissions) in the main README for the full list of feature flags to grant.

3. **The TYPO3 host is reachable from your local machine** — verify in the same terminal you run Claude Code in:
   ```bash
   curl -sS <typo3-url>/aisuite-mcp/health
   ```
   Must respond `200`. If you use a self-signed certificate (e.g. DDEV's mkcert), make sure the CA is installed in your OS trust store (`mkcert -install`).

4. **Claude Code is installed** — see the [installation guide](https://docs.claude.com/en/docs/claude-code/setup). `claude --version` must work.

## Path A — Static bearer token

### Step A1 — Create the token in the AI Suite backend

1. In the TYPO3 backend, open the **MCP** tab in the AI Suite backend module (or click the MCP icon in the AI Suite button bar).
2. Click **Create Token** — copy the token string. Token lifetime is controlled by `mcpTokenLifetimeDays` (default `30`).

### Step A2 — Register the MCP server with Claude Code

```bash
claude mcp add typo3-ai-suite <typo3-url>/aisuite-mcp \
  --transport http \
  --header "Authorization: Bearer <token>"
```

The configuration is written to `~/.claude.json` (or the project-local `.claude/mcp.json` if you pass `--scope project`). Verify:

```bash
claude mcp list
```

`typo3-ai-suite` should appear in the output.

## Path B — OAuth 2.1 with localhost callback

### Step B1 — Register the MCP server with Claude Code

```bash
claude mcp add typo3-ai-suite <typo3-url>/aisuite-mcp \
  --transport http
```

(no `--header` flag — that triggers OAuth on first use).

### Step B2 — First connection triggers the OAuth flow

The next time Claude Code talks to the server (either on session start or the first tool call), it:

1. Performs OAuth Dynamic Client Registration against `<typo3-url>/aisuite-mcp/oauth/register`.
2. Spawns a local HTTP listener on an ephemeral port (e.g. `http://localhost:54123/callback`).
3. Opens your default browser at `<typo3-url>/aisuite-mcp/oauth/authorize?...`.
4. You log in to TYPO3 (if necessary) and confirm the consent screen with the requested scopes (`mcp:read`, `mcp:write`, `mcp:generate`, `mcp:translate`, `mcp:image`, `mcp:workflow`, `mcp:easy-language`, `mcp:glossary`, `mcp:manage`).
5. The browser redirects back to the localhost listener → Claude Code exchanges the code for tokens and persists them in `~/.claude.json`.

After the first run, the token is reused (and refreshed automatically) on subsequent invocations.

## Step 3 — Verify the connector is loaded

Inside an interactive Claude Code session, run:

```
/mcp
```

This lists all configured MCP servers and their status. `typo3-ai-suite` should be `connected`. The available tools (`getServerInfo`, `getTables`, `getPageTree`, `generateMetadata`, …) are now in scope for the model.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `claude mcp list` shows the server but every call returns *"Authentication required"* / 401 (Path A) | Token is invalid, expired, or was revoked; or the URL has a TYPO3 site prefix that the MCP middleware does not match | Re-issue the token via Step A1. Make sure the URL is the **root** form `<typo3-url>/aisuite-mcp` — not `<typo3-url>/<site>/aisuite-mcp` |
| `/mcp` reports the server as `failed` with no further detail | Connection error before the protocol handshake — usually TLS, DNS, or wrong path | Run `curl <typo3-url>/aisuite-mcp/health` in the same terminal. For self-signed certs run `mkcert -install` |
| Browser opens but redirect lands on *"Your connection is not private"* (Path B) | Self-signed cert not trusted by the browser | Trust the CA at the OS level (e.g. `mkcert -install` for DDEV) and restart the browser |

For state-gate, RateLimiter DI-cache, Apache `Authorization`-header strip and empty-tools-list issues that affect any client, see [Common troubleshooting](../README.md#common-troubleshooting) in the main README.

## Live logs while debugging

- **Claude Code** — `claude --debug` enables verbose logging to stderr. Inside an interactive session, `/mcp logs typo3-ai-suite` shows the recent request/response traffic.
- **AI Suite MCP** — TYPO3 exception pages are returned directly in the HTTP response. Stack traces also land in `/var/log/` where applicable.
- **Webserver access log** — useful to confirm the request reached the right path. Watch for `404` on `/aisuite-mcp` (site-prefix problem) or repeated `401` (Authorization-header problem).

## Persistence notes

- Path A: the bearer token is stored in the AI Suite MCP database table `tx_aisuite_oauth_tokens` and referenced by `~/.claude.json` on the client side. Lifetime is controlled by `mcpTokenLifetimeDays` (default `30`); after expiry, generate a new token via Step A1 and run `claude mcp remove typo3-ai-suite && claude mcp add ...` again.
- Path B: OAuth client credentials and the access + refresh tokens are stored in `~/.claude.json` (or the project-scoped config). Refresh tokens extend the access automatically as long as the connector stays in use.
- Revoking access can be done from either side — on the client by `claude mcp remove typo3-ai-suite`, or in the TYPO3 backend via the **MCP dashboard → Revoke Token**.
