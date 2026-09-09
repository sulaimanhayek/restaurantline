<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    /**
     * No header actions.
     *
     * The generated page offers "New order". Orders come from the phone line —
     * see OrderResource::canCreate().
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
