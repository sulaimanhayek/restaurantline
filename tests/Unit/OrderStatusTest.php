<?php

declare(strict_types=1);

use App\Enums\ModifierKind;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;

it('keeps unconfirmed orders off the kitchen display', function (): void {
    // The whole point of the confirming step: a caller who hung up mid-sentence
    // must not put food on the grill.
    expect(OrderStatus::Confirming->isLiveOnKitchenDisplay())->toBeFalse()
        ->and(OrderStatus::Draft->isLiveOnKitchenDisplay())->toBeFalse()
        ->and(OrderStatus::Confirmed->isLiveOnKitchenDisplay())->toBeTrue()
        ->and(OrderStatus::Preparing->isLiveOnKitchenDisplay())->toBeTrue()
        ->and(OrderStatus::Ready->isLiveOnKitchenDisplay())->toBeTrue()
        ->and(OrderStatus::Completed->isLiveOnKitchenDisplay())->toBeFalse();
});

it('treats completed, cancelled and failed as terminal', function (): void {
    expect(OrderStatus::Completed->isTerminal())->toBeTrue()
        ->and(OrderStatus::Cancelled->isTerminal())->toBeTrue()
        ->and(OrderStatus::Failed->isTerminal())->toBeTrue()
        ->and(OrderStatus::Preparing->isTerminal())->toBeFalse();
});

it('has no payment state for a card taken over the phone', function (): void {
    $values = array_map(fn (PaymentStatus $s): string => $s->value, PaymentStatus::cases());

    expect($values)->not->toContain('card_on_phone')
        ->and($values)->toContain('link_sent')
        ->and($values)->toContain('cash_on_collection');
});

it('prefixes removals on a kitchen ticket so a chef cannot misread them', function (): void {
    expect(ModifierKind::Removal->ticketPrefix())->toBe('NO ')
        ->and(ModifierKind::Addon->ticketPrefix())->toBe('+ ')
        ->and(ModifierKind::Swap->ticketPrefix())->toBe('→ ')
        ->and(ModifierKind::Option->ticketPrefix())->toBe('');
});
