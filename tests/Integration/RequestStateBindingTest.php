<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Integration;

use Mcp\Client;
use Mcp\Client\Handler\Request\RequestHandlerInterface;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\ElicitResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * What a client can do with a state it holds: every replay below succeeds without a binder, and none does with one.
 *
 * @phpstan-import-type Call from RequestStateTestCase
 */
final class RequestStateBindingTest extends RequestStateTestCase
{
    /**
     * @return iterable<string, array{string, Call, string, Call, string}>
     */
    public static function provideReplays(): iterable
    {
        $transfer = self::tool('transfer', ['to' => 'bob', 'amount' => 10]);

        yield 'alice\'s approval under mallory\'s token' => [
            'alice', $transfer,
            'mallory', $transfer,
            'mallory moved 10 to bob, approved by alice',
        ];
        yield 'alice\'s approval under a token of another client' => [
            'alice', $transfer,
            'alice-other-client', $transfer,
            'alice moved 10 to bob, approved by alice',
        ];
        yield 'an approval of 10 for bob on a transfer of 10000 to mallory' => [
            'alice', $transfer,
            'alice', self::tool('transfer', ['to' => 'mallory', 'amount' => 10000]),
            'alice moved 10000 to mallory, approved by alice',
        ];
        yield 'an approval of a transfer on closing the account' => [
            'alice', $transfer,
            'alice', self::tool('close_account', ['account' => 'savings']),
            'alice closed savings, approved by alice',
        ];
        yield 'an approval to read one document on reading another' => [
            'alice', self::resource('doc://public'),
            'alice', self::resource('doc://secret'),
            'contents of secret, approved by alice',
        ];
        yield 'an approval of one prompt on getting another' => [
            'alice', self::prompt('brief', ['topic' => 'roadmap']),
            'alice', self::prompt('leak', ['topic' => 'roadmap']),
            'leak of roadmap, approved by alice',
        ];
        yield 'an approval of a prompt on the same prompt with other arguments' => [
            'alice', self::prompt('brief', ['topic' => 'roadmap']),
            'alice', self::prompt('brief', ['topic' => 'salaries']),
            'brief on salaries, approved by alice',
        ];
        yield 'alice\'s approval of a prompt under mallory\'s token' => [
            'alice', self::prompt('brief', ['topic' => 'roadmap']),
            'mallory', self::prompt('brief', ['topic' => 'roadmap']),
            'brief on roadmap, approved by alice',
        ];
        yield 'an approval of a map with numeric keys on the list it would sort into' => [
            'alice', self::tool('inspect', ['options' => ['1' => 'B', '0' => 'A']]),
            'alice', self::tool('inspect', ['options' => ['A', 'B']]),
            '["A","B"], approved by alice',
        ];
        yield 'an approval of a list on the same list in another order' => [
            'alice', self::tool('inspect', ['options' => ['A', 'B']]),
            'alice', self::tool('inspect', ['options' => ['B', 'A']]),
            '["B","A"], approved by alice',
        ];
        yield 'alice\'s approval under a token of another issuer' => [
            'alice', $transfer,
            'alice-other-issuer', $transfer,
            'alice moved 10 to bob, approved by alice',
        ];
        yield 'a manager\'s authorization check under mallory\'s token' => [
            'alice', self::tool('refund', ['order' => 1]),
            'mallory', self::tool('refund', ['order' => 1]),
            'mallory refunded order 1',
        ];
        yield 'a manager\'s authorization check on another order' => [
            'alice', self::tool('refund', ['order' => 1]),
            'alice', self::tool('refund', ['order' => 2]),
            'alice refunded order 2',
        ];
        yield 'tokens telling their users apart by a claim other than the subject' => [
            'no-subject-alice', $transfer,
            'no-subject-mallory', $transfer,
            'nobody moved 10 to bob, approved by no-subject-alice',
        ];
    }

    /**
     * @param Call $minted
     * @param Call $replayed
     */
    #[DataProvider('provideReplays')]
    #[TestDox('without a binder, a client gets away with $_dataName')]
    public function testReplaySucceedsWithoutABinder(string $holder, array $minted, string $replayer, array $replayed, string $outcome): void
    {
        $url = $this->startServer(self::NEW_KEY);

        $this->assertCompleted($this->finish($url, $replayer, $replayed, $this->pendingState($url, $holder, $minted)), $outcome);
    }

    /**
     * @param Call $minted
     * @param Call $replayed
     */
    #[DataProvider('provideReplays')]
    #[TestDox('with a binder, a client is refused $_dataName')]
    public function testReplayIsRefusedWithABinder(string $holder, array $minted, string $replayer, array $replayed, string $outcome): void
    {
        $url = $this->startServer(self::NEW_KEY, binder: true);

        $this->assertRefused($this->finish($url, $replayer, $replayed, $this->pendingState($url, $holder, $minted)));
    }

    #[TestDox('a manager\'s check still stops mallory from starting a refund, whatever the binder')]
    public function testHandlerStillDecidesTheFirstRound(): void
    {
        $url = $this->startServer(self::NEW_KEY, binder: true);

        $this->assertCompleted($this->send($url, 'mallory', self::tool('refund', ['order' => 1])), 'Only a manager may refund.');
    }

    #[TestDox('with a binder, the retry of a call completes under a refreshed token')]
    public function testRetryCompletesUnderARefreshedToken(): void
    {
        $url = $this->startServer(self::NEW_KEY, binder: true);
        $call = self::tool('transfer', ['to' => 'bob', 'amount' => 10]);

        $this->assertCompleted(
            $this->finish($url, 'alice-refreshed', $call, $this->pendingState($url, 'alice', $call)),
            'alice moved 10 to bob, approved by alice',
        );
    }

    #[TestDox('with a binder, the retry of a call completes with its arguments re-encoded, another id and other metadata')]
    public function testRetryCompletesWhenOnlyItsEnvelopeChanged(): void
    {
        $url = $this->startServer(self::NEW_KEY, binder: true);
        $pending = $this->pendingState($url, 'alice', self::tool('transfer', ['to' => 'bob', 'amount' => 10]));

        $answer = $this->send($url, 'alice', self::tool('transfer', ['amount' => 10, 'to' => 'bob']), [
            'inputResponses' => [$pending['asks'][0] => ['action' => 'accept', 'content' => ['v' => 'alice']]],
            'requestState' => $pending['state'],
        ], ['progressToken' => 'another-round', 'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'], id: 99);

        $this->assertCompleted($answer, 'alice moved 10 to bob, approved by alice');
    }

    /**
     * @return iterable<string, array{Call, Call, string}>
     */
    public static function provideEncodings(): iterable
    {
        yield 'a map with numeric keys in another member order' => [
            self::tool('inspect', ['options' => ['2' => 'C', '0' => 'A', '1' => 'B']]),
            self::tool('inspect', ['options' => ['1' => 'B', '2' => 'C', '0' => 'A']]),
            '{"1":"B","2":"C","0":"A"}, approved by alice',
        ];
        yield 'a nested map in another member order' => [
            self::tool('inspect', ['options' => ['trip' => ['to' => 'BER', 'from' => 'HAM']]]),
            self::tool('inspect', ['options' => ['trip' => ['from' => 'HAM', 'to' => 'BER']]]),
            '{"trip":{"from":"HAM","to":"BER"}}, approved by alice',
        ];
    }

    /**
     * @param Call $minted
     * @param Call $retried
     */
    #[DataProvider('provideEncodings')]
    #[TestDox('with a binder, a retry completes with $_dataName')]
    public function testRetryCompletesInAnotherEncoding(array $minted, array $retried, string $outcome): void
    {
        $url = $this->startServer(self::NEW_KEY, binder: true);

        $this->assertCompleted($this->finish($url, 'alice', $retried, $this->pendingState($url, 'alice', $minted)), $outcome);
    }

    #[TestDox('with a binder, arguments nested as deep as a request may be complete, and bind by their innermost value')]
    public function testDeeplyNestedArgumentsComplete(): void
    {
        $nested = static function (string $leaf): array {
            $value = $leaf;

            for ($depth = 0; $depth < 480; ++$depth) {
                $value = ['k' => $value];
            }

            return $value;
        };

        $url = $this->startServer(self::NEW_KEY, binder: true);
        $call = self::tool('inspect', ['options' => $nested('preview')]);
        $pending = $this->pendingState($url, 'alice', $call);

        $this->assertRefused($this->finish($url, 'alice', self::tool('inspect', ['options' => $nested('execute')]), $pending));

        $this->assertCompleted($this->finish($url, 'alice', $call, $pending), json_encode($nested('preview'), depth: 1024).', approved by alice');
    }

    #[TestDox('with a binder, the rounds of a call answering as a stream complete, for the call they were minted for only')]
    public function testStreamedRoundsStayBound(): void
    {
        $url = $this->startServer(self::NEW_KEY, binder: true);
        $call = self::tool('report', ['amount' => 10]);
        $pending = $this->pendingState($url, 'alice', $call, ['progressToken' => 'first']);

        $this->assertRefused($this->finish($url, 'mallory', $call, $pending, ['progressToken' => 'second']));
        $this->assertRefused($this->finish($url, 'alice', self::tool('report', ['amount' => 10000]), $pending, ['progressToken' => 'second']));

        $answer = $this->finish($url, 'alice', $call, $pending, ['progressToken' => 'second']);

        $this->assertStringContainsString('notifications/progress', $answer['raw']);
        $this->assertCompleted($answer, 'reported 10, approved by alice');
    }

    #[TestDox('with a binder and a subject resolver, a call completes under a refreshed token')]
    public function testSubjectResolverCompletesUnderARefreshedToken(): void
    {
        $url = $this->startServer(self::NEW_KEY, binder: true, subjectClaim: 'email');
        $call = self::tool('transfer', ['to' => 'bob', 'amount' => 10]);

        $this->assertCompleted(
            $this->finish($url, 'no-subject-alice-refreshed', $call, $this->pendingState($url, 'no-subject-alice', $call)),
            'nobody moved 10 to bob, approved by no-subject-alice',
        );
    }

    #[TestDox('with a binder and a subject resolver, a state stays with its user and its client')]
    public function testSubjectResolverKeepsUserAndClient(): void
    {
        $url = $this->startServer(self::NEW_KEY, binder: true, subjectClaim: 'email');
        $call = self::tool('transfer', ['to' => 'bob', 'amount' => 10]);
        $pending = $this->pendingState($url, 'no-subject-alice', $call);

        $this->assertRefused($this->finish($url, 'no-subject-mallory', $call, $pending));
        $this->assertRefused($this->finish($url, 'no-subject-alice-other-client', $call, $pending));
    }

    #[TestDox('with a binder and a subject resolver, a token the resolver knows nobody for still fails closed')]
    public function testSubjectResolverFailsClosed(): void
    {
        $url = $this->startServer(self::NEW_KEY, binder: true, subjectClaim: 'email');
        $call = self::tool('transfer', ['to' => 'bob', 'amount' => 10]);

        $this->assertRefused($this->finish($url, 'alice', $call, $this->pendingState($url, 'alice', $call)));
    }

    #[TestDox('with a binder, a token without a subject cannot finish a call that needs a state, but everything else still works')]
    public function testTokenWithoutASubjectFailsClosed(): void
    {
        $url = $this->startServer(self::NEW_KEY, binder: true);
        $call = self::tool('transfer', ['to' => 'bob', 'amount' => 10]);

        $this->assertRefused($this->finish($url, 'no-subject-alice', $call, $this->pendingState($url, 'no-subject-alice', $call)));
        $this->assertCompleted($this->send($url, 'no-subject-alice', self::tool('ping')), 'pong');
    }

    #[TestDox('with a binder, an endpoint without authorization still binds the call')]
    public function testAnonymousEndpointBindsTheCall(): void
    {
        $url = $this->startServer(self::NEW_KEY, binder: true, anonymous: true);
        $call = self::tool('transfer', ['to' => 'bob', 'amount' => 10]);
        $pending = $this->pendingState($url, null, $call);

        $this->assertCompleted($this->finish($url, null, $call, $pending), 'nobody moved 10 to bob, approved by ');
        $this->assertRefused($this->finish($url, null, self::tool('transfer', ['to' => 'mallory', 'amount' => 10]), $pending));
    }

    #[TestDox('with a binder, the principal stays off the wire')]
    public function testPrincipalStaysOffTheWire(): void
    {
        $url = $this->startServer(self::NEW_KEY, binder: true);
        $state = $this->pendingState($url, 'alice', self::tool('refund', ['order' => 1]))['state'];

        $this->assertCount(2, explode('.', $state));
        $this->assertSame(
            ['authorized' => true],
            json_decode((string) base64_decode(strtr(explode('.', $state)[0], '-_', '+/'), true), true)['data'] ?? null,
        );
    }

    #[TestDox('with a binder, our own client completes the calls that take several rounds')]
    public function testOwnClientCompletesThroughTheBinder(): void
    {
        $client = $this->client([$this->startServer(self::NEW_KEY, binder: true)], 'alice');

        $this->assertSame('alice moved 10 to bob, approved by client', self::text($client->callTool('transfer', ['to' => 'bob', 'amount' => 10])));
        $this->assertSame('alice refunded order 1', self::text($client->callTool('refund', ['order' => 1])));

        $client->disconnect();
    }

    /**
     * @return iterable<string, array{ProtocolVersion}>
     */
    public static function provideEras(): iterable
    {
        yield 'the modern era' => [ProtocolVersion::V2026_07_28];
        yield 'the handshake era' => [ProtocolVersion::V2025_11_25];
    }

    #[DataProvider('provideEras')]
    #[TestDox('with a binder, a client completes the calls that take several rounds over stdio on $_dataName')]
    public function testStdioCompletesThroughTheBinder(ProtocolVersion $era): void
    {
        $client = Client::builder()
            ->setClientInfo('request-state-client', '1.0.0')
            ->setProtocolVersion($era)
            ->setCapabilities(new ClientCapabilities(elicitation: true))
            ->setRequestTimeout(10)
            ->addRequestHandler(new class implements RequestHandlerInterface {
                public function supports(Request $request): bool
                {
                    return $request instanceof ElicitRequest;
                }

                public function handle(Request $request): Response
                {
                    return new Response($request->getId(), new ElicitResult(ElicitAction::Accept, ['v' => 'client']));
                }
            })
            ->build();

        $client->connect(new StdioTransport('php', [__DIR__.'/Fixture/request_state.php'], env: array_merge(getenv(), self::environment(self::NEW_KEY, binder: true))));

        $this->assertSame('nobody moved 10 to bob, approved by client', self::text($client->callTool('transfer', ['to' => 'bob', 'amount' => 10])));
        $this->assertSame('nobody refunded order 1', self::text($client->callTool('refund', ['order' => 1])));

        $client->disconnect();
    }
}
