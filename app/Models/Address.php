<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Enums\GeocodeProvider;
use Carbon\CarbonImmutable;
use Database\Factories\AddressFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A delivery address, as spoken and as resolved.
 *
 * @property int $id
 * @property int $restaurant_id
 * @property int $customer_id
 * @property string|null $raw_spoken_text Verbatim; never overwritten
 * @property string|null $formatted_address
 * @property string|null $line_1
 * @property string|null $line_2
 * @property string|null $city
 * @property string|null $postcode
 * @property string|null $country
 * @property float|null $latitude
 * @property float|null $longitude
 * @property float|null $geocode_confidence 0.0–1.0
 * @property GeocodeProvider|null $geocode_provider
 * @property string|null $place_id
 * @property CarbonImmutable|null $verified_at
 * @property int|null $distance_metres
 * @property bool $is_default
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Restaurant $restaurant
 * @property-read Customer $customer
 *
 * @method static AddressFactory factory($count = null, $state = [])
 */
class Address extends Model
{
    /** @use HasFactory<AddressFactory> */
    use HasFactory;

    protected $table = 'addresses';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'geocode_confidence' => 'float',
            'geocode_provider' => GeocodeProvider::class,
            'verified_at' => UtcDateTime::class,
            'distance_metres' => 'integer',
            'is_default' => 'boolean',
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
        ];
    }

    /** @return BelongsTo<Restaurant, $this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @param  Builder<Address>  $query
     */
    public function scopeVerified(Builder $query): void
    {
        $query->whereNotNull('verified_at');
    }

    /**
     * Has the caller heard this address read back and agreed to it?
     *
     * Order creation checks this and refuses a delivery order without it. That
     * makes "confirm the address aloud" a property of the system rather than
     * something we hope the prompt remembered to do.
     */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Mark the address as confirmed by the caller.
     *
     * Called only from the order-creation path, once the agent reports that the
     * caller said yes.
     */
    public function markVerified(): void
    {
        $this->forceFill(['verified_at' => CarbonImmutable::now()])->save();
    }

    public function isWithinDeliveryRadius(): bool
    {
        if ($this->distance_metres === null) {
            return false;
        }

        return $this->distance_metres <= $this->restaurant->delivery_radius_metres;
    }

    /**
     * How the agent reads the address back for confirmation.
     *
     * Deliberately not the full formatted address — reading out a country and a
     * county to someone who just told you where they live is how you lose them.
     * House number, street and postcode is what people check against.
     */
    public function spoken(): string
    {
        $parts = array_filter([
            $this->line_1,
            $this->line_2,
            $this->city,
            $this->postcode,
        ], static fn (?string $part): bool => $part !== null && trim($part) !== '');

        return implode(', ', $parts);
    }

    /**
     * @return array{lat: float, lon: float}|null
     */
    public function coordinates(): ?array
    {
        if ($this->latitude === null || $this->longitude === null) {
            return null;
        }

        return ['lat' => $this->latitude, 'lon' => $this->longitude];
    }
}
