<?php

declare(strict_types=1);

namespace App\Services\ElevenLabs;

use App\Models\Restaurant;

/**
 * Turns a row in `restaurants` into a working ElevenLabs agent.
 *
 * All the decisions live here, which is the deal `ElevenLabsClient` makes: the
 * client knows endpoints, this knows what a provisioned restaurant is.
 *
 * The property that matters most is that running it twice is the same as
 * running it once. A forker will run this after every change to the menu
 * routes, after every tone-of-voice edit, and once by accident; none of those
 * may leave a workspace with eighteen tools, two agents and two webhooks
 * pointing at the same URL. Everything created is remembered on the restaurant
 * row, and everything remembered is checked before it is reused — an id whose
 * resource was deleted in the dashboard is treated as absent rather than as a
 * fatal 404.
 *
 * The order is the dependency order and cannot be shuffled: the secret is
 * needed to define a tool, the tools are needed to define the agent, and the
 * webhook is attached to the agent as it is written.
 *
 * @see docs/DECISIONS.md #0040, #0041
 */
final class Provisioner
{
    public function __construct(private readonly ElevenLabsClient $client) {}

    public function provision(Restaurant $restaurant): ProvisioningReport
    {
        [$secretId, $secretAction] = $this->authorizationSecret($restaurant);
        [$toolIds, $toolActions] = $this->tools($restaurant, $secretId);
        [$webhookId, $webhookSecret, $webhookAction] = $this->webhook($restaurant);
        [$agentId, $agentAction] = $this->agent($restaurant, array_values($toolIds), $webhookId);

        $restaurant->forceFill([
            'elevenlabs_secret_id' => $secretId,
            'elevenlabs_tool_ids' => $toolIds,
            'elevenlabs_webhook_id' => $webhookId,
            'elevenlabs_agent_id' => $agentId,
            'provisioned_at' => now(),
        ])->save();

        return new ProvisioningReport(
            agentId: $agentId,
            agentAction: $agentAction,
            tools: $toolActions,
            secretId: $secretId,
            secretAction: $secretAction,
            webhookId: $webhookId,
            webhookAction: $webhookAction,
            webhookSecret: $webhookSecret,
            phoneNumberId: $restaurant->elevenlabs_phone_number_id,
        );
    }

    /**
     * Exactly what would be sent, without sending it.
     *
     * What `--dry-run` prints, and the only honest way to answer "what is my
     * agent actually being told?" — the system prompt is assembled from four
     * database columns and a lot of prose, and reading it in the file is not
     * the same as reading what came out.
     *
     * @return array{tools: array<string, array<string, mixed>>, agent: array<string, mixed>}
     */
    public function preview(Restaurant $restaurant): array
    {
        $secretId = $restaurant->elevenlabs_secret_id ?? '<secret created on the first real run>';

        $tools = (new ToolDefinitions($restaurant, $secretId))->all();

        return [
            'tools' => $tools,
            'agent' => $this->definition($restaurant)->payload(
                array_map(
                    fn (string $name): string => $restaurant->elevenlabs_tool_ids[$name] ?? '<new tool '.$name.'>',
                    array_keys($tools),
                ),
                $restaurant->elevenlabs_webhook_id ?? '<webhook created on the first real run>',
            ),
        ];
    }

    /**
     * Point a telephone number at the agent.
     *
     * Deliberately not part of `provision()`. Everything else in this file
     * edits a definition nobody is currently using; this one changes who
     * answers when a customer rings, and doing that as a side effect of a
     * routine re-provision is how a restaurant discovers at seven on a Friday
     * that its phone now goes somewhere else.
     *
     * The Twilio credentials come from the environment rather than from
     * arguments, because an account SID and auth token passed on a command line
     * end up in a shell history file.
     */
    public function attachPhoneNumber(Restaurant $restaurant, string $phoneNumber): string
    {
        $agentId = $restaurant->elevenlabs_agent_id;

        if ($agentId === null) {
            throw new ElevenLabsException('There is no agent to attach a number to yet. Provision first.');
        }

        $sid = (string) config('restaurantline.twilio.account_sid', '');
        $token = (string) config('restaurantline.twilio.auth_token', '');

        if ($sid === '' || $token === '') {
            throw new ElevenLabsException(
                'Importing a phone number needs TWILIO_ACCOUNT_SID and TWILIO_AUTH_TOKEN in the environment. '
                .'ElevenLabs holds them to answer calls on the number; they are not passed on the command line.',
            );
        }

        $id = $this->client->importTwilioNumber(
            phoneNumber: $phoneNumber,
            label: $restaurant->name,
            accountSid: $sid,
            authToken: $token,
            agentId: $agentId,
        );

        $restaurant->forceFill(['elevenlabs_phone_number_id' => $id])->save();

        return $id;
    }

    // -----------------------------------------------------------------------
    // The steps
    // -----------------------------------------------------------------------

    /**
     * The bearer token, stored once in the workspace and referenced by id.
     *
     * The alternative — pasting `AGENT_API_TOKEN` into nine tool definitions —
     * puts the key to this application's order endpoints in plaintext in nine
     * places in somebody else's dashboard, and makes rotating it a nine-step
     * job that is only ever done eight times.
     *
     * The stored value is the whole header, `Bearer <token>`, not the bare
     * token. A secret locator substitutes the entire header value rather than
     * interpolating into it, so the "Bearer " has to be inside the secret.
     *
     * The value is written on every run. We cannot read a secret back to
     * compare — that is the point of a secret store — so rotating the token in
     * `.env` and re-provisioning is the rotation procedure, and it works
     * because this overwrites rather than checks.
     *
     * @return array{string, string}
     */
    private function authorizationSecret(Restaurant $restaurant): array
    {
        $token = (string) config('restaurantline.agent.token', '');

        if ($token === '') {
            throw new ElevenLabsException(
                'AGENT_API_TOKEN is empty. The agent authenticates to this application with it, and '
                .'provisioning without one would register nine tools that can never call anything. '
                .'Generate one with: php -r "echo bin2hex(random_bytes(32));"',
            );
        }

        $name = $this->secretName($restaurant);
        $existing = $this->client->listSecrets()[$name] ?? null;

        if ($existing !== null) {
            $this->client->updateSecret($existing, $name, 'Bearer '.$token);

            return [$existing, 'updated'];
        }

        return [$this->client->createSecret($name, 'Bearer '.$token), 'created'];
    }

    /**
     * The nine tools, created or patched in place.
     *
     * Keyed by name throughout. The name is the identity as far as this
     * application is concerned — ElevenLabs' id is just how you address it —
     * which is why renaming a tool creates a new one and leaves the old behind.
     *
     * @return array{array<string, string>, array<string, string>}
     */
    private function tools(Restaurant $restaurant, string $secretId): array
    {
        $known = $restaurant->elevenlabs_tool_ids ?? [];

        $ids = [];
        $actions = [];

        foreach ((new ToolDefinitions($restaurant, $secretId))->all() as $name => $config) {
            $id = $known[$name] ?? null;

            if ($id !== null && $this->client->getTool($id) !== null) {
                $ids[$name] = $this->client->updateTool($id, $config);
                $actions[$name] = 'updated';

                continue;
            }

            $ids[$name] = $this->client->createTool($config);
            $actions[$name] = 'created';
        }

        return [$ids, $actions];
    }

    /**
     * The post-call webhook, matched on its URL.
     *
     * Matched on the URL and not on the remembered id, because the failure this
     * guards against is a second webhook posting every call to the same
     * endpoint — which produces two of every conversation record and is
     * tiresome to unpick. Two webhooks with the same URL are the same webhook
     * as far as this application is concerned, whoever made them.
     *
     * @return array{?string, ?string, string}
     */
    private function webhook(Restaurant $restaurant): array
    {
        $url = route('webhooks.elevenlabs');

        $existing = $this->client->listWebhooks()[$url] ?? null;

        if ($existing !== null) {
            return [$existing, null, 'existing'];
        }

        $created = $this->client->createWebhook(
            sprintf('restaurantline — %s post-call', $restaurant->name),
            $url,
        );

        return [$created['webhook_id'], $created['webhook_secret'], 'created'];
    }

    /**
     * @param  list<string>  $toolIds
     * @return array{string, string}
     */
    private function agent(Restaurant $restaurant, array $toolIds, ?string $webhookId): array
    {
        $payload = $this->definition($restaurant)->payload($toolIds, $webhookId);

        $id = $restaurant->elevenlabs_agent_id;

        if ($id !== null && $this->client->getAgent($id) !== null) {
            $this->client->updateAgent($id, $payload);

            return [$id, 'updated'];
        }

        return [$this->client->createAgent($payload), 'created'];
    }

    private function definition(Restaurant $restaurant): AgentDefinition
    {
        return new AgentDefinition($restaurant, new AgentPrompt($restaurant));
    }

    /**
     * Scoped to the restaurant so a workspace can hold several, and stripped to
     * word characters because a secret name is an identifier, not a label.
     */
    private function secretName(Restaurant $restaurant): string
    {
        return 'restaurantline_'.preg_replace('/[^a-z0-9]+/i', '_', $restaurant->slug).'_authorization';
    }
}
