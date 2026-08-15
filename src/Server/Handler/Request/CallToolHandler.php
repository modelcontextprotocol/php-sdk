<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Handler\Request;

use Mcp\Capability\Discovery\SchemaValidator;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\RegistryInterface;
use Mcp\Exception\ToolCallException;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @implements RequestHandlerInterface<CallToolResult>
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class CallToolHandler implements RequestHandlerInterface
{
    private SchemaValidator $schemaValidator;

    public function __construct(
        private readonly RegistryInterface $registry,
        private readonly ReferenceHandlerInterface $referenceHandler,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?SchemaValidator $schemaValidator = null,
    ) {
        $this->schemaValidator = $schemaValidator ?? new SchemaValidator($logger);
    }

    public function supports(Request $request): bool
    {
        return $request instanceof CallToolRequest;
    }

    /**
     * @return Response<CallToolResult>|Error
     */
    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        \assert($request instanceof CallToolRequest);

        $toolName = $request->name;
        $arguments = $request->arguments;

        $this->logger->debug('Executing tool', ['name' => $toolName, 'arguments' => $arguments]);

        try {
            $reference = $this->registry->getTool($toolName);
        } catch (ToolNotFoundException $e) {
            $this->logger->error('Tool not found', ['name' => $toolName, 'exception' => $e]);

            return new Error($request->getId(), Error::METHOD_NOT_FOUND, $e->getMessage());
        }

        $inputSchema = $reference->tool->inputSchema;
        $validationErrors = $this->schemaValidator->validateAgainstJsonSchema($arguments, $inputSchema);
        if (!empty($validationErrors)) {
            $errorMessages = [];

            foreach ($validationErrors as $errorDetail) {
                $pointer = $errorDetail['pointer'] ?? '';
                $message = $errorDetail['message'] ?? 'Unknown validation error';
                $errorMessages[] = ('/' !== $pointer && '' !== $pointer ? "Property '{$pointer}': " : '').$message;
            }

            $summaryMessage = "Invalid parameters for tool '{$toolName}': ".implode('; ', \array_slice($errorMessages, 0, 3));
            if (\count($errorMessages) > 3) {
                $summaryMessage .= '; ...and more errors.';
            }

            return Error::forInvalidParams($summaryMessage, $request->getId(), ['validation_errors' => $validationErrors]);
        }

        $arguments['_session'] = $session;
        $arguments['_request'] = $request;

        $context = new RequestContext($session, $request);

        try {
            $result = $this->referenceHandler->handle($reference, $arguments);

            $protocolVersion = $context->getProtocolVersion();

            $structuredContent = null;
            if (!$result instanceof CallToolResult) {
                $structuredContent = $reference->extractStructuredContent($result, $protocolVersion);

                if (null === $structuredContent && null !== $reference->tool->outputSchema) {
                    $this->logger->warning('Tool declares an "outputSchema" but returned a value that cannot be sent as "structuredContent"; the value is only carried in "content".', [
                        'name' => $toolName,
                        'result_type' => get_debug_type($result),
                    ]);
                }

                $result = new CallToolResult($reference->formatResult($result), structuredContent: $structuredContent);
            } elseif ($protocolVersion->requiresObjectStructuredContent()
                && null !== $result->structuredContent
                && [] !== $result->structuredContent
                && !self::isJsonObject($result->structuredContent)
            ) {
                // A tool building its own `CallToolResult` bypasses the extraction
                // rules on purpose, so the value is sent as it was set — but before
                // SEP-2106 only a JSON object is valid here, whether the value is a
                // list or a scalar, and clients may reject it.
                $this->logger->warning('Tool returned a "CallToolResult" whose "structuredContent" is not a JSON object, which the negotiated protocol revision requires; sending it unchanged.', [
                    'name' => $toolName,
                    'protocol_version' => $protocolVersion->value,
                    'structured_content_type' => get_debug_type($result->structuredContent),
                ]);
            }

            $this->logger->debug('Tool executed successfully', [
                'name' => $toolName,
                'result_type' => \gettype($result),
                'structured_content' => $structuredContent,
            ]);

            return new Response($request->getId(), $result);
        } catch (ToolCallException $e) {
            $this->logger->error(\sprintf('Error while executing tool "%s": "%s".', $toolName, $e->getMessage()), [
                'tool' => $toolName,
                'arguments' => $arguments,
                'exception' => $e,
            ]);

            $errorContent = [new TextContent($e->getMessage())];

            return new Response($request->getId(), CallToolResult::error($errorContent));
        } catch (\Throwable $e) {
            $this->logger->error('Unhandled error during tool execution', [
                'name' => $toolName,
                'exception' => $e,
            ]);

            return Error::forInternalError('Error while executing tool', $request->getId());
        }
    }

    /**
     * Whether a `structuredContent` value encodes as a JSON object — the only shape
     * revisions predating SEP-2106 accept.
     */
    private static function isJsonObject(mixed $value): bool
    {
        return \is_array($value) && !array_is_list($value);
    }
}
