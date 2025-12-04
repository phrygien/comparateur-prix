<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class CacheResponse
{
    public function handle(Request $request, Closure $next, int $minutes = 60): Response
    {
        // Ne pas mettre en cache les requêtes POST, PUT, DELETE
        if (!$request->isMethod('GET')) {
            return $next($request);
        }

        // Créer une clé de cache unique basée sur l'URL et les paramètres
        $key = 'route_cache_' . md5($request->fullUrl());

        // Vérifier si la réponse est en cache
        if (Cache::has($key)) {
            return Cache::get($key);
        }

        // Exécuter la requête
        $response = $next($request);

        // Mettre en cache uniquement les réponses réussies
        if ($response->isSuccessful()) {
            Cache::put($key, $response, now()->addMinutes($minutes));
        }

        return $response;
    }
}