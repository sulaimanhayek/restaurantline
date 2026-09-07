<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The named things that can go wrong inside an ordinary phone call.
 *
 * None of these is a bug. Every one of them is something a caller can say that
 * the restaurant cannot do, and the agent needs to recognise each so it can
 * respond differently — offering alternatives for an unknown item, asking for a
 * postcode for an unclear address, suggesting collection when an address is out
 * of range. Matching on a stable code is far more reliable than matching on the
 * wording of a sentence.
 *
 * They travel in an HTTP 200 body under `error.code` (#0017). Genuine request
 * faults — a bad token, a malformed payload — are HTTP status codes and are
 * deliberately not in this list.
 */
enum AgentErrorCode: string
{
    // Menu
    case ItemNotFound = 'item_not_found';
    case ItemAmbiguous = 'item_ambiguous';
    case ItemUnavailable = 'item_unavailable';
    case ModifierNotFound = 'modifier_not_found';
    case ModifierUnavailable = 'modifier_unavailable';

    // Address and delivery
    case AddressNotFound = 'address_not_found';
    case AddressAmbiguous = 'address_ambiguous';
    case AddressNotConfirmed = 'address_not_confirmed';
    case AddressTokenInvalid = 'address_token_invalid';
    case AddressTokenExpired = 'address_token_expired';
    case OutsideDeliveryArea = 'outside_delivery_area';

    // The order itself
    case EmptyOrder = 'empty_order';
    case BelowMinimum = 'below_minimum';
    case OrderNotFound = 'order_not_found';
    case OrderAlreadyConfirmed = 'order_already_confirmed';
    case OrderNotConfirmable = 'order_not_confirmable';

    // The restaurant
    case Closed = 'closed';
    case NotAcceptingOrders = 'not_accepting_orders';

    /** The one code that is a bug rather than a conversation. */
    case ServerError = 'server_error';
}
