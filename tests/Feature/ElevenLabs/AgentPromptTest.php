<?php

declare(strict_types=1);

use App\Models\Restaurant;
use App\Services\ElevenLabs\AgentDefinition;
use App\Services\ElevenLabs\AgentPrompt;

/**
 * What the agent is told before it picks up.
 *
 * Testing prose is usually a waste of a test, and these are the exception: four
 * of the rules in this prompt are the rules that keep a restaurant out of
 * trouble, and each of them is one careless edit away from disappearing. Never
 * take a card number. Read the order back before writing it down. Read the
 * address back before promising a driver. Always offer a person.
 *
 * The assertions are deliberately loose about wording and strict about the rule
 * being present, so the prompt can be rewritten without a test rewriting it
 * back.
 */
function prompt(?Restaurant $restaurant = null): string
{
    return (new AgentPrompt($restaurant ?? restaurant()))->systemPrompt();
}

describe('the rules that protect the restaurant', function (): void {
    it('forbids card details in the strongest terms it has', function (): void {
        expect(strtolower(prompt()))
            ->toContain('never ask for, accept, repeat or write down card details');
    });

    it('says to read the order back before writing it down', function (): void {
        expect(strtolower(prompt()))->toContain('read')
            ->and(strtolower(prompt()))->toContain('back');
    });

    it('says a delivery address is confirmed out loud or not at all', function (): void {
        expect(strtolower(prompt()))->toContain('address_confirmed');
    });

    it('tells the agent that reaching for a human is never the wrong call', function (): void {
        expect(prompt())->toContain('escalate_to_human');
    });

    /*
     * The whole reason nine tools exist. A model answering from memory is a
     * model quoting last year's prices with total confidence.
     */
    it('forbids answering about the menu from memory', function (): void {
        expect(strtolower(prompt()))->toContain('never')
            ->and(prompt())->toContain('search_menu');
    });
});

describe('what it takes from the restaurant row', function (): void {
    it('uses the restaurant name', function (): void {
        expect(prompt(restaurant(['name' => 'The Marigold'])))->toContain('The Marigold');
    });

    it('uses the tone of voice somebody set in the dashboard', function (): void {
        expect(prompt(restaurant(['agent_tone_of_voice' => 'Brisk, dry, faintly Yorkshire.'])))
            ->toContain('Brisk, dry, faintly Yorkshire.');
    });

    it('mentions a minimum order when there is one', function (): void {
        expect(prompt(restaurant(['minimum_order_value' => 1500])))->toContain('minimum order');
    });

    it('says nothing about a minimum order when there is none', function (): void {
        expect(prompt(restaurant(['minimum_order_value' => 0])))->not->toContain('minimum order');
    });

    it('asks how they want to pay only when there are two answers', function (): void {
        expect(strtolower(prompt(restaurant(['accepts_card_link' => true, 'accepts_cash' => true]))))
            ->toContain('ask how they would like to pay')
            ->and(strtolower(prompt(restaurant(['accepts_card_link' => false, 'accepts_cash' => true]))))
            ->not->toContain('ask how they would like to pay');
    });

    it('speaks the delivery radius the way a person would say it', function (): void {
        expect(prompt(restaurant(['delivery_radius_metres' => 5000])))->toContain('about 5 kilometres')
            ->and(prompt(restaurant(['delivery_radius_metres' => 5000])))->not->toContain('about about');
    });
});

describe('the first thing the caller hears', function (): void {
    it('is the greeting the restaurant wrote', function (): void {
        expect((new AgentPrompt(restaurant(['agent_greeting' => 'Marigold, order line.'])))->firstMessage())
            ->toBe('Marigold, order line.');
    });

    it('falls back to something a restaurant would actually say', function (): void {
        expect((new AgentPrompt(restaurant(['agent_greeting' => null, 'name' => 'The Marigold'])))->firstMessage())
            ->toContain('The Marigold');
    });
});

describe('the agent definition it goes into', function (): void {
    it('carries the restaurant timezone, so closing time means closing time there', function (): void {
        $restaurant = restaurant(['timezone' => 'Europe/Lisbon']);
        $payload = (new AgentDefinition($restaurant, new AgentPrompt($restaurant)))->payload(['tool_1']);

        expect($payload['conversation_config']['agent']['prompt']['timezone'])->toBe('Europe/Lisbon');
    });

    /*
     * Not a feature — a ceiling on what one stuck conversation can cost.
     */
    it('caps how long a single call can run', function (): void {
        $restaurant = restaurant();
        $payload = (new AgentDefinition($restaurant, new AgentPrompt($restaurant)))->payload([]);

        expect($payload['conversation_config']['conversation']['max_duration_seconds'])->toBe(600);
    });

    it('offers a transfer only when there is a number to transfer to', function (): void {
        $with = restaurant(['transfer_phone_number' => '+441134960000']);
        $without = restaurant(['transfer_phone_number' => null, 'slug' => 'no-transfer']);

        $tools = fn (Restaurant $r): array => (new AgentDefinition($r, new AgentPrompt($r)))
            ->payload([])['conversation_config']['agent']['prompt']['built_in_tools'];

        expect($tools($with))->toHaveKeys(['end_call', 'transfer_to_number'])
            ->and($tools($with)['transfer_to_number']['params']['transfers'][0]['transfer_destination'])
            ->toBe(['type' => 'phone', 'phone_number' => '+441134960000'])
            ->and($tools($without))->toHaveKey('end_call')
            ->and($tools($without))->not->toHaveKey('transfer_to_number');
    });

    it('attaches the post-call webhook to this agent rather than the workspace', function (): void {
        $restaurant = restaurant();
        $payload = (new AgentDefinition($restaurant, new AgentPrompt($restaurant)))->payload([], 'wh_123');

        expect($payload['platform_settings']['workspace_overrides']['webhooks'])
            ->toBe(['post_call_webhook_id' => 'wh_123', 'events' => ['transcript', 'audio', 'call_initiation_failure']]);
    });

    it('leaves the voice to the workspace default when none is configured', function (): void {
        config(['restaurantline.elevenlabs.voice_id' => '']);

        $restaurant = restaurant();
        $tts = (new AgentDefinition($restaurant, new AgentPrompt($restaurant)))
            ->payload([])['conversation_config']['tts'];

        expect($tts)->not->toHaveKey('voice_id')
            ->and($tts['model_id'])->toBe('eleven_flash_v2_5');
    });
});
