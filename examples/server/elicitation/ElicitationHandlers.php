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

namespace Mcp\Example\Server\Elicitation;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Elicitation\BooleanSchemaDefinition;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\EnumSchemaDefinition;
use Mcp\Schema\Elicitation\NumberSchemaDefinition;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Server\RequestContext;
use Psr\Log\LoggerInterface;

/**
 * Example handlers demonstrating the elicitation feature.
 *
 * Elicitation allows servers to request additional information from users
 * during tool execution. The user can accept (providing data), decline,
 * or cancel the request.
 */
final class ElicitationHandlers
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        $this->logger->info('ElicitationHandlers instantiated.');
    }

    /**
     * Book a restaurant reservation with user elicitation.
     *
     * Demonstrates multi-field elicitation with different field types:
     * - Number field for party size with validation
     * - String field with date format for reservation date
     * - Enum field for dietary restrictions with human-readable labels
     *
     * @return array{status: string, message: string, booking?: array{party_size: int, date: string, dietary: string}}
     */
    #[McpTool('book_restaurant', 'Book a restaurant reservation, collecting details via elicitation.')]
    public function bookRestaurant(RequestContext $context, string $restaurantName): array
    {
        if (!$context->getClientGateway()->supportsElicitation()) {
            return [
                'status' => 'error',
                'message' => 'Client does not support elicitation. Please provide reservation details (party_size, date, dietary) as tool parameters instead.',
            ];
        }

        $client = $context->getClientGateway();

        $this->logger->info(\sprintf('Starting reservation process for restaurant: %s', $restaurantName));

        $schema = new ElicitationSchema(
            properties: [
                'party_size' => new NumberSchemaDefinition(
                    title: 'Party Size',
                    integerOnly: true,
                    description: 'Number of guests in your party',
                    default: 2,
                    minimum: 1,
                    maximum: 20,
                ),
                'date' => new StringSchemaDefinition(
                    title: 'Reservation Date',
                    description: 'Preferred date for your reservation',
                    format: 'date',
                ),
                'dietary' => new EnumSchemaDefinition(
                    title: 'Dietary Restrictions',
                    enum: ['none', 'vegetarian', 'vegan', 'gluten-free', 'halal', 'kosher'],
                    description: 'Any dietary restrictions or preferences',
                    default: 'none',
                    enumNames: ['None', 'Vegetarian', 'Vegan', 'Gluten-Free', 'Halal', 'Kosher'],
                ),
            ],
            required: ['party_size', 'date'],
        );

        $result = $client->elicit(
            message: \sprintf('Please provide your reservation details for %s:', $restaurantName),
            requestedSchema: $schema,
            timeout: 120,
        );

        if ($result->isDeclined()) {
            $this->logger->info('User declined to provide reservation details.');

            return [
                'status' => 'declined',
                'message' => 'Reservation request was declined by user.',
            ];
        }

        if ($result->isCancelled()) {
            $this->logger->info('User cancelled the reservation request.');

            return [
                'status' => 'cancelled',
                'message' => 'Reservation request was cancelled.',
            ];
        }

        $content = $result->content;
        if (null === $content) {
            throw new \RuntimeException('Expected content for accepted elicitation.');
        }

        if (!isset($content['party_size']) || !isset($content['date'])) {
            throw new \RuntimeException('Missing required fields: party_size and date.');
        }

        $partySize = (int) $content['party_size'];
        $date = (string) $content['date'];
        $dietary = (string) ($content['dietary'] ?? 'none');

        if ($partySize < 1 || $partySize > 20) {
            throw new \RuntimeException(\sprintf('Invalid party size: %d. Must be between 1 and 20.', $partySize));
        }

        $this->logger->info(\sprintf(
            'Booking confirmed: %d guests on %s with %s dietary requirements',
            $partySize,
            $date,
            $dietary,
        ));

        return [
            'status' => 'confirmed',
            'message' => \sprintf(
                'Reservation confirmed at %s for %d guests on %s.',
                $restaurantName,
                $partySize,
                $date,
            ),
            'booking' => [
                'party_size' => $partySize,
                'date' => $date,
                'dietary' => $dietary,
            ],
        ];
    }

    /**
     * Confirm an action with a simple boolean elicitation.
     *
     * Demonstrates the simplest elicitation pattern - a yes/no confirmation.
     *
     * @return array{status: string, message: string}
     */
    #[McpTool('confirm_action', 'Request user confirmation before proceeding with an action.')]
    public function confirmAction(RequestContext $context, string $actionDescription): array
    {
        if (!$context->getClientGateway()->supportsElicitation()) {
            return [
                'status' => 'error',
                'message' => 'Client does not support elicitation. Please confirm the action explicitly in your request.',
            ];
        }

        $client = $context->getClientGateway();

        $schema = new ElicitationSchema(
            properties: [
                'confirm' => new BooleanSchemaDefinition(
                    title: 'Confirm',
                    description: 'Check to confirm you want to proceed',
                    default: false,
                ),
            ],
            required: ['confirm'],
        );

        $result = $client->elicit(
            message: \sprintf('Are you sure you want to: %s?', $actionDescription),
            requestedSchema: $schema,
        );

        if (!$result->isAccepted()) {
            return [
                'status' => 'not_confirmed',
                'message' => 'Action was not confirmed by user.',
            ];
        }

        $content = $result->content;
        if (null === $content) {
            throw new \RuntimeException('Expected content for accepted elicitation.');
        }

        if (!isset($content['confirm'])) {
            throw new \RuntimeException('Missing required field: confirm.');
        }

        $confirmed = (bool) $content['confirm'];

        if (!$confirmed) {
            return [
                'status' => 'not_confirmed',
                'message' => 'User did not check the confirmation box.',
            ];
        }

        $this->logger->info(\sprintf('User confirmed action: %s', $actionDescription));

        return [
            'status' => 'confirmed',
            'message' => \sprintf('Action confirmed: %s', $actionDescription),
        ];
    }

    /**
     * Collect user feedback using elicitation.
     *
     * Demonstrates elicitation with optional fields and enum with labels.
     *
     * @return array{status: string, message: string, feedback?: array{rating: string, comments: string}}
     */
    #[McpTool('collect_feedback', 'Collect user feedback via elicitation form.')]
    public function collectFeedback(RequestContext $context, string $topic): array
    {
        if (!$context->getClientGateway()->supportsElicitation()) {
            return [
                'status' => 'error',
                'message' => 'Client does not support elicitation. Please provide feedback (rating 1-5, comments) as tool parameters instead.',
            ];
        }

        $client = $context->getClientGateway();

        $schema = new ElicitationSchema(
            properties: [
                'rating' => new EnumSchemaDefinition(
                    title: 'Rating',
                    enum: ['1', '2', '3', '4', '5'],
                    description: 'Rate your experience from 1 (poor) to 5 (excellent)',
                    enumNames: ['1 - Poor', '2 - Fair', '3 - Good', '4 - Very Good', '5 - Excellent'],
                ),
                'comments' => new StringSchemaDefinition(
                    title: 'Comments',
                    description: 'Any additional comments or suggestions (optional)',
                    maxLength: 500,
                ),
            ],
            required: ['rating'],
        );

        $result = $client->elicit(
            message: \sprintf('Please provide your feedback about: %s', $topic),
            requestedSchema: $schema,
        );

        if (!$result->isAccepted()) {
            return [
                'status' => 'skipped',
                'message' => 'User chose not to provide feedback.',
            ];
        }

        $content = $result->content;
        if (null === $content) {
            throw new \RuntimeException('Expected content for accepted elicitation.');
        }

        if (!isset($content['rating'])) {
            throw new \RuntimeException('Missing required field: rating.');
        }

        $rating = (string) $content['rating'];
        $comments = (string) ($content['comments'] ?? '');

        $this->logger->info(\sprintf('Feedback received: rating=%s, comments=%s', $rating, $comments));

        return [
            'status' => 'received',
            'message' => 'Thank you for your feedback!',
            'feedback' => [
                'rating' => $rating,
                'comments' => $comments,
            ],
        ];
    }
}
