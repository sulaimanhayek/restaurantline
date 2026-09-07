<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\RestaurantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * The tenant root. Everything else in the schema hangs off this row.
 *
 * restaurantline ships configured for exactly one of these, seeded on first
 * boot. The relationships below are how every query reaches its data, so that
 * introducing real multi-tenancy later means adding a resolver and a global
 * scope rather than rewriting call sites.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $timezone
 * @property string $currency
 * @property string|null $phone_number
 * @property string|null $transfer_phone_number
 * @property string|null $email
 * @property string|null $address_line_1
 * @property string|null $address_line_2
 * @property string|null $city
 * @property string|null $postcode
 * @property string|null $country
 * @property float|null $latitude
 * @property float|null $longitude
 * @property int $delivery_radius_metres
 * @property int $minimum_order_value
 * @property int $base_delivery_fee
 * @property int $collection_prep_minutes
 * @property int $delivery_prep_minutes
 * @property bool $is_accepting_orders
 * @property string|null $agent_tone_of_voice
 * @property string|null $agent_greeting
 * @property string|null $elevenlabs_agent_id
 * @property array<string, string>|null $elevenlabs_tool_ids
 * @property CarbonImmutable|null $provisioned_at
 * @property string|null $twilio_phone_number_sid
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, OpeningHour> $openingHours
 * @property-read Collection<int, OpeningHourOverride> $openingHourOverrides
 * @property-read Collection<int, DeliveryFeeRule> $deliveryFeeRules
 * @property-read Collection<int, MenuCategory> $menuCategories
 * @property-read Collection<int, MenuItem> $menuItems
 * @property-read Collection<int, ModifierGroup> $modifierGroups
 * @property-read Collection<int, Modifier> $modifiers
 * @property-read Collection<int, Customer> $customers
 * @property-read Collection<int, Order> $orders
 * @property-read Collection<int, Conversation> $conversations
 *
 * @method static RestaurantFactory factory($count = null, $state = [])
 */
class Restaurant extends Model
{
    /** @use HasFactory<RestaurantFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'delivery_radius_metres' => 'integer',
            'minimum_order_value' => 'integer',
            'base_delivery_fee' => 'integer',
            'collection_prep_minutes' => 'integer',
            'delivery_prep_minutes' => 'integer',
            'is_accepting_orders' => 'boolean',
            'elevenlabs_tool_ids' => 'array',
            'provisioned_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    // -----------------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------------

    /** @return HasMany<OpeningHour, $this> */
    public function openingHours(): HasMany
    {
        return $this->hasMany(OpeningHour::class)->orderBy('day_of_week')->orderBy('opens_at');
    }

    /** @return HasMany<OpeningHourOverride, $this> */
    public function openingHourOverrides(): HasMany
    {
        return $this->hasMany(OpeningHourOverride::class)->orderBy('date');
    }

    /** @return HasMany<DeliveryFeeRule, $this> */
    public function deliveryFeeRules(): HasMany
    {
        return $this->hasMany(DeliveryFeeRule::class)->orderBy('sort_order');
    }

    /** @return HasMany<MenuCategory, $this> */
    public function menuCategories(): HasMany
    {
        return $this->hasMany(MenuCategory::class)->orderBy('sort_order');
    }

    /** @return HasMany<MenuItem, $this> */
    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    /** @return HasMany<ModifierGroup, $this> */
    public function modifierGroups(): HasMany
    {
        return $this->hasMany(ModifierGroup::class)->orderBy('sort_order');
    }

    /** @return HasMany<Modifier, $this> */
    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class);
    }

    /** @return HasMany<Customer, $this> */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<Conversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * "Now", in the restaurant's own timezone.
     *
     * Anything answering "are we open?" or "when will this be ready?" must go
     * through here. The application timezone is UTC and is not the right frame
     * for either question.
     */
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone);
    }

    public function money(int $amount): Money
    {
        return Money::of($amount, $this->currency);
    }

    public function minimumOrderValue(): Money
    {
        return $this->money($this->minimum_order_value);
    }

    /**
     * Has this restaurant been wired up to an ElevenLabs agent yet?
     */
    public function isProvisioned(): bool
    {
        return $this->elevenlabs_agent_id !== null;
    }

    /**
     * The restaurant's own coordinates, or null if it has not been geocoded.
     *
     * @return array{lat: float, lon: float}|null
     */
    public function coordinates(): ?array
    {
        if ($this->latitude === null || $this->longitude === null) {
            return null;
        }

        return ['lat' => $this->latitude, 'lon' => $this->longitude];
    }

    /**
     * The single restaurant this installation serves.
     *
     * Every caller goes through here rather than reaching for `first()`
     * directly, so the day multi-tenancy arrives there is one method to change
     * into a tenant resolver instead of a codebase-wide search.
     */
    public static function current(): self
    {
        return self::query()->orderBy('id')->firstOrFail();
    }
}
