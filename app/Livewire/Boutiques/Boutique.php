<?php

namespace App\Livewire\Boutiques;

use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class Boutique extends Component
{
    use WithPagination;

    public $search = "";
    
    // Filtres avancés
    public $filterName = "";
    public $filterMarque = "";
    public $filterType = "";
    public $filterCapacity = "";

    // Nombre d'éléments par page
    public $perPage = 12;

    // Durée du cache en secondes (1 heure)
    protected $cacheTTL = 3600;
    
    // Préfixe pour les clés de cache
    protected $cachePrefix = 'boutique';

    protected $queryString = [
        'search' => ['except' => ''],
        'filterName' => ['except' => ''],
        'filterMarque' => ['except' => ''],
        'filterType' => ['except' => ''],
        'filterCapacity' => ['except' => ''],
        'perPage' => ['except' => 12],
    ];

    public function updated($property)
    {
        if (in_array($property, ['search', 'filterName', 'filterMarque', 'filterType', 'filterCapacity', 'perPage'])) {
            $this->resetPage();
        }
    }

    public function applyFilters()
    {
        $this->resetPage();
        $this->dispatch('close-drawer');
    }

    public function resetFilters()
    {
        $this->filterName = "";
        $this->filterMarque = "";
        $this->filterType = "";
        $this->filterCapacity = "";
        $this->search = "";
        $this->resetPage();
        $this->dispatch('close-drawer');
    }

    // Méthodes de pagination personnalisées
    public function goToPage($page)
    {
        $this->setPage($page);
    }

    public function previousPage()
    {
        $this->setPage(max(1, $this->getPage() - 1));
    }

    public function nextPage()
    {
        $currentPage = $this->getPage();
        $productsData = $this->getProducts();
        $totalPages = $productsData['total_page'];
        
        $this->setPage(min($totalPages, $currentPage + 1));
    }

    public function getPage()
    {
        return $this->paginators['page'] ?? 1;
    }

    public function setPage($page)
    {
        $this->paginators['page'] = $page;
    }

    /**
     * Génère une clé de cache simple et prévisible
     */
    protected function getCacheKey($page = null)
    {
        $page = $page ?? $this->getPage();
        
        // Créer un tableau avec seulement les filtres non vides
        $activeFilters = array_filter([
            'search' => $this->search,
            'name' => $this->filterName,
            'marque' => $this->filterMarque,
            'type' => $this->filterType,
            'capacity' => $this->filterCapacity,
            'perPage' => $this->perPage,
        ], function($value) {
            return !empty($value) || $value === 0 || $value === '0';
        });
        
        // Si aucun filtre actif, utiliser une clé simple
        if (empty($activeFilters)) {
            return sprintf('%s:products:page:%s', $this->cachePrefix, $page);
        }
        
        // Sinon, créer une clé basée sur les filtres actifs
        ksort($activeFilters); // Trier pour la consistance
        $filterString = http_build_query($activeFilters);
        $filterHash = md5($filterString);
        
        return sprintf('%s:products:%s:page:%s', $this->cachePrefix, $filterHash, $page);
    }

    /**
     * Génère une clé de cache pour le count
     */
    protected function getCountCacheKey()
    {
        $activeFilters = array_filter([
            'search' => $this->search,
            'name' => $this->filterName,
            'marque' => $this->filterMarque,
            'type' => $this->filterType,
            'capacity' => $this->filterCapacity,
            'perPage' => $this->perPage,
        ], function($value) {
            return !empty($value) || $value === 0 || $value === '0';
        });
        
        if (empty($activeFilters)) {
            return sprintf('%s:count:all', $this->cachePrefix);
        }
        
        ksort($activeFilters);
        $filterString = http_build_query($activeFilters);
        $filterHash = md5($filterString);
        
        return sprintf('%s:count:%s', $this->cachePrefix, $filterHash);
    }

    /**
     * Vider le cache de la page courante
     */
    public function clearCurrentPageCache()
    {
        try {
            $cacheKey = $this->getCacheKey();
            Cache::forget($cacheKey);
            
            $this->dispatch('cache-cleared', ['message' => 'Cache de la page courante vidé']);
        } catch (\Exception $e) {
            Log::error('Error clearing current page cache: ' . $e->getMessage());
            $this->dispatch('cache-error', ['message' => 'Erreur lors du vidage du cache']);
        }
    }

    /**
     * Vider tout le cache des produits
     */
    public function clearAllCache()
    {
        try {
            $pattern = $this->cachePrefix . ':*';
            $this->flushCacheByPattern($pattern);
            
            $this->dispatch('cache-cleared', ['message' => 'Tout le cache des produits vidé']);
        } catch (\Exception $e) {
            Log::error('Error clearing all cache: ' . $e->getMessage());
            $this->dispatch('cache-error', ['message' => 'Erreur lors du vidage du cache']);
        }
    }

    /**
     * Vider le cache par pattern (Redis uniquement)
     */
    protected function flushCacheByPattern($pattern)
    {
        if (config('cache.default') !== 'redis') {
            Cache::flush();
            return;
        }

        try {
            $redis = Cache::getRedis();
            
            // Version corrigée - gérer le cas où $foundKeys est null
            $keys = [];
            $cursor = '0';
            
            do {
                $result = $redis->scan($cursor, 'MATCH', $pattern, 'COUNT', 100);
                $cursor = $result[0];
                $foundKeys = $result[1] ?? [];
                
                if (!empty($foundKeys) && is_array($foundKeys)) {
                    $keys = array_merge($keys, $foundKeys);
                }
            } while ($cursor != '0');
            
            if (!empty($keys)) {
                $redis->del($keys);
                Log::info('Flushed cache keys', ['pattern' => $pattern, 'count' => count($keys)]);
            }
        } catch (\Exception $e) {
            Log::error('Redis pattern flush error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Obtenir les statistiques du cache
     */
    public function getCacheStats()
    {
        try {
            if (config('cache.default') !== 'redis') {
                return [
                    'total_keys' => 0,
                    'pattern' => 'N/A (non-Redis)',
                    'cache_driver' => config('cache.default'),
                ];
            }

            $redis = Cache::getRedis();
            
            // Compter toutes les clés avec le préfixe boutique
            $pattern = $this->cachePrefix . ':*';
            
            $keys = [];
            $cursor = '0';
            
            do {
                $result = $redis->scan($cursor, 'MATCH', $pattern, 'COUNT', 100);
                $cursor = $result[0];
                $foundKeys = $result[1] ?? [];
                
                if (!empty($foundKeys) && is_array($foundKeys)) {
                    $keys = array_merge($keys, $foundKeys);
                }
            } while ($cursor != '0');
            
            // Compter par sous-catégories
            $stats = [
                'total_keys' => count($keys),
                'by_category' => [
                    'products' => 0,
                    'count' => 0,
                ],
                'pattern' => $pattern,
                'cache_driver' => config('cache.default'),
            ];
            
            foreach ($keys as $key) {
                if (str_contains($key, ':products:')) {
                    $stats['by_category']['products']++;
                } elseif (str_contains($key, ':count:')) {
                    $stats['by_category']['count']++;
                }
            }
            
            return $stats;
            
        } catch (\Exception $e) {
            Log::error('Error getting cache stats: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Récupère les produits avec cache
     */
    public function getProducts()
    {
        $cacheKey = $this->getCacheKey();
        
        return Cache::remember($cacheKey, $this->cacheTTL, function () {
            return $this->fetchProductsFromDatabase();
        });
    }

    /**
     * Récupère les produits depuis la base de données
     */
    protected function fetchProductsFromDatabase()
    {
        try {
            $page = $this->getPage();
            $offset = ($page - 1) * $this->perPage;

            $subQuery = "";
            $params = [];

            // Global search
            if (!empty($this->search)) {
                $searchClean = str_replace("'", "", $this->search);
                $words = explode(" ", $searchClean);

                $subQuery = " AND ( ";
                $and = "";

                foreach ($words as $word) {
                    $subQuery .= " $and CONCAT(product_char.name, ' ', COALESCE(options.attribute_value, '')) LIKE ? ";
                    $params[] = "%$word%";
                    $and = "AND";
                }

                $subQuery .= " OR produit.sku LIKE ? ) ";
                $params[] = "%$searchClean%";
            }

            // Filtres avancés
            if (!empty($this->filterName)) {
                $subQuery .= " AND product_char.name LIKE ? ";
                $params[] = "%{$this->filterName}%";
            }

            if (!empty($this->filterMarque)) {
                $subQuery .= " AND SUBSTRING_INDEX(product_char.name, ' - ', 1) = ? ";
                $params[] = $this->filterMarque;
            }

            if (!empty($this->filterType)) {
                $subQuery .= " AND SUBSTRING_INDEX(eas.attribute_set_name, '_', -1) = ? ";
                $params[] = $this->filterType;
            }

            if (!empty($this->filterCapacity)) {
                $subQuery .= " AND product_int.capacity = ? ";
                $params[] = $this->filterCapacity;
            }

            // Filtre pour prix > 0
            $subQuery .= " AND product_decimal.price > 0 ";

            // Total count avec cache séparé
            $countCacheKey = $this->getCountCacheKey();
            $total = Cache::remember($countCacheKey, $this->cacheTTL, function () use ($subQuery, $params) {
                $resultTotal = DB::connection('mysqlMagento')->selectOne("
                    SELECT COUNT(*) as nb
                    FROM catalog_product_entity as produit
                    LEFT JOIN catalog_product_relation as parent_child_table ON parent_child_table.child_id = produit.entity_id 
                    LEFT JOIN catalog_product_super_link as cpsl ON cpsl.product_id = produit.entity_id 
                    LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
                    LEFT JOIN product_text ON product_text.entity_id = produit.entity_id 
                    LEFT JOIN product_decimal ON product_decimal.entity_id = produit.entity_id
                    LEFT JOIN product_int ON product_int.entity_id = produit.entity_id
                    LEFT JOIN product_media ON product_media.entity_id = produit.entity_id
                    LEFT JOIN product_categorie ON product_categorie.entity_id = produit.entity_id 
                    LEFT JOIN cataloginventory_stock_item AS stock_item ON stock_item.product_id = produit.entity_id 
                    LEFT JOIN cataloginventory_stock_status AS stock_status ON stock_item.product_id = stock_status.product_id 
                    LEFT JOIN option_super_attribut AS options ON options.simple_product_id = produit.entity_id 
                    LEFT JOIN eav_attribute_set AS eas ON produit.attribute_set_id = eas.attribute_set_id 
                    LEFT JOIN catalog_product_entity as produit_parent ON parent_child_table.parent_id = produit_parent.entity_id 
                    LEFT JOIN product_char as product_parent_char ON product_parent_char.entity_id = produit_parent.entity_id
                    LEFT JOIN product_text as product_parent_text ON product_parent_text.entity_id = produit_parent.entity_id 
                    WHERE product_int.status >= 0 $subQuery
                ", $params);

                return $resultTotal->nb ?? 0;
            });

            $nbPage = ceil($total / $this->perPage);

            if ($page > $nbPage && $nbPage > 0) {
                $this->setPage(1);
                $page = 1;
                $offset = 0;
            }

            // Paginated data
            $dataQuery = "
                SELECT 
                    produit.entity_id as id,
                    produit.sku as sku,
                    product_char.reference as parkode,
                    CAST(product_char.name AS CHAR CHARACTER SET utf8mb4) as title,
                    CAST(product_parent_char.name AS CHAR CHARACTER SET utf8mb4) as parent_title,
                    SUBSTRING_INDEX(product_char.name, ' - ', 1) as vendor,
                    SUBSTRING_INDEX(eas.attribute_set_name, '_', -1) as type,
                    product_char.thumbnail as thumbnail,
                    product_char.swatch_image as swatch_image,
                    product_char.reference as parkode,
                    product_char.reference_us as reference_us,
                    CAST(product_text.description AS CHAR CHARACTER SET utf8mb4) as description,
                    CAST(product_text.short_description AS CHAR CHARACTER SET utf8mb4) as short_description,
                    CAST(product_parent_text.description AS CHAR CHARACTER SET utf8mb4) as parent_description,
                    CAST(product_parent_text.short_description AS CHAR CHARACTER SET utf8mb4) as parent_short_description,
                    CAST(product_text.composition AS CHAR CHARACTER SET utf8mb4) as composition,
                    CAST(product_text.olfactive_families AS CHAR CHARACTER SET utf8mb4) as olfactive_families,
                    CAST(product_text.product_benefit AS CHAR CHARACTER SET utf8mb4) as product_benefit,
                    ROUND(product_decimal.price, 2) as price,
                    ROUND(product_decimal.special_price, 2) as special_price,
                    ROUND(product_decimal.cost, 2) as cost,
                    ROUND(product_decimal.pvc, 2) as pvc,
                    ROUND(product_decimal.prix_achat_ht, 2) as prix_achat_ht,
                    ROUND(product_decimal.prix_us, 2) as prix_us,
                    product_int.status as status,
                    product_int.color as color,
                    product_int.capacity as capacity,
                    product_int.product_type as product_type,
                    product_media.media_gallery as media_gallery,
                    CAST(product_categorie.name AS CHAR CHARACTER SET utf8mb4) as categorie,
                    REPLACE(product_categorie.name, ' > ', ',') as tags,
                    stock_item.qty as quatity,
                    stock_status.stock_status as quatity_status,
                    options.configurable_product_id as configurable_product_id,
                    parent_child_table.parent_id as parent_id,
                    options.attribute_code as option_name,
                    options.attribute_value as option_value
                FROM catalog_product_entity as produit
                LEFT JOIN catalog_product_relation as parent_child_table ON parent_child_table.child_id = produit.entity_id 
                LEFT JOIN catalog_product_super_link as cpsl ON cpsl.product_id = produit.entity_id 
                LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
                LEFT JOIN product_text ON product_text.entity_id = produit.entity_id 
                LEFT JOIN product_decimal ON product_decimal.entity_id = produit.entity_id
                LEFT JOIN product_int ON product_int.entity_id = produit.entity_id
                LEFT JOIN product_media ON product_media.entity_id = produit.entity_id
                LEFT JOIN product_categorie ON product_categorie.entity_id = produit.entity_id 
                LEFT JOIN cataloginventory_stock_item AS stock_item ON stock_item.product_id = produit.entity_id 
                LEFT JOIN cataloginventory_stock_status AS stock_status ON stock_item.product_id = stock_status.product_id 
                LEFT JOIN option_super_attribut AS options ON options.simple_product_id = produit.entity_id 
                LEFT JOIN eav_attribute_set AS eas ON produit.attribute_set_id = eas.attribute_set_id 
                LEFT JOIN catalog_product_entity as produit_parent ON parent_child_table.parent_id = produit_parent.entity_id 
                LEFT JOIN product_char as product_parent_char ON product_parent_char.entity_id = produit_parent.entity_id
                LEFT JOIN product_text as product_parent_text ON product_parent_text.entity_id = produit_parent.entity_id 
                WHERE product_int.status >= 0 $subQuery
                ORDER BY product_char.entity_id DESC
                LIMIT ? OFFSET ?
            ";

            $params[] = $this->perPage;
            $params[] = $offset;

            $result = DB::connection('mysqlMagento')->select($dataQuery, $params);

            return [
                "total_item" => $total,
                "per_page" => $this->perPage,
                "total_page" => $nbPage,
                "current_page" => $page,
                "data" => $result,
                "cached_at" => now()->toDateTimeString(),
                "cache_key" => $this->getCacheKey()
            ];

        } catch (\Throwable $e) {
            Log::error('Error fetching products: ' . $e->getMessage());
            
            return [
                "total_item" => 0,
                "per_page" => $this->perPage,
                "total_page" => 0,
                "current_page" => 1,
                "data" => [],
                "error" => $e->getMessage()
            ];
        }
    }

    public function render()
    {
        $productsData = $this->getProducts();
        $cacheStats = $this->getCacheStats();
        
        return view('livewire.boutiques.boutique', [
            'products' => $productsData['data'],
            'totalItems' => $productsData['total_item'],
            'totalPages' => $productsData['total_page'],
            'currentPage' => $productsData['current_page'],
            'cachedAt' => $productsData['cached_at'] ?? null,
            'cacheKey' => $productsData['cache_key'] ?? null,
            'cacheStats' => $cacheStats
        ]);
    }
}