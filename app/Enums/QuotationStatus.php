<?php

namespace App\Enums;

/**
 * Quotation lifecycle: Pending → Preparing → For Approval → Sent → Accepted → Converted
 * (or Rejected / Expired). Admin approval is recorded on the quotation (approved_by/at) and
 * sends it; the customer's acceptance creates the Sales Order.
 */
enum QuotationStatus: string
{
    case Pending = 'pending';
    case Preparing = 'preparing';
    case ForApproval = 'for_approval';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Converted = 'converted';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Preparing => 'Preparing',
            self::ForApproval => 'For Approval',
            self::Sent => 'Sent',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
            self::Converted => 'Converted',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending, self::ForApproval => 'bg-amber-50 text-amber-700 border-amber-200',
            self::Preparing => 'bg-gray-100 text-gray-700 border-gray-200',
            self::Sent => 'bg-blue-50 text-blue-700 border-blue-200',
            self::Accepted, self::Converted => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            self::Rejected => 'bg-rose-50 text-rose-700 border-rose-200',
            self::Expired => 'bg-slate-100 text-slate-600 border-slate-200',
        };
    }

    /**
     * Statuses this one may move to through any action.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Preparing, self::Rejected],
            self::Preparing => [self::ForApproval, self::Rejected],
            self::ForApproval => [self::Sent, self::Preparing, self::Expired, self::Rejected],
            self::Sent => [self::Sent, self::Accepted, self::Rejected, self::Expired, self::Preparing],
            self::Accepted => [self::Converted],
            self::Rejected, self::Expired => [self::Preparing],
            self::Converted => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /**
     * Statuses staff can pick with the general "change status" action. Sending (after
     * approval), the customer's answer and conversion have their own actions.
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return [self::Preparing, self::ForApproval, self::Rejected];
    }

    /** Prices and lines can be changed. Sent quotations must be revised (back to Preparing) first. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Pending, self::Preparing, self::ForApproval], true);
    }

    /** Finished: nothing more happens to it, and it never expires. */
    public function isClosed(): bool
    {
        return in_array($this, [self::Converted, self::Rejected, self::Expired], true);
    }

    public function canConvertToSale(): bool
    {
        return $this === self::Accepted;
    }
}
