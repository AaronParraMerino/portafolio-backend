<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\api\HomePortfolioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomePortfolioController extends Controller
{
    public function __construct(private readonly HomePortfolioService $homePortfolioService)
    {
    }

    public function featured(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 6);
        $search = $request->query('q', $request->query('search'));

        return response()->json([
            'data' => $this->homePortfolioService->getFeaturedPortfolios($limit, $search),
        ]);
    }

    public function show(int $userId): JsonResponse
    {
        $portfolio = $this->homePortfolioService->getPublicPortfolio($userId);

        if (! $portfolio) {
            return response()->json([
                'message' => 'Portafolio publico no encontrado',
            ], 404);
        }

        return response()->json([
            'data' => $portfolio,
        ]);
    }
}
