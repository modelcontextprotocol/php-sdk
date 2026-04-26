<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Handler;

use Mcp\Server\ClientGateway;

/**
 * Contract for handlers that resolve their own arguments and execute at runtime.
 *
 * Unlike string/array/Closure handlers, a runtime handler is a stateful object
 * registered with a reference. The reference handler delegates argument
 * filtering and execution to it, and provides a {@see ClientGateway} so the
 * handler can communicate with the client (notifications, sampling, etc.).
 */
interface RunTimeHandlerInterface
{
    /**
     * Filters out arguments that the handler does not care about.
     *
     * The reference handler builds a generic argument map (including reserved
     * keys such as `_session` and `_request`); this method narrows it down to
     * what {@see self::execute()} expects.
     *
     * @param array<string, mixed> $arguments arguments as constructed by the reference handler
     *
     * @return array<string, mixed> the arguments the handler cares about
     *
     * @see \Mcp\Capability\Registry\ReferenceHandler::handle()
     */
    public function filterArguments(array $arguments): array;

    /**
     * Executes the handler and returns its result.
     *
     * @param array<string, mixed> $arguments the handler arguments as key-value pairs
     * @param ClientGateway        $gateway   client gateway for handlers that support callbacks
     *
     * @return mixed the handler result
     */
    public function execute(array $arguments, ClientGateway $gateway): mixed;

    /**
     * Returns the JSON Schema describing tool inputs.
     *
     * Returns null when this handler does not back a tool, or when the
     * Builder caller supplies the schema via the `inputSchema:` keyword.
     *
     * @return array<string, mixed>|null
     */
    public function getInputSchema(): ?array;

    /**
     * Returns the JSON Schema describing tool outputs.
     *
     * Returns null when no output schema applies (the field is itself optional
     * on Tool), or when the Builder caller supplies the schema via the
     * `outputSchema:` keyword.
     *
     * @return array<string, mixed>|null
     */
    public function getOutputSchema(): ?array;

    /**
     * Returns the prompt arguments for prompt-backed runtime handlers.
     *
     * Returns null when this handler does not back a prompt.
     *
     * @return list<\Mcp\Schema\PromptArgument>|null
     */
    public function getPromptArguments(): ?array;

    /**
     * Returns the completion providers for prompts and resource templates.
     *
     * Map of argument name => provider class-string or provider instance.
     * Returns null when no completion providers apply.
     *
     * @return array<string, class-string|object>|null
     */
    public function getCompletionProviders(): ?array;
}
