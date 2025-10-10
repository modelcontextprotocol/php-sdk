<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema\Enum;

use Mcp\Schema\Enum\LoggingLevel;
use PHPUnit\Framework\TestCase;

/**
 * @author Adam Jamiu <jamiuadam120@gmail.com>
 */
class LoggingLevelTest extends TestCase
{
    public function testEnumValuesAndSeverityIndexes(): void
    {
        $expectedLevelsWithIndexes = [
            ['level' => LoggingLevel::Debug, 'value' => 'debug', 'index' => 0],
            ['level' => LoggingLevel::Info, 'value' => 'info', 'index' => 1],
            ['level' => LoggingLevel::Notice, 'value' => 'notice', 'index' => 2],
            ['level' => LoggingLevel::Warning, 'value' => 'warning', 'index' => 3],
            ['level' => LoggingLevel::Error, 'value' => 'error', 'index' => 4],
            ['level' => LoggingLevel::Critical, 'value' => 'critical', 'index' => 5],
            ['level' => LoggingLevel::Alert, 'value' => 'alert', 'index' => 6],
            ['level' => LoggingLevel::Emergency, 'value' => 'emergency', 'index' => 7],
        ];

        foreach ($expectedLevelsWithIndexes as $data) {
            $level = $data['level'];

            // Test enum value
            $this->assertEquals($data['value'], $level->value);

            // Test severity index
            $this->assertEquals($data['index'], $level->getSeverityIndex());

            // Test severity index consistency (multiple calls return same result)
            $this->assertEquals($data['index'], $level->getSeverityIndex());

            // Test from string conversion
            $fromString = LoggingLevel::from($data['value']);
            $this->assertEquals($level, $fromString);
            $this->assertEquals($data['value'], $fromString->value);
        }

        // Test severity comparisons - each level should have higher index than previous
        for ($i = 1; $i < \count($expectedLevelsWithIndexes); ++$i) {
            $previous = $expectedLevelsWithIndexes[$i - 1]['level'];
            $current = $expectedLevelsWithIndexes[$i]['level'];
            $this->assertTrue(
                $previous->getSeverityIndex() < $current->getSeverityIndex(),
                "Expected {$previous->value} index to be less than {$current->value} index"
            );
        }
    }

    public function testInvalidLogLevelHandling(): void
    {
        // Test invalid level string
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('"invalid_level" is not a valid backing value for enum');
        LoggingLevel::from('invalid_level');
    }

    public function testCaseSensitiveLogLevels(): void
    {
        // Should be case sensitive - 'DEBUG' is not 'debug'
        $this->expectException(\ValueError::class);
        LoggingLevel::from('DEBUG');
    }

    public function testEnumUniquenessAndCoverage(): void
    {
        $indexes = [];
        $allCases = LoggingLevel::cases();

        foreach ($allCases as $level) {
            $index = $level->getSeverityIndex();

            // Check that this index hasn't been used before
            $this->assertNotContains($index, $indexes, "Severity index {$index} is duplicated for level {$level->value}");

            $indexes[] = $index;
        }

        // Verify we have exactly 8 unique indexes
        $this->assertCount(8, $indexes);
        $this->assertCount(8, $allCases);

        // Verify indexes are sequential from 0 to 7
        sort($indexes);
        $this->assertEquals([0, 1, 2, 3, 4, 5, 6, 7], $indexes);
    }
}
