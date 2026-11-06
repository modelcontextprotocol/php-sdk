<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Concern;

use Mcp\Exception\InvalidArgumentException;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
trait AnnotationsFactories
{
    /**
     * Hydrates an optional "annotations" field, rejecting a value that is present but not an object.
     *
     * @param string $context the surrounding schema type, used for the error message
     */
    public static function tryFromArray(mixed $data, string $context): ?self
    {
        if (null === $data) {
            return null;
        }

        if (!\is_array($data)) {
            throw new InvalidArgumentException(\sprintf('Invalid "annotations" in %s data; expected an array.', $context));
        }

        return self::fromArray($data);
    }
}
