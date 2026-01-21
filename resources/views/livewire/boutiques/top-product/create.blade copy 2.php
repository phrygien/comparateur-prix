<?php

use Livewire\Volt\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

new class extends Component {
    public $page = 1;
    public $perPage = 60;
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
            return;
        }
        
        if ($this->loading) {
            return;
        }
        
        Log::info('loadMore: Chargement page ' . ($this->page + 1));
        
        $this->loading = true;
        $this->page++;
    }
    
    public function updatedSearch()
    {
        $this->resetProducts();
        $this->loading = true;
    }
    
    public function updatedFilterName()
    {
        $this->resetProducts();
        $this->loading = true;
    }
    
    public function updatedFilterMarque()
    {
        $this->resetProducts();
        $this->loading = true;
    }
    
    public function updatedFilterType()
    {
        $this->resetProducts();
        $this->loading = true;
    }
    
    public function updatedFilterEAN()
    {
        $this->resetProducts();
        $this->loading = true;
    }
    
    protected function resetProducts()
    {
        $this->page = 1;
        $this->hasMore = true;
    }
    
    public function with(): array
    {
        // Note: Ne pas modifier loading ici
        
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
        }
    }
    
    // Hook pour réinitialiser le loading après le rendu
    #[On('rendered')]
    public function resetLoadingAfterRender()
    {
        $this->loading = false;
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

<div class="mx-auto max-w-5xl" x-data>
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

    <div class="rounded-box border border-base-content/5 bg-base-100 overflow-hidden relative">
        <!-- Conteneur principal avec infinite scroll -->
        <div 
            x-data="{
                showLoading: false,
                isLoading: @entangle('loading'),
                hasMore: @entangle('hasMore'),
                productCount: {{ count($products) }},
                init() {
                    // Observer les changements de l'état loading de Livewire
                    this.$watch('isLoading', (value) => {
                        console.log('Loading state changed:', value);
                        this.showLoading = value;
                    });
                    
                    // Gestionnaire de scroll
                    this.$el.addEventListener('scroll', (e) => {
                        const el = this.$el;
                        const scrollTop = el.scrollTop;
                        const scrollHeight = el.scrollHeight;
                        const clientHeight = el.clientHeight;
                        
                        // Détecter quand on est à 80% du bas
                        if (scrollTop + clientHeight >= scrollHeight - 100) {
                            if (this.hasMore && !this.isLoading) {
                                console.log('Triggering loadMore...');
                                @this.loadMore();
                                this.showLoading = true;
                            }
                        }
                    });
                }
            }"
            class="max-h-[600px] overflow-y-auto relative"
            wire:ignore.self
        >
            <!-- Overlay de chargement avec Alpine.js -->
            <div 
                x-show="showLoading && productCount > 0"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="fixed inset-0 z-50 flex items-center justify-center"
                style="display: none;"
            >
                <!-- Overlay avec blur -->
                <div class="absolute inset-0 bg-black/40 backdrop-blur-md"></div>
                
                <!-- Modal de chargement -->
                <div class="relative z-10 bg-base-100/90 backdrop-blur-xl border-2 border-primary/20 rounded-2xl shadow-2xl p-8 max-w-md mx-4">
                    <!-- Contenu du loading -->
                    <div class="text-center">
                        <!-- Spinner animé -->
                        <div class="mb-6">
                            <div class="relative inline-block">
                                <div class="w-20 h-20 border-4 border-primary/20 rounded-full"></div>
                                <div class="w-20 h-20 border-4 border-primary border-t-transparent rounded-full animate-spin absolute top-0 left-0"></div>
                                <!-- Icône au centre -->
                                <div class="absolute inset-0 flex items-center justify-center">
                                    <svg class="w-8 h-8 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Texte de chargement -->
                        <div class="space-y-2">
                            <h3 class="text-2xl font-bold text-base-content">
                                Chargement de plus de produits...
                            </h3>
                            <p class="text-base-content/70">
                                Patientez pendant que nous chargeons les produits suivants
                            </p>
                        </div>
                        
                        <!-- Animation de points -->
                        <div class="mt-6 flex justify-center space-x-2">
                            <div class="w-3 h-3 bg-primary rounded-full animate-bounce" style="animation-delay: 0ms"></div>
                            <div class="w-3 h-3 bg-primary rounded-full animate-bounce" style="animation-delay: 150ms"></div>
                            <div class="w-3 h-3 bg-primary rounded-full animate-bounce" style="animation-delay: 300ms"></div>
                        </div>
                        
                        <!-- Compteur -->
                        <div class="mt-6">
                            <div class="inline-flex items-center gap-2 bg-primary/10 text-primary px-4 py-2 rounded-full">
                                <span class="text-sm font-medium">
                                    {{ count($products) }} produits chargés
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Effet blur sur le tableau pendant le chargement -->
            <div 
                x-show="showLoading && productCount > 0"
                x-transition.opacity
                class="absolute inset-0 bg-base-100/30 backdrop-blur-sm z-40 pointer-events-none"
            ></div>
            
            <!-- Tableau -->
            <table class="table table-sm w-full relative">
                <thead class="sticky top-0 bg-base-200 z-30">
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
                        <tr 
                            wire:key="product-{{ $product['id'] ?? $index }}"
                            x-bind:class="{ 'opacity-30': showLoading && productCount > 0 }"
                        >
                            <td>
                                @if(!empty($product['thumbnail']))
                                    <div class="avatar">
                                        <div class="w-10 h-10 rounded">
                                            <img 
                                                src="https://www.cosma-parfumeries.com/media/catalog/product/{{ $product['thumbnail'] }}"
                                                alt="{{ $product['title'] ?? '' }}"
                                            >
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
                                @if($loading)
                                    <!-- Loading pour premier chargement -->
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
                    
                    <!-- Ligne de chargement dans le tableau -->
                    <tr x-show="showLoading && productCount > 0">
                        <td colspan="8" class="text-center py-8 bg-base-100/80">
                            <div class="flex flex-col items-center gap-3">
                                <span class="loading loading-spinner loading-md text-primary"></span>
                                <span class="text-base-content/70 font-medium">
                                    Ajout de nouveaux produits...
                                </span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <!-- Message de fin -->
        @if(!$hasMore && count($products) > 0 && !$loading)
            <div class="text-center py-6 text-base-content/70 bg-base-100 border-t border-base-content/5">
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

<style>
/* Animation pour le spinner */
@keyframes spin {
    from {
        transform: rotate(0deg);
    }
    to {
        transform: rotate(360deg);
    }
}

.animate-spin {
    animation: spin 1s linear infinite;
}

/* Animation pour les points qui rebondissent */
@keyframes bounce {
    0%, 100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-10px);
    }
}

.animate-bounce {
    animation: bounce 0.6s infinite;
}

/* Transition pour l'opacité */
.transition-opacity {
    transition: opacity 0.3s ease-in-out;
}

/* Style pour le modal de chargement */
.backdrop-blur-xl {
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
}

/* Effet glassmorphism */
.glass-effect {
    background: rgba(255, 255, 255, 0.25);
    box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.37);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    border: 1px solid rgba(255, 255, 255, 0.18);
}
</style>

<script>
// Script pour déboguer et s'assurer qu'Alpine fonctionne
document.addEventListener('alpine:init', () => {
    console.log('Alpine.js initialisé');
});

// Vérifier l'état du loading
document.addEventListener('livewire:init', () => {
    Livewire.hook('commit', ({ component, commit, respond, succeed, fail }) => {
        succeed(({ snapshot, effect }) => {
            console.log('Livewire commit succeeded, loading state:', component.$wire.loading);
        });
    });
});
</script>