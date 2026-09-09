{{--
    The call, as a person reviews it.

    Audio and transcript are one component rather than two, because the reason
    anyone opens this screen is to check what was actually said at the moment
    something went wrong — and that means reading a line and then hearing it.
    Clicking a turn's timestamp seeks the player to it.

    The styling is a scoped <style> block using Filament's own colour variables,
    not Tailwind classes. Filament v4 ships a prebuilt stylesheet containing
    only the utilities its own components use, so a utility class in a custom
    view like this one silently does nothing unless the panel has a compiled
    custom theme. Adding one would put `npm install && npm run build` between a
    fresh clone and a dashboard that looks right, which is exactly the kind of
    step this repo is trying not to have.

    @see docs/DECISIONS.md #0029
--}}
@php
    /** @var \App\Models\Conversation $conversation */
    $conversation = $getRecord();
    $turns = $conversation->transcriptTurns();
    $audio = $conversation->audioSource();

    $stamp = static fn (int $seconds): string => sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
@endphp

<style>
    .rl-review { display: flex; flex-direction: column; gap: 1rem; }
    .rl-review__player { width: 100%; }
    .rl-review__empty { font-size: 0.875rem; color: var(--gray-500); }
    .rl-transcript { display: flex; flex-direction: column; gap: 0.5rem; }
    .rl-turn { display: flex; }
    .rl-turn--caller { flex-direction: row-reverse; }
    .rl-turn__bubble {
        max-width: 85%;
        border-radius: 0.5rem;
        padding: 0.5rem 0.75rem;
        font-size: 0.875rem;
        line-height: 1.4;
        background-color: var(--gray-100);
        color: var(--gray-900);
    }
    .rl-turn--caller .rl-turn__bubble {
        background-color: var(--primary-50);
        color: var(--primary-950);
    }
    .rl-turn__meta {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 0.125rem;
        font-size: 0.6875rem;
        font-weight: 600;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        opacity: 0.6;
    }
    .rl-turn__seek {
        font-variant-numeric: tabular-nums;
        text-decoration: underline dotted;
        text-underline-offset: 2px;
        cursor: pointer;
        background: none;
        border: 0;
        padding: 0;
        font: inherit;
        color: inherit;
    }
    .rl-turn__seek:hover { opacity: 1; }
    .rl-turn__at { font-variant-numeric: tabular-nums; }
    .rl-turn__message { white-space: pre-wrap; margin: 0; }

    .dark .rl-review__empty { color: var(--gray-400); }
    .dark .rl-turn__bubble { background-color: var(--gray-800); color: var(--gray-100); }
    .dark .rl-turn--caller .rl-turn__bubble { background-color: var(--primary-950); color: var(--primary-200); }
</style>

<div
    class="rl-review"
    x-data="{
        seek(seconds) {
            const player = $refs.player;
            if (! player) return;
            player.currentTime = seconds;
            player.play();
        },
    }"
>
    @if ($audio)
        <audio x-ref="player" class="rl-review__player" controls preload="none" src="{{ $audio }}"></audio>
    @else
        <p class="rl-review__empty">
            No recording. Either the call predates recording being switched on, or the
            download has not run yet.
        </p>
    @endif

    @if ($turns === [])
        <p class="rl-review__empty">
            No transcript. ElevenLabs sends this with the post-call webhook, so a call
            still in progress will not have one yet.
        </p>
    @else
        <div class="rl-transcript">
            @foreach ($turns as $turn)
                @php $isCaller = $turn['role'] === 'user'; @endphp

                <div @class(['rl-turn', 'rl-turn--caller' => $isCaller])>
                    <div class="rl-turn__bubble">
                        <div class="rl-turn__meta">
                            <span>{{ $isCaller ? 'Caller' : 'Agent' }}</span>

                            @if ($turn['at'] !== null && $audio)
                                <button
                                    type="button"
                                    class="rl-turn__seek"
                                    title="Play from here"
                                    x-on:click="seek({{ $turn['at'] }})"
                                >{{ $stamp($turn['at']) }}</button>
                            @elseif ($turn['at'] !== null)
                                <span class="rl-turn__at">{{ $stamp($turn['at']) }}</span>
                            @endif
                        </div>

                        <p class="rl-turn__message">{{ $turn['message'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
