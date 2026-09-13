<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Foundation\Vite;

/**
 * Whether Vite has anything to serve yet.
 *
 * `@vite` throws when the manifest is missing, which is the correct behaviour
 * for an application whose pages are made of compiled assets. The kitchen
 * display is not one of those: its layout carries its own stylesheet, and the
 * only thing in the bundle it wants is Echo. So the choice is between a screen
 * that refuses to load until somebody has run `npm install && npm run build`,
 * and a screen that works immediately and gets faster once they have.
 *
 * A takeaway does not care whether its orders arrive over a websocket or from
 * a poll fifteen seconds later. It cares that the screen works. So the layout
 * asks this first, and the display polls when the answer is no.
 *
 * @see docs/DECISIONS.md #0036
 */
final class CompiledAssets
{
    /**
     * True when `npm run dev` is running, or `npm run build` has been run.
     *
     * Both branches are needed and neither implies the other: the dev server
     * writes a hot file and no manifest, a production build writes a manifest
     * and no hot file.
     */
    public static function exist(): bool
    {
        $vite = app(Vite::class);

        return $vite->isRunningHot() || $vite->manifestHash() !== null;
    }
}
