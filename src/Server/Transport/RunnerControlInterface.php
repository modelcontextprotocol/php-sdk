<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport;

/**
 * Used by the transport to control the runner state.
 */
interface RunnerControlInterface
{
    public function getState(): RunnerState;
}
