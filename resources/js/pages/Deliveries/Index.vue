<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { useCanDelete } from '@/composables/useCanDelete';
import { showToast } from '@/composables/useFlashToast';
import { ref, computed, watch } from 'vue';
import SpecularButton from '@/components/ui/SpecularButton.vue';
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
    Eye,
    Phone,
    MapPin,
    User as UserIcon,
    AlertTriangle,
    RotateCcw,
    Camera,
    Image as ImageIcon,
    History,
    UserCheck,
    Navigation,
    ShieldAlert,
    ExternalLink
} from '@lucide/vue';

interface AttemptLogItem {
    id: number;
    attempt_number: number;
    previous_status: string;
    new_status: string;
    staff_id: number;
    staff?: { id: number; name: string };
    failure_reason?: string | null;
    remarks?: string | null;
    proof_image?: string | null;
    proof_url?: string | null;
    attempt_date: string;
}

interface OutboundDeliveryItem {
    id: number;
    fulfillment_number: string;
    fulfillment_type: string;
    recipient_name: string;
    contact_number: string;
    delivery_address?: string | null;
    delivery_schedule?: string | null;
    instructions?: string | null;
    status: string;
    status_label?: string;
    allowed_actions?: string[];
    status_choices?: StatusChoice[];
    open_release?: ReleaseItem | null;
    releases?: ReleaseItem[];
    attempt_count: number;
    remaining_attempts: number;
    attempts_display: string;
    proof_of_delivery?: string | null;
    proof_url?: string | null;
    handled_by_id?: number | null;
    handled_by?: { id: number; name: string; email: string; role: string } | null;
    dispatched_at?: string | null;
    delivered_at?: string | null;
    rescheduled_at?: string | null;
    rescheduled_by?: { id: number; name: string } | null;
    notes?: string | null;
    sale?: {
        id: number;
        order_number: string;
        invoice_number?: string | null;
        client_name: string;
        grand_total: number;
        sale_date: string;
        status?: string;
        channel?: string;
        hold_reason?: string | null;
        items?: SaleLine[];
    } | null;
    commercial_invoice?: {
        id: number;
        invoice_number: string;
        total_cost: number;
    } | null;
    attempt_logs?: AttemptLogItem[];
}

interface StatusChoice {
    action: string;
    status: string | null;
    label: string;
    needs: 'quantities' | 'receipt' | 'attempt' | 'schedule' | 'reason' | null;
}

interface SaleLine {
    id: number;
    sku: string;
    product_name: string;
    quantity: number;
    unit_price: number;
    reserved_quantity: number;
    released_quantity: number;
    delivered_quantity: number;
    cancelled_quantity: number;
    remaining_quantity: number;
    releasable_quantity: number;
}

interface ReleaseItem {
    id: number;
    release_number: string;
    type: 'delivery' | 'pickup';
    status: string;
    dispatched_at?: string | null;
    delivered_at?: string | null;
    received_by_name?: string | null;
    return_reason?: string | null;
    proof_url?: string | null;
    created_at: string;
    items: { id: number; sale_item_id: number; quantity: number; delivered_quantity: number; returned_quantity: number; sale_item?: { sku: string; product_name: string } | null }[];
}

interface InboundDeliveryItem {
    id: number;
    delivery_date: string;
    delivery_number: string;
    purchase_order_number?: string | null;
    purchase_order_id?: number | null;
    quantity: number;
    unit: string;
    description: string;
    supplier_id?: number | null;
    received_by_id?: number | null;
    status: string;
    notes?: string | null;
    supplier?: { id: number; name: string };
    purchase_order?: { id: number; po_number: string; promised_delivery_date?: string | null };
    received_by?: { id: number; name: string };
    accepted_quantity?: number | null;
    rejected_quantity?: number | null;
    inspection_result?: 'accepted' | 'partially_accepted' | 'rejected' | null;
    inspected_on?: string | null;
    inspection_remarks?: string | null;
    rejection_reason?: string | null;
    rejection_category?: string | null;
    damaged_quantity?: number | null;
    defective_quantity?: number | null;
    inspected_by?: { id: number; name: string } | null;
    inspection_status?: string;
    posted_at?: string | null;
    posted_quantity?: number | null;
    invoices?: { id: number; invoice_number: string; status: string }[];
}

interface ProductItem {
    id: number;
    name: string;
    sku: string;
    cost_price: number;
    stock: number;
}

interface SupplierItem {
    id: number;
    name: string;
}

interface PurchaseOrderItem {
    id: number;
    po_number: string;
    item_sku?: string | null;
    description: string;
    unit_of_measure?: string | null;
    quantity: number;
    supplier_id?: number | null;
    status?: string;
    remaining?: number;
}

const props = defineProps<{
    activeTab: 'outbound' | 'inbound';
    isDeliveryStaff: boolean;
    isAdmin: boolean;
    outboundDeliveries?: {
        data: OutboundDeliveryItem[];
        current_page: number;
        last_page: number;
        total: number;
        links: any[];
    };
    outboundMetrics?: {
        totalAssigned: number;
        pending: number;
        inTransit: number;
        delivered: number;
        failed: number;
        attemptsRemaining: number;
    };
    deliveryStaffList?: Array<{ id: number; name: string; email: string; role: string }>;
    statusOptions?: Array<{ value: string; label: string; badgeClass: string }>;
    canPrepare?: boolean;
    inboundDeliveries?: {
        data: InboundDeliveryItem[];
        current_page: number;
        last_page: number;
        total: number;
        links: any[];
    } | null;
    inboundMetrics?: {
        totalDeliveries: number;
        totalQuantityDelivered: number;
        linkedPosCount: number;
        deliveredCount: number;
    } | null;
    products?: ProductItem[];
    suppliers?: SupplierItem[];
    purchaseOrders?: PurchaseOrderItem[];
    filters?: {
        tab?: string;
        search?: string;
        status?: string;
        staff_id?: string | number;
        date_from?: string;
        date_to?: string;
    };
}>();

const page = usePage();
const canDelete = useCanDelete();
const teamSlug = computed(() => {
    const pageTeam = (page.props as any).currentTeam?.slug || (page.props as any).team?.slug;
    if (pageTeam) return pageTeam;
    if (typeof window !== 'undefined') {
        const pathSegments = window.location.pathname.split('/').filter(Boolean);
        if (pathSegments.length > 0 && pathSegments[0] !== 'deliveries') {
            return pathSegments[0];
        }
    }
    return 'mr-pride';
});

// Flash toasts are handled globally by useFlashToast in AppSidebarLayout

const formatDate = (dateStr?: string | null) => {
    if (!dateStr) return '—';
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return dateStr;
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
};

const formatDateTime = (dateStr?: string | null) => {
    if (!dateStr) return '—';
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return dateStr;
    return d.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true });
};

// Current Tab State
const currentTab = ref<'outbound' | 'inbound'>(props.activeTab || 'outbound');
const switchTab = (tab: 'outbound' | 'inbound') => {
    currentTab.value = tab;
    router.get(
        `/${teamSlug.value}/deliveries`,
        { tab, search: search.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true }
    );
};

// Search & Filter State
const search = ref(props.filters?.search || '');
const selectedStatus = ref(props.filters?.status || 'All');
const selectedStaff = ref(props.filters?.staff_id !== undefined ? String(props.filters.staff_id) : 'All');
const dateFrom = ref(props.filters?.date_from || '');
const dateTo = ref(props.filters?.date_to || '');

let filterTimeout: ReturnType<typeof setTimeout> | null = null;
const applyFilter = () => {
    if (filterTimeout) clearTimeout(filterTimeout);
    filterTimeout = setTimeout(() => {
        router.get(
            `/${teamSlug.value}/deliveries`,
            {
                tab: currentTab.value,
                search: search.value || undefined,
                status: selectedStatus.value !== 'All' ? selectedStatus.value : undefined,
                staff_id: selectedStaff.value !== 'All' ? selectedStaff.value : undefined,
                date_from: dateFrom.value || undefined,
                date_to: dateTo.value || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true }
        );
    }, 300);
};

const resetFilters = () => {
    search.value = '';
    selectedStatus.value = 'All';
    selectedStaff.value = 'All';
    dateFrom.value = '';
    dateTo.value = '';
    applyFilter();
};

const hasActiveFilters = computed(() => {
    return !!search.value || selectedStatus.value !== 'All' || selectedStaff.value !== 'All' || !!dateFrom.value || !!dateTo.value;
});

// Helper: Status badge styling (codes and labels come from the server)
const getStatusBadgeClass = (status: string) =>
    props.statusOptions?.find((o) => o.value === status)?.badgeClass ?? 'bg-gray-50 text-muted-foreground border-gray-200';
const statusLabel = (status: string) => props.statusOptions?.find((o) => o.value === status)?.label ?? status;
const can = (item: OutboundDeliveryItem, action: string) => !!item.allowed_actions?.includes(action);
const releaseStatusText: Record<string, string> = { preparing: 'Being prepared', ready: 'Ready', dispatched: 'On the way', delivered: 'Received', returned: 'Returned', cancelled: 'Cancelled' };
const outstanding = (item: OutboundDeliveryItem) => (item.sale?.items ?? []).filter((l) => l.remaining_quantity > 0);
const getAttemptBadgeClass = (count: number) => {
    if (count === 0) return 'bg-emerald-50 text-emerald-600 border-emerald-200';
    if (count === 1) return 'bg-blue-50 text-blue-600 border-blue-200';
    if (count === 2) return 'bg-amber-50 text-amber-600 border-amber-200 font-semibold';
    return 'bg-rose-50 text-rose-600 border-rose-200 font-semibold';
};

// ==========================================
// OUTBOUND DELIVERY ACTION MODALS
// ==========================================
const selectedFulfillment = ref<OutboundDeliveryItem | null>(null);

// 1. Details Modal
const showDetailsModal = ref(false);
const openDetails = (item: OutboundDeliveryItem) => {
    selectedFulfillment.value = item;
    showDetailsModal.value = true;
};

// "Update status": one form for every status change this user may make now. The choices and
// the rules come from the server; the form only asks for what the chosen change needs.
const failureReasons = ['Customer unavailable', 'Incorrect or incomplete address', 'Customer refused delivery', 'Customer unreachable', 'Other reason'];
const showStatusUpdate = ref(false);
const statusChoice = ref<StatusChoice | null>(null);
const statusSaving = ref(false);
const statusForm = ref({
    remarks: '',
    reason: '',
    failure_reason: failureReasons[0],
    recipient_name: '',
    quantities: {} as Record<number, number>,
    delivery_schedule: '',
    reset_attempts: true,
    photo: null as File | null,
});
const openStatusUpdate = (item: OutboundDeliveryItem) => {
    selectedFulfillment.value = item;
    statusChoice.value = item.status_choices?.[0] ?? null;
    resetStatusForm();
    showStatusUpdate.value = true;
};
const resetStatusForm = () => {
    const item = selectedFulfillment.value;
    const choice = statusChoice.value;
    const quantities = choice?.needs === 'quantities'
        ? Object.fromEntries((item?.sale?.items ?? []).map((l) => [l.id, l.releasable_quantity]))
        : Object.fromEntries((item?.open_release?.items ?? []).map((i) => [i.sale_item_id, i.quantity]));
    statusForm.value = {
        remarks: '',
        reason: '',
        failure_reason: failureReasons[0],
        recipient_name: choice?.action === 'deliver' ? item?.recipient_name ?? '' : '',
        quantities,
        delivery_schedule: new Date(Date.now() + 86400000).toISOString().slice(0, 16),
        reset_attempts: true,
        photo: null,
    };
};
watch(statusChoice, () => resetStatusForm());
const statusNeedsPhoto = computed(() => statusChoice.value?.action === 'deliver');
const canSubmitStatus = computed(() => {
    const c = statusChoice.value;
    const f = statusForm.value;
    if (!c || statusSaving.value) return false;
    if (c.needs === 'quantities') return Object.values(f.quantities).some((q) => Number(q) > 0);
    if (c.needs === 'reason') return f.reason.trim().length > 0;
    if (c.needs === 'attempt') return f.remarks.trim().length >= 3;
    if (c.needs === 'schedule') return !!f.delivery_schedule;
    if (c.needs === 'receipt') return !!f.recipient_name.trim() && (!statusNeedsPhoto.value || !!f.photo);
    return true;
});
const submitStatusUpdate = () => {
    const item = selectedFulfillment.value;
    const c = statusChoice.value;
    if (!item || !c || !canSubmitStatus.value) return;
    const f = statusForm.value;
    const data: Record<string, any> = { action: c.action, remarks: f.remarks || undefined };
    if (c.needs === 'quantities') data.quantities = f.quantities;
    if (c.needs === 'reason') data.reason = f.reason.trim();
    if (c.needs === 'attempt') Object.assign(data, { reason: f.failure_reason, remarks: f.remarks, attempt_photo: f.photo ?? undefined });
    if (c.needs === 'schedule') Object.assign(data, { delivery_schedule: f.delivery_schedule, reset_attempts: f.reset_attempts ? 1 : 0, notes: f.remarks || undefined });
    if (c.needs === 'receipt') Object.assign(data, { quantities: f.quantities, recipient_name: f.recipient_name.trim(), proof_of_delivery: f.photo ?? undefined });
    statusSaving.value = true;
    router.post(`/${teamSlug.value}/deliveries/fulfillments/${item.id}/status`, data, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            showStatusUpdate.value = false;
        },
        onError: (err: any) => showToast(Object.values(err)[0] as string || 'The status could not be updated.', 'error'),
        onFinish: () => (statusSaving.value = false),
    });
};

// Prepare a release: choose what goes out next (defaults to everything still to send).
const showPrepareModal = ref(false);
const prepareQuantities = ref<Record<number, number>>({});
const prepareRemarks = ref('');
const preparing = ref(false);
const openPrepare = (item: OutboundDeliveryItem) => {
    selectedFulfillment.value = item;
    prepareQuantities.value = Object.fromEntries((item.sale?.items ?? []).map((l) => [l.id, l.releasable_quantity]));
    prepareRemarks.value = '';
    showPrepareModal.value = true;
};
const submitPrepare = () => {
    if (!selectedFulfillment.value) return;
    preparing.value = true;
    router.post(`/${teamSlug.value}/deliveries/fulfillments/${selectedFulfillment.value.id}/releases`, {
        quantities: prepareQuantities.value,
        remarks: prepareRemarks.value || undefined,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            showPrepareModal.value = false;
        },
        onError: (err: any) => showToast(err.items || err.error || 'The release could not be prepared.', 'error'),
        onFinish: () => (preparing.value = false),
    });
};

const postAction = (url: string, data: Record<string, any> = {}, done?: () => void) => {
    router.post(url, data, {
        preserveScroll: true,
        onSuccess: () => done?.(),
        onError: (err: any) => showToast(err.error || err.reason || Object.values(err)[0] as string || 'The action could not be completed.', 'error'),
    });
};
const markReady = (item: OutboundDeliveryItem) => item.open_release && postAction(`/${teamSlug.value}/deliveries/releases/${item.open_release.id}/ready`);

// Reason prompts: cancel a release that has not left, or record goods returned to the warehouse.
const reasonPrompt = ref<{ title: string; help: string; url: string; confirm: string } | null>(null);
const reasonText = ref('');
const askCancelRelease = (item: OutboundDeliveryItem) => {
    if (!item.open_release) return;
    reasonText.value = '';
    reasonPrompt.value = { title: `Cancel ${item.open_release.release_number}`, help: 'The items go back to the order and can be released again later.', url: `/${teamSlug.value}/deliveries/releases/${item.open_release.id}/cancel`, confirm: 'Cancel release' };
};
const askReturn = (item: OutboundDeliveryItem) => {
    reasonText.value = '';
    reasonPrompt.value = { title: 'Goods returned to the warehouse', help: 'Record this only when the goods are physically back. They go back into stock and stay held for this order.', url: `/${teamSlug.value}/deliveries/fulfillments/${item.id}/return`, confirm: 'Record return' };
};
const submitReason = () => {
    if (!reasonPrompt.value || !reasonText.value.trim()) return;
    postAction(reasonPrompt.value.url, { reason: reasonText.value.trim() }, () => (reasonPrompt.value = null));
};

// 2. Start Delivery Modal
const showStartModal = ref(false);
const openStartDelivery = (item: OutboundDeliveryItem) => {
    selectedFulfillment.value = item;
    showStartModal.value = true;
};
const startDeliveryForm = useForm({});

// Goods-receipt inspection of an inbound supplier delivery (used by Supplier Evaluation).
const inspectingDelivery = ref<InboundDeliveryItem | null>(null);
const inspectionForm = useForm({ accepted_quantity: 0, rejected_quantity: 0, inspected_on: '', inspection_remarks: '', rejection_reason: '', rejection_category: '', damaged_quantity: 0, defective_quantity: 0 });
// Workflow errors that are not about one field (e.g. "already inspected") arrive under "inspection".
const inspectionError = computed(() => (inspectionForm.errors as Record<string, string | undefined>).inspection);
// Rejected units not explained as damaged or defective need a category.
const otherRejected = computed(() => Math.max(0, inspectionForm.rejected_quantity - inspectionForm.damaged_quantity - inspectionForm.defective_quantity));
const rejectionCategories = ['defective', 'damaged', 'incorrect_item', 'nonconforming_spec', 'shortage_in_pack', 'other'];
// Accepted units go into stock only once the delivery's supplier invoice is verified (checked again by the server).
const workspacePoId = ref<number | null>(null);
const postingId = ref<number | null>(null);
const postToInventory = (item: InboundDeliveryItem) => {
    postingId.value = item.id;
    router.post(`/${teamSlug.value}/deliveries/${item.id}/post-inventory`, {}, {
        preserveScroll: true,
        onError: (errors) => showToast(Object.values(errors).join(' '), 'error'),
        onSuccess: () => showToast(`Accepted units of ${item.delivery_number} added to inventory.`, 'success'),
        onFinish: () => { postingId.value = null; },
    });
};
const canPost = (item: InboundDeliveryItem) => !item.posted_at && (item.accepted_quantity ?? 0) > 0 && (item.invoices ?? []).some((i) => i.status === 'Verified');
const inspectionLabels: Record<string, string> = { accepted: 'All accepted', partially_accepted: 'Partially accepted', rejected: 'Rejected' };
const openInspection = (item: InboundDeliveryItem) => {
    inspectingDelivery.value = item;
    inspectionForm.clearErrors();
    inspectionForm.accepted_quantity = item.accepted_quantity ?? item.quantity;
    inspectionForm.rejected_quantity = item.rejected_quantity ?? 0;
    inspectionForm.inspected_on = item.inspected_on?.slice(0, 10) ?? new Date().toISOString().slice(0, 10);
    inspectionForm.inspection_remarks = item.inspection_remarks ?? '';
    inspectionForm.rejection_reason = item.rejection_reason ?? '';
    inspectionForm.rejection_category = item.rejection_category ?? '';
    inspectionForm.damaged_quantity = item.damaged_quantity ?? 0;
    inspectionForm.defective_quantity = item.defective_quantity ?? 0;
};
const submitInspection = () => {
    if (!inspectingDelivery.value) return;
    inspectionForm
        .transform((d) => ({ ...d, inspection_remarks: d.inspection_remarks || null, rejection_reason: d.rejected_quantity > 0 ? d.rejection_reason : null, rejection_category: d.rejected_quantity > 0 && d.rejection_category ? d.rejection_category : null, damaged_quantity: d.rejected_quantity > 0 ? d.damaged_quantity : null, defective_quantity: d.rejected_quantity > 0 ? d.defective_quantity : null }))
        .post(`/${teamSlug.value}/deliveries/${inspectingDelivery.value.id}/inspection`, {
            preserveScroll: true,
            onSuccess: () => {
                inspectingDelivery.value = null;
            },
        });
};
const confirmStartDelivery = () => {
    if (!selectedFulfillment.value) return;
    startDeliveryForm.post(`/${teamSlug.value}/deliveries/fulfillments/${selectedFulfillment.value.id}/start`, {
        preserveScroll: true,
        onSuccess: () => {
            showStartModal.value = false;
            showToast(`Delivery ${selectedFulfillment.value?.fulfillment_number} is out for delivery.`, 'success');
        },
        onError: (err: any) => {
            showToast(err.error || 'Failed to start delivery.', 'error');
        }
    });
};

// 3. Record Failed Attempt Modal
const showAttemptModal = ref(false);
const openRecordAttempt = (item: OutboundDeliveryItem) => {
    selectedFulfillment.value = item;
    attemptForm.reset();
    attemptPhotoPreview.value = null;
    showAttemptModal.value = true;
};
const attemptForm = useForm({
    reason: 'Customer unavailable',
    remarks: '',
    attempt_photo: null as File | null,
});
const attemptPhotoPreview = ref<string | null>(null);
const handleAttemptPhotoChange = (e: Event) => {
    const input = e.target as HTMLInputElement;
    if (input.files && input.files[0]) {
        const file = input.files[0];
        attemptForm.attempt_photo = file;
        attemptPhotoPreview.value = URL.createObjectURL(file);
    }
};
const submitFailedAttempt = () => {
    if (!selectedFulfillment.value) return;
    attemptForm.post(`/${teamSlug.value}/deliveries/fulfillments/${selectedFulfillment.value.id}/attempt`, {
        preserveScroll: true,
        onSuccess: () => {
            showAttemptModal.value = false;
            showToast('Delivery attempt recorded successfully.', 'warning');
        },
        onError: (err: any) => {
            showToast(err.reason || err.remarks || 'Failed to record attempt.', 'error');
        }
    });
};

// 4. Mark Delivered Modal (Proof Photo Upload)
const showDeliveredModal = ref(false);
const openMarkDelivered = (item: OutboundDeliveryItem) => {
    selectedFulfillment.value = item;
    deliveredForm.reset();
    deliveredForm.recipient_name = item.fulfillment_type === 'pickup' ? '' : item.recipient_name;
    deliveredForm.quantities = Object.fromEntries((item.open_release?.items ?? []).map((i) => [i.sale_item_id, i.quantity]));
    deliveryPhotoPreview.value = null;
    showDeliveredModal.value = true;
};
const deliveredForm = useForm({
    recipient_name: '',
    remarks: '',
    proof_of_delivery: null as File | null,
    quantities: {} as Record<number, number>,
});
const isPickupDelivery = computed(() => selectedFulfillment.value?.fulfillment_type === 'pickup');
const deliveryPhotoPreview = ref<string | null>(null);
const handleProofPhotoChange = (e: Event) => {
    const input = e.target as HTMLInputElement;
    if (input.files && input.files[0]) {
        const file = input.files[0];
        deliveredForm.proof_of_delivery = file;
        deliveryPhotoPreview.value = URL.createObjectURL(file);
    }
};
const submitDelivered = () => {
    if (!selectedFulfillment.value) return;
    deliveredForm.post(`/${teamSlug.value}/deliveries/fulfillments/${selectedFulfillment.value.id}/deliver`, {
        preserveScroll: true,
        onSuccess: () => {
            showDeliveredModal.value = false;
        },
        onError: (err: any) => {
            showToast(err.proof_of_delivery || err.recipient_name || err.items || err.error || 'Failed to complete delivery.', 'error');
        }
    });
};

// 5. Assign Staff Modal (Admin only)
const showAssignModal = ref(false);
const openAssignStaff = (item: OutboundDeliveryItem) => {
    selectedFulfillment.value = item;
    assignForm.staff_id = item.handled_by_id ? String(item.handled_by_id) : '';
    showAssignModal.value = true;
};
const assignForm = useForm({
    staff_id: '',
});
const submitAssignStaff = () => {
    if (!selectedFulfillment.value) return;
    assignForm.post(`/${teamSlug.value}/deliveries/fulfillments/${selectedFulfillment.value.id}/assign`, {
        preserveScroll: true,
        onSuccess: () => {
            showAssignModal.value = false;
            showToast('Delivery assigned successfully.', 'success');
        }
    });
};

// 6. Reschedule Modal (Admin only)
const showRescheduleModal = ref(false);
const openReschedule = (item: OutboundDeliveryItem) => {
    selectedFulfillment.value = item;
    rescheduleForm.reset();
    rescheduleForm.delivery_schedule = new Date(Date.now() + 86400000).toISOString().slice(0, 16);
    rescheduleForm.reset_attempts = true;
    showRescheduleModal.value = true;
};
const rescheduleForm = useForm({
    delivery_schedule: '',
    staff_id: '',
    reset_attempts: true,
    notes: '',
});
const submitReschedule = () => {
    if (!selectedFulfillment.value) return;
    rescheduleForm.post(`/${teamSlug.value}/deliveries/fulfillments/${selectedFulfillment.value.id}/reschedule`, {
        preserveScroll: true,
        onSuccess: () => {
            showRescheduleModal.value = false;
            showToast('Delivery successfully rescheduled.', 'success');
        }
    });
};

// 7. Proof Viewer Modal
const showProofViewerModal = ref(false);
const viewingProofUrl = ref<string | null>(null);
const viewingProofTitle = ref('');
const openProofViewer = (url: string, title: string) => {
    viewingProofUrl.value = url;
    viewingProofTitle.value = title;
    showProofViewerModal.value = true;
};

// ==========================================
// INBOUND SUPPLIER RECEIVING LOGIC (Preserved)
// ==========================================
const showCreateInboundModal = ref(false);
const inboundForm = useForm({
    purchase_order_id: null as number | null,
    delivery_date: new Date().toISOString().slice(0, 10),
    quantity: 1,
    supplier_document_number: '',
    over_receipt_reason: '',
    notes: '',
});
const selectedInboundPo = computed(() => (props.purchaseOrders ?? []).find((p) => p.id === inboundForm.purchase_order_id) ?? null);
const submitInboundDelivery = () => {
    if (!inboundForm.purchase_order_id) return;
    inboundForm.post(`/${teamSlug.value}/deliveries/purchase-orders/${inboundForm.purchase_order_id}/receive`, {
        preserveScroll: true,
        onSuccess: () => {
            showCreateInboundModal.value = false;
            inboundForm.reset();
            showToast('Inbound delivery record saved.', 'success');
        }
    });
};

const showDeleteConfirm = ref(false);
const deleteTargetId = ref<number | null>(null);
const confirmDeleteInbound = (id: number) => {
    deleteTargetId.value = id;
    showDeleteConfirm.value = true;
};
const executeDeleteInbound = () => {
    if (!deleteTargetId.value) return;
    router.delete(`/${teamSlug.value}/deliveries/${deleteTargetId.value}`, {
        preserveScroll: true,
        onSuccess: () => {
            showDeleteConfirm.value = false;
            deleteTargetId.value = null;
            showToast('Delivery record deleted.', 'success');
        }
    });
};
</script>

<template>
    <Head title="Deliveries — MR-PRIDE" />

    <div class="workspace-page">
        <!-- Top Header & Role Indicator -->
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
                <h1 class="page-title">
                    {{ isDeliveryStaff ? 'Delivery Staff Dashboard' : 'Deliveries & Logistics' }}
                </h1>
                <p class="page-subtitle">
                    {{ isDeliveryStaff
                        ? 'Your assigned deliveries, attempts and handovers.'
                        : 'Customer deliveries, driver assignments and supplier PO receiving.' }}
                </p>
            </div>

            <!-- Tab Switcher (Visible to Admin & Non-Driver Staff) -->
            <div v-if="!isDeliveryStaff" class="flex w-full items-center gap-1 overflow-x-auto rounded-xl border border-gray-200 bg-white p-1 sm:w-auto sm:self-start lg:self-auto">
                <button
                    @click="switchTab('outbound')"
                    class="flex flex-1 shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-lg px-3.5 py-2 text-sm font-medium transition-colors sm:flex-none"
                    :class="currentTab === 'outbound' ? 'bg-gray-100 text-gray-900' : 'text-gray-500 hover:text-gray-900'"
                >
                    <Truck class="size-4" />
                    Customer Deliveries
                    <span class="text-xs text-gray-400">{{ outboundMetrics?.totalAssigned ?? 0 }}</span>
                </button>
                <button 
                    @click="switchTab('inbound')"
                    class="flex flex-1 shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-lg px-3.5 py-2 text-sm font-medium transition-colors sm:flex-none"
                    :class="currentTab === 'inbound' ? 'bg-gray-100 text-gray-900' : 'text-gray-500 hover:text-gray-900'"
                >
                    <Package class="size-4" />
                    Inbound PO Receiving
                    <span class="text-xs text-gray-400">{{ inboundMetrics?.totalDeliveries ?? 0 }}</span>
                </button>
            </div>
        </div>

        <!-- ========================================================= -->
        <!-- TAB 1: OUTBOUND CUSTOMER DELIVERIES (Driver Dashboard)    -->
        <!-- ========================================================= -->
        <template v-if="currentTab === 'outbound'">
            <!-- Summary Metric Cards (6 core metrics requested). Colour only marks counts that need attention. -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <div
                    v-for="card in [
                        { label: isDeliveryStaff ? 'Assigned to you' : 'Total assigned', value: outboundMetrics?.totalAssigned ?? 0, alert: '' },
                        { label: 'To prepare', value: outboundMetrics?.pending ?? 0, alert: 'text-amber-600' },
                        { label: 'Out for delivery', value: outboundMetrics?.inTransit ?? 0, alert: '' },
                        { label: 'Delivered', value: outboundMetrics?.delivered ?? 0, alert: '' },
                        { label: 'Attempt failed', value: outboundMetrics?.failed ?? 0, alert: 'text-rose-600' },
                        { label: 'Attempts left', value: outboundMetrics?.attemptsRemaining ?? 0, alert: '' },
                    ]"
                    :key="card.label"
                    class="rounded-xl border border-gray-200 bg-white px-4 py-3"
                >
                    <div class="text-xs text-gray-500">{{ card.label }}</div>
                    <div :class="card.value > 0 && card.alert ? card.alert : 'text-gray-900'" class="mt-1 text-2xl font-semibold">{{ card.value }}</div>
                </div>
            </div>

            <!-- Search & Filters Toolbar -->
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="flex flex-col gap-3 md:flex-row md:flex-wrap md:items-center">
                    <!-- Search Input -->
                    <div class="relative min-w-0 md:min-w-64 md:flex-1">
                        <Search class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <input
                            v-model="search"
                            @input="applyFilter"
                            type="search"
                            aria-label="Search deliveries"
                            placeholder="Search order #, recipient, address, phone or delivery #"
                            class="field-control pl-10"
                        />
                    </div>

                    <!-- Status Filter -->
                    <select
                        v-model="selectedStatus"
                        @change="applyFilter"
                        aria-label="Filter by status"
                        class="field-control md:w-56"
                    >
                        <option value="All">All statuses</option>
                        <option v-for="o in statusOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>

                    <!-- Staff Filter (Admin only) -->
                    <select
                        v-if="isAdmin"
                        v-model="selectedStaff"
                        @change="applyFilter"
                        aria-label="Filter by driver"
                        class="field-control md:w-56"
                    >
                        <option value="All">All Delivery Drivers</option>
                        <option v-for="staff in deliveryStaffList" :key="staff.id" :value="String(staff.id)">
                            {{ staff.name }} ({{ staff.role }})
                        </option>
                    </select>

                    <!-- Clear Filters -->
                    <button
                        v-if="hasActiveFilters"
                        @click="resetFilters"
                        type="button"
                        class="secondary-button shrink-0"
                    >
                        <RotateCcw class="size-4" />
                        Reset
                    </button>
                </div>
            </div>

            <!-- Outbound Delivery Cards / Table (Mobile Optimized) -->
            <div class="space-y-4">
                <!-- Empty State -->
                <div v-if="!outboundDeliveries?.data || outboundDeliveries.data.length === 0" class="flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-200 bg-card/40 p-12 text-center">
                    <Truck class="size-12 text-muted-foreground/40" />
                    <h3 class="mt-4 text-base font-semibold text-foreground">No Delivery Tasks Found</h3>
                    <p class="mt-1 text-sm text-muted-foreground max-w-md">
                        {{ isDeliveryStaff 
                            ? 'You currently have no delivery tasks assigned. Check back later or contact your operations administrator.' 
                            : 'No customer order fulfillments match your selected filters.' }}
                    </p>
                </div>

                <!-- Deliveries List -->
                <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <div
                        v-for="item in outboundDeliveries.data"
                        :key="item.id"
                        class="flex min-w-0 flex-col justify-between rounded-xl border border-gray-200 bg-white p-5"
                    >
                        <!-- Top Row: Reference, Status & Attempt Badge -->
                        <div>
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <span class="font-mono text-sm font-semibold text-foreground">
                                            {{ item.fulfillment_number }}
                                        </span>
                                        <span v-if="item.sale" class="font-mono text-xs text-muted-foreground">
                                            • {{ item.sale.order_number }}
                                        </span>
                                        <span class="text-xs text-muted-foreground">• {{ item.fulfillment_type === 'pickup' ? 'Pickup' : 'Delivery' }}</span>
                                    </div>
                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                        <span class="inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs font-medium" :class="getStatusBadgeClass(item.status)">
                                            {{ item.status_label ?? item.status }}
                                        </span>
                                        <!-- Attempt Counter Badge (0/3, 1/3, 2/3, 3/3) -->
                                        <span v-if="item.fulfillment_type !== 'pickup' && ['out_for_delivery', 'delivery_attempt_failed'].includes(item.status)" class="inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs" :class="getAttemptBadgeClass(item.attempt_count)">
                                            <RotateCcw class="size-3" />
                                            Attempt: {{ item.attempts_display }}
                                        </span>
                                    </div>
                                </div>

                                <!-- Driver Assignment Badge -->
                                <div class="shrink-0 text-right">
                                    <span v-if="item.handled_by" class="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                        <UserIcon class="size-3" />
                                        {{ item.handled_by.name }}
                                    </span>
                                    <span v-else class="inline-flex items-center gap-1 rounded-md bg-amber-50 px-1.5 py-0.5 text-xs font-medium text-amber-600">
                                        Unassigned
                                    </span>
                                </div>
                            </div>

                            <!-- Customer Information Block (Read-only as required) -->
                            <div class="mt-4 space-y-2.5 rounded-xl border border-gray-200 bg-gray-50 p-4">
                                <!-- Customer Name -->
                                <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5 text-sm font-semibold text-foreground">
                                    <UserCheck class="size-4 text-brand shrink-0" />
                                    <span class="break-words">{{ item.recipient_name }}</span>
                                    <span v-if="item.sale?.client_name && item.sale.client_name !== item.recipient_name" class="text-xs text-muted-foreground font-normal">
                                        ({{ item.sale.client_name }})
                                    </span>
                                </div>

                                <!-- Address -->
                                <div class="flex items-start gap-2 text-xs text-muted-foreground">
                                    <MapPin class="size-4 text-rose-600 shrink-0" />
                                    <span class="min-w-0 break-words leading-relaxed">{{ item.delivery_address || 'No address specified' }}</span>
                                </div>

                                <!-- Contact Phone (Click to call for mobile driver convenience) -->
                                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 pt-2.5 text-xs">
                                    <a :href="'tel:' + item.contact_number" class="flex items-center gap-2 font-medium text-brand hover:underline">
                                        <Phone class="size-4 shrink-0" />
                                        <span>{{ item.contact_number }}</span>
                                    </a>
                                    <span v-if="item.delivery_schedule" class="text-muted-foreground flex items-center gap-1.5">
                                        <Calendar class="size-3.5" />
                                        {{ formatDate(item.delivery_schedule) }}
                                    </span>
                                </div>
                            </div>

                            <!-- What is on its way, and what is still owed -->
                            <div v-if="item.open_release" class="mt-3 rounded-xl border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-900">
                                <span class="font-mono font-semibold">{{ item.open_release.release_number }}</span> · {{ releaseStatusText[item.open_release.status] ?? item.open_release.status }}:
                                {{ item.open_release.items.map((i) => `${i.sale_item?.product_name} × ${i.quantity}`).join(', ') }}
                            </div>
                            <p v-if="outstanding(item).length && !['cancelled', 'completed'].includes(item.status)" class="mt-2 text-xs text-muted-foreground">
                                Still owed: {{ outstanding(item).map((l) => `${l.product_name} × ${l.remaining_quantity}`).join(', ') }}
                            </p>

                            <!-- Remarks / Notes Preview if any -->
                            <p v-if="item.notes" class="mt-3 line-clamp-2 text-xs italic text-muted-foreground">
                                "{{ item.notes }}"
                            </p>
                        </div>

                        <!-- Action Buttons Bar -->
                        <div class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 pt-4">
                            <!-- Left: Details and Proof -->
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    @click="openDetails(item)"
                                    class="inline-flex h-8 items-center gap-1.5 whitespace-nowrap rounded-lg border border-gray-200 bg-gray-50 px-3 text-xs font-semibold text-muted-foreground transition-colors hover:bg-gray-100 hover:text-foreground"
                                >
                                    <Eye class="size-3.5" />
                                    Details & History
                                </button>

                                <button
                                    v-if="item.proof_url"
                                    @click="openProofViewer(item.proof_url, `Proof of Delivery: ${item.fulfillment_number}`)"
                                    class="inline-flex h-8 items-center gap-1.5 whitespace-nowrap rounded-lg border border-emerald-200 bg-emerald-50 px-3 text-xs font-semibold text-emerald-600 transition-colors hover:bg-emerald-500/20"
                                >
                                    <ImageIcon class="size-3.5" />
                                    Proof Photo
                                </button>
                            </div>

                            <!-- Right: the status update (admin and the assigned delivery staff) -->
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                <span v-if="item.sale?.hold_reason" class="inline-flex h-8 items-center gap-1.5 whitespace-nowrap rounded-lg border border-amber-200 bg-amber-50 px-3 text-xs font-semibold text-amber-700" :title="item.sale.hold_reason">
                                    Order on hold
                                </span>
                                <span v-if="item.status === 'delivery_attempt_failed' && item.attempt_count >= 3"
                                      class="inline-flex h-8 items-center gap-1.5 whitespace-nowrap rounded-lg border border-rose-200 bg-rose-50 px-3 text-xs font-semibold text-rose-600">
                                    <ShieldAlert class="size-3.5" />
                                    3/3 attempts: admin must reschedule
                                </span>
                                <button v-if="can(item, 'assign') && !item.handled_by_id" @click="openAssignStaff(item)"
                                        class="inline-flex h-8 items-center gap-1.5 whitespace-nowrap rounded-lg border border-gray-200 bg-gray-100 px-3 text-xs font-semibold text-foreground hover:bg-gray-200">
                                    <UserCheck class="size-3.5" />
                                    Assign driver
                                </button>
                                <button v-if="item.status_choices?.length" @click="openStatusUpdate(item)"
                                        class="inline-flex h-8 items-center gap-1.5 whitespace-nowrap rounded-lg bg-brand px-3 text-xs font-semibold text-white transition-colors hover:bg-[#3b4219]">
                                    <RotateCcw class="size-3.5" />
                                    Update status
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pagination -->
                <div v-if="outboundDeliveries?.links && outboundDeliveries.links.length > 3" class="flex flex-col gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="text-xs text-muted-foreground">
                        Showing {{ (outboundDeliveries.current_page - 1) * 12 + 1 }} to {{ Math.min(outboundDeliveries.current_page * 12, outboundDeliveries.total) }} of {{ outboundDeliveries.total }} deliveries
                    </div>
                    <div class="flex flex-wrap items-center gap-1">
                        <button
                            v-for="(link, i) in outboundDeliveries.links"
                            :key="i"
                            @click="link.url && router.get(link.url, {}, { preserveScroll: true })"
                            :disabled="!link.url || link.active"
                            v-html="link.label"
                            class="inline-flex h-8 min-w-8 items-center justify-center rounded-lg px-3 text-xs font-medium transition-colors"
                            :class="{
                                'bg-brand text-white font-semibold': link.active,
                                'border border-gray-200 text-muted-foreground hover:text-foreground hover:bg-gray-50': !link.active && link.url,
                                'opacity-40 cursor-not-allowed': !link.url
                            }"
                        />
                    </div>
                </div>
            </div>
        </template>

        <!-- ========================================================= -->
        <!-- TAB 2: INBOUND SUPPLIER RECEIVING (Preserved)             -->
        <!-- ========================================================= -->
        <template v-else-if="!isDeliveryStaff && currentTab === 'inbound'">
            <!-- Inbound Action Bar -->
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-muted-foreground">Receiving log for supplier purchase order shipments into the main warehouse.</p>
                <div class="flex shrink-0 items-center gap-2">
                    <button
                        type="button"
                        @click="showCreateInboundModal = true"
                        class="brand-button w-full sm:w-auto"
                    >
                        <Plus class="size-4" />
                        Record Inbound PO Delivery
                    </button>
                </div>
            </div>

            <!-- Inbound Deliveries Table -->
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-gray-200 bg-gray-50 text-xs font-semibold text-muted-foreground">
                            <tr>
                                <th class="whitespace-nowrap px-4 py-3">Delivery Date</th>
                                <th class="whitespace-nowrap px-4 py-3">Delivery #</th>
                                <th class="whitespace-nowrap px-4 py-3">PO #</th>
                                <th class="whitespace-nowrap px-4 py-3">Supplier</th>
                                <th class="whitespace-nowrap px-4 py-3">Description</th>
                                <th class="whitespace-nowrap px-4 py-3 text-right">Qty</th>
                                <th class="whitespace-nowrap px-4 py-3">Status</th>
                                <th class="whitespace-nowrap px-4 py-3">Inspection</th>
                                <th class="whitespace-nowrap px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="!inboundDeliveries?.data || inboundDeliveries.data.length === 0">
                                <td colspan="9" class="p-8 text-center text-sm text-muted-foreground">No inbound supplier delivery records found.</td>
                            </tr>
                            <tr v-for="item in inboundDeliveries?.data" :key="item.id" class="hover:bg-gray-50">
                                <td class="whitespace-nowrap px-4 py-3 text-xs">{{ formatDate(item.delivery_date) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 font-mono text-xs font-semibold text-foreground">{{ item.delivery_number }}</td>
                                <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-brand">{{ item.purchase_order_number || '—' }}</td>
                                <td class="px-4 py-3 text-xs">{{ item.supplier?.name || '—' }}</td>
                                <td class="min-w-48 px-4 py-3 text-xs text-muted-foreground">{{ item.description }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-xs font-semibold">{{ item.quantity }} {{ item.unit }}</td>
                                <td class="px-4 py-3 text-xs">
                                    <span class="inline-flex items-center rounded-md border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-600">
                                        {{ item.status }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    <span class="font-medium" :class="item.inspection_status === 'Pending Inspection' ? 'text-amber-700' : ''">{{ item.inspection_status ?? (item.inspection_result ? inspectionLabels[item.inspection_result] : 'Pending Inspection') }}</span>
                                    <div v-if="item.inspection_result" class="text-muted-foreground">{{ item.accepted_quantity }} accepted · {{ item.rejected_quantity }} rejected</div>
                                    <div v-if="item.posted_at" class="text-emerald-700">{{ item.posted_quantity }} in stock</div>
                                    <div v-else-if="item.inspection_result && (item.accepted_quantity ?? 0) > 0" class="text-muted-foreground">
                                        {{ (item.invoices ?? []).some((i) => i.status === 'Verified') ? 'Invoice verified · ready to post' : 'Awaiting verified invoice' }}
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <button
                                        v-if="canPost(item)"
                                        type="button"
                                        class="mr-1 rounded-lg border border-[#4b5320] px-2 py-1 text-xs font-medium text-[#4b5320] hover:bg-gray-50"
                                        :disabled="postingId === item.id"
                                        @click="postToInventory(item)"
                                    >
                                        Post {{ item.accepted_quantity }} to inventory
                                    </button>
                                    <button
                                        v-if="item.purchase_order_id"
                                        type="button"
                                        class="mr-1 rounded-lg border border-gray-200 px-2 py-1 text-xs font-medium hover:bg-gray-50"
                                        @click="workspacePoId = item.purchase_order_id ?? null"
                                    >
                                        Open PO
                                    </button>
                                    <button
                                        v-if="!item.posted_at && (!item.inspection_result || isAdmin)"
                                        type="button"
                                        class="mr-1 rounded-lg border border-gray-200 px-2 py-1 text-xs font-medium hover:bg-gray-50"
                                        @click="openInspection(item)"
                                    >
                                        {{ item.inspection_result ? 'Correct inspection' : 'Inspect' }}
                                    </button>
                                    <button
                                        v-if="canDelete"
                                        @click="confirmDeleteInbound(item.id)"
                                        class="rounded-lg p-1 text-muted-foreground hover:bg-rose-50 hover:text-rose-600"
                                    >
                                        <Trash2 class="size-4" />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </div>

    <!-- ========================================================= -->
    <!-- MODAL 1: START DELIVERY CONFIRMATION                      -->
    <!-- ========================================================= -->
    <div v-if="showStartModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
        <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl border border-gray-200 bg-card p-6 shadow-xl">
            <div class="flex items-center gap-3 text-blue-600">
                <Navigation class="size-6 shrink-0" />
                <h3 class="text-lg font-semibold text-foreground">{{ selectedFulfillment?.status === 'delivery_failed' || selectedFulfillment?.status === 'delivery_rescheduled' ? 'Dispatch again' : 'Dispatch release' }}</h3>
            </div>
            <p class="mt-3 text-sm text-muted-foreground leading-relaxed">
                Confirm that <strong class="text-foreground">{{ selectedFulfillment?.open_release?.release_number ?? selectedFulfillment?.fulfillment_number }}</strong> is leaving for <strong class="text-foreground">{{ selectedFulfillment?.recipient_name }}</strong>.
                The items are taken out of stock now (only on the first dispatch). The customer is emailed that the order is on its way.
            </p>
            <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-3 text-xs text-muted-foreground">
                <div class="font-medium text-foreground">Address:</div>
                <div>{{ selectedFulfillment?.delivery_address }}</div>
            </div>
            <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-end">
                <button
                    @click="showStartModal = false"
                    type="button"
                    class="secondary-button"
                >
                    Cancel
                </button>
                <button
                    @click="confirmStartDelivery"
                    :disabled="startDeliveryForm.processing"
                    class="brand-button"
                >
                    <Navigation class="size-4" />
                    Confirm Out for Delivery
                </button>
            </div>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- MODAL 2: RECORD FAILED ATTEMPT (Strict 3-Limit Enforced)  -->
    <!-- ========================================================= -->
    <div v-if="showAttemptModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
        <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl border border-amber-200 bg-card p-6 shadow-xl">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 pb-3">
                <div class="flex items-center gap-2.5 text-amber-600">
                    <AlertTriangle class="size-5 shrink-0" />
                    <h3 class="text-lg font-semibold text-foreground">Record Unsuccessful Attempt</h3>
                </div>
                <span class="rounded-md border border-amber-200 bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700">
                    Recording Attempt {{ (selectedFulfillment?.attempt_count ?? 0) + 1 }} of 3
                </span>
            </div>

            <!-- Warning notice if this is attempt 3 -->
            <div v-if="(selectedFulfillment?.attempt_count ?? 0) >= 2" class="mt-4 flex items-start gap-2.5 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700">
                <ShieldAlert class="size-4 shrink-0 mt-0.5 text-rose-600" />
                <div>
                    <strong>CRITICAL NOTICE (Final Attempt 3 of 3):</strong>
                    This will reach the strict 3-attempt limit. The delivery will automatically lock to <em>Maximum Attempts Reached</em> and will require administrator follow-up.
                </div>
            </div>

            <form @submit.prevent="submitFailedAttempt" class="mt-4 space-y-4">
                <!-- Reason Dropdown -->
                <div>
                    <label class="field-label">Failure Reason</label>
                    <select
                        v-model="attemptForm.reason"
                        required
                        class="field-control"
                    >
                        <option value="Customer unavailable">Customer unavailable</option>
                        <option value="Incorrect or incomplete address">Incorrect or incomplete address</option>
                        <option value="Customer refused delivery">Customer refused delivery</option>
                        <option value="Customer unreachable">Customer unreachable (unresponsive on phone)</option>
                        <option value="Other reason">Other reason (explain in remarks)</option>
                    </select>
                </div>

                <!-- Remarks Textarea -->
                <div>
                    <label class="field-label">Detailed Remarks / Attempt Notes</label>
                    <textarea
                        v-model="attemptForm.remarks"
                        required
                        rows="3"
                        placeholder="Provide details about what happened during this attempt..."
                        class="field-control"
                    />
                </div>

                <!-- Optional Photo Evidence -->
                <div>
                    <label class="field-label">Optional Photo Evidence (Closed gate, site signage, etc.)</label>
                    <input
                        type="file"
                        accept="image/jpeg,image/png,image/jpg"
                        @change="handleAttemptPhotoChange"
                        class="mt-1.5 w-full rounded-xl border border-gray-200 bg-white p-2 text-xs text-muted-foreground file:mr-3 file:rounded-lg file:border-0 file:bg-brand file:px-3 file:py-1 file:text-xs file:font-semibold file:text-white"
                    />
                    <div v-if="attemptPhotoPreview" class="mt-2">
                        <img :src="attemptPhotoPreview" class="h-28 w-auto rounded-lg object-cover border border-gray-200" />
                    </div>
                </div>

                <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-end">
                    <button
                        @click="showAttemptModal = false"
                        type="button"
                        class="secondary-button"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="attemptForm.processing"
                        class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-amber-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-amber-700 disabled:pointer-events-none disabled:opacity-50"
                    >
                        <AlertTriangle class="size-4" />
                        Record Attempt {{ (selectedFulfillment?.attempt_count ?? 0) + 1 }}/3
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- MODAL 3: MARK DELIVERED WITH PHOTO PROOF                  -->
    <!-- ========================================================= -->
    <div v-if="showDeliveredModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
        <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl border border-emerald-200 bg-card p-6 shadow-xl">
            <div class="flex items-center gap-2.5 border-b border-gray-200 pb-3 text-emerald-600">
                <Camera class="size-5 shrink-0" />
                <h3 class="text-lg font-semibold text-foreground">{{ isPickupDelivery ? 'Record pickup' : 'Record delivery' }}</h3>
            </div>

            <form @submit.prevent="submitDelivered" class="mt-4 space-y-4">
                <!-- Quantities received (anything not received goes back into stock) -->
                <div v-if="selectedFulfillment?.open_release">
                    <span class="field-label">Quantities received</span>
                    <div class="divide-y divide-gray-100 rounded-xl border border-gray-200">
                        <div v-for="ri in selectedFulfillment.open_release.items" :key="ri.id" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                            <span class="min-w-0 truncate">{{ ri.sale_item?.product_name }} <span class="text-xs text-muted-foreground">(sent {{ ri.quantity }})</span></span>
                            <input v-model.number="deliveredForm.quantities[ri.sale_item_id]" type="number" min="0" :max="ri.quantity" class="field-control w-24 text-right" :aria-label="`Received of ${ri.sale_item?.product_name}`" />
                        </div>
                    </div>
                    <p class="mt-1 text-xs text-muted-foreground">Anything not received goes back into stock and stays owed to the customer.</p>
                </div>

                <!-- Proof Photo Upload (required for deliveries) -->
                <div>
                    <label class="field-label mb-0">
                        Photo proof <span v-if="!isPickupDelivery" class="text-rose-600">* (JPG or PNG required)</span><span v-else class="text-muted-foreground">(optional)</span>
                    </label>
                    <p class="mt-1 text-xs text-muted-foreground">Capture signed invoice, delivery receipt, or received package with customer.</p>
                    <div class="mt-2 flex flex-col items-center justify-center rounded-xl border-2 border-dashed border-emerald-200 bg-white p-4 text-center hover:border-emerald-500/50">
                        <input
                            type="file"
                            accept="image/jpeg,image/png,image/jpg"
                            :required="!isPickupDelivery"
                            @change="handleProofPhotoChange"
                            class="w-full cursor-pointer text-xs text-muted-foreground file:mr-3 file:rounded-lg file:border-0 file:bg-emerald-600 file:px-3 file:py-1.5 file:text-xs file:font-bold file:text-white"
                        />
                        <div v-if="deliveryPhotoPreview" class="mt-3 relative w-full">
                            <img :src="deliveryPhotoPreview" class="mx-auto max-h-48 rounded-lg object-contain border border-gray-200 shadow-md" />
                            <span class="mt-1 block text-center text-[10px] text-emerald-600">Preview ready for upload</span>
                        </div>
                    </div>
                </div>

                <!-- Recipient Confirmed Name -->
                <div>
                    <label class="field-label">{{ isPickupDelivery ? 'Picked up by (name) *' : 'Received by (name / title)' }}</label>
                    <input
                        v-model="deliveredForm.recipient_name"
                        type="text"
                        placeholder="Person who physically received the package"
                        class="field-control"
                    />
                </div>

                <!-- Handover Remarks -->
                <div>
                    <label class="field-label">Delivery Remarks</label>
                    <textarea
                        v-model="deliveredForm.remarks"
                        rows="2"
                        placeholder="Package condition, gate pass details, or recipient remarks..."
                        class="field-control"
                    />
                </div>

                <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-end">
                    <button
                        @click="showDeliveredModal = false"
                        type="button"
                        class="secondary-button"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="deliveredForm.processing || (!isPickupDelivery && !deliveredForm.proof_of_delivery) || (isPickupDelivery && !deliveredForm.recipient_name.trim())"
                        class="brand-button"
                    >
                        <Check class="size-4" />
                        {{ isPickupDelivery ? 'Confirm pickup' : 'Confirm delivery' }}
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- MODAL 4: DETAILS & AUDIT ATTEMPT HISTORY DRAWER           -->
    <!-- ========================================================= -->
    <div v-if="showDetailsModal && selectedFulfillment" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
        <div class="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-xl border border-gray-200 bg-card p-6 shadow-xl">
            <!-- Header -->
            <div class="flex items-start justify-between gap-3 border-b border-gray-200 pb-4">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-mono text-lg font-semibold text-foreground">{{ selectedFulfillment.fulfillment_number }}</span>
                        <span class="inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium" :class="getStatusBadgeClass(selectedFulfillment.status)">
                            {{ selectedFulfillment.status_label ?? selectedFulfillment.status }}
                        </span>
                        <span class="inline-flex items-center rounded-md border px-2 py-0.5 text-xs" :class="getAttemptBadgeClass(selectedFulfillment.attempt_count)">
                            {{ selectedFulfillment.attempts_display }}
                        </span>
                    </div>
                    <div class="mt-1 text-xs text-muted-foreground">
                        Order Fulfillment Details & Delivery Attempt History
                    </div>
                </div>
                <button @click="showDetailsModal = false" class="rounded-lg p-1.5 text-muted-foreground hover:bg-gray-100 hover:text-foreground">
                    <XCircle class="size-5" />
                </button>
            </div>

            <!-- Customer & Delivery Specs Grid -->
            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <!-- Customer Details -->
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 space-y-2">
                    <div class="text-xs font-semibold text-muted-foreground">Customer Information</div>
                    <div class="text-sm font-semibold text-foreground">{{ selectedFulfillment.recipient_name }}</div>
                    <div class="text-xs text-muted-foreground flex items-center gap-1.5">
                        <Phone class="size-3.5 text-brand" />
                        <a :href="'tel:' + selectedFulfillment.contact_number" class="hover:underline">{{ selectedFulfillment.contact_number }}</a>
                    </div>
                    <div class="text-xs text-muted-foreground flex items-start gap-1.5 pt-1">
                        <MapPin class="size-3.5 text-rose-600 shrink-0 mt-0.5" />
                        <span>{{ selectedFulfillment.delivery_address || 'No address specified' }}</span>
                    </div>
                </div>

                <!-- Order Details -->
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 space-y-2">
                    <div class="text-xs font-semibold text-muted-foreground">Order & Logistics</div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-muted-foreground">Sales Order:</span>
                        <span class="font-mono font-semibold text-foreground">{{ selectedFulfillment.sale?.order_number || 'N/A' }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-muted-foreground">Assigned Driver:</span>
                        <span class="font-semibold text-foreground">{{ selectedFulfillment.handled_by?.name || 'Unassigned' }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-muted-foreground">Scheduled Date:</span>
                        <span>{{ formatDate(selectedFulfillment.delivery_schedule) }}</span>
                    </div>
                    <div v-if="selectedFulfillment.delivered_at" class="flex items-center justify-between text-xs text-emerald-600">
                        <span>Delivered At:</span>
                        <span class="font-medium">{{ formatDateTime(selectedFulfillment.delivered_at) }}</span>
                    </div>
                </div>
            </div>

            <!-- Order Items breakdown -->
            <div v-if="selectedFulfillment.sale?.items && selectedFulfillment.sale.items.length > 0" class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-4">
                <div class="text-xs font-semibold text-muted-foreground mb-2">Order quantities</div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead><tr><th class="py-1">Item</th><th class="py-1 text-right">Ordered</th><th class="py-1 text-right">Sent</th><th class="py-1 text-right">Received</th><th class="py-1 text-right">Remaining</th></tr></thead>
                        <tbody>
                            <tr v-for="line in selectedFulfillment.sale.items" :key="line.id" class="border-t border-gray-200">
                                <td class="py-1 text-foreground">{{ line.product_name }}</td>
                                <td class="py-1 text-right">{{ line.quantity }}</td>
                                <td class="py-1 text-right">{{ line.released_quantity }}</td>
                                <td class="py-1 text-right">{{ line.delivered_quantity }}</td>
                                <td class="py-1 text-right font-semibold">{{ line.remaining_quantity }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="selectedFulfillment.releases?.length" class="mt-3 space-y-1">
                    <div v-for="r in selectedFulfillment.releases" :key="r.id" class="flex flex-wrap items-center justify-between gap-2 text-xs">
                        <span><span class="font-mono font-semibold">{{ r.release_number }}</span> · {{ releaseStatusText[r.status] ?? r.status }}<span v-if="r.received_by_name"> · received by {{ r.received_by_name }}</span></span>
                        <a :href="`/${teamSlug}/deliveries/releases/${r.id}/pdf`" target="_blank" class="text-brand hover:underline">{{ r.type === 'pickup' ? 'Release slip' : 'Delivery receipt' }}</a>
                    </div>
                </div>
            </div>

            <!-- Chronological Attempt History (Requirement 7) -->
            <div class="mt-6">
                <div class="flex items-center justify-between">
                    <h4 class="text-xs font-semibold text-muted-foreground flex items-center gap-1.5">
                        Delivery Attempt & Audit History
                    </h4>
                    <span class="text-[11px] text-muted-foreground">{{ selectedFulfillment.attempt_logs?.length ?? 0 }} log entries</span>
                </div>

                <div class="mt-3 space-y-3">
                    <div v-if="!selectedFulfillment.attempt_logs || selectedFulfillment.attempt_logs.length === 0" class="rounded-xl border border-dashed border-gray-200 p-4 text-center text-xs text-muted-foreground">
                        No delivery attempt entries logged yet.
                    </div>

                    <div
                        v-for="log in selectedFulfillment.attempt_logs"
                        :key="log.id"
                        class="rounded-xl border border-gray-200 bg-gray-50 p-3 text-xs space-y-1.5"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold text-foreground">
                                    {{ log.attempt_number > 0 ? `Failed attempt #${log.attempt_number}` : 'Status change' }}
                                </span>
                                <span class="rounded border px-1.5 py-0.5 text-[10px] font-medium" :class="getStatusBadgeClass(log.new_status)">
                                    {{ statusLabel(log.new_status) }}
                                </span>
                            </div>
                            <span class="text-[11px] text-muted-foreground">{{ formatDateTime(log.attempt_date) }}</span>
                        </div>

                        <div v-if="log.failure_reason" class="text-rose-600 font-medium">
                            Reason: {{ log.failure_reason }}
                        </div>

                        <div class="text-muted-foreground leading-relaxed">
                            {{ log.remarks }}
                        </div>

                        <div class="flex items-center justify-between pt-1 text-[11px] text-muted-foreground/70">
                            <span>By: {{ log.staff?.name || 'System' }}</span>
                            <button
                                v-if="log.proof_url"
                                @click="openProofViewer(log.proof_url, `Attempt #${log.attempt_number} Photo`)"
                                class="text-brand hover:underline flex items-center gap-1 font-medium"
                            >
                                <ImageIcon class="size-3" />
                                View Photo
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-end">
                <button
                    @click="showDetailsModal = false"
                    class="secondary-button"
                >
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- MODAL 5: ASSIGN DRIVER (Admin only)                       -->
    <!-- ========================================================= -->
    <div v-if="showAssignModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
        <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl border border-gray-200 bg-card p-6 shadow-xl">
            <h3 class="text-lg font-semibold text-foreground">Assign Delivery Driver</h3>
            <p class="mt-1 text-xs text-muted-foreground">Select a registered staff member to handle delivery {{ selectedFulfillment?.fulfillment_number }}.</p>

            <form @submit.prevent="submitAssignStaff" class="mt-4 space-y-4">
                <div>
                    <label class="field-label">Delivery Staff Member</label>
                    <select
                        v-model="assignForm.staff_id"
                        required
                        class="field-control"
                    >
                        <option value="" disabled>Select Driver</option>
                        <option v-for="staff in deliveryStaffList" :key="staff.id" :value="String(staff.id)">
                            {{ staff.name }} ({{ staff.role }})
                        </option>
                    </select>
                </div>

                <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-end">
                    <button
                        @click="showAssignModal = false"
                        type="button"
                        class="secondary-button"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="assignForm.processing || !assignForm.staff_id"
                        class="brand-button"
                    >
                        Assign Driver
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- MODAL 6: RESCHEDULE DELIVERY (Admin only)                 -->
    <!-- ========================================================= -->
    <div v-if="showRescheduleModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
        <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl border border-gray-200 bg-card p-6 shadow-xl">
            <h3 class="text-lg font-semibold text-foreground">Reschedule Delivery & Reset Limit</h3>
            <p class="mt-1 text-xs text-muted-foreground">Authorize a new delivery window and grant fresh attempt slots.</p>

            <form @submit.prevent="submitReschedule" class="mt-4 space-y-4">
                <div>
                    <label class="field-label">New Delivery Date & Time</label>
                    <input
                        v-model="rescheduleForm.delivery_schedule"
                        type="datetime-local"
                        required
                        class="field-control"
                    />
                </div>

                <div class="flex items-center gap-2">
                    <input
                        id="resetAttempts"
                        v-model="rescheduleForm.reset_attempts"
                        type="checkbox"
                        class="size-4 rounded border-gray-200 bg-background text-brand focus:ring-brand/20"
                    />
                    <label for="resetAttempts" class="text-xs font-medium text-foreground cursor-pointer">
                        Reset Attempt Counter back to 0 of 3 attempts
                    </label>
                </div>

                <div>
                    <label class="field-label">Authorization Notes</label>
                    <textarea
                        v-model="rescheduleForm.notes"
                        rows="2"
                        placeholder="Reason for reschedule authorization (e.g. customer requested postponement)..."
                        class="field-control"
                    />
                </div>

                <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-end">
                    <button
                        @click="showRescheduleModal = false"
                        type="button"
                        class="secondary-button"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="rescheduleForm.processing"
                        class="brand-button"
                    >
                        Authorize Reschedule
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- MODAL 7: PHOTO PROOF VIEWER                               -->
    <!-- ========================================================= -->
    <div v-if="showProofViewerModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4">
        <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl border border-gray-200 bg-card p-6 shadow-xl">
            <div class="flex items-center justify-between gap-3 border-b border-gray-200 pb-3">
                <h3 class="text-sm font-semibold text-foreground">{{ viewingProofTitle }}</h3>
                <button @click="showProofViewerModal = false" class="text-muted-foreground hover:text-foreground">
                    <XCircle class="size-5" />
                </button>
            </div>
            <div class="mt-4 flex items-center justify-center bg-black/40 rounded-xl p-2">
                <img :src="viewingProofUrl || ''" class="max-h-[70vh] max-w-full rounded-lg object-contain shadow-lg" />
            </div>
            <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-end">
                <button
                    @click="showProofViewerModal = false"
                    class="secondary-button"
                >
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- UPDATE STATUS                                             -->
    <!-- ========================================================= -->
    <div v-if="showStatusUpdate && selectedFulfillment" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
        <form @submit.prevent="submitStatusUpdate" class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl border border-gray-200 bg-card p-6 shadow-xl">
            <h3 class="text-lg font-semibold text-foreground">Update delivery status</h3>
            <p class="mt-1 text-xs text-muted-foreground">
                {{ selectedFulfillment.fulfillment_number }} · {{ selectedFulfillment.sale?.order_number }} · now
                <span class="font-medium text-foreground">{{ selectedFulfillment.status_label }}</span>
                <span v-if="selectedFulfillment.fulfillment_type === 'pickup'"> · customer pickup</span>
            </p>

            <fieldset class="mt-4 space-y-2">
                <legend class="field-label">New status</legend>
                <label v-for="c in selectedFulfillment.status_choices" :key="c.action"
                       :class="statusChoice?.action === c.action ? 'border-[#4b5320] bg-[#4b5320]/5' : 'border-gray-200'"
                       class="flex cursor-pointer items-center gap-3 rounded-xl border p-3 text-sm">
                    <input v-model="statusChoice" type="radio" :value="c" class="accent-[#4b5320]" />
                    <span class="min-w-0 flex-1 text-foreground">{{ c.label }}</span>
                    <span v-if="c.status" class="shrink-0 rounded-md border px-2 py-0.5 text-[11px] font-medium" :class="getStatusBadgeClass(c.status)">{{ statusLabel(c.status) }}</span>
                </label>
            </fieldset>

            <div v-if="statusChoice" class="mt-4 space-y-4">
                <!-- Preparing: what goes out on this release -->
                <div v-if="statusChoice.needs === 'quantities'">
                    <span class="field-label">Items for this {{ selectedFulfillment.fulfillment_type === 'pickup' ? 'pickup' : 'trip' }}</span>
                    <div class="divide-y divide-gray-100 rounded-xl border border-gray-200">
                        <div v-for="line in selectedFulfillment.sale?.items ?? []" :key="line.id" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                            <div class="min-w-0">
                                <div class="truncate font-medium text-foreground">{{ line.product_name }}</div>
                                <div class="text-xs text-muted-foreground">Ordered {{ line.quantity }} · delivered {{ line.delivered_quantity }} · can send {{ line.releasable_quantity }}</div>
                            </div>
                            <input v-model.number="statusForm.quantities[line.id]" type="number" min="0" :max="line.releasable_quantity" :disabled="line.releasable_quantity === 0"
                                   class="field-control w-24 text-right" :aria-label="`Quantity of ${line.product_name}`" />
                        </div>
                    </div>
                    <p class="mt-1 text-xs text-muted-foreground">Anything left out stays owed for a later release.</p>
                </div>

                <!-- Delivered: what was received, by whom, with proof -->
                <template v-if="statusChoice.needs === 'receipt'">
                    <div v-if="selectedFulfillment.open_release">
                        <span class="field-label">Quantities received</span>
                        <div class="divide-y divide-gray-100 rounded-xl border border-gray-200">
                            <div v-for="ri in selectedFulfillment.open_release.items" :key="ri.id" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                <span class="min-w-0 truncate">{{ ri.sale_item?.product_name }} <span class="text-xs text-muted-foreground">(sent {{ ri.quantity }})</span></span>
                                <input v-model.number="statusForm.quantities[ri.sale_item_id]" type="number" min="0" :max="ri.quantity" class="field-control w-24 text-right" :aria-label="`Received of ${ri.sale_item?.product_name}`" />
                            </div>
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">If something was not received, it goes back into stock and stays owed; the status returns to Preparing.</p>
                    </div>
                    <div>
                        <label class="field-label" for="su-name">{{ selectedFulfillment.fulfillment_type === 'pickup' ? 'Picked up by (name)' : 'Received by (name)' }}</label>
                        <input id="su-name" v-model="statusForm.recipient_name" required maxlength="255" class="field-control" />
                    </div>
                    <div>
                        <label class="field-label" for="su-photo">Photo proof {{ statusNeedsPhoto ? '(required, JPG or PNG)' : '(optional)' }}</label>
                        <input id="su-photo" type="file" accept="image/jpeg,image/png" :required="statusNeedsPhoto" class="field-control py-1.5 text-xs"
                               @change="statusForm.photo = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                    </div>
                </template>

                <!-- Delivery Attempt Failed -->
                <template v-if="statusChoice.needs === 'attempt'">
                    <p class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        Attempt {{ selectedFulfillment.attempt_count + 1 }} of 3. After the third, an administrator has to reschedule. The customer is emailed.
                    </p>
                    <div>
                        <label class="field-label" for="su-failure">Reason</label>
                        <select id="su-failure" v-model="statusForm.failure_reason" class="field-control">
                            <option v-for="r in failureReasons" :key="r" :value="r">{{ r }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="field-label" for="su-photo-attempt">Photo (optional)</label>
                        <input id="su-photo-attempt" type="file" accept="image/jpeg,image/png" class="field-control py-1.5 text-xs"
                               @change="statusForm.photo = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                    </div>
                </template>

                <!-- Reschedule after a failed attempt -->
                <template v-if="statusChoice.needs === 'schedule'">
                    <div>
                        <label class="field-label" for="su-date">New delivery date and time</label>
                        <input id="su-date" v-model="statusForm.delivery_schedule" type="datetime-local" required class="field-control" />
                    </div>
                    <label class="flex items-center gap-2 text-sm text-foreground">
                        <input v-model="statusForm.reset_attempts" type="checkbox" class="accent-[#4b5320]" /> Reset the attempt counter to 0 of 3
                    </label>
                </template>

                <div v-if="statusChoice.needs === 'reason'">
                    <label class="field-label" for="su-reason">Reason</label>
                    <textarea id="su-reason" v-model="statusForm.reason" rows="2" maxlength="500" required class="field-control"></textarea>
                </div>

                <div v-if="statusChoice.needs !== 'reason'">
                    <label class="field-label" for="su-remarks">{{ statusChoice.needs === 'attempt' ? 'What happened' : 'Remarks (optional)' }}</label>
                    <textarea id="su-remarks" v-model="statusForm.remarks" rows="2" maxlength="1000" :required="statusChoice.needs === 'attempt'" class="field-control"></textarea>
                </div>

                <p v-if="statusChoice.status && ['ready_for_delivery', 'out_for_delivery', 'delivered', 'delivery_attempt_failed', 'cancelled'].includes(statusChoice.status)" class="text-xs text-muted-foreground">
                    The customer is emailed about this change.
                </p>
            </div>

            <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-end">
                <button type="button" class="secondary-button" @click="showStatusUpdate = false">Cancel</button>
                <button type="submit" class="brand-button" :disabled="!canSubmitStatus">{{ statusSaving ? 'Saving…' : 'Update status' }}</button>
            </div>
        </form>
    </div>

    <!-- ========================================================= -->
    <!-- PREPARE RELEASE                                           -->
    <!-- ========================================================= -->
    <div v-if="showPrepareModal && selectedFulfillment" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
        <form @submit.prevent="submitPrepare" class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl border border-gray-200 bg-card p-6 shadow-xl">
            <h3 class="text-lg font-semibold text-foreground">Prepare release</h3>
            <p class="mt-1 text-xs text-muted-foreground">
                {{ selectedFulfillment.sale?.order_number }} · {{ selectedFulfillment.recipient_name }}. Choose what goes out on this
                {{ selectedFulfillment.fulfillment_type === 'pickup' ? 'pickup' : 'trip' }}; the rest stays owed for a later release.
            </p>
            <div class="mt-4 divide-y divide-gray-100 rounded-xl border border-gray-200">
                <div v-for="line in selectedFulfillment.sale?.items ?? []" :key="line.id" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                    <div class="min-w-0">
                        <div class="truncate font-medium text-foreground">{{ line.product_name }}</div>
                        <div class="text-xs text-muted-foreground">Ordered {{ line.quantity }} · delivered {{ line.delivered_quantity }} · can release {{ line.releasable_quantity }}</div>
                    </div>
                    <input v-model.number="prepareQuantities[line.id]" type="number" min="0" :max="line.releasable_quantity" :disabled="line.releasable_quantity === 0"
                           class="field-control w-24 text-right" :aria-label="`Quantity of ${line.product_name}`" />
                </div>
            </div>
            <label class="field-label mt-4" for="prepare-remarks">Remarks (optional)</label>
            <textarea id="prepare-remarks" v-model="prepareRemarks" rows="2" maxlength="1000" class="field-control" placeholder="e.g. second truck, breakers only"></textarea>
            <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-end">
                <button type="button" class="secondary-button" @click="showPrepareModal = false">Cancel</button>
                <button type="submit" class="brand-button" :disabled="preparing || !Object.values(prepareQuantities).some((q) => Number(q) > 0)">
                    <Package class="size-4" /> Start preparing
                </button>
            </div>
        </form>
    </div>

    <!-- ========================================================= -->
    <!-- REASON PROMPT (cancel release / returned)                 -->
    <!-- ========================================================= -->
    <div v-if="reasonPrompt" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
        <form @submit.prevent="submitReason" class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl border border-gray-200 bg-card p-6 shadow-xl">
            <h3 class="text-lg font-semibold text-foreground">{{ reasonPrompt.title }}</h3>
            <p class="mt-1 text-xs text-muted-foreground">{{ reasonPrompt.help }}</p>
            <label class="field-label mt-4" for="delivery-reason">Reason</label>
            <textarea id="delivery-reason" v-model="reasonText" rows="3" maxlength="500" required class="field-control"></textarea>
            <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-end">
                <button type="button" class="secondary-button" @click="reasonPrompt = null">Back</button>
                <button type="submit" class="brand-button" :disabled="!reasonText.trim()">{{ reasonPrompt.confirm }}</button>
            </div>
        </form>
    </div>

    <!-- ========================================================= -->
    <!-- MODAL 8: CREATE INBOUND PO RECORD (Preserved)             -->
    <!-- ========================================================= -->
    <div v-if="showCreateInboundModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
        <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl border border-gray-200 bg-card p-6 shadow-xl">
            <h3 class="text-lg font-semibold text-foreground">Record Inbound Supplier Delivery</h3>
            <p class="mt-1 text-xs text-muted-foreground">Goods are received only against a purchase order sent to the supplier. Enter what actually arrived; stock changes only after inspection and invoice verification.</p>
            <form @submit.prevent="submitInboundDelivery" class="mt-4 space-y-4">
                <div>
                    <label class="field-label" for="ib-po">Purchase order</label>
                    <select id="ib-po" v-model="inboundForm.purchase_order_id" required class="field-control">
                        <option :value="null" disabled>Select an open purchase order</option>
                        <option v-for="po in purchaseOrders ?? []" :key="po.id" :value="po.id">{{ po.po_number }} · {{ po.description }} · {{ po.remaining }} of {{ po.quantity }} still owed</option>
                    </select>
                    <p v-if="!(purchaseOrders ?? []).length" class="mt-1 text-xs text-amber-700">No purchase order is waiting for goods.</p>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="field-label" for="ib-date">Delivery date</label>
                        <input id="ib-date" v-model="inboundForm.delivery_date" type="date" :max="new Date().toISOString().slice(0, 10)" required class="field-control" />
                    </div>
                    <div>
                        <label class="field-label" for="ib-qty">Delivered quantity</label>
                        <input id="ib-qty" v-model.number="inboundForm.quantity" type="number" min="1" required class="field-control" />
                    </div>
                </div>
                <div>
                    <label class="field-label" for="ib-doc">Supplier delivery document no.</label>
                    <input id="ib-doc" v-model="inboundForm.supplier_document_number" type="text" class="field-control" />
                </div>
                <div v-if="selectedInboundPo && inboundForm.quantity > (selectedInboundPo.remaining ?? 0)">
                    <label class="field-label" for="ib-over">Reason for receiving more than is owed (administrator only)</label>
                    <input id="ib-over" v-model="inboundForm.over_receipt_reason" type="text" class="field-control" />
                </div>
                <div>
                    <label class="field-label" for="ib-notes">Remarks / discrepancies</label>
                    <input id="ib-notes" v-model="inboundForm.notes" type="text" class="field-control" />
                </div>
                <p v-for="(e, k) in inboundForm.errors" :key="k" class="text-xs text-rose-600">{{ e }}</p>
                <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-end">
                    <button @click="showCreateInboundModal = false" type="button" class="secondary-button">Cancel</button>
                    <button type="submit" :disabled="inboundForm.processing" class="brand-button">Save Delivery</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- MODAL 9: DELETE INBOUND CONFIRMATION                      -->
    <!-- ========================================================= -->
    <div v-if="showDeleteConfirm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
        <div class="max-h-[90vh] w-full max-w-sm overflow-y-auto rounded-xl border border-rose-200 bg-card p-6 shadow-xl">
            <h3 class="text-base font-semibold text-foreground">Delete Delivery Record?</h3>
            <p class="mt-2 text-xs text-muted-foreground">Are you sure you want to delete this delivery record? This action cannot be undone.</p>
            <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-end">
                <button @click="showDeleteConfirm = false" type="button" class="secondary-button">Cancel</button>
                <button @click="executeDeleteInbound" type="button" class="inline-flex min-h-10 items-center justify-center rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-rose-700">Delete</button>
            </div>
        </div>
    </div>
    <!-- Goods-receipt inspection -->
    <div v-if="inspectingDelivery" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="inspectingDelivery = null">
        <form class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl border border-gray-200 bg-card p-6" role="dialog" aria-modal="true" aria-labelledby="inspect-title" @submit.prevent="submitInspection">
            <h3 id="inspect-title" class="text-lg font-semibold text-foreground">Inspect delivery {{ inspectingDelivery.delivery_number }}</h3>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ inspectingDelivery.quantity }} {{ inspectingDelivery.unit }} received from {{ inspectingDelivery.supplier?.name || 'unknown supplier' }}.
                Record what was accepted and rejected; the result is worked out from these numbers.
            </p>
            <div class="mt-4 grid grid-cols-2 gap-3">
                <div>
                    <label class="field-label" for="insp-accepted">Accepted</label>
                    <input id="insp-accepted" v-model.number="inspectionForm.accepted_quantity" type="number" min="0" :max="inspectingDelivery.quantity" class="field-control" required />
                </div>
                <div>
                    <label class="field-label" for="insp-rejected">Rejected</label>
                    <input id="insp-rejected" v-model.number="inspectionForm.rejected_quantity" type="number" min="0" :max="inspectingDelivery.quantity" class="field-control" required />
                </div>
            </div>
            <p class="mt-1 text-xs" :class="inspectionForm.accepted_quantity + inspectionForm.rejected_quantity === inspectingDelivery.quantity ? 'text-muted-foreground' : 'text-rose-600'">
                Accepted + rejected must equal {{ inspectingDelivery.quantity }} (now {{ inspectionForm.accepted_quantity + inspectionForm.rejected_quantity }}).
            </p>
            <p v-if="inspectionForm.errors.accepted_quantity" class="mt-1 text-xs text-rose-600">{{ inspectionForm.errors.accepted_quantity }}</p>
            <div class="mt-3">
                <label class="field-label" for="insp-date">Inspection date</label>
                <input id="insp-date" v-model="inspectionForm.inspected_on" type="date" :min="inspectingDelivery.delivery_date.slice(0, 10)" :max="new Date().toISOString().slice(0, 10)" class="field-control" required />
                <p v-if="inspectionForm.errors.inspected_on" class="mt-1 text-xs text-rose-600">{{ inspectionForm.errors.inspected_on }}</p>
            </div>
            <div v-if="inspectionForm.rejected_quantity > 0" class="mt-3 grid grid-cols-2 gap-3">
                <div>
                    <label class="field-label" for="insp-dmg">of which damaged</label>
                    <input id="insp-dmg" v-model.number="inspectionForm.damaged_quantity" type="number" min="0" :max="inspectionForm.rejected_quantity" class="field-control" />
                </div>
                <div>
                    <label class="field-label" for="insp-def">of which defective</label>
                    <input id="insp-def" v-model.number="inspectionForm.defective_quantity" type="number" min="0" :max="inspectionForm.rejected_quantity" class="field-control" />
                </div>
                <p class="col-span-2 text-xs text-muted-foreground">{{ otherRejected }} rejected for another reason. Rejected units are held and never added to stock.</p>
                <p v-if="inspectionForm.errors.damaged_quantity" class="col-span-2 text-xs text-rose-600">{{ inspectionForm.errors.damaged_quantity }}</p>
            </div>
            <div v-if="inspectionForm.rejected_quantity > 0" class="mt-3">
                <label class="field-label" for="insp-cat">Rejection category{{ otherRejected === 0 ? ' (optional)' : '' }}</label>
                <select id="insp-cat" v-model="inspectionForm.rejection_category" class="field-control" :required="otherRejected > 0">
                    <option value="">{{ otherRejected === 0 ? 'From the breakdown' : 'Choose…' }}</option>
                    <option v-for="c in rejectionCategories" :key="c" :value="c">{{ c.replace(/_/g, ' ') }}</option>
                </select>
                <p v-if="inspectionForm.errors.rejection_category" class="mt-1 text-xs text-rose-600">{{ inspectionForm.errors.rejection_category }}</p>
            </div>
            <div v-if="inspectionForm.rejected_quantity > 0" class="mt-3">
                <label class="field-label" for="insp-reason">Defect or rejection reason</label>
                <textarea id="insp-reason" v-model="inspectionForm.rejection_reason" rows="2" maxlength="1000" class="field-control" required></textarea>
                <p v-if="inspectionForm.errors.rejection_reason" class="mt-1 text-xs text-rose-600">{{ inspectionForm.errors.rejection_reason }}</p>
            </div>
            <div class="mt-3">
                <label class="field-label" for="insp-remarks">Remarks (optional)</label>
                <textarea id="insp-remarks" v-model="inspectionForm.inspection_remarks" rows="2" maxlength="1000" class="field-control"></textarea>
            </div>
            <p v-if="inspectionError" class="mt-2 text-xs text-rose-600">{{ inspectionError }}</p>
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" class="secondary-button" @click="inspectingDelivery = null">Cancel</button>
                <button type="submit" class="brand-button" :disabled="inspectionForm.processing || inspectionForm.accepted_quantity + inspectionForm.rejected_quantity !== inspectingDelivery.quantity">Save inspection</button>
            </div>
        </form>
    </div>

    <PurchaseOrderWorkspace :po-id="workspacePoId" :is-admin="!!isAdmin" @close="workspacePoId = null; router.reload({ only: ['inboundDeliveries', 'purchaseOrders', 'inboundMetrics'] })" />
</template>
