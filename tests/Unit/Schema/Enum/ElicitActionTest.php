<?php

declare(strict_types=1);

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema\Enum;

use Mcp\Schema\Enum\ElicitAction;
use PHPUnit\Framework\TestCase;

final class ElicitActionTest extends TestCase
{
    public function testEnumValues(): void
    {
        $this->assertSame('accept', ElicitAction::Accept->value);
        $this->assertSame('decline', ElicitAction::Decline->value);
        $this->assertSame('cancel', ElicitAction::Cancel->value);
    }

    public function testFromValidValues(): void
    {
        $this->assertSame(ElicitAction::Accept, ElicitAction::from('accept'));
        $this->assertSame(ElicitAction::Decline, ElicitAction::from('decline'));
        $this->assertSame(ElicitAction::Cancel, ElicitAction::from('cancel'));
    }

    public function testFromInvalidValue(): void
    {
        $this->expectException(\ValueError::class);
        ElicitAction::from('invalid');
    }

    public function testTryFromValidValues(): void
    {
        $this->assertSame(ElicitAction::Accept, ElicitAction::tryFrom('accept'));
        $this->assertSame(ElicitAction::Decline, ElicitAction::tryFrom('decline'));
        $this->assertSame(ElicitAction::Cancel, ElicitAction::tryFrom('cancel'));
    }

    public function testTryFromInvalidValue(): void
    {
        $this->assertNull(ElicitAction::tryFrom('invalid'));
    }
}
