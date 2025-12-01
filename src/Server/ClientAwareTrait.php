<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server;

use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\Result\CreateSamplingMessageResult;

/**
 * @phpstan-import-type SampleOptions from ClientGateway
 *
 * @deprecated since 0.2.0, to be removed in 0.3.0. Use the RequestContext->getClientGateway() directly instead.
 */
trait ClientAwareTrait
{
    private ClientGateway $client;

    public function setClient(ClientGateway $client): void
    {
        $this->client = $client;
    }

    private function notify(Notification $notification): void
    {
        $this->client->notify($notification);
    }

    private function log(LoggingLevel $level, mixed $data, ?string $logger = null): void
    {
        $this->client->log($level, $data, $logger);
    }

    private function progress(float $progress, ?float $total = null, ?string $message = null): void
    {
        $this->client->progress($progress, $total, $message);
    }

    /**
     * @param SampleOptions $options
     */
    private function sample(string $prompt, int $maxTokens = 1000, int $timeout = 120, array $options = []): CreateSamplingMessageResult
    {
        return $this->client->sample($prompt, $maxTokens, $timeout, $options);
    }
}
