<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Discovery;

use Mcp\Exception\InvalidArgumentException;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Validates data against JSON Schema definitions using opis/json-schema.
 *
 * @phpstan-type Error array{
 *     pointer: string,
 *     keyword: string,
 *     message: string,
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class SchemaValidator
{
    private ?Validator $jsonSchemaValidator = null;

    public function __construct(
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Validates data against a JSON schema.
     *
     * @param mixed                       $data   the data to validate (should generally be decoded JSON)
     * @param array<string, mixed>|object $schema the JSON Schema definition (as PHP array or object)
     *
     * @return list<array{pointer: string, keyword: string, message: string}> array of validation errors, empty if valid
     */
    public function validateAgainstJsonSchema(mixed $data, array|object $schema): array
    {
        if (\is_array($data) && empty($data)) {
            $data = new \stdClass();
        }

        try {
            // --- Schema Preparation ---
            if (\is_array($schema)) {
                $schemaJson = json_encode($schema, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
                $schemaObject = json_decode($schemaJson, false, 512, \JSON_THROW_ON_ERROR);
            } elseif (\is_object($schema)) {
                // This might be overly cautious but safer against varied inputs.
                $schemaJson = json_encode($schema, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
                $schemaObject = json_decode($schemaJson, false, 512, \JSON_THROW_ON_ERROR);
            } else {
                throw new InvalidArgumentException('Schema must be an array or object.');
            }

            // --- Data Preparation ---
            // Opis Validator generally prefers objects for object validation
            $dataToValidate = $this->convertDataForValidator($data);
        } catch (\JsonException $e) {
            $this->logger->error('MCP SDK: Invalid schema structure provided for validation (JSON conversion failed).', ['exception' => $e]);

            return [['pointer' => '', 'keyword' => 'internal', 'message' => 'Invalid schema definition provided (JSON error).']];
        } catch (InvalidArgumentException $e) {
            $this->logger->error('MCP SDK: Invalid schema structure provided for validation.', ['exception' => $e]);

            return [['pointer' => '', 'keyword' => 'internal', 'message' => $e->getMessage()]];
        } catch (\Throwable $e) {
            $this->logger->error('MCP SDK: Error preparing data/schema for validation.', ['exception' => $e]);

            return [['pointer' => '', 'keyword' => 'internal', 'message' => 'Internal validation preparation error.']];
        }

        $validator = $this->getJsonSchemaValidator();

        try {
            $result = $validator->validate($dataToValidate, $schemaObject);
        } catch (\Throwable $e) {
            $this->logger->error('MCP SDK: JSON Schema validation failed internally.', [
                'exception_message' => $e->getMessage(),
                'exception_trace' => $e->getTraceAsString(),
                'data' => json_encode($dataToValidate),
                'schema' => json_encode($schemaObject),
            ]);

            return [['pointer' => '', 'keyword' => 'internal', 'message' => 'Schema validation process failed: '.$e->getMessage()]];
        }

        if ($result->isValid()) {
            return [];
        }

        $formattedErrors = [];
        $topError = $result->error();

        if ($topError) {
            $this->collectSubErrors($topError, $formattedErrors);
        }

        if (empty($formattedErrors) && $topError) { // Fallback
            $formattedErrors[] = [
                'pointer' => $this->formatJsonPointerPath($topError->data()->path()),
                'keyword' => $topError->keyword(),
                'message' => $this->formatValidationError($topError),
            ];
        }

        return $formattedErrors;
    }

    /**
     * Get or create the JSON Schema validator instance.
     */
    private function getJsonSchemaValidator(): Validator
    {
        if (null === $this->jsonSchemaValidator) {
            $this->jsonSchemaValidator = new Validator();
            // Potentially configure resolver here if needed later
        }

        return $this->jsonSchemaValidator;
    }

    /**
     * Recursively converts associative arrays to stdClass objects for validator compatibility.
     */
    private function convertDataForValidator(mixed $data): mixed
    {
        if (\is_array($data)) {
            // Check if it's an associative array (keys are not sequential numbers 0..N-1)
            if (!empty($data) && array_keys($data) !== range(0, \count($data) - 1)) {
                $obj = new \stdClass();
                foreach ($data as $key => $value) {
                    $obj->{$key} = $this->convertDataForValidator($value);
                }

                return $obj;
            }

            // It's a list (sequential array), convert items recursively
            return array_map([$this, 'convertDataForValidator'], $data);
        } elseif (\is_object($data) && $data instanceof \stdClass) {
            // Deep copy/convert stdClass objects as well
            $obj = new \stdClass();
            foreach (get_object_vars($data) as $key => $value) {
                $obj->{$key} = $this->convertDataForValidator($value);
            }

            return $obj;
        }

        // Leave other objects and scalar types as they are
        return $data;
    }

    /**
     * Recursively collects leaf validation errors.
     *
     * @param Error[] $collectedErrors
     */
    private function collectSubErrors(ValidationError $error, array &$collectedErrors): void
    {
        $subErrors = $error->subErrors();
        if (empty($subErrors)) {
            $collectedErrors[] = [
                'pointer' => $this->formatJsonPointerPath($error->data()->path()),
                'keyword' => $error->keyword(),
                'message' => $this->formatValidationError($error),
            ];
        } else {
            foreach ($subErrors as $subError) {
                $this->collectSubErrors($subError, $collectedErrors);
            }
        }
    }

    /**
     * Formats the path array into a JSON Pointer string.
     *
     * @param string[]|int[]|null $pathComponents
     */
    private function formatJsonPointerPath(?array $pathComponents): string
    {
        if (empty($pathComponents)) {
            return '/';
        }
        $escapedComponents = array_map(static function ($component) {
            $componentStr = (string) $component;

            return str_replace(['~', '/'], ['~0', '~1'], $componentStr);
        }, $pathComponents);

        return '/'.implode('/', $escapedComponents);
    }

    /**
     * Formats an Opis SchemaValidationError into a user-friendly message.
     */
    private function formatValidationError(ValidationError $error): string
    {
        $keyword = $error->keyword();
        $args = $error->args();
        $message = "Constraint `{$keyword}` failed.";

        switch (strtolower($keyword)) {
            case 'required':
                $missing = $args['missing'] ?? [];
                $formattedMissing = implode(', ', array_map(static fn ($p) => "`{$p}`", $missing));
                $message = "Missing required properties: {$formattedMissing}.";
                break;
            case 'type':
                $expected = implode('|', (array) ($args['expected'] ?? []));
                $used = $error->data()->type() ?? 'unknown';
                $message = "Invalid type. Expected `{$expected}`, but received `{$used}`.";
                break;
            case 'enum':
                $schemaData = $error->schema()->info()->data();
                $allowedValues = [];
                if (\is_object($schemaData) && property_exists($schemaData, 'enum') && \is_array($schemaData->enum)) {
                    $allowedValues = $schemaData->enum;
                } elseif (\is_array($schemaData) && isset($schemaData['enum']) && \is_array($schemaData['enum'])) {
                    $allowedValues = $schemaData['enum'];
                } else {
                    $this->logger->warning("MCP SDK: Could not retrieve 'enum' values from schema info for error.", ['error_args' => $args]);
                }
                if (empty($allowedValues)) {
                    $message = 'Value does not match the allowed enumeration.';
                } else {
                    $formattedAllowed = array_map(static function ($v) { /* ... formatting logic ... */
                        if (\is_string($v)) {
                            return '"'.$v.'"';
                        }
                        if (\is_bool($v)) {
                            return $v ? 'true' : 'false';
                        }
                        if (null === $v) {
                            return 'null';
                        }

                        return (string) $v;
                    }, $allowedValues);
                    $message = 'Value must be one of the allowed values: '.implode(', ', $formattedAllowed).'.';
                }
                break;
            case 'const':
                $expected = json_encode($args['expected'] ?? 'null', \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
                $message = "Value must be equal to the constant value: {$expected}.";
                break;
            case 'minLength': // Corrected casing
                $min = $args['min'] ?? '?';
                $message = "String must be at least {$min} characters long.";
                break;
            case 'maxLength': // Corrected casing
                $max = $args['max'] ?? '?';
                $message = "String must not be longer than {$max} characters.";
                break;
            case 'pattern':
                $pattern = $args['pattern'] ?? '?';
                $message = "String does not match the required pattern: `{$pattern}`.";
                break;
            case 'minimum':
                $min = $args['min'] ?? '?';
                $message = "Number must be greater than or equal to {$min}.";
                break;
            case 'maximum':
                $max = $args['max'] ?? '?';
                $message = "Number must be less than or equal to {$max}.";
                break;
            case 'exclusiveMinimum': // Corrected casing
                $min = $args['min'] ?? '?';
                $message = "Number must be strictly greater than {$min}.";
                break;
            case 'exclusiveMaximum': // Corrected casing
                $max = $args['max'] ?? '?';
                $message = "Number must be strictly less than {$max}.";
                break;
            case 'multipleOf': // Corrected casing
                $value = $args['value'] ?? '?';
                $message = "Number must be a multiple of {$value}.";
                break;
            case 'minItems': // Corrected casing
                $min = $args['min'] ?? '?';
                $message = "Array must contain at least {$min} items.";
                break;
            case 'maxItems': // Corrected casing
                $max = $args['max'] ?? '?';
                $message = "Array must contain no more than {$max} items.";
                break;
            case 'uniqueItems': // Corrected casing
                $message = 'Array items must be unique.';
                break;
            case 'minProperties': // Corrected casing
                $min = $args['min'] ?? '?';
                $message = "Object must have at least {$min} properties.";
                break;
            case 'maxProperties': // Corrected casing
                $max = $args['max'] ?? '?';
                $message = "Object must have no more than {$max} properties.";
                break;
            case 'additionalProperties': // Corrected casing
                $unexpected = $args['properties'] ?? [];
                $formattedUnexpected = implode(', ', array_map(static fn ($p) => "`{$p}`", $unexpected));
                $message = "Object contains unexpected additional properties: {$formattedUnexpected}.";
                break;
            case 'format':
                $format = $args['format'] ?? 'unknown';
                $message = "Value does not match the required format: `{$format}`.";
                break;
            default:
                $builtInMessage = $error->message();
                if ($builtInMessage && 'The data must match the schema' !== $builtInMessage) {
                    $placeholders = $args;
                    $builtInMessage = preg_replace_callback('/\{(\w+)\}/', static function ($match) use ($placeholders) {
                        $key = $match[1];
                        $value = $placeholders[$key] ?? '{'.$key.'}';

                        return \is_array($value) ? json_encode($value) : (string) $value;
                    }, $builtInMessage);
                    $message = $builtInMessage;
                }
                break;
        }

        return $message;
    }
}
