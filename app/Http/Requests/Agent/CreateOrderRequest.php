<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent;

use App\Enums\FulfilmentType;
use App\Rules\NoCardNumber;
use Illuminate\Validation\Rule;

final class CreateOrderRequest extends AgentRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fulfilment' => ['required', Rule::enum(FulfilmentType::class)],
            'conversation_id' => ['required', 'string', 'max:255'],

            // A delivery order cannot be created without a sealed address the
            // agent says the caller agreed to. Both halves are required
            // together and neither is sufficient alone: the seal proves the
            // address came out of the geocoder, the flag records that it was
            // read back and confirmed aloud (#0019).
            'address_token' => ['required_if:fulfilment,delivery', 'nullable', 'string'],
            'address_confirmed' => ['required_if:fulfilment,delivery', 'nullable', 'boolean'],

            'customer' => ['required', 'array'],
            'customer.phone_number' => ['required', 'string', 'min:6', 'max:32'],
            'customer.name' => ['nullable', 'string', 'max:120', new NoCardNumber],

            'notes' => ['nullable', 'string', 'max:1000', new NoCardNumber],
        ] + $this->itemRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'address_token.required_if' => 'A delivery order needs an address token from /address/validate.',
            'address_confirmed.required_if' => 'A delivery order needs the address to have been confirmed aloud.',
        ];
    }

    public function fulfilment(): FulfilmentType
    {
        return FulfilmentType::from((string) $this->validated()['fulfilment']);
    }

    public function customerPhoneNumber(): string
    {
        /** @var array{phone_number: string, name?: string|null} $customer */
        $customer = $this->validated()['customer'];

        return $customer['phone_number'];
    }

    public function customerName(): ?string
    {
        /** @var array{phone_number: string, name?: string|null} $customer */
        $customer = $this->validated()['customer'];

        $name = trim((string) ($customer['name'] ?? ''));

        return $name === '' ? null : $name;
    }

    public function addressToken(): ?string
    {
        $token = $this->validated()['address_token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function addressConfirmed(): bool
    {
        return (bool) ($this->validated()['address_confirmed'] ?? false);
    }
}
