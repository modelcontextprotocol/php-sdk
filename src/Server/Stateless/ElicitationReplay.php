<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Stateless;

use Mcp\Exception\LogicException;
use Mcp\Schema\Enum\ElicitationMode;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\ElicitResult;
use Mcp\Schema\Result\InputRequiredResult;

/**
 * Answers a handler's {@see \Mcp\Server\ClientGateway::elicit()} on a lifecycle
 * that has no server-initiated requests.
 *
 * The 2026-07-28 revision carries an ask in the result and lets the client
 * re-send the whole call with the answer, so the blocking call a handler makes
 * cannot block: there is nobody to ask while the request is open. What there is
 * instead is a way to end the request by asking, and to be entered again once
 * the answer exists — which is the same call, resolved one round later.
 *
 * So an ask is served twice. The first time nothing has been answered, and the
 * gateway's suspension becomes an {@see InputRequiredResult} that ends the
 * request. The second time the handler runs from the top again, reaches the
 * same ask, and this time the answer is here, so the call returns it and the
 * handler continues past it as if it had never stopped. A handler written
 * against the handshake era therefore serves this one unchanged — at the price
 * of running once per ask, which is what makes anything it does before its last
 * ask happen once per round.
 *
 * Answers are kept in the `requestState` because the client only ever echoes
 * the round it just answered ({@see \Mcp\Client\Protocol}), so round three
 * would otherwise no longer know what round one said. That state is signed and
 * not encrypted, and it is the client's own answers travelling back to the
 * client that gave them — but a handler that elicits something it would not
 * hand back should ask for it in one round and use it in that same round.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class ElicitationReplay
{
    /**
     * The `requestState` member carrying answers from earlier rounds. Reserved,
     * like every `_mcp.` key: a handler's own payload travels beside it.
     */
    public const CARRIED_ANSWERS = '_mcp.answers';

    /** @var array<string, array<string, mixed>> raw answers, keyed as they were asked */
    private array $answers = [];

    /** @var array<string, mixed> the verified state this round arrived with */
    private array $payload;

    /** @var array<string, true> keys whose answer this run could not read */
    private array $rejected = [];

    private int $asked = 0;

    public function __construct(?InputContext $input, private readonly ?RequestStateCodec $codec = null)
    {
        $this->payload = $input?->requestState() ?? [];

        $carried = $this->payload[self::CARRIED_ANSWERS] ?? [];

        if (\is_array($carried)) {
            $this->answers = array_filter($carried, \is_array(...));
        }

        // Lifted out, so what is sealed again is what this run could still use
        // rather than whatever the last round happened to carry.
        unset($this->payload[self::CARRIED_ANSWERS]);

        // What this round answered wins over what an earlier one did: a key is
        // only re-asked because its old answer was not usable.
        foreach ($input?->all() ?? [] as $key => $answer) {
            if (\is_array($answer)) {
                $this->answers[$key] = $answer;
            }
        }
    }

    /**
     * The key an ask is filed under: the one the handler named, or its position
     * among this run's asks.
     *
     * Positional keys hold across rounds only because the handler reaches its
     * asks in the same order every time — which it does whenever it is
     * re-enterable at all. A handler whose asks depend on a coin flip should
     * name them.
     */
    public function key(?string $key): string
    {
        ++$this->asked;

        return $key ?? 'elicitation_'.$this->asked;
    }

    /**
     * The answer to `$key`, or null when there is none to give the handler.
     *
     * Null covers an answer that does not parse as well as one that never
     * arrived: the specification says a server SHOULD ask again for what it
     * still needs, and a malformed answer left the server still needing it.
     *
     * @return array<string, mixed>|null
     */
    public function answer(string $key, ElicitationMode $mode = ElicitationMode::Form): ?array
    {
        $answer = $this->answers[$key] ?? null;

        if (null === $answer) {
            return null;
        }

        try {
            ElicitResult::fromArray($answer, $mode);
        } catch (\Throwable) {
            // Noted rather than dropped: `answer()` only reads, and what makes
            // this one unreadable is the mode it was read under. Marking it
            // keeps it out of the state, since it is about to be asked again.
            $this->rejected[$key] = true;

            return null;
        }

        unset($this->rejected[$key]);

        return $answer;
    }

    /**
     * The result that ends this request by asking, carrying everything already
     * answered so the next round does not have to ask for it again.
     *
     * @throws LogicException when there is state to carry and no key to sign it with
     */
    public function ask(string $key, ElicitRequest $request): InputRequiredResult
    {
        return new InputRequiredResult([$key => $request], $this->state());
    }

    private function state(): ?string
    {
        $payload = $this->payload;
        $answers = array_diff_key($this->answers, $this->rejected);

        if ([] !== $answers) {
            $payload[self::CARRIED_ANSWERS] = $answers;
        }

        if ([] === $payload) {
            return null;
        }

        if (null === $this->codec) {
            throw new LogicException('Carrying an answer to the next round of a multi round-trip call needs a signing key; call Builder::setRequestState() to configure one.');
        }

        return $this->codec->mint($payload);
    }
}
