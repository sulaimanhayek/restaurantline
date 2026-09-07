<?php

declare(strict_types=1);

namespace App\Services\Menu;

use App\Enums\ModifierKind;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\Restaurant;
use App\Support\SpokenText;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Turning "the peri peri chicken thing" into a menu row.
 *
 * Two mechanisms, in order:
 *
 *  1. The `spoken_aliases` on each item and modifier. These do most of the
 *     work, and a menu that matches badly is nearly always a menu whose
 *     aliases were never filled in rather than a matcher that needs tuning.
 *  2. PostgreSQL trigram similarity over the name and every alias, for
 *     everything the aliases did not anticipate.
 *
 * The score blends two pg_trgm measures because they fail in opposite
 * directions. `similarity()` compares whole strings, so "burger" against
 * "Ember Chicken Burger" scores poorly even though it is obviously relevant.
 * `word_similarity()` finds the best matching run inside the longer string, so
 * it scores that pair near 1.0 — but it also scores "chicken" near 1.0 against
 * every chicken dish on the menu. Weighted together, an exact name still wins
 * outright and a partial phrase surfaces every plausible candidate, which is
 * what makes the ambiguity check downstream meaningful.
 *
 * @see docs/DECISIONS.md #0013
 */
final class MenuMatchingService
{
    /** Weight on the best-matching run inside the term. */
    private const WORD_WEIGHT = 0.6;

    /** Weight on whole-string similarity. */
    private const WHOLE_WEIGHT = 0.4;

    /**
     * SQL that normalises a term the same way SpokenText::normalise() does in
     * PHP. Both sides of a comparison have to agree on what "Crème Brûlée" and
     * "Halloumi & Avocado" reduce to, or the accents and the ampersand quietly
     * cost a caller their match.
     */
    private const NORMALISE_SQL = <<<'SQL'
        BTRIM(REGEXP_REPLACE(
            REGEXP_REPLACE(REPLACE(UNACCENT(LOWER(%s)), '&', ' and '), '[^a-z0-9 ]+', ' ', 'g'),
            '\s+', ' ', 'g'
        ))
        SQL;

    /**
     * Rank the menu against something a caller said.
     *
     * @param  CarbonInterface|null  $at  Local time used to decide whether each
     *                                    candidate is servable right now.
     *                                    Defaults to the restaurant's own now.
     */
    public function search(
        Restaurant $restaurant,
        string $query,
        ?CarbonInterface $at = null,
        ?int $limit = null,
    ): MenuMatchResult {
        $at ??= $restaurant->now();
        $limit ??= (int) config('restaurantline.menu.max_results', 5);
        $minimum = (float) config('restaurantline.menu.minimum_confidence', 0.3);

        $normalised = SpokenText::normalise($query);
        $stripped = SpokenText::forMatching($query);

        if ($normalised === '') {
            return $this->emptyResult($query, $normalised);
        }

        $rows = $this->scoreItems($restaurant, $normalised, $stripped, $minimum, $limit);

        if ($rows === []) {
            return $this->emptyResult($query, $normalised);
        }

        /** @var array<int, MenuItem> $items */
        $items = MenuItem::query()
            ->with(['category', 'restaurant'])
            ->whereIn('id', array_map(static fn (object $row): int => (int) $row->menu_item_id, $rows))
            ->get()
            ->keyBy('id')
            ->all();

        $matches = [];

        foreach ($rows as $row) {
            $item = $items[(int) $row->menu_item_id] ?? null;

            if ($item === null) {
                continue;
            }

            [$available, $reason] = $this->availability($item, $at);

            $matches[] = new MenuMatch(
                item: $item,
                confidence: round((float) $row->score, 4),
                matchedTerm: (string) $row->matched_term,
                isAvailableNow: $available,
                unavailableReason: $reason,
            );
        }

        return new MenuMatchResult(
            query: $query,
            normalisedQuery: $normalised,
            matches: $matches,
            confidentThreshold: (float) config('restaurantline.menu.confident_threshold', 0.62),
            ambiguityMargin: (float) config('restaurantline.menu.ambiguity_margin', 0.08),
        );
    }

    /**
     * Match something a caller said about an item against that item's own
     * modifiers.
     *
     * Scoped to one item on purpose. "Large" means different things on a drink
     * and on a bucket of wings, and a matcher that searched the whole menu
     * would have to guess which.
     *
     * @return list<ModifierMatch>
     */
    public function matchModifiers(MenuItem $item, string $query, ?int $limit = null): array
    {
        $limit ??= (int) config('restaurantline.menu.max_results', 5);
        $minimum = (float) config('restaurantline.menu.minimum_confidence', 0.3);

        $item->loadMissing(['modifierGroups.modifiers', 'modifierOverrides']);

        $negated = SpokenText::isNegated($query);

        // "no onions" and "onions" have to reach the same row, so the negation
        // words come off before matching — but which rows are eligible depends
        // on whether they were there.
        $subject = $negated ? SpokenText::stripNegation($query) : SpokenText::forMatching($query);

        if ($subject === '') {
            return [];
        }

        $matches = [];

        foreach ($item->modifierGroups as $group) {
            foreach ($group->modifiers as $modifier) {
                if (! $this->modifierIsEligible($modifier, $negated)) {
                    continue;
                }

                $score = $this->bestTermScore($subject, $modifier->matchableTerms());

                if ($score < $minimum) {
                    continue;
                }

                $matches[] = new ModifierMatch(
                    modifier: $modifier,
                    group: $group,
                    confidence: round($score, 4),
                    matchedTerm: $modifier->name,
                    priceDelta: $item->resolvedPriceDelta($modifier),
                    isAvailable: $this->modifierIsAvailable($item, $modifier),
                );
            }
        }

        usort(
            $matches,
            static fn (ModifierMatch $a, ModifierMatch $b): int => $b->confidence <=> $a->confidence,
        );

        return array_slice($matches, 0, $limit);
    }

    /**
     * Which modifiers a query is allowed to reach.
     *
     * A removal only matches a phrase that actually asked for something to be
     * left out. Without this rule "extra onions" scores just as well against
     * the removal named "Onions" as against the add-on, and the kitchen gets a
     * ticket saying the opposite of what the caller wanted.
     *
     * @see docs/DECISIONS.md #0009
     */
    private function modifierIsEligible(Modifier $modifier, bool $negated): bool
    {
        return $negated
            ? $modifier->kind === ModifierKind::Removal
            : $modifier->kind !== ModifierKind::Removal;
    }

    private function modifierIsAvailable(MenuItem $item, Modifier $modifier): bool
    {
        $override = $item->modifierOverrides
            ->firstWhere('id', $modifier->id)
            ?->getAttribute('pivot')
            ?->getAttribute('is_available_override');

        return $override !== null ? (bool) $override : $modifier->is_available;
    }

    /**
     * Score one phrase against a modifier's terms, in PHP.
     *
     * Items go through PostgreSQL because a menu can hold hundreds of them.
     * A single item's modifiers are a couple of dozen rows already in memory,
     * and a round trip to score them would cost more than the scoring.
     *
     * @param  list<string>  $terms
     */
    private function bestTermScore(string $subject, array $terms): float
    {
        $best = 0.0;

        foreach ($terms as $term) {
            $normalised = SpokenText::normalise($term);

            if ($normalised === '') {
                continue;
            }

            if ($normalised === $subject) {
                return 1.0;
            }

            $percent = 0.0;
            similar_text($subject, $normalised, $percent);
            $whole = $percent / 100;

            // The "extra cheese" / "cheese" case: a term wholly contained in
            // the phrase, or the other way round, is a strong signal that
            // whole-string similarity understates badly on short words.
            $contains = str_contains($subject, $normalised) || str_contains($normalised, $subject)
                ? 0.9
                : 0.0;

            $best = max($best, $whole, $contains);
        }

        return $best;
    }

    /**
     * Rank menu items by trigram similarity, in the database.
     *
     * Both the raw phrase and the filler-stripped one are scored, and the
     * better wins: stripping helps "can I get the wings please" and hurts
     * "Bucket of Wings", whose own name contains a word the stripper removes.
     *
     * @return list<object{menu_item_id: int, matched_term: string, score: float}>
     */
    private function scoreItems(
        Restaurant $restaurant,
        string $normalised,
        string $stripped,
        float $minimum,
        int $limit,
    ): array {
        $term = sprintf(self::NORMALISE_SQL, 't.term');

        $sql = <<<SQL
            WITH terms AS (
                SELECT mi.id AS menu_item_id,
                       t.term AS matched_term,
                       {$term} AS normalised_term
                FROM menu_items mi
                CROSS JOIN LATERAL (
                    SELECT mi.name AS term
                    UNION ALL
                    SELECT a.value
                    FROM jsonb_array_elements_text(
                        CASE WHEN jsonb_typeof(mi.spoken_aliases) = 'array'
                             THEN mi.spoken_aliases
                             ELSE '[]'::jsonb END
                    ) AS a(value)
                ) AS t(term)
                WHERE mi.restaurant_id = ?
            ), scored AS (
                SELECT menu_item_id,
                       matched_term,
                       GREATEST(
                           :word: * word_similarity(?, normalised_term)
                               + :whole: * similarity(?, normalised_term),
                           :word: * word_similarity(?, normalised_term)
                               + :whole: * similarity(?, normalised_term)
                       ) AS score
                FROM terms
                WHERE normalised_term <> ''
            ), best AS (
                SELECT DISTINCT ON (menu_item_id) menu_item_id, matched_term, score
                FROM scored
                ORDER BY menu_item_id, score DESC, matched_term
            )
            SELECT menu_item_id, matched_term, score
            FROM best
            WHERE score >= CAST(? AS double precision)
            ORDER BY score DESC, menu_item_id
            LIMIT ?
            SQL;

        // The weights are class constants, not input. Inlining them keeps
        // PostgreSQL from having to infer a type for an untyped parameter
        // sitting next to a real-returning function.
        $sql = str_replace(
            [':word:', ':whole:'],
            [sprintf('%.4F', self::WORD_WEIGHT), sprintf('%.4F', self::WHOLE_WEIGHT)],
            $sql,
        );

        /** @var list<object{menu_item_id: int, matched_term: string, score: float}> $rows */
        $rows = DB::select($sql, [
            $restaurant->id,
            $normalised, $normalised,
            $stripped, $stripped,
            $minimum,
            $limit,
        ]);

        return $rows;
    }

    /**
     * Why an item cannot be ordered right now, phrased for reading aloud.
     *
     * The two reasons are genuinely different to a caller: "we've run out" ends
     * the conversation about that dish, "that's lunch only" invites them to
     * order something else or call back.
     *
     * @return array{0: bool, 1: string|null}
     */
    private function availability(MenuItem $item, CarbonInterface $at): array
    {
        if (! $item->is_available) {
            return [false, 'sold out'];
        }

        if (! $item->category->isAvailableAt($at)) {
            $window = $item->category->spokenAvailability();

            return [false, $window === null
                ? sprintf('not served right now (%s)', mb_strtolower($item->category->name))
                : sprintf('only served %s', $window)];
        }

        return [true, null];
    }

    private function emptyResult(string $query, string $normalised): MenuMatchResult
    {
        return new MenuMatchResult(
            query: $query,
            normalisedQuery: $normalised,
            matches: [],
            confidentThreshold: (float) config('restaurantline.menu.confident_threshold', 0.62),
            ambiguityMargin: (float) config('restaurantline.menu.ambiguity_margin', 0.08),
        );
    }
}
