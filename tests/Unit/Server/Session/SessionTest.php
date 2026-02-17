<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Session;

use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV4;

class SessionTest extends TestCase
{
    private InMemorySessionStore $store;
    private Session $session;

    protected function setUp(): void
    {
        $this->store = new InMemorySessionStore();
        $this->session = new Session($this->store);
    }

    public function testGetIdReturnsSessionId(): void
    {
        $id = new UuidV4();
        $session = new Session($this->store, $id);

        $this->assertSame($id, $session->getId());
    }

    public function testSetAndGetSimpleKey(): void
    {
        $this->session->set('foo', 'bar');

        $this->assertSame('bar', $this->session->get('foo'));
    }

    public function testGetReturnsDefaultWhenKeyDoesNotExist(): void
    {
        $this->assertNull($this->session->get('nonexistent'));
        $this->assertSame('default', $this->session->get('nonexistent', 'default'));
    }

    public function testSetAndGetNestedKey(): void
    {
        $this->session->set('user.name', 'John');
        $this->session->set('user.email', 'john@example.com');
        $this->session->set('user.address.city', 'New York');

        $this->assertSame('John', $this->session->get('user.name'));
        $this->assertSame('john@example.com', $this->session->get('user.email'));
        $this->assertSame('New York', $this->session->get('user.address.city'));
        $this->assertSame(['city' => 'New York'], $this->session->get('user.address'));
    }

    public function testSetDoesNotOverwriteWhenOverwriteIsFalse(): void
    {
        $this->session->set('key', 'original');
        $this->session->set('key', 'new', overwrite: false);

        $this->assertSame('original', $this->session->get('key'));
    }

    public function testSetOverwritesWhenOverwriteIsTrue(): void
    {
        $this->session->set('key', 'original');
        $this->session->set('key', 'new');

        $this->assertSame('new', $this->session->get('key'));
    }

    public function testHasReturnsTrueForExistingKey(): void
    {
        $this->session->set('foo', 'bar');

        $this->assertTrue($this->session->has('foo'));
    }

    public function testHasReturnsFalseForNonExistingKey(): void
    {
        $this->assertFalse($this->session->has('nonexistent'));
    }

    public function testHasWorksWithNestedKeys(): void
    {
        $this->session->set('user.name', 'John');

        $this->assertTrue($this->session->has('user'));
        $this->assertTrue($this->session->has('user.name'));
        $this->assertFalse($this->session->has('user.email'));
        $this->assertFalse($this->session->has('user.name.first'));
    }

    public function testForgetRemovesSimpleKey(): void
    {
        $this->session->set('foo', 'bar');
        $this->session->forget('foo');

        $this->assertFalse($this->session->has('foo'));
        $this->assertNull($this->session->get('foo'));
    }

    public function testForgetRemovesNestedKey(): void
    {
        $this->session->set('user.name', 'John');
        $this->session->set('user.email', 'john@example.com');
        $this->session->forget('user.name');

        $this->assertFalse($this->session->has('user.name'));
        $this->assertTrue($this->session->has('user.email'));
    }

    public function testClearRemovesAllData(): void
    {
        $this->session->set('foo', 'bar');
        $this->session->set('baz', 'qux');
        $this->session->clear();

        $this->assertSame([], $this->session->all());
        $this->assertFalse($this->session->has('foo'));
        $this->assertFalse($this->session->has('baz'));
    }

    public function testPullReturnsValueAndRemovesKey(): void
    {
        $this->session->set('foo', 'bar');

        $value = $this->session->pull('foo');

        $this->assertSame('bar', $value);
        $this->assertFalse($this->session->has('foo'));
    }

    public function testPullReturnsDefaultWhenKeyDoesNotExist(): void
    {
        $this->assertNull($this->session->pull('nonexistent'));
        $this->assertSame('default', $this->session->pull('also_nonexistent', 'default'));
    }

    public function testAllReturnsAllData(): void
    {
        $this->session->set('foo', 'bar');
        $this->session->set('user.name', 'John');

        $all = $this->session->all();

        $this->assertSame([
            'foo' => 'bar',
            'user' => ['name' => 'John'],
        ], $all);
    }

    public function testHydrateReplacesAllData(): void
    {
        $this->session->set('original', 'value');

        $this->session->hydrate(['new' => 'data', 'nested' => ['key' => 'value']]);

        $this->assertSame([
            'new' => 'data',
            'nested' => ['key' => 'value'],
        ], $this->session->all());
        $this->assertFalse($this->session->has('original'));
    }

    public function testJsonSerializeReturnsAllData(): void
    {
        $this->session->set('foo', 'bar');
        $this->session->set('user.name', 'John');

        $serialized = $this->session->jsonSerialize();

        $this->assertSame([
            'foo' => 'bar',
            'user' => ['name' => 'John'],
        ], $serialized);
    }

    public function testSavePersistsDataToStore(): void
    {
        $this->session->set('foo', 'bar');
        $result = $this->session->save();

        $this->assertTrue($result);

        // Verify data was persisted by creating a new session with the same ID
        $newSession = new Session($this->store, $this->session->getId());
        $this->assertSame('bar', $newSession->get('foo'));
    }

    public function testSessionLoadsDataFromStoreOnConstruction(): void
    {
        // Set and save data in one session
        $this->session->set('persisted', 'value');
        $this->session->save();
        $sessionId = $this->session->getId();

        // Create a new session instance with the same ID
        $newSession = new Session($this->store, $sessionId);

        $this->assertSame('value', $newSession->get('persisted'));
    }

    public function testSetCreatesNestedStructure(): void
    {
        $this->session->set('a.b.c.d', 'value');

        $this->assertSame('value', $this->session->get('a.b.c.d'));
        $this->assertSame(['d' => 'value'], $this->session->get('a.b.c'));
        $this->assertSame(['c' => ['d' => 'value']], $this->session->get('a.b'));
        $this->assertSame(['b' => ['c' => ['d' => 'value']]], $this->session->get('a'));
    }

    public function testSetOverwritesNonArrayWithNestedStructure(): void
    {
        $this->session->set('key', 'string_value');
        $this->session->set('key.nested', 'nested_value');

        $this->assertSame('nested_value', $this->session->get('key.nested'));
        $this->assertSame(['nested' => 'nested_value'], $this->session->get('key'));
    }

    public function testGetReturnsArrayForIntermediateKey(): void
    {
        $this->session->set('user.profile.name', 'John');
        $this->session->set('user.profile.age', 30);

        $profile = $this->session->get('user.profile');

        $this->assertSame(['name' => 'John', 'age' => 30], $profile);
    }

    public function testForgetDoesNotThrowWhenKeyDoesNotExist(): void
    {
        $this->session->forget('nonexistent');
        $this->session->forget('nested.nonexistent');

        $this->assertFalse($this->session->has('nonexistent'));
    }

    public function testSessionCanStoreVariousDataTypes(): void
    {
        $this->session->set('string', 'value');
        $this->session->set('int', 42);
        $this->session->set('float', 3.14);
        $this->session->set('bool', true);
        $this->session->set('null', null);
        $this->session->set('array', ['a', 'b', 'c']);
        $this->session->set('assoc', ['key' => 'value']);

        $this->assertSame('value', $this->session->get('string'));
        $this->assertSame(42, $this->session->get('int'));
        $this->assertSame(3.14, $this->session->get('float'));
        $this->assertTrue($this->session->get('bool'));
        $this->assertNull($this->session->get('null'));
        $this->assertSame(['a', 'b', 'c'], $this->session->get('array'));
        $this->assertSame(['key' => 'value'], $this->session->get('assoc'));
    }

    public function testSessionGeneratesUniqueIdIfNotProvided(): void
    {
        $session1 = new Session($this->store);
        $session2 = new Session($this->store);

        $this->assertNotEquals($session1->getId()->toRfc4122(), $session2->getId()->toRfc4122());
    }

    public function testAll()
    {
        $store = $this->getMockBuilder(InMemorySessionStore::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['read'])
            ->getMock();
        $store->expects($this->once())->method('read')->willReturn(json_encode(['foo' => 'bar']));

        $session = new Session($store);
        $result = $session->all();
        $this->assertEquals(['foo' => 'bar'], $result);

        // Call again to make sure we dont read from Store
        $result = $session->all();
        $this->assertEquals(['foo' => 'bar'], $result);
    }

    public function testAllReturnsEmptyArrayForNullPayload()
    {
        $store = $this->getMockBuilder(InMemorySessionStore::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['read'])
            ->getMock();
        $store->expects($this->once())->method('read')->willReturn('null');

        $session = new Session($store);
        $result = $session->all();

        $this->assertSame([], $result);
    }
}
