<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Translation;

/**
 * Marks a translation tool whose model-less call already writes: it creates the localization
 * records and hands the source fields back instead of only listing the available models.
 */
interface SelfTranslatingToolInterface {}
