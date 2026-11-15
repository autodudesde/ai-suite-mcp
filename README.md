# TYPO3 + MCP + AI Suite

> 🚧 **Under active development.** This extension is in `beta` state and evolving fast. Tool signatures, settings keys, and the upcoming `AbstractCustomTool` API may still change between minor versions. Production deployments are possible (and encouraged for early feedback), but pin a version and review the changelog before upgrading. Join us on Slack: [#ai-suite on TYPO3 Slack](https://typo3.slack.com/archives/C05QAN1KNVD) to follow development, raise issues, or shape the roadmap.

An MCP (Model Context Protocol) server for **TYPO3**. It connects Claude Desktop, Claude.ai, ChatGPT, MCP Inspector and other MCP-compatible clients directly to your TYPO3 backend, so the model can walk the page tree, read and search content, and create or edit records through DataHandler, as the logged-in backend user, with that user's permissions.

[AI Suite](https://www.autodudes.de/) is the technical foundation this builds on: it ships the extension infrastructure, the backend-group permission model, the TYPO3 version-compatibility layer and the shared services. It is a hard dependency (`autodudes/ai-suite` `^12.26.0 || ^13.20.0 || ^14.8.0`) and Composer pulls it in for you.

**What it is not is a paywall.** The MCP server is useful on its own, with no AI Suite account, no API key and no credits: your MCP client already brings the model that does the thinking. AI Suite's own AI providers (OpenAI, Anthropic, Mittwald AI, IONOS AI Model Hub incl. Flux, DeepL, Google Translate, Midjourney, …) are an *optional* add-on for the twelve tools that generate, translate or audit server-side.

## Do I need an AI Suite subscription?

For most of what this extension does, no.

**35 of the 47 tools run entirely inside your TYPO3 installation** and cost nothing beyond what your MCP client charges you: the whole page tree, content and file reads, `searchContent`, schema discovery, record CRUD through DataHandler, the safe-edit tools, `localizeRecord`, workspace review (`compareWithLive`), stored audit results (`readAuditResults`), `uploadMedia` and `moveFile`. Your client's model composes the content; TYPO3 stores it. These tools send no request to the AI Suite Server (`tools/list` only checks whether it is reachable, and `readTaskStatus` refreshes a batch status from it free of charge), and the transport does not check for an API key.

**12 tools do call the AI Suite Server and spend credits**, because the generation, translation or audit happens there:

| Scope | Tools |
|---|---|
| `mcp:generate` | `generateFileMetadata` |
| `mcp:image` | `generateImage` |
| `mcp:translate` | `translateRecord`, `translatePage`, `translateFileMetadata` |
| `mcp:workflow` | `batchGenerateMetadata`, `batchGenerateFileMetadata`, `batchTranslatePage`, `batchTranslateFileMetadata` |
| `mcp:read` (plus the `enable_audit` flag) | `auditSeo`, `auditAccessibility`, `auditContent` |

These twelve are the entire credit-costing surface; without an AI Suite account they are the only ones you lose. The tools that poll and persist their results are free: `readTaskStatus`, `readTaskResults` (`mcp:read`) and `applyTaskResults` (`mcp:write`). So is granting the `mcp:workflow` scope itself.

Translation is the one place where the distinction is easy to miss, and it depends on the `model` argument. Called **without** `model`, `translateRecord` and `translatePage` cost nothing: they create the translation records, hand the source fields back together with the glossary of the content's site, and your client's model translates them and writes the result with `writeRecords`. A link field is handed back as its visible text, marked `(link text)`; writing the translated text keeps the link target (see [Writing translations](#writing-translations)). Called **with** a `model`, the work goes to a translation model on the server and is billed. `translateFileMetadata` and the `batch*` tools always need a model. `localizeRecord` creates an empty translation shell with TYPO3's own localization machinery and costs nothing.

## What you can do with it

Once connected, your MCP client can drive the TYPO3 backend the same way an editor would, but without leaving the chat.

- 🧭 **Walk the page tree, read pages, search content**: the model gets first-class access to every page, content element and FAL file the BE user can see.
- ✍️ **Create & rewrite content**: the client model composes tt_content elements and page trees itself and persists them through DataHandler, honouring the editors' guidelines (`readEditorialGuidelines`). No credits are spent for that.
- 🌍 **Translate anything**: single records, complete pages, file metadata, or whole folders in one batch. *(server-side translation costs credits; `localizeRecord` + your own model does not)*
- 🏷️ **Fill in metadata at scale**: SEO titles, descriptions, OG / Twitter tags, alt texts, file metadata. Single record or bulk over a whole folder / page subtree. *(costs credits)*
- 🖼️ **Generate images straight into FAL**: the result lands as a real `sys_file`, ready to be referenced. *(costs credits)*
- 🧱 **Edit records safely**: every CRUD tool runs through DataHandler; approval comes from the MCP client's dialog (see [Why the client asks for approval](#why-the-client-asks-for-approval)), and `previewRecords` offers an optional old→new diff. Reversibility is guaranteed by the `workspace` write mode (the default), which keeps every change in a reviewable draft.
- 🧰 **Workspace-aware writes**: defaults to routing changes through a TYPO3 draft workspace (auto-creating a per-user one when needed); tokens can even be pinned to a specific workspace.
- 🧩 **Works with EXT:container and your custom records**: container children, third-party tables (news, products, custom CTypes) are first-class.
- ⏱️ **Background batch jobs**: long-running translations / metadata generation get an async task ID; results come back as suggestions you approve. *(costs credits)*
- 🔐 **Production-grade auth**: OAuth 2.1 + PKCE with dynamic client registration, per-token rate limiting, full HTTPS enforcement, password-change revocation.
- 👤 **Respects TYPO3 BE-user permissions**: every tool call runs as the linked backend user; page mounts, file mounts, table/field access rights and AI Suite per-feature/per-model BE-group flags are enforced on every request.
- 🖥️ **Managed from the backend**: a dedicated MCP module issues and revokes tokens without the CLI, and an **MCP** entry in the AI Suite button bar leads there (both gated by `enable_mcp_access`).
- 📚 **Resources and prompts**: besides the tools, the server exposes the operating guidelines, a page's editorial instructions, the site configuration, the credit status and the usage dashboard as MCP resources, and the AI Suite prompt templates as MCP prompts.
- 📊 **Reports + dedicated logs**: TYPO3 Reports module flags misconfigurations; two log streams (verbose + WARNING-only) keep ops monitoring simple.

## AI capabilities & available models

**This section covers the optional part**: the twelve tools listed above that route to the AI Suite Server. Skip it if your MCP client's own model is doing the work.

These tools delegate the actual generation / translation to the parent AI Suite extension, so every model you've licensed there is also available to your MCP client. Permissions are still gated per BE-group feature flag and per AI model.

| Capability | MCP tools | Models available via AI Suite |
|---|---|---|
| **Page metadata** (SEO, OG, Twitter) | `batchGenerateMetadata` | ChatGPT, Anthropic, Mittwald AI, Meta Llama-3.3 (70B-Instruct) |
| **File metadata** (alt, title, description) | `generateFileMetadata`, `batchGenerateFileMetadata` | OpenAI Vision, Mittwald AI Vision |
| **Page-tree** | `savePageTree` | n/a (composed by the client model) |
| **Translation** (records, pages, file metadata) | `translateRecord`, `translatePage`, `translateFileMetadata`, `batchTranslatePage`, `batchTranslate*Metadata` | DeepL, Google Translate, ChatGPT, Anthropic, Mittwald AI |
| **Glossary** | applied automatically by the translation tools: the glossary of the site the content belongs to; file metadata takes it from the optional `pageId` | DeepL, ChatGPT, Anthropic, Mittwald AI |
| **Image generation** | `generateImage` | GPT-Image (OpenAI), Midjourney, Flux |
| **Audits** (SEO, accessibility, GEO questions, content gap, topic clusters, competitors) | `auditSeo`, `auditAccessibility`, `auditContent` | AutoDudes audit infrastructure, reached through the AI Suite Server |

The exact model list available to a given BE user depends on the AI-model permissions configured on their BE group in AI Suite. The model picks itself up automatically from the AI Suite settings; no extra config in MCP.

Beyond the AI-powered tools above, MCP also ships **discovery and editing tools** (no model calls): `readRenderedPage` (the page as a visitor sees it, including plugin output; needs the `enable_mcp_rendered_page_read` flag, see [Per-tool permissions](#per-tool-permissions)), `readEditorialGuidelines` (the tone / target audience / style the editors configured for a page subtree), `listTables`, `readRecordSchema` (with per-field content kind, read-only and relation metadata), `listContentTypes`, `readChildren` (list a record's container/IRRE children), `readPageContent`, `readRecords`, `searchContent` (sweeps every page-bound table with a searchable text field, see [Which tables searchContent sweeps](#which-tables-searchcontent-sweeps); optional `rootPageId` to search one page subtree, single-`field` / `matchHtml` search), `previewRecords` (shows an old→new diff when editing), `writeRecords` (with optional `atomic:true` all-or-nothing batches), `copyRecords`, `moveRecords`, `deleteRecords`, `localizeRecord`, and the safe-edit tools `patchText` / `bulkReplaceText` for small text corrections without resending whole fields (they locate the match ignoring line-ending and spacing differences by default, since stored rich text keeps CRLF that no read shows verbatim; pass `normalizeWhitespace:false` to require a byte-exact match). Media references can be reused/swapped with `copyMediaReference` / `replaceMediaReference`.

## Requirements

- TYPO3 12.4.11 to 14.3.99
- PHP 8.1+
- `autodudes/ai-suite` `^12.26.0 || ^13.20.0 || ^14.8.0` (`ext_emconf`: `12.26.0-14.99.99`): required, and installed automatically. It provides the extension infrastructure, the BE-group permission model, the TYPO3 version-compatibility layer and shared services. An AI Suite **account, API key or credits** are a separate matter and only needed for the twelve server-side tools listed above.
- `typo3/cms-reports` `^12.4.11 || ^13.4.1 || ^14.3.0`
- `logiscape/mcp-sdk-php` `^2.0` (bundled for classic mode, see below)
- `symfony/clock` `^6.4 || ^7.0 || ^8.0` (shipped by the TYPO3 core)
- `typo3/cms-workspaces` `^12.4.11 || ^13.4.1 || ^14.3.0`: required; powers the workspace write modes (the default), including on-demand per-user workspace provisioning
- PHP extensions `curl` and `json`

## Installation

```bash
composer require autodudes/ai-suite-mcp
vendor/bin/typo3 extension:setup
```

Also available on the TYPO3 Extension Repository: [extensions.typo3.org/extension/ai_suite_mcp](https://extensions.typo3.org/extension/ai_suite_mcp).

Then make sure the webserver passes the `Authorization` header on to PHP, see [Webserver setup](#webserver-setup). Without it every request to the MCP endpoint ends in `401`, even with a valid token.

### Classic mode (no Composer)

The extension also runs in TYPO3 12, 13 and 14 installations without Composer. The one library TYPO3 does not ship, `logiscape/mcp-sdk-php`, is bundled in `Resources/Private/PHP/ComposerVendor`. TYPO3 v14 loads it through `providesPackages` in `composer.json`, v12 and v13 through the `autoload` section of `ext_emconf.php` or, when that section is missing as after a download from TER in the Extension Manager, through the bundle's own autoloader. Nothing has to be copied by hand.

1. Activate the system extensions `workspaces` and `reports`.
2. Install and activate `ai_suite`, then `ai_suite_mcp` (Extension Manager upload or TER), in that order.
3. Open **System → Reports → Status Report**. *AI Suite MCP Environment* names missing PHP extensions and an SDK that cannot be loaded, which is what an incompletely uploaded extension looks like.
4. Wherever this README calls `vendor/bin/typo3`, use `typo3/sysext/core/bin/typo3` from the TYPO3 root instead. Without a system cron, run `ai-suite-mcp:cleanup` through the Scheduler task *Execute console commands*.

What differs from a Composer installation:

- **Paths:** `var/` is `typo3temp/var/`, so sessions live in `typo3temp/var/aisuite_mcp_sessions/` and the logs in `typo3temp/var/log/`. TYPO3's `.htaccess` denies web access to that directory on Apache. On nginx add a rule yourself, e.g. `location ^~ /typo3temp/var/ { deny all; }`, or the session files are publicly readable.
- **Host root only:** the MCP endpoint, the OAuth endpoints and the `/.well-known/` documents are answered at the root of the host. A TYPO3 installed in a sub directory (`https://example.com/cms/`) is not supported.
- **Webserver:** the Authorization header rules from [Webserver setup](#webserver-setup) apply unchanged.

## Configuration

All settings live under **Admin Tools → Settings → Extension Configuration → `ai_suite_mcp`**.

| Setting | Default | Description |
|---|---|---|
| `enableMcp` | `0` | Master switch for the MCP endpoint. While disabled, all `/aisuite-mcp*` requests and the `/.well-known/` OAuth documents return `404 mcp_disabled`; only the backend link page `/aisuite-mcp/open` keeps answering. |
| `mcpTokenLifetimeDays` | `30` | Lifetime in days of a token created in the MCP screen or with `ai-suite-mcp:create-token`. These tokens cannot be refreshed; OAuth connections follow the three settings below. |
| `mcpAccessTokenLifetimeMinutes` | `60` | Lifetime of an OAuth access token in minutes. The connector then gets a new one through its refresh token, without asking the editor; a leaked access token is worth an hour, not weeks. `0` = 60 minutes. |
| `mcpRefreshIdleDays` | `30` | A connector that does not refresh for this many days has to be authorized again; every refresh starts the period anew. `0` = 30 days. |
| `mcpRefreshMaxLifetimeDays` | `90` | Maximum lifetime of one authorization in days. Refreshing rotates the tokens but never extends a connection beyond this many days after its authorization; then the connector has to be authorized again. `0` = 90 days. |
| `mcpAllowedOrigins` | _(empty)_ | Comma-separated allowlist of browser origins. Requests without an `Origin` header, from the site's own host, from `localhost` or from `*.ddev.site` are accepted outside the Production context; any other origin must be listed, otherwise the request is rejected with `403 forbidden_origin`. In the Development context every origin is accepted regardless of this list. |
| `mcpAllowedClientIds` | _(empty)_ | Comma-separated allowlist of OAuth `client_id` values. Empty = all clients allowed. Checked at `/oauth/authorize`; a client that registers itself gets a random `mcp-…` id at registration, so a non-empty list admits only ids agreed in advance. |
| `mcpMaxCreditsPerSession` | `0` | Credit budget per access token. Once a token has spent this many AI credits, AI tools send no further requests with it; the call that crosses the limit still returns its result, with a note attached. The total is stored on the token, so neither a new conversation nor a restart resets it; a newly issued token starts from zero. `0` = no budget. |
| `mcpRateLimitRequests` | `100` | MCP requests one access token may send within `mcpRateLimitWindowSeconds`. Batch work and agent loops can exceed the default; raise it when a connector receives `429 rate_limit_exceeded`. `0` = no limit. The OAuth and MFA endpoints and ChEddi keep their own fixed limits, see [Security](#security). |
| `mcpRateLimitWindowSeconds` | `60` | Window the request count above refers to. Values below `1` count as `1`. |
| `mcpRateLimitLockoutSeconds` | `60` | How long a token is refused after exceeding the limit. The `429` answer carries the remaining time in `Retry-After`. Values below `1` count as `1`. |
| `mcpAllowHttp` | `0` | Allow the MCP endpoint over unencrypted HTTP. **Never** enable this in production: Bearer tokens would travel in clear text. Localhost and `*.ddev.site` are exempted from HTTPS enforcement outside the Production context. |
| `mcpWriteMode` | `workspace` | How write tools persist data, see [Write modes](#write-modes). |
| `mcpSessionTimeoutSeconds` | `1800` | Drop idle MCP sessions after N seconds. `0` = SDK default (3600). Lower values free PHP workers and reduce session-store bloat. Applies to the legacy protocol era only: MCP `2026-07-28` has no protocol session. |
| `mcpAllowedRedirectUris` | _(empty)_ | Comma-separated allowlist of external OAuth redirect URIs (https only). An entry matches the same scheme, host and port and every path at or below its own path (`https://claude.ai` covers `https://claude.ai/api/mcp/auth_callback`, never `https://claude.ai.example.org`); an entry with a query must match exactly. Any `http` or `https` redirect URI on `localhost`, `127.0.0.1` or `[::1]` is always accepted. Empty allows only those, in every context. |
| `mcpExcludedTables` | _(empty)_ | Comma-separated list of tables that MCP tools must **not** read or write, **added to** a shipped default list (see [Which tables searchContent sweeps](#which-tables-searchcontent-sweeps)); entries here can only extend it, never unblock a default. Applied on top of TYPO3 backend permissions and **also blocks admins**. |
| `mcpAllowRawHtmlWrite` | `0` | Allow write tools to store raw markup verbatim in **code editor fields**: `tt_content.bodytext` of CType `html` and any other TCA `text` field with a code editor `renderType`/`format`. Those fields render unfiltered in the frontend, so an agent (or a prompt injection reaching it) can place arbitrary HTML/JavaScript on the site. While disabled, a write that carries markup into such a field is **rejected** (`unsupported_html`): nothing is stored and the existing value survives. Markup-free values are written either way. See [Code editor fields](#code-editor-fields). |
| `mcpTrustedProxies` | _(empty)_ | Comma-separated reverse-proxy IPs / CIDRs (e.g. `10.0.0.0/8,192.168.0.0/16`). Only a request from one of them may name the real client IP (`X-Forwarded-For`) and the original scheme (`X-Forwarded-Proto`). The resolved IP is what the OAuth rate limits count per client, what consent and MFA tickets are bound to and what the audit log records. Empty = both headers are ignored. See [Reverse proxy & load balancer](#reverse-proxy--load-balancer). |
| `mcpBackendBaseUrl` | _(empty)_ | Scheme and host for the backend links in tool results, e.g. `https://www.example.com`. Empty = taken from the current request, and without one (stdio transport) from the site configuration. Set it when the backend runs under a different domain than the site base. See [Backend links in tool results](#backend-links-in-tool-results). |
| `mcpBackendLinkBounce` | `1` | Open backend links through a bounce page on the backend host (`/aisuite-mcp/open`). Without it, a link clicked in an external chat reaches the backend without the editor's session cookie and ends on the login screen. Links handed out earlier keep opening either way. See [Backend links in tool results](#backend-links-in-tool-results). |

The request-body cap `mcpMaxRequestBodyMb` (default `1`) is described under [Security](#security). Logging settings (`mcpLogVerbose`, `mcpLogRedactionPatterns`) are documented under [Logging & retention](#logging--retention); the media-upload settings (`mcpMediaDefaultFolder`, `mcpMediaMaxSizeMb`, `mcpMediaAllowedExtensions`, `mcpMediaAllowUrlFetch`, `mcpMediaHostDenylist`) under the [`uploadMedia`](#media-upload-mcpmedia) tool.

<img src="Resources/Public/Icons/cheddi.png" alt="ChEddi" width="48" align="right">

**ChEddi inherits these settings.** The editor assistant ChEddi (`cheddi`) runs the same tools
in-process and follows `mcpWriteMode`, `mcpAllowRawHtmlWrite`, `mcpExcludedTables`, `mcpLogVerbose`
and `mcpLogRedactionPatterns` unless ChEddi sets its own value: `chatWriteMode`,
`chatAllowRawHtmlWrite` and `chatLogVerbose` default to `inherit`, the list settings default to
empty and are added on top of the MCP lists. `chatAllowRawHtmlWrite`, `chatExcludedTables` and
`chatLogRedactionPatterns` can only **tighten** the MCP value. Two settings are ChEddi's
own decision instead:
`chatWriteMode` replaces `mcpWriteMode` for ChEddi (so `live` there writes live even while MCP
writes to a workspace), and `chatLogVerbose` switches ChEddi's own log independently of
`mcpLogVerbose`. ChEddi hands its markup and table overrides to `SurfaceSettingOverrides`, which
stays empty for calls arriving over the MCP transport; its write mode goes to the write-mode
resolver, and its log settings apply to its own log only.

### Backend links in tool results

A call that touched records reports them back as UIDs, which is precise but unusable; nobody wants
to look up page 99934 by hand. Every tool result therefore also carries links to what it touched, in
two channels: `structuredContent.links` for hosts that render them themselves, and a
`🔗 Open in TYPO3` block appended to the result text, which is what makes them clickable in a chat
client. The targets come from the UIDs the tools reported, so they point at what was actually
written; the label is the record's own title where it has one. On a site with more than one
language, a target also names the site language of its record (`"language": "English"`, in the text
as the link label `Landing page (English)`), because a page and its translation usually share their title. The
block asks the assistant to pass the links on, so an editor gets them with the answer instead of
having to ask for them.

Links are grouped by table, because a page and the content elements on it are different destinations.
`pages` comes first, the remaining tables in the order they were touched. The cap of six is **per
group**, so a written page is never pushed out of the list by the content elements written onto it;
`omitted` reports how many the cap dropped, so a truncated group cannot be mistaken for a complete
one.

The URLs are absolute and carry **no route token**. A token belongs to the session that generated it,
the MCP session and not the editor's browser, so it would be worthless in a link that leaves the
process.

They do not point at the backend directly but at `/aisuite-mcp/open` on the backend host, carrying
the backend path and a signature. TYPO3's backend session cookie is not sent along when a link is
followed from another site such as claude.ai or ChatGPT, so a direct link ends on the login screen
even for an editor who is logged in. The bounce page is served by the backend host itself and
continues to the backend from there, a navigation the browser does send the cookie with: an editor
with a live session lands directly on the record, one without passes through the login first. The
page holds no data and answers even while `enableMcp` is off, so links in older chats keep opening.
It forwards only same-host absolute paths signed with this installation's `encryptionKey`, which keeps it from
being used as an open redirect; the links do not expire, and changing the key invalidates every link
handed out before. `mcpBackendLinkBounce = 0` returns to direct links.

Host resolution, first hit wins: `mcpBackendBaseUrl` → current request → site configuration
(`Site::getBase()`). If none of them yields a scheme and host (the stdio transport has
no request, and an installation may have no site configured), the link is dropped rather than
emitted broken, with a warning in `aisuite_mcp_warnings.log`.

### Which tables searchContent sweeps

`searchContent` always searches `pages` and `tt_content`. Beyond those it sweeps **every table whose records can live on a page and that carries a searchable text column**: no configuration, no allowlist. That covers standalone record tables (`tx_news_domain_model_news` and friends), Content Blocks collections, Bootstrap Package accordion/card items and any hand-written equivalent.

Three kinds of table are dropped structurally, each on its own TCA declaration: a table with `rootLevel = 1` (pid 0 only, so it can never sit inside a webmount; note that `rootLevel = -1` means *both* and those tables, `sys_category` among them, **are** swept), a table that declares the page tree does not apply to it (the core sets this on the FAL tables, so `sys_file_reference` drops out by its own declaration rather than by name), and a table without a searchable text column. `hideTable` is deliberately **not** a criterion: it means "no entry in the list module", not "secret", and every Content Blocks collection carries it.

Because nothing has to be listed to be searched, the **denylist is the only gate**, and it ships filled. These tables are blocked out of the box:

| Blocked by default | Why |
|---|---|
| `fe_users`, `fe_groups`, `be_users`, `be_groups` | personal data |
| `tx_cheddi_session` | editors' chat history |
| `sys_template`, `backend_layout`, `form_definition`, `index_config` | configuration, not content |
| `tx_scheduler_task`, `tx_scheduler_task_group`, `tx_impexp_presets`, `be_dashboards` | system administration |
| `sys_webhook`, `sys_reaction` | outbound and inbound integrations; a webhook carries its target URL and secret |
| `sys_file_storage`, `sys_filemounts`, `sys_workspace` | access configuration: which storages, folders and workspaces exist and who may use them |
| `sys_redirect` | site-wide redirects; one written record can reroute any URL of the installation |

`mcpExcludedTables` **adds** to that list and cannot remove an entry, so no configuration mistake can expose `fe_users` through search. A table you do not want swept but do want readable has no separate switch any more; block it entirely or leave it.

The user's own backend permissions apply on top: a table the BE user cannot `tables_select` is skipped silently and does not appear in the response's `searchedTables`, which always names exactly the tables that were searched.

Earlier versions worked the other way round, with two settings (`mcpSearchAdditionalTables`, `mcpExcludeAdditionalTablesFromSearch`) and auto-detection limited to IRRE children. **Both settings are gone.** If you had listed a table under the first, it is now found automatically and the entry can be dropped. If you had used the second to silence a noisy child table, move that table to `mcpExcludedTables`: it is no longer possible to exclude a table from search while keeping it readable.

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
- An entry in `mcpAllowedRedirectUris` covers every path below its own, so `https://claude.ai` covers any sub-path Claude may send, while a host that merely starts the same way does not match.
- An empty `mcpAllowedRedirectUris` allows only the localhost redirect URIs, in every context: a remote connector needs its callback listed, also on Development and staging instances. The origin check is relaxed in the **Development** context only (see `mcpAllowedOrigins`).
- A client registers its redirect URIs once (`/aisuite-mcp/oauth/register`); the registration is stored, and an authorization request whose `redirect_uri` the client did not register is refused without a redirect. The login and consent pages name the client and the host the code will be sent to. A connector authorized before the registry existed is registered on its next authorization, provided its `redirect_uri` is allowed. `ai-suite-mcp:cleanup` removes registrations unused for 30 days that hold no active token.
- `mcpAllowedOrigins` only affects browser-based clients (CORS). CLI and desktop-native clients ignore it.

## Write modes

`mcpWriteMode` controls how every write-capable tool (`writeRecords`, `copyRecords`, `moveRecords`, `localizeRecord`, `deleteRecords`, `savePageTree`, …) persists its changes. It is set globally in the extension configuration; a token can additionally be bound to a workspace when it is issued, and that binding always wins.

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

> ⚠️ **Three tools are not workspace-contained and write to live in *every* mode:** `uploadMedia`,
> `generateImage` and `moveFile`. They create or move a `sys_file` record and the physical file through
> FAL, which has no `versioningWS`: so their writes cannot land in a draft and no write mode undoes them.
> All three are gated behind their own scope (`mcp:media` / `mcp:image`) and BE-group feature flag
> (default off), and the MCP client's approval dialog is the only pre-write gate. Separately, the
> credits spent by the `generate*`, `batch*` and `audit*` tools, and by `translateRecord` /
> `translatePage` with a `model`, are never refundable; the generating tools return suggestions and
> spend credits even though they write no database row.

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
| `idempotentHint` | Repeating the call changes nothing further | the same read tools, plus `replaceMediaReference` (repointing a reference at the same file twice leaves the same rows) |
| `destructiveHint` | Removes or overwrites existing content, or writes to live where discarding a workspace does not undo it | `deleteRecords` (removes), `applyTaskResults` (replaces existing translations and metadata), `uploadMedia`, `moveFile`, `generateImage` (live in every write mode) |
| `openWorldHint` | Reaches a system outside this TYPO3 | every tool that calls the AI Suite Server (the twelve credit-spending tools), plus `uploadMedia` |

The three `audit*` tools are deliberately **neither** read-only **nor** idempotent: each call spends credits and stores its report in `tx_aisuite_audit_result`, so a client that runs read-only tools or retries without asking would otherwise start paid audits on its own. `readTaskStatus` stays read-only: it only refreshes the stored status of a batch from the server, free of charge, and repeating it changes nothing further.

`idempotentHint` is the one hint that matters on a *writing* tool: it tells a client that a retry of
the identical call needs no second approval. It is deliberately not set on `copyMediaReference`,
which creates a new reference every time, nor on `writeRecords`, whose batch may contain creates.

`writeRecords`, `copyRecords`, `moveRecords` and `savePageTree` are deliberately **not** marked
destructive: under the default `mcpWriteMode = workspace` they land in a draft and are undone by
discarding it. Marking them destructive would add approval friction for changes that are already
reversible.

If a client asks too often or too rarely, the setting to change is in the client. `previewRecords`
is available for a read-only look at what a write would do, and `dryRun` on `bulkReplaceText`
reports the blast radius of a bulk edit without writing.

## Protocol revisions

The server speaks two eras of the Model Context Protocol and picks per request, so no connector has
to be configured for one or the other:

| Era | Revisions | How a request is recognised | Session |
|---|---|---|---|
| Modern | `2026-07-28` | `MCP-Protocol-Version: 2026-07-28`, or the version inside the request's `_meta`, or a `server/discover` call | none, every request is self-contained |
| Legacy | `2025-11-25`, `2025-06-18`, `2025-03-26`, `2024-11-05` | an `initialize` handshake | `Mcp-Session-Id`, file-backed under `var/aisuite_mcp_sessions/` |

A modern client that asks for a revision this server does not implement gets `400` with JSON-RPC
error `-32600` and the supported list in `error.data.supported`. That is the spec's signal for
"legacy-capable server": a dual-era client falls back to `initialize` on it.

Server identity, icons and the operating guidelines ride the `initialize` result on the legacy path
and the `server/discover` result on the modern one.

## Connectors

Each supported MCP client has its own dedicated setup guide under [`Connectors/`](Connectors/). The guides cover prerequisites (extension settings, BE-group permissions, host reachability), the per-client UI / CLI / config-file steps to register the connector, smoke-test prompts, and a troubleshooting matrix.

| Client | Setup guide | Auth flow | Reach |
|---|---|---|---|
| **Claude Desktop** | [`Connectors/claude-desktop.md`](Connectors/claude-desktop.md) | Static bearer token (default) or OAuth 2.1 | `mcp-remote` / stdio: local, can reach `localhost`, `*.ddev.site`, internal hosts. Custom connector: public HTTPS only, Anthropic-hosted |
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
- `enable_metadata_generation`: for `batchGenerateMetadata`, `generateFileMetadata`, `batchGenerateFileMetadata` (any of `enable_metadata_generation`, `enable_content_element_generation` or `enable_pages_generation` satisfies the `mcp:generate` scope, but these three tools need the metadata flag itself)
- `enable_translation`: for `translateRecord`, `translatePage` and `translateFileMetadata`, and checked by the two `batchTranslate*` tools (`localizeRecord` needs none)
- `enable_translation_whole_page`: in addition for `translatePage` and `batchTranslatePage`, the same flag the backend's whole-page translation asks for
- `enable_translation_sys_file_metadata`: in addition for `translateFileMetadata` and `batchTranslateFileMetadata`
- `enable_image_generation`: for `generateImage`
- `enable_massaction_generation`: for the four `batch*` tools (`readTaskStatus`, `readTaskResults` and `applyTaskResults` need no flag)
- `enable_mcp_media_upload`: for `uploadMedia` and `moveFile` (`mcp:media` scope)
- `enable_mcp_rendered_page_read`: for `readRenderedPage` (see "Per-tool permissions" below)
- `enable_audit`: for the three `audit*` tools (see "Per-tool permissions" below)

Without `enable_mcp_access` every request to the MCP endpoint is rejected with `403 access_denied`, and the OAuth consent is refused. These flags apply to MCP only: ChEddi runs the same tools with its own *ChEddi Features* (see the ChEddi README) and needs neither `enable_mcp_access` nor the flags above. Without the feature-specific flags the affected tools simply don't appear in the model's tool list. The `batch*` tools are partly an exception: they need `enable_massaction_generation` and the tool's own flag from the table below to appear, and refuse the call when the generation or translation flag behind their credit spend is missing. `readTaskResults` shows and `applyTaskResults` writes only the results for records the user may read or edit.

Pages whose page TSconfig sets `tx_aisuite.noAiProcessing` are refused by every AI tool that works on a page, `translateRecord` included.

#### Per-tool permissions

Most flags gate a whole scope (see the table under [OAuth scopes](#oauth-scopes)). Some flags gate individual tools instead, because those tools reach further than the rest of their scope, or because the scope is shared by features the backend grants separately:

| Tool | Scope | Additional flag |
|---|---|---|
| `readRenderedPage` | `mcp:read` | `enable_mcp_rendered_page_read` |
| `auditSeo` | `mcp:read` | `enable_audit` |
| `auditAccessibility` | `mcp:read` | `enable_audit` |
| `auditContent` | `mcp:read` | `enable_audit` |
| `generateFileMetadata` | `mcp:generate` | `enable_metadata_generation` |
| `batchGenerateMetadata`, `batchGenerateFileMetadata` | `mcp:workflow` | `enable_metadata_generation` |
| `translatePage` | `mcp:translate` | `enable_translation_whole_page` |
| `batchTranslatePage` | `mcp:workflow` | `enable_translation_whole_page` |
| `translateFileMetadata` | `mcp:translate` | `enable_translation_sys_file_metadata` |
| `batchTranslateFileMetadata` | `mcp:workflow` | `enable_translation_sys_file_metadata` |

`readRenderedPage` renders the page through a backend preview session of the MCP user, so it also returns **hidden and unpublished pages**: content a plain HTTP fetch of the public URL could never reach. **Workspace drafts** are not rendered: in a workspace, and whenever rendering fails, the text is assembled from the stored records (content elements, their inline children such as accordion items, the page fields in the requested language), and the answer says so, because plugin output and TypoScript-composed content are missing there. The other `mcp:read` tools return stored records and need no flag, so the gate sits on the tool rather than on the scope: putting it on `mcp:read` would revoke every read tool and change which scopes OAuth grants.

The audit tools (`auditSeo`, `auditAccessibility`, `auditContent`) follow the same pattern: they send the page URL to the AutoDudes audit infrastructure (AI Suite Server → Tool Gateway), resolved from `pageId` when the caller names a page rather than a URL, so page URL and, transitively, public page content leave the TYPO3 instance. They write no page content, only their report, hence `mcp:read` plus the dedicated `enable_audit` flag instead of an own OAuth scope; their annotations still mark them as writing, because they spend credits.

When the audited URL resolves to a page of this installation that the user may see, the result is stored for that page and language exactly like an audit started in the Audit module: it shows up there, in the page module tile and in `readAuditResults`, and a newer audit of the same type replaces it. The tool result then ends with `Stored in the AI Suite audit module for page <uid>.` and carries `structuredContent.auditStored`. Audits of foreign URLs (a competitor's site) and audits that could not reach the page are not stored.

The user's own page permissions still apply on top. Without the flag the tool does not appear in `tools/list`, and a forced call returns a permission error.

### Common troubleshooting

Issues that can happen with any client, regardless of transport:

| Symptom | Cause | Fix |
|---|---|---|
| MCP responds with `"state parameter is required and must be at least 32 characters"` during the OAuth flow | An older `ai_suite_mcp` with a 32-character minimum on the OAuth `state` parameter | Update the extension: current versions accept a `state` of 22 characters or more (OAuth 2.1 BCP / RFC 9700 §4.7), which covers clients whose default state length is below 32 |
| MCP responds with `"ArgumentCountError: Too few arguments to RateLimiterService::__construct"` | DI container cache is stale after a code update to the `RateLimiterService` class | Flush the TYPO3 cache + DI container cache (typically by removing the generated DI container under `var/cache/code/di/` and clearing caches in the backend) |
| Persistent 401 on `/aisuite-mcp` after a fresh token, with the request reaching PHP | The webserver does not hand the `Authorization` header on to PHP (Apache with mod_php / FCGI without the rewrite rule, nginx without the `fastcgi_param`). Token endpoints still work (they read the body); the MCP endpoint requires the Bearer header and never sees it | Forward the header: the `RewriteCond` / `RewriteRule` pair in the web-root `.htaccess` on Apache, `fastcgi_param HTTP_AUTHORIZATION $http_authorization;` on nginx. See [Webserver setup](#webserver-setup) |
| Connector loops on OAuth (refreshes tokens, re-registers under a new `client_id`, asks for re-authorisation) while `aisuite_mcp.log` shows `unsupported MCP-Protocol-Version` with `"requested":"2026-07-28"` and no `MCP endpoint response` line at all | The installed `ai_suite_mcp` predates MCP revision `2026-07-28`. The client probes with the modern revision, the server answers `400`, and because the version check used to run ahead of token validation the client never saw the `401` that points at OAuth discovery, so it read the protocol mismatch as an auth failure | Update `ai_suite_mcp` (it now ships `logiscape/mcp-sdk-php` v2, which serves both protocol eras). Nothing to change on the connector side |
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
| `readServerInfo` | (none) | Markdown summary with TYPO3, AI Suite and MCP versions |
| `listTables` | (none) | List of accessible TYPO3 tables for the BE user |
| `readPageTree` | `{ "rootPageId": 0, "depth": 2 }` | Nested JSON of the page tree |

## OAuth scopes

Each tool requires a scope, and each scope is only granted to users whose BE group has at least one of the matching AI Suite feature flags (see [BE-group permissions](#be-group-permissions)).

| Scope | Required BE-group permission(s) | Covers |
|---|---|---|
| `mcp:read` | _none_ (baseline) | All read-only / discovery tools |
| `mcp:write` | _none_ (approval comes from the MCP client's dialog) | Record CRUD via DataHandler |
| `mcp:generate` | `enable_metadata_generation`, `enable_content_element_generation`, `enable_pages_generation` | AI file metadata (`generateFileMetadata`); also the credit-side check of the `batchGenerate*` tools |
| `mcp:translate` | `enable_translation` | `translateRecord`, `translatePage`, `translateFileMetadata`; also the credit-side check of the `batchTranslate*` tools |
| `mcp:image` | `enable_image_generation` | AI image generation |
| `mcp:media` | `enable_mcp_media_upload` | `uploadMedia` (URL / base64 / online-media import into FAL), `moveFile` (move files between folders) |
| `mcp:workflow` | `enable_massaction_generation` | Batch / background task tools |

These seven are the complete list. In particular there is no `mcp:glossary`, no `mcp:easy-language`
and no `mcp:manage` scope; those appeared in an outdated document and have never existed in any
released version. Glossary handling rides along with `mcp:translate`, Easy Language is not exposed
over MCP, and there is no management scope at all. An unknown scope is ignored: the consent screen offers only scopes from this list that the user's groups allow.

## Tools

### Context (`mcp:read`)
| Tool | Purpose |
|---|---|
| `readServerInfo` | TYPO3 + AI Suite + MCP version / config summary |
| `readPageTree` | Traverse the page tree (respecting BE user mounts) |
| `readPageContent` | Read the content of a page (tt_content, optionally nested containers) |
| `readContentTree` | Read the content of every page in a subtree at once (paginated) |
| `readRenderedPage` | The page as a visitor sees it, incl. plugin output; needs `enable_mcp_rendered_page_read` (see [Per-tool permissions](#per-tool-permissions)) |
| `auditSeo` | Full SEO audit for a page, named by `pageId` (its public URL is resolved here) or by `url` for a page outside this installation (on-page checks, Lighthouse/CrUX, GEO signals; optional focus keyword adds SERP position, top-10 and search volume); needs `enable_audit` (see [Per-tool permissions](#per-tool-permissions)), costs 3 credits |
| `auditAccessibility` | WCAG 2.1 AA audit for a page, named by `pageId` or `url` (axe-core + HTML_CodeSniffer, summary + top issue groups); needs `enable_audit` (see [Per-tool permissions](#per-tool-permissions)), costs 3 credits |
| `auditContent` | What a page (`pageId`, or `url` for one outside this installation) does not yet cover, rated answered/partial/open against its content. `type` picks the angle: `questions` (People-also-ask + AI-derived, 2 credits), `gap` (weak rankings), `cluster` (subtopics around a keyword), `competitors` (keyword gap vs. the strongest competitor). Needs `enable_audit`, 3 credits unless stated |
| `readAuditResults` | The audit results already stored for a page (all six types, scores/coverage, the stored findings in the result text with `auditType`; a type with no stored audit returns an empty result (`stored: false`), not an error), including audits run through MCP or ChEddi on a URL of this installation; free, no new audit is started; needs no extra flag, only read access to the page |
| `readEditorialGuidelines` | The tone / target audience / style the editors configured for a page subtree |
| `readChildren` | List a record's container / IRRE children, grouped by relation |
| `searchContent` | Full-text search across `pages`, `tt_content` and every other page-bound table with a text column, unless the denylist blocks it. Every hit carries `languageUid` and, where the page belongs to a site, its ISO `language` |
| `listFiles` | List files in a FAL storage / folder |
| `readFileInfo` | Metadata for a single sys_file / sys_file_metadata record |
| `listStaleContent` | Detect pages / content that have not been updated for N days |

### Records: discovery (`mcp:read`) and CRUD (`mcp:write` / `mcp:read`)
| Tool | Purpose |
|---|---|
| `listTables` | List tables exposed to MCP (all tables the BE user can read, minus the shipped denylist and `mcpExcludedTables`) |
| `readRecordSchema` | Return TCA schema for a table (fields, types, defaults) |
| `listPageTypes` | List available page doktypes |
| `listContentTypes` | List available tt_content CTypes and valid colPos for a page; `includeContainers` adds the containers already on it |
| `readFlexFormSchema` | Resolve the inner schema of a FlexForm field (default `tt_content.pi_flexform`), sheets, fields, types, select options. Pass `recordUid` or a `type` hint when the data structure depends on the record type |
| `previewRecords` | Build a preview of a DataHandler operation without persisting; read-only, but requires `mcp:write` |
| `readRecords` | Read records of a table: one by `uid`, all on a page (`pid`), a page subtree (`rootPageId`) or by exact-match `filters` |
| `compareWithLive` | Diff workspace draft vs live (changed/added/removed fields; a move or reorder shows as a change of `pid` or `sorting`), requires a non-live workspace session |
| `writeRecords` | Create / update records via DataHandler (workspace-aware). A top-level `table` fills in for entries that carry none, and a `translations` object writes the linked translations along with the record (see [Writing translations](#writing-translations)) |
| `copyRecords` | Copy one or more records (`copies` array; one entry for a single copy) |
| `moveRecords` | Move one or more records (`moves` array, an optional top-level `table` is the default; `afterUid: 0` counts as no anchor). A moved page whose slug was generated gets a slug under its new parent, and its subpages follow; a slug set by hand stays |
| `localizeRecord` | Localize a record into a target language (creates the translation with the field values of the source; no AI, no credits) |
| `deleteRecords` | Soft-delete records, annotated `destructiveHint`, so the client raises its approval dialog |
| `savePageTree` | Persist a generated page tree |
| `patchText` | Several replacements in one field, applied atomically |
| `bulkReplaceText` | The same replacement across all child records of a parent, or every record of a table on given pages (`pageIds`) |
| `copyMediaReference` | Copy a file reference from a source field onto a target field; a file (`sys_file`) as the source is refused with the reference record to write instead |
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

`readRecordSchema` reports the distinction per field as `kind:rte`, `kind:html`, `kind:text`, `kind:relation`, `kind:json`, `kind:plaintext` or, where a format hint applies, `kind:lines`. When
editing such a field, read it with `readRecords(raw: true)` (a normal read returns a tag-stripped
preview) or, better, change it in place with `patchText`.

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
shell. It is created hidden, as TYPO3 does it; send `hidden: 0` among the translated fields to make it
visible in the same call. `translateRecord`, `translatePage` and `localizeRecord` take `hidden: false`
for the same purpose.

On a translation, plain text sent to a link field becomes the title of the link already stored there
(or in the default-language record), so the link target is kept. A value that is a link itself
(`t3://…`, `https://…`, `mailto:`, `/…`, `#…`) replaces the link. Plain text with no stored link to
go into is refused with `invalid_parameter`, and nothing is written.

Calling the same payload twice **updates** the existing translation instead of creating a second one,
so re-sending a batch after a partial failure is safe. `translations` also works on an entry that
carries a `uid`: that record is the origin. Nested inline children cannot be translated in the same
call; write them first, then translate them by uid.

Writes over the MCP transport do not trigger the automatic translation on save
(`enableAutoTranslateOnSave`): that hook only runs inside a backend request, and MCP requests are not
one. Translate explicitly with `translations`, `translateRecord` or `translatePage`. ChEddi runs its
tools inside a backend request, so the hook does apply to what ChEddi writes.

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
| `translatePage` | Translate a page: metadata, every content element and their inline children |
| `translateRecord` | Translate a single record together with its inline children |
| `translateFileMetadata` | Translate file metadata (glossary via the optional `pageId`) |

### Images (`mcp:image`)
| Tool | Purpose |
|---|---|
| `generateImage` | Generate an AI image and add it to FAL (GPT-Image (OpenAI) / Midjourney / Flux / …) |

### Media upload (`mcp:media`)
| Tool | Purpose |
|---|---|
| `uploadMedia` | Upload one or more existing images/videos into FAL: by remote http(s) URL (downloaded), inline base64 (`content`), or a YouTube/Vimeo link (stored as an online-media reference). No AI, no credits. |
| `moveFile` | Move one or more files into another folder of the same storage. The `sys_file` UID stays, so every reference and `t3://file?uid=` link keeps working. No AI, no credits. |

`uploadMedia` takes a `media` array; each item carries exactly one source (`url` **or** `content`) plus optional `fileName`, `targetFolder` and metadata (`title`, `alternative`, `description`). Items are processed independently; one failing item does not abort the batch. Each successful item names the new file UID (the value `uid_local` of a file reference takes) and links its metadata record (`sys_file_metadata`) as a backend edit link.

Three switches, per item or as the batch default:

- `onConflict` decides what happens when the file name already exists in the target folder. `rename` (default) stores the upload under a suffixed name. `replace` overwrites the existing file's content in place through `ResourceStorage::replaceFile()`: the uid stays, so every content element, page property and reference shows the new file at once. Given metadata overwrites the stored one; empty fields leave it untouched.
- `createFolder: true` creates a missing target folder, parents included. Write permission is checked on the deepest folder that already exists. Without it, a missing folder fails that item and names the switch.
- `allowSameNameFolder: true` creates a new folder although one of the same name already exists at the storage root or in the default upload folder. Without it such a folder is refused, and the error names the existing path.

Each result line says whether the file was `created`, `renamed` or `replaced`.

`moveFile` takes `fileUids`, a `targetFolder`, `createFolder` and `allowSameNameFolder` (same meaning as above); the target folder is checked once before the first file moves. A name already taken in the target folder gets a suffix, never an overwrite, and the result line names the new name. A file that already sits in the target folder is skipped. The core's own FAL checks apply on top of the flag: `move` permission on the file and write permission on the target folder, both within the file mounts. `listFiles` with `includeFolders: true` returns the subfolders next to the files (every level below with `recursive`), which is how a client picks or checks an upload target.

**Security.** Remote URL fetching is the sensitive part and is SSRF-guarded: only `http`/`https`, every resolved IP must be public (private, loopback, link-local incl. the `169.254.169.254` cloud-metadata endpoint, and reserved ranges are rejected, IPv4 + IPv6), redirects are followed manually and re-validated per hop, and the download is streamed with a hard size cap. Blocked targets are logged at WARNING. Beyond the OAuth scope + `enable_mcp_media_upload` flag, FAL filemount permissions on the target folder still apply. Tunables (`ext_conf`): `mcpMediaDefaultFolder` (default `1:/user_upload/`), `mcpMediaMaxSizeMb` (`50`), `mcpMediaAllowedExtensions` (`jpg,jpeg,png,gif,webp,avif,mp4,webm,ogg`; SVG excluded by default, XSS risk), `mcpMediaAllowUrlFetch` (`1`, kill-switch for URL downloads), `mcpMediaHostDenylist` (empty). Large videos should be supplied via `url` or an online-media link rather than base64; a base64 upload has to fit into one request, see `mcpMaxRequestBodyMb`.

### Workflow (`mcp:workflow`; polling `mcp:read`, applying `mcp:write`)
Batch tools run asynchronously and return a task ID. Poll via `readTaskStatus`, retrieve results via `readTaskResults`.

| Tool | Purpose |
|---|---|
| `batchGenerateMetadata` | Page metadata in bulk, for an explicit UID list or a whole page subtree |
| `batchGenerateFileMetadata` | File metadata for a list of files, or for every file in given FAL folders |
| `batchTranslatePage` | Translate multiple pages, from an explicit UID list or a whole page subtree |
| `batchTranslateFileMetadata` | Translate file metadata for a list of files, or for every file in given FAL folders (glossary via the optional `pageId`) |
| `readTaskStatus` | Status of a background task |
| `readTaskResults` | Fetch paginated results (read-only) |
| `applyTaskResults` | Write the translations of a finished translation batch into the localization records |

The page-batch tools (`batchGenerateMetadata` and `batchTranslatePage`) take their targets one of two ways, and exactly one of them: `pageIds` (an explicit UID array) **or** `rootPageId` (a page and everything below it, resolved server-side). Passing both is an error rather than a guess about which one wins; passing neither is an error too. Neither selection parameter is therefore marked as required, because "exactly one of two" is only expressible as a top-level `oneOf`, which no provider loads reliably as a tool. `batchGenerateMetadata` marks nothing as required at all, while `batchTranslatePage` still requires `targetLanguage`. `recursive` (default `true`) decides whether a `rootPageId` walks the subtree (up to 20 levels) or stops at its direct children; the root page itself is always included.

**Cost cap.** A `rootPageId` expands to at most **50 pages**, for both tools. Beyond that the call is refused before anything is billed, naming the number of pages it found. The reason is asymmetric: these tools spend credits per page, and a subtree is a quantity the caller never counted: "everything below the site root" is one short sentence away from a four-figure charge. An explicit `pageIds` list is a quantity the caller did state, so it is not capped. To work within the cap, pick a deeper root, set `recursive` to `false`, or pass the UIDs explicitly.

### Tools removed in this version

Seven tool names no longer exist. A saved prompt or a connector configuration that still calls one
gets an "unknown tool" error, so repoint it:

- `auditQuestions` → `auditContent` with `type: "questions"`
- `auditContentGap` → `auditContent` with `type: "gap"`
- `auditTopicCluster` → `auditContent` with `type: "cluster"`
- `auditCompetitors` → `auditContent` with `type: "competitors"`
- `batchGenerateFolderMetadata` → `batchGenerateFileMetadata` with `folderIdentifiers`
- `batchTranslateFolderMetadata` → `batchTranslateFileMetadata` with `folderIdentifiers`
- `replaceText` → `patchText` with a single-entry `replacements` list

The four audit tools shared their parameters and their verdict shape and differed only in the angle
they asked from, which is a `type` enum rather than four tools; the enum values are the stored audit
types, so results written by the old names stay readable through `readAuditResults`. The two folder
tools only resolved a folder to file UIDs and then delegated. `replaceText` was the one-entry case of
`patchText`. Every tool carries its schema on every turn, so the count is what costs context: the
merges took seven tools out and added one.

`translateRecord` also changed shape rather than name: its result now reports `translation.records`
where it used to report `translation.fields`, because the tool carries the inline children of a
record along with the record itself.

## Operating guidelines

`Classes/Mcp/Utility/OperatingGuidelines.php` is the single source of the normative text the server sends to the model. It ships as `initialize.instructions` (once per session, cached by the provider alongside the tool definitions) and is also readable as the `aisuite://guidelines` resource. Tool descriptions never repeat it: a section is sent once per session, a description on every turn.

Nine sections are sent, in this order:

| Section | Covers |
|---|---|
| `targetPage` | Resolving which page the user means |
| `defaults` | Choose sensible defaults instead of interviewing the user, ask only when the request cannot be carried out otherwise, finish the whole request |
| `discoverFields` | Read the schema before writing; never guess field names |
| `rules` | The host confirms changes, `previewRecords` is an optional diff, backend links are passed on |
| `credits` | Which tools spend credits |
| `smallEdits` | Prefer `patchText` over resending whole fields |
| `workspace` | Unless the write mode is live, writes go into a draft; `compareWithLive` shows the draft against live and how its scope works |
| `batchVsSingle` | When to use a `batch*` tool instead of looping |
| `bulkOps` | Bulk operations across many records |

ChEddi receives the same sections without the batch route; `batchVsSingle` becomes the rule to use single tools for one record.

**Approval is the host's job, not the model's.** The guidelines deliberately contain no rule telling the model to wait for confirmation before writing: models follow such a rule literally and wait for a human who is not there. Confirmation happens outside the model: MCP clients raise their own approval dialog, and ChEddi stops write/destructive calls with `needsConfirm`. `previewRecords` is offered as a read-only old→new diff, but nothing enforces it server-side; what guarantees reversibility is the write mode (see [Write modes](#write-modes)).

Batch tools never auto-persist: they return suggestions, which `applyTaskResults` (translation batches) or `writeRecords` (metadata batches) persists only when called.

## Custom tools

A public API for tools of your own extension is planned but not available yet: only tools from the
AutoDudes namespaces (`AutoDudes\AiSuiteMcp\`, `AutoDudes\AiSuite\`, `AutoDudes\Cheddi\`) are
registered, and every other tool is rejected at boot time with a warning in
`aisuite_mcp_warnings.log`. If you have a use case that doesn't fit any of the built-in tools,
[open a feedback issue](#feedback); we'd like to know what shape the API needs to take before we
freeze it.

## Console commands

```bash
# Create a test token (bypasses OAuth flow, development only). --audience is required: the CLI has
# no request host, and the token is bound to the public MCP URL (RFC 8707)
vendor/bin/typo3 ai-suite-mcp:create-token --user=1 --audience="https://your-host/aisuite-mcp"
vendor/bin/typo3 ai-suite-mcp:create-token --user=admin --scopes="mcp:read mcp:write mcp:generate" --audience="https://your-host/aisuite-mcp"
vendor/bin/typo3 ai-suite-mcp:create-token --user=1 --client=mcp-inspector --audience="https://your-host/aisuite-mcp"
# Bind the token to a workspace
vendor/bin/typo3 ai-suite-mcp:create-token --user=1 --workspace=3 --audience="https://your-host/aisuite-mcp"

# Clean up expired OAuth state, session files and leftover task files
vendor/bin/typo3 ai-suite-mcp:cleanup

# Run a local MCP server over stdio (trusted local CLI clients only, see "Local stdio transport")
vendor/bin/typo3 ai-suite-mcp:server --user=1
vendor/bin/typo3 ai-suite-mcp:server --user=editor --scopes="mcp:read mcp:write"
```

Both commands need `--user`; there is no default account. They refuse while `enableMcp` is off, for a disabled user or one outside its start/end time, and for a user without `enable_mcp_access`, the same gates the HTTP endpoint applies. `--scopes` is cut down to the scopes the user may be granted, and the command names what it dropped.

`ai-suite-mcp:cleanup` removes:
- authorization codes older than 10 min
- tokens whose access and refresh token both expired more than 37 days ago (a fixed cutoff, independent of the lifetime settings)
- revoked tokens created more than 30 days ago (hard delete)
- session files under `var/aisuite_mcp_sessions/` older than twice `mcpSessionTimeoutSeconds`, at least one hour (one hour at the default of 1800 s)
- leftover background task files under `var/mcp_tasks/` older than 30 days (task results now live in the database)
- client registrations unused for 30 days that hold no active token

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
      "args": ["-c", "cd '<project-root>' && exec '<ddev-path>' exec vendor/bin/typo3 ai-suite-mcp:server --user=1"]
    }
  }
}
```

`exec` replaces `bash` with `ddev` so the pipe is passed through cleanly. Docker Desktop / OrbStack
must be running and `ddev start` run once. A bare-`docker exec -i ddev-<project>-web …` variant also
works (`-i` required, **never `-t`**: a TTY corrupts JSON-RPC framing). For the full
Claude-Desktop walkthrough see [`Connectors/claude-desktop.md`](Connectors/claude-desktop.md).

**Security model.** stdio runs the tools as the given backend user; the per-tool scope and
feature-flag checks apply as over HTTP, but the `enable_mcp_access` master flag is not checked.
And because the transport is a local pipe, it
**bypasses OAuth, the HTTPS gate, per-token rate limiting, the request-body cap and the
per-token credit budget** (`mcpMaxCreditsPerSession` is tracked per OAuth token, so over stdio it
never applies): those are
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
| `tx_aisuite_oauth_clients` | Registered OAuth clients (dynamic client registration): name and allowed redirect URIs |

## Security

Enforced by `McpServerMiddleware` and the OAuth endpoints:

- **HTTPS required** (outside the Production context localhost and `*.ddev.site` are exempted, which also applies to the Origin check; override with `mcpAllowHttp=1`: **not** for production).
- **Framing and caching:** every MCP and OAuth response carries `X-Frame-Options: DENY`, `Content-Security-Policy: frame-ancestors 'none'` and `X-Content-Type-Options: nosniff`, so the login and consent pages cannot be embedded by a foreign site; the `/oauth/…` responses also carry `Cache-Control: no-store`, so no proxy keeps a token or a login page.
- **`/aisuite-mcp/health`** answers without authentication and therefore says only `ready` or `not_configured`, nothing about versions or settings.
- **Session ids are checked** before anything uses them: an `Mcp-Session-Id` that is not the 64-hex-character id the server issued is refused with `400`.
- **Request-body cap** of 1 MB per MCP request, adjustable with `mcpMaxRequestBodyMb`. Base64 uploads through `uploadMedia` are the only calls that need more: base64 adds a third, so a 3 MB video needs 4 MB.
- **Rate limiting**: 100 requests / minute per Bearer token by default, adjustable or switchable off with `mcpRateLimitRequests`, `mcpRateLimitWindowSeconds` and `mcpRateLimitLockoutSeconds`. Exceeding it answers `429` with the remaining lockout in `Retry-After`.
- **OAuth rate limiting**: `/aisuite-mcp/oauth/token`, `/aisuite-mcp/oauth/register`, `/aisuite-mcp/oauth/revoke` and `/aisuite-mcp/oauth/authorize` are limited per client IP to 100 requests / minute, the MFA step to 10 attempts per backend user within 5 minutes and the login form to 10 failed attempts per username within 15 minutes (the next failure locks the username for 15 minutes; only failures count, a successful login or dashboard confirmation does not). These limits are fixed and not affected by the settings above (`429 rate_limit_exceeded`).
- **OAuth 2.1 with PKCE**, no implicit / password grants.
- **Dynamic Client Registration** is open but only accepts redirect URIs `mcpAllowedRedirectUris` allows; the stored registration then binds `/oauth/authorize` to exactly those URIs, on top of `mcpAllowedClientIds`.
- **Consent grants what was asked for, at most.** "Grant all" grants the requested scopes the user may hold, never more, and a custom selection cannot add a scope the client did not request.
- **MFA follows the backend's rules.** A user with an MFA provider must pass it; a user whom `BE.requireMfa` or the user TSconfig `auth.mfa.required` obliges to use MFA but who has not set it up gets no token and is sent to set it up in the backend first.
- **A dashboard token needs the password.** Creating a token in the MCP backend screen asks for the backend password again, because the token acts with the editor's rights for weeks; a session taken over in the browser alone is not enough. Failed confirmations count towards the same per-username login limit as the OAuth login form.
- **The OAuth login is a backend login.** A failed attempt is written to the backend log and dispatched as `LoginAttemptFailedEvent`, `BE.adminOnly` keeps editors out, and an unknown username takes as long as a wrong password.
- **Password change revokes all tokens** for that BE user (`PasswordChangeHook` on `processDatamapClass`).
- **Live backend-user status check** on every request and every refresh: disabled, deleted, not yet started or expired (`starttime` / `endtime`) users are rejected even if their token is still valid.
- **Refresh tokens rotate and have an end.** A reused refresh token revokes its whole lineage, a revoked lineage cannot be revived through the 30-second leeway for concurrent refreshes, an OAuth access token lives `mcpAccessTokenLifetimeMinutes` (60 by default) and is renewed through the refresh token, a connection left unused for longer than `mcpRefreshIdleDays` has to be authorized again, and no lineage outlives `mcpRefreshMaxLifetimeDays`. Revoking an access or a refresh token (`/aisuite-mcp/oauth/revoke`) ends the whole connection unless the request names a different `client_id` than the token's, and an authorization code presented twice revokes the tokens it was exchanged for.
- **Scope + permission double check**: an OAuth scope alone is not sufficient; the BE user group must also carry the matching AI Suite feature flag.
- **Raw markup is refused by default**: a write that would put HTML/JavaScript into an unfiltered [code editor field](#code-editor-fields) fails unless `mcpAllowRawHtmlWrite` is enabled.
- **Reports module** surfaces misconfigurations: HTTP allowed, empty allowlists in production. Check **System → Reports → AI Suite MCP Security**.

## Production deployment

The settings, security gates, and connector flows above are sufficient to *run* the MCP server. Operating it stably in production additionally requires getting the topics in this section right; none of them are enforced by the code, but ignoring any one of them tends to cause silent failures (lost session state, stale tokens, audit-log gaps, …) rather than loud errors.

### Reverse proxy & load balancer

- **HTTPS detection:** `McpServerMiddleware::enforceHttps()` honors `X-Forwarded-Proto: https` in addition to the request scheme, but **only from a proxy listed in `mcpTrustedProxies`**. If your CDN or load balancer terminates TLS and forwards plain HTTP to the origin, set this header on the proxy and list the proxy; a direct client sending the header itself is still asked for HTTPS.
- **Client IP:** the OAuth rate limits per client, the IP binding of consent and MFA tickets and the OAuth audit-log entries (token revocations, rate-limit hits, invalid MFA or consent tickets) all use the same resolved client IP. Behind a proxy without `mcpTrustedProxies`, that is the proxy for every client, so all clients share one rate-limit bucket. By default that is the peer IP (`REMOTE_ADDR`), which behind a proxy is the proxy IP, not the end-user IP. Set `mcpTrustedProxies` to your proxy IPs / CIDRs and the extension walks the `X-Forwarded-For` chain from the right, skipping trusted hops, and logs the first untrusted address (the real client). When `mcpTrustedProxies` is empty, `X-Forwarded-For` is ignored entirely, so a client cannot spoof its IP in the audit log by sending the header itself.

### Webserver setup

The MCP endpoint authenticates every request with an `Authorization: Bearer <token>` header. Several PHP setups do not hand that header on to PHP. The endpoint then answers `401` although the token is valid, while discovery and the OAuth token endpoints (which read the request body) keep working.

**Apache** (`.htaccess`, mod_php / FCGI): the web-root `.htaccess` needs this rewrite, next to TYPO3's other rewrite rules after `RewriteEngine On`:

```apache
RewriteCond %{HTTP:Authorization} ^(.*)
RewriteRule .* - [E=HTTP_AUTHORIZATION:%1]
```

TYPO3's default `.htaccess` ships these two lines, but they get lost easily when the file is customised or replaced by a hosting template, so check for them.

**Nginx** (php-fpm): nginx reads no `.htaccess`. Forward the header explicitly in the `location` that passes requests to PHP:

```nginx
location ~ \.php$ {
    fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    # ... your existing fastcgi_params include
}
```

Also raise `client_max_body_size` to at least the MCP body cap (`mcpMaxRequestBodyMb`, default 1 MB) plus margin for batch payloads; `8m` is a safe default. PHP's `post_max_size` has to allow the same size.

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

**Use prefix regexes, not exact paths.** `$request_uri` is the raw request target *including the query string*, so an exact key such as `"/aisuite-mcp/oauth/authorize"` never matches: the authorize call always arrives as `/aisuite-mcp/oauth/authorize?response_type=code&client_id=…&state=…`. This fails in a way that reads as partial success, because discovery and `/aisuite-mcp/health` are requested without a query string and do match, so the connector completes discovery and then dies at the login step. `~^/aisuite-mcp` covers the transport endpoint, `/health` and all four `/oauth/…` endpoints in one line.

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
curl -i "https://<host>/aisuite-mcp/health"                       # expect 200 {"status":"ready"}
curl -i "https://<host>/aisuite-mcp/oauth/authorize?response_type=code&client_id=x&state=aaaaaaaaaaaaaaaaaaaaaa"
curl -i "https://<host>/typo3/"                                   # expect 401 WWW-Authenticate: Basic
```

The authorize call is the one that must not be skipped: it is the only request here carrying a query string, which is what an exact-match exemption fails on. What it answers does not matter, the dummy `client_id` will be rejected; what matters is that the response carries no `WWW-Authenticate: Basic`. The last call proves the rest of the site is still protected.

A `403` here means an env-flag guard (Apache step 2) or a host-level dot-path block is still catching the path; a `401` with `WWW-Authenticate: Basic` means the Basic-Auth exemption is not matching: on Apache, check that it keys off `THE_REQUEST`, on nginx that the `map` uses prefix regexes.

### Scheduled maintenance

`ai-suite-mcp:cleanup` is **required** in production, not optional. Run it via TYPO3 Scheduler or system cron at least **hourly**. It removes:

- authorization codes older than 10 min
- tokens whose access and refresh token both expired more than 37 days ago (a fixed cutoff, independent of the lifetime settings)
- **revoked tokens created more than 30 days ago**: hard-deleted from `tx_aisuite_oauth_tokens` to meet GDPR right-to-erasure expectations. Until then revoked entries (`deleted = 1`) stay, so refresh-token reuse detection can still recognise a rotated token; after that window the signal is moot
- session files under `var/aisuite_mcp_sessions/` older than twice `mcpSessionTimeoutSeconds`, at least one hour (one hour at the default of 1800 s)
- leftover background-task files under `var/mcp_tasks/` older than 30 days (task results now live in the database)
- client registrations unused for 30 days that hold no active token

Watch the session directory in particular: a stateless MCP client leaves one file behind **per request**, so the count climbs quickly on a busy site. The Reports module shows it as *MCP Session Store* and turns to a warning above 500 files; `readServerInfo` reports the same number and logs a warning at WARNING level once the threshold is crossed.

Without it, the authorization-code table grows unbounded, the on-disk session directory balloons, and revoked or expired tokens accumulate in `tx_aisuite_oauth_tokens`. For high-volume sites (>100 concurrent users) monitor row counts in `tx_aisuite_oauth_tokens` and tighten `mcpTokenLifetimeDays` and `mcpRefreshIdleDays` if growth outpaces the cleanup cycle.

### Runtime & scaling

- **PHP runtime:** the extension targets a classic **PHP-FPM / mod_php** request lifecycle (one request per process). It is **not** validated on persistent-worker runtimes such as **FrankenPHP** or **RoadRunner**: an in-process, per-instance cache (`RecordAccessService::$readablePageIdsCache`) assumes the process ends with the request, and reusing it across users in a long-lived worker could leak read-access decisions between backend users. Do not run the MCP server on a persistent-worker SAPI until that cache is moved to request scope.
- **Multi-host / load-balanced setups:** MCP transport sessions (`var/aisuite_mcp_sessions/`) live on the **local filesystem**. Across multiple app nodes you therefore need either **sticky sessions** (pin a client to one node) or a **shared filesystem** for that directory. OAuth state and background-task results live in the database and are shared automatically; only the on-disk session state needs this treatment.

### Logging & retention

Two dedicated log files are configured for the `AutoDudes.AiSuiteMcp` namespace in `ext_localconf.php`:

- `var/log/aisuite_mcp.log`: **INFO+** (verbose). Useful for forensic debugging and per-request audit replay. Switched by `mcpLogVerbose` (default: off), because it holds prompts and content snippets; switch it on while debugging. The Reports module warns while it is on in Production; the WARNING+ alert log stays active either way.
- `var/log/aisuite_mcp_warnings.log`: **WARNING+** only. Always active. Stays small; if it is non-empty, something is worth investigating (rate-limit hits, tool execution failures, OAuth misconfigurations). Designed for monitoring / paging; point your log shipper or `tail -F` here in production.

What gets logged:

- OAuth events: issued and refreshed tokens with `client_id`, issued authorization codes and MFA results with the BE-user UID, revocations and rate-limit or ticket warnings with the (real) client IP
- MCP responses: status code, response headers and the first 500 characters of the response body, plus the request method, JSON-RPC method, tool name and the first 500 characters of the tool arguments (the raw first 300 characters when the body is not JSON). Arguments and responses routinely contain user prompts, page content snippets, file metadata, etc.
- Tool execution errors with the exception message and the call chain as `file:line Class->method()`, without the call arguments, which would otherwise copy content and tokens into the log

Before an entry is written, Bearer tokens become `Bearer [REDACTED]`, JWTs `[REDACTED-JWT]`, 64-character hex hashes `[REDACTED-HASH64]`, other long token-like strings (40+ characters mixing upper case, lower case and digits, such as a PKCE verifier) `[REDACTED-TOKEN]` and email addresses `[REDACTED-EMAIL]`. Values stored under the keys `authorization`, `password`, `passwd`, `code`, `code_verifier`, `access_token`, `refresh_token`, `client_secret`, `ticket`, `consent_ticket` and `token` are replaced as a whole, whatever they look like. If a pattern cannot be applied (the PCRE engine gives up on the value), the value is written as `[REDACTION-FAILED]` rather than unredacted. `mcpLogRedactionPatterns` (default empty) adds your own patterns on top, replaced with `[REDACTED]`: comma-separated regular expressions without delimiters. Each pattern is wrapped in `/…/`, so escape a `/` inside it; an invalid pattern is skipped silently. ChEddi's log applies them as well.

The log files are not rotated by TYPO3. Keep them bounded with the system's logrotate, for example in `/etc/logrotate.d/typo3-ai-suite-mcp` (adjust the path and the retention to what your data protection concept allows):

```
/var/www/html/var/log/aisuite_mcp*.log /var/www/html/var/log/cheddi*.log {
    daily
    rotate 14
    maxage 14
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
}
```

`copytruncate` keeps the file handle PHP has open; without it, entries are written into the rotated file until PHP-FPM restarts.

### Outbound network egress

MCP tools that use AI (`generate*`, `translate*`, `batch*`, `audit*`) inherit the network configuration of the parent `autodudes/ai-suite` extension. Outbound HTTPS is required to:

- the AI Suite Server configured in AI Suite (`aiSuiteServer`); it reaches the AI providers, also with your own keys, so TYPO3 itself does not call provider hosts
- every remote URL `uploadMedia` is asked to download, unless `mcpMediaAllowUrlFetch = 0`

In hardened environments with strict egress firewalls, allowlist the AI Suite Server host, and decide deliberately whether `uploadMedia` may fetch from the internet.

## Feedback

We're actively shaping this extension and the upcoming public custom-tool API. If you try it out, especially against a real editorial workflow, we'd love to hear from you:

- 🐛 **Bugs / regressions** → please file an issue with the relevant `aisuite_mcp_warnings.log` excerpt and the connector you used.
- 💡 **Tool gaps** → if you reached for a tool that doesn't exist (a third-party table you'd like discoverable, a workflow not covered by the built-ins), tell us what the LLM should have been able to do. This is the most valuable feedback for the `AbstractCustomTool` API design.
- 🔌 **New connectors** → if your favourite MCP client isn't in `Connectors/`, share the redirect URI / origin / auth flow it expects and we'll add a guide.
- 🔒 **Security findings** → please contact us directly rather than opening a public issue.

The fastest way to reach us is the [#ai-suite channel on TYPO3 Slack](https://typo3.slack.com/archives/C05QAN1KNVD). You can also reach us via [service@autodudes.de](mailto:service@autodudes.de).

## License

GPL-2.0-or-later
