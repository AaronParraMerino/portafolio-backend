<?php

namespace App\Profile\Controllers;

use App\Profile\Services\ProfileServiceMock;
use Illuminate\Http\Request;

class ProfileControllerMock
{
    protected ProfileServiceMock $service;

    public function __construct(ProfileServiceMock $service)
    {
        $this->service = $service;
    }

    public function show(int $userId)
    {
        return response()->json(
            $this->service->getProfile($userId)
        );
    }

    public function update(Request $request, int $userId)
    {
        $data = $request->only([
            'nombre',
            'apellido',
            'correo',
            'telefono',
            'biografia',
            'ciudad',
            'pais'
        ]);

        return response()->json(
            $this->service->updateProfile($userId, $data)
        );
    }

    public function updateVisibility(Request $request, int $userId)
    {
        $data = $request->all();

        return response()->json(
            $this->service->updateVisibility($userId, $data)
        );
    }
}