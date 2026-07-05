<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\Server\CombinedRegistration;

use Psr\Log\LoggerInterface;

/**
 * A handler whose constructor takes a scalar the container cannot auto-wire, so
 * it can only be registered as a pre-built object instance:
 * `->addTool([new PreconfiguredGreeter('...', ...), 'greet'], 'instance_greeter')`.
 *
 * Neither the container-less `new $className()` fallback nor the auto-wiring
 * container can build this class, since the required `string $greeting` has no
 * default and is not a resolvable service.
 */
final class PreconfiguredGreeter
{
    public function __construct(
        private readonly string $greeting,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * A tool registered as a pre-built object instance.
     *
     * @param string $name the name to greet
     *
     * @return string greeting
     */
    public function greet(string $name): string
    {
        $this->logger->info("Instance tool 'instance_greeter' called for {$name}");

        return "{$this->greeting}, {$name}!";
    }
}
