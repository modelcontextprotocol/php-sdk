<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Extension\Tasks;

use Mcp\Schema\Exception\InvalidArgumentException;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\ResultInterface;

/**
 * @author OpenAI
 */
final class UpdateTaskRequest extends Request
{
    /**
     * @param array<array-key, array<string, mixed>|\stdClass|ResultInterface> $inputResponses
     */
    public function __construct(
        public readonly string $taskId,
        public readonly array $inputResponses,
    ) {
    }

    public static function getMethod(): string
    {
        return 'tasks/update';
    }

    public static function requiresJsonTypes(): bool
    {
        return true;
    }

    protected static function paramsToArray(\stdClass $params): array
    {
        $data = parent::paramsToArray($params);
        if (isset($params->inputResponses)) {
            $data['inputResponses'] = $params->inputResponses;
        }

        return $data;
    }

    protected static function fromParams(?array $params): static
    {
        if (!\is_string($params['taskId'] ?? null)) {
            throw new InvalidArgumentException('Missing or invalid "taskId" parameter for tasks/update.');
        }

        $inputResponses = $params['inputResponses'] ?? null;
        if (!$inputResponses instanceof \stdClass) {
            throw new InvalidArgumentException('Parameter "inputResponses" must be an object.');
        }

        $responses = [];
        foreach ((array) $inputResponses as $key => $response) {
            if (!$response instanceof \stdClass) {
                throw new InvalidArgumentException('Each entry in "inputResponses" must be an object.');
            }

            $responses[$key] = $response;
        }

        return new self($params['taskId'], $responses);
    }

    /**
     * @return array{taskId: string, inputResponses: \stdClass}
     */
    protected function getParams(): array
    {
        $responses = array_map(
            static fn (array|\stdClass|ResultInterface $response): object => \is_array($response) ? (object) $response : $response,
            $this->inputResponses,
        );

        return ['taskId' => $this->taskId, 'inputResponses' => (object) $responses];
    }
}
