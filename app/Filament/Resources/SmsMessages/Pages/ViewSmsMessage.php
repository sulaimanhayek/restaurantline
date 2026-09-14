<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmsMessages\Pages;

use App\Filament\Resources\SmsMessages\SmsMessageResource;
use App\Models\SmsMessage;
use Filament\Resources\Pages\ViewRecord;

class ViewSmsMessage extends ViewRecord
{
    protected static string $resource = SmsMessageResource::class;

    /**
     * The full number, here and nowhere else.
     *
     * Lists show it masked — a wall of phone numbers on a screen somebody left
     * logged in is personal data nobody needed to see. Opening one message is a
     * deliberate act, and the number is the thing being checked.
     */
    public function getTitle(): string
    {
        /** @var SmsMessage $record */
        $record = $this->getRecord();

        return $record->to_number;
    }
}
