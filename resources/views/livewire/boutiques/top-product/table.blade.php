<?php

use Livewire\Volt\Component;
use App\Models\DetailProduct;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;

new class extends Component {
    public string $id = '';
    
    // Filtres par site (sites autorisés : 1, 2, 8, 16)
    public array $availableSites = [];
    public array $selectedSites = [];
    
    // Résultats de la recherche
    public array $competitorResults = [];
    public array $searchingProducts = [];
    public array $expandedProducts = [];

    // Produit sélectionné pour la vue détaillée
    public ?array $selectedProduct = null;

    public function mount($id): void
    {
        $this->id = $id;
        $this->loadAvailableSites();
        
        // Initialiser avec tous les sites sélectionnés par défaut
        $this->selectedSites = array_column($this->availableSites, 'id');
    }

    /**
     * Charger la liste des sites disponibles (sites autorisés : 1, 2, 8, 16)
     */
    protected function loadAvailableSites(): void
    {
        try {
            $sites = DB::connection('mysql')
                ->table('web_site')
                ->select('id', 'name')
                ->whereIn('id', [1, 2, 8, 16])
                ->orderBy('name')
                ->get()
                ->toArray();

            $this->availableSites = array_map(fn($site) => [
                'id' => $site->id,
                'name' => $site->name
            ], $sites);

        } catch (\Exception $e) {
            \Log::error('Erreur chargement sites', ['error' => $e->getMessage()]);
            $this->availableSites = [];
        }
    }

    /**
     * Basculer la sélection d'un site
     */
    public function toggleSiteFilter(int $siteId): void
    {
        $key = array_search($siteId, $this->selectedSites);

        if ($key !== false) {
            unset($this->selectedSites[$key]);
            $this->selectedSites = array_values($this->selectedSites);
        } else {
            $this->selectedSites[] = $siteId;
        }
    }

    /**
     * Sélectionner tous les sites
     */
    public function selectAllSites(): void
    {
        $this->selectedSites = array_column($this->availableSites, 'id');
    }

    /**
     * Désélectionner tous les sites
     */
    public function deselectAllSites(): void
    {
        $this->selectedSites = [];
    }

    /**
     * Vérifier si un site est sélectionné
     */
    public function isSiteSelected(int $siteId): bool
    {
        return in_array($siteId, $this->selectedSites);
    }

    /**
     * Récupérer les détails d'un produit depuis Magento par EAN
     */
    protected function getProductDetailsFromMagento(string $ean): ?array
    {
        try {
            $query = "
                SELECT 
                    produit.sku as sku,
                    produit.entity_id,
                    CAST(product_char.name AS CHAR CHARACTER SET utf8mb4) as title,
                    product_char.thumbnail as thumbnail,
                    product_char.swatch_image as swatch_image,
                    SUBSTRING_INDEX(product_char.name, ' - ', 1) as vendor,
                    SUBSTRING_INDEX(eas.attribute_set_name, '_', -1) as type,
                    ROUND(product_decimal.price, 2) as price,
                    ROUND(product_decimal.special_price, 2) as special_price,
                    stock_item.qty as quantity,
                    stock_status.stock_status as quantity_status,
                    product_char.reference as reference,
                    product_char.reference_us as reference_us,
                    product_int.status as status
                FROM catalog_product_entity as produit
                LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
                LEFT JOIN product_decimal ON product_decimal.entity_id = produit.entity_id
                LEFT JOIN product_int ON product_int.entity_id = produit.entity_id
                LEFT JOIN cataloginventory_stock_item AS stock_item ON stock_item.product_id = produit.entity_id 
                LEFT JOIN cataloginventory_stock_status AS stock_status ON stock_status.product_id = stock_item.product_id 
                LEFT JOIN eav_attribute_set AS eas ON produit.attribute_set_id = eas.attribute_set_id 
                WHERE produit.sku = ?
                AND product_int.status >= 0
                LIMIT 1
            ";

            $result = DB::connection('mysqlMagento')->select($query, [$ean]);

            if (!empty($result)) {
                $product = (array) $result[0];
                $product['price'] = $this->cleanPrice($product['price'] ?? 0);
                $product['special_price'] = $this->cleanPrice($product['special_price'] ?? 0);
                $product['final_price'] = !empty($product['special_price']) ? $product['special_price'] : $product['price'];
                
                // Construire l'URL de l'image
                if (!empty($product['swatch_image'])) {
                    $product['image_url'] = 'https://www.cosma-parfumeries.com/media/catalog/product' . $product['swatch_image'];
                } elseif (!empty($product['thumbnail']) && filter_var($product['thumbnail'], FILTER_VALIDATE_URL)) {
                    $product['image_url'] = $product['thumbnail'];
                } else {
                    $product['image_url'] = null;
                }
                
                return $product;
            }

            return null;

        } catch (\Exception $e) {
            \Log::error('Erreur récupération produit Magento', [
                'ean' => $ean,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * RECHERCHE PAR EAN - MÉTHODE PRINCIPALE
     */
    public function searchCompetitorsForProduct(string $ean): void
    {
        $this->searchingProducts[$ean] = true;

        try {
            // 1. Récupérer les détails du produit depuis Magento
            $productDetails = $this->getProductDetailsFromMagento($ean);
            
            if (!$productDetails) {
                \Log::warning('Produit non trouvé dans Magento', ['ean' => $ean]);
                $this->competitorResults[$ean] = [
                    'product' => null,
                    'error' => 'Produit non trouvé dans Magento',
                    'competitors' => [],
                    'count' => 0
                ];
                return;
            }

            $ourPrice = $productDetails['final_price'];

            \Log::info('Recherche concurrents par EAN', [
                'ean' => $ean,
                'product_name' => $productDetails['title'],
                'our_price' => $ourPrice,
                'selected_sites' => $this->selectedSites
            ]);

            // 2. Rechercher les concurrents par EAN
            $competitors = $this->findCompetitorsByEAN($ean, $ourPrice);

            \Log::info('Résultats trouvés', [
                'ean' => $ean,
                'total' => count($competitors)
            ]);

            $this->competitorResults[$ean] = [
                'product' => $productDetails,
                'competitors' => $competitors,
                'count' => count($competitors)
            ];

        } catch (\Exception $e) {
            \Log::error('Erreur recherche EAN', [
                'ean' => $ean,
                'error' => $e->getMessage()
            ]);
            
            $this->competitorResults[$ean] = [
                'product' => null,
                'error' => $e->getMessage(),
                'competitors' => [],
                'count' => 0
            ];
        } finally {
            unset($this->searchingProducts[$ean]);
        }
    }

    /**
     * RECHERCHE PAR EAN - REQUÊTE SQL AVEC PIVOT PAR SITE
     */
    protected function findCompetitorsByEAN(string $ean, float $ourPrice): array
    {
        try {
            // Sites autorisés uniquement : 1, 2, 8, 16
            $allowedSites = [1, 2, 8, 16];

            $query = "
                SELECT 
                    sp.id,
                    sp.ean,
                    sp.name,
                    sp.vendor,
                    sp.type,
                    sp.variation,
                    sp.url,
                    sp.image_url,
                    sp.web_site_id,
                    ws.name as site_name,
                    lp.prix_ht,
                    lp.updated_at
                FROM scraped_product sp
                LEFT JOIN web_site ws ON sp.web_site_id = ws.id
                LEFT JOIN last_price_scraped_product lp ON sp.id = lp.scraped_product_id
                WHERE sp.ean = ?
                AND sp.web_site_id IN (" . implode(',', $allowedSites) . ")
                AND lp.prix_ht IS NOT NULL
                AND lp.prix_ht > 0
                ORDER BY sp.web_site_id, lp.prix_ht ASC
            ";

            $results = DB::connection('mysql')->select($query, [$ean]);

            if (empty($results)) {
                \Log::info('Aucun concurrent trouvé', ['ean' => $ean]);
                return [];
            }

            // Pivoter : garder le meilleur prix par site
            $pivotedResults = $this->pivotResultsBySite($results, $allowedSites);

            // Filtrer par sites sélectionnés
            $filteredResults = $this->filterBySites($pivotedResults);

            // Ajouter les comparaisons de prix
            $competitors = [];
            foreach ($filteredResults as $result) {
                $result->clean_price = $this->cleanPrice($result->prix_ht ?? 0);
                $result->price_difference = $ourPrice - $result->clean_price;
                $result->price_difference_percent = $ourPrice > 0 ? 
                    (($ourPrice - $result->clean_price) / $ourPrice) * 100 : 0;
                
                // Déterminer le statut de prix
                if ($result->clean_price < $ourPrice * 0.9) {
                    $result->price_status = 'much_cheaper';
                } elseif ($result->clean_price < $ourPrice) {
                    $result->price_status = 'cheaper';
                } elseif ($result->clean_price == $ourPrice) {
                    $result->price_status = 'same';
                } elseif ($result->clean_price <= $ourPrice * 1.1) {
                    $result->price_status = 'slightly_higher';
                } else {
                    $result->price_status = 'much_higher';
                }

                // Score de similarité = 1.0 (correspondance exacte par EAN)
                $result->similarity_score = 1.0;
                $result->match_level = 'exact';

                // Traiter l'image
                $result->image = $this->getCompetitorImage($result);

                $competitors[] = $result;
            }

            return $competitors;

        } catch (\Exception $e) {
            \Log::error('Erreur findCompetitorsByEAN', [
                'ean' => $ean,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * PIVOT PAR SITE - Garder le meilleur prix (le plus bas) par site
     */
    protected function pivotResultsBySite(array $results, array $allowedSites): array
    {
        $pivoted = [];
        
        foreach ($results as $result) {
            $siteId = $result->web_site_id;
            
            if (!in_array($siteId, $allowedSites)) {
                continue;
            }

            // Garder le meilleur prix (le plus bas) par site
            if (!isset($pivoted[$siteId]) || 
                ($result->prix_ht < $pivoted[$siteId]->prix_ht)) {
                $pivoted[$siteId] = $result;
            }
        }

        return array_values($pivoted);
    }

    /**
     * Filtrer les résultats par sites sélectionnés
     */
    protected function filterBySites(array $results): array
    {
        if (empty($this->selectedSites)) {
            return [];
        }

        return array_filter($results, function($result) {
            return in_array($result->web_site_id, $this->selectedSites);
        });
    }

    /**
     * Nettoyer un prix
     */
    protected function cleanPrice($price): float
    {
        if ($price === null || $price === '' || $price === false) {
            return 0.0;
        }

        if (is_numeric($price)) {
            return (float) $price;
        }

        if (is_string($price)) {
            $cleanPrice = preg_replace('/[^\d,.-]/', '', $price);
            $cleanPrice = str_replace(',', '.', $cleanPrice);

            $parts = explode('.', $cleanPrice);
            if (count($parts) > 2) {
                $cleanPrice = $parts[0] . '.' . end($parts);
            }

            if (is_numeric($cleanPrice)) {
                return (float) $cleanPrice;
            }
        }

        return 0.0;
    }

    /**
     * Formater un prix pour l'affichage
     */
    public function formatPrice($price): string
    {
        $cleanPrice = $this->cleanPrice($price);
        return number_format($cleanPrice, 2, ',', ' ') . ' €';
    }

    /**
     * Obtenir l'image d'un concurrent
     */
    protected function getCompetitorImage($competitor): string
    {
        if (!empty($competitor->image_url)) {
            $imageUrl = $competitor->image_url;

            if (filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                return $imageUrl;
            }

            if (strpos($imageUrl, 'http') !== 0) {
                $productUrl = $competitor->url ?? '';
                if (!empty($productUrl)) {
                    $parsed = parse_url($productUrl);
                    if (isset($parsed['scheme']) && isset($parsed['host'])) {
                        $baseUrl = $parsed['scheme'] . '://' . $parsed['host'];
                        if (strpos($imageUrl, '/') === 0) {
                            return $baseUrl . $imageUrl;
                        }
                    }
                }
            }

            return $imageUrl;
        }

        if (!empty($competitor->url)) {
            return $competitor->url;
        }

        return 'https://placehold.co/100x100/cccccc/999999?text=No+Image';
    }

    /**
     * Obtenir le nom du statut de prix
     */
    public function getPriceStatusLabel(string $status): string
    {
        $labels = [
            'much_cheaper' => 'Beaucoup moins cher',
            'cheaper' => 'Moins cher',
            'same' => 'Même prix',
            'slightly_higher' => 'Légèrement plus cher',
            'much_higher' => 'Beaucoup plus cher'
        ];

        return $labels[$status] ?? 'Inconnu';
    }

    /**
     * Obtenir la classe CSS du statut de prix
     */
    public function getPriceStatusClass(string $status): string
    {
        $classes = [
            'much_cheaper' => 'badge-success',
            'cheaper' => 'badge-success',
            'same' => 'badge-info',
            'slightly_higher' => 'badge-warning',
            'much_higher' => 'badge-error'
        ];

        return $classes[$status] ?? 'badge-neutral';
    }

    /**
     * Formater une différence de prix
     */
    public function formatPriceDifference($difference): string
    {
        $cleanDiff = $this->cleanPrice($difference);
        $sign = $cleanDiff > 0 ? '+' : ($cleanDiff < 0 ? '-' : '');
        $absDiff = abs($cleanDiff);
        return $sign . number_format($absDiff, 2, ',', ' ') . ' €';
    }

    /**
     * Formater un pourcentage
     */
    public function formatPercentage($percentage): string
    {
        $cleanPercentage = $this->cleanPrice($percentage);
        $sign = $cleanPercentage > 0 ? '+' : ($cleanPercentage < 0 ? '-' : '');
        $absPercentage = abs($cleanPercentage);
        return $sign . number_format($absPercentage, 1, ',', ' ') . '%';
    }

    /**
     * Basculer l'affichage des concurrents pour un produit
     */
    public function toggleCompetitors(string $ean): void
    {
        if (isset($this->expandedProducts[$ean])) {
            unset($this->expandedProducts[$ean]);
        } else {
            $this->expandedProducts[$ean] = true;

            if (!isset($this->competitorResults[$ean])) {
                $this->searchCompetitorsForProduct($ean);
            }
        }
    }

    /**
     * Rechercher les concurrents pour TOUS les produits de la liste
     */
    public function searchAllCompetitors(): void
    {
        try {
            $eans = DetailProduct::where('list_product_id', $this->id)
                ->pluck('EAN')
                ->unique()
                ->toArray();

            foreach ($eans as $ean) {
                if (!empty($ean)) {
                    $this->searchCompetitorsForProduct($ean);
                }
            }

        } catch (\Exception $e) {
            \Log::error('Erreur recherche tous concurrents', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Récupérer les produits de la liste
     */
    #[Computed]
    public function products()
    {
        try {
            $detailProducts = DetailProduct::where('list_product_id', $this->id)
                ->get();

            $productsWithDetails = [];

            foreach ($detailProducts as $detailProduct) {
                $ean = $detailProduct->EAN;
                $productDetails = $this->getProductDetailsFromMagento($ean);

                if ($productDetails) {
                    $productsWithDetails[] = [
                        'ean' => $ean,
                        'detail_id' => $detailProduct->id,
                        'magento' => $productDetails,
                        'has_competitors' => isset($this->competitorResults[$ean]),
                        'is_searching' => isset($this->searchingProducts[$ean]),
                        'is_expanded' => isset($this->expandedProducts[$ean])
                    ];
                }
            }

            return $productsWithDetails;

        } catch (\Exception $e) {
            \Log::error('Erreur récupération produits', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Supprimer un produit de la liste
     */
    public function removeProduct(string $ean): void
    {
        try {
            $deleted = DetailProduct::removeFromList($this->id, $ean);

            if ($deleted) {
                unset($this->competitorResults[$ean]);
                unset($this->expandedProducts[$ean]);
                $this->dispatch('product-removed');
            }

        } catch (\Exception $e) {
            \Log::error('Erreur suppression produit', [
                'ean' => $ean,
                'error' => $e->getMessage()
            ]);
        }
    }

}; ?>

<div class="w-full max-w-7xl mx-auto p-6">
    <!-- En-tête -->
    <div class="mb-6">
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Comparaison de prix</h1>
                <p class="mt-1 text-sm text-gray-600">
                    <span class="font-semibold">Recherche par EAN</span> - 
                    Sites : <span class="badge badge-sm badge-info">1</span>
                    <span class="badge badge-sm badge-info">2</span>
                    <span class="badge badge-sm badge-info">8</span>
                    <span class="badge badge-sm badge-info">16</span>
                </p>
            </div>
            
            <button wire:click="searchAllCompetitors"
                class="btn btn-sm btn-primary"
                wire:loading.attr="disabled">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
                Rechercher tous
            </button>
        </div>
    </div>

    <!-- Filtre par site -->
    @if(!empty($availableSites))
        <div class="mb-6 p-4 bg-white border border-gray-200 rounded-lg shadow-sm">
            <div class="flex justify-between items-center mb-3">
                <div class="text-sm font-semibold text-gray-700 flex items-center gap-2">
                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path>
                    </svg>
                    Filtrer par site
                    @if(!empty($selectedSites))
                        <span class="badge badge-sm badge-info">
                            {{ count($selectedSites) }} sélectionné(s)
                        </span>
                    @endif
                </div>
                <div class="flex gap-2">
                    <button wire:click="selectAllSites" 
                            class="btn btn-xs btn-outline btn-success">
                        Tous
                    </button>
                    <button wire:click="deselectAllSites" 
                            class="btn btn-xs btn-outline btn-error">
                        Aucun
                    </button>
                </div>
            </div>
            
            <div class="flex flex-wrap gap-2">
                @foreach($availableSites as $site)
                    @php $isSelected = $this->isSiteSelected($site['id']); @endphp
                    <label class="cursor-pointer">
                        <input type="checkbox" 
                               class="checkbox checkbox-xs hidden"
                               wire:click="toggleSiteFilter({{ $site['id'] }})"
                               {{ $isSelected ? 'checked' : '' }}>
                        <span class="badge {{ $isSelected ? 'badge-info' : 'badge-outline' }} transition-all hover:scale-105">
                            {{ $site['name'] }}
                        </span>
                    </label>
                @endforeach
            </div>
        </div>
    @endif

    <!-- Liste des produits -->
    <div class="space-y-4">
        @forelse($this->products() as $product)
            @php
                $magento = $product['magento'];
                $ean = $product['ean'];
                $hasCompetitors = $product['has_competitors'];
                $isSearching = $product['is_searching'];
                $isExpanded = $product['is_expanded'];
                
                $competitorCount = $hasCompetitors ? ($competitorResults[$ean]['count'] ?? 0) : 0;
            @endphp
            
            <!-- Carte produit -->
            <div class="overflow-hidden bg-white ring-1 shadow-sm ring-gray-900/5 sm:rounded-xl">
                <div class="px-4 py-5 sm:px-6">
                    <div class="flex justify-between items-start gap-4">
                        <!-- Informations produit -->
                        <div class="flex gap-4 flex-1">
                            <!-- Image -->
                            @if($magento['image_url'])
                                <div class="flex-shrink-0">
                                    <img src="{{ $magento['image_url'] }}" 
                                         alt="{{ $magento['title'] }}"
                                         class="w-20 h-20 object-cover rounded-lg border border-gray-200"
                                         onerror="this.src='https://placehold.co/80x80/cccccc/999999?text=No+Image'">
                                </div>
                            @endif
                            
                            <!-- Détails -->
                            <div class="flex-1 min-w-0">
                                <h3 class="text-lg font-semibold text-gray-900 truncate">
                                    {{ $magento['title'] }}
                                </h3>
                                <div class="mt-1 flex flex-col gap-1 text-sm text-gray-600">
                                    <div class="flex items-center gap-2">
                                        <span class="font-mono font-bold">EAN: {{ $ean }}</span>
                                        @if($magento['vendor'])
                                            <span class="text-gray-400">•</span>
                                            <span>{{ $magento['vendor'] }}</span>
                                        @endif
                                    </div>
                                    @if($magento['type'])
                                        <span class="badge badge-sm badge-outline">{{ $magento['type'] }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        
                        <!-- Prix et actions -->
                        <div class="flex flex-col items-end gap-2">
                            <div class="text-right">
                                <div class="text-2xl font-bold text-success">
                                    {{ $this->formatPrice($magento['final_price']) }}
                                </div>
                                @if($magento['special_price'] > 0 && $magento['special_price'] < $magento['price'])
                                    <div class="text-sm text-gray-500 line-through">
                                        {{ $this->formatPrice($magento['price']) }}
                                    </div>
                                @endif
                            </div>
                            
                            <div class="flex gap-2">
                                <button wire:click="toggleCompetitors('{{ $ean }}')"
                                    class="btn btn-sm btn-info btn-outline"
                                    wire:loading.attr="disabled">
                                    @if($isSearching)
                                        <span class="loading loading-spinner loading-xs"></span>
                                        Recherche...
                                    @else
                                        @if($hasCompetitors)
                                            <span class="badge badge-success badge-sm mr-1">{{ $competitorCount }}</span>
                                            Concurrents
                                        @else
                                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                            </svg>
                                            Rechercher
                                        @endif
                                    @endif
                                </button>
                                
                                <button wire:click="removeProduct('{{ $ean }}')"
                                    class="btn btn-sm btn-error btn-outline"
                                    onclick="return confirm('Supprimer ce produit ?')">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Résultats concurrents -->
                @if($hasCompetitors && $isExpanded)
                    <div class="border-t border-gray-200 bg-gray-50 px-4 py-4">
                        @php
                            $result = $competitorResults[$ean];
                            $competitors = $result['competitors'] ?? [];
                        @endphp
                        
                        @if(!empty($competitors))
                            <div class="overflow-x-auto">
                                <table class="table table-xs w-full">
                                    <thead>
                                        <tr class="bg-gray-100">
                                            <th class="text-xs">Image</th>
                                            <th class="text-xs">Site</th>
                                            <th class="text-xs">Produit</th>
                                            <th class="text-xs">Prix concurrent</th>
                                            <th class="text-xs">Différence</th>
                                            <th class="text-xs">Statut</th>
                                            <th class="text-xs">MAJ</th>
                                            <th class="text-xs">Lien</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($competitors as $competitor)
                                            <tr class="hover">
                                                <td>
                                                    <div class="avatar">
                                                        <div class="w-10 h-10 rounded border">
                                                            <img src="{{ $competitor->image }}" 
                                                                 alt="{{ $competitor->name }}"
                                                                 class="w-full h-full object-contain"
                                                                 onerror="this.src='https://placehold.co/40x40/cccccc/999999?text=No+Img';">
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="text-xs">
                                                    <span class="badge badge-xs badge-outline">{{ $competitor->site_name }}</span>
                                                </td>
                                                <td class="text-xs">
                                                    <div class="font-medium max-w-xs truncate">{{ $competitor->name }}</div>
                                                    @if($competitor->vendor)
                                                        <div class="text-[10px] opacity-70">{{ $competitor->vendor }}</div>
                                                    @endif
                                                </td>
                                                <td class="text-xs font-bold text-success">
                                                    {{ $this->formatPrice($competitor->clean_price) }}
                                                </td>
                                                <td class="text-xs">
                                                    <div class="{{ $competitor->price_difference > 0 ? 'text-success' : 'text-error' }}">
                                                        <div class="font-medium">{{ $this->formatPriceDifference($competitor->price_difference) }}</div>
                                                        <div class="text-[10px]">{{ $this->formatPercentage($competitor->price_difference_percent) }}</div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge badge-xs {{ $this->getPriceStatusClass($competitor->price_status) }}">
                                                        {{ $this->getPriceStatusLabel($competitor->price_status) }}
                                                    </span>
                                                </td>
                                                <td class="text-xs">
                                                    {{ \Carbon\Carbon::parse($competitor->updated_at)->format('d/m/Y') }}
                                                </td>
                                                <td>
                                                    @if($competitor->url)
                                                        <a href="{{ $competitor->url }}" 
                                                           target="_blank" 
                                                           class="btn btn-xs btn-outline btn-info">
                                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                                            </svg>
                                                        </a>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="text-center py-8">
                                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <p class="mt-2 text-sm text-gray-600">Aucun concurrent trouvé sur les sites sélectionnés</p>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @empty
            <div class="bg-white shadow rounded-lg py-12 text-center">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                </svg>
                <h3 class="mt-2 text-sm font-semibold text-gray-900">Aucun produit</h3>
                <p class="mt-1 text-sm text-gray-500">Ajoutez des produits à cette liste pour commencer</p>
            </div>
        @endforelse
    </div>
</div>

@push('styles')
<style>
    .animate-spin {
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }

    .badge-success {
        background-color: #10b981 !important;
        color: white !important;
    }
    
    .badge-warning {
        background-color: #f59e0b !important;
        color: white !important;
    }
    
    .badge-error {
        background-color: #ef4444 !important;
        color: white !important;
    }
    
    .badge-neutral {
        background-color: #9ca3af !important;
        color: white !important;
    }
    
    .badge-info {
        background-color: #3b82f6 !important;
        color: white !important;
    }
</style>
@endpush
