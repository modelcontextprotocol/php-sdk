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

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server\Builder;
use Mcp\Server\Protocol;
use Mcp\Server\Stateless\StatelessProtocol;
use Mcp\Server\Transport\StatelessAwareTransportInterface;
use Mcp\Server\Transport\TransportInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
final class Server
{
    /**
     * @param StatelessProtocol|null               $statelessProtocol the modern-era (SEP-2575) dispatcher, absent on a
     *                                                                server that serves the handshake era alone
     * @param non-empty-list<ProtocolVersion>|null $handshakeVersions revisions `initialize` negotiates, all of them by default
     */
    public function __construct(
        private readonly Protocol $protocol,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?StatelessProtocol $statelessProtocol = null,
        private readonly ?array $handshakeVersions = null,
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

        // The eras share the transport, not the dispatcher: a transport that
        // can tell them apart takes both and picks per request. One that
        // cannot carries the handshake era alone.
        if ($transport instanceof StatelessAwareTransportInterface) {
            $transport->setHandshakeVersions($this->handshakeVersions ?? ProtocolVersion::handshakeVersions());

            if (null !== $this->statelessProtocol) {
                $transport->connectStateless($this->statelessProtocol);
            }
        }

        $this->logger->info('Running server...');

        try {
            return $transport->listen();
        } finally {
            $transport->close();
        }
    }
}
