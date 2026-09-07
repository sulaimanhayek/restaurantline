<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\FulfilmentType;
use App\Models\Restaurant;

/**
 * Everything needed to price an order that does not exist yet.
 *
 * The quote endpoint builds one of these from what the agent has gathered so
 * far and throws it away; order creation builds the same shape and persists the
 * result. One pricing path, so a caller cannot be quoted one total and charged
 * another.
 */
final readonly class Cart
{
    /**
     * @param  list<CartLine>  $lines
     * @param  int|null  $distanceMetres  Null for collection, or for a delivery
     *                                    whose address has not been resolved yet
     *                                    — in which case the restaurant's base
     *                                    delivery fee applies.
     */
    public function __construct(
        public Restaurant $restaurant,
        public FulfilmentType $fulfilment,
        public array $lines = [],
        public ?int $distanceMetres = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function isDelivery(): bool
    {
        return $this->fulfilment === FulfilmentType::Delivery;
    }

    /**
     * @param  list<CartLine>  $lines
     */
    public function withLines(array $lines): self
    {
        return new self($this->restaurant, $this->fulfilment, $lines, $this->distanceMetres);
    }

    public function withDistance(?int $metres): self
    {
        return new self($this->restaurant, $this->fulfilment, $this->lines, $metres);
    }
}
