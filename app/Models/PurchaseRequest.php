<?php

namespace App\Models;

use App\Traits\TracksDeletions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PurchaseRequest extends Model
{
    use HasFactory, TracksDeletions;

    protected $fillable = [
        'team_id',
        'request_number',
        'product_id',
        'supplier_id',
        'requested_by_id',
        'quantity',
        'estimated_cost',
        'status',
        'notes',
        'requested_at',
        'ordered_at',
        'delivered_at',
        'deleted_by_id',
        'current_stock',
        'forecast_quantity',
        'ahp_score',
        'ahp_classification',
        'recommended_quantity',
        'justification',
        'submitted_at',
        'reviewed_by_id',
        'reviewed_at',
        'review_remarks',
    ];

    public const DRAFT = 'Draft';

    public const PENDING = 'Pending Approval';

    public const APPROVED = 'Approved';

    public const REJECTED = 'Rejected';

    public const CONVERTED = 'Converted to Purchase Order';

    public const STATUSES = [self::DRAFT, self::PENDING, self::APPROVED, self::REJECTED, self::CONVERTED];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'current_stock' => 'integer',
            'forecast_quantity' => 'integer',
            'recommended_quantity' => 'integer',
            'ahp_score' => 'float',
            'estimated_cost' => 'decimal:2',
            'requested_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'ordered_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    public function purchaseOrder(): HasOne
    {
        return $this->hasOne(PurchaseOrder::class, 'purchase_request_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    /**
     * Generate the next purchase request number for a team.
     */
    public static function generateRequestNumber(int $teamId): string
    {
        $year = date('Y');
        $n = static::withTrashed()->where('team_id', $teamId)->whereYear('created_at', $year)->count() + 1;
        // Numbers are unique per team (archived ones included), so skip any already taken.
        while (static::withTrashed()->where('team_id', $teamId)->where('request_number', $candidate = sprintf('PR-%s-%04d', substr($year, 2), $n))->exists()) {
            $n++;
        }

        return $candidate;
    }
}
