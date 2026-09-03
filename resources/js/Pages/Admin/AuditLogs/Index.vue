<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    logs: { type: Object, required: true },
    filters: { type: Object, required: true },
    actions: { type: Array, required: true },
});

const actionFilter = ref(props.filters.action || '');

const applyFilter = () => {
    router.get(route('admin.audit-logs.index'), {
        action: actionFilter.value || undefined,
    }, { preserveState: true, replace: true });
};

const formatDate = (iso) => {
    if (!iso) return '';
    return new Date(iso).toLocaleString('es-ES');
};
</script>

<template>
    <Head title="Auditoría" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-slate-500">Administración</p>
                    <h2 class="text-2xl font-semibold text-slate-900">Registro de auditoría</h2>
                </div>
                <Link
                    :href="route('admin.users.index')"
                    class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                >
                    Volver a usuarios
                </Link>
            </div>
        </template>

        <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4">
                <div>
                    <label for="action-filter" class="block text-sm font-medium text-slate-700">Filtrar por acción</label>
                    <select id="action-filter" v-model="actionFilter" class="mt-1 rounded-md border-slate-300">
                        <option value="">Todas</option>
                        <option v-for="action in actions" :key="action" :value="action">{{ action }}</option>
                    </select>
                </div>
                <button
                    type="button"
                    class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700"
                    @click="applyFilter"
                >
                    Aplicar
                </button>
            </div>

            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold text-slate-500">Fecha</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-500">Usuario</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-500">Acción</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-500">Recurso</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-500">IP</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="log in logs.data" :key="log.id" class="hover:bg-slate-50">
                            <td class="px-4 py-3 whitespace-nowrap text-slate-600">{{ formatDate(log.created_at) }}</td>
                            <td class="px-4 py-3">
                                <p class="font-medium text-slate-800">{{ log.user?.name || 'Sistema' }}</p>
                                <p class="text-xs text-slate-500">{{ log.user?.email }}</p>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-700">{{ log.action }}</td>
                            <td class="px-4 py-3 text-xs text-slate-500">
                                <span v-if="log.auditable_type">{{ log.auditable_type }} #{{ log.auditable_id }}</span>
                                <span v-else>—</span>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ log.ip_address }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="logs.links?.length > 3" class="flex flex-wrap gap-2">
                <Link
                    v-for="link in logs.links"
                    :key="link.label"
                    :href="link.url || '#'"
                    class="rounded-md border px-3 py-1 text-sm"
                    :class="link.active ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 text-slate-700 hover:bg-slate-50'"
                    v-html="link.label"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
