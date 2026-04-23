<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\api\EnlaceService;
use Illuminate\Http\Request;

class EnlaceController extends Controller
{
    protected $service;

    public function __construct(EnlaceService $service)
    {
        $this->service = $service;
    }

    //controller para agregar un enlace de un usuario
    public function store(Request $request, $userId)
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'link' => 'required|string',
            'descripcion' => 'nullable|string',
        ]);

        return response()->json(
            $this->service->create($userId, $data),
            201
        );
    }

    //controller para obtener los enlaces de un usuario
    public function index($userId)
    {
        return response()->json(
            $this->service->getByUser($userId)
        );
    }

    //controller para eliminar un enlace de un usuario
    public function destroy($userId, $idEnlace)
    {
        $this->service->delete($userId, $idEnlace);

        return response()->json([
            'message' => 'Enlace eliminado correctamente'
        ]);
    }
}