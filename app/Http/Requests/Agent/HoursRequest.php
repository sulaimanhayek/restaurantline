<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent;

final class HoursRequest extends AgentRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'conversation_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
