<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Jobs\ProcessElevenLabsWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The post-call webhook.
 *
 * ElevenLabs sends this once a call has ended, and it is where most of what
 * this application knows about a conversation comes from: the transcript, the
 * recording, the duration and cost, and their own analysis of how it went.
 * Every call produces one whether or not it produced an order — the calls that
 * failed are the ones worth reading.
 *
 * This controller does almost nothing on purpose. The signature is checked by
 * middleware before anything reaches here; all this does is confirm the
 * envelope is the right shape, hand the payload to a queued job, and answer.
 *
 * The reason is retries. A webhook sender treats a slow or failed response as a
 * delivery failure and sends the whole thing again, so anything expensive done
 * inline — decoding a base64 MP3, writing it to disk, reconciling an order —
 * buys a duplicate delivery of the same payload. Answering immediately and
 * working afterwards means a retry only happens when something is genuinely
 * wrong.
 *
 * @see docs/DECISIONS.md #0024
 */
final class ElevenLabsWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $type = $request->input('type');
        $data = $request->input('data');

        // A signed payload we cannot parse is a different problem from an
        // unsigned one: it came from ElevenLabs, so it is either a new event
        // type or a change in shape. Worth a log line rather than silence, and
        // worth a 200 rather than a retry loop over something a redeploy will
        // not fix.
        if (! is_string($type) || ! is_array($data)) {
            Log::warning('A signed webhook arrived without a recognisable envelope.', [
                'type' => is_string($type) ? $type : gettype($type),
            ]);

            return response()->json(['ok' => true, 'handled' => false]);
        }

        ProcessElevenLabsWebhook::dispatch($type, $data, (int) $request->input('event_timestamp', 0));

        return response()->json(['ok' => true]);
    }
}
