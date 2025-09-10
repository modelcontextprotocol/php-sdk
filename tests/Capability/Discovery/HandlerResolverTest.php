<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace Mcp\Tests\Capability\Discovery;

use ReflectionFunction;
use ReflectionNamedType;
use ReflectionMethod;
use Mcp\Capability\Discovery\HandlerResolver;
use Mcp\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class HandlerResolverTest extends TestCase
{
    public function testResolvesClosuresToReflectionFunction(): void
    {
        $closure = (fn (string $input): string => "processed: $input");
        $resolved = HandlerResolver::resolve($closure);
        $this->assertInstanceOf(ReflectionFunction::class, $resolved);
        $this->assertEquals(1, $resolved->getNumberOfParameters());
        $this->assertInstanceOf(ReflectionNamedType::class, $returnType = $resolved->getReturnType());
        $this->assertEquals('string', $returnType->getName());
    }

    public function testResolvesValidArrayHandler(): void
    {
        $handler = [ValidHandlerClass::class, 'publicMethod'];
        $resolved = HandlerResolver::resolve($handler);
        $this->assertInstanceOf(ReflectionMethod::class, $resolved);
        $this->assertEquals('publicMethod', $resolved->getName());
        $this->assertEquals(ValidHandlerClass::class, $resolved->getDeclaringClass()->getName());
    }

    public function testResolvesValidInvokableClassStringHandler(): void
    {
        $handler = ValidInvokableClass::class;
        $resolved = HandlerResolver::resolve($handler);
        $this->assertInstanceOf(ReflectionMethod::class, $resolved);
        $this->assertEquals('__invoke', $resolved->getName());
        $this->assertEquals(ValidInvokableClass::class, $resolved->getDeclaringClass()->getName());
    }

    public function testResolvesStaticMethodsForManualRegistration(): void
    {
        $handler = ValidHandlerClass::staticMethod(...);
        $resolved = HandlerResolver::resolve($handler);
        $this->assertInstanceOf(ReflectionMethod::class, $resolved);
        $this->assertEquals('staticMethod', $resolved->getName());
        $this->assertTrue($resolved->isStatic());
    }

    public function testThrowsForInvalidArrayHandlerFormatCount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid array handler format. Expected [ClassName::class, 'methodName'].");
        HandlerResolver::resolve([ValidHandlerClass::class]); /* @phpstan-ignore argument.type */
    }

    public function testThrowsForInvalidArrayHandlerFormatTypes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid array handler format. Expected [ClassName::class, 'methodName'].");
        HandlerResolver::resolve([ValidHandlerClass::class, 123]); /* @phpstan-ignore argument.type */
    }

    public function testThrowsForNonExistentClassInArrayHandler(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Handler class "NonExistentClass" not found');
        HandlerResolver::resolve(['NonExistentClass', 'method']);
    }

    public function testThrowsForNonExistentMethodInArrayHandler(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Handler method "nonExistentMethod" not found in class');
        HandlerResolver::resolve([ValidHandlerClass::class, 'nonExistentMethod']);
    }

    public function testThrowsForNonExistentClassInStringHandler(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid handler format. Expected Closure, [ClassName::class, \'methodName\'] or InvokableClassName::class string.');
        HandlerResolver::resolve('NonExistentInvokableClass');
    }

    public function testThrowsForNonInvokableClassStringHandler(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invokable handler class "Mcp\\Tests\\Capability\\Discovery\\NonInvokableClass" must have a public "__invoke" method.');
        HandlerResolver::resolve(NonInvokableClass::class);
    }

    public function testThrowsForProtectedMethodHandler(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be public');
        HandlerResolver::resolve([ValidHandlerClass::class, 'protectedMethod']);
    }

    public function testThrowsForPrivateMethodHandler(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Handler method "Mcp\Tests\Capability\Discovery\ValidHandlerClass::privateMethod" must be public.');
        HandlerResolver::resolve([ValidHandlerClass::class, 'privateMethod']);
    }

    public function testThrowsForConstructorAsHandler(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be a constructor or destructor');
        HandlerResolver::resolve([ValidHandlerClass::class, '__construct']);
    }

    public function testThrowsForDestructorAsHandler(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be a constructor or destructor');
        HandlerResolver::resolve([ValidHandlerClass::class, '__destruct']);
    }

    public function testThrowsForAbstractMethodHandler(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Handler method "Mcp\Tests\Capability\Discovery\AbstractHandlerClass::abstractMethod" must not be abstract.');
        HandlerResolver::resolve([AbstractHandlerClass::class, 'abstractMethod']);
    }

    public function testResolvesClosuresWithDifferentSignatures(): void
    {
        $noParams = (fn (): string => 'test');
        $withParams = (fn (int $a, string $b = 'default'): string => $a.$b);
        $variadic = (fn (...$args) => $args);
        $this->assertInstanceOf(ReflectionFunction::class, HandlerResolver::resolve($noParams));
        $this->assertInstanceOf(ReflectionFunction::class, HandlerResolver::resolve($withParams));
        $this->assertInstanceOf(ReflectionFunction::class, HandlerResolver::resolve($variadic));
        $this->assertEquals(0, HandlerResolver::resolve($noParams)->getNumberOfParameters());
        $this->assertEquals(2, HandlerResolver::resolve($withParams)->getNumberOfParameters());
        $this->assertTrue(HandlerResolver::resolve($variadic)->isVariadic());
    }

    public function testDistinguishesBetweenClosuresAndCallableArrays(): void
    {
        $closure = (fn (): string => 'closure');
        $array = [ValidHandlerClass::class, 'publicMethod'];
        $string = ValidInvokableClass::class;
        $this->assertInstanceOf(ReflectionFunction::class, HandlerResolver::resolve($closure));
        $this->assertInstanceOf(ReflectionMethod::class, HandlerResolver::resolve($array));
        $this->assertInstanceOf(ReflectionMethod::class, HandlerResolver::resolve($string));
    }
}

// Helper classes
class ValidHandlerClass
{
    public function publicMethod(): void
    {
    }

    private function privateMethod(): void /* @phpstan-ignore method.unused */
    {
    }

    protected function protectedMethod(): void
    {
    }

    public static function staticMethod(): void
    {
    }

    public function __construct()
    {
    }

    public function __destruct()
    {
    }
}
class ValidInvokableClass
{
    public function __invoke(): void
    {
    }
}
class NonInvokableClass
{
}
abstract class AbstractHandlerClass
{
    abstract public function abstractMethod(): void;
}
