<script setup lang="ts">
/**
 * One purchase order end to end: its approved request, quantities (ordered / delivered /
 * accepted / rejected / posted), every inbound delivery with its inspection, the supplier
 * invoices with their three-way-match checks, and the next allowed actions.
 * All rules are enforced by the backend (ProcurementWorkflow); this panel only shows them.
 */
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps<{ poId: number | null; isAdmin: boolean }>();
const emit = defineEmits<{ close: [] }>();

const page = usePage();
const teamSlug = computed(() => (page.props as any).currentTeam?.slug || (page.props as any).team?.slug || '');
const { formatMoney } = useCurrency();

const data = ref<any>(null);
const loading = ref(false);
const loadError = ref('');
const busy = ref(false);
const actionError = ref('');
const today = new Date().toISOString().slice(0, 10);

const load = async () => {
    if (!props.poId) return;
    loading.value = true;
    loadError.value = '';
    try {
        const res = await fetch(`/${teamSlug.value}/procurement/purchase-orders/${props.poId}/workspace`, { headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error(`Could not load the purchase order (${res.status}).`);
        data.value = await res.json();
    } catch (e: any) {
        loadError.value = e.message;
    } finally {
        loading.value = false;
    }
};
watch(() => props.poId, (id) => { data.value = null; actionError.value = ''; if (id) load(); }, { immediate: true });

const po = computed(() => data.value?.purchase_order);
const s = computed(() => data.value?.summary);

/** Run a workflow action, then reload this panel. Business-rule messages come back as errors. */
const act = (method: 'post' | 'patch', path: string, payload: Record<string, any> = {}, done?: () => void) => {
    busy.value = true;
    actionError.value = '';
    router[method](`/${teamSlug.value}/${path}`, payload, {
        preserveScroll: true,
        preserveState: true,
        onError: (errors) => { actionError.value = Object.values(errors).join(' '); },
        onSuccess: () => {
            const errs = (page.props as any).errors ?? {};
            if (Object.keys(errs).length) {
                actionError.value = Object.values(errs).join(' ');
                return;
            }
            done?.();
            load();
        },
        onFinish: () => { busy.value = false; },
    });
};

// Forms
const receive = reactive({ open: false, delivery_date: today, quantity: 0, supplier_document_number: '', over_receipt_reason: '', notes: '' });
const openReceive = () => Object.assign(receive, { open: true, delivery_date: today, quantity: s.value?.remaining ?? 0, supplier_document_number: '', over_receipt_reason: '', notes: '' });

const inspection = reactive({ id: 0, accepted_quantity: 0, rejected_quantity: 0, damaged_quantity: 0, defective_quantity: 0, inspected_on: today, rejection_category: '', rejection_reason: '', inspection_remarks: '' });
const openInspection = (d: any) => Object.assign(inspection, {
    id: d.id, accepted_quantity: d.accepted_quantity ?? d.quantity, rejected_quantity: d.rejected_quantity ?? 0,
    damaged_quantity: d.damaged_quantity ?? 0, defective_quantity: d.defective_quantity ?? 0,
    inspected_on: d.inspected_on?.slice(0, 10) ?? today, rejection_category: d.rejection_category ?? '', rejection_reason: d.rejection_reason ?? '', inspection_remarks: d.inspection_remarks ?? '',
});
// Category is needed only for rejected units not explained as damaged or defective.
const otherRejected = computed(() => Math.max(0, inspection.rejected_quantity - inspection.damaged_quantity - inspection.defective_quantity));
const inspectionPayload = () => ({
    ...inspection,
    damaged_quantity: inspection.rejected_quantity ? inspection.damaged_quantity : null,
    defective_quantity: inspection.rejected_quantity ? inspection.defective_quantity : null,
    rejection_category: inspection.rejected_quantity && inspection.rejection_category ? inspection.rejection_category : null,
    rejection_reason: inspection.rejected_quantity ? inspection.rejection_reason : null,
});

// Draft order created automatically on approval: staff confirm supplier, quantity, price, terms.
const draft = reactive({ supplier_id: null as number | null, quantity: 0, unit_cost: 0, po_date: today, promised_delivery_date: '', payment_terms: '', quantity_adjustment_reason: '', notes: '' });
const draftOpen = ref(false);
const openDraft = () => {
    const p = po.value;
    Object.assign(draft, {
        supplier_id: p.supplier_id, quantity: p.quantity, unit_cost: Number(p.unit_cost) || 0, po_date: p.po_date?.slice(0, 10) ?? today,
        promised_delivery_date: p.promised_delivery_date?.slice(0, 10) ?? '', payment_terms: p.payment_terms ?? '', quantity_adjustment_reason: p.quantity_adjustment_reason ?? '', notes: p.notes ?? '',
    });
    draftOpen.value = true;
};
const approvedQty = computed(() => po.value?.purchase_request?.quantity ?? po.value?.quantity);
const readyToSend = computed(() => po.value?.supplier_id && Number(po.value?.unit_cost) > 0);
const resolution = reactive({ id: 0, resolution_status: 'returned', resolution_remarks: '' });

const invoice = reactive({ open: false, delivery_id: 0, invoice_number: '', invoice_date: today, quantity: 0, unit_cost: 0, tax_amount: 0, payment_terms: '', attachment: null as File | null });
const openInvoice = (d: any) => Object.assign(invoice, {
    open: true, delivery_id: d.id, invoice_number: '', invoice_date: today, quantity: d.accepted_quantity ?? d.quantity,
    unit_cost: Number(po.value?.unit_cost ?? 0), tax_amount: 0, payment_terms: po.value?.payment_terms ?? '', attachment: null,
});
const invoicePayload = () => {
    const { open: _open, attachment, ...rest } = invoice;
    return { ...rest, purchase_order_id: po.value.id, ...(attachment ? { attachment } : {}) };
};
const correction = reactive({ id: 0, invoice_number: '', quantity: 0, unit_cost: 0, tax_amount: 0, remarks: '' });
const openCorrection = (ci: any) => Object.assign(correction, { id: ci.id, invoice_number: ci.invoice_number, quantity: ci.quantity, unit_cost: Number(ci.unit_cost), tax_amount: Number(ci.tax_amount ?? 0), remarks: '' });
const decision = reactive({ id: 0, kind: '' as '' | 'verify' | 'flag', remarks: '' });
const poReason = reactive({ kind: '' as '' | 'cancel' | 'close', reason: '' });

const invoiceFor = (deliveryId: number) => (data.value?.invoices ?? []).filter((i: any) => i.delivery_id === deliveryId && i.status !== 'Cancelled');
const hasVerifiedInvoice = (deliveryId: number) => invoiceFor(deliveryId).some((i: any) => i.status === 'Verified');
const label = (v: string) => v.replace(/_/g, ' ');
const statusClass = (st: string) => ({
    'Draft': 'bg-gray-50 text-gray-700 border-gray-200',
    'Sent to Supplier': 'bg-blue-50 text-blue-700 border-blue-200',
    'Partially Delivered': 'bg-amber-50 text-amber-800 border-amber-200',
    'Fully Delivered': 'bg-emerald-50 text-emerald-700 border-emerald-200',
    'Closed': 'bg-gray-100 text-gray-700 border-gray-200',
    'Cancelled': 'bg-rose-50 text-rose-700 border-rose-200',
    'Pending Verification': 'bg-blue-50 text-blue-700 border-blue-200',
    'Flagged': 'bg-rose-50 text-rose-700 border-rose-200',
    'Verified': 'bg-emerald-50 text-emerald-700 border-emerald-200',
    'Pending Inspection': 'bg-blue-50 text-blue-700 border-blue-200',
    'Passed': 'bg-emerald-50 text-emerald-700 border-emerald-200',
    'Partially Accepted': 'bg-amber-50 text-amber-800 border-amber-200',
    'Failed': 'bg-rose-50 text-rose-700 border-rose-200',
    'Pending Resolution': 'bg-amber-50 text-amber-800 border-amber-200',
}[st] ?? 'bg-gray-50 text-gray-700 border-gray-200');
</script>

<template>
    <div v-if="poId" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="emit('close')">
        <div class="flex max-h-[94vh] w-full max-w-5xl flex-col overflow-hidden rounded-xl border border-gray-200 bg-white" role="dialog" aria-modal="true" aria-labelledby="po-ws-title">
            <div class="flex items-start justify-between gap-3 border-b border-gray-100 px-6 py-4">
                <div>
                    <h3 id="po-ws-title" class="text-base font-semibold text-gray-900">Purchase order {{ po?.po_number ?? '…' }}</h3>
                    <p v-if="po" class="text-xs text-gray-500">
                        {{ po.description }} · {{ po.supplier?.name ?? 'No supplier' }} · ordered {{ po.po_date?.slice(0, 10) }}
                        <template v-if="po.promised_delivery_date"> · promised {{ po.promised_delivery_date.slice(0, 10) }}</template>
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <span v-if="po" :class="statusClass(po.status)" class="rounded-full border px-2.5 py-0.5 text-xs font-medium">{{ po.status }}</span>
                    <button type="button" class="text-xl text-gray-400 hover:text-gray-600" aria-label="Close" @click="emit('close')">&times;</button>
                </div>
            </div>

            <div class="space-y-5 overflow-y-auto px-6 py-4 text-sm">
                <p v-if="loading && !data" class="text-gray-500">Loading…</p>
                <p v-if="loadError" class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-rose-700">{{ loadError }}</p>
                <p v-if="actionError" class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-rose-700">{{ actionError }}</p>

                <template v-if="data">
                    <!-- Source request and order terms -->
                    <section class="grid grid-cols-1 gap-3 md:grid-cols-2">
                        <div class="rounded-xl border border-gray-200 p-3">
                            <h4 class="mb-1 text-xs font-medium text-gray-500">Approved purchase request</h4>
                            <template v-if="po.purchase_request">
                                <p class="font-mono font-medium text-gray-900">{{ po.purchase_request.request_number }}</p>
                                <p class="text-xs text-gray-600">
                                    Requested {{ po.purchase_request.quantity }} by {{ po.purchase_request.requested_by?.name ?? '—' }} ·
                                    approved by {{ po.purchase_request.reviewed_by?.name ?? '—' }} {{ po.purchase_request.reviewed_at?.slice(0, 10) }}
                                </p>
                                <p class="text-xs text-gray-600">AHP {{ po.purchase_request.ahp_score ?? 'incomplete' }} · forecast {{ po.purchase_request.forecast_quantity ?? 'n/a' }} · stock then {{ po.purchase_request.current_stock ?? '—' }}</p>
                                <p class="mt-1 text-xs text-gray-500">{{ po.purchase_request.justification }}</p>
                            </template>
                            <p v-else class="text-xs text-amber-700">No purchase request (imported historical record).</p>
                        </div>
                        <div class="rounded-xl border border-gray-200 p-3">
                            <h4 class="mb-1 text-xs font-medium text-gray-500">Order terms</h4>
                            <p>{{ po.quantity }} {{ po.unit_of_measure }} × {{ formatMoney(Number(po.unit_cost)) }} = <span class="font-medium">{{ formatMoney(Number(po.total_cost)) }}</span></p>
                            <p class="text-xs text-gray-600">Payment terms: {{ po.payment_terms || '—' }} · prepared by {{ po.prepared_by?.name ?? '—' }}</p>
                            <p v-if="po.quantity_adjustment_reason" class="text-xs text-amber-700">Quantity adjusted from the request: {{ po.quantity_adjustment_reason }}</p>
                            <p v-if="po.cancellation_reason" class="text-xs text-rose-700">Cancelled: {{ po.cancellation_reason }}</p>
                        </div>
                    </section>

                    <!-- Quantities -->
                    <section>
                        <dl class="grid grid-cols-3 gap-2 sm:grid-cols-7">
                            <div v-for="[k, l] in [['ordered', 'Ordered'], ['delivered', 'Delivered'], ['uninspected', 'Not inspected'], ['accepted', 'Accepted'], ['rejected', 'Rejected'], ['posted', 'In stock'], ['remaining', 'Still owed']]" :key="k" class="rounded-xl border border-gray-200 px-3 py-2">
                                <dt class="text-xs text-gray-500">{{ l }}</dt>
                                <dd class="font-mono text-base font-medium" :class="k === 'rejected' && s[k] ? 'text-rose-700' : 'text-gray-900'">{{ s[k] }}</dd>
                            </div>
                        </dl>
                        <p class="mt-1 text-xs text-gray-500">Only accepted units with a verified invoice are added to stock. Rejected units stay owed by the supplier.</p>
                    </section>

                    <!-- Order actions -->
                    <section v-if="po.status === 'Draft'" class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        <p class="font-medium">Draft created automatically when the request was approved.</p>
                        <p class="text-xs">{{ po.notes }} Confirm the supplier, quantity, agreed price and terms, then send it.</p>
                    </section>
                    <form v-if="po.status === 'Draft' && draftOpen" class="grid grid-cols-1 gap-3 rounded-xl border border-gray-200 p-3 sm:grid-cols-4" @submit.prevent="act('patch', `procurement/purchase-orders/${po.id}/draft`, { ...draft, promised_delivery_date: draft.promised_delivery_date || null }, () => (draftOpen = false))">
                        <div class="sm:col-span-2">
                            <label class="field-label" for="dr-sup">Supplier</label>
                            <select id="dr-sup" v-model="draft.supplier_id" class="field-control w-full" required>
                                <option :value="null" disabled>Choose…</option>
                                <option v-for="s in data.suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </select>
                        </div>
                        <div><label class="field-label" for="dr-qty">Quantity (approved {{ approvedQty }})</label><input id="dr-qty" v-model.number="draft.quantity" type="number" min="1" class="field-control w-full" required /></div>
                        <div><label class="field-label" for="dr-price">Agreed unit price</label><input id="dr-price" v-model.number="draft.unit_cost" type="number" min="0.01" step="0.01" class="field-control w-full" required /></div>
                        <div v-if="draft.quantity !== approvedQty" class="sm:col-span-4">
                            <label class="field-label" for="dr-adj">Reason for changing the approved quantity{{ draft.quantity > approvedQty ? ' (administrator only)' : '' }}</label>
                            <input id="dr-adj" v-model="draft.quantity_adjustment_reason" class="field-control w-full" required />
                        </div>
                        <div><label class="field-label" for="dr-date">Order date</label><input id="dr-date" v-model="draft.po_date" type="date" :max="today" class="field-control w-full" /></div>
                        <div><label class="field-label" for="dr-prom">Expected delivery</label><input id="dr-prom" v-model="draft.promised_delivery_date" type="date" :min="today" class="field-control w-full" /></div>
                        <div class="sm:col-span-2"><label class="field-label" for="dr-terms">Payment terms</label><input id="dr-terms" v-model="draft.payment_terms" class="field-control w-full" placeholder="e.g. 30 days" /></div>
                        <p class="text-sm sm:col-span-4">Total: <span class="font-medium">{{ formatMoney((draft.quantity || 0) * (draft.unit_cost || 0)) }}</span></p>
                        <div class="flex justify-end gap-2 sm:col-span-4"><button type="button" class="secondary-button" @click="draftOpen = false">Cancel</button><button class="brand-button" :disabled="busy">Save draft</button></div>
                    </form>

                    <section class="flex flex-wrap gap-2">
                        <button v-if="po.status === 'Draft' && !draftOpen" type="button" class="secondary-button" @click="openDraft">Review and edit draft</button>
                        <button v-if="po.status === 'Draft'" type="button" class="brand-button" :disabled="busy || !readyToSend" :title="readyToSend ? '' : 'Confirm the supplier and agreed price first'" @click="act('post', `procurement/purchase-orders/${po.id}/send`)">Finalize and send to supplier</button>
                        <button v-if="['Sent to Supplier', 'Partially Delivered'].includes(po.status)" type="button" class="brand-button" :disabled="busy" @click="openReceive">Record inbound delivery</button>
                        <button v-if="['Draft', 'Sent to Supplier'].includes(po.status) && !data.deliveries.length" type="button" class="secondary-button" @click="poReason.kind = 'cancel'; poReason.reason = ''">Cancel order</button>
                        <button v-if="po.status === 'Fully Delivered' || (po.status === 'Partially Delivered' && isAdmin)" type="button" class="secondary-button" @click="poReason.kind = 'close'; poReason.reason = ''">Close order</button>
                    </section>
                    <form v-if="poReason.kind" class="rounded-xl border border-gray-200 p-3" @submit.prevent="act('post', `procurement/purchase-orders/${po.id}/${poReason.kind}`, { reason: poReason.reason }, () => (poReason.kind = ''))">
                        <label class="field-label" for="po-reason">{{ poReason.kind === 'cancel' ? 'Reason for cancelling' : (po.status === 'Partially Delivered' ? 'Reason for closing short (the rest will not be delivered)' : 'Remarks (optional)') }}</label>
                        <textarea id="po-reason" v-model="poReason.reason" rows="2" class="field-control w-full" :required="poReason.kind === 'cancel' || po.status === 'Partially Delivered'"></textarea>
                        <div class="mt-2 flex justify-end gap-2"><button type="button" class="secondary-button" @click="poReason.kind = ''">Back</button><button class="brand-button" :disabled="busy">Confirm</button></div>
                    </form>

                    <form v-if="receive.open" class="grid grid-cols-1 gap-3 rounded-xl border border-gray-200 p-3 sm:grid-cols-4" @submit.prevent="act('post', `deliveries/purchase-orders/${po.id}/receive`, { ...receive }, () => (receive.open = false))">
                        <h4 class="text-sm font-medium text-gray-900 sm:col-span-4">Record what the supplier actually delivered ({{ s.remaining }} still owed)</h4>
                        <div><label class="field-label" for="rc-date">Delivery date</label><input id="rc-date" v-model="receive.delivery_date" type="date" :max="today" class="field-control w-full" required /></div>
                        <div><label class="field-label" for="rc-qty">Delivered quantity</label><input id="rc-qty" v-model.number="receive.quantity" type="number" min="1" class="field-control w-full" required /></div>
                        <div class="sm:col-span-2"><label class="field-label" for="rc-doc">Supplier delivery document no.</label><input id="rc-doc" v-model="receive.supplier_document_number" class="field-control w-full" /></div>
                        <div v-if="receive.quantity > s.remaining" class="sm:col-span-4">
                            <label class="field-label" for="rc-over">Reason for receiving more than ordered (administrator only)</label>
                            <input id="rc-over" v-model="receive.over_receipt_reason" class="field-control w-full" />
                        </div>
                        <div class="sm:col-span-4"><label class="field-label" for="rc-notes">Remarks</label><input id="rc-notes" v-model="receive.notes" class="field-control w-full" /></div>
                        <div class="flex justify-end gap-2 sm:col-span-4"><button type="button" class="secondary-button" @click="receive.open = false">Cancel</button><button class="brand-button" :disabled="busy">Save delivery</button></div>
                    </form>

                    <!-- Deliveries -->
                    <section>
                        <h4 class="mb-2 text-sm font-medium text-gray-900">Inbound deliveries and inspection</h4>
                        <p v-if="!data.deliveries.length" class="text-xs text-gray-500">No goods received yet.</p>
                        <div v-for="d in data.deliveries" :key="d.id" class="mb-3 rounded-xl border border-gray-200 p-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <span class="font-mono font-medium text-gray-900">{{ d.delivery_number }}</span>
                                    <span class="text-xs text-gray-500"> · {{ d.delivery_date?.slice(0, 10) }} · {{ d.quantity }} received<template v-if="d.supplier_document_number"> · doc {{ d.supplier_document_number }}</template> · by {{ d.received_by ?? '—' }}</span>
                                </div>
                                <span :class="statusClass(d.inspection_status)" class="rounded-full border px-2.5 py-0.5 text-xs font-medium">{{ d.inspection_status }}</span>
                            </div>
                            <p v-if="d.over_receipt_reason" class="text-xs text-amber-700">Over-receipt authorized: {{ d.over_receipt_reason }}</p>
                            <p v-if="d.inspection_number" class="mt-1 text-xs text-gray-600">
                                {{ d.inspection_number }}: {{ d.accepted_quantity }} accepted, {{ d.rejected_quantity }} rejected
                                <template v-if="d.rejected_quantity"> (damaged {{ d.damaged_quantity ?? 0 }}, defective {{ d.defective_quantity ?? 0 }}; {{ label(d.rejection_category ?? '') }}: {{ d.rejection_reason }}) · held, not in stock</template>
                                · {{ d.inspected_on?.slice(0, 10) }} by {{ d.inspected_by ?? '—' }}
                                <template v-if="d.resolution_status"> · resolved: {{ label(d.resolution_status) }}</template>
                            </p>
                            <p v-if="d.posted_at" class="mt-1 text-xs text-emerald-700">{{ d.posted_quantity }} added to stock {{ d.posted_at.slice(0, 10) }} by {{ d.posted_by ?? '—' }}</p>

                            <div class="mt-2 flex flex-wrap gap-2">
                                <button v-if="!d.posted_at && (!d.inspection_number || isAdmin)" type="button" class="secondary-button" @click="openInspection(d)">{{ d.inspection_number ? 'Correct inspection' : 'Inspect' }}</button>
                                <button v-if="d.rejected_quantity && !d.resolution_status" type="button" class="secondary-button" @click="resolution.id = d.id">Record rejection resolution</button>
                                <button v-if="d.inspection_number && !invoiceFor(d.id).length" type="button" class="secondary-button" @click="openInvoice(d)">Record supplier invoice</button>
                                <!-- Normally automatic on invoice verification; shown only for older verified invoices. -->
                                <button v-if="d.inspection_number && d.accepted_quantity && !d.posted_at && hasVerifiedInvoice(d.id)" type="button" class="secondary-button" :disabled="busy" @click="act('post', `deliveries/${d.id}/post-inventory`)">
                                    Post {{ d.accepted_quantity }} accepted to inventory
                                </button>
                            </div>
                            <p v-if="d.inspection_number && d.accepted_quantity && !d.posted_at && !hasVerifiedInvoice(d.id)" class="mt-1 text-xs text-gray-500">
                                The {{ d.accepted_quantity }} accepted units are added to stock automatically when the supplier invoice is verified.
                            </p>

                            <form v-if="inspection.id === d.id" class="mt-3 grid grid-cols-1 gap-3 rounded-xl bg-gray-50 p-3 sm:grid-cols-4" @submit.prevent="act('post', `deliveries/${d.id}/inspection`, inspectionPayload(), () => (inspection.id = 0))">
                                <div><label class="field-label" :for="`in-a-${d.id}`">Accepted</label><input :id="`in-a-${d.id}`" v-model.number="inspection.accepted_quantity" type="number" min="0" class="field-control w-full" /></div>
                                <div><label class="field-label" :for="`in-r-${d.id}`">Rejected</label><input :id="`in-r-${d.id}`" v-model.number="inspection.rejected_quantity" type="number" min="0" class="field-control w-full" /></div>
                                <div><label class="field-label" :for="`in-d-${d.id}`">Inspected on</label><input :id="`in-d-${d.id}`" v-model="inspection.inspected_on" type="date" :max="today" class="field-control w-full" /></div>
                                <p class="self-end text-xs" :class="inspection.accepted_quantity + inspection.rejected_quantity === d.quantity ? 'text-gray-500' : 'text-rose-600'">Must add up to {{ d.quantity }} received.</p>
                                <template v-if="inspection.rejected_quantity > 0">
                                    <div><label class="field-label" :for="`in-dm-${d.id}`">of which damaged</label><input :id="`in-dm-${d.id}`" v-model.number="inspection.damaged_quantity" type="number" min="0" :max="inspection.rejected_quantity" class="field-control w-full" /></div>
                                    <div><label class="field-label" :for="`in-df-${d.id}`">of which defective</label><input :id="`in-df-${d.id}`" v-model.number="inspection.defective_quantity" type="number" min="0" :max="inspection.rejected_quantity" class="field-control w-full" /></div>
                                    <p class="self-end text-xs sm:col-span-2" :class="otherRejected >= 0 && inspection.damaged_quantity + inspection.defective_quantity <= inspection.rejected_quantity ? 'text-gray-500' : 'text-rose-600'">
                                        {{ otherRejected }} rejected for another reason. Rejected units are held for return, replacement or credit.
                                    </p>
                                    <div>
                                        <label class="field-label" :for="`in-c-${d.id}`">Rejection category{{ otherRejected === 0 ? ' (optional)' : '' }}</label>
                                        <select :id="`in-c-${d.id}`" v-model="inspection.rejection_category" class="field-control w-full" :required="otherRejected > 0">
                                            <option value="">{{ otherRejected === 0 ? 'From the breakdown' : 'Choose…' }}</option>
                                            <option v-for="c in data.rejection_categories" :key="c" :value="c">{{ label(c) }}</option>
                                        </select>
                                    </div>
                                    <div class="sm:col-span-3"><label class="field-label" :for="`in-rr-${d.id}`">Defect / rejection reason</label><input :id="`in-rr-${d.id}`" v-model="inspection.rejection_reason" class="field-control w-full" required /></div>
                                </template>
                                <div class="sm:col-span-4"><label class="field-label" :for="`in-rm-${d.id}`">Remarks (condition, specification check)</label><input :id="`in-rm-${d.id}`" v-model="inspection.inspection_remarks" class="field-control w-full" /></div>
                                <div class="flex justify-end gap-2 sm:col-span-4"><button type="button" class="secondary-button" @click="inspection.id = 0">Cancel</button><button class="brand-button" :disabled="busy">Save inspection</button></div>
                            </form>

                            <form v-if="resolution.id === d.id" class="mt-3 grid grid-cols-1 gap-3 rounded-xl bg-gray-50 p-3 sm:grid-cols-4" @submit.prevent="act('post', `deliveries/${d.id}/resolution`, { resolution_status: resolution.resolution_status, resolution_remarks: resolution.resolution_remarks }, () => (resolution.id = 0))">
                                <div>
                                    <label class="field-label" :for="`rs-${d.id}`">Resolution</label>
                                    <select :id="`rs-${d.id}`" v-model="resolution.resolution_status" class="field-control w-full"><option v-for="r in data.resolutions" :key="r" :value="r">{{ label(r) }}</option></select>
                                </div>
                                <div class="sm:col-span-3"><label class="field-label" :for="`rsr-${d.id}`">Remarks (RMA, credit note, replacement delivery…)</label><input :id="`rsr-${d.id}`" v-model="resolution.resolution_remarks" class="field-control w-full" /></div>
                                <div class="flex justify-end gap-2 sm:col-span-4"><button type="button" class="secondary-button" @click="resolution.id = 0">Cancel</button><button class="brand-button" :disabled="busy">Save</button></div>
                            </form>

                            <form v-if="invoice.open && invoice.delivery_id === d.id" class="mt-3 grid grid-cols-1 gap-3 rounded-xl bg-gray-50 p-3 sm:grid-cols-4" @submit.prevent="act('post', 'procurement/invoices', invoicePayload(), () => (invoice.open = false))">
                                <div><label class="field-label" :for="`iv-n-${d.id}`">Supplier invoice no.</label><input :id="`iv-n-${d.id}`" v-model="invoice.invoice_number" class="field-control w-full" required /></div>
                                <div><label class="field-label" :for="`iv-d-${d.id}`">Invoice date</label><input :id="`iv-d-${d.id}`" v-model="invoice.invoice_date" type="date" :max="today" class="field-control w-full" required /></div>
                                <div><label class="field-label" :for="`iv-q-${d.id}`">Invoiced quantity</label><input :id="`iv-q-${d.id}`" v-model.number="invoice.quantity" type="number" min="1" class="field-control w-full" required /></div>
                                <div><label class="field-label" :for="`iv-u-${d.id}`">Invoiced unit price</label><input :id="`iv-u-${d.id}`" v-model.number="invoice.unit_cost" type="number" min="0" step="0.01" class="field-control w-full" required /></div>
                                <div><label class="field-label" :for="`iv-t-${d.id}`">Tax</label><input :id="`iv-t-${d.id}`" v-model.number="invoice.tax_amount" type="number" min="0" step="0.01" class="field-control w-full" /></div>
                                <div class="sm:col-span-2"><label class="field-label" :for="`iv-p-${d.id}`">Payment terms</label><input :id="`iv-p-${d.id}`" v-model="invoice.payment_terms" class="field-control w-full" /></div>
                                <p class="self-end text-xs text-gray-500">Enter what the supplier charged, not what was ordered.</p>
                                <div class="sm:col-span-4">
                                    <label class="field-label" :for="`iv-f-${d.id}`">Invoice document (PDF or image, optional, up to 25 MB)</label>
                                    <input :id="`iv-f-${d.id}`" type="file" accept=".pdf,.png,.jpg,.jpeg" class="field-control w-full" @change="invoice.attachment = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                                </div>
                                <div class="flex justify-end gap-2 sm:col-span-4"><button type="button" class="secondary-button" @click="invoice.open = false">Cancel</button><button class="brand-button" :disabled="busy">Record invoice</button></div>
                            </form>
                        </div>
                    </section>

                    <!-- Invoices -->
                    <section>
                        <h4 class="mb-2 text-sm font-medium text-gray-900">Commercial invoices (PO ↔ delivery ↔ inspection ↔ invoice)</h4>
                        <p v-if="!data.invoices.length" class="text-xs text-gray-500">No supplier invoice recorded yet.</p>
                        <div v-for="ci in data.invoices" :key="ci.id" class="mb-3 rounded-xl border border-gray-200 p-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <span class="font-mono font-medium text-gray-900">{{ ci.invoice_number }}</span>
                                    <span class="text-xs text-gray-500"> · {{ ci.invoice_date?.slice(0, 10) }} · delivery {{ ci.delivery_number ?? '—' }} · {{ ci.quantity }} × {{ formatMoney(Number(ci.unit_cost)) }}<template v-if="Number(ci.tax_amount)"> + tax {{ formatMoney(Number(ci.tax_amount)) }}</template> = {{ formatMoney(Number(ci.total_cost)) }}</span>
                                </div>
                                <span :class="statusClass(ci.status)" class="rounded-full border px-2.5 py-0.5 text-xs font-medium">{{ ci.status }}</span>
                            </div>
                            <ul v-if="ci.discrepancies?.length" class="mt-2 space-y-0.5 text-xs" :class="ci.status === 'Verified' ? 'text-gray-600' : 'text-rose-700'">
                                <li v-for="f in ci.discrepancies" :key="f.code">{{ ci.status === 'Verified' ? 'Accepted difference: ' : '' }}{{ f.message }}{{ f.overridable || ci.status === 'Verified' ? '' : ' (must be fixed)' }}</li>
                            </ul>
                            <p v-else-if="ci.status !== 'Verified'" class="mt-1 text-xs text-emerald-700">Matches the purchase order and the accepted quantity.</p>
                            <p v-if="ci.attachment_name" class="mt-1 text-xs"><a :href="`/${teamSlug}/procurement/invoices/${ci.id}/attachment`" class="text-[#4b5320] underline">{{ ci.attachment_name }}</a></p>
                            <p v-if="ci.verification_remarks" class="mt-1 text-xs text-gray-600">Remarks: {{ ci.verification_remarks }}</p>
                            <p v-if="ci.verified_at" class="text-xs text-gray-500">Verified {{ ci.verified_at.slice(0, 10) }} by {{ ci.verified_by ?? '—' }}. Verification is not payment.</p>

                            <div v-if="['Pending Verification', 'Flagged'].includes(ci.status)" class="mt-2 flex flex-wrap gap-2">
                                <button type="button" class="brand-button" :disabled="busy" :title="'Verifying adds the accepted units to stock automatically'" @click="decision.id = ci.id; decision.kind = 'verify'; decision.remarks = ''">Verify and receive into stock</button>
                                <button v-if="ci.status === 'Pending Verification'" type="button" class="secondary-button" @click="decision.id = ci.id; decision.kind = 'flag'; decision.remarks = ''">Flag problem</button>
                                <button type="button" class="secondary-button" @click="openCorrection(ci)">Correct invoice</button>
                            </div>

                            <form v-if="decision.id === ci.id && decision.kind" class="mt-3 rounded-xl bg-gray-50 p-3" @submit.prevent="act('post', `procurement/invoices/${ci.id}/${decision.kind}`, { remarks: decision.remarks }, () => (decision.kind = ''))">
                                <label class="field-label" :for="`dc-${ci.id}`">
                                    {{ decision.kind === 'flag' ? 'Describe the problem' : (ci.discrepancies?.length ? 'Why the difference is accepted (administrator only)' : 'Verification remarks (optional)') }}
                                </label>
                                <textarea :id="`dc-${ci.id}`" v-model="decision.remarks" rows="2" class="field-control w-full" :required="decision.kind === 'flag' || ci.discrepancies?.length > 0"></textarea>
                                <div class="mt-2 flex justify-end gap-2"><button type="button" class="secondary-button" @click="decision.kind = ''">Back</button><button class="brand-button" :disabled="busy">{{ decision.kind === 'flag' ? 'Flag invoice' : 'Verify invoice' }}</button></div>
                            </form>

                            <form v-if="correction.id === ci.id" class="mt-3 grid grid-cols-1 gap-3 rounded-xl bg-gray-50 p-3 sm:grid-cols-4" @submit.prevent="act('patch', `procurement/invoices/${ci.id}`, { ...correction }, () => (correction.id = 0))">
                                <div><label class="field-label" :for="`cr-n-${ci.id}`">Invoice no.</label><input :id="`cr-n-${ci.id}`" v-model="correction.invoice_number" class="field-control w-full" /></div>
                                <div><label class="field-label" :for="`cr-q-${ci.id}`">Quantity</label><input :id="`cr-q-${ci.id}`" v-model.number="correction.quantity" type="number" min="1" class="field-control w-full" /></div>
                                <div><label class="field-label" :for="`cr-u-${ci.id}`">Unit price</label><input :id="`cr-u-${ci.id}`" v-model.number="correction.unit_cost" type="number" min="0" step="0.01" class="field-control w-full" /></div>
                                <div><label class="field-label" :for="`cr-t-${ci.id}`">Tax</label><input :id="`cr-t-${ci.id}`" v-model.number="correction.tax_amount" type="number" min="0" step="0.01" class="field-control w-full" /></div>
                                <div class="sm:col-span-4"><label class="field-label" :for="`cr-r-${ci.id}`">Reason (e.g. corrected invoice received)</label><input :id="`cr-r-${ci.id}`" v-model="correction.remarks" class="field-control w-full" required /></div>
                                <div class="flex justify-end gap-2 sm:col-span-4"><button type="button" class="secondary-button" @click="correction.id = 0">Cancel</button><button class="brand-button" :disabled="busy">Save correction</button></div>
                            </form>
                        </div>
                    </section>
                </template>
            </div>
        </div>
    </div>
</template>
