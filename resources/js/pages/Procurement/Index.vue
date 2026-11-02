<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { useCanDelete } from '@/composables/useCanDelete';
import { useCurrency } from '@/composables/useCurrency';
import { showToast } from '@/composables/useFlashToast';
import { ref, computed } from 'vue';
import SpecularButton from '@/components/ui/SpecularButton.vue';
import PurchaseRequestCreator, { type PrCandidate } from '@/components/procurement/PurchaseRequestCreator.vue';
import PurchaseOrderWorkspace from '@/components/procurement/PurchaseOrderWorkspace.vue';
import { 
    Search, 
    Plus, 
    Truck, 
    Clock, 
    CheckCircle2, 
    Check,
    XCircle, 
    Package, 
    Trash2, 
    Calendar, 
    Building2,
    FileText,
    Receipt,
    ShoppingCart,
    UploadCloud,
    Download,
    AlertCircle,
    Eye
} from '@lucide/vue';

interface CommercialInvoiceItem {
    id: number;
    invoice_date: string;
    invoice_number: string;
    quantity: number;
    description: string;
    unit_cost: number;
    total_cost: number;
    supplier_id?: number | null;
    status: string;
    notes?: string | null;
    supplier?: { id: number; name: string };
    purchase_order_id?: number | null;
    purchase_order?: { id: number; po_number: string } | null;
    delivery?: { id: number; delivery_number: string } | null;
    discrepancies?: { code: string; message: string; overridable: boolean }[] | null;
    verified_by?: { id: number; name: string } | null;
}

interface PurchaseOrderItem {
    id: number;
    po_date: string;
    po_number: string;
    quantity: number;
    item_sku?: string | null;
    description: string;
    unit_of_measure?: string | null;
    unit_cost: number;
    total_cost: number;
    supplier_id?: number | null;
    status: string;
    notes?: string | null;
    supplier?: { id: number; name: string };
    product_id?: number | null;
    product?: { id: number; name: string; sku: string } | null;
    promised_delivery_date?: string | null;
    deliveries_count?: number;
    purchase_request?: { id: number; request_number: string } | null;
}

interface PurchaseRequestItem {
    id: number;
    request_number: string;
    product_id: number;
    supplier_id: number;
    quantity: number;
    estimated_cost: number;
    status: string;
    notes?: string | null;
    requested_at: string;
    submitted_at?: string | null;
    current_stock?: number | null;
    forecast_quantity?: number | null;
    ahp_score?: number | null;
    ahp_classification?: string | null;
    recommended_quantity?: number | null;
    justification?: string | null;
    reviewed_at?: string | null;
    review_remarks?: string | null;
    product?: { id: number; name: string; sku: string };
    supplier?: { id: number; name: string };
    requested_by?: { id: number; name: string };
    reviewed_by?: { id: number; name: string } | null;
    purchase_order?: { id: number; po_number: string; status: string } | null;
}

interface ProductItem {
    id: number;
    name: string;
    sku: string;
    cost_price: number;
    price: number;
    stock: number;
    reorder_level: number;
}

interface SupplierItem {
    id: number;
    name: string;
}

const props = defineProps<{
    commercialInvoices?: {
        data: CommercialInvoiceItem[];
        current_page: number;
        last_page: number;
        total: number;
        links: any[];
    };
    purchaseOrders?: {
        data: PurchaseOrderItem[];
        current_page: number;
        last_page: number;
        total: number;
        links: any[];
    };
    requests: {
        data: PurchaseRequestItem[];
        current_page: number;
        last_page: number;
        total: number;
        links: any[];
    };
    activeTab?: string;
    filters?: {
        search?: string;
        status?: string;
        supplier_id?: string | number;
        unit_of_measure?: string;
        date_from?: string;
        date_to?: string;
        tab?: string;
    };
    metrics: {
        totalRequests: number;
        requestedCount: number;
        orderedCount: number;
        deliveredCount: number;
        totalCommercialInvoices?: number;
        totalCommercialInvoiceAmount?: number;
        totalPurchaseOrders?: number;
        totalPurchaseOrderAmount?: number;
    };
    products: ProductItem[];
    suppliers: SupplierItem[];
    isAdmin: boolean;
    statusOptions: { requests: string[]; purchase_orders: string[]; commercial_invoices: string[] };
}>();

const page = usePage();
const canDelete = useCanDelete();
const teamSlug = computed(() => (page.props as any).currentTeam?.slug || (page.props as any).team?.slug || '');

// Flash toasts are handled globally by useFlashToast in AppSidebarLayout

// Stored amounts are USD; shown in the user's display currency (header switch).
const { formatMoney: formatCurrency } = useCurrency();

const formatDate = (dateStr: string) => {
    if (!dateStr) return '—';
    // Format as MONTH-DAY-YEAR e.g. 10-25-2026
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return dateStr;
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    const yyyy = d.getFullYear();
    return `${mm}-${dd}-${yyyy}`;
};

// Tabs: 'commercial_invoices' | 'purchase_orders' | 'requests'
const activeTab = ref(props.activeTab || props.filters?.tab || 'commercial_invoices');

// Search & Filters state
const search = ref(props.filters?.search || '');
const selectedStatus = ref(props.filters?.status || 'All');
const selectedSupplier = ref(
    props.filters?.supplier_id !== undefined && props.filters?.supplier_id !== null && String(props.filters?.supplier_id) !== ''
        ? String(props.filters?.supplier_id) 
        : 'All'
);
const selectedUm = ref(props.filters?.unit_of_measure || 'All');

let searchTimeout: ReturnType<typeof setTimeout> | null = null;
const applyFilter = () => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(
            `/${teamSlug.value}/procurement`,
            {
                tab: activeTab.value,
                search: search.value || undefined,
                status: selectedStatus.value !== 'All' ? selectedStatus.value : undefined,
                supplier_id: selectedSupplier.value !== 'All' ? selectedSupplier.value : undefined,
                unit_of_measure: activeTab.value === 'purchase_orders' && selectedUm.value !== 'All' ? selectedUm.value : undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true }
        );
    }, 300);
};

const switchTab = (tab: string) => {
    activeTab.value = tab;
    search.value = '';
    selectedStatus.value = 'All';
    selectedSupplier.value = 'All';
    selectedUm.value = 'All';
    applyFilter();
};

const resetFilters = () => {
    search.value = '';
    selectedStatus.value = 'All';
    selectedSupplier.value = 'All';
    selectedUm.value = 'All';
    applyFilter();
};

const hasActiveFilters = computed(() => {
    return !!search.value || 
        selectedStatus.value !== 'All' || 
        selectedSupplier.value !== 'All' || 
        (activeTab.value === 'purchase_orders' && selectedUm.value !== 'All');
});

// ==========================================
// 1. COMMERCIAL INVOICES LOGIC
// ==========================================
const showDeleteCiModal = ref(false);
const selectedCi = ref<CommercialInvoiceItem | null>(null);
const deleteCiConfirmInput = ref('');

const openDeleteCiModal = (ci: CommercialInvoiceItem) => {
    deleteCiConfirmInput.value = '';
    selectedCi.value = ci;
    showDeleteCiModal.value = true;
};

const confirmDeleteCi = () => {
    if (!selectedCi.value) return;
    router.delete(`/${teamSlug.value}/procurement/commercial-invoices/${selectedCi.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            showDeleteCiModal.value = false;
            selectedCi.value = null;
            showToast('Commercial invoice deleted successfully.', 'success');
        },
    });
};

// CI CSV Import
const showCiImportModal = ref(false);
const ciImportFile = ref<File | null>(null);
const isValidatingCiCsv = ref(false);
const isImportingCiCsv = ref(false);
const ciCsvValidationResult = ref<any>(null);
const ciCsvError = ref('');

const openCiImportModal = () => {
    ciImportFile.value = null;
    ciCsvValidationResult.value = null;
    ciCsvError.value = '';
    showCiImportModal.value = true;
};

const handleCiFileChange = (e: Event) => {
    const input = e.target as HTMLInputElement;
    if (input.files && input.files[0]) {
        ciImportFile.value = input.files[0];
        validateCiCsv();
    }
};

const validateCiCsv = async () => {
    if (!ciImportFile.value) return;
    isValidatingCiCsv.value = true;
    ciCsvError.value = '';

    const formData = new FormData();
    formData.append('file', ciImportFile.value);
    formData.append('execute', '0');

    const token = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '';

    try {
        const response = await fetch(`/${teamSlug.value}/procurement/commercial-invoices/import-csv`, {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-TOKEN': token,
                'Accept': 'application/json',
            }
        });
        const data = await response.json();
        if (response.ok && data.success) {
            ciCsvValidationResult.value = data;
        } else {
            ciCsvError.value = data.message || 'Failed to validate CSV structure.';
            ciCsvValidationResult.value = null;
        }
    } catch (err: any) {
        ciCsvError.value = err.message || 'Failed to process CSV file.';
        ciCsvValidationResult.value = null;
    } finally {
        isValidatingCiCsv.value = false;
    }
};

const downloadSampleCiCsv = () => {
    const headers = 'DATE,COMMERCIAL INVOICE,QUANTITY,Description,UNIT COST\n';
    const sampleRows = [
        '10-25-2026,CI-035-0722,50,"Heavy Duty Electrical Conduit 20mm",120.00',
        '10-25-2026,CI-036-0722,15,"Industrial Circuit Breaker 3-Phase 100A",1450.00',
        '07-22-2022,CI-035-0722,100,"Single Gang Electrical Outlet Grounded",95.50'
    ].join('\n');

    const blob = new Blob([headers + sampleRows], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'mr_pride_commercial_invoices_template.csv';
    link.click();
    URL.revokeObjectURL(link.href);
    showToast('Sample Commercial Invoice CSV downloaded with required headers!', 'info');
};

const executeCiCsvImport = () => {
    if (!ciImportFile.value || !ciCsvValidationResult.value || ciCsvValidationResult.value.summary?.valid_count === 0) {
        showToast('No valid rows available to import.', 'error');
        return;
    }

    isImportingCiCsv.value = true;
    const formData = new FormData();
    formData.append('file', ciImportFile.value);
    formData.append('execute', '1');

    router.post(`/${teamSlug.value}/procurement/commercial-invoices/import-csv`, formData as any, {
        onSuccess: () => {
            showCiImportModal.value = false;
            isImportingCiCsv.value = false;
            showToast('Commercial invoices imported successfully!', 'success');
        },
        onError: (err) => {
            isImportingCiCsv.value = false;
            const msg = Object.values(err)[0] as string;
            showToast(msg || 'Failed to import CSV.', 'error');
        }
    });
};

// ==========================================
// 2. PURCHASE ORDERS LOGIC
// ==========================================
// Product and promised delivery date of an existing purchase order (Supplier Evaluation uses them).
const schedulePo = ref<PurchaseOrderItem | null>(null);
const scheduleForm = useForm({ product_id: null as number | null, promised_delivery_date: '' });
const openSchedule = (po: PurchaseOrderItem) => {
    schedulePo.value = po;
    scheduleForm.clearErrors();
    scheduleForm.product_id = po.product_id ?? null;
    scheduleForm.promised_delivery_date = po.promised_delivery_date?.slice(0, 10) ?? '';
};
const submitSchedule = () => {
    if (!schedulePo.value) return;
    scheduleForm.transform((d) => ({ ...d, promised_delivery_date: d.promised_delivery_date || null }))
        .patch(`/${teamSlug.value}/procurement/purchase-orders/${schedulePo.value.id}/schedule`, {
            preserveScroll: true,
            onSuccess: () => {
                schedulePo.value = null;
            },
        });
};

const showDeletePoModal = ref(false);
const selectedPo = ref<PurchaseOrderItem | null>(null);
const deletePoConfirmInput = ref('');

const openDeletePoModal = (po: PurchaseOrderItem) => {
    deletePoConfirmInput.value = '';
    selectedPo.value = po;
    showDeletePoModal.value = true;
};

const confirmDeletePo = () => {
    if (!selectedPo.value) return;
    router.delete(`/${teamSlug.value}/procurement/purchase-orders/${selectedPo.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            showDeletePoModal.value = false;
            selectedPo.value = null;
            showToast('Purchase order deleted successfully.', 'success');
        },
    });
};

// PO CSV Import
const showPoImportModal = ref(false);
const poImportFile = ref<File | null>(null);
const isValidatingPoCsv = ref(false);
const isImportingPoCsv = ref(false);
const poCsvValidationResult = ref<any>(null);
const poCsvError = ref('');

const openPoImportModal = () => {
    poImportFile.value = null;
    poCsvValidationResult.value = null;
    poCsvError.value = '';
    showPoImportModal.value = true;
};

const handlePoFileChange = (e: Event) => {
    const input = e.target as HTMLInputElement;
    if (input.files && input.files[0]) {
        poImportFile.value = input.files[0];
        validatePoCsv();
    }
};

const validatePoCsv = async () => {
    if (!poImportFile.value) return;
    isValidatingPoCsv.value = true;
    poCsvError.value = '';

    const formData = new FormData();
    formData.append('file', poImportFile.value);
    formData.append('execute', '0');

    const token = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '';

    try {
        const response = await fetch(`/${teamSlug.value}/procurement/purchase-orders/import-csv`, {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-TOKEN': token,
                'Accept': 'application/json',
            }
        });
        const data = await response.json();
        if (response.ok && data.success) {
            poCsvValidationResult.value = data;
        } else {
            poCsvError.value = data.message || 'Failed to validate CSV structure.';
            poCsvValidationResult.value = null;
        }
    } catch (err: any) {
        poCsvError.value = err.message || 'Failed to process CSV file.';
        poCsvValidationResult.value = null;
    } finally {
        isValidatingPoCsv.value = false;
    }
};

const downloadSamplePoCsv = () => {
    const headers = 'Date,Purchase Order No.,Quantity,ITEM/SKU Number,Description,U/M,Unit Cost\n';
    const sampleRows = [
        '10-25-2026,4000005472,100,068SSCR2,"0.68mm Stainless Steel Conduit Rod 2m",PCS,450.00',
        '10-25-2026,4000005473,40,BRK-40A,"Circuit Breaker 2P 40A Rail Mounted",BOX,320.00',
        '10-25-2026,4000005474,250,WIR-THHN,"THHN Building Wire 3.5mm Stranded",MTR,35.00'
    ].join('\n');

    const blob = new Blob([headers + sampleRows], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'mr_pride_purchase_orders_template.csv';
    link.click();
    URL.revokeObjectURL(link.href);
    showToast('Sample Purchase Order CSV downloaded with required headers!', 'info');
};

const executePoCsvImport = () => {
    if (!poImportFile.value || !poCsvValidationResult.value || poCsvValidationResult.value.summary?.valid_count === 0) {
        showToast('No valid rows available to import.', 'error');
        return;
    }

    isImportingPoCsv.value = true;
    const formData = new FormData();
    formData.append('file', poImportFile.value);
    formData.append('execute', '1');

    router.post(`/${teamSlug.value}/procurement/purchase-orders/import-csv`, formData as any, {
        onSuccess: () => {
            showPoImportModal.value = false;
            isImportingPoCsv.value = false;
            showToast('Purchase orders imported successfully!', 'success');
        },
        onError: (err) => {
            isImportingPoCsv.value = false;
            const msg = Object.values(err)[0] as string;
            showToast(msg || 'Failed to import CSV.', 'error');
        }
    });
};

// ==========================================
// 3. PURCHASE REQUESTS LOGIC
// ==========================================
const showDeleteModal = ref(false);
const selectedRequest = ref<PurchaseRequestItem | null>(null);
const actionBusy = ref(false);

// New request: pick a product; the server takes the AHP / forecast snapshot on submit.
const showProductPicker = ref(false);
const pickedProductId = ref<number | null>(null);
const prCandidates = ref<PrCandidate[]>([]);
const showPrCreator = ref(false);
const openNewRequest = () => {
    pickedProductId.value = props.products[0]?.id ?? null;
    showProductPicker.value = true;
};
const continueNewRequest = () => {
    const p = props.products.find((x) => x.id === pickedProductId.value);
    if (!p) return;
    prCandidates.value = [{ product_id: p.id, name: p.name, sku: p.sku, current_stock: p.stock, forecast: null, ahp_score: null, classification: null, recommended_quantity: null }];
    showProductPicker.value = false;
    showPrCreator.value = true;
};

// Review (admin) and conversion to a purchase order.
const reviewing = ref<PurchaseRequestItem | null>(null);
const reviewForm = useForm({ decision: 'approve' as 'approve' | 'reject', remarks: '' });
const openReview = (req: PurchaseRequestItem) => {
    reviewing.value = req;
    reviewForm.reset();
    reviewForm.clearErrors();
};
const submitReview = (decision: 'approve' | 'reject') => {
    if (!reviewing.value) return;
    reviewForm.decision = decision;
    reviewForm.post(`/${teamSlug.value}/procurement/requests/${reviewing.value.id}/review`, {
        preserveScroll: true,
        onSuccess: () => { if (!Object.keys(reviewForm.errors).length) reviewing.value = null; },
    });
};

const submitDraftRequest = (req: PurchaseRequestItem) => {
    router.post(`/${teamSlug.value}/procurement/requests/${req.id}/submit`, {}, { preserveScroll: true });
};

// Purchase order workspace (deliveries, inspection, invoices, posting).
const workspacePoId = ref<number | null>(null);
const closeWorkspace = () => {
    workspacePoId.value = null;
    router.reload({ only: ['purchaseOrders', 'commercialInvoices', 'requests', 'metrics'] });
};

const prStatusClass = (st: string) => ({
    'Draft': 'bg-gray-50 text-gray-700 border-gray-200',
    'Pending Approval': 'bg-blue-50 text-blue-700 border-blue-200',
    'Approved': 'bg-emerald-50 text-emerald-700 border-emerald-200',
    'Rejected': 'bg-rose-50 text-rose-700 border-rose-200',
    'Converted to Purchase Order': 'bg-gray-100 text-gray-700 border-gray-200',
    'Sent to Supplier': 'bg-blue-50 text-blue-700 border-blue-200',
    'Partially Delivered': 'bg-amber-50 text-amber-800 border-amber-200',
    'Fully Delivered': 'bg-emerald-50 text-emerald-700 border-emerald-200',
    'Closed': 'bg-gray-100 text-gray-700 border-gray-200',
    'Cancelled': 'bg-rose-50 text-rose-700 border-rose-200',
    'Pending Verification': 'bg-blue-50 text-blue-700 border-blue-200',
    'Flagged': 'bg-rose-50 text-rose-700 border-rose-200',
    'Verified': 'bg-emerald-50 text-emerald-700 border-emerald-200',
}[st] ?? 'bg-gray-50 text-gray-700 border-gray-200');

// Action Confirmation Modal State
interface ConfirmActionOptions {
    title: string;
    description: string;
    details?: Array<{ label: string; value: string | number }>;
    confirmText?: string;
    action: () => void;
}

const showConfirmModal = ref(false);
const confirmOptions = ref<ConfirmActionOptions>({
    title: 'Confirm Action',
    description: 'Are you sure you want to proceed?',
    details: [],
    confirmText: 'Confirm & Submit',
    action: () => {},
});

const openConfirmModal = (options: ConfirmActionOptions) => {
    confirmOptions.value = {
        confirmText: 'Confirm & Submit',
        ...options,
    };
    showConfirmModal.value = true;
};

const handleConfirmedAction = () => {
    showConfirmModal.value = false;
    confirmOptions.value.action();
};

const deleteConfirmInput = ref('');

const openDeleteModal = (req: PurchaseRequestItem) => {
    deleteConfirmInput.value = '';
    selectedRequest.value = req;
    showDeleteModal.value = true;
};

const confirmDelete = () => {
    if (!selectedRequest.value) return;
    router.delete(`/${teamSlug.value}/procurement/${selectedRequest.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            showDeleteModal.value = false;
            selectedRequest.value = null;
        },
    });
};
</script>

<template>
    <Head title="Procurement Monitoring" />

    <div class="workspace-page">
        <!-- Page Header -->
        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-4">
            <div>
                <h1 class="page-title">Procurement & Sourcing</h1>
                <p class="page-subtitle">Commercial invoices, purchase orders and restock requests.</p>
            </div>

            <!-- Contextual Actions -->
            <div class="flex flex-wrap items-center justify-start lg:justify-end gap-2.5 w-full lg:w-auto">
                <template v-if="activeTab === 'commercial_invoices'">
                    <SpecularButton
                        variant="secondary"
                        size="md"
                        :radius="12"
                        @click="openCiImportModal"
                    >
                        <UploadCloud class="w-4 h-4 text-gray-500" />
                        <span>Import CSV</span>
                    </SpecularButton>
                </template>

                <template v-else-if="activeTab === 'purchase_orders'">
                    <SpecularButton
                        variant="secondary"
                        size="md"
                        :radius="12"
                        @click="openPoImportModal"
                    >
                        <UploadCloud class="w-4 h-4 text-gray-500" />
                        <span>Import CSV</span>
                    </SpecularButton>
                </template>

                <template v-else>
                    <SpecularButton
                        variant="brand"
                        size="md"
                        :radius="12"
                        @click="openNewRequest"
                    >
                        <Plus class="w-4 h-4" />
                        <span>New Purchase Request</span>
                    </SpecularButton>
                </template>
            </div>
        </div>

        <!-- Tab Switcher -->
        <div class="flex items-center gap-2 border-b border-gray-200 pb-3 mb-6 overflow-x-auto">
            <button
                type="button"
                @click="switchTab('commercial_invoices')"
                :class="[
                    'flex items-center gap-2 px-3.5 py-2 rounded-lg text-sm font-medium transition-colors cursor-pointer whitespace-nowrap',
                    activeTab === 'commercial_invoices'
                        ? 'bg-gray-100 text-gray-900'
                        : 'text-gray-500 hover:text-gray-900 hover:bg-gray-50'
                ]"
            >
                <Receipt class="w-4 h-4" />
                <span>Commercial Invoices</span>
                <span 
                    v-if="metrics.totalCommercialInvoices !== undefined" 
                    class="ml-1 px-2 py-0.5 text-xs rounded-full bg-black/10 text-gray-900 font-semibold"
                >
                    {{ metrics.totalCommercialInvoices }}
                </span>
            </button>

            <button
                type="button"
                @click="switchTab('purchase_orders')"
                :class="[
                    'flex items-center gap-2 px-3.5 py-2 rounded-lg text-sm font-medium transition-colors cursor-pointer whitespace-nowrap',
                    activeTab === 'purchase_orders'
                        ? 'bg-gray-100 text-gray-900'
                        : 'text-gray-500 hover:text-gray-900 hover:bg-gray-50'
                ]"
            >
                <ShoppingCart class="w-4 h-4" />
                <span>Purchase Orders</span>
                <span 
                    v-if="metrics.totalPurchaseOrders !== undefined" 
                    class="ml-1 px-2 py-0.5 text-xs rounded-full bg-black/10 text-gray-900 font-semibold"
                >
                    {{ metrics.totalPurchaseOrders }}
                </span>
            </button>

            <button
                type="button"
                @click="switchTab('requests')"
                :class="[
                    'flex items-center gap-2 px-3.5 py-2 rounded-lg text-sm font-medium transition-colors cursor-pointer whitespace-nowrap',
                    activeTab === 'requests'
                        ? 'bg-gray-100 text-gray-900'
                        : 'text-gray-500 hover:text-gray-900 hover:bg-gray-50'
                ]"
            >
                <Package class="w-4 h-4" />
                <span>Purchase Requests</span>
                <span class="ml-1 px-2 py-0.5 text-xs rounded-full bg-black/10 text-gray-900 font-semibold">
                    {{ metrics.totalRequests }}
                </span>
            </button>
        </div>

        <!-- Metrics Cards Section -->
        <div v-if="activeTab === 'commercial_invoices'" class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
            <div class="bg-white border border-gray-200 rounded-xl p-5">
                <div class="flex items-center justify-between text-gray-500 text-xs font-semibold mb-2">
                    <span>Total Invoices</span>
                    <Receipt class="w-4 h-4 text-emerald-500" />
                </div>
                <div class="text-2xl font-semibold text-gray-900">{{ metrics.totalCommercialInvoices || 0 }}</div>
                <div class="text-xs text-gray-500 mt-1">Logged vendor invoices</div>
            </div>

            <div class="bg-white border border-gray-200 rounded-xl p-5">
                <div class="flex items-center justify-between text-gray-500 text-xs font-semibold mb-2">
                    <span>Total Cost Value</span>
                    <Package class="w-4 h-4 text-[#F8B803]" />
                </div>
                <div class="text-2xl font-semibold text-[#B45309]">{{ formatCurrency(metrics.totalCommercialInvoiceAmount || 0) }}</div>
                <div class="text-xs text-gray-500 mt-1">Total invoiced goods value</div>
            </div>
        </div>

        <div v-else-if="activeTab === 'purchase_orders'" class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
            <div class="bg-white border border-gray-200 rounded-xl p-5">
                <div class="flex items-center justify-between text-gray-500 text-xs font-semibold mb-2">
                    <span>Total Purchase Orders</span>
                    <ShoppingCart class="w-4 h-4 text-blue-500" />
                </div>
                <div class="text-2xl font-semibold text-gray-900">{{ metrics.totalPurchaseOrders || 0 }}</div>
                <div class="text-xs text-gray-500 mt-1">Issued vendor POs</div>
            </div>

            <div class="bg-white border border-gray-200 rounded-xl p-5">
                <div class="flex items-center justify-between text-gray-500 text-xs font-semibold mb-2">
                    <span>Total PO Amount</span>
                    <Receipt class="w-4 h-4 text-[#F8B803]" />
                </div>
                <div class="text-2xl font-semibold text-[#B45309]">{{ formatCurrency(metrics.totalPurchaseOrderAmount || 0) }}</div>
                <div class="text-xs text-gray-500 mt-1">Total PO commitment</div>
            </div>
        </div>

        <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="bg-white border border-gray-200 rounded-xl p-5">
                <div class="flex items-center justify-between text-gray-500 text-xs font-semibold mb-2">
                    <span>Total Requests</span>
                    <Package class="w-4 h-4 text-gray-400" />
                </div>
                <div class="text-2xl font-semibold text-gray-900">{{ metrics.totalRequests }}</div>
                <div class="text-xs text-gray-500 mt-1">All lifecycle stages</div>
            </div>

            <div class="bg-white border border-gray-200 rounded-xl p-5">
                <div class="flex items-center justify-between text-gray-500 text-xs font-semibold mb-2">
                    <span>Pending approval</span>
                    <Clock class="w-4 h-4 text-blue-500" />
                </div>
                <div class="text-2xl font-semibold text-blue-600">{{ metrics.requestedCount }}</div>
                <div class="text-xs text-gray-500 mt-1">Waiting for an administrator</div>
            </div>

            <div class="bg-white border border-gray-200 rounded-xl p-5">
                <div class="flex items-center justify-between text-gray-500 text-xs font-semibold mb-2">
                    <span>Approved</span>
                    <Truck class="w-4 h-4 text-[#F8B803]" />
                </div>
                <div class="text-2xl font-semibold text-gray-900">{{ metrics.orderedCount }}</div>
                <div class="text-xs text-gray-500 mt-1">Ready for a purchase order</div>
            </div>

            <div class="bg-white border border-gray-200 rounded-xl p-5">
                <div class="flex items-center justify-between text-gray-500 text-xs font-semibold mb-2">
                    <span>Converted</span>
                    <CheckCircle2 class="w-4 h-4 text-emerald-500" />
                </div>
                <div class="text-2xl font-semibold text-gray-900">{{ metrics.deliveredCount }}</div>
                <div class="text-xs text-gray-500 mt-1">Turned into purchase orders</div>
            </div>
        </div>

        <!-- Search & Filters Bar (Beside search bar) -->
        <div class="flex flex-col lg:flex-row items-stretch lg:items-center justify-between gap-3 mb-6 bg-white p-3 rounded-xl border border-gray-200">
            <!-- Search Bar -->
            <div class="relative flex-1 max-w-md">
                <Search class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400" />
                <input
                    v-model="search"
                    @input="applyFilter"
                    type="text"
                    :placeholder="
                        activeTab === 'commercial_invoices' 
                            ? 'Search by invoice #, description or supplier...'
                            : activeTab === 'purchase_orders'
                            ? 'Search by PO #, SKU, description or supplier...'
                            : 'Search by request #, product or supplier...'
                    "
                    class="w-full pl-10 pr-4 py-2 bg-gray-50/80 hover:bg-white focus:bg-white border border-gray-200 rounded-xl text-sm text-gray-900 focus:outline-none focus:border-[#F8B803] transition-colors"
                />
            </div>

            <!-- Filters beside search bar -->
            <div class="flex flex-wrap items-center gap-2.5">
                <!-- Status Filter (Tailored per tab) -->
                <div class="relative min-w-[140px]">
                    <select
                        v-model="selectedStatus"
                        @change="applyFilter"
                        class="w-full bg-gray-50/80 hover:bg-white border border-gray-200 rounded-xl px-3.5 py-2 text-xs font-semibold text-gray-700 focus:outline-none focus:border-[#F8B803] focus:bg-white cursor-pointer transition-colors"
                    >
                        <option value="All">All Statuses</option>
                        <option v-for="st in (statusOptions as any)[activeTab] ?? []" :key="st" :value="st">{{ st }}</option>
                    </select>
                </div>

                <!-- Supplier Filter (For all tabs) -->
                <div class="relative min-w-[150px]">
                    <select
                        v-model="selectedSupplier"
                        @change="applyFilter"
                        class="w-full bg-gray-50/80 hover:bg-white border border-gray-200 rounded-xl px-3.5 py-2 text-xs font-semibold text-gray-700 focus:outline-none focus:border-[#F8B803] focus:bg-white cursor-pointer transition-colors"
                    >
                        <option value="All">All Suppliers</option>
                        <option v-for="s in suppliers" :key="s.id" :value="String(s.id)">{{ s.name }}</option>
                    </select>
                </div>

                <!-- U/M Filter (Specifically for Purchase Orders) -->
                <div v-if="activeTab === 'purchase_orders'" class="relative min-w-[110px]">
                    <select
                        v-model="selectedUm"
                        @change="applyFilter"
                        class="w-full bg-gray-50/80 hover:bg-white border border-gray-200 rounded-xl px-3.5 py-2 text-xs font-semibold text-gray-700 focus:outline-none focus:border-[#F8B803] focus:bg-white cursor-pointer transition-colors"
                    >
                        <option value="All">All U/M</option>
                        <option value="PCS">PCS</option>
                        <option value="BOX">BOX</option>
                        <option value="MTR">MTR</option>
                        <option value="KG">KG</option>
                        <option value="SET">SET</option>
                        <option value="ROLL">ROLL</option>
                    </select>
                </div>

                <!-- Reset Filters Button -->
                <button
                    v-if="hasActiveFilters"
                    type="button"
                    @click="resetFilters"
                    class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-gray-600 hover:text-red-600 bg-gray-100 hover:bg-red-50 border border-gray-200 hover:border-red-200 rounded-xl transition-colors cursor-pointer"
                    title="Reset all search and filter conditions"
                >
                    <XCircle class="w-3.5 h-3.5" />
                    <span>Reset Filters</span>
                </button>
            </div>
        </div>

        <!-- ======================================================== -->
        <!-- TAB 1: COMMERCIAL INVOICES TABLE -->
        <!-- ======================================================== -->
        <div v-if="activeTab === 'commercial_invoices'" class="rounded-xl border border-gray-200 bg-white overflow-hidden flex flex-col">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-gray-50 text-xs text-gray-500 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-4 font-semibold text-gray-900 tracking-wider">DATE</th>
                            <th class="px-6 py-4 font-semibold text-gray-900 tracking-wider">COMMERCIAL INVOICE</th>
                            <th class="px-6 py-4 font-semibold text-gray-900 text-center tracking-wider">QUANTITY</th>
                            <th class="px-6 py-4 font-semibold text-gray-900 tracking-wider">Description</th>
                            <th class="px-6 py-4 font-semibold text-gray-900 text-right tracking-wider">UNIT COST</th>
                            <th class="px-6 py-4 font-semibold text-gray-900 text-right tracking-wider">TOTAL AMOUNT</th>
                            <th class="px-6 py-4 font-semibold text-gray-900 tracking-wider">PO / Delivery</th>
                            <th class="px-6 py-4 font-semibold text-gray-900 tracking-wider">Verification</th>
                            <th class="px-6 py-4 font-semibold text-gray-900 text-right tracking-wider">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-if="!commercialInvoices?.data?.length">
                            <td colspan="9" class="px-6 py-10 text-center text-gray-500">
                                <div class="max-w-md mx-auto space-y-2">
                                    <Receipt class="w-8 h-8 mx-auto text-gray-300" />
                                    <p class="font-medium text-gray-700">No commercial invoices found</p>
                                    <p class="text-xs text-gray-400">Record a supplier invoice from its purchase order (Purchase Orders → Open) after the delivery is inspected.</p>
                                </div>
                            </td>
                        </tr>
                        <tr v-for="ci in commercialInvoices?.data" :key="ci.id" class="hover:bg-gray-50/80 transition-colors">
                            <td class="px-6 py-4 text-gray-700 font-medium whitespace-nowrap">{{ formatDate(ci.invoice_date) }}</td>
                            <td class="px-6 py-4 font-mono font-semibold text-[#b45309] whitespace-nowrap">{{ ci.invoice_number }}</td>
                            <td class="px-6 py-4 text-center font-semibold text-gray-900">{{ ci.quantity }}</td>
                            <td class="px-6 py-4 text-gray-900 font-medium max-w-md truncate">{{ ci.description }}</td>
                            <td class="px-6 py-4 text-right font-medium text-gray-800 whitespace-nowrap">{{ formatCurrency(ci.unit_cost) }}</td>
                            <td class="px-6 py-4 text-right font-semibold text-gray-900 whitespace-nowrap">{{ formatCurrency(ci.total_cost) }}</td>
                            <td class="px-6 py-4 font-mono text-xs whitespace-nowrap">
                                <template v-if="ci.purchase_order">{{ ci.purchase_order.po_number }} / {{ ci.delivery?.delivery_number ?? '—' }}</template>
                                <span v-else class="font-sans text-amber-700">Not linked</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span :class="prStatusClass(ci.status)" class="rounded-full border px-2.5 py-0.5 text-xs font-medium">{{ ci.status }}</span>
                                <div v-if="ci.status !== 'Verified' && ci.discrepancies?.length" class="mt-1 text-xs text-rose-700">{{ ci.discrepancies.length }} discrepancy flag(s)</div>
                            </td>
                            <td class="px-6 py-4 text-right whitespace-nowrap">
                                <button v-if="ci.purchase_order_id" type="button" class="mr-1 inline-flex items-center px-2.5 py-1 text-xs font-medium border border-gray-200 rounded-lg hover:bg-gray-50" @click="workspacePoId = ci.purchase_order_id">
                                    {{ ['Pending Verification', 'Flagged'].includes(ci.status) ? 'Review & verify' : 'Open PO' }}
                                </button>
                                <button
                                    v-if="canDelete"
                                    type="button"
                                    @click="openDeleteCiModal(ci)"
                                    class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-red-600 hover:text-red-700 hover:bg-red-50 border border-red-200 rounded-lg transition-colors cursor-pointer"
                                    title="Delete invoice"
                                >
                                    <Trash2 class="w-3.5 h-3.5" />
                                    <span>Delete</span>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Commercial Invoices Pagination -->
            <div v-if="commercialInvoices && commercialInvoices.total > commercialInvoices.data.length" class="p-4 flex items-center justify-between border-t border-gray-100">
                <span class="text-xs text-gray-500">
                    Showing {{ commercialInvoices.data.length }} of {{ commercialInvoices.total }} commercial invoices
                </span>
                <div class="flex gap-1">
                    <button
                        v-for="(link, i) in commercialInvoices.links"
                        :key="i"
                        :disabled="!link.url || link.active"
                        @click="link.url && router.visit(link.url, { preserveScroll: true })"
                        v-html="link.label"
                        :class="[
                            'px-3 py-1 text-xs rounded-lg border transition-colors',
                            link.active ? 'bg-[#F8B803] text-gray-900 border-[#F8B803] font-semibold' : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50',
                            !link.url ? 'opacity-50 cursor-not-allowed' : ''
                        ]"
                    />
                </div>
            </div>
        </div>

        <!-- ======================================================== -->
        <!-- TAB 2: PURCHASE ORDERS TABLE -->
        <!-- ======================================================== -->
        <div v-else-if="activeTab === 'purchase_orders'" class="rounded-xl border border-gray-200 bg-white overflow-hidden flex flex-col">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-gray-50 text-xs text-gray-500 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-4 font-semibold text-gray-900 tracking-wider">Date</th>
                            <th class="px-6 py-4 font-semibold text-gray-900 tracking-wider">Purchase Order No.</th>
                            <th class="px-6 py-4 font-semibold text-gray-900 text-center tracking-wider">Quantity</th>
                            <th class="px-6 py-4 font-semibold text-gray-900 tracking-wider">ITEM/SKU Number</th>
                            <th class="px-6 py-4 font-semibold text-gray-900 tracking-wider">Description</th>
                            <th class="px-6 py-4 font-semibold text-gray-900 text-center tracking-wider">U/M</th>
                            <th class="px-6 py-4 font-semibold text-gray-900 text-right tracking-wider">Unit Cost</th>
                            <th class="px-6 py-4 font-semibold text-gray-900 text-right tracking-wider">Total Amount</th>
                            <th class="px-6 py-4 font-semibold text-gray-900 tracking-wider">Promised Delivery</th>
                            <th class="px-6 py-4 font-semibold text-gray-900 tracking-wider">Request</th>
                            <th class="px-6 py-4 font-semibold text-gray-900 tracking-wider">Status</th>
                            <th class="px-6 py-4 font-semibold text-gray-900 text-right tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-if="!purchaseOrders?.data?.length">
                            <td colspan="12" class="px-6 py-10 text-center text-gray-500">
                                <div class="max-w-md mx-auto space-y-2">
                                    <ShoppingCart class="w-8 h-8 mx-auto text-gray-300" />
                                    <p class="font-medium text-gray-700">No purchase orders found</p>
                                    <p class="text-xs text-gray-400">Purchase orders are prepared from approved purchase requests (Purchase Requests tab).</p>
                                </div>
                            </td>
                        </tr>
                        <tr v-for="po in purchaseOrders?.data" :key="po.id" class="hover:bg-gray-50/80 transition-colors">
                            <td class="px-6 py-4 text-gray-700 font-medium whitespace-nowrap">{{ formatDate(po.po_date) }}</td>
                            <td class="px-6 py-4 font-mono font-semibold text-blue-700 whitespace-nowrap">{{ po.po_number }}</td>
                            <td class="px-6 py-4 text-center font-semibold text-gray-900">{{ po.quantity }}</td>
                            <td class="px-6 py-4 font-mono text-xs font-semibold text-gray-800 whitespace-nowrap">{{ po.item_sku || '—' }}</td>
                            <td class="px-6 py-4 text-gray-900 font-medium max-w-sm truncate">{{ po.description }}</td>
                            <td class="px-6 py-4 text-center text-xs font-semibold text-gray-700">{{ po.unit_of_measure || 'PCS' }}</td>
                            <td class="px-6 py-4 text-right font-medium text-gray-800 whitespace-nowrap">{{ formatCurrency(po.unit_cost) }}</td>
                            <td class="px-6 py-4 text-right font-semibold text-gray-900 whitespace-nowrap">{{ formatCurrency(po.total_cost) }}</td>
                            <td class="px-6 py-4 text-xs whitespace-nowrap">
                                <span v-if="po.promised_delivery_date">{{ formatDate(po.promised_delivery_date) }}</span>
                                <span v-else class="text-amber-700">Not set</span>
                                <div v-if="!po.product_id" class="text-amber-700">No product linked</div>
                            </td>
                            <td class="px-6 py-4 font-mono text-xs whitespace-nowrap">{{ po.purchase_request?.request_number ?? '—' }}</td>
                            <td class="px-6 py-4 whitespace-nowrap"><span :class="prStatusClass(po.status)" class="rounded-full border px-2.5 py-0.5 text-xs font-medium">{{ po.status }}</span></td>
                            <td class="px-6 py-4 text-right whitespace-nowrap">
                                <button type="button" class="mr-1 inline-flex items-center px-2.5 py-1 text-xs font-medium border border-[#4b5320] text-[#4b5320] rounded-lg hover:bg-gray-50" @click="workspacePoId = po.id">Open</button>
                                <button type="button" class="mr-1 inline-flex items-center px-2.5 py-1 text-xs font-medium border border-gray-200 rounded-lg hover:bg-gray-50" @click="openSchedule(po)">Product &amp; date</button>
                                <button
                                    v-if="canDelete"
                                    type="button"
                                    @click="openDeletePoModal(po)"
                                    class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-red-600 hover:text-red-700 hover:bg-red-50 border border-red-200 rounded-lg transition-colors cursor-pointer"
                                    title="Delete order"
                                >
                                    <Trash2 class="w-3.5 h-3.5" />
                                    <span>Delete</span>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Purchase Orders Pagination -->
            <div v-if="purchaseOrders && purchaseOrders.total > purchaseOrders.data.length" class="p-4 flex items-center justify-between border-t border-gray-100">
                <span class="text-xs text-gray-500">
                    Showing {{ purchaseOrders.data.length }} of {{ purchaseOrders.total }} purchase orders
                </span>
                <div class="flex gap-1">
                    <button
                        v-for="(link, i) in purchaseOrders.links"
                        :key="i"
                        :disabled="!link.url || link.active"
                        @click="link.url && router.visit(link.url, { preserveScroll: true })"
                        v-html="link.label"
                        :class="[
                            'px-3 py-1 text-xs rounded-lg border transition-colors',
                            link.active ? 'bg-[#F8B803] text-gray-900 border-[#F8B803] font-semibold' : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50',
                            !link.url ? 'opacity-50 cursor-not-allowed' : ''
                        ]"
                    />
                </div>
            </div>
        </div>

        <!-- ======================================================== -->
        <!-- TAB 3: PURCHASE REQUESTS TABLE -->
        <!-- ======================================================== -->
        <div v-else class="rounded-xl border border-gray-200 bg-white overflow-hidden flex flex-col">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-gray-50 text-xs text-gray-500 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-4 font-medium">Request ID</th>
                            <th class="px-6 py-4 font-medium">Item Name</th>
                            <th class="px-6 py-4 font-medium">Supplier</th>
                            <th class="px-6 py-4 font-medium text-center">Requested / Recommended</th>
                            <th class="px-6 py-4 font-medium">Stock · Forecast · AHP</th>
                            <th class="px-6 py-4 font-medium">Submitted</th>
                            <th class="px-6 py-4 font-medium text-center">Status</th>
                            <th class="px-6 py-4 font-medium text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-if="!requests.data.length">
                            <td colspan="8" class="px-6 py-8 text-center text-gray-500">
                                No purchase requests found. Create them from Inventory → AHP Product Prioritization, or with "New Purchase Request".
                            </td>
                        </tr>
                        <tr v-for="req in requests.data" :key="req.id" class="hover:bg-gray-50 transition-colors align-top">
                            <td class="px-6 py-4 font-mono font-semibold text-[#b45309]">{{ req.request_number }}</td>
                            <td class="px-6 py-4">
                                <div class="font-semibold text-gray-900">{{ req.product?.name || '—' }}</div>
                                <div v-if="req.justification" class="max-w-xs text-xs text-gray-500">{{ req.justification }}</div>
                                <div class="text-xs text-gray-400">by {{ req.requested_by?.name ?? '—' }}</div>
                            </td>
                            <td class="px-6 py-4 text-gray-600">{{ req.supplier?.name || '—' }}</td>
                            <td class="px-6 py-4 text-center font-mono">
                                <span class="font-semibold text-gray-900">{{ req.quantity }}</span> / {{ req.recommended_quantity ?? '—' }}
                                <div class="text-xs text-gray-400">{{ formatCurrency(Number(req.estimated_cost)) }}</div>
                            </td>
                            <td class="px-6 py-4 font-mono text-xs text-gray-600">
                                {{ req.current_stock ?? '—' }} · {{ req.forecast_quantity ?? 'n/a' }} · {{ req.ahp_score != null ? Number(req.ahp_score).toFixed(2) : 'incomplete' }}
                                <div class="font-sans text-gray-400">{{ req.ahp_classification ?? '' }}</div>
                            </td>
                            <td class="px-6 py-4 text-gray-500 text-xs">{{ req.submitted_at ? formatDate(req.submitted_at) : 'Draft' }}</td>
                            <td class="px-6 py-4 text-center">
                                <span :class="prStatusClass(req.status)" class="rounded-full border px-2.5 py-0.5 text-xs font-medium">{{ req.status }}</span>
                                <div v-if="req.reviewed_by" class="mt-1 text-xs text-gray-500">{{ req.reviewed_by.name }} · {{ formatDate(req.reviewed_at ?? '') }}</div>
                                <div v-if="req.review_remarks" class="max-w-[12rem] text-xs text-gray-500">{{ req.review_remarks }}</div>
                                <div v-if="req.purchase_order" class="mt-1 font-mono text-xs text-blue-700">PO {{ req.purchase_order.po_number }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-2">
                                    <button v-if="req.status === 'Draft'" type="button" class="inline-flex items-center px-2.5 py-1 text-xs font-medium border border-gray-200 rounded-lg hover:bg-gray-50" @click="submitDraftRequest(req)">Submit</button>
                                    <button v-if="isAdmin && req.status === 'Pending Approval'" type="button" class="inline-flex items-center px-2.5 py-1 text-xs font-medium border border-[#4b5320] text-[#4b5320] rounded-lg hover:bg-gray-50" @click="openReview(req)">Review</button>
                                    <button v-if="req.purchase_order" type="button" class="inline-flex items-center px-2.5 py-1 text-xs font-medium border border-gray-200 rounded-lg hover:bg-gray-50" @click="workspacePoId = req.purchase_order.id">Open PO</button>
                                    <button
                                        v-if="canDelete && ['Draft', 'Pending Approval', 'Rejected'].includes(req.status)"
                                        type="button"
                                        @click="openDeleteModal(req)"
                                        class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-red-600 hover:text-red-700 hover:bg-red-50 border border-red-200 rounded-lg transition-colors cursor-pointer"
                                        title="Delete purchase request"
                                    >
                                        <Trash2 class="w-3.5 h-3.5" />
                                        <span>Delete</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="requests.total > requests.data.length" class="p-4 flex items-center justify-between border-t border-gray-100">
                <span class="text-xs text-gray-500">
                    Showing {{ requests.data.length }} of {{ requests.total }} requests
                </span>
                <div class="flex gap-1">
                    <button
                        v-for="(link, i) in requests.links"
                        :key="i"
                        :disabled="!link.url || link.active"
                        @click="link.url && router.visit(link.url, { preserveScroll: true })"
                        v-html="link.label"
                        :class="[
                            'px-3 py-1 text-xs rounded-lg border transition-colors',
                            link.active ? 'bg-[#F8B803] text-white border-[#F8B803] font-semibold' : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50',
                            !link.url ? 'opacity-50 cursor-not-allowed' : ''
                        ]"
                    />
                </div>
            </div>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- MODAL: IMPORT COMMERCIAL INVOICES CSV -->
    <!-- ======================================================== -->
    <div v-if="showCiImportModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-2xl shadow-xl max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-4 border-b border-gray-100 pb-3">
                <div>
                    <h3 class="text-xl font-semibold text-gray-900">Import Commercial Invoices CSV</h3>
                    <p class="text-xs text-gray-500">Headers: <code class="bg-gray-100 px-1 py-0.5 rounded font-semibold text-gray-800">DATE, COMMERCIAL INVOICE, QUANTITY, Description, UNIT COST</code></p>
                </div>
                <button @click="showCiImportModal = false" class="text-gray-400 hover:text-gray-600 text-xl cursor-pointer">&times;</button>
            </div>

            <!-- Upload Area & Sample Template -->
            <div class="flex flex-col sm:flex-row gap-4 items-center justify-between p-4 bg-amber-50/60 border border-amber-100 rounded-xl mb-4">
                <div>
                    <h4 class="text-sm font-semibold text-gray-900">Need the template?</h4>
                    <p class="text-xs text-gray-600">Sample CI format: <code class="font-mono text-amber-800 font-semibold">CI-035-0722</code></p>
                </div>
                <button
                    type="button"
                    @click="downloadSampleCiCsv"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-white border border-amber-200 text-amber-900 hover:bg-amber-50 cursor-pointer whitespace-nowrap"
                >
                    <Download class="w-3.5 h-3.5" />
                    Download Sample CSV
                </button>
            </div>

            <!-- File Input -->
            <div class="mb-4">
                <label class="block text-xs font-semibold text-gray-700 mb-2">Select CSV File (.csv, .txt)</label>
                <input
                    type="file"
                    accept=".csv,.txt"
                    @change="handleCiFileChange"
                    class="w-full text-xs text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-[#F8B803] file:text-gray-900 hover:file:opacity-90 cursor-pointer border border-gray-200 rounded-xl p-2"
                />
            </div>

            <!-- Loading State -->
            <div v-if="isValidatingCiCsv" class="py-6 text-center text-gray-500 text-xs">
                <Clock class="w-5 h-5 mx-auto animate-spin mb-1 text-[#F8B803]" />
                Validating CSV rows...
            </div>

            <!-- Error Message -->
            <div v-if="ciCsvError" class="p-3 mb-4 rounded-xl bg-red-50 border border-red-200 text-xs text-red-700 flex items-start gap-2">
                <AlertCircle class="w-4 h-4 shrink-0 text-red-500 mt-0.5" />
                <span>{{ ciCsvError }}</span>
            </div>

            <!-- Preview Results -->
            <div v-if="ciCsvValidationResult" class="space-y-4">
                <div class="flex gap-4 p-3 bg-gray-50 rounded-xl border border-gray-100 text-xs">
                    <div>
                        <span class="text-gray-500">Total Rows:</span>
                        <span class="ml-1 font-semibold text-gray-900">{{ ciCsvValidationResult.summary.total_rows }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500">Valid:</span>
                        <span class="ml-1 font-semibold text-emerald-600">{{ ciCsvValidationResult.summary.valid_count }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500">Invalid:</span>
                        <span class="ml-1 font-semibold text-red-600">{{ ciCsvValidationResult.summary.invalid_count }}</span>
                    </div>
                </div>

                <!-- Preview Table -->
                <div v-if="ciCsvValidationResult.valid_rows?.length" class="max-h-60 overflow-y-auto border border-gray-200 rounded-xl">
                    <table class="w-full text-left text-xs text-gray-600">
                        <thead class="bg-gray-50 font-semibold text-gray-700 border-b border-gray-200 sticky top-0">
                            <tr>
                                <th class="p-2">DATE</th>
                                <th class="p-2">COMMERCIAL INVOICE</th>
                                <th class="p-2 text-center">QUANTITY</th>
                                <th class="p-2">Description</th>
                                <th class="p-2 text-right">UNIT COST</th>
                                <th class="p-2 text-right">TOTAL</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="r in ciCsvValidationResult.valid_rows.slice(0, 10)" :key="r.row_number">
                                <td class="p-2 whitespace-nowrap">{{ r.display_date || r.date }}</td>
                                <td class="p-2 font-mono font-semibold text-amber-700 whitespace-nowrap">{{ r.invoice_number }}</td>
                                <td class="p-2 text-center font-semibold">{{ r.quantity }}</td>
                                <td class="p-2 max-w-xs truncate">{{ r.description }}</td>
                                <td class="p-2 text-right whitespace-nowrap">{{ formatCurrency(r.unit_cost) }}</td>
                                <td class="p-2 text-right font-semibold whitespace-nowrap">{{ formatCurrency(r.total_cost) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="flex justify-end gap-3 mt-6 pt-3 border-t border-gray-100">
                <SpecularButton
                    type="button"
                    variant="secondary"
                    size="md"
                    :radius="10"
                    @click="showCiImportModal = false"
                >
                    Cancel
                </SpecularButton>
                <SpecularButton
                    type="button"
                    variant="brand"
                    size="md"
                    :radius="10"
                    :disabled="isImportingCiCsv || !ciCsvValidationResult || ciCsvValidationResult.summary?.valid_count === 0"
                    @click="executeCiCsvImport"
                >
                    {{ isImportingCiCsv ? 'Importing...' : `Import ${ciCsvValidationResult?.summary?.valid_count || ''} Invoices` }}
                </SpecularButton>
            </div>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- MODAL: IMPORT PURCHASE ORDERS CSV -->
    <!-- ======================================================== -->
    <div v-if="showPoImportModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-2xl shadow-xl max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-4 border-b border-gray-100 pb-3">
                <div>
                    <h3 class="text-xl font-semibold text-gray-900">Import Purchase Orders CSV</h3>
                    <p class="text-xs text-gray-500">Headers: <code class="bg-gray-100 px-1 py-0.5 rounded font-semibold text-gray-800">Date, Purchase Order No., Quantity, ITEM/SKU Number, Description, U/M, Unit Cost</code></p>
                </div>
                <button @click="showPoImportModal = false" class="text-gray-400 hover:text-gray-600 text-xl cursor-pointer">&times;</button>
            </div>

            <!-- Upload Area & Sample Template -->
            <div class="flex flex-col sm:flex-row gap-4 items-center justify-between p-4 bg-blue-50/60 border border-blue-100 rounded-xl mb-4">
                <div>
                    <h4 class="text-sm font-semibold text-gray-900">Need the template?</h4>
                    <p class="text-xs text-gray-600">Sample PO No: <code class="font-mono text-blue-800 font-semibold">4000005472</code></p>
                </div>
                <button
                    type="button"
                    @click="downloadSamplePoCsv"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-white border border-blue-200 text-blue-900 hover:bg-blue-50 cursor-pointer whitespace-nowrap"
                >
                    <Download class="w-3.5 h-3.5" />
                    Download Sample CSV
                </button>
            </div>

            <!-- File Input -->
            <div class="mb-4">
                <label class="block text-xs font-semibold text-gray-700 mb-2">Select CSV File (.csv, .txt)</label>
                <input
                    type="file"
                    accept=".csv,.txt"
                    @change="handlePoFileChange"
                    class="w-full text-xs text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-[#F8B803] file:text-gray-900 hover:file:opacity-90 cursor-pointer border border-gray-200 rounded-xl p-2"
                />
            </div>

            <!-- Loading State -->
            <div v-if="isValidatingPoCsv" class="py-6 text-center text-gray-500 text-xs">
                <Clock class="w-5 h-5 mx-auto animate-spin mb-1 text-[#F8B803]" />
                Validating CSV rows...
            </div>

            <!-- Error Message -->
            <div v-if="poCsvError" class="p-3 mb-4 rounded-xl bg-red-50 border border-red-200 text-xs text-red-700 flex items-start gap-2">
                <AlertCircle class="w-4 h-4 shrink-0 text-red-500 mt-0.5" />
                <span>{{ poCsvError }}</span>
            </div>

            <!-- Preview Results -->
            <div v-if="poCsvValidationResult" class="space-y-4">
                <div class="flex gap-4 p-3 bg-gray-50 rounded-xl border border-gray-100 text-xs">
                    <div>
                        <span class="text-gray-500">Total Rows:</span>
                        <span class="ml-1 font-semibold text-gray-900">{{ poCsvValidationResult.summary.total_rows }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500">Valid:</span>
                        <span class="ml-1 font-semibold text-emerald-600">{{ poCsvValidationResult.summary.valid_count }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500">Invalid:</span>
                        <span class="ml-1 font-semibold text-red-600">{{ poCsvValidationResult.summary.invalid_count }}</span>
                    </div>
                </div>

                <!-- Preview Table -->
                <div v-if="poCsvValidationResult.valid_rows?.length" class="max-h-60 overflow-y-auto border border-gray-200 rounded-xl">
                    <table class="w-full text-left text-xs text-gray-600">
                        <thead class="bg-gray-50 font-semibold text-gray-700 border-b border-gray-200 sticky top-0">
                            <tr>
                                <th class="p-2">Date</th>
                                <th class="p-2">Purchase Order No.</th>
                                <th class="p-2 text-center">Quantity</th>
                                <th class="p-2">ITEM/SKU</th>
                                <th class="p-2">Description</th>
                                <th class="p-2 text-center">U/M</th>
                                <th class="p-2 text-right">Unit Cost</th>
                                <th class="p-2 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="r in poCsvValidationResult.valid_rows.slice(0, 10)" :key="r.row_number">
                                <td class="p-2 whitespace-nowrap">{{ r.display_date || r.date }}</td>
                                <td class="p-2 font-mono font-semibold text-blue-700 whitespace-nowrap">{{ r.po_number }}</td>
                                <td class="p-2 text-center font-semibold">{{ r.quantity }}</td>
                                <td class="p-2 font-mono text-xs">{{ r.item_sku || '—' }}</td>
                                <td class="p-2 max-w-xs truncate">{{ r.description }}</td>
                                <td class="p-2 text-center font-semibold">{{ r.unit_of_measure }}</td>
                                <td class="p-2 text-right whitespace-nowrap">{{ formatCurrency(r.unit_cost) }}</td>
                                <td class="p-2 text-right font-semibold whitespace-nowrap">{{ formatCurrency(r.total_cost) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="flex justify-end gap-3 mt-6 pt-3 border-t border-gray-100">
                <SpecularButton
                    type="button"
                    variant="secondary"
                    size="md"
                    :radius="10"
                    @click="showPoImportModal = false"
                >
                    Cancel
                </SpecularButton>
                <SpecularButton
                    type="button"
                    variant="brand"
                    size="md"
                    :radius="10"
                    :disabled="isImportingPoCsv || !poCsvValidationResult || poCsvValidationResult.summary?.valid_count === 0"
                    @click="executePoCsvImport"
                >
                    {{ isImportingPoCsv ? 'Importing...' : `Import ${poCsvValidationResult?.summary?.valid_count || ''} Purchase Orders` }}
                </SpecularButton>
            </div>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- MODAL: DELETE COMMERCIAL INVOICE -->
    <!-- ======================================================== -->
    <div v-if="showDeleteCiModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-md shadow-xl">
            <h3 class="text-xl font-semibold text-gray-900 mb-2">Delete Commercial Invoice</h3>
            <p class="text-gray-600 text-sm mb-4">
                Are you sure you want to delete commercial invoice <span class="font-semibold text-gray-900">{{ selectedCi?.invoice_number }}</span>?
            </p>
            <div class="mt-4 mb-6">
                <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                    Type <span class="font-mono font-semibold text-rose-600 bg-rose-50 px-1.5 py-0.5 rounded border border-rose-200">YES</span> if you wish to delete:
                </label>
                <input
                    v-model="deleteCiConfirmInput"
                    type="text"
                    placeholder="Type YES here"
                    class="w-full px-3 py-2 text-sm font-mono rounded-xl border border-gray-300 focus:outline-none focus:border-rose-500 focus:ring-1 focus:ring-rose-500"
                />
            </div>
            <div class="flex justify-end gap-3">
                <SpecularButton
                    variant="secondary"
                    size="md"
                    :radius="10"
                    @click="showDeleteCiModal = false"
                >
                    Cancel
                </SpecularButton>
                <SpecularButton
                    variant="destructive"
                    size="md"
                    :radius="10"
                    @click="confirmDeleteCi"
                    :disabled="deleteCiConfirmInput.trim().toUpperCase() !== 'YES'"
                >
                    Delete Invoice
                </SpecularButton>
            </div>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- MODAL: DELETE PURCHASE ORDER -->
    <!-- ======================================================== -->
    <div v-if="showDeletePoModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-md shadow-xl">
            <h3 class="text-xl font-semibold text-gray-900 mb-2">Delete Purchase Order</h3>
            <p class="text-gray-600 text-sm mb-4">
                Are you sure you want to delete purchase order <span class="font-semibold text-gray-900">{{ selectedPo?.po_number }}</span>?
            </p>
            <div class="mt-4 mb-6">
                <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                    Type <span class="font-mono font-semibold text-rose-600 bg-rose-50 px-1.5 py-0.5 rounded border border-rose-200">YES</span> if you wish to delete:
                </label>
                <input
                    v-model="deletePoConfirmInput"
                    type="text"
                    placeholder="Type YES here"
                    class="w-full px-3 py-2 text-sm font-mono rounded-xl border border-gray-300 focus:outline-none focus:border-rose-500 focus:ring-1 focus:ring-rose-500"
                />
            </div>
            <div class="flex justify-end gap-3">
                <SpecularButton
                    variant="secondary"
                    size="md"
                    :radius="10"
                    @click="showDeletePoModal = false"
                >
                    Cancel
                </SpecularButton>
                <SpecularButton
                    variant="destructive"
                    size="md"
                    :radius="10"
                    @click="confirmDeletePo"
                    :disabled="deletePoConfirmInput.trim().toUpperCase() !== 'YES'"
                >
                    Delete Order
                </SpecularButton>
            </div>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- MODAL: DELETE PURCHASE REQUEST -->
    <!-- ======================================================== -->
    <div v-if="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-md shadow-xl">
            <h3 class="text-xl font-semibold text-gray-900 mb-2">Delete Purchase Request</h3>
            <p class="text-gray-600 text-sm mb-4">Are you sure you want to cancel and delete request <span class="font-semibold text-gray-900">{{ selectedRequest?.request_number }}</span>?</p>
            <div class="mt-4 mb-6">
                <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                    Type <span class="font-mono font-semibold text-rose-600 bg-rose-50 px-1.5 py-0.5 rounded border border-rose-200">YES</span> if you wish to delete:
                </label>
                <input
                    v-model="deleteConfirmInput"
                    type="text"
                    placeholder="Type YES here"
                    class="w-full px-3 py-2 text-sm font-mono rounded-xl border border-gray-300 focus:outline-none focus:border-rose-500 focus:ring-1 focus:ring-rose-500"
                />
            </div>
            <div class="flex justify-end gap-3">
                <SpecularButton
                    variant="secondary"
                    size="md"
                    :radius="10"
                    @click="showDeleteModal = false"
                >
                    Cancel
                </SpecularButton>
                <SpecularButton
                    variant="destructive"
                    size="md"
                    :radius="10"
                    @click="confirmDelete"
                    :disabled="deleteConfirmInput.trim().toUpperCase() !== 'YES'"
                >
                    Delete Request
                </SpecularButton>
            </div>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- MODAL: ACTION CONFIRMATION -->
    <!-- ======================================================== -->
    <div v-if="showConfirmModal" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-md shadow-xl animate-in fade-in duration-150">
            <div class="w-11 h-11 rounded-full bg-amber-50 text-[#F8B803] flex items-center justify-center mb-4 border border-amber-100">
                <CheckCircle2 class="w-6 h-6" />
            </div>
            <h3 class="text-xl font-semibold text-gray-900 mb-1.5">{{ confirmOptions.title }}</h3>
            <p class="text-gray-600 text-sm mb-4 leading-relaxed">
                {{ confirmOptions.description }}
            </p>

            <div v-if="confirmOptions.details && confirmOptions.details.length > 0" class="bg-gray-50 border border-gray-100 rounded-xl p-3.5 mb-5 space-y-2 text-xs">
                <div v-for="(item, idx) in confirmOptions.details" :key="idx" class="flex justify-between items-center py-0.5 border-b border-gray-100 last:border-b-0">
                    <span class="text-gray-500 font-medium">{{ item.label }}</span>
                    <span class="font-semibold text-gray-900 text-right">{{ item.value }}</span>
                </div>
            </div>

            <div class="flex justify-end gap-3">
                <SpecularButton
                    type="button"
                    variant="secondary"
                    size="md"
                    :radius="10"
                    @click="showConfirmModal = false"
                >
                    Cancel
                </SpecularButton>
                <SpecularButton
                    type="button"
                    variant="brand"
                    size="md"
                    :radius="10"
                    @click="handleConfirmedAction"
                    :disabled="actionBusy"
                >
                    <Check class="w-4 h-4 mr-1.5 inline" />
                    <span>{{ confirmOptions.confirmText || 'Confirm & Submit' }}</span>
                </SpecularButton>
            </div>
        </div>
    </div>
    <!-- Workflow: new request product picker → request creator -->
    <div v-if="showProductPicker" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="showProductPicker = false">
        <div class="w-full max-w-md rounded-xl border border-gray-200 bg-white p-6" role="dialog" aria-modal="true" aria-labelledby="pick-title">
            <h3 id="pick-title" class="text-base font-semibold text-gray-900">New purchase request</h3>
            <p class="mb-3 text-xs text-gray-500">Tip: Inventory → AHP Product Prioritization lists the products the system recommends reordering.</p>
            <label class="field-label" for="pick-product">Product</label>
            <select id="pick-product" v-model="pickedProductId" class="field-control w-full">
                <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }} ({{ p.sku }}) · stock {{ p.stock }}</option>
            </select>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" class="secondary-button" @click="showProductPicker = false">Cancel</button>
                <button type="button" class="brand-button" :disabled="!pickedProductId" @click="continueNewRequest">Continue</button>
            </div>
        </div>
    </div>
    <PurchaseRequestCreator :open="showPrCreator" :candidates="prCandidates" @close="showPrCreator = false" @submitted="showPrCreator = false; showToast('Purchase request saved.')" />

    <!-- Workflow: admin review -->
    <div v-if="reviewing" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="reviewing = null">
        <div class="w-full max-w-lg rounded-xl border border-gray-200 bg-white p-6" role="dialog" aria-modal="true" aria-labelledby="review-title">
            <h3 id="review-title" class="text-base font-semibold text-gray-900">Review {{ reviewing.request_number }}</h3>
            <dl class="my-3 grid grid-cols-2 gap-2 text-sm">
                <div><dt class="text-xs text-gray-500">Product</dt><dd>{{ reviewing.product?.name }}</dd></div>
                <div><dt class="text-xs text-gray-500">Requested by</dt><dd>{{ reviewing.requested_by?.name ?? '—' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Requested / recommended</dt><dd class="font-mono">{{ reviewing.quantity }} / {{ reviewing.recommended_quantity ?? '—' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Stock at request · SMA forecast</dt><dd class="font-mono">{{ reviewing.current_stock ?? '—' }} · {{ reviewing.forecast_quantity ?? 'n/a' }}</dd></div>
                <div><dt class="text-xs text-gray-500">AHP score</dt><dd class="font-mono">{{ reviewing.ahp_score != null ? Number(reviewing.ahp_score).toFixed(2) : 'Incomplete data' }} {{ reviewing.ahp_classification ?? '' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Estimated cost</dt><dd>{{ formatCurrency(Number(reviewing.estimated_cost)) }}</dd></div>
                <div class="col-span-2"><dt class="text-xs text-gray-500">Justification</dt><dd>{{ reviewing.justification || '—' }}</dd></div>
            </dl>
            <label class="field-label" for="review-remarks">Remarks (required to reject)</label>
            <textarea id="review-remarks" v-model="reviewForm.remarks" rows="2" class="field-control w-full"></textarea>
            <p v-for="(e, k) in reviewForm.errors" :key="k" class="mt-1 text-xs text-rose-600">{{ e }}</p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" class="secondary-button" @click="reviewing = null">Close</button>
                <button type="button" class="secondary-button text-rose-700" :disabled="reviewForm.processing" @click="submitReview('reject')">Reject</button>
                <button type="button" class="brand-button" :disabled="reviewForm.processing" @click="submitReview('approve')">Approve</button>
            </div>
        </div>
    </div>


    <PurchaseOrderWorkspace :po-id="workspacePoId" :is-admin="isAdmin" @close="closeWorkspace" />

    <!-- Purchase order product and promised date -->
    <div v-if="schedulePo" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="schedulePo = null">
        <form class="w-full max-w-md rounded-xl border border-gray-200 bg-white p-6" role="dialog" aria-modal="true" aria-labelledby="po-schedule-title" @submit.prevent="submitSchedule">
            <h3 id="po-schedule-title" class="text-lg font-semibold text-gray-900">Purchase order {{ schedulePo.po_number }}</h3>
            <p class="mt-1 text-sm text-gray-500">Supplier Evaluation compares the promised date with the actual delivery date.</p>
            <div class="mt-4">
                <label class="field-label" for="sch-product">Product</label>
                <select id="sch-product" v-model="scheduleForm.product_id" class="field-control">
                    <option :value="null">Not linked</option>
                    <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }} ({{ p.sku }})</option>
                </select>
            </div>
            <div class="mt-3">
                <label class="field-label" for="sch-date">Promised delivery date</label>
                <input id="sch-date" v-model="scheduleForm.promised_delivery_date" type="date" :min="schedulePo.po_date.slice(0, 10)" class="field-control" />
                <p v-if="schedulePo.deliveries_count" class="mt-1 text-xs text-amber-700">Goods were already received against this order; only an administrator can change the date.</p>
                <p v-if="scheduleForm.errors.promised_delivery_date" class="mt-1 text-xs text-red-500">{{ scheduleForm.errors.promised_delivery_date }}</p>
            </div>
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" class="secondary-button" @click="schedulePo = null">Cancel</button>
                <button type="submit" class="brand-button" :disabled="scheduleForm.processing">Save</button>
            </div>
        </form>
    </div>
</template>
