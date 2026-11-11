<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Capability\Discovery;

use Mcp\Server\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;
use Symfony\Component\JsonSchema\Attribute\JsonSchemaConstraint;
use Symfony\Component\Validator\Constraints as Assert;

enum FixtureUnit: string
{
    case Celsius = 'c';
    case Fahrenheit = 'f';
}

final class FixtureAddress
{
    public function __construct(
        public string $street,
        #[Assert\Length(min: 2, max: 2)]
        public string $country = 'DE',
    ) {
    }
}

final class FixtureUser
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        #[Assert\Email]
        public string $email,
        #[JsonSchemaConstraint(minimum: 18)]
        public int $age,
        public ?FixtureAddress $address = null,
        public array $tags = [],
        public ?\DateTimeImmutable $birthday = null,
    ) {
    }
}

final class FixtureCategory
{
    public string $name;

    /** @var list<FixtureCategory> */
    public array $children = [];
}

final class SchemaGeneratorObjectFixture
{
    /**
     * @param string                            $city    the city to look up
     * @param list<string>                      $fields  which fields to return
     * @param array{lat: float, label?: string} $point
     * @param array<string, int>                $weights
     */
    public function scalars(string $city, array $fields, array $point, array $weights, int $days = 3, FixtureUnit $unit = FixtureUnit::Celsius): void
    {
    }

    /**
     * @param FixtureUser $user the user to create
     */
    public function dto(FixtureUser $user, RequestContext $context): void
    {
    }

    public function tree(FixtureCategory $root): void
    {
    }

    public function variadic(int ...$numbers): void
    {
    }

    public function untyped($anything): void // @phpstan-ignore missingType.parameter
    {
    }

    public function returnsDto(): FixtureUser
    {
        return new FixtureUser('a@b.c', 18);
    }

    #[McpTool(outputSchema: ['type' => 'object', 'properties' => ['explicit' => ['type' => 'boolean']]])]
    public function explicitOutput(): FixtureUser
    {
        return new FixtureUser('a@b.c', 18);
    }

    public function returnsArray(): array
    {
        return [];
    }
}
