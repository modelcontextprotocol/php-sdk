<?php

namespace Mcp\Server\Transport;

/**
 * State for the transport.
 */
enum RunnerState
{
    case RUNNING;
    case KILL_SESSION_AND_STOP;
    case STOP;
}