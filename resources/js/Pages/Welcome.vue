<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';

const page = usePage();

defineProps({
    canLogin: {
        type: Boolean,
    },
    canRegister: {
        type: Boolean,
    },
});

const features = [
    {
        title: 'Guarda tus documentos',
        icon: 'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z',
    },
    {
        title: 'Redáctalos y edítalos',
        icon: 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z',
    },
    {
        title: 'Ve a tu equipo en tiempo real',
        icon: 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z',
    },
    {
        title: 'Control de acceso por personal',
        icon: 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z',
    },
];

const isLoggedIn = computed(() => page.props.auth?.user);
</script>

<template>
    <Head title="Plataforma interna" />

    <div class="flex min-h-screen flex-col bg-slate-50 text-slate-800">
        <!-- Navbar -->
        <nav class="sticky top-0 z-20 border-b border-slate-200/70 bg-white/85 backdrop-blur">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6 lg:px-8">
                <div class="flex items-center gap-3">
                    <ApplicationLogo class="h-10 w-10 text-indigo-600" />
                    <div class="leading-tight">
                        <span class="block text-lg font-bold tracking-tight text-slate-900">
                            Despacho <span class="text-indigo-600">Dr. R</span>
                        </span>
                        <span class="block text-xs font-medium text-slate-500">
                            Plataforma interna
                        </span>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <template v-if="canLogin">
                        <Link
                            v-if="isLoggedIn"
                            :href="route('dashboard')"
                            class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700"
                        >
                            Ir a mi panel
                        </Link>
                        <Link
                            v-else
                            :href="route('login')"
                            class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700"
                        >
                            Iniciar sesión
                        </Link>
                    </template>
                </div>
            </div>
        </nav>

        <!-- Hero -->
        <section class="relative overflow-hidden">
            <div class="absolute inset-0 -z-10 bg-gradient-to-br from-indigo-50 via-white to-slate-100"></div>
            <div class="absolute -top-24 -right-24 -z-10 h-96 w-96 rounded-full bg-indigo-200/40 blur-3xl"></div>
            <div class="absolute -bottom-24 -left-24 -z-10 h-96 w-96 rounded-full bg-sky-200/40 blur-3xl"></div>

            <div class="mx-auto grid max-w-6xl items-center gap-12 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:px-8 lg:py-24">
                <div>
                    <span class="inline-flex items-center rounded-full bg-indigo-100 px-3 py-1 text-xs font-semibold text-indigo-700">
                        Sistema de uso exclusivo del personal
                    </span>
                    <h1 class="mt-6 text-4xl font-extrabold tracking-tight text-slate-900 sm:text-5xl">
                        Bienvenido a la plataforma
                        <span class="text-indigo-600">del despacho</span>
                    </h1>
                    <p class="mt-6 max-w-xl text-lg text-slate-600">
                        Guarda tus documentos, redáctalos y dales seguimiento desde un
                        solo lugar. Como responsable, puedes ver en tiempo real lo que
                        está haciendo tu equipo.
                    </p>
                    <div class="mt-8 flex flex-wrap items-center gap-4">
                        <template v-if="canLogin">
                            <Link
                                v-if="!isLoggedIn"
                                :href="route('login')"
                                class="inline-flex items-center rounded-lg bg-indigo-600 px-6 py-3 text-base font-semibold text-white shadow-lg shadow-indigo-600/30 transition hover:bg-indigo-700"
                            >
                                Entrar a la plataforma
                            </Link>
                            <Link
                                v-else
                                :href="route('dashboard')"
                                class="inline-flex items-center rounded-lg bg-indigo-600 px-6 py-3 text-base font-semibold text-white shadow-lg shadow-indigo-600/30 transition hover:bg-indigo-700"
                            >
                                Abrir mi panel
                            </Link>
                        </template>
                        <p v-if="!isLoggedIn" class="text-sm text-slate-500">
                            Acceso restringido al personal autorizado del Dr. R.
                        </p>
                    </div>
                </div>

                <!-- Mock editor preview -->
                <div class="relative">
                    <div class="rounded-2xl bg-white p-2 shadow-2xl ring-1 ring-slate-200">
                        <div class="rounded-xl border border-slate-200 bg-slate-50">
                            <div class="flex items-center gap-2 border-b border-slate-200 bg-white px-4 py-3">
                                <span class="h-3 w-3 rounded-full bg-red-400"></span>
                                <span class="h-3 w-3 rounded-full bg-amber-400"></span>
                                <span class="h-3 w-3 rounded-full bg-emerald-400"></span>
                                <div class="ml-4 hidden flex-1 items-center gap-2 text-xs text-slate-400 sm:flex">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                    <span>Expediente en edición</span>
                                </div>
                            </div>
                            <div class="space-y-4 p-6">
                                <div class="h-6 w-3/4 rounded bg-slate-200"></div>
                                <div class="h-4 w-full rounded bg-slate-200/70"></div>
                                <div class="h-4 w-5/6 rounded bg-slate-200/70"></div>
                                <div class="flex items-center gap-2 pt-2 text-xs text-slate-400">
                                    <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-emerald-500 font-bold text-white">LA</span>
                                    <span>Lucía está redactando…</span>
                                    <span class="ml-auto inline-flex items-center gap-1 font-medium text-emerald-600">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                        </svg>
                                        Guardado en vivo
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="absolute -bottom-4 -left-4 rounded-xl bg-white px-4 py-3 shadow-lg ring-1 ring-slate-200">
                        <div class="flex items-center gap-2 text-sm font-semibold text-emerald-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            Colaboración en vivo
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- What you can do here -->
        <section class="bg-white py-20">
            <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-2xl text-center">
                    <h2 class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">
                        ¿Qué puedes hacer en la plataforma?
                    </h2>
                    <p class="mt-4 text-lg text-slate-600">
                        Un espacio sencillo pensado para el día a día del despacho.
                    </p>
                </div>

                <div class="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    <div
                        v-for="feature in features"
                        :key="feature.title"
                        class="rounded-2xl border border-slate-200 bg-slate-50 p-6 text-center transition hover:border-indigo-200 hover:bg-white hover:shadow-lg"
                    >
                        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-100 text-indigo-600">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" :d="feature.icon" />
                            </svg>
                        </div>
                        <h3 class="mt-4 text-base font-semibold text-slate-900">{{ feature.title }}</h3>
                    </div>
                </div>
            </div>
        </section>

        <!-- Access note -->
        <section class="py-16">
            <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <div class="flex flex-col items-center gap-4 rounded-2xl border border-slate-200 bg-slate-100 px-8 py-10 text-center sm:flex-row sm:text-left">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-indigo-600">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                        </svg>
                    </span>
                    <div>
                        <h3 class="text-lg font-semibold text-slate-900">Acceso restringido</h3>
                        <p class="mt-1 text-sm text-slate-600">
                            Esta plataforma es de uso exclusivo del personal autorizado del
                            Despacho Dr. R. Si necesitas acceso, solicítalo al administrador.
                        </p>
                    </div>
                    <Link
                        v-if="canLogin && !isLoggedIn"
                        :href="route('login')"
                        class="sm:ml-auto inline-flex items-center rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700"
                    >
                        Iniciar sesión
                    </Link>
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer class="mt-auto border-t border-slate-200 bg-white">
            <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-4 px-4 py-8 sm:flex-row sm:px-6 lg:px-8">
                <div class="flex items-center gap-3">
                    <ApplicationLogo class="h-8 w-8 text-indigo-600" />
                    <span class="font-semibold text-slate-700">
                        Despacho Dr. R
                    </span>
                </div>
                <p class="text-sm text-slate-500">
                    © {{ new Date().getFullYear() }} Despacho Dr. R — Uso interno
                </p>
            </div>
        </footer>
    </div>
</template>