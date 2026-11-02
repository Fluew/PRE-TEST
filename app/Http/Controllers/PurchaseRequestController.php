<?php

namespace App\Http\Controllers;

use App\Models\CommercialInvoice;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\Team;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseRequestController extends Controller
{
    /**
     * Display procurement monitoring page.
     */
    public function index(Request $request): Response
    {
        $team = $this->getTeam($request);
        abort_if(! $team, 403, 'No active team selected.');

        $search = $request->input('search');
        $status = $request->input('status', 'All');
        $supplierId = $request->input('supplier_id', 'All');
        $unitOfMeasure = $request->input('unit_of_measure', 'All');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $activeTab = $request->input('tab', 'commercial_invoices');

        // 1. Purchase Requests
        $query = PurchaseRequest::where('team_id', $team->id)
            ->with([
                'product:id,name,sku',
                'supplier:id,name',
                'requestedBy:id,name',
                'reviewedBy:id,name',
                'purchaseOrder:id,purchase_request_id,po_number,status',
            ]);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('request_number', 'like', "%{$search}%")
                    ->orWhereHas('product', fn ($pq) => $pq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$search}%"));
            });
        }

        if ($status && $status !== 'All') {
            $query->where('status', $status);
        }

        if ($supplierId && $supplierId !== 'All') {
            $query->where('supplier_id', (int) $supplierId);
        }

        if ($dateFrom) {
            $query->whereDate('requested_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('requested_at', '<=', $dateTo);
        }

        $requests = $query->latest('requested_at')->latest('id')->paginate(15, ['*'], 'requests_page')->withQueryString();

        // 2. Commercial Invoices
        $ciQuery = CommercialInvoice::where('team_id', $team->id)->with(['supplier:id,name', 'purchaseOrder:id,po_number', 'delivery:id,delivery_number', 'verifiedBy:id,name']);
        if ($search) {
            $ciQuery->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$search}%"));
            });
        }
        if ($status && $status !== 'All') {
            $ciQuery->where('status', $status);
        }
        if ($supplierId && $supplierId !== 'All') {
            $ciQuery->where('supplier_id', (int) $supplierId);
        }
        if ($dateFrom) {
            $ciQuery->whereDate('invoice_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $ciQuery->whereDate('invoice_date', '<=', $dateTo);
        }
        $commercialInvoices = $ciQuery->latest('invoice_date')->latest('id')->paginate(15, ['*'], 'ci_page')->withQueryString();

        // 3. Purchase Orders
        $poQuery = PurchaseOrder::where('team_id', $team->id)->with(['supplier:id,name', 'product:id,name,sku', 'purchaseRequest:id,request_number'])->withCount('deliveries');
        if ($search) {
            $poQuery->where(function ($q) use ($search) {
                $q->where('po_number', 'like', "%{$search}%")
                    ->orWhere('item_sku', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$search}%"));
            });
        }
        if ($status && $status !== 'All') {
            $poQuery->where('status', $status);
        }
        if ($supplierId && $supplierId !== 'All') {
            $poQuery->where('supplier_id', (int) $supplierId);
        }
        if ($unitOfMeasure && $unitOfMeasure !== 'All') {
            $poQuery->where('unit_of_measure', $unitOfMeasure);
        }
        if ($dateFrom) {
            $poQuery->whereDate('po_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $poQuery->whereDate('po_date', '<=', $dateTo);
        }
        $purchaseOrders = $poQuery->latest('po_date')->latest('id')->paginate(15, ['*'], 'po_page')->withQueryString();

        // Summary metrics - 3 fast aggregate queries instead of 8
        $prRow = PurchaseRequest::where('team_id', $team->id)
            ->selectRaw("
                COUNT(*) as total_requests,
                SUM(CASE WHEN status = 'Pending Approval' THEN 1 ELSE 0 END) as requested_count,
                SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as ordered_count,
                SUM(CASE WHEN status = 'Converted to Purchase Order' THEN 1 ELSE 0 END) as delivered_count
            ")
            ->first();

        $ciRow = CommercialInvoice::where('team_id', $team->id)
            ->selectRaw('COUNT(*) as total_count, COALESCE(SUM(total_cost), 0) as total_amount')
            ->first();

        $poRow = PurchaseOrder::where('team_id', $team->id)
            ->selectRaw('COUNT(*) as total_count, COALESCE(SUM(total_cost), 0) as total_amount')
            ->first();

        $metrics = [
            'totalRequests' => (int) ($prRow->total_requests ?? 0),
            'requestedCount' => (int) ($prRow->requested_count ?? 0),
            'orderedCount' => (int) ($prRow->ordered_count ?? 0),
            'deliveredCount' => (int) ($prRow->delivered_count ?? 0),
            'totalCommercialInvoices' => (int) ($ciRow->total_count ?? 0),
            'totalCommercialInvoiceAmount' => (float) ($ciRow->total_amount ?? 0),
            'totalPurchaseOrders' => (int) ($poRow->total_count ?? 0),
            'totalPurchaseOrderAmount' => (float) ($poRow->total_amount ?? 0),
        ];

        // Available products and suppliers for forms
        $products = Product::where('team_id', $team->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'cost_price', 'stock', 'reorder_level']);

        // Plain arrays: cached objects come back as __PHP_Incomplete_Class (cache.serializable_classes).
        $suppliers = Cache::remember("team:{$team->id}:procurement_suppliers_v2", 300, function () use ($team) {
            return Supplier::where('team_id', $team->id)
                ->where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->toArray();
        });

        return Inertia::render('Procurement/Index', [
            'requests' => $requests,
            'commercialInvoices' => $commercialInvoices,
            'purchaseOrders' => $purchaseOrders,
            'activeTab' => $activeTab,
            'filters' => [
                'search' => $search,
                'status' => $status,
                'supplier_id' => $supplierId,
                'unit_of_measure' => $unitOfMeasure,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'tab' => $activeTab,
            ],
            'metrics' => $metrics,
            'products' => $products,
            'suppliers' => $suppliers,
            'isAdmin' => $request->user()->isAdmin(),
            'statusOptions' => [
                'requests' => PurchaseRequest::STATUSES,
                'purchase_orders' => PurchaseOrder::STATUSES,
                'commercial_invoices' => [CommercialInvoice::PENDING, CommercialInvoice::FLAGGED, CommercialInvoice::VERIFIED, CommercialInvoice::CANCELLED],
            ],
        ]);
    }

    /**
     * Delete a purchase request (only if status is Requested).
     */
    public function destroy(Request $request, mixed $current_team = null, mixed $purchaseRequest = null): RedirectResponse
    {
        $purchaseRequest = $this->resolvePurchaseRequest($purchaseRequest, $current_team);
        $team = $this->getTeam($request, $current_team);
        abort_if(! $team || $purchaseRequest->team_id !== $team->id, 403);

        if (! in_array($purchaseRequest->status, [PurchaseRequest::DRAFT, PurchaseRequest::PENDING, PurchaseRequest::REJECTED], true)) {
            return back()->withErrors(['status' => 'Only draft, pending or rejected requests can be deleted; approved and converted requests are kept for the audit trail.']);
        }

        $purchaseRequest->delete();

        return back()->with('success', 'Purchase request deleted.');
    }

    /**
     * Delete a commercial invoice.
     */
    public function destroyCommercialInvoice(Request $request, mixed $current_team = null, mixed $commercialInvoice = null): RedirectResponse
    {
        $commercialInvoice = $this->resolveCommercialInvoice($commercialInvoice, $current_team);
        $team = $this->getTeam($request, $current_team);
        abort_if(! $team || $commercialInvoice->team_id !== $team->id, 403);

        $commercialInvoice->delete();

        return back()->with('success', 'Commercial invoice deleted successfully.');
    }

    /**
     * Process CSV Import for Commercial Invoices.
     * Required headers: DATE, COMMERCIAL INVOICE, QUANTITY, Description, UNIT COST
     */
    public function importCommercialInvoicesCsv(Request $request): JsonResponse|RedirectResponse
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

        $dateIdx = $this->findHeaderIndex($headers, ['date', 'invoicedate']);
        $ciIdx = $this->findHeaderIndex($headers, ['commercialinvoice', 'commercialinvoiceno', 'commercialinvoicenumber', 'invoicenumber', 'invoiceno', 'ci']);
        $qtyIdx = $this->findHeaderIndex($headers, ['quantity', 'qty', 'count']);
        $descIdx = $this->findHeaderIndex($headers, ['description', 'desc', 'itemdescription']);
        $costIdx = $this->findHeaderIndex($headers, ['unitcost', 'cost', 'unitprice', 'price', 'rate']);

        if ($dateIdx === -1 || $qtyIdx === -1 || $descIdx === -1 || $costIdx === -1) {
            return response()->json([
                'success' => false,
                'message' => 'Required column headers missing. CSV must include headers: DATE, COMMERCIAL INVOICE, QUANTITY, Description, UNIT COST.',
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

            // 1. DATE
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
                    } catch (\Throwable $e) {
                        $errors[] = "Invalid date format: '{$rawDate}'. Expected MONTH-DAY-YEAR (eg 10-25-2026).";
                    }
                }
            }

            // 2. COMMERCIAL INVOICE
            $rawCi = $ciIdx !== -1 && isset($row[$ciIdx]) ? trim($row[$ciIdx]) : '';
            if ($rawCi === '' && $parsedDate) {
                $rawCi = CommercialInvoice::generateInvoiceNumber($team->id, $parsedDate);
            }

            // 3. QUANTITY
            $rawQty = isset($row[$qtyIdx]) ? preg_replace('/[^0-9]/', '', (string) $row[$qtyIdx]) : '0';
            $qty = is_numeric($rawQty) ? (int) $rawQty : 0;
            if ($qty <= 0) {
                $errors[] = 'Quantity must be at least 1.';
            }

            // 4. DESCRIPTION
            $desc = isset($row[$descIdx]) ? trim($row[$descIdx]) : '';
            if ($desc === '') {
                $errors[] = 'Description is required.';
            }

            // 5. UNIT COST
            $rawCost = isset($row[$costIdx]) ? preg_replace('/[^0-9.-]/', '', (string) $row[$costIdx]) : '0';
            $unitCost = is_numeric($rawCost) ? (float) $rawCost : 0.00;
            if ($unitCost < 0) {
                $errors[] = 'Unit cost cannot be negative.';
            }

            $totalCost = round($qty * $unitCost, 2);

            if (! empty($errors)) {
                $invalidRows[] = [
                    'row_number' => $rowNumber,
                    'date' => $rawDate,
                    'invoice_number' => $rawCi,
                    'quantity' => $rawQty,
                    'description' => $desc,
                    'unit_cost' => $rawCost,
                    'total_cost' => $totalCost,
                    'errors' => $errors,
                ];
            } else {
                $validRows[] = [
                    'row_number' => $rowNumber,
                    'date' => $parsedDate,
                    'display_date' => $displayDate,
                    'invoice_number' => $rawCi,
                    'quantity' => $qty,
                    'description' => $desc,
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost,
                ];
            }
        }

        $execute = $request->boolean('execute');

        if ($execute && count($validRows) > 0) {
            DB::transaction(function () use ($team, $validRows) {
                foreach ($validRows as $r) {
                    CommercialInvoice::create([
                        'team_id' => $team->id,
                        'invoice_date' => $r['date'],
                        'invoice_number' => $r['invoice_number'],
                        'quantity' => $r['quantity'],
                        'description' => $r['description'],
                        'unit_cost' => $r['unit_cost'],
                        'total_cost' => $r['total_cost'],
                        'status' => CommercialInvoice::PENDING,
                        'recorded_by_id' => auth()->id(),
                        'notes' => 'Imported from CSV: link it to a purchase order and delivery by recording it through the workflow before it can be verified.',
                    ]);
                }
            });

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Successfully imported '.count($validRows).' commercial invoice(s).',
                    'count' => count($validRows),
                    'invalid_count' => count($invalidRows),
                ]);
            }

            return back()->with('success', 'Successfully imported '.count($validRows).' commercial invoice(s).');
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
     * Set the product and promised delivery date of a purchase order (used by Supplier Evaluation).
     * Once a delivery has been received against it, only an administrator may change the promised date.
     */
    public function updatePurchaseOrderSchedule(Request $request, mixed $current_team = null, mixed $purchaseOrder = null): RedirectResponse
    {
        $purchaseOrder = $this->resolvePurchaseOrder($purchaseOrder, $current_team);
        $team = $this->getTeam($request);
        abort_if(! $team || $purchaseOrder->team_id !== $team->id, 403);

        $validated = $request->validate([
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('team_id', $team->id)],
            'promised_delivery_date' => ['nullable', 'date', 'after_or_equal:'.$purchaseOrder->po_date->toDateString()],
        ]);

        $newDate = $validated['promised_delivery_date'] ?? null;
        $oldDate = $purchaseOrder->promised_delivery_date?->toDateString();
        if ($newDate !== $oldDate && $purchaseOrder->deliveries()->exists() && ! $request->user()->isAdmin()) {
            return back()->withErrors(['promised_delivery_date' => 'Goods were already received against this purchase order, so only an administrator can change its promised date.']);
        }

        $purchaseOrder->update(['product_id' => $validated['product_id'] ?? null, 'promised_delivery_date' => $newDate]);
        AuditLogger::record('purchase_order.schedule_updated', "Purchase order {$purchaseOrder->po_number}: product and promised delivery date updated.", $purchaseOrder, ['promised_delivery_date' => [$oldDate, $newDate], 'product_id' => $purchaseOrder->product_id], $request->user());

        return back()->with('success', "Purchase order {$purchaseOrder->po_number} updated.");
    }

    private function productIdForSku(int $teamId, ?string $sku): ?int
    {
        return $sku ? Product::where('team_id', $teamId)->where('sku', trim($sku))->value('id') : null;
    }

    /**
     * Delete a purchase order.
     */
    public function destroyPurchaseOrder(Request $request, mixed $current_team = null, mixed $purchaseOrder = null): RedirectResponse
    {
        $purchaseOrder = $this->resolvePurchaseOrder($purchaseOrder, $current_team);
        $team = $this->getTeam($request, $current_team);
        abort_if(! $team || $purchaseOrder->team_id !== $team->id, 403);

        $purchaseOrder->delete();

        return back()->with('success', 'Purchase order deleted successfully.');
    }

    /**
     * Process CSV Import for Purchase Orders.
     * Required headers: Date, Purchase Order No., Quantity, ITEM/SKU Number, Description, U/M, Unit Cost
     */
    public function importPurchaseOrdersCsv(Request $request): JsonResponse|RedirectResponse
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

        $dateIdx = $this->findHeaderIndex($headers, ['date', 'podate']);
        $poIdx = $this->findHeaderIndex($headers, ['purchaseorderno', 'purchaseordernumber', 'pono', 'ponumber', 'purchaseorder']);
        $qtyIdx = $this->findHeaderIndex($headers, ['quantity', 'qty', 'count']);
        $skuIdx = $this->findHeaderIndex($headers, ['itemskunumber', 'itemsku', 'skunumber', 'sku', 'itemno', 'itemnumber']);
        $descIdx = $this->findHeaderIndex($headers, ['description', 'desc', 'itemdescription']);
        $umIdx = $this->findHeaderIndex($headers, ['um', 'uom', 'unitofmeasure', 'unit', 'measure']);
        $costIdx = $this->findHeaderIndex($headers, ['unitcost', 'cost', 'unitprice', 'price', 'rate']);

        if ($dateIdx === -1 || $qtyIdx === -1 || $descIdx === -1 || $costIdx === -1) {
            return response()->json([
                'success' => false,
                'message' => 'Required column headers missing. CSV must include headers: Date, Purchase Order No., Quantity, ITEM/SKU Number, Description, U/M, Unit Cost.',
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

            // 1. DATE
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
                    } catch (\Throwable $e) {
                        $errors[] = "Invalid date format: '{$rawDate}'. Expected MONTH-DAY-YEAR (eg 10-25-2026).";
                    }
                }
            }

            // 2. PURCHASE ORDER NO
            $rawPo = $poIdx !== -1 && isset($row[$poIdx]) ? trim($row[$poIdx]) : '';
            if ($rawPo === '') {
                $rawPo = PurchaseOrder::generatePoNumber($team->id);
            }

            // 3. QUANTITY
            $rawQty = isset($row[$qtyIdx]) ? preg_replace('/[^0-9]/', '', (string) $row[$qtyIdx]) : '0';
            $qty = is_numeric($rawQty) ? (int) $rawQty : 0;
            if ($qty <= 0) {
                $errors[] = 'Quantity must be at least 1.';
            }

            // 4. ITEM/SKU Number
            $rawSku = $skuIdx !== -1 && isset($row[$skuIdx]) ? trim($row[$skuIdx]) : '';

            // 5. DESCRIPTION
            $desc = isset($row[$descIdx]) ? trim($row[$descIdx]) : '';
            if ($desc === '') {
                $errors[] = 'Description is required.';
            }

            // 6. U/M
            $rawUm = $umIdx !== -1 && isset($row[$umIdx]) && trim($row[$umIdx]) !== '' ? trim($row[$umIdx]) : 'PCS';

            // 7. UNIT COST
            $rawCost = isset($row[$costIdx]) ? preg_replace('/[^0-9.-]/', '', (string) $row[$costIdx]) : '0';
            $unitCost = is_numeric($rawCost) ? (float) $rawCost : 0.00;
            if ($unitCost < 0) {
                $errors[] = 'Unit cost cannot be negative.';
            }

            $totalCost = round($qty * $unitCost, 2);

            if (! empty($errors)) {
                $invalidRows[] = [
                    'row_number' => $rowNumber,
                    'date' => $rawDate,
                    'po_number' => $rawPo,
                    'quantity' => $rawQty,
                    'item_sku' => $rawSku,
                    'description' => $desc,
                    'unit_of_measure' => $rawUm,
                    'unit_cost' => $rawCost,
                    'total_cost' => $totalCost,
                    'errors' => $errors,
                ];
            } else {
                $validRows[] = [
                    'row_number' => $rowNumber,
                    'date' => $parsedDate,
                    'display_date' => $displayDate,
                    'po_number' => $rawPo,
                    'quantity' => $qty,
                    'item_sku' => $rawSku,
                    'description' => $desc,
                    'unit_of_measure' => $rawUm,
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost,
                ];
            }
        }

        $execute = $request->boolean('execute');

        if ($execute && count($validRows) > 0) {
            DB::transaction(function () use ($team, $validRows) {
                foreach ($validRows as $r) {
                    PurchaseOrder::create([
                        'team_id' => $team->id,
                        'po_date' => $r['date'],
                        'po_number' => $r['po_number'],
                        'quantity' => $r['quantity'],
                        'item_sku' => $r['item_sku'] ?: null,
                        'description' => $r['description'],
                        'unit_of_measure' => $r['unit_of_measure'] ?: 'PCS',
                        'unit_cost' => $r['unit_cost'],
                        'total_cost' => $r['total_cost'],
                        // Imported POs have no approved purchase request, so they are historical records only.
                        'status' => PurchaseOrder::CLOSED,
                        'notes' => 'Imported historical record (no approved purchase request). Goods cannot be received against it.',
                    ]);
                }
            });

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Successfully imported '.count($validRows).' purchase order(s).',
                    'count' => count($validRows),
                    'invalid_count' => count($invalidRows),
                ]);
            }

            return back()->with('success', 'Successfully imported '.count($validRows).' purchase order(s).');
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
     * Find index of header matching any candidate.
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
     * Resolve purchase request from route parameters.
     */
    protected function resolvePurchaseRequest(mixed $purchaseRequest, mixed $fallback = null): PurchaseRequest
    {
        if ($purchaseRequest instanceof PurchaseRequest) {
            return $purchaseRequest;
        }

        if (is_numeric($purchaseRequest)) {
            return PurchaseRequest::findOrFail((int) $purchaseRequest);
        }

        if ($fallback instanceof PurchaseRequest) {
            return $fallback;
        }

        if (is_numeric($fallback)) {
            return PurchaseRequest::findOrFail((int) $fallback);
        }

        abort(404, 'Purchase request not found.');
    }

    /**
     * Resolve commercial invoice from route parameters.
     */
    protected function resolveCommercialInvoice(mixed $commercialInvoice, mixed $fallback = null): CommercialInvoice
    {
        if ($commercialInvoice instanceof CommercialInvoice) {
            return $commercialInvoice;
        }

        if (is_numeric($commercialInvoice)) {
            return CommercialInvoice::findOrFail((int) $commercialInvoice);
        }

        if ($fallback instanceof CommercialInvoice) {
            return $fallback;
        }

        if (is_numeric($fallback)) {
            return CommercialInvoice::findOrFail((int) $fallback);
        }

        abort(404, 'Commercial invoice not found.');
    }

    /**
     * Resolve purchase order from route parameters.
     */
    protected function resolvePurchaseOrder(mixed $purchaseOrder, mixed $fallback = null): PurchaseOrder
    {
        if ($purchaseOrder instanceof PurchaseOrder) {
            return $purchaseOrder;
        }

        if (is_numeric($purchaseOrder)) {
            return PurchaseOrder::findOrFail((int) $purchaseOrder);
        }

        if ($fallback instanceof PurchaseOrder) {
            return $fallback;
        }

        if (is_numeric($fallback)) {
            return PurchaseOrder::findOrFail((int) $fallback);
        }

        abort(404, 'Purchase order not found.');
    }
}
