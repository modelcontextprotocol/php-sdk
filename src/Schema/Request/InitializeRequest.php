<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Request;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Implementation;
use Mcp\Schema\JsonRpc\Request;

/**
 * This request is sent from the client to the server when it first connects, asking it to begin initialization.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class InitializeRequest extends Request
{
    /**
     * @param string             $protocolVersion The latest version of the Model Context Protocol that the client supports. The client MAY decide to support older versions as well.
     * @param ClientCapabilities $capabilities    the capabilities of the client
     * @param Implementation     $clientInfo      information about the client
     */
    public function __construct(
        public readonly string $protocolVersion,
        public readonly ClientCapabilities $capabilities,
        public readonly Implementation $clientInfo,
    ) {
    }

    public static function getMethod(): string
    {
        return 'initialize';
    }

    protected static function fromParams(?array $params): static
    {
        if (!isset($params['protocolVersion'])) {
            throw new InvalidArgumentException('protocolVersion is required');
        }

        if (!isset($params['capabilities'])) {
            throw new InvalidArgumentException('capabilities is required');
        }
        $capabilities = ClientCapabilities::fromArray($params['capabilities']);

        if (!isset($params['clientInfo'])) {
            throw new InvalidArgumentException('clientInfo is required');
        }
        $clientInfo = Implementation::fromArray($params['clientInfo']);

        return new self($params['protocolVersion'], $capabilities, $clientInfo);
    }

    protected function getParams(): ?array
    {
        return [
            'protocolVersion' => $this->protocolVersion,
            'capabilities' => $this->capabilities,
            'clientInfo' => $this->clientInfo,
        ];
    }
}
