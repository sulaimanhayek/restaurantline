{{--
    The layout for the two pages a customer might actually land on, from a link
    in a text message, on a phone, probably outdoors.

    Self-contained CSS for the same reason as the kitchen layout: these have to
    render on a fresh clone before anybody has run `npm install`, and a payment
    page that arrives unstyled because a build step was skipped looks exactly
    like a phishing page.

    Under components/ rather than next to layouts/kitchen.blade.php because it
    is used as `<x-layouts.public>` from ordinary Blade views, where Laravel
    resolves the name against that directory. The kitchen one is a Livewire
    layout, which is looked up differently.
--}}
@props(['title' => 'Your order'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>

    <style>
        :root {
            --bg: #f5f5f4;
            --card: #ffffff;
            --edge: #e4e4e7;
            --ink: #18181b;
            --ink-dim: #71717a;
            --brand: #b45309;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0c0c0e;
                --card: #18181b;
                --edge: #2a2a30;
                --ink: #fafafa;
                --ink-dim: #a1a1aa;
                --brand: #f59e0b;
            }
        }

        *, *::before, *::after { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.25rem;
            background: var(--bg);
            color: var(--ink);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            line-height: 1.5;
        }

        .card {
            width: 100%;
            max-width: 26rem;
            background: var(--card);
            border: 1px solid var(--edge);
            border-radius: 1rem;
            padding: 1.75rem;
        }

        .eyebrow {
            margin: 0 0 0.25rem;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--ink-dim);
        }

        h1 {
            margin: 0 0 1rem;
            font-size: 1.375rem;
            font-weight: 700;
        }

        .amount {
            font-size: 2.25rem;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            letter-spacing: -0.02em;
        }

        .muted { color: var(--ink-dim); font-size: 0.9375rem; }

        .row {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.5rem 0;
            border-top: 1px solid var(--edge);
            font-size: 0.9375rem;
        }

        .row:first-of-type { border-top: 0; }

        button {
            appearance: none;
            width: 100%;
            margin-top: 1.25rem;
            padding: 0.9rem 1rem;
            border: 0;
            border-radius: 0.625rem;
            background: var(--brand);
            color: #fff;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }

        .notice {
            margin-top: 1.25rem;
            padding: 0.75rem 0.875rem;
            border-radius: 0.625rem;
            border: 1px dashed var(--edge);
            color: var(--ink-dim);
            font-size: 0.8125rem;
        }
    </style>
</head>
<body>
    <main class="card">
        {{ $slot }}
    </main>
</body>
</html>
