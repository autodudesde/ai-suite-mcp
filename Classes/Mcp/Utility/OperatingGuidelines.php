<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Utility;

/**
 * Used by:
 * - getOperatingGuidelines tool (automatic, via initialize instruction)
 * - operating-guidelines prompt (manual fallback)
 */
class OperatingGuidelines
{
    public static function get(): string
    {
        return <<<'GUIDELINES'
            ## Two approaches for creating/modifying content

            ### Approach A: External AI-powered (costs credits)
            Use generate*, translate*, optimize* tools when the user wants external AI-generated content.
            1. Call the tool WITHOUT `model` parameter → returns list of available models.
            2. Call the tool again WITH chosen `model` → returns a **preview directly in the response** (no additional tool call needed).
            3. Display the preview to the user and wait for explicit user approval.
            4. After explicit user approval → persist via writeRecords.

            **Exceptions to step 4:**
            - generateImage — no preview possible for images. Ask the user for confirmation BEFORE calling. Writes directly to FAL.
            - savePageTree — writes a page tree directly. Display the generated tree to the user and get confirmation BEFORE calling.

            ### Approach B: Manual (no credits)
            Use this when composing content yourself or when external AI tools are unavailable.
            1. Discover valid fields:
               - For content elements (tt_content): call getContentTypes(pageId) to learn available CTypes, then getColumnPositions(pageId) for valid colPos values. If you need field details for a specific CType, also call getRecordSchema(table: "tt_content", type: "textmedia").
               - For other tables (pages, custom records): call getRecordSchema(table) to learn field names and types.
               Never guess CTypes, field names, or colPos values.
            2. Compose the content yourself.
            3. Call previewRecords with the data → display the preview to the user → wait for explicit user approval.
            4. After explicit user approval → persist via writeRecords.

            ### Rules that apply to BOTH approaches
            - Never write to the database without displaying a preview and getting explicit user approval first.
            - Never guess CTypes, field names, or colPos values — always discover them first.
            - Never delete records without explicit user confirmation.
            - If the user says "just create it", still display the preview. It is fast and prevents mistakes.
            - When EDITING an existing record's bodytext or another rich-text (RTE) field, first read it with readRecords(raw: true) and edit that raw HTML. A normal read strips tags to a plain-text preview; writing that flattened text back would destroy the stored <h2>/<p>/<a>/<ul> markup.

            ## Working in a non-live workspace
            If this session is bound to a workspace (check getServerInfo for the active workspace), use compareWithLive to see what your draft changes against the live site — a per-record field-level diff (changed/added/removed). Other read tools show the workspace-overlaid state, not the diff.

            ## When to use batch vs. single tools
            - 1 page/file → use the single tool (generateMetadata, translatePage, etc.).
            - 2+ specific files by UID → use batchGenerateFileMetadata or batchTranslateFileMetadata.
            - All files in a folder → use batchGenerateFolderMetadata or batchTranslateFolderMetadata.
            - 2+ pages → use batchGenerateMetadata or batchTranslatePage.
            Batch tools run asynchronously and return a task ID:
            1. Call batch tool → returns task ID.
            2. Save the task ID locally. Inform the user that a batch is running. Continue with other work.
            3. Do NOT poll getTaskStatus automatically — only check when the user asks about it.
            4. Once status is "completed" → retrieve results via getTaskResults (paginate with offset/limit if needed).
            5. For translation batches: display results → after user approval → call getTaskResults(taskId, apply: true) to write translations.
            6. For metadata batches: display results → after user approval → persist via writeRecords.
            Batch tools do NOT write to the database automatically — results are suggestions that require user approval.

            ## Background tasks
            Background tasks run asynchronously. Do NOT poll or loop to check status — save the task ID and only check when the user explicitly asks. If tasks are still pending, let the user know and continue with other work.
            GUIDELINES;
    }
}
