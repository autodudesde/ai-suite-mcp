# TYPO3 + AI Suite MCP

> 🚧 **Under active development.** This extension is in `beta` state and evolving fast. Tool signatures, settings keys, and the upcoming `AbstractCustomTool` API may still change between minor versions. Production deployments are possible (and encouraged for early feedback), but pin a version and review the changelog before upgrading. Join us on Slack — [#ai-suite on TYPO3 Slack](https://typo3.slack.com/archives/C05QAN1KNVD) — to follow development, raise issues, or shape the roadmap.

MCP (Model Context Protocol) server integration for TYPO3's [AI Suite](https://www.autodudes.de/) extension. Connects Claude Desktop, Claude.ai, ChatGPT, MCP Inspector and other MCP-compatible clients directly to your TYPO3 backend — and lets the model drive the same AI providers (Anthropic, OpenAI, Mittwald AI, DeepL, Midjourney, Flux, …) that AI Suite already integrates.

## What you can do with it

Once connected, your MCP client can drive the TYPO3 backend the same way an editor would — but without leaving the chat.

- 🧭 **Walk the page tree, read pages, search content** — the model gets first-class access to every page, content element and FAL file the BE user can see.
- ✍️ **Generate & optimize content** — full tt_content elements, landing pages, complete page trees from a single prompt, plus rewrite / shorten / simplify on existing copy.
- 🌍 **Translate anything** — single records, complete pages, file metadata, or whole folders in one batch. Includes **Easy Language** rewrites for accessible content.
- 🏷️ **Fill in metadata at scale** — SEO titles, descriptions, OG / Twitter tags, alt texts, file metadata. Single record or bulk over a whole folder / page subtree.
- 🖼️ **Generate images straight into FAL** — the result lands as a real `sys_file`, ready to be referenced.
- 🧱 **Edit records safely** — every CRUD tool runs through DataHandler with a mandatory preview / confirm step before anything is persisted.
- 🧰 **Workspace-aware writes** — auto-routes changes through TYPO3 workspaces when EXT:workspaces is loaded; tokens can even be pinned to a specific workspace.
- 🧩 **Works with EXT:container and your custom records** — container children, third-party tables (news, products, custom CTypes) are first-class.
- ⏱️ **Background batch jobs** — long-running translations / metadata generation get an async task ID; results come back as suggestions you approve.
- 🔐 **Production-grade auth** — OAuth 2.1 + PKCE with dynamic client registration, per-token rate limiting, full HTTPS enforcement, password-change revocation.
- 👤 **Respects TYPO3 BE-user permissions** — every tool call runs as the linked backend user; page mounts, file mounts, table/field access rights and AI Suite per-feature/per-model BE-group flags are enforced on every request.
- 📊 **Reports + dedicated logs** — TYPO3 Reports module flags misconfigurations; two log streams (verbose + WARNING-only) keep ops monitoring simple.

## AI capabilities & available models

MCP tools delegate the actual generation / translation work to the parent AI Suite extension — so every model you've licensed there is also available to your MCP client. Permissions are still gated per BE-group feature flag and per AI model.

| Capability | MCP tools | Models available via AI Suite |
|---|---|---|
| **Page metadata** (SEO, OG, Twitter) | `generateMetadata`, `batchGenerateMetadata` | ChatGPT, Anthropic, Mittwald AI, Meta Llama-3.3 (70B-Instruct) |
| **File metadata** (alt, title, description) | `generateFileMetadata`, `batchGenerateFileMetadata`, `batchGenerateFolderMetadata` | ChatGPT Vision, Mittwald AI Vision, Meta Llama-3.3 (70B-Instruct) |
| **Content generation** (tt_content) | `generateContent`, `optimizeContent` | ChatGPT, Anthropic, Mittwald AI, Meta Llama-3.3 (70B-Instruct) |
| **Page-tree / landing pages** | `generatePageTree`, `generateLandingPage`, `savePageTree` | ChatGPT, Anthropic, Mittwald AI, Meta Llama-3.3 (70B-Instruct) |
| **Translation** (records, pages, file metadata) | `translateRecord`, `translatePage`, `translateFileMetadata`, `batchTranslatePage`, `batchTranslate*Metadata` | DeepL, Google Translate, ChatGPT, Anthropic, Mittwald AI |
| **Easy Language** (accessibility rewrites) | exposed via `optimizeContent` / translation tools | ChatGPT, Anthropic, Meta Llama-3.3 (70B-Instruct) |
| **DeepL glossary sync** | (via AI Suite — gated by `mcp:glossary` scope) | DeepL |
| **Image generation** | `generateImage` | GPT-Image (OpenAI), Midjourney, Flux |

The exact model list available to a given BE user depends on the AI-model permissions configured on their BE group in AI Suite. The model picks itself up automatically from the AI Suite settings — no extra config in MCP.

## Requirements

- TYPO3 12.4.11 – 14.3.x
- PHP 8.2+
- `autodudes/ai-suite` `^12.19.2 || ^13.13.2 || ^14.1.2`
- `typo3/cms-reports` `^12.4.11 | ^13.4.1 || ^14.3.0`
- `logiscape/mcp-sdk-php` `^1.7`
- `symfony/clock` `^6.4 || ^7.0 || ^8.0`
- *(recommended)* `typo3/cms-workspaces` `^12.4.11 | ^13.4.1 || ^14.0.0` — enables the workspace-aware write modes

## Installation

```bash
composer require autodudes/ai-suite-mcp
vendor/bin/typo3 extension:setup
```

## Configuration

All settings live under **Admin Tools → Settings → Extension Configuration → `ai_suite_mcp`**.

| Setting | Default | Description |
|---|---|---|
| `enableMcp` | `0` | Master switch for the MCP endpoint. While disabled, all `/aisuite-mcp*` requests return `404 mcp_disabled`. |
| `mcpTokenLifetimeDays` | `30` | OAuth access-token lifetime in days. |
| `mcpAllowedOrigins` | _(empty)_ | Comma-separated CORS origin allowlist. In **production** an empty value means "no CORS headers" (same-origin only). In development an empty value means "any origin allowed". |
| `mcpAllowedClientIds` | _(empty)_ | Comma-separated allowlist of OAuth `client_id` values. Empty = all clients allowed. |
| `mcpAllowHttp` | `0` | Allow the MCP endpoint over unencrypted HTTP. **Never** enable this in production — Bearer tokens would travel in clear text. Localhost and `*.ddev.site` are always exempted from HTTPS enforcement. |
| `mcpWriteMode` | `auto` | How write tools persist data — see [Write modes](#write-modes). |
| `mcpSessionTimeoutSeconds` | `1800` | Drop idle MCP sessions after N seconds. `0` = SDK default (3600). Lower values free PHP workers and reduce session-store bloat. |
| `mcpAllowedRedirectUris` | _(empty)_ | Comma-separated allowlist of external OAuth redirect URIs. Matched by **prefix** (`str_starts_with`). `http://localhost`, `http://127.0.0.1` and `http://[::1]` are always accepted regardless of this setting. |
| `mcpExcludedTables` | _(empty)_ | Comma-separated list of tables that MCP tools must **not** read or write. Applied on top of TYPO3 backend permissions and **also blocks admins** — use to hide sensitive tables (e.g. `be_users`, `fe_users`, `sys_log`) from MCP clients regardless of the user's TYPO3 role. |
| `mcpTrustedProxies` | _(empty)_ | Comma-separated reverse-proxy IPs / CIDRs (e.g. `10.0.0.0/8,192.168.0.0/16`). When set, OAuth audit-log entries resolve the real client IP from `X-Forwarded-For` instead of the proxy peer IP. Empty = `X-Forwarded-For` is ignored and the raw peer IP is logged. See [Reverse proxy & load balancer](#reverse-proxy--load-balancer). |

Logging settings (`mcpLogVerbose`, `mcpLogRedactionPatterns`) are documented under [Logging & retention](#logging--retention); the media-upload settings (`mcpMediaDefaultFolder`, `mcpMediaMaxSizeMb`, `mcpMediaAllowedExtensions`, `mcpMediaAllowUrlFetch`, `mcpMediaHostDenylist`) under the [`uploadMedia`](#media-upload-mcpmedia) tool.

### Known MCP client callback URLs

Copy these into `mcpAllowedRedirectUris` / `mcpAllowedOrigins` for every client you want to support.

| Client | `redirect_uri` → `mcpAllowedRedirectUris` | Browser origin → `mcpAllowedOrigins` |
|---|---|---|
| **Claude.ai / Claude Desktop** (remote connector) | `https://claude.ai/api/mcp/auth_callback` | `https://claude.ai` |
| **ChatGPT** (MCP connector) | `https://chatgpt.com/connector_platform_oauth_redirect` | `https://chatgpt.com` |
| **MCP Inspector** (dev tool) | `http://localhost:6274/oauth/callback`, `http://localhost:6274/oauth/callback/debug` | `http://localhost:6274` |
| **Claude Code CLI** | `http://localhost:<ephemeral-port>/callback` — covered by the localhost exception, no entry needed | — (no browser) |
| **Open WebUI** (self-hosted) | `https://<your-openwebui-host>/oauth/oidc/callback` | `https://<your-openwebui-host>` |

**Notes**
- Entries in `mcpAllowedRedirectUris` are matched by prefix, so e.g. `https://claude.ai/` covers any sub-path Claude may send (see `AuthorizationEndpoint::validateRedirectUri`).
- In a **development** context (non-production `TYPO3_CONTEXT`) an empty allowlist permits any `redirect_uri` / origin. In **production** an empty allowlist restricts to localhost-only redirect URIs and same-origin requests.
- `mcpAllowedOrigins` only affects browser-based clients (CORS). CLI and desktop-native clients ignore it.

## Write modes

`mcpWriteMode` controls how every write-capable tool (`writeRecords`, `copyRecords`, `moveRecords`, `localizeRecord`, `deleteRecords`, `savePageTree`, …) persists its changes. It can be set globally in the extension configuration **and** overridden per token at issue time (token-bound workspaces always win).

| Mode | What happens | When to use it |
|---|---|---|
| `auto` *(default)* | If EXT:workspaces is loaded, writes go to the BE user's default workspace; if the user hasn't picked one, the first accessible non-live workspace is used; otherwise live. | The safe default — workspaces stay workspaces, plain installs keep working. |
| `workspace` | Forces every write into the BE user's workspace (`be_users.workspace_id`). Fails the request if the user has no workspace access. | Editorial environments where AI changes must always go through review. |
| `live` | Bypasses workspaces entirely and writes live records. | Small sites without workspaces, or low-stakes automation where review isn't worth the friction. |

Resolution order (see `AiSuiteMcpEndpoint`):

1. **Token-bound workspace** (set when issuing the token) — always wins.
2. `mcpWriteMode = live` → live (`0`).
3. `mcpWriteMode = workspace` → BE user's default workspace.
4. `mcpWriteMode = auto` + `ext:workspaces` loaded → user's default, falling back to the first accessible non-live workspace.
5. Otherwise → live.

Read tools transparently follow whatever workspace the request resolved to — so previews show what the model would see after the write lands.

## Connectors

Each supported MCP client has its own dedicated setup guide under [`Connectors/`](Connectors/). The guides cover prerequisites (extension settings, BE-group permissions, host reachability), the per-client UI / CLI / config-file steps to register the connector, smoke-test prompts, and a troubleshooting matrix.

| Client | Setup guide | Auth flow | Reach |
|---|---|---|---|
| **Claude Desktop** | [`Connectors/claude-desktop.md`](Connectors/claude-desktop.md) | Static bearer token (default) or OAuth 2.1 | Local — can reach `localhost`, `*.ddev.site`, internal hosts |
| **Claude.ai** (web) | [`Connectors/claude-ai.md`](Connectors/claude-ai.md) | OAuth 2.1 with DCR | Public HTTPS only — Anthropic-hosted |
| **ChatGPT** | [`Connectors/chatgpt.md`](Connectors/chatgpt.md) | OAuth 2.1 with DCR | Public HTTPS only — OpenAI-hosted |
| **Claude Code** (CLI) | [`Connectors/claude-code.md`](Connectors/claude-code.md) | Static bearer token or OAuth 2.1 (localhost callback) | Local — can reach private hosts |
| **MCP Inspector** | [`Connectors/mcp-inspector.md`](Connectors/mcp-inspector.md) | OAuth 2.1 (localhost:6274 callback) | Local debug tool, browser-based |
| **Open WebUI** (self-hosted) | [`Connectors/openwebui.md`](Connectors/openwebui.md) | OAuth 2.1 with DCR | Wherever your OpenWebUI lives |

For a quick reference of the OAuth `redirect_uri` and browser `origin` values each client expects, see the [Known MCP client callback URLs](#known-mcp-client-callback-urls) table above.

## Connector setup essentials

The per-client guides under [`Connectors/`](Connectors/) all share a few foundational requirements and common failure modes. They are documented once here to avoid repetition; each connector guide cross-references this section.

### BE-group permissions

The TYPO3 backend user the token / OAuth consent is issued for needs the following feature flags on their BE group:

- `enable_mcp_access` — mandatory; required for the `mcp:manage` scope (token / dashboard access)
- `enable_metadata_generation` — for `generateMetadata`, `batchGenerateMetadata`, `generateFileMetadata`, …
- `enable_translation` — for all translation tools
- `enable_image_generation` — for `generateImage`
- `enable_content_element_generation` — for `generateContent`
- `enable_pages_generation` — for `generatePageTree`, `generateLandingPage`
- `enable_massaction_generation` — for batch / background-task tools
- `enable_translation_deepl_sync` — for DeepL glossary sync (`mcp:glossary` scope)
- `enable_rte_aieasylanguageplugin` — for the Easy Language tool (`mcp:easy-language` scope)

Without `enable_mcp_access` the connector connects but every tool call returns "no permission". Without the feature-specific flags the affected tools simply don't appear in the model's tool list.

### Common troubleshooting

Issues that can happen with any client, regardless of transport:

| Symptom | Cause | Fix |
|---|---|---|
| MCP responds with `"state parameter is required and must be at least 32 characters"` during the OAuth flow | Historical 32-character minimum on the OAuth `state` parameter | In `AuthorizationEndpoint.php`, change `< 32` to `< 22` (OAuth 2.1 BCP / RFC 9700 §4.7) — applies to any OAuth client whose default state length is below 32 |
| MCP responds with `"ArgumentCountError: Too few arguments to RateLimiter::__construct"` | DI container cache is stale after a code update to the `RateLimiter` class | Flush the TYPO3 cache + DI container cache (typically by removing the generated DI container under `var/cache/code/di/` and clearing caches in the backend) |
| Persistent 401 on `/aisuite-mcp` after a fresh token, with the request reaching PHP | Apache + mod_php / FCGI strips the `Authorization` header before it reaches PHP. Token endpoints still work (they read the body); the MCP endpoint requires the Bearer header and never sees it | Add the rewrite rule to the web-root `.htaccess`: `RewriteCond %{HTTP:Authorization} ^(.*)` / `RewriteRule .* - [E=HTTP_AUTHORIZATION:%1]` (TYPO3's default `.htaccess` ships this — verify it has not been removed) |
| Tools list is empty after a successful connect | The BE user has no AI Suite feature permissions, so all scope filtering returns empty | Grant `enable_mcp_access` plus the relevant feature permissions on the BE group (see [BE-group permissions](#be-group-permissions) above), then re-authenticate from the connector |
| Connector reports auth / connection failure after a successful OAuth dance, and the webserver access log shows `404` on a path like `/<site-prefix>/aisuite-mcp` | The connector URL contains a TYPO3 site prefix. `McpServerMiddleware` only matches `/aisuite-mcp` at the domain root, so requests with a prefix fall through TYPO3 routing and 404. Editors are often more affected than admins, because the backend URL they see already contains the site prefix and they paste that into the connector | Re-create the connector with the **root URL** (no site prefix): `<host>/aisuite-mcp` |
| Connector misbehaves (401 / 404 / no response), but `var/log/aisuite_mcp.log` has **no entry** for the request | The request never reached the MCP middleware at all — the dedicated log only records requests that hit `McpServerMiddleware` | Inspect the webserver access log for the actual URL that was hit. Typical root causes: site prefix in the connector URL (see previous row), `enableMcp = 0` (returns a generic `404 mcp_disabled` without writing to the MCP log), TLS / firewall rejection at the webserver layer |
| `401` on every MCP / OAuth request, with **no entry** in `aisuite_mcp.log` | The site is behind HTTP Basic Auth (`.htaccess`). Basic Auth and the MCP Bearer token share the `Authorization` header, so the webserver rejects the request before PHP runs | Carve the MCP paths out of Basic Auth — see [Systems behind HTTP Basic Auth (.htaccess)](#systems-behind-http-basic-auth-htaccess) |
| External connector (claude.ai / ChatGPT) reports *"Couldn't register with … sign-in service"*, and `curl` shows `/.well-known/oauth-*` returning `403` while `/aisuite-mcp` works | The site is behind an HTTP Basic Auth / env-flag guard (e.g. `Deny from env=SECURED`) that catches dot-paths. The client cannot complete OAuth discovery, so dynamic client registration fails before it starts | Exempt the discovery + MCP paths from the guard, keyed on `%{THE_REQUEST}` — see [Systems behind HTTP Basic Auth (.htaccess)](#systems-behind-http-basic-auth-htaccess), step 2 |

### Calling tools manually

When using a debug client like the [MCP Inspector](Connectors/mcp-inspector.md), tools can be invoked directly with hand-built JSON arguments instead of going through an LLM. The right-hand pane shows the raw JSON-RPC request and response for every call — invaluable for diagnosing schema mismatches and permission issues.

A useful starter sequence:

| Tool | Arguments | Expected result |
|---|---|---|
| `getServerInfo` | (none) | JSON with TYPO3 version, AI Suite version, MCP version |
| `getTables` | (none) | List of accessible TYPO3 tables for the BE user |
| `getPageTree` | `{ "rootPageId": 0, "depth": 2 }` | Nested JSON of the page tree |
| `generateMetadata` | `{ "pageId": 1, "preview": true }` | Preview payload, requires explicit confirmation before write |

## OAuth scopes

Each tool requires a scope, and each scope is only granted to users whose BE group has at least one of the matching AI Suite feature flags. See `McpPermissionService::SCOPE_PERMISSION_MAP`.

| Scope | Required BE-group permission(s) | Covers |
|---|---|---|
| `mcp:read` | _none_ (baseline) | All read-only / discovery tools |
| `mcp:write` | _none_ (preview + explicit confirmation is enforced per tool) | Record CRUD via DataHandler |
| `mcp:generate` | `enable_metadata_generation`, `enable_content_element_generation`, `enable_pages_generation` | AI content / metadata / page-tree / landing-page generation |
| `mcp:translate` | `enable_translation` | All translation tools |
| `mcp:image` | `enable_image_generation` | AI image generation |
| `mcp:workflow` | `enable_massaction_generation` | Batch / background task tools |
| `mcp:glossary` | `enable_translation` + `enable_translation_deepl_sync` | DeepL glossary sync |
| `mcp:easy-language` | `enable_rte_aieasylanguageplugin` | Easy Language writing |
| `mcp:manage` | `enable_mcp_access` | Global instructions, prompt templates, token / dashboard access |

## Tools

### Context (`mcp:read`)
| Tool | Purpose |
|---|---|
| `getServerInfo` | TYPO3 + AI Suite + MCP version / config summary |
| `getOperatingGuidelines` | Returns the operating guidelines the server expects clients to follow |
| `getPageTree` | Traverse the page tree (respecting BE user mounts) |
| `getPageContent` | Read the content of a page (tt_content, optionally nested containers) |
| `searchContent` | Full-text search across pages and content elements |
| `listFiles` | List files in a FAL storage / folder |
| `getFileInfo` | Metadata for a single sys_file / sys_file_metadata record |
| `findStaleContent` | Detect pages / content that have not been updated for N days |
| `auditContent` | Run a content audit (SEO, metadata completeness, …) via `ContentAuditService` |

### Records — discovery (`mcp:read`) and CRUD (`mcp:write` / `mcp:read`)
| Tool | Purpose |
|---|---|
| `getTables` | List tables exposed to MCP (all tables the BE user can read, minus `mcpExcludedTables`) |
| `getRecordSchema` | Return TCA schema for a table (fields, types, defaults) |
| `getPageTypes` | List available page doktypes |
| `getContentTypes` | List available tt_content CTypes for a page |
| `getColumnPositions` | List valid `colPos` values for a page's backend layout |
| `getFlexFormSchema` | Resolve the inner schema of a FlexForm field (default `tt_content.pi_flexform`) — sheets, fields, types, select options. Pass `recordUid` or a `type` hint when the data structure depends on the record type |
| `previewRecords` | Build a preview of a DataHandler operation without persisting |
| `readRecords` | Read records by table + UID(s) |
| `compareWithLive` | Diff workspace draft vs live (changed/added/removed fields) — requires a non-live workspace session |
| `writeRecords` | Create / update records via DataHandler (workspace-aware) |
| `copyRecords` | Copy one or more records (single params, or a `copies` batch array) |
| `moveRecords` | Move one or more records (single params, or a `moves` batch array) |
| `localizeRecord` | Localize a record into a target language |
| `deleteRecords` | Delete records (requires explicit user confirmation) |
| `savePageTree` | Persist a generated page tree |

### Generation (`mcp:generate`)
| Tool | Purpose |
|---|---|
| `generateMetadata` | Generate SEO metadata (title, description, keywords) for a page |
| `generateFileMetadata` | Generate alt text / title / description for a file |
| `generateContent` | Generate tt_content content |
| `generatePageTree` | Generate a complete page tree from a prompt |
| `generateLandingPage` | Generate a single landing page |
| `optimizeContent` | Optimize / rewrite / simplify existing content |

### Translation (`mcp:translate`)
| Tool | Purpose |
|---|---|
| `translatePage` | Translate all content of a page |
| `translateRecord` | Translate a single record |
| `translateFileMetadata` | Translate file metadata |

### Images (`mcp:image`)
| Tool | Purpose |
|---|---|
| `generateImage` | Generate an AI image and add it to FAL (GPT-Image (OpenAI) / Midjourney / Flux / …) |

### Media upload (`mcp:media`)
| Tool | Purpose |
|---|---|
| `uploadMedia` | Upload one or more existing images/videos into FAL — by remote http(s) URL (downloaded), inline base64 (`content`), or a YouTube/Vimeo link (stored as an online-media reference). No AI, no credits. |

`uploadMedia` takes a `media` array; each item carries exactly one source (`url` **or** `content`) plus optional `fileName`, `targetFolder` and metadata (`title`, `alternative`, `description`). Items are processed independently — one failing item does not abort the batch.

**Security.** Remote URL fetching is the sensitive part and is SSRF-guarded by `RemoteMediaService`: only `http`/`https`, every resolved IP must be public (private, loopback, link-local incl. the `169.254.169.254` cloud-metadata endpoint, and reserved ranges are rejected, IPv4 + IPv6), redirects are followed manually and re-validated per hop, and the download is streamed with a hard size cap. Blocked targets are logged at WARNING. Beyond the OAuth scope + `enable_mcp_media_upload` flag, FAL filemount permissions on the target folder still apply. Tunables (`ext_conf`): `mcpMediaDefaultFolder`, `mcpMediaMaxSizeMb`, `mcpMediaAllowedExtensions` (SVG excluded by default — XSS), `mcpMediaAllowUrlFetch` (kill-switch for URL downloads), `mcpMediaHostDenylist`. Large videos should be supplied via `url` or an online-media link rather than base64.

### Workflow (`mcp:workflow` / `mcp:generate`)
Batch tools run asynchronously and return a task ID. Poll via `getTaskStatus`, retrieve results via `getTaskResults`.

| Tool | Purpose |
|---|---|
| `batchGenerateMetadata` | Page metadata in bulk |
| `batchGenerateFileMetadata` | File metadata for an explicit list of files |
| `batchGenerateFolderMetadata` | File metadata for every file in a folder |
| `batchTranslatePage` | Translate multiple pages |
| `batchTranslateFileMetadata` | Translate file metadata for an explicit list of files |
| `batchTranslateFolderMetadata` | Translate file metadata for every file in a folder |
| `getTaskStatus` | Status of a background task |
| `getTaskResults` | Fetch paginated results (for translations: pass `apply: true` to persist) |

## Operating guidelines

The server advertises a strict two-approach flow to clients (see `OperatingGuidelines.php`):

1. **External AI-powered tools** (`generate*`, `translate*`, `optimize*`) — first call returns available models; second call returns a preview; client must display the preview, obtain explicit user approval, then persist via `writeRecords`.
2. **Manual tools** — discover fields via `getRecordSchema` / `getContentTypes` / `getColumnPositions`; never guess; always preview + confirm before `writeRecords`.

Batch tools never auto-persist: results are suggestions that require user approval.

## Custom tools

Need a tool that doesn't exist yet — pulling data from a project-specific table, kicking off a domain workflow, exposing a sitepackage helper to the model? You can extend the MCP server from your own extension.

### Anatomy of a tool

Every tool implements `AutoDudes\AiSuiteMcp\Mcp\ToolInterface`:

```php
public function getName(): string;           // unique tool name, used by the LLM
public function getDescription(): string;    // shown to the model when picking tools
public function getSchema(): array;          // JSON Schema for the input arguments
public function execute(array $params): CallToolResult;
public function getRequiredScope(): ?string; // null = no scope check
```

`ToolInterface` carries `#[AutoconfigureTag('aisuite.mcp.tool')]`, so any service implementing it is picked up automatically by `ToolRegistry` — no manual `Services.yaml` wiring needed, just make sure your extension's `Configuration/Services.php` autowires + autoconfigures the namespace.

### Trust boundary

`ToolRegistry::validateToolOrigin()` enforces a hard rule:

- Tools under `AutoDudes\AiSuiteMcp\Mcp\Tool\` may extend `AbstractTool` directly (full backend access, full DataHandler).
- Tools under any other namespace **must extend `AbstractCustomTool`** — its `final doExecute()` routes calls through the AI Suite Server so credit accounting and the central security policy stay in place.

Third-party tools that try to extend `AbstractTool` directly are silently rejected at boot time and logged as a warning to `aisuite_mcp.log`. Don't bypass this — it's there to prevent custom code from siphoning AI provider calls outside the credits pipeline.

> ⚠️ **Status:** `AbstractCustomTool` is the planned public extension API and currently a stub (`Classes/Mcp/CustomTool/`). Until it ships, third-party tools cannot register. If you have a use case that doesn't fit any of the built-in tools, [open a feedback issue](#feedback) — we'd like to know what shape the API needs to take before we freeze it.

### Adding a tool inside this extension

For tools that legitimately belong here (built-in tools), the pattern is:

1. Create a class under `Classes/Mcp/Tool/<Category>/MyNewTool.php` extending `AbstractTool` (or `AbstractAiTool` / `AbstractTranslateTool` for AI-powered tools that need credit accounting and model routing).
2. Add `#[AutoconfigureTag('aisuite.mcp.tool')]` if your class doesn't pick it up via `ToolInterface` (in practice it does automatically).
3. Implement `getName()`, `getDescription()`, `getSchema()`, `getRequiredScope()`, and `doExecute()` — never `execute()`, which is `final` on `AbstractTool` and runs the validation / permissions / error-handling pipeline.
4. Inject any extra services through your own constructor; the bundled context (`McpToolContext`) already covers the common ones (`McpUserContext`, `McpPermissionService`, logger, `LocalizationService`, `BackendUserService`, …).
5. Map your scope to the right BE-group flag in `McpPermissionService::SCOPE_PERMISSION_MAP` if you introduce a new scope.

Run the test suite (`phpunit -c Tests/UnitTests.xml`, `phpunit -c Tests/FunctionalTests.xml`) and verify the tool shows up in `getServerInfo` and on a connector smoke test.

## Console commands

```bash
# Create a test token (bypasses OAuth flow — development only)
vendor/bin/typo3 ai-suite-mcp:create-token --user=1
vendor/bin/typo3 ai-suite-mcp:create-token --user=admin --scopes="mcp:read mcp:write mcp:generate"
vendor/bin/typo3 ai-suite-mcp:create-token --user=1 --client=mcp-inspector

# Clean up expired OAuth state, session files and completed task files
vendor/bin/typo3 ai-suite-mcp:cleanup

# Run a local MCP server over stdio (trusted local CLI clients only — see "Local stdio transport")
vendor/bin/typo3 ai-suite-mcp:server --user=1
vendor/bin/typo3 ai-suite-mcp:server --user=editor --scopes="mcp:read mcp:write"
```

`ai-suite-mcp:cleanup` removes:
- authorization codes older than 10 min
- access tokens older than the token lifetime + 7-day buffer (37 days by default)
- session files under `var/aisuite_mcp_sessions/` older than 7 days
- background task files under `var/mcp_tasks/` older than 30 days

Schedule it via the TYPO3 Scheduler or cron.

## Local stdio transport

`ai-suite-mcp:server` exposes the same tools as the HTTP endpoint, but over **stdio**
(JSON-RPC on stdin/stdout) instead of HTTP. It is intended for **local, trusted CLI clients**
(Claude Desktop / Claude Code on the same host) that prefer launching a command over an OAuth
connector.

```bash
# The client launches this command and talks JSON-RPC over the pipe:
vendor/bin/typo3 ai-suite-mcp:server --user=<uid|username>
#   --scopes="mcp:read mcp:write …"   # default: all scopes the BE user is entitled to
#   --workspace=<uid>                 # default: resolved from mcpWriteMode
```

Example Claude Desktop / Claude Code config entry:

```json
{
  "mcpServers": {
    "typo3-ai-suite": {
      "command": "vendor/bin/typo3",
      "args": ["ai-suite-mcp:server", "--user=1"]
    }
  }
}
```

**Security model.** stdio runs the tools as the given backend user with the scope + BE-group
double gate fully enforced (identical to HTTP). But because the transport is a local pipe, it
**bypasses OAuth, the HTTPS gate, per-token rate limiting and the request-body cap** — those are
HTTP-surface protections. Run it **only** as a locally launched process, never wired to a network
socket. Anyone who can run the command can act as the chosen `--user`, so treat command access as
equivalent to that user's backend credentials. For remote / multi-user access, use the OAuth HTTP
endpoint instead.

Diagnostics are written to stderr (stdout is reserved for the JSON-RPC channel); tool calls are
logged to `var/log/aisuite_mcp.log` as usual.

## Database tables

| Table | Purpose |
|---|---|
| `tx_aisuite_oauth_codes` | Short-lived authorization codes (PKCE challenge + redirect URI) |
| `tx_aisuite_oauth_tokens` | Access + refresh tokens, client metadata, last-used IP, credit usage |
| `tx_aisuite_oauth_consents` | Remembered per-user / per-client scope consents |

## Security

Enforced by `McpServerMiddleware` and the OAuth endpoints:

- **HTTPS required** in production (localhost + `*.ddev.site` exempted; override with `mcpAllowHttp=1` — **not** for production).
- **Request-body cap** of 1 MB per MCP request.
- **Rate limiting** — 100 requests / minute per Bearer token (responds `429` with `Retry-After: 60`).
- **OAuth 2.1 with PKCE**, no implicit / password grants.
- **Dynamic Client Registration** is permitted but constrained by `mcpAllowedClientIds` / `mcpAllowedRedirectUris`.
- **Password change revokes all tokens** for that BE user (`PasswordChangeHook` on `processDatamapClass`).
- **Live backend-user status check** on every request — disabled / deleted users are rejected even if their token is still valid.
- **Scope + permission double check** — an OAuth scope alone is not sufficient; the BE user group must also carry the matching AI Suite feature flag.
- **Reports module** surfaces misconfigurations: HTTP allowed, empty allowlists in production. Check **System → Reports → AI Suite MCP Security**.

## Production deployment

The settings, security gates, and connector flows above are sufficient to *run* the MCP server. Operating it stably in production additionally requires getting the topics in this section right — none of them are enforced by the code, but ignoring any one of them tends to cause silent failures (lost session state, stale tokens, audit-log gaps, …) rather than loud errors.

### Reverse proxy & load balancer

- **HTTPS detection:** `McpServerMiddleware::enforceHttps()` honors `X-Forwarded-Proto: https` in addition to the request scheme. If your CDN or load balancer terminates TLS and forwards plain HTTP to the origin, set this header on the proxy.
- **HTTPS-gate trust boundary:** `X-Forwarded-Proto` is accepted from *any* upstream — the HTTPS gate does **not** consult the `mcpTrustedProxies` list (that setting only governs audit-log IP resolution, see below). A direct client that sends `X-Forwarded-Proto: https` would bypass the HTTPS gate. Make sure your proxy strips the header from inbound traffic before re-setting it, or restrict access to the origin to the proxy IPs only (firewall / VPC).
- **Client IP in audit logs:** OAuth audit entries (`token issued`, `token revoked`, …) record the client IP resolved by `ClientIpService`. By default that is the peer IP (`REMOTE_ADDR`), which behind a proxy is the proxy IP, not the end-user IP. Set `mcpTrustedProxies` to your proxy IPs / CIDRs and the service walks the `X-Forwarded-For` chain from the right, skipping trusted hops, and logs the first untrusted address (the real client). When `mcpTrustedProxies` is empty, `X-Forwarded-For` is ignored entirely — so a client cannot spoof its IP in the audit log by sending the header itself.

### Webserver setup

**Apache** (mod_php / FCGI): TYPO3's default `.htaccess` ships the Authorization-header rewrite the MCP endpoint needs. Verify the rule is intact (the exact rule is in [Common troubleshooting](#common-troubleshooting)).

**Nginx** (php-fpm): the equivalent rewrite is per-`location` in your nginx config. The MCP endpoint requires the Authorization header to be forwarded to PHP explicitly:

```nginx
location ~ \.php$ {
    fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    # ... your existing fastcgi_params include
}
```

Also raise `client_max_body_size` to at least the MCP body cap (1 MB) plus margin for batch payloads — `8m` is a safe default.

### Systems behind HTTP Basic Auth (.htaccess)

Sites are often shielded with HTTP Basic Auth (`.htaccess` / `AuthType Basic`) — staging environments, internal instances, not-yet-launched sites, and so on. This **collides** with the MCP server, because Basic Auth and the MCP endpoint both use the same `Authorization` request header — Basic Auth sends `Authorization: Basic …`, while the MCP endpoint requires `Authorization: Bearer <token>`. A request can carry only one `Authorization` header, so a connector placed behind Basic Auth fails: the webserver answers `401` before PHP ever runs (the request never reaches `McpServerMiddleware`, so there is also no entry in `aisuite_mcp.log`).

You can run MCP on a Basic-Auth-protected system, but the MCP paths must be carved out. The server's own OAuth 2.1 + Bearer-token auth then provides the protection on those paths.

Which paths must be reachable **without** Basic Auth:
- `/.well-known/oauth-authorization-server` and `/.well-known/oauth-protected-resource` — OAuth discovery (RFC 8414 / 9728); the client fetches these first.
- `/aisuite-mcp/oauth/*` — the OAuth flow. The interactive login on `/aisuite-mcp/oauth/authorize` *is* the TYPO3 backend login (the actual "log in" step), so Basic Auth must not mask it.
- `/aisuite-mcp` (and sub-paths) — the MCP endpoint itself; its Bearer-token auth already protects it (every token is bound to a concrete BE user with enforced permissions).

> ⚠️ Do **not** use `<Location>` / `<LocationMatch>` for this — they are only valid in the server / vhost configuration. Placing them in `.htaccess` triggers `500 Internal Server Error` (`<Location> not allowed here` in the Apache error log). Use the `Require expr` / `SetEnvIf` forms below.

**1. Match on `%{THE_REQUEST}`, not `Request_URI`.** On TYPO3, the front-controller rewrite rewrites the request to `index.php` *before* the authorization phase runs, so `SetEnvIf Request_URI …` / `<If "%{REQUEST_URI} …">` no longer see the original path and silently fail to match. `%{THE_REQUEST}` is the verbatim original request line (e.g. `GET /.well-known/oauth-authorization-server HTTP/1.1`) and stays stable across internal rewrites — always key the exemptions off it.

In the web-root `.htaccess`, combine your existing Basic Auth with `<RequireAny>` and exempt the MCP paths via `Require expr`. Adjust `AuthUserFile` to your setup and **replace** your current `Require valid-user` line with this block:

```apache
AuthType Basic
AuthName "Restricted"
AuthUserFile /path/to/.htpasswd

<RequireAny>
    Require expr %{THE_REQUEST} =~ m#\s/\.well-known/oauth-#
    Require expr %{THE_REQUEST} =~ m#\s/aisuite-mcp#
    Require valid-user
</RequireAny>
```

A request to an MCP path matches one of the `Require expr` lines and passes without Basic Auth; everything else falls back to `Require valid-user`.

**2. If the host gates access via an env flag (e.g. `Deny from env=SECURED`), exempt the MCP paths from that flag too.** Some managed hosts (and TYPO3's own staging recipe) protect the site with a pattern like:

```apache
SetEnvIf Host staging\.example\.com$ SECURED=yes
# … later, inside the auth block:
Order allow,deny
Allow from all
Deny from env=SECURED
```

This host-based `Deny` is evaluated independently of the `<RequireAny>` above and will still `403` the MCP/discovery paths (often only the dot-paths visibly fail, while `/aisuite-mcp` appears to work — an artifact of mixing legacy `Satisfy`/`Order`/`Allow`/`Deny` with `Require`). Unset the flag for the MCP paths, right after it is set:

```apache
SetEnvIf Host staging\.example\.com$ SECURED=yes
# Exempt OAuth discovery + MCP endpoint from the staging guard:
SetEnvIfExpr "%{THE_REQUEST} =~ m#\s/(\.well-known/oauth-|aisuite-mcp)#" !SECURED
```

(Again `THE_REQUEST`, not `Request_URI` — and `SetEnvIfExpr` because `SetEnvIf` cannot match `THE_REQUEST`.)

**3. Make sure the Authorization-header rewrite is present** (see [Webserver setup](#webserver-setup) above):

```apache
RewriteCond %{HTTP:Authorization} ^(.*)
RewriteRule .* - [E=HTTP_AUTHORIZATION:%1]
```

TYPO3's default `.htaccess` ships it, but verify it survived customisation — without it the Bearer header never reaches PHP and the MCP endpoint returns `401` even though discovery and the OAuth flow work.

The rest of the site stays behind Basic Auth; only the MCP surface is opened up, and it remains protected by OAuth. To verify, `curl` the discovery URLs and the endpoint:

```bash
curl -i https://<host>/.well-known/oauth-protected-resource     # expect 200 JSON
curl -i https://<host>/.well-known/oauth-authorization-server   # expect 200 JSON
curl -i https://<host>/aisuite-mcp/health                       # expect 200 JSON
```

A `403` here means an env-flag guard (step 2) or a host-level dot-path block is still catching the path; a `401` with `WWW-Authenticate: Basic` means the Basic-Auth exemption (step 1) is not matching — check that it keys off `THE_REQUEST`.

### Scheduled maintenance

`ai-suite-mcp:cleanup` is **required** in production, not optional. Run it via TYPO3 Scheduler or system cron at least **hourly**. It removes:

- authorization codes older than 10 min
- access tokens older than the token lifetime + 7-day buffer (37 days at default `mcpTokenLifetimeDays = 30`)
- **revoked tokens older than 30 days** — hard-deleted from `tx_aisuite_oauth_tokens` to meet GDPR right-to-erasure expectations. Soft-deleted entries (`deleted = 1`) are kept for 30 days so refresh-token theft detection (S24) can still recognise reuse of a rotated token; after that window the signal is moot
- session files under `var/aisuite_mcp_sessions/` older than 7 days
- background-task files under `var/mcp_tasks/` older than 30 days

Without it, the authorization-code table grows unbounded, on-disk session and task directories balloon, and revoked or expired tokens accumulate in `tx_aisuite_oauth_tokens`. For high-volume sites (>100 concurrent users) monitor row counts in `tx_aisuite_oauth_tokens` and tighten `mcpTokenLifetimeDays` if growth outpaces the cleanup cycle.

### Logging & retention

Two dedicated log files are configured for the `AutoDudes.AiSuiteMcp` namespace in `ext_localconf.php`:

- `var/log/aisuite_mcp.log` — **INFO+** (verbose, full trace). Useful for forensic debugging and per-request audit replay. **Toggleable** via the `mcpLogVerbose` extension setting (default: on). Disable in mature production deployments to reduce I/O and PII surface — the WARNING+ alert log stays active either way.
- `var/log/aisuite_mcp_warnings.log` — **WARNING+** only. Always active. Stays small; if it is non-empty, something is worth investigating (rate-limit hits, tool execution failures, OAuth misconfigurations). Designed for monitoring / paging — point your log shipper or `tail -F` here in production.

What gets logged:

- OAuth events (`token issued`, refreshed, revoked) with client_id, BE-user UID, and (real) client IP
- MCP request method, path, status code, and the first ~300 characters of the request body — which routinely contains user prompts, page content snippets, file metadata, etc.
- Tool execution errors with full exception traces

### Outbound network egress

MCP tools that call AI providers (`generate*`, `translate*`, `optimize*`, `generateImage`) inherit the network configuration of the parent `autodudes/ai-suite` extension. Outbound HTTPS is required to:

- the API host(s) of every provider you have enabled in AI Suite (Anthropic, OpenAI, Mittwald AI, Midjourney, Flux, DeepL, …)
- the AutoDudes credit-accounting backend, if licensed via AutoDudes

In hardened environments with strict egress firewalls, allowlist the provider hosts that are actually configured in your AI Suite settings. The MCP endpoint itself does not introduce additional outbound destinations beyond what AI Suite already uses.

## Feedback

We're actively shaping this extension and the upcoming public custom-tool API. If you try it out — especially against a real editorial workflow — we'd love to hear from you:

- 🐛 **Bugs / regressions** → please file an issue with the relevant `aisuite_mcp_warnings.log` excerpt and the connector you used.
- 💡 **Tool gaps** → if you reached for a tool that doesn't exist (a third-party table you'd like discoverable, a workflow not covered by the built-ins), tell us what the LLM should have been able to do. This is the most valuable feedback for the `AbstractCustomTool` API design.
- 🔌 **New connectors** → if your favourite MCP client isn't in `Connectors/`, share the redirect URI / origin / auth flow it expects and we'll add a guide.
- 🔒 **Security findings** → please contact us directly rather than opening a public issue.

The fastest way to reach us is the [#ai-suite channel on TYPO3 Slack](https://typo3.slack.com/archives/C05QAN1KNVD). You can also reach us via [service@autodudes.de](mailto:service@autodudes.de).

## License

GPL-2.0-or-later
