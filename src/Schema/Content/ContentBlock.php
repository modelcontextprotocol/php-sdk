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
 * @phpstan-import-type AudioContentData from AudioContent
 * @phpstan-import-type EmbeddedResourceData from EmbeddedResource
 * @phpstan-import-type ImageContentData from ImageContent
 * @phpstan-import-type ResourceLinkData from ResourceLink
 * @phpstan-import-type TextContentData from TextContent
 *
 * @phpstan-type ContentBlockData TextContentData|ImageContentData|AudioContentData|ResourceLinkData|EmbeddedResourceData
 *
 * Generated from the MCP specification by tools/schema-generator, do not edit.
 */
interface ContentBlock extends Content
{
}
