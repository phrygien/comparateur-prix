<?php

namespace App\Livewire;

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Models\Site as WebSite;

new class extends Component {
    // Propriétés de base
    public $products = [];
    public $product = null;
    public $hasData = false;
    public $searchTerms = [];
    public $searchVolumes = [];
    public $searchVariationKeywords = [];

    public $id;
    public $mydata;

    // Similarité
    public $similarityThreshold = 0.6;
    public $matchedProducts = [];

    // Recherche
    public $searchQuery = '';

    // Prix
    public $price;
    public $referencePrice;
    public $cosmashopPrice;

    // Filtres
    public $filters = [
        'vendor' => '',
        'name' => '',
        'variation' => '',
        'type' => '',
        'site_source' => ''
    ];

    // Pagination et chargement
    public $perPage = 20;
    public $currentPage = 1;
    public $totalPages = 0;
    public $isLoading = false;
    public $hasMore = false;

    // Sites et état
    public $sites = [];
    public $showTable = false;
    public $isAutomaticSearch = true;
    public $originalAutomaticResults = [];
    public $hasAppliedFilters = false;

    // Cache optimisé
    private array $quickCache = [];
    private const CACHE_TTL = 60;
    private const QUICK_CACHE_TTL = 10; // Cache court pour les opérations fréquentes

    // Mapping des abréviations des marques (chargé une fois)
    private static $brandAbbreviations = null;

public function mount($name, $id, $price)
{
    // Vérifier le cache complet d'abord
    $fullCacheKey = 'full_search:' . md5($name . $id . $price);
    $cached = Cache::get($fullCacheKey);

    if ($cached && !request()->has('refresh')) {
        $this->hydrateFromCache($cached);
        return;
    }

    // S'assurer que $id est un scalaire (pas un tableau)
    if (is_array($id)) {
        \Log::warning('ID is array, using first element', ['id' => $id]);
        $id = !empty($id) ? reset($id) : null;
    }
    
    // Convertir en int si possible
    $this->id = is_numeric($id) ? (int) $id : $id;
    
    // Le reste du code...
    $this->price = $this->cleanPrice($price);
    $this->referencePrice = $this->cleanPrice($price);
    $this->cosmashopPrice = $this->cleanPrice($price) * 1.05;
    $this->searchQuery = $name;

    // Extraire le vendor par défaut
    $this->extractDefaultVendor($name);

    // Charger les sites (avec cache)
    $this->loadSites();

    // Toujours afficher le tableau
    $this->showTable = true;

    // Recherche initiale en arrière-plan
    $this->deferredSearch($name);
}

    // Hydratation depuis le cache
    private function hydrateFromCache(array $cached): void
    {
        foreach ($cached as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
        $this->hasData = !empty($this->products);
        \Log::info('Loaded from full cache', ['key' => array_keys($cached)]);
    }

    // Recherche différée pour le premier rendu
    private function deferredSearch(string $search): void
    {
        if (empty($search)) {
            $this->hasData = false;
            return;
        }

        // Utiliser une file d'attente pour la recherche lourde
        if (app()->runningInConsole() === false) {
            dispatch(function () use ($search) {
                $this->getCompetitorPrice($search);
            })->afterResponse();
        } else {
            $this->getCompetitorPrice($search);
        }
    }

    // Cache hiérarchique
    private function getCacheKey(string $search, array $filters = [], bool $isManual = false): string
    {
        $keyData = [
            'search' => substr(trim(strtolower($search)), 0, 100),
            'filters' => $this->filterCacheData($filters),
            'type' => $isManual ? 'manual' : 'auto',
            'threshold' => round($this->similarityThreshold, 1)
        ];

        return 'search:' . md5(json_encode($keyData));
    }

    // Réduire les données de filtre pour le cache
    private function filterCacheData(array $filters): array
    {
        return array_filter($filters, function($value) {
            return !empty($value);
        });
    }

    // Gestionnaire de cache optimisé
    private function cacheGet(string $key)
    {
        // Vérifier d'abord le cache rapide en mémoire
        if (isset($this->quickCache[$key]) && 
            $this->quickCache[$key]['expires'] > microtime(true)) {
            return $this->quickCache[$key]['data'];
        }

        // Sinon vérifier le cache Laravel
        $data = Cache::get($key);
        
        // Stocker dans le cache rapide
        if ($data !== null) {
            $this->quickCache[$key] = [
                'data' => $data,
                'expires' => microtime(true) + self::QUICK_CACHE_TTL
            ];
        }

        return $data;
    }

    private function cachePut(string $key, $data, int $ttl = null): void
    {
        $ttl = $ttl ?? self::CACHE_TTL;
        
        // Stocker dans le cache rapide
        $this->quickCache[$key] = [
            'data' => $data,
            'expires' => microtime(true) + self::QUICK_CACHE_TTL
        ];

        // Stocker dans le cache Laravel
        Cache::put($key, $data, now()->addMinutes($ttl));
    }

    // Extraction optimisée du vendor
    private function extractDefaultVendor(string $search): void
    {
        if (empty($search)) return;

        // Pattern simple pour extraction rapide
        if (preg_match('/^([A-Z&]+(?:\s+[A-Z&]+)*)/', $search, $matches)) {
            $vendor = trim($matches[1]);
            
            // Nettoyer rapidement
            $vendor = preg_replace('/\d+ml/i', '', $vendor);
            $vendor = trim($vendor);
            
            // Normaliser
            $vendor = $this->normalizeVendor($vendor);
            
            if (!empty($vendor)) {
                $this->filters['vendor'] = $vendor;
                return;
            }
        }

        // Fallback simple
        $words = explode(' ', $search);
        if (count($words) > 0) {
            $this->filters['vendor'] = $this->normalizeVendor($words[0]);
        }
    }

    // Marques abrégées avec chargement paresseux
    private function getBrandAbbreviations(): array
    {
        if (self::$brandAbbreviations === null) {
            self::$brandAbbreviations = [
                'YSL' => 'Yves Saint Laurent',
                'D&G' => 'Dolce & Gabbana',
                'CK' => 'Calvin Klein',
                'JPG' => 'Jean Paul Gaultier',
                'PR' => 'Paco Rabanne',
                'CH' => 'Carolina Herrera',
                'V&R' => 'Viktor & Rolf',
                'BVLGARI' => 'Bvlgari',
                'HERMES' => 'Hermès',
                'GUERLAIN' => 'Guerlain',
                'LANCOME' => 'Lancôme',
                'DIOR' => 'Dior',
                'CHANEL' => 'Chanel',
                'ARMANI' => 'Armani',
                'PRADA' => 'Prada',
                'VERSACE' => 'Versace',
                'GIVENCHY' => 'Givenchy',
                'BURBERRY' => 'Burberry',
                'MUGLER' => 'Mugler',
                'NR' => 'Narciso Rodriguez',
                'MB' => 'Montblanc',
                'CARTIER' => 'Cartier',
            ];
        }

        return self::$brandAbbreviations;
    }

    // Normalisation optimisée
    private function normalizeVendor(string $vendor): string
    {
        if (empty(trim($vendor))) return '';

        $vendorUpper = strtoupper(trim($vendor));
        $abbreviations = $this->getBrandAbbreviations();

        // Vérification directe
        if (isset($abbreviations[$vendorUpper])) {
            return $abbreviations[$vendorUpper];
        }

        // Recherche inverse (optimisée)
        foreach ($abbreviations as $abbr => $full) {
            if (strcasecmp($vendor, $full) === 0) {
                return $full;
            }
        }

        return trim($vendor);
    }

    // Chargement des sites avec cache longue durée
    public function loadSites()
    {
        $cacheKey = 'sites_list_v2';
        $cachedSites = $this->cacheGet($cacheKey);

        if ($cachedSites !== null) {
            $this->sites = $cachedSites;
            return;
        }

        try {
            // Requête simplifiée
            $this->sites = WebSite::select(['id', 'name'])
                ->orderBy('name')
                ->get()
                ->toArray();

            // Cache pour 24h
            $this->cachePut($cacheKey, $this->sites, 1440);
        } catch (\Throwable $e) {
            $this->sites = [];
            \Log::error('Error loading sites', ['error' => $e->getMessage()]);
        }
    }

    // RECHERCHE MANUELLE OPTIMISÉE
    public function searchManual()
    {
        if ($this->isLoading) return;

        $this->isLoading = true;
        $startTime = microtime(true);

        try {
            // Vérifier le cache
            $cacheKey = $this->getManualSearchCacheKey();
            $cachedResults = $this->cacheGet($cacheKey);

            if ($cachedResults !== null) {
                $this->applyCachedResults($cachedResults);
                $this->logPerformance($startTime, 'manual_search_cache');
                $this->isLoading = false;
                return;
            }

            // Construire la requête optimisée
            [$sql, $params] = $this->buildOptimizedManualQuery();

            // Exécuter avec limite
            $limit = $this->perPage * $this->currentPage;
            $sql .= " LIMIT ?";
            $params[] = $limit;

            $result = DB::connection('mysql')->select($sql, $params);

            // Traiter les résultats
            $processedResults = $this->processManualResults($result);

            // Mettre à jour l'état
            $this->updateManualSearchState($processedResults, $cacheKey);

            $this->logPerformance($startTime, 'manual_search_db');

        } catch (\Throwable $e) {
            $this->handleSearchError($e);
        } finally {
            $this->isLoading = false;
        }
    }

    // Construction de requête optimisée
    private function buildOptimizedManualQuery(): array
    {
        $conditions = [];
        $params = [];

        // Vendor avec variations
        if (!empty($this->filters['vendor'])) {
            $vendorVariations = $this->getVendorVariations($this->filters['vendor']);
            if (!empty($vendorVariations)) {
                $vendorConditions = [];
                foreach ($vendorVariations as $variation) {
                    $vendorConditions[] = "sp.vendor LIKE ?";
                    $params[] = '%' . $variation . '%';
                }
                $conditions[] = '(' . implode(' OR ', $vendorConditions) . ')';
            }
        }

        // Autres filtres
        $filterFields = ['name', 'variation', 'type'];
        foreach ($filterFields as $field) {
            if (!empty($this->filters[$field])) {
                $conditions[] = "sp.$field LIKE ?";
                $params[] = '%' . $this->filters[$field] . '%';
            }
        }

        // Filtre site
        if (!empty($this->filters['site_source'])) {
            $conditions[] = "sp.web_site_id = ?";
            $params[] = $this->filters['site_source'];
        }

        // Requête de base optimisée
        $sql = "SELECT DISTINCT ON (sp.url, sp.vendor, sp.name, sp.type, sp.variation)
                    sp.*,
                    ws.name as site_name
                FROM scraped_product sp
                LEFT JOIN web_site ws ON sp.web_site_id = ws.id";

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        $sql .= " ORDER BY sp.url, sp.vendor, sp.name, sp.type, sp.variation, sp.created_at DESC";

        return [$sql, $params];
    }

    // Traitement des résultats manuels
    private function processManualResults(array $results): array
    {
        $processed = [];
        foreach ($results as $product) {
            // Nettoyer le prix
            if (isset($product->prix_ht)) {
                $product->prix_ht = $this->cleanPrice($product->prix_ht);
            }

            // Assurer les URLs
            $product->product_url = $product->url ?? '';
            $product->image = $product->image_url ?? '';

            // Propriétés pour l'UI
            $product->is_manual_search = true;
            $product->similarity_score = null;
            $product->match_level = null;

            $processed[] = $product;
        }

        return $processed;
    }

    // Mise à jour de l'état
    private function updateManualSearchState(array $results, string $cacheKey): void
    {
        $this->products = $results;
        $this->matchedProducts = $results;
        $this->hasData = !empty($results);
        $this->isAutomaticSearch = false;
        $this->hasAppliedFilters = true;

        // Calculer la pagination
        $this->totalPages = ceil(count($results) / $this->perPage);
        $this->hasMore = ($this->currentPage < $this->totalPages);

        // Cache
        $this->cachePut($cacheKey, $results);
    }

    // Clé de cache pour recherche manuelle
    private function getManualSearchCacheKey(): string
    {
        $cacheData = [
            'filters' => $this->filterCacheData($this->filters),
            'page' => $this->currentPage,
            'perPage' => $this->perPage
        ];

        return 'manual_search:' . md5(json_encode($cacheData));
    }

    // APPLIQUER LES FILTRES
    public function applyFilters()
    {
        // Réinitialiser la pagination
        $this->currentPage = 1;
        $this->totalPages = 0;
        
        // Invalider le cache
        $this->clearSearchCache();

        // Rechercher
        $this->searchManual();
    }

    // RÉINITIALISER LES FILTRES
    public function resetFilters()
    {
        // Sauvegarder le vendor original
        $originalVendor = $this->filters['vendor'];

        // Réinitialiser
        $this->filters = [
            'vendor' => $originalVendor,
            'name' => '',
            'variation' => '',
            'type' => '',
            'site_source' => ''
        ];

        $this->currentPage = 1;
        $this->hasAppliedFilters = false;
        $this->clearSearchCache();

        // Restaurer les résultats automatiques si disponibles
        if (!empty($this->originalAutomaticResults)) {
            $this->restoreAutomaticResults();
        } else {
            $this->getCompetitorPrice($this->searchQuery);
        }
    }

    // Restaurer les résultats automatiques
    private function restoreAutomaticResults(): void
    {
        // S'assurer que $this->originalAutomaticResults est bien un tableau d'objets
        if (!is_array($this->originalAutomaticResults)) {
            $this->originalAutomaticResults = [];
        }
        
        $this->matchedProducts = $this->originalAutomaticResults;
        $this->products = $this->originalAutomaticResults;
        $this->hasData = !empty($this->originalAutomaticResults);
        $this->isAutomaticSearch = true;
        $this->currentPage = 1;
        
        // Pagination
        $this->totalPages = ceil(count($this->originalAutomaticResults) / $this->perPage);
        $this->hasMore = ($this->currentPage < $this->totalPages);
        
        \Log::debug('Restored automatic results', [
            'count' => count($this->originalAutomaticResults),
            'type' => gettype($this->originalAutomaticResults),
            'first_item_type' => !empty($this->originalAutomaticResults[0]) ? gettype($this->originalAutomaticResults[0]) : 'empty'
        ]);
    }

    // Effacer le cache de recherche
    private function clearSearchCache(): void
    {
        $this->quickCache = []; // Vider le cache rapide
        
        // Clés spécifiques à effacer
        if (!empty($this->searchQuery)) {
            $autoCacheKey = $this->getCacheKey($this->searchQuery, [], false);
            Cache::forget($autoCacheKey);
        }
        
        $manualCacheKey = $this->getManualSearchCacheKey();
        Cache::forget($manualCacheKey);
    }

    // Mettre à jour les filtres avec debounce
    public function updatedFilters($value, $key)
    {
        // Debounce optimisé
        $this->debounce('applyFilters', 800);
    }

    // CHARGER PLUS DE RÉSULTATS
    public function loadMore()
    {
        if ($this->isLoading || !$this->hasMore) {
            return;
        }

        $this->currentPage++;
        $this->isLoading = true;

        if ($this->isAutomaticSearch) {
            $this->loadMoreAutomatic();
        } else {
            $this->loadMoreManual();
        }

        $this->isLoading = false;
    }

    private function loadMoreManual(): void
    {
        // Implémentation de pagination manuelle
        $start = ($this->currentPage - 1) * $this->perPage;
        $end = $start + $this->perPage;
        
        $newProducts = array_slice($this->products, $start, $this->perPage);
        
        if (!empty($newProducts)) {
            $this->matchedProducts = array_merge($this->matchedProducts, $newProducts);
        }
        
        $this->hasMore = (count($this->products) > count($this->matchedProducts));
    }

    private function loadMoreAutomatic(): void
    {
        // Pour la recherche automatique, on recharge tout
        $this->getCompetitorPrice($this->searchQuery);
    }

    // RECHERCHE AUTOMATIQUE OPTIMISÉE
    public function getCompetitorPrice($search)
    {
        if (empty($search)) {
            $this->resetSearchState();
            return null;
        }

        $startTime = microtime(true);

        try {
            // Vérifier le cache
            $cacheKey = $this->getCacheKey($search, [], false);
            $cachedResults = $this->cacheGet($cacheKey);

            if ($cachedResults !== null) {
                $this->applyAutomaticCachedResults($cachedResults);
                $this->logPerformance($startTime, 'auto_search_cache');
                return $cachedResults['full_result'];
            }

            // Préparer la recherche
            $this->extractSearchVolumes($search);
            $this->extractSearchVariationKeywords($search);
            $searchQuery = $this->prepareSearchTerms($search);

            if (empty($searchQuery)) {
                $this->resetSearchState();
                return null;
            }

            // Exécuter la recherche
            $result = $this->executeAutomaticSearch($searchQuery);

            // Traiter et scorer
            $processedProducts = $this->processAutomaticResults($result, $search);
            $matchedProducts = $this->calculateSimilarityBatch($processedProducts, $search);

            // Mettre en cache et mettre à jour l'état
            $fullResult = $this->cacheAndUpdateState($matchedProducts, $search, $searchQuery, $cacheKey);

            $this->logPerformance($startTime, 'auto_search_db');

            return $fullResult;

        } catch (\Throwable $e) {
            $this->handleSearchError($e);
            return null;
        }
    }

    // Exécuter la recherche automatique
    private function executeAutomaticSearch(string $searchQuery): array
    {
        $sql = "SELECT lp.*, ws.name as site_name, lp.url as product_url, lp.image_url as image
                FROM last_price_scraped_product lp
                LEFT JOIN web_site ws ON lp.web_site_id = ws.id
                WHERE MATCH (lp.name, lp.vendor, lp.type, lp.variation) 
                AGAINST (? IN BOOLEAN MODE)
                ORDER BY lp.prix_ht DESC 
                LIMIT 100";

        return DB::connection('mysql')->select($sql, [$searchQuery]);
    }

    // Traiter les résultats automatiques
    private function processAutomaticResults(array $results, string $search): array
    {
        $processed = [];
        
        foreach ($results as $product) {
            // Nettoyer le prix
            if (isset($product->prix_ht)) {
                $product->prix_ht = $this->cleanPrice($product->prix_ht);
            }

            // Normaliser le vendor
            if (isset($product->vendor)) {
                $product->vendor = $this->normalizeVendor($product->vendor);
            }

            // URLs
            $product->product_url = $product->url ?? '';
            $product->image = $product->image_url ?? '';
            $product->is_manual_search = false;

            $processed[] = $product;
        }

        return $processed;
    }

    // Calcul de similarité par lot (optimisé)
    private function calculateSimilarityBatch(array $products, string $search): array
    {
        $scoredProducts = [];
        $searchLower = strtolower($search);
        
        foreach ($products as $product) {
            $similarityScore = $this->computeQuickSimilarity($product, $searchLower);
            
            if ($similarityScore >= $this->similarityThreshold) {
                $product->similarity_score = $similarityScore;
                $product->match_level = $this->getMatchLevel($similarityScore);
                $scoredProducts[] = $product;
            }
        }

        // Trier par score
        usort($scoredProducts, function ($a, $b) {
            return ($b->similarity_score ?? 0) <=> ($a->similarity_score ?? 0);
        });

        return $scoredProducts;
    }

    // Similarité rapide
    private function computeQuickSimilarity($product, string $searchLower): float
    {
        $score = 0;
        
        // Vérifications rapides
        $productName = strtolower($product->name ?? '');
        $productVendor = strtolower($product->vendor ?? '');
        
        // Correspondance exacte partielle
        if (str_contains($productName, $searchLower)) {
            $score += 0.4;
        }
        
        if (str_contains($productVendor, $searchLower)) {
            $score += 0.3;
        }
        
        // Volumes correspondants
        if ($this->hasMatchingVolume($product)) {
            $score += 0.2;
        }
        
        // Variation correspondante
        if ($this->hasMatchingVariationKeyword($product)) {
            $score += 0.1;
        }
        
        return min(1.0, $score);
    }

// Mettre en cache et mettre à jour - CORRIGÉ
private function cacheAndUpdateState(array $matchedProducts, string $search, string $searchQuery, string $cacheKey): array
{
    // S'assurer que $this->id est valide
    $productDetails = null;
    if (!empty($this->id) && !is_array($this->id) && is_numeric($this->id)) {
        $productDetails = $this->getOneProductDetails((int) $this->id);
    }
    
    $fullResult = [
        'count' => count($matchedProducts),
        'has_data' => !empty($matchedProducts),
        'products' => $matchedProducts,
        'product' => $productDetails,
        'query' => $searchQuery
    ];

    // Mettre en cache
    $this->cachePut($cacheKey, [
        'products' => $matchedProducts,
        'full_result' => $fullResult
    ]);

    // Mettre à jour l'état
    $this->matchedProducts = $matchedProducts;
    $this->products = $matchedProducts;
    $this->originalAutomaticResults = $matchedProducts;
    $this->hasAppliedFilters = false;
    $this->hasData = !empty($matchedProducts);
    $this->isAutomaticSearch = true;
    $this->currentPage = 1;
    
    // Pagination
    $this->totalPages = ceil(count($matchedProducts) / $this->perPage);
    $this->hasMore = ($this->currentPage < $this->totalPages);

    return $fullResult;
}

// Appliquer les résultats du cache automatique - CORRIGÉ
private function applyAutomaticCachedResults($cachedResults): void
{
    // Vérifier la structure du cache
    if (!is_array($cachedResults) || !isset($cachedResults['products'])) {
        \Log::error('Invalid cache structure', ['cache' => $cachedResults]);
        $this->resetSearchState();
        return;
    }
    
    $products = $cachedResults['products'];
    
    // S'assurer que c'est un tableau
    if (!is_array($products)) {
        $products = [];
    }
    
    $this->matchedProducts = $products;
    $this->products = $products;
    $this->originalAutomaticResults = $products;
    $this->hasAppliedFilters = false;
    $this->hasData = !empty($products);
    $this->isAutomaticSearch = true;
    $this->currentPage = 1;
    
    $this->totalPages = ceil(count($products) / $this->perPage);
    $this->hasMore = ($this->currentPage < $this->totalPages);
}

// Valider et normaliser les données de produit
private function validateAndNormalizeProduct($product)
{
    // Si c'est déjà un objet stdClass, le retourner tel quel
    if ($product instanceof \stdClass) {
        return $product;
    }
    
    // Si c'est un tableau, le convertir en objet
    if (is_array($product)) {
        return (object) $product;
    }
    
    // Si c'est autre chose, créer un objet vide
    return (object) [];
}

// Utiliser cette méthode dans le traitement des résultats
private function processAutomaticResults(array $results, string $search): array
{
    $processed = [];
    
    foreach ($results as $product) {
        // Valider et normaliser le produit
        $product = $this->validateAndNormalizeProduct($product);
        
        // Nettoyer le prix
        if (isset($product->prix_ht)) {
            $product->prix_ht = $this->cleanPrice($product->prix_ht);
        }

        // Normaliser le vendor
        if (isset($product->vendor)) {
            $product->vendor = $this->normalizeVendor($product->vendor);
        }

        // URLs
        $product->product_url = $product->url ?? '';
        $product->image = $product->image_url ?? '';
        $product->is_manual_search = false;

        $processed[] = $product;
    }

    return $processed;
}
    // Réinitialiser l'état de recherche
    private function resetSearchState(): void
    {
        $this->products = [];
        $this->hasData = false;
        $this->originalAutomaticResults = [];
        $this->hasAppliedFilters = false;
        $this->showTable = true;
    }

    // Appliquer les résultats du cache
    private function applyCachedResults($cachedResults): void
    {
        $this->matchedProducts = $cachedResults;
        $this->products = $cachedResults;
        $this->hasData = !empty($cachedResults);
        $this->isAutomaticSearch = false;
        $this->hasAppliedFilters = true;
    }

    // Gestion des erreurs
    private function handleSearchError(\Throwable $e): void
    {
        \Log::error('Search error', [
            'message' => $e->getMessage(),
            'trace' => substr($e->getTraceAsString(), 0, 500)
        ]);

        $this->products = [];
        $this->hasData = false;
    }

    // Log de performance
    private function logPerformance(float $startTime, string $operation): void
    {
        $duration = microtime(true) - $startTime;
        
        if ($duration > 0.5) { // Seulement loguer les opérations lentes
            \Log::warning("Slow operation: {$operation}", [
                'duration' => round($duration, 3) . 's',
                'memory' => round(memory_get_usage() / 1024 / 1024, 2) . 'MB'
            ]);
        }
    }

    // MÉTHODES UTILITAIRES OPTIMISÉES

    // Nettoyer le prix (optimisé)
    private function cleanPrice($price)
    {
        if ($price === null || $price === '') {
            return null;
        }

        if (is_numeric($price)) {
            return (float) $price;
        }

        if (is_string($price)) {
            // Nettoyage rapide
            $cleanPrice = preg_replace('/[^\d,.-]/', '', $price);
            $cleanPrice = str_replace(',', '.', $cleanPrice);
            
            if (is_numeric($cleanPrice)) {
                return (float) $cleanPrice;
            }
        }

        return null;
    }

    // Variations du vendor
    private function getVendorVariations(string $vendor): array
    {
        $variations = [trim($vendor)];
        $normalized = $this->normalizeVendor($vendor);
        
        if ($normalized !== $vendor) {
            $variations[] = $normalized;
        }
        
        // Ajouter des variations simples
        $variations[] = strtoupper($vendor);
        $variations[] = strtolower($vendor);
        
        return array_unique(array_filter($variations));
    }

    // Extraire les volumes
    private function extractSearchVolumes(string $search): void
    {
        $this->searchVolumes = [];
        
        if (preg_match_all('/(\d+)\s*ml/i', $search, $matches)) {
            $this->searchVolumes = array_slice($matches[1], 0, 3); // Limiter à 3 volumes
        }
    }

    // Extraire les mots-clés de variation
    private function extractSearchVariationKeywords(string $search): void
    {
        $this->searchVariationKeywords = [];
        
        // Pattern simplifié
        $pattern = '/^[^-]+\s*-\s*[^-]+\s*-\s*/i';
        $variation = preg_replace($pattern, '', $search);
        
        if (empty($variation)) return;
        
        // Nettoyer et extraire les mots significatifs
        $words = preg_split('/\s+/', strtolower(trim($variation)));
        $stopWords = ['de', 'le', 'la', 'et', 'pour', 'avec', 'ml', 'edition'];
        
        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) > 2 && !in_array($word, $stopWords) && !is_numeric($word)) {
                $this->searchVariationKeywords[] = $word;
            }
            
            // Limiter à 5 mots-clés
            if (count($this->searchVariationKeywords) >= 5) break;
        }
    }

    // Préparer les termes de recherche
    private function prepareSearchTerms(string $search): string
    {
        $searchClean = preg_replace('/[^a-zA-ZÀ-ÿ\s]/', ' ', $search);
        $words = preg_split('/\s+/', strtolower(trim($searchClean)));
        
        $significantWords = [];
        $stopWords = ['de', 'le', 'la', 'et', 'pour', 'avec', 'eau', 'ml'];
        
        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) > 2 && !in_array($word, $stopWords)) {
                $significantWords[] = '+' . $word . '*';
            }
            
            // Limiter à 3 termes
            if (count($significantWords) >= 3) break;
        }
        
        return implode(' ', $significantWords);
    }

    // Vérifier si le produit a un volume correspondant
    public function hasMatchingVolume($product): bool
    {
        if (empty($this->searchVolumes)) return false;
        
        $productText = ($product->name ?? '') . ' ' . ($product->variation ?? '');
        preg_match_all('/(\d+)\s*ml/i', $productText, $matches);
        $productVolumes = $matches[1] ?? [];
        
        return !empty(array_intersect($this->searchVolumes, $productVolumes));
    }

    // Vérifier si la variation correspond
    public function hasMatchingVariationKeyword($product): bool
    {
        if (empty($this->searchVariationKeywords) || empty($product->variation)) {
            return false;
        }
        
        $variationLower = strtolower($product->variation);
        
        foreach ($this->searchVariationKeywords as $keyword) {
            if (str_contains($variationLower, $keyword)) {
                return true;
            }
        }
        
        return false;
    }

    // Extraire les volumes du texte
    public function extractVolumesFromText($text): array
    {
        if (empty($text)) return [];
        
        $volumes = [];
        if (preg_match_all('/(\d+)\s*ml/i', $text, $matches)) {
            $volumes = $matches[1];
        }
        
        return $volumes;
    }

    // Ajuster le seuil de similarité
    public function adjustSimilarityThreshold($threshold)
    {
        $this->similarityThreshold = $threshold;
        $this->clearSearchCache();
        
        if (!empty($this->searchQuery)) {
            $this->getCompetitorPrice($this->searchQuery);
        }
    }

// Détails du produit avec cache - CORRIGÉ
public function getOneProductDetails($entity_id)
{
    // Vérifier que $entity_id n'est pas un tableau
    if (is_array($entity_id)) {
        \Log::error('Entity ID is array instead of scalar', ['entity_id' => $entity_id]);
        return ['error' => 'Invalid entity ID'];
    }
    
    $cacheKey = 'product_details:' . (string) $entity_id;
    $cachedDetails = $this->cacheGet($cacheKey);
    
    if ($cachedDetails !== null) {
        return $cachedDetails;
    }
    
    try {
        // Requête simplifiée
        $dataQuery = "
            SELECT 
                produit.entity_id as id,
                produit.sku as sku,
                product_char.reference as parkode,
                CAST(product_char.name AS CHAR) as title,
                SUBSTRING_INDEX(product_char.name, ' - ', 1) as vendor,
                product_char.thumbnail as thumbnail,
                ROUND(product_decimal.price, 2) as price,
                product_int.status as status
            FROM catalog_product_entity as produit
            LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
            LEFT JOIN product_decimal ON product_decimal.entity_id = produit.entity_id
            LEFT JOIN product_int ON product_int.entity_id = produit.entity_id
            WHERE product_int.status >= 0 AND produit.entity_id = ? 
            LIMIT 1
        ";
        
        $result = DB::connection('mysqlMagento')->select($dataQuery, [(int) $entity_id]);
        
        // Mettre en cache
        $this->cachePut($cacheKey, $result, 30);
        
        return $result;
        
    } catch (\Throwable $e) {
        \Log::error('Error loading product details', [
            'error' => $e->getMessage(),
            'entity_id' => $entity_id,
            'entity_id_type' => gettype($entity_id)
        ]);
        return ['error' => $e->getMessage()];
    }
}

    // MÉTHODES D'AFFICHAGE OPTIMISÉES

    public function formatPrice($price)
    {
        $cleanPrice = $this->cleanPrice($price);
        return $cleanPrice !== null ? number_format($cleanPrice, 2, ',', ' ') . ' €' : 'N/A';
    }

    public function extractDomain($url)
    {
        if (empty($url)) return 'N/A';
        
        $parsed = parse_url($url);
        $domain = $parsed['host'] ?? '';
        
        if (strpos($domain, 'www.') === 0) {
            $domain = substr($domain, 4);
        }
        
        return $domain ?: 'N/A';
    }

    public function getMatchLevel($similarityScore)
    {
        if ($similarityScore >= 0.9) return 'excellent';
        if ($similarityScore >= 0.7) return 'bon';
        if ($similarityScore >= 0.6) return 'moyen';
        return 'faible';
    }

    public function calculatePriceDifference($competitorPrice)
    {
        $cleanCompetitor = $this->cleanPrice($competitorPrice);
        $cleanReference = $this->cleanPrice($this->referencePrice);
        
        if ($cleanCompetitor === null || $cleanReference === null) {
            return null;
        }
        
        return $cleanReference - $cleanCompetitor;
    }

    public function getPriceCompetitiveness($competitorPrice)
    {
        $difference = $this->calculatePriceDifference($competitorPrice);
        
        if ($difference === null) return 'unknown';
        if ($difference > 10) return 'higher';
        if ($difference > 0) return 'slightly_higher';
        if ($difference == 0) return 'same';
        if ($difference >= -10) return 'competitive';
        return 'very_competitive';
    }

    public function getPriceStatusLabel($competitorPrice)
    {
        $status = $this->getPriceCompetitiveness($competitorPrice);
        
        $labels = [
            'very_competitive' => 'Nous sommes beaucoup moins cher',
            'competitive' => 'Nous sommes moins cher',
            'same' => 'Prix identique',
            'slightly_higher' => 'Nous sommes légèrement plus cher',
            'higher' => 'Nous sommes beaucoup plus cher',
            'unknown' => 'Non comparable'
        ];
        
        return $labels[$status] ?? $labels['unknown'];
    }

    public function getPriceStatusClass($competitorPrice)
    {
        $status = $this->getPriceCompetitiveness($competitorPrice);
        
        $classes = [
            'very_competitive' => 'bg-green-100 text-green-800',
            'competitive' => 'bg-emerald-100 text-emerald-800',
            'same' => 'bg-blue-100 text-blue-800',
            'slightly_higher' => 'bg-yellow-100 text-yellow-800',
            'higher' => 'bg-red-100 text-red-800',
            'unknown' => 'bg-gray-100 text-gray-800'
        ];
        
        return $classes[$status] ?? $classes['unknown'];
    }

    // Image optimisée
    public function getProductImage($product)
    {
        // Vérifier dans l'ordre de priorité
        $sources = [
            $product->image ?? null,
            $product->image_url ?? null,
            $product->thumbnail ?? null,
            $product->swatch_image ?? null
        ];
        
        foreach ($sources as $source) {
            if (!empty($source) && $this->isValidImageUrl($source)) {
                return $source;
            }
        }
        
        // Placeholder optimisé
        return 'data:image/svg+xml;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100">
                <rect width="100" height="100" fill="#f3f4f6"/>
            </svg>'
        );
    }

    public function isValidImageUrl($url)
    {
        if (empty($url)) return false;
        
        // Vérification rapide
        $validExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $path = parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        
        return in_array($extension, $validExtensions);
    }

    // Mettre en évidence les termes correspondants
    public function highlightMatchingTerms($text)
    {
        if (empty($text)) return $text;
        
        $patterns = [];
        
        // Volumes
        if (!empty($this->searchVolumes)) {
            foreach ($this->searchVolumes as $volume) {
                $patterns[] = '\b' . preg_quote($volume, '/') . '\s*ml\b';
            }
        }
        
        // Mots-clés
        if (!empty($this->searchVariationKeywords)) {
            foreach ($this->searchVariationKeywords as $keyword) {
                if (strlen($keyword) > 2) {
                    $patterns[] = '\b' . preg_quote($keyword, '/') . '\b';
                }
            }
        }
        
        if (empty($patterns)) return e($text);
        
        $pattern = '/(' . implode('|', $patterns) . ')/iu';
        
        return preg_replace($pattern, '<span class="bg-yellow-100 px-1 rounded">$1</span>', e($text));
    }

    // Analyse des prix (simplifiée)
    public function getPriceAnalysis()
    {
        if (empty($this->matchedProducts) || !$this->referencePrice) {
            return null;
        }
        
        $prices = [];
        foreach ($this->matchedProducts as $product) {
            $price = $product->price_ht ?? $product->prix_ht;
            $cleanPrice = $this->cleanPrice($price);
            if ($cleanPrice !== null) {
                $prices[] = $cleanPrice;
            }
        }
        
        if (empty($prices)) return null;
        
        $minPrice = min($prices);
        $maxPrice = max($prices);
        $avgPrice = array_sum($prices) / count($prices);
        $ourPrice = $this->cleanPrice($this->referencePrice);
        
        return [
            'min' => $minPrice,
            'max' => $maxPrice,
            'average' => $avgPrice,
            'our_price' => $ourPrice,
            'count' => count($prices),
            'our_position' => $ourPrice <= $avgPrice ? 'competitive' : 'above_average'
        ];
    }

    // Similarité pour recherche manuelle
    public function calculateManualSimilarity($product)
    {
        if (!empty($this->searchQuery)) {
            $similarityScore = $this->computeQuickSimilarity($product, strtolower($this->searchQuery));
            $matchLevel = $this->getMatchLevel($similarityScore);
            
            return [
                'similarity_score' => $similarityScore,
                'match_level' => $matchLevel
            ];
        }
        
        return [
            'similarity_score' => null,
            'match_level' => null
        ];
    }
}; ?>
<div>
    <!-- Overlay de chargement global - Uniquement visible lors d'une action Livewire -->
    <div wire:loading.delay.flex class="hidden fixed inset-0 z-50 items-center justify-center bg-transparent">
        <div class="flex flex-col items-center justify-center bg-white/90 backdrop-blur-sm rounded-2xl p-8 shadow-2xl border border-white/20 min-w-[200px]">
            <!-- Spinner -->
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mb-4"></div>
            
            <!-- Texte de chargement -->
            <p class="text-lg font-semibold text-gray-800">Chargement</p>
            <p class="text-sm text-gray-600 mt-1">Veuillez patienter...</p>
        </div>
    </div>

    <!-- Indicateur de chargement pour les filtres - Uniquement lors du filtrage -->
    <div wire:loading.delay.flex wire:target="filters.vendor, filters.name, filters.variation, filters.type, filters.site_source" class="hidden fixed top-4 right-4 z-40 items-center justify-center">
        <div class="bg-blue-500/90 backdrop-blur-sm text-white px-4 py-2 rounded-lg shadow-lg flex items-center space-x-2">
            <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-white"></div>
            <span class="text-sm">Filtrage en cours...</span>
        </div>
    </div>

    <livewire:plateformes.detail :id="$id"/>

    <!-- Section d'analyse des prix (uniquement si on a des données) -->
    @if($hasData && $referencePrice && count($matchedProducts) > 0)
        @php
            $priceAnalysis = $this->getPriceAnalysis();
            $cosmashopAnalysis = $this->getCosmashopPriceAnalysis();
        @endphp
        @if($priceAnalysis && $cosmashopAnalysis)
            <div class="mx-auto w-full px-4 py-4 sm:px-6 lg:px-8">
                <!-- Analyse Cosmaparfumerie -->
                <div class="bg-gradient-to-r from-purple-50 to-indigo-50 rounded-lg border border-purple-200 p-4 mb-4">
                    <h4 class="text-lg font-semibold text-purple-800 mb-3 flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                        Analyse Cosmaparfumerie
                    </h4>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-3">
                        <div class="text-center p-3 bg-white rounded-lg shadow-sm">
                            <div class="text-2xl font-bold text-green-600">{{ $this->formatPrice($priceAnalysis['min']) }}</div>
                            <div class="text-xs text-gray-600">Prix minimum concurrent</div>
                        </div>
                        <div class="text-center p-3 bg-white rounded-lg shadow-sm">
                            <div class="text-2xl font-bold text-red-600">{{ $this->formatPrice($priceAnalysis['max']) }}</div>
                            <div class="text-xs text-gray-600">Prix maximum concurrent</div>
                        </div>
                        <div class="text-center p-3 bg-white rounded-lg shadow-sm">
                            <div class="text-2xl font-bold text-blue-600">{{ $this->formatPrice($priceAnalysis['average']) }}</div>
                            <div class="text-xs text-gray-600">Prix moyen concurrent</div>
                        </div>
                        <div class="text-center p-3 bg-white rounded-lg shadow-sm border-2 
                            {{ $priceAnalysis['our_position'] === 'competitive' ? 'border-green-300' : 'border-yellow-300' }}">
                            <div class="text-2xl font-bold text-purple-600">{{ $this->formatPrice($priceAnalysis['our_price']) }}</div>
                            <div class="text-xs {{ $priceAnalysis['our_position'] === 'competitive' ? 'text-green-600' : 'text-yellow-600' }} font-semibold">
                                Notre prix actuel
                            </div>
                        </div>
                    </div>

                    <div class="text-sm text-purple-700">
                        <strong>Analyse :</strong> 
                        Notre prix est 
                        <span class="font-semibold {{ $priceAnalysis['our_position'] === 'competitive' ? 'text-green-600' : 'text-yellow-600' }}">
                            {{ $priceAnalysis['our_position'] === 'competitive' ? 'compétitif' : 'au-dessus de la moyenne' }}
                        </span>
                        par rapport aux {{ $priceAnalysis['count'] }} concurrents analysés.
                        @if($priceAnalysis['our_price'] > $priceAnalysis['average'])
                            <span class="text-yellow-600">({{ $this->formatPriceDifference($priceAnalysis['our_price'] - $priceAnalysis['average']) }} par rapport à la moyenne)</span>
                        @endif
                    </div>
                </div>

                <!-- Analyse Cosmashop -->
                <div class="bg-gradient-to-r from-orange-50 to-amber-50 rounded-lg border border-orange-200 p-4">
                    <h4 class="text-lg font-semibold text-orange-800 mb-3 flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Analyse Cosmashop (Prix +5%)
                    </h4>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-3">
                        <div class="text-center p-3 bg-white rounded-lg shadow-sm">
                            <div class="text-2xl font-bold text-green-600">{{ $this->formatPrice($cosmashopAnalysis['min']) }}</div>
                            <div class="text-xs text-gray-600">Prix minimum concurrent</div>
                        </div>
                        <div class="text-center p-3 bg-white rounded-lg shadow-sm">
                            <div class="text-2xl font-bold text-red-600">{{ $this->formatPrice($cosmashopAnalysis['max']) }}</div>
                            <div class="text-xs text-gray-600">Prix maximum concurrent</div>
                        </div>
                        <div class="text-center p-3 bg-white rounded-lg shadow-sm">
                            <div class="text-2xl font-bold text-blue-600">{{ $this->formatPrice($cosmashopAnalysis['average']) }}</div>
                            <div class="text-xs text-gray-600">Prix moyen concurrent</div>
                        </div>
                        <div class="text-center p-3 bg-white rounded-lg shadow-sm border-2 border-orange-300">
                            <div class="text-2xl font-bold text-orange-600">{{ $this->formatPrice($cosmashopAnalysis['cosmashop_price']) }}</div>
                            <div class="text-xs text-orange-600 font-semibold">
                                Prix Cosmashop
                            </div>
                        </div>
                    </div>

                    <div class="text-sm text-orange-700">
                        <strong>Analyse :</strong> 
                        Le prix Cosmashop serait 
                        <span class="font-semibold {{ $cosmashopAnalysis['cosmashop_position'] === 'competitive' ? 'text-green-600' : 'text-yellow-600' }}">
                            {{ $cosmashopAnalysis['cosmashop_position'] === 'competitive' ? 'compétitif' : 'au-dessus de la moyenne' }}
                        </span>.
                        <span class="font-semibold text-green-600">{{ $cosmashopAnalysis['below_cosmashop'] }} concurrent(s)</span> en dessous et 
                        <span class="font-semibold text-red-600">{{ $cosmashopAnalysis['above_cosmashop'] }} concurrent(s)</span> au-dessus.
                    </div>
                </div>
            </div>
        @endif
    @endif

    <!-- Section des résultats - TOUJOURS AFFICHÉE -->
    <div class="mx-auto w-full px-4 py-6 sm:px-6 lg:px-8">
        <!-- Message d'information si pas de résultats automatiques -->
        @if(!$hasData && $showTable)
            <div class="mb-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-yellow-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span class="text-sm font-medium text-yellow-800">
                        Aucun résultat trouvé automatiquement. Utilisez les filtres ci-dessous pour rechercher manuellement.
                    </span>
                </div>
                <p class="mt-2 text-sm text-yellow-700">
                    Le vendor a été pré-rempli à partir de votre recherche. Vous pouvez ajuster les autres filtres pour trouver des produits.
                </p>
            </div>
        @endif

        @if($hasData && $isAutomaticSearch)
            <!-- Indicateur de similarité (uniquement si on a des résultats automatiques) -->
            <div class="mb-4 p-4 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg border">
                <div class="flex flex-col space-y-3">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 text-blue-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                            </svg>
                            <span class="text-sm font-medium text-blue-800">
                                Algorithme de similarité activé - 
                                {{ count($matchedProducts) }} produit(s) correspondant(s) au seuil de {{ $similarityThreshold * 100 }}%
                            </span>
                        </div>
                        <div class="flex items-center space-x-2">
                            <span class="text-xs text-blue-600 font-semibold">Ajuster le seuil :</span>
                            <!-- Boutons avec indicateurs de chargement -->
                            <button wire:click="adjustSimilarityThreshold(0.5)" 
                                    class="px-2 py-1 text-xs {{ $similarityThreshold == 0.5 ? 'bg-blue-600 text-white' : 'bg-blue-100 text-blue-800' }} rounded transition-colors flex items-center justify-center min-w-[50px]"
                                    wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="adjustSimilarityThreshold(0.5)">50%</span>
                                <span wire:loading wire:target="adjustSimilarityThreshold(0.5)">
                                    <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-blue-600"></div>
                                </span>
                            </button>
                            <button wire:click="adjustSimilarityThreshold(0.6)" 
                                    class="px-2 py-1 text-xs {{ $similarityThreshold == 0.6 ? 'bg-blue-600 text-white' : 'bg-blue-100 text-blue-800' }} rounded transition-colors flex items-center justify-center min-w-[50px]"
                                    wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="adjustSimilarityThreshold(0.6)">60%</span>
                                <span wire:loading wire:target="adjustSimilarityThreshold(0.6)">
                                    <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-blue-600"></div>
                                </span>
                            </button>
                            <button wire:click="adjustSimilarityThreshold(0.7)" 
                                    class="px-2 py-1 text-xs {{ $similarityThreshold == 0.7 ? 'bg-blue-600 text-white' : 'bg-blue-100 text-blue-800' }} rounded transition-colors flex items-center justify-center min-w-[50px]"
                                    wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="adjustSimilarityThreshold(0.7)">70%</span>
                                <span wire:loading wire:target="adjustSimilarityThreshold(0.7)">
                                    <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-blue-600"></div>
                                </span>
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center space-x-4 text-xs text-blue-600">
                        <span class="font-semibold">Légende :</span>
                        <span class="flex items-center">
                            <span class="w-3 h-3 bg-green-500 rounded-full mr-1"></span>
                            Excellent (90-100%)
                        </span>
                        <span class="flex items-center">
                            <span class="w-3 h-3 bg-blue-500 rounded-full mr-1"></span>
                            Bon (70-89%)
                        </span>
                        <span class="flex items-center">
                            <span class="w-3 h-3 bg-yellow-500 rounded-full mr-1"></span>
                            Moyen (60-69%)
                        </span>
                    </div>
                </div>
            </div>

            <!-- Critères de recherche -->
            @if(!empty($searchVolumes) || !empty($searchVariationKeywords))
                <div class="mb-4 p-4 bg-blue-50 rounded-lg">
                    <div class="flex flex-col space-y-2">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 text-blue-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <span class="text-sm font-medium text-blue-800">Critères de recherche détectés :</span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if(!empty($searchVolumes))
                                <div class="flex items-center">
                                    <span class="text-xs text-blue-700 mr-1">Volumes :</span>
                                    @foreach($searchVolumes as $volume)
                                        <span class="bg-green-100 text-green-800 font-semibold px-2 py-1 rounded text-xs">{{ $volume }} ml</span>
                                    @endforeach
                                </div>
                            @endif
                            @php
                                $searchVariation = $this->extractSearchVariation();
                            @endphp
                            @if($searchVariation)
                                <div class="flex items-center">
                                    <span class="text-xs text-blue-700 mr-1">Variation :</span>
                                    <span class="bg-blue-100 text-blue-800 font-semibold px-2 py-1 rounded text-xs">{{ $searchVariation }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endif
        @endif

        <!-- Indicateur des filtres actifs -->
        @if(array_filter($filters))
            <div class="mb-4 p-3 bg-blue-50 rounded-lg border border-blue-200">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 text-blue-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path>
                        </svg>
                        <span class="text-sm font-medium text-blue-800">Filtres actifs :</span>
                    </div>
                    <!-- Bouton Réinitialiser avec indicateur de chargement -->
                    <button wire:click="resetFilters" 
                            class="px-3 py-1.5 text-sm bg-red-50 text-red-700 hover:bg-red-100 rounded-md transition-colors duration-200 flex items-center border border-red-200"
                            wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="resetFilters">
                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                            Réinitialiser les filtres
                        </span>
                        <span wire:loading wire:target="resetFilters">
                            <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-red-600 mr-1"></div>
                            Réinitialisation...
                        </span>
                    </button>
                </div>

                <div class="mt-2 flex flex-wrap gap-2">
                    <!-- FILTRE VENDOR AJOUTÉ -->
                    @if($filters['vendor'])
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800 border border-blue-200">
                            Marque: {{ $filters['vendor'] }}
                            <button wire:click="$set('filters.vendor', '')" 
                                    class="ml-2 text-blue-600 hover:text-blue-800 flex items-center"
                                    wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="$set('filters.vendor', '')">×</span>
                                <span wire:loading wire:target="$set('filters.vendor', '')">
                                    <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-blue-600"></div>
                                </span>
                            </button>
                        </span>
                    @endif

                    @if($filters['name'])
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800 border border-green-200">
                            Nom: {{ $filters['name'] }}
                            <button wire:click="$set('filters.name', '')" 
                                    class="ml-2 text-green-600 hover:text-green-800 flex items-center"
                                    wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="$set('filters.name', '')">×</span>
                                <span wire:loading wire:target="$set('filters.name', '')">
                                    <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-green-600"></div>
                                </span>
                            </button>
                        </span>
                    @endif

                    @if($filters['variation'])
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-800 border border-purple-200">
                            Variation: {{ $filters['variation'] }}
                            <button wire:click="$set('filters.variation', '')" 
                                    class="ml-2 text-purple-600 hover:text-purple-800 flex items-center"
                                    wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="$set('filters.variation', '')">×</span>
                                <span wire:loading wire:target="$set('filters.variation', '')">
                                    <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-purple-600"></div>
                                </span>
                            </button>
                        </span>
                    @endif

                    @if($filters['type'])
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-orange-100 text-orange-800 border border-orange-200">
                            Type: {{ $filters['type'] }}
                            <button wire:click="$set('filters.type', '')" 
                                    class="ml-2 text-orange-600 hover:text-orange-800 flex items-center"
                                    wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="$set('filters.type', '')">×</span>
                                <span wire:loading wire:target="$set('filters.type', '')">
                                    <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-orange-600"></div>
                                </span>
                            </button>
                        </span>
                    @endif

                    @if($filters['site_source'])
                        @php
                            $selectedSite = $sites->firstWhere('id', $filters['site_source']);
                            $siteName = $selectedSite ? $selectedSite->name : 'Site ID: ' . $filters['site_source'];
                        @endphp
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800 border border-indigo-200">
                            Site: {{ $siteName }}
                            <button wire:click="$set('filters.site_source', '')" 
                                    class="ml-2 text-indigo-600 hover:text-indigo-800 flex items-center"
                                    wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="$set('filters.site_source', '')">×</span>
                                <span wire:loading wire:target="$set('filters.site_source', '')">
                                    <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-indigo-600"></div>
                                </span>
                            </button>
                        </span>
                    @endif
                </div>
            </div>
        @endif

        <!-- Tableau des résultats - TOUJOURS AFFICHÉ -->
        @if($showTable)
            <div class="bg-white shadow-sm rounded-lg overflow-hidden" wire:loading.class="opacity-50" wire:target="adjustSimilarityThreshold, resetFilters, updatedFilters">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">
                        @if($hasData)
                            @if($isAutomaticSearch)
                                Résultats de la recherche automatique ({{ count($matchedProducts) }} produit(s))
                            @else
                                Résultats de la recherche manuelle ({{ count($matchedProducts) }} produit(s))
                            @endif
                        @else
                            Recherche manuelle - Utilisez les filtres
                        @endif
                    </h3>
                    <p class="mt-1 text-sm text-gray-500">
                        @if($hasData)
                            <span wire:loading.remove wire:target="adjustSimilarityThreshold, resetFilters, updatedFilters">
                                {{ count($matchedProducts) }} produit(s) trouvé(s)
                            </span>
                        @else
                            Aucun résultat automatique. Utilisez les filtres pour rechercher manuellement.
                        @endif
                    </p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <!-- NOUVELLE COLONNE : Image (TOUJOURS VISIBLE) -->
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <div class="flex flex-col">
                                        <span>Image</span>
                                    </div>
                                </th>
                                
                                @if($hasData && $isAutomaticSearch)
                                <!-- Colonne Score (uniquement si résultats automatiques) -->
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <div class="flex flex-col">
                                        <span>Score</span>
                                    </div>
                                </th>
                                
                                <!-- Colonne Correspondance (uniquement si résultats automatiques) -->
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <div class="flex flex-col">
                                        <span>Correspondance</span>
                                    </div>
                                </th>
                                @endif
                                
                                <!-- Colonne Vendor avec filtre AJOUTÉE -->
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-48">
                                    <div class="flex flex-col space-y-2">
                                        <span class="whitespace-nowrap">Marque/Vendor</span>
                                        <div class="relative">
                                            <input type="text" 
                                                   disabled
                                                   wire:model.live.debounce.800ms="filters.vendor"
                                                   placeholder="Filtrer par marque..."
                                                   class="px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 w-full"
                                                   wire:loading.attr="disabled">
                                            <div wire:loading wire:target="filters.vendor" class="absolute right-3 top-1/2 transform -translate-y-1/2">
                                                <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-blue-600"></div>
                                            </div>
                                        </div>
                                    </div>
                                </th>
                                
                                <!-- Colonne Nom avec filtre -->
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-64" style="width: 30%;">
                                    <!-- Largeur ajustée -->
                                    <div class="flex flex-col space-y-2">
                                        <span class="whitespace-nowrap">Nom</span>
                                        <div class="relative">
                                            <input type="text" 
                                                wire:model.live.debounce.800ms="filters.name"
                                                placeholder="Filtrer..."
                                                class="px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 w-full"
                                                wire:loading.attr="disabled">
                                            <div wire:loading wire:target="filters.name" class="absolute right-3 top-1/2 transform -translate-y-1/2">
                                                <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-blue-600"></div>
                                            </div>
                                        </div>
                                    </div>
                                </th>
                                
                                <!-- Colonne Variation avec filtre -->
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <div class="flex flex-col space-y-1">
                                        <span>Variation</span>
                                        <div class="relative">
                                            <input type="text" 
                                                   wire:model.live.debounce.800ms="filters.variation"
                                                   placeholder="Filtrer..."
                                                   class="px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 w-full"
                                                   wire:loading.attr="disabled">
                                            <div wire:loading wire:target="filters.variation" class="absolute right-2 top-1/2 transform -translate-y-1/2">
                                                <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-blue-600"></div>
                                            </div>
                                        </div>
                                    </div>
                                </th>
                                
                                <!-- Colonne Site Source avec filtre -->
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <div class="flex flex-col space-y-1">
                                        <span>Site Source</span>
                                        <div class="relative">
                                            <select wire:model.live="filters.site_source"
                                                    class="px-2 py-1 text-xs border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 w-full"
                                                    wire:loading.attr="disabled">
                                                <option value="">Tous</option>
                                                @foreach($sites as $site)
                                                    <option value="{{ $site->id }}">{{ $site->name }}</option>
                                                @endforeach
                                            </select>
                                            <div wire:loading wire:target="filters.site_source" class="absolute right-2 top-1/2 transform -translate-y-1/2">
                                                <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-blue-600"></div>
                                            </div>
                                        </div>
                                    </div>
                                </th>
                                
                                <!-- Colonne Prix HT -->
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <div class="flex flex-col">
                                        <span>Prix HT</span>
                                    </div>
                                </th>
                                
                                <!-- Colonne Date MAJ Prix -->
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <div class="flex flex-col">
                                        <span>Date MAJ Prix</span>
                                    </div>
                                </th>
                                
                                @if($hasData && $referencePrice)
                                <!-- Colonne Vs Cosmaparfumerie (uniquement si on a un prix de référence) -->
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <div class="flex flex-col">
                                        <span>Vs Cosmaparfumerie</span>
                                    </div>
                                </th>
                                
                                <!-- Colonne Vs Cosmashop (uniquement si on a un prix de référence) -->
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <div class="flex flex-col">
                                        <span>Vs Cosmashop</span>
                                    </div>
                                </th>
                                @endif
                                
                                <!-- Colonne Type avec filtre -->
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <div class="flex flex-col space-y-1">
                                        <span>Type</span>
                                        <div class="relative">
                                            <input type="text" 
                                                   wire:model.live.debounce.800ms="filters.type"
                                                   placeholder="Filtrer..."
                                                   class="px-2 py-1 text-xs border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 w-full"
                                                   wire:loading.attr="disabled">
                                            <div wire:loading wire:target="filters.type" class="absolute right-2 top-1/2 transform -translate-y-1/2">
                                                <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-blue-600"></div>
                                            </div>
                                        </div>
                                    </div>
                                </th>
                                
                                <!-- Colonne Actions -->
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <div class="flex flex-col">
                                        <span>Actions</span>
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @if(count($matchedProducts) > 0)
                                @foreach($matchedProducts as $product)
                                    @php
                                        // Pour la recherche manuelle, on calcule la similarité à la volée si nécessaire
                                        if ($isAutomaticSearch) {
                                            $similarityScore = $product->similarity_score ?? null;
                                            $matchLevel = $product->match_level ?? null;
                                        } else {
                                            // Pour la recherche manuelle, on calcule la similarité
                                            $similarityData = $this->calculateManualSimilarity($product);
                                            $similarityScore = $similarityData['similarity_score'];
                                            $matchLevel = $similarityData['match_level'];
                                        }
                                        
                                        // Définir la classe de match si disponible
                                        if ($matchLevel) {
                                            $matchClass = [
                                                'excellent' => 'bg-green-100 text-green-800 border-green-300',
                                                'bon' => 'bg-blue-100 text-blue-800 border-blue-300',
                                                'moyen' => 'bg-yellow-100 text-yellow-800 border-yellow-300',
                                                'faible' => 'bg-gray-100 text-gray-800 border-gray-300'
                                            ][$matchLevel];
                                        }

                                        $productVolumes = $this->extractVolumesFromText($product->name . ' ' . $product->variation);
                                        $hasMatchingVolume = $this->hasMatchingVolume($product);
                                        $hasExactVariation = $this->hasExactVariationMatch($product);

                                        // Données pour la comparaison de prix (uniquement si référencePrice)
                                        if ($referencePrice) {
                                            $competitorPrice = $product->price_ht ?? $product->prix_ht;
                                            $priceDifference = $this->calculatePriceDifference($competitorPrice);
                                            $priceDifferencePercent = $this->calculatePriceDifferencePercentage($competitorPrice);
                                            $priceStatusClass = $this->getPriceStatusClass($competitorPrice);
                                            $priceStatusLabel = $this->getPriceStatusLabel($competitorPrice);

                                            // Données pour Cosmashop
                                            $cosmashopDifference = $this->calculateCosmashopPriceDifference($competitorPrice);
                                            $cosmashopDifferencePercent = $this->calculateCosmashopPriceDifferencePercentage($competitorPrice);
                                            $cosmashopStatusClass = $this->getCosmashopPriceStatusClass($competitorPrice);
                                            $cosmashopStatusLabel = $this->getCosmashopPriceStatusLabel($competitorPrice);
                                        }
                                    @endphp
                                    <tr class="hover:bg-gray-50 transition-colors duration-150">
                                        <!-- NOUVELLE COLONNE : Image (TOUJOURS VISIBLE) -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            @php
                                                $productImage = $this->getProductImage($product);
                                                $productName = $product->name ?? 'Produit sans nom';
                                            @endphp
                                            <div class="relative group">
                                                <img src="{{ $productImage }}" 
                                                     alt="{{ $productName }}" 
                                                     class="h-20 w-20 object-cover rounded-lg shadow-sm border border-gray-200 hover:shadow-md transition-shadow duration-200"
                                                     loading="lazy"
                                                     onerror="this.onerror=null; this.src='https://placehold.co/400x400/cccccc/999999?text=No+Image'">
                                                
                                                <!-- Overlay au survol pour agrandir -->
                                                <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-10 rounded-lg transition-all duration-200 flex items-center justify-center opacity-0 group-hover:opacity-100">
                                                    <svg class="w-6 h-6 text-white opacity-0 group-hover:opacity-70 transition-opacity duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7"></path>
                                                    </svg>
                                                </div>
                                            </div>
                                            
                                            <!-- Indicateur si pas d'image -->
                                            @if(!$this->isValidImageUrl($productImage) || str_contains($productImage, 'https://placehold.co/400x400/cccccc/999999?text=No+Image'))
                                                <div class="mt-1 text-center">
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800">
                                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                                        </svg>
                                                        Sans image
                                                    </span>
                                                </div>
                                            @endif
                                        </td>
                                        
                                        @if($hasData && $isAutomaticSearch)
                                        <!-- Colonne Score (uniquement si résultats automatiques) -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="w-16 bg-gray-200 rounded-full h-2 mr-3">
                                                    <div class="h-2 rounded-full 
                                                        @if($similarityScore >= 0.9) bg-green-500
                                                        @elseif($similarityScore >= 0.7) bg-blue-500
                                                        @elseif($similarityScore >= 0.6) bg-yellow-500
                                                        @else bg-gray-500 @endif"
                                                        style="width: {{ ($similarityScore ?? 0) * 100 }}%">
                                                    </div>
                                                </div>
                                                <span class="text-sm font-mono font-semibold 
                                                    @if($similarityScore >= 0.9) text-green-600
                                                    @elseif($similarityScore >= 0.7) text-blue-600
                                                    @elseif($similarityScore >= 0.6) text-yellow-600
                                                    @else text-gray-600 @endif">
                                                    {{ $similarityScore ? number_format($similarityScore * 100, 0) : 'N/A' }}%
                                                </span>
                                            </div>
                                        </td>

                                        <!-- Colonne Correspondance (uniquement si résultats automatiques) -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            @if($matchLevel)
                                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium border {{ $matchClass ?? '' }}">
                                                    @if($matchLevel === 'excellent')
                                                        <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                                        </svg>
                                                    @endif
                                                    {{ ucfirst($matchLevel) }}
                                                </span>
                                            @else
                                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium border border-gray-300 bg-gray-100 text-gray-800">
                                                    N/A
                                                </span>
                                            @endif
                                        </td>
                                        @endif

                                        <!-- Colonne Vendor AJOUTÉE -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">
                                                {{ $product->vendor ?? 'N/A' }}
                                            </div>
                                        </td>

                                        <!-- Colonne Nom -->
                                        <td class="px-6 py-4">
                                            <div class="text-sm font-medium text-gray-900 max-w-xs" title="{{ $product->name ?? 'N/A' }}">
                                                @if($isAutomaticSearch && !empty($searchVolumes))
                                                    {!! $this->highlightMatchingTerms($product->name) !!}
                                                @else
                                                    {{ $product->name ?? 'N/A' }}
                                                @endif
                                            </div>
                                            <!-- Badges des volumes du produit -->
                                            @if(!empty($productVolumes))
                                                <div class="mt-2 flex flex-wrap gap-1">
                                                    @foreach($productVolumes as $volume)
                                                        <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium 
                                                            @if($this->isVolumeMatching($volume))
                                                                bg-green-100 text-green-800 border border-green-300
                                                            @else
                                                                bg-gray-100 text-gray-800
                                                            @endif">
                                                            {{ $volume }} ml
                                                            @if($this->isVolumeMatching($volume))
                                                                <svg class="w-3 h-3 ml-1 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                                                </svg>
                                                            @endif
                                                        </span>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </td>

                                        <!-- Colonne Variation -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900 max-w-xs" title="{{ $product->variation ?? 'Standard' }}">
                                                @if($isAutomaticSearch && !empty($searchVariationKeywords))
                                                    {!! $this->highlightMatchingTerms($product->variation ?? 'Standard') !!}
                                                @else
                                                    {{ $product->variation ?? 'Standard' }}
                                                @endif
                                            </div>
                                            @if($hasData && $hasExactVariation)
                                                <div class="mt-1">
                                                    <span class="inline-flex items-center px-2 py-1 bg-blue-100 text-blue-800 text-xs font-medium rounded-full">
                                                        <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                                        </svg>
                                                        Variation identique
                                                    </span>
                                                </div>
                                            @endif
                                        </td>

                                        <!-- Colonne Site Source -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-8 w-8 bg-gray-100 rounded-full flex items-center justify-center mr-3">
                                                    <span class="text-xs font-medium text-gray-600">
                                                        @php
                                                            $productUrl = $this->getProductUrl($product);
                                                            $domain = $this->extractDomain($productUrl ?? '');
                                                            echo strtoupper(substr($domain, 0, 2));
                                                        @endphp
                                                    </span>
                                                </div>
                                                <div>
                                                    <div class="text-sm font-medium text-gray-900">
                                                        {{ $product->site_name ?? $this->extractDomain($productUrl ?? '') }}
                                                    </div>
                                                    {{-- @if(isset($product->web_site_id))
                                                        <div class="text-xs text-gray-500">
                                                            ID: {{ $product->web_site_id }}
                                                        </div>
                                                    @endif --}}
                                                </div>
                                            </div>
                                        </td>

                                        <!-- Colonne Prix HT -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-semibold text-green-600">
                                                {{ $this->formatPrice($product->price_ht ?? $product->prix_ht) }}
                                            </div>
                                        </td>

                                        <!-- Colonne Date MAJ Prix -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-xs text-gray-400">
                                                {{ \Carbon\Carbon::parse($product->updated_at)->translatedFormat('j F Y') }}
                                            </div>
                                        </td>

                                        @if($referencePrice)
                                        <!-- Colonne Vs Cosmaparfumerie (uniquement si référencePrice) -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            @if(is_numeric($competitorPrice) && is_numeric($referencePrice))
                                                <div class="space-y-1">
                                                    <div class="text-xs text-gray-500">
                                                        prix cosma-parfumerie: {{ number_format($referencePrice, 2, ',', ' ') }} €
                                                    </div>
                                                    <!-- Statut -->
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $priceStatusClass }}">
                                                        {{ $priceStatusLabel }}
                                                    </span>

                                                    <!-- Différence -->
                                                    <div class="text-xs font-semibold 
                                                        {{ $priceDifference > 0 ? 'text-green-600' : ($priceDifference < 0 ? 'text-red-600' : 'text-blue-600') }}">
                                                        {{ $this->formatPriceDifference($priceDifference) }}
                                                    </div>

                                                    <!-- Pourcentage -->
                                                    @if($priceDifferencePercent !== null && $priceDifference != 0)
                                                        <div class="text-xs text-gray-500">
                                                            {{ $this->formatPercentageDifference($priceDifferencePercent) }}
                                                        </div>
                                                    @endif
                                                </div>
                                            @else
                                                <span class="text-xs text-gray-400">N/A</span>
                                            @endif
                                        </td>

                                        <!-- Colonne Vs Cosmashop (uniquement si référencePrice) -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            @if(is_numeric($competitorPrice) && is_numeric($cosmashopPrice))
                                                <div class="space-y-1">
                                                    <div class="text-xs text-gray-500">
                                                        prix cosmashop: {{ number_format($cosmashopPrice, 2, ',', ' ') }} €
                                                    </div>
                                                    <!-- Statut Cosmashop -->
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $cosmashopStatusClass }}">
                                                        {{ $cosmashopStatusLabel }}
                                                    </span>

                                                    <!-- Différence Cosmashop -->
                                                    <div class="text-xs font-semibold 
                                                        {{ $cosmashopDifference > 0 ? 'text-green-600' : ($cosmashopDifference < 0 ? 'text-red-600' : 'text-blue-600') }}">
                                                        {{ $this->formatPriceDifference($cosmashopDifference) }}
                                                    </div>

                                                    <!-- Pourcentage Cosmashop -->
                                                    @if($cosmashopDifferencePercent !== null && $cosmashopDifference != 0)
                                                        <div class="text-xs text-gray-500">
                                                            {{ $this->formatPercentageDifference($cosmashopDifferencePercent) }}
                                                        </div>
                                                    @endif
                                                </div>
                                            @else
                                                <span class="text-xs text-gray-400">N/A</span>
                                            @endif
                                        </td>
                                        @endif

                                        <!-- Colonne Type -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                                {{ $product->type ?? 'N/A' }}
                                            </span>
                                        </td>

                                        <!-- Colonne Actions -->
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex space-x-2">
                                                @php
                                                    $productUrl = $this->getProductUrl($product);
                                                @endphp
                                                @if(!empty($productUrl))
                                                    <a href="{{ $productUrl }}" target="_blank" rel="noopener noreferrer"
                                                        class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-full shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors duration-200">
                                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                                d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                                        </svg>
                                                        Voir
                                                    </a>
                                                @else
                                                    <span class="inline-flex items-center px-2 py-1 text-xs text-gray-400 bg-gray-100 rounded-full">
                                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                                        </svg>
                                                        Indisponible
                                                    </span>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            @else
                                <!-- Aucun résultat avec les filtres appliqués -->
                                <tr>
                                    <td colspan="{{ ($hasData && $isAutomaticSearch ? 15 : 13) }}" class="px-6 py-12 text-center">
                                        <svg class="mx-auto h-16 w-16 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        <h3 class="mt-4 text-lg font-medium text-gray-900">
                                            @if(array_filter($filters))
                                                Aucun résultat avec les filtres actuels
                                            @else
                                                Aucun produit trouvé
                                            @endif
                                        </h3>
                                        <p class="mt-2 text-sm text-gray-500">
                                            @if(array_filter($filters))
                                                Aucun produit ne correspond à vos critères de recherche. Essayez de modifier les filtres.
                                            @else
                                                Ajustez les filtres pour trouver des produits.
                                            @endif
                                        </p>
                                        @if(array_filter($filters))
                                            <div class="mt-4 flex justify-center space-x-3">
                                                <!-- Bouton Réinitialiser avec loading -->
                                                <button wire:click="resetFilters" 
                                                        class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-red-600 hover:bg-red-700 focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-colors duration-200"
                                                        wire:loading.attr="disabled">
                                                    <span wire:loading.remove wire:target="resetFilters">
                                                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                                        </svg>
                                                        Réinitialiser les filtres
                                                    </span>
                                                    <span wire:loading wire:target="resetFilters">
                                                        <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2"></div>
                                                        Réinitialisation...
                                                    </span>
                                                </button>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>


  
@push('styles')
    <style>
        /* Style pour les filtres dans le thead */
        th .flex-col {
            min-height: 70px;
            justify-content: space-between;
        }

        /* Style pour les inputs de filtres */
        input[type="text"], select {
            transition: all 0.2s ease;
        }

        input[type="text"]:focus, select:focus {
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
            border-color: #3b82f6;
        }

        /* Style pour les filtres actifs */
        .filter-active {
            background-color: rgba(59, 130, 246, 0.1);
            border-left: 3px solid #3b82f6;
        }

        /* Style pour les badges de filtres */
        .filter-badge {
            transition: all 0.2s ease;
        }

        .filter-badge:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        /* Animation pour les boutons */
        button {
            transition: all 0.2s ease;
        }

        button:hover {
            transform: translateY(-1px);
        }

        button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        /* Style pour les colonnes avec filtres */
        th.with-filter {
            background-color: #f9fafb;
        }

        /* Animation de spin pour les loaders */
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .animate-spin {
            animation: spin 1s linear infinite;
        }

        /* Style pour les indicateurs de chargement dans les inputs */
        .relative .animate-spin {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
        }

        /* Style pour l'overlay de chargement global - Transparent */
        .fixed.inset-0 {
            z-index: 9999;
            background-color: transparent !important;
        }

        .fixed.inset-0 > div {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(8px);
            box-shadow: 
                0 20px 40px rgba(0, 0, 0, 0.1),
                0 0 0 1px rgba(255, 255, 255, 0.1);
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        /* Style pour le loader des filtres */
        .fixed.top-4.right-4 {
            z-index: 9998;
            animation: slideInRight 0.3s ease-out;
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        /* Transition pour l'opacité du tableau */
        .opacity-50 {
            transition: opacity 0.3s ease;
        }
    </style>
@endpush

@push('scripts')
    <script>
        // Script pour gérer les indicateurs de chargement
        document.addEventListener('livewire:init', () => {
            // Désactiver les inputs pendant le chargement
            Livewire.hook('request', ({ fail }) => {
                // Ajouter un indicateur visuel
                document.body.style.cursor = 'wait';

                fail(() => {
                    document.body.style.cursor = 'default';
                });
            });

            Livewire.hook('response', ({ component }) => {
                document.body.style.cursor = 'default';
            });
        });
    </script>
@endpush  
</div>