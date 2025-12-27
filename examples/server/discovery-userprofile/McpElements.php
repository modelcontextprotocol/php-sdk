<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\Server\DiscoveryUserProfile;

use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\PromptGetException;
use Mcp\Exception\ResourceReadException;
use Psr\Log\LoggerInterface;

/**
 * @phpstan-type User array{name: string, email: string, role: string}
 */
final class McpElements
{
    /**
     * Simulate a simple user database.
     *
     * @var array<int, User>
     */
    private array $users = [
        '101' => ['name' => 'Alice', 'email' => 'alice@example.com', 'role' => 'admin'],
        '102' => ['name' => 'Bob', 'email' => 'bob@example.com', 'role' => 'user'],
        '103' => ['name' => 'Charlie', 'email' => 'charlie@example.com', 'role' => 'user'],
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        $this->logger->debug('DiscoveryUserProfile McpElements instantiated.');
    }

    /**
     * Retrieves the profile data for a specific user.
     *
     * @param string $userId the ID of the user (from URI)
     *
     * @return User user profile data
     *
     * @throws ResourceReadException if the user is not found
     */
    #[McpResourceTemplate(
        uriTemplate: 'user://{userId}/profile',
        name: 'user_profile',
        description: 'Get profile information for a specific user ID.',
        mimeType: 'application/json'
    )]
    public function getUserProfile(
        #[CompletionProvider(values: ['101', '102', '103'])]
        string $userId,
    ): array {
        $this->logger->info('Reading resource: user profile', ['userId' => $userId]);
        if (!isset($this->users[$userId])) {
            throw new ResourceReadException("User not found for ID: {$userId}");
        }

        return $this->users[$userId];
    }

    /**
     * Retrieves a list of all known user IDs.
     *
     * @return int[] list of user IDs
     */
    #[McpResource(
        uri: 'user://list/ids',
        name: 'user_id_list',
        description: 'Provides a list of all available user IDs.',
        mimeType: 'application/json'
    )]
    public function listUserIds(): array
    {
        $this->logger->info('Reading resource: user ID list');

        return array_keys($this->users);
    }

    /**
     * Sends a welcome message to a user.
     * (This is a placeholder - in a real app, it might queue an email).
     *
     * @param string      $userId        the ID of the user to message
     * @param string|null $customMessage an optional custom message part
     *
     * @return array<string, bool|string> status of the operation
     */
    #[McpTool(name: 'send_welcome')]
    public function sendWelcomeMessage(string $userId, ?string $customMessage = null): array
    {
        $this->logger->info('Executing tool: send_welcome', ['userId' => $userId]);
        if (!isset($this->users[$userId])) {
            return ['success' => false, 'error' => "User ID {$userId} not found."];
        }
        $user = $this->users[$userId];
        $message = "Welcome, {$user['name']}!";
        if ($customMessage) {
            $message .= ' '.$customMessage;
        }
        // Simulate sending
        $this->logger->info("Simulated sending message to {$user['email']}: {$message}");

        return ['success' => true, 'message_sent' => $message];
    }

    /**
     * @return array<string, bool|string>
     */
    #[McpTool(name: 'test_tool_without_params')]
    public function testToolWithoutParams(): array
    {
        return ['success' => true, 'message' => 'Test tool without params'];
    }

    /**
     * Generates a prompt to write a bio for a user.
     *
     * @param string $userId the user ID to generate the bio for
     * @param string $tone   Desired tone (e.g., 'formal', 'casual').
     *
     * @return array<string, string>[] prompt messages
     *
     * @throws PromptGetException if user not found
     */
    #[McpPrompt(name: 'generate_bio_prompt')]
    public function generateBio(
        #[CompletionProvider(provider: UserIdCompletionProvider::class)]
        string $userId,
        string $tone = 'professional',
    ): array {
        $this->logger->info('Executing prompt: generate_bio', ['userId' => $userId, 'tone' => $tone]);
        if (!isset($this->users[$userId])) {
            throw new PromptGetException("User not found for bio prompt: {$userId}");
        }
        $user = $this->users[$userId];

        return [
            ['role' => 'user', 'content' => "Write a short, {$tone} biography for {$user['name']} (Role: {$user['role']}, Email: {$user['email']}). Highlight their role within the system."],
        ];
    }
}
