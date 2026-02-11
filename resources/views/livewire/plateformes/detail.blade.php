<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;

new class extends Component {

    public $productId = null;

    // Durée du cache en minutes
    protected $cacheDuration = 180; // 3 heures

    public function mount($id)
    {
        $this->productId = $id;
    }

    /**
     * Génère une clé de cache unique pour le produit
     */
    protected function getCacheKey()
    {
        return sprintf('product:details:%d', $this->productId);
    }

    /**
     * Vider le cache du produit actuel
     */
    public function clearCache()
    {
        Cache::forget($this->getCacheKey());
        $this->dispatch('cache-cleared', ['message' => 'Cache du produit vidé']);
        
        // Forcer le recalcul du computed property
        unset($this->computedPropertyCache['product']);
    }

    /**
     * Vider le cache de tous les produits
     */
    public function clearAllProductsCache()
    {
        // Méthode 1: Si vous utilisez Redis avec tags
        // Cache::tags(['products'])->flush();
        
        // Méthode 2: Avec file cache (approximatif)
        $pattern = 'product:details:*';
        
        // Note: avec le driver 'file', vous devrez implémenter une logique personnalisée
        // ou simplement vider tout le cache
        Cache::flush();
        
        $this->dispatch('cache-cleared', ['message' => 'Cache de tous les produits vidé']);
    }

    /**
     * Récupère les détails du produit avec mise en cache
     */
    #[Computed]
    public function product()
    {
        try {
            $cacheKey = $this->getCacheKey();

            // Essayer de récupérer depuis le cache
            return Cache::remember($cacheKey, $this->cacheDuration * 60, function () {
                \Log::info('Fetching product from database (not cached)', [
                    'product_id' => $this->productId,
                    'cache_key' => $this->getCacheKey()
                ]);

                $result = DB::connection('mysqlMagento')
                    ->table('catalog_product_entity as produit')
                    ->select([
                        'produit.entity_id as id',
                        'produit.sku as sku',
                        'product_char.reference as parkode',
                        DB::raw('CAST(product_char.name AS CHAR CHARACTER SET utf8mb4) as title'),
                        DB::raw('CAST(product_parent_char.name AS CHAR CHARACTER SET utf8mb4) as parent_title'),
                        DB::raw("SUBSTRING_INDEX(product_char.name, ' - ', 1) as vendor"),
                        DB::raw("SUBSTRING_INDEX(eas.attribute_set_name, '_', -1) as type"),
                        'product_char.thumbnail as thumbnail',
                        'product_char.swatch_image as swatch_image',
                        'product_char.reference_us as reference_us',
                        DB::raw('CAST(product_text.description AS CHAR CHARACTER SET utf8mb4) as description'),
                        DB::raw('CAST(product_text.short_description AS CHAR CHARACTER SET utf8mb4) as short_description'),
                        DB::raw('CAST(product_parent_text.description AS CHAR CHARACTER SET utf8mb4) as parent_description'),
                        DB::raw('CAST(product_parent_text.short_description AS CHAR CHARACTER SET utf8mb4) as parent_short_description'),
                        DB::raw('CAST(product_text.composition AS CHAR CHARACTER SET utf8mb4) as composition'),
                        DB::raw('CAST(product_text.olfactive_families AS CHAR CHARACTER SET utf8mb4) as olfactive_families'),
                        DB::raw('CAST(product_text.product_benefit AS CHAR CHARACTER SET utf8mb4) as product_benefit'),
                        DB::raw('ROUND(product_decimal.price, 2) as price'),
                        DB::raw('ROUND(product_decimal.special_price, 2) as special_price'),
                        DB::raw('ROUND(product_decimal.cost, 2) as cost'),
                        DB::raw('ROUND(product_decimal.pvc, 2) as pvc'),
                        DB::raw('ROUND(product_decimal.prix_achat_ht, 2) as prix_achat_ht'),
                        DB::raw('ROUND(product_decimal.prix_us, 2) as prix_us'),
                        'product_int.status as status',
                        'product_int.color as color',
                        'product_int.capacity as capacity',
                        'product_int.product_type as product_type',
                        'product_media.media_gallery as media_gallery',
                        DB::raw('CAST(product_categorie.name AS CHAR CHARACTER SET utf8mb4) as categorie'),
                        DB::raw("REPLACE(product_categorie.name, ' > ', ',') as tags"),
                        'stock_item.qty as quatity',
                        'stock_status.stock_status as quatity_status',
                        'options.configurable_product_id as configurable_product_id',
                        'parent_child_table.parent_id as parent_id',
                        'options.attribute_code as option_name',
                        'options.attribute_value as option_value'
                    ])
                    ->leftJoin('catalog_product_relation as parent_child_table', 'parent_child_table.child_id', '=', 'produit.entity_id')
                    ->leftJoin('catalog_product_super_link as cpsl', 'cpsl.product_id', '=', 'produit.entity_id')
                    ->leftJoin('product_char', 'product_char.entity_id', '=', 'produit.entity_id')
                    ->leftJoin('product_text', 'product_text.entity_id', '=', 'produit.entity_id')
                    ->leftJoin('product_decimal', 'product_decimal.entity_id', '=', 'produit.entity_id')
                    ->leftJoin('product_int', 'product_int.entity_id', '=', 'produit.entity_id')
                    ->leftJoin('product_media', 'product_media.entity_id', '=', 'produit.entity_id')
                    ->leftJoin('product_categorie', 'product_categorie.entity_id', '=', 'produit.entity_id')
                    ->leftJoin('cataloginventory_stock_item as stock_item', 'stock_item.product_id', '=', 'produit.entity_id')
                    ->leftJoin('cataloginventory_stock_status as stock_status', 'stock_item.product_id', '=', 'stock_status.product_id')
                    ->leftJoin('option_super_attribut as options', 'options.simple_product_id', '=', 'produit.entity_id')
                    ->leftJoin('eav_attribute_set as eas', 'produit.attribute_set_id', '=', 'eas.attribute_set_id')
                    ->leftJoin('catalog_product_entity as produit_parent', 'parent_child_table.parent_id', '=', 'produit_parent.entity_id')
                    ->leftJoin('product_char as product_parent_char', 'product_parent_char.entity_id', '=', 'produit_parent.entity_id')
                    ->leftJoin('product_text as product_parent_text', 'product_parent_text.entity_id', '=', 'produit_parent.entity_id')
                    ->where('produit.entity_id', $this->productId)
                    ->where(function($query) {
                        $query->whereNull('product_int.status')
                              ->orWhere('product_int.status', '>=', 0);
                    })
                    ->orderBy('product_char.entity_id', 'DESC')
                    ->first();

                \Log::info('Product fetched and cached', [
                    'product_id' => $this->productId,
                    'cache_key' => $this->getCacheKey(),
                    'cache_duration' => $this->cacheDuration,
                    'found' => $result !== null
                ]);

                return $result;
            });

        } catch (\Throwable $e) {
            \Log::error('Error loading product:', [
                'message' => $e->getMessage(),
                'entity_id' => $this->productId ?? null,
                'trace' => $e->getTraceAsString()
            ]);
            
            return null;
        }
    }

    /**
     * Vérifie si le produit est en cache
     */
    public function isCached()
    {
        return Cache::has($this->getCacheKey());
    }

    /**
     * Obtient les informations de cache
     */
    public function getCacheInfo()
    {
        $cacheKey = $this->getCacheKey();
        $isCached = Cache::has($cacheKey);
        
        return [
            'is_cached' => $isCached,
            'cache_key' => $cacheKey,
            'cache_duration_minutes' => $this->cacheDuration,
            'product_id' => $this->productId
        ];
    }

}; ?>

<div x-data="{ 
    showBar: false, 
    isBarVisible: true,
    lastScroll: 0,
    scrollTimeout: null,
    barHeight: 140,
    
    handleScroll() {
        let currentScroll = window.pageYOffset || document.documentElement.scrollTop;
        const scrollThreshold = 100;
        
        if (currentScroll > scrollThreshold && this.isBarVisible) {
            this.showBar = true;
            clearTimeout(this.scrollTimeout);
        }
        else if (currentScroll <= scrollThreshold) {
            clearTimeout(this.scrollTimeout);
            this.scrollTimeout = setTimeout(() => {
                this.showBar = false;
            }, 150);
        }
        
        this.lastScroll = currentScroll;
    },
    
    toggleBarVisibility() {
        this.isBarVisible = !this.isBarVisible;
        if (!this.isBarVisible) {
            this.showBar = false;
        } else {
            let currentScroll = window.pageYOffset || document.documentElement.scrollTop;
            if (currentScroll > 100) {
                this.showBar = true;
            }
        }
        
        localStorage.setItem('floatingBarVisible', this.isBarVisible);
    },
    
    init() {
        const savedVisibility = localStorage.getItem('floatingBarVisible');
        if (savedVisibility !== null) {
            this.isBarVisible = savedVisibility === 'true';
        }
    }
}" 
@scroll.window.throttle.50ms="handleScroll()" class="w-full max-w-7xl mx-auto p-6">
    
    <!-- Layout compact horizontal -->
    <div class="w-full px-4 py-4 sm:px-6 lg:px-10" 
         :style="isBarVisible ? 'padding-bottom: 180px' : 'padding-bottom: 40px'">

        <!-- Carte compacte avec image et détails côte à côte -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
            <div class="flex items-start gap-6">
                <!-- Product image -->
                <div class="flex-shrink-0">
                    <figure class="w-32 h-32 rounded-lg overflow-hidden bg-gray-50">
                        <img src="{{ asset('https://www.cosma-parfumeries.com/media/catalog/product/' . $this->product->thumbnail) }}" 
                             alt="{{ utf8_encode($this->product->title) ?? 'Product image' }}" 
                             class="w-full h-full object-contain" />
                    </figure>
                </div>

                <!-- Product details -->
                <div class="flex-1 min-w-0">
                    <!-- Titre et vendeur -->
                    <div class="mb-3">
                        <h2 class="text-lg font-bold text-gray-900 mb-1">
                            {{ utf8_encode($this->product->title) ?? 'N/A' }}
                        </h2>
                        <p class="text-sm text-gray-600">
                            {{ utf8_encode($this->product->vendor) ?? 'N/A' }}
                        </p>
                    </div>

                    <!-- Prix principal -->
                    <div class="mb-3">
                        @if($this->product->special_price)
                            <div class="flex items-center gap-2">
                                <p class="text-xl font-bold text-red-600">
                                    {{ number_format($this->product->special_price, 2) }} €
                                </p>
                                <p class="text-sm text-gray-500 line-through">
                                    {{ number_format($this->product->price, 2) }} €
                                </p>
                            </div>
                        @else
                            <p class="text-xl font-bold text-gray-900">
                                {{ $this->product->price ? number_format($this->product->price, 2) . ' €' : 'N/A' }}
                            </p>
                        @endif
                    </div>

                    <!-- Détails des prix en ligne compacte -->
                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs">
                        @if($this->product->prix_achat_ht)
                        <div class="flex items-center gap-1">
                            <span class="text-gray-600">Coût HT:</span>
                            <span class="font-semibold text-blue-600">{{ number_format($this->product->prix_achat_ht, 2) }} €</span>
                        </div>
                        @endif
                        @if($this->product->pvc)
                        <div class="flex items-center gap-1">
                            <span class="text-gray-600">PVC:</span>
                            <span class="font-semibold text-purple-600">{{ number_format($this->product->pvc, 2) }} €</span>
                        </div>
                        @endif
                        @if($this->product->prix_us)
                        <div class="flex items-center gap-1">
                            <span class="text-gray-600">Prix US:</span>
                            <span class="font-semibold text-orange-600">${{ number_format($this->product->prix_us, 2) }}</span>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Floating Product Name Bar avec bordure crystal -->
    <div x-show="showBar && isBarVisible" 
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="translate-y-full"
         x-transition:enter-end="translate-y-0"
         x-transition:leave="transition ease-in duration-300"
         x-transition:leave-start="translate-y-0"
         x-transition:leave-end="translate-y-full"
         class="fixed bottom-4 left-1/2 transform -translate-x-1/2 z-50 rounded-2xl overflow-hidden"
         style="height: 140px; width: 88%; max-width: 1000px;">
        
        <!-- Fond avec bordure crystal effet -->
        <div class="absolute inset-0 backdrop-blur-xl rounded-2xl">
            <!-- Background avec effet glass -->
            <div class="absolute inset-0 bg-gradient-to-br from-white/90 via-white/80 to-white/90 rounded-2xl border border-white/30"></div>
            
            <!-- Bordure crystal avec effet de brillance -->
            <div class="absolute inset-0 rounded-2xl p-[3px]">
                <!-- Gradient principal crystal -->
                <div class="absolute inset-0 rounded-2xl bg-gradient-to-r from-blue-50/50 via-white/60 to-blue-50/50"></div>
                
                <!-- Effet de brillance -->
                <div class="absolute inset-0 rounded-2xl bg-gradient-to-r from-transparent via-white/20 to-transparent"></div>
                
                <!-- Contour lumineux -->
                <div class="absolute inset-0 rounded-2xl bg-gradient-to-r from-blue-100/40 via-purple-100/30 to-blue-100/40 shadow-[inset_0_0_20px_rgba(147,197,253,0.3)]"></div>
                
                <!-- Bordure interne blanche pour effet cristal -->
                <div class="absolute inset-[3px] rounded-2xl bg-white/85 backdrop-blur-xl border border-white/40 shadow-sm"></div>
            </div>
            
            <!-- Effets de lumière supplémentaires -->
            <div class="absolute top-0 left-0 w-20 h-20 bg-gradient-to-br from-white/30 to-transparent rounded-full blur-xl -translate-x-10 -translate-y-10"></div>
            <div class="absolute bottom-0 right-0 w-20 h-20 bg-gradient-to-tl from-white/30 to-transparent rounded-full blur-xl translate-x-10 translate-y-10"></div>
        </div>
        
        <!-- Contenu de la barre -->
        <div class="relative px-3 py-4 sm:px-4">
            <div class="flex flex-col mx-auto">
                <!-- En-tête avec titre -->
                <div class="mb-2 pb-2 border-b border-gray-200/50 flex justify-between items-center">
                    <h3 class="text-base font-bold text-gray-900">
                        Produit à comparer sur le concurrent
                    </h3>
                    
                    <!-- Badge "hide" positionné à droite -->
                    <button @click="toggleBarVisibility()" 
                            class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 hover:bg-red-200 transition-colors shadow-sm"
                            title="Masquer la barre flottante">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 mr-1" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                        </svg>
                        Hide
                    </button>
                </div>
                
                <div class="flex items-center justify-between">
                    <!-- Product Image -->
                    <div class="flex-shrink-0 mr-4">
                        @if($this->product->thumbnail)
                        <img src="{{ asset('https://www.cosma-parfumeries.com/media/catalog/product/' . $this->product->thumbnail) }}" 
                             alt="{{ utf8_encode($this->product->title) ?? 'Product' }}" 
                             class="w-16 h-16 object-contain rounded-lg border border-gray-300/50 shadow-sm bg-white/50" />
                        @endif
                    </div>

                    <!-- Product Info -->
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-900 truncate">
                            {{ utf8_encode($this->product->title) ?? 'N/A' }}
                        </p>
                        <p class="text-xs text-gray-600 truncate">
                            {{ utf8_encode($this->product->vendor) ?? 'N/A' }}
                        </p>
                        
                        <!-- Additional info -->
                        <div class="mt-1 space-y-0.5">
                            @if($this->product->sku)
                            <p class="text-xs text-gray-600">
                                <span class="font-semibold">SKU:</span> {{ $this->product->sku }}
                            </p>
                            @endif
                        </div>
                    </div>

                    <!-- Price -->
                    <div class="ml-4 flex-shrink-0 text-right">
                        @if($this->product->special_price)
                            <p class="text-lg font-bold text-gray-900">
                                {{ number_format($this->product->special_price, 2) }} €
                            </p>
                            <p class="text-xs text-gray-500 line-through">
                                {{ number_format($this->product->price, 2) }} €
                            </p>
                        @else
                            <p class="text-lg font-bold text-gray-900">
                                {{ $this->product->price ? number_format($this->product->price, 2) . ' €' : 'N/A' }}
                            </p>
                        @endif
                    </div>
                </div>
                
                <!-- Texte d'aide -->
                <div class="mt-3 pt-2 border-t border-gray-100/50">
                    <p class="text-xs text-red-600 font-medium">
                        ⓘ Vous pouvez effectuer une recherche manuelle à partir du nom du produit ou de mots-clés spécifiques.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Bouton flottant pour réafficher la barre -->
    <div x-show="!isBarVisible" 
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-300"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         class="fixed bottom-6 right-6 z-40">
        <!-- Bouton avec badge intégré -->
        <button @click="toggleBarVisibility()"
                class="relative bg-gradient-to-r from-blue-500 to-blue-600 text-white p-3 rounded-full shadow-lg hover:from-blue-600 hover:to-blue-700 transition-all duration-300 flex items-center justify-center group shadow-blue-500/30"
                title="Afficher la barre de comparaison">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
            </svg>
            
            <!-- Petit badge "show" -->
            <span class="absolute -top-1 -right-1 inline-flex items-center justify-center px-1.5 py-0.5 text-xs font-medium leading-none text-white bg-gradient-to-r from-green-500 to-emerald-500 rounded-full shadow-sm">
                Show
            </span>
        </button>
    </div>
</div>