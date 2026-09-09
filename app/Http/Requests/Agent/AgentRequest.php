<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent;

use App\Models\Restaurant;
use App\Rules\NoCardNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Shared ground for every agent tool request.
 *
 * Authorisation is the bearer-token middleware's job, not this class's — these
 * requests all return true, and a forker looking for the auth check should find
 * it in one obvious place rather than repeated across nine form requests.
 */
abstract class AgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The restaurant this installation serves.
     *
     * One line today. The day multi-tenancy arrives this becomes a lookup on
     * the resolved tenant, and every endpoint follows without being touched.
     */
    public function restaurant(): Restaurant
    {
        return Restaurant::current();
    }

    /**
     * The ElevenLabs conversation this call belongs to.
     *
     * Threaded through every tool call so a failed order can be traced back to
     * the transcript that produced it, and so order creation can be made
     * idempotent against a retried tool call.
     */
    public function conversationId(): ?string
    {
        $id = $this->input('conversation_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Rules for the `items` array that `/quote` and `/orders` share.
     *
     * The caps are not arbitrary. Fifty lines is more than any phone order, and
     * a payload with five hundred is either a bug or someone probing; either
     * way it should bounce before it reaches the database.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    protected function itemRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.item' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:50'],
            'items.*.modifiers' => ['nullable', 'array', 'max:20'],
            'items.*.modifiers.*.modifier' => ['required', 'string', 'max:255'],
            'items.*.modifiers.*.quantity' => ['nullable', 'integer', 'min:1', 'max:10'],
            'items.*.notes' => ['nullable', 'string', 'max:500', new NoCardNumber],
        ];
    }

    /**
     * The resolved `items` payload, with the shape the assembler expects.
     *
     * @return list<array{item: string, quantity?: int, modifiers?: list<array{modifier: string, quantity?: int}>, notes?: string|null}>
     */
    public function items(): array
    {
        /** @var list<array{item: string, quantity?: int, modifiers?: list<array{modifier: string, quantity?: int}>, notes?: string|null}> $items */
        $items = $this->validated()['items'] ?? [];

        return $items;
    }

    /**
     * A malformed payload is one of the three things that is genuinely wrong
     * with the request rather than with the order, so it keeps its 422 (#0017).
     * It still answers in the house shape, with a sentence — a forker holding
     * `curl` gets told what to fix, and an agent that somehow sends a bad body
     * mid-call still has something to say rather than dead air.
     */
    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json([
            'ok' => false,
            'error' => [
                'code' => 'invalid_request',
                'say' => "Sorry, I didn't catch that. Could you say it again?",
                'detail' => $validator->errors()->first(),
            ],
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }
}
