<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Capability\Registry\Source;

/**
 * Provides elements as a value, so a source can be cached, filtered or composed.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface ElementSourceInterface
{
    public function provide(): ElementCollection;
}
