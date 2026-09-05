<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Acceso del personal" />

        <div class="text-center">
            <h2 class="text-2xl font-bold tracking-tight text-slate-900">
                Acceso del personal
            </h2>
            <p class="mt-2 text-sm text-slate-600">
                Plataforma interna del Despacho Dr. R
            </p>
        </div>

        <div v-if="status" class="mb-4 text-sm font-medium text-green-600">
            {{ status }}
        </div>

        <form class="mt-8" @submit.prevent="submit">
            <div>
                <InputLabel for="email" value="Correo electrónico" />

                <div class="relative mt-2">
                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                        </svg>
                    </span>
                    <TextInput
                        id="email"
                        type="email"
                        class="mt-0 w-full border-slate-300 pl-10"
                        v-model="form.email"
                        placeholder="nombre@despachor.com"
                        required
                        autofocus
                        autocomplete="username"
                    />
                </div>

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div class="mt-5">
                <InputLabel for="password" value="Contraseña" />

                <div class="relative mt-2">
                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                        </svg>
                    </span>
                    <TextInput
                        id="password"
                        type="password"
                        class="mt-0 w-full border-slate-300 pl-10"
                        v-model="form.password"
                        placeholder="••••••••"
                        required
                        autocomplete="current-password"
                    />
                </div>

                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="mt-6 flex items-center justify-between">
                <label class="flex items-center">
                    <Checkbox name="remember" v-model:checked="form.remember" />
                    <span class="ms-2 text-sm text-slate-600">Recuérdame</span>
                </label>

                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="text-sm font-medium text-indigo-600 transition hover:text-indigo-700"
                >
                    ¿Olvidaste tu contraseña?
                </Link>
            </div>

            <div class="mt-6">
                <button
                    type="submit"
                    :disabled="form.processing"
                    :class="{ 'opacity-25': form.processing }"
                    class="inline-flex w-full items-center justify-center rounded-lg border border-transparent bg-indigo-600 px-4 py-3 text-sm font-semibold uppercase tracking-wider text-white shadow-lg shadow-indigo-600/30 transition duration-150 ease-in-out hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 active:bg-indigo-900"
                >
                    <svg v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    Entrar
                </button>
            </div>
        </form>

        <p class="mt-6 text-center text-sm text-slate-500">
            Acceso restringido al personal autorizado del despacho.
        </p>
    </GuestLayout>
</template>