<?php

namespace App\Models;

use App\Traits\TracksDeletions;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Delivery extends Model
{
    use HasFactory, TracksDeletions;

    protected $fillable = [
        'team_id',
        'delivery_date',
        'delivery_number',
        'purchase_order_number',
        'purchase_order_id',
        'quantity',
        'accepted_quantity',
        'rejected_quantity',
        'inspection_result',
        'inspected_on',
        'inspected_by_id',
        'inspection_remarks',
        'rejection_reason',
        'unit',
        'description',
        'supplier_id',
        'received_by_id',
        'status',
        'notes',
        'deleted_by_id',
        'supplier_document_number',
        'over_receipt_reason',
        'over_receipt_approved_by_id',
        'inspection_number',
        'rejection_category',
        'resolution_status',
        'resolution_remarks',
        'resolved_at',
        'posted_quantity',
        'posted_at',
        'posted_by_id',
        'stock_movement_id',
        'damaged_quantity',
        'defective_quantity',
    ];

    /** Kinds of rejection recorded at inspection. */
    public const REJECTION_CATEGORIES = ['defective', 'damaged', 'incorrect_item', 'nonconforming_spec', 'shortage_in_pack', 'other'];

    /** How rejected units were resolved with the supplier. */
    public const RESOLUTIONS = ['returned', 'replaced', 'credited', 'written_off'];

    protected $casts = [
        'delivery_date' => 'date',
        'quantity' => 'integer',
        'accepted_quantity' => 'integer',
        'rejected_quantity' => 'integer',
        'posted_quantity' => 'integer',
        'damaged_quantity' => 'integer',
        'defective_quantity' => 'integer',
        'inspected_on' => 'date',
        'resolved_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    /**
     * Inspection status shown to staff, derived from the recorded quantities:
     * Pending Inspection, Passed, Partially Accepted, Failed, or Pending Resolution
     * (rejected units exist and no supplier resolution has been recorded yet).
     */
    public function inspectionStatus(): string
    {
        if ($this->inspection_result === null) {
            return 'Pending Inspection';
        }
        if ((int) $this->rejected_quantity > 0 && $this->resolution_status === null) {
            return 'Pending Resolution';
        }

        return match ($this->inspection_result) {
            'accepted' => 'Passed',
            'rejected' => 'Failed',
            default => 'Partially Accepted',
        };
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(CommercialInvoice::class, 'delivery_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function inspectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_id');
    }

    /**
     * Generate unique delivery number, e.g. DEL-102526-001
     */
    public static function generateDeliveryNumber(int $teamId, mixed $date = null): string
    {
        $parsedDate = $date ? Carbon::parse($date) : now();
        $dateCode = $parsedDate->format('mdy'); // e.g. 102526

        $count = static::where('team_id', $teamId)
            ->whereDate('delivery_date', $parsedDate->toDateString())
            ->count() + 1;

        $counter = $count;
        do {
            $seq = str_pad((string) $counter, 3, '0', STR_PAD_LEFT);
            $candidate = "DEL-{$dateCode}-{$seq}";
            $counter++;
        } while (static::where('team_id', $teamId)->where('delivery_number', $candidate)->exists());

        return $candidate;
    }
}
