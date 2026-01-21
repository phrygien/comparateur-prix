<?php

use Livewire\Volt\Component;
use Livewire\Attributes\On;

new class extends Component {
    public $page = 1;
    public $perPage = 20;
    public $hasMore = true;
    public $loading = false;
    
    // Filtres
    public $search = '';
    public $filterName = '';
    public $filterMarque = '';
    public $filterType = '';
    public $filterEAN = '';
    
    // Cache
    protected $cacheTTL = 3600;
    
    public function loadMore()
    {
        // Vérification stricte
        if (!$this->hasMore) {
            Log::info('loadMore: Plus de produits à charger');
            return;
        }
        
        if ($this->loading) {
            Log::info('loadMore: Déjà en cours de chargement');
            return;
        }
        
        Log::info('loadMore: Chargement page ' . ($this->page + 1));
        
        $this->page++;
    }
    
    public function updatedSearch()
    {
        $this->resetProducts();
    }
    
    public function updatedFilterName()
    {
        $this->resetProducts();
    }
    
    public function updatedFilterMarque()
    {
        $this->resetProducts();
    }
    
    public function updatedFilterType()
    {
        $this->resetProducts();
    }
    
    public function updatedFilterEAN()
    {
        $this->resetProducts();
    }
    
    protected function resetProducts()
    {
        $this->page = 1;
        $this->hasMore = true;
    }
    
    public function with(): array
    {
        Log::info('with() appelé - Page: ' . $this->page . ', Loading: ' . ($this->loading ? 'true' : 'false'));
        
        $this->loading = true;
        
        try {
            $allProducts = [];
            $totalItems = 0;
            
            // Charger toutes les pages jusqu'à la page actuelle
            for ($i = 1; $i <= $this->page; $i++) {
                $result = $this->fetchProductsFromDatabase($this->search, $i, $this->perPage);
                
                if (isset($result['error'])) {
                    Log::error('Erreur DB: ' . $result['error']);
                    break;
                }
                
                $totalItems = $result['total_item'] ?? 0;
                $newProducts = $result['data'] ?? [];
                
                // Convertir les objets en tableaux
                $newProducts = array_map(fn($p) => (array) $p, $newProducts);
                
                $allProducts = array_merge($allProducts, $newProducts);
                
                // Si moins de produits que demandé, on a atteint la fin
                if (count($newProducts) < $this->perPage) {
                    $this->hasMore = false;
                    break;
                }
            }
            
            // Vérifier si on a atteint la fin
            if (count($allProducts) >= $totalItems) {
                $this->hasMore = false;
            }
            
            Log::info('Produits chargés: ' . count($allProducts) . ' / ' . $totalItems);
            
            // IMPORTANT: On ne remet pas loading à false ici
            // On le fait après un court délai pour que l'animation soit visible
            
            return [
                'products' => $allProducts,
                'totalItems' => $totalItems,
            ];
            
        } catch (\Exception $e) {
            Log::error('Erreur with(): ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            $this->hasMore = false;
            
            return [
                'products' => [],
                'totalItems' => 0,
            ];
        } finally {
            // Remettre loading à false après le rendu
            $this->dispatch('loading-finished');
        }
    }
    
    /**
     * Récupère les produits depuis la base de données
     */
    protected function fetchProductsFromDatabase($search = "", $page = 1, $perPage = null)
    {
        try {
            $offset = ($page - 1) * $perPage;

            $subQuery = "";
            $params = [];

            // Global search
            if (!empty($search)) {
                $searchClean = str_replace("'", "", $search);
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

            // Total count (mis en cache séparément)
            $total = $this->getProductCount($subQuery, $params);
            $nbPage = ceil($total / $perPage);

            if ($page > $nbPage && $nbPage > 0) {
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

            $params[] = $perPage;
            $params[] = $offset;

            $result = DB::connection('mysqlMagento')->select($dataQuery, $params);

            return [
                "total_item" => $total,
                "per_page" => $perPage,
                "total_page" => $nbPage,
                "current_page" => $page,
                "data" => $result,
                "cached_at" => now()->toDateTimeString(),
                "cache_key" => $this->getCacheKey('products', $page, $perPage)
            ];

        } catch (\Throwable $e) {
            Log::error('Error fetching products: ' . $e->getMessage());
            
            return [
                "total_item" => 0,
                "per_page" => $perPage,
                "total_page" => 0,
                "current_page" => 1,
                "data" => [],
                "error" => $e->getMessage()
            ];
        }
    }
    
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
    
    protected function getCacheKey($type, ...$params)
    {
        return "products_{$type}_" . md5(serialize($params));
    }
    
    public function save()
    {
        // Logique de sauvegarde
        $this->dispatch('saved');
    }
    
    public function cancel()
    {
        return redirect()->to('/previous-page');
    }
}; ?>

<div class="mx-auto max-w-5xl">
    <x-header title="Créer la liste à comparer" separator>
        <x-slot:middle class="!justify-end">
            <div class="flex items-center gap-2">
                @if($loading)
                    <span class="loading loading-spinner loading-sm text-primary"></span>
                @endif
                <div class="text-sm text-base-content/70">
                    {{ count($products) }} / {{ $totalItems }} produits
                </div>
            </div>
        </x-slot:middle>
        <x-slot:actions>
            <x-button class="btn-error" label="Annuler" wire:click="cancel"
                wire:confirm="Êtes-vous sûr de vouloir annuler ?" />
            <x-button class="btn-primary" label="Valider" wire:click="save" />
        </x-slot:actions>
    </x-header>

    <!-- Filtres -->
    <div class="mb-4 grid grid-cols-1 md:grid-cols-3 gap-4">
        <x-input 
            label="Recherche globale" 
            wire:model.live.debounce.500ms="search" 
            placeholder="Nom, SKU..."
            icon="o-magnifying-glass"
        />
        
        <x-input 
            label="Nom du produit" 
            wire:model.live.debounce.500ms="filterName" 
            placeholder="Filtrer par nom"
        />
        
        <x-input 
            label="Marque" 
            wire:model.live.debounce.500ms="filterMarque" 
            placeholder="Filtrer par marque"
        />
        
        <x-input 
            label="Type" 
            wire:model.live.debounce.500ms="filterType" 
            placeholder="Filtrer par type"
        />
        
        <x-input 
            label="EAN/SKU" 
            wire:model.live.debounce.500ms="filterEAN" 
            placeholder="Filtrer par EAN"
        />
    </div>

    <div class="overflow-x-auto rounded-box border border-base-content/5 bg-base-100 relative">
        
        <!-- Loading indicator pour infinite scroll -->
        <div 
            x-data="{ 
                show: false,
                productCount: {{ count($products) }}
            }"
            x-init="
                console.log('Modal init - ProductCount:', productCount);
                
                // Écouter les changements de loading
                Livewire.on('loading-finished', () => {
                    console.log('❌ Loading finished event received');
                    setTimeout(() => {
                        show = false;
                    }, 300);
                });
                
                // Observer directement la propriété loading via wire
                $watch('$wire.loading', (value) => {
                    console.log('👁️ $wire.loading changed to:', value);
                    show = value && productCount > 0;
                });
            "
            x-show="show"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            style="display: none;"
            class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 backdrop-blur-sm"
        >
            <div class="flex flex-col items-center justify-center bg-base-100 rounded-2xl p-10 shadow-2xl border border-base-content/20 min-w-[300px] max-w-md">
                <!-- Icône animée -->
                <div class="relative mb-6">
                    <svg class="w-16 h-16 text-primary animate-pulse" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                    </svg>
                </div>
                
                <!-- Texte principal -->
                <h3 class="text-xl font-bold text-base-content mb-2 text-center">
                    Chargement des produits
                </h3>
                
                <!-- Barre de progression animée -->
                <div class="w-full bg-base-300 rounded-full h-2 overflow-hidden">
                    <div class="bg-primary h-full rounded-full" style="animation: loading 2s ease-in-out infinite;"></div>
                </div>
                
                <p class="text-xs text-base-content/70 mt-4">Chargement de plus de produits en cours...</p>
            </div>
        </div>
        
        <div 
            x-data="{ 
                loading: @entangle('loading').live,
                hasMore: @entangle('hasMore').live,
                productCount: {{ count($products) }},
                throttleTimer: null
            }"
            x-init="
                console.log('Init - Loading:', loading, 'ProductCount:', productCount);
                
                $watch('loading', value => {
                    console.log('🔄 Loading changed to:', value, 'ProductCount:', productCount);
                });
                
                $el.addEventListener('scroll', function(e) {
                    if (throttleTimer) return;
                    
                    throttleTimer = setTimeout(() => {
                        throttleTimer = null;
                        
                        const scrollTop = $el.scrollTop;
                        const scrollHeight = $el.scrollHeight;
                        const clientHeight = $el.clientHeight;
                        const scrollPercentage = (scrollTop / (scrollHeight - clientHeight)) * 100;
                        
                        console.log('📊 Scroll %:', scrollPercentage.toFixed(2), 'Loading:', loading, 'HasMore:', hasMore);
                        
                        if (scrollPercentage > 80 && hasMore && !loading) {
                            console.log('✅ Calling loadMore()');
                            $wire.loadMore();
                        }
                    }, 150);
                });
            "
            class="max-h-[600px] overflow-y-auto"
        >
            <table class="table table-sm">
                <thead class="sticky top-0 bg-base-200 z-10">
                    <tr>
                        <th>Image</th>
                        <th>SKU</th>
                        <th>Nom</th>
                        <th>Marque</th>
                        <th>Type</th>
                        <th>Prix</th>
                        <th>Stock</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($products as $index => $product)
                        <tr wire:key="product-{{ $product['id'] ?? $index }}">
                            <td>
                                @if(!empty($product['thumbnail']))
                                    <div class="avatar">
                                        <div class="w-10 h-10 rounded">
                                            <img src="{{ $product['thumbnail'] }}" alt="{{ $product['title'] ?? '' }}" />
                                        </div>
                                    </div>
                                @else
                                    <div class="w-10 h-10 bg-base-300 rounded flex items-center justify-center">
                                        <span class="text-xs">N/A</span>
                                    </div>
                                @endif
                            </td>
                            <td class="font-mono text-xs">{{ $product['sku'] ?? '' }}</td>
                            <td>
                                <div class="max-w-xs truncate" title="{{ $product['title'] ?? '' }}">
                                    {{ $product['title'] ?? '' }}
                                </div>
                            </td>
                            <td>{{ $product['vendor'] ?? '' }}</td>
                            <td>
                                <span class="badge badge-sm">{{ $product['type'] ?? '' }}</span>
                            </td>
                            <td>
                                @if(!empty($product['special_price']))
                                    <div class="flex flex-col">
                                        <span class="line-through text-xs text-base-content/50">
                                            {{ number_format($product['price'] ?? 0, 2) }} €
                                        </span>
                                        <span class="text-error font-semibold">
                                            {{ number_format($product['special_price'], 2) }} €
                                        </span>
                                    </div>
                                @else
                                    <span class="font-semibold">
                                        {{ number_format($product['price'] ?? 0, 2) }} €
                                    </span>
                                @endif
                            </td>
                            <td>
                                <span class="{{ ($product['quatity'] ?? 0) > 0 ? 'text-success' : 'text-error' }}">
                                    {{ $product['quatity'] ?? 0 }}
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-sm {{ ($product['quatity_status'] ?? 0) == 1 ? 'badge-success' : 'badge-error' }}">
                                    {{ ($product['quatity_status'] ?? 0) == 1 ? 'En stock' : 'Rupture' }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center py-12 text-base-content/50">
                                @if($loading && count($products) === 0)
                                    <div class="flex flex-col items-center gap-3">
                                        <span class="loading loading-spinner loading-lg text-primary"></span>
                                        <span class="text-lg">Chargement des produits...</span>
                                    </div>
                                @else
                                    <div class="flex flex-col items-center gap-3">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 text-base-content/20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                        </svg>
                                        <span class="text-lg">Aucun produit trouvé</span>
                                        <span class="text-sm">Essayez de modifier vos filtres</span>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @if(!$hasMore && count($products) > 0 && !$loading)
                <div class="text-center py-6 text-base-content/70 bg-base-100">
                    <div class="inline-flex items-center gap-2 bg-success/10 text-success px-6 py-3 rounded-full">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                        </svg>
                        <span class="font-medium">Tous les produits chargés ({{ $totalItems }} au total)</span>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>

<!-- Styles inline pour l'animation de la barre de progression -->
<style>
    @keyframes loading {
        0% {
            width: 0%;
            margin-left: 0%;
        }
        50% {
            width: 50%;
            margin-left: 25%;
        }
        100% {
            width: 0%;
            margin-left: 100%;
        }
    }
</style>