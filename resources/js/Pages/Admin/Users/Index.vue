<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    users: { type: Array, required: true },
    roles: { type: Array, required: true },
    permissions: { type: Array, required: true },
    rolePermissions: { type: Object, default: () => ({}) },
});

const showCreateModal = ref(false);

const createForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: props.roles[0] ?? '',
    permissions: [],
});

// Permisos por defecto del rol seleccionado actualmente.
const defaultPermissionsForRole = computed(() => props.rolePermissions[createForm.role] || []);

// Al cambiar el rol, precargar sus permisos por defecto en el formulario.
watch(defaultPermissionsForRole, (perms) => {
    if (createForm.role) {
        createForm.permissions = [...perms];
    }
});

const openCreateModal = () => {
    createForm.reset('name', 'email', 'password', 'password_confirmation');
    createForm.permissions = [...defaultPermissionsForRole.value];
    showCreateModal.value = true;
};

const togglePermission = (name) => {
    const index = createForm.permissions.indexOf(name);
    if (index === -1) {
        createForm.permissions.push(name);
    } else {
        createForm.permissions.splice(index, 1);
    }
};

const submitCreate = () => {
    createForm.post(route('admin.users.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showCreateModal.value = false;
            createForm.reset();
        },
    });
};
</script>

<template>
    <Head title="Administración de usuarios" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-slate-500">Administración</p>
                    <h2 class="text-2xl font-semibold text-slate-900">Usuarios</h2>
                </div>
                <button
                    type="button"
                    class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700"
                    @click="openCreateModal"
                >
                    + Crear usuario
                </button>
                <Link
                    :href="route('admin.audit-logs.index')"
                    class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                >
                    Ver auditoría
                </Link>
            </div>
        </template>

        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <div v-if="$page.props.flash?.success" class="mb-4 rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ $page.props.flash.success }}
            </div>

            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Usuario</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Rol</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Permisos efectivos</th>
                            <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="user in users" :key="user.id" class="hover:bg-slate-50">
                            <td class="px-5 py-4">
                                <p class="font-medium text-slate-900">{{ user.name }}</p>
                                <p class="text-sm text-slate-500">{{ user.email }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">
                                    {{ user.roles[0] || 'sin rol' }}
                                </span>
                            </td>
                            <td class="px-5 py-4 text-sm text-slate-600">{{ user.permissions_count }}</td>
                            <td class="px-5 py-4 text-right">
                                <Link
                                    :href="route('admin.users.edit', user.id)"
                                    class="text-sm font-medium text-sky-700 hover:underline"
                                >
                                    Gestionar
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" @click.self="showCreateModal = false">
            <form class="max-h-[92vh] w-full max-w-lg overflow-auto rounded-lg bg-white p-6 shadow-xl" @submit.prevent="submitCreate">
                <h3 class="text-lg font-semibold text-slate-900">Crear usuario</h3>

                <label for="create-name" class="mt-4 block text-sm font-medium text-slate-700">Nombre</label>
                <input id="create-name" v-model="createForm.name" type="text" class="mt-1 block w-full rounded-md border-slate-300" required autofocus />
                <p v-if="createForm.errors.name" class="mt-1 text-sm text-red-600">{{ createForm.errors.name }}</p>

                <label for="create-email" class="mt-4 block text-sm font-medium text-slate-700">Correo electrónico</label>
                <input id="create-email" v-model="createForm.email" type="email" class="mt-1 block w-full rounded-md border-slate-300" required />
                <p v-if="createForm.errors.email" class="mt-1 text-sm text-red-600">{{ createForm.errors.email }}</p>

                <label for="create-password" class="mt-4 block text-sm font-medium text-slate-700">Contraseña</label>
                <input id="create-password" v-model="createForm.password" type="password" class="mt-1 block w-full rounded-md border-slate-300" required />
                <p v-if="createForm.errors.password" class="mt-1 text-sm text-red-600">{{ createForm.errors.password }}</p>

                <label for="create-password-confirmation" class="mt-4 block text-sm font-medium text-slate-700">Confirmar contraseña</label>
                <input id="create-password-confirmation" v-model="createForm.password_confirmation" type="password" class="mt-1 block w-full rounded-md border-slate-300" required />

                <label for="create-role" class="mt-4 block text-sm font-medium text-slate-700">Rol</label>
                <select id="create-role" v-model="createForm.role" class="mt-1 block w-full rounded-md border-slate-300">
                    <option v-for="role in roles" :key="role" :value="role">{{ role }}</option>
                </select>
                <p v-if="createForm.errors.role" class="mt-1 text-sm text-red-600">{{ createForm.errors.role }}</p>

                <p class="mt-4 block text-sm font-medium text-slate-700">Permisos</p>
                <p class="text-xs text-slate-500">Al cambiar el rol se cargan sus permisos por defecto. Puedes marcarlos o desmarcarlos libremente para este usuario.</p>
                <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <label
                        v-for="permission in permissions"
                        :key="permission"
                        class="flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"
                    >
                        <input
                            type="checkbox"
                            class="rounded border-slate-300 text-sky-600"
                            :checked="createForm.permissions.includes(permission)"
                            @change="togglePermission(permission)"
                        />
                        {{ permission }}
                    </label>
                </div>
                <p v-if="createForm.errors.permissions" class="mt-1 text-sm text-red-600">{{ createForm.errors.permissions }}</p>

                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-md px-4 py-2 text-sm text-slate-600 hover:bg-slate-100" @click="showCreateModal = false">Cancelar</button>
                    <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white" :disabled="createForm.processing">Crear</button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
