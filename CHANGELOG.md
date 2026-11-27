# Changelog

All notable changes to AI Suite MCP are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the versions follow
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Security fixes are listed under **Security** and published as
[GitHub security advisories](https://github.com/autodudesde/ai-suite-mcp/security/advisories); see
[SECURITY.md](SECURITY.md) for supported versions and how to report a vulnerability.

## [0.13.0] - 2026-11-15

### Added

- Added readable values to record previews: relations show the title of their target, checkboxes their meaning, and nested items, FlexForm values and images their content instead of the stored raw values and relation pointers
- Records with nested file references on an existing record are now refused in the preview, before the editor confirms anything
- Added a dry run for bulkReplaceText that returns the old and new value per record without writing
- Every writing tool is now classified by where its write lands (draft, live or no content), so a connected client can tell the editor before confirming

### Changed

- Creating a folder named like an existing one at the storage root or in the upload folder is now refused with a pointer to the existing folder, unless allowSameNameFolder is set
- Clearer refusals and hints: the full file reference record to write instead of a nested file, the position where a records JSON string breaks, a file passed as the source of copyMediaReference, and the address a refused audit was sent for

### Fixed

- Fixed translated records in every table keeping the slug of the source language
- Fixed every tool call failing with a missing MCP SDK class after installing the extension from TER on v12 and v13
- Fixed uploadMedia and moveFile refusing editors without a file mount on the storage root
- Fixed moveRecords failing when a model sends afterUid 0 next to a target page
- Fixed compareWithLive listing translation columns and empty relations as changes
- Fixed readRecords leaving translated pages out of a page subtree
- Fixed readAuditResults answering only with score and counts, and reporting a missing stored audit as an error

### Upgrade notes

- Please clear all caches after updating.
- Requires AI Suite 12.26.0, 13.20.0 or 14.8.0.

## [0.12.0] - 2026-10-28

### Added

- Added the moveFile tool to move files between folders of the same storage while keeping all references and links
- Expanded nested inline children up to three levels deep in writeRecords, so an element with tabs and their entries is created in one call
- Made the per-token rate limit configurable (mcpRateLimitRequests, mcpRateLimitWindowSeconds, mcpRateLimitLockoutSeconds)
- Added the site language to backend links on multilingual sites, so a page and its translation can be told apart
- Limited OAuth access tokens to 60 minutes with automatic refresh and split the lifetime settings (mcpAccessTokenLifetimeMinutes, mcpRefreshIdleDays)

### Changed

- Hardened the OAuth flow: stored client registrations, exact redirect URI matching, only requested scopes, required MFA and the core backend login rules
- Required each AI tool's own backend feature flag in addition to its scope
- Enforced the session credit budget on batch tools and after token refreshes
- Kept the verbose log off by default, redacted logs reliably and added warnings to the Reports module
- Applied only the glossary of the content's site in the translate tools and kept the link target when a link title is translated
- Resolved global instructions and languages from the record's own page or folder
- Kept supplied page text above the editorial guidelines and returned other scopes' guidelines when the requested scope has none
- Kept the order of batches that copy or move records to one page and moved reparented inline children behind their new siblings
- Kept page slugs under the parent path on create and move
- Kept relation values on the live uid inside a workspace
- Linked the metadata record of uploaded files in the batch result
- Improved tool answers: sort order in readChildren, hidden state in localizeRecord, moves and reorders in compareWithLive, ctrl columns in readRecords, workspace overlay in readFileInfo, nested child collections in listContentTypes
- Localized the OAuth pages, the MCP screens and the extension configuration

### Upgrade notes

- Please clear all caches after updating and compare database.
- OAuth connectors now refresh their access token every hour. Raise mcpAccessTokenLifetimeMinutes for a connector that does not refresh correctly.
- Remote connectors (claude.ai, ChatGPT, ...) need their callback URLs in mcpAllowedRedirectUris; an empty list only allows localhost.
- A proxy that terminates TLS has to be listed in mcpTrustedProxies. A local instance in the Production context needs HTTPS or mcpAllowHttp.
- Backend groups need enable_metadata_generation, enable_translation_whole_page or enable_translation_sys_file_metadata for the matching tools.
- sys_redirect, sys_webhook, sys_reaction, sys_file_storage, sys_filemounts and sys_workspace can no longer be read or written through MCP.
- ai-suite-mcp:create-token and ai-suite-mcp:server require --user, create-token also --audience. Creating a token in the MCP screen asks for the backend password.
- The verbose log (mcpLogVerbose) is off for new installations.

## [0.11.0] - 2026-10-02

### Changed

- Optimized the extension settings tabs
- Removed the OAuth consent table `tx_aisuite_oauth_consents`. The consent screen was already shown on every authorization and still is.
- **Breaking:** `McpUserContext::initialize()` no longer takes a `tokenId`, and the session key and token id accessors are gone

### Upgrade notes

- Please clear all caches after updating and compare database (the table `tx_aisuite_oauth_consents` can be dropped).
- Requires AI Suite 14.6.1, 13.18.1 or 12.24.1. If ChEddi is installed, update it to v0.4.0 together with this release.

## [0.10.0] - 2026-09-26

### Added

- Extended uploadMedia so that existing files can be replaced in place (references keep pointing to the updated file) and missing target folders can be created
- Extended listFiles to optionally return subfolders
- Added the setting `mcpMaxRequestBodyMb` to raise the request size limit, e.g. for base64 uploads of videos (default 1 MB)

### Changed

- Revised the German backend translations

### Fixed

- Fixed translating inline child records through writeRecords

### Upgrade notes

- Please clear all caches after updating.
- Requires AI Suite 14.6.1, 13.18.1 or 12.24.1.

## [0.9.0] - 2026-09-22

### Breaking changes

- `auditQuestions`, `auditContentGap`, `auditTopicCluster` and `auditCompetitors` are replaced by `auditContent` with an analysis type; `batchGenerateFolderMetadata` and `batchTranslateFolderMetadata` are replaced by a `folderIdentifiers` property on the two batch file metadata tools. Update the tool names in your own prompts and integrations.
- The settings `mcpSearchAdditionalTables` and `mcpExcludeAdditionalTablesFromSearch` are removed, along with ChEddi's `chatSearchAdditionalTables` and `chatExcludeAdditionalTablesFromSearch`. Searchable tables are resolved from the TCA now; existing values are ignored. Review the excluded tables setting instead, which is added to a shipped default denylist.

### Added

- `searchContent` now sweeps every table an editor can reach through the page tree that carries text, resolved from the TCA instead of from a configured list.
- Reading tools name an element the way an editor would recognise it.
- `readPageContent` now lists the records on a page that belong to no content element, named instead of counted.
- A single `readRecords` read resolves the files behind a FAL column, so `generateFileMetadata` and `replaceMediaReference` get file uids instead of the child count the column stores.
- Every tool call is recorded in the EU AI Act provenance register of AI Suite.
- Backend links in tool results now open in the running backend session: clicked in an external chat such as claude.ai or ChatGPT, they no longer land on the login screen. The new setting `mcpBackendLinkBounce` (default on) turns the wrapping off.
- Added support for MCP revision 2026-07-28 alongside the legacy protocol era, which fixes connectors that could not connect at all, and an unauthenticated request now receives the 401 carrying the discovery hint instead of a 400.
- Added support for installations without Composer: the MCP SDK ships bundled, so the extension runs in TYPO3 12, 13 and 14 classic mode.

### Changed

- The self-translation hand-over now names the fields to write in its text, not only in the structured content, so a model no longer guesses a column that does not exist.
- The button offered after a page translation now opens the parent page in the target language instead of the empty translation row.
- The audit tools now take the page an editor names instead of requiring a URL
- Batch tools accept a subtree root beside a UID list and treat a zero as an omitted parameter instead of refusing the call.
- Safe-edit answers now name the layer they are about, so a preview of an already edited draft can no longer be mistaken for a dry run that wrote.
- `replaceMediaReference` is declared idempotent and the hint was removed from the read tools that carried it wrongly.
- Upgraded the vendored MCP SDK to logiscape/mcp-sdk-php v2.0.1 and removed an unused rate-limit factory.

### Fixed

- Fixed a self-translation being overwritten by its own source and a second translation level being created, which left the overlay carrying the source language again.
- Fixed the workspace comparison reporting the changes of one page beside the record count of its subpages.

### Upgrade notes

- Please clear all caches after updating.

## [0.8.0] - 2026-09-02

### Added

- Added SEO and accessibility audits as MCP tools (`auditSeo`, `auditAccessibility`), available with the `mcp:read` scope and the new `enable_audit` permission, honouring the configured WCAG conformance level.
- Added four content-analysis audit tools: question coverage, content gap, topic cluster and competitor comparison, each with an explicit search market parameter.
- Added `readAuditResults` for free read access to audit results already stored by AI Suite.
- Added credit accounting: every tool declares its fixed credit cost (`getCreditCost`), and the credits a connector session may spend can be capped.
- Grouped the MCP settings into categories and subcategories for a clearer configuration screen.

### Changed

- The tool origin check is now applied to every registered tool.
- The server instructions are now sent over the stdio transport as well.
- Write tools now return the records they actually touched, and the tool schemas no longer repeat themselves.
- Documented the Basic Auth carve-out for nginx and the OpenID configuration endpoint.

### Fixed

- Fixed the configured token lifetime being ignored.
- Fixed the generated Claude Desktop configuration not being accepted by the client.

### Upgrade notes

- Please clear all caches after updating.

## [0.7.0] - 2026-08-10

### Added

- Tool results now carry backend links to the records they touched, so every MCP client can open a record instead of only seeing its UID. The new "MCP Backend Base URL" setting supplies the host for shareable links.
- `writeRecords` can write the translations of a record along with it, via a `translations` object keyed by ISO code.
- `translateRecord` and `translatePage` no longer demand a model: without one they hand back the translation fields, glossary and editorial instructions for the calling model to translate, which costs no translation credits.
- `writeRecords`, `previewRecords`, `deleteRecords` and `moveRecords` accept a top-level `table` for entries that carry none.
- Both spellings of the translation pointer field (`l10n_parent` and `l18n_parent`) are accepted and mapped onto the table's real column.
- `readRecords` gained `rootPageId` to scope a list read to a whole subtree, and accepts `uid` and `pid` as readable fields.
- `searchContent` reports the language of every hit.
- The Reports module gained an "MCP Session Store" entry with the session file count and a warning above 500 files.

### Changed

- Failing tool calls are now logged with tool, error type and message instead of only `isError: true`.

### Fixed

- Fixed a failed `uploadMedia` returning prose without a structured result, leaving clients without error type or counts.
- Fixed empty search results reading as "the record does not exist" when the table was never searched. They now name the tables that were searched.
- Fixed an invalid record losing its table and UID on the way into a preview.
- Fixed the record preview understating a write by not naming the planned translations.
- Fixed hidden records shifting the insert position of newly created records.

### Upgrade notes

- Please clear all caches after updating.
- Breaking change: the write mode "auto" has been removed, as it was the only mode that could silently send a write to live. An installation still configured as "auto" now writes into a draft workspace; set "MCP Write Mode" to "Live" explicitly to keep live writes.

## [0.6.0] - 2026-08-04

### Added

- Added page mode to bulkReplaceText: `pageIds` (or `relationField: "pid"`) rolls a wording change over up to 50 pages in one call, with multiple replacement rules and a `dryRun`.
- Added a `fields` parameter to readRecords so a read returns only the requested columns instead of every field.
- searchContent now sweeps IRRE child tables automatically, derived from the TCA; the new settings `mcpSearchAdditionalTables` and `mcpExcludeAdditionalTablesFromSearch` add or remove single tables.
- searchContent now reports the matching field and the tables it searched, and matches the text of the current workspace instead of the live record.
- savePageTree appends new pages at the end by default and understands `start`, `after:UID` and `before:UID`; `before:UID` works in writeRecords as well.
- Batch payloads of writeRecords, moveRecords, deleteRecords and copyRecords are validated up front, so all malformed entries are reported in one message.
- Search texts are located across differing line endings, and a text that cannot be found reports where the stored value starts to diverge.
- readRecordSchema now lists the HTML tags a rich-text field keeps, so generated markup is no longer removed at the next editorial save.
- readEditorialGuidelines, compareWithLive and readServerInfo now explain empty or reduced answers instead of leaving them unexplained.

### Changed

- **Breaking:** `mcpWriteMode=workspace` now refuses a call when no draft workspace can be provisioned, where it previously wrote to live. Switch to `mcpWriteMode=auto` or `=live` if writing to live is intended.
- applyTaskResults now reports the tasks a batch could not apply, instead of closing with "All translations applied successfully" on a partly dead run.

### Fixed

- Fixed code editor fields (`tt_content.bodytext` of CType "html" and comparable fields) losing their markup on write. Such fields are stored verbatim now, gated behind the new setting `mcpAllowRawHtmlWrite` (off by default) because TYPO3 renders them unfiltered; while it is off the write is refused instead of silently flattened (fixes [#1](https://github.com/autodudesde/ai-suite-mcp/issues/1), thx [@Crocus99](https://github.com/Crocus99))
- Fixed the read tools returning live data in a draft workspace: readChildren, readRecords, readPageContent and readContentTree resolve the workspace overlay now, so a child is no longer listed twice, a record deleted in the draft no longer looks restored, and an edit is visible to the next read.
- Fixed relations written in a draft pointing at the live record instead of the versioned one, which stored the record correctly but rendered it nowhere.
- Fixed writes to inline child-collection columns re-parenting unrelated records; any value for such a column is refused before DataHandler runs.
- Fixed MCP tokens not being revocable in the backend module, where the confirmation dialog rendered without title, message and confirm button. A token dropped by the active-token cap is now named in the module and in the log.
- Fixed the initialize handshake failing for clients that send `params._meta`, which kept claude.ai's connector from connecting at all, and made the announced prompt surface reachable.
- Fixed a tool call being dropped when its session had aged out; the endpoint mints a replacement session instead of answering 404 mid-turn.
- Fixed bulkReplaceText reporting a correct run as "Batch FAILED" when records simply contained nothing to match.
- Fixed an entity mismatch being reported as guesswork; the error now says so and points at `raw: true`.
- Fixed error messages resolving to an empty string when a language label was missing, instead of falling back to the English text.

### Upgrade notes

- Please clear all caches after updating.
- `mcpWriteMode=workspace` no longer falls back to live writing. If an installation relies on that fallback, switch it to `mcpWriteMode=auto` or `mcpWriteMode=live`.

## [0.5.0] - 2026-07-23

### Added

- Workspace write mode is now the default: MCP writes land in a draft workspace, and a per-user workspace is created automatically if the editor has none. ext:workspaces is therefore a hard requirement instead of a suggestion, and the mode stays configurable (mcpWriteMode: workspace / auto / live).
- Reworked the tool surface into a consistent lexicon: read tools are named read*, listing tools list*. Renamed getPageContent, getPageTree, getFileInfo, getServerInfo, getRecordSchema, getFlexFormSchema, getTaskStatus, getTaskResults, findStaleContent, getTables, getPageTypes and getContentTypes accordingly.
- Added readContentTree to read every content element of a whole page subtree, with UIDs and page-wise pagination, in a single call instead of looping readPageContent per page.
- Added readEditorialGuidelines so tone, audience and style rules for a given page reach the model before it writes.
- Added readChildren for container and inline children, plus copyMediaReference and replaceMediaReference for image and media relations.
- Added the safe-edit tools replaceText, patchText and bulkReplaceText: small, targeted edits with old/new excerpts instead of rewriting a whole field.
- writeRecords now writes inline children in the same call, accepts common alias shapes (type for the real CType field), refuses a newly created container that no child points at, and offers an optional atomic mode that rolls the whole batch back on failure.
- previewRecords shows a per-field old/new diff on updates and accepts the same record shapes as writeRecords.
- batchGenerateMetadata can now target a whole page subtree via rootPageId + recursive instead of requiring a resolved UID list; the subtree expansion is capped at 50 pages and honours the caller's page permissions.
- Translation tools now offer the site's actual languages as an enum instead of a free-text ISO code, so an unavailable language is rejected by parameter validation. On single-language installations the translation tools are hidden entirely.
- tools/list now ships per-tool annotations, so MCP clients such as Claude Desktop can offer "Always allow" for read-only tools while write, AI and media tools keep their per-call confirmation.
- The initialize response carries a compact session orientation (sites, write mode, writable tables), so the model knows what it may write from the first turn.
- listContentTypes returns the page's valid colPos values, a FlexForm bucket with the matching readFlexFormSchema follow-up and a container's inline children; readRecordSchema reports readOnly, eval, relation kind and content kind.
- readRecords curates system and noise fields out of its output and resolves relation UIDs to readable titles, honouring the user's table access.

### Changed

- Closed a critical authentication bypass in the OAuth consent step, where a client-supplied user ID could mint a full token without a password. The identity is now bound to an HMAC-signed consent ticket issued only after successful authentication. Added per-IP rate limiting on the token and registration endpoints and fixed same-origin validation, which previously rejected the consent POST on production setups without configured origins.
- FlexForm values are validated against the real data structure before they reach the DataHandler. A wrongly shaped value used to be stored as an unreadable FlexForm, which then broke every later read of the record and the frontend rendering, while the write itself still reported success.
- Records without a type value are now validated and described against their default sub-schema, instead of against every column of the table. Creating a plain EXT:news article no longer fails with required fields that belong to other record types.
- writeRecords no longer invites a JSON-string payload in its schema. Batches sent as an escaped string arrived truncated and were silently lost; a truncated payload is now recognised, reported with its length, and the model is told to split the batch.
- batchTranslatePage and batchTranslateFileMetadata now refuse an unresolvable target language. Previously it silently became the source language, and the batch paid per page to translate content into the language it was already in.
- Batch results report isError: true on partial and total failure, instead of always reporting success. Errors carry a machine-readable type, and DataHandler errors are translated into classified messages (e.g. missing permissions) rather than raw log output.
- The prompts capability is now announced over HTTP as well. A spec-compliant client never asked for a prompt before, so the whole prompt surface was unreachable outside stdio.
- readPageContent now lists the other record tables stored on a page, so a record folder is no longer read as empty and the model stops falling back to a page or content element when a domain record was asked for.
- generateImage and uploadMedia are flagged destructive, since they write to live file storage that no workspace can undo.
- Trimmed tool descriptions and shared guideline snippets to cut the token cost of the tool catalogue on every turn.
- Removed the tools generateContent, generatePageTree, generateLandingPage, generateMetadata, optimizeContent, auditContent, getColumnPositions and getOperatingGuidelines. Their results can be composed from the read and write tools, and each removed entry frees context on every turn. generateFileMetadata and batchGenerateMetadata stay, since they use vision models and server-side prompts a client cannot reconstruct.
- Dropped the OAuth scopes mcp:manage, mcp:glossary and mcp:easy-language. They were offered on the consent screen but gated nothing; the glossary is applied by the translate tools under mcp:translate.
- The operating guidelines no longer force the model into an approach decision before it can pick a tool and no longer ask it to wait for an approval the host is responsible for; they now steer toward sensible defaults, field discovery and correct container handling.

### Fixed

- Fixed an OAuth refresh-token rotation issue that logged connectors out mid-conversation: concurrent refreshes no longer race each other, a rotated token is separated from a revoked one, and a 30 second grace window (RFC 9700) serves a concurrent retry instead of raising a false token-theft alarm and revoking every session.
- Fixed a TypeError in resources/list with typed SDK request parameters.
- Fixed a uid_local resolution issue in the record access checks and several FlexForm record schema details.

### Upgrade notes

- Please clear all caches after updating and compare database.
- This release renames and removes MCP tools. Connected clients cache the tool list from their authorization, so reconnect or re-authorize the MCP connector after updating. ext:workspaces is now a hard requirement, and new MCP writes go into a draft workspace by default.

## [0.3.3] - 2026-05-27

### Changed

- Updated composer dependencies
- Added missing extension icon
