<?php

namespace App\Http\Controllers;

use App\Enums\QuotationStatus;
use App\Exceptions\QuotationNumberException;
use App\Models\Client;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Team;
use App\Models\User;
use App\Services\Documents\DocumentPdf;
use App\Services\MaterialList\ExtractionException;
use App\Services\MaterialList\FileReader;
use App\Services\MaterialList\MaterialParser;
use App\Services\MaterialList\ProductMatcher;
use App\Services\Quotations\QuotationWorkflow;
use App\Services\Sales\SalesOrderService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class QuotationController extends Controller
{
    /**
     * Display a listing of quotations.
     */
    public function index(Request $request): Response
    {
        $team = $this->getTeam($request);
        abort_if(! $team, 404, 'Team not found.');

        // Approved or sent quotations past their validity date expire (cached for 60s to prevent redundant DB hits)
        \Illuminate\Support\Facades\Cache::remember("team:{$team->id}:quotation_expire_check", 60, function () use ($team) {
            return app(QuotationWorkflow::class)->expireDue($team->id);
        });

        $query = Quotation::query()
            ->with(['items.product', 'items.supplier:id,name', 'client', 'preparedBy:id,name', 'approvedBy:id,name', 'sentBy:id,name', 'decisionBy:id,name', 'sale:id,order_number,status,payment_status', 'sale.fulfillment', 'files'])
            ->where('team_id', $team->id);

        // Search query
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('quotation_number', 'like', "%{$search}%")
                    ->orWhere('client_name', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Status filter
        if ($status = $request->input('status')) {
            if ($status !== 'All') {
                $query->where('status', $status);
            }
        }

        // Date range filter
        if ($dateFrom = $request->input('date_from')) {
            $query->whereDate('quotation_date', '>=', $dateFrom);
        }
        if ($dateTo = $request->input('date_to')) {
            $query->whereDate('quotation_date', '<=', $dateTo);
        }

        $quotations = $query->latest('quotation_date')
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        // Summary metrics using SQL aggregates instead of loading all models into PHP memory
        $baseMetricsQuery = fn () => Quotation::where('team_id', $team->id);
        $metrics = [
            'totalQuotations' => $baseMetricsQuery()->count(),
            // Requests waiting for staff, and quotations waiting for the administrator.
            'pendingCount' => $baseMetricsQuery()->where('status', QuotationStatus::Pending->value)->count(),
            'forApprovalCount' => $baseMetricsQuery()->where('status', QuotationStatus::ForApproval->value)->whereNull('approved_at')->count(),
            'approvedCount' => $baseMetricsQuery()->where('status', QuotationStatus::Sent->value)->count(),
            'acceptedCount' => $baseMetricsQuery()->where('status', QuotationStatus::Accepted->value)->count(),
            'totalQuotedValue' => (float) $baseMetricsQuery()->whereIn('status', [
                QuotationStatus::Sent->value,
                QuotationStatus::Accepted->value,
                QuotationStatus::Converted->value,
            ])->sum('grand_total'),
        ];

        // Inventory products for selection: available = stock not reserved for confirmed orders.
        // Supplier cost and supplier are internal and only shown on this staff page.
        $rawProducts = Product::where('team_id', $team->id)
            ->with('supplier:id,name')
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'category', 'price', 'cost_price', 'supplier_id', 'stock', 'status', 'reorder_level', 'min_stock']);

        $reservedMap = Product::batchReservedQuantities($rawProducts->pluck('id')->all());

        $products = $rawProducts->map(function (Product $p) use ($reservedMap) {
            $reserved = $reservedMap[$p->id] ?? 0;
            $p->setAttribute('precomputed_reserved_quantity', $reserved);
            $available = max(0, (int) $p->stock - $reserved);

            return [
                'id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'category' => $p->category, 'price' => $p->price,
                'cost_price' => $p->cost_price, 'supplier_id' => $p->supplier_id, 'supplier_name' => $p->supplier?->name,
                'stock' => $available, 'on_hand' => (int) $p->stock, 'status' => $p->status,
                'stock_status' => $p->stockStatusFor(1, $available),
            ];
        });

        // Existing client records for auto-complete/quick pick
        $clients = Client::where('team_id', $team->id)
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'contact_person', 'email', 'phone', 'address']);

        // Default next quotation number (shown only; the real one is reserved when saving)
        $firstSku = $products->first()['sku'] ?? null;
        $nextNumber = Quotation::peekNumber($team->id, $firstSku);

        // Per-line stock condition for the requested quantity (batch resolved without N+1 queries).
        $itemProductIds = $quotations->getCollection()->flatMap(fn ($q) => $q->items->pluck('product_id'))->filter()->unique()->all();
        $itemReservedMap = Product::batchReservedQuantities($itemProductIds);

        $quotations->getCollection()->each(function (Quotation $q) use ($itemReservedMap) {
            $q->items->each(function ($item) use ($itemReservedMap) {
                if ($item->product) {
                    $reserved = $itemReservedMap[$item->product->id] ?? 0;
                    $item->product->setAttribute('precomputed_reserved_quantity', $reserved);
                    $available = max(0, (int) $item->product->stock - $reserved);
                    $item->setAttribute('stock_status', $item->product->stockStatusFor((int) $item->quantity, $available));
                } else {
                    $item->setAttribute('stock_status', 'Not in catalog');
                }
            });
            $q->setAttribute('allowed_actions', $this->allowedActions($q, request()->user()));
        });

        return Inertia::render('Quotations/AdminIndex', [
            'quotations' => $quotations,
            'filters' => $request->only(['search', 'status', 'date_from', 'date_to']),
            'metrics' => $metrics,
            'products' => $products,
            'clients' => $clients,
            'nextNumber' => $nextNumber,
            'statusOptions' => collect(QuotationStatus::cases())->map(fn ($s) => [
                'value' => $s->value,
                'label' => $s->label(),
                'badgeClass' => $s->badgeClass(),
            ]),
            // Statuses a staff member can save a quotation with from the form.
            'formStatuses' => collect([QuotationStatus::Pending, QuotationStatus::Preparing, QuotationStatus::ForApproval])
                ->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'decisionSources' => QuotationWorkflow::DECISION_SOURCES,
            'canApprove' => (bool) $request->user()?->isAdmin(),
            // For the supplier quotation import.
            'suppliers' => \App\Models\Supplier::where('team_id', $team->id)->where('status', 'Active')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * What the current user can do with a quotation next (buttons on the page).
     *
     * @return list<string>
     */
    private function allowedActions(Quotation $quotation, ?User $user): array
    {
        $status = $quotation->status;
        $actions = [];
        if ($status->isEditable()) {
            $actions[] = 'edit';
        }
        foreach (QuotationStatus::manual() as $manual) {
            if ($status->canMoveTo($manual)) {
                $actions[] = 'status:'.$manual->value;
            }
        }
        if ($status === QuotationStatus::ForApproval && ! $quotation->approved_at && $user?->isAdmin()) {
            $actions[] = 'approve';
        }
        // Approved but not sent (e.g. no email address when it was approved), or sent already.
        if ($status === QuotationStatus::ForApproval && $quotation->approved_at) {
            $actions[] = 'send';
        }
        if ($status === QuotationStatus::Sent) {
            $actions[] = 'resend';
        }
        if ($status === QuotationStatus::Sent) {
            $actions[] = 'decision';
        }
        if ($status->canConvertToSale() && ! $quotation->converted_to_sale_id) {
            $actions[] = 'convert';
        }

        return $actions;
    }

    /**
     * Store a newly created quotation.
     */
    public function store(Request $request): RedirectResponse
    {
        $team = $this->getTeam($request);
        abort_if(! $team, 404, 'Team not found.');

        $validated = $request->validate([
            'client_name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'quotation_date' => ['required', 'date'],
            'valid_until' => ['required', 'date', 'after_or_equal:quotation_date'],
            'status' => ['required', Rule::in(self::FORM_STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'terms_and_conditions' => ['nullable', 'string', 'max:2000'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.sku' => ['required', 'string', 'max:100'],
            'items.*.product_name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('team_id', $team->id)],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $this->createStaffQuotation($request, $team, $validated);
        } catch (QuotationNumberException|QueryException $e) {
            return back()->withErrors(['quotation_number' => $this->savingFailed($e, $team, 'create')]);
        }

        return back()->with('success', 'Quotation created successfully.');
    }

    /** Validated staff form → quotation and its items, in one transaction (retried on deadlock). */
    private function createStaffQuotation(Request $request, Team $team, array $validated): void
    {
        DB::transaction(function () use ($team, $request, $validated) {
            // Find or create client
            $client = Client::firstOrCreate(
                [
                    'team_id' => $team->id,
                    'company_name' => $validated['client_name'],
                ],
                [
                    'contact_person' => $validated['contact_person'] ?? null,
                    'email' => $validated['email'] ?? null,
                    'phone' => $validated['phone'] ?? null,
                    'address' => $validated['address'] ?? null,
                ]
            );

            // Compute financial totals accurately
            [$itemsData, $subtotal] = $this->buildItems($validated['items'], $team);

            $overallDiscount = (float) ($validated['discount'] ?? 0);
            $taxableAmount = max(0, $subtotal - $overallDiscount);
            $taxRate = isset($validated['tax_rate']) ? (float) $validated['tax_rate'] : 12.00;
            $taxAmount = round($taxableAmount * ($taxRate / 100), 2);
            $grandTotal = $taxableAmount + $taxAmount;

            $firstSku = $validated['items'][0]['sku'] ?? null;

            $quotation = Quotation::createWithNumber([
                'team_id' => $team->id,
                'client_id' => $client->id,
                'client_name' => $validated['client_name'],
                'contact_person' => $validated['contact_person'] ?? null,
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'address' => $validated['address'] ?? null,
                'quotation_date' => $validated['quotation_date'],
                'valid_until' => $validated['valid_until'],
                'subtotal' => $subtotal,
                'discount' => $overallDiscount,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'grand_total' => $grandTotal,
                'status' => $validated['status'],
                'notes' => $validated['notes'] ?? null,
                'terms_and_conditions' => $validated['terms_and_conditions'] ?? $this->defaultTerms(),
                'prepared_by_id' => $request->user()->id,
            ], $firstSku, $validated['quotation_date']);

            foreach ($itemsData as $data) {
                $quotation->items()->create($data);
            }
        }, 3);
    }

    /**
     * Log why a quotation could not be saved and return a message that is safe to show staff
     * (no SQL). The transaction has rolled back, so nothing was half-saved.
     */
    private function savingFailed(Throwable $e, Team $team, string $action): string
    {
        Log::error("Quotation {$action} failed for team {$team->id}: ".$e->getMessage(), ['exception' => $e]);

        return $e instanceof QuotationNumberException || $e instanceof UniqueConstraintViolationException
            ? 'A quotation number could not be assigned because another quotation was saved at the same moment. Nothing was saved. Please try again.'
            : 'The quotation could not be saved because of a database problem. Nothing was saved. Please try again, or contact the administrator if it keeps happening.';
    }

    /**
     * Update the specified quotation.
     */
    public function update(Request $request, mixed $current_team = null, mixed $quotation = null): RedirectResponse
    {
        $quotation = $this->resolveQuotation($quotation, $current_team);
        $team = $this->getTeam($request, $current_team);
        abort_if(! $team || $quotation->team_id !== $team->id, 403, 'Unauthorized quotation access.');

        $validated = $request->validate([
            'client_name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'quotation_date' => ['required', 'date'],
            'valid_until' => ['required', 'date', 'after_or_equal:quotation_date'],
            'status' => ['required', Rule::in(self::FORM_STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'terms_and_conditions' => ['nullable', 'string', 'max:2000'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.sku' => ['required', 'string', 'max:100'],
            'items.*.product_name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('team_id', $team->id)],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (! $quotation->status->isEditable()) {
            return back()->withErrors(['error' => "A quotation that is {$quotation->status->label()} cannot be edited. Revise it first (it will need approval again)."]);
        }

        DB::transaction(function () use ($quotation, $validated, $team) {
            [$itemsData, $subtotal] = $this->buildItems($validated['items'], $team);

            $overallDiscount = (float) ($validated['discount'] ?? 0);
            $taxableAmount = max(0, $subtotal - $overallDiscount);
            $taxRate = isset($validated['tax_rate']) ? (float) $validated['tax_rate'] : (float) $quotation->tax_rate;
            $taxAmount = round($taxableAmount * ($taxRate / 100), 2);
            $grandTotal = $taxableAmount + $taxAmount;

            $quotation->update([
                'client_name' => $validated['client_name'],
                'contact_person' => $validated['contact_person'] ?? null,
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'address' => $validated['address'] ?? null,
                'quotation_date' => $validated['quotation_date'],
                'valid_until' => $validated['valid_until'],
                'subtotal' => $subtotal,
                'discount' => $overallDiscount,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'grand_total' => $grandTotal,
                'status' => $validated['status'],
                'notes' => $validated['notes'] ?? null,
                'terms_and_conditions' => $validated['terms_and_conditions'] ?? $quotation->terms_and_conditions,
            ]);

            // Re-sync line items
            $quotation->items()->delete();
            foreach ($itemsData as $data) {
                $quotation->items()->create($data);
            }
        });

        return back()->with('success', 'Quotation updated successfully.');
    }

    /**
     * Move a quotation between the working statuses (review, supplier pricing, preparation,
     * for approval, cancelled). Approval, sending and the customer's decision have own actions.
     */
    public function updateStatus(Request $request, mixed $current_team = null, mixed $quotation = null): RedirectResponse
    {
        $quotation = $this->authorizedQuotation($request, $quotation, $current_team);
        $validated = $request->validate([
            'status' => ['required', Rule::in(array_map(fn ($s) => $s->value, QuotationStatus::manual()))],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);
        $to = QuotationStatus::from($validated['status']);

        return $this->runWorkflow(function () use ($quotation, $to, $request, $validated) {
            app(QuotationWorkflow::class)->changeStatus($quotation, $to, $request->user(), $validated['remarks'] ?? null);

            return "Status changed to {$to->label()}.";
        });
    }

    /** Admin approval. Does not email the customer and does not touch stock. */
    public function approve(Request $request, mixed $current_team = null, mixed $quotation = null): RedirectResponse
    {
        $quotation = $this->authorizedQuotation($request, $quotation, $current_team);
        abort_unless($request->user()->isAdmin(), 403, 'Only administrators can approve quotations.');
        $remarks = $request->validate(['remarks' => ['nullable', 'string', 'max:1000']])['remarks'] ?? null;

        return $this->runWorkflow(function () use ($quotation, $request, $remarks) {
            [$approved, $notSent] = app(QuotationWorkflow::class)->approve($quotation, $request->user(), $remarks);
            if ($notSent) {
                session()->flash('warning', "Approved, but not sent yet: {$notSent}");

                return "Quotation {$approved->quotation_number} approved.";
            }

            return "Quotation {$approved->quotation_number} approved and sent to {$approved->email}.";
        });
    }

    /** Email the approved quotation PDF to the customer. */
    public function send(Request $request, mixed $current_team = null, mixed $quotation = null): RedirectResponse
    {
        $quotation = $this->authorizedQuotation($request, $quotation, $current_team);

        return $this->runWorkflow(function () use ($quotation, $request) {
            $sent = app(QuotationWorkflow::class)->send($quotation, $request->user());

            return "Quotation {$sent->quotation_number} is being emailed to {$sent->email}. Its email status shows whether sending worked.";
        });
    }

    /** Record the customer's acceptance or rejection, as received by staff. */
    public function recordDecision(Request $request, mixed $current_team = null, mixed $quotation = null): RedirectResponse
    {
        $quotation = $this->authorizedQuotation($request, $quotation, $current_team);
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['accepted', 'rejected'])],
            'source' => ['required', Rule::in(array_keys(QuotationWorkflow::DECISION_SOURCES))],
            'decided_on' => ['nullable', 'date', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            // Used for the Sales Order that an acceptance creates.
            'fulfillment_type' => ['nullable', Rule::in(['delivery', 'pickup'])],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'delivery_instructions' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->runWorkflow(function () use ($quotation, $request, $validated) {
            $accepted = $validated['decision'] === 'accepted';
            [, $sale, $warnings, $notConverted] = app(QuotationWorkflow::class)->recordDecision(
                $quotation, $accepted, $validated['source'], $validated['remarks'] ?? null, $request->user(), $validated['decided_on'] ?? null,
                ['fulfillment_type' => $validated['fulfillment_type'] ?? 'delivery', 'address' => $validated['delivery_address'] ?? null, 'delivery_instructions' => $validated['delivery_instructions'] ?? null],
            );

            if (! $accepted) {
                return "Customer rejection recorded for {$quotation->quotation_number}.";
            }
            if ($notConverted) {
                session()->flash('warning', "The Sales Order could not be created automatically: {$notConverted} Fix it, then use Generate Sales Order.");

                return "Customer acceptance recorded for {$quotation->quotation_number}.";
            }
            if ($warnings) {
                session()->flash('warning', 'Stock check: '.implode(' ', $warnings));
            }

            return "Acceptance recorded. Sales Order {$sale->order_number} was created (Pending) and the customer is being emailed the order and payment instructions.";
        });
    }

    /**
     * Generate the Sales Order from an accepted quotation. Nothing is paid or taken from stock:
     * the order waits for payment (or approved terms) before it is confirmed.
     */
    public function convertToSale(Request $request, mixed $current_team = null, mixed $quotation = null): RedirectResponse
    {
        $quotation = $this->authorizedQuotation($request, $quotation, $current_team);
        if ($request->filled('fulfillment_type')) {
            $request->merge(['fulfillment_type' => strtolower((string) $request->input('fulfillment_type'))]);
        }
        $validated = $request->validate([
            'fulfillment_type' => ['nullable', Rule::in(['delivery', 'pickup'])],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'delivery_instructions' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->runWorkflow(function () use ($quotation, $request, $validated) {
            [$sale, $warnings] = app(SalesOrderService::class)->createFromQuotation($quotation, $request->user(), [
                'fulfillment_type' => $validated['fulfillment_type'] ?? 'delivery',
                'address' => $validated['delivery_address'] ?? null,
                'delivery_instructions' => $validated['delivery_instructions'] ?? null,
            ]);
            if ($warnings) {
                session()->flash('warning', 'Stock check: '.implode(' ', $warnings));
            }

            return "Sales Order {$sale->order_number} created (pending payment). The customer is being emailed the order and payment instructions.";
        });
    }

    /** The customer-facing quotation PDF (no supplier costs or internal notes). */
    public function pdf(Request $request, mixed $current_team = null, mixed $quotation = null)
    {
        $quotation = $this->authorizedQuotation($request, $quotation, $current_team);
        $sent = $request->boolean('sent') && $quotation->sent_pdf_path && Storage::disk('local')->exists($quotation->sent_pdf_path);
        $content = $sent ? Storage::disk('local')->get($quotation->sent_pdf_path) : app(DocumentPdf::class)->quotation($quotation);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Quotation-'.$quotation->quotation_number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function authorizedQuotation(Request $request, mixed $quotation, mixed $current_team): Quotation
    {
        $quotation = $this->resolveQuotation($quotation, $current_team);
        $team = $this->getTeam($request, $current_team);
        abort_if(! $team || $quotation->team_id !== $team->id, 403, 'Unauthorized quotation access.');

        return $quotation;
    }

    /**
     * Statuses a quotation can be saved with from the staff form.
     */
    private const FORM_STATUSES = ['pending', 'preparing', 'for_approval'];

    /**
     * Validated form lines → rows to save, and the subtotal. The supplier cost and supplier are
     * kept with the line (internal); without a cost entered, the product's current cost is used.
     *
     * @return array{0: list<array<string, mixed>>, 1: float}
     */
    private function buildItems(array $items, Team $team): array
    {
        $products = Product::where('team_id', $team->id)->whereIn('id', collect($items)->pluck('product_id')->filter())->get()->keyBy('id');
        $rows = [];
        $subtotal = 0.0;

        foreach ($items as $item) {
            $discount = (float) ($item['discount'] ?? 0);
            $price = (float) $item['unit_price'];
            $qty = (int) $item['quantity'];
            $lineTotal = max(0, ($price - $discount) * $qty);
            $subtotal += $lineTotal;
            $product = isset($item['product_id']) ? $products->get((int) $item['product_id']) : null;

            $rows[] = [
                'product_id' => $product?->id,
                'sku' => $item['sku'],
                'product_name' => $item['product_name'],
                'quantity' => $qty,
                'unit_price' => $price,
                'unit_cost' => isset($item['unit_cost']) && $item['unit_cost'] !== '' ? (float) $item['unit_cost'] : $product?->cost_price,
                'supplier_id' => ($item['supplier_id'] ?? null) ?: $product?->supplier_id,
                'discount' => $discount,
                'subtotal' => $lineTotal,
            ];
        }

        return [$rows, $subtotal];
    }

    /**
     * Download or view attached quotation specification file.
     */
    public function downloadAttachment(Request $request, mixed $current_team = null, mixed $quotation = null)
    {
        $quotation = $this->resolveQuotation($quotation, $current_team);
        $team = $this->getTeam($request, $current_team);
        abort_if(! $team || $quotation->team_id !== $team->id, 403, 'Unauthorized quotation access.');

        // ?file=materials: the client's original material-list file instead of the general attachment.
        $isMaterialFile = $request->query('file') === 'materials';
        $path = $isMaterialFile ? $quotation->material_file_path : $quotation->attachment_path;
        abort_if(! $path, 404, 'No attachment associated with this quotation.');
        abort_if(! Storage::disk('local')->exists($path), 404, 'Attachment file not found.');

        return Storage::disk('local')->response(
            $path,
            $isMaterialFile ? ($quotation->material_file_name ?: basename($path)) : null,
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']
        );
    }

    /**
     * Open a product-list file the client sent with a request. Staff only (route group), and
     * only for a quotation of the current team; the file must belong to that quotation.
     */
    public function downloadFile(Request $request, mixed $current_team = null, mixed $quotation = null, mixed $file = null)
    {
        $quotation = $this->resolveQuotation($quotation ?? $request->route('quotation'));
        $team = $this->getTeam($request, $current_team);
        abort_if(! $team || $quotation->team_id !== $team->id, 403, 'Unauthorized quotation access.');

        $record = $quotation->files()->whereKey((int) ($file ?? $request->route('file')))->first();
        abort_if(! $record || ! Storage::disk('local')->exists($record->path), 404, 'File not found.');

        return Storage::disk('local')->response($record->path, $record->original_name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Import a material list (PDF, Excel, CSV, JPG or PNG) as a new Draft quotation.
     *
     * Without `execute` it only reads the file and returns the lines for staff to check. With
     * `execute` it creates the quotation: lines whose product code matches the catalog get the
     * catalog price, every other line is a custom item at ₱0.00 for staff to price. The file's
     * fingerprint is stored, so importing the same file again is refused instead of duplicated.
     */
    public function importMaterials(Request $request, FileReader $reader): JsonResponse|RedirectResponse
    {
        $team = $this->getTeam($request);
        abort_if(! $team, 404, 'Team not found.');

        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'extensions:'.implode(',', FileReader::EXTENSIONS)],
            'client_name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'execute' => ['nullable', 'boolean'],
        ], [
            'file.max' => 'The file is larger than 10 MB. Please upload a smaller file.',
            'file.extensions' => 'This file type is not supported. Please upload a PDF, Excel (.xlsx/.xls), CSV, JPG or PNG file.',
            'client_name.required' => 'Please enter the client or company name.',
        ]);

        $fail = fn (string $message, int $status = 422, array $extra = []) => $request->boolean('execute')
            ? back()->withErrors(['file' => $message])
            : response()->json(['success' => false, 'message' => $message] + $extra, $status);

        $file = $request->file('file');
        $path = $file->getRealPath() ?: $file->getPathname();
        $hash = hash_file('sha256', $path);

        $duplicate = Quotation::where('team_id', $team->id)->where('source_file_sha256', $hash)->first(['id', 'quotation_number']);
        if ($duplicate) {
            return $fail("This file was already imported as quotation {$duplicate->quotation_number}. Open that quotation instead of importing it again.", 409, ['duplicate_of' => $duplicate->quotation_number]);
        }

        @set_time_limit(180); // OCR of a scanned file can take a while

        try {
            $type = $reader->detectType($path, $file->getClientOriginalName());
            $read = $reader->read($path, $type);
            $parsed = isset($read['rows'])
                ? MaterialParser::parseTable($read['rows'], null, null, $read['method'])
                : MaterialParser::parseText(MaterialParser::cleanLines($read['pages']), $read['method']);
        } catch (ExtractionException $e) {
            return $fail(str_replace(['Please type your material list below instead.', ', or type your material list below', 'Please type your material list below, or upload an Excel/CSV file.'], ['', '', 'Please upload an Excel or CSV file instead.'], $e->getMessage()));
        }

        $items = (new ProductMatcher($team->id))->matchAll($parsed['items']);
        $valid = array_values(array_filter($items, fn ($i) => ($i['errors'] ?? []) === []));
        $ocr = in_array($read['method'], ['ocr', 'pdf-ocr'], true);

        if ($valid === []) {
            return $fail(isset($read['rows'])
                ? 'No materials were found. The file needs a description column and a quantity column (for example "Description" and "Qty").'
                : 'No materials with a quantity were found in this file. Please check the file, or use an Excel or CSV file.');
        }

        if (! $request->boolean('execute')) {
            return response()->json([
                'success' => true,
                'method' => $read['method'],
                'warning' => $ocr ? 'Read with text recognition (OCR). Check every line against the file after importing.' : null,
                'valid_count' => count($valid),
                'invalid_count' => count($items) - count($valid),
                'unparsed_count' => count($parsed['unparsed_lines'] ?? []),
                'items' => array_map(fn ($i) => [
                    'description' => $i['description'],
                    'quantity' => $i['quantity'],
                    'unit' => $i['unit'],
                    'brand' => $i['brand'],
                    'errors' => $i['errors'] ?? [],
                    'match' => $i['match'] ? ['sku' => $i['match']['sku'], 'name' => $i['match']['name'], 'price' => $i['match']['price']] : null,
                ], array_slice($items, 0, 200)),
            ]);
        }

        $storedFile = null;
        try {
            $quotation = $this->createImportedQuotation($request, $team, $file, $hash, $valid, $parsed, $storedFile);
        } catch (QuotationNumberException|QueryException $e) {
            if ($storedFile) {
                Storage::disk('local')->delete($storedFile);
            }

            return $fail($this->savingFailed($e, $team, 'material-list import'), 503);
        }

        return back()->with('success', "Created draft quotation {$quotation->quotation_number} with {$quotation->items()->count()} line(s). Check the lines against the original file and set the prices.");
    }

    /**
     * The draft quotation for an imported material list, in one transaction (retried on deadlock).
     * $storedFile receives the stored copy of the file, for clean-up if saving fails.
     */
    private function createImportedQuotation(Request $request, Team $team, UploadedFile $file, string $hash, array $valid, array $parsed, ?string &$storedFile): Quotation
    {
        $storedFile = $file->storeAs('quotations/attachments', 'material-list-'.Str::uuid().'.'.strtolower($file->getClientOriginalExtension()), 'local');

        return DB::transaction(function () use ($request, $team, $file, $hash, $valid, $parsed, $storedFile) {
            $client = Client::firstOrCreate(
                ['team_id' => $team->id, 'company_name' => $request->input('client_name')],
                ['contact_person' => $request->input('contact_person'), 'email' => $request->input('email'), 'phone' => $request->input('phone')]
            );

            $subtotal = 0.0;
            $lines = [];
            foreach ($valid as $item) {
                $price = $item['match'] && (float) $item['match']['price'] > 0 ? round((float) $item['match']['price'], 2) : 0.0;
                $lines[] = [
                    'product_id' => $item['match']['product_id'] ?? null,
                    'sku' => $item['match']['sku'] ?? 'CUSTOM',
                    'product_name' => Str::limit($item['match']['name'] ?? trim($item['description'].($item['brand'] ? " — {$item['brand']}" : '')), 250),
                    'quantity' => (int) $item['quantity'],
                    'unit_price' => $price,
                    'discount' => 0.00,
                    'subtotal' => round($price * (int) $item['quantity'], 2),
                ];
                $subtotal += round($price * (int) $item['quantity'], 2);
            }

            $taxRate = (float) config('quotation.tax_rate', 12);
            $taxAmount = round($subtotal * $taxRate / 100, 2);
            $unpriced = count(array_filter($lines, fn ($l) => $l['unit_price'] <= 0));
            $originalName = Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())), 200, '');

            $quotation = Quotation::createWithNumber([
                'team_id' => $team->id,
                'client_id' => $client->id,
                'client_name' => $client->company_name,
                'contact_person' => $request->input('contact_person') ?: $client->contact_person,
                'email' => $request->input('email') ?: $client->email,
                'phone' => $request->input('phone') ?: $client->phone,
                'quotation_date' => now()->toDateString(),
                'valid_until' => now()->addDays(30)->toDateString(),
                'subtotal' => round($subtotal, 2),
                'discount' => 0.00,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'grand_total' => round($subtotal + $taxAmount, 2),
                'status' => QuotationStatus::Preparing,
                'notes' => "Imported from material list file: {$originalName}\n".count($lines).' line(s) read'
                    .($unpriced ? ", {$unpriced} need pricing and product matching by staff." : '.')
                    .(count($parsed['unparsed_lines'] ?? []) ? "\n".count($parsed['unparsed_lines']).' line(s) of the file could not be read as materials: check the original file.' : ''),
                'terms_and_conditions' => $this->defaultTerms(),
                'prepared_by_id' => $request->user()?->id,
                'material_file_path' => $storedFile,
                'material_file_name' => $originalName,
                'source_file_sha256' => $hash,
                'request_details' => [
                    'source' => 'staff_import',
                    'file_name' => $originalName,
                    'imported_by' => $request->user()?->name,
                    'submitted_at' => now()->toIso8601String(),
                ],
            ], collect($lines)->firstWhere('product_id')['sku'] ?? null);
            $quotation->items()->createMany($lines);

            return $quotation;
        }, 3);
    }

    /**
     * Delete the specified quotation.
     */
    public function destroy(Request $request, mixed $current_team = null, mixed $quotation = null): RedirectResponse
    {
        $quotation = $this->resolveQuotation($quotation, $current_team);
        $team = $this->getTeam($request, $current_team);
        abort_if(! $team || $quotation->team_id !== $team->id, 403, 'Unauthorized quotation access.');

        $quotation->delete();

        return back()->with('success', 'Quotation deleted.');
    }

    /**
     * Helper to retrieve team from request or route parameter.
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
     * Resolve quotation from route parameters.
     */
    protected function resolveQuotation(mixed $quotation, mixed $fallback = null): Quotation
    {
        if ($quotation instanceof Quotation) {
            return $quotation;
        }

        if (is_numeric($quotation)) {
            return Quotation::findOrFail((int) $quotation);
        }

        if ($fallback instanceof Quotation) {
            return $fallback;
        }

        if (is_numeric($fallback)) {
            return Quotation::findOrFail((int) $fallback);
        }

        abort(404, 'Quotation not found.');
    }

    /**
     * Standard Electrical Supply Terms and Conditions template.
     */
    protected function defaultTerms(): string
    {
        return "1. Price Validity: Quotation is valid until the stated expiration date.\n"
             ."2. Payment Terms: 50% down payment upon order confirmation, 50% upon delivery/completion.\n"
             ."3. Delivery: Within 5-7 business days upon receipt of purchase order and down payment.\n"
             ."4. Warranty: 1-Year standard manufacturer warranty against factory defects.\n"
             .'5. Acceptance: To confirm this quote, please sign and return a copy via email.';
    }

    /**
     * Process CSV Import for Quotations.
     * Required headers: DATE, QUOTE NUMBER, QUANTITY, DESCRIPTION, UNIT PRICE, AMOUNT
     */
    public function importCsv(Request $request): JsonResponse|RedirectResponse
    {
        $team = $this->getTeam($request);
        abort_if(! $team, 404, 'Team not found.');

        $request->validate([
            'file' => ['required', 'file', 'extensions:csv,txt'],
            'mode' => ['nullable', 'in:append,replace'],
            'execute' => ['nullable', 'boolean'],
            'default_client_name' => ['nullable', 'string', 'max:255'],
        ]);

        // Replace mode archives (inventory) or overwrites (quotations) existing records, so it is admin-only.
        abort_if($request->input('mode') === 'replace' && ! $request->user()->isAdmin(), 403, 'Only administrators can use Replace mode.');

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

        // Determine delimiter from first line (support tab, comma, semicolon)
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

        // Clean headers (remove UTF-8 BOM, trim, lowercase, strip non-alphanumeric)
        $rawHeaders = $lines[0];
        $headers = array_map(function ($h) {
            $cleaned = preg_replace('/^\xEF\xBB\xBF/', '', (string) $h);

            return preg_replace('/[^a-z0-9]/', '', strtolower($cleaned));
        }, $rawHeaders);

        $dateIdx = $this->findHeaderIndex($headers, ['date', 'quotedate', 'quotationdate']);
        $quoteNumIdx = $this->findHeaderIndex($headers, ['quotenumber', 'quoteno', 'quotationnumber', 'quote', 'quotation']);
        $qtyIdx = $this->findHeaderIndex($headers, ['quantity', 'qty', 'count']);
        $descIdx = $this->findHeaderIndex($headers, ['description', 'productname', 'itemname', 'product', 'item', 'desc']);
        $priceIdx = $this->findHeaderIndex($headers, ['unitprice', 'price', 'rate', 'cost', 'unitcost']);
        $amountIdx = $this->findHeaderIndex($headers, ['amount', 'total', 'subtotal', 'totalamount', 'linetotal']);

        if ($dateIdx === -1 || $qtyIdx === -1 || $descIdx === -1 || $priceIdx === -1) {
            return response()->json([
                'success' => false,
                'message' => 'Required column headers missing. CSV must include headers: DATE, QUOTE NUMBER, QUANTITY, DESCRIPTION, UNIT PRICE, AMOUNT.',
                'valid_rows' => [],
                'invalid_rows' => [],
            ], 422);
        }

        $validRows = [];
        $invalidRows = [];
        $autoNumbers = []; // date => number shown for rows without a quote number

        for ($i = 1; $i < count($lines); $i++) {
            $row = $lines[$i];
            if (empty(array_filter($row))) {
                continue;
            }

            $rowNumber = $i + 1;
            $errors = [];

            // 1. DATE parsing (support MONTH-DAY-YEAR e.g. 10-25-2026, 01-04-2023, MM/DD/YYYY, YYYY-MM-DD)
            $rawDate = isset($row[$dateIdx]) ? trim($row[$dateIdx]) : '';
            $parsedDate = null;
            $displayDate = $rawDate;

            if ($rawDate === '') {
                $errors[] = 'Date is required.';
            } else {
                if (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $rawDate, $m)) {
                    $month = (int) $m[1];
                    $day = (int) $m[2];
                    $year = (int) $m[3];
                    if (checkdate($month, $day, $year)) {
                        $parsedDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
                        $displayDate = sprintf('%02d-%02d-%04d', $month, $day, $year);
                    } else {
                        $errors[] = "Invalid calendar date: '{$rawDate}'.";
                    }
                } else {
                    try {
                        $carbon = Carbon::parse($rawDate);
                        $parsedDate = $carbon->toDateString();
                        $displayDate = $carbon->format('m-d-Y');
                    } catch (Throwable $e) {
                        $errors[] = "Invalid date format: '{$rawDate}'. Expected MONTH-DAY-YEAR (eg 10-25-2026).";
                    }
                }
            }

            // 2. QUANTITY
            $rawQty = isset($row[$qtyIdx]) ? preg_replace('/[^0-9]/', '', (string) $row[$qtyIdx]) : '0';
            $qty = is_numeric($rawQty) ? (int) $rawQty : 0;
            if ($qty <= 0) {
                $errors[] = 'Quantity must be at least 1.';
            }

            // 3. DESCRIPTION
            $desc = isset($row[$descIdx]) ? trim($row[$descIdx]) : '';
            if ($desc === '') {
                $errors[] = 'Description is required.';
            }

            // 4. UNIT PRICE
            $rawPrice = isset($row[$priceIdx]) ? preg_replace('/[^0-9.-]/', '', (string) $row[$priceIdx]) : '0';
            $unitPrice = is_numeric($rawPrice) ? (float) $rawPrice : 0.00;
            if ($unitPrice < 0) {
                $errors[] = 'Unit price cannot be negative.';
            }

            // 5. AMOUNT
            $rawAmount = $amountIdx !== -1 && isset($row[$amountIdx]) ? preg_replace('/[^0-9.-]/', '', (string) $row[$amountIdx]) : '';
            $amount = (is_numeric($rawAmount) && (float) $rawAmount > 0)
                ? (float) $rawAmount
                : round($qty * $unitPrice, 2);

            // 6. QUOTE NUMBER (e.g. Q010423-068SSCR2)
            $rawQuoteNum = $quoteNumIdx !== -1 && isset($row[$quoteNumIdx]) ? trim($row[$quoteNumIdx]) : '';
            $extractedSku = null;
            $autoNumber = $rawQuoteNum === '';

            if (! $autoNumber) {
                if (preg_match('/^[Qq]\d{6,8}-([A-Za-z0-9_-]+)$/', $rawQuoteNum, $skuMatch)) {
                    $extractedSku = strtoupper($skuMatch[1]);
                } elseif (str_contains($rawQuoteNum, '-')) {
                    $parts = explode('-', $rawQuoteNum, 2);
                    $extractedSku = strtoupper(trim($parts[1]));
                }
            } else {
                // No number in the file: rows of the same day become one quotation, numbered when
                // it is saved. The preview only shows the number it would probably get.
                $extractedSku = 'PROD';
                $rawQuoteNum = $autoNumbers[$parsedDate ?? ''] ??= Quotation::peekNumber($team->id, $extractedSku, $parsedDate);
            }

            if (! empty($errors)) {
                $invalidRows[] = [
                    'row_number' => $rowNumber,
                    'date' => $rawDate,
                    'quote_number' => $rawQuoteNum,
                    'quantity' => $rawQty,
                    'description' => $desc,
                    'unit_price' => $rawPrice,
                    'amount' => $rawAmount,
                    'errors' => $errors,
                ];
            } else {
                $validRows[] = [
                    'row_number' => $rowNumber,
                    'date' => $parsedDate,
                    'display_date' => $displayDate,
                    'quote_number' => $rawQuoteNum,
                    'auto_number' => $autoNumber,
                    'sku' => $extractedSku ?: 'SKU-'.strtoupper(substr(md5($desc), 0, 6)),
                    'quantity' => $qty,
                    'description' => $desc,
                    'unit_price' => $unitPrice,
                    'amount' => $amount,
                ];
            }
        }

        // Quote numbers already in the system are skipped on import (never duplicated), unless an
        // administrator chooses Replace mode. Archived quotations still own their numbers.
        $existingNumbers = Quotation::withTrashed()
            ->where('team_id', $team->id)
            ->whereIn('quotation_number', array_unique(array_column($validRows, 'quote_number')))
            ->pluck('quotation_number')->map(fn ($n) => mb_strtolower($n))->flip();
        foreach ($validRows as &$validRow) {
            $validRow['exists'] = ! $validRow['auto_number'] && isset($existingNumbers[mb_strtolower($validRow['quote_number'])]);
        }
        unset($validRow);

        // Preview validation mode
        if (! $request->boolean('execute')) {
            return response()->json([
                'success' => true,
                'existing_count' => count(array_unique(array_column(array_filter($validRows, fn ($r) => $r['exists']), 'quote_number'))),
                'total_rows' => count($lines) - 1,
                'valid_count' => count($validRows),
                'invalid_count' => count($invalidRows),
                'valid_rows' => array_slice($validRows, 0, 50),
                'invalid_rows' => $invalidRows,
            ]);
        }

        if (empty($validRows)) {
            return back()->withErrors(['error' => 'No valid rows found to import from the CSV.']);
        }

        $mode = $request->input('mode', 'append');
        $defaultClientName = $request->input('default_client_name') ?: 'Direct Customer';

        // Group items by Quote Number (rows without one: by day, numbered on save)
        $grouped = [];
        foreach ($validRows as $item) {
            $qNum = $item['auto_number'] ? 'auto|'.$item['date'] : $item['quote_number'];
            $grouped[$qNum][] = $item;
        }

        $skipped = [];
        try {
            $this->importCsvGroups($request, $team, $grouped, $mode, $defaultClientName, $skipped);
        } catch (QuotationNumberException|QueryException $e) {
            $message = $this->savingFailed($e, $team, 'CSV import');
            if ($e instanceof UniqueConstraintViolationException) {
                $message = 'A quote number in this file was used by another quotation while importing. Nothing was imported. Please run the import again.';
            }

            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => $message], 409)
                : back()->withErrors(['error' => $message]);
        }

        $imported = count($grouped) - count($skipped);
        $message = "Imported {$imported} quotation(s)."
            .($skipped ? ' Skipped '.count($skipped).' that already exist: '.implode(', ', array_slice($skipped, 0, 5)).(count($skipped) > 5 ? '…' : '').'.' : '');

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => $message, 'skipped' => $skipped]);
        }

        return back()->with('success', $message);
    }

    /**
     * Save the grouped CSV rows as quotations, all in one transaction (retried on deadlock).
     *
     * @param  array<string, array<int, array<string, mixed>>>  $grouped  quote number (or "auto|<date>") => rows
     * @param  array<int, string>  $skipped  receives the quote numbers that already exist
     */
    private function importCsvGroups(Request $request, Team $team, array $grouped, string $mode, string $defaultClientName, array &$skipped): void
    {
        DB::transaction(function () use ($team, $grouped, $mode, $defaultClientName, $request, &$skipped) {
            $skipped = [];
            $client = Client::firstOrCreate(
                [
                    'team_id' => $team->id,
                    'company_name' => $defaultClientName,
                ],
                [
                    'contact_person' => 'Direct Quotation Contact',
                ]
            );

            foreach ($grouped as $quoteNumber => $items) {
                $first = $items[0];
                $qDate = $first['date'];
                $validUntil = Carbon::parse($qDate)->addDays(30)->toDateString();
                $totalAmount = array_sum(array_column($items, 'amount'));

                $auto = (bool) $first['auto_number'];
                $existing = $auto ? null : Quotation::withTrashed()->where('team_id', $team->id)->where('quotation_number', $quoteNumber)->first();

                if ($existing && $mode === 'replace' && ! $existing->trashed()) {
                    $quotation = $existing;
                    $quotation->update([
                        'quotation_date' => $qDate,
                        'valid_until' => $validUntil,
                        'subtotal' => $totalAmount,
                        'grand_total' => $totalAmount,
                        'status' => QuotationStatus::Preparing,
                    ]);
                    $quotation->items()->delete();
                } elseif ($existing) {
                    // Importing the same file again must not create a second copy of the quotation,
                    // and an archived quotation keeps its number (it is never overwritten from here).
                    $skipped[] = $quoteNumber;

                    continue;
                } else {
                    $attributes = [
                        'team_id' => $team->id,
                        'client_id' => $client->id,
                        'client_name' => $client->company_name,
                        'quotation_date' => $qDate,
                        'valid_until' => $validUntil,
                        'subtotal' => $totalAmount,
                        'discount' => 0.00,
                        'tax_rate' => 0.00,
                        'tax_amount' => 0.00,
                        'grand_total' => $totalAmount,
                        'status' => QuotationStatus::Preparing,
                        'notes' => 'Imported via CSV file with headers: DATE, QUOTE NUMBER, QUANTITY, DESCRIPTION, UNIT PRICE, AMOUNT',
                        'terms_and_conditions' => $this->defaultTerms(),
                        'prepared_by_id' => $request->user()?->id,
                    ];
                    // The file's own number is kept as written; rows without one get the next free number.
                    $quotation = $auto
                        ? Quotation::createWithNumber($attributes, 'PROD', $qDate)
                        : Quotation::create(['quotation_number' => $quoteNumber] + $attributes);
                }

                foreach ($items as $item) {
                    $matchedProduct = Product::where('team_id', $team->id)
                        ->where(function ($q) use ($item) {
                            $q->where('sku', $item['sku'])
                                ->orWhere('name', $item['description']);
                        })->first();

                    $quotation->items()->create([
                        'product_id' => $matchedProduct?->id,
                        'sku' => $matchedProduct?->sku ?? $item['sku'],
                        'product_name' => $item['description'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'discount' => 0.00,
                        'subtotal' => $item['amount'],
                    ]);
                }
            }
        }, 3);
    }

    /**
     * Helper to find matching header index.
     */
    protected function findHeaderIndex(array $headers, array $candidates): int
    {
        foreach ($candidates as $cand) {
            $idx = array_search($cand, $headers, true);
            if ($idx !== false) {
                return (int) $idx;
            }
        }
        foreach ($candidates as $cand) {
            foreach ($headers as $idx => $h) {
                if (str_contains($h, $cand)) {
                    return (int) $idx;
                }
            }
        }

        return -1;
    }
}
