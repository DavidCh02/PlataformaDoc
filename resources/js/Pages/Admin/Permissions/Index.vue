<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

const props = defineProps({
    roles: { type: Array, required: true },
    permissions: { type: Array, required: true },
});

// Estado editable: roleName -> array de permisos seleccionados.
const selections = reactive({});
props.roles.forEach((role) => {
    selections[role.name] = [...role.permissions.filter((name) => props.permissions.some(p => p.name === name))];
});

const saving = reactive({});
props.roles.forEach((role) => { saving[role.name] = false; });

const isSaving = roleName => saving[roleName];
const isSelected = (roleName, permissionName) => selections[roleName]?.includes(permissionName) ?? false;
const roleCount = roleName => selections[roleName]?.length ?? 0;
const allSelected = roleName => (selections[roleName]?.length ?? 0) === props.permissions.length;

const toggle = (roleName, permissionName) => {
    const list = selections[roleName];
    const index = list.indexOf(permissionName);
    if (index >= 0) {
        list.splice(index, 1);
    } else {
        list.push(permissionName);
    }
};

const toggleAll = (roleName) => {
    selections[roleName] = allSelected(roleName)
        ? (roleName === 'admin' ? ['users.manage'] : [])
        : props.permissions.map(p => p.name);
};

const save = (role) => {
    saving[role.name] = true;
    router.patch(route('admin.permissions.update'), {
        role: role.name,
        permissions: selections[role.name],
    }, {
        preserveScroll: true,
        onSuccess: () => { saving[role.name] = false; },
        onError: (errors) => {
            saving[role.name] = false;
            if (errors.permissions) window.alert(errors.permissions);
        },
    });
};

const canEditPermission = (role) => role.name !== 'admin';

const permissionLabel = (name) => name.replace(/\./g, ' · ');
</script>

<template>
    <Head title="Permisos por rol" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <Link :href="route('admin.users.index')" class="text-sm text-slate-500 hover:text-slate-900">Administración</Link>
                    <h2 class="text-2xl font-semibold text-slate-900">Permisos por rol</h2>
                    <p class="text-sm text-slate-500">Marca qué acciones puede realizar cada rol. El rol «admin» conserva siempre la administración.</p>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <div v-if="$page.props.flash?.success" class="rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ $page.props.flash.success }}
            </div>
            <div v-if="$page.props.errors?.permissions" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ $page.props.errors.permissions }}
            </div>

            <section v-for="role in roles" :key="role.id" class="rounded-lg border border-slate-200 bg-white shadow-sm">
                <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                    <div class="flex items-center gap-3">
                        <span class="rounded-md bg-sky-50 px-2.5 py-1 text-xs font-semibold uppercase tracking-wide text-sky-700">{{ role.name }}</span>
                        <span class="text-xs text-slate-500">{{ roleCount(role.name) }} de {{ permissions.length }} permisos</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <label class="flex cursor-pointer items-center gap-2 text-xs font-medium text-slate-600">
                            <input
                                type="checkbox"
                                class="rounded border-slate-300 text-sky-600 focus:ring-sky-500"
                                :checked="allSelected(role.name)"
                                @change="toggleAll(role.name)"
                            />
                            Seleccionar todos
                        </label>
                        <button
                            type="button"
                            class="rounded-md bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-700"
                            :disabled="isSaving(role.name)"
                            @click="save(role)"
                        >
                            {{ isSaving(role.name) ? 'Guardando...' : 'Guardar' }}
                        </button>
                    </div>
                </header>

                <div class="grid gap-x-6 gap-y-1 p-5 sm:grid-cols-2">
                    <label
                        v-for="permission in permissions"
                        :key="`${role.name}-${permission.name}`"
                        class="flex items-center justify-between gap-3 rounded-md px-2 py-1.5 hover:bg-slate-50"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-800">{{ permissionLabel(permission.name) }}</p>
                            <p v-if="role.name === 'admin' && permission.name === 'users.manage'" class="text-xs text-slate-500">
                                Obligatorio
                            </p>
                        </div>
                        <input
                            type="checkbox"
                            class="rounded border-slate-300 text-sky-600 focus:ring-sky-500"
                            :disabled="!canEditPermission(role) && permission.name === 'users.manage'"
                            :checked="isSelected(role.name, permission.name)"
                            @change="toggle(role.name, permission.name)"
                        />
                    </label>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>