<?php

use Livewire\Volt\Component;
use App\Models\DetailProduct;
use App\Models\Comparaison;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

new class extends Component {

    public int $id;
    public string $listTitle = '';
    public bool $loading = true;
    public bool $loadingMore = false;
    public bool $hasMore = true;
    public int $page = 1;
    public int $perPage = 5;

    // Cache
    protected $cacheTTL = 3600;

    public function mount($id): void
    {
        $this->id = $id;
        $this->loadListTitle();
    }

    public function loadListTitle(): void
    {
        try {
            $list = Comparaison::find($this->id);
            $this->listTitle = $list ? $list->libelle : 'Liste non trouvée';
        } catch (\Exception $e) {
            Log::error('Erreur chargement titre liste: ' . $e->getMessage());
            $this->listTitle = 'Erreur de chargement';
        }
    }

    public function loadMore(): void
    {
        if (!$this->hasMore || $this->loading || $this->loadingMore) {
            return;
        }

        Log::info('loadMore: Chargement page ' . ($this->page + 1));
        $this->loadingMore = true;
        $this->page++;
    }

    // Réinitialiser les produits
    protected function resetProducts(): void
    {
        $this->page = 1;
        $this->hasMore = true;
        $this->loadingMore = false;
        $this->loading = true;
    }

    // Rafraîchir la liste
    public function refreshProducts(): void
    {
        $this->resetProducts();
        $this->loadListTitle(); // Recharger aussi le titre au cas où
    }

    public function with(): array
    {
        try {
            // Récupérer tous les EAN de la liste
            $allSkus = Cache::remember("list_skus_{$this->id}", 300, function () {
                return DetailProduct::where('list_product_id', $this->id)
                    ->pluck('EAN')
                    ->unique()
                    ->values()
                    ->toArray();
            });

            $totalItems = count($allSkus);

            if ($totalItems === 0) {
                $this->loading = false;
                $this->hasMore = false;
                return [
                    'products' => [],
                    'totalItems' => 0,
                    'allSkus' => [],
                ];
            }

            $allProducts = [];

            // Charger les produits page par page
            for ($i = 1; $i <= $this->page; $i++) {
                $result = $this->fetchProductsFromDatabase($allSkus, $i, $this->perPage);

                if (isset($result['error'])) {
                    Log::error('Erreur DB: ' . $result['error']);
                    break;
                }

                $newProducts = $result['data'] ?? [];
                $newProducts = array_map(fn($p) => (array) $p, $newProducts);

                $allProducts = array_merge($allProducts, $newProducts);

                // Vérifier si on a chargé tous les produits
                if (count($newProducts) < $this->perPage) {
                    $this->hasMore = false;
                    break;
                }
            }

            // Vérifier s'il reste des produits à charger
            if (count($allProducts) >= $totalItems) {
                $this->hasMore = false;
            }

            $this->loading = false;
            $this->loadingMore = false;

            return [
                'products' => $allProducts,
                'totalItems' => $totalItems,
                'allSkus' => $allSkus,
            ];

        } catch (\Exception $e) {
            Log::error('Erreur with(): ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            $this->loading = false;
            $this->loadingMore = false;
            $this->hasMore = false;

            return [
                'products' => [],
                'totalItems' => 0,
                'allSkus' => [],
            ];
        }
    }

    /**
     * Récupère les produits depuis la base de données
     */
    protected function fetchProductsFromDatabase(array $allSkus, int $page = 1, int $perPage = null)
    {
        try {
            $offset = ($page - 1) * $perPage;
            $pageSkus = array_slice($allSkus, $offset, $perPage);

            if (empty($pageSkus)) {
                return [
                    "total_item" => count($allSkus),
                    "per_page" => $perPage,
                    "total_page" => ceil(count($allSkus) / $perPage),
                    "current_page" => $page,
                    "data" => [],
                    "cached_at" => now()->toDateTimeString(),
                ];
            }

            $placeholders = implode(',', array_fill(0, count($pageSkus), '?'));

            $query = "
                SELECT 
                    produit.sku as sku,
                    CAST(product_char.name AS CHAR CHARACTER SET utf8mb4) as title,
                    product_char.thumbnail as thumbnail,
                    SUBSTRING_INDEX(product_char.name, ' - ', 1) as vendor,
                    SUBSTRING_INDEX(eas.attribute_set_name, '_', -1) as type,
                    ROUND(product_decimal.price, 2) as price,
                    ROUND(product_decimal.special_price, 2) as special_price,
                    stock_item.qty as quatity,
                    stock_status.stock_status as quatity_status,
                    product_char.reference as reference,
                    product_char.reference_us as reference_us,
                    product_int.status as status,
                    CAST(product_text.description AS CHAR CHARACTER SET utf8mb4) as description,
                    product_char.swatch_image as swatch_image
                FROM catalog_product_entity as produit
                LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
                LEFT JOIN product_decimal ON product_decimal.entity_id = produit.entity_id
                LEFT JOIN product_int ON product_int.entity_id = produit.entity_id
                LEFT JOIN product_text ON product_text.entity_id = produit.entity_id
                LEFT JOIN cataloginventory_stock_item AS stock_item ON stock_item.product_id = produit.entity_id 
                LEFT JOIN cataloginventory_stock_status AS stock_status ON stock_item.product_id = stock_status.product_id 
                LEFT JOIN eav_attribute_set AS eas ON produit.attribute_set_id = eas.attribute_set_id 
                WHERE produit.sku IN ($placeholders)
                AND product_int.status >= 0
                ORDER BY FIELD(produit.sku, " . implode(',', $pageSkus) . ")
            ";

            $result = DB::connection('mysqlMagento')->select($query, $pageSkus);

            return [
                "total_item" => count($allSkus),
                "per_page" => $perPage,
                "total_page" => ceil(count($allSkus) / $perPage),
                "current_page" => $page,
                "data" => $result,
                "cached_at" => now()->toDateTimeString(),
                "cache_key" => $this->getCacheKey('list_products', $this->id, $page, $perPage)
            ];

        } catch (\Throwable $e) {
            Log::error('Error fetching list products: ' . $e->getMessage());

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

    protected function getCacheKey($type, ...$params)
    {
        return "list_products_{$type}_" . md5(serialize($params));
    }
}; ?>

<div x-data="{
    loading: false,
    observer: null,
    
    init() {
        // Observer pour le scroll infini
        this.setupInfiniteScroll();
    },
    
    setupInfiniteScroll() {
        const options = {
            root: null,
            rootMargin: '100px',
            threshold: 0.1
        };
        
        this.observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && !this.loading && $wire.hasMore) {
                    this.loadMore();
                }
            });
        }, options);
        
        // Observer le dernier élément de la table
        this.$watch('$wire.products', () => {
            this.$nextTick(() => {
                const lastRow = this.$el.querySelector('tbody tr:last-child');
                if (lastRow) {
                    this.observer.disconnect();
                    this.observer.observe(lastRow);
                }
            });
        });
    },
    
    async loadMore() {
        if (this.loading || !$wire.hasMore) return;
        
        this.loading = true;
        await $wire.loadMore();
        this.loading = false;
    }
}" x-init="init">

    <!-- En-tête avec information -->
    <div class="mb-6">
        <h2 class="text-2xl font-bold mb-2">{{ $listTitle }}</h2>
        <div class="flex items-center gap-4">
            <div class="badge badge-primary">
                {{ $totalItems }} produits au total
            </div>
            @if($loading)
                <div class="flex items-center gap-2 text-sm text-base-content/70">
                    <span class="loading loading-spinner loading-xs"></span>
                    Chargement...
                </div>
            @endif
        </div>
    </div>

    <!-- Table des produits -->
    <div class="overflow-x-auto rounded-box border border-base-content/5 bg-base-100">
        <table class="table">
            <!-- head -->
            <thead>
                <tr>
                    <th>#</th>
                    <th>Image</th>
                    <th>EAN/SKU</th>
                    <th>Nom</th>
                    <th>Marque</th>
                    <th>Type</th>
                    <th>Prix</th>
                    <th>Stock</th>
                    <th>Statut</th>
                </tr>
            </thead>
            <tbody>
                @if($loading && $page === 1)
                    <!-- État de chargement initial -->
                    <tr>
                        <td colspan="9" class="text-center py-12">
                            <div class="flex flex-col items-center gap-3">
                                <span class="loading loading-spinner loading-lg text-primary"></span>
                                <span class="text-lg">Chargement des produits...</span>
                            </div>
                        </td>
                    </tr>
                @elseif(count($products) === 0 && !$loading)
                    <!-- Aucun produit -->
                    <tr>
                        <td colspan="9" class="text-center py-12 text-base-content/50">
                            <div class="flex flex-col items-center gap-3">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 text-base-content/20" fill="none"
                                    viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                        d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                </svg>
                                <span class="text-lg">Aucun produit dans cette liste</span>
                            </div>
                        </td>
                    </tr>
                @else
                    <!-- Liste des produits -->
                    @foreach($products as $index => $product)
                        <tr wire:key="product-{{ $product['sku'] }}-{{ $index }}" @if($loop->last) x-ref="lastRow" @endif>
                            <th>{{ $index + 1 }}</th>
                            <td>
                                @if(!empty($product['thumbnail']))
                                    <div class="avatar">
                                        <div class="w-12 h-12 rounded">
                                            <img src="https://www.cosma-parfumeries.com/media/catalog/product/{{ $product['thumbnail'] }}"
                                                alt="{{ $product['title'] ?? '' }}" class="object-cover" loading="lazy">
                                        </div>
                                    </div>
                                @elseif(!empty($product['swatch_image']))
                                    <div class="avatar">
                                        <div class="w-12 h-12 rounded">
                                            <img src="https://www.cosma-parfumeries.com/media/catalog/product/{{ $product['swatch_image'] }}"
                                                alt="{{ $product['title'] ?? '' }}" class="object-cover" loading="lazy">
                                        </div>
                                    </div>
                                @else
                                    <div class="w-12 h-12 bg-base-300 rounded flex items-center justify-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-base-content/40" fill="none"
                                            viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1"
                                                d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                    </div>
                                @endif
                            </td>
                            <td class="font-mono text-sm">
                                <div class="tooltip" data-tip="Cliquer pour copier">
                                    <button @click="copySku('{{ $product['sku'] }}')"
                                        class="hover:text-primary transition-colors">
                                        {{ $product['sku'] ?? '' }}
                                    </button>
                                </div>
                            </td>
                            <td>
                                <div class="max-w-xs" title="{{ $product['title'] ?? '' }}">
                                    {{ $product['title'] ?? 'N/A' }}
                                </div>
                            </td>
                            <td>{{ $product['vendor'] ?? 'N/A' }}</td>
                            <td>
                                <span class="badge">{{ $product['type'] ?? 'N/A' }}</span>
                            </td>
                            <td>
                                @if(!empty($product['special_price']) && $product['special_price'] > 0)
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
                                @php
                                    $statusClass = ($product['status'] ?? 0) == 1 ? 'badge-success' : 'badge-error';
                                    $statusText = ($product['status'] ?? 0) == 1 ? 'Actif' : 'Inactif';
                                    $stockStatusClass = ($product['quatity_status'] ?? 0) == 1 ? 'badge-success' : 'badge-error';
                                    $stockStatusText = ($product['quatity_status'] ?? 0) == 1 ? 'En stock' : 'Rupture';
                                @endphp
                                <div class="flex flex-col gap-1">
                                    <span class="badge badge-sm {{ $statusClass }}">
                                        {{ $statusText }}
                                    </span>
                                    <span class="badge badge-sm {{ $stockStatusClass }}">
                                        {{ $stockStatusText }}
                                    </span>
                                </div>
                            </td>
                        </tr>
                    @endforeach

                    <!-- Indicateur de chargement pour le scroll infini -->
                    @if($loadingMore)
                        <tr>
                            <td colspan="9" class="text-center py-4 bg-base-100/80">
                                <div class="flex flex-col items-center gap-3">
                                    <span class="loading loading-spinner loading-md text-primary"></span>
                                    <span class="text-base-content/70 font-medium">
                                        Chargement de {{ $perPage }} produits supplémentaires...
                                    </span>
                                </div>
                            </td>
                        </tr>
                    @endif

                    <!-- Indicateur de fin -->
                    @if(!$hasMore && count($products) > 0)
                        <tr>
                            <td colspan="9" class="text-center py-6 text-base-content/70">
                                <div class="inline-flex items-center gap-2 bg-success/10 text-success px-6 py-3 rounded-full">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20"
                                        fill="currentColor">
                                        <path fill-rule="evenodd"
                                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                            clip-rule="evenodd" />
                                    </svg>
                                    <span class="font-medium">
                                        Tous les {{ $totalItems }} produits sont chargés
                                    </span>
                                </div>
                            </td>
                        </tr>
                    @endif
                @endif
            </tbody>
        </table>
    </div>

    <!-- Boutons d'action -->
    <div class="mt-6 flex gap-4 justify-center">
        <button wire:click="refreshProducts" wire:loading.attr="disabled" wire:target="refreshProducts"
            class="btn btn-primary">
            <span wire:loading.remove wire:target="refreshProducts">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd"
                        d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z"
                        clip-rule="evenodd" />
                </svg>
                Rafraîchir
            </span>
            <span wire:loading wire:target="refreshProducts" class="flex items-center gap-2">
                <span class="loading loading-spinner loading-sm"></span>
                Chargement...
            </span>
        </button>

        @if($hasMore && !$loadingMore && !$loading)
            <button @click="loadMore()" :disabled="loading" class="btn btn-outline">
                <span :class="{'loading loading-spinner loading-sm': loading}"></span>
                Charger plus
            </button>
        @endif
    </div>

    <!-- Stats en bas -->
    @if(!$loading && count($products) > 0)
        <div class="mt-4 text-center text-sm text-base-content/60">
            Affichage de
            <span class="font-medium">{{ count($products) }}</span>
            produit(s) sur
            <span class="font-medium">{{ $totalItems }}</span>
            au total
            @if($hasMore)
                <span class="ml-2">
                    ({{ $totalItems - count($products) }} restants)
                </span>
            @endif
        </div>
    @endif
</div>

@push('scripts')
    <script>
        // Fonction pour copier le SKU
        document.addEventListener('livewire:initialized', () => {
            // Afficher une notification lors de la copie
            Livewire.on('copied', (event) => {
                const toast = document.createElement('div');
                toast.className = `toast toast-top toast-end`;
                toast.innerHTML = `
                    <div class="alert alert-success">
                        <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>SKU ${event.sku} copié !</span>
                    </div>
                `;
                document.body.appendChild(toast);

                setTimeout(() => {
                    toast.remove();
                }, 2000);
            });
        });

        // Fonction pour émettre l'événement de copie
        function copySku(sku) {
            navigator.clipboard.writeText(sku).then(() => {
                Livewire.dispatch('copied', { sku: sku });
            }).catch(err => {
                console.error('Erreur copie:', err);
            });
        }
    </script>
@endpush