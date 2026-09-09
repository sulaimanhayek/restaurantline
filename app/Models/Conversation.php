<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Enums\ConversationDirection;
use App\Enums\ConversationOutcome;
use Carbon\CarbonImmutable;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

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
     * Mirrors the column defaults, so a row means the same thing in memory as
     * it does once the database has seen it.
     *
     * Without this, a model from `firstOrCreate` comes back with these
     * attributes unset. The database fills them in on insert; the instance does
     * not know that, so `$conversation->outcome` reads null on exactly the path
     * that matters — a call that never reached an agent tool and gets its row
     * from the post-call webhook instead. The `@property` block above promises
     * a ConversationOutcome, and this is what makes that true.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'direction' => 'inbound',
        'outcome' => 'pending',
        'needs_review' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => ConversationDirection::class,
            'outcome' => ConversationOutcome::class,
            'started_at' => UtcDateTime::class,
            'ended_at' => UtcDateTime::class,
            'reviewed_at' => UtcDateTime::class,
            'duration_seconds' => 'integer',
            'transcript' => 'array',
            'analysis' => 'array',
            'raw_payload' => 'array',
            'needs_review' => 'boolean',
            'cost' => 'integer',
            'cost_credits' => 'integer',
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
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
     * `at` is seconds into the call, and is null on older rows and on any
     * provider payload that omits it — the dashboard uses it to seek the audio
     * to a turn, and simply does not offer that on turns without it.
     *
     * @return list<array{role: string, message: string, at: int|null}>
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

            $at = $turn['time_in_call_secs'] ?? null;

            $turns[] = [
                'role' => $role,
                'message' => $message,
                'at' => is_numeric($at) ? (int) $at : null,
            ];
        }

        return $turns;
    }

    /**
     * A link the dashboard can put in an <audio> tag, or null if there is no
     * recording.
     *
     * Prefers the copy this install downloaded over the provider's URL: the
     * provider's expires, and a review screen that plays for a week and then
     * silently stops is worse than one that never offered playback.
     */
    public function audioSource(): ?string
    {
        if ($this->audio_path !== null && Storage::disk('local')->exists($this->audio_path)) {
            return route('conversations.audio', ['conversation' => $this->id]);
        }

        return $this->audio_url;
    }

    public function durationForHumans(): string
    {
        $seconds = $this->duration_seconds ?? 0;

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
