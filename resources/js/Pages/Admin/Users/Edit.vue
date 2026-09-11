<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    managedUser: { type: Object, required: true },
    roles: { type: Array, required: true },
    permissions: { type: Array, required: true },
    rolePermissions: { type: Object, default: () => ({}) },
});

const initials = props.permissions
    .filter((permission) => permission.active)
    .map((permission) => permission.name);

const userForm = useForm({
    role: props.managedUser.role || props.roles[0],
    permissions: [...initials],
});

// Al cambiar el rol a mano, recargar los permisos por defecto de ese rol pero
// conservando los permisos directos ya marcados (los que no eran por defecto
// del rol anterior). Se ignora el primer disparo (montaje).
let isFirstRoleRender = true;
let lastRole = userForm.role;
watch(() => userForm.role, (role) => {
    if (isFirstRoleRender) {
        isFirstRoleRender = false;
        return;
    }

    const oldDefaults = props.rolePermissions[lastRole] || [];
    const extras = userForm.permissions.filter((name) => !oldDefaults.includes(name));
    userForm.permissions = [...new Set([...(props.rolePermissions[role] || []), ...extras])];
    lastRole = role;
});

const defaultPermissionsForRole = computed(
    () => props.rolePermissions[userForm.role] || [],
);

const togglePermission = (permissionName) => {
    const index = userForm.permissions.indexOf(permissionName);
    if (index >= 0) {
        userForm.permissions.splice(index, 1);
    } else {
        userForm.permissions.push(permissionName);
    }
};

const saveUser = () => {
    userForm.patch(route('admin.users.update', props.managedUser.id), {
        preserveScroll: true,
    });
};

const permissionLabel = (name) => name.replace(/\./g, ' · ');
</script>

<template>
    <Head :title="`Gestionar ${managedUser.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <Link :href="route('admin.users.index')" class="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">Usuarios</Link>
                    <h2 class="text-2xl font-semibold text-slate-900 dark:text-white">{{ managedUser.name }}</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ managedUser.email }}</p>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <div v-if="$page.props.flash?.success" class="rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ $page.props.flash.success }}
            </div>

            <section class="rounded-lg border border-slate-200 bg-white p-6 dark:bg-slate-800 dark:border-slate-700">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Rol y permisos</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    Elige un rol para precargar sus permisos por defecto y añade o quita otros con los checkboxes.
                    Desmarcar un permiso lo desactiva solo para este usuario, aunque el rol lo incluya.
                </p>

                <label for="role" class="mt-4 block text-sm font-medium text-slate-700 dark:text-slate-300">Rol</label>
                <select
                    id="role"
                    v-model="userForm.role"
                    class="mt-1 rounded-md border-slate-300 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100"
                    @change="userForm.errors.role = null"
                >
                    <option v-for="role in roles" :key="role" :value="role">{{ role }}</option>
                </select>
                <p v-if="userForm.errors.role" class="mt-1 text-sm text-red-600">{{ userForm.errors.role }}</p>

                <div class="mt-6 divide-y divide-slate-100 dark:divide-slate-700 rounded-lg border border-slate-200 dark:border-slate-700">
                    <label
                        v-for="permission in permissions"
                        :key="permission.name"
                        class="flex items-center justify-between gap-4 px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-700/40"
                    >
                        <div>
                            <p class="font-medium text-slate-800 dark:text-slate-100">{{ permissionLabel(permission.name) }}</p>
                            <p v-if="defaultPermissionsForRole.includes(permission.name)" class="text-xs text-slate-500 dark:text-slate-400">
                                Por defecto del rol «{{ userForm.role }}»
                            </p>
                        </div>
                        <input
                            type="checkbox"
                            class="rounded border-slate-300 text-sky-600 focus:ring-sky-500 dark:border-slate-600"
                            :checked="userForm.permissions.includes(permission.name)"
                            @change="togglePermission(permission.name)"
                        />
                    </label>
                </div>
                <p v-if="userForm.errors.permissions" class="mt-2 text-sm text-red-600">{{ userForm.errors.permissions }}</p>

                <div class="mt-5 flex justify-end">
                    <button
                        type="button"
                        class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700"
                        :disabled="userForm.processing"
                        @click="saveUser"
                    >
                        {{ userForm.processing ? 'Guardando...' : 'Guardar cambios' }}
                    </button>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
