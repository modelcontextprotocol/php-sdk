<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Capability\Registry\Loader;

use Mcp\Server\Capability\RegistryInterface;

/**
 * @author Antoine Bluchet <soyuka@gmail.com>
 */
interface LoaderInterface
{
    public function load(RegistryInterface $registry): void;
}
