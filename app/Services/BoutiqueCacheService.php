<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BoutiqueCacheService
{
    /**
     * Préfixe pour toutes les clés de cache
     */
    protected string $prefix = 'boutique';

    /**
     * Durée du cache par défaut (en secondes)
     */
    protected int $defaultTTL = 3600; // 1 heure

    /**
     * Configuration des TTL par type de cache
     */
    protected array $ttlConfig = [
        'products' => 3600,      // 1 heure
        'count' => 7200,         // 2 heures
        'filters' => 1800,       // 30 minutes
        'stats' => 300,          // 5 minutes
    ];

    /**
     * Génère une clé de cache
     */
    public function generateKey(string $type, ...$params): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->prefix,
            $type,
            implode(':', array_map(function($param) {
                return is_array($param) ? md5(json_encode($param)) : $param;
            }, $params))
        );
    }

    /**
     * Récupère ou crée une entrée de cache
     */
    public function remember(string $type, array $params, callable $callback, ?int $ttl = null)
    {
        $key = $this->generateKey($type, ...$params);
        $ttl = $ttl ?? ($this->ttlConfig[$type] ?? $this->defaultTTL);

        return Cache::remember($key, $ttl, $callback);
    }

    /**
     * Stocke une valeur dans le cache
     */
    public function put(string $type, array $params, $value, ?int $ttl = null): bool
    {
        $key = $this->generateKey($type, ...$params);
        $ttl = $ttl ?? ($this->ttlConfig[$type] ?? $this->defaultTTL);

        return Cache::put($key, $value, $ttl);
    }

    /**
     * Récupère une valeur du cache
     */
    public function get(string $type, array $params, $default = null)
    {
        $key = $this->generateKey($type, ...$params);
        return Cache::get($key, $default);
    }

    /**
     * Supprime une entrée du cache
     */
    public function forget(string $type, array $params): bool
    {
        $key = $this->generateKey($type, ...$params);
        return Cache::forget($key);
    }

    /**
     * Vérifie si une clé existe
     */
    public function has(string $type, array $params): bool
    {
        $key = $this->generateKey($type, ...$params);
        return Cache::has($key);
    }

    /**
     * Supprime les clés correspondant à un pattern
     */
    public function forgetByPattern(string $pattern): int
    {
        if (!$this->isRedis()) {
            Log::warning('forgetByPattern requires Redis driver');
            return 0;
        }

        try {
            $redis = Cache::getRedis();
            $fullPattern = "{$this->prefix}:{$pattern}";
            $keys = $redis->keys($fullPattern);

            if (empty($keys)) {
                return 0;
            }

            $redis->del($keys);
            return count($keys);
        } catch (\Exception $e) {
            Log::error('Error forgetting by pattern: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Vide tout le cache boutique
     */
    public function flush(): int
    {
        return $this->forgetByPattern('*');
    }

    /**
     * Récupère les statistiques du cache
     */
    public function getStats(): array
    {
        if (!$this->isRedis()) {
            return [
                'driver' => config('cache.default'),
                'redis' => false,
            ];
        }

        try {
            $redis = Cache::getRedis();
            $patterns = [
                'products' => 'products:*',
                'count' => 'count:*',
                'all' => '*',
            ];

            $stats = [
                'driver' => 'redis',
                'redis' => true,
                'categories' => [],
            ];

            foreach ($patterns as $category => $pattern) {
                $fullPattern = "{$this->prefix}:{$pattern}";
                $keys = $redis->keys($fullPattern);
                $stats['categories'][$category] = count($keys);
            }

            // Info Redis
            $info = $redis->info();
            $stats['memory_used'] = $info['used_memory'] ?? 0;
            $stats['memory_peak'] = $info['used_memory_peak'] ?? 0;

            return $stats;
        } catch (\Exception $e) {
            Log::error('Error getting cache stats: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Récupère toutes les clés correspondant à un pattern
     */
    public function getKeys(string $pattern = '*'): array
    {
        if (!$this->isRedis()) {
            return [];
        }

        try {
            $redis = Cache::getRedis();
            $fullPattern = "{$this->prefix}:{$pattern}";
            return $redis->keys($fullPattern);
        } catch (\Exception $e) {
            Log::error('Error getting keys: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Précharge le cache pour des données fréquentes
     */
    public function warmup(array $filters, int $maxPages = 5): array
    {
        $warmed = [];

        try {
            // Ici vous pouvez définir la logique de préchauffage
            // Par exemple, charger les premières pages avec différents filtres
            
            Log::info('Cache warmup completed', ['pages' => count($warmed)]);
            return $warmed;
        } catch (\Exception $e) {
            Log::error('Cache warmup error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Définit la durée du cache pour un type
     */
    public function setTTL(string $type, int $seconds): void
    {
        $this->ttlConfig[$type] = $seconds;
    }

    /**
     * Récupère la durée du cache pour un type
     */
    public function getTTL(string $type): int
    {
        return $this->ttlConfig[$type] ?? $this->defaultTTL;
    }

    /**
     * Vérifie si Redis est utilisé
     */
    protected function isRedis(): bool
    {
        return config('cache.default') === 'redis';
    }

    /**
     * Incrémente un compteur dans le cache
     */
    public function increment(string $type, array $params, int $value = 1): int
    {
        $key = $this->generateKey($type, ...$params);
        return Cache::increment($key, $value);
    }

    /**
     * Décrémente un compteur dans le cache
     */
    public function decrement(string $type, array $params, int $value = 1): int
    {
        $key = $this->generateKey($type, ...$params);
        return Cache::decrement($key, $value);
    }

    /**
     * Met en cache avec des tags (si supporté)
     */
    public function rememberWithTags(array $tags, string $type, array $params, callable $callback, ?int $ttl = null)
    {
        if (!$this->supportsTagging()) {
            return $this->remember($type, $params, $callback, $ttl);
        }

        $key = $this->generateKey($type, ...$params);
        $ttl = $ttl ?? ($this->ttlConfig[$type] ?? $this->defaultTTL);

        return Cache::tags($tags)->remember($key, $ttl, $callback);
    }

    /**
     * Vide le cache par tags
     */
    public function flushTags(array $tags): bool
    {
        if (!$this->supportsTagging()) {
            Log::warning('Cache tagging not supported');
            return false;
        }

        try {
            Cache::tags($tags)->flush();
            return true;
        } catch (\Exception $e) {
            Log::error('Error flushing tags: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Vérifie si le driver supporte le tagging
     */
    protected function supportsTagging(): bool
    {
        $driver = config('cache.default');
        return in_array($driver, ['redis', 'memcached', 'dynamodb', 'array']);
    }
}