<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent;

final class MenuSearchRequest extends AgentRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // What the caller actually said, not a search term. The matcher
            // strips the filler itself — "erm can I get the peri peri please"
            // is the input this is designed for.
            'query' => ['required', 'string', 'min:1', 'max:255'],
            'conversation_id' => ['required', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:10'],
        ];
    }
}
