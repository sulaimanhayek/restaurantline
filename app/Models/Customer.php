<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Someone who has called.
 *
 * Identified by phone number, because that is the one thing every caller
 * arrives with. Numbers are normalised to E.164 before they reach this model,
 * so a returning caller is recognised whether the network presented their
 * number as +447700900123, 07700 900123 or 447700900123.
 *
 * @property int $id
 * @property int $restaurant_id
 * @property string $phone_number E.164
 * @property string|null $name
 * @property string|null $notes
 * @property int $order_count
 * @property CarbonImmutable|null $first_ordered_at
 * @property CarbonImmutable|null $last_ordered_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Restaurant $restaurant
 * @property-read Collection<int, Address> $addresses
 * @property-read Collection<int, Order> $orders
 *
 * @method static CustomerFactory factory($count = null, $state = [])
 */
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_count' => 'integer',
            'first_ordered_at' => 'immutable_datetime',
            'last_ordered_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Restaurant, $this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /** @return HasMany<Address, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class)->orderByDesc('is_default')->orderByDesc('id');
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class)->latest();
    }

    public function isReturning(): bool
    {
        return $this->order_count > 0;
    }

    /**
     * The address to offer first on a repeat call: "delivering to the same
     * address as last time?"
     */
    public function preferredAddress(): ?Address
    {
        return $this->addresses()->whereNotNull('verified_at')->first();
    }
}
