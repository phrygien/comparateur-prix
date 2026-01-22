<?php

namespace App\Livewire;

use Livewire\Volt\Component;
use App\Models\DetailProduct;
use App\Models\Comparaison;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Models\Site as WebSite;

new class extends Component {

    public int $id;
    public string $listTitle = '';
    public bool $loading = true;
    public bool $loadingMore = false;
    public bool $hasMore = true;
    public int $page = 1;
    public int $perPage = 200;
    public int $totalPages = 1;
    public array $expandedRows = [];
    public array $similarProducts = [];
    public array $loadingSimilarProducts = [];
    public bool $showAllSimilarProducts = false;

    // Cache
    protected $cacheTTL = 3600;
    private array $knownVendors = [];
    private bool $vendorsLoaded = false;

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

    // Changer de page
    public function goToPage($page): void
    {
        if ($page < 1 || $page > $this->totalPages || $page === $this->page) {
            return;
        }

        $this->loading = true;
        $this->page = (int) $page;
        $this->resetSimilarProducts();
    }

    // Page précédente
    public function previousPage(): void
    {
        if ($this->page > 1) {
            $this->goToPage($this->page - 1);
        }
    }

    // Page suivante
    public function nextPage(): void
    {
        if ($this->page < $this->totalPages) {
            $this->goToPage($this->page + 1);
        }
    }

    // Rafraîchir la liste
    public function refreshProducts(): void
    {
        $this->page = 1;
        $this->loading = true;
        $this->loadListTitle();
        $this->resetSimilarProducts();
    }

    // Réinitialiser les produits similaires
    public function resetSimilarProducts(): void
    {
        $this->expandedRows = [];
        $this->similarProducts = [];
        $this->loadingSimilarProducts = [];
    }

    // Méthodes pour charger les vendors
    private function loadVendorsFromDatabase(): void
    {
        if ($this->vendorsLoaded) {
            return;
        }

        $cacheKey = 'all_vendors_list';
        $cachedVendors = Cache::get($cacheKey);

        if ($cachedVendors !== null) {
            $this->knownVendors = $cachedVendors;
            $this->vendorsLoaded = true;
            return;
        }

        try {
            $vendors = DB::connection('mysql')
                ->table('scraped_product')
                ->select('vendor')
                ->whereNotNull('vendor')
                ->where('vendor', '!=', '')
                ->distinct()
                ->get()
                ->pluck('vendor')
                ->toArray();

            $cleanVendors = [];
            foreach ($vendors as $vendor) {
                $clean = trim($vendor);
                if (!empty($clean) && strlen($clean) > 1) {
                    $cleanVendors[] = $clean;
                }
            }

            $this->knownVendors = array_unique($cleanVendors);
            Cache::put($cacheKey, $this->knownVendors, now()->addHours(24));
            $this->vendorsLoaded = true;

        } catch (\Throwable $e) {
            \Log::error('Error loading vendors:', ['error' => $e->getMessage()]);
            $this->knownVendors = [];
            $this->vendorsLoaded = true;
        }
    }

    // Méthode pour extraire le vendor du nom du produit
    private function extractVendorFromProductName(string $productName): string
    {
        $this->loadVendorsFromDatabase();
        
        if (empty($productName) || empty($this->knownVendors)) {
            return '';
        }

        $productNameLower = mb_strtolower(trim($productName));
        $bestMatch = '';
        $bestScore = 0;

        foreach ($this->knownVendors as $vendor) {
            $vendorLower = mb_strtolower($vendor);
            
            // Vérifier si le vendor est au début du nom du produit
            if (str_starts_with($productNameLower, $vendorLower)) {
                $score = 100;
            } 
            // Vérifier si le vendor est contenu dans le nom (avec séparateur)
            elseif (str_contains($productNameLower, ' ' . $vendorLower . ' ') || 
                   str_contains($productNameLower, '-' . $vendorLower . '-')) {
                $score = 90;
            }
            // Correspondance partielle
            elseif (str_contains($productNameLower, $vendorLower)) {
                $score = 80;
            } else {
                continue;
            }

            // Bonus pour la longueur du match
            $score += strlen($vendor) * 2;

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $vendor;
            }
        }

        return $bestMatch;
    }

    // Méthode pour extraire le premier mot du produit
    private function extractFirstProductWord(string $search, ?string $vendor = null): string
    {
        $search = trim($search);

        // Supprimer le vendor si présent
        if (!empty($vendor)) {
            $pattern = '/^' . preg_quote($vendor, '/') . '\s*-\s*/i';
            $search = preg_replace($pattern, '', $search);
            $search = preg_replace('/^\s*-\s*/', '', $search);
        }

        // Extraire les parties séparées par des tirets
        $parts = preg_split('/\s*-\s*/', $search, 3);

        // Le premier mot après le vendor est généralement dans la première partie
        $potentialPart = $parts[0] ?? '';

        // Supprimer les mots-clés de produit
        $productKeywords = [
            'eau de parfum',
            'eau de toilette',
            'parfum',
            'edp',
            'edt',
            'coffret',
            'spray',
            'ml',
            'pour homme',
            'pour femme'
        ];

        foreach ($productKeywords as $keyword) {
            $potentialPart = str_ireplace($keyword, '', $potentialPart);
        }

        // Extraire le premier mot
        $words = preg_split('/\s+/', trim($potentialPart), 2);
        $firstWord = $words[0] ?? '';

        // Nettoyer le mot
        $firstWord = preg_replace('/[^a-zA-ZÀ-ÿ0-9]/', '', $firstWord);

        // Vérifier que ce n'est pas un mot vide ou stop word
        $stopWords = ['le', 'la', 'les', 'de', 'des', 'du', 'et', 'pour', 'avec'];
        if (strlen($firstWord) > 2 && !in_array(strtolower($firstWord), $stopWords)) {
            return $firstWord;
        }

        return '';
    }

    // Méthode pour rechercher les produits similaires
    public function findSimilarProducts(string $productTitle, string $sku): void
    {
        $this->loadingSimilarProducts[$sku] = true;

        try {
            // Extraire le vendor
            $vendor = $this->extractVendorFromProductName($productTitle);
            
            // Extraire le premier mot du produit
            $firstProductWord = $this->extractFirstProductWord($productTitle, $vendor);
            
            // Construire la requête de recherche
            $searchTerms = [];
            if (!empty($vendor)) {
                $searchTerms[] = $vendor;
            }
            if (!empty($firstProductWord)) {
                $searchTerms[] = $firstProductWord;
            }

            if (empty($searchTerms)) {
                $this->similarProducts[$sku] = [];
                $this->loadingSimilarProducts[$sku] = false;
                return;
            }

            // Rechercher dans la base de données des concurrents
            $limit = $this->showAllSimilarProducts ? 50 : 10;
            
            $sql = "SELECT 
                    lp.*, 
                    ws.name as site_name, 
                    lp.url as product_url, 
                    lp.image_url as image,
                    lp.prix_ht as price_ht
                FROM last_price_scraped_product lp
                LEFT JOIN web_site ws ON lp.web_site_id = ws.id
                WHERE (lp.variation != 'Standard' OR lp.variation IS NULL OR lp.variation = '')
                AND (";

            $params = [];
            $conditions = [];

            foreach ($searchTerms as $term) {
                $conditions[] = "(lp.name LIKE ? OR lp.vendor LIKE ? OR lp.variation LIKE ?)";
                $params[] = '%' . $term . '%';
                $params[] = '%' . $term . '%';
                $params[] = '%' . $term . '%';
            }

            $sql .= implode(' OR ', $conditions) . ") ORDER BY lp.prix_ht ASC LIMIT $limit";

            $results = DB::connection('mysql')->select($sql, $params);

            // Traiter les résultats
            $processedResults = [];
            foreach ($results as $product) {
                $price = $this->cleanPrice($product->price_ht);
                $processedResults[] = [
                    'vendor' => $product->vendor ?? 'N/A',
                    'name' => $product->name ?? 'N/A',
                    'variation' => $product->variation ?? 'Standard',
                    'site_name' => $product->site_name ?? 'N/A',
                    'price_ht' => $price !== null ? number_format($price, 2) . ' €' : 'N/A',
                    'price_ht_raw' => $price,
                    'product_url' => $product->product_url ?? $product->url ?? null,
                    'image' => $product->image ?? $product->image_url ?? null,
                    'updated_at' => $product->updated_at ?? null
                ];
            }

            $this->similarProducts[$sku] = $processedResults;

        } catch (\Throwable $e) {
            Log::error('Error finding similar products: ' . $e->getMessage());
            $this->similarProducts[$sku] = [];
        }

        $this->loadingSimilarProducts[$sku] = false;
    }

    // Méthode pour basculer l'affichage des produits similaires
    public function toggleSimilarProducts(string $sku, string $productTitle): void
    {
        if (isset($this->expandedRows[$sku])) {
            unset($this->expandedRows[$sku]);
        } else {
            $this->expandedRows[$sku] = true;
            
            // Si les produits similaires ne sont pas déjà chargés, les rechercher
            if (!isset($this->similarProducts[$sku])) {
                $this->findSimilarProducts($productTitle, $sku);
            }
        }
    }

    // Méthode pour basculer l'affichage de tous les produits similaires
    public function toggleShowAllSimilarProducts(): void
    {
        $this->showAllSimilarProducts = !$this->showAllSimilarProducts;
        
        // Recharger tous les produits similaires déjà ouverts
        foreach ($this->expandedRows as $sku => $isExpanded) {
            if ($isExpanded) {
                // Trouver le titre du produit
                foreach ($this->products as $product) {
                    if ($product['sku'] == $sku) {
                        $this->findSimilarProducts($product['title'], $sku);
                        break;
                    }
                }
            }
        }
    }

    // Méthode utilitaire pour nettoyer les prix
    private function cleanPrice($price)
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

    // Méthode pour extraire le domaine d'une URL
    public function extractDomain($url)
    {
        if (empty($url)) {
            return 'N/A';
        }

        try {
            $parsedUrl = parse_url($url);
            if (isset($parsedUrl['host'])) {
                $domain = $parsedUrl['host'];
                if (strpos($domain, 'www.') === 0) {
                    $domain = substr($domain, 4);
                }
                return $domain;
            }
        } catch (\Exception $e) {
            Log::error('Error extracting domain:', ['url' => $url]);
        }

        return 'N/A';
    }

    // Méthode pour obtenir les statistiques de prix
    public function getPriceStatistics($similarProducts, $ourPrice)
    {
        if (empty($similarProducts)) {
            return null;
        }

        $prices = array_filter(array_column($similarProducts, 'price_ht_raw'));
        
        if (empty($prices)) {
            return null;
        }

        return [
            'min' => min($prices),
            'max' => max($prices),
            'avg' => array_sum($prices) / count($prices),
            'count' => count($prices),
            'our_price' => $ourPrice,
            'our_position' => $ourPrice <= (array_sum($prices) / count($prices)) ? 'competitive' : 'above_average'
        ];
    }

    // Méthode pour calculer la différence de prix
    public function calculatePriceDifference($competitorPrice, $ourPrice)
    {
        $cleanCompetitorPrice = $this->cleanPrice($competitorPrice);
        $cleanOurPrice = $this->cleanPrice($ourPrice);

        if ($cleanCompetitorPrice === null || $cleanOurPrice === null) {
            return null;
        }

        return $cleanOurPrice - $cleanCompetitorPrice;
    }

    // Méthode pour formater la différence de prix
    public function formatPriceDifference($difference)
    {
        if ($difference === null) {
            return 'N/A';
        }

        if ($difference == 0) {
            return '0 €';
        }

        $formatted = number_format(abs($difference), 2, ',', ' ');
        return $difference > 0 ? "+{$formatted} €" : "-{$formatted} €";
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
                $this->totalPages = 1;
                return [
                    'products' => [],
                    'totalItems' => 0,
                    'totalPages' => 1,
                    'allSkus' => [],
                ];
            }

            // Calculer le nombre total de pages
            $this->totalPages = max(1, ceil($totalItems / $this->perPage));

            // Charger uniquement la page courante
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
                LEFT JOIN cataloginventory_stock_status AS stock_status ON stock_status.product_id = stock_status.product_id 
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

    // Générer les boutons de pagination
    public function getPaginationButtons(): array
    {
        $buttons = [];
        $current = $this->page;
        $total = $this->totalPages;

        // Toujours afficher la première page
        $buttons[] = [
            'page' => 1,
            'label' => '1',
            'active' => $current === 1,
        ];

        // Afficher les pages autour de la page courante
        $start = max(2, $current - 2);
        $end = min($total - 1, $current + 2);

        // Ajouter "..." après la première page si nécessaire
        if ($start > 2) {
            $buttons[] = [
                'page' => null,
                'label' => '...',
                'disabled' => true,
            ];
        }

        // Pages du milieu
        for ($i = $start; $i <= $end; $i++) {
            $buttons[] = [
                'page' => $i,
                'label' => (string) $i,
                'active' => $current === $i,
            ];
        }

        // Ajouter "..." avant la dernière page si nécessaire
        if ($end < $total - 1) {
            $buttons[] = [
                'page' => null,
                'label' => '...',
                'disabled' => true,
            ];
        }

        // Toujours afficher la dernière page si elle existe
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
    
    // Exécuter la recherche de prix concurrent pour tous les produits
    public function executeCompetitorSearch(): void
    {
        $this->showAllSimilarProducts = true;
        
        // Fermer toutes les lignes ouvertes
        $this->expandedRows = [];
        $this->similarProducts = [];
        $this->loadingSimilarProducts = [];
        
        // Ouvrir et rechercher pour tous les produits de la page
        foreach ($this->products as $product) {
            $this->expandedRows[$product['sku']] = true;
            $this->findSimilarProducts($product['title'], $product['sku']);
        }
    }
}; ?>

<div>
    <x-header title="{{ $listTitle }}" subtitle="Page {{ $page }} sur {{ $totalPages }} ({{ $totalItems }} produits)" separator>
        <x-slot:middle class="!justify-end">
            <x-input icon="o-bolt" placeholder="Search..." />
        </x-slot:middle>
        <x-slot:actions>
            <x-button label="Exécuter la recherche de prix concurrent" 
                      class="btn-primary" 
                      wire:click="executeCompetitorSearch"
                      wire:loading.attr="disabled" />
        </x-slot:actions>
    </x-header>

    <!-- Contrôle pour afficher tous les produits similaires -->
    @if(count($expandedRows) > 0)
        <div class="mb-4 p-3 bg-blue-50 rounded-lg border border-blue-200">
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-blue-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span class="text-sm font-medium text-blue-800">
                        {{ count($expandedRows) }} produit(s) en cours d'analyse
                    </span>
                </div>
                <div class="flex items-center space-x-3">
                    <label class="inline-flex items-center cursor-pointer">
                        <input type="checkbox" 
                               wire:model.live="showAllSimilarProducts" 
                               class="sr-only peer"
                               wire:change="toggleShowAllSimilarProducts">
                        <div class="relative w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                        <span class="ml-3 text-sm font-medium text-gray-700">
                            Afficher tous les résultats ({{ $showAllSimilarProducts ? '50' : '10' }} par produit)
                        </span>
                    </label>
                    <button wire:click="resetSimilarProducts"
                            class="px-3 py-1.5 text-sm bg-white text-red-700 hover:bg-red-50 rounded-md transition-colors duration-200 flex items-center border border-red-200">
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                        Fermer toutes les analyses
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Table des produits -->
    <div class="overflow-x-auto rounded-box border border-base-content/5 bg-base-100 mb-6">
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
                    <th>Comparaison</th>
                </tr>
            </thead>
            <tbody>
                @if($loading)
                    <!-- État de chargement initial -->
                    <tr>
                        <td colspan="8" class="text-center py-12">
                            <div class="flex flex-col items-center gap-3">
                                <span class="loading loading-spinner loading-lg text-primary"></span>
                                <span class="text-lg">Chargement des produits...</span>
                            </div>
                        </td>
                    </tr>
                @elseif(count($products) === 0 && !$loading)
                    <!-- Aucun produit -->
                    <tr>
                        <td colspan="8" class="text-center py-12 text-base-content/50">
                            <div class="flex flex-col items-center gap-3">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 text-base-content/20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                </svg>
                                <span class="text-lg">Aucun produit dans cette liste</span>
                            </div>
                        </td>
                    </tr>
                @else
                    <!-- Liste des produits -->
                    @foreach($products as $index => $product)
                        @php
                            $rowNumber = (($page - 1) * $perPage) + $index + 1;
                            $isExpanded = isset($expandedRows[$product['sku']]);
                            $hasSimilarProducts = isset($similarProducts[$product['sku']]) && count($similarProducts[$product['sku']]) > 0;
                            $isLoading = isset($loadingSimilarProducts[$product['sku']]) && $loadingSimilarProducts[$product['sku']];
                        @endphp
                        
                        <!-- Ligne principale du produit -->
                        <tr wire:key="product-{{ $product['sku'] }}-{{ $page }}-{{ $index }}"
                            class="{{ $isExpanded ? 'bg-blue-50' : '' }}">
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
                                @php
                                    $ourPrice = $product['special_price'] > 0 ? $product['special_price'] : ($product['price'] ?? 0);
                                @endphp
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
                                <!-- Bouton pour afficher/masquer les produits similaires -->
                                <button 
                                    wire:click="toggleSimilarProducts('{{ $product['sku'] }}', '{{ $product['title'] }}')"
                                    class="btn btn-xs {{ $isExpanded ? 'btn-primary' : 'btn-outline' }} {{ $hasSimilarProducts ? 'btn-success' : '' }}"
                                    title="{{ $isExpanded ? 'Masquer les produits similaires' : 'Trouver des produits similaires' }}"
                                    wire:loading.attr="disabled"
                                >
                                    @if($isLoading)
                                        <span class="loading loading-spinner loading-xs"></span>
                                    @elseif($isExpanded)
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"></path>
                                        </svg>
                                        Masquer
                                    @else
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                        </svg>
                                        Comparer
                                    @endif
                                    @if($hasSimilarProducts)
                                        <span class="badge badge-xs ml-1">{{ count($similarProducts[$product['sku']]) }}</span>
                                    @endif
                                </button>
                            </td>
                        </tr>
                        
                        <!-- Ligne des produits similaires (collapsible) -->
                        @if($isExpanded)
                            <tr class="bg-blue-50 border-t border-blue-200">
                                <td colspan="8" class="p-0">
                                    <div class="p-4">
                                        @if($isLoading)
                                            <!-- État de chargement -->
                                            <div class="text-center py-4">
                                                <div class="flex flex-col items-center gap-2">
                                                    <span class="loading loading-spinner loading-sm text-primary"></span>
                                                    <span class="text-sm">Recherche des produits similaires...</span>
                                                </div>
                                            </div>
                                        @elseif($hasSimilarProducts)
                                            <!-- Tableau des produits similaires -->
                                            <div class="mb-4">
                                                <h4 class="font-semibold text-blue-800 mb-3 flex items-center">
                                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                                    </svg>
                                                    Produits similaires chez les concurrents ({{ count($similarProducts[$product['sku']]) }} trouvé(s))
                                                </h4>
                                                
                                                <div class="overflow-x-auto">
                                                    <table class="table table-xs table-zebra">
                                                        <thead>
                                                            <tr>
                                                                <th>Site</th>
                                                                <th>Vendor</th>
                                                                <th>Nom du produit</th>
                                                                <th>Variation</th>
                                                                <th>Prix HT</th>
                                                                <th>Dernière mise à jour</th>
                                                                <th>Actions</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            @foreach($similarProducts[$product['sku']] as $similarIndex => $similarProduct)
                                                                @php
                                                                    $priceDiff = $this->calculatePriceDifference(
                                                                        str_replace(' €', '', $similarProduct['price_ht']),
                                                                        $ourPrice
                                                                    );
                                                                @endphp
                                                                <tr>
                                                                    <td class="font-medium">{{ $similarProduct['site_name'] }}</td>
                                                                    <td>{{ $similarProduct['vendor'] }}</td>
                                                                    <td class="max-w-xs truncate" title="{{ $similarProduct['name'] }}">
                                                                        {{ $similarProduct['name'] }}
                                                                    </td>
                                                                    <td>
                                                                        <span class="badge badge-outline">{{ $similarProduct['variation'] }}</span>
                                                                    </td>
                                                                    <td class="font-semibold {{ $priceDiff > 0 ? 'text-green-600' : ($priceDiff < 0 ? 'text-red-600' : 'text-blue-600') }}">
                                                                        {{ $similarProduct['price_ht'] }}
                                                                        @if($priceDiff !== null)
                                                                            <div class="text-xs {{ $priceDiff > 0 ? 'text-green-600' : ($priceDiff < 0 ? 'text-red-600' : 'text-blue-600') }}">
                                                                                {{ $this->formatPriceDifference($priceDiff) }}
                                                                            </div>
                                                                        @endif
                                                                    </td>
                                                                    <td class="text-xs">
                                                                        @if($similarProduct['updated_at'])
                                                                            {{ \Carbon\Carbon::parse($similarProduct['updated_at'])->format('d/m/Y') }}
                                                                        @else
                                                                            N/A
                                                                        @endif
                                                                    </td>
                                                                    <td>
                                                                        @if(!empty($similarProduct['product_url']))
                                                                            <a href="{{ $similarProduct['product_url'] }}" 
                                                                               target="_blank" 
                                                                               rel="noopener noreferrer"
                                                                               class="btn btn-xs btn-outline btn-info"
                                                                               title="Voir le produit">
                                                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                                                                </svg>
                                                                                Voir
                                                                            </a>
                                                                        @endif
                                                                    </td>
                                                                </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                            
                                            <!-- Analyse des prix -->
                                            @php
                                                $priceStats = $this->getPriceStatistics($similarProducts[$product['sku']], $ourPrice);
                                            @endphp
                                            
                                            @if($priceStats)
                                                <div class="bg-white p-4 rounded-lg border border-blue-200">
                                                    <h5 class="font-semibold text-blue-800 mb-3">Analyse comparative des prix</h5>
                                                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                                                        <div class="text-center p-3 bg-gray-50 rounded-lg">
                                                            <div class="text-2xl font-bold text-green-600">{{ number_format($priceStats['min'], 2) }} €</div>
                                                            <div class="text-sm text-gray-600">Prix minimum</div>
                                                            <div class="text-xs text-green-600 mt-1">
                                                                {{ $this->formatPriceDifference($ourPrice - $priceStats['min']) }}
                                                            </div>
                                                        </div>
                                                        <div class="text-center p-3 bg-gray-50 rounded-lg">
                                                            <div class="text-2xl font-bold text-blue-600">{{ number_format($priceStats['avg'], 2) }} €</div>
                                                            <div class="text-sm text-gray-600">Prix moyen</div>
                                                            <div class="text-xs {{ $priceStats['our_position'] === 'competitive' ? 'text-green-600' : 'text-red-600' }} mt-1">
                                                                {{ $this->formatPriceDifference($ourPrice - $priceStats['avg']) }}
                                                            </div>
                                                        </div>
                                                        <div class="text-center p-3 bg-gray-50 rounded-lg">
                                                            <div class="text-2xl font-bold text-red-600">{{ number_format($priceStats['max'], 2) }} €</div>
                                                            <div class="text-sm text-gray-600">Prix maximum</div>
                                                            <div class="text-xs text-red-600 mt-1">
                                                                {{ $this->formatPriceDifference($ourPrice - $priceStats['max']) }}
                                                            </div>
                                                        </div>
                                                        <div class="text-center p-3 {{ $priceStats['our_position'] === 'competitive' ? 'bg-green-50' : 'bg-red-50' }} rounded-lg border {{ $priceStats['our_position'] === 'competitive' ? 'border-green-200' : 'border-red-200' }}">
                                                            <div class="text-2xl font-bold {{ $priceStats['our_position'] === 'competitive' ? 'text-green-600' : 'text-red-600' }}">
                                                                {{ number_format($ourPrice, 2) }} €
                                                            </div>
                                                            <div class="text-sm text-gray-600">Notre prix</div>
                                                            <div class="text-xs mt-1 font-semibold {{ $priceStats['our_position'] === 'competitive' ? 'text-green-600' : 'text-red-600' }}">
                                                                @if($priceStats['our_position'] === 'competitive')
                                                                    ✅ Nous sommes compétitifs
                                                                @else
                                                                    ⚠️ Au-dessus de la moyenne
                                                                @endif
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="mt-3 text-xs text-gray-500 text-center">
                                                        Basé sur {{ $priceStats['count'] }} prix concurrents analysés
                                                    </div>
                                                </div>
                                            @endif
                                        @else
                                            <!-- Aucun produit similaire trouvé -->
                                            <div class="text-center py-8 text-gray-500">
                                                <svg class="w-12 h-12 mx-auto text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                <p>Aucun produit similaire trouvé chez les concurrents</p>
                                                <p class="text-sm mt-2">Essayez de modifier les critères de recherche</p>
                                            </div>
                                        @endif
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
            <!-- Informations -->
            <div class="text-sm text-base-content/60">
                Affichage des produits 
                <span class="font-medium">{{ min((($page - 1) * $perPage) + 1, $totalItems) }}</span>
                à 
                <span class="font-medium">{{ min($page * $perPage, $totalItems) }}</span>
                sur 
                <span class="font-medium">{{ $totalItems }}</span> 
                au total
            </div>
            
            <!-- Boutons de pagination -->
            <div class="join">
                <!-- Bouton précédent -->
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
                
                <!-- Boutons de pages -->
                @foreach($this->getPaginationButtons() as $button)
                    @if($button['page'] === null)
                        <!-- Séparateur "..." -->
                        <button class="join-item btn btn-disabled" disabled>
                            {{ $button['label'] }}
                        </button>
                    @else
                        <!-- Bouton de page -->
                        <button 
                            class="join-item btn {{ $button['active'] ? 'btn-active' : '' }}"
                            wire:click="goToPage({{ $button['page'] }})"
                            wire:loading.attr="disabled"
                        >
                            {{ $button['label'] }}
                        </button>
                    @endif
                @endforeach
                
                <!-- Bouton suivant -->
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
</div>

<script>
    // Fonction pour copier le SKU
    function copySku(sku) {
        navigator.clipboard.writeText(sku).then(() => {
            // Créer une notification simple
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

<style>
    /* Animation pour l'expansion des lignes */
    tr {
        transition: background-color 0.3s ease;
    }
    
    /* Style pour les tableaux imbriqués */
    .table-zebra tbody tr:nth-child(even) {
        background-color: #f9fafb;
    }
    
    .table-zebra tbody tr:hover {
        background-color: #f0f9ff;
    }
    
    /* Style pour les badges */
    .badge {
        padding: 0.25rem 0.75rem;
        font-size: 0.875rem;
        line-height: 1.25rem;
    }
    
    /* Style pour les boutons */
    .btn-xs {
        padding: 0.25rem 0.75rem;
        font-size: 0.75rem;
        line-height: 1rem;
    }
    
    /* Transition pour les boutons */
    button {
        transition: all 0.2s ease;
    }
    
    button:hover:not(:disabled) {
        transform: translateY(-1px);
    }
    
    /* Style pour les indicateurs de prix */
    .text-green-600 {
        color: #059669;
    }
    
    .text-red-600 {
        color: #dc2626;
    }
    
    .text-blue-600 {
        color: #2563eb;
    }
    
    /* Style pour les backgrounds */
    .bg-blue-50 {
        background-color: #eff6ff;
    }
    
    .bg-green-50 {
        background-color: #f0fdf4;
    }
    
    .bg-red-50 {
        background-color: #fef2f2;
    }
</style>