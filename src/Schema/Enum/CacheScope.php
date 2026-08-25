<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Enum;

/**
 * How widely a cacheable result may be reused (SEP-2549).
 *
 * A security boundary, not a performance knob: `public` lets shared caches
 * serve the result to other authorization contexts, so anything shaped by who
 * asked must stay private — which is why private is the default throughout.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
enum CacheScope: string
{
    /** Contains no caller-specific data; any cache may share it. */
    case Public = 'public';

    /** Reusable only within the same authorization context. */
    case Private = 'private';
}
