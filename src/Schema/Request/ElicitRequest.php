<?php

declare(strict_types=1);

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
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\JsonRpc\Request;

/**
 * A request from the server to elicit additional information from the user.
 *
 * The client will present the message and requested schema to the user, allowing them
 * to provide the requested information, decline, or cancel the operation.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ElicitRequest extends Request
{
    /**
     * @param string            $message         A human-readable message describing what information is needed
     * @param ElicitationSchema $requestedSchema The schema defining the fields to elicit from the user
     */
    public function __construct(
        public readonly string $message,
        public readonly ElicitationSchema $requestedSchema,
    ) {
    }

    public static function getMethod(): string
    {
        return 'elicitation/create';
    }

    protected static function fromParams(?array $params): static
    {
        if (!isset($params['message']) || !\is_string($params['message'])) {
            throw new InvalidArgumentException('Missing or invalid "message" parameter for elicitation/create.');
        }

        if (!isset($params['requestedSchema']) || !\is_array($params['requestedSchema'])) {
            throw new InvalidArgumentException('Missing or invalid "requestedSchema" parameter for elicitation/create.');
        }

        return new self(
            $params['message'],
            ElicitationSchema::fromArray($params['requestedSchema']),
        );
    }

    /**
     * @return array{
     *     message: string,
     *     requestedSchema: ElicitationSchema,
     * }
     */
    protected function getParams(): array
    {
        return [
            'message' => $this->message,
            'requestedSchema' => $this->requestedSchema,
        ];
    }
}
