<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConversationDirection;
use App\Enums\ConversationOutcome;
use Carbon\CarbonImmutable;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One call.
 *
 * Created either by the post-call webhook, or earlier — the first time an agent
 * tool call arrives carrying a conversation id we have not seen before. Both
 * paths go through firstOrCreate on `elevenlabs_conversation_id`, which is
 * unique, so the two racing is harmless.
 *
 * @property int $id
 * @property int $restaurant_id
 * @property string $elevenlabs_conversation_id
 * @property string|null $elevenlabs_agent_id
 * @property ConversationDirection $direction
 * @property string|null $caller_number
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $ended_at
 * @property int|null $duration_seconds
 * @property array<int, mixed>|null $transcript
 * @property array<string, mixed>|null $analysis
 * @property string|null $audio_url
 * @property string|null $audio_path
 * @property ConversationOutcome $outcome
 * @property bool $needs_review
 * @property string|null $review_reason
 * @property CarbonImmutable|null $reviewed_at
 * @property int|null $cost Minor units
 * @property int|null $cost_credits
 * @property array<string, mixed>|null $raw_payload
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Restaurant $restaurant
 * @property-read Order|null $order
 *
 * @method static ConversationFactory factory($count = null, $state = [])
 */
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => ConversationDirection::class,
            'outcome' => ConversationOutcome::class,
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'duration_seconds' => 'integer',
            'transcript' => 'array',
            'analysis' => 'array',
            'raw_payload' => 'array',
            'needs_review' => 'boolean',
            'cost' => 'integer',
            'cost_credits' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Restaurant, $this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * The order this call produced, if it produced one.
     *
     * @return HasOne<Order, $this>
     */
    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }

    /**
     * @param  Builder<Conversation>  $query
     */
    public function scopeFlagged(Builder $query): void
    {
        $query->where('needs_review', true)->whereNull('reviewed_at');
    }

    /**
     * @param  Builder<Conversation>  $query
     */
    public function scopeWithoutOrder(Builder $query): void
    {
        $query->doesntHave('order');
    }

    public function producedAnOrder(): bool
    {
        return $this->order()->exists();
    }

    /**
     * Flag this call for a human to look at.
     *
     * The reason is stored rather than recomputed so the dashboard can show
     * *why* something was flagged even after the rules for flagging change.
     */
    public function flagForReview(string $reason): void
    {
        $this->forceFill([
            'needs_review' => true,
            'review_reason' => $reason,
        ])->save();
    }

    public function markReviewed(): void
    {
        $this->forceFill([
            'needs_review' => false,
            'reviewed_at' => CarbonImmutable::now(),
        ])->save();
    }

    /**
     * Turn-by-turn transcript as plain text, for reading in the dashboard or
     * diffing in an eval.
     *
     * @return list<array{role: string, message: string}>
     */
    public function transcriptTurns(): array
    {
        $turns = [];

        foreach ($this->transcript ?? [] as $turn) {
            if (! is_array($turn)) {
                continue;
            }

            $role = is_string($turn['role'] ?? null) ? $turn['role'] : 'unknown';
            $message = is_string($turn['message'] ?? null) ? $turn['message'] : '';

            if ($message === '') {
                continue;
            }

            $turns[] = ['role' => $role, 'message' => $message];
        }

        return $turns;
    }

    public function durationForHumans(): string
    {
        $seconds = $this->duration_seconds ?? 0;

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
