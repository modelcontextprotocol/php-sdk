<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client;

/**
 * Polled while a request waits for its response.
 */
interface CancellationTokenInterface
{
    public function isCancellationRequested(): bool;
}
