<?php

declare(strict_types=1);

use App\Services\ElevenLabs\ElevenLabsClient;
use App\Services\ElevenLabs\ElevenLabsException;
use App\Services\ElevenLabs\FakeElevenLabsClient;
use App\Services\ElevenLabs\Provisioner;

/**
 * Provisioning, run twice, which is the only interesting number of times.
 *
 * A forker runs this after every change to the tool routes, after every edit to
 * the tone of voice, and once by accident. None of those may leave a workspace
 * holding eighteen tools, two agents and two webhooks posting every call twice.
 *
 * All of it runs against `FakeElevenLabsClient`, which remembers what it was
 * given — so a second `provision()` here finds its own earlier work exactly as
 * a second run against a real workspace would.
 */
function fakeElevenLabs(): FakeElevenLabsClient
{
    /** @var FakeElevenLabsClient $client */
    $client = app(ElevenLabsClient::class);

    return $client;
}

beforeEach(function (): void {
    config([
        'restaurantline.elevenlabs.driver' => 'fake',
        'restaurantline.agent.token' => 'token-one',
    ]);

    fakeElevenLabs()->flush();
});

it('creates a secret, nine tools, a webhook and an agent', function (): void {
    $report = app(Provisioner::class)->provision($restaurant = restaurant());

    expect($report->secretAction)->toBe('created')
        ->and($report->tools)->toHaveCount(9)
        ->and(array_unique(array_values($report->tools)))->toBe(['created'])
        ->and($report->webhookAction)->toBe('created')
        ->and($report->agentAction)->toBe('created')
        ->and($report->createdAnything())->toBeTrue();

    $restaurant->refresh();

    expect($restaurant->elevenlabs_agent_id)->toBe($report->agentId)
        ->and($restaurant->elevenlabs_secret_id)->toBe($report->secretId)
        ->and($restaurant->elevenlabs_webhook_id)->toBe($report->webhookId)
        ->and($restaurant->elevenlabs_tool_ids)->toHaveCount(9)
        ->and($restaurant->provisioned_at)->not->toBeNull();
});

describe('running it again', function (): void {
    it('updates what it made rather than making it twice', function (): void {
        app(Provisioner::class)->provision($restaurant = restaurant());
        $second = app(Provisioner::class)->provision($restaurant->refresh());

        expect(array_unique(array_values($second->tools)))->toBe(['updated'])
            ->and($second->agentAction)->toBe('updated')
            ->and($second->secretAction)->toBe('updated')
            ->and(fakeElevenLabs()->tools())->toHaveCount(9)
            ->and(fakeElevenLabs()->agents())->toHaveCount(1);
    });

    /*
     * Two webhooks on one URL means two of every conversation record, and
     * unpicking that afterwards is an afternoon.
     */
    it('finds the webhook by its URL rather than making a second one', function (): void {
        app(Provisioner::class)->provision($restaurant = restaurant());
        $second = app(Provisioner::class)->provision($restaurant->refresh());

        expect($second->webhookAction)->toBe('existing')
            ->and($second->webhookId)->not->toBeNull()
            ->and(fakeElevenLabs()->listWebhooks())->toHaveCount(1);
    });

    /*
     * The secret comes back once. Printing it again on a run that did not
     * create one would teach somebody to ignore the box it is printed in.
     */
    it('has no webhook secret to hand over the second time', function (): void {
        app(Provisioner::class)->provision($restaurant = restaurant());
        $second = app(Provisioner::class)->provision($restaurant->refresh());

        expect($second->webhookSecret)->toBeNull();
    });

    it('reports that it changed nothing new', function (): void {
        app(Provisioner::class)->provision($restaurant = restaurant());

        expect(app(Provisioner::class)->provision($restaurant->refresh())->createdAnything())->toBeFalse();
    });
});

describe('the token', function (): void {
    /*
     * A secret locator substitutes the whole header value rather than
     * interpolating into it, and `AuthenticateAgent` reads `$request->
     * bearerToken()`. So the "Bearer " has to live inside the secret.
     */
    it('is stored as the whole Authorization header, prefix and all', function (): void {
        $report = app(Provisioner::class)->provision(restaurant());

        expect(fakeElevenLabs()->secretValue($report->secretId))->toBe('Bearer token-one');
    });

    it('rotates in place, so the nine tools never move', function (): void {
        $first = app(Provisioner::class)->provision($restaurant = restaurant());

        config(['restaurantline.agent.token' => 'token-two']);
        $second = app(Provisioner::class)->provision($restaurant->refresh());

        expect($second->secretId)->toBe($first->secretId)
            ->and($second->secretAction)->toBe('updated')
            ->and(fakeElevenLabs()->secretValue($second->secretId))->toBe('Bearer token-two')
            ->and(array_unique(array_values($second->tools)))->toBe(['updated']);
    });

    it('refuses to provision nine tools that could never call anything', function (): void {
        config(['restaurantline.agent.token' => '']);

        expect(fn () => app(Provisioner::class)->provision(restaurant()))
            ->toThrow(ElevenLabsException::class, 'AGENT_API_TOKEN');
    });
});

describe('when somebody has been in the dashboard', function (): void {
    /*
     * An id we remember whose resource is gone must be treated as absent. The
     * alternative is a 404 on every run from then on, and a restaurant stuck
     * with whatever its agent last knew.
     */
    it('recreates a tool that was deleted', function (): void {
        app(Provisioner::class)->provision($restaurant = restaurant());

        $ids = $restaurant->refresh()->elevenlabs_tool_ids;
        $ids['search_menu'] = 'tool_deleted_in_the_dashboard';
        $restaurant->forceFill(['elevenlabs_tool_ids' => $ids])->save();

        $report = app(Provisioner::class)->provision($restaurant->refresh());

        expect($report->tools['search_menu'])->toBe('created')
            ->and($report->tools['create_order'])->toBe('updated')
            ->and($restaurant->refresh()->elevenlabs_tool_ids['search_menu'])
            ->not->toBe('tool_deleted_in_the_dashboard');
    });

    it('recreates an agent that was deleted', function (): void {
        app(Provisioner::class)->provision($restaurant = restaurant());
        $restaurant->forceFill(['elevenlabs_agent_id' => 'agent_deleted'])->save();

        expect(app(Provisioner::class)->provision($restaurant->refresh())->agentAction)->toBe('created');
    });
});

it('hands the agent its nine tools and its webhook', function (): void {
    $report = app(Provisioner::class)->provision($restaurant = restaurant());

    $agent = fakeElevenLabs()->agents()[$report->agentId];
    $prompt = $agent['conversation_config']['agent']['prompt'];

    /*
     * Compared as a set. `elevenlabs_tool_ids` is jsonb, and Postgres does not
     * promise to hand a jsonb object's keys back in the order they went in —
     * which is fine, because everything that reads that column reads it by name.
     */
    $stored = array_values($restaurant->refresh()->elevenlabs_tool_ids);
    sort($stored);
    $sent = $prompt['tool_ids'];
    sort($sent);

    expect($prompt['tool_ids'])->toHaveCount(9)
        ->and($sent)->toBe($stored)
        ->and($agent['platform_settings']['workspace_overrides']['webhooks']['post_call_webhook_id'])
        ->toBe($report->webhookId)
        ->and($agent['tags'])->toContain($restaurant->slug);
});

describe('attaching a phone number', function (): void {
    beforeEach(function (): void {
        config([
            'restaurantline.twilio.account_sid' => 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            'restaurantline.twilio.auth_token' => 'token_secret',
        ]);
    });

    it('points the number at the agent and remembers the id', function (): void {
        $restaurant = restaurant();
        app(Provisioner::class)->provision($restaurant);

        $id = app(Provisioner::class)->attachPhoneNumber($restaurant->refresh(), '+441134960000');

        expect($restaurant->refresh()->elevenlabs_phone_number_id)->toBe($id)
            ->and(fakeElevenLabs()->phoneNumbers()[$id]['agent_id'])
            ->toBe($restaurant->elevenlabs_agent_id);
    });

    /*
     * Not part of provision(), and this is the test that keeps it that way: it
     * is the one operation here that changes who answers when a customer rings.
     */
    it('is not something a routine provision does', function (): void {
        app(Provisioner::class)->provision(restaurant());

        expect(fakeElevenLabs()->phoneNumbers())->toBe([]);
    });

    it('will not attach a number to an agent that does not exist yet', function (): void {
        expect(fn () => app(Provisioner::class)->attachPhoneNumber(restaurant(), '+441134960000'))
            ->toThrow(ElevenLabsException::class, 'Provision first');
    });

    /*
     * ElevenLabs holds the Twilio credentials in order to answer calls on the
     * number. They come from the environment because an auth token passed on a
     * command line ends up in a shell history file.
     */
    it('reads the Twilio credentials from the environment, not from arguments', function (): void {
        config(['restaurantline.twilio.auth_token' => '']);

        $restaurant = restaurant();
        app(Provisioner::class)->provision($restaurant);

        expect(fn () => app(Provisioner::class)->attachPhoneNumber($restaurant->refresh(), '+441134960000'))
            ->toThrow(ElevenLabsException::class, 'TWILIO_AUTH_TOKEN');
    });
});

describe('preview', function (): void {
    it('sends nothing at all', function (): void {
        app(Provisioner::class)->preview(restaurant());

        expect(fakeElevenLabs()->tools())->toBe([])
            ->and(fakeElevenLabs()->agents())->toBe([]);
    });

    it('shows the nine tools and the prompt the agent would get', function (): void {
        $preview = app(Provisioner::class)->preview(restaurant());

        expect($preview['tools'])->toHaveCount(9)
            ->and($preview['agent']['conversation_config']['agent']['prompt']['prompt'])
            ->toBeString()
            ->not->toBe('');
    });
});
