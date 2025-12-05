<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Configuration du cache Boutique
    |--------------------------------------------------------------------------
    |
    | Cette configuration permet de gérer le cache des produits de la boutique
    |
    */

    // Préfixe pour toutes les clés de cache
    'prefix' => env('BOUTIQUE_CACHE_PREFIX', 'boutique'),

    // Activer ou désactiver le cache
    'enabled' => env('BOUTIQUE_CACHE_ENABLED', true),

    // Durées de cache (en secondes)
    'ttl' => [
        'products' => env('BOUTIQUE_CACHE_TTL_PRODUCTS', 3600),      // 1 heure
        'count' => env('BOUTIQUE_CACHE_TTL_COUNT', 7200),            // 2 heures
        'filters' => env('BOUTIQUE_CACHE_TTL_FILTERS', 1800),        // 30 minutes
        'stats' => env('BOUTIQUE_CACHE_TTL_STATS', 300),             // 5 minutes
        'search' => env('BOUTIQUE_CACHE_TTL_SEARCH', 1800),          // 30 minutes
    ],

    // Stratégies de cache
    'strategies' => [
        // Mise en cache automatique des pages fréquemment visitées
        'auto_warmup' => env('BOUTIQUE_CACHE_AUTO_WARMUP', false),
        
        // Nombre de pages à préchauffer
        'warmup_pages' => env('BOUTIQUE_CACHE_WARMUP_PAGES', 5),
        
        // Vider automatiquement le cache après X heures
        'auto_flush_hours' => env('BOUTIQUE_CACHE_AUTO_FLUSH_HOURS', 24),
    ],

    // Limites
    'limits' => [
        // Nombre maximum de clés de cache par utilisateur
        'max_keys_per_user' => env('BOUTIQUE_CACHE_MAX_KEYS_PER_USER', 100),
        
        // Taille maximale d'une valeur de cache (en octets)
        'max_value_size' => env('BOUTIQUE_CACHE_MAX_VALUE_SIZE', 1048576), // 1 MB
    ],

    // Compression
    'compression' => [
        'enabled' => env('BOUTIQUE_CACHE_COMPRESSION', true),
        'threshold' => env('BOUTIQUE_CACHE_COMPRESSION_THRESHOLD', 1024), // 1 KB
    ],

    // Monitoring
    'monitoring' => [
        'enabled' => env('BOUTIQUE_CACHE_MONITORING', true),
        'log_hits' => env('BOUTIQUE_CACHE_LOG_HITS', false),
        'log_misses' => env('BOUTIQUE_CACHE_LOG_MISSES', true),
    ],

    // Tags de cache (si supporté par le driver)
    'tags' => [
        'products' => 'boutique-products',
        'filters' => 'boutique-filters',
        'search' => 'boutique-search',
    ],

];