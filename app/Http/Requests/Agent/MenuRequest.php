<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent;

final class MenuRequest extends AgentRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Optional: the agent asks for one category when a caller says
            // "what burgers do you do?", and the whole menu otherwise.
            'category' => ['nullable', 'string', 'max:255'],
            'conversation_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
