<?php

namespace App\Events\Procurement;

use App\Models\CommercialInvoice;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Staff verified a supplier invoice against the order, delivery and inspection. Dispatched only after the change is committed. */
class CommercialInvoiceVerified implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public CommercialInvoice $invoice, public ?User $actor = null) {}
}
