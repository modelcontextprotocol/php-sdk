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
 * Server for {@see \Mcp\Tests\Integration\NotificationTest}.
 */

use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Server;
use Mcp\Server\RequestContext;
use Mcp\Server\Transport\StdioTransport;

require_once dirname(__DIR__, 3).'/vendor/autoload.php';

Server::builder()
    ->setServerInfo('integration-server', '1.0.0')
    ->addTool(
        static function (RequestContext $context): string {
            $gateway = $context->getClientGateway();

            $gateway->log(LoggingLevel::Info, 'starting work');
            $gateway->progress(0.5, 1.0, 'halfway');
            $gateway->progress(1.0, 1.0, 'done');

            return 'finished';
        },
        name: 'work',
        description: 'Reports progress and logs while working.',
    )
    ->build()
    ->run(new StdioTransport());
