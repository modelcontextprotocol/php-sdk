<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Discovery;

/**
 * Converts a class-typed tool result into a JSON-serializable representation.
 *
 * The output counterpart to {@see PropertyDescriberInterface}: it turns an
 * instance returned by a tool into the scalar/array form described by the
 * schema (e.g. a `\DateTimeImmutable` into an ISO-8601 string) before it is
 * encoded, instead of relying on the default `json_encode()` of the object.
 */
interface PropertyNormalizerInterface extends PropertyHandlerInterface
{
    /**
     * @param object $value the instance returned by the tool
     *
     * @return mixed a JSON-serializable representation of $value
     */
    public function normalize(object $value): mixed;
}
