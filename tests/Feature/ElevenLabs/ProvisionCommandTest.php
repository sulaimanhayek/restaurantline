<?php

declare(strict_types=1);

use App\Services\ElevenLabs\ElevenLabsClient;
use App\Services\ElevenLabs\FakeElevenLabsClient;
use Illuminate\Support\Facades\Artisan;

/**
 * `kitchenline:provision`, which is the second command a forker ever runs.
 *
 * `Provisioner` is tested next door for what it does to a workspace. This is
 * about what the person watching it is told: that a dry run sent nothing, that
 * the webhook secret they will never see again is impossible to scroll past,
 * that an APP_URL only their laptop can resolve is going to produce an agent
 * which answers the phone and then fails every tool call, and that nothing
 * points a real telephone number anywhere without being asked first.
 *
 * Everything runs against the fake client, which is also the default a forker
 * gets, so these assertions are about the same output they will see.
 */
function provisioningWorkspace(): FakeElevenLabsClient
{
    /** @var FakeElevenLabsClient $client */
    $client = app(ElevenLabsClient::class);

    return $client;
}

/**
 * The command's whole output, as one string.
 *
 * `expectsOutputToContain` matches one write at a time and in order, which is
 * the wrong shape for a command whose output is a page: two things said in the
 * same sentence cannot both be asserted, and anything asserted out of order
 * fails for a reason that has nothing to do with the command. Buffering it and
 * reading the lot is simpler and says what these tests actually mean.
 *
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function runProvision(array $parameters = []): array
{
    $status = Artisan::call('kitchenline:provision', $parameters);

    return [$status, Artisan::output()];
}

beforeEach(function (): void {
    config([
        'restaurantline.elevenlabs.driver' => 'fake',
        'restaurantline.agent.token' => 'token-one',
        'app.url' => 'https://ember.example',
    ]);

    provisioningWorkspace()->flush();
});

describe('--dry-run', function (): void {
    it('sends nothing at all', function (): void {
        restaurant();

        [$status, $output] = runProvision(['--dry-run' => true]);

        expect($status)->toBe(0)
            ->and($output)->toContain('Nothing was sent')
            ->and(provisioningWorkspace()->tools())->toBe([])
            ->and(provisioningWorkspace()->agents())->toBe([])
            ->and(restaurant()->refresh()->provisioned_at)->toBeNull();
    });

    /*
     * Nine names and nine endpoints, spelled out rather than generated from
     * the same source the command reads. A tool pointed at a route that does
     * not exist is the failure this is for, and it is invisible until a
     * caller asks for something.
     */
    it('prints every tool with the URL it would be called at', function (): void {
        restaurant();

        [, $output] = runProvision(['--dry-run' => true]);

        foreach ([
            'search_menu' => 'POST '.url('/api/agent/menu/search'),
            'show_menu' => 'GET '.url('/api/agent/menu'),
            'check_availability' => 'POST '.url('/api/agent/availability'),
            'opening_hours' => 'GET '.url('/api/agent/hours'),
            'validate_address' => 'POST '.url('/api/agent/address/validate'),
            'quote_order' => 'POST '.url('/api/agent/quote'),
            'create_order' => 'POST '.url('/api/agent/orders'),
            'confirm_order' => 'POST '.url('/api/agent/orders/{order}/confirm'),
            'escalate_to_human' => 'POST '.url('/api/agent/escalate'),
        ] as $tool => $endpoint) {
            expect($output)->toContain($tool)->toContain($endpoint);
        }
    });

    /*
     * The one thing a dry run is really for. The system prompt is assembled
     * from four database columns and a page of prose, and "what is my agent
     * actually being told?" has no other honest answer.
     */
    it('prints the system prompt the agent would be given', function (): void {
        restaurant(['name' => 'Ember Grill']);

        [, $output] = runProvision(['--dry-run' => true]);

        expect($output)->toContain('System prompt')
            ->toContain('card details')
            ->toContain('Ember Grill')
            ->toContain('gpt-4o-mini');
    });
});

describe('a real run', function (): void {
    it('reports what it made and remembers it on the restaurant', function (): void {
        $restaurant = restaurant();

        [$status, $output] = runProvision();

        expect($status)->toBe(0)
            ->and($output)->toContain('created')
            ->and($output)->toContain('Done.');

        $restaurant->refresh();

        expect($restaurant->elevenlabs_agent_id)->not->toBeNull()
            ->and($restaurant->elevenlabs_tool_ids)->toHaveCount(9)
            ->and(provisioningWorkspace()->tools())->toHaveCount(9);
    });

    it('says out loud that the fake driver touched no real workspace', function (): void {
        restaurant();

        [, $output] = runProvision();

        expect($output)->toContain('ELEVENLABS_DRIVER')
            ->toContain('not touch a real workspace');
    });

    /*
     * ElevenLabs returns the signing key at creation and never again. Without
     * it the post-call webhook rejects every delivery — correctly, since a
     * webhook that accepts unsigned posts is an endpoint anybody can write
     * conversations into — so this has to be unmissable on the one run that
     * produces it, and absent on every other.
     */
    it('makes the one-time webhook secret impossible to scroll past', function (): void {
        restaurant();

        [, $output] = runProvision();

        expect($output)->toContain('COPY THIS NOW')
            ->toContain('ELEVENLABS_WEBHOOK_SECRET=wsec_');
    });

    it('does not print a secret on a run that created no webhook', function (): void {
        restaurant();

        runProvision();

        [, $output] = runProvision();

        expect($output)->not->toContain('COPY THIS NOW')
            ->and($output)->not->toContain('ELEVENLABS_WEBHOOK_SECRET')
            ->and($output)->toContain('updated');
    });
});

describe('the warning that saves an evening', function (): void {
    /*
     * ElevenLabs calls the tool URLs from its own infrastructure. An APP_URL
     * only this machine can resolve produces an agent that answers the phone,
     * sounds perfect, and cannot look up a single dish — and nothing in the
     * ElevenLabs dashboard says why.
     */
    it('warns when APP_URL is somewhere only this laptop can reach', function (string $url): void {
        config(['app.url' => $url]);
        restaurant();

        [, $output] = runProvision(['--dry-run' => true]);

        expect($output)->toContain('APP_URL is '.$url)
            ->toContain('ElevenLabs cannot reach')
            ->toContain('ngrok');
    })->with([
        'http://localhost:8000',
        'http://127.0.0.1:8000',
        'http://restaurantline.test',
        'http://ember.local',
    ]);

    it('stays quiet about a URL the internet can resolve', function (): void {
        restaurant();

        [, $output] = runProvision(['--dry-run' => true]);

        expect($output)->not->toContain('ngrok');
    });
});

describe('--phone-number', function (): void {
    beforeEach(function (): void {
        config([
            'restaurantline.twilio.account_sid' => 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            'restaurantline.twilio.auth_token' => 'twilio-auth-token',
        ]);
    });

    /*
     * The only step in this command that changes who answers a ringing
     * telephone. It asks by name every time.
     */
    it('asks before pointing a real number at the agent', function (): void {
        $restaurant = restaurant();

        $this->artisan('kitchenline:provision --phone-number=+442079460000')
            ->expectsConfirmation('Point +442079460000 at this agent?', 'no')
            ->assertSuccessful();

        expect($restaurant->refresh()->elevenlabs_phone_number_id)->toBeNull()
            ->and(provisioningWorkspace()->phoneNumbers())->toBe([]);
    });

    it('imports the number and points it at the agent once told to', function (): void {
        $restaurant = restaurant();

        $this->artisan('kitchenline:provision --phone-number=+442079460000')
            ->expectsConfirmation('Point +442079460000 at this agent?', 'yes')
            ->assertSuccessful();

        $restaurant->refresh();

        expect($restaurant->elevenlabs_phone_number_id)->not->toBeNull();

        $number = provisioningWorkspace()->phoneNumbers()[$restaurant->elevenlabs_phone_number_id] ?? null;

        expect($number)->not->toBeNull()
            ->and($number['phone_number'])->toBe('+442079460000')
            ->and($number['agent_id'])->toBe($restaurant->elevenlabs_agent_id);
    });

    it('leaves the phone alone on a routine provision', function (): void {
        $restaurant = restaurant();

        runProvision();

        expect($restaurant->refresh()->elevenlabs_phone_number_id)->toBeNull()
            ->and(provisioningWorkspace()->phoneNumbers())->toBe([]);
    });

    it('refuses without the Twilio credentials ElevenLabs needs to answer the number', function (): void {
        config(['restaurantline.twilio.auth_token' => '']);
        $restaurant = restaurant();

        [$status, $output] = runProvision(['--phone-number' => '+442079460000', '--force' => true]);

        expect($status)->toBe(1)
            ->and($output)->toContain('TWILIO_AUTH_TOKEN')
            ->and($restaurant->refresh()->elevenlabs_phone_number_id)->toBeNull();
    });
});

describe('when it cannot proceed', function (): void {
    it('says which restaurant slug it could not find', function (): void {
        restaurant(['slug' => 'ember-grill']);

        [$status, $output] = runProvision(['--restaurant' => 'the-other-place', '--dry-run' => true]);

        expect($status)->toBe(1)
            ->and($output)->toContain('the-other-place');
    });

    it('provisions the restaurant it was pointed at', function (): void {
        restaurant(['slug' => 'ember-grill', 'name' => 'Ember Grill']);
        $second = restaurant(['slug' => 'the-other-place', 'name' => 'The Other Place']);

        [$status] = runProvision(['--restaurant' => 'the-other-place']);

        expect($status)->toBe(0)
            ->and($second->refresh()->provisioned_at)->not->toBeNull();
    });

    it('sends somebody to migrate --seed when there is nothing to provision', function (): void {
        [$status, $output] = runProvision();

        expect($status)->toBe(1)
            ->and($output)->toContain('migrate --seed');
    });

    /*
     * Nine tools registered against a token that authenticates nothing is a
     * worse outcome than a command that refuses to run, because it looks like
     * it worked.
     */
    it('refuses to register tools that could never call anything', function (): void {
        config(['restaurantline.agent.token' => '']);
        restaurant();

        [$status, $output] = runProvision();

        expect($status)->toBe(1)
            ->and($output)->toContain('AGENT_API_TOKEN is empty')
            ->and($output)->toContain('safe to run again')
            ->and(provisioningWorkspace()->tools())->toBe([]);
    });
});
