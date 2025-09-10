<?php

declare(strict_types=1);

namespace Mcp\Server;

use Psr\Clock\ClockInterface;
use DateTimeImmutable;

class NativeClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
