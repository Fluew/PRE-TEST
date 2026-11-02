<script setup lang="ts">
/**
 * Universal document import: upload (CSV, Excel, PDF, PNG, JPG up to 25 MB) → extraction on
 * the server (queued; OCR for images and scanned PDFs) → editable preview → confirm.
 * Nothing is saved until staff confirm, and every rule is checked again by the server.
 */
import { usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

type Module = 'inventory' | 'supplier_quotation';

interface Issue { level: 'error' | 'warning' | 'info'; field: string | null; message: string }
interface Suggestion { product_id: number; sku: string; name: string; unit?: string | null; confidence: number; level: string; reason: string }
interface Row {
    id: number;
    row_number: number;
    raw: string | null;
    extracted: Record<string, any>;
    values: Record<string, any>;
    sources: Record<string, string | null> | null;
    issues: Issue[] | null;
    status: string;
    skipped: boolean;
    matched_product_id: number | null;
    matched_product?: { id: number; name: string; sku: string; unit: string | null } | null;
    suggestions: Suggestion[] | null;
}
interface Batch {
    id: number;
    module: Module;
    status: string;
    original_filename: string;
    file_type: string | null;
    file_size: number;
    extraction_method: string | null;
    page_count: number | null;
    meta: Record<string, any> | null;
    summary: Record<string, any> | null;
    error: string | null;
    uses_ocr: boolean;
    uploaded_by: string | null;
    imported_by: string | null;
    created_at: string;
}

const props = defineProps<{ open: boolean; module: Module; suppliers?: { id: number; name: string }[] }>();
const emit = defineEmits<{ close: []; completed: [batch: Batch] }>();

const page = usePage();
const teamSlug = computed(() => (page.props as any).currentTeam?.slug || (page.props as any).team?.slug || '');
const base = computed(() => `/${teamSlug.value}/imports`);
const csrf = () => (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '';

const FIELDS: Record<Module, { key: string; label: string; numeric?: boolean; width?: string }[]> = {
    inventory: [
        { key: 'sku', label: 'SKU', width: 'w-28' },
        { key: 'name', label: 'Product name', width: 'min-w-56' },
        { key: 'category', label: 'Category', width: 'w-28' },
        { key: 'brand', label: 'Brand', width: 'w-28' },
        { key: 'unit', label: 'Unit', width: 'w-20' },
        { key: 'cost_price', label: 'Cost price', numeric: true, width: 'w-24' },
        { key: 'price', label: 'Selling price', numeric: true, width: 'w-24' },
    ],
    supplier_quotation: [
        { key: 'item_code', label: 'Code', width: 'w-24' },
        { key: 'description', label: 'Description', width: 'min-w-56' },
        { key: 'unit', label: 'Unit', width: 'w-20' },
        { key: 'quantity', label: 'Qty', numeric: true, width: 'w-20' },
        { key: 'unit_price', label: 'Unit price', numeric: true, width: 'w-24' },
        { key: 'amount', label: 'Amount', numeric: true, width: 'w-28' },
    ],
};
const fields = computed(() => FIELDS[props.module]);
const title = computed(() => (props.module === 'inventory' ? 'Import products' : 'Import supplier quotation'));

const step = ref<'upload' | 'processing' | 'review' | 'done' | 'failed'>('upload');
const error = ref('');
const busy = ref(false);
const batch = ref<Batch | null>(null);
const rows = ref<Row[]>([]);
const pager = ref({ current_page: 1, last_page: 1, total: 0 });
const filter = ref('all');
const ocrChecked = ref(false);
const history = ref<Batch[]>([]);
let timer: ReturnType<typeof setTimeout> | null = null;

// Upload form
const file = ref<File | null>(null);
const meta = ref({ supplier_id: null as number | null, quoted_on: new Date().toISOString().slice(0, 10), valid_until: '', currency: 'PHP', tax_inclusive: false, reference: '' });

const reset = () => {
    step.value = 'upload';
    error.value = '';
    batch.value = null;
    rows.value = [];
    file.value = null;
    ocrChecked.value = false;
    filter.value = 'all';
    loadHistory();
};
watch(() => props.open, (o) => (o ? reset() : stopPolling()));
onBeforeUnmount(() => stopPolling());

const request = async (url: string, init: RequestInit = {}) => {
    const res = await fetch(url, { ...init, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), ...(init.headers ?? {}) } });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) {
        const first = body?.errors ? (Object.values(body.errors)[0] as string[])?.[0] : null;
        throw new Error(first || body?.message || `Request failed (${res.status}).`);
    }

    return body;
};

const loadHistory = async () => {
    try {
        history.value = (await request(`${base.value}/${props.module}`)).data ?? [];
    } catch {
        history.value = [];
    }
};

const upload = async () => {
    if (!file.value) return;
    if (file.value.size > 25 * 1024 * 1024) {
        error.value = 'The file is larger than 25 MB.';
        return;
    }
    busy.value = true;
    error.value = '';
    const form = new FormData();
    form.append('file', file.value);
    if (props.module === 'supplier_quotation') {
        Object.entries(meta.value).forEach(([k, v]) => v !== null && v !== '' && form.append(k, typeof v === 'boolean' ? (v ? '1' : '0') : String(v)));
    }
    try {
        batch.value = await request(`${base.value}/${props.module}`, { method: 'POST', body: form });
        afterStatus();
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
};

const stopPolling = () => {
    if (timer) clearTimeout(timer);
    timer = null;
};
const afterStatus = () => {
    const s = batch.value?.status;
    if (s === 'queued' || s === 'processing') {
        step.value = 'processing';
        stopPolling();
        timer = setTimeout(() => load(1), 2000);
    } else if (s === 'failed') {
        step.value = 'failed';
    } else if (s === 'completed') {
        step.value = 'done';
    } else if (s === 'ready_for_review') {
        step.value = 'review';
        if (!rows.value.length) load(1);
    } else {
        step.value = 'failed';
    }
};

const load = async (p = pager.value.current_page) => {
    if (!batch.value) return;
    try {
        const data = await request(`${base.value}/batches/${batch.value.id}?status=${filter.value}&page=${p}`);
        batch.value = data.batch;
        rows.value = data.rows.data;
        pager.value = { current_page: data.rows.current_page, last_page: data.rows.last_page, total: data.rows.total };
        afterStatus();
    } catch (e: any) {
        error.value = e.message;
    }
};
watch(filter, () => load(1));

const openBatch = (b: Batch) => {
    batch.value = b;
    rows.value = [];
    load(1);
};

const patchRow = async (row: Row, payload: Record<string, any>) => {
    if (!batch.value) return;
    error.value = '';
    try {
        await request(`${base.value}/batches/${batch.value.id}/rows/${row.id}`, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        await load();
    } catch (e: any) {
        error.value = e.message;
    }
};
const editField = (row: Row, key: string, value: string) => {
    if (String(row.values?.[key] ?? '') === value) return;
    patchRow(row, { values: { [key]: value } });
};

// Product picker for supplier quotation lines
const searchFor = ref<number | null>(null);
const searchQ = ref('');
const searchResults = ref<{ id: number; name: string; sku: string; unit: string | null }[]>([]);
const searchProducts = async () => {
    if (!batch.value) return;
    searchResults.value = await request(`${base.value}/batches/${batch.value.id}/products?q=${encodeURIComponent(searchQ.value)}`).catch(() => []);
};

const bulk = async (action: 'skip_problems' | 'restore') => {
    if (!batch.value) return;
    busy.value = true;
    try {
        await request(`${base.value}/batches/${batch.value.id}/bulk`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action }) });
        await load(1);
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
};

const result = ref<{ created: number; skipped: number } | null>(null);
const confirmImport = async () => {
    if (!batch.value) return;
    busy.value = true;
    error.value = '';
    try {
        const data = await request(`${base.value}/batches/${batch.value.id}/confirm`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ocr_checked: ocrChecked.value }) });
        result.value = { created: data.created, skipped: data.skipped };
        batch.value = data.batch;
        step.value = 'done';
        emit('completed', data.batch);
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
};

const cancelImport = async () => {
    if (!batch.value) return;
    try {
        await request(`${base.value}/batches/${batch.value.id}/cancel`, { method: 'POST' });
    } catch {
        // Already finished; just close.
    }
    emit('close');
};

const summary = computed(() => batch.value?.summary ?? {});
const canConfirm = computed(() => step.value === 'review' && (summary.value.needs_review ?? 0) === 0 && (summary.value.ready ?? 0) > 0 && (!batch.value?.uses_ocr || ocrChecked.value));
const methodLabel = (m: string | null) => ({ csv: 'CSV parser', spreadsheet: 'Excel reader', 'pdf-text': 'PDF text (Poppler)', 'pdf-ocr': 'Scanned PDF: Poppler + Tesseract OCR', ocr: 'Image: Tesseract OCR' } as Record<string, string>)[m ?? ''] ?? m ?? '—';
const statusLabel: Record<string, string> = { ready: 'Ready', needs_review: 'Needs review', duplicate: 'Duplicate', existing: 'Already in inventory', skipped: 'Skipped', imported: 'Imported' };
const statusClass = (s: string) => ({
    ready: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    needs_review: 'border-rose-200 bg-rose-50 text-rose-700',
    duplicate: 'border-amber-200 bg-amber-50 text-amber-800',
    existing: 'border-gray-200 bg-gray-50 text-gray-600',
    skipped: 'border-gray-200 bg-gray-50 text-gray-500',
    imported: 'border-emerald-200 bg-emerald-50 text-emerald-700',
}[s] ?? 'border-gray-200 bg-gray-50 text-gray-700');
const issueClass = (l: string) => (l === 'error' ? 'text-rose-700' : l === 'warning' ? 'text-amber-700' : 'text-gray-500');
const sourceBadge = (row: Row, key: string) => ({ calculated: 'calc', edited: 'edited', generated: 'gen' } as Record<string, string>)[row.sources?.[key] ?? ''] ?? '';
const display = (v: any) => (v === null || v === undefined ? '' : String(v));
const fieldIssue = (row: Row, key: string) => (row.issues ?? []).find((i) => i.field === key && i.level === 'error');
const locked = (row: Row) => row.status === 'imported' || step.value !== 'review';
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-2 sm:p-4" @click.self="step === 'review' ? null : emit('close')">
        <div class="flex max-h-[96vh] w-full max-w-7xl flex-col overflow-hidden rounded-xl border border-gray-200 bg-white" role="dialog" aria-modal="true" aria-labelledby="import-title">
            <div class="flex items-start justify-between gap-3 border-b border-gray-100 px-5 py-4">
                <div>
                    <h3 id="import-title" class="text-base font-semibold text-gray-900">{{ title }}</h3>
                    <p v-if="batch" class="text-xs text-gray-500">
                        {{ batch.original_filename }} · {{ methodLabel(batch.extraction_method) }}<template v-if="batch.page_count"> · {{ batch.page_count }} page(s)</template>
                        · <a :href="`${base}/batches/${batch.id}/file`" class="text-[#4b5320] underline">original file</a>
                    </p>
                    <p v-else class="text-xs text-gray-500">CSV, Excel (.xls/.xlsx), PDF, PNG or JPG, up to 25 MB. Nothing is saved until you confirm the preview.</p>
                </div>
                <button type="button" class="text-xl text-gray-400 hover:text-gray-600" aria-label="Close" @click="emit('close')">&times;</button>
            </div>

            <div class="flex-1 space-y-4 overflow-y-auto px-5 py-4 text-sm">
                <p v-if="error" class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-rose-700" role="alert">{{ error }}</p>

                <!-- Upload -->
                <form v-if="step === 'upload'" class="space-y-4" @submit.prevent="upload">
                    <div>
                        <label class="field-label" for="imp-file">Document</label>
                        <input id="imp-file" type="file" accept=".csv,.txt,.xls,.xlsx,.pdf,.png,.jpg,.jpeg" class="field-control w-full" required @change="file = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                        <p class="mt-1 text-xs text-gray-500">
                            Spreadsheets and CSV are read directly; digital PDFs through their text; photos and scanned PDFs through OCR (every page).
                            <template v-if="module === 'inventory'"> Creates new products only: stock is received separately and existing products are not changed.</template>
                            <template v-else> Saves supplier quotes for review and admin verification. Inventory prices are never changed.</template>
                        </p>
                    </div>
                    <div v-if="module === 'supplier_quotation'" class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <label class="field-label" for="imp-sup">Supplier</label>
                            <select id="imp-sup" v-model="meta.supplier_id" class="field-control w-full" required>
                                <option :value="null" disabled>Choose…</option>
                                <option v-for="s in suppliers ?? []" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </select>
                        </div>
                        <div><label class="field-label" for="imp-ref">Quotation no.</label><input id="imp-ref" v-model="meta.reference" class="field-control w-full" /></div>
                        <div>
                            <label class="field-label" for="imp-cur">Currency</label>
                            <select id="imp-cur" v-model="meta.currency" class="field-control w-full"><option value="PHP">PHP</option><option value="USD">USD</option></select>
                        </div>
                        <div><label class="field-label" for="imp-date">Quotation date</label><input id="imp-date" v-model="meta.quoted_on" type="date" class="field-control w-full" required /></div>
                        <div><label class="field-label" for="imp-valid">Valid until</label><input id="imp-valid" v-model="meta.valid_until" type="date" class="field-control w-full" /></div>
                        <label class="mt-6 flex items-center gap-2 text-sm"><input v-model="meta.tax_inclusive" type="checkbox" class="rounded border-gray-300" /> Prices include tax</label>
                    </div>
                    <div class="flex justify-end">
                        <button class="brand-button" :disabled="busy || !file">{{ busy ? 'Uploading…' : 'Upload and read' }}</button>
                    </div>

                    <div v-if="history.length" class="border-t border-gray-100 pt-3">
                        <h4 class="mb-2 text-xs font-medium text-gray-500">Recent imports</h4>
                        <ul class="divide-y divide-gray-100 text-xs">
                            <li v-for="h in history" :key="h.id" class="flex items-center justify-between gap-2 py-1.5">
                                <span class="truncate">{{ h.original_filename }} · {{ new Date(h.created_at).toLocaleString() }} · {{ h.uploaded_by ?? '—' }}</span>
                                <span class="flex items-center gap-2">
                                    <span :class="statusClass(h.status === 'completed' ? 'imported' : h.status === 'failed' ? 'needs_review' : 'existing')" class="rounded-full border px-2 py-0.5">{{ h.status.replace(/_/g, ' ') }}</span>
                                    <button v-if="['ready_for_review', 'queued', 'processing', 'completed'].includes(h.status)" type="button" class="text-[#4b5320] underline" @click="openBatch(h)">Open</button>
                                </span>
                            </li>
                        </ul>
                    </div>
                </form>

                <div v-else-if="step === 'processing'" class="py-10 text-center text-gray-600" aria-live="polite">
                    <p class="font-medium">Reading the document…</p>
                    <p class="text-xs text-gray-500">Scanned PDFs and photos go through OCR page by page; long documents can take a few minutes. You can close this window and open the import again later.</p>
                </div>

                <div v-else-if="step === 'failed'" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-rose-800">
                    <p class="font-medium">The import failed. Nothing was saved.</p>
                    <p class="text-sm">{{ batch?.error }}</p>
                    <button type="button" class="secondary-button mt-3" @click="reset">Upload another file</button>
                </div>

                <!-- Review -->
                <template v-else-if="step === 'review' || step === 'done'">
                    <div v-if="step === 'done'" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">
                        Import completed<template v-if="batch?.imported_by"> by {{ batch.imported_by }}</template>:
                        {{ result?.created ?? batch?.summary?.created ?? batch?.summary?.imported }} {{ module === 'inventory' ? 'product(s) created' : 'supplier quote(s) saved for verification' }}.
                        <template v-if="module === 'inventory'"> Products with missing values are inactive and listed under the "Incomplete" filter.</template>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <button v-for="[k, l] in [['all', 'All'], ['attention', 'Needs attention'], ['ready', 'Ready'], ['needs_review', 'Needs review'], ['duplicate', 'Duplicates'], ['existing', 'Already in inventory'], ['skipped', 'Skipped'], ['imported', 'Imported']]" :key="k"
                                type="button" class="rounded-full border px-2.5 py-1" :class="filter === k ? 'border-[#4b5320] bg-[#4b5320]/10 text-[#4b5320]' : 'border-gray-200 text-gray-600'" @click="filter = k">
                            {{ l }}<template v-if="k === 'all'"> ({{ summary.total ?? 0 }})</template><template v-else-if="k === 'attention'"> ({{ (summary.needs_review ?? 0) + (summary.duplicate ?? 0) }})</template><template v-else-if="summary[k] !== undefined"> ({{ summary[k] }})</template>
                        </button>
                        <span class="flex-1"></span>
                        <template v-if="step === 'review'">
                            <button type="button" class="secondary-button !py-1 text-xs" :disabled="busy || !summary.needs_review" @click="bulk('skip_problems')">Skip all rows needing review</button>
                            <button type="button" class="secondary-button !py-1 text-xs" :disabled="busy || !summary.skipped" @click="bulk('restore')">Restore skipped</button>
                        </template>
                    </div>

                    <p v-if="batch?.uses_ocr && step === 'review'" class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800">
                        This document was read by OCR. OCR helps with typing but is not a source of truth: compare every value with the original file before importing.
                    </p>
                    <details v-if="summary.unparsed_lines?.length" class="text-xs text-gray-600">
                        <summary class="cursor-pointer">Text above the table, not imported ({{ summary.unparsed_lines.length }} lines)</summary>
                        <ul class="mt-1 list-disc pl-5"><li v-for="(l, i) in summary.unparsed_lines" :key="i">{{ l }}</li></ul>
                    </details>

                    <div class="overflow-x-auto rounded-xl border border-gray-200">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-gray-50 text-gray-500">
                                <tr>
                                    <th class="px-2 py-2">Row</th>
                                    <th v-for="f in fields" :key="f.key" class="px-2 py-2" :class="f.numeric ? 'text-right' : ''">{{ f.label }}</th>
                                    <th v-if="module === 'supplier_quotation'" class="px-2 py-2">Inventory product</th>
                                    <th class="px-2 py-2">Status and checks</th>
                                    <th class="px-2 py-2"><span class="sr-only">Skip</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!rows.length"><td :colspan="fields.length + 4" class="px-3 py-6 text-center text-gray-500">No rows in this view.</td></tr>
                                <tr v-for="row in rows" :key="row.id" class="border-t border-gray-100 align-top" :class="row.skipped ? 'opacity-60' : ''">
                                    <td class="px-2 py-1.5 text-gray-400">{{ row.row_number }}</td>
                                    <td v-for="f in fields" :key="f.key" class="px-1 py-1" :class="f.width">
                                        <input :value="display(row.values?.[f.key])" :disabled="locked(row)" :inputmode="f.numeric ? 'decimal' : 'text'" :aria-label="`${f.label}, row ${row.row_number}`"
                                               class="w-full rounded-lg border px-2 py-1" :class="[f.numeric ? 'text-right font-mono' : '', fieldIssue(row, f.key) ? 'border-rose-300 bg-rose-50' : 'border-gray-200']"
                                               @change="editField(row, f.key, ($event.target as HTMLInputElement).value)" />
                                        <div v-if="sourceBadge(row, f.key)" class="mt-0.5 text-[10px] text-gray-500">{{ sourceBadge(row, f.key) === 'calc' ? 'calculated' : sourceBadge(row, f.key) === 'gen' ? 'generated' : 'edited' }}<template v-if="row.extracted?.[f.key] && sourceBadge(row, f.key) === 'edited'"> · doc: {{ row.extracted[f.key] }}</template></div>
                                    </td>
                                    <td v-if="module === 'supplier_quotation'" class="min-w-56 px-1 py-1">
                                        <div v-if="row.matched_product" class="flex items-center justify-between gap-1">
                                            <span><span class="font-medium">{{ row.matched_product.name }}</span> <span class="font-mono text-gray-500">{{ row.matched_product.sku }}</span></span>
                                            <button v-if="!locked(row)" type="button" class="text-gray-400 hover:text-gray-700" aria-label="Clear product" @click="patchRow(row, { product_id: null })">&times;</button>
                                        </div>
                                        <template v-else-if="!locked(row)">
                                            <div v-for="s in row.suggestions ?? []" :key="s.product_id">
                                                <button type="button" class="text-left text-[#4b5320] underline" @click="patchRow(row, { product_id: s.product_id })">{{ s.name }} ({{ s.sku }}, {{ s.confidence }}%)</button>
                                            </div>
                                            <div v-if="searchFor === row.id" class="mt-1">
                                                <input v-model="searchQ" class="w-full rounded-lg border border-gray-200 px-2 py-1" placeholder="Search name or SKU" aria-label="Search products" @input="searchProducts" />
                                                <button v-for="p in searchResults" :key="p.id" type="button" class="block text-left hover:underline" @click="patchRow(row, { product_id: p.id }); searchFor = null">{{ p.name }} <span class="font-mono text-gray-500">{{ p.sku }}</span></button>
                                            </div>
                                            <button v-else type="button" class="mt-0.5 text-gray-500 underline" @click="searchFor = row.id; searchQ = row.values?.description ?? ''; searchProducts()">Find product…</button>
                                        </template>
                                    </td>
                                    <td class="min-w-64 px-2 py-1.5">
                                        <span :class="statusClass(row.status)" class="rounded-full border px-2 py-0.5">{{ statusLabel[row.status] ?? row.status }}</span>
                                        <ul class="mt-1 space-y-0.5">
                                            <li v-for="(i, n) in row.issues ?? []" :key="n" :class="issueClass(i.level)">{{ i.message }}</li>
                                        </ul>
                                        <p v-if="row.raw && module === 'supplier_quotation'" class="mt-0.5 truncate text-[10px] text-gray-400" :title="row.raw">Read: {{ row.raw }}</p>
                                    </td>
                                    <td class="px-2 py-1.5">
                                        <label v-if="!locked(row) && row.status !== 'existing'" class="flex items-center gap-1 whitespace-nowrap">
                                            <input type="checkbox" :checked="row.skipped" class="rounded border-gray-300" @change="patchRow(row, { skipped: ($event.target as HTMLInputElement).checked })" /> Skip
                                        </label>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="pager.last_page > 1" class="flex items-center justify-end gap-2 text-xs">
                        <button type="button" class="secondary-button !py-1" :disabled="pager.current_page <= 1" @click="load(pager.current_page - 1)">Previous</button>
                        <span>Page {{ pager.current_page }} of {{ pager.last_page }} ({{ pager.total }} rows)</span>
                        <button type="button" class="secondary-button !py-1" :disabled="pager.current_page >= pager.last_page" @click="load(pager.current_page + 1)">Next</button>
                    </div>
                </template>
            </div>

            <div v-if="step === 'review'" class="flex flex-wrap items-center justify-end gap-3 border-t border-gray-100 bg-gray-50/50 px-5 py-3">
                <label v-if="batch?.uses_ocr" class="mr-auto flex items-center gap-2 text-sm">
                    <input v-model="ocrChecked" type="checkbox" class="rounded border-gray-300" /> I checked the values against the original document
                </label>
                <span class="text-xs text-gray-500">{{ summary.ready ?? 0 }} ready · {{ summary.needs_review ?? 0 }} need review</span>
                <button type="button" class="secondary-button" @click="cancelImport">Cancel import</button>
                <button type="button" class="brand-button" :disabled="busy || !canConfirm" @click="confirmImport">{{ busy ? 'Importing…' : `Import ${summary.ready ?? 0} row(s)` }}</button>
            </div>
            <div v-else-if="step === 'done'" class="flex justify-end gap-2 border-t border-gray-100 px-5 py-3">
                <button type="button" class="secondary-button" @click="reset">Import another file</button>
                <button type="button" class="brand-button" @click="emit('close')">Close</button>
            </div>
        </div>
    </div>
</template>
