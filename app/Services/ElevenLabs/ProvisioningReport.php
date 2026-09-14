<?php

declare(strict_types=1);

namespace App\Services\ElevenLabs;

/**
 * What a provisioning run did, so the command can say so.
 *
 * Separated from `Provisioner` because the interesting output of provisioning
 * is not a return value anybody acts on — it is a paragraph a human reads once
 * and then either relaxes or goes and fixes something.
 */
final class ProvisioningReport
{
    /**
     * @param  array<string, string>  $tools  Tool name => "created" or "updated".
     */
    public function __construct(
        public readonly string $agentId,
        public readonly string $agentAction,
        public readonly array $tools,
        public readonly string $secretId,
        public readonly string $secretAction,
        public readonly ?string $webhookId,
        public readonly string $webhookAction,
        /**
         * The HMAC signing key, on the one run that created the webhook.
         *
         * ElevenLabs returns it exactly once. Null on every subsequent run
         * means "we did not create a webhook this time", not "there isn't one"
         * — there is no way to ask for it again, only to make another webhook.
         */
        public readonly ?string $webhookSecret = null,
        public readonly ?string $phoneNumberId = null,
    ) {}

    public function createdAnything(): bool
    {
        return $this->agentAction === 'created'
            || $this->secretAction === 'created'
            || $this->webhookAction === 'created'
            || in_array('created', $this->tools, strict: true);
    }
}
