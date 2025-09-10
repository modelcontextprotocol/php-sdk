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

namespace Mcp\Example\ComplexSchemaHttpExample;

use Mcp\Capability\Attribute\McpTool;
use Mcp\ComplexSchemaHttpExample\Model\EventPriority;
use Mcp\ComplexSchemaHttpExample\Model\EventType;
use Psr\Log\LoggerInterface;

class McpEventScheduler
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Schedules a new event.
     * The inputSchema for this tool will reflect all parameter types and defaults.
     *
     * @param string        $title       the title of the event
     * @param string        $date        the date of the event (YYYY-MM-DD)
     * @param EventType     $type        the type of event
     * @param string|null   $time        the time of the event (HH:MM), optional
     * @param EventPriority $priority    The priority of the event. Defaults to Normal.
     * @param string[]|null $attendees   an optional list of attendee email addresses
     * @param bool          $sendInvites send calendar invites to attendees? Defaults to true if attendees are provided
     *
     * @return array confirmation of the scheduled event
     */
    #[McpTool(name: 'schedule_event')]
    public function scheduleEvent(
        string $title,
        string $date,
        EventType $type,
        ?string $time = null, // Optional, nullable
        EventPriority $priority = EventPriority::Normal, // Optional with enum default
        ?array $attendees = null, // Optional array of strings, nullable
        bool $sendInvites = true,   // Optional with default
    ): array {
        $this->logger->info("Tool 'schedule_event' called", ['title' => $title, 'date' => $date, 'type' => $type, 'time' => $time, 'priority' => $priority, 'attendees' => $attendees, 'sendInvites' => $sendInvites]);

        // Simulate scheduling logic
        $eventDetails = [
            'title' => $title,
            'date' => $date,
            'type' => $type->value, // Use enum value
            'time' => $time ?? 'All day',
            'priority' => $priority->name, // Use enum name
            'attendees' => $attendees ?? [],
            'invites_will_be_sent' => ($attendees && $sendInvites),
        ];

        // In a real app, this would interact with a calendar service
        $this->logger->info('Event scheduled', ['details' => $eventDetails]);

        return [
            'success' => true,
            'message' => "Event '{$title}' scheduled successfully for {$date}.",
            'event_details' => $eventDetails,
        ];
    }
}
