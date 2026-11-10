<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema\Content;

use Mcp\Schema\Content\MimeType;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class MimeTypeTest extends TestCase
{
    #[RequiresPhpExtension('fileinfo')]
    public function testGuessDetectsMimeTypeOfFile(): void
    {
        $this->assertSame('text/plain', MimeType::guess(__DIR__.'/../../../../LICENSE'));
    }
}
