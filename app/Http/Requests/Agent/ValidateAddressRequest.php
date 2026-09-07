<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent;

use App\Rules\NoCardNumber;

final class ValidateAddressRequest extends AgentRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Kept verbatim on the Address row as `raw_spoken_text`, forever.
            // When a driver cannot find a house six months from now, this is
            // the field that explains why.
            'spoken' => ['required', 'string', 'min:2', 'max:500', new NoCardNumber],
            'conversation_id' => ['required', 'string', 'max:255'],
        ];
    }
}
