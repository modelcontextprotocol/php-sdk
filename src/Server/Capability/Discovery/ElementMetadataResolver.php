<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Capability\Discovery;

/**
 * Derives an element's name and description from its handler method, used by
 * both attribute discovery and reflection-based manual registration.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class ElementMetadataResolver
{
    private function __construct()
    {
    }

    /**
     * Explicit names win; otherwise invokable classes are named after the class,
     * regular handlers after the method.
     */
    public static function resolveName(\ReflectionMethod $method, ?string $name): string
    {
        $methodName = $method->getName();

        return $name ?? ('__invoke' === $methodName ? $method->getDeclaringClass()->getShortName() : $methodName);
    }

    /**
     * Explicit descriptions win; otherwise the summary of the method's doc block is used.
     */
    public static function resolveDescription(\ReflectionMethod $method, ?string $description, DocBlockParser $docBlockParser): ?string
    {
        return $description ?? $docBlockParser->getDescription($docBlockParser->parseDocBlock($method->getDocComment() ?? null));
    }
}
