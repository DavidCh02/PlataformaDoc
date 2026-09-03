<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    managedUser: { type: Object, required: true },
    roles: { type: Array, required: true },
    permissions: { type: Array, required: true },
});

const roleForm = useForm({ role: props.managedUser.role || props.roles[0] });

const directPermissions = computed(() =>
    props.permissions.filter((permission) => permission.direct).map((permission) => permission.name),
);

const permissionForm = useForm({
    permissions: [...directPermissions.value],
});

const updateRole = () => {
    roleForm.patch(route('admin.users.update-role', props.managedUser.id), {
        preserveScroll: true,
    });
};

const savePermissions = () => {
    permissionForm.patch(route('admin.users.sync-permissions', props.managedUser.id), {
        preserveScroll: true,
    });
};

const togglePermission = (permissionName) => {
    const index = permissionForm.permissions.indexOf(permissionName);
    if (index >= 0) {
        permissionForm.permissions.splice(index, 1);
    } else {
        permissionForm.permissions.push(permissionName);
    }
};

const permissionLabel = (name) => name.replace(/\./g, ' · ');
</script>

<template>
    <Head :title="`Gestionar ${managedUser.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <Link :href="route('admin.users.index')" class="text-sm text-slate-500 hover:text-slate-900">Usuarios</Link>
                    <h2 class="text-2xl font-semibold text-slate-900">{{ managedUser.name }}</h2>
                    <p class="text-sm text-slate-500">{{ managedUser.email }}</p>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <div v-if="$page.props.flash?.success" class="rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ $page.props.flash.success }}
            </div>

            <section class="rounded-lg border border-slate-200 bg-white p-6">
                <h3 class="text-lg font-semibold text-slate-900">Rol del usuario</h3>
                <p class="mt-1 text-sm text-slate-500">El rol define el conjunto base de permisos.</p>
                <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="updateRole">
                    <div>
                        <label for="role" class="block text-sm font-medium text-slate-700">Rol</label>
                        <select id="role" v-model="roleForm.role" class="mt-1 rounded-md border-slate-300">
                            <option v-for="role in roles" :key="role" :value="role">{{ role }}</option>
                        </select>
                    </div>
                    <button
                        type="submit"
                        class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700"
                        :disabled="roleForm.processing"
                    >
                        Guardar rol
                    </button>
                </form>
                <p v-if="roleForm.errors.role" class="mt-2 text-sm text-red-600">{{ roleForm.errors.role }}</p>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-900">Permisos individuales</h3>
                        <p class="mt-1 text-sm text-slate-500">
                            Activa o desactiva permisos directos. Los heredados del rol aparecen marcados como "vía rol".
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700"
                        :disabled="permissionForm.processing"
                        @click="savePermissions"
                    >
                        Guardar permisos
                    </button>
                </div>

                <div class="mt-6 divide-y divide-slate-100 rounded-lg border border-slate-200">
                    <label
                        v-for="permission in permissions"
                        :key="permission.name"
                        class="flex items-center justify-between gap-4 px-4 py-3 hover:bg-slate-50"
                    >
                        <div>
                            <p class="font-medium text-slate-800">{{ permissionLabel(permission.name) }}</p>
                            <p v-if="permission.via_role" class="text-xs text-slate-500">Incluido por rol</p>
                        </div>
                        <input
                            type="checkbox"
                            class="rounded border-slate-300 text-sky-600 focus:ring-sky-500"
                            :checked="permission.via_role || permissionForm.permissions.includes(permission.name)"
                            :disabled="permission.via_role"
                            @change="!permission.via_role && togglePermission(permission.name)"
                        />
                    </label>
                </div>
                <p v-if="permissionForm.errors.permissions" class="mt-2 text-sm text-red-600">{{ permissionForm.errors.permissions }}</p>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
