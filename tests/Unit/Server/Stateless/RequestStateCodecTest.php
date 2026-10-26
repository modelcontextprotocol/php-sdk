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

use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\RequestStateException;
use Mcp\Server\Stateless\RequestStateCodec;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class RequestStateCodecTest extends TestCase
{
    private const KEY = 'a-key-that-is-long-enough-to-be-secure';
    private const OTHER_KEY = 'a-different-key-that-is-also-long-enough';

    #[TestDox('a sealed payload survives the round trip intact')]
    public function testRoundTrip(): void
    {
        $codec = new RequestStateCodec(self::KEY);
        $payload = ['round' => 2, 'tool' => 'do_thing', 'nested' => ['a' => 1]];

        $this->assertSame($payload, $codec->verify($codec->mint($payload)));
    }

    #[TestDox('a state signed by another key is refused')]
    public function testForeignKeyRefused(): void
    {
        $state = (new RequestStateCodec(self::OTHER_KEY))->mint(['round' => 1]);

        $this->expectException(RequestStateException::class);
        (new RequestStateCodec(self::KEY))->verify($state);
    }

    #[TestDox('any edit to the wire value invalidates it')]
    public function testTamperingIsDetected(): void
    {
        $codec = new RequestStateCodec(self::KEY);
        $state = $codec->mint(['admin' => false]);

        foreach ([$state.'-TAMPERED', substr($state, 0, -2), str_replace('.', 'X.', $state)] as $tampered) {
            try {
                $codec->verify($tampered);
                $this->fail(\sprintf('Expected "%s" to be rejected.', $tampered));
            } catch (RequestStateException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[TestDox('a re-signed payload from a different key cannot be swapped in')]
    public function testPayloadSwapRefused(): void
    {
        $mine = new RequestStateCodec(self::KEY);
        $theirs = new RequestStateCodec(self::OTHER_KEY);

        // Body from one, MAC from the other: each half is well-formed alone.
        [$body] = explode('.', $mine->mint(['admin' => false]));
        [, $mac] = explode('.', $theirs->mint(['admin' => true]));

        $this->expectException(RequestStateException::class);
        $mine->verify($body.'.'.$mac);
    }

    #[TestDox('an expired state is refused even though its signature is good')]
    public function testExpiryEnforced(): void
    {
        $codec = new RequestStateCodec(self::KEY, ttlSeconds: 60);
        $state = $codec->mint(['round' => 1], now: 1_000);

        $this->assertSame(['round' => 1], $codec->verify($state, now: 1_059));

        $this->expectException(RequestStateException::class);
        $codec->verify($state, now: 1_061);
    }

    #[TestDox('a malformed value is refused rather than parsed')]
    public function testMalformedRefused(): void
    {
        $codec = new RequestStateCodec(self::KEY);

        foreach (['', 'not-a-state', 'one.two.three', '.', 'YQ.'] as $bad) {
            try {
                $codec->verify($bad);
                $this->fail(\sprintf('Expected "%s" to be rejected.', $bad));
            } catch (RequestStateException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[TestDox('the failure reason never says more than a category')]
    public function testFailureReasonsAreOpaque(): void
    {
        $codec = new RequestStateCodec(self::KEY);

        try {
            $codec->verify((new RequestStateCodec(self::OTHER_KEY))->mint(['secret' => 'value']));
            $this->fail('Expected rejection.');
        } catch (RequestStateException $e) {
            // Anything beyond a category is a hint to whoever is probing.
            $this->assertContains($e->getMessage(), ['malformed', 'mac', 'expired']);
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    #[TestDox('a bound state verifies under the same binding')]
    public function testBoundRoundTrip(): void
    {
        $codec = (new RequestStateCodec(self::KEY))->withBinding('tools/call|book');

        $this->assertSame(['round' => 1], $codec->withBinding('tools/call|book')->verify($codec->mint(['round' => 1])));
    }

    #[TestDox('a bound state is refused under another binding, like a forged one')]
    public function testBoundStateRefusedUnderAnotherBinding(): void
    {
        $codec = new RequestStateCodec(self::KEY);
        $state = $codec->withBinding('tools/call|book')->mint(['round' => 1]);

        $this->expectExceptionObject(new RequestStateException('mac'));
        $codec->withBinding('tools/call|cancel')->verify($state);
    }

    #[TestDox('an unbound state is refused by a bound codec, and a bound one by an unbound codec')]
    public function testBindingIsNeitherAddedNorStripped(): void
    {
        $unbound = new RequestStateCodec(self::KEY);
        $bound = $unbound->withBinding('');

        foreach ([[$unbound, $bound], [$bound, $unbound]] as [$minter, $verifier]) {
            try {
                $verifier->verify($minter->mint(['round' => 1]));
                $this->fail('Expected rejection.');
            } catch (RequestStateException $e) {
                $this->assertSame('mac', $e->getMessage());
            }
        }
    }

    #[TestDox('the binding stays off the wire, which keeps its shape')]
    public function testBindingStaysOffTheWire(): void
    {
        $state = (new RequestStateCodec(self::KEY))->withBinding('alice@example.com')->mint(['round' => 1], now: 1_000);
        [$body] = explode('.', $state);

        $this->assertCount(2, explode('.', $state));
        $this->assertSame(
            ['exp' => 1_600, 'data' => ['round' => 1]],
            json_decode(base64_decode(strtr($body, '-_', '+/')), true),
        );
    }

    #[TestDox('a key too short to be safe is refused at construction')]
    public function testShortKeyRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RequestStateCodec('too-short');
    }

    #[TestDox('a non-positive TTL is refused at construction')]
    public function testNonPositiveTtlRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RequestStateCodec(self::KEY, ttlSeconds: 0);
    }
}
