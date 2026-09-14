<?php

declare(strict_types=1);

use App\Models\Restaurant;
use App\Services\ElevenLabs\ToolDefinitions;

/**
 * The nine tool definitions, checked against the routes they claim to call.
 *
 * Nothing here talks to ElevenLabs. What is worth testing is the join: that
 * every tool points at a route this application actually serves, that the
 * shapes obey ElevenLabs' schema dialect rather than JSON Schema, and that the
 * bearer token reaches the tools as a secret reference rather than as a string
 * in a payload.
 *
 * A wrong URL here is not a test failure in production — it is an agent that
 * answers the phone, sounds perfect, and cannot look up a single dish.
 */
/**
 * @return array<string, array<string, mixed>>
 */
function tools(?Restaurant $restaurant = null, string $secretId = 'secret_abc'): array
{
    return (new ToolDefinitions($restaurant ?? restaurant(), $secretId))->all();
}

/**
 * Every property object in a tool, however deeply nested.
 *
 * @param  list<array<string, mixed>>  $found
 * @return list<array<string, mixed>>
 */
function properties(mixed $node, array $found = []): array
{
    if (! is_array($node)) {
        return $found;
    }

    if (isset($node['type']) && is_string($node['type'])) {
        $found[] = $node;
    }

    foreach ($node as $child) {
        $found = properties($child, $found);
    }

    return $found;
}

it('defines exactly the nine tools, named after what they do', function (): void {
    expect(array_keys(tools()))->toBe([
        'search_menu',
        'show_menu',
        'check_availability',
        'opening_hours',
        'validate_address',
        'quote_order',
        'create_order',
        'confirm_order',
        'escalate_to_human',
    ]);
});

it('points every tool at a route this application serves', function (): void {
    $expected = [
        'search_menu' => ['POST', '/api/agent/menu/search'],
        'show_menu' => ['GET', '/api/agent/menu'],
        'check_availability' => ['POST', '/api/agent/availability'],
        'opening_hours' => ['GET', '/api/agent/hours'],
        'validate_address' => ['POST', '/api/agent/address/validate'],
        'quote_order' => ['POST', '/api/agent/quote'],
        'create_order' => ['POST', '/api/agent/orders'],
        'confirm_order' => ['POST', '/api/agent/orders/{order}/confirm'],
        'escalate_to_human' => ['POST', '/api/agent/escalate'],
    ];

    foreach (tools() as $name => $tool) {
        [$method, $path] = $expected[$name];

        expect($tool['api_schema']['method'])->toBe($method)
            ->and($tool['api_schema']['url'])->toBe(rtrim((string) config('app.url'), '/').$path);
    }
});

/*
 * Laravel would percent-encode the braces if the placeholder were handed to the
 * URL generator as a value, and ElevenLabs would then call
 * /orders/%7Border%7D/confirm for the rest of the restaurant's life.
 */
it('leaves the order number as a placeholder rather than encoding it', function (): void {
    $confirm = tools()['confirm_order'];

    expect($confirm['api_schema']['url'])->toEndWith('/orders/{order}/confirm')
        ->and($confirm['api_schema']['url'])->not->toContain('%7B')
        ->and($confirm['api_schema']['path_params_schema'])->toHaveKey('order')
        ->and($confirm['api_schema']['path_params_schema']['order']['type'])->toBe('string');
});

describe('authentication', function (): void {
    it('references the workspace secret rather than carrying the token', function (): void {
        foreach (tools(secretId: 'secret_xyz') as $name => $tool) {
            expect($tool['api_schema']['request_headers'])
                ->toBe(['Authorization' => ['secret_id' => 'secret_xyz']], $name);
        }
    });

    /*
     * The one thing that must never be true of these payloads. A token written
     * into a tool definition is a token in somebody else's database, in the
     * dashboard, and in every screenshot of it.
     */
    it('puts the token itself nowhere in the payload', function (): void {
        config(['restaurantline.agent.token' => 'sk-the-actual-token']);

        expect(json_encode(tools()))->not->toContain('sk-the-actual-token');
    });
});

describe("ElevenLabs' schema dialect, which is not JSON Schema", function (): void {
    it('gives every property a type and a property_kind where one is due', function (): void {
        foreach (tools() as $name => $tool) {
            foreach (properties($tool['api_schema']) as $property) {
                expect($property['type'])->toBeIn(['string', 'integer', 'number', 'boolean', 'object', 'array'], $name);

                if (in_array($property['type'], ['object', 'array'], true)) {
                    expect($property)->toHaveKey('property_kind');
                }
            }
        }
    });

    it('describes an array with one schema rather than a list of them', function (): void {
        $items = tools()['create_order']['api_schema']['request_body_schema']['properties']['items'];

        expect($items['type'])->toBe('array')
            ->and($items['property_kind'])->toBe('array')
            ->and($items['items'])->toBeArray()
            ->and($items['items']['type'])->toBe('object')
            ->and(array_is_list($items['items']))->toBeFalse();
    });

    it('uses none of the JSON Schema keywords ElevenLabs does not implement', function (): void {
        $encoded = (string) json_encode(tools());

        foreach (['additionalProperties', '$ref', 'oneOf', 'anyOf', 'allOf', 'nullable'] as $keyword) {
            expect($encoded)->not->toContain($keyword);
        }
    });

    /*
     * `description` is mutually exclusive with `dynamic_variable`, and the API
     * rejects a property carrying both. It is also the right design: the model
     * is not the one filling this in, so there is nothing to tell it.
     */
    it('never describes a property the platform fills in', function (): void {
        foreach (tools() as $name => $tool) {
            foreach (properties($tool['api_schema']) as $property) {
                if (isset($property['dynamic_variable'])) {
                    expect($property)->not->toHaveKey('description', $name);
                }
            }
        }
    });

    it('survives being encoded as JSON', function (): void {
        expect(json_encode(tools(), JSON_THROW_ON_ERROR))->toBeString();
    });
});

it('takes the conversation id from the platform on every tool', function (): void {
    foreach (tools() as $name => $tool) {
        $schema = $tool['api_schema'];

        $properties = $schema['method'] === 'GET'
            ? $schema['query_params_schema']['properties']
            : $schema['request_body_schema']['properties'];

        expect($properties['conversation_id'])
            ->toBe(['type' => 'string', 'dynamic_variable' => 'system__conversation_id'], $name);
    }
});

describe('payment method', function (): void {
    /*
     * The question is only asked when there are two answers to it. Offering a
     * caller a way to pay the restaurant cannot take is worse than not asking.
     */
    it('is offered when the restaurant takes both', function (): void {
        $tool = tools(restaurant(['accepts_card_link' => true, 'accepts_cash' => true]))['confirm_order'];
        $body = $tool['api_schema']['request_body_schema'];

        expect($body['properties']['payment_method']['enum'])->toBe(['card_link', 'cash'])
            ->and($body['required'])->toContain('payment_method');
    });

    it('is not asked about at all when there is only one way to pay', function (): void {
        $tool = tools(restaurant(['accepts_card_link' => false, 'accepts_cash' => true]))['confirm_order'];
        $body = $tool['api_schema']['request_body_schema'];

        expect($body['properties'])->not->toHaveKey('payment_method')
            ->and($body['required'])->not->toContain('payment_method');
    });
});

/*
 * Twenty seconds is the API default and far too long for somebody holding a
 * phone to their ear.
 */
it('gives every tool a timeout short enough for a phone call', function (): void {
    foreach (tools() as $name => $tool) {
        expect($tool['response_timeout_secs'])->toBeLessThanOrEqual(8, $name)
            ->and($tool['type'])->toBe('webhook');
    }
});
