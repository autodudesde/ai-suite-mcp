<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Utility;

/**
 * The single source of the normative text every front end sends to the model.
 *
 * There used to be an "Approach A (external AI) vs Approach B (manual)" fork here, mirrored into
 * eight tool descriptions. It forced the model to make a meta-decision *before* it could pick a
 * tool, and whether credits are spent is a property of the installation, not something the model
 * should deliberate. The fork is gone: tools are described by what they uniquely do, and the model
 * picks one the way it picks any other tool.
 *
 * ## Approval is the host's job. Never write a rule that tells the model to wait for it.
 *
 * A rule saying "get explicit user approval before writing" outlived the server-side preview gate it
 * belonged to. Measured against a benchmark with no human present: gpt-5.4-nano and gpt-oss-120b
 * obeyed it — they called previewRecords, then stopped and waited forever. The task never completed.
 * claude-haiku-4-5 ignored the rule and finished. The rule did not protect anything (there is no gate
 * to satisfy any more); it only cost the two weaker models the task.
 *
 * This is the third instance of one failure mode. `getOperatingGuidelines` said "REQUIRED FIRST STEP"
 * and burned a turn. deleteRecords said "ask the user for confirmation before calling" and the model
 * answered in prose instead of calling the tool. Both were removed after measurement showed the model
 * was doing exactly what it was told.
 *
 * Confirmation happens outside the model: ChEddi's ChatService stops Write/Destructive calls with
 * needsConfirm, and MCP clients raise their own approval dialog. The model's job is to call the tool.
 * Prose that inserts a human into that loop makes the model hang, not the system safe.
 */
class OperatingGuidelines
{
    /**
     * The normative text, one entry per section, in the order it is sent.
     *
     * Keys are stable so a front end can reason about the set; the *text* is what the model sees.
     * A tool description must never repeat any of this (see ToolDescriptionConventionTest): a
     * section is sent once per session and cached, a description is sent on every single turn.
     *
     * @return array<string, string>
     */
    public static function sections(): array
    {
        return [
            'targetPage' => self::targetPage(),
            'defaults' => self::defaults(),
            'discoverFields' => self::discoverFields(),
            'rules' => self::rules(),
            'credits' => self::credits(),
            'smallEdits' => self::smallEdits(),
            'workspace' => self::workspace(),
            'batchVsSingle' => self::batchVsSingle(),
            'bulkOps' => self::bulkOps(),
        ];
    }

    public static function get(): string
    {
        return implode("\n\n", self::sections());
    }

    /**
     * The full normative set for the MCP `initialize` instructions.
     *
     * Sent once per session and cached by the provider alongside the tool definitions, which is
     * strictly cheaper than making the model spend a reasoning turn on a bootstrap tool call.
     */
    public static function getForInstructions(): string
    {
        return self::get();
    }

    /**
     * Same text for the chat drawer.
     *
     * The two front ends may only ever differ in *which operational sections* apply — never in
     * whether the normative ones are present. The chat prompt once omitted them entirely, so the
     * drawer's model was never told the rules at all. Today both send everything;
     * OperatingGuidelinesSectionsTest keeps it that way.
     */
    public static function contentRulesForChat(): string
    {
        return self::get();
    }

    private static function targetPage(): string
    {
        return <<<'SECTION'
            ## Identifying the target page
            Never guess a page UID, and never carry the UID of an earlier turn over to a new request. Whenever the user names a page (a title such as "the page Only admins", or a slug), resolve it to its UID first: readPageTree returns every page with its title and UID, hidden pages included; searchContent(searchIn: "pages") finds one by title.
            You cannot see which page the user is looking at. A request that points at a page without naming it ("here", "this page", "the current page") does not identify one — ask which page is meant. Never fall back to the site root or to any page you happened to read earlier.
            When a tool hands you a page UID — savePageTree returning a page it just created, for instance — that UID is the page to keep working with. Content that belongs on a new page takes the new page's UID as its `pid`, never the UID of the parent it was created under.
            A page's content lives in its content elements, not in subpages. Create exactly the pages the editor asked for: "a landing page" is one page whose sections are content elements, not a page with an "Inhalte" or "Content" subpage under it. Only nest subpages (savePageTree children) when the editor asked for a page hierarchy or navigation.
            If a name matches no page, or more than one, ask which page is meant. Writing to a different page than the one the user named is worse than asking, and doing it without a word is worse still.
            SECTION;
    }

    private static function defaults(): string
    {
        return <<<'SECTION'
            ## Defaults instead of questions
            Do not interview the editor. When a request leaves details open, choose sensible defaults from what you already know (the page, its editorial guidelines, an attachment, the surrounding content), state them in one short line, and carry the request out. The host asks the editor to confirm before anything is written, so a default that misses costs one click, not data.
            Ask only when the request cannot be carried out at all without the answer — when two pages match the name, or when it points at a page ("here", "this page") without naming one, which you have no way of resolving. Then ask that one question, not a list. A page is the one thing you must never default.
            Carry out the whole request, not its shell. An element that takes children is created together with its children. A page requested from an attachment is created together with the content from that attachment.
            When you are done, name the one obvious next step in a single sentence and offer to do it.
            SECTION;
    }

    private static function discoverFields(): string
    {
        return <<<'SECTION'
            ## Discovering fields before writing
            Decide first WHAT you are creating. Not everything is a page or a tt_content element: a news article, event, job, address, product or FAQ is usually its own record in its own table (e.g. tx_news_domain_model_news), kept in a folder rather than as page content. When the editor names such a record kind, or when readPageContent reports other record types on the target page, do NOT fall back to a tt_content element or a subpage — call listTables to find the matching table and writeRecords a record into it with pid set to that folder. A SysFolder that looks empty of content elements almost always holds domain records of one table; readPageContent lists them.
            Never guess CTypes, field names or colPos values — discover them first: listContentTypes(pageId) for content elements; it returns the available CTypes and the valid top-level colPos values of the page, and with includeContainers also the containers already on the page. For any other table call readRecordSchema(table[, type]).
            Pick the most specific CType the content fits: a list of points is a bullets element, a price or feature matrix is a table element, a set of questions is an accordion, a set of cards is a card group. A plain text element is the fallback for prose, not the default for everything — a page built entirely from text elements is a page you gave up on.
            Do not set presentation fields — frame_class, header_layout, layout, appearance and the like — on your own initiative. They control how content renders, not what it says, and a guessed value overrides the site's own default. Leave them out and the site default applies; readRecordSchema also prints a `suggested` value sampled from the page's other elements, which is safe to reuse. Never invent one, and never set frame_class to "none" as a default — "default" is the default.
            But when the editor DOES ask for a specific look — "three columns", "boxed", a particular layout — that request IS the instruction to set the matching field. Setting it then is required, not "guessing"; do not skip it as presentation.
            Some CTypes keep that look in a configuration, a flex field such as pi_flexform: a card group's column count lives there, not in a plain column. listContentTypes marks such a CType with `config: pi_flexform`. If the editor asked for a layout that a flex field controls — three-column cards, for instance — you MUST call readFlexFormSchema for that CType, find the setting (the column count, say), and write pi_flexform with it. Never invent the structure and never pass XML. Leaving pi_flexform empty ships the element on its default — a two-column card group — which is not what was asked.
            A layout the editor asked for applies to EVERY element of that kind, not just the first. If they want three-column card groups, set columns to 3 on all the card groups you create, not only the opening one — a page with one three-column group and the rest on the default looks like a mistake.
            A container element holds its children through `tx_container_parent` + `colPos`. Create the container and its children in the SAME writeRecords call: the container is record 0, each child sets `tx_container_parent: "$ref:0"` and a colPos from that container's grid. A container written without children renders as an empty box and is rejected.
            readRecordSchema reports each field's content kind (rte = HTML honoured; text/plaintext = markup stripped on write; lines = line-based, see below; json; relation), its read-only status and its relation kind — check it before writing.
            Some fields are line-based: one newline separates one entry. The bullets CType renders one list item per line of bodytext, the table CType one row per line. Send those as plain text with real newlines, one entry per line — no <ul>/<li>, no bullet glyphs, no <br>. readRecordSchema and listContentTypes print a Format note on every field that works this way.
            Before you write or translate content for a page, call readEditorialGuidelines(pageId) with the page the content will live on — not the page you looked at before it: it returns the tone, target audience and style the editors configured for that subtree, both the general rules and the ones for the scope you ask about. Honour them. They are the only channel for those rules; nothing else feeds them to you.
            When EDITING an existing rich-text (RTE) field, first read it with readRecords(raw: true) and edit that raw HTML. A normal read strips tags to a plain-text preview; writing that flattened text back would destroy the stored <h2>/<p>/<a>/<ul> markup.
            readRenderedPage(pageId) returns the page as a visitor sees it, including plugin output; readPageContent returns the stored tt_content rows and cannot show that.
            SECTION;
    }

    private static function rules(): string
    {
        return <<<'SECTION'
            ## Rules
            - Changes are confirmed by the host, not by you: state in one sentence what you are about to do, then call the tool. The user is asked to approve the call before it runs. This includes deleting — never answer a delete request in prose instead of calling deleteRecords.
            - previewRecords renders an old→new diff of a change. Use it to show the user what a change looks like, not as a step you must clear before writing.
            SECTION;
    }

    private static function credits(): string
    {
        return <<<'SECTION'
            ## Tools that cost credits
            generateFileMetadata, generateImage, the translate* tools and the batch* tools call the AI Suite Server and consume credits. Reach for them for what only they can do:
            - generateImage — creates an image. It writes to FAL directly; no preview exists.
            - generateFileMetadata — inspects the file itself (AI vision), which is what makes alt text useful.
            - translatePage, translateRecord, translateFileMetadata — DeepL plus the site's glossary, for consistent terminology. They write the translation directly; do not call writeRecords afterwards. localizeRecord only creates an empty translation shell and costs nothing.
            Anything else you compose yourself and persist with writeRecords — that costs no credits.
            An AI tool called without the `model` parameter does not run yet: it answers with the models available to this user. Call it again with one of those models to run it.
            SECTION;
    }

    private static function smallEdits(): string
    {
        return <<<'SECTION'
            ## Small edits on existing records — do NOT resend whole fields
            For small corrections (a typo, an em-dash, a single word) on an existing record, prefer the safe-edit tools over rewriting the whole field with writeRecords:
            - replaceText(table, uid, field, search, replace) — replace a literal fragment (unique by default; pass all:true for every occurrence). Returns an old/new snippet.
            - patchText(table, uid, field, replacements) — several ordered replacements in one write; if any fails, nothing is written.
            - bulkReplaceText(parentUid, childTable, relationField, field, search, replace) — apply the same fix to every child (e.g. each card in a card group).
            These keep the payload tiny, preserve surrounding HTML, and are far less likely to be blocked by a client's safety filters than resending a full bodytext.
            To list the actual child records (container or IRRE) of an element before editing, call readChildren(uid).
            SECTION;
    }

    private static function workspace(): string
    {
        return <<<'SECTION'
            ## Working in a non-live workspace
            If this session is bound to a workspace (check readServerInfo for the active workspace), use compareWithLive to see what your draft changes against the live site — a per-record field-level diff (changed/added/removed). Other read tools show the workspace-overlaid state, not the diff.
            SECTION;
    }

    private static function batchVsSingle(): string
    {
        return <<<'SECTION'
            ## When to use batch vs. single tools
            - SEO metadata for 1 page → there is no single tool for this, and you do not need one. Read the page with readPageContent, look up the fields with readRecordSchema(table: "pages"), write seo_title / description / og_* / twitter_* yourself and persist them with writeRecords. This costs no credits.
            - 1 file → generateFileMetadata (it looks at the image itself; you only ever see a thumbnail).
            - 1 page to translate → translatePage.
            - 2+ specific files by UID → use batchGenerateFileMetadata or batchTranslateFileMetadata.
            - All files in a folder → use batchGenerateFolderMetadata or batchTranslateFolderMetadata.
            - 2+ pages → use batchGenerateMetadata or batchTranslatePage.
            Batch tools run asynchronously and return a task ID:
            1. Call batch tool → returns task ID.
            2. Save the task ID locally. Tell the user a batch is running, then continue with other work.
            3. Do NOT poll readTaskStatus automatically or loop on it — only check when the user asks about it. If tasks are still pending, say so and continue.
            4. Once status is "completed" → retrieve results via readTaskResults (paginate with offset/limit if needed).
            5. For translation batches: show the results, then call applyTaskResults(taskId) to write the translations.
            6. For metadata batches: show the results, then persist them via writeRecords.
            Batch tools do NOT write to the database themselves — their results are suggestions until you call applyTaskResults or writeRecords, and the host asks the user to approve that call.
            SECTION;
    }

    private static function bulkOps(): string
    {
        return <<<'SECTION'
            ## Site-wide / bulk operations — do NOT loop one page at a time
            For tasks that span many pages, discover and act in bulk instead of calling a per-page tool in a loop:
            - Reading/auditing content across a subtree (consistency or tone checks, find-and-replace, preparing a bulk edit): call readContentTree(rootPageId, depth, language?) — it returns every element (with its UID) across the subtree, paginated by page. Do NOT call readPageContent per page. Page through with offset.
            - Editing the same thing on many pages/elements: collect the target UIDs (from readContentTree), then write them all in a SINGLE writeRecords call — its records array takes one entry per record, each with its own uid/pid. Same for inserting a new element on many pages (one record per page, each with its pid).
            - Finding records whose field is empty (missing SEO/OG title, missing alt text, ...): call readRecords with an empty-string filter, e.g. readRecords(table: "pages", filters: {og_title: ""}) or readRecords(table: "sys_file_reference", filters: {alternative: ""}). Scope it to a branch by taking the page UIDs from readPageTree(rootPageId, depth) first. Collect the resulting UIDs and feed them into batchTranslatePage / batchGenerateMetadata / batchGenerateFileMetadata in ONE call instead of handling pages individually.
            - Finding where a term/string occurs: searchContent(query). Use readContentTree when you need the full text of a subtree rather than keyword hits.
            SECTION;
    }
}
