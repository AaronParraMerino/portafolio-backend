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

    public function stats(): JsonResponse
    {
        return response()->json([
            'data' => $this->homePortfolioService->getStats(),
        ]);
    }

    public function recentProjects(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 12);

        return response()->json([
            'data' => $this->homePortfolioService->getRecentProjects($limit),
        ]);
    }

    public function projectDetail(int $projectId): JsonResponse
    {
        $project = $this->homePortfolioService->getPublicProjectDetail($projectId);

        if (! $project) {
            return response()->json(['message' => 'Proyecto publico no encontrado'], 404);
        }

        return response()->json(['data' => $project]);
    }

    public function developers(Request $request): JsonResponse
    {
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', $request->query('limit', 20));
        $search = $request->query('q', $request->query('search', $request->query('nombre')));

        return response()->json([
            'data' => $this->homePortfolioService->getPublicDevelopers($page, $perPage, $search),
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
