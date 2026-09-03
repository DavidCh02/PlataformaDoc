<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Resetear permisos en caché
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Lista de permisos del sistema
        $permissions = [
            'folders.create',
            'files.upload',
            'files.view',
            'files.download',
            'files.delete',
            'docs.create',
            'docs.edit_realtime',
            'users.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Rol: Admin (Todos los permisos)
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $admin->givePermissionTo(Permission::all());

        // Rol: Doctor (Editar, crear, cargar, eliminar, ver, descargar)
        $doctor = Role::firstOrCreate(['name' => 'doctor']);
        $doctor->givePermissionTo([
            'folders.create',
            'files.upload',
            'files.view',
            'files.download',
            'files.delete',
            'docs.create',
            'docs.edit_realtime',
        ]);

        // Rol: Practicante (Crear, guardar, descargar, ver, editar docs - SIN BORRAR por defecto)
        $practicante = Role::firstOrCreate(['name' => 'practicante']);
        $practicante->givePermissionTo([
            'folders.create',
            'files.upload',
            'files.view',
            'files.download',
            'docs.create',
            'docs.edit_realtime',
        ]);

        // Rol: Visitante (Solo visualizar y descargar)
        $visitante = Role::firstOrCreate(['name' => 'visitante']);
        $visitante->givePermissionTo([
            'files.view',
            'files.download',
        ]);
    }
}