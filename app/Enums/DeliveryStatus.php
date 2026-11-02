<?php

namespace App\Enums;

/**
 * Delivery status of a Sales Order, separate from the order and payment status.
 *
 * Order Confirmed → Preparing → Ready for Delivery → Out for Delivery → Delivered, with
 * Delivery Attempt Failed and Cancelled. Pickup orders skip Out for Delivery. With partial
 * deliveries the status goes back to Preparing until everything is received; it is Delivered
 * only when nothing is owed any more.
 */
enum DeliveryStatus: string
{
    case OrderConfirmed = 'order_confirmed';
    case Preparing = 'preparing';
    case ReadyForDelivery = 'ready_for_delivery';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case DeliveryAttemptFailed = 'delivery_attempt_failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::OrderConfirmed => 'Order Confirmed',
            self::Preparing => 'Preparing',
            self::ReadyForDelivery => 'Ready for Delivery',
            self::OutForDelivery => 'Out for Delivery',
            self::Delivered => 'Delivered',
            self::DeliveryAttemptFailed => 'Delivery Attempt Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Statuses this one may move to. Staying on the same status (e.g. the next release starting
     * while Preparing) is always allowed.
     *
     * @return list<self>
     */
    public function allowedNext(bool $pickup = false): array
    {
        return match ($this) {
            self::OrderConfirmed => [self::Preparing, self::Delivered, self::Cancelled],
            self::Preparing => [self::ReadyForDelivery, self::OrderConfirmed, self::Delivered, self::Cancelled],
            self::ReadyForDelivery => $pickup
                ? [self::Delivered, self::Preparing, self::OrderConfirmed, self::Cancelled]
                : [self::OutForDelivery, self::Preparing, self::OrderConfirmed, self::Cancelled],
            self::OutForDelivery => [self::Delivered, self::Preparing, self::DeliveryAttemptFailed],
            self::DeliveryAttemptFailed => [self::OutForDelivery, self::ReadyForDelivery, self::Preparing],
            self::Delivered, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $next, bool $pickup = false): bool
    {
        return $next === $this || in_array($next, $this->allowedNext($pickup), true);
    }

    /** An unresolved delivery problem: the order cannot be completed while it lasts. */
    public function isException(): bool
    {
        return $this === self::DeliveryAttemptFailed;
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled], true);
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::OrderConfirmed, self::Preparing => 'bg-amber-50 text-amber-700 border-amber-200',
            self::ReadyForDelivery, self::OutForDelivery => 'bg-blue-50 text-blue-700 border-blue-200',
            self::Delivered => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            self::DeliveryAttemptFailed => 'bg-rose-50 text-rose-700 border-rose-200',
            self::Cancelled => 'bg-slate-100 text-slate-600 border-slate-200',
        };
    }
}
