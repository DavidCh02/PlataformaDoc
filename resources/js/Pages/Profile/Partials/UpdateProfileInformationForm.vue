<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { FlaskConical, Link2, Send, Unlink } from 'lucide-vue-next';
import { ref } from 'vue';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
    telegram: {
        type: Object,
        default: () => ({}),
    },
});

const user = usePage().props.auth.user;
const showManual = ref(false);
const testing = ref(false);

const form = useForm({
    name: user.name,
    email: user.email,
    telegram_chat_id: user.telegram_chat_id || '',
});

const sendTest = () => {
    testing.value = true;
    router.post(route('telegram.test'), {}, {
        preserveScroll: true,
        onFinish: () => { testing.value = false; },
    });
};
const unlink = () => {
    if (!confirm('¿Desvincular tu chat de Telegram? Dejarás de recibir avisos ahí.')) return;
    router.post(route('telegram.unlink'), {}, { preserveScroll: true });
};
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">
                Información del perfil
            </h2>

            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Actualiza el nombre y el correo electrónico de tu cuenta.
            </p>
        </header>

        <form
            @submit.prevent="form.patch(route('profile.update'))"
            class="mt-6 space-y-6"
        >
            <div>
                <InputLabel for="name" value="Nombre" />

                <TextInput
                    id="name"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="form.name"
                    required
                    autofocus
                    autocomplete="name"
                />

                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div>
                <InputLabel for="email" value="Correo electrónico" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div class="rounded-2xl border border-sky-200 bg-sky-50/60 p-4 dark:border-sky-900 dark:bg-sky-950/30">
                <div class="flex items-center justify-between gap-2">
                    <p class="flex items-center gap-1.5 text-sm font-extrabold text-slate-800 dark:text-slate-100">
                        <Send :size="15" class="text-sky-600 dark:text-sky-400" />Telegram · avisos de recordatorios
                    </p>
                    <span v-if="telegram?.linked" class="rounded-full bg-emerald-100 px-2.5 py-1 text-[0.7rem] font-extrabold text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">✅ Vinculado</span>
                    <span v-else class="rounded-full bg-slate-200 px-2.5 py-1 text-[0.7rem] font-extrabold text-slate-500 dark:bg-slate-700 dark:text-slate-300">Sin vincular</span>
                </div>

                <div v-if="!telegram?.configured" class="mt-2 rounded-xl bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">
                    ⚠️ El bot aún no está configurado en el servidor. Pídele al administrador que ponga el token de @BotFather en el <code>.env</code> (<code>TELEGRAM_BOT_TOKEN</code>).
                </div>

                <template v-else>
                    <ol class="mt-2 space-y-1.5 text-xs leading-relaxed text-slate-600 dark:text-slate-300">
                        <li><strong>1.</strong> Pulsa <strong>Vincular con Telegram</strong> (abre el chat del bot).</li>
                        <li><strong>2.</strong> En Telegram pulsa <strong>Iniciar / Start</strong>. El bot confirmará ✅.</li>
                        <li><strong>3.</strong> Vuelve aquí y pulsa <strong>Enviar prueba</strong> para comprobar que llega 📩.</li>
                    </ol>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <a v-if="telegram?.link_url" :href="telegram.link_url" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-sky-500 to-blue-600 px-4 py-2 text-xs font-extrabold text-white shadow-lg shadow-sky-500/30 transition hover:brightness-110">
                            <Link2 :size="14" />{{ telegram?.linked ? 'Re-vincular con Telegram' : 'Vincular con Telegram' }}
                        </a>
                        <button type="button" class="inline-flex items-center gap-1.5 rounded-xl border border-sky-300 bg-white px-3.5 py-2 text-xs font-extrabold text-sky-700 transition hover:bg-sky-100 disabled:opacity-60 dark:border-sky-800 dark:bg-slate-800 dark:text-sky-300 dark:hover:bg-sky-950" :disabled="testing || !telegram?.linked" title="Requiere chat vinculado" @click="sendTest">
                            <FlaskConical :size="14" />{{ testing ? 'Enviando…' : 'Enviar prueba' }}
                        </button>
                        <button v-if="telegram?.linked" type="button" class="inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-xs font-bold text-red-500 transition hover:bg-red-50 dark:hover:bg-red-950" @click="unlink">
                            <Unlink :size="13" />Desvincular
                        </button>
                    </div>
                    <p v-if="telegram?.linked" class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                        Chat actual: <code class="rounded bg-slate-200/70 px-1.5 py-0.5 font-mono dark:bg-slate-700">{{ user.telegram_chat_id }}</code> · Recibirás aquí cada recordatorio dirigido a ti.
                    </p>

                    <button type="button" class="mt-2 text-xs font-semibold text-slate-400 underline hover:text-slate-600" @click="showManual = !showManual">
                        {{ showManual ? 'Ocultar método manual' : '¿No se abre Telegram? Método manual' }}
                    </button>
                    <div v-show="showManual" class="mt-2">
                        <InputLabel for="telegram_chat_id" value="ID de chat (manual)" />
                        <TextInput
                            id="telegram_chat_id"
                            type="text"
                            class="mt-1 block w-full"
                            v-model="form.telegram_chat_id"
                            placeholder="p. ej. 123456789"
                        />
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Abre el chat del bot y pulsa /start, averigua tu ID escribiéndole a @userinfobot y pégalo aquí. Guarda con el botón principal.
                        </p>
                    </div>
                </template>

                <InputError class="mt-2" :message="form.errors.telegram_chat_id" />
            </div>

            <div v-if="mustVerifyEmail && user.email_verified_at === null">
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">
                    Tu correo electrónico no está verificado.
                    <Link
                        :href="route('verification.send')"
                        method="post"
                        as="button"
                        class="rounded-md text-sm text-sky-600 underline hover:text-sky-800 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-2"
                    >
                        Haz clic aquí para reenviar el correo de verificación.
                    </Link>
                </p>

                <div
                    v-show="status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-emerald-600"
                >
                    Se ha enviado un nuevo enlace de verificación a tu correo electrónico.
                </div>
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">Guardar</PrimaryButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p
                        v-if="form.recentlySuccessful"
                        class="text-sm text-emerald-600"
                    >
                        Guardado.
                    </p>
                </Transition>
            </div>
        </form>
    </section>
</template>