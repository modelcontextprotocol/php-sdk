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

final class ManualHandlers
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * A manually registered tool.
     *
     * @param string $user the user to greet
     *
     * @return string greeting
     */
    public function manualGreeter(string $user): string
    {
        $this->logger->info("Manual tool 'manual_greeter' called for {$user}");

        return "Hello {$user}, from manual registration!";
    }

    /**
     * Manually registered resource that overrides a discovered one.
     *
     * @return string content
     */
    public function getPriorityConfigManual(): string
    {
        $this->logger->info("Manual resource 'config://priority' read.");

        return 'Manual Priority Config: HIGH (overrides discovered)';
    }
}
