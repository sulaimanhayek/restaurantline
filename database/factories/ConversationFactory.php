<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ConversationDirection;
use App\Enums\ConversationOutcome;
use App\Models\Conversation;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = now()->subMinutes($this->faker->numberBetween(5, 600));
        $duration = $this->faker->numberBetween(45, 240);

        return [
            'restaurant_id' => Restaurant::factory(),
            'elevenlabs_conversation_id' => 'conv_'.Str::lower(Str::random(24)),
            'elevenlabs_agent_id' => 'agent_'.Str::lower(Str::random(24)),
            'direction' => ConversationDirection::Inbound,
            'caller_number' => '+4477'.$this->faker->numerify('########'),
            'started_at' => $startedAt,
            'ended_at' => $startedAt->copy()->addSeconds($duration),
            'duration_seconds' => $duration,
            'transcript' => [
                ['role' => 'agent', 'message' => 'Thanks for calling. Is this delivery or collection?'],
                ['role' => 'user', 'message' => 'Delivery please.'],
            ],
            'analysis' => null,
            'audio_url' => null,
            'audio_path' => null,
            'outcome' => ConversationOutcome::Pending,
            'needs_review' => false,
            'review_reason' => null,
            'reviewed_at' => null,
            'cost' => null,
            'cost_credits' => null,
            'raw_payload' => null,
        ];
    }

    public function withOutcome(ConversationOutcome $outcome): self
    {
        return $this->state(fn (): array => ['outcome' => $outcome]);
    }

    /**
     * A call that ended without an order. These are the ones worth reading.
     */
    public function abandoned(): self
    {
        return $this->state(fn (): array => [
            'outcome' => ConversationOutcome::OrderAbandoned,
            'needs_review' => true,
            'review_reason' => 'Call ended before the order was confirmed.',
        ]);
    }

    public function flagged(string $reason = 'Needs a human look'): self
    {
        return $this->state(fn (): array => [
            'needs_review' => true,
            'review_reason' => $reason,
        ]);
    }

    public function inProgress(): self
    {
        return $this->state(fn (): array => [
            'ended_at' => null,
            'duration_seconds' => null,
            'outcome' => ConversationOutcome::Pending,
        ]);
    }
}
