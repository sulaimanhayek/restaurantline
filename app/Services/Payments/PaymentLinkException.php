<?php

declare(strict_types=1);

namespace App\Services\Payments;

use RuntimeException;

/**
 * A payment link could not be created.
 *
 * This one does throw, unlike a failed text. Creating the link is the first
 * thing the confirmation job does and a failure is usually transient — Stripe
 * having a moment, a network blip — so it is worth the queue's retry. The job
 * catches it on the last attempt and leaves the order payable in cash, which is
 * a worse outcome than a link and a much better one than a silent nothing.
 */
final class PaymentLinkException extends RuntimeException {}
