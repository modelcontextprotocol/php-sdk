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

namespace Mcp\Tests\Unit\Schema\Request;

use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\NumberSchemaDefinition;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Request\ElicitRequest;
use PHPUnit\Framework\TestCase;

final class ElicitRequestTest extends TestCase
{
    public function testConstructor(): void
    {
        $schema = new ElicitationSchema([
            'name' => new StringSchemaDefinition('Name'),
        ]);

        $request = new ElicitRequest('Please provide your name', $schema);

        $this->assertSame('Please provide your name', $request->message);
        $this->assertSame($schema, $request->requestedSchema);
    }

    public function testGetMethod(): void
    {
        $this->assertSame('elicitation/create', ElicitRequest::getMethod());
    }

    public function testJsonSerialization(): void
    {
        $schema = new ElicitationSchema(
            [
                'name' => new StringSchemaDefinition('Name'),
                'age' => new NumberSchemaDefinition('Age', integerOnly: true, minimum: 0),
            ],
            ['name'],
        );

        $request = new ElicitRequest('Please provide your details', $schema);
        $request = $request->withId(1);

        $json = json_encode($request);
        $this->assertIsString($json);

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);

        $this->assertSame('2.0', $decoded['jsonrpc']);
        $this->assertSame('elicitation/create', $decoded['method']);
        $this->assertArrayHasKey('params', $decoded);

        $params = $decoded['params'];
        $this->assertSame('Please provide your details', $params['message']);
        $this->assertArrayHasKey('requestedSchema', $params);

        $requestedSchema = $params['requestedSchema'];
        $this->assertSame('object', $requestedSchema['type']);
        $this->assertArrayHasKey('name', $requestedSchema['properties']);
        $this->assertArrayHasKey('age', $requestedSchema['properties']);
        $this->assertSame(['name'], $requestedSchema['required']);
    }
}
