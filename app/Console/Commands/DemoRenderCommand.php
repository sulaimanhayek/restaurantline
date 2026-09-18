<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Restaurant;
use App\Services\Demo\ConversationRenderer;
use App\Services\Demo\DemoRenderException;
use App\Services\ElevenLabs\ElevenLabsClient;
use App\Services\ElevenLabs\ElevenLabsException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Turn a conversation that already happened into something you can play.
 *
 * Demo tooling. It is not part of taking an order, nothing in the ordering
 * flow calls it, and it never runs while a caller is on the line — it reads a
 * stored transcript and asks ElevenLabs to speak it. The distinction matters
 * because this project deliberately does not handle call audio, and a command
 * that writes a .wav could easily be mistaken for the beginning of doing so.
 *
 * Where the transcripts come from:
 *
 *  - `kitchenline:eval --mode=live`, which has a real model play the caller
 *    against the real agent, with real tool calls landing real orders.
 *  - Any real call, stored by the post-call webhook.
 *  - `php artisan migrate --seed`, which seeds five demo conversations — which
 *    is what makes this runnable on a laptop with no account at all.
 *
 * With `ELEVENLABS_DRIVER=fake` the audio is silence of believable length. The
 * command says so, loudly, every time, because the failure mode worth
 * preventing is finding out in front of a client.
 */
final class DemoRenderCommand extends Command
{
    protected $signature = 'kitchenline:demo:render
        {conversation? : ElevenLabs conversation id; defaults to the most recent one with a transcript}
        {--restaurant= : Slug of the restaurant, if this install has more than one}
        {--caller-voice= : ElevenLabs voice id for the caller}
        {--agent-voice= : ElevenLabs voice id for the agent}
        {--gap= : Seconds of silence between turns}
        {--out= : Write the file here instead of storage/app/demos}';

    protected $description = 'Render a stored conversation to a two-voice .wav you can play to a client';

    public function handle(ConversationRenderer $renderer, ElevenLabsClient $client): int
    {
        $conversation = $this->conversation();

        if ($conversation === null) {
            return self::FAILURE;
        }

        $turns = $conversation->transcriptTurns();

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Conversation</>', $conversation->elevenlabs_conversation_id);
        $this->components->twoColumnDetail('<fg=gray>Outcome</>', (string) $conversation->outcome->value);
        $this->components->twoColumnDetail('<fg=gray>Turns</>', (string) count($turns));
        $this->components->twoColumnDetail('<fg=gray>ElevenLabs driver</>', $client->name());
        $this->newLine();

        $voices = $this->voices($client);

        if ($voices === null) {
            return self::FAILURE;
        }

        [$callerVoice, $agentVoice] = $voices;

        try {
            $demo = $renderer->render(
                $turns,
                $callerVoice,
                $agentVoice,
                $this->gap(),
                function (int $index, int $total, string $role, string $message): void {
                    $this->components->twoColumnDetail(
                        sprintf('<fg=gray>%d/%d</> %s', $index, $total, $this->speaker($role)),
                        '<fg=gray>'.str($message)->limit(52)->toString().'</>',
                    );
                },
            );
        } catch (DemoRenderException|ElevenLabsException $exception) {
            $this->newLine();
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $path = $this->write($conversation, $demo->wav);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Length</>', $demo->describeLength());
        $this->components->twoColumnDetail('<fg=gray>Format</>', $demo->format);
        $this->components->twoColumnDetail('<fg=gray>Written to</>', $path);
        $this->newLine();

        if ($demo->silent) {
            $this->silentWarning();

            return self::SUCCESS;
        }

        $this->components->info('Done. Play it before you show it to anybody.');

        return self::SUCCESS;
    }

    // -----------------------------------------------------------------------

    private function conversation(): ?Conversation
    {
        $query = Conversation::query()->whereNotNull('transcript');

        $slug = $this->option('restaurant');

        if (is_string($slug) && $slug !== '') {
            $restaurant = Restaurant::query()->where('slug', $slug)->first();

            if ($restaurant === null) {
                $this->components->error(sprintf('No restaurant with the slug "%s".', $slug));

                return null;
            }

            $query->where('restaurant_id', $restaurant->id);
        }

        $id = $this->argument('conversation');

        if (is_string($id) && $id !== '') {
            $found = $query->where('elevenlabs_conversation_id', $id)->first();

            if ($found === null) {
                $this->components->error(sprintf(
                    'No conversation "%s" with a transcript. A conversation exists but has no transcript when '
                    .'the call was run in fake mode, or when the post-call webhook has not arrived yet.',
                    $id,
                ));
            }

            return $found;
        }

        // Nulls sort first on a Postgres DESC, and a row can have a transcript
        // without a start time, so say it rather than let one of those win.
        $latest = $query->orderByRaw('started_at desc nulls last')->orderByDesc('id')->first();

        if ($latest === null) {
            $this->components->error(
                'There is no conversation with a transcript to render. Run `php artisan migrate --seed` for the '
                .'demo ones, or `kitchenline:eval --mode=live` to make a real one.',
            );
        }

        return $latest;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function voices(ElevenLabsClient $client): ?array
    {
        $caller = $this->voice('caller-voice', 'caller_voice_id');
        $agent = $this->voice('agent-voice', 'agent_voice_id');

        // The fake never looks at a voice id, and requiring two before a
        // developer can hear what the command does would put a dashboard visit
        // in front of the only step that works without an account.
        if ($client->name() === 'fake') {
            return [$caller ?? 'fake-caller', $agent ?? 'fake-agent'];
        }

        if ($caller === null || $agent === null) {
            $this->components->error(
                'Two voice ids are needed: one for the caller and one for the agent. Set DEMO_CALLER_VOICE_ID '
                .'and DEMO_AGENT_VOICE_ID (the agent falls back to ELEVENLABS_VOICE_ID), or pass --caller-voice '
                .'and --agent-voice. Ids are under Voices in the dashboard, or from GET /v1/voices.',
            );

            return null;
        }

        return [$caller, $agent];
    }

    private function voice(string $option, string $configKey): ?string
    {
        $fromOption = $this->option($option);

        if (is_string($fromOption) && $fromOption !== '') {
            return $fromOption;
        }

        $fromConfig = config('restaurantline.demo.'.$configKey);

        return is_string($fromConfig) && $fromConfig !== '' ? $fromConfig : null;
    }

    private function gap(): float
    {
        $option = $this->option('gap');

        return is_numeric($option)
            ? (float) $option
            : (float) config('restaurantline.demo.gap_seconds', 0.55);
    }

    private function write(Conversation $conversation, string $wav): string
    {
        $name = sprintf('%s.wav', $conversation->elevenlabs_conversation_id);

        $out = $this->option('out');

        if (is_string($out) && $out !== '') {
            $target = str_ends_with(strtolower($out), '.wav') ? $out : rtrim($out, '/').'/'.$name;
            $directory = dirname($target);

            if (! is_dir($directory)) {
                mkdir($directory, 0o755, true);
            }

            file_put_contents($target, $wav);

            return $target;
        }

        $relative = trim((string) config('restaurantline.demo.path', 'demos'), '/').'/'.$name;

        Storage::disk('local')->put($relative, $wav);

        return (string) Storage::disk('local')->path($relative);
    }

    private function speaker(string $role): string
    {
        return in_array(strtolower($role), ['user', 'caller', 'customer'], true)
            ? '<fg=cyan>caller</>'
            : '<fg=green>agent</>';
    }

    /**
     * The one thing about this command that could embarrass somebody.
     *
     * A file of the right length, with the right name, in the right place,
     * containing nothing. Said plainly and every time, rather than once in the
     * documentation.
     */
    private function silentWarning(): void
    {
        $this->line('  <bg=yellow;fg=black> THIS FILE IS SILENT </>');
        $this->newLine();
        $this->line('  ELEVENLABS_DRIVER is "fake", so no speech was synthesised. The timings and the');
        $this->line('  structure are real; the audio is not. Set ELEVENLABS_DRIVER=api with a key and');
        $this->line('  two voice ids, then run this again, before playing it to anybody.');
        $this->newLine();
    }
}
