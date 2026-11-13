<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Capability;

use Mcp\Server\Capability\Registry\Loader\LoaderInterface;

/**
 * A registry that takes part in its own load lifecycle.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface DeferredRegistryInterface
{
    /**
     * Runs a pending load, if there is one. A failed load stays pending.
     */
    public function load(): void;

    public function isLoaded(): bool;

    /**
     * Runs $loader against this registry without announcing its writes as list changes, since they
     * describe the registry filling up rather than changing.
     */
    public function loadFrom(LoaderInterface $loader): void;
}
