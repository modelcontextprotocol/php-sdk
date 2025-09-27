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
use Mcp\Server\TransportInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
final class Server
{
    public function __construct(
        private readonly Handler $jsonRpcHandler,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function builder(): ServerBuilder
    {
        return new ServerBuilder();
    }

    public function connect(TransportInterface $transport): void
    {
        $transport->initialize();

        $this->logger->info('Transport initialized.', [
            'transport' => $transport::class,
        ]);

        $transport->onMessage(function (string $message, ?Uuid $sessionId) use ($transport) {
            foreach ($this->jsonRpcHandler->process($message, $sessionId) as [$response, $context]) {
                if (null === $response) {
                    continue;
                }

                $transport->send($response, $context);
            }
        });

        $transport->onSessionEnd(function (Uuid $sessionId) {
            $this->jsonRpcHandler->destroySession($sessionId);
        });
    }
}
