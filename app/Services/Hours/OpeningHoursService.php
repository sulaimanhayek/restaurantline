<?php

declare(strict_types=1);

namespace App\Services\Hours;

use App\Models\OpeningHour;
use App\Models\OpeningHourOverride;
use App\Models\Restaurant;
use App\Support\SpokenTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * "Are you open?" and "when will that be ready?".
 *
 * Both questions look trivial and neither is. A kitchen that serves until 2am
 * is open at half past midnight on a day its recurring row does not mention,
 * because the window belongs to the night before. A bank holiday override
 * replaces the pattern for that date rather than adding to it. And every
 * comparison has to happen in the restaurant's timezone, never the
 * application's — an order placed at 23:30 in London is placed at 22:30 UTC,
 * and one of those two numbers gives the wrong answer to "are you still open".
 *
 * Everything here works in `CarbonImmutable` fixed to `$restaurant->timezone`.
 */
final class OpeningHoursService
{
    /** How far ahead `nextOpening()` is willing to look before giving up. */
    private const LOOKAHEAD_DAYS = 14;

    public function isOpenAt(Restaurant $restaurant, ?CarbonInterface $at = null): bool
    {
        return $this->currentWindow($restaurant, $at) !== null;
    }

    /**
     * The window `$at` falls inside, or null if the restaurant is shut.
     */
    public function currentWindow(Restaurant $restaurant, ?CarbonInterface $at = null): ?ServiceWindow
    {
        $at = $this->moment($restaurant, $at);

        // Yesterday as well as today: a window that closes next day is stored
        // against the day it *opens*, so 00:30 on Saturday lives on Friday.
        foreach ([$at->subDay(), $at] as $date) {
            foreach ($this->windowsOn($restaurant, $date) as $window) {
                if ($window->contains($at)) {
                    return $window;
                }
            }
        }

        return null;
    }

    /**
     * The next window to open strictly after `$at`.
     *
     * Null means the restaurant has nothing on the books for a fortnight, which
     * in practice means its hours were never seeded.
     */
    public function nextOpening(Restaurant $restaurant, ?CarbonInterface $at = null): ?ServiceWindow
    {
        $at = $this->moment($restaurant, $at);

        for ($offset = 0; $offset <= self::LOOKAHEAD_DAYS; $offset++) {
            foreach ($this->windowsOn($restaurant, $at->addDays($offset)) as $window) {
                if ($window->opensAt > $at) {
                    return $window;
                }
            }
        }

        return null;
    }

    /**
     * When the kitchen next opens, as a phrase to drop into a sentence.
     *
     * "later today at 8pm", "tomorrow at midday", "on Tuesday at midday". Three
     * endpoints have to say this and were each building it inline, which is
     * two chances too many for the agent to tell one caller "today at midday"
     * and the next "on Sunday at 12:00".
     *
     * Null when there is no next opening at all — the caller of last resort
     * should say "we're closed" and nothing more, rather than inventing a day.
     */
    public function spokenNextOpening(Restaurant $restaurant, ?CarbonInterface $at = null): ?string
    {
        $at = $this->moment($restaurant, $at);
        $next = $this->nextOpening($restaurant, $at);

        if ($next === null) {
            return null;
        }

        $day = match (true) {
            $next->opensAt->isSameDay($at) => 'later today',
            $next->opensAt->isSameDay($at->addDay()) => 'tomorrow',
            default => 'on '.$next->opensAt->format('l'),
        };

        return sprintf('%s at %s', $day, SpokenTime::of($next->opensAt->format('H:i')));
    }

    /**
     * Every window on one local date, in the order they open.
     *
     * An override for that date replaces the recurring pattern outright — it
     * does not merge with it. "Closed Christmas Day" has to be able to beat a
     * Wednesday row, and "open late for the match" has to be able to beat it in
     * the other direction.
     *
     * @return list<ServiceWindow>
     */
    public function windowsOn(Restaurant $restaurant, CarbonInterface $date): array
    {
        $date = $this->moment($restaurant, $date)->startOfDay();

        $override = $this->overrides($restaurant)
            ->firstWhere(fn (OpeningHourOverride $row): bool => $row->date->isSameDay($date));

        if ($override !== null) {
            if ($override->is_closed || $override->opens_at === null || $override->closes_at === null) {
                return [];
            }

            return [$this->window($date, $override->opens_at, $override->closes_at, $override->closes_next_day, $override->reason)];
        }

        $windows = $this->recurring($restaurant)
            ->where('day_of_week', $date->dayOfWeek)
            ->map(fn (OpeningHour $row): ServiceWindow => $this->window(
                $date,
                $row->opens_at,
                $row->closes_at,
                $row->closes_next_day,
                $row->label,
            ))
            ->values()
            ->all();

        usort($windows, static fn (ServiceWindow $a, ServiceWindow $b): int => $a->opensAt <=> $b->opensAt);

        return $windows;
    }

    /**
     * The recurring week, shaped for reading down the phone.
     *
     * Days the kitchen is shut are included rather than omitted: "we're closed
     * Mondays" is an answer, and a gap in a list is not.
     *
     * @return list<array{day: string, day_of_week: int, closed: bool, windows: list<string>, spoken: string}>
     */
    public function weeklySummary(Restaurant $restaurant): array
    {
        $today = $restaurant->now()->startOfDay();
        $summary = [];

        // Monday first. The stored `day_of_week` is Carbon's, where 0 is Sunday,
        // but nobody reads their week out starting on Sunday.
        foreach ([1, 2, 3, 4, 5, 6, 0] as $dayOfWeek) {
            $rows = $this->recurring($restaurant)->where('day_of_week', $dayOfWeek);

            $spans = $rows
                ->sortBy('opens_at')
                ->map(static fn (OpeningHour $row): string => SpokenTime::range($row->opens_at, $row->closes_at))
                ->values()
                ->all();

            $summary[] = [
                'day' => $today->startOfWeek()->addDays($dayOfWeek === 0 ? 6 : $dayOfWeek - 1)->format('l'),
                'day_of_week' => $dayOfWeek,
                'closed' => $spans === [],
                'windows' => $spans,
                'spoken' => $spans === [] ? 'closed' : implode(' and ', $spans),
            ];
        }

        return $summary;
    }

    /**
     * When an order placed now would be ready, and how many minutes that is.
     *
     * Prep starts when the kitchen next opens, not when the phone rang. An
     * agent taking a pre-order at 10am for a kitchen that opens at noon must
     * not promise food in twenty minutes.
     *
     * @return array{ready_at: CarbonImmutable, minutes: int, prep_minutes: int, opens_first: bool}
     */
    public function readyEstimate(
        Restaurant $restaurant,
        int $prepMinutes,
        ?CarbonInterface $at = null,
    ): array {
        $at = $this->moment($restaurant, $at);
        $from = $at;
        $opensFirst = false;

        if (! $this->isOpenAt($restaurant, $at)) {
            $next = $this->nextOpening($restaurant, $at);

            if ($next !== null) {
                $from = $next->opensAt;
                $opensFirst = true;
            }
        }

        $readyAt = $from->addMinutes($prepMinutes);

        return [
            'ready_at' => $readyAt,
            // What the caller experiences is the wait from now, which is longer
            // than the prep time whenever the kitchen has to open first.
            'minutes' => max(0, (int) ceil($at->diffInMinutes($readyAt, absolute: false))),
            'prep_minutes' => $prepMinutes,
            'opens_first' => $opensFirst,
        ];
    }

    private function window(
        CarbonImmutable $date,
        string $opensAt,
        string $closesAt,
        bool $closesNextDay,
        ?string $label,
    ): ServiceWindow {
        $opens = $this->onDate($date, $opensAt);
        $closes = $this->onDate($date, $closesAt);

        // Belt and braces: a row saying 17:00 to 02:00 without the flag set is
        // a data-entry slip, not a two-minute service window.
        if ($closesNextDay || $closes <= $opens) {
            $closes = $closes->addDay();
        }

        return new ServiceWindow($opens, $closes, $label);
    }

    private function onDate(CarbonImmutable $date, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_pad(array_map(intval(...), explode(':', $time)), 2, 0);

        return $date->startOfDay()->addHours($hour)->addMinutes($minute);
    }

    /**
     * Loaded once per instance per restaurant. A single `/availability` call
     * asks about several days, and each of those must not become a query.
     *
     * @return Collection<int, OpeningHour>
     */
    private function recurring(Restaurant $restaurant): Collection
    {
        return $restaurant->relationLoaded('openingHours')
            ? $restaurant->openingHours
            : $restaurant->openingHours()->get()->tap(
                static fn ($rows) => $restaurant->setRelation('openingHours', $rows),
            );
    }

    /**
     * @return Collection<int, OpeningHourOverride>
     */
    private function overrides(Restaurant $restaurant): Collection
    {
        return $restaurant->relationLoaded('openingHourOverrides')
            ? $restaurant->openingHourOverrides
            : $restaurant->openingHourOverrides()->get()->tap(
                static fn ($rows) => $restaurant->setRelation('openingHourOverrides', $rows),
            );
    }

    private function moment(Restaurant $restaurant, ?CarbonInterface $at): CarbonImmutable
    {
        if ($at === null) {
            return $restaurant->now();
        }

        return CarbonImmutable::instance($at)->setTimezone($restaurant->timezone);
    }
}
