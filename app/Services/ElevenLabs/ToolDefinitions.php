<?php

declare(strict_types=1);

namespace App\Services\ElevenLabs;

use App\Enums\FulfilmentType;
use App\Enums\PaymentMethod;
use App\Models\Restaurant;

/**
 * The nine things the agent can do, in ElevenLabs' vocabulary.
 *
 * One file, one job: turn `routes/agent.php` into nine `tool_config` objects.
 * The route names are the join — change a name there and the URL here follows
 * on the next provision, which is the whole reason those routes are named.
 *
 * Three things about ElevenLabs' parameter schemas are worth knowing before
 * editing anything below, because all three look like JSON Schema and are not:
 *
 *  - A property carries `property_kind` as well as `type`, and an array's
 *    `items` is a single schema, never a list.
 *  - `description` is mutually exclusive with `dynamic_variable`,
 *    `constant_value`, `is_system_provided` and `is_omitted`. A property that
 *    is filled in by the platform does not get described to the model, because
 *    the model is not the one filling it in.
 *  - There is no `additionalProperties`, no `$ref`, no `oneOf`, no nullable
 *    union. A field is optional by being absent from `required`.
 *
 * Everything is built through the four helpers at the bottom so that shape is
 * stated once and nine tools cannot drift from it.
 *
 * The descriptions are prompt, not documentation. They are what the model reads
 * when deciding which tool to reach for, so they say when to call the thing and
 * what it needs — a caller mid-sentence is the audience, not a developer.
 *
 * @see docs/DECISIONS.md #0040
 */
final class ToolDefinitions
{
    /**
     * How long ElevenLabs waits before giving up on one of our endpoints.
     *
     * Twenty is the API default and too long for a phone call: the caller is
     * listening to silence for all of it. Ours are database reads behind a
     * local network hop, and the two that are not — address validation and
     * order creation — still finish well inside eight seconds or have gone
     * wrong in a way that waiting will not fix.
     */
    private const TIMEOUT_SECONDS = 8;

    public function __construct(
        private readonly Restaurant $restaurant,
        private readonly string $secretId,
    ) {}

    /**
     * Every tool, keyed by name.
     *
     * The key is what gets stored in `restaurants.elevenlabs_tool_ids`, so it
     * is also the thing that decides whether the next run patches a tool or
     * creates one. Renaming a tool orphans the old one in the workspace.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $tools = [
            $this->searchMenu(),
            $this->showMenu(),
            $this->checkAvailability(),
            $this->openingHours(),
            $this->validateAddress(),
            $this->quoteOrder(),
            $this->createOrder(),
            $this->confirmOrder(),
            $this->escalateToHuman(),
        ];

        $byName = [];

        foreach ($tools as $tool) {
            $byName[$tool['name']] = $tool;
        }

        return $byName;
    }

    // -----------------------------------------------------------------------
    // Menu
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function searchMenu(): array
    {
        return $this->post(
            'search_menu',
            'agent.menu.search',
            'Look up a dish the caller has asked for by name. Use this every time they name '
            .'something, before you say whether it is available or what it costs — never answer '
            .'from memory. Handles near-misses and mishearings, so pass what you heard. '
            .'Returns matching dishes with their prices and any choices they come with.',
            $this->object([
                'query' => $this->literal('string', 'What the caller called the dish, in their words. "chicken tikka", "the large pepperoni".'),
                'limit' => $this->literal('integer', 'How many matches to return. Leave empty for the default of five; a caller cannot hold more than three in their head anyway.'),
                'conversation_id' => $this->conversationId(),
            ], ['query', 'conversation_id']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function showMenu(): array
    {
        return $this->get(
            'show_menu',
            'agent.menu.show',
            'Read out what is on the menu. Use it when the caller asks what you do, or asks for '
            .'a whole section — "what pizzas have you got?". Pass the section name to narrow it; '
            .'leave it empty and you get the sections, which is the better answer to "what do you do?" '
            .'than forty dishes.',
            [
                'category' => $this->literal('string', 'A section of the menu, as the caller said it. "starters", "curries", "drinks".'),
                'conversation_id' => $this->conversationId(),
            ],
            ['conversation_id'],
        );
    }

    // -----------------------------------------------------------------------
    // Can we take this order, and when
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function checkAvailability(): array
    {
        return $this->post(
            'check_availability',
            'agent.availability',
            'Check the kitchen is open and taking orders, and how long the food will take. '
            .'Call this early — before taking an order, and certainly before promising a time. '
            .'Returns whether we are accepting orders now, the wait in minutes, and when we next open if we are not.',
            $this->object([
                'fulfilment' => $this->fulfilment(),
                'conversation_id' => $this->conversationId(),
            ], ['fulfilment', 'conversation_id']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function openingHours(): array
    {
        return $this->get(
            'opening_hours',
            'agent.hours',
            'The opening times for the week. Use it when the caller asks when you are open, or '
            .'what time you close, rather than to decide whether to take an order right now — '
            .'check_availability answers that and accounts for the kitchen being shut early.',
            ['conversation_id' => $this->conversationId()],
            ['conversation_id'],
        );
    }

    // -----------------------------------------------------------------------
    // Delivery
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function validateAddress(): array
    {
        return $this->post(
            'validate_address',
            'agent.address.validate',
            'Check a delivery address and find out whether we deliver to it. Required for every '
            .'delivery order; collection orders do not need it. Pass what the caller said, word for '
            .'word. Returns matching addresses, each with an address_token. Read the address you '
            .'matched back to the caller and get them to agree to it, then pass that token to '
            .'quote_order and create_order. Also tells you when an address is outside the delivery '
            .'area, which is the moment to offer collection instead.',
            $this->object([
                'spoken' => $this->literal('string', 'The address exactly as the caller gave it, including the postcode if they said one. Do not tidy it up.'),
                'conversation_id' => $this->conversationId(),
            ], ['spoken', 'conversation_id']),
        );
    }

    // -----------------------------------------------------------------------
    // The order
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function quoteOrder(): array
    {
        return $this->post(
            'quote_order',
            'agent.quote',
            'Price an order without creating it. Use it to tell the caller what something will cost, '
            .'and to check the basket is understood, before you commit to anything. Nothing is saved '
            .'and it can be called as often as the order changes. Returns each line with its price, '
            .'any delivery fee, and the total.',
            $this->object([
                'items' => $this->items(),
                'fulfilment' => $this->fulfilment(),
                'address_token' => $this->addressToken('Only needed on a delivery quote, to work out the delivery fee.'),
                'conversation_id' => $this->conversationId(),
            ], ['items', 'fulfilment', 'conversation_id']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function createOrder(): array
    {
        return $this->post(
            'create_order',
            'agent.orders.store',
            'Write the order down. Read the whole thing back to the caller first — every item and '
            .'the total — because this is the point it becomes an order rather than a conversation. '
            .'It is not sent to the kitchen yet: it waits for confirm_order. Returns the order number '
            .'and the total to read out.',
            $this->object([
                'items' => $this->items(),
                'fulfilment' => $this->fulfilment(),
                'customer' => $this->object([
                    'phone_number' => $this->literal('string', 'A number the restaurant can call back on. Use the number they are calling from unless they give a different one.'),
                    'name' => $this->literal('string', 'The name the order is under. Ask for it; a driver at a block of flats needs it.'),
                ], ['phone_number'], 'Who the order is for.'),
                'address_token' => $this->addressToken('Required for delivery. The token from validate_address for the address the caller agreed to.'),
                'address_confirmed' => $this->literal('boolean', 'Required for delivery. True only once you have read the address back and the caller has agreed it is right. Do not set it because you are confident.'),
                'notes' => $this->literal('string', 'Anything the caller asked for that is not a dish: allergies, "no cutlery", "ring the top bell". Never anything about payment.'),
                'conversation_id' => $this->conversationId(),
            ], ['items', 'fulfilment', 'customer', 'conversation_id']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function confirmOrder(): array
    {
        $properties = ['conversation_id' => $this->conversationId()];
        $required = ['conversation_id'];

        /*
         * The question is only asked when there are two answers to it. A
         * restaurant that takes one of the two gets a tool with no
         * `payment_method` at all, so the model has nothing to offer the caller
         * and the application fills it in — rather than a tool that lists an
         * option the restaurant cannot honour.
         */
        if ($this->restaurant->offersChoiceOfPaymentMethod()) {
            $properties['payment_method'] = $this->literal(
                'string',
                'How the caller wants to pay, after you have asked them. "card_link" texts them a link to pay online — '
                .'never take card details over the phone. "cash" means they pay the driver or at the counter.',
                array_map(static fn (PaymentMethod $method): string => $method->value, $this->restaurant->paymentMethods()),
            );
            $required[] = 'payment_method';
        }

        return $this->post(
            'confirm_order',
            'agent.orders.confirm',
            'Send the order to the kitchen. Call this only after the caller has heard the order read '
            .'back and said yes. This is what starts the food being cooked and sends their confirmation '
            .'text. Returns the order number to read back, digit by digit, and how long it will be.',
            $this->object($properties, $required),
            ['order' => $this->literal('string', 'The order number create_order gave you, exactly as it was returned.')],
        );
    }

    // -----------------------------------------------------------------------
    // Always offer a human
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function escalateToHuman(): array
    {
        return $this->post(
            'escalate_to_human',
            'agent.escalate',
            'Flag the call for a person at the restaurant. Use it the moment the caller asks for a '
            .'human, gets frustrated, or wants something you have no tool for — a complaint, a '
            .'booking, a question about an order from yesterday. Reaching for this is never the wrong '
            .'call. Say you are getting someone, then say it has been passed on.',
            $this->object([
                'reason' => $this->literal('string', 'What they need, in one line, so whoever picks this up does not have to start the conversation again.'),
                'conversation_id' => $this->conversationId(),
            ], ['reason', 'conversation_id']),
        );
    }

    // -----------------------------------------------------------------------
    // Shared parameters
    // -----------------------------------------------------------------------

    /**
     * The conversation this call belongs to, filled in by the platform.
     *
     * Deliberately not described: `description` and `dynamic_variable` are
     * mutually exclusive, and there is nothing to tell the model anyway. It
     * never sees this field, never has to remember a string across a dozen tool
     * calls, and cannot get it wrong — which matters, because this is the
     * column that joins an order to the transcript that produced it.
     *
     * @return array<string, mixed>
     */
    private function conversationId(): array
    {
        return ['type' => 'string', 'dynamic_variable' => 'system__conversation_id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function fulfilment(): array
    {
        return $this->literal(
            'string',
            'Whether the caller wants it delivered or is collecting. Ask if they have not said.',
            array_map(static fn (FulfilmentType $type): string => $type->value, FulfilmentType::cases()),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function addressToken(string $note): array
    {
        return $this->literal('string', $note.' Pass it back exactly as validate_address returned it.');
    }

    /**
     * What the caller is ordering.
     *
     * The same shape on both tools that take a basket, because a model that has
     * learnt one is holding the other.
     *
     * @return array<string, mixed>
     */
    private function items(): array
    {
        return $this->arrayOf(
            $this->object([
                'item' => $this->literal('string', 'The dish, as the caller said it. Use the name search_menu returned if you have it.'),
                'quantity' => $this->literal('integer', 'How many. Leave empty for one.'),
                'modifiers' => $this->arrayOf(
                    $this->object([
                        'modifier' => $this->literal('string', 'The choice or extra, as the caller said it. "large", "extra cheese", "no onions".'),
                        'quantity' => $this->literal('integer', 'How many of this extra. Leave empty for one.'),
                    ], ['modifier']),
                    'Choices and extras on this line: size, toppings, anything they asked to leave out.',
                ),
                'notes' => $this->literal('string', 'Anything about this one dish that is not a choice on the menu. "well done", "sauce on the side".'),
            ], ['item']),
            'One entry per line of the order. Two of the same dish with different extras are two entries.',
        );
    }

    // -----------------------------------------------------------------------
    // The four shapes
    // -----------------------------------------------------------------------

    /**
     * A single value the model fills in.
     *
     * `enum` is a list of strings whatever the type, which is ElevenLabs' rule
     * rather than ours.
     *
     * @param  list<string>|null  $enum
     * @return array<string, mixed>
     */
    private function literal(string $type, string $description, ?array $enum = null): array
    {
        $property = ['type' => $type, 'description' => $description];

        if ($enum !== null && $enum !== []) {
            $property['enum'] = $enum;
        }

        return $property;
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private function object(array $properties, array $required = [], string $description = ''): array
    {
        $object = [
            'type' => 'object',
            'property_kind' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];

        return $description === '' ? $object : ['description' => $description] + $object;
    }

    /**
     * @param  array<string, mixed>  $items  One schema, not a list of them.
     * @return array<string, mixed>
     */
    private function arrayOf(array $items, string $description): array
    {
        return [
            'type' => 'array',
            'property_kind' => 'array',
            'description' => $description,
            'items' => $items,
        ];
    }

    // -----------------------------------------------------------------------
    // The two kinds of tool
    // -----------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, array<string, mixed>>  $pathParams
     * @return array<string, mixed>
     */
    private function post(string $name, string $route, string $description, array $body, array $pathParams = []): array
    {
        $schema = ['url' => $this->url($route, $pathParams), 'method' => 'POST', 'request_body_schema' => $body];

        if ($pathParams !== []) {
            $schema['path_params_schema'] = $pathParams;
        }

        return $this->tool($name, $description, $schema);
    }

    /**
     * @param  array<string, array<string, mixed>>  $queryParams
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private function get(string $name, string $route, string $description, array $queryParams, array $required): array
    {
        return $this->tool($name, $description, [
            'url' => $this->url($route),
            'method' => 'GET',
            'query_params_schema' => ['properties' => $queryParams, 'required' => $required],
        ]);
    }

    /**
     * @param  array<string, mixed>  $apiSchema
     * @return array<string, mixed>
     */
    private function tool(string $name, string $description, array $apiSchema): array
    {
        $apiSchema['request_headers'] = [
            /*
             * The whole header value, because a secret locator substitutes the
             * value rather than interpolating into it — which is why the stored
             * secret is `Bearer <token>` and not the bare token. See
             * Provisioner::authorizationSecret().
             */
            'Authorization' => ['secret_id' => $this->secretId],
        ];

        return [
            'type' => 'webhook',
            'name' => $name,
            'description' => $description,
            'response_timeout_secs' => self::TIMEOUT_SECONDS,
            'api_schema' => $apiSchema,
        ];
    }

    /**
     * The absolute URL of a route, with `{param}` left in for ElevenLabs.
     *
     * Built from APP_URL, so getting that wrong is the single most likely
     * reason a freshly provisioned agent cannot reach this application. The
     * provisioning command says so out loud before it sends anything.
     *
     * @param  array<string, array<string, mixed>>  $pathParams
     */
    private function url(string $route, array $pathParams = []): string
    {
        $placeholders = [];

        foreach (array_keys($pathParams) as $param) {
            // Laravel would URL-encode the braces; substituting afterwards is
            // the reliable way to leave ElevenLabs a template to fill in.
            $placeholders[$param] = '__'.$param.'__';
        }

        $url = route($route, $placeholders, absolute: true);

        foreach ($placeholders as $param => $placeholder) {
            $url = str_replace($placeholder, '{'.$param.'}', $url);
        }

        return $url;
    }
}
