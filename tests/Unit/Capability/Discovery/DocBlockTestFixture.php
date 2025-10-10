<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Discovery;

use Mcp\Tests\Capability\Discovery\Missing;

/**
 * A stub class for testing DocBlock parsing.
 */
class DocBlockTestFixture
{
    /**
     * Simple summary line.
     */
    public function methodWithSummaryOnly(): void
    {
    }

    /**
     * Summary line here.
     *
     * This is a longer description spanning
     * multiple lines.
     * It might contain *markdown* or `code`.
     *
     * @since 1.0
     */
    public function methodWithSummaryAndDescription(): void
    {
    }

    /**
     * Method with various parameter tags.
     *
     * @param string               $param1 description for string param
     * @param int|null             $param2 description for nullable int param
     * @param bool                 $param3 nothing to say
     * @param                      $param4 Missing type
     * @param array<string, mixed> $param5 array description
     * @param \stdClass            $param6 object param
     */
    /* @phpstan-ignore-next-line missingType.parameter */
    public function methodWithParams(string $param1, ?int $param2, bool $param3, $param4, array $param5, \stdClass $param6): void
    {
    }

    /**
     * Method with return tag.
     *
     * @return string the result of the operation
     */
    public function methodWithReturn(): string
    {
        return '';
    }

    /**
     * Method with multiple tags.
     *
     * @param float $value the value to process
     *
     * @return bool status of the operation
     *
     * @throws \RuntimeException if processing fails
     *
     * @deprecated use newMethod() instead
     * @see DocBlockTestFixture::newMethod()
     */
    public function methodWithMultipleTags(float $value): bool
    {
        if ($value < 0) {
            throw new \RuntimeException('Processing failed for negative values');
        }

        return true;
    }

    /**
     * Malformed docblock - missing closing.
     */
    public function methodWithMalformedDocBlock(): void
    {
    }

    public function methodWithNoDocBlock(): void
    {
    }

    // Some other method needed for a @see tag perhaps
    public function newMethod(): void
    {
    }
}
