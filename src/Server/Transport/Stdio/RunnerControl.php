<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport\Stdio;

/**
 * Default implementation of RunnerControlInterface. This will allow
 * anyone to change the state of the runner.
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class RunnerControl implements RunnerControlInterface
{
    public static RunnerState $state = RunnerState::RUNNING;

    public function getState(): RunnerState
    {
        return self::$state;
    }
}
