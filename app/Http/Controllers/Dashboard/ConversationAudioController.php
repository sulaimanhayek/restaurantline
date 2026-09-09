<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Models\Conversation;
use App\Models\Restaurant;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a call recording to the dashboard's audio player.
 *
 * Recordings live on the private disk, not in public storage. They are
 * customers saying their address and phone number out loud, and a guessable
 * public URL for that is a data breach waiting for someone to notice the
 * pattern. Everything reaching this controller has been through the panel's
 * auth middleware, and the tenant check below stops an authenticated user of
 * one restaurant reading another's calls by changing the id in the URL.
 */
class ConversationAudioController
{
    public function __invoke(Conversation $conversation): StreamedResponse
    {
        abort_unless($conversation->restaurant_id === Restaurant::current()->id, 404);

        $path = $conversation->audio_path;

        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        // Streamed rather than downloaded: the player wants to seek, and a
        // fifteen-minute call is not something to hold in memory.
        return Storage::disk('local')->response($path, null, [
            'Content-Type' => 'audio/mpeg',
        ]);
    }
}
