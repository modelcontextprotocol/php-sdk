<?php

namespace Mcp\Server\Transport;

/**
 * Used by the transport to control the runner state.
 */
interface RunnerControlInterface
{
    public function getState(): RunnerState;
}