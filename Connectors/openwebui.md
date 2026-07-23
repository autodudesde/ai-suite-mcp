# OpenWebUI + Ollama as a local MCP test client

This document covers **every step in the OpenWebUI admin UI** required to test the AI Suite MCP server end-to-end with OpenWebUI as the frontend and Ollama as the inference backend.

The infrastructure side (containers, networking, TLS, optional GPU acceleration) is set up separately and is not covered here. For the container setup refer to the official docs:

- **Ollama** — [GitHub](https://github.com/ollama/ollama), [Docker image](https://hub.docker.com/r/ollama/ollama), [Docker setup guide](https://github.com/ollama/ollama/blob/main/docs/docker.md)
- **Open WebUI** — [Documentation](https://docs.openwebui.com/), [Quick start](https://docs.openwebui.com/getting-started/quick-start/), [GitHub](https://github.com/open-webui/open-webui)

## Conventions used in this guide

- **`<typo3-url>`** — base URL of the TYPO3 instance that has the AI Suite MCP extension installed and reachable (e.g. `https://typo3.example.com`).
- **`<openwebui-url>`** — base URL where OpenWebUI is reachable (e.g. `https://openwebui.example.com`).
- **Model:** the examples below use **`qwen2.5:7b`**. The configuration steps stay the same for any other tool-capable model — see the alternatives in the prerequisites.

## Service URLs

| Service | URL |
|---|---|
| OpenWebUI | `<openwebui-url>` |
| AI Suite MCP endpoint | `<typo3-url>/aisuite-mcp` |
| AI Suite OAuth discovery | `<typo3-url>/.well-known/oauth-authorization-server` |

## Prerequisites before the first OpenWebUI login

1. **Enable the AI Suite MCP extension** — TYPO3 backend → *Admin Tools → Settings → Extension Configuration → `ai_suite_mcp`*:

   | Setting | Value |
   |---|---|
   | `enableMcp` | `1` |
   | `mcpAllowedRedirectUris` | leave empty in development (any redirect URI is accepted) — for production: `<openwebui-url>/oauth/clients/` |
   | `mcpAllowedOrigins` | leave empty in development (any origin is accepted) — for production: `<openwebui-url>` |
   | `mcpAllowedClientIds` | leave empty (otherwise the DCR-generated client_id has to be added manually) |

2. **BE user group permissions** — see [Required BE-group permissions](../README.md#required-be-group-permissions) in the main README for the full list of feature flags to grant.

3. **A tool-capable model is pulled** in your Ollama instance, e.g.:
   ```bash
   ollama pull qwen2.5:7b
   ```
   Other usable models: `qwen2.5:3b` (small, for smoke tests), `llama3.1:8b`, `mistral-nemo`.

## Step 1 — First login in OpenWebUI

1. Open `<openwebui-url>`.
2. **Sign up** — depending on your OpenWebUI configuration, the very first user may automatically become admin. The first login creates a local OpenWebUI account; it is independent of the TYPO3 backend user.
3. Once logged in, verify your Ollama connection under **Settings → Connections** (Ollama base URL).

## Step 2 — Add the MCP tool server

1. Top right, click your **avatar** → **Admin Panel**.
2. Left navigation: **Settings → Tools**.
3. **Add Connection** → "Edit Connection" dialog opens:

   | Field | Value |
   |---|---|
   | **Type** | MCP Streamable HTTP |
   | **Name** | e.g. `AI Suite MCP` |
   | **ID** | `ai-suite-mcp` *(manual entry — the `auto` hint in the field is just a placeholder, the form validation requires a real slug)* |
   | **URL** | `<typo3-url>/aisuite-mcp` |
   | **Enabled** *(toggle on the right)* | ✓ |
   | **Authentication** | OAuth 2.1 *(uses Dynamic Client Registration)* |

4. Click **Register Client** — OpenWebUI calls `POST /aisuite-mcp/oauth/register` (RFC 7591). The status badge changes from *"Not registered"* (orange) to *"Registered"* (green).
5. **Save**.
6. Re-open the connection → click **Authenticate**. A browser tab opens `<typo3-url>/aisuite-mcp/oauth/authorize?...`.
7. If you are not yet logged in: TYPO3 backend login.
8. The **consent screen** lists the requested scopes (`mcp:read`, `mcp:write`, `mcp:generate`, `mcp:translate`, `mcp:image`, `mcp:media`, `mcp:workflow`) → confirm.
9. Redirect back to OpenWebUI → token is stored. Connection status: *"Connected"*.

## Step 3 — Grant tool server access

By default only the owner of the MCP connection sees it in the tool picker. To make it usable for other users (or for yourself in regular chats instead of as admin):

1. In the connection edit dialog, click the **🔒 Access** button (bottom right).
2. Either grant *"Public / All users"* read access or add specific users/groups.
3. Save.

## Step 4 — Increase the model context window for tool calling

**Important:** Ollama's default context window for most models is **4096 tokens**. The 42 MCP tools exposed by AI Suite, together with the system prompt and chat history, produce roughly **9,500 tokens** of tool definitions. Without raising the limit, Ollama silently truncates the tool listing, the model never sees the tools, and it hallucinates (e.g. *"MCP = Microsoft Certified Professional"* instead of issuing real tool calls).

Fix:

1. In the chat header, click the model name `qwen2.5:7b ▼` → **Edit Model** *(or: Admin Panel → Settings → Models → qwen2.5:7b)*.
2. Expand **Advanced Parameters**.
3. Set **Context Length** *(`num_ctx`)* to **`16384`** (or up to 32768; qwen2.5:7b nominally supports 128K, but more context costs more VRAM).
4. Save → start a new chat. Existing chats keep the old value.

## Step 5 — Activate tools per chat

MCP tool servers are **not enabled in every chat by default** — they have to be turned on per conversation:

1. New chat, select model `qwen2.5:7b`.
2. Click the **`+` icon** (or wrench/tool icon) below the chat input.
3. In the popup menu find **AI Suite MCP** and toggle it on.
4. Only now does OpenWebUI include the tool definitions in the chat completion request to Ollama.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| *"Registration failed"* when clicking "Register Client" | OpenWebUI's aiohttp can't verify the TYPO3 host's TLS certificate (typical with self-signed dev certs) | Set `AIOHTTP_CLIENT_SESSION_SSL=false` and `AIOHTTP_CLIENT_SESSION_TOOL_SERVER_SSL=false` in the OpenWebUI environment |
| *Internal server error* on "Authenticate" with `SSL: CERTIFICATE_VERIFY_FAILED` in OpenWebUI logs | Python's httpx (used by authlib) ignores those env vars and does its own TLS verification | Make the TYPO3 host's root CA trusted inside the OpenWebUI container — typically by mounting it and combining it with certifi's bundle, then pointing `SSL_CERT_FILE` / `REQUESTS_CA_BUNDLE` at the merged file |
| Model hallucinates "MCP = Microsoft Certified Professional" even though the tool server is active | Ollama truncated the prompt, tool definitions were dropped (default 4096 tokens isn't enough for 42 tools) | Step 4 above: raise `num_ctx` to 16384 |
| Model says *"I have no access to MCP"* even though the connection is connected | Tool server isn't enabled **per chat** | Step 5 above: `+` icon under the chat input → toggle AI Suite MCP |

For state-gate, RateLimiter DI-cache, Apache `Authorization`-header strip and empty-tools-list issues that affect any client, see [Common troubleshooting](../README.md#common-troubleshooting) in the main README.

## Live logs while debugging

- **OpenWebUI** (Python uvicorn) — surfaces aiohttp/httpx errors during DCR and OAuth.
- **Ollama** — surfaces truncation warnings and the actual tool calls being issued by the model.
- **AI Suite MCP** — TYPO3 exception pages are returned directly in the HTTP response. Stack traces also land in `/var/log/` where applicable.

## Persistence notes

- The Ollama model cache and the OpenWebUI database (users, OAuth tokens, tool-server config) should each live in a persistent volume so they survive container restarts.
- `WEBUI_SECRET_KEY` must be stable: if it changes, all stored OAuth tokens become unusable, because OpenWebUI encrypts them with this key.
