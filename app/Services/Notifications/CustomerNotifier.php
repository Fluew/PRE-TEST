<?php

namespace App\Services\Notifications;

use App\Enums\EmailStatus;
use App\Jobs\SendCustomerNotification;
use App\Models\NotificationLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Queues one customer email for one business event.
 *
 * - The key identifies the event (e.g. "sales_order_created:12"): the same key is never
 *   emailed twice, so retried or repeated actions do not send duplicates.
 * - The log row is written in the caller's transaction and the job is dispatched after it
 *   commits: nothing is sent for a change that was rolled back, and a failed email never
 *   undoes the business change.
 */
class CustomerNotifier
{
    /** type => history category */
    public const TYPES = [
        'quotation_sent' => 'quotation',
        'quotation_accepted' => 'quotation',
        'order_completed' => 'sales_order',
        'sales_order_created' => 'sales_order',
        'order_confirmed' => 'sales_order',
        'order_cancelled' => 'sales_order',
        'payment_submitted' => 'payment',
        'payment_rejected' => 'payment',
        'payment_verified' => 'receipt',
        'invoice_issued' => 'invoice',
        'delivery_status' => 'delivery',
    ];

    /** @param  array<string, mixed>  $context */
    public function notify(string $type, Model $record, string $key, array $context = []): ?NotificationLog
    {
        try {
            // A savepoint, so a duplicate key does not abort the caller's transaction.
            $log = DB::transaction(fn () => NotificationLog::create([
                'team_id' => $record->getAttribute('team_id'),
                'category' => self::TYPES[$type] ?? 'other',
                'type' => $type,
                'notifiable_type' => class_basename($record),
                'notifiable_id' => $record->getKey(),
                'status' => EmailStatus::Pending,
                'idempotency_key' => $key,
                'context' => $context ?: null,
            ]));
        } catch (UniqueConstraintViolationException) {
            return null; // this event was already emailed (or is being emailed)
        }

        SendCustomerNotification::dispatch($log->id)->afterCommit();

        return $log;
    }

    /** Send a failed email again (same event, same log entry). */
    public function retry(NotificationLog $log): void
    {
        if ($log->status === EmailStatus::Sent || $log->status === EmailStatus::Delivered) {
            return;
        }
        $log->update(['status' => EmailStatus::Pending, 'error' => null]);
        SendCustomerNotification::dispatch($log->id)->afterCommit();
    }
}
