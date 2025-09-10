<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace Mcp\Capability\Prompt\Completion;

/**
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
interface ProviderInterface
{
    /**
     * Get completions for a given current value.
     *
     * @param string $currentValue the current value to get completions for
     *
     * @return string[] the completions
     */
    public function getCompletions(string $currentValue): array;
}
