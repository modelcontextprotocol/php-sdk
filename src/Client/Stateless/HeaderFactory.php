<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client\Stateless;

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Wire\McpHeader;

/**
 * The HTTP headers a modern-era client puts on every POST (SEP-2243, SEP-2575).
 *
 * These exist so an intermediary can route and authorize MCP traffic without
 * parsing the body. That only works if the headers cannot disagree with what
 * they mirror, so they are derived here from the very message that is about to
 * be sent — never from what the caller believes it is sending.
 *
 * @see \Mcp\Server\Stateless\StandardHeaderValidator the server that checks this
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class HeaderFactory
{
    public function __construct(
        private readonly ToolCatalog $tools,
    ) {
    }

    /**
     * @param array<string, mixed> $payload a serialized JSON-RPC message
     *
     * @return array<string, string>
     */
    public function forMessage(array $payload, ProtocolVersion $protocolVersion): array
    {
        $method = $payload['method'] ?? null;

        // A response to a server-initiated request carries no method to mirror;
        // the version header is unconditional and still applies.
        if (!\is_string($method)) {
            return [McpHeader::PROTOCOL_VERSION => $protocolVersion->value];
        }

        $params = \is_array($payload['params'] ?? null) ? $payload['params'] : null;

        $headers = [
            McpHeader::PROTOCOL_VERSION => $protocolVersion->value,
            McpHeader::METHOD => $method,
        ];

        if (null !== $name = McpHeader::nameFor($method, $params)) {
            // Tool and prompt names are only SHOULD-constrained to header-safe
            // characters and a resource URI is not constrained at all, so
            // anything unsafe is wrapped rather than dropped or mangled.
            $headers[McpHeader::NAME] = McpHeader::encode($name) ?? $name;
        }

        if ('tools/call' === $method && \is_string($params['name'] ?? null)) {
            $arguments = \is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

            foreach ($this->tools->headersFor($params['name'], $arguments) as $suffix => $value) {
                $headers[McpHeader::PARAM_PREFIX.$suffix] = $value;
            }
        }

        return $headers;
    }
}
