<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Exception;

final class ReferenceExecutionException extends RegistryException
{
    /**
     * @param non-empty-list<non-empty-string> $messages
     */
    public function __construct(
        public readonly array $messages,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(implode("\n", $this->messages), previous: $previous);
    }
}
