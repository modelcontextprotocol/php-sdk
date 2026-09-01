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
 * Server for {@see \Mcp\Tests\Integration\ElicitationTest}.
 */

use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Server\Exception\ClientException;
use Mcp\Server\RequestContext;
use Mcp\Server\Server;
use Mcp\Server\Transport\StdioTransport;

require_once dirname(__DIR__, 3).'/vendor/autoload.php';

Server::builder()
    ->setServerInfo('integration-server', '1.0.0')
    ->addTool(
        static function (RequestContext $context): string {
            $gateway = $context->getClientGateway();

            if (!$gateway->supportsElicitation()) {
                return 'unsupported';
            }

            try {
                $result = $gateway->elicit('What is your name?', new ElicitationSchema([
                    'name' => new StringSchemaDefinition(title: 'Name'),
                ]));
            } catch (ClientException $e) {
                return $e->getMessage();
            }

            return sprintf('%s:%s', $result->action->value, $result->content['name'] ?? '');
        },
        name: 'ask_name',
        description: 'Asks the client for a name.',
    )
    ->build()
    ->run(new StdioTransport());
