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
 * State for the transport.
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
enum RunnerState
{
    case RUNNING;
    case STOP_AND_DESTROY_SESSION;
    case STOP;
}
