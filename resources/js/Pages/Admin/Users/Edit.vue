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

// Al cambiar el rol a mano, recargar los permisos por defecto de ese rol.
// Se ignora el primer disparo (montaje) para no pisar los permisos actuales.
let isFirstRoleRender = true;
watch(() => userForm.role, (role) => {
    if (isFirstRoleRender) {
        isFirstRoleRender = false;
        return;
    }

    userForm.permissions = [...(props.rolePermissions[role] || [])];
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
                <h3 class="text-lg font-semibold text-slate-900">Rol y permisos</h3>
                <p class="mt-1 text-sm text-slate-500">
                    Elige un rol para precargar sus permisos por defecto y ajústalos con los checkboxes.
                    Los cambios se guardan juntos con un solo clic.
                </p>

                <label for="role" class="mt-4 block text-sm font-medium text-slate-700">Rol</label>
                <select
                    id="role"
                    v-model="userForm.role"
                    class="mt-1 rounded-md border-slate-300"
                    @change="userForm.errors.role = null"
                >
                    <option v-for="role in roles" :key="role" :value="role">{{ role }}</option>
                </select>
                <p v-if="userForm.errors.role" class="mt-1 text-sm text-red-600">{{ userForm.errors.role }}</p>

                <div class="mt-6 divide-y divide-slate-100 rounded-lg border border-slate-200">
                    <label
                        v-for="permission in permissions"
                        :key="permission.name"
                        class="flex items-center justify-between gap-4 px-4 py-3 hover:bg-slate-50"
                    >
                        <div>
                            <p class="font-medium text-slate-800">{{ permissionLabel(permission.name) }}</p>
                            <p v-if="defaultPermissionsForRole.includes(permission.name)" class="text-xs text-slate-500">
                                Por defecto del rol «{{ userForm.role }}»
                            </p>
                        </div>
                        <input
                            type="checkbox"
                            class="rounded border-slate-300 text-sky-600 focus:ring-sky-500"
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
