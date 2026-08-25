<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Server for {@see \Mcp\Tests\Integration\HandshakeTest}.
 *
 * The test pins the revision through the environment; unset negotiates freely.
 */

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

require_once dirname(__DIR__, 3).'/vendor/autoload.php';

$builder = Server::builder()
    ->setServerInfo('integration-server', '1.0.0')
    ->setInstructions('Be brief.');

if (is_string($pinned = getenv('MCP_INTEGRATION_PROTOCOL_VERSION')) && '' !== $pinned) {
    $builder->setProtocolVersion(ProtocolVersion::from($pinned));
}

$builder->build()->run(new StdioTransport());
