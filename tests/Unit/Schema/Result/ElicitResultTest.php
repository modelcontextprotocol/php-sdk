<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema\Result;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Enum\ElicitationMode;
use Mcp\Schema\Result\ElicitResult;
use PHPUnit\Framework\TestCase;

final class ElicitResultTest extends TestCase
{
    public function testConstructorWithAcceptAndContent(): void
    {
        $content = ['name' => 'John', 'age' => 30];
        $result = new ElicitResult(ElicitAction::Accept, $content);

        $this->assertSame(ElicitAction::Accept, $result->action);
        $this->assertSame($content, $result->content);
    }

    public function testConstructorWithDecline(): void
    {
        $result = new ElicitResult(ElicitAction::Decline);

        $this->assertSame(ElicitAction::Decline, $result->action);
        $this->assertNull($result->content);
    }

    public function testConstructorWithCancel(): void
    {
        $result = new ElicitResult(ElicitAction::Cancel);

        $this->assertSame(ElicitAction::Cancel, $result->action);
        $this->assertNull($result->content);
    }

    public function testFromArrayWithAccept(): void
    {
        $result = ElicitResult::fromArray([
            'action' => 'accept',
            'content' => ['name' => 'John', 'email' => 'john@example.com'],
        ]);

        $this->assertSame(ElicitAction::Accept, $result->action);
        $this->assertSame(['name' => 'John', 'email' => 'john@example.com'], $result->content);
    }

    public function testFromArrayWithDecline(): void
    {
        $result = ElicitResult::fromArray([
            'action' => 'decline',
        ]);

        $this->assertSame(ElicitAction::Decline, $result->action);
        $this->assertNull($result->content);
    }

    public function testFromArrayWithCancel(): void
    {
        $result = ElicitResult::fromArray([
            'action' => 'cancel',
        ]);

        $this->assertSame(ElicitAction::Cancel, $result->action);
        $this->assertNull($result->content);
    }

    public function testFromArrayWithMissingAction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing or invalid "action"');

        ElicitResult::fromArray([]);
    }

    public function testFromArrayWithInvalidAction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid "action" value "invalid"');

        ElicitResult::fromArray(['action' => 'invalid']);
    }

    public function testFromArrayWithAcceptActionRequiresContent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Content must be provided when action is "accept"');

        ElicitResult::fromArray(['action' => 'accept']);
    }

    public function testFromArrayWithUrlModeAllowsContentlessAccept(): void
    {
        $result = ElicitResult::fromArray(['action' => 'accept'], ElicitationMode::Url);

        $this->assertSame(ElicitAction::Accept, $result->action);
        $this->assertNull($result->content);
        $this->assertTrue($result->isAccepted());
    }

    public function testFromArrayWithExplicitFormModeRequiresContent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Content must be provided when action is "accept"');

        ElicitResult::fromArray(['action' => 'accept'], ElicitationMode::Form);
    }

    public function testIsAccepted(): void
    {
        $acceptResult = new ElicitResult(ElicitAction::Accept, ['name' => 'John']);
        $declineResult = new ElicitResult(ElicitAction::Decline);
        $cancelResult = new ElicitResult(ElicitAction::Cancel);

        $this->assertTrue($acceptResult->isAccepted());
        $this->assertFalse($declineResult->isAccepted());
        $this->assertFalse($cancelResult->isAccepted());
    }

    public function testIsDeclined(): void
    {
        $acceptResult = new ElicitResult(ElicitAction::Accept, ['name' => 'John']);
        $declineResult = new ElicitResult(ElicitAction::Decline);
        $cancelResult = new ElicitResult(ElicitAction::Cancel);

        $this->assertFalse($acceptResult->isDeclined());
        $this->assertTrue($declineResult->isDeclined());
        $this->assertFalse($cancelResult->isDeclined());
    }

    public function testIsCancelled(): void
    {
        $acceptResult = new ElicitResult(ElicitAction::Accept, ['name' => 'John']);
        $declineResult = new ElicitResult(ElicitAction::Decline);
        $cancelResult = new ElicitResult(ElicitAction::Cancel);

        $this->assertFalse($acceptResult->isCancelled());
        $this->assertFalse($declineResult->isCancelled());
        $this->assertTrue($cancelResult->isCancelled());
    }

    public function testJsonSerializeWithAcceptAndContent(): void
    {
        $result = new ElicitResult(ElicitAction::Accept, ['name' => 'John', 'age' => 30]);

        $this->assertSame([
            'action' => 'accept',
            'content' => ['name' => 'John', 'age' => 30],
        ], $result->jsonSerialize());
    }

    public function testJsonSerializeWithDecline(): void
    {
        $result = new ElicitResult(ElicitAction::Decline);

        $this->assertSame([
            'action' => 'decline',
        ], $result->jsonSerialize());
    }

    public function testJsonSerializeWithCancel(): void
    {
        $result = new ElicitResult(ElicitAction::Cancel);

        $this->assertSame([
            'action' => 'cancel',
        ], $result->jsonSerialize());
    }

    public function testUrlModeRejectsContent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Content must not be provided for a url-mode elicitation result.');

        ElicitResult::fromArray(
            ['action' => 'accept', 'content' => ['name' => 'Ada']],
            ElicitationMode::Url,
        );
    }
}
