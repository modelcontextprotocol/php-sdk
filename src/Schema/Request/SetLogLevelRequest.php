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
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\JsonRpc\Request;

/**
 * A request from the client to the server, to enable or adjust logging.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class SetLogLevelRequest extends Request
{
    /**
     * @param LoggingLevel $level The level of logging that the client wants to receive from the server. The server
     *                            should send all logs at this level and higher (i.e., more severe) to the client as
     *                            notifications/message.
     */
    public function __construct(
        public readonly LoggingLevel $level,
    ) {
    }

    public static function getMethod(): string
    {
        return 'logging/setLevel';
    }

    protected static function fromParams(?array $params): static
    {
        if (!isset($params['level']) || !\is_string($params['level']) || empty($params['level'])) {
            throw new InvalidArgumentException('Missing or invalid "level" parameter for "logging/setLevel".');
        }

        return new self(LoggingLevel::from($params['level']));
    }

    protected function getParams(): ?array
    {
        return [
            'level' => $this->level->value,
        ];
    }
}
