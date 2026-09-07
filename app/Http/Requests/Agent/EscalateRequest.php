<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent;

use App\Rules\NoCardNumber;

final class EscalateRequest extends AgentRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'conversation_id' => ['required', 'string', 'max:255'],
            // Free text on purpose. An enum here would mean deciding in advance
            // what callers get stuck on, and the whole value of this endpoint is
            // learning what they actually get stuck on.
            'reason' => ['required', 'string', 'min:2', 'max:500', new NoCardNumber],
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->validated()['reason']);
    }
}
