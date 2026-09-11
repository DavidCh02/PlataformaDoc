<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});
</script>

<template>
    <Head title="Mi perfil" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Cuenta</p>
                <h2 class="text-2xl font-semibold text-slate-900 dark:text-white">Mi perfil</h2>
            </div>
        </template>

        <div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm dark:bg-slate-800 dark:border-slate-700">
                <UpdateProfileInformationForm
                    :must-verify-email="mustVerifyEmail"
                    :status="status"
                    class="max-w-xl"
                />
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm dark:bg-slate-800 dark:border-slate-700">
                <UpdatePasswordForm class="max-w-xl" />
            </section>

            <section class="rounded-lg border border-red-200 bg-white p-6 shadow-sm dark:bg-slate-800 dark:border-red-800">
                <DeleteUserForm class="max-w-xl" />
            </section>
        </div>
    </AuthenticatedLayout>
</template>
