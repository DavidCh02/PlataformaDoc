<?php

namespace App\Http\Controllers;

use App\Models\Reminder;
use App\Models\User;
use App\Services\DueReminderDispatcher;
use App\Services\OfficialTime;
use App\Services\ReminderDebugSender;
use App\Services\TelegramNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Consola secreta de diagnóstico ("Lab").
 *
 * - Vive en una URL oscura (config/lab.php) sin enlaces en la app.
 * - Tiene su PROPIO login (email + contraseña) y exige `users.manage`.
 * - Sirve para adelantar recordatorios, probar Telegram, revisar permisos,
 *   cron/scheduler y ver errores con detalle en producción.
 */
class LabController extends Controller
{
    /** Clave de sesión que marca el acceso al lab. */
    public const SESSION_KEY = 'lab_auth_expires_at';

    /* ------------------------------------------------------------------ */
    /* Vista principal + login                                             */
    /* ------------------------------------------------------------------ */

    public function show(Request $request)
    {
        if (! config('lab.enabled', true)) {
            abort(404);
        }

        $response = response()->view('lab.login', ['base' => $this->base()]);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        if ($this->authed($request)) {
            $response = response()->view('lab.dashboard', array_merge(
                ['base' => $this->base(), 'user' => $request->user() ?? Auth::user()],
                $this->snapshot()
            ));
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    public function login(Request $request)
    {
        if (! config('lab.enabled', true)) {
            abort(404);
        }

        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = 'lab-login:'.strtolower((string) $credentials['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 8)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()->withErrors(['email' => "Demasiados intentos. Espera {$seconds} segundos."]);
        }
        RateLimiter::hit($throttleKey, 300);

        if (! Auth::attempt(['email' => $credentials['email'], 'password' => $credentials['password']], false)) {
            return back()->withErrors(['email' => 'Credenciales incorrectas.'])->withInput(['email' => $credentials['email']]);
        }

        /** @var User $user */
        $user = Auth::user();
        if (! $user->can('users.manage')) {
            Auth::logout();
            $request->session()->invalidate();

            return back()->withErrors(['email' => 'Esa cuenta no es administradora (le falta el permiso users.manage).'])->withInput(['email' => $credentials['email']]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, now()->addMinutes((int) config('lab.session_minutes', 60))->toISOString());

        return redirect()->to('/'.$this->base());
    }

    public function logout(Request $request)
    {
        $request->session()->forget(self::SESSION_KEY);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to('/'.$this->base());
    }

    /* ------------------------------------------------------------------ */
    /* API del lab (requiere sesión del lab)                               */
    /* ------------------------------------------------------------------ */

    public function checklist(Request $request): JsonResponse
    {
        $guard = $this->requireLab($request);
        if ($guard) {
            return $guard;
        }

        $checks = [];

        // Telegram: configuración
        $checks[] = $this->check(
            'Telegram · token configurado',
            TelegramNotifier::enabled(),
            TelegramNotifier::enabled() ? 'TELEGRAM_BOT_TOKEN presente.' : 'Falta TELEGRAM_BOT_TOKEN en el .env del servidor.',
            'Define TELEGRAM_BOT_TOKEN en el servidor y limpia caché (php artisan config:clear).'
        );
        $checks[] = $this->check(
            'Telegram · username del bot',
            TelegramNotifier::botUsername() !== '',
            TelegramNotifier::botUsername() !== '' ? '@'.TelegramNotifier::botUsername() : 'Falta TELEGRAM_BOT_USERNAME.',
            'Copia el @usuario del bot desde BotFather a TELEGRAM_BOT_USERNAME (sin @). Sin esto el botón "Vincular" no genera enlace.'
        );
        $checks[] = $this->check(
            'Telegram · secreto de webhook',
            (string) config('services.telegram.webhook_secret') !== '',
            (string) config('services.telegram.webhook_secret') !== '' ? 'TELEGRAM_WEBHOOK_SECRET presente.' : 'Falta TELEGRAM_WEBHOOK_SECRET.',
            'Genera un texto largo aleatorio y corre php artisan telegram:webhook en el servidor con HTTPS pública.'
        );

        // Telegram: bot en vivo (getMe)
        try {
            $me = TelegramNotifier::enabled() ? TelegramNotifier::checkBot() : null;
            $checks[] = $this->check(
                'Telegram · bot responde (getMe)',
                $me !== null,
                $me ? 'Bot activo: @'.($me['username'] ?? '?').' ('.($me['first_name'] ?? '?').').' : 'La Bot API no respondió ok. Token inválido o sin internet.',
                'Verifica el token con BotFather y que el servidor tenga salida HTTPS a api.telegram.org.'
            );
        } catch (Throwable $e) {
            $checks[] = $this->check('Telegram · bot responde (getMe)', false, ReminderDebugSender::errorInfo($e), 'Revisa el firewall/salida HTTPS del servidor.');
        }

        // Telegram: webhook info
        try {
            $info = TelegramNotifier::enabled() ? TelegramNotifier::api('getWebhookInfo') : null;
            $url = $info['result']['url'] ?? '';
            $pending = $info['result']['pending_update_count'] ?? null;
            $lastError = $info['result']['last_error_message'] ?? null;
            $ok = ($info['ok'] ?? false) && $url !== '' && ! $lastError;
            $detail = $url !== '' ? "URL: {$url}".($pending !== null ? " · pendientes: {$pending}" : '') : 'Sin webhook registrado.';
            if ($lastError) {
                $detail .= " · ÚLTIMO ERROR DE TELEGRAM: {$lastError}";
            }
            $checks[] = $this->check('Telegram · webhook registrado', $ok, $detail, 'Corre php artisan telegram:webhook en el servidor (requiere HTTPS pública).');
        } catch (Throwable $e) {
            $checks[] = $this->check('Telegram · webhook registrado', false, ReminderDebugSender::errorInfo($e), null);
        }

        // Scheduler / cron (detección directa del programador, sin shell_exec
        // para que funcione igual en Windows/XAMPP que en Linux).
        $scheduled = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'reminders:dispatch'));
        $hasDispatch = $scheduled->isNotEmpty();
        $expression = $hasDispatch ? (string) $scheduled->first()->expression : '';
        $checks[] = $this->check(
            'Cron · reminders:dispatch programado',
            $hasDispatch,
            $hasDispatch
                ? "El scheduler incluye reminders:dispatch ({$expression}). OJO: programado no significa ejecutándose; verifica el check siguiente."
                : 'No se detectó reminders:dispatch en el scheduler de Laravel.',
            'En el servidor programa cada minuto: * * * * * php artisan schedule:run (Linux cron) o una tarea programada de Windows con ese comando. Sin esto, Telegram sale por el respaldo web al navegar (ver check siguiente).'
        );

        $lastCron = Cache::get('reminders:last_dispatch_at');
        $checks[] = $this->check(
            'Cron · última ejecución del dispatcher',
            $lastCron !== null,
            $lastCron ? "Última vez: {$lastCron}." : 'El comando reminders:dispatch aún no se ha ejecutado en este servidor.',
            'Si nunca corre, configura el cron del sistema (schedule:run cada minuto). Mientras tanto el respaldo web envía Telegram al navegar.'
        );

        // Respaldo web: envía Telegram al navegar cuando no hay cron.
        $webTick = Cache::get('reminders:telegram_web_tick');
        $webRecent = false;
        if ($webTick) {
            try {
                $webRecent = now()->diffInMinutes(\Carbon\Carbon::parse($webTick)) < 15;
            } catch (Throwable) {
                $webRecent = false;
            }
        }
        $checks[] = $this->check(
            'Telegram · respaldo web activo (sin cron)',
            $webRecent,
            $webRecent
                ? "Funcionando: última pasada {$webTick} (se reintenta 1 vez/min al navegar)."
                : 'Aún sin pasadas ('.($webTick ? "última: {$webTick}" : 'nunca').'). Navega por la plataforma con sesión iniciada y vuelve a revisar.',
            'Este respaldo cubre servidores sin cron, pero con hasta ~1 min de retraso y solo mientras alguien navega. Para avisos puntuales usa el cron.'
        );

        // Base de datos / recordatorios
        try {
            $pending = Reminder::query()->where('status', Reminder::STATUS_PENDING)->count();
            $due = Reminder::query()->where('status', Reminder::STATUS_PENDING)
                ->where(fn ($q) => $q->where('notify_at', '<=', now())->orWhere(fn ($q2) => $q2->whereNull('notify_at')->where('scheduled_at', '<=', now())))
                ->count();
            $usersLinked = User::query()->whereNotNull('telegram_chat_id')->where('telegram_chat_id', '!=', '')->count();
            $usersTotal = User::query()->count();
            $checks[] = $this->check('Datos · recordatorios pendientes', true, "{$pending} pendientes · {$due} vencidos/avisables ahora.");
            $checks[] = $this->check(
                'Datos · usuarios con Telegram vinculado',
                $usersLinked > 0,
                "{$usersLinked} de {$usersTotal} usuarios tienen chat vinculado.",
                'Cada usuario debe vincular desde Mi perfil → Telegram; si no, el aviso solo llega a la plataforma.'
            );
        } catch (Throwable $e) {
            $checks[] = $this->check('Datos · base de datos', false, ReminderDebugSender::errorInfo($e), 'Revisa la conexión DB_* del .env y migra si falta alguna tabla.');
        }

        // Permisos / roles
        try {
            $perms = Permission::query()->pluck('name')->all();
            $need = ['reminders.view', 'reminders.manage', 'users.manage'];
            $missing = array_values(array_diff($need, $perms));
            $checks[] = $this->check(
                'Permisos · catálogo base',
                $missing === [],
                $missing === [] ? count($perms).' permisos registrados.' : 'Faltan: '.implode(', ', $missing),
                'Corre php artisan db:seed --class=RoleAndPermissionSeeder.'
            );
            $adminRole = Role::query()->where('name', 'admin')->first();
            $adminOk = $adminRole && $adminRole->hasPermissionTo('users.manage');
            $checks[] = $this->check('Permisos · rol admin conserva users.manage', (bool) $adminOk, $adminOk ? 'Correcto.' : 'El rol admin perdió users.manage: nadie podría administrar.', 'Re-ejecuta el seeder de roles y permisos.');
        } catch (Throwable $e) {
            $checks[] = $this->check('Permisos · catálogo base', false, ReminderDebugSender::errorInfo($e), null);
        }

        // App / entorno
        $checks[] = $this->check('App · APP_URL', (string) config('app.url') !== '', 'APP_URL='.config('app.url'), 'En producción debe ser la URL pública HTTPS (el webhook de Telegram la usa).');

        // Reloj del equipo vs hora oficial: detecta el caso "cambié la fecha
        // de mi PC y los recordatorios se dispararon antes/raro". Los avisos
        // usan la hora oficial, pero se avisa del desfase para que todo coincida.
        $official = OfficialTime::now();
        $localNow = now();
        $skew = OfficialTime::skewSeconds();
        $source = OfficialTime::source();
        $skewAbs = $skew === null ? null : abs($skew);
        $clockOk = $source === 'internet' && ($skewAbs === null || $skewAbs < 120);
        $skewLabel = $skew === null ? 'desconocido (sin calibración)' : (($skew >= 0 ? '+' : '').$skew.' s');
        $checks[] = $this->check(
            'Reloj · equipo vs hora oficial Ecuador',
            $clockOk,
            "Oficial: {$official->toDateTimeString()} ({$source}) · Reloj del equipo: {$localNow->toDateTimeString()} · Desfase: {$skewLabel}.",
            $source !== 'internet'
                ? 'Sin conexión a relojes de internet: los avisos usan el reloj del equipo. Revisa el internet del servidor.'
                : ($clockOk
                    ? null
                    : 'El reloj de este equipo está desfasado más de 2 minutos. Los recordatorios se rigen por la hora oficial, pero sincroniza el reloj del sistema (Windows: Configuración → Hora e idioma → Sincronizar ahora) para que agenda y avisos coincidan.')
        );
        $checks[] = $this->check('App · zona horaria', true, 'App: '.config('app.timezone').' · servidor: '.date_default_timezone_get().' · hora: '.now()->toDateTimeString(), null);
        $storageOk = is_writable(storage_path('logs'));
        $checks[] = $this->check('App · storage escribible', $storageOk, $storageOk ? storage_path().' escribible.' : storage_path().'/logs NO escribible: los logs y subidas fallarán.', 'Ajusta permisos de storage/ y bootstrap/cache/ en el servidor.');

        $passed = count(array_filter($checks, fn ($c) => $c['ok']));
        $failed = count($checks) - $passed;

        return response()->json(['passed' => $passed, 'failed' => $failed, 'checks' => $checks]);
    }

    public function reminders(Request $request): JsonResponse
    {
        $guard = $this->requireLab($request);
        if ($guard) {
            return $guard;
        }

        $items = Reminder::query()
            ->with(['assignee:id,name,telegram_chat_id', 'creator:id,name', 'recipients:id,name,telegram_chat_id'])
            ->orderBy('scheduled_at', 'desc')
            ->limit(60)
            ->get()
            ->map(fn (Reminder $r): array => [
                'id' => $r->id,
                'title' => $r->title,
                'scheduled_at' => $r->scheduled_at->toDateTimeString(),
                'notify_at' => $r->effectiveNotifyAt()->toDateTimeString(),
                'status' => $r->status,
                'notified_at' => $r->notified_at?->toDateTimeString(),
                'telegram_sent' => (bool) $r->telegram_sent,
                'due_now' => $r->effectiveNotifyAt()->lte(now()) && $r->status === Reminder::STATUS_PENDING,
                'targets' => $r->notificationTargets()->map(fn ($u) => [
                    'id' => $u->id, 'name' => $u->name, 'linked' => trim((string) $u->telegram_chat_id) !== '',
                ])->values(),
            ]);

        return response()->json(['reminders' => $items, 'now' => now()->toDateTimeString()]);
    }

    /** Adelanta un recordatorio: lo envía a Telegram AHORA aunque no sea la hora. */
    public function fireReminder(Request $request, Reminder $reminder): JsonResponse
    {
        $guard = $this->requireLab($request);
        if ($guard) {
            return $guard;
        }

        try {
            $trace = ReminderDebugSender::fire($reminder, $request->boolean('platform', true));

            return response()->json($trace, $trace['ok'] ? 200 : 422);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => ReminderDebugSender::errorInfo($e)], 500);
        }
    }

    /** Corre el dispatcher real (como lo haría el cron) y reporta cuántos salieron. */
    public function dispatchNow(Request $request): JsonResponse
    {
        $guard = $this->requireLab($request);
        if ($guard) {
            return $guard;
        }

        try {
            $sent = DueReminderDispatcher::run(null, true);
            Cache::put('reminders:last_dispatch_at', now()->toDateTimeString(), now()->addWeek());

            return response()->json(['ok' => true, 'sent' => $sent, 'at' => OfficialTime::now()->toDateTimeString(), 'hint' => $sent > 0 ? "Se despacharon {$sent} aviso(s)." : 'Nada vencido por despachar: el dispatcher solo envía lo que ya llegó a su hora (usa "Adelantar" para forzar uno).']);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => ReminderDebugSender::errorInfo($e)], 500);
        }
    }

    /** Prueba de Telegram a un chat_id arbitrario o al propio. */
    public function telegramTest(Request $request): JsonResponse
    {
        $guard = $this->requireLab($request);
        if ($guard) {
            return $guard;
        }

        $data = $request->validate([
            'chat_id' => ['nullable', 'string', 'max:64'],
            'text' => ['nullable', 'string', 'max:1000'],
        ]);

        $chatId = trim((string) ($data['chat_id'] ?? ''));
        if ($chatId === '') {
            $chatId = (string) (($request->user() ?? Auth::user())?->telegram_chat_id ?? '');
        }

        if (! TelegramNotifier::enabled()) {
            return response()->json(['ok' => false, 'error' => 'TELEGRAM_BOT_TOKEN no configurado en el servidor.'], 422);
        }
        if ($chatId === '') {
            return response()->json(['ok' => false, 'error' => 'Sin chat_id: vincula tu Telegram primero o escribe un chat_id manual.'], 422);
        }

        try {
            $text = trim((string) ($data['text'] ?? '')) !== ''
                ? (string) $data['text']
                : "🧪 <b>[PRUEBA Lab]</b>\nSi lees esto, tu Telegram con PlataformaDoc funciona. ".now()->toDateTimeString();
            $sent = TelegramNotifier::send($chatId, $text);

            return response()->json(
                ['ok' => $sent, 'chat_id' => $chatId, 'error' => $sent ? null : 'La Bot API rechazó el envío (revisa token, /start y bloqueos).'],
                $sent ? 200 : 422
            );
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => ReminderDebugSender::errorInfo($e)], 500);
        }
    }

    /** Matriz de usuarios × roles/permisos/Telegram para depurar accesos. */
    public function users(Request $request): JsonResponse
    {
        $guard = $this->requireLab($request);
        if ($guard) {
            return $guard;
        }

        $rows = User::query()->with('roles')->orderBy('name')->limit(200)->get()->map(fn (User $u): array => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'roles' => $u->getRoleNames()->values(),
            'permissions' => $u->getAllPermissions()->pluck('name')->values(),
            'can_manage_reminders' => $u->can('reminders.manage'),
            'can_admin' => $u->can('users.manage'),
            'telegram_linked' => $u->telegramLinked(),
            'verified' => $u->email_verified_at !== null,
        ]);

        return response()->json(['users' => $rows]);
    }

    /** Últimas líneas del log para ver el error real sin SSH. */
    public function logs(Request $request): JsonResponse
    {
        $guard = $this->requireLab($request);
        if ($guard) {
            return $guard;
        }

        try {
            $file = storage_path('logs/laravel.log');
            if (! is_file($file)) {
                return response()->json(['lines' => [], 'hint' => 'Aún no hay laravel.log en este servidor.']);
            }
            $lines = (int) $request->input('lines', 80);
            $lines = max(20, min(300, $lines));
            $content = file($file, FILE_IGNORE_NEW_LINES) ?: [];
            $tail = array_slice($content, -$lines);
            // Recorta líneas gigantes para no romper el JSON.
            $tail = array_map(fn ($l) => mb_strimwidth((string) $l, 0, 600, '…'), $tail);

            return response()->json(['lines' => array_values($tail), 'file' => 'storage/logs/laravel.log']);
        } catch (Throwable $e) {
            return response()->json(['lines' => [], 'hint' => ReminderDebugSender::errorInfo($e)]);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function base(): string
    {
        return trim((string) config('lab.path', 'soporte-lab-7x9q2'), '/');
    }

    private function authed(Request $request): bool
    {
        $expires = $request->session()->get(self::SESSION_KEY);
        if (! $expires) {
            return false;
        }
        try {
            if (now()->greaterThan(\Carbon\Carbon::parse($expires))) {
                $request->session()->forget(self::SESSION_KEY);

                return false;
            }
        } catch (Throwable) {
            return false;
        }

        $user = $request->user() ?? Auth::user();
        if (! $user) {
            return false;
        }

        return $user->can('users.manage');
    }

    /** Devuelve JsonResponse 401/403 si no hay sesión válida del lab, o null si todo bien. */
    private function requireLab(Request $request): ?JsonResponse
    {
        if (! config('lab.enabled', true)) {
            return response()->json(['ok' => false, 'error' => 'Lab desactivado (LAB_ENABLED=false).'], Response::HTTP_NOT_FOUND);
        }
        if (! $this->authed($request)) {
            return response()->json(['ok' => false, 'error' => 'Sesión del lab vencida o sin permiso. Recarga e inicia sesión de nuevo.'], Response::HTTP_UNAUTHORIZED);
        }

        return null;
    }

    /** @return array{name:string, ok:bool, detail:string, hint:?string} */
    private function check(string $name, bool $ok, string $detail, ?string $hint = null): array
    {
        return ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'hint' => $hint];
    }

    /** @return array<string,mixed> */
    private function snapshot(): array
    {
        try {
            $db = DB::connection()->getPdo() ? 'ok' : 'sin conexión';
        } catch (Throwable $e) {
            $db = 'ERROR: '.$e->getMessage();
        }

        return [
            'snapshot' => [
                'now' => OfficialTime::now()->toDateTimeString(),
                'local_now' => now()->toDateTimeString(),
                'time_source' => OfficialTime::source() === 'internet' ? 'oficial (internet)' : 'reloj local (¡sin internet!)',
                'app_url' => config('app.url'),
                'timezone' => config('app.timezone'),
                'env' => config('app.env'),
                'debug' => config('app.debug') ? 'ON' : 'OFF',
                'db' => $db,
                'bot' => TelegramNotifier::enabled() ? '@'.TelegramNotifier::botUsername() : 'no configurado',
                'session_minutes' => (int) config('lab.session_minutes', 60),
            ],
        ];
    }
}
