<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Extension;

use Mcp\Schema\JsonRpc\Request;
use Mcp\Server\Session\SessionInterface;

/**
 * An extension that hands objects of its own to tool, prompt and resource
 * handlers, the way the SDK hands them a `RequestContext`.
 *
 * A handler declaring a parameter of one of the provided types receives what
 * the provider builds for the request being served — so an extension's API
 * reaches handler code without the core request context having to know it.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface ArgumentProvidingExtensionInterface extends ExtensionInterface
{
    /**
     * Builders for the types this extension injects, keyed by the type.
     *
     * @return array<class-string, callable(SessionInterface, Request): object>
     */
    public function getArgumentProviders(): array;
}
