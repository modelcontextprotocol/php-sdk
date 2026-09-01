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
 * Server for {@see \Mcp\Tests\Integration\SamplingToolsTest}.
 */

use Mcp\Schema\Content\SamplingMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\ToolResultContent;
use Mcp\Schema\Content\ToolUseContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Tool;
use Mcp\Server\RequestContext;
use Mcp\Server\Server;
use Mcp\Server\Transport\StdioTransport;

require_once dirname(__DIR__, 3).'/vendor/autoload.php';

$weather = new Tool(
    'get_weather',
    null,
    ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
    'Get current weather for a city',
    null,
);

Server::builder()
    ->setServerInfo('integration-server', '1.0.0')
    ->addTool(
        static function (RequestContext $context, string $city) use ($weather): string {
            $gateway = $context->getClientGateway();

            // The spec forbids sending tools to a client that did not advertise
            // sampling.tools, so the loop is only entered when it did.
            if (!$gateway->supportsSamplingTools()) {
                return 'client cannot use tools during sampling';
            }

            $messages = [new SamplingMessage(Role::User, new TextContent(sprintf('Weather in %s?', $city)))];

            $answer = $gateway->sample($messages, maxTokens: 64, options: ['tools' => [$weather]]);
            $messages[] = new SamplingMessage(Role::Assistant, $answer->content);

            $toolResults = [];
            foreach ($answer->getContentBlocks() as $block) {
                if ($block instanceof ToolUseContent) {
                    $toolResults[] = new ToolResultContent(
                        $block->id,
                        [new TextContent(sprintf('%s: 18 C', $block->input['city'] ?? 'unknown'))],
                    );
                }
            }

            if ([] === $toolResults) {
                return 'the model asked for no tools';
            }

            $messages[] = new SamplingMessage(Role::User, $toolResults);

            $final = $gateway->sample($messages, maxTokens: 64, options: ['tools' => [$weather]]);
            assert($final->content instanceof TextContent);

            return sprintf('%s (%s)', $final->content->text, $final->stopReason);
        },
        name: 'weather_report',
        description: 'Reports weather by running a sampling tool loop.',
    )
    ->build()
    ->run(new StdioTransport());
