<script setup lang="ts">
/**
 * Submit Purchase Requests for one or more products. The recommended quantity, AHP score and
 * forecast shown here come from the backend; the server takes its own snapshot on submit.
 */
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

export interface PrCandidate {
    product_id: number;
    name: string;
    sku: string;
    unit?: string;
    current_stock: number | null;
    forecast: number | null;
    ahp_score: number | null;
    classification: string | null;
    recommended_quantity: number | null;
}

const props = defineProps<{ open: boolean; candidates: PrCandidate[] }>();
const emit = defineEmits<{ close: []; submitted: [] }>();

const page = usePage();
const teamSlug = computed(() => (page.props as any).currentTeam?.slug || (page.props as any).team?.slug || '');

const form = useForm({
    lines: [] as { product_id: number; quantity: number; justification: string }[],
    draft: false,
});

watch(
    () => [props.open, props.candidates],
    () => {
        if (!props.open) return;
        form.clearErrors();
        form.draft = false;
        form.lines = props.candidates.map((c) => ({
            product_id: c.product_id,
            quantity: c.recommended_quantity && c.recommended_quantity > 0 ? c.recommended_quantity : 1,
            justification: c.recommended_quantity
                ? `AHP reorder recommendation: available ${c.current_stock ?? '—'}, SMA forecast ${c.forecast ?? 'n/a'}.`
                : '',
        }));
    },
    { immediate: true },
);

const err = (i: number, field: string) => (form.errors as Record<string, string>)[`lines.${i}.${field}`];

const submit = (draft: boolean) => {
    form.draft = draft;
    form.post(`/${teamSlug.value}/procurement/requests`, {
        preserveScroll: true,
        onSuccess: () => emit('submitted'),
    });
};
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="emit('close')">
        <div class="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-xl border border-gray-200 bg-white" role="dialog" aria-modal="true" aria-labelledby="pr-create-title">
            <div class="border-b border-gray-100 px-6 py-4">
                <h3 id="pr-create-title" class="text-base font-semibold text-gray-900">Create purchase request</h3>
                <p class="text-xs text-gray-500">One request per product. An administrator must approve it before a purchase order can be made. Nothing is ordered and stock does not change yet.</p>
            </div>
            <div class="space-y-3 overflow-y-auto px-6 py-4">
                <p v-if="form.errors.lines" class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ form.errors.lines }}</p>
                <p v-if="(form.errors as any).error" class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ (form.errors as any).error }}</p>
                <div v-for="(line, i) in form.lines" :key="line.product_id" class="rounded-xl border border-gray-200 p-3">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <div>
                            <div class="font-medium text-gray-900">{{ candidates[i]?.name }}</div>
                            <div class="font-mono text-xs text-gray-500">{{ candidates[i]?.sku }}</div>
                        </div>
                        <dl class="flex gap-4 text-xs text-gray-600">
                            <div><dt class="text-gray-400">Available</dt><dd class="font-mono">{{ candidates[i]?.current_stock ?? '—' }}</dd></div>
                            <div><dt class="text-gray-400">SMA forecast</dt><dd class="font-mono">{{ candidates[i]?.forecast ?? 'Insufficient data' }}</dd></div>
                            <div><dt class="text-gray-400">AHP score</dt><dd class="font-mono">{{ candidates[i]?.ahp_score != null ? candidates[i]!.ahp_score!.toFixed(2) : 'Incomplete' }}</dd></div>
                            <div><dt class="text-gray-400">Recommended</dt><dd class="font-mono">{{ candidates[i]?.recommended_quantity ?? '—' }}</dd></div>
                        </dl>
                    </div>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-4">
                        <div>
                            <label class="field-label" :for="`pr-q-${i}`">Requested quantity</label>
                            <input :id="`pr-q-${i}`" v-model.number="line.quantity" type="number" min="1" class="field-control w-full" />
                            <p v-if="err(i, 'quantity')" class="mt-1 text-xs text-rose-600">{{ err(i, 'quantity') }}</p>
                        </div>
                        <div class="sm:col-span-3">
                            <label class="field-label" :for="`pr-j-${i}`">Purchase justification</label>
                            <textarea :id="`pr-j-${i}`" v-model="line.justification" rows="2" class="field-control w-full" required></textarea>
                            <p v-if="err(i, 'justification')" class="mt-1 text-xs text-rose-600">{{ err(i, 'justification') }}</p>
                            <p v-if="err(i, 'product_id')" class="mt-1 text-xs text-rose-600">{{ err(i, 'product_id') }}</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-gray-100 bg-gray-50/50 px-6 py-3">
                <button type="button" class="secondary-button" @click="emit('close')">Cancel</button>
                <button type="button" class="secondary-button" :disabled="form.processing" @click="submit(true)">Save as draft</button>
                <button type="button" class="brand-button" :disabled="form.processing || !form.lines.length" @click="submit(false)">Submit for approval</button>
            </div>
        </div>
    </div>
</template>
