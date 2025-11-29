<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp;

use Mcp\Server\Builder;
use Mcp\Server\Protocol;
use Mcp\Server\Transport\TransportInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
final class Server
{
    public function __construct(
        private readonly Protocol $protocol,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function builder(): Builder
    {
        return new Builder();
    }

    /**
     * @template TResult
     *
     * @param TransportInterface<TResult> $transport
     *
     * @return TResult
     */
    public function run(TransportInterface $transport): mixed
    {
        $transport->initialize();

        $this->protocol->connect($transport);

        $this->logger->info('Running server...');

        try {
            return $transport->listen();
        } finally {
            $transport->close();
            $this->protocol->disconnect();
        }
    }
}
