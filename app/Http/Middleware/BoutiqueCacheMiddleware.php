<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class BoutiqueCacheMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Si le cache est désactivé, continuer normalement
        if (!config('boutique-cache.enabled', true)) {
            return $next($request);
        }

        // Ne pas mettre en cache les requêtes POST/PUT/DELETE
        if (!$request->isMethod('GET')) {
            return $next($request);
        }

        // Générer une clé de cache basée sur l'URL et les paramètres
        $cacheKey = $this->generateCacheKey($request);
        
        // Vérifier si nous devons bypasser le cache
        if ($this->shouldBypassCache($request)) {
            $response = $next($request);
            $this->logCacheAction('bypass', $cacheKey);
            return $response;
        }

        // Essayer de récupérer depuis le cache
        if (Cache::has($cacheKey)) {
            $this->logCacheAction('hit', $cacheKey);
            return Cache::get($cacheKey);
        }

        // Exécuter la requête
        $response = $next($request);

        // Mettre en cache si la réponse est réussie
        if ($response->isSuccessful() && $this->shouldCacheResponse($response)) {
            $ttl = $this->getCacheTTL($request);
            Cache::put($cacheKey, $response, $ttl);
            $this->logCacheAction('miss', $cacheKey);
        }

        return $response;
    }

    /**
     * Génère une clé de cache unique pour la requête
     */
    protected function generateCacheKey(Request $request): string
    {
        $url = $request->fullUrl();
        $user = $request->user();
        
        $parts = [
            'boutique-page',
            md5($url),
            $user ? $user->id : 'guest',
        ];

        return implode(':', $parts);
    }

    /**
     * Détermine si le cache doit être bypassé
     */
    protected function shouldBypassCache(Request $request): bool
    {
        // Bypass si paramètre nocache présent
        if ($request->has('nocache')) {
            return true;
        }

        // Bypass pour les utilisateurs authentifiés (optionnel)
        // if ($request->user()) {
        //     return true;
        // }

        // Bypass si header spécifique présent
        if ($request->header('X-No-Cache')) {
            return true;
        }

        return false;
    }

    /**
     * Détermine si la réponse doit être mise en cache
     */
    protected function shouldCacheResponse(Response $response): bool
    {
        // Ne pas mettre en cache les redirections
        if ($response->isRedirection()) {
            return false;
        }

        // Ne pas mettre en cache les erreurs
        if ($response->isClientError() || $response->isServerError()) {
            return false;
        }

        // Vérifier la présence de l'en-tête Cache-Control
        $cacheControl = $response->headers->get('Cache-Control');
        if ($cacheControl && strpos($cacheControl, 'no-cache') !== false) {
            return false;
        }

        return true;
    }

    /**
     * Récupère le TTL approprié pour la requête
     */
    protected function getCacheTTL(Request $request): int
    {
        // TTL par défaut
        $defaultTTL = config('boutique-cache.ttl.products', 3600);

        // Personnaliser selon le type de page
        if ($request->has('search')) {
            return config('boutique-cache.ttl.search', 1800);
        }

        return $defaultTTL;
    }

    /**
     * Log les actions de cache
     */
    protected function logCacheAction(string $action, string $key): void
    {
        if (!config('boutique-cache.monitoring.enabled', true)) {
            return;
        }

        $shouldLog = false;

        switch ($action) {
            case 'hit':
                $shouldLog = config('boutique-cache.monitoring.log_hits', false);
                break;
            case 'miss':
                $shouldLog = config('boutique-cache.monitoring.log_misses', true);
                break;
            case 'bypass':
                $shouldLog = true;
                break;
        }

        if ($shouldLog) {
            Log::info("Boutique Cache {$action}", [
                'key' => $key,
                'timestamp' => now()->toDateTimeString(),
            ]);
        }
    }
}