<?php

namespace App\Http\Middleware;

use App\Services\DueReminderDispatcher;
use Illuminate\Http\Request;
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
        // Sin Telegram aquí para no frenar la página; lo envía el scheduler.
        if ($request->user()) {
            try {
                DueReminderDispatcher::run($request->user(), false);
            } catch (Throwable) {
                // Nunca romper la página por un recordatorio.
            }
        }

        return [
            ...parent::share($request),
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
