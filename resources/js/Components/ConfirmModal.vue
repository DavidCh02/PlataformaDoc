<script setup>
import { onBeforeUnmount, onMounted } from 'vue';
import { Info, TriangleAlert } from 'lucide-vue-next';

defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, required: true },
    message: { type: String, default: '' },
    confirmLabel: { type: String, default: 'Confirmar' },
    cancelLabel: { type: String, default: 'Cancelar' },
    danger: { type: Boolean, default: false },
});

const emit = defineEmits(['confirm', 'cancel']);

const onKeydown = event => { if (event.key === 'Escape') emit('cancel'); };
onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" @click.self="emit('cancel')">
        <div class="w-full max-w-md rounded-xl border border-slate-200 bg-white p-6 shadow-2xl dark:border-slate-700 dark:bg-slate-800" role="alertdialog" aria-modal="true">
            <div class="flex items-start gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full"
                     :class="danger ? 'bg-red-100 text-red-600 dark:bg-red-900/60 dark:text-red-300' : 'bg-sky-100 text-sky-600 dark:bg-sky-900/60 dark:text-sky-300'">
                    <TriangleAlert v-if="danger" :size="20" />
                    <Info v-else :size="20" />
                </div>
                <div class="min-w-0">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-white">{{ title }}</h3>
                    <p v-if="message" class="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-300">{{ message }}</p>
                    <div class="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-300"><slot /></div>
                </div>
            </div>
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" class="explorer-action-button" @click="emit('cancel')">{{ cancelLabel }}</button>
                <button type="button" :class="danger ? 'explorer-danger-button' : 'explorer-primary-button'" @click="emit('confirm')">{{ confirmLabel }}</button>
            </div>
        </div>
    </div>
</template>
