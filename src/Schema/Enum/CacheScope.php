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
 * The analogue of HTTP's `Cache-Control: public` vs `private`. The distinction
 * is a security boundary, not a performance knob: marking a result public
 * allows shared gateways and proxies to serve it to *other* authorization
 * contexts, so anything shaped by who asked must stay private.
 *
 * {@see self::Private} is therefore the default everywhere in this SDK — an
 * over-cautious hint costs a cache hit, an over-broad one leaks a response.
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
