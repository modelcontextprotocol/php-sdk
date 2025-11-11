<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability;

use Mcp\Capability\Registry\ReferenceProviderInterface;
use Mcp\Capability\Registry\ReferenceRegistryInterface;
use Mcp\Schema\ServerCapabilities;

interface RegistryInterface extends ReferenceRegistryInterface, ReferenceProviderInterface
{
    public function getCapabilities(): ServerCapabilities;

    public function setServerCapabilities(ServerCapabilities $serverCapabilities): void;
}
