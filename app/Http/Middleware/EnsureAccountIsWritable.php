<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsWritable
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe() && $request->user()?->estado === 'pausado') {
            return response()->json([
                'status' => 'account_paused',
                'message' => 'Tu cuenta esta en pausa. Puedes consultar tu informacion, pero no realizar modificaciones.',
            ], 423);
        }

        return $next($request);
    }
}
