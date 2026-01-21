<?php

namespace App\Livewire\Boutiques;

use Livewire\Volt\Component;
use Livewire\Attributes\Url;
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
    public function loadMore()
    {
        if ($this->loading || !$this->hasMore) {
            return;
        }
        
        $this->loading = true;
        
        try {
            // Récupérer les produits pour la page actuelle
            $productsData = $this->getListProduct($this->page);
            
            // Ajouter les nouveaux produits à la liste existante
            $this->allProducts = array_merge($this->allProducts, $productsData['data']);
            
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
                $params[] = "%{$this->filterName}%";
            }
            
            if (!empty($this->filterMarque)) {
                $subQuery .= " AND SUBSTRING_INDEX(product_char.name, ' - ', 1) LIKE ? ";
                $params[] = "%{$this->filterMarque}%";
            }
            
            if (!empty($this->filterType)) {
                $subQuery .= " AND SUBSTRING_INDEX(eas.attribute_set_name, '_', -1) LIKE ? ";
                $params[] = "%{$this->filterType}%";
            }
            
            if (!empty($this->filterEAN)) {
                $subQuery .= " AND produit.sku LIKE ? ";
                $params[] = "%{$this->filterEAN}%";
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
            $this->selectedProducts = array_diff($this->selectedProducts, [$productId]);
        } else {
            $this->selectedProducts[] = $productId;
        }
    }
    
    public function selectAllVisible()
    {
        $visibleIds = collect($this->allProducts)->pluck('id')->toArray();
        $this->selectedProducts = array_unique(array_merge($this->selectedProducts, $visibleIds));
    }
    
    public function deselectAll()
    {
        $this->selectedProducts = [];
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
            console.log('🚀 Initialisation de l\'infinity scroll');
            
            // Configuration de l'observer
            this.observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    console.log('📊 Observer triggered:', {
                        isIntersecting: entry.isIntersecting,
                        hasMore: @js($hasMore),
                        loading: @js($loading)
                    });
                    
                    if (entry.isIntersecting && !@this.loading && @this.hasMore) {
                        console.log('✅ Chargement de plus de produits...');
                        @this.loadMore();
                    }
                });
            }, {
                root: null,
                rootMargin: '200px', // Augmenté pour détecter plus tôt
                threshold: 0
            });
            
            // Observer l'élément après le rendu
            this.$nextTick(() => {
                const trigger = document.getElementById('loadTrigger');
                if (trigger) {
                    console.log('✅ Observer attaché à l\'élément trigger');
                    this.observer.observe(trigger);
                } else {
                    console.error('❌ Élément trigger non trouvé');
                }
            });
            
            // Écouter les événements de cache
            Livewire.on('cache-cleared', (data) => {
                this.showNotification(data.message, 'success');
            });
            
            Livewire.on('notify', (data) => {
                this.showNotification(data.message, data.type);
            });
        },
        destroy() {
            if (this.observer) {
                this.observer.disconnect();
                console.log('🛑 Observer déconnecté');
            }
        },
        showNotification(message, type = 'info') {
            // Créer une notification toast
            const toast = document.createElement('div');
            toast.className = `alert alert-${type} shadow-lg mb-2 transform transition-all duration-300 translate-x-full`;
            toast.innerHTML = `
                <div>
                    ${type === 'success' ? '✅' : type === 'error' ? '❌' : type === 'warning' ? '⚠️' : 'ℹ️'}
                    <span>${message}</span>
                </div>
                <button class="btn btn-sm btn-ghost" onclick="this.parentElement.remove()">×</button>
            `;
            
            const container = document.getElementById('notification-container');
            if (!container) {
                // Créer le conteneur s'il n'existe pas
                const newContainer = document.createElement('div');
                newContainer.id = 'notification-container';
                newContainer.className = 'toast toast-top toast-end z-50';
                document.body.appendChild(newContainer);
                newContainer.appendChild(toast);
            } else {
                container.appendChild(toast);
            }
            
            // Animation d'entrée
            setTimeout(() => {
                toast.classList.remove('translate-x-full');
                toast.classList.add('translate-x-0');
            }, 10);
            
            // Suppression automatique après 5 secondes
            setTimeout(() => {
                toast.classList.add('translate-x-full');
                setTimeout(() => toast.remove(), 300);
            }, 5000);
        }
    }"
>
    <x-header title="Créer la liste à comparer" separator>
        <x-slot:middle class="!justify-end">
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
            <x-button 
                class="btn-secondary" 
                label="Filtres" 
                wire:click="$toggle('showFilters')"
            />
            
            <div class="relative">
                <x-button 
                    class="btn-ghost" 
                    label="Cache" 
                    wire:click="$toggle('showCacheMenu')"
                />
                
                <div class="absolute right-0 mt-2 w-48 bg-base-100 rounded-md shadow-lg z-50" 
                     x-show="$wire.showCacheMenu" 
                     x-cloak
                     @click.outside="$wire.showCacheMenu = false">
                    <div class="py-1">
                        <button class="w-full text-left px-4 py-2 hover:bg-base-200" 
                                wire:click="clearCurrentCache(); $wire.showCacheMenu = false">
                            Vider cache page
                        </button>
                        <button class="w-full text-left px-4 py-2 hover:bg-base-200" 
                                wire:click="clearFilterCache(); $wire.showCacheMenu = false">
                            Vider cache filtres
                        </button>
                        <button class="w-full text-left px-4 py-2 hover:bg-base-200" 
                                wire:click="clearAllCache(); $wire.showCacheMenu = false">
                            Vider tout le cache
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="ml-2 px-3 py-1 bg-primary text-primary-content rounded-lg" 
                 x-show="$wire.selectedProducts.length > 0"
                 x-cloak>
                <span x-text="$wire.selectedProducts.length + ' sélectionnés'"></span>
            </div>
        </x-slot:actions>
    </x-header>

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

    <div class="overflow-x-auto rounded-box border border-base-content/5 bg-base-100">
        <table class="table">
            <thead>
                <tr>
                    <th class="w-12">
                        <input type="checkbox" 
                               class="checkbox checkbox-sm" 
                               @change="$wire.selectAllVisible()"
                               :checked="$wire.allProducts.length > 0 && $wire.selectedProducts.length === $wire.allProducts.length">
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
                @forelse($allProducts as $product)
                    <tr wire:key="product-{{ $product->id }}">
                        <td>
                            <input type="checkbox" 
                                   class="checkbox checkbox-sm" 
                                   wire:model.live="selectedProducts"
                                   value="{{ $product->id }}">
                        </td>
                        <td>
                            @if($product->thumbnail)
                                <img src="https://www.cosma-parfumeries.com/media/catalog/product/{{ $product->thumbnail }}"
                                     alt="{{ $product->title }}"
                                     class="h-12 w-12 object-cover rounded">
                            @else
                                <div class="h-12 w-12 bg-gray-200 rounded flex items-center justify-center">
                                    <span class="text-xs text-gray-400">No img</span>
                                </div>
                            @endif
                        </td>
                        <td>
                            <div class="font-medium text-sm">{{ $product->title }}</div>
                            @if($product->parent_title)
                                <div class="text-xs text-gray-500">Parent: {{ $product->parent_title }}</div>
                            @endif
                        </td>
                        <td>
                            <span class="badge badge-outline">{{ $product->vendor }}</span>
                        </td>
                        <td>
                            <span class="badge badge-ghost">{{ $product->type }}</span>
                        </td>
                        <td>
                            <code class="text-xs">{{ $product->sku }}</code>
                            <div class="text-xs text-gray-500">{{ $product->parkode }}</div>
                        </td>
                        <td>
                            <div class="font-bold">{{ number_format($product->price, 2) }} €</div>
                            @if($product->special_price)
                                <div class="text-xs line-through text-gray-500">
                                    {{ number_format($product->special_price, 2) }} €
                                </div>
                            @endif
                        </td>
                        <td>
                            @if($product->quantity_status)
                                <span class="badge badge-success">Dispo</span>
                            @else
                                <span class="badge badge-error">Rupture</span>
                            @endif
                            <div class="text-xs text-gray-500">Qty: {{ $product->quantity ?? 0 }}</div>
                        </td>
                        <td>
                            <button class="btn btn-xs btn-outline" 
                                    wire:click="toggleProduct({{ $product->id }})"
                                    wire:confirm="Changer la sélection de ce produit ?">
                                @if(in_array($product->id, $selectedProducts))
                                    Retirer
                                @else
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
        <div id="loadTrigger" class="py-8 text-center">
            @if($loading)
                <div class="flex justify-center items-center">
                    <span class="loading loading-spinner loading-lg"></span>
                    <span class="ml-2">Chargement des produits...</span>
                </div>
            @elseif($hasMore)
                <p class="text-gray-500">Faites défiler pour charger plus de produits</p>
            @elseif(count($allProducts) > 0)
                <p class="text-gray-500 font-semibold">✅ Tous les produits sont chargés ({{ count($allProducts) }}/{{ $totalItems }})</p>
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
            
            <!-- Informations sur les filtres actifs -->
            <div class="mt-6 p-4 bg-base-200 rounded-lg">
                <h4 class="font-semibold mb-2">Filtres actifs :</h4>
                <div class="flex flex-wrap gap-2">
                    @if($search)
                        <span class="badge badge-primary">
                            Recherche: {{ $search }}
                            <button class="ml-1" wire:click="$set('search', '')">×</button>
                        </span>
                    @endif
                    @if($filterName)
                        <span class="badge badge-secondary">
                            Nom: {{ $filterName }}
                            <button class="ml-1" wire:click="$set('filterName', '')">×</button>
                        </span>
                    @endif
                    @if($filterMarque)
                        <span class="badge badge-accent">
                            Marque: {{ $filterMarque }}
                            <button class="ml-1" wire:click="$set('filterMarque', '')">×</button>
                        </span>
                    @endif
                    @if($filterType)
                        <span class="badge badge-info">
                            Type: {{ $filterType }}
                            <button class="ml-1" wire:click="$set('filterType', '')">×</button>
                        </span>
                    @endif
                    @if($filterEAN)
                        <span class="badge badge-warning">
                            EAN: {{ $filterEAN }}
                            <button class="ml-1" wire:click="$set('filterEAN', '')">×</button>
                        </span>
                    @endif
                    @if($filterCapacity)
                        <span class="badge badge-success">
                            Capacité: {{ $filterCapacity }}
                            <button class="ml-1" wire:click="$set('filterCapacity', '')">×</button>
                        </span>
                    @endif
                    @if($search || $filterName || $filterMarque || $filterType || $filterEAN || $filterCapacity)
                        <button class="btn btn-xs btn-ghost" wire:click="resetData">
                            Tout effacer
                        </button>
                    @else
                        <span class="text-sm text-gray-500">Aucun filtre actif</span>
                    @endif
                </div>
            </div>
            
            <!-- Statistiques de cache -->
            <div class="mt-6 p-4 bg-base-200 rounded-lg">
                <h4 class="font-semibold mb-2">Statistiques :</h4>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span>Produits chargés :</span>
                        <span class="font-semibold">{{ count($allProducts) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Total produits :</span>
                        <span class="font-semibold">{{ $totalItems }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Pages chargées :</span>
                        <span class="font-semibold">{{ $page }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Statut :</span>
                        <span class="font-semibold {{ $loading ? 'text-warning' : ($hasMore ? 'text-info' : 'text-success') }}">
                            @if($loading)
                                Chargement...
                            @elseif($hasMore)
                                Plus de données disponibles
                            @else
                                Tous chargés
                            @endif
                        </span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="mt-8 flex gap-2">
            <button class="btn btn-primary flex-1" wire:click="$refresh">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
                Appliquer les filtres
            </button>
            <button class="btn btn-ghost" wire:click="resetData">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
                Réinitialiser
            </button>
        </div>
    </div>

    <!-- Overlay pour les filtres -->
    <div x-show="$wire.showFilters" 
         x-cloak
         @click="$wire.showFilters = false"
         class="fixed inset-0 bg-black bg-opacity-50 z-40"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-300"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">
    </div>

    <!-- Bouton flottant pour ouvrir les filtres -->
    <button class="fixed bottom-6 right-6 btn btn-primary btn-circle shadow-lg z-30"
            x-show="!$wire.showFilters"
            @click="$wire.showFilters = true">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
        </svg>
    </button>

    <!-- Bouton flottant pour remonter en haut -->
    <button class="fixed bottom-6 left-6 btn btn-circle btn-outline shadow-lg z-30"
            x-show="window.scrollY > 300"
            x-transition
            @click="window.scrollTo({ top: 0, behavior: 'smooth' })">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18" />
        </svg>
    </button>

    <!-- Actions finales -->
    <div class="mt-8 p-6 bg-base-100 rounded-box border border-base-content/10">
        <div class="flex flex-col md:flex-row justify-between items-center gap-4">
            <!-- Résumé de la sélection -->
            <div class="flex-1">
                <h3 class="font-bold text-lg mb-2">Résumé de la sélection</h3>
                <div class="space-y-2">
                    <div class="flex items-center gap-2">
                        <span class="badge badge-primary">
                            {{ count($selectedProducts) }} produits sélectionnés
                        </span>
                        <span class="text-sm text-gray-500">
                            sur {{ count($allProducts) }} produits chargés
                        </span>
                    </div>
                    
                    @if(count($selectedProducts) > 0)
                        <div class="mt-2">
                            <details class="collapse collapse-arrow border border-base-300">
                                <summary class="collapse-title text-sm font-medium">
                                    Voir les produits sélectionnés
                                </summary>
                                <div class="collapse-content">
                                    <div class="max-h-48 overflow-y-auto mt-2">
                                        <ul class="space-y-1">
                                            @foreach($allProducts->whereIn('id', $selectedProducts)->take(10) as $product)
                                                <li class="flex items-center justify-between text-sm py-1 border-b border-base-200">
                                                    <div class="flex items-center gap-2">
                                                        @if($product->thumbnail)
                                                            <img src="https://www.cosma-parfumeries.com/media/catalog/product/{{ $product->thumbnail }}"
                                                                 alt="{{ $product->title }}"
                                                                 class="h-8 w-8 object-cover rounded">
                                                        @endif
                                                        <span class="truncate max-w-xs">{{ $product->title }}</span>
                                                    </div>
                                                    <span class="font-semibold">{{ number_format($product->price, 2) }} €</span>
                                                </li>
                                            @endforeach
                                            @if(count($selectedProducts) > 10)
                                                <li class="text-center text-sm text-gray-500 py-2">
                                                    ... et {{ count($selectedProducts) - 10 }} autres produits
                                                </li>
                                            @endif
                                        </ul>
                                    </div>
                                </div>
                            </details>
                        </div>
                    @endif
                </div>
            </div>
            
            <!-- Actions -->
            <div class="flex flex-col sm:flex-row gap-3">
                <x-button 
                    class="btn-error btn-outline" 
                    label="Annuler" 
                    wire:click="cancel"
                    wire:confirm="Êtes-vous sûr de vouloir annuler ? Tous les produits sélectionnés seront perdus."
                />
                
                <x-button 
                    class="btn-primary" 
                    :label="'Valider (' . count($selectedProducts) . ' produits)'" 
                    wire:click="save"
                    :disabled="empty($selectedProducts)"
                />
            </div>
        </div>
        
        <!-- Informations supplémentaires -->
        <div class="mt-6 pt-6 border-t border-base-300">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                <div class="text-center">
                    <div class="stat-title">Produits chargés</div>
                    <div class="stat-value text-primary">{{ count($allProducts) }}</div>
                    <div class="stat-desc">sur {{ $totalItems }} au total</div>
                </div>
                
                <div class="text-center">
                    <div class="stat-title">Sélection</div>
                    <div class="stat-value text-secondary">{{ count($selectedProducts) }}</div>
                    <div class="stat-desc">{{ count($selectedProducts) > 0 ? round((count($selectedProducts) / count($allProducts)) * 100, 1) : 0 }}% des visibles</div>
                </div>
                
                <div class="text-center">
                    <div class="stat-title">Pages</div>
                    <div class="stat-value text-accent">{{ $page }}</div>
                    <div class="stat-desc">sur {{ $totalPages }} pages totales</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Script pour améliorer l'expérience utilisateur -->
    <script>
        document.addEventListener('livewire:init', () => {
            // Forcer le rechargement quand on change de page et qu'on revient
            window.addEventListener('pageshow', (event) => {
                if (event.persisted) {
                    Livewire.dispatch('refresh');
                }
            });
            
            // Gérer le scroll avec le cache
            let lastScrollPosition = 0;
            window.addEventListener('scroll', () => {
                const currentScroll = window.scrollY;
                const scrollDifference = Math.abs(currentScroll - lastScrollPosition);
                
                // Sauvegarder la position toutes les 500px de défilement
                if (scrollDifference > 500) {
                    sessionStorage.setItem('productListScrollPosition', currentScroll);
                    lastScrollPosition = currentScroll;
                }
            });
            
            // Restaurer la position au chargement
            const savedPosition = sessionStorage.getItem('productListScrollPosition');
            if (savedPosition) {
                setTimeout(() => {
                    window.scrollTo(0, parseInt(savedPosition));
                    sessionStorage.removeItem('productListScrollPosition');
                }, 100);
            }
            
            // Améliorer la sélection des checkboxes
            document.addEventListener('click', (e) => {
                if (e.target.matches('input[type="checkbox"]') && e.target.closest('tr')) {
                    const checkbox = e.target;
                    const row = checkbox.closest('tr');
                    if (checkbox.checked) {
                        row.classList.add('bg-primary', 'bg-opacity-10');
                    } else {
                        row.classList.remove('bg-primary', 'bg-opacity-10');
                    }
                }
            });
        });
        
        // Gestion du scroll pour le bouton retour en haut
        window.addEventListener('scroll', () => {
            const scrollTopBtn = document.querySelector('[x-show="window.scrollY > 300"]');
            if (scrollTopBtn) {
                scrollTopBtn.style.display = window.scrollY > 300 ? 'block' : 'none';
            }
        });
    </script>
</div>

<style>
    /* Styles supplémentaires pour améliorer l'interface */
    [x-cloak] { display: none !important; }
    
    .checkbox:checked {
        background-color: hsl(var(--p));
        border-color: hsl(var(--p));
    }
    
    .table tr {
        transition: background-color 0.2s ease;
    }
    
    .table tr:hover {
        background-color: hsl(var(--b2) / 0.5);
    }
    
    .table tr.bg-primary {
        background-color: hsl(var(--p) / 0.1) !important;
    }
    
    .badge {
        transition: all 0.2s ease;
    }
    
    .badge:hover {
        transform: scale(1.05);
    }
    
    /* Animation pour le chargement */
    @keyframes pulse-subtle {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.7; }
    }
    
    .loading {
        animation: pulse-subtle 1.5s ease-in-out infinite;
    }
    
    /* Amélioration du scroll */
    ::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }
    
    ::-webkit-scrollbar-track {
        background: hsl(var(--b2));
        border-radius: 4px;
    }
    
    ::-webkit-scrollbar-thumb {
        background: hsl(var(--n));
        border-radius: 4px;
    }
    
    ::-webkit-scrollbar-thumb:hover {
        background: hsl(var(--p));
    }
    
    /* Animation pour les notifications */
    .alert {
        transition: all 0.3s ease;
    }
    
    /* Styles pour les lignes sélectionnées */
    input[type="checkbox"]:checked + * {
        color: hsl(var(--p));
    }
    
    /* Amélioration de la table */
    .table th {
        background-color: hsl(var(--b1));
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.05em;
    }
    
    .table td {
        vertical-align: middle;
    }
    
    /* Responsive table */
    @media (max-width: 768px) {
        .table {
            display: block;
            overflow-x: auto;
            white-space: nowrap;
        }
        
        .table th:nth-child(3),
        .table td:nth-child(3) {
            min-width: 200px;
        }
        
        .table th:nth-child(6),
        .table td:nth-child(6) {
            min-width: 120px;
        }
    }
</style>