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

namespace Mcp\Schema\Elicitation;

use Mcp\Exception\InvalidArgumentException;

/**
 * Schema definition for string fields in elicitation requests.
 *
 * Supports optional format validation (date, date-time, email, uri) and length constraints.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class StringSchemaDefinition extends AbstractSchemaDefinition
{
    private const VALID_FORMATS = ['date', 'date-time', 'email', 'uri'];

    /**
     * @param string      $title       Human-readable title for the field
     * @param string|null $description Optional description/help text
     * @param string|null $default     Optional default value
     * @param string|null $format      Optional format constraint (date, date-time, email, uri)
     * @param int|null    $minLength   Optional minimum string length
     * @param int|null    $maxLength   Optional maximum string length
     */
    public function __construct(
        string $title,
        ?string $description = null,
        public readonly ?string $default = null,
        public readonly ?string $format = null,
        public readonly ?int $minLength = null,
        public readonly ?int $maxLength = null,
    ) {
        parent::__construct($title, $description);

        if (null !== $format && !\in_array($format, self::VALID_FORMATS, true)) {
            throw new InvalidArgumentException(\sprintf('Invalid format "%s". Valid formats are: %s.', $format, implode(', ', self::VALID_FORMATS)));
        }

        if (null !== $minLength && $minLength < 0) {
            throw new InvalidArgumentException('minLength must be non-negative.');
        }

        if (null !== $maxLength && $maxLength < 0) {
            throw new InvalidArgumentException('maxLength must be non-negative.');
        }

        if (null !== $minLength && null !== $maxLength && $minLength > $maxLength) {
            throw new InvalidArgumentException('minLength cannot be greater than maxLength.');
        }
    }

    /**
     * @param array{
     *     title: string,
     *     description?: string,
     *     default?: string,
     *     format?: string,
     *     minLength?: int,
     *     maxLength?: int,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        self::validateTitle($data, 'string');

        return new self(
            title: $data['title'],
            description: $data['description'] ?? null,
            default: $data['default'] ?? null,
            format: $data['format'] ?? null,
            minLength: isset($data['minLength']) ? (int) $data['minLength'] : null,
            maxLength: isset($data['maxLength']) ? (int) $data['maxLength'] : null,
        );
    }

    /**
     * @return array{
     *     type: string,
     *     title: string,
     *     description?: string,
     *     default?: string,
     *     format?: string,
     *     minLength?: int,
     *     maxLength?: int,
     * }
     */
    public function jsonSerialize(): array
    {
        $data = $this->buildBaseJson('string');

        if (null !== $this->default) {
            $data['default'] = $this->default;
        }

        if (null !== $this->format) {
            $data['format'] = $this->format;
        }

        if (null !== $this->minLength) {
            $data['minLength'] = $this->minLength;
        }

        if (null !== $this->maxLength) {
            $data['maxLength'] = $this->maxLength;
        }

        return $data;
    }
}
