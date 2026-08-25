<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema\Extension;

use Mcp\Schema\Extension\AbstractExtension;
use Mcp\Schema\Extension\ExtensionIdentifier;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class AbstractExtensionTest extends TestCase
{
    #[TestDox('An extension that only announces a capability need not declare messages or handlers')]
    public function testDefaultsToNoMessagesOrHandlers(): void
    {
        $extension = new class extends AbstractExtension {
            public function getId(): ExtensionIdentifier
            {
                return new ExtensionIdentifier('com.example/minimal');
            }

            public function getCapabilities(): array
            {
                return [];
            }
        };

        $this->assertSame([], $extension->getMessages());
        $this->assertSame([], $extension->getRequestHandlers());
    }
}
