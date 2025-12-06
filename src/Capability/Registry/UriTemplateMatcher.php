<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Registry;

/**
 * Utility for matching URIs against URI templates (RFC 6570 simple syntax).
 *
 * Handles templates like "file://{path}" or "db://users/{id}/profile".
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 * @author Mateu Aguiló Bosch <mateu@mateuaguilo.com>
 */
final class UriTemplateMatcher
{
    /**
     * @var array<string, array{regex: string, variables: array<int, string>}>
     */
    private array $compiledTemplates = [];

    /**
     * Checks if a URI matches a URI template pattern.
     *
     * @param string $uri         The concrete URI to check
     * @param string $uriTemplate The URI template with {placeholders}
     */
    public function matches(string $uri, string $uriTemplate): bool
    {
        $compiled = $this->compile($uriTemplate);

        return 1 === preg_match($compiled['regex'], $uri);
    }

    /**
     * Extracts variable values from a URI based on a template.
     *
     * @param string $uri         The concrete URI to extract from
     * @param string $uriTemplate The URI template with {placeholders}
     *
     * @return array<string, string> Map of variable name => extracted value
     */
    public function extractVariables(string $uri, string $uriTemplate): array
    {
        $compiled = $this->compile($uriTemplate);
        $matches = [];

        if (!preg_match($compiled['regex'], $uri, $matches)) {
            return [];
        }

        return array_filter(
            $matches,
            fn ($key) => \in_array($key, $compiled['variables'], true),
            \ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Gets the variable names defined in a URI template.
     *
     * @param string $uriTemplate The URI template with {placeholders}
     *
     * @return array<int, string> List of variable names
     */
    public function getVariableNames(string $uriTemplate): array
    {
        return $this->compile($uriTemplate)['variables'];
    }

    /**
     * Compiles a URI template into a regex pattern and extracts variable names.
     *
     * Results are cached for performance.
     *
     * @return array{regex: string, variables: array<int, string>}
     */
    private function compile(string $uriTemplate): array
    {
        if (isset($this->compiledTemplates[$uriTemplate])) {
            return $this->compiledTemplates[$uriTemplate];
        }

        $variableNames = [];
        $regexParts = [];

        $segments = preg_split('/(\{\w+\})/', $uriTemplate, -1, \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY);

        foreach ($segments as $segment) {
            if (preg_match('/^\{(\w+)\}$/', $segment, $matches)) {
                $varName = $matches[1];
                $variableNames[] = $varName;
                $regexParts[] = '(?P<'.$varName.'>[^/]+)';
            } else {
                $regexParts[] = preg_quote($segment, '#');
            }
        }

        $this->compiledTemplates[$uriTemplate] = [
            'regex' => '#^'.implode('', $regexParts).'$#',
            'variables' => $variableNames,
        ];

        return $this->compiledTemplates[$uriTemplate];
    }
}
