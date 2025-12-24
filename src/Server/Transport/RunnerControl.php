<?php

namespace Mcp\Server\Transport;

class RunnerControl implements RunnerControlInterface
{
    public static RunnerState $state = RunnerState::RUNNING;

    public function getState(): RunnerState
    {
        return self::$state;
    }
}