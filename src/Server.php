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

use Mcp\JsonRpc\Handler;
use Mcp\Server\ServerBuilder;
use Mcp\Server\Session\SessionFactoryInterface;
use Mcp\Server\Session\SessionStoreInterface;
use Mcp\Server\TransportInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class Server
{
    public function __construct(
        private readonly Handler $jsonRpcHandler,
        private readonly SessionFactoryInterface $sessionFactory,
        private readonly SessionStoreInterface $sessionStore,
        private readonly int $sessionTtl,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public static function make(): ServerBuilder
    {
        return new ServerBuilder();
    }

    public function connect(TransportInterface $transport): void
    {
        $transport->initialize();

        $this->logger->info('Transport initialized.', [
            'transport' => $transport::class,
        ]);

        $transport->on('message', function (string $message) use ($transport) {
            $this->handleMessage($message, $transport);
        });
    }

    private function handleMessage(string $message, TransportInterface $transport): void
    {
        try {
            foreach ($this->jsonRpcHandler->process($message) as $response) {
                if (null === $response) {
                    continue;
                }

                $transport->send($response);
            }
        } catch (\JsonException $e) {
            $this->logger->error('Failed to encode response to JSON.', [
                'message' => $message,
                'exception' => $e,
            ]);
        }
    }
}
