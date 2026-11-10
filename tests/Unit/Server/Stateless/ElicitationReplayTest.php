<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Stateless;

use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Enum\ElicitationMode;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Server\Exception\LogicException;
use Mcp\Server\Stateless\ElicitationReplay;
use Mcp\Server\Stateless\InputContext;
use Mcp\Server\Stateless\RequestStateCodec;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class ElicitationReplayTest extends TestCase
{
    private const ACCEPTED = ['action' => 'accept', 'content' => ['n' => 'ada']];

    private static function codec(): RequestStateCodec
    {
        return new RequestStateCodec(str_repeat('k', 32));
    }

    private static function request(): ElicitRequest
    {
        return ElicitRequest::forForm('Who?', new ElicitationSchema(['n' => new StringSchemaDefinition('N')], ['n']));
    }

    #[TestDox('an unnamed ask is filed under its position, a named one under its name')]
    public function testKeysFallBackToPosition(): void
    {
        $replay = new ElicitationReplay(null);

        $this->assertSame('elicitation_1', $replay->key(null));
        $this->assertSame('seat', $replay->key('seat'));
        // Counted even when named, so a position always means the same ask.
        $this->assertSame('elicitation_3', $replay->key(null));
    }

    #[TestDox('an answer from this round is handed to the handler')]
    public function testAnswerFromThisRound(): void
    {
        $replay = new ElicitationReplay(new InputContext(['who' => self::ACCEPTED]));

        $this->assertSame(self::ACCEPTED, $replay->answer('who'));
        $this->assertNull($replay->answer('other'));
    }

    #[TestDox('an answer from an earlier round is read back out of the state')]
    public function testAnswerCarriedInState(): void
    {
        $replay = new ElicitationReplay(new InputContext([], [
            ElicitationReplay::CARRIED_ANSWERS => ['who' => self::ACCEPTED],
        ]));

        $this->assertSame(self::ACCEPTED, $replay->answer('who'));
    }

    #[TestDox('what this round answered wins over what an earlier one did')]
    public function testThisRoundOverridesTheCarriedAnswer(): void
    {
        $fresh = ['action' => 'accept', 'content' => ['n' => 'grace']];

        $replay = new ElicitationReplay(new InputContext(['who' => $fresh], [
            ElicitationReplay::CARRIED_ANSWERS => ['who' => self::ACCEPTED],
        ]));

        $this->assertSame($fresh, $replay->answer('who'));
    }

    #[TestDox('an answer that does not parse counts as no answer, and is not carried on')]
    public function testMalformedAnswerIsDropped(): void
    {
        // Accepted, but a form-mode acceptance has to carry content.
        $replay = new ElicitationReplay(new InputContext(['who' => ['action' => 'accept']]), self::codec());

        $this->assertNull($replay->answer('who'));

        $ask = $replay->ask('who', self::request());

        $this->assertNull($ask->requestState);
    }

    #[TestDox('an answer rejected this round is not carried into the next one')]
    public function testRejectedAnswerIsNotCarriedOn(): void
    {
        $codec = self::codec();
        $replay = new ElicitationReplay(new InputContext([], [
            ElicitationReplay::CARRIED_ANSWERS => ['who' => ['action' => 'accept']],
        ]), $codec);

        $this->assertNull($replay->answer('who'));
        $this->assertNull($replay->ask('who', self::request())->requestState);
    }

    #[TestDox('a url-mode answer is read against its own mode')]
    public function testUrlModeAnswer(): void
    {
        $replay = new ElicitationReplay(new InputContext(['consent' => ['action' => 'accept']]));

        // Content is required in form mode and forbidden in url mode, so the
        // same answer only parses under the mode it was asked in.
        $this->assertNull($replay->answer('consent'));
        $this->assertSame(['action' => 'accept'], $replay->answer('consent', ElicitationMode::Url));
    }

    #[TestDox('a first ask carries no state, since nothing has been answered yet')]
    public function testFirstAskCarriesNoState(): void
    {
        $ask = (new ElicitationReplay(null, self::codec()))->ask('who', self::request());

        $this->assertSame(['who'], array_keys($ask->inputRequests));
        $this->assertNull($ask->requestState);
    }

    #[TestDox('a later ask seals what is already answered, beside the handler\'s own payload')]
    public function testLaterAskSealsTheAnswers(): void
    {
        $codec = self::codec();
        $replay = new ElicitationReplay(new InputContext(['who' => self::ACCEPTED], ['booking' => 42]), $codec);

        $ask = $replay->ask('seat', self::request());

        $this->assertIsString($ask->requestState);
        $this->assertSame([
            'booking' => 42,
            ElicitationReplay::CARRIED_ANSWERS => ['who' => self::ACCEPTED],
        ], $codec->verify($ask->requestState));
    }

    #[TestDox('there is nowhere to keep an answer without a signing key')]
    public function testSealingNeedsASigningKey(): void
    {
        $replay = new ElicitationReplay(new InputContext(['who' => self::ACCEPTED]));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/setRequestState/');

        $replay->ask('seat', self::request());
    }
}
