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
 * Server for {@see \Mcp\Tests\Integration\SamplingTest}.
 */

use Mcp\Exception\ClientException;
use Mcp\Schema\Content\TextContent;
use Mcp\Server;
use Mcp\Server\RequestContext;
use Mcp\Server\Transport\StdioTransport;

require_once dirname(__DIR__, 3).'/vendor/autoload.php';

Server::builder()
    ->setServerInfo('integration-server', '1.0.0')
    ->addTool(
        static function (RequestContext $context, string $text): string {
            try {
                $result = $context->getClientGateway()->sample($text, maxTokens: 64);
            } catch (ClientException $e) {
                return $e->getMessage();
            }

            assert($result->content instanceof TextContent);

            return sprintf('%s said: %s', $result->model, $result->content->text);
        },
        name: 'summarize',
        description: 'Summarizes text by asking the client to sample.',
    )
    ->build()
    ->run(new StdioTransport());
