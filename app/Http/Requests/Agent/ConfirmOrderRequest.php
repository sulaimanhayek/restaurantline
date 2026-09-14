<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent;

use App\Enums\PaymentMethod;
use Illuminate\Validation\Rule;

final class ConfirmOrderRequest extends AgentRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'conversation_id' => ['required', 'string', 'max:255'],

            /*
             * Optional, and required only when there is genuinely a question to
             * ask. A restaurant that takes one kind of payment has its agent
             * told not to raise the subject at all, so insisting on a field
             * here would be insisting the agent ask a question with one
             * possible answer. A restaurant that takes both needs the caller's
             * answer, and an agent that confirms without one has skipped a step
             * of the script — better to bounce that than to guess.
             *
             * There is no card field here, and there is not going to be one.
             * See app/Services/Payments/README.md.
             *
             * @see docs/DECISIONS.md #0037
             */
            'payment_method' => [
                $this->restaurant()->offersChoiceOfPaymentMethod() ? 'required' : 'nullable',
                Rule::enum(PaymentMethod::class),
            ],
        ];
    }

    /**
     * How this order is being paid for.
     *
     * When the restaurant offers one method, whatever the agent sent is
     * discarded in favour of the one that exists. That is not defensiveness
     * about a hallucinated value so much as the contract: the agent was never
     * told about the other method, so anything it sends is noise, and a cash
     * shop must not end up texting payment links because a model was creative.
     */
    public function paymentMethod(): PaymentMethod
    {
        $restaurant = $this->restaurant();

        if (! $restaurant->offersChoiceOfPaymentMethod()) {
            return $restaurant->defaultPaymentMethod();
        }

        $method = $this->input('payment_method');

        return is_string($method)
            ? (PaymentMethod::tryFrom($method) ?? $restaurant->defaultPaymentMethod())
            : $restaurant->defaultPaymentMethod();
    }
}
