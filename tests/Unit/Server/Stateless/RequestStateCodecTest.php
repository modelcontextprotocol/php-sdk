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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class RequestStateCodecTest extends TestCase
{
    private const KEY = 'a-key-that-is-long-enough-to-be-secure';
    private const OTHER_KEY = 'a-different-key-that-is-also-long-enough';
    private const OLD_KEY = 'the-key-being-rotated-out-of-the-ring';
    private const NEW_KEY = 'the-key-being-rotated-into-the-ring-now';

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

    #[TestDox('rotating through a key ring never fails a state still within its TTL')]
    public function testKeyRotation(): void
    {
        $old = new RequestStateCodec(self::OLD_KEY);
        $mintedByOld = $old->mint(['round' => 1]);

        // Step 1, for more than one instance: every instance learns the new
        // key before any signs with it.
        $learning = new RequestStateCodec([self::OLD_KEY, self::NEW_KEY]);
        $this->assertSame(['round' => 1], $learning->verify($mintedByOld));
        $mintedWhileLearning = $learning->mint(['round' => 2]);
        $this->assertSame(['round' => 2], $old->verify($mintedWhileLearning), 'An instance not yet updated verifies it.');

        // Step 2: the new key signs, the old one still verifies.
        $rotated = new RequestStateCodec([self::NEW_KEY, self::OLD_KEY]);
        $mintedByNew = $rotated->mint(['round' => 3]);
        $this->assertSame(['round' => 1], $rotated->verify($mintedByOld));
        $this->assertSame(['round' => 3], $rotated->verify($mintedByNew));
        $this->assertSame(['round' => 3], $learning->verify($mintedByNew), 'An instance still on step 1 verifies it.');

        // Step 3, one TTL later: the old key is gone, and so is what it signed.
        $retired = new RequestStateCodec(self::NEW_KEY);
        $this->assertSame(['round' => 3], $retired->verify($mintedByNew));

        $this->expectExceptionObject(new RequestStateException('mac'));
        $retired->verify($mintedByOld);
    }

    #[TestDox('a state signed by none of the ring\'s keys is refused')]
    public function testForeignKeyRefusedByARing(): void
    {
        $state = (new RequestStateCodec(self::OTHER_KEY))->mint(['round' => 1]);

        $this->expectExceptionObject(new RequestStateException('mac'));
        (new RequestStateCodec([self::NEW_KEY, self::OLD_KEY, self::KEY]))->verify($state);
    }

    #[TestDox('a single key and a ring of that key mint the same wire value')]
    public function testSingleKeyIsARingOfOne(): void
    {
        $single = new RequestStateCodec(self::KEY);
        $ring = new RequestStateCodec([self::KEY]);

        $this->assertSame($single->mint(['round' => 1], now: 1_000), $ring->mint(['round' => 1], now: 1_000));
        $this->assertSame(['round' => 1], $ring->verify($single->mint(['round' => 1])));
        $this->assertSame(['round' => 1], $single->verify($ring->mint(['round' => 1])));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidRings(): iterable
    {
        yield 'empty' => [[]];
        yield 'short key behind a good one' => [[self::KEY, 'too-short']];
        yield 'not a list' => [['current' => self::KEY]];
        yield 'not a string' => [[self::KEY, 42]];
    }

    #[DataProvider('invalidRings')]
    #[TestDox('an invalid key ring is refused at construction: $_dataName')]
    public function testInvalidRingRefused(mixed $keys): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RequestStateCodec($keys);
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
