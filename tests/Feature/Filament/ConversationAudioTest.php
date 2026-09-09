<?php

declare(strict_types=1);

use App\Models\Conversation;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Call recordings
|--------------------------------------------------------------------------
|
| A recording is a customer reading out their address and phone number. It is
| the most sensitive thing this application stores, and the only one that
| cannot be regenerated. These tests exist so nobody makes it public by
| accident — see docs/DECISIONS.md #0032.
|
*/

beforeEach(function (): void {
    $this->restaurant = restaurant();
    Storage::fake('local');
});

function recordedCall(Restaurant $restaurant, string $path = 'call-recordings/conv-1.mp3'): Conversation
{
    Storage::disk('local')->put($path, 'not really audio');

    return Conversation::factory()->for($restaurant)->create(['audio_path' => $path]);
}

it('will not serve a recording to a guest', function (): void {
    $conversation = recordedCall($this->restaurant);

    get(route('conversations.audio', ['conversation' => $conversation]))
        ->assertRedirect('/admin/login');
});

it('serves a recording to a signed-in user', function (): void {
    actingAs(User::factory()->create(['restaurant_id' => $this->restaurant->id]));
    $conversation = recordedCall($this->restaurant);

    get(route('conversations.audio', ['conversation' => $conversation]))
        ->assertOk()
        ->assertHeader('content-type', 'audio/mpeg');
});

it('will not serve another restaurant\'s recording', function (): void {
    actingAs(User::factory()->create(['restaurant_id' => $this->restaurant->id]));
    $theirs = recordedCall(Restaurant::factory()->create(), 'call-recordings/theirs.mp3');

    get(route('conversations.audio', ['conversation' => $theirs]))->assertNotFound();
});

it('404s when the file is gone', function (): void {
    actingAs(User::factory()->create(['restaurant_id' => $this->restaurant->id]));
    $conversation = Conversation::factory()->for($this->restaurant)
        ->create(['audio_path' => 'call-recordings/never-downloaded.mp3']);

    get(route('conversations.audio', ['conversation' => $conversation]))->assertNotFound();
});

it('does not write recordings anywhere the web server would serve them', function (): void {
    $conversation = recordedCall($this->restaurant);

    expect($conversation->audio_path)->not->toStartWith('public/')
        ->and(config('filesystems.disks.local.url'))->toBeNull();
});

describe('what the review screen links to', function (): void {
    it('prefers the downloaded copy over the provider URL', function (): void {
        $conversation = recordedCall($this->restaurant);
        $conversation->update(['audio_url' => 'https://storage.elevenlabs.io/expires-in-a-week.mp3']);

        expect($conversation->audioSource())
            ->toBe(route('conversations.audio', ['conversation' => $conversation]));
    });

    it('falls back to the provider URL when nothing was downloaded', function (): void {
        $conversation = Conversation::factory()->for($this->restaurant)->create([
            'audio_path' => null,
            'audio_url' => 'https://storage.elevenlabs.io/expires-in-a-week.mp3',
        ]);

        expect($conversation->audioSource())->toBe('https://storage.elevenlabs.io/expires-in-a-week.mp3');
    });

    it('offers no player when there is no recording at all', function (): void {
        $conversation = Conversation::factory()->for($this->restaurant)
            ->create(['audio_path' => null, 'audio_url' => null]);

        expect($conversation->audioSource())->toBeNull();
    });
});
