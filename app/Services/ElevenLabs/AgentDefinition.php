<?php

declare(strict_types=1);

namespace App\Services\ElevenLabs;

use App\Models\Restaurant;

/**
 * The agent itself, as ElevenLabs wants it described.
 *
 * The same payload creates and updates: `POST /v1/convai/agents/create` takes
 * it whole, and `PATCH /v1/convai/agents/{id}` is a genuine partial patch that
 * accepts the same keys. Building one object for both is what keeps the second
 * provisioning run from being a different thing from the first.
 *
 * Almost everything here is left at ElevenLabs' default on purpose. The
 * settings that are set are the ones where the default is wrong for a
 * restaurant phone line specifically, and each says why.
 *
 * @see docs/DECISIONS.md #0041
 */
final class AgentDefinition
{
    /**
     * Which post-call events we want.
     *
     * `transcript` is the one the webhook actually processes — it carries the
     * conversation, the analysis and the tool calls. `audio` is what makes a
     * disputed order settleable. `call_initiation_failure` is the only way to
     * find out that callers have been getting silence, which is otherwise
     * invisible: a call that never starts produces no transcript to notice.
     */
    private const WEBHOOK_EVENTS = ['transcript', 'audio', 'call_initiation_failure'];

    public function __construct(
        private readonly Restaurant $restaurant,
        private readonly AgentPrompt $prompt,
    ) {}

    /**
     * The name in the ElevenLabs dashboard.
     *
     * Prefixed, because a workspace running three restaurants is three agents
     * in one list and "Ember Grill" alone does not say which of the several
     * things in that dashboard it belongs to.
     */
    public function name(): string
    {
        return sprintf('restaurantline — %s', $this->restaurant->name);
    }

    /**
     * @param  list<string>  $toolIds
     * @return array<string, mixed>
     */
    public function payload(array $toolIds, ?string $webhookId = null): array
    {
        return [
            'name' => $this->name(),
            'tags' => ['restaurantline', $this->restaurant->slug],
            'conversation_config' => $this->conversationConfig($toolIds),
            'platform_settings' => $this->platformSettings($webhookId),
        ];
    }

    /**
     * @param  list<string>  $toolIds
     * @return array<string, mixed>
     */
    private function conversationConfig(array $toolIds): array
    {
        return [
            'agent' => [
                'first_message' => $this->prompt->firstMessage(),
                'language' => (string) config('restaurantline.elevenlabs.language', 'en'),
                'prompt' => [
                    'prompt' => $this->prompt->systemPrompt(),
                    'llm' => (string) config('restaurantline.elevenlabs.llm', 'gpt-4o-mini'),
                    'tool_ids' => $toolIds,
                    'built_in_tools' => $this->builtInTools(),
                    // The restaurant's own clock, so "we close at eleven" means
                    // eleven where the restaurant is and not where the model
                    // thinks it is.
                    'timezone' => $this->restaurant->timezone,
                ],
            ],
            'tts' => $this->tts(),
            'conversation' => [
                /*
                 * Ten minutes. A phone order takes three. This is not a
                 * feature, it is the ceiling on what a stuck conversation can
                 * cost — an agent in a loop with a silent line bills by the
                 * minute until something stops it.
                 */
                'max_duration_seconds' => (int) config('restaurantline.elevenlabs.max_call_seconds', 600),
            ],
        ];
    }

    /**
     * The platform's own tools, as opposed to ours.
     *
     * `end_call` so a finished conversation ends rather than sitting open on a
     * meter. `transfer_to_number` only when the restaurant has given us a
     * number to transfer to — offering a transfer that goes nowhere is worse
     * than not offering one, and `escalate_to_human` still works either way.
     *
     * @return array<string, mixed>
     */
    private function builtInTools(): array
    {
        $tools = [
            'end_call' => [
                'name' => 'end_call',
                'description' => 'End the call once the caller has what they need and has said goodbye. '
                    .'Do not use it to get out of a difficult conversation — that is what escalate_to_human is for.',
                'params' => ['system_tool_type' => 'end_call'],
            ],
        ];

        $number = trim((string) $this->restaurant->transfer_phone_number);

        if ($number !== '') {
            $tools['transfer_to_number'] = [
                'name' => 'transfer_to_number',
                'description' => 'Put the caller through to the restaurant.',
                'params' => [
                    'system_tool_type' => 'transfer_to_number',
                    'transfers' => [[
                        'transfer_destination' => ['type' => 'phone', 'phone_number' => $number],
                        'condition' => 'The caller has asked to speak to a person, is unhappy, or wants '
                            .'something this agent has no tool for. Always call escalate_to_human as well, '
                            .'so the restaurant has a record of it whether or not anybody picks up.',
                    ]],
                ],
            ];
        }

        return $tools;
    }

    /**
     * @return array<string, mixed>
     */
    private function tts(): array
    {
        $tts = [
            /*
             * Flash, because latency is the whole experience on a phone call.
             * A caller reads a half-second gap as "it didn't hear me" and
             * starts repeating themselves, and two people talking at once is
             * the failure mode this design can least afford.
             */
            'model_id' => (string) config('restaurantline.elevenlabs.tts_model', 'eleven_flash_v2_5'),
        ];

        $voice = trim((string) config('restaurantline.elevenlabs.voice_id', ''));

        // Left to the workspace default when unset, which is a real voice and
        // sounds fine. Picking one is a thing a person does with their ears.
        if ($voice !== '') {
            $tts['voice_id'] = $voice;
        }

        return $tts;
    }

    /**
     * @return array<string, mixed>
     */
    private function platformSettings(?string $webhookId): array
    {
        $settings = [];

        /*
         * Per agent rather than per workspace. `PATCH /v1/convai/settings`
         * would set this once for everything in the account, which is fine for
         * one restaurant and wrong the moment there are two — and the schema
         * here has been shaped for two since the first migration. The same
         * object, on a field that belongs to this agent.
         *
         * @see docs/DECISIONS.md #0005, #0040
         */
        if ($webhookId !== null) {
            $settings['workspace_overrides'] = [
                'webhooks' => [
                    'post_call_webhook_id' => $webhookId,
                    'events' => self::WEBHOOK_EVENTS,
                ],
            ];
        }

        return $settings;
    }
}
