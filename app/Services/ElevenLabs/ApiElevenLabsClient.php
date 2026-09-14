<?php

declare(strict_types=1);

namespace App\Services\ElevenLabs;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The real thing. Switched on with ELEVENLABS_DRIVER=api.
 *
 * One header, one base URL, one error path. The header is `xi-api-key` — not
 * `Authorization: Bearer`, whatever some of the rendered API-reference pages
 * say; the authentication page and the OpenAPI document agree with each other
 * and not with those pages. See docs/DECISIONS.md #0040.
 *
 * Every method here is called by a person running an Artisan command and
 * watching the output, never by a phone call in progress. That shapes the error
 * handling: failures throw with ElevenLabs' own words attached, because the one
 * useful thing in a failed provision is usually a 422 naming the single
 * malformed property out of nine tools.
 */
final class ApiElevenLabsClient implements ElevenLabsClient
{
    public function name(): string
    {
        return 'api';
    }

    // -----------------------------------------------------------------------
    // Tools
    // -----------------------------------------------------------------------

    public function createTool(array $config): string
    {
        return $this->id($this->post('/v1/convai/tools', ['tool_config' => $config]), 'id');
    }

    public function updateTool(string $toolId, array $config): string
    {
        return $this->id(
            $this->patch('/v1/convai/tools/'.rawurlencode($toolId), ['tool_config' => $config]),
            'id',
        );
    }

    public function getTool(string $toolId): ?array
    {
        return $this->getOrNull('/v1/convai/tools/'.rawurlencode($toolId));
    }

    // -----------------------------------------------------------------------
    // Agents
    // -----------------------------------------------------------------------

    public function createAgent(array $payload): string
    {
        return $this->id($this->post('/v1/convai/agents/create', $payload), 'agent_id');
    }

    public function updateAgent(string $agentId, array $payload): void
    {
        $this->patch('/v1/convai/agents/'.rawurlencode($agentId), $payload);
    }

    public function getAgent(string $agentId): ?array
    {
        return $this->getOrNull('/v1/convai/agents/'.rawurlencode($agentId));
    }

    // -----------------------------------------------------------------------
    // Secrets
    // -----------------------------------------------------------------------

    public function createSecret(string $name, string $value): string
    {
        return $this->id(
            $this->post('/v1/convai/secrets', ['type' => 'new', 'name' => $name, 'value' => $value]),
            'secret_id',
        );
    }

    public function updateSecret(string $secretId, string $name, string $value): void
    {
        $this->patch('/v1/convai/secrets/'.rawurlencode($secretId), [
            'type' => 'update',
            'name' => $name,
            'value' => $value,
        ]);
    }

    public function listSecrets(): array
    {
        $secrets = $this->send('GET', '/v1/convai/secrets')['secrets'] ?? [];

        $map = [];

        foreach (is_array($secrets) ? $secrets : [] as $secret) {
            if (is_array($secret) && is_string($secret['name'] ?? null) && is_string($secret['secret_id'] ?? null)) {
                $map[$secret['name']] = $secret['secret_id'];
            }
        }

        return $map;
    }

    // -----------------------------------------------------------------------
    // Webhooks
    // -----------------------------------------------------------------------

    public function createWebhook(string $name, string $url): array
    {
        $payload = $this->post('/v1/workspace/webhooks', [
            'settings' => ['auth_type' => 'hmac', 'name' => $name, 'webhook_url' => $url],
        ]);

        $secret = $payload['webhook_secret'] ?? null;

        return [
            'webhook_id' => $this->id($payload, 'webhook_id'),
            'webhook_secret' => is_string($secret) && $secret !== '' ? $secret : null,
        ];
    }

    public function listWebhooks(): array
    {
        $webhooks = $this->send('GET', '/v1/workspace/webhooks')['webhooks'] ?? [];

        $map = [];

        foreach (is_array($webhooks) ? $webhooks : [] as $webhook) {
            if (is_array($webhook) && is_string($webhook['webhook_url'] ?? null) && is_string($webhook['webhook_id'] ?? null)) {
                $map[$webhook['webhook_url']] = $webhook['webhook_id'];
            }
        }

        return $map;
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
        return $this->id($this->post('/v1/convai/phone-numbers', array_filter([
            'provider' => 'twilio',
            'phone_number' => $phoneNumber,
            'label' => $label,
            'sid' => $accountSid,
            'token' => $authToken,
            'agent_id' => $agentId,
        ], fn (?string $value): bool => $value !== null)), 'phone_number_id');
    }

    // -----------------------------------------------------------------------
    // Plumbing
    // -----------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        return $this->send('POST', $path, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function patch(string $path, array $payload): array
    {
        return $this->send('PATCH', $path, $payload);
    }

    /**
     * A GET whose 404 is an answer rather than a failure.
     *
     * Used where an id we remembered may have been deleted in the dashboard
     * since. Every other status still throws — a 401 must not be quietly read
     * as "that tool does not exist", which would make provisioning cheerfully
     * recreate all nine against a workspace it has no access to.
     *
     * @return array<string, mixed>|null
     */
    private function getOrNull(string $path): ?array
    {
        $response = $this->request()->get($this->url($path));

        if ($response->status() === 404) {
            return null;
        }

        return $this->decode($response, 'GET', $path);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?array $payload = null): array
    {
        try {
            $response = $this->request()->send($method, $this->url($path), $payload === null ? [] : ['json' => $payload]);
        } catch (ConnectionException $exception) {
            throw new ElevenLabsException('Could not reach ElevenLabs: '.$exception->getMessage());
        }

        return $this->decode($response, $method, $path);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response, string $method, string $path): array
    {
        if ($response->failed()) {
            throw new ElevenLabsException(
                sprintf('%s %s returned HTTP %d. %s', $method, $path, $response->status(), $this->reason($response)),
                $response->status(),
                $response->body(),
            );
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    /**
     * ElevenLabs' own explanation, dug out of the two shapes it arrives in.
     *
     * A FastAPI validation error is `detail` as a list of per-field objects; a
     * business rejection is `detail.message` or a bare string. Anything else
     * falls back to the raw body, truncated — a wall of HTML from a proxy is
     * still more useful than "request failed".
     */
    private function reason(Response $response): string
    {
        $detail = $response->json('detail');

        if (is_string($detail) && $detail !== '') {
            return $detail;
        }

        if (is_array($detail)) {
            if (is_string($detail['message'] ?? null)) {
                return $detail['message'];
            }

            $lines = [];

            foreach ($detail as $item) {
                if (is_array($item) && is_string($item['msg'] ?? null)) {
                    $location = is_array($item['loc'] ?? null)
                        ? implode('.', array_map(strval(...), $item['loc']))
                        : '';

                    $lines[] = trim($location.' '.$item['msg']);
                }
            }

            if ($lines !== []) {
                return implode('; ', $lines);
            }
        }

        return str($response->body())->limit(400)->toString();
    }

    private function request(): PendingRequest
    {
        $key = (string) config('restaurantline.elevenlabs.api_key', '');

        if ($key === '') {
            throw new ElevenLabsException(
                'ELEVENLABS_DRIVER is set to "api" but ELEVENLABS_API_KEY is empty. '
                .'Add a key, or leave the driver as "fake" to see what provisioning would send.',
            );
        }

        return Http::withHeaders(['xi-api-key' => $key])
            ->acceptJson()
            ->timeout((int) config('restaurantline.elevenlabs.timeout', 30));
    }

    private function url(string $path): string
    {
        return rtrim((string) config('restaurantline.elevenlabs.base_url', 'https://api.elevenlabs.io'), '/').$path;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function id(array $payload, string $key): string
    {
        $id = $payload[$key] ?? null;

        if (! is_string($id) || $id === '') {
            throw new ElevenLabsException(
                sprintf('ElevenLabs accepted the request but returned no "%s".', $key),
            );
        }

        return $id;
    }
}
