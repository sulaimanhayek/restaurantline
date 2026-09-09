<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\UtcDateTime;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A dashboard user — restaurant staff, or the developer who deployed this.
 *
 * `restaurant_id` is nullable so a future install can have an operator who sees
 * across tenants. Today it is always set by the seeder.
 *
 * @property int $id
 * @property int|null $restaurant_id
 * @property string $name
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Restaurant|null $restaurant
 *
 * @method static UserFactory factory($count = null, $state = [])
 */
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'restaurant_id',
        'name',
        'email',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => UtcDateTime::class,
            'password' => 'hashed',
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
        ];
    }

    /** @return BelongsTo<Restaurant, $this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * Whether this user may open the dashboard.
     *
     * Implementing FilamentUser is not optional. Filament's panel middleware
     * falls back to `config('app.env') === 'local'` for models that do not
     * implement it — which is safe by default and means a deployed install
     * locks out the person who just deployed it, with a bare 403 and nothing
     * in the log to say why.
     *
     * There are no roles here: a row in `users` is staff, because nothing
     * creates one except the seeder and `php artisan kitchenline:user`, and
     * there is no public registration route. What is checked is the tenant, so
     * that on the day this install serves two restaurants an account stamped
     * with the wrong one cannot read the other's orders and call recordings.
     * A null `restaurant_id` is the deploying developer — see the class
     * docblock. Add the role check here when you add roles.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->restaurant_id === null) {
            return true;
        }

        return $this->restaurant_id === Restaurant::currentOrNull()?->id;
    }
}
