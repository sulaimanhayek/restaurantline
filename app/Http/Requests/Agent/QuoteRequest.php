<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent;

use App\Enums\FulfilmentType;
use Illuminate\Validation\Rule;

final class QuoteRequest extends AgentRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fulfilment' => ['required', Rule::enum(FulfilmentType::class)],
            'conversation_id' => ['required', 'string', 'max:255'],
            // Optional here, mandatory on /orders. A caller can reasonably ask
            // "how much would that come to?" before giving an address, and the
            // quote falls back to the flat delivery fee when it has no distance
            // to band on.
            'address_token' => ['nullable', 'string'],
        ] + $this->itemRules();
    }

    public function fulfilment(): FulfilmentType
    {
        return FulfilmentType::from((string) $this->validated()['fulfilment']);
    }
}
