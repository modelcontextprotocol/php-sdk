<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * STDIO Client Elicitation Example.
 *
 * This example demonstrates how a client responds to server-initiated
 * elicitation/create requests. The server's tools ask the user for additional
 * information mid-execution; the client below prompts interactively on STDIN
 * and accepts defaults when the user presses Enter.
 *
 * Run against the elicitation demo server:
 *   php examples/client/stdio_elicitation.php
 */

require_once __DIR__.'/../../vendor/autoload.php';

use Mcp\Client;
use Mcp\Client\Handler\Request\ElicitationCallbackInterface;
use Mcp\Client\Handler\Request\ElicitationRequestHandler;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Elicitation\AbstractSchemaDefinition;
use Mcp\Schema\Elicitation\BooleanSchemaDefinition;
use Mcp\Schema\Elicitation\EnumSchemaDefinition;
use Mcp\Schema\Elicitation\NumberSchemaDefinition;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\ElicitResult;

$elicitationRequestHandler = new ElicitationRequestHandler(new class implements ElicitationCallbackInterface {
    public function __invoke(ElicitRequest $request): ElicitResult
    {
        echo "\n[ELICIT] {$request->message}\n";

        $content = [];
        foreach ($request->requestedSchema->properties as $name => $definition) {
            $default = $this->defaultFor($definition);
            $label = $this->labelFor($definition);

            if (null !== $default) {
                $display = is_bool($default) ? ($default ? 'true' : 'false') : (string) $default;
                echo "  {$label} [{$display}]: ";
            } else {
                echo "  {$label}: ";
            }

            $rawInput = fgets(\STDIN);
            $input = false === $rawInput ? '' : trim($rawInput);
            $value = '' === $input ? $default : $this->cast($definition, $input);

            $content[$name] = $value;
        }

        return new ElicitResult(ElicitAction::Accept, $content);
    }

    private function defaultFor(object $definition): mixed
    {
        return match (true) {
            $definition instanceof EnumSchemaDefinition => $definition->default ?? $definition->enum[0],
            $definition instanceof NumberSchemaDefinition => $definition->default ?? $definition->minimum ?? ($definition->integerOnly ? 1 : 1.0),
            $definition instanceof BooleanSchemaDefinition => $definition->default ?? false,
            $definition instanceof StringSchemaDefinition => $definition->default ?? ('date' === $definition->format ? date('Y-m-d') : ''),
            default => null,
        };
    }

    private function labelFor(AbstractSchemaDefinition $definition): string
    {
        return $definition->title;
    }

    private function cast(object $definition, string $input): mixed
    {
        return match (true) {
            $definition instanceof BooleanSchemaDefinition => filter_var($input, \FILTER_VALIDATE_BOOLEAN),
            $definition instanceof NumberSchemaDefinition => $definition->integerOnly ? (int) $input : (float) $input,
            default => $input,
        };
    }
});

$client = Client::builder()
    ->setClientInfo('STDIO Elicitation Test', '1.0.0')
    ->setInitTimeout(30)
    ->setRequestTimeout(120)
    ->setCapabilities(new ClientCapabilities(elicitation: true))
    ->addRequestHandler($elicitationRequestHandler)
    ->build();

$transport = new StdioTransport(
    command: 'php',
    args: [__DIR__.'/../server/elicitation/server.php'],
);

try {
    echo "Connecting to MCP server...\n";
    $client->connect($transport);

    $serverInfo = $client->getServerInfo();
    echo 'Connected to: '.($serverInfo->name ?? 'unknown')."\n\n";

    echo "Calling 'book_restaurant'...\n";
    $result = $client->callTool(
        name: 'book_restaurant',
        arguments: ['restaurantName' => 'The Test Kitchen'],
    );

    echo "\nResult:\n";
    foreach ($result->content as $content) {
        if ($content instanceof TextContent) {
            echo $content->text."\n";
        }
    }

    echo "\nCalling 'confirm_action'...\n";
    $result = $client->callTool(
        name: 'confirm_action',
        arguments: ['actionDescription' => 'Delete all temporary files'],
    );

    echo "\nResult:\n";
    foreach ($result->content as $content) {
        if ($content instanceof TextContent) {
            echo $content->text."\n";
        }
    }
} catch (Throwable $e) {
    echo "Error: {$e->getMessage()}\n";
    echo $e->getTraceAsString()."\n";
} finally {
    $client->disconnect();
}
