<?php

namespace App\Livewire\Boutiques;

use Livewire\Volt\Component;
use Livewire\Attributes\Url;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

new class extends Component {
    // Variables pour les filtres
    #[Url]
    public $search = '';
    
    #[Url]
    public $filterName = '';
    
    #[Url]
    public $filterMarque = '';
    
    #[Url]
    public $filterType = '';
    
    #[Url]
    public $filterCapacity = '';
    
    #[Url]
    public $filterEAN = '';
    
    // Variables pour l'infinity scroll
    public $page = 1;
    public $perPage = 30;
    public $hasMore = true;
    public $loading = false;
    public $allProducts = [];
    public $totalItems = 0;
    public $totalPages = 0;
    
    // Produits sélectionnés
    public $selectedProducts = [];
    
    // Variables pour les filtres UI
    public $showFilters = false;
    public $showCacheMenu = false;
    
    // Durée du cache en secondes (1 heure)
    protected $cacheTTL = 3600;
    
    // Préfixe pour les clés de cache
    protected $cachePrefix = 'boutique_volt';
    
    public function mount()
    {
        // Charger la première page au montage
        $this->loadMore();
    }
    
    public function updated($property)
    {
        // Reset les données quand un filtre change
        if (in_array($property, ['search', 'filterName', 'filterMarque', 'filterType', 'filterEAN', 'filterCapacity'])) {
            $this->resetData();
        }
    }
    
    protected function resetData()
    {
        $this->page = 1;
        $this->hasMore = true;
        $this->allProducts = [];
        $this->loading = false;
        $this->selectedProducts = [];
        $this->loadMore();
    }
    
    /**
     * Génère une clé de cache unique basée sur les filtres
     */
    protected function getCacheKey($type, ...$params)
    {
        $filters = [
            'search' => $this->search,
            'name' => $this->filterName,
            'marque' => $this->filterMarque,
            'type' => $this->filterType,
            'ean' => $this->filterEAN,
            'capacity' => $this->filterCapacity,
        ];
        
        $filterHash = md5(json_encode($filters));
        
        return sprintf(
            '%s:%s:%s:%s',
            $this->cachePrefix,
            $type,
            $filterHash,
            implode(':', $params)
        );
    }
    
    /**
     * Charge plus de produits pour l'infinity scroll
     */
    #[On('load-more')]
    public function loadMore()
    {
        if ($this->loading || !$this->hasMore) {
            return;
        }
        
        $this->loading = true;
        
        try {
            // Récupérer les produits pour la page actuelle
            $productsData = $this->getListProduct($this->page);
            
            // Convertir les objets en tableaux pour éviter les problèmes de sérialisation
            $newProducts = array_map(function($product) {
                return (array) $product;
            }, $productsData['data']);
            
            // Ajouter les nouveaux produits à la liste existante
            $this->allProducts = array_merge($this->allProducts, $newProducts);
            
            // Mettre à jour les totaux
            $this->totalItems = $productsData['total_item'];
            $this->totalPages = $productsData['total_page'];
            
            // Vérifier s'il y a plus de pages
            $this->hasMore = $this->page < $productsData['total_page'];
            
            // Incrémenter la page pour la prochaine requête
            if ($this->hasMore) {
                $this->page++;
            }
        } catch (\Exception $e) {
            Log::error('Error loading more products: ' . $e->getMessage());
            $this->hasMore = false;
        } finally {
            $this->loading = false;
        }
    }
    
    /**
     * Récupère la liste des produits avec pagination
     */
    public function getListProduct($page = 1)
    {
        $cacheKey = $this->getCacheKey('products', $page, $this->perPage);
        
        return Cache::remember($cacheKey, $this->cacheTTL, function () use ($page) {
            return $this->fetchProductsFromDatabase($page);
        });
    }
    
    /**
     * Récupère les produits depuis la base de données
     */
    protected function fetchProductsFromDatabase($page = 1)
    {
        try {
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
                $params[] = '%' . $this->filterName . '%';
            }
            
            if (!empty($this->filterMarque)) {
                $subQuery .= " AND SUBSTRING_INDEX(product_char.name, ' - ', 1) LIKE ? ";
                $params[] = '%' . $this->filterMarque . '%';
            }
            
            if (!empty($this->filterType)) {
                $subQuery .= " AND SUBSTRING_INDEX(eas.attribute_set_name, '_', -1) LIKE ? ";
                $params[] = '%' . $this->filterType . '%';
            }
            
            if (!empty($this->filterEAN)) {
                $subQuery .= " AND produit.sku LIKE ? ";
                $params[] = '%' . $this->filterEAN . '%';
            }
            
            // Filtre pour prix > 0
            $subQuery .= " AND product_decimal.price > 0 ";
            
            // Total count
            $total = $this->getProductCount($subQuery, $params);
            $nbPage = ceil($total / $this->perPage);
            
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
                    stock_item.qty as quantity,
                    stock_status.stock_status as quantity_status,
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
    
    /**
     * Récupère le nombre total de produits
     */
    protected function getProductCount($subQuery, $params)
    {
        $countCacheKey = $this->getCacheKey('count', md5($subQuery . serialize($params)));
        
        return Cache::remember($countCacheKey, $this->cacheTTL, function () use ($subQuery, $params) {
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
    }
    
    // Gestion des sélections
    public function toggleProduct($productId)
    {
        if (in_array($productId, $this->selectedProducts)) {
            $this->selectedProducts = array_values(array_diff($this->selectedProducts, [$productId]));
        } else {
            $this->selectedProducts[] = $productId;
        }
    }
    
    public function selectAllVisible()
    {
        $visibleIds = collect($this->allProducts)->pluck('id')->toArray();
        $this->selectedProducts = array_values(array_unique(array_merge($this->selectedProducts, $visibleIds)));
    }
    
    public function deselectAll()
    {
        $this->selectedProducts = [];
    }
    
    public function toggleAllVisible()
    {
        $visibleIds = collect($this->allProducts)->pluck('id')->toArray();
        $allSelected = count(array_intersect($this->selectedProducts, $visibleIds)) === count($visibleIds);
        
        if ($allSelected) {
            // Déselectionner tous les visibles
            $this->selectedProducts = array_values(array_diff($this->selectedProducts, $visibleIds));
        } else {
            // Sélectionner tous les visibles
            $this->selectedProducts = array_values(array_unique(array_merge($this->selectedProducts, $visibleIds)));
        }
    }
    
    // Gestion du cache
    public function clearCurrentCache()
    {
        $cacheKey = $this->getCacheKey('products', $this->page, $this->perPage);
        Cache::forget($cacheKey);
        
        $countKey = $this->getCacheKey('count', $this->page, $this->perPage);
        Cache::forget($countKey);
        
        $this->dispatch('cache-cleared', message: 'Cache de la page courante vidé');
    }
    
    public function clearFilterCache()
    {
        $pattern = $this->getCachePattern();
        $this->flushCacheByPattern($pattern);
        
        $this->dispatch('cache-cleared', message: 'Cache des filtres actuels vidé');
        $this->resetData();
    }
    
    public function clearAllCache()
    {
        $pattern = $this->cachePrefix . ':*';
        $this->flushCacheByPattern($pattern);
        
        $this->dispatch('cache-cleared', message: 'Tout le cache des produits vidé');
        $this->resetData();
    }
    
    protected function getCachePattern()
    {
        $filters = [
            'search' => $this->search,
            'name' => $this->filterName,
            'marque' => $this->filterMarque,
            'type' => $this->filterType,
            'ean' => $this->filterEAN,
            'capacity' => $this->filterCapacity,
        ];
        
        $filterHash = md5(json_encode($filters));
        
        return sprintf('%s:*:%s:*', $this->cachePrefix, $filterHash);
    }
    
    protected function flushCacheByPattern($pattern)
    {
        if (config('cache.default') !== 'redis') {
            Cache::flush();
            return;
        }
        
        try {
            $redis = Cache::getRedis();
            $keys = $redis->keys($pattern);
            
            if (!empty($keys)) {
                $redis->del($keys);
            }
        } catch (\Exception $e) {
            Log::error('Redis pattern flush error: ' . $e->getMessage());
        }
    }
    
    // Actions de la liste
    public function save()
    {
        if (empty($this->selectedProducts)) {
            $this->dispatch('notify', type: 'error', message: 'Veuillez sélectionner au moins un produit');
            return;
        }
        
        // Sauvegarder les produits sélectionnés
        session()->put('comparison_list', $this->selectedProducts);
        session()->flash('success', count($this->selectedProducts) . ' produits ajoutés à la liste de comparaison');
        
        // Redirection vers la page de comparaison
        // return $this->redirect('/comparaison', navigate: true);
    }
    
    public function cancel()
    {
        $this->selectedProducts = [];
        // return $this->redirect('/boutique', navigate: true);
    }
}; ?>

<div class="mx-auto max-w-7xl" 
    x-data="{
        observer: null,
        init() {
            // Observer pour l'infinity scroll
            this.observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting && @js($this->hasMore) && !@js($this->loading)) {
                        $wire.dispatch('load-more');
                    }
                });
            }, {
                root: null,
                rootMargin: '200px',
                threshold: 0.1
            });
            
            // Observer l'élément de déclenchement
            this.$nextTick(() => {
                const trigger = this.$refs.loadTrigger;
                if (trigger) {
                    this.observer.observe(trigger);
                }
            });
        },
        destroy() {
            if (this.observer) {
                this.observer.disconnect();
            }
        }
    }"
>
    <x-header title="Créer la liste à comparer" separator>
        <x-slot:middle class="!justify-end">
            <!-- Barre de recherche -->
            <div class="form-control w-64">
                <div class="input-group">
                    <input 
                        type="text" 
                        placeholder="Rechercher..." 
                        class="input input-bordered w-full" 
                        wire:model.live.debounce.500ms="search"
                    />
                    <button class="btn btn-square" wire:click="$refresh">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </button>
                </div>
            </div>
        </x-slot:middle>
        <x-slot:actions>
            <!-- Bouton des filtres -->
            <x-button 
                class="btn-secondary" 
                label="Filtres" 
                wire:click="$toggle('showFilters')"
            />
            
            <!-- Menu cache -->
            <div class="relative" x-data="{ open: false }">
                <x-button 
                    class="btn-ghost" 
                    label="Cache" 
                    @click="open = !open"
                />
                
                <!-- Dropdown cache -->
                <div class="absolute right-0 mt-2 w-48 bg-base-100 rounded-md shadow-lg z-50" 
                     x-show="open" 
                     x-cloak
                     @click.outside="open = false">
                    <div class="py-1">
                        <button class="w-full text-left px-4 py-2 hover:bg-base-200" 
                                wire:click="clearCurrentCache"
                                @click="open = false">
                            Vider cache page
                        </button>
                        <button class="w-full text-left px-4 py-2 hover:bg-base-200" 
                                wire:click="clearFilterCache"
                                @click="open = false">
                            Vider cache filtres
                        </button>
                        <button class="w-full text-left px-4 py-2 hover:bg-base-200" 
                                wire:click="clearAllCache"
                                @click="open = false">
                            Vider tout le cache
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Compteur de sélection -->
            @if(count($selectedProducts) > 0)
            <div class="ml-2 px-3 py-1 bg-primary text-primary-content rounded-lg">
                <span>{{ count($selectedProducts) }} sélectionnés</span>
            </div>
            @endif
        </x-slot:actions>
    </x-header>

    <!-- Boutons de gestion des sélections -->
    <div class="flex gap-2 mb-4">
        <button class="btn btn-sm btn-primary" wire:click="selectAllVisible">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            Tout sélectionner (visible)
        </button>
        <button class="btn btn-sm btn-ghost" wire:click="deselectAll">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
            Tout désélectionner
        </button>
        <div class="ml-auto text-sm text-gray-500">
            <span>{{ $totalItems }} produits au total</span>
        </div>
    </div>

    <!-- Tableau des produits -->
    <div class="overflow-x-auto rounded-box border border-base-content/5 bg-base-100">
        <table class="table">
            <thead>
                <tr>
                    <th class="w-12">
                        <input type="checkbox" 
                               class="checkbox checkbox-sm" 
                               wire:click="toggleAllVisible"
                               @checked="count($allProducts) > 0 && count(array_intersect($selectedProducts, collect($allProducts)->pluck('id')->toArray())) === count($allProducts)">
                    </th>
                    <th>Image</th>
                    <th>Nom</th>
                    <th>Marque</th>
                    <th>Type</th>
                    <th>SKU</th>
                    <th>Prix</th>
                    <th>Stock</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($allProducts as $index => $product)
                    <tr wire:key="product-{{ $product['id'] ?? $index }}">
                        <td>
                            <input type="checkbox" 
                                   class="checkbox checkbox-sm" 
                                   wire:model.live="selectedProducts"
                                   value="{{ $product['id'] }}">
                        </td>
                        <td>
                            @if(!empty($product['thumbnail']))
                                <img src="https://www.cosma-parfumeries.com/media/catalog/product/{{ $product['thumbnail'] }}"
                                     alt="{{ $product['title'] ?? '' }}"
                                     class="h-12 w-12 object-cover rounded">
                            @else
                                <div class="h-12 w-12 bg-gray-200 rounded flex items-center justify-center">
                                    <span class="text-xs text-gray-400">No img</span>
                                </div>
                            @endif
                        </td>
                        <td>
                            <div class="font-medium text-sm">{{ $product['title'] ?? 'N/A' }}</div>
                            @if(!empty($product['parent_title']))
                                <div class="text-xs text-gray-500">Parent: {{ $product['parent_title'] }}</div>
                            @endif
                        </td>
                        <td>
                            <span class="badge badge-outline">{{ $product['vendor'] ?? 'N/A' }}</span>
                        </td>
                        <td>
                            <span class="badge badge-ghost">{{ $product['type'] ?? 'N/A' }}</span>
                        </td>
                        <td>
                            <code class="text-xs">{{ $product['sku'] ?? 'N/A' }}</code>
                            @if(!empty($product['parkode']))
                                <div class="text-xs text-gray-500">{{ $product['parkode'] }}</div>
                            @endif
                        </td>
                        <td>
                            <div class="font-bold">{{ number_format($product['price'] ?? 0, 2) }} €</div>
                            @if(!empty($product['special_price']))
                                <div class="text-xs line-through text-gray-500">
                                    {{ number_format($product['special_price'], 2) }} €
                                </div>
                            @endif
                        </td>
                        <td>
                            @if(!empty($product['quantity_status']))
                                <span class="badge badge-success">Dispo</span>
                            @else
                                <span class="badge badge-error">Rupture</span>
                            @endif
                            <div class="text-xs text-gray-500">Qty: {{ $product['quantity'] ?? 0 }}</div>
                        </td>
                        <td>
                            <button class="btn btn-xs btn-outline" 
                                    wire:click="toggleProduct({{ $product['id'] }})"
                                    wire:confirm="Changer la sélection de ce produit ?">
                                @if(in_array($product['id'], $selectedProducts))
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                    Retirer
                                @else
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                    </svg>
                                    Ajouter
                                @endif
                            </button>
                        </td>
                    </tr>
                @empty
                    @if(!$loading)
                    <tr>
                        <td colspan="9" class="text-center py-8 text-gray-500">
                            Aucun produit trouvé. Essayez de modifier vos filtres.
                        </td>
                    </tr>
                    @endif
                @endforelse
            </tbody>
        </table>
        
        <!-- Élément de déclenchement pour l'infinity scroll -->
        <div x-ref="loadTrigger" class="py-8 text-center">
            @if($loading)
                <div class="flex justify-center items-center">
                    <span class="loading loading-spinner loading-lg"></span>
                    <span class="ml-2">Chargement des produits...</span>
                </div>
            @elseif($hasMore)
                <p class="text-gray-500">Faites défiler pour charger plus de produits</p>
            @elseif(count($allProducts) > 0)
                <p class="text-gray-500">Tous les produits sont chargés</p>
            @endif
        </div>
    </div>

    <!-- Panneau des filtres -->
    <div x-show="$wire.showFilters" 
         x-cloak
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="translate-x-full"
         x-transition:enter-end="translate-x-0"
         x-transition:leave="transition ease-in duration-300"
         x-transition:leave-start="translate-x-0"
         x-transition:leave-end="translate-x-full"
         class="fixed right-0 top-0 h-full w-96 bg-base-100 shadow-xl z-50 p-6 overflow-y-auto">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-lg font-bold">Filtres avancés</h3>
            <button wire:click="$set('showFilters', false)" class="btn btn-ghost btn-sm">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        
        <div class="space-y-4">
            <div class="form-control">
                <label class="label">
                    <span class="label-text">Nom du produit</span>
                </label>
                <input type="text" 
                       class="input input-bordered" 
                       wire:model.live.debounce.500ms="filterName"
                       placeholder="Filtrer par nom...">
            </div>
            
            <div class="form-control">
                <label class="label">
                    <span class="label-text">Marque</span>
                </label>
                <input type="text" 
                       class="input input-bordered" 
                       wire:model.live.debounce.500ms="filterMarque"
                       placeholder="Filtrer par marque...">
            </div>
            
            <div class="form-control">
                <label class="label">
                    <span class="label-text">Type de produit</span>
                </label>
                <input type="text" 
                       class="input input-bordered" 
                       wire:model.live.debounce.500ms="filterType"
                       placeholder="Filtrer par type...">
            </div>
            
            <div class="form-control">
                <label class="label">
                    <span class="label-text">EAN / SKU</span>
                </label>
                <input type="text" 
                       class="input input-bordered" 
                       wire:model.live.debounce.500ms="filterEAN"
                       placeholder="Filtrer par EAN ou SKU...">
            </div>
            
            <div class="form-control">
                <label class="label">
                    <span class="label-text">Capacité</span>
                </label>
                <input type="text" 
                       class="input input-bordered" 
                       wire:model.live.debounce.500ms="filterCapacity"
                       placeholder="Filtrer par capacité...">
            </div>
        </div>
        
        <div class="mt-8 flex gap-2">
            <button class="btn btn-primary flex-1" wire:click="$refresh">
                Appliquer les filtres
            </button>
            <button class="btn btn-ghost" wire:click="resetData">
                Réinitialiser
            </button>
        </div>
    </div>

    <!-- Overlay pour les filtres -->
    <div x-show="$wire.showFilters" 
         x-cloak
         @click="$wire.showFilters = false"
         class="fixed inset-0 bg-black bg-opacity-50 z-40">
    </div>

    <!-- Actions finales -->
    <div class="mt-6 flex justify-between items-center">
        <div class="text-sm text-gray-500">
            {{ count($allProducts) }} produits chargés sur {{ $totalItems }} au total
        </div>
        <div class="flex gap-2">
            <x-button 
                class="btn-error" 
                label="Annuler" 
                wire:click="cancel"
                wire:confirm="Êtes-vous sûr de vouloir annuler ? Tous les produits sélectionnés seront perdus."
            />
            <x-button 
                class="btn-primary" 
                label="Valider la sélection" 
                wire:click="save"
                :disabled="count($selectedProducts) === 0"
            />
        </div>
    </div>
</div>

@script
<script>
    // Écouter les événements de cache
    Livewire.on('cache-cleared', (event) => {
        // Afficher une notification
        if (event.message) {
            alert(event.message);
        }
    });
    
    // Écouter les notifications
    Livewire.on('notify', (event) => {
        if (event.type === 'error') {
            alert(event.message);
        }
    });
</script>
@endscript