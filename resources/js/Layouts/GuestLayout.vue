<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="flex min-h-screen bg-slate-50">
        <!-- Left branding panel -->
        <div
            class="relative hidden w-1/2 items-center justify-center overflow-hidden bg-gradient-to-br from-indigo-700 via-indigo-800 to-slate-900 lg:flex"
        >
            <div class="absolute -top-24 -left-24 h-96 w-96 rounded-full bg-white/10 blur-3xl"></div>
            <div class="absolute -bottom-24 -right-24 h-96 w-96 rounded-full bg-white/10 blur-3xl"></div>

            <div class="relative max-w-md px-12 text-white">
                <Link href="/" class="mb-8 inline-flex items-center gap-3">
                    <ApplicationLogo class="h-14 w-14 text-white" />
                </Link>
                <h2 class="text-3xl font-bold leading-tight">
                    Plataforma del Despacho Dr. R
                </h2>
                <p class="mt-4 text-lg text-indigo-200">
                    Tu espacio de trabajo para guardar, redactar y dar seguimiento a
                    los documentos y expedientes del despacho.
                </p>

                <ul class="mt-10 space-y-4 text-indigo-100">
                    <li class="flex items-center gap-3">
                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-white/20">
                            <svg class="h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        </span>
                        Guarda y redacta tus expedientes
                    </li>
                    <li class="flex items-center gap-3">
                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-white/20">
                            <svg class="h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        </span>
                        Observa a tu equipo en tiempo real
                    </li>
                    <li class="flex items-center gap-3">
                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-white/20">
                            <svg class="h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        </span>
                        Acceso restringido al personal autorizado
                    </li>
                </ul>

                <p class="mt-12 text-sm text-indigo-300">
                    Uso interno — solo personal del despacho
                </p>
            </div>
        </div>

        <!-- Right form panel -->
        <div class="flex w-full flex-col items-center justify-center px-4 py-12 sm:px-6 lg:w-1/2">
            <div class="mb-8 flex flex-col items-center">
                <Link href="/" class="mb-6 lg:hidden">
                    <ApplicationLogo class="h-14 w-14 text-indigo-600" />
                </Link>
            </div>

            <div class="w-full max-w-md">
                <div class="bg-white px-8 py-8 shadow-xl ring-1 ring-slate-200 sm:rounded-2xl">
                    <slot />
                </div>
            </div>
        </div>
    </div>
</template>