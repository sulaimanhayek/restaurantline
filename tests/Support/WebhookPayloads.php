<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Post-call webhook payloads, shaped as the platform documents them.
 *
 * Field names verified against the ElevenLabs documentation on 2026-09-07 —
 * `metadata.start_time_unix_secs`, `metadata.call_duration_secs`,
 * `metadata.cost`, `analysis.call_successful`,
 * `analysis.evaluation_criteria_results`, `analysis.transcript_summary`, and
 * the caller's number as the `system__caller_id` dynamic variable rather than a
 * top-level field.
 *
 * Kept in one place because the value of these tests is entirely in the field
 * names being right. A fixture that quietly invents `caller_number` would pass
 * every test here and drop the caller's number on the floor in production.
 */
final class WebhookPayloads
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function transcription(string $conversationId = 'conv-1', array $overrides = []): array
    {
        return [
            'type' => 'post_call_transcription',
            'event_timestamp' => now()->getTimestamp(),
            'data' => array_replace([
                'agent_id' => 'agent_abc123',
                'agent_name' => 'Ember Kitchen',
                'conversation_id' => $conversationId,
                'status' => 'done',
                'transcript' => [
                    ['role' => 'agent', 'message' => 'Ember Kitchen, how can I help?', 'time_in_call_secs' => 1],
                    ['role' => 'user', 'message' => 'Two chicken burgers for collection please.', 'time_in_call_secs' => 6],
                ],
                'metadata' => [
                    'start_time_unix_secs' => 1_788_000_000,
                    'call_duration_secs' => 94,
                    'cost' => 320,
                    'termination_reason' => 'end_call_tool',
                ],
                'analysis' => [
                    'call_successful' => 'success',
                    'transcript_summary' => 'Caller ordered two chicken burgers for collection.',
                    'evaluation_criteria_results' => [],
                    'data_collection_results' => [],
                ],
                'conversation_initiation_client_data' => [
                    'dynamic_variables' => [
                        'system__caller_id' => '+447700900123',
                        'system__called_number' => '+442079460000',
                    ],
                ],
                'has_audio' => true,
            ], $overrides),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function audio(string $conversationId = 'conv-1', string $audio = 'not-really-an-mp3'): array
    {
        return [
            'type' => 'post_call_audio',
            'event_timestamp' => now()->getTimestamp(),
            'data' => [
                'agent_id' => 'agent_abc123',
                'conversation_id' => $conversationId,
                'full_audio' => base64_encode($audio),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function initiationFailure(string $conversationId = 'conv-1', string $reason = 'no-answer'): array
    {
        return [
            'type' => 'call_initiation_failure',
            'event_timestamp' => now()->getTimestamp(),
            'data' => [
                'agent_id' => 'agent_abc123',
                'conversation_id' => $conversationId,
                'failure_reason' => $reason,
                'metadata' => [
                    'type' => 'twilio',
                    'body' => ['CallSid' => 'CA123', 'CallStatus' => 'no-answer'],
                ],
            ],
        ];
    }
}
