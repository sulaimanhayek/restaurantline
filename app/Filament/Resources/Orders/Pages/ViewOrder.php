<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\PaymentStatus;
use App\Enums\SmsKind;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Services\Payments\PaymentLink;
use App\Services\Sms\OrderMessages;
use App\Services\Sms\SmsDispatcher;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Update status'),

            $this->resendPaymentLinkAction(),
        ];
    }

    /**
     * "They say they never got the link."
     *
     * The commonest support call this application generates, and the fix is a
     * button rather than an explanation of how to find the URL and paste it
     * into a phone. It sends the link this order already has — the same URL,
     * the same Stripe session — so pressing it twice cannot create two ways to
     * pay for one order.
     *
     * Hidden once the order is paid, which is the other half of the same
     * thought: the fastest way to confuse a customer who has already paid is to
     * text them a payment link.
     */
    private function resendPaymentLinkAction(): Action
    {
        return Action::make('resendPaymentLink')
            ->label('Text the link again')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Send the payment link again?')
            ->modalDescription(fn (Order $record): string => sprintf(
                'Texts the same link to %s.',
                $record->customer->phone_number ?? 'the customer',
            ))
            ->modalSubmitActionLabel('Send it')
            ->visible(fn (Order $record): bool => filled($record->payment_link_url)
                && filled($record->payment_reference)
                && $record->payment_status !== PaymentStatus::Paid
                && $record->customer !== null)
            ->action(function (Order $record): void {
                $link = new PaymentLink(
                    url: (string) $record->payment_link_url,
                    reference: (string) $record->payment_reference,
                    expiresAt: $record->payment_link_expires_at,
                );

                $message = app(SmsDispatcher::class)->toOrder(
                    $record,
                    SmsKind::PaymentLink,
                    OrderMessages::paymentLink($record, $link),
                );

                // The dispatcher records a refusal rather than throwing one, so
                // the notification reads the row it wrote. Telling somebody a
                // text was sent when the provider rejected it is how a customer
                // ends up waiting all evening.
                if ($message === null || ! $message->status->wasSent()) {
                    Notification::make()
                        ->title('Not sent')
                        ->body($message->error ?? 'There is nobody to text on this order.')
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                // `link_sent` rather than left at whatever it was: the customer
                // has now been shown a link, and the kitchen ticket says so.
                if ($record->payment_status === PaymentStatus::Unpaid) {
                    $record->update(['payment_status' => PaymentStatus::LinkSent]);
                }

                Notification::make()
                    ->title('Sent')
                    ->body('The link is on its way to '.$message->maskedNumber().'.')
                    ->success()
                    ->send();
            });
    }
}
