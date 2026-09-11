<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Traits\HasRoles; // 1. Trait de Spatie importado

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;
    use HasRoles {
        getAllPermissions as protected spatieGetAllPermissions;
        hasPermissionTo as protected spatieHasPermissionTo;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Permisos que el rol otorga pero que para ESTE usuario están desactivados.
     * Efectivo = (permisos del rol + directos) - exenciones.
     */
    public function exemptedPermissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_permission_exemptions', 'user_id', 'permission_id');
    }

    public function exemptedPermissionNames(): array
    {
        // Sin caché en memoria: la lista puede cambiar vía otro proceso/instancia.
        return $this->exemptedPermissions()->pluck('name')->all();
    }

    public function syncExemptedPermissions(array $permissionNames): void
    {
        $this->exemptedPermissions()->sync(
            Permission::query()->whereIn('name', $permissionNames)->pluck('id'),
        );
    }

    /**
     * Un permiso exento por este usuario no aplica aunque el rol lo conceda.
     */
    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        $name = $permission instanceof Permission
            ? $permission->name
            : (is_string($permission) ? $permission : null);

        if ($name !== null && in_array($name, $this->exemptedPermissionNames(), true)) {
            return false;
        }

        return $this->spatieHasPermissionTo($permission, $guardName);
    }

    public function getAllPermissions(): Collection
    {
        $permissions = $this->spatieGetAllPermissions();

        $exemptions = $this->exemptedPermissionNames();
        if ($exemptions !== []) {
            $permissions = $permissions->reject(
                fn (mixed $permission): bool => $permission instanceof Permission
                    && in_array($permission->name, $exemptions, true),
            )->values();
        }

        return $permissions;
    }
}