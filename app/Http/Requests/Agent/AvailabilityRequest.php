<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent;

use App\Enums\FulfilmentType;
use Illuminate\Validation\Rule;

final class AvailabilityRequest extends AgentRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fulfilment' => ['required', Rule::enum(FulfilmentType::class)],
            'conversation_id' => ['required', 'string', 'max:255'],
        ];
    }

    public function fulfilment(): FulfilmentType
    {
        return FulfilmentType::from((string) $this->validated()['fulfilment']);
    }
}
