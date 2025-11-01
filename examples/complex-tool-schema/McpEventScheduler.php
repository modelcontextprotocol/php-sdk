<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\ComplexToolSchema;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Example\ComplexToolSchema\Model\EventPriority;
use Mcp\Example\ComplexToolSchema\Model\EventType;
use Psr\Log\LoggerInterface;

final class McpEventScheduler
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
     * @return array<string, mixed> confirmation of the scheduled event
     */
    #[McpTool(name: 'schedule_event')]
    public function scheduleEvent(
        string $title,
        string $date,
        EventType $type,
        ?string $time = null,
        EventPriority $priority = EventPriority::Normal,
        ?array $attendees = null,
        bool $sendInvites = true,
    ): array {
        $this->logger->info("Tool 'schedule_event' called", compact('title', 'date', 'type', 'time', 'priority', 'attendees', 'sendInvites'));

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
            'message' => \sprintf('Event "%s" scheduled successfully for "%s".', $title, $date),
            'event_details' => $eventDetails,
        ];
    }
}
