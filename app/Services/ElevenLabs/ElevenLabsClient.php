<?php

declare(strict_types=1);

namespace App\Services\ElevenLabs;

/**
 * Everything this application asks of the ElevenLabs Agents API.
 *
 * There is no official PHP SDK, so this is the whole surface: one interface,
 * two implementations, and no other file in the repo makes an HTTP call to
 * ElevenLabs. A forker who wants to know what we send them reads this and then
 * `ApiElevenLabsClient`, and nothing else.
 *
 * Deliberately thin. The methods mirror endpoints rather than intentions —
 * "create a tool", not "provision a restaurant" — because the moment this
 * interface starts making decisions is the moment the fake stops being able to
 * stand in for it. The decisions live in `Provisioner`.
 *
 * Every method throws ElevenLabsException on a non-2xx. Provisioning is a thing
 * a human runs and watches; there is nothing useful to do with a half-applied
 * change except stop and say where it stopped.
 *
 * @see docs/DECISIONS.md #0006, #0040
 */
interface ElevenLabsClient
{
    /**
     * `fake` or `api`, for anything that needs to say which one is loaded.
     */
    public function name(): string;

    // -----------------------------------------------------------------------
    // Tools
    // -----------------------------------------------------------------------

    /**
     * POST /v1/convai/tools — returns the new tool's id.
     *
     * @param  array<string, mixed>  $config  A `tool_config` object; the wrapper is this method's job.
     */
    public function createTool(array $config): string;

    /**
     * PATCH /v1/convai/tools/{tool_id} — returns the id, for symmetry.
     *
     * @param  array<string, mixed>  $config
     */
    public function updateTool(string $toolId, array $config): string;

    /**
     * GET /v1/convai/tools/{tool_id}, or null if it is gone.
     *
     * Provisioning calls this before patching a remembered id: a tool deleted
     * in the dashboard leaves an id in our database that would otherwise make
     * every subsequent run fail on a 404.
     *
     * @return array<string, mixed>|null
     */
    public function getTool(string $toolId): ?array;

    // -----------------------------------------------------------------------
    // Agents
    // -----------------------------------------------------------------------

    /**
     * POST /v1/convai/agents/create — returns the new agent's id.
     *
     * Note the path. It is not a POST to the collection.
     *
     * @param  array<string, mixed>  $payload  name, conversation_config, platform_settings, tags
     */
    public function createAgent(array $payload): string;

    /**
     * PATCH /v1/convai/agents/{agent_id}. A genuine partial patch.
     *
     * @param  array<string, mixed>  $payload
     */
    public function updateAgent(string $agentId, array $payload): void;

    /**
     * GET /v1/convai/agents/{agent_id}, or null if it is gone.
     *
     * @return array<string, mixed>|null
     */
    public function getAgent(string $agentId): ?array;

    // -----------------------------------------------------------------------
    // Secrets
    // -----------------------------------------------------------------------

    /**
     * POST /v1/convai/secrets — returns the `secret_id`.
     *
     * Workspace secret names are not unique, so provisioning looks through
     * `listSecrets()` first rather than creating a second copy of the same
     * token on every run.
     */
    public function createSecret(string $name, string $value): string;

    /**
     * PATCH /v1/convai/secrets/{secret_id} — replaces the stored value.
     *
     * What makes rotating AGENT_API_TOKEN a one-line change. Without it, a new
     * token would mean a new secret and nine tools re-pointed at it; with it,
     * the id in `restaurants.elevenlabs_secret_id` keeps meaning the same
     * thing and the tools never move.
     */
    public function updateSecret(string $secretId, string $name, string $value): void;

    /**
     * GET /v1/convai/secrets, as a map of name => secret_id.
     *
     * Values are never returned by the API, which is the point of a secret
     * store; only the name and the id come back.
     *
     * @return array<string, string>
     */
    public function listSecrets(): array;

    // -----------------------------------------------------------------------
    // Webhooks
    // -----------------------------------------------------------------------

    /**
     * POST /v1/workspace/webhooks.
     *
     * The `webhook_secret` in the return is the HMAC key, and it comes back
     * **once**. Whatever calls this either shows it to a human immediately or
     * loses it.
     *
     * @return array{webhook_id: string, webhook_secret: string|null}
     */
    public function createWebhook(string $name, string $url): array;

    /**
     * GET /v1/workspace/webhooks, as a map of webhook_url => webhook_id.
     *
     * Keyed by URL rather than name because the URL is what makes two webhooks
     * the same thing; a name is a label a person can change.
     *
     * @return array<string, string>
     */
    public function listWebhooks(): array;

    // -----------------------------------------------------------------------
    // Phone numbers
    // -----------------------------------------------------------------------

    /**
     * POST /v1/convai/phone-numbers — imports a Twilio number and, in the same
     * call, points it at an agent. Returns the `phone_number_id`.
     *
     * This is the one method here that changes who answers a phone customers
     * are ringing. Its caller asks first.
     */
    public function importTwilioNumber(
        string $phoneNumber,
        string $label,
        string $accountSid,
        string $authToken,
        ?string $agentId = null,
    ): string;
}
