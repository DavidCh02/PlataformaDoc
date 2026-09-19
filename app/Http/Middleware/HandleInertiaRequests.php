<?php

namespace App\Http\Middleware;

use App\Services\DueReminderDispatcher;
use App\Services\OfficialTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;
use Throwable;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // Disparo perezoso: si el scheduler/cron no está corriendo (típico en
        // XAMPP/Windows), igual se genera el aviso de plataforma al navegar.
        // Además, como respaldo, se intenta el envío a Telegram como máximo
        // 1 vez por minuto entre TODOS los visitantes (throttle global por
        // caché), para no frenar la página. El cron sigue siendo la vía
        // principal en producción; esto solo cubre servidores sin cron.
        if ($request->user()) {
            try {
                DueReminderDispatcher::run($request->user(), false);
            } catch (Throwable) {
                // Nunca romper la página por un recordatorio.
            }

            try {
                $lastTick = Cache::get('reminders:telegram_web_tick');
                $stale = ! $lastTick || now()->diffInSeconds(\Carbon\Carbon::parse($lastTick)) >= 60;
                if ($stale) {
                    // Se marca ANTES de enviar para que dos visitas
                    // simultáneas no disparen el envío dos veces.
                    Cache::put('reminders:telegram_web_tick', now()->toDateTimeString(), now()->addDay());
                    DueReminderDispatcher::run(null, true);
                }
            } catch (Throwable) {
                // Nunca romper la página por Telegram.
            }
        }

        return [
            ...parent::share($request),
            // Hora OFICIAL de la plataforma (internet, America/Guayaquil),
            // no el reloj del dispositivo. El frontend la usa como
            // referencia para clasificar pendientes/vencidos, así la
            // página no depende de que el dispositivo esté adelantado
            // o atrasado.
            'serverNow' => OfficialTime::now()->toISOString(),
            'auth' => [
                'user' => $request->user(),
                'can' => $request->user()
                    ? $request->user()->getAllPermissions()->pluck('name')->values()
                    : [],
                'roles' => $request->user()
                    ? $request->user()->getRoleNames()->values()
                    : [],
            ],
            'notifications' => $request->user() ? [
                'unread_count' => $request->user()->unreadNotifications()->count(),
                'recent' => $request->user()->notifications()->latest()->limit(6)->get()->map(fn ($notification): array => [
                    'id' => $notification->id,
                    'data' => $notification->data,
                    'read_at' => $notification->read_at?->toISOString(),
                    'created_at' => $notification->created_at->toISOString(),
                ])->values(),
            ] : ['unread_count' => 0, 'recent' => []],
        ];
    }
}
