# TYPO3 + MCP + AI Suite

> 🚧 **Under active development.** This extension is in `beta` state and evolving fast. Tool signatures, settings keys, and the upcoming `AbstractCustomTool` API may still change between minor versions. Production deployments are possible (and encouraged for early feedback), but pin a version and review the changelog before upgrading. Join us on Slack: [#ai-suite on TYPO3 Slack](https://typo3.slack.com/archives/C05QAN1KNVD) to follow development, raise issues, or shape the roadmap.

An MCP (Model Context Protocol) server for **TYPO3**. It connects Claude Desktop, Claude.ai, ChatGPT, MCP Inspector and other MCP-compatible clients directly to your TYPO3 backend, so the model can walk the page tree, read and search content, and create or edit records through DataHandler, as the logged-in backend user, with that user's permissions.

[AI Suite](https://www.autodudes.de/) is the technical foundation this builds on: it ships the extension infrastructure, the backend-group permission model, the TYPO3 version-compatibility layer and the shared services. It is a hard dependency (`autodudes/ai-suite` `^12.22.1 || ^13.16.1 || ^14.4.1`) and Composer pulls it in for you.

**What it is not is a paywall.** The MCP server is useful on its own, with no AI Suite account, no API key and no credits: your MCP client already brings the model that does the thinking. AI Suite's own AI providers (Anthropic, OpenAI, Mittwald AI, DeepL, Midjourney, Flux, …) are an *optional* add-on for the eleven tools that generate or translate server-side.

## Do I need an AI Suite subscription?

For most of what this extension does, no.

**34 of the 45 tools run entirely inside your TYPO3 installation** and cost nothing beyond what your MCP client charges you: the whole page tree, content and file reads, `searchContent`, schema discovery, record CRUD through DataHandler, the safe-edit tools, `localizeRecord`, workspace review and publishing, and `uploadMedia`. Your client's model composes the content; TYPO3 stores it. No AI Suite Server is contacted, and the transport does not check for an API key.

**11 tools do call the AI Suite Server and spend credits**, because the generation or translation happens there:

| Scope | Tools |
|---|---|
| `mcp:generate` | `generateFileMetadata` |
| `mcp:image` | `generateImage` |
| `mcp:translate` | `translateRecord`, `translatePage`, `translateFileMetadata` |
| `mcp:workflow` | `batchGenerateMetadata`, `batchGenerateFileMetadata`, `batchGenerateFolderMetadata`, `batchTranslatePage`, `batchTranslateFileMetadata`, `batchTranslateFolderMetadata` |

These eleven are the entire credit-costing surface; without an AI Suite account they are the only ones you lose. The tools that poll and persist their results are free: `readTaskStatus`, `readTaskResults` (`mcp:read`) and `applyTaskResults` (`mcp:write`). So is granting the `mcp:workflow` scope itself.

Translation is the one place where the distinction is easy to miss: `translateRecord` and friends hand the work to a translation model on the server and bill for it, while `localizeRecord` creates the translation with TYPO3's own localization machinery and costs nothing, so your client's model can then write the translated fields itself.

## What you can do with it

Once connected, your MCP client can drive the TYPO3 backend the same way an editor would, but without leaving the chat.

- 🧭 **Walk the page tree, read pages, search content**: the model gets first-class access to every page, content element and FAL file the BE user can see.
- ✍️ **Create & rewrite content**: the client model composes tt_content elements and page trees itself and persists them through DataHandler, honouring the editors' guidelines (`readEditorialGuidelines`). No credits are spent for that.
- 🌍 **Translate anything**: single records, complete pages, file metadata, or whole folders in one batch. Includes **Easy Language** rewrites for accessible content. *(server-side translation costs credits; `localizeRecord` + your own model does not)*
- 🏷️ **Fill in metadata at scale**: SEO titles, descriptions, OG / Twitter tags, alt texts, file metadata. Single record or bulk over a whole folder / page subtree. *(costs credits)*
- 🖼️ **Generate images straight into FAL**: the result lands as a real `sys_file`, ready to be referenced. *(costs credits)*
- 🧱 **Edit records safely**: every CRUD tool runs through DataHandler, and the operating guidelines require a preview / confirm step before anything is persisted. Reversibility is guaranteed by the `workspace` write mode (the default), which keeps every change in a reviewable draft.
- 🧰 **Workspace-aware writes**: defaults to routing changes through a TYPO3 draft workspace (auto-creating a per-user one when needed); tokens can even be pinned to a specific workspace.
- 🧩 **Works with EXT:container and your custom records**: container children, third-party tables (news, products, custom CTypes) are first-class.
- ⏱️ **Background batch jobs**: long-running translations / metadata generation get an async task ID; results come back as suggestions you approve. *(costs credits)*
- 🔐 **Production-grade auth**: OAuth 2.1 + PKCE with dynamic client registration, per-token rate limiting, full HTTPS enforcement, password-change revocation.
- 👤 **Respects TYPO3 BE-user permissions**: every tool call runs as the linked backend user; page mounts, file mounts, table/field access rights and AI Suite per-feature/per-model BE-group flags are enforced on every request.
- 📊 **Reports + dedicated logs**: TYPO3 Reports module flags misconfigurations; two log streams (verbose + WARNING-only) keep ops monitoring simple.

## AI capabilities & available models

**This section covers the optional part**: the eleven tools listed above that route to the AI Suite Server. Skip it if your MCP client's own model is doing the work.

These tools delegate the actual generation / translation to the parent AI Suite extension, so every model you've licensed there is also available to your MCP client. Permissions are still gated per BE-group feature flag and per AI model.

| Capability | MCP tools | Models available via AI Suite |
|---|---|---|
| **Page metadata** (SEO, OG, Twitter) | `batchGenerateMetadata` | ChatGPT, Anthropic, Mittwald AI, Meta Llama-3.3 (70B-Instruct) |
| **File metadata** (alt, title, description) | `generateFileMetadata`, `batchGenerateFileMetadata`, `batchGenerateFolderMetadata` | ChatGPT Vision, Mittwald AI Vision, Meta Llama-3.3 (70B-Instruct) |
| **Page-tree** | `savePageTree` | n/a (composed by the client model) |
| **Translation** (records, pages, file metadata) | `translateRecord`, `translatePage`, `translateFileMetadata`, `batchTranslatePage`, `batchTranslate*Metadata` | DeepL, Google Translate, ChatGPT, Anthropic, Mittwald AI |
| **Easy Language** (accessibility rewrites) | exposed via the translation tools | ChatGPT, Anthropic, Meta Llama-3.3 (70B-Instruct) |
| **DeepL glossary** | applied automatically by the translation tools (site glossary) | DeepL |
| **Image generation** | `generateImage` | GPT-Image (OpenAI), Midjourney, Flux |

The exact model list available to a given BE user depends on the AI-model permissions configured on their BE group in AI Suite. The model picks itself up automatically from the AI Suite settings; no extra config in MCP.

Beyond the AI-powered tools above, MCP also ships **discovery and editing tools** (no model calls): `readRenderedPage` (the page as a visitor sees it, including plugin output; needs the `enable_mcp_rendered_page_read` flag, see [Per-tool permissions](#per-tool-permissions)), `readEditorialGuidelines` (the tone / target audience / style the editors configured for a page subtree), `listTables`, `readRecordSchema` (with per-field content kind, read-only and relation metadata), `listContentTypes`, `readChildren` (list a record's container/IRRE children), `readPageContent`, `readRecords`, `searchContent` (sweeps IRRE child tables automatically; optional `rootPageId` to search one page subtree, single-`field` / `matchHtml` search), `previewRecords` (shows an old→new diff when editing), `writeRecords` (with optional `atomic:true` all-or-nothing batches), `copyRecords`, `moveRecords`, `deleteRecords`, `localizeRecord`, and the safe-edit tools `replaceText` / `patchText` / `bulkReplaceText` for small text corrections without resending whole fields (they locate the match ignoring line-ending and spacing differences by default, since stored rich text keeps CRLF that no read shows verbatim; pass `normalizeWhitespace:false` to require a byte-exact match). Media references can be reused/swapped with `copyMediaReference` / `replaceMediaReference`.

## Requirements

- TYPO3 12.4.11 – 14.3.x
- PHP 8.2+
- `autodudes/ai-suite` `^12.22.1 || ^13.16.1 || ^14.4.1` (`ext_emconf`: `12.22.1-14.99.99`): required, and installed automatically. It provides the extension infrastructure, the BE-group permission model, the TYPO3 version-compatibility layer and shared services. An AI Suite **account, API key or credits** are a separate matter and only needed for the eleven server-side tools listed above.
- `typo3/cms-reports` `^12.4.11 || ^13.4.1 || ^14.3.0`
- `logiscape/mcp-sdk-php` `^1.7`
- `symfony/clock` `^6.4 || ^7.0 || ^8.0`
- `typo3/cms-workspaces` `^12.4.11 || ^13.4.1 || ^14.3.0`: required; powers the workspace write modes (the default), including on-demand per-user workspace provisioning

## Installation

```bash
composer require autodudes/ai-suite-mcp
vendor/bin/typo3 extension:setup
```

Also available on the TYPO3 Extension Repository: [extensions.typo3.org/extension/ai_suite_mcp](https://extensions.typo3.org/extension/ai_suite_mcp).

## Configuration

All settings live under **Admin Tools → Settings → Extension Configuration → `ai_suite_mcp`**.

| Setting | Default | Description |
|---|---|---|
| `enableMcp` | `0` | Master switch for the MCP endpoint. While disabled, all `/aisuite-mcp*` requests return `404 mcp_disabled`. |
| `mcpTokenLifetimeDays` | `30` | OAuth access-token lifetime in days. |
| `mcpAllowedOrigins` | _(empty)_ | Comma-separated CORS origin allowlist. In **production** an empty value means "no CORS headers" (same-origin only). In development an empty value means "any origin allowed". |
| `mcpAllowedClientIds` | _(empty)_ | Comma-separated allowlist of OAuth `client_id` values. Empty = all clients allowed. |
| `mcpAllowHttp` | `0` | Allow the MCP endpoint over unencrypted HTTP. **Never** enable this in production: Bearer tokens would travel in clear text. Localhost and `*.ddev.site` are always exempted from HTTPS enforcement. |
| `mcpWriteMode` | `workspace` | How write tools persist data, see [Write modes](#write-modes). |
| `mcpSessionTimeoutSeconds` | `1800` | Drop idle MCP sessions after N seconds. `0` = SDK default (3600). Lower values free PHP workers and reduce session-store bloat. |
| `mcpAllowedRedirectUris` | _(empty)_ | Comma-separated allowlist of external OAuth redirect URIs. Matched by **prefix** (`str_starts_with`). `http://localhost`, `http://127.0.0.1` and `http://[::1]` are always accepted regardless of this setting. |
| `mcpExcludedTables` | _(empty)_ | Comma-separated list of tables that MCP tools must **not** read or write. Applied on top of TYPO3 backend permissions and **also blocks admins**: use to hide sensitive tables (e.g. `be_users`, `fe_users`, `sys_log`) from MCP clients regardless of the user's TYPO3 role. |
| `mcpSearchAdditionalTables` | _(empty)_ | Comma-separated tables that `searchContent` sweeps **on top of** the automatically detected child tables. Only needed for standalone record tables that are not children of anything (e.g. `tx_news_domain_model_news`); IRRE child tables are found in the TCA by themselves. See [Which tables searchContent sweeps](#which-tables-searchcontent-sweeps). |
| `mcpExcludeAdditionalTablesFromSearch` | _(empty)_ | Comma-separated tables to remove from the auto-detected set. Does **not** affect tables listed in `mcpSearchAdditionalTables`, and does not block MCP access; use `mcpExcludedTables` for that. |
| `mcpAllowRawHtmlWrite` | `0` | Allow write tools to store raw markup verbatim in **code editor fields**: `tt_content.bodytext` of CType `html` and any other TCA `text` field with a code editor `renderType`/`format`. Those fields render unfiltered in the frontend, so an agent (or a prompt injection reaching it) can place arbitrary HTML/JavaScript on the site. While disabled, a write that carries markup into such a field is **rejected** (`unsupported_html`): nothing is stored and the existing value survives. Markup-free values are written either way. See [Code editor fields](#code-editor-fields). |
| `mcpTrustedProxies` | _(empty)_ | Comma-separated reverse-proxy IPs / CIDRs (e.g. `10.0.0.0/8,192.168.0.0/16`). When set, OAuth audit-log entries resolve the real client IP from `X-Forwarded-For` instead of the proxy peer IP. Empty = `X-Forwarded-For` is ignored and the raw peer IP is logged. See [Reverse proxy & load balancer](#reverse-proxy--load-balancer). |
| `mcpBackendBaseUrl` | _(empty)_ | Scheme and host for the backend links in tool results, e.g. `https://www.example.com`. Empty = taken from the current request, and without one (stdio transport) from the site configuration. Set it when the backend runs under a different domain than the site base. See [Backend links in tool results](#backend-links-in-tool-results). |

Logging settings (`mcpLogVerbose`, `mcpLogRedactionPatterns`) are documented under [Logging & retention](#logging--retention); the media-upload settings (`mcpMediaDefaultFolder`, `mcpMediaMaxSizeMb`, `mcpMediaAllowedExtensions`, `mcpMediaAllowUrlFetch`, `mcpMediaHostDenylist`) under the [`uploadMedia`](#media-upload-mcpmedia) tool.

**ChEddi inherits these settings.** The editor chat (`cheddi`) runs the same tools in-process and
follows `mcpWriteMode`, `mcpAllowRawHtmlWrite`, `mcpExcludedTables`, `mcpSearchAdditionalTables`,
`mcpExcludeAdditionalTablesFromSearch`, `mcpLogVerbose` and `mcpLogRedactionPatterns` unless its own
`chat*` counterparts are set, which default to `inherit`. A configured ChEddi value can only
**tighten** the MCP one, never widen it; nothing configured there loosens what MCP allows. ChEddi
hands its values to `SurfaceSettingOverrides`, which stays empty for calls arriving over the MCP
transport.

### Backend links in tool results

A call that touched records reports them back as UIDs, which is precise but unusable; nobody wants
to look up page 99934 by hand. Every tool result therefore also carries links to what it touched, in
two channels: `structuredContent.links` for hosts that render them themselves, and a
`🔗 Open in TYPO3` block appended to the result text, which is what makes them clickable in a chat
client. The targets come from the UIDs the tools reported, so they point at what was actually
written; the label is the record's own title where it has one. The block asks the assistant to pass
the links on, so an editor gets them with the answer instead of having to ask for them.

Links are grouped by table, because a page and the content elements on it are different destinations:

```json
"links": [
  {"table": "pages", "label": "Page", "targets": [{"label": "Landing page", "url": "https://…"}], "omitted": 0},
  {"table": "tt_content", "label": "Page Content", "targets": [{"label": "Header", "url": "https://…"}], "omitted": 2}
]
```

`pages` comes first, the remaining tables in the order they were touched. The cap of six is **per
group**, so a written page is never pushed out of the list by the content elements written onto it;
`omitted` reports how many the cap dropped, so a truncated group cannot be mistaken for a complete
one.

The URLs are absolute and carry **no route token**. A token belongs to the session that generated it,
the MCP session and not the editor's browser, so it would be worthless in a link that leaves the
process. TYPO3 answers a tokenless backend URL with a redirect through the login route that carries
the original target, so an editor with a live backend session lands directly on the record.

Host resolution, first hit wins: `mcpBackendBaseUrl` → current request → site configuration
(`Site::getBase()`). If none of them yields a scheme and host (the stdio transport has
no request, and an installation may have no site configured), the link is dropped rather than
emitted broken, with a warning in `aisuite_mcp_warnings.log`.

### Which tables searchContent sweeps

`searchContent` always searches `pages` and `tt_content`. Beyond those it sweeps the **IRRE child tables of your installation**, and it derives that list from the TCA instead of asking you to configure it: every table reachable through an `inline` or `file` field that carries both a `foreign_table` and a `foreign_field` is a child table. That covers Content Blocks collections, Bootstrap Package accordion/card items and any hand-written equivalent; without them a term rollout silently misses every child record.

Four kinds of table are dropped from that set structurally: `sys_file_reference`, `pages` and `tt_content` (already searched or pure relation glue), every `rootLevel` table (it sits on `pid = 0` and can therefore never be inside a webmount, which is what keeps `sys_file_metadata` and `sys_workspace_stage` out), and every table without a searchable text column. `hideTable` is deliberately **not** a criterion: it means "no entry in the list module", not "secret", and every Content Blocks collection carries it.

Precedence, strongest first:

1. `mcpExcludedTables`: blocks the table from MCP entirely, search included.
2. `mcpSearchAdditionalTables`: adds a table regardless of detection or search exclusion.
3. `mcpExcludeAdditionalTablesFromSearch`: removes a table from the auto-detected set.
4. The auto-detected set.

The user's own backend permissions apply on top: a table the BE user cannot `tables_select` is skipped silently and does not appear in the response's `searchedTables`, which always names exactly the tables that were searched.

### Known MCP client callback URLs

Copy these into `mcpAllowedRedirectUris` / `mcpAllowedOrigins` for every client you want to support.

| Client | `redirect_uri` → `mcpAllowedRedirectUris` | Browser origin → `mcpAllowedOrigins` |
|---|---|---|
| **Claude.ai / Claude Desktop** (remote connector) | `https://claude.ai/api/mcp/auth_callback` | `https://claude.ai` |
| **ChatGPT** (MCP connector) | `https://chatgpt.com/connector_platform_oauth_redirect` | `https://chatgpt.com` |
| **MCP Inspector** (dev tool) | `http://localhost:6274/oauth/callback`, `http://localhost:6274/oauth/callback/debug` | `http://localhost:6274` |
| **Claude Code CLI** | `http://localhost:<ephemeral-port>/callback`: covered by the localhost exception, no entry needed | n/a (no browser) |
| **Open WebUI** (self-hosted) | `https://<your-openwebui-host>/oauth/` | `https://<your-openwebui-host>` |

**Notes**
- Entries in `mcpAllowedRedirectUris` are matched by prefix, so e.g. `https://claude.ai/` covers any sub-path Claude may send (see `AuthorizationEndpoint::validateRedirectUri`).
- In a **development** context (non-production `TYPO3_CONTEXT`) an empty allowlist permits any `redirect_uri` / origin. In **production** an empty allowlist restricts to localhost-only redirect URIs and same-origin requests.
- `mcpAllowedOrigins` only affects browser-based clients (CORS). CLI and desktop-native clients ignore it.

## Write modes

`mcpWriteMode` controls how every write-capable tool (`writeRecords`, `copyRecords`, `moveRecords`, `localizeRecord`, `deleteRecords`, `savePageTree`, …) persists its changes. It can be set globally in the extension configuration **and** overridden per token at issue time (token-bound workspaces always win).

| Mode | What happens | When to use it |
|---|---|---|
| `workspace` *(default)* | Forces every write into a draft workspace. Uses the BE user's default workspace (`be_users.workspace_id`) if set, otherwise an existing per-user MCP workspace, otherwise **auto-creates** one (titled `AI Suite MCP [#<uid>]`, with the user as member) so writes never silently hit live. | The safe default: AI changes always land in a reviewable draft. |
| `live` | Bypasses workspaces entirely and writes live records. | Low-stakes automation where review isn't worth the friction. |

A third mode, `auto` (draft if one happened to be available, else live), was removed: its soft fallback made the write target depend on the user's workspace assignment rather than on a decision. A configuration still carrying the value resolves to `workspace`.

Resolution order (see `McpBackendUserInitializer`):

1. **Token-bound workspace** (set when issuing the token), always wins.
2. `mcpWriteMode = live` → live (`0`).
3. Every other value, `workspace` included → BE user's default workspace → else an existing per-user MCP workspace → else a freshly auto-created per-user draft workspace (never silently live). Only `live` sends a write to the live site.

> The auto-created workspace is **not** stored as the user's TYPO3 default (`be_users.workspace_id` is left untouched), so it only affects MCP writes; the user's normal backend session stays on whatever workspace they had.

Read tools transparently follow whatever workspace the request resolved to, so previews show what the model would see after the write lands.

> ⚠️ **Two tools are not workspace-contained and write to live in *every* mode:** `uploadMedia`
> and `generateImage`. They create a `sys_file` record plus the physical file through FAL, which
> has no `versioningWS`: so their writes cannot land in a draft and no write mode undoes them.
> Both are gated behind their own scope (`mcp:media` / `mcp:image`) and BE-group feature flag
> (default off), and the MCP client's approval dialog is the only pre-write gate. A build-time
> completeness test (`WriteModeContainmentTest`) fails if a new mutating tool is added without
> deciding whether it is contained. Separately, the credits spent by the `generate*` / `batch*`
> tools are never refundable; those tools return suggestions and spend credits even though they
> write no database row.

## Why the client asks for approval

Nothing in this server asks a user to confirm a call. When a client shows an approval dialog, or
reports something like `No approval received`, that decision was made entirely on the client side.
There is no server-side approval gate, no confirmation parameter and, in particular, **no
batch-size threshold**: deleting one record and deleting eleven in one call take the identical code
path. If single calls go through while a batch does not, that is a property of the client, not of
this server.

What the server does contribute are the MCP tool annotations, which is what a client typically bases
its dialog on:

| Annotation | Meaning here | Which tools |
|---|---|---|
| `readOnlyHint` | Reads only, never writes | all `read*` / `list*` tools, `searchContent`, `previewRecords`, `compareWithLive` |
| `idempotentHint` | Repeating the call changes nothing further | the same read tools |
| `destructiveHint` | Not undoable by discarding a workspace | `deleteRecords`, `uploadMedia`, `generateImage`, `applyTaskResults` |
| `openWorldHint` | Reaches a system outside this TYPO3 | `generateImage`, `uploadMedia` |

`writeRecords`, `copyRecords`, `moveRecords` and `savePageTree` are deliberately **not** marked
destructive: under the default `mcpWriteMode = workspace` they land in a draft and are undone by
discarding it. Marking them destructive would add approval friction for changes that are already
reversible.

If a client asks too often or too rarely, the setting to change is in the client. `previewRecords`
is available for a read-only look at what a write would do, and `dryRun` on `bulkReplaceText`
reports the blast radius of a bulk edit without writing.

## Connectors

Each supported MCP client has its own dedicated setup guide under [`Connectors/`](Connectors/). The guides cover prerequisites (extension settings, BE-group permissions, host reachability), the per-client UI / CLI / config-file steps to register the connector, smoke-test prompts, and a troubleshooting matrix.

| Client | Setup guide | Auth flow | Reach |
|---|---|---|---|
| **Claude Desktop** | [`Connectors/claude-desktop.md`](Connectors/claude-desktop.md) | Static bearer token (default) or OAuth 2.1 | Local: can reach `localhost`, `*.ddev.site`, internal hosts |
| **Claude.ai** (web) | [`Connectors/claude-ai.md`](Connectors/claude-ai.md) | OAuth 2.1 with DCR | Public HTTPS only: Anthropic-hosted |
| **ChatGPT** | [`Connectors/chatgpt.md`](Connectors/chatgpt.md) | OAuth 2.1 with DCR | Public HTTPS only: OpenAI-hosted |
| **Claude Code** (CLI) | [`Connectors/claude-code.md`](Connectors/claude-code.md) | Static bearer token or OAuth 2.1 (localhost callback) | Local: can reach private hosts |
| **MCP Inspector** | [`Connectors/mcp-inspector.md`](Connectors/mcp-inspector.md) | OAuth 2.1 (localhost:6274 callback) | Local debug tool, browser-based |
| **Open WebUI** (self-hosted) | [`Connectors/openwebui.md`](Connectors/openwebui.md) | OAuth 2.1 with DCR | Wherever your OpenWebUI lives |

For a quick reference of the OAuth `redirect_uri` and browser `origin` values each client expects, see the [Known MCP client callback URLs](#known-mcp-client-callback-urls) table above.

## Connector setup essentials

The per-client guides under [`Connectors/`](Connectors/) all share a few foundational requirements and common failure modes. They are documented once here to avoid repetition; each connector guide cross-references this section.

### BE-group permissions

The TYPO3 backend user the token / OAuth consent is issued for needs the following feature flags on their BE group:

- `enable_mcp_access`: mandatory; the master gate for MCP and the backend dashboard, checked directly on every request (no OAuth scope maps to it)
- `enable_metadata_generation`: for `batchGenerateMetadata`, `generateFileMetadata`, `batchGenerateFileMetadata`, `batchGenerateFolderMetadata`
- `enable_translation`: for all translation tools
- `enable_image_generation`: for `generateImage`
- `enable_massaction_generation`: for batch / background-task tools
- `enable_mcp_media_upload`: for `uploadMedia` (`mcp:media` scope)
- `enable_mcp_rendered_page_read`: for `readRenderedPage` (see "Per-tool permissions" below)
- `enable_audit`: for `auditSeo` / `auditAccessibility` (see "Per-tool permissions" below)

Without `enable_mcp_access` the connector connects but every tool call returns "no permission". Without the feature-specific flags the affected tools simply don't appear in the model's tool list.

#### Per-tool permissions

Most flags gate a whole scope (see the table under [OAuth scopes](#oauth-scopes)). One flag gates a single tool instead, because the tool is far more powerful than the rest of its scope:

| Tool | Scope | Additional flag |
|---|---|---|
| `readRenderedPage` | `mcp:read` | `enable_mcp_rendered_page_read` |
| `auditSeo` | `mcp:read` | `enable_audit` |
| `auditAccessibility` | `mcp:read` | `enable_audit` |
| `auditQuestions` | `mcp:read` | `enable_audit` |
| `auditContentGap` | `mcp:read` | `enable_audit` |
| `auditTopicCluster` | `mcp:read` | `enable_audit` |
| `auditCompetitors` | `mcp:read` | `enable_audit` |
| `readAuditResults` | `mcp:read` | `enable_audit` |

`readRenderedPage` renders the page through a backend preview session of the MCP user, so it also returns **hidden pages, unpublished pages and workspace drafts**: content a plain HTTP fetch of the public URL could never reach. The other `mcp:read` tools return stored records and need no flag, so the gate sits on the tool rather than on the scope: putting it on `mcp:read` would revoke every read tool and change which scopes OAuth grants.

The audit tools (`auditSeo`, `auditAccessibility`, `auditQuestions`, `auditContentGap`, `auditTopicCluster`, `auditCompetitors`) follow the same pattern: they send the page URL to the AutoDudes audit infrastructure (AI Suite Server → Tool Gateway), so page URL and — transitively — public page content leave the TYPO3 instance. They stay read-only towards TYPO3, hence `mcp:read` plus the dedicated `enable_audit` flag instead of an own OAuth scope.

The user's own page permissions still apply on top. Without the flag the tool does not appear in `tools/list`, and a forced call returns a permission error.

### Common troubleshooting

Issues that can happen with any client, regardless of transport:

| Symptom | Cause | Fix |
|---|---|---|
| MCP responds with `"state parameter is required and must be at least 32 characters"` during the OAuth flow | Historical 32-character minimum on the OAuth `state` parameter | In `AuthorizationEndpoint.php`, change `< 32` to `< 22` (OAuth 2.1 BCP / RFC 9700 §4.7), applies to any OAuth client whose default state length is below 32 |
| MCP responds with `"ArgumentCountError: Too few arguments to RateLimiter::__construct"` | DI container cache is stale after a code update to the `RateLimiter` class | Flush the TYPO3 cache + DI container cache (typically by removing the generated DI container under `var/cache/code/di/` and clearing caches in the backend) |
| Persistent 401 on `/aisuite-mcp` after a fresh token, with the request reaching PHP | Apache + mod_php / FCGI strips the `Authorization` header before it reaches PHP. Token endpoints still work (they read the body); the MCP endpoint requires the Bearer header and never sees it | Add the rewrite rule to the web-root `.htaccess`: `RewriteCond %{HTTP:Authorization} ^(.*)` / `RewriteRule .* - [E=HTTP_AUTHORIZATION:%1]` (TYPO3's default `.htaccess` ships this; verify it has not been removed) |
| Tools list is empty after a successful connect | The BE user has no AI Suite feature permissions, so all scope filtering returns empty | Grant `enable_mcp_access` plus the relevant feature permissions on the BE group (see [BE-group permissions](#be-group-permissions) above), then re-authenticate from the connector |
| Connector reports auth / connection failure after a successful OAuth dance, and the webserver access log shows `404` on a path like `/<site-prefix>/aisuite-mcp` | The connector URL contains a TYPO3 site prefix. `McpServerMiddleware` only matches `/aisuite-mcp` at the domain root, so requests with a prefix fall through TYPO3 routing and 404. Editors are often more affected than admins, because the backend URL they see already contains the site prefix and they paste that into the connector | Re-create the connector with the **root URL** (no site prefix): `<host>/aisuite-mcp` |
| Connector misbehaves (401 / 404 / no response), but `var/log/aisuite_mcp.log` has **no entry** for the request | The request never reached the MCP middleware at all; the dedicated log only records requests that hit `McpServerMiddleware` | Inspect the webserver access log for the actual URL that was hit. Typical root causes: site prefix in the connector URL (see previous row), `enableMcp = 0` (returns a generic `404 mcp_disabled` without writing to the MCP log), TLS / firewall rejection at the webserver layer |
| `401` on every MCP / OAuth request, with **no entry** in `aisuite_mcp.log` | The site is behind HTTP Basic Auth (`.htaccess`). Basic Auth and the MCP Bearer token share the `Authorization` header, so the webserver rejects the request before PHP runs | Carve the MCP paths out of Basic Auth, see [Systems behind HTTP Basic Auth](#systems-behind-http-basic-auth) |
| External connector (claude.ai / ChatGPT) reports *"Couldn't register with … sign-in service"*, and `curl` shows `/.well-known/oauth-*` returning `403` while `/aisuite-mcp` works | The site is behind an HTTP Basic Auth / env-flag guard (e.g. `Deny from env=SECURED`) that catches dot-paths. The client cannot complete OAuth discovery, so dynamic client registration fails before it starts | Exempt the discovery + MCP paths from the guard, keyed on `%{THE_REQUEST}`: see [Systems behind HTTP Basic Auth](#systems-behind-http-basic-auth), Apache step 2 |

### Calling tools manually

When using a debug client like the [MCP Inspector](Connectors/mcp-inspector.md), tools can be invoked directly with hand-built JSON arguments instead of going through an LLM. The right-hand pane shows the raw JSON-RPC request and response for every call, invaluable for diagnosing schema mismatches and permission issues.

A useful starter sequence:

| Tool | Arguments | Expected result |
|---|---|---|
| `readServerInfo` | (none) | JSON with TYPO3 version, AI Suite version, MCP version |
| `listTables` | (none) | List of accessible TYPO3 tables for the BE user |
| `readPageTree` | `{ "rootPageId": 0, "depth": 2 }` | Nested JSON of the page tree |

## OAuth scopes

Each tool requires a scope, and each scope is only granted to users whose BE group has at least one of the matching AI Suite feature flags. See `PermissionService::SCOPE_PERMISSION_MAP`.

| Scope | Required BE-group permission(s) | Covers |
|---|---|---|
| `mcp:read` | _none_ (baseline) | All read-only / discovery tools |
| `mcp:write` | _none_ (the client is instructed to preview and get explicit confirmation) | Record CRUD via DataHandler |
| `mcp:generate` | `enable_metadata_generation`, `enable_content_element_generation`, `enable_pages_generation` | AI content / metadata / page-tree / landing-page generation |
| `mcp:translate` | `enable_translation` | All translation tools |
| `mcp:image` | `enable_image_generation` | AI image generation |
| `mcp:media` | `enable_mcp_media_upload` | `uploadMedia` (URL / base64 / online-media import into FAL) |
| `mcp:workflow` | `enable_massaction_generation` | Batch / background task tools |

These seven are the complete list. In particular there is no `mcp:glossary`, no `mcp:easy-language`
and no `mcp:manage` scope; those appeared in an outdated document and have never existed in any
released version. Glossary handling and Easy Language rewrites ride along with `mcp:translate`, and
there is no management scope at all. Requesting an unknown scope fails the authorization request.

## Tools

### Context (`mcp:read`)
| Tool | Purpose |
|---|---|
| `readServerInfo` | TYPO3 + AI Suite + MCP version / config summary |
| `readPageTree` | Traverse the page tree (respecting BE user mounts) |
| `readPageContent` | Read the content of a page (tt_content, optionally nested containers) |
| `readContentTree` | Read the content of every page in a subtree at once (paginated) |
| `readRenderedPage` | The page as a visitor sees it, incl. plugin output; needs `enable_mcp_rendered_page_read` (see [Per-tool permissions](#per-tool-permissions)) |
| `auditSeo` | Full SEO audit for a public page URL (on-page checks, Lighthouse/CrUX, GEO signals; optional focus keyword adds SERP position, top-10 and search volume); needs `enable_audit` (see [Per-tool permissions](#per-tool-permissions)) |
| `auditAccessibility` | WCAG 2.1 AA audit for a public page URL (axe-core + HTML_CodeSniffer, summary + top issue groups); needs `enable_audit` (see [Per-tool permissions](#per-tool-permissions)) |
| `auditQuestions` | Question coverage of a public page URL (GEO/FAQ): People-also-ask + AI-derived questions rated answered/partial/open against the page content; needs `enable_audit`, costs 2 credits |
| `auditContentGap` | Content gap of a public page URL: keywords the page ranks weakly for (public ranking data with volume/difficulty), rated against the page content; needs `enable_audit`, costs 3 credits |
| `auditTopicCluster` | Topic clusters (query fan-out) around a focus keyword, each cluster rated against the page content; needs `enable_audit`, costs 3 credits |
| `auditCompetitors` | Top competitors of the page's domain plus the keyword gap vs. the strongest one, rated against the page content; needs `enable_audit`, costs 3 credits |
| `readAuditResults` | The audit results already stored for a page (all six types, scores/coverage, optional full details per type) — free, no new audit is started; needs `enable_audit` |
| `readEditorialGuidelines` | The tone / target audience / style the editors configured for a page subtree |
| `readChildren` | List a record's container / IRRE children, grouped by relation |
| `searchContent` | Full-text search across pages, content elements and the auto-detected IRRE child tables. Every hit carries `languageUid` and, where the page belongs to a site, its ISO `language` |
| `listFiles` | List files in a FAL storage / folder |
| `readFileInfo` | Metadata for a single sys_file / sys_file_metadata record |
| `listStaleContent` | Detect pages / content that have not been updated for N days |

### Records: discovery (`mcp:read`) and CRUD (`mcp:write` / `mcp:read`)
| Tool | Purpose |
|---|---|
| `listTables` | List tables exposed to MCP (all tables the BE user can read, minus `mcpExcludedTables`) |
| `readRecordSchema` | Return TCA schema for a table (fields, types, defaults) |
| `listPageTypes` | List available page doktypes |
| `listContentTypes` | List available tt_content CTypes and valid colPos for a page; `includeContainers` adds the containers already on it |
| `readFlexFormSchema` | Resolve the inner schema of a FlexForm field (default `tt_content.pi_flexform`), sheets, fields, types, select options. Pass `recordUid` or a `type` hint when the data structure depends on the record type |
| `previewRecords` | Build a preview of a DataHandler operation without persisting |
| `readRecords` | Read records by table + UID(s) |
| `compareWithLive` | Diff workspace draft vs live (changed/added/removed fields), requires a non-live workspace session |
| `writeRecords` | Create / update records via DataHandler (workspace-aware). A top-level `table` fills in for entries that carry none, and a `translations` object writes the linked translations along with the record (see [Writing translations](#writing-translations)) |
| `copyRecords` | Copy one or more records (single params, or a `copies` batch array) |
| `moveRecords` | Move one or more records (single params, or a `moves` batch array) |
| `localizeRecord` | Localize a record into a target language (creates the translation shell; no AI, no credits) |
| `deleteRecords` | Soft-delete records, annotated `destructiveHint`, so the client raises its approval dialog |
| `savePageTree` | Persist a generated page tree |
| `replaceText` | Literal search/replace inside a single field, without resending the whole field |
| `patchText` | Several replacements in one field, applied atomically |
| `bulkReplaceText` | The same replacement across all child records of a parent |
| `copyMediaReference` | Copy a file reference from a source field onto a target field |
| `replaceMediaReference` | Swap the file behind an existing file reference |

#### Code editor fields

Write tools do not store markup a field cannot hold: a value going into a plain TCA `text` or `input`
field has its tags removed and its whitespace normalised, so a model that answers in HTML does not
put `<p>` into a page title. Two kinds of field are exempt, because there the markup **is** the value:

- **Rich text fields** (`enableRichtext`, e.g. `bodytext` of `textmedia`) are stored as sent. They pass
  through TYPO3's own `RteHtmlParser` + HTML sanitizer on save, exactly like manual backend editing.
- **Code editor fields**: TCA `text` with a code editor `renderType`/`format`, the prominent one being
  `tt_content.bodytext` of CType `html`. TYPO3 renders those unfiltered, so they are gated by
  `mcpAllowRawHtmlWrite` (default off): with the setting on the source round-trips byte for byte, with
  it off a write carrying markup is rejected with `unsupported_html` and the stored value is left alone.

`readRecordSchema` reports the distinction per field as `kind:rte`, `kind:html` or `kind:text`. When
editing such a field, read it with `readRecords(raw: true)` (a normal read returns a tag-stripped
preview) or, better, change it in place with `patchText` / `replaceText`.

#### Writing translations

A record entry may carry a `translations` object next to `fields`, keyed by **ISO language code**:

```json
{
  "records": [{
    "table": "tt_content",
    "pid": 354,
    "fields": { "CType": "text", "header": "Einstellungen" },
    "translations": { "en": { "header": "Settings" } }
  }]
}
```

The translation shell is created with TYPO3's own `localize` command, the same path the backend
takes, so the language field, the translation parent, `l10n_source`, `l10n_state`, `l10n_mode:
exclude` and any inline children are handled by the core. The given fields are then written into that
shell. It is created hidden, as TYPO3 does it.

Calling the same payload twice **updates** the existing translation instead of creating a second one,
so re-sending a batch after a partial failure is safe. `translations` also works on an entry that
carries a `uid`: that record is the origin. Nested inline children cannot be translated in the same
call; write them first, then translate them by uid.

#### Field name aliases

`tt_content` kept the legacy `l18n_parent`, while `pages` and most hand-written child tables use
`l10n_parent`. Both spellings are accepted on read and on write and mapped onto whatever the table
really calls it (same for `l10n_source` / `l18n_source`); the result says when it happened. A name the
table genuinely has is never rewritten.

### Generation (`mcp:generate`)
| Tool | Purpose |
|---|---|
| `generateFileMetadata` | Generate alt text / title / description for a file |

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
| `uploadMedia` | Upload one or more existing images/videos into FAL: by remote http(s) URL (downloaded), inline base64 (`content`), or a YouTube/Vimeo link (stored as an online-media reference). No AI, no credits. |

`uploadMedia` takes a `media` array; each item carries exactly one source (`url` **or** `content`) plus optional `fileName`, `targetFolder` and metadata (`title`, `alternative`, `description`). Items are processed independently; one failing item does not abort the batch.

**Security.** Remote URL fetching is the sensitive part and is SSRF-guarded by `RemoteMediaService`: only `http`/`https`, every resolved IP must be public (private, loopback, link-local incl. the `169.254.169.254` cloud-metadata endpoint, and reserved ranges are rejected, IPv4 + IPv6), redirects are followed manually and re-validated per hop, and the download is streamed with a hard size cap. Blocked targets are logged at WARNING. Beyond the OAuth scope + `enable_mcp_media_upload` flag, FAL filemount permissions on the target folder still apply. Tunables (`ext_conf`): `mcpMediaDefaultFolder`, `mcpMediaMaxSizeMb`, `mcpMediaAllowedExtensions` (SVG excluded by default, XSS risk), `mcpMediaAllowUrlFetch` (kill-switch for URL downloads), `mcpMediaHostDenylist`. Large videos should be supplied via `url` or an online-media link rather than base64.

### Workflow (`mcp:workflow` / `mcp:generate`)
Batch tools run asynchronously and return a task ID. Poll via `readTaskStatus`, retrieve results via `readTaskResults`.

| Tool | Purpose |
|---|---|
| `batchGenerateMetadata` | Page metadata in bulk, for an explicit UID list or a whole page subtree |
| `batchGenerateFileMetadata` | File metadata for an explicit list of files |
| `batchGenerateFolderMetadata` | File metadata for every file in a folder |
| `batchTranslatePage` | Translate multiple pages |
| `batchTranslateFileMetadata` | Translate file metadata for an explicit list of files |
| `batchTranslateFolderMetadata` | Translate file metadata for every file in a folder |
| `readTaskStatus` | Status of a background task |
| `readTaskResults` | Fetch paginated results (read-only) |
| `applyTaskResults` | Write the translations of a finished translation batch into the localization records |

`batchGenerateMetadata` takes its targets one of two ways, and exactly one of them: `pageIds` (an explicit UID array) **or** `rootPageId` (a page and everything below it, resolved server-side). Passing both is an error rather than a guess about which one wins; passing neither is an error too. The schema therefore marks nothing as required, because "exactly one of two" is only expressible as a top-level `oneOf`, which no provider loads reliably as a tool (guarded by `ToolSchemaCompatibilityTest`). `recursive` (default `true`) decides whether a `rootPageId` walks the whole subtree or stops at its direct children; the root page itself is always included.

**Cost cap.** A `rootPageId` expands to at most **50 pages**. Beyond that the call is refused before anything is billed, naming the number of pages it found. The reason is asymmetric: this tool spends credits per page, and a subtree is a quantity the caller never counted: "everything below the site root" is one short sentence away from a four-figure charge. An explicit `pageIds` list is a quantity the caller did state, so it is not capped. To work within the cap, pick a deeper root, set `recursive` to `false`, or pass the UIDs explicitly.

## Operating guidelines

`Classes/Mcp/Utility/OperatingGuidelines.php` is the single source of the normative text the server sends to the model. It ships as `initialize.instructions` (once per session, cached by the provider alongside the tool definitions) and is also readable as the `aisuite://guidelines` resource. Tool descriptions never repeat it: a section is sent once per session, a description on every turn (enforced by `ToolDescriptionConventionTest`).

Nine sections are sent, in this order:

| Section | Covers |
|---|---|
| `targetPage` | Resolving which page the user means |
| `defaults` | Site / language / write-mode defaults |
| `discoverFields` | Read the schema before writing; never guess field names |
| `rules` | Hard constraints on record writes |
| `credits` | Which tools spend credits |
| `smallEdits` | Prefer `replaceText` / `patchText` over resending whole fields |
| `workspace` | What the active write mode means for reversibility |
| `batchVsSingle` | When to use a `batch*` tool instead of looping |
| `bulkOps` | Bulk operations across many records |

**Approval is the host's job, not the model's.** The guidelines deliberately contain no rule telling the model to wait for confirmation before writing: measured against a benchmark, gpt-5.4-nano and gpt-oss-120b obeyed such a rule literally: they previewed, then waited forever for a human who was not there. Confirmation happens outside the model: MCP clients raise their own approval dialog, and ChEddi stops write/destructive calls with `needsConfirm`. `previewRecords` is offered as a read-only old→new diff, but nothing enforces it server-side; what guarantees reversibility is the write mode (see [Write modes](#write-modes)).

Batch tools never auto-persist: they return suggestions, which `applyTaskResults` writes only when called.

## Custom tools

Need a tool that doesn't exist yet: pulling data from a project-specific table, kicking off a domain workflow, exposing a sitepackage helper to the model? You can extend the MCP server from your own extension.

### Anatomy of a tool

Every tool implements `AutoDudes\AiSuiteMcp\Mcp\ToolInterface`:

```php
public function getName(): string;           // unique tool name, used by the LLM
public function getDescription(): string;    // shown to the model when picking tools
public function getSchema(): array;          // JSON Schema for the input arguments
public function execute(array $params): CallToolResult;
public function getRequiredScope(): ?string; // null = no scope check
```

`ToolInterface` carries `#[AutoconfigureTag('aisuite.mcp.tool')]`, so any service implementing it is picked up automatically by `ToolRegistry`: no manual `Services.yaml` wiring needed, just make sure your extension's `Configuration/Services.php` autowires + autoconfigures the namespace.

### Trust boundary

`ToolRegistry::validateToolOrigin()` enforces a hard rule:

- Tools under `AutoDudes\AiSuiteMcp\Mcp\Tool\` may extend `AbstractTool` directly (full backend access, full DataHandler).
- Tools under any other namespace **must extend `AbstractCustomTool`**: its `final doExecute()` routes calls through the AI Suite Server so credit accounting and the central security policy stay in place.

Third-party tools that try to extend `AbstractTool` directly are silently rejected at boot time and logged as a warning to `aisuite_mcp.log`. Don't bypass this; it's there to prevent custom code from siphoning AI provider calls outside the credits pipeline.

> ⚠️ **Status:** `AbstractCustomTool` is the planned public extension API and currently a stub (`Classes/Mcp/CustomTool/`). Until it ships, third-party tools cannot register. If you have a use case that doesn't fit any of the built-in tools, [open a feedback issue](#feedback), we'd like to know what shape the API needs to take before we freeze it.

### Adding a tool inside this extension

For tools that legitimately belong here (built-in tools), the pattern is:

1. Create a class under `Classes/Mcp/Tool/<Category>/MyNewTool.php` extending `AbstractTool` (or `AbstractAiTool` / `AbstractTranslateTool` for AI-powered tools that need credit accounting and model routing).
2. Add `#[AutoconfigureTag('aisuite.mcp.tool')]` if your class doesn't pick it up via `ToolInterface` (in practice it does automatically).
3. Implement `getName()`, `getDescription()`, `getSchema()`, `getRequiredScope()`, and `doExecute()`: never `execute()`, which is `final` on `AbstractTool` and runs the validation / permissions / error-handling pipeline.
4. Inject any extra services through your own constructor; the bundled context (`ToolContext`) already covers the common ones (`McpUserContext`, `PermissionService`, logger, `LocalizationService`, `BackendUserService`, …).
5. Map your scope to the right BE-group flag in `PermissionService::SCOPE_PERMISSION_MAP` if you introduce a new scope.

Run the test suite (`phpunit -c Tests/UnitTests.xml`, `phpunit -c Tests/FunctionalTests.xml`) and verify the tool shows up in `readServerInfo` and on a connector smoke test.

## Console commands

```bash
# Create a test token (bypasses OAuth flow, development only)
vendor/bin/typo3 ai-suite-mcp:create-token --user=1
vendor/bin/typo3 ai-suite-mcp:create-token --user=admin --scopes="mcp:read mcp:write mcp:generate"
vendor/bin/typo3 ai-suite-mcp:create-token --user=1 --client=mcp-inspector

# Clean up expired OAuth state, session files and completed task files
vendor/bin/typo3 ai-suite-mcp:cleanup

# Run a local MCP server over stdio (trusted local CLI clients only, see "Local stdio transport")
vendor/bin/typo3 ai-suite-mcp:server --user=1
vendor/bin/typo3 ai-suite-mcp:server --user=editor --scopes="mcp:read mcp:write"
```

`ai-suite-mcp:cleanup` removes:
- authorization codes older than 10 min
- access tokens older than the token lifetime + 7-day buffer (37 days by default)
- session files under `var/aisuite_mcp_sessions/` older than twice `mcpSessionTimeoutSeconds`, at least one hour (one hour at the default of 1800 s)
- background task files under `var/mcp_tasks/` older than 30 days

Schedule it via the TYPO3 Scheduler or cron. How many session files are lying around is reported by
`readServerInfo` and, for admins, as the *MCP Session Store* entry in the Reports module.

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

No wrapper script is needed; `command` + `args` do everything inline. GUI clients (Claude
Desktop) launch the command from their own working directory with a minimal `PATH`, so use
**absolute** paths to the launcher.

**Composer install (TYPO3 reachable directly, no DDEV):**

```json
{
  "mcpServers": {
    "typo3-ai-suite": {
      "command": "/bin/bash",
      "args": ["-c", "cd '<project-root>' && exec ./vendor/bin/typo3 ai-suite-mcp:server --user=1"]
    }
  }
}
```

`<project-root>` contains `composer.json`; the bin dir defaults to `vendor/bin/` (relocatable via
`config.bin-dir`).

**DDEV install**: the `typo3` binary lives inside the web container, so reach it through
`ddev exec`. Two things break a bare `ddev exec` when launched by the client, both solved inline:
`cd` into the project first (DDEV resolves its project from the cwd), and use the absolute `ddev`
path (`which ddev`, e.g. `/opt/homebrew/bin/ddev`):

```json
{
  "mcpServers": {
    "typo3-ai-suite": {
      "command": "/bin/bash",
      "args": ["-c", "cd '<project-root>' && exec '<ddev-path>' exec .Build/bin/typo3 ai-suite-mcp:server --user=1"]
    }
  }
}
```

`exec` replaces `bash` with `ddev` so the pipe is passed through cleanly. Docker Desktop / OrbStack
must be running and `ddev start` run once. A bare-`docker exec -i ddev-<project>-web …` variant also
works (`-i` required, **never `-t`**: a TTY corrupts JSON-RPC framing). For the full
Claude-Desktop walkthrough see [`Connectors/claude-desktop.md`](Connectors/claude-desktop.md).

**Security model.** stdio runs the tools as the given backend user with the scope + BE-group
double gate fully enforced (identical to HTTP). But because the transport is a local pipe, it
**bypasses OAuth, the HTTPS gate, per-token rate limiting and the request-body cap**: those are
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

- **HTTPS required** in production (localhost + `*.ddev.site` exempted; override with `mcpAllowHttp=1`: **not** for production).
- **Request-body cap** of 1 MB per MCP request.
- **Rate limiting**: 100 requests / minute per Bearer token (responds `429` with `Retry-After: 60`).
- **OAuth 2.1 with PKCE**, no implicit / password grants.
- **Dynamic Client Registration** is permitted but constrained by `mcpAllowedClientIds` / `mcpAllowedRedirectUris`.
- **Password change revokes all tokens** for that BE user (`PasswordChangeHook` on `processDatamapClass`).
- **Live backend-user status check** on every request: disabled / deleted users are rejected even if their token is still valid.
- **Scope + permission double check**: an OAuth scope alone is not sufficient; the BE user group must also carry the matching AI Suite feature flag.
- **Raw markup is refused by default**: a write that would put HTML/JavaScript into an unfiltered [code editor field](#code-editor-fields) fails unless `mcpAllowRawHtmlWrite` is enabled.
- **Reports module** surfaces misconfigurations: HTTP allowed, empty allowlists in production. Check **System → Reports → AI Suite MCP Security**.

## Production deployment

The settings, security gates, and connector flows above are sufficient to *run* the MCP server. Operating it stably in production additionally requires getting the topics in this section right; none of them are enforced by the code, but ignoring any one of them tends to cause silent failures (lost session state, stale tokens, audit-log gaps, …) rather than loud errors.

### Reverse proxy & load balancer

- **HTTPS detection:** `McpServerMiddleware::enforceHttps()` honors `X-Forwarded-Proto: https` in addition to the request scheme. If your CDN or load balancer terminates TLS and forwards plain HTTP to the origin, set this header on the proxy.
- **HTTPS-gate trust boundary:** `X-Forwarded-Proto` is accepted from *any* upstream; the HTTPS gate does **not** consult the `mcpTrustedProxies` list (that setting only governs audit-log IP resolution, see below). A direct client that sends `X-Forwarded-Proto: https` would bypass the HTTPS gate. Make sure your proxy strips the header from inbound traffic before re-setting it, or restrict access to the origin to the proxy IPs only (firewall / VPC).
- **Client IP in audit logs:** OAuth audit entries (`token issued`, `token revoked`, …) record the client IP resolved by `ClientIpService`. By default that is the peer IP (`REMOTE_ADDR`), which behind a proxy is the proxy IP, not the end-user IP. Set `mcpTrustedProxies` to your proxy IPs / CIDRs and the service walks the `X-Forwarded-For` chain from the right, skipping trusted hops, and logs the first untrusted address (the real client). When `mcpTrustedProxies` is empty, `X-Forwarded-For` is ignored entirely, so a client cannot spoof its IP in the audit log by sending the header itself.

### Webserver setup

**Apache** (mod_php / FCGI): TYPO3's default `.htaccess` ships the Authorization-header rewrite the MCP endpoint needs. Verify the rule is intact (the exact rule is in [Common troubleshooting](#common-troubleshooting)).

**Nginx** (php-fpm): the equivalent rewrite is per-`location` in your nginx config. The MCP endpoint requires the Authorization header to be forwarded to PHP explicitly:

```nginx
location ~ \.php$ {
    fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    # ... your existing fastcgi_params include
}
```

Also raise `client_max_body_size` to at least the MCP body cap (1 MB) plus margin for batch payloads; `8m` is a safe default.

### Systems behind HTTP Basic Auth

Sites are often shielded with HTTP Basic Auth (`.htaccess` / `AuthType Basic`), staging environments, internal instances, not-yet-launched sites, and so on. This **collides** with the MCP server, because Basic Auth and the MCP endpoint both use the same `Authorization` request header: Basic Auth sends `Authorization: Basic …`, while the MCP endpoint requires `Authorization: Bearer <token>`. A request can carry only one `Authorization` header, so a connector placed behind Basic Auth fails: the webserver answers `401` before PHP ever runs (the request never reaches `McpServerMiddleware`, so there is also no entry in `aisuite_mcp.log`).

You can run MCP on a Basic-Auth-protected system, but the MCP paths must be carved out. The server's own OAuth 2.1 + Bearer-token auth then provides the protection on those paths.

Which paths must be reachable **without** Basic Auth:
- `/.well-known/oauth-authorization-server` and `/.well-known/oauth-protected-resource`: OAuth discovery (RFC 8414 / 9728); the client fetches these first.
- `/.well-known/openid-configuration`: the RFC 8414 §5 fallback path, serving the same document as `oauth-authorization-server`. Several clients probe it first, so it needs the same exemption.
- `/aisuite-mcp/oauth/*`: the OAuth flow. The interactive login on `/aisuite-mcp/oauth/authorize` *is* the TYPO3 backend login (the actual "log in" step), so Basic Auth must not mask it.
- `/aisuite-mcp` (and sub-paths), the MCP endpoint itself; its Bearer-token auth already protects it (every token is bound to a concrete BE user with enforced permissions).

#### Apache (`.htaccess`)

> ⚠️ Do **not** use `<Location>` / `<LocationMatch>` for this; they are only valid in the server / vhost configuration. Placing them in `.htaccess` triggers `500 Internal Server Error` (`<Location> not allowed here` in the Apache error log). Use the `Require expr` / `SetEnvIf` forms below.

**1. Match on `%{THE_REQUEST}`, not `Request_URI`.** On TYPO3, the front-controller rewrite rewrites the request to `index.php` *before* the authorization phase runs, so `SetEnvIf Request_URI …` / `<If "%{REQUEST_URI} …">` no longer see the original path and silently fail to match. `%{THE_REQUEST}` is the verbatim original request line (e.g. `GET /.well-known/oauth-authorization-server HTTP/1.1`) and stays stable across internal rewrites, so always key the exemptions off it.

In the web-root `.htaccess`, combine your existing Basic Auth with `<RequireAny>` and exempt the MCP paths via `Require expr`. Adjust `AuthUserFile` to your setup and **replace** your current `Require valid-user` line with this block:

```apache
AuthType Basic
AuthName "Restricted"
AuthUserFile /path/to/.htpasswd

<RequireAny>
    Require expr %{THE_REQUEST} =~ m#\s/\.well-known/(oauth-|openid-configuration)#
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

This host-based `Deny` is evaluated independently of the `<RequireAny>` above and will still `403` the MCP/discovery paths (often only the dot-paths visibly fail, while `/aisuite-mcp` appears to work, an artifact of mixing legacy `Satisfy`/`Order`/`Allow`/`Deny` with `Require`). Unset the flag for the MCP paths, right after it is set:

```apache
SetEnvIf Host staging\.example\.com$ SECURED=yes
# Exempt OAuth discovery + MCP endpoint from the staging guard:
SetEnvIfExpr "%{THE_REQUEST} =~ m#\s/(\.well-known/(oauth-|openid-configuration)|aisuite-mcp)#" !SECURED
```

(Again `THE_REQUEST`, not `Request_URI`: and `SetEnvIfExpr` because `SetEnvIf` cannot match `THE_REQUEST`.)

**3. Make sure the Authorization-header rewrite is present** (see [Webserver setup](#webserver-setup) above):

```apache
RewriteCond %{HTTP:Authorization} ^(.*)
RewriteRule .* - [E=HTTP_AUTHORIZATION:%1]
```

TYPO3's default `.htaccess` ships it, but verify it survived customisation; without it the Bearer header never reaches PHP and the MCP endpoint returns `401` even though discovery and the OAuth flow work.

#### Nginx

Nginx has no per-directory configuration, so the exemption is expressed as a `map` that switches the realm off for the MCP paths. Define it at `http` level and reference it from the `server` block:

```nginx
map $request_uri $auth_realm {
    default                              "Restricted Area";
    ~^/aisuite-mcp                       "off";
    ~^/\.well-known/oauth-               "off";
    ~^/\.well-known/openid-configuration "off";
}

server {
    # ...
    auth_basic           $auth_realm;
    auth_basic_user_file /etc/nginx/.htpasswd;
}
```

`auth_basic` accepts a variable, and the literal value `off` disables the check for that request. That is the only way to relax Basic Auth per URI without rebuilding the `location` structure.

**Use prefix regexes, not exact paths.** `$request_uri` is the raw request target *including the query string*, so an exact key such as `"/aisuite-mcp/oauth/authorize"` never matches: the authorize call always arrives as `/aisuite-mcp/oauth/authorize?response_type=code&client_id=…&state=…`. This fails in a way that reads as partial success, because discovery and `/aisuite-mcp/health` are requested without a query string and do match — so the connector completes discovery and then dies at the login step. `~^/aisuite-mcp` covers the transport endpoint, `/health` and all four `/oauth/…` endpoints in one line.

Also confirm that the Authorization header reaches PHP (see [Webserver setup](#webserver-setup) above). Both problems produce a `401`, and the response tells them apart:

| Response | Meaning |
|---|---|
| `401` with `WWW-Authenticate: Basic`, **no** entry in `aisuite_mcp.log` | The exemption is not matching; the request never reached PHP. Check the `map`. |
| `401` with `WWW-Authenticate: Bearer`, entry in `aisuite_mcp.log` | The exemption works, but the `Authorization` header is not forwarded. Add `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`. |

The rest of the site stays behind Basic Auth; only the MCP surface is opened up, and it remains protected by OAuth. To verify, `curl` the discovery URLs and the endpoint:

```bash
curl -i "https://<host>/.well-known/oauth-protected-resource"     # expect 200 JSON
curl -i "https://<host>/.well-known/oauth-authorization-server"   # expect 200 JSON
curl -i "https://<host>/.well-known/openid-configuration"         # expect 200 JSON
curl -i "https://<host>/aisuite-mcp/health"                       # expect 200 JSON
curl -i "https://<host>/aisuite-mcp/oauth/authorize?response_type=code&client_id=x&state=aaaaaaaaaaaaaaaaaaaaaa"
curl -i "https://<host>/typo3/"                                   # expect 401 WWW-Authenticate: Basic
```

The authorize call is the one that must not be skipped: it is the only request here carrying a query string, which is what an exact-match exemption fails on. What it answers does not matter, the dummy `client_id` will be rejected; what matters is that the response carries no `WWW-Authenticate: Basic`. The last call proves the rest of the site is still protected.

A `403` here means an env-flag guard (Apache step 2) or a host-level dot-path block is still catching the path; a `401` with `WWW-Authenticate: Basic` means the Basic-Auth exemption is not matching — on Apache, check that it keys off `THE_REQUEST`, on nginx that the `map` uses prefix regexes.

### Scheduled maintenance

`ai-suite-mcp:cleanup` is **required** in production, not optional. Run it via TYPO3 Scheduler or system cron at least **hourly**. It removes:

- authorization codes older than 10 min
- access tokens older than the token lifetime + 7-day buffer (37 days at default `mcpTokenLifetimeDays = 30`)
- **revoked tokens older than 30 days**: hard-deleted from `tx_aisuite_oauth_tokens` to meet GDPR right-to-erasure expectations. Soft-deleted entries (`deleted = 1`) are kept for 30 days so refresh-token theft detection (S24) can still recognise reuse of a rotated token; after that window the signal is moot
- session files under `var/aisuite_mcp_sessions/` older than twice `mcpSessionTimeoutSeconds`, at least one hour (one hour at the default of 1800 s)
- background-task files under `var/mcp_tasks/` older than 30 days

Watch the session directory in particular: a stateless MCP client leaves one file behind **per request**, so the count climbs quickly on a busy site. The Reports module shows it as *MCP Session Store* and turns to a warning above 500 files; `readServerInfo` reports the same number and logs a warning at WARNING level once the threshold is crossed.

Without it, the authorization-code table grows unbounded, on-disk session and task directories balloon, and revoked or expired tokens accumulate in `tx_aisuite_oauth_tokens`. For high-volume sites (>100 concurrent users) monitor row counts in `tx_aisuite_oauth_tokens` and tighten `mcpTokenLifetimeDays` if growth outpaces the cleanup cycle.

### Runtime & scaling

- **PHP runtime:** the extension targets a classic **PHP-FPM / mod_php** request lifecycle (one request per process). It is **not** validated on persistent-worker runtimes such as **FrankenPHP** or **RoadRunner**: an in-process, per-instance cache (`AbstractTool::$readablePageIdsCache`) assumes the process ends with the request, and reusing it across users in a long-lived worker could leak read-access decisions between backend users. Do not run the MCP server on a persistent-worker SAPI until that cache is moved to request scope.
- **Multi-host / load-balanced setups:** MCP transport sessions (`var/aisuite_mcp_sessions/`) and background-task results (`var/mcp_tasks/`) live on the **local filesystem**. Across multiple app nodes you therefore need either **sticky sessions** (pin a client to one node) or a **shared filesystem** for those two directories. OAuth state itself lives in the database and is shared automatically; only the on-disk session/task state needs this treatment.

### Logging & retention

Two dedicated log files are configured for the `AutoDudes.AiSuiteMcp` namespace in `ext_localconf.php`:

- `var/log/aisuite_mcp.log`: **INFO+** (verbose, full trace). Useful for forensic debugging and per-request audit replay. **Toggleable** via the `mcpLogVerbose` extension setting (default: on). Disable in mature production deployments to reduce I/O and PII surface; the WARNING+ alert log stays active either way.
- `var/log/aisuite_mcp_warnings.log`: **WARNING+** only. Always active. Stays small; if it is non-empty, something is worth investigating (rate-limit hits, tool execution failures, OAuth misconfigurations). Designed for monitoring / paging; point your log shipper or `tail -F` here in production.

What gets logged:

- OAuth events (`token issued`, refreshed, revoked) with client_id, BE-user UID, and (real) client IP
- MCP request method, path, status code, and the first ~300 characters of the request body, which routinely contains user prompts, page content snippets, file metadata, etc.
- Tool execution errors with full exception traces

### Outbound network egress

MCP tools that call AI providers (`generate*`, `translate*`, `batch*`) inherit the network configuration of the parent `autodudes/ai-suite` extension. Outbound HTTPS is required to:

- the API host(s) of every provider you have enabled in AI Suite (Anthropic, OpenAI, Mittwald AI, Midjourney, Flux, DeepL, …)
- the AutoDudes credit-accounting backend, if licensed via AutoDudes

In hardened environments with strict egress firewalls, allowlist the provider hosts that are actually configured in your AI Suite settings. The MCP endpoint itself does not introduce additional outbound destinations beyond what AI Suite already uses.

## Feedback

We're actively shaping this extension and the upcoming public custom-tool API. If you try it out, especially against a real editorial workflow, we'd love to hear from you:

- 🐛 **Bugs / regressions** → please file an issue with the relevant `aisuite_mcp_warnings.log` excerpt and the connector you used.
- 💡 **Tool gaps** → if you reached for a tool that doesn't exist (a third-party table you'd like discoverable, a workflow not covered by the built-ins), tell us what the LLM should have been able to do. This is the most valuable feedback for the `AbstractCustomTool` API design.
- 🔌 **New connectors** → if your favourite MCP client isn't in `Connectors/`, share the redirect URI / origin / auth flow it expects and we'll add a guide.
- 🔒 **Security findings** → please contact us directly rather than opening a public issue.

The fastest way to reach us is the [#ai-suite channel on TYPO3 Slack](https://typo3.slack.com/archives/C05QAN1KNVD). You can also reach us via [service@autodudes.de](mailto:service@autodudes.de).

## License

GPL-2.0-or-later
