<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Content;

/**
 * @deprecated since protocol revision 2026-07-28 (SEP-2577), earliest removal 2027-07-28
 *
 * @phpstan-import-type AudioContentData from AudioContent
 * @phpstan-import-type ImageContentData from ImageContent
 * @phpstan-import-type TextContentData from TextContent
 * @phpstan-import-type ToolResultContentData from ToolResultContent
 * @phpstan-import-type ToolUseContentData from ToolUseContent
 *
 * @phpstan-type SamplingMessageContentBlockData TextContentData|ImageContentData|AudioContentData|ToolUseContentData|ToolResultContentData
 *
 * Generated from the MCP specification by tools/schema-generator, do not edit.
 */
interface SamplingMessageContentBlock extends Content
{
}
