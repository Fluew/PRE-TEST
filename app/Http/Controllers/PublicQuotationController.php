<?php

namespace App\Http\Controllers;

use App\Enums\QuotationStatus;
use App\Models\Brand;
use App\Models\Client;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationFile;
use App\Models\Team;
use App\Services\MaterialList\ExtractionException;
use App\Services\MaterialList\FileReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The public "Request a Quotation" form.
 *
 * A request holds the client's contact and project details, the catalog brands they are
 * interested in, any brands not in our catalog ("brands not listed"), and optional product-list
 * files. Everything is saved with the quotation as client-submitted information with status
 * Pending: nothing is approved, priced or confirmed as available until staff review it.
 */
class PublicQuotationController extends Controller
{
    public const PROJECT_TYPES = ['Commercial Building', 'Industrial Facility', 'Infrastructure/Public Works', 'Residential Complex', 'Other'];

    public const TIMELINES = ['Immediate (ASAP)', '1-3 months', '3-6 months', 'Over 6 months'];

    public const MAX_PRODUCT_FILES = 5;

    /** Together, all product-list files may not exceed this (each file is also limited to 10 MB). */
    public const MAX_PRODUCT_FILES_BYTES = 20 * 1024 * 1024;

    public const PRODUCT_FILE_EXTENSIONS = ['pdf', 'xls', 'xlsx', 'csv', 'jpg', 'jpeg', 'png'];

    public const PRODUCT_FILES_DIR = 'quotations/product-lists';

    /**
     * Distinct, non-empty product brands from active catalog products and partner brands
     * (case-insensitive, first spelling wins), sorted alphabetically.
     *
     * @return list<string>
     */
    public static function availableBrands(): array
    {
        $partnerBrands = Schema::hasTable('brands')
            ? Brand::query()->where('is_active', true)->pluck('name')
            : collect();

        $productBrands = Product::query()
            ->where('is_active', true)
            ->whereNotNull('brand')
            ->pluck('brand');

        return $partnerBrands->concat($productBrands)
            ->map(fn ($b) => trim((string) $b))
            ->filter(fn ($b) => $b !== '')
            ->unique(fn ($b) => mb_strtolower($b))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * Show the public quotation request form with the brands available in the catalog.
     */
    public function create(): Response
    {
        return Inertia::render('Quotations/Create', [
            'brands' => self::availableBrands(),
        ]);
    }

    /**
     * Store a public quotation request.
     */
    public function store(Request $request, FileReader $reader): RedirectResponse
    {
        $validated = $request->validate([
            'company' => ['required', 'string', 'max:255'],
            'contactName' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'projectType' => ['nullable', 'string', Rule::in(self::PROJECT_TYPES)],
            // The form shows "1–3 months"; both dash styles are accepted.
            'timeline' => ['nullable', 'string', Rule::in([...self::TIMELINES, '1–3 months', '3–6 months'])],
            'brands' => ['nullable', 'array', 'max:50'],
            'hasUnlistedBrands' => ['nullable', 'boolean'],
            'unlistedBrands' => ['nullable', 'string', 'max:3000'],

            // Optional files listing specific products the client wants quoted.
            'productFiles' => ['nullable', 'array', 'max:'.self::MAX_PRODUCT_FILES],
            'productFiles.*' => ['file', 'max:10240', 'extensions:'.implode(',', self::PRODUCT_FILE_EXTENSIONS)],
        ], [
            'projectType.in' => 'Please choose a project type from the list.',
            'timeline.in' => 'Please choose an expected timeline from the list.',
            'productFiles.max' => 'You can attach up to '.self::MAX_PRODUCT_FILES.' product-list files.',
            'productFiles.*.max' => 'Each product-list file must be 10 MB or smaller.',
            'productFiles.*.extensions' => 'Product lists must be PDF, Excel (.xls/.xlsx), CSV, JPG or PNG files.',
            'productFiles.*.uploaded' => 'A product-list file could not be uploaded. It may be larger than 10 MB.',
        ]);

        $productFiles = $this->checkProductFiles($request, $reader);

        [$brandItems, $catalog] = $this->brandItems($request);
        $brands = collect($brandItems)->map(fn ($i) => $catalog[mb_strtolower($i['brand'])])->unique()->values()->all();

        $unlisted = $request->boolean('hasUnlistedBrands') ? trim((string) ($validated['unlistedBrands'] ?? '')) : '';
        if ($request->boolean('hasUnlistedBrands') && $unlisted === '') {
            return back()->withErrors([
                'unlistedBrands' => "Please enter the brand name(s) you're looking for.",
            ])->withInput();
        }

        $team = Team::first();
        if ($team) {
            $storedFiles = [];
            try {
                // Retried as a whole if the database reports a deadlock between simultaneous requests.
                DB::transaction(function () use ($validated, $team, $brandItems, $brands, $catalog, $unlisted, $productFiles, &$storedFiles) {
                    $storedFiles = [];
                    $quotation = $this->createQuotation($validated, $team, $brandItems, $brands, $catalog, $unlisted);
                    $this->keepProductFiles($quotation, $productFiles, $storedFiles);
                }, 3);
            } catch (Throwable $e) {
                // Nothing was saved (the transaction rolled back): remove the copied files, log the
                // cause, and send the visitor back to the form with everything they entered.
                Storage::disk('local')->delete($storedFiles);
                Log::error('Public quotation request could not be saved: '.$e->getMessage(), [
                    'exception' => $e,
                    'company' => $validated['company'],
                    'email' => $validated['email'],
                ]);

                return back()->withErrors([
                    'submit' => 'We could not send your request just now. Please try again in a moment. If it keeps happening, please contact us directly.',
                ])->withInput();
            }
        }

        return back()->with('success', 'Thank you! Your quotation request has been received. Our team will review your request and get in touch shortly.');
    }

    /**
     * Check product-list files by content (not just the name) and their combined size.
     *
     * @return array<int, UploadedFile>
     */
    private function checkProductFiles(Request $request, FileReader $reader): array
    {
        $files = array_values(array_filter((array) $request->file('productFiles', []), fn ($f) => $f instanceof UploadedFile));

        $total = array_sum(array_map(fn (UploadedFile $f) => (int) $f->getSize(), $files));
        if ($total > self::MAX_PRODUCT_FILES_BYTES) {
            throw ValidationException::withMessages([
                'productFiles' => 'Product-list files can be up to 20 MB together. Please remove a file or send smaller ones.',
            ]);
        }

        foreach ($files as $i => $file) {
            $path = $file->getRealPath() ?: $file->getPathname();
            $name = Str::limit($file->getClientOriginalName(), 60);
            try {
                $type = $reader->detectType($path, $file->getClientOriginalName());
                // Lists must be written (typed, printed or handwritten text), not plain photos.
                if (! $reader->assertHasText($path, $type)) {
                    $this->uncheckedFiles[] = $file->getClientOriginalName();
                    Log::warning("Public quotation file {$name} was accepted without a text check: OCR is not available.");
                }
            } catch (ExtractionException $e) {
                $message = in_array($e->reason, ['ocr_failed'], true)
                    ? "We couldn't read the text in this file. Please upload a clearer image, or a PDF, Excel or CSV file."
                    : $e->getMessage();
                throw ValidationException::withMessages(["productFiles.{$i}" => "{$name}: {$message}"]);
            }
        }

        return $files;
    }

    /** @var list<string> files accepted without a text check because OCR is not installed */
    private array $uncheckedFiles = [];

    /**
     * Brands chosen in the form, limited to the catalog (case-insensitive, catalog spelling).
     * Accepts ['Schneider'] and [['brand' => 'Schneider', 'details' => '...']].
     *
     * @return array{0: array<int, array{brand: string, details: string}>, 1: Collection}
     */
    private function brandItems(Request $request): array
    {
        $brandItems = [];
        foreach ((array) $request->input('brands', []) as $item) {
            $name = trim((string) (is_array($item) ? ($item['brand'] ?? $item['name'] ?? '') : $item));
            $details = is_array($item) ? trim((string) ($item['details'] ?? $item['quantity'] ?? $item['specifics'] ?? '')) : '';
            if ($name !== '') {
                $brandItems[] = ['brand' => $name, 'details' => Str::limit($details, 1000, '')];
            }
        }

        $catalog = collect(self::availableBrands())->keyBy(fn ($b) => mb_strtolower($b));
        $unknown = collect($brandItems)->pluck('brand')->map(fn ($b) => mb_strtolower($b))->reject(fn ($b) => $catalog->has($b));
        if ($unknown->isNotEmpty()) {
            throw ValidationException::withMessages([
                'brands' => 'One or more selected brands are not available. Please choose from the list.',
            ]);
        }

        return [$brandItems, $catalog];
    }

    private function createQuotation(array $validated, Team $team, array $brandItems, array $brands, $catalog, string $unlisted): Quotation
    {
        $client = Client::firstOrCreate(
            ['team_id' => $team->id, 'company_name' => $validated['company']],
            ['contact_person' => $validated['contactName'], 'email' => $validated['email'], 'phone' => $validated['phone']]
        );

        $projectType = $validated['projectType'] ?? 'General';
        $timeline = str_replace('–', '-', $validated['timeline'] ?? 'Flexible');

        $notes = "Project Type: {$projectType}\nExpected Timeline: {$timeline}\n";
        if ($brands !== []) {
            $notes .= "\n--- Brands Requested ---\n".implode(', ', $brands)."\n";
            $withDetails = array_filter($brandItems, fn ($i) => $i['details'] !== '');
            if ($withDetails !== []) {
                $notes .= "\n--- Specific Requirements & Quantities ---\n";
                foreach ($withDetails as $item) {
                    $notes .= '• '.$catalog[mb_strtolower($item['brand'])].": {$item['details']}\n";
                }
            }
        }
        if ($unlisted !== '') {
            $notes .= "\n--- Brands Not Listed ---\n".$unlisted."\n";
        }

        // Number: Q<today>-PROD, -01, -02, … reserved so no other request can get it.
        $quotation = Quotation::createWithNumber([
            'team_id' => $team->id,
            'client_id' => $client->id,
            'client_name' => $validated['company'],
            'contact_person' => $validated['contactName'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'subtotal' => 0.00,
            'discount' => 0.00,
            'tax_rate' => 12.00,
            'tax_amount' => 0.00,
            'grand_total' => 0.00,
            'status' => QuotationStatus::Pending,
            'notes' => $notes,
            // The same request in a structured form, for the staff review screen.
            'request_details' => [
                'source' => 'public_form',
                'project_type' => $projectType,
                'timeline' => $timeline,
                'brands' => array_values(array_map(fn ($i) => [
                    'brand' => $catalog[mb_strtolower($i['brand'])],
                    'details' => $i['details'],
                ], $brandItems)),
                'unlisted_brands' => $unlisted !== '' ? $unlisted : null,
                'submitted_at' => now()->toIso8601String(),
            ],
        ]);

        // One line per selected brand item (details appended), for the existing item views.
        $seen = [];
        foreach ($brandItems as $item) {
            $canonical = $catalog[mb_strtolower($item['brand'])];
            $key = $canonical.'|'.$item['details'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $quotation->items()->create([
                'product_id' => null,
                'sku' => 'BRAND',
                'product_name' => 'Brand: '.$canonical.($item['details'] !== '' ? ' - '.Str::limit($item['details'], 100) : ''),
                'quantity' => 1,
                'unit_price' => 0.00,
                'discount' => 0.00,
                'subtotal' => 0.00,
            ]);
        }

        if ($unlisted !== '') {
            $quotation->items()->create([
                'product_id' => null,
                'sku' => 'BRAND-UNLISTED',
                'product_name' => 'Brands not listed: '.Str::limit($unlisted, 75),
                'quantity' => 1,
                'unit_price' => 0.00,
                'discount' => 0.00,
                'subtotal' => 0.00,
            ]);
        }

        return $quotation;
    }

    /**
     * Store the product-list files. A file identical to another product file is not stored twice.
     *
     * @param  array<int, UploadedFile>  $files
     * @param  array<int, string>  $stored  receives the storage path of every file copied, for clean-up if saving fails
     */
    private function keepProductFiles(Quotation $quotation, array $files, array &$stored): void
    {
        $seen = [];
        foreach ($files as $file) {
            $path = $file->getRealPath() ?: $file->getPathname();
            $hash = hash_file('sha256', $path);
            if (in_array($hash, $seen, true)) {
                continue;
            }
            $seen[] = $hash;

            $stored[] = $file->storeAs(self::PRODUCT_FILES_DIR, Str::uuid().'.'.strtolower($file->getClientOriginalExtension()), 'local');
            $quotation->files()->create([
                'kind' => QuotationFile::KIND_PRODUCT_LIST,
                'path' => end($stored),
                'original_name' => Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())), 200, ''),
                'mime' => $file->getMimeType(),
                'size' => (int) $file->getSize(),
                'sha256' => $hash,
            ]);
        }

        $count = $quotation->files()->count();
        if ($count > 0) {
            $unchecked = $this->uncheckedFiles === []
                ? ''
                : 'Not checked for text (OCR unavailable): '.implode(', ', $this->uncheckedFiles).".\n";
            $quotation->update(['notes' => rtrim((string) $quotation->notes)."\n\n--- Product list files ---\n{$count} file(s) attached for staff review.\n{$unchecked}"]);
        }
    }
}
