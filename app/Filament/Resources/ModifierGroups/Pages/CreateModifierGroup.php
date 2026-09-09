<?php

declare(strict_types=1);

namespace App\Filament\Resources\ModifierGroups\Pages;

use App\Filament\Concerns\AssignsCurrentRestaurant;
use App\Filament\Resources\ModifierGroups\ModifierGroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateModifierGroup extends CreateRecord
{
    use AssignsCurrentRestaurant;

    protected static string $resource = ModifierGroupResource::class;
}
