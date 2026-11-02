<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  BarElement,
  ArcElement,
  Filler,
  Title,
  Tooltip,
  Legend,
} from 'chart.js';
import { Line, Bar, Doughnut } from 'vue-chartjs';
import SpecularButton from '@/components/ui/SpecularButton.vue';
import { type Evaluation } from '@/components/evaluation/ScoreBreakdown.vue';
import ProductPriorityBreakdown, { type ProductPriority } from '@/components/evaluation/ProductPriorityBreakdown.vue';
import SupplierEvaluationBreakdown from '@/components/evaluation/SupplierEvaluationBreakdown.vue';
import { useCurrency } from '@/composables/useCurrency';
import {
  Package,
  AlertTriangle,
  FileText,
  LineChart,
  Download,
  Printer,
  Database,
  BarChart3,
  Calendar,
  Filter,
  RotateCcw,
  Search,
  ShoppingCart,
  Banknote,
  TrendingUp,
  Award,
  ChevronRight,
  ExternalLink,
  ChevronDown,
  Info,
  CheckCircle2,
  Clock,
  Layers,
  ArrowUpRight,
} from '@lucide/vue';

ChartJS.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  BarElement,
  ArcElement,
  Filler,
  Title,
  Tooltip,
  Legend,
);

// ─────────────────────────────────────────────────────────────
// INTERFACES
// ─────────────────────────────────────────────────────────────
interface InventoryItem {
    id: number;
    sku: string;
    name: string;
    category: string;
    supplier_name: string;
    current_stock: number;
    reorder_threshold: number;
    unit: string;
    cost_price: number;
    price: number;
    total_valuation: number;
    stock_status: 'In Stock' | 'Low Stock' | 'Out of Stock';
}

interface LowStockItem {
    id: number;
    sku: string;
    name: string;
    category: string;
    stock: number;
    reorder_level: number;
    shortage: number;
    suggested_reorder: number;
    supplier_name: string;
    urgency: string;
    price: number;
}

interface SalesItem {
    id: number;
    invoice_number: string;
    client_name: string;
    sale_date: string;
    subtotal: number;
    tax_amount: number;
    grand_total: number;
    payment_method: string;
    status: string;
}

interface QuotationItemRecord {
    id: number;
    quotation_number: string;
    client_name: string;
    quotation_date: string;
    valid_until: string;
    grand_total: number;
    status: string;
    is_converted: boolean;
}

interface ProcurementItem {
    id: number;
    request_number: string;
    product_name: string;
    sku: string;
    supplier_name: string;
    requested_by: string;
    quantity: number;
    estimated_cost: number;
    status: string;
    date: string;
}

interface SupplierAhpItem {
    rank: number | null;
    id: number;
    name: string;
    contact_person: string;
    email: string;
    phone: string;
    /** 0–100, null when the evaluation lacks data. */
    ahp_score: number | null;
    classification: string | null;
    complete: boolean;
    metrics: {
        price: number | null;
        quality: number | null;
        delivery: number | null;
    };
    evaluation: Evaluation & { period?: { from: string; to: string } };
    status: string;
}

const props = defineProps<{
    metrics: {
        inventoryCount: number;
        totalValuation: number;
        lowStockCount: number;
        outOfStockCount: number;
        inStockCount: number;
        quotationsCount: number;
        salesCount: number;
        totalRevenue: number;
        pendingQuotationsCount: number;
        pendingQuotationsValue: number;
        approvedQuotationsCount: number;
        totalPurchasesCount: number;
        totalPurchasesEstimatedCost: number;
        valuationBasisNote: string;
    };
    dateFilter: {
        preset: string;
        fromDate: string | null;
        toDate: string | null;
    };
    categories: string[];
    inventoryAnalytics: {
        statusDistribution: {
            in_stock: number;
            low_stock: number;
            out_of_stock: number;
        };
        byCategory: Array<{
            category: string;
            count: number;
            stock: number;
            valuation: number;
        }>;
        topProductsByValuation: Array<{
            id: number;
            name: string;
            sku: string;
            category: string;
            stock: number;
            unit: string;
            cost_price: number;
            price: number;
            valuation: number;
        }>;
        items: InventoryItem[];
    };
    lowStockAnalytics: {
        byCategory: Record<string, number>;
        severity: LowStockItem[];
        items: LowStockItem[];
    };
    quotationsSalesAnalytics: {
        salesTrend: {
            labels: string[];
            data: number[];
        };
        quotationStatusDistribution: Record<string, number>;
        topSellingProducts: Array<{
            name: string;
            sku: string;
            quantity: number;
            amount: number;
        }>;
        conversion: {
            total: number;
            approved: number;
            converted: number;
            rate: number;
        };
        salesList: SalesItem[];
        quotationsList: QuotationItemRecord[];
    };
    forecastAnalytics: {
        labels: string[];
        historical: number[];
        forecast: (number | null)[];
        year: number;
        window: number;
        hasData: boolean;
        steps: { month: string; inputs: { month: string; units: number }[]; sum: number; window: number; forecast: number }[];
        sourceRecordCount: number;
    };
    procurementAnalytics: {
        statusDistribution: Record<string, number>;
        totalEstimatedCost: number;
        requestsList: ProcurementItem[];
    };
    ahpAnalytics: {
        topSuppliers: SupplierAhpItem[];
        suppliersList: SupplierAhpItem[];
        weights: { delivery: number; price: number; quality: number };
        deliverySplit: { on_time: number; fulfillment: number };
        periodMonths: number;
        classes: Record<string, string>;
    };
    productPriority: {
        items: ProductPriority[];
        weights: { demand: number; stock: number; movement: number; supplier_availability: number };
        classes: Record<string, string>;
        movementMonths: number;
        smaWindow: number;
    };
}>();

// Calculation breakdowns: same backend results as Inventory, Suppliers and Demand Forecasting.
const stepByMonth = computed(() => new Map(props.forecastAnalytics.steps.map((s) => [s.month, s])));
const selectedProductPriority = ref<ProductPriority | null>(null);
const selectedSupplierEval = ref<SupplierAhpItem | null>(null);
const priorityContribution = (p: ProductPriority, key: string) => p.criteria.find((c) => c.key === key);
const pctW = (w: number) => `${Math.round(w * 100)}%`;
const priorityKeys = ['demand', 'stock', 'movement', 'supplier_availability'];
// Class thresholds come from config as {minScore: label}; shown highest first.
const classBands = (classes: Record<string, string>) =>
    Object.entries(classes).map(([min, label]) => ({ min: Number(min), label })).sort((a, b) => b.min - a.min);

const page = usePage();
const teamSlug = computed(() => (page.props as any).currentTeam?.slug || (page.props as any).team?.slug || '');

// Currency and number formatters. Stored amounts are USD; shown in the user's display currency
// (header switch). Money charts plot toDisplayAmount() values and label them with formatDisplayAmount().
const { formatMoney: formatCurrency, toDisplayAmount, formatDisplayAmount, displayCurrency } = useCurrency();
const formatNumber = (num: number) => {
    return new Intl.NumberFormat('en-PH').format(num);
};

// ─────────────────────────────────────────────────────────────
// ACTIVE TAB SELECTION
// ─────────────────────────────────────────────────────────────
type TabKey = 'overview' | 'inventory' | 'low-stock' | 'sales' | 'forecast' | 'procurement' | 'priority' | 'ahp';
const activeTab = ref<TabKey>('overview');

// ─────────────────────────────────────────────────────────────
// DATE FILTERING STATE & ACTIONS
// ─────────────────────────────────────────────────────────────
const selectedPreset = ref(props.dateFilter.preset || 'all_time');
const customFromDate = ref(props.dateFilter.fromDate || '');
const customToDate = ref(props.dateFilter.toDate || '');

const applyDateFilter = () => {
    router.get(
        `/${teamSlug.value}/reports`,
        {
            date_preset: selectedPreset.value,
            from_date: selectedPreset.value === 'custom' ? customFromDate.value : undefined,
            to_date: selectedPreset.value === 'custom' ? customToDate.value : undefined,
        },
        { preserveState: true, preserveScroll: true, replace: false },
    );
};

const onPresetChange = () => {
    if (selectedPreset.value !== 'custom') {
        applyDateFilter();
    }
};

const resetFilters = () => {
    selectedPreset.value = 'all_time';
    customFromDate.value = '';
    customToDate.value = '';
    applyDateFilter();
};

const activeScopeLabel = computed(() => {
    switch (props.dateFilter.preset) {
        case 'today': return 'Today';
        case 'last_7_days': return 'Last 7 Days';
        case 'last_30_days': return 'Last 30 Days';
        case 'this_month': return 'This Month';
        case 'this_year': return 'This Year';
        case 'custom':
            return `Custom: ${props.dateFilter.fromDate || 'Start'} to ${props.dateFilter.toDate || 'End'}`;
        case 'all_time':
        default:
            return 'All-Time Records';
    }
});

// ─────────────────────────────────────────────────────────────
// INVENTORY TAB FILTERS & SEARCH
// ─────────────────────────────────────────────────────────────
const invSearch = ref('');
const invCategoryFilter = ref('All');
const invStatusFilter = ref('All');
const invPage = ref(1);
const invPerPage = 10;

const filteredInventory = computed(() => {
    return props.inventoryAnalytics.items.filter(item => {
        const matchesSearch = !invSearch.value ||
            item.name.toLowerCase().includes(invSearch.value.toLowerCase()) ||
            item.sku.toLowerCase().includes(invSearch.value.toLowerCase()) ||
            item.supplier_name.toLowerCase().includes(invSearch.value.toLowerCase());
        const matchesCategory = invCategoryFilter.value === 'All' || item.category === invCategoryFilter.value;
        const matchesStatus = invStatusFilter.value === 'All' || item.stock_status === invStatusFilter.value;
        return matchesSearch && matchesCategory && matchesStatus;
    });
});

const paginatedInventory = computed(() => {
    const start = (invPage.value - 1) * invPerPage;
    return filteredInventory.value.slice(start, start + invPerPage);
});
const totalInvPages = computed(() => Math.ceil(filteredInventory.value.length / invPerPage) || 1);

// ─────────────────────────────────────────────────────────────
// LOW STOCK TAB FILTERS & SEARCH
// ─────────────────────────────────────────────────────────────
const lowStockSearch = ref('');
const lowStockCategoryFilter = ref('All');
const lowStockUrgencyFilter = ref('All');

const filteredLowStock = computed(() => {
    return props.lowStockAnalytics.items.filter(item => {
        const matchesSearch = !lowStockSearch.value ||
            item.name.toLowerCase().includes(lowStockSearch.value.toLowerCase()) ||
            item.sku.toLowerCase().includes(lowStockSearch.value.toLowerCase());
        const matchesCategory = lowStockCategoryFilter.value === 'All' || item.category === lowStockCategoryFilter.value;
        const matchesUrgency = lowStockUrgencyFilter.value === 'All' || item.urgency === lowStockUrgencyFilter.value;
        return matchesSearch && matchesCategory && matchesUrgency;
    });
});

// ─────────────────────────────────────────────────────────────
// QUOTATIONS & SALES SUB-TAB & FILTERS
// ─────────────────────────────────────────────────────────────
const salesSubTab = ref<'sales' | 'quotations'>('sales');
const salesSearch = ref('');
const filteredSales = computed(() => {
    return props.quotationsSalesAnalytics.salesList.filter(s =>
        !salesSearch.value ||
        s.invoice_number.toLowerCase().includes(salesSearch.value.toLowerCase()) ||
        s.client_name.toLowerCase().includes(salesSearch.value.toLowerCase())
    );
});
const filteredQuotations = computed(() => {
    return props.quotationsSalesAnalytics.quotationsList.filter(q =>
        !salesSearch.value ||
        q.quotation_number.toLowerCase().includes(salesSearch.value.toLowerCase()) ||
        q.client_name.toLowerCase().includes(salesSearch.value.toLowerCase())
    );
});

// ─────────────────────────────────────────────────────────────
// EXPORT ACTIONS
// ─────────────────────────────────────────────────────────────
const downloadReport = (endpoint: string, params?: Record<string, string>) => {
    const url = new URL(`/${teamSlug.value}/reports/export/${endpoint}`, window.location.origin);
    if (params) {
        Object.entries(params).forEach(([k, v]) => {
            if (v && v !== 'All') url.searchParams.set(k, v);
        });
    }
    window.location.href = url.toString();
};

const printReport = () => {
    window.print();
};

const goToInventory = () => {
    router.visit(`/${teamSlug.value}/inventory`);
};

const goToProcurement = () => {
    router.visit(`/${teamSlug.value}/procurement`);
};

// ─────────────────────────────────────────────────────────────
// CHART CONFIGURATIONS
// ─────────────────────────────────────────────────────────────

// 1. Stock Status Donut Chart
const stockDonutData = computed(() => ({
    labels: ['In Stock', 'Low Stock', 'Out of Stock'],
    datasets: [{
        data: [
            props.inventoryAnalytics.statusDistribution.in_stock,
            props.inventoryAnalytics.statusDistribution.low_stock,
            props.inventoryAnalytics.statusDistribution.out_of_stock,
        ],
        backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
        borderWidth: 2,
        borderColor: '#ffffff',
    }],
}));

// 2. Category Inventory Bar Chart
const categoryBarData = computed(() => ({
    labels: props.inventoryAnalytics.byCategory.map(c => c.category),
    datasets: [
        {
            label: 'Total Stock (Units)',
            data: props.inventoryAnalytics.byCategory.map(c => c.stock),
            backgroundColor: 'rgba(75, 83, 32, 0.85)', // brand green
            borderRadius: 6,
        },
    ],
}));

// 3. Top Products by Value Horizontal Bar Chart
const topValueBarData = computed(() => ({
    labels: props.inventoryAnalytics.topProductsByValuation.map(p => p.name.length > 18 ? p.name.substring(0, 18) + '...' : p.name),
    datasets: [{
        label: `Total Valuation (${displayCurrency.value})`,
        data: props.inventoryAnalytics.topProductsByValuation.map(p => toDisplayAmount(p.valuation)),
        backgroundColor: 'rgba(248, 184, 3, 0.85)', // gold
        borderRadius: 6,
    }],
}));

// 4. Low Stock Severity Horizontal Bar Chart
const lowStockSeverityData = computed(() => ({
    labels: props.lowStockAnalytics.severity.map(p => p.name.length > 18 ? p.name.substring(0, 18) + '...' : p.name),
    datasets: [{
        label: 'Stock Shortage (Units Below Threshold)',
        data: props.lowStockAnalytics.severity.map(p => p.shortage),
        backgroundColor: '#ef4444',
        borderRadius: 6,
    }],
}));

// 5. Sales Revenue Trend Line Chart
const salesRevenueTrendData = computed(() => ({
    labels: props.quotationsSalesAnalytics.salesTrend.labels,
    datasets: [{
        label: `Sales Revenue (${displayCurrency.value})`,
        data: props.quotationsSalesAnalytics.salesTrend.data.map(v => toDisplayAmount(v)),
        borderColor: '#10b981',
        backgroundColor: 'rgba(16, 185, 129, 0.1)',
        fill: true,
        tension: 0.35,
        pointBackgroundColor: '#10b981',
        pointRadius: 4,
    }],
}));

// 6. Quotation Status Donut Chart
const quotationStatusDonutData = computed(() => {
    const dist = props.quotationsSalesAnalytics.quotationStatusDistribution;
    return {
        labels: Object.keys(dist),
        datasets: [{
            data: Object.values(dist),
            backgroundColor: ['#f59e0b', '#10b981', '#ef4444', '#3b82f6', '#94a3b8', '#64748b'],
            borderWidth: 2,
            borderColor: '#ffffff',
        }],
    };
});

// 7. Top Selling Products Horizontal Bar Chart
const topSellingBarData = computed(() => ({
    labels: props.quotationsSalesAnalytics.topSellingProducts.map(p => p.name.length > 16 ? p.name.substring(0, 16) + '...' : p.name),
    datasets: [{
        label: 'Units Sold',
        data: props.quotationsSalesAnalytics.topSellingProducts.map(p => p.quantity),
        backgroundColor: '#4b5320',
        borderRadius: 6,
    }],
}));

// 8. Forecast Line Chart (Historical vs SMA)
const forecastChartData = computed(() => ({
    labels: props.forecastAnalytics.labels,
    datasets: [
        {
            label: 'Recorded Demand (Units)',
            data: props.forecastAnalytics.historical,
            borderColor: '#10b981',
            backgroundColor: 'rgba(16, 185, 129, 0.1)',
            fill: true,
            tension: 0.35,
            pointRadius: 3,
        },
        {
            label: 'SMA-3 Forecast',
            data: props.forecastAnalytics.forecast,
            borderColor: '#F8B803',
            borderDash: [5, 5],
            backgroundColor: 'transparent',
            tension: 0.35,
            pointRadius: 4,
            pointBackgroundColor: '#F8B803',
        },
    ],
}));

// 9. Procurement Status Donut Chart
const procurementDonutData = computed(() => {
    const dist = props.procurementAnalytics.statusDistribution;
    return {
        labels: Object.keys(dist),
        datasets: [{
            data: Object.values(dist),
            backgroundColor: ['#f59e0b', '#3b82f6', '#10b981', '#94a3b8'],
            borderWidth: 2,
            borderColor: '#ffffff',
        }],
    };
});

// 10. AHP Top Suppliers Bar Chart
const ahpBarData = computed(() => ({
    labels: props.ahpAnalytics.topSuppliers.filter(s => s.complete).map(s => s.name),
    datasets: [{
        label: 'Supplier Evaluation Score (0–100)',
        data: props.ahpAnalytics.topSuppliers.filter(s => s.complete).map(s => s.ahp_score),
        backgroundColor: '#4b5320',
        borderRadius: 6,
    }],
}));

// Shared Chart Options
const standardChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            position: 'top' as const,
            labels: { boxWidth: 12, usePointStyle: true, font: { size: 11, weight: 600 } },
        },
    },
};

const horizontalBarOptions = {
    indexAxis: 'y' as const,
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
    },
    scales: {
        x: { grid: { color: '#f3f4f6' }, ticks: { font: { size: 10 } } },
        y: { ticks: { font: { size: 11 } } },
    },
};

// Money charts: axis ticks and tooltips in the display currency (the plotted values already are).
const moneyTick = (value: number | string) => formatDisplayAmount(value, { decimals: 0 });
const moneyTooltip = (axis: 'x' | 'y') => ({
    callbacks: {
        label: (ctx: any) => `${ctx.dataset.label ?? ''}: ${formatDisplayAmount(ctx.parsed?.[axis])}`,
    },
});

const moneyLineOptions = computed(() => ({
    ...standardChartOptions,
    plugins: { ...standardChartOptions.plugins, tooltip: moneyTooltip('y') },
    scales: { y: { ticks: { callback: moneyTick } } },
}));

const moneyHorizontalBarOptions = computed(() => ({
    ...horizontalBarOptions,
    plugins: { ...horizontalBarOptions.plugins, tooltip: moneyTooltip('x') },
    scales: {
        ...horizontalBarOptions.scales,
        x: { ...horizontalBarOptions.scales.x, ticks: { ...horizontalBarOptions.scales.x.ticks, callback: moneyTick } },
    },
}));
</script>

<template>
    <Head title="Reports & Analytics" />

    <div class="workspace-page">
        <!-- ─── Print-Only Header (Hidden on screen) ──────────────── -->
        <div class="hidden print:block mb-8 pb-4 border-b border-gray-300">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-black text-gray-900 tracking-tight">MR-PRIDE ELECTRICAL SUPPLIES</h1>
                    <p class="text-xs text-gray-600 font-medium">Business Performance, Inventory Valuation &amp; Operational Audit</p>
                </div>
                <div class="text-right text-xs text-gray-500">
                    <p>Report Date: {{ new Date().toLocaleDateString('en-PH', { dateStyle: 'long' }) }}</p>
                    <p>Filter Scope: {{ activeScopeLabel }}</p>
                </div>
            </div>
        </div>

        <!-- ─── Main Header (Screen Only) ─────────────────────────── -->
        <div class="print:hidden flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-4">
            <div>
                <h1 class="page-title">Reports &amp; Analytics</h1>
                <p class="page-subtitle">Sales, inventory and forecast reports for any period.</p>
            </div>

            <!-- Top Actions -->
            <div class="flex flex-wrap items-center justify-start lg:justify-end gap-2.5 w-full lg:w-auto">
                <SpecularButton
                    variant="secondary"
                    size="md"
                    :radius="12"
                    @click="printReport"
                    class="flex-1 sm:flex-none"
                    title="Print formal report document"
                >
                    <Printer class="w-4 h-4 mr-1.5 inline text-gray-700" />
                    Print Summary
                </SpecularButton>

                <SpecularButton
                    variant="secondary"
                    size="md"
                    :radius="12"
                    @click="goToInventory"
                    class="flex-1 sm:flex-none"
                    title="Navigate to master catalog"
                >
                    <Database class="w-4 h-4 mr-1.5 inline text-gray-700" />
                    View Master Data
                </SpecularButton>

                <SpecularButton
                    variant="brand"
                    size="md"
                    :radius="12"
                    @click="downloadReport('all')"
                    class="flex-1 sm:flex-none"
                    title="Export complete catalog and supplier dataset"
                >
                    <Download class="w-4 h-4 mr-1.5 inline" />
                    Export All Master Data
                </SpecularButton>
            </div>
        </div>

        <!-- ─── SECTION A: BUSINESS PERFORMANCE OVERVIEW & KPIS ───── -->
        <div class="mb-6 space-y-4">
            <!-- Global Date & Scope Filter Bar -->
            <div class="print:hidden">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <span class="text-xs font-semibold text-gray-600 flex items-center gap-1.5">
                            <Calendar class="w-3.5 h-3.5 text-gray-400" />
                            Report Period:
                        </span>

                        <select
                            v-model="selectedPreset"
                            @change="onPresetChange"
                            class="bg-white border border-gray-200 rounded-lg px-3 py-1.5 text-xs text-gray-900 font-medium focus:border-gray-400 focus:outline-none"
                        >
                            <option value="all_time">All-Time Records</option>
                            <option value="today">Today</option>
                            <option value="last_7_days">Last 7 Days</option>
                            <option value="last_30_days">Last 30 Days</option>
                            <option value="this_month">This Month</option>
                            <option value="this_year">This Year</option>
                            <option value="custom">Custom Date Range</option>
                        </select>

                        <!-- Custom Date Pickers -->
                        <template v-if="selectedPreset === 'custom'">
                            <input
                                v-model="customFromDate"
                                type="date"
                                class="bg-white border border-gray-200 rounded-lg px-2.5 py-1 text-xs text-gray-900 focus:outline-none focus:border-gray-400"
                            />
                            <span class="text-xs text-gray-400">to</span>
                            <input
                                v-model="customToDate"
                                type="date"
                                class="bg-white border border-gray-200 rounded-lg px-2.5 py-1 text-xs text-gray-900 focus:outline-none focus:border-gray-400"
                            />
                            <button
                                @click="applyDateFilter"
                                class="px-3 py-1.5 bg-[#4b5320] text-white text-xs font-semibold rounded-xl hover:bg-[#3d441a] transition-colors"
                            >
                                Apply
                            </button>
                        </template>
                    </div>

                    <div class="flex items-center gap-2">
                        <span class="text-xs text-gray-500 bg-gray-100 px-2.5 py-1 rounded-lg font-medium">
                            Scope: <strong class="text-gray-800">{{ activeScopeLabel }}</strong>
                        </span>

                        <button
                            v-if="selectedPreset !== 'all_time'"
                            @click="resetFilters"
                            class="text-xs text-gray-500 hover:text-gray-900 flex items-center gap-1 font-medium transition-colors ml-1"
                            title="Reset date filters"
                        >
                            <RotateCcw class="w-3.5 h-3.5" />
                            Reset
                        </button>
                    </div>
                </div>
            </div>

            <!-- Primary KPIs: value plus one supporting detail. Cards that open a tab say so on hover. -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                <div class="bg-white border border-gray-200 rounded-xl px-4 py-3" :title="metrics.valuationBasisNote">
                    <div class="text-xs text-gray-500">Catalog valuation</div>
                    <div class="mt-1 text-2xl font-semibold text-gray-900">{{ formatCurrency(metrics.totalValuation) }}</div>
                    <div class="mt-1 text-xs text-gray-500">{{ metrics.inventoryCount }} items at cost price</div>
                </div>

                <button type="button" @click="activeTab = 'low-stock'" class="text-left bg-white border border-gray-200 rounded-xl px-4 py-3 hover:bg-gray-50 transition-colors">
                    <div class="text-xs text-gray-500">Low stock</div>
                    <div :class="metrics.lowStockCount > 0 ? 'text-amber-600' : 'text-gray-900'" class="mt-1 text-2xl font-semibold">{{ metrics.lowStockCount }} items</div>
                    <div class="mt-1 text-xs" :class="metrics.outOfStockCount > 0 ? 'text-rose-600' : 'text-gray-500'">{{ metrics.outOfStockCount }} out of stock</div>
                </button>

                <button type="button" @click="activeTab = 'sales'" class="text-left bg-white border border-gray-200 rounded-xl px-4 py-3 hover:bg-gray-50 transition-colors">
                    <div class="text-xs text-gray-500">Sales revenue</div>
                    <div class="mt-1 text-2xl font-semibold text-gray-900">{{ formatCurrency(metrics.totalRevenue) }}</div>
                    <div class="mt-1 text-xs text-gray-500">{{ metrics.salesCount }} completed sales</div>
                </button>

                <button type="button" @click="activeTab = 'procurement'" class="text-left bg-white border border-gray-200 rounded-xl px-4 py-3 hover:bg-gray-50 transition-colors">
                    <div class="text-xs text-gray-500">Procurement spend</div>
                    <div class="mt-1 text-2xl font-semibold text-gray-900">{{ formatCurrency(metrics.totalPurchasesEstimatedCost) }}</div>
                    <div class="mt-1 text-xs text-gray-500">{{ metrics.totalPurchasesCount }} purchase requests</div>
                </button>
            </div>

            <!-- Secondary indicators -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-x-6 gap-y-2 text-sm">
                <div class="flex items-center justify-between border-b border-gray-100 py-2">
                    <span class="text-gray-500">Pending quotations</span>
                    <span class="font-medium text-gray-900">{{ metrics.pendingQuotationsCount }} ({{ formatCurrency(metrics.pendingQuotationsValue) }})</span>
                </div>
                <div class="flex items-center justify-between border-b border-gray-100 py-2">
                    <span class="text-gray-500">Quotation conversion</span>
                    <span class="font-medium text-gray-900">
                        {{ quotationsSalesAnalytics.conversion.rate }}% ({{ quotationsSalesAnalytics.conversion.converted }} of {{ quotationsSalesAnalytics.conversion.total }})
                    </span>
                </div>
                <div class="flex items-center justify-between border-b border-gray-100 py-2">
                    <span class="text-gray-500">Top supplier (AHP)</span>
                    <span class="font-medium text-gray-900">
                        {{ ahpAnalytics.topSuppliers[0]?.name || 'No evaluation yet' }}<template v-if="ahpAnalytics.topSuppliers[0]?.complete"> ({{ Number(ahpAnalytics.topSuppliers[0].ahp_score).toFixed(2) }})</template><template v-else-if="ahpAnalytics.topSuppliers[0]"> (insufficient data)</template>
                    </span>
                </div>
            </div>
        </div>

        <!-- ─── SECTION B: INTERACTIVE ANALYTICS & MODULE REPORT TABS ─ -->
        <div class="space-y-6">
            <!-- Modern Tab Navigation Bar -->
            <div class="print:hidden border-b border-gray-200 flex overflow-x-auto gap-2 pb-1 scrollbar-none">
                <button
                    v-for="tab in [
                        { key: 'overview', label: 'Overview', icon: BarChart3 },
                        { key: 'inventory', label: 'Inventory Status', icon: Package },
                        { key: 'low-stock', label: 'Low Stock Audit', icon: AlertTriangle },
                        { key: 'sales', label: 'Quotations & Sales', icon: Banknote },
                        { key: 'forecast', label: 'Demand Forecasting', icon: LineChart },
                        { key: 'procurement', label: 'Purchasing & Procurement', icon: ShoppingCart },
                        { key: 'priority', label: 'AHP Product Prioritization', icon: Package },
                        { key: 'ahp', label: 'Supplier Evaluation', icon: Award },
                    ]"
                    :key="tab.key"
                    @click="activeTab = tab.key as TabKey"
                    :class="[
                        'flex items-center gap-2 px-3.5 py-2 rounded-lg text-sm font-medium transition-colors whitespace-nowrap cursor-pointer',
                        activeTab === tab.key
                            ? 'bg-gray-100 text-gray-900'
                            : 'text-gray-500 hover:text-gray-900 hover:bg-gray-50'
                    ]"
                >
                    <component :is="tab.icon" class="w-4 h-4" />
                    <span>{{ tab.label }}</span>
                </button>
            </div>

            <!-- ═════════════════════════════════════════════════════ -->
            <!-- TAB 1: OVERVIEW DASHBOARD                             -->
            <!-- ═════════════════════════════════════════════════════ -->
            <div v-if="activeTab === 'overview'" class="space-y-6">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Sales Revenue Trend -->
                    <div class="lg:col-span-2 bg-white border border-gray-200 rounded-xl p-5 flex flex-col">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h3 class="text-base font-semibold text-gray-900">Monthly Revenue Velocity</h3>
                                <p class="text-xs text-gray-400">Actual sales income aggregated per billing cycle</p>
                            </div>
                            <span class="text-xs font-semibold px-2.5 py-1 bg-emerald-50 text-emerald-700 rounded-full">
                                Total: {{ formatCurrency(metrics.totalRevenue) }}
                            </span>
                        </div>
                        <div class="h-64">
                            <Line :data="salesRevenueTrendData" :options="moneyLineOptions" />
                        </div>
                    </div>

                    <!-- Stock Health Donut -->
                    <div class="bg-white border border-gray-200 rounded-xl p-5 flex flex-col justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900 mb-1">Catalog Stock Health</h3>
                            <p class="text-xs text-gray-400 mb-4">Real-time breakdown of warehouse thresholds</p>
                            <div class="h-48 relative flex items-center justify-center">
                                <Doughnut :data="stockDonutData" :options="standardChartOptions" />
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-2 text-center pt-3 border-t border-gray-100 text-xs">
                            <div>
                                <span class="block text-emerald-600 font-semibold">{{ metrics.inStockCount }}</span>
                                <span class="text-gray-400 text-[10px]">In Stock</span>
                            </div>
                            <div>
                                <span class="block text-amber-600 font-semibold">{{ metrics.lowStockCount - metrics.outOfStockCount }}</span>
                                <span class="text-gray-400 text-[10px]">Low Stock</span>
                            </div>
                            <div>
                                <span class="block text-rose-600 font-semibold">{{ metrics.outOfStockCount }}</span>
                                <span class="text-gray-400 text-[10px]">Out of Stock</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Row 2: Categories & Top Products -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Inventory by Category Bar Chart -->
                    <div class="bg-white border border-gray-200 rounded-xl p-5">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h3 class="text-base font-semibold text-gray-900">Stock Units by Product Category</h3>
                                <p class="text-xs text-gray-400">Inventory volume distribution across classifications</p>
                            </div>
                        </div>
                        <div class="h-60">
                            <Bar :data="categoryBarData" :options="standardChartOptions" />
                        </div>
                    </div>

                    <!-- Top Products by Valuation -->
                    <div class="bg-white border border-gray-200 rounded-xl p-5 flex flex-col">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h3 class="text-base font-semibold text-gray-900">Top Products by Inventory Value</h3>
                                <p class="text-xs text-gray-400">Capital tied up in current on-hand warehouse stock</p>
                            </div>
                            <button
                                @click="activeTab = 'inventory'"
                                class="text-xs font-semibold text-[#4b5320] hover:underline flex items-center gap-1"
                            >
                                View Table <ArrowUpRight class="w-3.5 h-3.5" />
                            </button>
                        </div>
                        <div class="h-60">
                            <Bar :data="topValueBarData" :options="moneyHorizontalBarOptions" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═════════════════════════════════════════════════════ -->
            <!-- TAB 2: INVENTORY STATUS REPORT                        -->
            <!-- ═════════════════════════════════════════════════════ -->
            <div v-else-if="activeTab === 'inventory'" class="space-y-6">
                <!-- Visualizations -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="bg-white border border-gray-200 rounded-xl p-5">
                        <h4 class="text-sm font-semibold text-gray-900 mb-1">Status Distribution</h4>
                        <p class="text-xs text-gray-400 mb-3">Ratio of healthy vs understocked catalog items</p>
                        <div class="h-52">
                            <Doughnut :data="stockDonutData" :options="standardChartOptions" />
                        </div>
                    </div>

                    <div class="lg:col-span-2 bg-white border border-gray-200 rounded-xl p-5">
                        <h4 class="text-sm font-semibold text-gray-900 mb-1">Inventory Valuation by Category</h4>
                        <p class="text-xs text-gray-400 mb-3">Capital value allocated across product lines</p>
                        <div class="h-52">
                            <Bar :data="topValueBarData" :options="moneyHorizontalBarOptions" />
                        </div>
                    </div>
                </div>

                <!-- Section C: Filterable Detailed Inventory Table -->
                <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
                    <div class="p-4 border-b border-gray-200 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">Inventory Status Manifest</h3>
                            <p class="text-xs text-gray-500">Live catalog quantities, threshold levels, and valuation bases</p>
                        </div>

                        <!-- Table Controls -->
                        <div class="flex flex-wrap items-center gap-2.5 w-full sm:w-auto">
                            <!-- Search -->
                            <div class="relative flex-1 sm:w-48">
                                <Search class="w-3.5 h-3.5 absolute left-3 top-2.5 text-gray-400" />
                                <input
                                    v-model="invSearch"
                                    type="text"
                                    placeholder="Search items..."
                                    class="w-full bg-gray-50 border border-gray-200 rounded-xl pl-8 pr-3 py-1.5 text-xs text-gray-900 focus:outline-none focus:border-gray-400"
                                />
                            </div>

                            <!-- Category Filter -->
                            <select
                                v-model="invCategoryFilter"
                                class="bg-white border border-gray-200 rounded-lg px-2.5 py-1.5 text-xs text-gray-900 font-medium focus:outline-none"
                            >
                                <option value="All">All Categories</option>
                                <option v-for="cat in categories" :key="cat" :value="cat">{{ cat }}</option>
                            </select>

                            <!-- Status Filter -->
                            <select
                                v-model="invStatusFilter"
                                class="bg-white border border-gray-200 rounded-lg px-2.5 py-1.5 text-xs text-gray-900 font-medium focus:outline-none"
                            >
                                <option value="All">All Statuses</option>
                                <option value="In Stock">In Stock</option>
                                <option value="Low Stock">Low Stock</option>
                                <option value="Out of Stock">Out of Stock</option>
                            </select>

                            <!-- Export CSV Dropdown -->
                            <SpecularButton
                                variant="secondary"
                                size="sm"
                                :radius="8"
                                @click="downloadReport('inventory', { category: invCategoryFilter, status: invStatusFilter })"
                                title="Download filtered CSV"
                            >
                                <Download class="w-3.5 h-3.5 mr-1 inline" />
                                CSV
                            </SpecularButton>
                        </div>
                    </div>

                    <!-- Table -->
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-gray-50/80 border-b border-gray-200 text-gray-600 font-semibold text-[11px]">
                                    <th class="py-3 px-4">SKU</th>
                                    <th class="py-3 px-4">Product Name</th>
                                    <th class="py-3 px-4">Category</th>
                                    <th class="py-3 px-4">Supplier</th>
                                    <th class="py-3 px-4 text-center">Stock</th>
                                    <th class="py-3 px-4 text-center">Threshold</th>
                                    <th class="py-3 px-4 text-right">Cost / Unit</th>
                                    <th class="py-3 px-4 text-right">Total Valuation</th>
                                    <th class="py-3 px-4 text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-if="!paginatedInventory.length">
                                    <td colspan="9" class="py-8 text-center text-gray-400">
                                        No matching inventory products found.
                                    </td>
                                </tr>
                                <tr
                                    v-for="item in paginatedInventory"
                                    :key="item.id"
                                    class="hover:bg-gray-50/70 transition-colors"
                                >
                                    <td class="py-3 px-4 font-mono font-medium text-gray-700">{{ item.sku }}</td>
                                    <td class="py-3 px-4 font-semibold text-gray-900">{{ item.name }}</td>
                                    <td class="py-3 px-4 text-gray-600">
                                        <span class="px-2 py-0.5 rounded bg-gray-100 text-gray-700 text-[10px] font-medium">
                                            {{ item.category }}
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-gray-600">{{ item.supplier_name }}</td>
                                    <td class="py-3 px-4 text-center font-semibold text-gray-900">{{ item.current_stock }} {{ item.unit }}</td>
                                    <td class="py-3 px-4 text-center text-gray-500">{{ item.reorder_threshold }}</td>
                                    <td class="py-3 px-4 text-right font-medium text-gray-700">{{ formatCurrency(item.cost_price || item.price) }}</td>
                                    <td class="py-3 px-4 text-right font-semibold text-gray-900">{{ formatCurrency(item.total_valuation) }}</td>
                                    <td class="py-3 px-4 text-center">
                                        <span
                                            :class="[
                                                'px-2 py-0.5 rounded-full text-[10px] font-semibold',
                                                item.stock_status === 'Out of Stock' ? 'bg-red-100 text-red-700 border border-red-200' :
                                                item.stock_status === 'Low Stock' ? 'bg-amber-100 text-amber-800 border border-amber-200' :
                                                'bg-emerald-100 text-emerald-800 border border-emerald-200'
                                            ]"
                                        >
                                            {{ item.stock_status }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="p-3 border-t border-gray-100 flex items-center justify-between text-xs text-gray-500">
                        <span>Showing {{ filteredInventory.length }} items</span>
                        <div class="flex gap-1" v-if="totalInvPages > 1">
                            <button
                                v-for="p in totalInvPages"
                                :key="p"
                                @click="invPage = p"
                                :class="[
                                    'w-7 h-7 rounded-lg font-semibold flex items-center justify-center transition-colors',
                                    invPage === p ? 'bg-[#4b5320] text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                                ]"
                            >
                                {{ p }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═════════════════════════════════════════════════════ -->
            <!-- TAB 3: LOW STOCK AUDIT REPORT                         -->
            <!-- ═════════════════════════════════════════════════════ -->
            <div v-else-if="activeTab === 'low-stock'" class="space-y-6">
                <!-- Visualizations -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="bg-white border border-gray-200 rounded-xl p-5">
                        <h4 class="text-sm font-semibold text-gray-900 mb-1">Stock Shortage Severity</h4>
                        <p class="text-xs text-gray-400 mb-3">Units below configured minimum reorder thresholds</p>
                        <div class="h-56">
                            <Bar :data="lowStockSeverityData" :options="horizontalBarOptions" />
                        </div>
                    </div>

                    <div class="bg-white border border-gray-200 rounded-xl p-5 flex flex-col justify-between">
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900 mb-1">Restock Priority Rules</h4>
                            <p class="text-xs text-gray-400 mb-3">Automated replenishment calculation methodology</p>
                            <div class="space-y-2 text-xs text-gray-600">
                                <div class="p-3 rounded-xl bg-red-50 border border-red-200 flex items-start gap-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-red-600 mt-1 shrink-0"></span>
                                    <div>
                                        <strong class="text-red-900">Critical Out of Stock:</strong> Current quantity ≤ 0.
                                        Suggested replenishment = configured reorder qty or 2× safety threshold.
                                    </div>
                                </div>
                                <div class="p-3 rounded-xl bg-amber-50 border border-amber-200 flex items-start gap-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-amber-600 mt-1 shrink-0"></span>
                                    <div>
                                        <strong class="text-amber-900">Urgent Threshold:</strong> Current quantity ≤ 50% of reorder level.
                                        Immediate procurement requisition recommended.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="pt-4 border-t border-gray-100 flex justify-between items-center text-xs">
                            <span class="text-gray-500 font-medium">Affecting {{ lowStockAnalytics.items.length }} products</span>
                            <SpecularButton variant="brand" size="sm" :radius="8" @click="goToProcurement">
                                <ShoppingCart class="w-3.5 h-3.5 mr-1 inline" />
                                Open Procurement
                            </SpecularButton>
                        </div>
                    </div>
                </div>

                <!-- Prioritization Table -->
                <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
                    <div class="p-4 border-b border-gray-200 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">Restock Prioritization Audit</h3>
                            <p class="text-xs text-gray-500">Ordered by shortage severity (Out of Stock items first)</p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
                            <div class="relative flex-1 sm:w-44">
                                <Search class="w-3.5 h-3.5 absolute left-3 top-2.5 text-gray-400" />
                                <input
                                    v-model="lowStockSearch"
                                    type="text"
                                    placeholder="Search shortage..."
                                    class="w-full bg-gray-50 border border-gray-200 rounded-xl pl-8 pr-3 py-1.5 text-xs text-gray-900 focus:outline-none"
                                />
                            </div>

                            <select
                                v-model="lowStockCategoryFilter"
                                class="bg-white border border-gray-200 rounded-lg px-2.5 py-1.5 text-xs text-gray-900 font-medium focus:outline-none"
                            >
                                <option value="All">All Categories</option>
                                <option v-for="cat in categories" :key="cat" :value="cat">{{ cat }}</option>
                            </select>

                            <SpecularButton
                                variant="secondary"
                                size="sm"
                                :radius="8"
                                @click="downloadReport('low-stock', { category: lowStockCategoryFilter })"
                            >
                                <Download class="w-3.5 h-3.5 mr-1 inline" />
                                CSV
                            </SpecularButton>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-gray-50/80 border-b border-gray-200 text-gray-600 font-semibold text-[11px]">
                                    <th class="py-3 px-4">Product Name</th>
                                    <th class="py-3 px-4">SKU</th>
                                    <th class="py-3 px-4 text-center">Available Stock</th>
                                    <th class="py-3 px-4 text-center">Threshold</th>
                                    <th class="py-3 px-4 text-center">Deficit Shortage</th>
                                    <th class="py-3 px-4 text-center font-semibold text-[#4b5320]">Suggested Reorder</th>
                                    <th class="py-3 px-4">Primary Supplier</th>
                                    <th class="py-3 px-4 text-center">Urgency</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-if="!filteredLowStock.length">
                                    <td colspan="8" class="py-8 text-center text-gray-400">
                                        No products currently below reorder thresholds.
                                    </td>
                                </tr>
                                <tr
                                    v-for="item in filteredLowStock"
                                    :key="item.id"
                                    :class="[
                                        'transition-colors',
                                        item.stock <= 0 ? 'bg-red-50/30 hover:bg-red-50/60' : 'hover:bg-gray-50/70'
                                    ]"
                                >
                                    <td class="py-3 px-4 font-semibold text-gray-900">{{ item.name }}</td>
                                    <td class="py-3 px-4 font-mono text-gray-600">{{ item.sku }}</td>
                                    <td class="py-3 px-4 text-center font-semibold" :class="item.stock <= 0 ? 'text-red-600' : 'text-amber-600'">
                                        {{ item.stock }}
                                    </td>
                                    <td class="py-3 px-4 text-center text-gray-500">{{ item.reorder_level }}</td>
                                    <td class="py-3 px-4 text-center font-semibold text-red-600">-{{ item.shortage }}</td>
                                    <td class="py-3 px-4 text-center font-semibold text-[#4b5320]">+{{ item.suggested_reorder }} units</td>
                                    <td class="py-3 px-4 text-gray-600">{{ item.supplier_name }}</td>
                                    <td class="py-3 px-4 text-center">
                                        <span
                                            :class="[
                                                'px-2.5 py-0.5 rounded-full text-[10px] font-semibold',
                                                item.urgency === 'Out of Stock' ? 'bg-red-100 text-red-700 border border-red-200' :
                                                item.urgency === 'Critical Low' ? 'bg-rose-100 text-rose-800 border border-rose-200' :
                                                'bg-amber-100 text-amber-800 border border-amber-200'
                                            ]"
                                        >
                                            {{ item.urgency }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═════════════════════════════════════════════════════ -->
            <!-- TAB 4: QUOTATIONS & SALES REPORT                      -->
            <!-- ═════════════════════════════════════════════════════ -->
            <div v-else-if="activeTab === 'sales'" class="space-y-6">
                <!-- Visualizations -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-2 bg-white border border-gray-200 rounded-xl p-5">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h4 class="text-sm font-semibold text-gray-900">Revenue Generation Timeline</h4>
                                <p class="text-xs text-gray-400">Settled invoices excluding pending or rejected quotes</p>
                            </div>
                            <span class="text-xs font-semibold px-2 py-0.5 bg-emerald-50 text-emerald-800 rounded">
                                Completed: {{ formatCurrency(metrics.totalRevenue) }}
                            </span>
                        </div>
                        <div class="h-56">
                            <Line :data="salesRevenueTrendData" :options="moneyLineOptions" />
                        </div>
                    </div>

                    <div class="bg-white border border-gray-200 rounded-xl p-5">
                        <h4 class="text-sm font-semibold text-gray-900 mb-1">Quotation Statuses</h4>
                        <p class="text-xs text-gray-400 mb-3">Conversion funnel breakdown</p>
                        <div class="h-44">
                            <Doughnut :data="quotationStatusDonutData" :options="standardChartOptions" />
                        </div>
                        <div class="text-center text-xs text-gray-500 mt-2 font-medium">
                            Conversion Rate: <strong class="text-emerald-700">{{ quotationsSalesAnalytics.conversion.rate }}%</strong>
                        </div>
                    </div>
                </div>

                <!-- Sub-tab Selector for Sales vs Quotations -->
                <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
                    <div class="p-4 border-b border-gray-200 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
                        <div class="flex items-center gap-2">
                            <button
                                @click="salesSubTab = 'sales'"
                                :class="[
                                    'px-3 py-1.5 rounded-xl text-xs font-semibold transition-colors',
                                    salesSubTab === 'sales'
                                        ? 'bg-gray-100 text-gray-900'
                                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                                ]"
                            >
                                Sales Invoices ({{ quotationsSalesAnalytics.salesList.length }})
                            </button>
                            <button
                                @click="salesSubTab = 'quotations'"
                                :class="[
                                    'px-3 py-1.5 rounded-xl text-xs font-semibold transition-colors',
                                    salesSubTab === 'quotations'
                                        ? 'bg-gray-100 text-gray-900'
                                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                                ]"
                            >
                                Customer Quotations ({{ quotationsSalesAnalytics.quotationsList.length }})
                            </button>
                        </div>

                        <div class="flex items-center gap-2">
                            <div class="relative w-44">
                                <Search class="w-3.5 h-3.5 absolute left-3 top-2.5 text-gray-400" />
                                <input
                                    v-model="salesSearch"
                                    type="text"
                                    placeholder="Search records..."
                                    class="w-full bg-gray-50 border border-gray-200 rounded-xl pl-8 pr-3 py-1.5 text-xs text-gray-900 focus:outline-none"
                                />
                            </div>

                            <SpecularButton
                                variant="secondary"
                                size="sm"
                                :radius="8"
                                @click="downloadReport('quotations-sales', { type: salesSubTab })"
                            >
                                <Download class="w-3.5 h-3.5 mr-1 inline" />
                                Export {{ salesSubTab === 'sales' ? 'Sales' : 'Quotes' }}
                            </SpecularButton>
                        </div>
                    </div>

                    <!-- Sales Table -->
                    <div v-if="salesSubTab === 'sales'" class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-gray-50/80 border-b border-gray-200 text-gray-600 font-semibold text-[11px]">
                                    <th class="py-3 px-4">Invoice #</th>
                                    <th class="py-3 px-4">Customer Name</th>
                                    <th class="py-3 px-4">Sale Date</th>
                                    <th class="py-3 px-4 text-right">Subtotal</th>
                                    <th class="py-3 px-4 text-right">Tax</th>
                                    <th class="py-3 px-4 text-right">Grand Total</th>
                                    <th class="py-3 px-4">Payment</th>
                                    <th class="py-3 px-4 text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-if="!filteredSales.length">
                                    <td colspan="8" class="py-8 text-center text-gray-400">No matching sales records found.</td>
                                </tr>
                                <tr v-for="s in filteredSales" :key="s.id" class="hover:bg-gray-50/70 transition-colors">
                                    <td class="py-3 px-4 font-mono font-semibold text-gray-900">{{ s.invoice_number }}</td>
                                    <td class="py-3 px-4 font-medium text-gray-800">{{ s.client_name }}</td>
                                    <td class="py-3 px-4 text-gray-500">{{ s.sale_date }}</td>
                                    <td class="py-3 px-4 text-right text-gray-600">{{ formatCurrency(s.subtotal) }}</td>
                                    <td class="py-3 px-4 text-right text-gray-600">{{ formatCurrency(s.tax_amount) }}</td>
                                    <td class="py-3 px-4 text-right font-semibold text-gray-900">{{ formatCurrency(s.grand_total) }}</td>
                                    <td class="py-3 px-4 text-gray-600">{{ s.payment_method }}</td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-100 text-emerald-800">
                                            {{ s.status }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Quotations Table -->
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-gray-50/80 border-b border-gray-200 text-gray-600 font-semibold text-[11px]">
                                    <th class="py-3 px-4">Quote #</th>
                                    <th class="py-3 px-4">Customer Name</th>
                                    <th class="py-3 px-4">Date Issued</th>
                                    <th class="py-3 px-4">Valid Until</th>
                                    <th class="py-3 px-4 text-right">Quoted Amount</th>
                                    <th class="py-3 px-4 text-center">Status</th>
                                    <th class="py-3 px-4 text-center">Converted</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-if="!filteredQuotations.length">
                                    <td colspan="7" class="py-8 text-center text-gray-400">No matching quotation records found.</td>
                                </tr>
                                <tr v-for="q in filteredQuotations" :key="q.id" class="hover:bg-gray-50/70 transition-colors">
                                    <td class="py-3 px-4 font-mono font-semibold text-gray-900">{{ q.quotation_number }}</td>
                                    <td class="py-3 px-4 font-medium text-gray-800">{{ q.client_name }}</td>
                                    <td class="py-3 px-4 text-gray-500">{{ q.quotation_date ? q.quotation_date.split('T')[0] : '—' }}</td>
                                    <td class="py-3 px-4 text-gray-500">{{ q.valid_until ? q.valid_until.split('T')[0] : '—' }}</td>
                                    <td class="py-3 px-4 text-right font-semibold text-gray-900">{{ formatCurrency(q.grand_total) }}</td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-gray-100 text-gray-700">
                                            {{ q.status }}
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <span v-if="q.is_converted" class="text-emerald-600 font-semibold flex items-center justify-center gap-1">
                                            <CheckCircle2 class="w-3.5 h-3.5" /> Converted
                                        </span>
                                        <span v-else class="text-gray-400">—</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═════════════════════════════════════════════════════ -->
            <!-- TAB 5: DEMAND FORECASTING                             -->
            <!-- ═════════════════════════════════════════════════════ -->
            <div v-else-if="activeTab === 'forecast'" class="space-y-6">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-2 bg-white border border-gray-200 rounded-xl p-5">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h4 class="text-sm font-semibold text-gray-900">12-Month Demand vs SMA Predictive Model</h4>
                                <p class="text-xs text-gray-400">Calculated from warehouse outgoing stock movements</p>
                            </div>
                            <SpecularButton
                                variant="secondary"
                                size="sm"
                                :radius="8"
                                @click="downloadReport('forecast', { year: String(forecastAnalytics.year) })"
                            >
                                <Download class="w-3.5 h-3.5 mr-1 inline" />
                                CSV
                            </SpecularButton>
                        </div>
                        <div class="h-64">
                            <Line :data="forecastChartData" :options="standardChartOptions" />
                        </div>
                    </div>

                    <div class="bg-white border border-gray-200 rounded-xl p-5 flex flex-col justify-between">
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900 mb-2">SMA Calculation Transparent Breakdown</h4>
                            <p class="text-xs text-gray-500 mb-4 leading-relaxed">
                                {{ forecastAnalytics.window }}-month Simple Moving Average: the plain average of the {{ forecastAnalytics.window }} previous months.
                                Monthly demand = the larger of outgoing stock movements and units on confirmed, processing or completed Sales Orders.
                                Months with no records count as 0; nothing is estimated.
                            </p>
                            <div class="p-3 bg-gray-50 border border-gray-100 rounded-xl font-mono text-xs text-gray-700 space-y-1">
                                <div>SMA = (Sum of sales during selected period) / (Number of periods)</div>
                                <div>SMA-3 = (Sales Month 1 + Sales Month 2 + Sales Month 3) / 3</div>
                                <div class="text-[#4b5320] font-semibold">Forecast for next month = SMA</div>
                            </div>
                            <p v-if="!forecastAnalytics.hasData" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-[11px] text-amber-800">
                                Insufficient data: no sales or outgoing stock records for {{ forecastAnalytics.year }}, so no forecast is shown.
                            </p>
                            <p v-else class="text-[11px] text-gray-400 mt-3">
                                Based on {{ forecastAnalytics.sourceRecordCount }} recorded transactions. Per-product SMA inputs are in the AHP Product Prioritization tab (View Calculation → Demand Forecasting).
                            </p>
                        </div>

                        <SpecularButton
                            variant="brand"
                            size="sm"
                            :radius="8"
                            @click="router.visit(`/${teamSlug}/forecast`)"
                            class="w-full mt-4"
                        >
                            Open Interactive Forecast Module →
                        </SpecularButton>
                    </div>
                </div>

                <!-- Monthly Forecast Table -->
                <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
                    <div class="p-4 border-b border-gray-200">
                        <h3 class="text-base font-semibold text-gray-900">Monthly Forecast Table (Year {{ forecastAnalytics.year }})</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-gray-50/80 border-b border-gray-200 text-gray-600 font-semibold text-[11px]">
                                    <th class="py-3 px-4">Period</th>
                                    <th class="py-3 px-4 text-center">Historical Demand (Units)</th>
                                    <th class="py-3 px-4 text-center">SMA-3 Forecast (Units)</th>
                                    <th class="py-3 px-4 text-right">Step-by-step SMA</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="(mLabel, idx) in forecastAnalytics.labels" :key="mLabel" class="hover:bg-gray-50/70">
                                    <td class="py-3 px-4 font-semibold text-gray-900">{{ mLabel }} {{ forecastAnalytics.year }}</td>
                                    <td class="py-3 px-4 text-center font-medium">{{ forecastAnalytics.historical[idx] }} units</td>
                                    <td class="py-3 px-4 text-center font-semibold text-[#4b5320]">
                                        {{ forecastAnalytics.forecast[idx] !== null ? `${forecastAnalytics.forecast[idx]} units` : '— (N/A)' }}
                                    </td>
                                    <td class="py-3 px-4 text-right text-gray-500">
                                        <template v-if="stepByMonth.get(`${mLabel} ${forecastAnalytics.year}`)">
                                            ({{ stepByMonth.get(`${mLabel} ${forecastAnalytics.year}`)!.inputs.map((i) => i.units).join(' + ') }}) / {{ forecastAnalytics.window }}
                                            = {{ stepByMonth.get(`${mLabel} ${forecastAnalytics.year}`)!.sum }} / {{ forecastAnalytics.window }}
                                        </template>
                                        <template v-else>Needs {{ forecastAnalytics.window }} prior months</template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═════════════════════════════════════════════════════ -->
            <!-- TAB 6: PURCHASING & PROCUREMENT                       -->
            <!-- ═════════════════════════════════════════════════════ -->
            <div v-else-if="activeTab === 'procurement'" class="space-y-6">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="bg-white border border-gray-200 rounded-xl p-5">
                        <h4 class="text-sm font-semibold text-gray-900 mb-1">Purchase Request Statuses</h4>
                        <p class="text-xs text-gray-400 mb-3">Requisition fulfillment distribution</p>
                        <div class="h-48">
                            <Doughnut :data="procurementDonutData" :options="standardChartOptions" />
                        </div>
                    </div>

                    <div class="lg:col-span-2 bg-white border border-gray-200 rounded-xl p-5 flex flex-col justify-between">
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900 mb-1">Procurement Operational Summary</h4>
                            <p class="text-xs text-gray-400 mb-4">Total estimated acquisition commitments</p>
                            <div class="grid grid-cols-3 gap-4">
                                <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                                    <span class="text-xs text-gray-500 font-semibold block">Total Requisitions</span>
                                    <span class="text-xl font-semibold text-gray-900">{{ metrics.totalPurchasesCount }}</span>
                                </div>
                                <div class="p-3 bg-blue-50 rounded-xl border border-blue-100">
                                    <span class="text-xs text-blue-800 font-semibold block">Active Orders</span>
                                    <span class="text-xl font-semibold text-blue-900">{{ procurementAnalytics.statusDistribution['Ordered'] || 0 }}</span>
                                </div>
                                <div class="p-3 bg-emerald-50 rounded-xl border border-emerald-100">
                                    <span class="text-xs text-emerald-800 font-semibold block">Delivered</span>
                                    <span class="text-xl font-semibold text-emerald-900">{{ procurementAnalytics.statusDistribution['Delivered'] || 0 }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="pt-4 border-t border-gray-100 flex justify-between items-center text-xs">
                            <span class="text-gray-500">Total Committed Spend: <strong class="text-gray-900 font-semibold">{{ formatCurrency(procurementAnalytics.totalEstimatedCost) }}</strong></span>
                            <SpecularButton variant="secondary" size="sm" :radius="8" @click="downloadReport('procurement')">
                                <Download class="w-3.5 h-3.5 mr-1 inline" />
                                Export Procurement CSV
                            </SpecularButton>
                        </div>
                    </div>
                </div>

                <!-- Requisitions Table -->
                <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
                    <div class="p-4 border-b border-gray-200 flex justify-between items-center">
                        <h3 class="text-base font-semibold text-gray-900">Purchase Orders &amp; Requests Ledger</h3>
                        <SpecularButton variant="brand" size="sm" :radius="8" @click="goToProcurement">
                            <ShoppingCart class="w-3.5 h-3.5 mr-1 inline" />
                            Manage Procurement
                        </SpecularButton>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-gray-50/80 border-b border-gray-200 text-gray-600 font-semibold text-[11px]">
                                    <th class="py-3 px-4">Request #</th>
                                    <th class="py-3 px-4">Item Name (SKU)</th>
                                    <th class="py-3 px-4">Supplier</th>
                                    <th class="py-3 px-4">Requested By</th>
                                    <th class="py-3 px-4 text-center">Qty</th>
                                    <th class="py-3 px-4 text-right">Est. Cost</th>
                                    <th class="py-3 px-4 text-center">Status</th>
                                    <th class="py-3 px-4">Date</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-if="!procurementAnalytics.requestsList.length">
                                    <td colspan="8" class="py-8 text-center text-gray-400">No purchase requests found.</td>
                                </tr>
                                <tr v-for="r in procurementAnalytics.requestsList" :key="r.id" class="hover:bg-gray-50/70">
                                    <td class="py-3 px-4 font-mono font-semibold text-gray-900">{{ r.request_number }}</td>
                                    <td class="py-3 px-4">
                                        <div class="font-semibold text-gray-900">{{ r.product_name }}</div>
                                        <div class="text-[10px] text-gray-400 font-mono">{{ r.sku }}</div>
                                    </td>
                                    <td class="py-3 px-4 text-gray-700">{{ r.supplier_name }}</td>
                                    <td class="py-3 px-4 text-gray-600">{{ r.requested_by }}</td>
                                    <td class="py-3 px-4 text-center font-semibold text-gray-900">{{ r.quantity }}</td>
                                    <td class="py-3 px-4 text-right font-semibold text-gray-900">{{ formatCurrency(r.estimated_cost) }}</td>
                                    <td class="py-3 px-4 text-center">
                                        <span
                                            :class="[
                                                'px-2 py-0.5 rounded-full text-[10px] font-semibold',
                                                r.status === 'Delivered' ? 'bg-emerald-100 text-emerald-800' :
                                                r.status === 'Ordered' ? 'bg-blue-100 text-blue-800' :
                                                r.status === 'Requested' ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-700'
                                            ]"
                                        >
                                            {{ r.status }}
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-gray-500">{{ r.date }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═════════════════════════════════════════════════════ -->
            <!-- TAB 7: AHP PRODUCT PRIORITIZATION                     -->
            <!-- ═════════════════════════════════════════════════════ -->
            <div v-else-if="activeTab === 'priority'" class="space-y-6">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="bg-white border border-gray-200 rounded-xl p-5">
                        <h4 class="text-sm font-semibold text-gray-900 mb-2">AHP Methodology and Criteria Weights</h4>
                        <p class="text-xs text-gray-500 mb-3 leading-relaxed">
                            Each criterion is normalized to a 0–100 score, multiplied by its fixed weight, and the weighted results are added.
                            A higher score means a higher replenishment priority. Weights are the approved fixed configuration and cannot be changed in the UI.
                        </p>
                        <table class="w-full text-left text-xs">
                            <thead><tr class="text-gray-500"><th class="py-1">Criterion</th><th class="text-right">Weight</th></tr></thead>
                            <tbody>
                                <tr class="border-t border-gray-100"><td class="py-1.5">Demand Forecasting</td><td class="text-right font-mono">{{ pctW(productPriority.weights.demand) }}</td></tr>
                                <tr class="border-t border-gray-100"><td class="py-1.5">Current Stock</td><td class="text-right font-mono">{{ pctW(productPriority.weights.stock) }}</td></tr>
                                <tr class="border-t border-gray-100"><td class="py-1.5">Product Movement</td><td class="text-right font-mono">{{ pctW(productPriority.weights.movement) }}</td></tr>
                                <tr class="border-t border-gray-100"><td class="py-1.5">Supplier Availability</td><td class="text-right font-mono">{{ pctW(productPriority.weights.supplier_availability) }}</td></tr>
                            </tbody>
                        </table>
                        <p class="mt-3 p-2.5 rounded-lg bg-gray-50 border border-gray-100 font-mono text-[11px] text-gray-700">
                            AHP Score = (Demand × {{ productPriority.weights.demand }}) + (Current Stock × {{ productPriority.weights.stock }}) + (Product Movement × {{ productPriority.weights.movement }}) + (Supplier Availability × {{ productPriority.weights.supplier_availability }})
                        </p>
                        <p class="mt-2 text-[11px] text-gray-500">
                            <span v-for="b in classBands(productPriority.classes)" :key="b.min" class="mr-2">{{ b.label }} ≥ {{ b.min }}</span>
                        </p>
                    </div>

                    <div class="bg-white border border-gray-200 rounded-xl p-5">
                        <h4 class="text-sm font-semibold text-gray-900 mb-2">Criteria Scoring (normalized 0–100)</h4>
                        <div class="space-y-2 text-xs">
                            <div class="p-2.5 rounded-lg bg-gray-50 border border-gray-100">
                                <p class="font-semibold text-gray-800">Demand Forecasting</p>
                                <p class="font-mono text-[11px] text-gray-600">(SMA-{{ productPriority.smaWindow }} forecast / Highest forecast) × 100</p>
                                <p class="text-[11px] text-gray-500">Higher forecasted demand → higher priority.</p>
                            </div>
                            <div class="p-2.5 rounded-lg bg-gray-50 border border-gray-100">
                                <p class="font-semibold text-gray-800">Current Stock</p>
                                <p class="font-mono text-[11px] text-gray-600">(1 − Available stock / Highest available stock) × 100</p>
                                <p class="text-[11px] text-gray-500">Available = on hand − reserved. Lower stock → higher priority.</p>
                            </div>
                            <div class="p-2.5 rounded-lg bg-gray-50 border border-gray-100">
                                <p class="font-semibold text-gray-800">Product Movement</p>
                                <p class="font-mono text-[11px] text-gray-600">(Units moved / Highest units moved) × 100</p>
                                <p class="text-[11px] text-gray-500">Last {{ productPriority.movementMonths }} complete months. Faster-moving → higher priority.</p>
                            </div>
                            <div class="p-2.5 rounded-lg bg-gray-50 border border-gray-100">
                                <p class="font-semibold text-gray-800">Supplier Availability</p>
                                <p class="font-mono text-[11px] text-gray-600">(1 − Available suppliers / Verified suppliers) × 100</p>
                                <p class="text-[11px] text-gray-500">Fewer suppliers able to supply → harder sourcing → higher priority.</p>
                            </div>
                            <div class="p-2.5 rounded-lg bg-gray-50 border border-gray-100">
                                <p class="font-semibold text-gray-800">Reorder recommendation</p>
                                <p class="text-[11px] text-gray-500">Reorder when available stock ≤ reorder level or &lt; SMA forecast. Suggested quantity = max(reorder quantity, forecast − available).</p>
                            </div>
                            <p class="text-[11px] text-amber-700">A criterion without data is "insufficient data": it is not counted as 0, its weight is not moved, and the product gets no final score.</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
                    <div class="p-4 border-b border-gray-200">
                        <h3 class="text-base font-semibold text-gray-900">Weighted Contributions per Product</h3>
                        <p class="text-xs text-gray-500">Score (0–100) → weighted contribution for each criterion. Same results as the Inventory page.</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-gray-50/80 border-b border-gray-200 text-gray-600 font-semibold text-[11px]">
                                    <th class="py-3 px-4 text-center">Rank</th>
                                    <th class="py-3 px-4">Product</th>
                                    <th class="py-3 px-4 text-center">Demand</th>
                                    <th class="py-3 px-4 text-center">Current Stock</th>
                                    <th class="py-3 px-4 text-center">Movement</th>
                                    <th class="py-3 px-4 text-center">Supplier Avail.</th>
                                    <th class="py-3 px-4 text-center text-[#4b5320]">AHP Score</th>
                                    <th class="py-3 px-4">Priority</th>
                                    <th class="py-3 px-4">Reorder</th>
                                    <th class="py-3 px-4 text-center">Calculation</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-if="!productPriority.items.length">
                                    <td colspan="10" class="py-8 text-center text-gray-400">No active products.</td>
                                </tr>
                                <tr v-for="(p, i) in productPriority.items" :key="p.product.id" class="hover:bg-gray-50/70">
                                    <td class="py-3 px-4 text-center font-semibold text-gray-700">{{ p.complete ? `#${i + 1}` : '—' }}</td>
                                    <td class="py-3 px-4">
                                        <div class="font-semibold text-gray-900">{{ p.product.name }}</div>
                                        <div class="font-mono text-[11px] text-gray-500">{{ p.product.sku }}</div>
                                    </td>
                                    <td v-for="k in priorityKeys" :key="k" class="py-3 px-4 text-center font-mono">
                                        <template v-if="priorityContribution(p, k)?.available">{{ priorityContribution(p, k)!.score!.toFixed(2) }} → {{ priorityContribution(p, k)!.weighted!.toFixed(2) }}</template>
                                        <span v-else class="font-sans text-amber-700">Insufficient data</span>
                                    </td>
                                    <td class="py-3 px-4 text-center font-semibold text-[#4b5320]">{{ p.complete ? p.score!.toFixed(2) : 'Incomplete' }}</td>
                                    <td class="py-3 px-4 text-gray-700">{{ p.classification ?? 'Not classified' }}</td>
                                    <td class="py-3 px-4" :class="p.reorder.needed ? 'text-amber-700 font-medium' : 'text-gray-500'">{{ p.reorder.needed ? `Reorder ${p.reorder.suggested_quantity}` : 'No' }}</td>
                                    <td class="py-3 px-4 text-center"><button type="button" class="text-xs font-medium text-[#4b5320] hover:underline" @click="selectedProductPriority = p">View Calculation</button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═════════════════════════════════════════════════════ -->
            <!-- TAB 8: SUPPLIER EVALUATION                    -->
            <!-- ═════════════════════════════════════════════════════ -->
            <div v-else-if="activeTab === 'ahp'" class="space-y-6">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-2 bg-white border border-gray-200 rounded-xl p-5">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h4 class="text-sm font-semibold text-gray-900">Supplier Evaluation Ranking</h4>
                                <p class="text-xs text-gray-400">Weighted scores from delivery, quotation and inspection records</p>
                            </div>
                            <SpecularButton variant="secondary" size="sm" :radius="8" @click="downloadReport('ahp')">
                                <Download class="w-3.5 h-3.5 mr-1 inline" />
                                Export AHP CSV
                            </SpecularButton>
                        </div>
                        <div class="h-60">
                            <Bar :data="ahpBarData" :options="horizontalBarOptions" />
                        </div>
                    </div>

                    <div class="bg-white border border-gray-200 rounded-xl p-5 flex flex-col justify-between">
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900 mb-2">Supplier Evaluation Methodology</h4>
                            <p class="text-xs text-gray-500 mb-3 leading-relaxed">
                                Calculated only from recorded transactions in the last {{ ahpAnalytics.periodMonths }} months. Nobody can enter or edit a score.
                                Weights are the approved fixed configuration (config/evaluation.php).
                            </p>
                            <div class="space-y-2 text-xs">
                                <div class="p-2.5 rounded-lg bg-gray-50 border border-gray-100">
                                    <div class="flex justify-between"><span class="font-semibold text-gray-800">1. Inbound Delivery Performance</span><span class="font-mono">{{ pctW(ahpAnalytics.weights.delivery) }}</span></div>
                                    <p class="mt-1 font-mono text-[11px] text-gray-600">(On-time rate × {{ ahpAnalytics.deliverySplit.on_time }}) + (Quantity fulfillment × {{ ahpAnalytics.deliverySplit.fulfillment }})</p>
                                    <p class="text-[11px] text-gray-500">Deliveries vs. the purchase order's promised date; accepted vs. ordered quantity.</p>
                                </div>
                                <div class="p-2.5 rounded-lg bg-gray-50 border border-gray-100">
                                    <div class="flex justify-between"><span class="font-semibold text-gray-800">2. Price Competitiveness</span><span class="font-mono">{{ pctW(ahpAnalytics.weights.price) }}</span></div>
                                    <p class="mt-1 font-mono text-[11px] text-gray-600">Average of (Lowest quoted price / Supplier's price) × 100</p>
                                    <p class="text-[11px] text-gray-500">Verified, unexpired quotes for the same product, unit, currency and tax basis.</p>
                                </div>
                                <div class="p-2.5 rounded-lg bg-gray-50 border border-gray-100">
                                    <div class="flex justify-between"><span class="font-semibold text-gray-800">3. Product Quality</span><span class="font-mono">{{ pctW(ahpAnalytics.weights.quality) }}</span></div>
                                    <p class="mt-1 font-mono text-[11px] text-gray-600">(Accepted units / Inspected units) × 100</p>
                                    <p class="text-[11px] text-gray-500">Goods-receipt inspection records.</p>
                                </div>
                                <p class="p-2.5 rounded-lg bg-gray-50 border border-gray-100 font-mono text-[11px] text-gray-700">
                                    Score = (Delivery × {{ ahpAnalytics.weights.delivery }}) + (Price × {{ ahpAnalytics.weights.price }}) + (Quality × {{ ahpAnalytics.weights.quality }})
                                </p>
                                <p class="text-[11px] text-gray-500">
                                    <span v-for="b in classBands(ahpAnalytics.classes)" :key="b.min" class="mr-2">{{ b.label }} ≥ {{ b.min }}</span>
                                </p>
                                <p class="text-[11px] text-amber-700">A criterion without records is "insufficient data": it is not counted as 0, and the supplier gets no final score or rank.</p>
                            </div>
                        </div>

                        <SpecularButton
                            variant="brand"
                            size="sm"
                            :radius="8"
                            @click="router.visit(`/${teamSlug}/suppliers`)"
                            class="w-full mt-4"
                        >
                            Open Supplier Module →
                        </SpecularButton>
                    </div>
                </div>

                <!-- Supplier Ranking Table -->
                <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
                    <div class="p-4 border-b border-gray-200">
                        <h3 class="text-base font-semibold text-gray-900">Ranked Supplier Directory</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-gray-50/80 border-b border-gray-200 text-gray-600 font-semibold text-[11px]">
                                    <th class="py-3 px-4 text-center">Rank</th>
                                    <th class="py-3 px-4">Supplier Name</th>
                                    <th class="py-3 px-4">Contact Person</th>
                                    <th class="py-3 px-4">Phone / Email</th>
                                    <th class="py-3 px-4 text-center font-semibold text-[#4b5320]">Evaluation Score</th>
                                    <th class="py-3 px-4 text-center">Delivery ({{ pctW(ahpAnalytics.weights.delivery) }})</th>
                                    <th class="py-3 px-4 text-center">Price ({{ pctW(ahpAnalytics.weights.price) }})</th>
                                    <th class="py-3 px-4 text-center">Quality ({{ pctW(ahpAnalytics.weights.quality) }})</th>
                                    <th class="py-3 px-4 text-center">Classification</th>
                                    <th class="py-3 px-4 text-center">Status</th>
                                    <th class="py-3 px-4 text-center">Calculation</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-if="!ahpAnalytics.suppliersList.length">
                                    <td colspan="11" class="py-8 text-center text-gray-400">No suppliers registered.</td>
                                </tr>
                                <tr v-for="s in ahpAnalytics.suppliersList" :key="s.id" class="hover:bg-gray-50/70">
                                    <td class="py-3 px-4 text-center font-semibold text-gray-700">{{ s.rank ? `#${s.rank}` : '—' }}</td>
                                    <td class="py-3 px-4 font-semibold text-gray-900">{{ s.name }}</td>
                                    <td class="py-3 px-4 text-gray-700">{{ s.contact_person }}</td>
                                    <td class="py-3 px-4 text-gray-500 font-mono">{{ s.phone }}</td>
                                    <td class="py-3 px-4 text-center font-semibold text-[#4b5320] text-sm">
                                        {{ s.complete ? Number(s.ahp_score).toFixed(2) : 'Insufficient data' }}
                                    </td>
                                    <td v-for="k in (['delivery', 'price', 'quality'] as const)" :key="k" class="py-3 px-4 text-center text-gray-600">{{ s.metrics?.[k] != null ? Number(s.metrics[k]).toFixed(2) : '—' }}</td>
                                    <td class="py-3 px-4 text-center text-gray-600">{{ s.classification ?? 'Not classified' }}</td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-100 text-emerald-800">
                                            {{ s.status }}
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-center"><button type="button" class="text-xs font-medium text-[#4b5320] hover:underline" @click="selectedSupplierEval = s">View Calculation</button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Calculation breakdown modals (same components as Inventory and Suppliers) -->
        <div v-if="selectedProductPriority" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 print:hidden" @click.self="selectedProductPriority = null">
            <div class="flex max-h-[92vh] w-full max-w-2xl flex-col overflow-hidden rounded-xl border border-gray-200 bg-white" role="dialog" aria-modal="true" aria-labelledby="rp-priority-title">
                <div class="flex items-start justify-between gap-3 border-b border-gray-100 px-6 py-4">
                    <div>
                        <h3 id="rp-priority-title" class="text-base font-semibold text-gray-900">{{ selectedProductPriority.product.name }}</h3>
                        <p class="font-mono text-xs text-gray-500">{{ selectedProductPriority.product.sku }}</p>
                    </div>
                    <div class="text-right">
                        <div class="text-xl font-semibold text-gray-900">{{ selectedProductPriority.complete ? `${selectedProductPriority.score!.toFixed(2)} / 100` : 'Incomplete' }}</div>
                        <div class="text-xs text-gray-500">{{ selectedProductPriority.classification ?? 'Not classified' }}</div>
                    </div>
                </div>
                <div class="overflow-y-auto px-6 py-4"><ProductPriorityBreakdown :priority="selectedProductPriority" /></div>
                <div class="flex justify-end border-t border-gray-100 bg-gray-50/50 px-6 py-3">
                    <button type="button" class="secondary-button" @click="selectedProductPriority = null">Close</button>
                </div>
            </div>
        </div>

        <div v-if="selectedSupplierEval" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 print:hidden" @click.self="selectedSupplierEval = null">
            <div class="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-xl border border-gray-200 bg-white" role="dialog" aria-modal="true" aria-labelledby="rp-supplier-title">
                <div class="flex items-start justify-between gap-3 border-b border-gray-100 px-6 py-4">
                    <h3 id="rp-supplier-title" class="text-base font-semibold text-gray-900">{{ selectedSupplierEval.name }}</h3>
                    <div class="text-right">
                        <div class="text-xl font-semibold text-gray-900">{{ selectedSupplierEval.complete ? `${Number(selectedSupplierEval.ahp_score).toFixed(2)} / 100` : 'Insufficient data' }}</div>
                        <div class="text-xs text-gray-500">{{ selectedSupplierEval.rank ? `Rank #${selectedSupplierEval.rank} · ` : '' }}{{ selectedSupplierEval.classification ?? 'Not classified' }}</div>
                    </div>
                </div>
                <div class="overflow-y-auto px-6 py-4"><SupplierEvaluationBreakdown :evaluation="selectedSupplierEval.evaluation" :weights="ahpAnalytics.weights" /></div>
                <div class="flex justify-end border-t border-gray-100 bg-gray-50/50 px-6 py-3">
                    <button type="button" class="secondary-button" @click="selectedSupplierEval = null">Close</button>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
@media print {
    /* Hide all system chrome, sidebar, and buttons */
    nav, aside, header, .print\\:hidden, button, select, input {
        display: none !important;
    }
    body, .workspace-page {
        padding: 0 !important;
        margin: 0 !important;
        background: #ffffff !important;
        color: #000000 !important;
    }
    table {
        page-break-inside: auto;
    }
    tr {
        page-break-inside: avoid;
        page-break-after: auto;
    }
}
</style>
