<?php

declare(strict_types=1);

use App\Models\Address;

/**
 * `/address/validate` against the fake geocoder, whose east-London table is
 * built to be awkward in the ways real addresses are: three separate buildings
 * on Brick Lane, a flat sharing a street address with a house, and one address
 * well outside any sane delivery radius.
 *
 * Nothing here writes an Address row. That is the point — the table should hold
 * the address a caller confirmed, not the four they tried first.
 */
beforeEach(function (): void {
    $this->restaurant = restaurant();
});

it('finds a confident address and seals it for the order to come', function (): void {
    $response = agentPost('address/validate', [
        'conversation_id' => 'call-1',
        'spoken' => '3 Hanbury Street, E1 6QR',
    ])->assertOk()->assertJsonPath('ok', true)->assertJsonPath('confident', true);

    expect($response->json('candidates.0.address'))->toContain('3 Hanbury Street')
        ->and($response->json('candidates.0.postcode'))->toBe('E1 6QR')
        ->and($response->json('candidates.0.address_token'))->toBeString()->not->toBeEmpty();
});

it('measures the distance and says it the way a person would', function (): void {
    $candidate = agentPost('address/validate', [
        'conversation_id' => 'call-1',
        'spoken' => '3 Hanbury Street, E1 6QR',
    ])->json('candidates.0');

    expect($candidate['distance_metres'])->toBeInt()->toBeLessThan(5000)
        ->and($candidate['distance_spoken'])->toMatch('/^about [\d.]+ (metres|kilometres|kilometre)$/');
});

it('persists nothing, whatever the outcome', function (): void {
    agentPost('address/validate', ['conversation_id' => 'call-1', 'spoken' => '3 Hanbury Street, E1 6QR']);
    agentPost('address/validate', ['conversation_id' => 'call-1', 'spoken' => 'Brick Lane']);

    expect(Address::query()->count())->toBe(0);
});

it('asks which one when a street name alone could be several buildings', function (): void {
    $response = agentPost('address/validate', ['conversation_id' => 'call-1', 'spoken' => 'Brick Lane'])
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'address_ambiguous')
        ->assertJsonPath('ambiguous', true);

    expect($response->json('error.say'))->toStartWith('I found a few that could match. Is it ')
        ->and($response->json('error.say'))->toContain(' or ');
});

/**
 * The agent may well read back the second option and have the caller say yes to
 * that one. Without a token on every candidate that would mean asking them to
 * say the whole address again.
 */
it('seals every candidate, not only the best one', function (): void {
    $tokens = rows(agentPost('address/validate', ['conversation_id' => 'call-1', 'spoken' => 'Brick Lane'])
        ->json('candidates'))->pluck('address_token');

    expect($tokens)->toHaveCount(3)
        ->and($tokens->filter()->unique())->toHaveCount(3);
});

it('offers collection rather than a repeat when the address is simply too far', function (): void {
    agentPost('address/validate', ['conversation_id' => 'call-1', 'spoken' => '14 High Street, Croydon, CR0 1QG'])
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'outside_delivery_area')
        ->assertJsonPath('outside_delivery_area', true)
        ->assertJsonPath('found', true)
        ->assertJsonPath('error.say', "That's about 15.6 kilometres away, which is outside the area we deliver to. You're very welcome to collect.");
});

it('asks for a postcode when it finds nothing at all', function (): void {
    agentPost('address/validate', ['conversation_id' => 'call-1', 'spoken' => 'somewhere in Aberdeen'])
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'address_not_found')
        ->assertJsonPath('found', false)
        ->assertJsonPath('error.say', "I couldn't find that address. Could you give me your postcode?");
});

it('echoes back exactly what the caller said', function (): void {
    agentPost('address/validate', ['conversation_id' => 'call-1', 'spoken' => 'erm, 3 Hanbury Street I think'])
        ->assertJsonPath('spoken_input', 'erm, 3 Hanbury Street I think');
});

it('rejects an empty address', function (): void {
    agentPost('address/validate', ['conversation_id' => 'call-1', 'spoken' => ''])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_request');
});

it('refuses an address field carrying a card number', function (): void {
    agentPost('address/validate', [
        'conversation_id' => 'call-1',
        'spoken' => '3 Hanbury Street, card is 4111111111111111',
    ])->assertStatus(422);
});
