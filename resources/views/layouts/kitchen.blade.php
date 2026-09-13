{{--
    The full-screen layout for the kitchen display.

    It carries its own stylesheet rather than using the application's Tailwind
    build, and pulls in only the JavaScript bundle, and only when there is one.
    Both choices come from the same place: this screen has to work on a fresh
    clone, before anybody has run `npm install`. See docs/DECISIONS.md #0036.

    Everything here is sized for a screen somebody reads from two metres away
    while holding a pan.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Kitchen' }}</title>

    @livewireStyles

    {{--
        Laravel Echo, and nothing else. The stylesheet below is the whole of
        this page's CSS, so a missing build costs the websocket and not the
        screen. Deferred by virtue of being a module, which means `window.Echo`
        is set before Livewire starts on DOMContentLoaded and finds it.
    --}}
    @if (\App\Support\CompiledAssets::exist())
        @vite(['resources/js/app.js'])
    @endif

    <style>
        :root {
            color-scheme: dark;

            --bg: #0c0c0e;
            --panel: #16161a;
            --card: #1e1e24;
            --card-edge: #30303a;
            --ink: #f4f4f5;
            --ink-dim: #9b9ba4;
            --brand: #f59e0b;

            --ok: #34d399;
            --warn: #fbbf24;
            --late: #f87171;

            --gap: 0.75rem;
        }

        *, *::before, *::after { box-sizing: border-box; }

        html, body {
            margin: 0;
            height: 100%;
        }

        body {
            background: var(--bg);
            color: var(--ink);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            font-size: 16px;
            line-height: 1.35;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            /* A kitchen screen is touched with the side of a thumb. Nothing on
               it should ever end up selected, zoomed or highlighted blue. */
            -webkit-tap-highlight-color: transparent;
            -webkit-user-select: none;
            user-select: none;
        }

        /* The Livewire component's single root element, between the body's
           flex column and the board's own. */
        .kitchen {
            flex: 1 1 auto;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        /* ---------------------------------------------------------------- */
        /* Header                                                            */
        /* ---------------------------------------------------------------- */

        .kitchen-header {
            flex: 0 0 auto;
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem 1rem;
            background: var(--panel);
            border-bottom: 1px solid var(--card-edge);
        }

        .kitchen-header h1 {
            margin: 0;
            font-size: 1.125rem;
            font-weight: 600;
            letter-spacing: 0.01em;
        }

        .kitchen-header .spacer { flex: 1 1 auto; }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.25rem 0.6rem;
            border-radius: 999px;
            background: #26262e;
            color: var(--ink-dim);
            font-size: 0.8125rem;
            font-weight: 500;
            white-space: nowrap;
        }

        .pill .dot {
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 50%;
            background: var(--ink-dim);
        }

        .pill[data-connection="live"] .dot { background: var(--ok); }
        .pill[data-connection="polling"] .dot { background: var(--warn); }
        .pill[data-connection="lost"] .dot { background: var(--late); }

        .header-link {
            color: var(--ink-dim);
            text-decoration: none;
            font-size: 0.8125rem;
            padding: 0.25rem 0.6rem;
            border-radius: 0.375rem;
            border: 1px solid var(--card-edge);
        }

        .header-link:hover { color: var(--ink); }

        /* ---------------------------------------------------------------- */
        /* Board                                                             */
        /* ---------------------------------------------------------------- */

        .board {
            flex: 1 1 auto;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: var(--gap);
            padding: var(--gap);
            overflow: hidden;
            min-height: 0;
        }

        .column {
            display: flex;
            flex-direction: column;
            min-height: 0;
            background: var(--panel);
            border-radius: 0.75rem;
            overflow: hidden;
        }

        .column-head {
            flex: 0 0 auto;
            display: flex;
            align-items: baseline;
            gap: 0.5rem;
            padding: 0.625rem 0.875rem;
            border-bottom: 1px solid var(--card-edge);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--ink-dim);
        }

        .column-head .count {
            margin-left: auto;
            font-size: 0.875rem;
            letter-spacing: 0;
            color: var(--ink);
        }

        .column-body {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            padding: var(--gap);
            display: flex;
            flex-direction: column;
            gap: var(--gap);
        }

        .column-empty {
            margin: auto;
            color: #5c5c66;
            font-size: 0.875rem;
        }

        /* ---------------------------------------------------------------- */
        /* Cards                                                             */
        /* ---------------------------------------------------------------- */

        /*
           `flex: 0 0 auto` is load-bearing. A card is a flex item in a column
           that scrolls, and a flex item's default is to shrink to fit: without
           this, a busy column squashes every card and `overflow: hidden` quietly
           clips the bottom of each one — which is where the advance button is.
           The result is a board that looks right and cannot be tapped, on the
           evening it is busy enough to matter. Cards keep their full height and
           the column scrolls instead.
        */
        .ticket {
            flex: 0 0 auto;
            background: var(--card);
            border: 1px solid var(--card-edge);
            border-left: 4px solid var(--card-edge);
            border-radius: 0.625rem;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .ticket[data-urgency="warn"] { border-left-color: var(--warn); }
        .ticket[data-urgency="late"] { border-left-color: var(--late); }

        .ticket-head {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 0.75rem 0.5rem;
        }

        .ticket-number {
            font-size: 1.5rem;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            letter-spacing: 0.02em;
        }

        .ticket-timer {
            margin-left: auto;
            font-size: 1rem;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            color: var(--ink-dim);
        }

        .ticket[data-urgency="warn"] .ticket-timer { color: var(--warn); }
        .ticket[data-urgency="late"] .ticket-timer { color: var(--late); }

        .ticket-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.375rem;
            padding: 0 0.75rem 0.5rem;
        }

        .tag {
            font-size: 0.6875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            padding: 0.15rem 0.45rem;
            border-radius: 0.25rem;
            background: #2b2b34;
            color: var(--ink-dim);
        }

        .tag[data-kind="delivery"] { background: #3b2f16; color: var(--brand); }
        .tag[data-kind="unpaid"] { background: #3a2020; color: var(--late); }

        .ticket-items {
            margin: 0;
            padding: 0.5rem 0.75rem;
            list-style: none;
            border-top: 1px solid var(--card-edge);
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .ticket-items li { font-size: 1.0625rem; }

        .item-line {
            display: flex;
            gap: 0.5rem;
            font-weight: 600;
        }

        .item-qty {
            min-width: 1.75rem;
            color: var(--brand);
            font-variant-numeric: tabular-nums;
        }

        .item-extra {
            margin: 0.15rem 0 0 2.25rem;
            font-size: 0.9375rem;
            font-weight: 400;
            color: var(--ink-dim);
        }

        .item-note {
            margin: 0.15rem 0 0 2.25rem;
            font-size: 0.9375rem;
            font-weight: 600;
            color: var(--warn);
        }

        .ticket-note {
            padding: 0.5rem 0.75rem;
            border-top: 1px solid var(--card-edge);
            font-size: 0.9375rem;
            font-weight: 600;
            color: var(--warn);
        }

        .ticket-address {
            padding: 0.5rem 0.75rem;
            border-top: 1px solid var(--card-edge);
            font-size: 0.875rem;
            color: var(--ink-dim);
        }

        .ticket-advance {
            appearance: none;
            border: 0;
            border-top: 1px solid var(--card-edge);
            background: #2a2a33;
            color: var(--ink);
            font: inherit;
            font-weight: 700;
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            /* Fitts's law, and wet hands. */
            min-height: 3.5rem;
            cursor: pointer;
        }

        .ticket-advance:hover { background: #343440; }
        .ticket-advance:active { background: var(--brand); color: #1a1200; }

        /* ---------------------------------------------------------------- */
        /* Small screens — a tablet propped up by the pass                   */
        /* ---------------------------------------------------------------- */

        @media (max-width: 900px) {
            body { overflow: auto; }

            .board {
                grid-template-columns: minmax(0, 1fr);
                overflow: visible;
            }

            .column, .column-body { overflow: visible; }
        }
    </style>
</head>
<body>
    {{ $slot }}

    @livewireScripts
</body>
</html>
