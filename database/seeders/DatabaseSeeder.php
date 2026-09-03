<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Cargar la matriz de roles y permisos
        $this->call(RoleAndPermissionSeeder::class);

        // 2. Crear usuario administrador de prueba
        $user = User::factory()->create([
            'name' => 'Admin Test',
            'email' => 'admin@test.com',
        ]);

        // Asignar el rol de admin
        $user->assignRole('admin');
    }
}