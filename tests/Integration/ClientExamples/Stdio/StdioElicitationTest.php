<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Integration\ClientExamples\Stdio;

final class StdioElicitationTest extends StdioClientExampleTestCase
{
    public static function provideInputs(): iterable
    {
        // Party size, date, dietary restrictions, then the confirmation.
        yield 'answered' => ["4\n2026-12-24\nvegan\nyes\n"];
        // An empty line takes the offered default; the date has none that is stable.
        yield 'defaults' => ["\n2026-12-24\n\n\n"];
    }

    protected function normalizeOutput(string $output): string
    {
        // The default offered for the date is today.
        return str_replace(date('Y-m-d'), '<today>', $output);
    }

    protected function getExampleScript(): string
    {
        return 'stdio_elicitation';
    }
}
