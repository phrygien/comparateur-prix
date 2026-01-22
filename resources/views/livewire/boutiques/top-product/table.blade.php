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
    public int $perPage = 200;
    public int $totalPages = 1;

    // Produits similaires
    public array $similarProducts = [];
    public array $expandedRows = [];
    public array $loadingSimilar = [];

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

    public function goToPage($page): void
    {
        if ($page < 1 || $page > $this->totalPages || $page === $this->page) {
            return;
        }

        $this->loading = true;
        $this->page = (int) $page;
    }

    public function previousPage(): void
    {
        if ($this->page > 1) {
            $this->goToPage($this->page - 1);
        }
    }

    public function nextPage(): void
    {
        if ($this->page < $this->totalPages) {
            $this->goToPage($this->page + 1);
        }
    }

    public function refreshProducts(): void
    {
        $this->page = 1;
        $this->loading = true;
        $this->loadListTitle();
    }

    /**
     * Bascule l'affichage des produits similaires pour une ligne
     */
    public function toggleSimilarProducts($sku): void
    {
        if (isset($this->expandedRows[$sku])) {
            unset($this->expandedRows[$sku]);
            return;
        }

        // Charger les produits similaires si pas déjà chargés
        if (!isset($this->similarProducts[$sku])) {
            $this->loadingSimilar[$sku] = true;
            $this->findSimilarProducts($sku);
            $this->loadingSimilar[$sku] = false;
        }

        $this->expandedRows[$sku] = true;
    }

    /**
     * Trouve les produits similaires pour un SKU donné
     */
    protected function findSimilarProducts($sku): void
    {
        try {
            // Récupérer le produit source
            $sourceProduct = $this->getProductBySku($sku);
            
            if (!$sourceProduct) {
                $this->similarProducts[$sku] = [];
                return;
            }

            // Extraire les informations du produit source
            $vendor = $this->extractVendor($sourceProduct['title']);
            $productName = $this->cleanProductName($sourceProduct['title']);
            $volumes = $this->extractVolumes($sourceProduct['title']);

            // Construire la requête de recherche
            $vendorConditions = [];
            $vendorParams = [];

            if (!empty($vendor)) {
                $vendorVariations = $this->getVendorVariations($vendor);
                foreach ($vendorVariations as $variation) {
                    $vendorConditions[] = "lp.vendor LIKE ?";
                    $vendorParams[] = '%' . $variation . '%';
                }
            }

            // Requête SQL pour trouver les produits similaires
            $sql = "SELECT 
                    lp.*, 
                    ws.name as site_name, 
                    lp.url as product_url, 
                    lp.image_url as image
                FROM last_price_scraped_product lp
                LEFT JOIN web_site ws ON lp.web_site_id = ws.id
                WHERE (lp.variation != 'Standard' OR lp.variation IS NULL OR lp.variation = '')";

            $params = [];

            // Appliquer le filtre vendor
            if (!empty($vendorConditions)) {
                $sql .= " AND (" . implode(' OR ', $vendorConditions) . ")";
                $params = array_merge($params, $vendorParams);
            }

            // Filtrer par nom de produit (recherche flexible)
            if (!empty($productName)) {
                $sql .= " AND lp.name LIKE ?";
                $params[] = '%' . $productName . '%';
            }

            $sql .= " ORDER BY lp.prix_ht DESC LIMIT 50";

            $results = DB::connection('mysql')->select($sql, $params);

            // Traiter les résultats
            $processedResults = [];
            foreach ($results as $product) {
                if (isset($product->prix_ht)) {
                    $product->prix_ht = $this->cleanPrice($product->prix_ht);
                }
                $product->product_url = $product->product_url ?? $product->url ?? null;
                $product->image = $product->image ?? $product->image_url ?? null;
                
                $processedResults[] = $product;
            }

            $this->similarProducts[$sku] = $processedResults;

        } catch (\Throwable $e) {
            Log::error('Error finding similar products:', [
                'sku' => $sku,
                'message' => $e->getMessage()
            ]);
            $this->similarProducts[$sku] = [];
        }
    }

    /**
     * Récupère un produit par son SKU
     */
    protected function getProductBySku($sku)
    {
        try {
            $query = "
                SELECT 
                    produit.sku as sku,
                    CAST(product_char.name AS CHAR CHARACTER SET utf8mb4) as title,
                    product_char.thumbnail as thumbnail,
                    SUBSTRING_INDEX(product_char.name, ' - ', 1) as vendor,
                    ROUND(product_decimal.price, 2) as price
                FROM catalog_product_entity as produit
                LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
                LEFT JOIN product_decimal ON product_decimal.entity_id = produit.entity_id
                WHERE produit.sku = ?
                LIMIT 1
            ";

            $result = DB::connection('mysqlMagento')->select($query, [$sku]);

            return !empty($result) ? (array) $result[0] : null;

        } catch (\Throwable $e) {
            Log::error('Error fetching product by SKU:', [
                'sku' => $sku,
                'message' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Extrait le vendor du titre du produit
     */
    protected function extractVendor($title): string
    {
        if (empty($title)) {
            return '';
        }

        // Extraire la première partie avant le tiret
        $parts = preg_split('/\s*-\s*/', $title, 2);
        return trim($parts[0] ?? '');
    }

    /**
     * Nettoie le nom du produit
     */
    protected function cleanProductName($title): string
    {
        if (empty($title)) {
            return '';
        }

        // Supprimer le vendor
        $parts = preg_split('/\s*-\s*/', $title, 3);
        
        // Prendre la deuxième partie (nom du produit)
        $name = $parts[1] ?? '';

        // Supprimer les volumes et types
        $name = preg_replace('/\d+\s*ml/i', '', $name);
        $name = preg_replace('/(eau de parfum|eau de toilette|edp|edt|parfum)/i', '', $name);

        return trim($name);
    }

    /**
     * Extrait les volumes du titre
     */
    protected function extractVolumes($title): array
    {
        if (empty($title)) {
            return [];
        }

        $volumes = [];
        if (preg_match_all('/(\d+)\s*ml/i', $title, $matches)) {
            $volumes = $matches[1];
        }

        return $volumes;
    }

    /**
     * Récupère les variations d'un vendor
     */
    protected function getVendorVariations($vendor): array
    {
        $variations = [trim($vendor)];

        // Variations de casse
        $variations[] = mb_strtoupper($vendor);
        $variations[] = mb_strtolower($vendor);
        $variations[] = mb_convert_case($vendor, MB_CASE_TITLE);

        return array_unique(array_filter($variations));
    }

    /**
     * Nettoie un prix
     */
    protected function cleanPrice($price)
    {
        if ($price === null || $price === '') {
            return null;
        }

        if (is_numeric($price)) {
            return (float) $price;
        }

        if (is_string($price)) {
            $cleanPrice = preg_replace('/[^\d,.-]/', '', $price);
            $cleanPrice = str_replace(',', '.', $cleanPrice);

            if (is_numeric($cleanPrice)) {
                return (float) $cleanPrice;
            }
        }

        return null;
    }

    public function with(): array
    {
        try {
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
                $this->totalPages = 1;
                return [
                    'products' => [],
                    'totalItems' => 0,
                    'totalPages' => 1,
                    'allSkus' => [],
                ];
            }

            $this->totalPages = max(1, ceil($totalItems / $this->perPage));

            $result = $this->fetchProductsFromDatabase($allSkus, $this->page, $this->perPage);

            if (isset($result['error'])) {
                Log::error('Erreur DB: ' . $result['error']);
                $products = [];
            } else {
                $products = $result['data'] ?? [];
                $products = array_map(fn($p) => (array) $p, $products);
            }

            $this->loading = false;
            $this->loadingMore = false;

            return [
                'products' => $products,
                'totalItems' => $totalItems,
                'totalPages' => $this->totalPages,
                'allSkus' => $allSkus,
            ];

        } catch (\Exception $e) {
            Log::error('Erreur with(): ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            $this->loading = false;
            $this->loadingMore = false;
            $this->totalPages = 1;

            return [
                'products' => [],
                'totalItems' => 0,
                'totalPages' => 1,
                'allSkus' => [],
            ];
        }
    }

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
                    product_int.status as status,
                    product_char.swatch_image as swatch_image
                FROM catalog_product_entity as produit
                LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
                LEFT JOIN product_decimal ON product_decimal.entity_id = produit.entity_id
                LEFT JOIN product_int ON product_int.entity_id = produit.entity_id
                LEFT JOIN cataloginventory_stock_item AS stock_item ON stock_item.product_id = produit.entity_id 
                LEFT JOIN cataloginventory_stock_status AS stock_status ON stock_item.product_id = stock_status.product_id 
                LEFT JOIN eav_attribute_set AS eas ON produit.attribute_set_id = eas.attribute_set_id 
                WHERE produit.sku IN ($placeholders)
                AND product_int.status >= 0
                ORDER BY FIELD(produit.sku, " . implode(',', array_map(fn($s) => "'$s'", $pageSkus)) . ")
            ";

            $result = DB::connection('mysqlMagento')->select($query, $pageSkus);

            return [
                "total_item" => count($allSkus),
                "per_page" => $perPage,
                "total_page" => ceil(count($allSkus) / $perPage),
                "current_page" => $page,
                "data" => $result,
                "cached_at" => now()->toDateTimeString(),
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

    public function getPaginationButtons(): array
    {
        $buttons = [];
        $current = $this->page;
        $total = $this->totalPages;

        $buttons[] = [
            'page' => 1,
            'label' => '1',
            'active' => $current === 1,
        ];

        $start = max(2, $current - 2);
        $end = min($total - 1, $current + 2);

        if ($start > 2) {
            $buttons[] = [
                'page' => null,
                'label' => '...',
                'disabled' => true,
            ];
        }

        for ($i = $start; $i <= $end; $i++) {
            $buttons[] = [
                'page' => $i,
                'label' => (string) $i,
                'active' => $current === $i,
            ];
        }

        if ($end < $total - 1) {
            $buttons[] = [
                'page' => null,
                'label' => '...',
                'disabled' => true,
            ];
        }

        if ($total > 1) {
            $buttons[] = [
                'page' => $total,
                'label' => (string) $total,
                'active' => $current === $total,
            ];
        }

        return $buttons;
    }

    protected function getCacheKey($type, ...$params)
    {
        return "list_products_{$type}_" . md5(serialize($params));
    }
}; ?>

<div>
    <x-header title="{{ $listTitle }}" subtitle="Page {{ $page }} sur {{ $totalPages }} ({{ $totalItems }} produits)" separator>
        <x-slot:middle class="!justify-end">
            <x-input icon="o-bolt" placeholder="Search..." />
        </x-slot:middle>
        <x-slot:actions>
            <x-button label="Exécuter la recherche de prix concurrent" class="btn-primary" />
        </x-slot:actions>
    </x-header>

    <!-- Table des produits -->
    <div class="overflow-x-auto rounded-box border border-base-content/5 bg-base-100 mb-6">
        <table class="table">
            <thead>
                <tr>
                    <th></th>
                    <th>#</th>
                    <th>Image</th>
                    <th>EAN/SKU</th>
                    <th>Nom</th>
                    <th>Marque</th>
                    <th>Type</th>
                    <th>Prix</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @if($loading)
                    <tr>
                        <td colspan="9" class="text-center py-12">
                            <div class="flex flex-col items-center gap-3">
                                <span class="loading loading-spinner loading-lg text-primary"></span>
                                <span class="text-lg">Chargement des produits...</span>
                            </div>
                        </td>
                    </tr>
                @elseif(count($products) === 0 && !$loading)
                    <tr>
                        <td colspan="9" class="text-center py-12 text-base-content/50">
                            <div class="flex flex-col items-center gap-3">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 text-base-content/20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                </svg>
                                <span class="text-lg">Aucun produit dans cette liste</span>
                            </div>
                        </td>
                    </tr>
                @else
                    @foreach($products as $index => $product)
                        @php
                            $rowNumber = (($page - 1) * $perPage) + $index + 1;
                            $isExpanded = isset($expandedRows[$product['sku']]);
                            $hasSimilar = isset($similarProducts[$product['sku']]) && count($similarProducts[$product['sku']]) > 0;
                            $isLoading = isset($loadingSimilar[$product['sku']]) && $loadingSimilar[$product['sku']];
                        @endphp
                        
                        <!-- Ligne principale du produit -->
                        <tr wire:key="product-{{ $product['sku'] }}-{{ $page }}-{{ $index }}" 
                            class="{{ $isExpanded ? 'bg-blue-50' : '' }}">
                            <td>
                                <button 
                                    wire:click="toggleSimilarProducts('{{ $product['sku'] }}')"
                                    class="btn btn-ghost btn-xs"
                                    wire:loading.attr="disabled"
                                >
                                    @if($isLoading)
                                        <span class="loading loading-spinner loading-xs"></span>
                                    @else
                                        <svg xmlns="http://www.w3.org/2000/svg" 
                                             class="h-4 w-4 transition-transform {{ $isExpanded ? 'rotate-90' : '' }}" 
                                             fill="none" 
                                             viewBox="0 0 24 24" 
                                             stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                        </svg>
                                    @endif
                                </button>
                            </td>
                            <th>{{ $rowNumber }}</th>
                            <td>
                                @if(!empty($product['thumbnail']))
                                    <div class="avatar">
                                        <div class="w-12 h-12 rounded">
                                            <img 
                                                src="https://www.cosma-parfumeries.com/media/catalog/product/{{ $product['thumbnail'] }}"
                                                alt="{{ $product['title'] ?? '' }}"
                                                class="object-cover"
                                                loading="lazy"
                                            >
                                        </div>
                                    </div>
                                @elseif(!empty($product['swatch_image']))
                                    <div class="avatar">
                                        <div class="w-12 h-12 rounded">
                                            <img 
                                                src="https://www.cosma-parfumeries.com/media/catalog/product/{{ $product['swatch_image'] }}"
                                                alt="{{ $product['title'] ?? '' }}"
                                                class="object-cover"
                                                loading="lazy"
                                            >
                                        </div>
                                    </div>
                                @else
                                    <div class="w-12 h-12 bg-base-300 rounded flex items-center justify-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-base-content/40" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                    </div>
                                @endif
                            </td>
                            <td class="font-mono text-sm">
                                <div class="tooltip" data-tip="Cliquer pour copier">
                                    <button 
                                        onclick="copySku('{{ $product['sku'] }}')"
                                        class="hover:text-primary transition-colors"
                                    >
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
                                @if($hasSimilar)
                                    <span class="badge badge-info badge-sm">
                                        {{ count($similarProducts[$product['sku']]) }} similaire(s)
                                    </span>
                                @endif
                            </td>
                        </tr>

                        <!-- Ligne des produits similaires (collapsible) -->
                        @if($isExpanded && $hasSimilar)
                            <tr wire:key="similar-{{ $product['sku'] }}-{{ $page }}-{{ $index }}" class="bg-base-200">
                                <td colspan="9" class="p-0">
                                    <div class="collapse collapse-open">
                                        <div class="collapse-content">
                                            <div class="overflow-x-auto">
                                                <table class="table table-compact w-full">
                                                    <thead>
                                                        <tr class="bg-base-300">
                                                            <th>Image</th>
                                                            <th>Site</th>
                                                            <th>Nom</th>
                                                            <th>Variation</th>
                                                            <th>Prix HT</th>
                                                            <th>Date MAJ</th>
                                                            <th>Action</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach($similarProducts[$product['sku']] as $similar)
                                                            <tr class="hover">
                                                                <td>
                                                                    @if(!empty($similar->image))
                                                                        <div class="avatar">
                                                                            <div class="w-10 h-10 rounded">
                                                                                <img src="{{ $similar->image }}" alt="{{ $similar->name ?? '' }}" loading="lazy">
                                                                            </div>
                                                                        </div>
                                                                    @else
                                                                        <div class="w-10 h-10 bg-base-300 rounded"></div>
                                                                    @endif
                                                                </td>
                                                                <td>
                                                                    <span class="badge badge-ghost badge-sm">{{ $similar->site_name ?? 'N/A' }}</span>
                                                                </td>
                                                                <td>
                                                                    <div class="text-sm max-w-xs truncate" title="{{ $similar->name ?? '' }}">
                                                                        {{ $similar->name ?? 'N/A' }}
                                                                    </div>
                                                                </td>
                                                                <td>
                                                                    <span class="text-xs">{{ $similar->variation ?? 'Standard' }}</span>
                                                                </td>
                                                                <td>
                                                                    <span class="font-semibold text-success">
                                                                        {{ number_format($similar->prix_ht ?? 0, 2) }} €
                                                                    </span>
                                                                </td>
                                                                <td>
                                                                    <span class="text-xs opacity-60">
                                                                        {{ isset($similar->updated_at) ? \Carbon\Carbon::parse($similar->updated_at)->format('d/m/Y') : 'N/A' }}
                                                                    </span>
                                                                </td>
                                                                <td>
                                                                    @if(!empty($similar->product_url))
                                                                        <a href="{{ $similar->product_url }}" target="_blank" class="btn btn-ghost btn-xs">
                                                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                                                            </svg>
                                                                        </a>
                                                                    @endif
                                                                </td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                @endif
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    @if($totalPages > 1)
        <div class="flex flex-col sm:flex-row items-center justify-between gap-4 mt-6">
            <div class="text-sm text-base-content/60">
                Affichage des produits 
                <span class="font-medium">{{ min((($page - 1) * $perPage) + 1, $totalItems) }}</span>
                à 
                <span class="font-medium">{{ min($page * $perPage, $totalItems) }}</span>
                sur 
                <span class="font-medium">{{ $totalItems }}</span> 
                au total
            </div>
            
            <div class="join">
                <button 
                    class="join-item btn"
                    wire:click="previousPage"
                    wire:loading.attr="disabled"
                    @disabled($page <= 1)
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>
                
                @foreach($this->getPaginationButtons() as $button)
                    @if($button['page'] === null)
                        <button class="join-item btn btn-disabled" disabled>
                            {{ $button['label'] }}
                        </button>
                    @else
                        <button 
                            class="join-item btn {{ $button['active'] ? 'btn-active' : '' }}"
                            wire:click="goToPage({{ $button['page'] }})"
                            wire:loading.attr="disabled"
                        >
                            {{ $button['label'] }}
                        </button>
                    @endif
                @endforeach
                
                <button 
                    class="join-item btn"
                    wire:click="nextPage"
                    wire:loading.attr="disabled"
                    @disabled($page >= $totalPages)
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </button>
            </div>
        </div>
    @endif
    @push('scripts')
    <script>
        // Fonction pour copier le SKU
        function copySku(sku) {
            navigator.clipboard.writeText(sku).then(() => {
                const toast = document.createElement('div');
                toast.className = `toast toast-top toast-end`;
                toast.innerHTML = `
                    <div class="alert alert-success">
                        <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>SKU ${sku} copié !</span>
                    </div>
                `;
                document.body.appendChild(toast);
                
                setTimeout(() => {
                    toast.remove();
                }, 2000);
            }).catch(err => {
                console.error('Erreur copie:', err);
            });
        }
    </script>
    @endpush

    @push('styles')
    <style>
        /* Animation pour l'expansion des lignes */
        .collapse {
            transition: all 0.3s ease-in-out;
        }
        
        /* Animation de rotation pour la flèche */
        svg {
            transition: transform 0.2s ease-in-out;
        }
        
        /* Style pour les lignes expandées */
        tr.bg-blue-50 {
            background-color: rgba(219, 234, 254, 0.5);
        }
        
        /* Hover effect sur les lignes de produits similaires */
        .hover:hover {
            background-color: rgba(229, 231, 235, 0.5);
        }
        
        /* Style pour le loading spinner */
        .loading-spinner {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from {
                transform: rotate(0deg);
            }
            to {
                transform: rotate(360deg);
            }
        }
    </style>
    @endpush
</div>