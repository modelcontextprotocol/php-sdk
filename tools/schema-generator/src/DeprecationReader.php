<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tools\SchemaGenerator;

/**
 * Reads `@deprecated` tags from the specification's schema.ts, which typescript-json-schema drops from schema.json.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class DeprecationReader
{
    /**
     * @return array<string, string> deprecation notice keyed by "Type" or "Type.property"
     */
    public function read(string $typeScript): array
    {
        $lines = preg_split('/\R/', $typeScript) ?: [];
        $deprecations = [];
        $currentInterface = null;
        $docBlock = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (\is_array($docBlock)) {
                $docBlock[] = $trimmed;
                if (str_contains($trimmed, '*/')) {
                    $docBlock = implode("\n", $docBlock);
                }
                continue;
            }

            if (str_starts_with($trimmed, '/**')) {
                $docBlock = str_contains($trimmed, '*/') ? $trimmed : [$trimmed];
                continue;
            }

            if ('' === $trimmed) {
                continue;
            }

            $notice = \is_string($docBlock) ? $this->deprecationOf($docBlock) : null;
            $docBlock = null;

            if (preg_match('/^export\s+(?:interface|type)\s+(\w+)/', $trimmed, $match)) {
                $currentInterface = str_contains($trimmed, 'interface') ? $match[1] : null;
                if (null !== $notice) {
                    $deprecations[$match[1]] = $notice;
                }
                continue;
            }

            if (str_starts_with($line, '}')) {
                $currentInterface = null;
                continue;
            }

            if (null !== $notice && null !== $currentInterface && preg_match('/^["\']?([\w.\/$-]+)["\']?\??:/', $trimmed, $match)) {
                $deprecations[$currentInterface.'.'.$match[1]] = $notice;
            }
        }

        return $deprecations;
    }

    private function deprecationOf(string $docBlock): ?string
    {
        $text = [];
        $inTag = false;

        foreach (explode("\n", $docBlock) as $line) {
            $line = trim(preg_replace('#^/?\*+/?|\*/$#', '', trim($line)) ?? '');
            if (str_starts_with($line, '@deprecated')) {
                $inTag = true;
                $line = trim(substr($line, \strlen('@deprecated')));
            } elseif (str_starts_with($line, '@')) {
                $inTag = false;
            }

            if ($inTag && '' !== $line) {
                $text[] = $line;
            }
        }

        return [] === $text ? null : implode(' ', $text);
    }
}
