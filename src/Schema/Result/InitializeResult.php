<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Result;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Implementation;
use Mcp\Schema\JsonRpc\MessageInterface;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Schema\ServerCapabilities;

/**
 * After receiving an initialize request from the client, the server sends this response.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class InitializeResult implements ResultInterface
{
    /**
     * Create a new InitializeResult.
     *
     * @param ServerCapabilities       $capabilities the capabilities of the server
     * @param Implementation           $serverInfo   information about the server
     * @param string|null              $instructions Instructions describing how to use the server and its features. This can be used by clients to improve the LLM's understanding of available tools, resources, etc. It can be thought of like a "hint" to the model. For example, this information MAY be added to the system prompt.
     * @param array<string,mixed>|null $_meta        optional _meta field
     */
    public function __construct(
        public readonly ServerCapabilities $capabilities,
        public readonly Implementation $serverInfo,
        public readonly ?string $instructions = null,
        public readonly ?array $_meta = null,
        public readonly ?ProtocolVersion $protocolVersion = null,
    ) {
    }

    /**
     * @param array{
     *     protocolVersion: string,
     *     capabilities: array<string, mixed>,
     *     serverInfo: array<string, mixed>,
     *     instructions?: string,
     *     _meta?: array<string, mixed>,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['protocolVersion']) || !\is_string($data['protocolVersion'])) {
            throw new InvalidArgumentException('Missing or invalid "protocolVersion".');
        }
        if (!isset($data['capabilities']) || !\is_array($data['capabilities'])) {
            throw new InvalidArgumentException('Missing or invalid "capabilities".');
        }
        if (!isset($data['serverInfo']) || !\is_array($data['serverInfo'])) {
            throw new InvalidArgumentException('Missing or invalid "serverInfo".');
        }

        return new self(
            ServerCapabilities::fromArray($data['capabilities']),
            Implementation::fromArray($data['serverInfo']),
            $data['instructions'] ?? null,
            $data['_meta'] ?? null,
            ProtocolVersion::tryFrom($data['protocolVersion']),
        );
    }

    /**
     * @return array{
     *     protocolVersion: string,
     *     capabilities: ServerCapabilities,
     *     serverInfo: Implementation,
     *     instructions?: string,
     *     _meta?: array<string, mixed>,
     * }
     */
    public function jsonSerialize(): array
    {
        $protocolVersion = $this->protocolVersion ?? MessageInterface::PROTOCOL_VERSION;
        $data = [
            'protocolVersion' => $protocolVersion->value,
            'capabilities' => $this->capabilities,
            'serverInfo' => $this->serverInfo,
        ];
        if (null !== $this->instructions) {
            $data['instructions'] = $this->instructions;
        }
        if (null !== $this->_meta) {
            $data['_meta'] = $this->_meta;
        }

        return $data;
    }
}
