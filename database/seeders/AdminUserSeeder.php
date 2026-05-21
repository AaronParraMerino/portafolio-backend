<?php

namespace Database\Seeders;

use App\Models\Usuario;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@admin.com');
        $password = env('ADMIN_PASSWORD');
        $resetPassword = filter_var(env('ADMIN_RESET_PASSWORD', false), FILTER_VALIDATE_BOOL);

        $usuario = Usuario::where('correo', $email)->first();

        if (! $usuario && ! $password) {
            $this->command?->warn('AdminUserSeeder: define ADMIN_PASSWORD para crear el usuario administrador.');
            return;
        }

        $data = [
            'nombre' => env('ADMIN_NOMBRE', 'admin'),
            'apellido' => env('ADMIN_APELLIDO', 'Sistema'),
            'telefono' => env('ADMIN_TELEFONO'),
            'rol' => 'admin',
            'estado' => 'activo',
            'intentos_fallidos' => 0,
            'fecha_bloqueo' => null,
            'idioma_preferido' => env('ADMIN_IDIOMA', 'es'),
        ];

        if (! $usuario || $resetPassword) {
            $data['password'] = Hash::make($password);
        }

        Usuario::updateOrCreate(
            ['correo' => $email],
            $data
        );

        $this->command?->info("AdminUserSeeder: usuario administrador listo ({$email}).");
    }
}
