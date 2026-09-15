<?php

declare(strict_types=1);

namespace App\Services\ElevenLabs;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * ElevenLabs, in memory, on a laptop with no account.
 *
 * The default driver, and it ships rather than living in tests/ because the
 * first hour with this repo is `docker compose up` and `kitchenline:provision`,
 * and that hour should end with the developer looking at the nine tool
 * definitions and the system prompt this application would have sent. Signing
 * up is the second hour.
 *
 * It keeps everything it was given, in the shape the real API keeps it, so the
 * provisioner cannot tell the difference: ids that look like ids, a second run
 * that finds the first run's work, a 404-shaped failure for an id nobody
 * created. The one thing it does not do is validate payloads — that is what a
 * 422 from the real API is for, and pretending to know ElevenLabs' validation
 * rules would only teach the provisioner to satisfy this class instead.
 *
 * Ids are derived from the name rather than random, so the same restaurant
 * always produces the same ids, and the workspace is kept in the cache rather
 * than in this object: a provisioning run is a whole process, and a workspace
 * that forgot everything between two `artisan` invocations could not show the
 * one behaviour worth showing, which is that the second run creates nothing.
 *
 * In the test suite `CACHE_STORE` is `array`, so that same arrangement gives
 * each test its own empty workspace with no cleanup to remember.
 */
final class FakeElevenLabsClient implements ElevenLabsClient
{
    private const CACHE_KEY = 'restaurantline:elevenlabs-fake-workspace';

    /** @var array<string, array<string, mixed>> */
    private array $tools = [];

    /** @var array<string, array<string, mixed>> */
    private array $agents = [];

    /** @var array<string, array{name: string, value: string}> */
    private array $secrets = [];

    /** @var array<string, array{name: string, url: string}> */
    private array $webhooks = [];

    /** @var array<string, array<string, mixed>> */
    private array $phoneNumbers = [];

    public function __construct()
    {
        /** @var array<string, array<string, mixed>> $state */
        $state = Cache::get(self::CACHE_KEY, []);

        $this->tools = $state['tools'] ?? [];
        $this->agents = $state['agents'] ?? [];
        // @phpstan-ignore-next-line assign.propertyType
        $this->secrets = $state['secrets'] ?? [];
        // @phpstan-ignore-next-line assign.propertyType
        $this->webhooks = $state['webhooks'] ?? [];
        $this->phoneNumbers = $state['phone_numbers'] ?? [];
    }

    public function name(): string
    {
        return 'fake';
    }

    /**
     * Empty the workspace, the way deleting everything in the dashboard would.
     *
     * `php artisan cache:clear` does the same thing, which is the right level
     * of ceremony for throwing away a make-believe account.
     */
    public function flush(): void
    {
        $this->tools = [];
        $this->agents = [];
        $this->secrets = [];
        $this->webhooks = [];
        $this->phoneNumbers = [];

        Cache::forget(self::CACHE_KEY);
    }

    // -----------------------------------------------------------------------
    // Tools
    // -----------------------------------------------------------------------

    public function createTool(array $config): string
    {
        $id = $this->id('tool', is_string($config['name'] ?? null) ? $config['name'] : Str::random());

        $this->tools[$id] = $config;
        $this->persist();

        return $id;
    }

    public function updateTool(string $toolId, array $config): string
    {
        $this->mustExist($this->tools, $toolId, 'tool');

        $this->tools[$toolId] = $config;
        $this->persist();

        return $toolId;
    }

    public function getTool(string $toolId): ?array
    {
        return isset($this->tools[$toolId])
            ? ['id' => $toolId, 'tool_config' => $this->tools[$toolId]]
            : null;
    }

    // -----------------------------------------------------------------------
    // Agents
    // -----------------------------------------------------------------------

    public function createAgent(array $payload): string
    {
        $id = $this->id('agent', is_string($payload['name'] ?? null) ? $payload['name'] : Str::random());

        $this->agents[$id] = $payload;
        $this->persist();

        return $id;
    }

    /**
     * A shallow merge, which is what the real endpoint does and is also the
     * only reason anything calls it: provisioning patches `platform_settings`
     * or `conversation_config` whole, never a leaf inside one.
     */
    public function updateAgent(string $agentId, array $payload): void
    {
        $this->mustExist($this->agents, $agentId, 'agent');

        $this->agents[$agentId] = array_replace($this->agents[$agentId], $payload);
        $this->persist();
    }

    public function getAgent(string $agentId): ?array
    {
        return isset($this->agents[$agentId])
            ? ['agent_id' => $agentId] + $this->agents[$agentId]
            : null;
    }

    // -----------------------------------------------------------------------
    // Secrets
    // -----------------------------------------------------------------------

    public function createSecret(string $name, string $value): string
    {
        $id = $this->id('secret', $name);

        $this->secrets[$id] = ['name' => $name, 'value' => $value];
        $this->persist();

        return $id;
    }

    public function updateSecret(string $secretId, string $name, string $value): void
    {
        $this->mustExist($this->secrets, $secretId, 'secret');

        $this->secrets[$secretId] = ['name' => $name, 'value' => $value];
        $this->persist();
    }

    public function listSecrets(): array
    {
        $map = [];

        foreach ($this->secrets as $id => $secret) {
            $map[$secret['name']] = $id;
        }

        return $map;
    }

    /**
     * The stored value, for tests that care that the right token went up.
     *
     * The real API has no such method — a secret store you can read back is not
     * one — which is exactly why this lives here and not on the interface.
     */
    public function secretValue(string $secretId): ?string
    {
        return $this->secrets[$secretId]['value'] ?? null;
    }

    // -----------------------------------------------------------------------
    // Webhooks
    // -----------------------------------------------------------------------

    public function createWebhook(string $name, string $url): array
    {
        $id = $this->id('webhook', $url);

        $this->webhooks[$id] = ['name' => $name, 'url' => $url];
        $this->persist();

        return [
            'webhook_id' => $id,
            // Shaped like the real one — 64 hex characters — so anything that
            // prints it, stores it or checks its length behaves the same.
            'webhook_secret' => 'wsec_'.hash('sha256', 'fake-webhook-secret:'.$url),
        ];
    }

    public function listWebhooks(): array
    {
        $map = [];

        foreach ($this->webhooks as $id => $webhook) {
            $map[$webhook['url']] = $id;
        }

        return $map;
    }

    // -----------------------------------------------------------------------
    // Simulation
    // -----------------------------------------------------------------------

    /**
     * The one method this class refuses rather than pretends.
     *
     * Everywhere else, a plausible answer is better than an error: a forker
     * without an account should still see nine tools and a system prompt. Here
     * a plausible answer would be a lie with consequences — a made-up
     * transcript, graded, printed as a pass. The whole value of a live eval is
     * that a real model made real decisions, and nothing in this file can
     * stand in for that.
     *
     * Fake mode is not the degraded version of this. It is a different and
     * better-aimed test, and `kitchenline:eval` runs it by default.
     */
    public function simulateConversation(
        string $agentId,
        array $simulationSpecification,
        array $evaluationCriteria = [],
        ?int $turnLimit = null,
    ): array {
        throw new ElevenLabsException(
            'Simulating a conversation needs a real agent and a real model, so there is nothing sensible '
            .'to fake. Set ELEVENLABS_DRIVER=api with a key to run live evals, or run `kitchenline:eval` '
            .'without --mode=live to replay the scenarios against this application instead.',
        );
    }

    // -----------------------------------------------------------------------
    // Phone numbers
    // -----------------------------------------------------------------------

    public function importTwilioNumber(
        string $phoneNumber,
        string $label,
        string $accountSid,
        string $authToken,
        ?string $agentId = null,
    ): string {
        $id = $this->id('phnum', $phoneNumber);

        $this->phoneNumbers[$id] = [
            'phone_number' => $phoneNumber,
            'label' => $label,
            'agent_id' => $agentId,
        ];
        $this->persist();

        return $id;
    }

    // -----------------------------------------------------------------------
    // What it was given
    // -----------------------------------------------------------------------

    /**
     * Every tool config it holds, keyed by tool name rather than id.
     *
     * @return array<string, array<string, mixed>>
     */
    public function tools(): array
    {
        $byName = [];

        foreach ($this->tools as $config) {
            if (is_string($config['name'] ?? null)) {
                $byName[$config['name']] = $config;
            }
        }

        return $byName;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function agents(): array
    {
        return $this->agents;
    }

    /**
     * @return array<string, array{phone_number: string, label: string, agent_id: string|null}>
     */
    public function phoneNumbers(): array
    {
        // @phpstan-ignore-next-line return.type
        return $this->phoneNumbers;
    }

    // -----------------------------------------------------------------------
    // Plumbing
    // -----------------------------------------------------------------------

    private function persist(): void
    {
        Cache::forever(self::CACHE_KEY, [
            'tools' => $this->tools,
            'agents' => $this->agents,
            'secrets' => $this->secrets,
            'webhooks' => $this->webhooks,
            'phone_numbers' => $this->phoneNumbers,
        ]);
    }

    private function id(string $prefix, string $seed): string
    {
        return $prefix.'_'.substr(hash('sha256', $prefix.':'.$seed), 0, 24);
    }

    /**
     * @param  array<string, mixed>  $collection
     */
    private function mustExist(array $collection, string $id, string $kind): void
    {
        if (! isset($collection[$id])) {
            throw new ElevenLabsException(
                sprintf('No %s with id "%s". It was deleted, or it belongs to another workspace.', $kind, $id),
                404,
            );
        }
    }
}
