<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import SpecularButton from '@/components/ui/SpecularButton.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useCanDelete } from '@/composables/useCanDelete';
import { useCurrency } from '@/composables/useCurrency';
import {
    Search, 
    Plus, 
    Printer, 
    Receipt, 
    ShoppingCart, 
    TrendingUp, 
    Calendar, 
    CheckCircle2, 
    Check,
    Clock, 
    Trash2, 
    X,
    CreditCard,
    Building2,
    Smartphone,
    Upload,
    FileText,
    ExternalLink,
    Truck,
    ShieldCheck,
    AlertTriangle
} from '@lucide/vue';

interface SaleItem {
    id: number;
    sku: string;
    product_name: string;
    quantity: number;
    unit_price: number;
    subtotal: number;
}

interface Sale {
    id: number;
    order_number: string;
    channel: 'quotation' | 'counter';
    fulfillment_type: 'delivery' | 'pickup';
    invoice_number?: string | null;
    client_name: string;
    sale_date: string;
    grand_total: number;
    amount_paid?: number;
    outstanding_balance?: number;
    status: string;
    status_label?: string;
    payment_status?: string;
    payment_status_label?: string;
    payment_terms?: string;
    is_on_hold?: boolean;
    delivery_status_label?: string | null;
    quotation?: { id: number; quotation_number: string } | null;
    fulfillment?: { id: number; status: string; fulfillment_number: string } | null;
    items?: SaleItem[];
}

type Option = { value: string; label: string };
interface ProductItem {
    id: number;
    sku: string;
    name: string;
    price: number;
    stock: number;
    category: string;
}

const props = defineProps<{
    sales: {
        data: Sale[];
        current_page: number;
        last_page: number;
        total: number;
        links: any[];
    };
    filters?: {
        search?: string;
        status?: string;
        payment_status?: string;
        delivery_status?: string;
        date_from?: string | null;
        date_to?: string | null;
        sort?: string;
    };
    metrics: {
        totalSales: number;
        todaySales: number;
        monthSales: number;
        pendingPayments: number;
        toVerify: number;
        outstanding: number;
    };
    products: ProductItem[];
    options: { status: Option[]; payment_status: Option[]; delivery_status: Option[] };
}>();

const page = usePage();
const canDelete = useCanDelete();
const teamSlug = computed(() => (page.props as any).currentTeam?.slug || (page.props as any).team?.slug || '');

// Stored amounts are USD; shown in the user's display currency (header switch).
const { formatMoney: formatCurrency, conversionNote } = useCurrency();

// Search & Filter
const search = ref(props.filters?.search || '');
const selectedStatus = ref(props.filters?.status || 'All');
const selectedPayment = ref(props.filters?.payment_status || 'All');
const selectedDelivery = ref(props.filters?.delivery_status || 'All');
const dateFrom = ref(props.filters?.date_from || '');
const dateTo = ref(props.filters?.date_to || '');
const sort = ref(props.filters?.sort || 'newest');

let searchTimeout: ReturnType<typeof setTimeout> | null = null;
const applyFilter = () => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(
            `/${teamSlug.value}/sales`,
            {
                search: search.value || undefined,
                status: selectedStatus.value !== 'All' ? selectedStatus.value : undefined,
                payment_status: selectedPayment.value !== 'All' ? selectedPayment.value : undefined,
                delivery_status: selectedDelivery.value !== 'All' ? selectedDelivery.value : undefined,
                date_from: dateFrom.value || undefined,
                date_to: dateTo.value || undefined,
                sort: sort.value !== 'newest' ? sort.value : undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true }
        );
    }, 300);
};
const hasFilters = computed(() => !!search.value || selectedStatus.value !== 'All' || selectedPayment.value !== 'All' || selectedDelivery.value !== 'All' || !!dateFrom.value || !!dateTo.value);
const resetFilters = () => {
    search.value = '';
    selectedStatus.value = 'All';
    selectedPayment.value = 'All';
    selectedDelivery.value = 'All';
    dateFrom.value = '';
    dateTo.value = '';
    sort.value = 'newest';
    applyFilter();
};
// POS State
const posModalOpen = ref(false);
const productSearch = ref('');
const currentCustomer = ref('');
const paymentMethod = ref<'Bank Transfer' | 'GCash'>('Bank Transfer');
const paymentReference = ref('');
const amountPaid = ref<number | ''>('');
const paymentDate = ref(new Date().toISOString().split('T')[0]);
const proofOfPaymentFile = ref<File | null>(null);
const proofFileName = ref('');
const notes = ref('');
const cart = ref<Array<{
    product_id: number;
    name: string;
    sku: string;
    price: number;
    stock: number;
    qty: number;
}>>([]);

const filteredProducts = computed(() => {
    const q = productSearch.value.toLowerCase().trim();
    if (!q) return props.products;
    return props.products.filter(p => 
        p.name.toLowerCase().includes(q) || p.sku.toLowerCase().includes(q) || p.category.toLowerCase().includes(q)
    );
});

const openPosModal = () => {
    currentCustomer.value = '';
    paymentMethod.value = 'Bank Transfer';
    paymentReference.value = '';
    amountPaid.value = '';
    paymentDate.value = new Date().toISOString().split('T')[0];
    proofOfPaymentFile.value = null;
    proofFileName.value = '';
    notes.value = '';
    cart.value = [];
    productSearch.value = '';
    posModalOpen.value = true;
};

const closePosModal = () => {
    posModalOpen.value = false;
};

const addToCart = (product: ProductItem) => {
    const existing = cart.value.find(item => item.product_id === product.id);
    if (existing) {
        if (existing.qty < product.stock) {
            existing.qty++;
        }
    } else {
        cart.value.push({
            product_id: product.id,
            name: product.name,
            sku: product.sku,
            price: Number(product.price),
            stock: product.stock,
            qty: 1,
        });
    }
};

const updateQty = (index: number, newQty: number) => {
    const item = cart.value[index];
    if (newQty <= 0) {
        cart.value.splice(index, 1);
    } else if (newQty <= item.stock) {
        item.qty = newQty;
    }
};

const removeFromCart = (index: number) => {
    cart.value.splice(index, 1);
};

const cartTotal = computed(() => {
    return cart.value.reduce((sum, item) => sum + item.price * item.qty, 0);
});

// Auto-sync amount paid with cart total when total changes
watch(cartTotal, (newTotal) => {
    if (newTotal > 0) {
        amountPaid.value = Number(newTotal.toFixed(2));
    } else {
        amountPaid.value = '';
    }
});

const handleProofFileChange = (e: Event) => {
    const target = e.target as HTMLInputElement;
    if (target.files && target.files.length > 0) {
        proofOfPaymentFile.value = target.files[0];
        proofFileName.value = target.files[0].name;
    } else {
        proofOfPaymentFile.value = null;
        proofFileName.value = '';
    }
};

const clearProofFile = () => {
    proofOfPaymentFile.value = null;
    proofFileName.value = '';
};

// Mirrors the server rule: payment must cover the full total (compared in cents).
const isUnderpaid = computed(
    () => amountPaid.value !== '' && Math.round(Number(amountPaid.value) * 100) < Math.round(cartTotal.value * 100),
);

const canComplete = computed(() => {
    return (
        cart.value.length > 0 &&
        currentCustomer.value.trim().length > 0 &&
        paymentReference.value.trim().length > 0 &&
        amountPaid.value !== '' &&
        Number(amountPaid.value) > 0 &&
        !isUnderpaid.value &&
        Boolean(paymentDate.value) &&
        !isSubmitting.value
    );
});

// Action Confirmation Modal State
interface ConfirmActionOptions {
    title: string;
    description: string;
    details?: Array<{ label: string; value: string | number }>;
    confirmText?: string;
    variant?: 'brand' | 'danger';
    action: () => void;
}

const showConfirmModal = ref(false);
const confirmOptions = ref<ConfirmActionOptions>({
    title: 'Confirm Action',
    description: 'Are you sure you want to proceed?',
    details: [],
    confirmText: 'Confirm & Record',
    variant: 'brand',
    action: () => {},
});

const openConfirmModal = (options: ConfirmActionOptions) => {
    confirmOptions.value = {
        confirmText: 'Confirm & Record',
        variant: 'brand',
        ...options,
    };
    showConfirmModal.value = true;
};

const handleConfirmedAction = () => {
    showConfirmModal.value = false;
    confirmOptions.value.action();
};

const promptCompleteTransaction = () => {
    if (!canComplete.value) return;

    const itemCount = cart.value.reduce((s, i) => s + i.qty, 0);

    openConfirmModal({
        title: 'Confirm Counter Sale',
        description: `Are you sure you want to finalize and record this sale for "${currentCustomer.value.trim()}"?`,
        details: [
            { label: 'Customer Name', value: currentCustomer.value.trim() },
            { label: 'Payment Method', value: paymentMethod.value },
            { label: 'Payment Reference', value: paymentReference.value.trim() },
            { label: 'Amount Paid', value: formatCurrency(Number(amountPaid.value)) },
            { label: 'Payment Date', value: paymentDate.value },
            { label: 'Proof of Payment', value: proofFileName.value ? proofFileName.value : 'None attached' },
            { label: 'Items in Order', value: `${cart.value.length} distinct items (${itemCount} units)` },
            { label: 'Total Amount', value: formatCurrency(cartTotal.value) },
        ],
        confirmText: 'Complete Sale',
        action: completeTransaction,
    });
};

const isSubmitting = ref(false);
const completeTransaction = () => {
    if (!canComplete.value) return;

    isSubmitting.value = true;
    router.post(
        `/${teamSlug.value}/sales`,
        {
            customer: currentCustomer.value.trim(),
            payment_method: paymentMethod.value,
            payment_reference: paymentReference.value.trim(),
            amount_paid: Number(amountPaid.value),
            payment_date: paymentDate.value,
            notes: notes.value || undefined,
            proof_of_payment: proofOfPaymentFile.value || undefined,
            items: cart.value.map(item => ({
                product_id: item.product_id,
                qty: item.qty,
            })),
        },
        {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                isSubmitting.value = false;
                closePosModal();
            },
            onError: () => {
                isSubmitting.value = false;
            },
        }
    );
};

const saleToDelete = ref<Sale | null>(null);

const promptDeleteSale = (sale: Sale) => {
    saleToDelete.value = sale;
    openConfirmModal({
        title: 'Archive Cancelled Order',
        description: `Archive cancelled order "${sale.order_number}" for ${sale.client_name}? Its stock was already handled when it was cancelled; it can be restored from the Recycle Bin.`,
        details: [
            { label: 'Order #', value: sale.order_number },
            { label: 'Customer', value: sale.client_name },
            { label: 'Grand Total', value: formatCurrency(Number(sale.grand_total)) },
        ],
        confirmText: 'Archive Order',
        variant: 'danger',
        action: () => deleteSale(sale),
    });
};

const deleteSale = (sale: Sale) => {
    isSubmitting.value = true;
    router.delete(`/${teamSlug.value}/sales/${sale.id}`, {
        preserveScroll: true,
        onFinish: () => {
            isSubmitting.value = false;
            saleToDelete.value = null;
        },
    });
};

</script>

<template>
    <Head title="Sales Management" />

    <div class="workspace-page">
        <!-- Header -->
        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4">
            <div>
                <h1 class="page-title">Sales Orders</h1>
                <p class="page-subtitle">Orders from accepted quotations and counter sales: payments, invoices and delivery in one place.</p>
            </div>
            <SpecularButton variant="brand" size="md" :radius="12" @click="openPosModal" class="w-full sm:w-auto">
                <Plus class="w-4 h-4" />
                Counter Sale
            </SpecularButton>
        </div>

        <!-- Metrics -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div
                v-for="card in [
                    { label: 'Sales today (confirmed)', value: formatCurrency(metrics.todaySales), alert: '' },
                    { label: 'Sales this month', value: formatCurrency(metrics.monthSales), alert: '' },
                    { label: 'Payments to verify', value: metrics.toVerify, alert: Number(metrics.toVerify) > 0 ? 'text-amber-600' : '' },
                    { label: 'Outstanding balances', value: formatCurrency(metrics.outstanding), alert: '' },
                ]"
                :key="card.label"
                class="bg-white border border-gray-200 rounded-xl px-4 py-3"
            >
                <div class="text-xs text-gray-500">{{ card.label }}</div>
                <div :class="card.alert || 'text-gray-900'" class="mt-1 text-2xl font-semibold">{{ card.value }}</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <div class="flex flex-col gap-3 md:flex-row md:flex-wrap md:items-center">
                <div class="relative min-w-0 md:min-w-64 md:flex-1">
                    <Search class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                    <input v-model="search" @input="applyFilter" type="search" aria-label="Search orders"
                           placeholder="Order #, quotation #, invoice # or customer" class="field-control pl-10" />
                </div>
                <select v-model="selectedStatus" @change="applyFilter" aria-label="Order status" class="field-control md:w-44">
                    <option value="All">All order statuses</option>
                    <option v-for="o in options.status" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
                <select v-model="selectedPayment" @change="applyFilter" aria-label="Payment status" class="field-control md:w-44">
                    <option value="All">All payment statuses</option>
                    <option v-for="o in options.payment_status" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
                <select v-model="selectedDelivery" @change="applyFilter" aria-label="Delivery status" class="field-control md:w-44">
                    <option value="All">All delivery statuses</option>
                    <option v-for="o in options.delivery_status" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
                <input v-model="dateFrom" @change="applyFilter" type="date" aria-label="From date" class="field-control md:w-40" />
                <input v-model="dateTo" @change="applyFilter" type="date" aria-label="To date" class="field-control md:w-40" />
                <select v-model="sort" @change="applyFilter" aria-label="Sort" class="field-control md:w-44">
                    <option value="newest">Newest first</option>
                    <option value="oldest">Oldest first</option>
                    <option value="total_desc">Largest total</option>
                    <option value="balance_desc">Largest balance</option>
                </select>
                <button v-if="hasFilters" type="button" class="secondary-button shrink-0" @click="resetFilters">Reset</button>
            </div>
        </div>

        <!-- Orders -->
        <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="border-b border-gray-200">
                        <tr>
                            <th class="whitespace-nowrap">Order</th>
                            <th class="whitespace-nowrap">Customer</th>
                            <th class="whitespace-nowrap text-right">Total</th>
                            <th class="whitespace-nowrap text-right">Paid / Balance</th>
                            <th class="whitespace-nowrap">Order</th>
                            <th class="whitespace-nowrap">Payment</th>
                            <th class="whitespace-nowrap">Delivery</th>
                            <th class="whitespace-nowrap text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-if="!sales.data.length">
                            <td colspan="8" class="py-10 text-center text-gray-500">
                                No orders match. Orders are created from accepted quotations, or with Counter Sale.
                            </td>
                        </tr>
                        <tr v-for="sale in sales.data" :key="sale.id">
                            <td class="whitespace-nowrap">
                                <a :href="`/${teamSlug}/sales/${sale.id}`" class="font-mono font-semibold text-gray-900 hover:underline">{{ sale.order_number }}</a>
                                <div class="text-[11px] text-gray-500">
                                    {{ new Date(sale.sale_date).toLocaleDateString() }}
                                    · {{ sale.channel === 'counter' ? 'Counter' : (sale.quotation?.quotation_number ?? 'Quotation') }}
                                </div>
                                <div v-if="sale.invoice_number" class="font-mono text-[11px] text-gray-400">{{ sale.invoice_number }}</div>
                            </td>
                            <td class="min-w-40 font-medium text-gray-900">{{ sale.client_name }}</td>
                            <td class="whitespace-nowrap text-right font-semibold text-gray-900">{{ formatCurrency(Number(sale.grand_total)) }}</td>
                            <td class="whitespace-nowrap text-right text-xs">
                                <div class="text-gray-900">{{ formatCurrency(Number(sale.amount_paid || 0)) }}</div>
                                <div v-if="Number(sale.outstanding_balance || 0) > 0" class="font-semibold text-rose-600">{{ formatCurrency(Number(sale.outstanding_balance)) }} due</div>
                            </td>
                            <td class="whitespace-nowrap"><StatusBadge :status="sale.status_label ?? sale.status" size="sm" /><div v-if="sale.is_on_hold" class="mt-0.5 text-[11px] font-medium text-amber-700">On hold</div></td>
                            <td class="whitespace-nowrap">
                                <StatusBadge :status="sale.payment_status_label ?? sale.payment_status" size="sm" />
                                <div v-if="sale.payment_terms && sale.payment_terms !== 'full'" class="mt-0.5 text-[11px] text-gray-500">{{ sale.payment_terms === 'credit' ? 'Credit terms' : 'Down payment' }}</div>
                            </td>
                            <td class="whitespace-nowrap">
                                <StatusBadge v-if="sale.delivery_status_label" :status="sale.delivery_status_label" size="sm" />
                                <span v-else class="text-xs text-gray-400">Starts when confirmed</span>
                                <div class="mt-0.5 text-[11px] text-gray-500">{{ sale.fulfillment_type === 'pickup' ? 'Pickup' : 'Delivery' }}</div>
                            </td>
                            <td class="whitespace-nowrap text-right">
                                <div class="inline-flex items-center gap-1.5">
                                    <a :href="`/${teamSlug}/sales/${sale.id}`" class="secondary-button min-h-8 px-3 text-xs">Open</a>
                                    <button v-if="canDelete && sale.status === 'cancelled'" type="button" title="Archive cancelled order"
                                            class="rounded-lg p-1.5 text-gray-400 hover:bg-rose-50 hover:text-rose-600" @click="promptDeleteSale(sale)">
                                        <Trash2 class="size-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="sales.last_page > 1" class="flex flex-col gap-3 border-t border-gray-100 p-4 sm:flex-row sm:items-center sm:justify-between">
                <span class="text-xs text-gray-500">Showing {{ sales.data.length }} of {{ sales.total }} orders</span>
                <div class="flex flex-wrap gap-1">
                    <button
                        v-for="(link, i) in sales.links"
                        :key="i"
                        :disabled="!link.url || link.active"
                        @click="link.url && router.visit(link.url, { preserveScroll: true })"
                        v-html="link.label"
                        :class="[
                            'inline-flex h-8 min-w-8 items-center justify-center rounded-lg border px-3 text-xs transition-colors',
                            link.active ? 'bg-brand text-white border-brand font-semibold' : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50',
                            !link.url ? 'opacity-50 cursor-not-allowed' : ''
                        ]"
                    />
                </div>
            </div>
        </div>
        <!-- POS Modal -->
        <div v-if="posModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="bg-white border border-gray-200 rounded-xl shadow-xl w-full max-w-6xl flex flex-col md:flex-row h-[90vh] overflow-hidden">
                
                <!-- Left: Catalog -->
                <div class="w-full md:w-7/12 border-r border-gray-200 flex flex-col h-full overflow-hidden">
                    <div class="p-4 border-b border-gray-200 bg-gray-50 shrink-0">
                        <h2 class="text-lg font-semibold text-gray-900 mb-2">Select Products</h2>
                        <div class="relative">
                            <Search class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
                            <input
                                v-model="productSearch"
                                type="text"
                                placeholder="Search by name, SKU or category..."
                                class="w-full pl-9 pr-3 py-2 bg-white border border-gray-200 rounded-xl text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                            />
                        </div>
                    </div>
                    
                    <div class="p-4 flex-1 overflow-y-auto grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 auto-rows-max">
                        <div
                            v-for="p in filteredProducts"
                            :key="p.id"
                            @click="addToCart(p)"
                            class="bg-white border border-gray-200 hover:border-[#F8B803] rounded-xl p-3.5 cursor-pointer transition-colors flex flex-col justify-between h-32 group"
                        >
                            <div>
                                <span class="text-xs text-gray-400 font-mono block">{{ p.sku }}</span>
                                <h4 class="text-gray-900 font-semibold text-sm leading-snug line-clamp-2 mt-0.5 group-hover:text-[#F8B803] transition-colors">{{ p.name }}</h4>
                            </div>
                            <div class="flex justify-between items-baseline pt-2 border-t border-gray-50">
                                <span class="text-xs text-gray-500">Stock: <strong class="text-gray-700">{{ p.stock }}</strong></span>
                                <span class="text-[#F8B803] font-semibold text-sm">{{ formatCurrency(Number(p.price)) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right: Cart & Checkout -->
                <div class="w-full md:w-5/12 flex flex-col bg-gray-50 h-full overflow-hidden">
                    <div class="p-3.5 border-b border-gray-200 bg-white flex justify-between items-center shrink-0">
                        <div class="flex items-center gap-2">
                            <ShoppingCart class="w-5 h-5 text-gray-700" />
                            <h2 class="text-base font-semibold text-gray-900">Current Order</h2>
                        </div>
                        <button @click="closePosModal" class="text-gray-400 hover:text-gray-600 text-xl font-semibold">&times;</button>
                    </div>

                    <!-- Scrollable Form & Cart Section -->
                    <div class="flex-1 overflow-y-auto p-4 space-y-3.5">
                        <!-- Customer Name Input -->
                        <div class="relative">
                            <input
                                id="pos_customer"
                                v-model="currentCustomer"
                                type="text"
                                placeholder=" "
                                class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm font-medium"
                                required
                            />
                            <label
                                for="pos_customer"
                                class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                                :class="currentCustomer ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''"
                            >
                                Customer Name
                            </label>
                        </div>

                        <!-- Select payment method -->
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                                Select payment method
                            </label>
                            <div class="grid grid-cols-2 gap-2">
                                <button
                                    type="button"
                                    @click="paymentMethod = 'Bank Transfer'"
                                    :class="[
                                        'flex items-center justify-center gap-2 px-3 py-2.5 rounded-xl border text-xs font-semibold transition-colors cursor-pointer',
                                        paymentMethod === 'Bank Transfer'
                                            ? 'border-[#4b5320] bg-[#4b5320] text-white ring-2 ring-[#4b5320]/20'
                                            : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300 hover:bg-gray-50'
                                    ]"
                                >
                                    <Building2 class="w-4 h-4 shrink-0" />
                                    <span>Bank Transfer</span>
                                </button>

                                <button
                                    type="button"
                                    @click="paymentMethod = 'GCash'"
                                    :class="[
                                        'flex items-center justify-center gap-2 px-3 py-2.5 rounded-xl border text-xs font-semibold transition-colors cursor-pointer',
                                        paymentMethod === 'GCash'
                                            ? 'border-[#007DFE] bg-[#007DFE] text-white ring-2 ring-[#007DFE]/20'
                                            : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300 hover:bg-gray-50'
                                    ]"
                                >
                                    <Smartphone class="w-4 h-4 shrink-0" />
                                    <span>GCash</span>
                                </button>
                            </div>
                        </div>

                        <!-- Enter payment details -->
                        <div class="rounded-xl border border-gray-200 bg-white p-3.5 space-y-3">
                            <div class="flex items-center justify-between border-b border-gray-100 pb-2">
                                <span class="text-xs font-semibold text-gray-900 flex items-center gap-1.5">
                                    <Receipt class="w-3.5 h-3.5 text-[#4b5320]" />
                                    Enter payment details
                                </span>
                                <span class="text-[11px] font-semibold text-gray-500 bg-gray-100 px-2 py-0.5 rounded-md">
                                    {{ paymentMethod }}
                                </span>
                            </div>

                            <!-- Payment reference number -->
                            <div class="relative">
                                <input
                                    id="pos_ref"
                                    v-model="paymentReference"
                                    type="text"
                                    placeholder=" "
                                    class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none font-mono sm:text-sm"
                                    required
                                />
                                <label
                                    for="pos_ref"
                                    class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                                    :class="paymentReference ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''"
                                >
                                    Payment reference number
                                </label>
                            </div>

                            <!-- Amount paid & Payment date -->
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label for="pos_amount" class="block text-xs font-semibold text-gray-700 mb-1">
                                        Amount paid (USD)
                                    </label>
                                    <div class="relative">
                                        <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400 text-xs font-semibold">$</span>
                                        <input
                                             id="pos_amount"
                                            v-model.number="amountPaid"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            placeholder="0.00"
                                            class="block w-full rounded-lg border border-gray-200 bg-white pl-6 pr-2.5 py-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none font-semibold"
                                            required
                                        />
                                    </div>
                                </div>

                                <div>
                                    <label for="pos_date" class="block text-xs font-semibold text-gray-700 mb-1">
                                        Payment date
                                    </label>
                                    <input
                                        id="pos_date"
                                        v-model="paymentDate"
                                        type="date"
                                        class="block w-full rounded-lg border border-gray-200 bg-white px-2.5 py-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none"
                                        required
                                    />
                                </div>
                            </div>

                            <!-- Proof of Payment -->
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">
                                    Proof of Payment
                                </label>
                                <div v-if="!proofOfPaymentFile" class="relative">
                                    <input
                                        id="pos_proof"
                                        type="file"
                                        accept="image/*,application/pdf"
                                        @change="handleProofFileChange"
                                        class="sr-only"
                                    />
                                    <label
                                        for="pos_proof"
                                        class="flex items-center justify-center gap-2 w-full px-3 py-2 border border-dashed border-gray-300 rounded-lg bg-gray-50/50 hover:bg-gray-50 cursor-pointer text-xs text-gray-600 transition-colors"
                                    >
                                        <span>Upload receipt / transfer screenshot</span>
                                    </label>
                                </div>
                                <div v-else class="flex items-center justify-between px-3 py-1.5 bg-gray-50 border border-gray-200 rounded-lg text-xs">
                                    <div class="flex items-center gap-2 truncate text-gray-700">
                                        <FileText class="w-3.5 h-3.5 text-[#4b5320] shrink-0" />
                                        <span class="truncate font-medium">{{ proofFileName }}</span>
                                    </div>
                                    <button
                                        type="button"
                                        @click="clearProofFile"
                                        class="text-gray-400 hover:text-red-500 ml-2 p-0.5 cursor-pointer"
                                        title="Remove file"
                                    >
                                        <X class="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Optional Notes -->
                        <div>
                            <label for="pos_notes" class="block text-xs font-semibold text-gray-700 mb-1">
                                Order Notes <span class="text-gray-400 font-normal">(Optional)</span>
                            </label>
                            <input
                                id="pos_notes"
                                v-model="notes"
                                type="text"
                                placeholder="Add any special instructions or remarks..."
                                class="block w-full rounded-xl border border-gray-200 bg-white px-3.5 py-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none placeholder:text-gray-400"
                            />
                        </div>

                        <!-- Cart Items -->
                        <div class="space-y-2 pt-1">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold text-gray-700">
                                    Cart Items ({{ cart.length }})
                                </span>
                                <span v-if="cart.length > 0" class="text-xs text-gray-500">
                                    {{ cart.reduce((s, i) => s + i.qty, 0) }} total units
                                </span>
                            </div>

                            <div v-if="cart.length === 0" class="text-gray-400 text-center py-6 text-xs bg-white rounded-xl border border-dashed border-gray-200">
                                <ShoppingCart class="w-6 h-6 mx-auto mb-1 text-gray-300" />
                                Cart is empty. Click products on the left to add items.
                            </div>

                            <div
                                v-for="(item, idx) in cart"
                                :key="item.product_id"
                                class="flex justify-between items-center bg-white border border-gray-200 p-2.5 rounded-xl"
                            >
                                <div class="flex-1 pr-2">
                                    <div class="text-xs font-semibold text-gray-900 line-clamp-1">{{ item.name }}</div>
                                    <div class="text-[11px] text-gray-500">{{ formatCurrency(item.price) }} each</div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <div class="flex items-center border border-gray-200 rounded-lg overflow-hidden bg-white">
                                        <button @click="updateQty(idx, item.qty - 1)" class="px-2 py-0.5 text-gray-600 hover:bg-gray-100 text-xs font-semibold cursor-pointer">-</button>
                                        <span class="px-2 text-xs font-semibold text-gray-900">{{ item.qty }}</span>
                                        <button @click="updateQty(idx, item.qty + 1)" class="px-2 py-0.5 text-gray-600 hover:bg-gray-100 text-xs font-semibold cursor-pointer">+</button>
                                    </div>
                                    <span class="text-xs font-semibold text-gray-900 w-16 text-right">
                                        {{ formatCurrency(item.price * item.qty) }}
                                    </span>
                                    <button @click="removeFromCart(idx)" class="text-gray-400 hover:text-red-500 ml-1 cursor-pointer">
                                        <X class="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Total & Checkout Footer -->
                    <div class="p-4 bg-white border-t border-gray-200 shrink-0">
                        <div class="flex justify-between text-gray-500 text-xs mb-1">
                            <span>Subtotal</span>
                            <span>{{ formatCurrency(cartTotal) }}</span>
                        </div>
                        <div class="flex justify-between text-gray-500 text-xs mb-2.5">
                            <span>Payment Method</span>
                            <span class="font-semibold text-gray-800">{{ paymentMethod }}</span>
                        </div>
                        <div class="flex justify-between items-baseline text-gray-900 text-lg font-semibold mb-3">
                            <span>Total</span>
                            <span class="text-[#F8B803] text-2xl">{{ formatCurrency(cartTotal) }}</span>
                        </div>
                        
                        <div v-if="!canComplete && cart.length > 0" class="mb-2 text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-2.5 py-1">
                            <span v-if="!currentCustomer.trim()">Enter Customer Name to proceed.</span>
                            <span v-else-if="!paymentReference.trim()">Enter Payment Reference Number.</span>
                            <span v-else-if="!amountPaid || Number(amountPaid) <= 0">Enter valid Amount Paid.</span>
                            <span v-else-if="isUnderpaid">Amount Paid is less than the total due ({{ formatCurrency(cartTotal) }}).</span>
                        </div>

                        <SpecularButton
                            variant="brand"
                            size="lg"
                            :radius="10"
                            class="w-full"
                            :disabled="!canComplete || isSubmitting"
                            @click="promptCompleteTransaction"
                        >
                            {{ isSubmitting ? 'Recording...' : 'Record Counter Sale' }}
                        </SpecularButton>
                    </div>
                </div>

            </div>
        </div>

    </div>

    <!-- Action Confirmation Modal -->
    <div v-if="showConfirmModal" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-md shadow-xl animate-in fade-in duration-150">
            <div
                :class="[
                    'w-11 h-11 rounded-full flex items-center justify-center mb-4 border',
                    confirmOptions.variant === 'danger'
                        ? 'bg-red-50 text-red-600 border-red-100'
                        : 'bg-amber-50 text-[#F8B803] border-amber-100'
                ]"
            >
                <Trash2 v-if="confirmOptions.variant === 'danger'" class="w-6 h-6" />
                <CheckCircle2 v-else class="w-6 h-6" />
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
                    :variant="confirmOptions.variant === 'danger' ? 'danger' : 'brand'"
                    size="md"
                    :radius="10"
                    @click="handleConfirmedAction"
                    :disabled="isSubmitting"
                >
                    <Trash2 v-if="confirmOptions.variant === 'danger'" class="w-4 h-4 mr-1.5 inline" />
                    <Check v-else class="w-4 h-4 mr-1.5 inline" />
                    <span>{{ confirmOptions.confirmText || 'Confirm' }}</span>
                </SpecularButton>
            </div>
        </div>
    </div>
</template>
