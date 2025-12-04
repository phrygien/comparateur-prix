<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class CacheResponse
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, int $minutes = 60): Response
    {
        // Ne pas cacher les requêtes POST, PUT, DELETE
        if (!$request->isMethod('GET')) {
            return $next($request);
        }

        // Créer une clé de cache unique basée sur l'URL et les paramètres
        $cacheKey = $this->getCacheKey($request);

        // Vérifier si la réponse est en cache
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Obtenir la réponse
        $response = $next($request);

        // Mettre en cache uniquement les réponses réussies
        if ($response->isSuccessful()) {
            Cache::put($cacheKey, $response, now()->addMinutes($minutes));
        }

        return $response;
    }

    /**
     * Générer une clé de cache unique
     */
    private function getCacheKey(Request $request): string
    {
        $url = $request->url();
        $queryParams = $request->query();
        $user = $request->user();
        
        // Inclure l'ID utilisateur si authentifié
        $userId = $user ? $user->id : 'guest';
        
        return 'route_cache_' . md5($url . serialize($queryParams) . $userId);
    }
}