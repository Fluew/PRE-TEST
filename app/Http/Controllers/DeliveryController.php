<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryStatus;
use App\Enums\SalesOrderStatus;
use App\Exceptions\WorkflowException;
use App\Models\Delivery;
use App\Models\DeliveryAttemptLog;
use App\Models\DeliveryRelease;
use App\Models\OrderFulfillment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Delivery\FulfillmentService;
use App\Services\Documents\DocumentPdf;
use App\Services\Procurement\ProcurementWorkflow;
use App\Services\Sales\SalesOrderService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeliveryController extends Controller
{
    /**
     * Display deliveries monitoring module and delivery staff dashboard.
     */
    public function index(Request $request): Response
    {
        $team = $this->getTeam($request);
        abort_if(! $team, 403, 'No active team selected.');

        $user = $request->user();
        $isAdmin = $user->isAdmin();
        $isDeliveryStaff = ! $isAdmin && ($user->role === 'staff_deliveries' || in_array('deliveries', $user->assignedModules(), true));

        // Active tab: delivery staff are restricted to outbound customer deliveries.
        $activeTab = $isDeliveryStaff ? 'outbound' : $request->input('tab', 'outbound');

        // ---------------------------------------------------------
        // 1. OUTBOUND CUSTOMER DELIVERIES (Order Fulfillments)
        // ---------------------------------------------------------
        $outboundQuery = OrderFulfillment::where('team_id', $team->id)
            ->with([
                'sale:id,order_number,invoice_number,client_name,grand_total,sale_date,status,channel,fulfillment_type,hold_reason',
                'sale.items:id,sale_id,sku,product_name,quantity,unit_price,reserved_quantity,deducted_quantity,released_quantity,delivered_quantity,cancelled_quantity',
                'releases.items.saleItem:id,sku,product_name',
                'releases.acknowledgedBy:id,name',
                'commercialInvoice:id,invoice_number,total_cost',
                'handledBy:id,name,email,role',
                'rescheduledBy:id,name',
                'attemptLogs.staff:id,name',
            ]);

        // RBAC: Delivery staff can view ONLY deliveries assigned to them
        if ($isDeliveryStaff) {
            $outboundQuery->where('handled_by_id', $user->id);
        } elseif ($request->filled('staff_id') && $request->input('staff_id') !== 'All') {
            $outboundQuery->where('handled_by_id', (int) $request->input('staff_id'));
        }

        // Filters
        $search = $request->input('search');
        if ($search) {
            $outboundQuery->where(function ($q) use ($search) {
                $q->where('fulfillment_number', 'like', "%{$search}%")
                    ->orWhere('recipient_name', 'like', "%{$search}%")
                    ->orWhere('delivery_address', 'like', "%{$search}%")
                    ->orWhere('contact_number', 'like', "%{$search}%")
                    ->orWhereHas('sale', fn ($sq) => $sq->where('order_number', 'like', "%{$search}%")->orWhere('invoice_number', 'like', "%{$search}%")->orWhere('client_name', 'like', "%{$search}%"));
            });
        }

        $status = $request->input('status', 'All');
        if ($status && $status !== 'All') {
            $groups = self::STATUS_GROUPS;
            $outboundQuery->whereIn('status', $groups[$status] ?? [$status]);
        }
        $dateFrom = $request->input('date_from');
        if ($dateFrom) {
            $outboundQuery->whereDate('delivery_schedule', '>=', $dateFrom);
        }

        $dateTo = $request->input('date_to');
        if ($dateTo) {
            $outboundQuery->whereDate('delivery_schedule', '<=', $dateTo);
        }

        $outboundDeliveries = $outboundQuery
            ->latest('id')
            ->paginate(12, ['*'], 'outbound_page')
            ->withQueryString();

        // Outbound Metrics - computed in a single aggregate query
        $toPrepareList = "'".implode("','", self::STATUS_GROUPS['to_prepare'])."'";
        $deliveredList = "'".implode("','", self::STATUS_GROUPS['delivered'])."'";
        $problemsList = "'".implode("','", self::STATUS_GROUPS['problems'])."'";
        $maxAttempts = OrderFulfillment::MAX_ATTEMPTS;

        $metricsRow = OrderFulfillment::where('team_id', $team->id)
            ->when($isDeliveryStaff, fn ($q) => $q->where('handled_by_id', $user->id))
            ->selectRaw("
                COUNT(*) as total_assigned,
                SUM(CASE WHEN status IN ({$toPrepareList}) THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'out_for_delivery' THEN 1 ELSE 0 END) as in_transit,
                SUM(CASE WHEN status IN ({$deliveredList}) THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status IN ({$problemsList}) THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'out_for_delivery' AND attempt_count < {$maxAttempts} THEN 1 ELSE 0 END) as attempts_remaining
            ")
            ->first();

        $outboundMetrics = [
            'totalAssigned' => (int) ($metricsRow->total_assigned ?? 0),
            'pending' => (int) ($metricsRow->pending ?? 0),
            'inTransit' => (int) ($metricsRow->in_transit ?? 0),
            'delivered' => (int) ($metricsRow->delivered ?? 0),
            'failed' => (int) ($metricsRow->failed ?? 0),
            'attemptsRemaining' => (int) ($metricsRow->attempts_remaining ?? 0),
        ];

        $outboundDeliveries->getCollection()->each(function (OrderFulfillment $f) use ($user) {
            $f->setAttribute('allowed_actions', $this->allowedActions($f, $user));
            $f->setAttribute('status_choices', $this->statusChoices($f, $user));
            $f->setAttribute('open_release', $f->releases->first(fn ($r) => in_array($r->status, DeliveryRelease::OPEN, true)));
        });
        // Available Delivery Staff list (for Admin assignment dropdown)
        $deliveryStaffList = [];
        if ($isAdmin) {
            $deliveryStaffList = User::where('current_team_id', $team->id)
                ->where('status', 'Active')
                ->where(function ($q) {
                    $q->where('role', 'staff_deliveries')
                        ->orWhere('role', 'like', 'staff%')
                        ->orWhereIn('role', ['admin', 'owner']);
                })
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role']);
        }

        // ---------------------------------------------------------
        // 2. INBOUND SUPPLIER DELIVERIES (Receiving Log)
        // ---------------------------------------------------------
        $inboundDeliveries = null;
        $inboundMetrics = null;
        $products = [];
        $suppliers = [];
        $purchaseOrders = [];

        if (! $isDeliveryStaff) {
            $inboundQuery = Delivery::where('team_id', $team->id)
                ->with([
                    'supplier:id,name',
                    'purchaseOrder:id,po_number,promised_delivery_date,quantity,status',
                    'receivedBy:id,name',
                    'inspectedBy:id,name',
                    'postedBy:id,name',
                    'invoices:id,delivery_id,invoice_number,status',
                ]);

            if ($search && $activeTab === 'inbound') {
                $inboundQuery->where(function ($q) use ($search) {
                    $q->where('delivery_number', 'like', "%{$search}%")
                        ->orWhere('purchase_order_number', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$search}%"));
                });
            }

            if ($dateFrom && $activeTab === 'inbound') {
                $inboundQuery->whereDate('delivery_date', '>=', $dateFrom);
            }
            if ($dateTo && $activeTab === 'inbound') {
                $inboundQuery->whereDate('delivery_date', '<=', $dateTo);
            }

            $inboundDeliveries = $inboundQuery->latest('delivery_date')->latest('id')->paginate(15, ['*'], 'inbound_page')->withQueryString();
            $inboundDeliveries->getCollection()->each(fn (Delivery $d) => $d->setAttribute('inspection_status', $d->inspectionStatus()));

            $inboundRow = Delivery::where('team_id', $team->id)
                ->selectRaw("
                    COUNT(*) as total_deliveries,
                    COALESCE(SUM(quantity), 0) as total_qty,
                    COUNT(DISTINCT CASE WHEN purchase_order_number IS NOT NULL AND purchase_order_number != '' THEN purchase_order_number END) as linked_pos,
                    SUM(CASE WHEN status = 'Delivered' THEN 1 ELSE 0 END) as delivered_count
                ")
                ->first();

            $inboundMetrics = [
                'totalDeliveries' => (int) ($inboundRow->total_deliveries ?? 0),
                'totalQuantityDelivered' => (int) ($inboundRow->total_qty ?? 0),
                'linkedPosCount' => (int) ($inboundRow->linked_pos ?? 0),
                'deliveredCount' => (int) ($inboundRow->delivered_count ?? 0),
            ];

            $products = Product::where('team_id', $team->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'cost_price', 'stock']);

            $suppliers = Supplier::where('team_id', $team->id)
                ->where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'name']);

            // Only orders sent to the supplier can receive goods.
            $purchaseOrders = PurchaseOrder::where('team_id', $team->id)
                ->whereIn('status', PurchaseOrder::RECEIVABLE)
                ->with('supplier:id,name')
                ->latest('po_date')
                ->get(['id', 'po_number', 'item_sku', 'description', 'unit_of_measure', 'quantity', 'supplier_id', 'status']);
            $workflow = app(ProcurementWorkflow::class);
            $purchaseOrders->each(fn (PurchaseOrder $po) => $po->setAttribute('remaining', $workflow->summary($po)['remaining']));
        }

        return Inertia::render('Deliveries/Index', [
            'activeTab' => $activeTab,
            'isDeliveryStaff' => $isDeliveryStaff,
            'isAdmin' => $isAdmin,
            'outboundDeliveries' => $outboundDeliveries,
            'outboundMetrics' => $outboundMetrics,
            'statusOptions' => collect(DeliveryStatus::cases())->map(fn ($c) => ['value' => $c->value, 'label' => $c->label(), 'badgeClass' => $c->badgeClass()])->values(),
            'canPrepare' => $this->canPrepare($user),
            'deliveryStaffList' => $deliveryStaffList,
            'inboundDeliveries' => $inboundDeliveries,
            'inboundMetrics' => $inboundMetrics,
            'products' => $products,
            'suppliers' => $suppliers,
            'purchaseOrders' => $purchaseOrders,
            'filters' => [
                'tab' => $activeTab,
                'search' => $search,
                'status' => $status,
                'staff_id' => $request->input('staff_id', 'All'),
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ]);
    }

    /** Delivery status filters on the page → status codes. */
    private const STATUS_GROUPS = [
        'to_prepare' => ['order_confirmed', 'preparing', 'ready_for_delivery'],
        'problems' => ['delivery_attempt_failed'],
        'delivered' => ['delivered'],
    ];

    /** What each "Update status" choice does, the status it leads to, and what it asks for. */
    private const STATUS_CHOICES = [
        'prepare' => ['status' => 'preparing', 'label' => 'Preparing: start preparing the items', 'needs' => 'quantities'],
        'ready' => ['status' => 'ready_for_delivery', 'label' => 'Ready for Delivery', 'needs' => null],
        'dispatch' => ['status' => 'out_for_delivery', 'label' => 'Out for Delivery', 'needs' => null],
        'retry' => ['status' => 'out_for_delivery', 'label' => 'Out for Delivery: try again', 'needs' => null],
        'deliver' => ['status' => 'delivered', 'label' => 'Delivered (customer received it)', 'needs' => 'receipt'],
        'handover' => ['status' => 'delivered', 'label' => 'Delivered (customer picked it up)', 'needs' => 'receipt'],
        'attempt' => ['status' => 'delivery_attempt_failed', 'label' => 'Delivery Attempt Failed', 'needs' => 'attempt'],
        'reschedule' => ['status' => 'ready_for_delivery', 'label' => 'Ready for Delivery: new delivery date', 'needs' => 'schedule'],
        'return' => ['status' => 'preparing', 'label' => 'Preparing: the goods came back to the warehouse', 'needs' => 'reason'],
        'cancel_release' => ['status' => null, 'label' => 'Undo: cancel the release being prepared', 'needs' => 'reason'],
        'cancel_delivery' => ['status' => 'cancelled', 'label' => 'Cancelled: stop delivering what is left', 'needs' => 'reason'],
    ];

    /**
     * One "Update status" action for admins and the assigned delivery staff (warehouse staff for
     * preparing and pickups). The choice must be one this user is allowed to make now; each one
     * runs the same rules as its own button.
     */
    public function updateStatus(Request $request, mixed $current_team = null, mixed $fulfillment = null): RedirectResponse
    {
        $fulfillment = $this->authorizedFulfillment($request, $fulfillment, $current_team);
        $fulfillment->loadMissing(['sale', 'releases']);
        $user = $request->user();
        $validated = $request->validate([
            'action' => ['required', Rule::in(array_keys(self::STATUS_CHOICES))],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'reason' => ['nullable', 'string', 'max:500'],
            'quantities' => ['nullable', 'array'],
            'quantities.*' => ['integer', 'min:0'],
            'delivery_schedule' => ['nullable', 'date'],
            'reset_attempts' => ['nullable', 'boolean'],
        ]);
        $action = $validated['action'];
        abort_unless(in_array($action, $this->allowedActions($fulfillment, $user), true), 403, 'You cannot make this status change.');

        return match ($action) {
            'prepare' => $this->prepareRelease($request, $current_team, $fulfillment),
            'ready' => $this->markReady($request, $current_team, $fulfillment->openRelease()),
            'dispatch', 'retry' => $this->startDelivery($request, $current_team, $fulfillment),
            'deliver', 'handover' => $this->markDelivered($request, $current_team, $fulfillment),
            'attempt' => $this->recordAttempt($request, $current_team, $fulfillment),
            'reschedule' => $this->reschedule($request, $current_team, $fulfillment),
            'return' => $this->returnToWarehouse($request, $current_team, $fulfillment),
            'cancel_release' => $this->cancelRelease($request, $current_team, $fulfillment->openRelease()),
            'cancel_delivery' => $this->runWorkflow(function () use ($fulfillment, $validated, $user) {
                $reason = trim((string) ($validated['reason'] ?? '')) ?: throw new WorkflowException('Give a reason for cancelling the delivery.', 'reason');
                app(SalesOrderService::class)->cancelRemaining($fulfillment->sale, null, $reason, $user);

                return "Delivery {$fulfillment->fulfillment_number}: the remaining items will not be delivered.";
            }),
        };
    }

    /** @return list<array{action: string, status: ?string, label: string, needs: ?string}> */
    private function statusChoices(OrderFulfillment $f, User $user): array
    {
        return collect($this->allowedActions($f, $user))
            ->filter(fn ($a) => isset(self::STATUS_CHOICES[$a]))
            ->map(fn ($a) => ['action' => $a] + self::STATUS_CHOICES[$a])
            ->values()->all();
    }

    /** Start a release: choose the items and quantities that go out next. */
    public function prepareRelease(Request $request, mixed $current_team = null, mixed $fulfillment = null): RedirectResponse
    {
        $fulfillment = $this->authorizedFulfillment($request, $fulfillment, $current_team);
        abort_unless($this->canPrepare($request->user()) || $fulfillment->handled_by_id === $request->user()->id, 403, 'You cannot prepare releases.');
        $validated = $request->validate([
            'quantities' => ['required', 'array'],
            'quantities.*' => ['integer', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->runWorkflow(function () use ($fulfillment, $validated, $request) {
            $release = app(FulfillmentService::class)->prepareRelease($fulfillment, $validated['quantities'], $request->user(), $validated['remarks'] ?? null);

            return "Release {$release->release_number} is being prepared.";
        });
    }

    public function markReady(Request $request, mixed $current_team = null, mixed $release = null): RedirectResponse
    {
        $release = $this->authorizedRelease($request, $release, $current_team);
        abort_unless($this->canPrepare($request->user()) || $release->fulfillment->handled_by_id === $request->user()->id, 403);

        return $this->runWorkflow(function () use ($release, $request) {
            app(FulfillmentService::class)->markReady($release, $request->user());

            return "{$release->release_number} is ready".($release->type === 'pickup' ? ' for pickup.' : ' for dispatch.');
        });
    }

    public function cancelRelease(Request $request, mixed $current_team = null, mixed $release = null): RedirectResponse
    {
        $release = $this->authorizedRelease($request, $release, $current_team);
        abort_unless($this->canPrepare($request->user()), 403);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        return $this->runWorkflow(function () use ($release, $reason, $request) {
            app(FulfillmentService::class)->cancelRelease($release, $request->user(), $reason);

            return "{$release->release_number} cancelled.";
        });
    }

    /** Out for delivery: dispatch the ready release (stock leaves now), or go again after a failure/reschedule. */
    public function startDelivery(Request $request, mixed $current_team = null, mixed $fulfillment = null): RedirectResponse
    {
        $fulfillment = $this->authorizedFulfillment($request, $fulfillment, $current_team);
        $this->assertDriver($request, $fulfillment);

        return $this->runWorkflow(function () use ($fulfillment, $request) {
            $service = app(FulfillmentService::class);
            $release = $fulfillment->openRelease() ?? throw new WorkflowException('Prepare a release and mark it ready first.');
            // Goods already on the truck (failed attempt or new date): go again. Otherwise dispatch.
            if ($release->status === DeliveryRelease::DISPATCHED) {
                $service->retryDispatch($fulfillment, $request->user());
            } else {
                $service->dispatch($release, $request->user());
            }

            return "Delivery {$fulfillment->fulfillment_number} is out for delivery.";
        });
    }

    public function recordAttempt(Request $request, mixed $current_team = null, mixed $fulfillment = null): RedirectResponse
    {
        $fulfillment = $this->authorizedFulfillment($request, $fulfillment, $current_team);
        $this->assertDriver($request, $fulfillment);

        $validated = $request->validate([
            'reason' => ['required', 'string', Rule::in(['Customer unavailable', 'Incorrect or incomplete address', 'Customer refused delivery', 'Customer unreachable', 'Other reason'])],
            'remarks' => ['required', 'string', 'min:3', 'max:1000'],
            'attempt_photo' => ['nullable', 'file', 'image', 'mimes:jpeg,jpg,png', 'max:5120'],
        ]);

        $photo = $request->file('attempt_photo')?->store('proofs/deliveries/attempts', 'local');

        return $this->runWorkflow(function () use ($fulfillment, $validated, $photo, $request) {
            try {
                $attempt = app(FulfillmentService::class)->recordFailedAttempt($fulfillment, $validated['reason'], $validated['remarks'], $photo, $request->user());
            } catch (\Throwable $e) {
                if ($photo) {
                    Storage::disk('local')->delete($photo);
                }
                throw $e;
            }

            return $attempt >= OrderFulfillment::MAX_ATTEMPTS
                ? 'Attempt 3/3 recorded. The limit is reached: an administrator has to reschedule or the goods have to be returned.'
                : "Attempt {$attempt}/".OrderFulfillment::MAX_ATTEMPTS.' recorded.';
        });
    }

    /**
     * Record what the customer received from the current release (all of it by default). For
     * deliveries a photo proof is required; for pickups the receiver's name is enough.
     */
    public function markDelivered(Request $request, mixed $current_team = null, mixed $fulfillment = null): RedirectResponse
    {
        $fulfillment = $this->authorizedFulfillment($request, $fulfillment, $current_team);
        $pickup = $fulfillment->isPickup();
        $pickup ? abort_unless($this->canPrepare($request->user()) || $fulfillment->handled_by_id === $request->user()->id, 403) : $this->assertDriver($request, $fulfillment);

        $validated = $request->validate([
            'proof_of_delivery' => [$pickup ? 'nullable' : 'required', 'file', 'image', 'mimes:jpeg,jpg,png', 'max:5120'],
            'recipient_name' => [$pickup ? 'required' : 'nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'quantities' => ['nullable', 'array'],
            'quantities.*' => ['integer', 'min:0'],
        ], [
            'proof_of_delivery.required' => 'Proof of delivery photo is required to mark this order as Delivered.',
            'proof_of_delivery.image' => 'The proof of delivery must be a valid image file (JPG or PNG).',
            'proof_of_delivery.mimes' => 'Only JPEG, JPG, and PNG image formats are accepted.',
            'proof_of_delivery.max' => 'The proof of delivery image size cannot exceed 5 MB.',
            'recipient_name.required' => 'Enter the name of the person who picked up the order.',
        ]);

        $release = $fulfillment->openRelease();
        if (! $release) {
            return back()->withErrors(['error' => 'There is no release on its way for this order.']);
        }

        $proof = $request->file('proof_of_delivery')?->store('proofs/deliveries', 'local');

        return $this->runWorkflow(function () use ($release, $validated, $proof, $fulfillment, $request) {
            try {
                $status = app(FulfillmentService::class)->recordReceipt($release, $validated['quantities'] ?? [], ($validated['recipient_name'] ?? null) ?: ($fulfillment->recipient_name ?: 'Customer'), $proof, $validated['remarks'] ?? null, $request->user());
            } catch (\Throwable $e) {
                if ($proof) {
                    Storage::disk('local')->delete($proof);
                }
                throw $e;
            }

            return $status === DeliveryStatus::Delivered
                ? "Delivery {$fulfillment->fulfillment_number}: everything has been received."
                : "Delivery {$fulfillment->fulfillment_number}: partial delivery recorded. The remaining quantities stay outstanding.";
        });
    }

    /** The goods came back to the warehouse (stock is put back and held for the order). */
    public function returnToWarehouse(Request $request, mixed $current_team = null, mixed $fulfillment = null): RedirectResponse
    {
        $fulfillment = $this->authorizedFulfillment($request, $fulfillment, $current_team);
        $this->assertDriver($request, $fulfillment);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        return $this->runWorkflow(function () use ($fulfillment, $reason, $request) {
            $release = $fulfillment->openRelease() ?? throw new WorkflowException('There is no release on its way for this order.');
            app(FulfillmentService::class)->returnRelease($release, $reason, $request->user());

            return "{$release->release_number} returned to the warehouse. The stock is back and held for the order.";
        });
    }

    /** Stream a release's proof of delivery. */
    public function releaseProof(Request $request, mixed $current_team = null, mixed $release = null): StreamedResponse
    {
        $release = $this->authorizedRelease($request, $release, $current_team);
        $user = $request->user();
        abort_unless($user->isAdmin() || $this->canPrepare($user) || $release->fulfillment->handled_by_id === $user->id || $user->hasModule('sales'), 403);
        abort_if(! $release->proof_path || ! Storage::disk('local')->exists($release->proof_path), 404);

        return Storage::disk('local')->response($release->proof_path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Printable delivery receipt / release slip for one release. */
    public function releasePdf(Request $request, mixed $current_team = null, mixed $release = null)
    {
        $release = $this->authorizedRelease($request, $release, $current_team);

        return response(app(DocumentPdf::class)->deliveryReceipt($release), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$release->release_number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Warehouse staff (and administrators) prepare releases and hand over pickups. */
    private function canPrepare(User $user): bool
    {
        return $user->isAdmin() || $user->hasAnyModule(['inventory', 'procurement']);
    }

    private function assertDriver(Request $request, OrderFulfillment $fulfillment): void
    {
        $user = $request->user();
        if (! $user->isAdmin()) {
            abort_if($fulfillment->handled_by_id !== $user->id, 403, 'You are not assigned to this delivery.');
        }
    }

    /** @return list<string> */
    private function allowedActions(OrderFulfillment $f, User $user): array
    {
        $status = $f->status;
        if (! $status) {
            return [];
        }
        $pickup = $f->isPickup();
        $driver = $user->isAdmin() || $f->handled_by_id === $user->id;
        $warehouse = $this->canPrepare($user) || $f->handled_by_id === $user->id;
        $open = $f->releases->first(fn ($r) => in_array($r->status, DeliveryRelease::OPEN, true));
        $orderOpen = in_array($f->sale?->status, [SalesOrderStatus::Confirmed, SalesOrderStatus::Processing], true);
        $actions = [];

        if ($warehouse && $orderOpen && ! $open && $status->canMoveTo(DeliveryStatus::Preparing, $pickup)) {
            $actions[] = 'prepare';
        }
        if ($warehouse && $open?->status === DeliveryRelease::PREPARING) {
            $actions[] = 'ready';
        }
        if ($this->canPrepare($user) && $open && in_array($open->status, [DeliveryRelease::PREPARING, DeliveryRelease::READY], true)) {
            $actions[] = 'cancel_release';
        }
        if (! $pickup && $driver && $open?->status === DeliveryRelease::READY && $orderOpen && ! $f->sale?->hold_reason) {
            $actions[] = 'dispatch';
        }
        if ($pickup && $warehouse && $open?->status === DeliveryRelease::READY) {
            $actions[] = 'handover';
        }
        if (! $pickup && $driver && $status === DeliveryStatus::OutForDelivery) {
            $actions[] = 'deliver';
            if ($f->attempt_count < OrderFulfillment::MAX_ATTEMPTS) {
                $actions[] = 'attempt';
            }
        }
        $onTruck = $open?->status === DeliveryRelease::DISPATCHED;
        $rescheduled = $status === DeliveryStatus::ReadyForDelivery && $onTruck;
        if (! $pickup && $driver && (($status === DeliveryStatus::DeliveryAttemptFailed && $f->attempt_count < OrderFulfillment::MAX_ATTEMPTS) || $rescheduled)) {
            $actions[] = 'retry';
        }
        if (! $pickup && $driver && $onTruck && $status !== DeliveryStatus::OutForDelivery) {
            $actions[] = 'return';
        }
        if ($user->isAdmin() && $status === DeliveryStatus::DeliveryAttemptFailed) {
            $actions[] = 'reschedule';
        }
        $owed = $f->sale?->items?->contains(fn ($l) => $l->releasable_quantity > 0) ?? false;
        if ($user->isAdmin() && $orderOpen && ! $open && $owed && in_array($status, [DeliveryStatus::OrderConfirmed, DeliveryStatus::Preparing], true)) {
            $actions[] = 'cancel_delivery';
        }
        if ($user->isAdmin() && ! $status->isFinal()) {
            $actions[] = 'assign';
        }

        return $actions;
    }

    private function authorizedFulfillment(Request $request, mixed $fulfillment, mixed $current_team): OrderFulfillment
    {
        $fulfillment = $this->resolveFulfillment($fulfillment, $current_team, $request);
        $team = $this->getTeam($request, $current_team);
        abort_if(! $team || $fulfillment->team_id !== $team->id, 403, 'Unauthorized delivery access.');

        return $fulfillment;
    }

    private function authorizedRelease(Request $request, mixed $release, mixed $current_team): DeliveryRelease
    {
        $team = $this->getTeam($request, $current_team);
        $id = $release instanceof DeliveryRelease ? $release->id : (int) ($release ?? $request->route('release'));
        $release = DeliveryRelease::with('fulfillment')->where('team_id', $team?->id)->findOrFail($id);

        return $release;
    }

    /**
     * Assign or reassign delivery staff (Administrator / Operations Staff only).
     */
    public function assignStaff(Request $request, mixed $current_team = null, mixed $fulfillment = null): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin(), 403, 'Only administrators or authorized operations staff can assign deliveries.');

        $fulfillment = $this->resolveFulfillment($fulfillment, $current_team, $request);
        $team = $this->getTeam($request, $current_team);
        abort_if(! $team || $fulfillment->team_id !== $team->id, 403, 'Unauthorized delivery access.');

        $validated = $request->validate([
            'staff_id' => ['required', 'integer', Rule::exists('users', 'id')],
        ]);

        $assignedUser = User::findOrFail($validated['staff_id']);
        $fulfillment->handled_by_id = $assignedUser->id;
        $fulfillment->save();

        DeliveryAttemptLog::create([
            'team_id' => $team->id,
            'order_fulfillment_id' => $fulfillment->id,
            'previous_status' => $fulfillment->status?->value ?? 'none',
            'new_status' => $fulfillment->status?->value ?? 'none',
            'attempt_number' => 0,
            'staff_id' => $user->id,
            'remarks' => "Administrator assigned delivery to {$assignedUser->name}.",
            'attempt_date' => now(),
        ]);

        return back()->with('success', "Delivery {$fulfillment->fulfillment_number} assigned to {$assignedUser->name}.");
    }

    /**
     * Reschedule delivery / reset attempts after 3-attempt limit (Admin/Ops only).
     */
    public function reschedule(Request $request, mixed $current_team = null, mixed $fulfillment = null): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin(), 403, 'Only administrators can authorize rescheduling.');

        $fulfillment = $this->resolveFulfillment($fulfillment, $current_team, $request);
        $team = $this->getTeam($request, $current_team);
        abort_if(! $team || $fulfillment->team_id !== $team->id, 403, 'Unauthorized delivery access.');

        $validated = $request->validate([
            'delivery_schedule' => ['required', 'date'],
            'staff_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'notes' => ['nullable', 'string', 'max:1000'],
            'reset_attempts' => ['nullable', 'boolean'],
        ]);

        return $this->runWorkflow(function () use ($fulfillment, $user, $validated) {
            app(FulfillmentService::class)->reschedule($fulfillment, $validated['delivery_schedule'], (bool) ($validated['reset_attempts'] ?? false), $validated['notes'] ?? null, $validated['staff_id'] ?? null, $user);

            return "Delivery {$fulfillment->fulfillment_number} rescheduled.";
        });
    }

    /**
     * Stream fulfillment proof of delivery image securely.
     */
    public function fulfillmentProof(Request $request, mixed $current_team = null, mixed $fulfillment = null): StreamedResponse
    {
        $fulfillment = $this->resolveFulfillment($fulfillment, $current_team, $request);
        $team = $this->getTeam($request, $current_team);
        abort_if(! $team || $fulfillment->team_id !== $team->id, 403, 'Unauthorized delivery access.');

        $user = $request->user();
        if (! $user->isAdmin()) {
            abort_if($fulfillment->handled_by_id !== $user->id, 403, 'You are not authorized to view this delivery proof.');
        }

        abort_if(! $fulfillment->proof_of_delivery, 404, 'No proof of delivery uploaded.');

        if (! Storage::disk('local')->exists($fulfillment->proof_of_delivery)) {
            abort(404, 'Proof of delivery file missing.');
        }

        return Storage::disk('local')->response($fulfillment->proof_of_delivery, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Stream attempt photo proof securely.
     */
    public function attemptProof(Request $request, mixed $current_team = null, mixed $attemptLog = null): StreamedResponse
    {
        $team = $this->getTeam($request, $current_team);
        abort_if(! $team, 403);

        $logId = is_numeric($attemptLog) ? (int) $attemptLog : ($attemptLog?->id ?? (int) $request->route('attemptLog'));
        $log = DeliveryAttemptLog::where('team_id', $team->id)->where('id', $logId)->firstOrFail();

        $user = $request->user();
        if (! $user->isAdmin()) {
            abort_if($log->staff_id !== $user->id && $log->orderFulfillment?->handled_by_id !== $user->id, 403);
        }

        abort_if(! $log->proof_image || ! Storage::disk('local')->exists($log->proof_image), 404, 'Attempt photo missing.');

        return Storage::disk('local')->response($log->proof_image, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // -------------------------------------------------------------
    // INBOUND SUPPLIER DELIVERY METHODS (Preserved for compatibility)
    // -------------------------------------------------------------

    /**
     * Delete an inbound delivery record.
     */
    public function destroy(Request $request, mixed $current_team = null, mixed $delivery = null): RedirectResponse
    {
        $delivery = $this->resolveDelivery($delivery, $current_team, $request);
        $team = $this->getTeam($request, $current_team);
        abort_if(! $team || $delivery->team_id !== $team->id, 403);

        $delivery->delete();

        return back()->with('success', 'Delivery record deleted successfully.');
    }

    /**
     * Process CSV Import for Deliveries.
     */
    public function importCsv(Request $request): JsonResponse|RedirectResponse
    {
        $team = $this->getTeam($request);
        abort_if(! $team, 404, 'Team not found.');

        $request->validate([
            'file' => ['required', 'file', 'extensions:csv,txt'],
            'execute' => ['nullable', 'boolean'],
        ]);

        $file = $request->file('file');
        $filePath = $file->getRealPath() ?: $file->getPathname();
        $content = file_get_contents($filePath);
        $rawLines = preg_split('/\r\n|\n|\r/', trim($content));

        if (empty($rawLines) || count($rawLines) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'The uploaded CSV file is empty or missing headers.',
                'valid_rows' => [],
                'invalid_rows' => [],
            ], 422);
        }

        $firstLine = $rawLines[0];
        $delimiter = ',';
        if (substr_count($firstLine, "\t") > substr_count($firstLine, ',')) {
            $delimiter = "\t";
        } elseif (substr_count($firstLine, ';') > substr_count($firstLine, ',')) {
            $delimiter = ';';
        }

        $lines = [];
        foreach ($rawLines as $rawLine) {
            if (trim($rawLine) === '') {
                continue;
            }
            $row = str_getcsv($rawLine, $delimiter);
            if (! empty(array_filter($row, fn ($v) => $v !== null && trim((string) $v) !== ''))) {
                $lines[] = $row;
            }
        }

        if (count($lines) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'The uploaded CSV file contains no data rows.',
                'valid_rows' => [],
                'invalid_rows' => [],
            ], 422);
        }

        $rawHeaders = $lines[0];
        $headers = array_map(function ($h) {
            $cleaned = preg_replace('/^\xEF\xBB\xBF/', '', (string) $h);

            return preg_replace('/[^a-z0-9]/', '', strtolower($cleaned));
        }, $rawHeaders);

        $dateIdx = $this->findHeaderIndex($headers, ['date', 'deliverydate']);
        $delNumIdx = $this->findHeaderIndex($headers, ['deliverynumber', 'deliveryno', 'delno', 'delivery']);
        $poNumIdx = $this->findHeaderIndex($headers, ['purchaseordernumber', 'purchaseorderno', 'pono', 'ponumber', 'purchaseorder', 'po']);
        $qtyIdx = $this->findHeaderIndex($headers, ['quantity', 'qty', 'count']);
        $unitIdx = $this->findHeaderIndex($headers, ['unit', 'um', 'uom', 'unitofmeasure', 'measure']);
        $descIdx = $this->findHeaderIndex($headers, ['description', 'desc', 'itemdescription', 'item', 'product']);

        if ($dateIdx === -1 || $qtyIdx === -1 || $descIdx === -1) {
            return response()->json([
                'success' => false,
                'message' => 'Required column headers missing. CSV must include headers: Date, Delivery Number, Purchase Order Number, Quantity, Unit, Description.',
                'valid_rows' => [],
                'invalid_rows' => [],
            ], 422);
        }

        $validRows = [];
        $invalidRows = [];

        for ($i = 1; $i < count($lines); $i++) {
            $row = $lines[$i];
            if (empty(array_filter($row))) {
                continue;
            }

            $rowNumber = $i + 1;
            $errors = [];

            $rawDate = isset($row[$dateIdx]) ? trim($row[$dateIdx]) : '';
            $parsedDate = null;
            $displayDate = $rawDate;

            if ($rawDate === '') {
                $errors[] = 'Date is required.';
            } else {
                if (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $rawDate, $m)) {
                    $parsedDate = sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]);
                } elseif (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $rawDate, $m)) {
                    $parsedDate = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
                } else {
                    try {
                        $parsedDate = Carbon::parse($rawDate)->toDateString();
                    } catch (\Exception $e) {
                        $errors[] = "Invalid date format '{$rawDate}'.";
                    }
                }
            }

            $delNumber = $delNumIdx !== -1 && isset($row[$delNumIdx]) ? trim($row[$delNumIdx]) : '';
            $poNumber = $poNumIdx !== -1 && isset($row[$poNumIdx]) ? trim($row[$poNumIdx]) : '';

            $rawQty = isset($row[$qtyIdx]) ? trim(str_replace(',', '', $row[$qtyIdx])) : '';
            $qty = 0;
            if ($rawQty === '' || ! is_numeric($rawQty) || (int) $rawQty <= 0) {
                $errors[] = 'Quantity must be a positive integer.';
            } else {
                $qty = (int) $rawQty;
            }

            $unit = $unitIdx !== -1 && isset($row[$unitIdx]) && trim($row[$unitIdx]) !== '' ? trim($row[$unitIdx]) : 'pcs';
            $desc = isset($row[$descIdx]) ? trim($row[$descIdx]) : '';
            if ($desc === '') {
                $errors[] = 'Description is required.';
            }

            if (! empty($errors)) {
                $invalidRows[] = [
                    'row' => $rowNumber,
                    'errors' => $errors,
                    'data' => [
                        'date' => $displayDate,
                        'delivery_number' => $delNumber,
                        'po_number' => $poNumber,
                        'quantity' => $rawQty,
                        'unit' => $unit,
                        'description' => $desc,
                    ],
                ];
            } else {
                $validRows[] = [
                    'row' => $rowNumber,
                    'delivery_date' => $parsedDate,
                    'delivery_number' => $delNumber,
                    'purchase_order_number' => $poNumber,
                    'quantity' => $qty,
                    'unit' => $unit,
                    'description' => $desc,
                ];
            }
        }

        if ($request->boolean('execute')) {
            if (empty($validRows)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid rows found to import.',
                ], 422);
            }

            DB::transaction(function () use ($validRows, $team, $request) {
                foreach ($validRows as $item) {
                    $delNum = ! empty($item['delivery_number'])
                        ? $item['delivery_number']
                        : Delivery::generateDeliveryNumber($team->id, $item['delivery_date']);

                    $poId = null;
                    $supplierId = null;
                    if (! empty($item['purchase_order_number'])) {
                        $foundPo = PurchaseOrder::where('team_id', $team->id)
                            ->where('po_number', $item['purchase_order_number'])
                            ->first();
                        if ($foundPo) {
                            $poId = $foundPo->id;
                            $supplierId = $foundPo->supplier_id;
                        }
                    }

                    Delivery::create([
                        'team_id' => $team->id,
                        'delivery_date' => $item['delivery_date'],
                        'delivery_number' => $delNum,
                        'purchase_order_number' => $item['purchase_order_number'] ?: null,
                        'purchase_order_id' => $poId,
                        'quantity' => $item['quantity'],
                        'unit' => $item['unit'],
                        'description' => $item['description'],
                        'supplier_id' => $supplierId,
                        'received_by_id' => $request->user()?->id,
                        'status' => 'Delivered',
                        'notes' => 'Imported via CSV',
                    ]);
                }
            });

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Successfully imported '.count($validRows).' delivery record(s).',
                    'count' => count($validRows),
                    'invalid_count' => count($invalidRows),
                ]);
            }

            return back()->with('success', 'Successfully imported '.count($validRows).' delivery record(s).');
        }

        return response()->json([
            'success' => true,
            'summary' => [
                'total_rows' => count($lines) - 1,
                'valid_count' => count($validRows),
                'invalid_count' => count($invalidRows),
            ],
            'valid_rows' => $validRows,
            'invalid_rows' => $invalidRows,
        ]);
    }

    /**
     * Find header index matching candidate strings.
     */
    protected function findHeaderIndex(array $headers, array $candidates): int
    {
        foreach ($headers as $index => $header) {
            foreach ($candidates as $candidate) {
                if ($header === $candidate) {
                    return $index;
                }
            }
        }

        return -1;
    }

    /**
     * Helper to retrieve team.
     */
    protected function getTeam(Request $request, mixed $teamParam = null): ?Team
    {
        if ($teamParam instanceof Team) {
            return $teamParam;
        }

        if (is_string($teamParam) && ! empty($teamParam)) {
            $found = Team::where('slug', $teamParam)->first();
            if ($found) {
                return $found;
            }
        }

        $team = $request->route('current_team') ?? $request->route('team') ?? $teamParam;
        if (is_string($team)) {
            $found = Team::where('slug', $team)->first();
            if ($found) {
                return $found;
            }
        }
        if ($team instanceof Team) {
            return $team;
        }

        return $request->user()?->currentTeam;
    }

    /**
     * Resolve delivery from route parameter.
     */
    protected function resolveDelivery(mixed $delivery, mixed $fallback = null, ?Request $request = null): Delivery
    {
        if ($delivery instanceof Delivery) {
            return $delivery;
        }

        $candidates = array_filter([
            $delivery,
            $fallback,
            $request?->route('delivery'),
        ], fn ($val) => $val !== null && $val !== '');

        foreach ($candidates as $cand) {
            if ($cand instanceof Delivery) {
                return $cand;
            }
            if (is_numeric($cand)) {
                $found = Delivery::find((int) $cand);
                if ($found) {
                    return $found;
                }
            }
            if (is_string($cand) && ! empty($cand)) {
                $found = Delivery::where('delivery_number', $cand)->first();
                if ($found) {
                    return $found;
                }
            }
        }

        abort(404, 'Delivery record not found.');
    }

    /**
     * Resolve order fulfillment from route parameters.
     */
    protected function resolveFulfillment(mixed $fulfillment, mixed $fallback = null, ?Request $request = null): OrderFulfillment
    {
        if ($fulfillment instanceof OrderFulfillment) {
            return $fulfillment;
        }

        $candidates = array_filter([
            $fulfillment,
            $fallback,
            $request?->route('fulfillment'),
        ], fn ($val) => $val !== null && $val !== '');

        foreach ($candidates as $cand) {
            if ($cand instanceof OrderFulfillment) {
                return $cand;
            }
            if (is_numeric($cand)) {
                $found = OrderFulfillment::find((int) $cand);
                if ($found) {
                    return $found;
                }
            }
            if (is_string($cand) && ! empty($cand)) {
                $found = OrderFulfillment::where('fulfillment_number', $cand)->first();
                if ($found) {
                    return $found;
                }
            }
        }

        abort(404, 'Order fulfillment record not found.');
    }
}
