<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nombre' => ['required', 'string', 'max:120'],
            'apellido' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
            'telefono' => ['required', 'string', 'min:7', 'max:20'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        if (DB::table('auth.users')->where('email', $data['email'])->exists()) {
            return response()->json(['message' => 'Email ya registrado'], 409);
        }

        if (DB::table('auth.users')->where('phone', $data['telefono'])->exists()) {
            return response()->json(['message' => 'Teléfono ya registrado'], 409);
        }

        $userId = (string) Str::uuid();

        DB::table('auth.users')->insert([
            'id' => $userId,
            'aud' => 'authenticated',
            'role' => 'authenticated',
            'email' => $data['email'],
            'encrypted_password' => Hash::make($data['password']),
            'phone' => $data['telefono'],
            'raw_user_meta_data' => json_encode([
                'nombre' => $data['nombre'],
                'apellido' => $data['apellido'],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Usuario registrado',
            'data' => [
                'id' => $userId,
                'email' => $data['email'],
                'telefono' => $data['telefono'],
                'nombre' => $data['nombre'],
                'apellido' => $data['apellido'],
            ],
        ], 201);
    }
}