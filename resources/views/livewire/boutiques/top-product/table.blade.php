<?php

namespace App\Livewire;

use Mary\Traits\Toast;
use Livewire\Volt\Component;
use App\Models\DetailProduct;
use App\Models\Comparaison;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

new class extends Component {
    use Toast;

    public int $id;
    public string $listTitle = '';
    public bool $loading = true;
    public bool $loadingMore = false;
    public bool $hasMore = true;
    public int $page = 1;
    public int $perPage = 200;
    public int $totalPages = 1;

    // Propriétés pour la recherche de concurrents
    public array $competitorResults = [];
    public bool $searchingCompetitors = false;
    public array $searchingProducts = [];
    public array $expandedProducts = [];

    // Cache
    protected $cacheTTL = 3600;

    // Sélection multiple
    public array $selectedProducts = [];

    // Filtres par site
    public array $availableSites = [];
    public array $selectedSitesByProduct = [];

    public function mount($id): void
    {
        $this->id = $id;
        $this->loadListTitle();
        $this->loadAvailableSites();
    }

    public function loadListTitle(): void
    {
        try {
            $list = Comparaison::find($this->id);
            $this->listTitle = $list ? $list->libelle : 'Liste non trouvée';
        } catch (\Exception $e) {
            $this->listTitle = 'Erreur de chargement';
        }
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
            $this->availableSites = [];
        }
    }

    /**
     * Basculer la sélection d'un site pour un produit spécifique
     */
    public function toggleSiteFilter(string $sku, int $siteId): void
    {
        if (!isset($this->selectedSitesByProduct[$sku])) {
            $this->selectedSitesByProduct[$sku] = [];
        }

        $key = array_search($siteId, $this->selectedSitesByProduct[$sku]);

        if ($key !== false) {
            unset($this->selectedSitesByProduct[$sku][$key]);
            $this->selectedSitesByProduct[$sku] = array_values($this->selectedSitesByProduct[$sku]);
        } else {
            $this->selectedSitesByProduct[$sku][] = $siteId;
        }

        if (empty($this->selectedSitesByProduct[$sku])) {
            unset($this->selectedSitesByProduct[$sku]);
        }
    }

    /**
     * Sélectionner tous les sites pour un produit
     */
    public function selectAllSites(string $sku): void
    {
        $siteIds = array_column($this->availableSites, 'id');
        $this->selectedSitesByProduct[$sku] = $siteIds;
    }

    /**
     * Désélectionner tous les sites pour un produit
     */
    public function deselectAllSites(string $sku): void
    {
        unset($this->selectedSitesByProduct[$sku]);
    }

    /**
     * Vérifier si un site est sélectionné pour un produit
     */
    public function isSiteSelected(string $sku, int $siteId): bool
    {
        return isset($this->selectedSitesByProduct[$sku]) &&
            in_array($siteId, $this->selectedSitesByProduct[$sku]);
    }

    /**
     * Obtenir les concurrents filtrés par site pour un produit
     */
    public function getFilteredCompetitors(string $sku): array
    {
        if (!isset($this->competitorResults[$sku]['competitors'])) {
            return [];
        }

        $competitors = $this->competitorResults[$sku]['competitors'];

        // Appliquer le filtre par site
        if (isset($this->selectedSitesByProduct[$sku]) && !empty($this->selectedSitesByProduct[$sku])) {
            $selectedSiteIds = $this->selectedSitesByProduct[$sku];
            $filtered = array_filter($competitors, function ($competitor) use ($selectedSiteIds) {
                $siteId = $competitor->web_site_id ?? null;
                return $siteId && in_array($siteId, $selectedSiteIds);
            });
            return array_values($filtered);
        }

        return array_values($competitors);
    }

    /**
     * Obtenir la liste des sites disponibles pour les concurrents d'un produit
     */
    public function getAvailableSitesForProduct(string $sku): array
    {
        if (!isset($this->competitorResults[$sku]['competitors'])) {
            return [];
        }

        $competitors = $this->competitorResults[$sku]['competitors'];

        $sites = [];
        foreach ($competitors as $competitor) {
            $siteId = $competitor->web_site_id ?? null;
            $siteName = $competitor->site_name ?? 'Inconnu';

            if ($siteId && !isset($sites[$siteId])) {
                $sites[$siteId] = [
                    'id' => $siteId,
                    'name' => $siteName,
                    'count' => 1
                ];
            }
        }

        return array_values($sites);
    }

    /**
     * Obtenir les statistiques de filtrage pour un produit
     */
    public function getFilterStats(string $sku): array
    {
        if (!isset($this->competitorResults[$sku])) {
            return ['total' => 0, 'good' => 0, 'filtered' => 0];
        }

        $competitors = $this->competitorResults[$sku]['competitors'] ?? [];
        $total = count($competitors);
        $goodCount = $total;

        $filteredCompetitors = $this->getFilteredCompetitors($sku);
        $filteredCount = count($filteredCompetitors);

        return [
            'total' => $total,
            'good' => $goodCount,
            'filtered' => $filteredCount
        ];
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
     * ============================================================================
     * RECHERCHE PAR EAN - MÉTHODE PRINCIPALE
     * ============================================================================
     */
    public function searchCompetitorsForProduct(string $sku, string $productName, $price): void
    {
        $this->searchingProducts[$sku] = true;

        try {
            $cleanPrice = $this->cleanPrice($price);

            \Log::info('Recherche concurrents par EAN', [
                'ean' => $sku,
                'our_price' => $cleanPrice
            ]);

            // Rechercher par EAN dans scraped_product
            $competitors = $this->findCompetitorsByEAN($sku, $cleanPrice);

            \Log::info('Résultats trouvés', [
                'ean' => $sku,
                'total' => count($competitors)
            ]);

            if (!empty($competitors)) {
                $this->competitorResults[$sku] = [
                    'product_name' => $productName,
                    'our_price' => $cleanPrice,
                    'competitors' => $competitors,
                    'count' => count($competitors),
                    'good_count' => count($competitors)
                ];

                // Initialiser tous les sites sélectionnés par défaut
                $availableSites = $this->getAvailableSitesForProduct($sku);
                if (!empty($availableSites)) {
                    $siteIds = array_column($availableSites, 'id');
                    $this->selectedSitesByProduct[$sku] = $siteIds;
                }
            } else {
                $this->competitorResults[$sku] = [
                    'product_name' => $productName,
                    'our_price' => $cleanPrice,
                    'competitors' => [],
                    'count' => 0,
                    'good_count' => 0
                ];
            }

        } catch (\Exception $e) {
            \Log::error('Erreur recherche EAN', [
                'ean' => $sku,
                'error' => $e->getMessage()
            ]);
            
            $this->competitorResults[$sku] = [
                'product_name' => $productName,
                'our_price' => $this->cleanPrice($price),
                'competitors' => [],
                'count' => 0,
                'good_count' => 0,
                'error' => $e->getMessage()
            ];
        } finally {
            unset($this->searchingProducts[$sku]);
        }
    }

    /**
     * ============================================================================
     * RECHERCHE PAR EAN - REQUÊTE SQL AVEC PIVOT PAR SITE
     * ============================================================================
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

            // Ajouter les comparaisons de prix
            $competitors = [];
            foreach ($pivotedResults as $result) {
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
     * ============================================================================
     * PIVOT PAR SITE - Garder le meilleur prix (le plus bas) par site
     * ============================================================================
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
     * Rechercher les concurrents pour TOUS les produits de la page
     */
    public function searchAllCompetitorsOnPage(): void
    {
        $this->searchingCompetitors = true;

        try {
            $currentProducts = $this->getCurrentPageProducts();

            foreach ($currentProducts as $product) {
                $sku = $product['sku'] ?? '';
                $productName = $product['title'] ?? '';
                $price = $product['price'] ?? 0;

                if (!empty($sku)) {
                    $this->searchCompetitorsForProduct($sku, $productName, $price);
                }
            }

        } catch (\Exception $e) {
            \Log::error('Erreur recherche tous concurrents', [
                'error' => $e->getMessage()
            ]);
        } finally {
            $this->searchingCompetitors = false;
        }
    }

    /**
     * Basculer l'affichage des concurrents pour un produit
     */
    public function toggleCompetitors(string $sku): void
    {
        if (isset($this->expandedProducts[$sku])) {
            unset($this->expandedProducts[$sku]);
            unset($this->selectedSitesByProduct[$sku]);
        } else {
            $this->expandedProducts[$sku] = true;

            if (!isset($this->competitorResults[$sku])) {
                $product = $this->findProductBySku($sku);
                if ($product) {
                    $this->searchCompetitorsForProduct(
                        $sku, 
                        $product['title'] ?? '', 
                        $product['price'] ?? 0
                    );
                }
            }
        }
    }

    /**
     * Obtenir les produits de la page courante
     */
    protected function getCurrentPageProducts(): array
    {
        try {
            $allSkus = DetailProduct::where('list_product_id', $this->id)
                ->pluck('EAN')
                ->unique()
                ->values()
                ->toArray();

            $offset = ($this->page - 1) * $this->perPage;
            $pageSkus = array_slice($allSkus, $offset, $this->perPage);

            if (empty($pageSkus)) {
                return [];
            }

            $placeholders = implode(',', array_fill(0, count($pageSkus), '?'));

            $query = "
                SELECT 
                    produit.sku as sku,
                    CAST(product_char.name AS CHAR CHARACTER SET utf8mb4) as title,
                    ROUND(product_decimal.price, 2) as price
                FROM catalog_product_entity as produit
                LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
                LEFT JOIN product_decimal ON product_decimal.entity_id = produit.entity_id
                LEFT JOIN product_int ON product_int.entity_id = produit.entity_id
                WHERE produit.sku IN ($placeholders)
                AND product_int.status >= 0
                ORDER BY FIELD(produit.sku, " . implode(',', array_map(fn($s) => "'$s'", $pageSkus)) . ")
            ";

            $result = DB::connection('mysqlMagento')->select($query, $pageSkus);
            return array_map(fn($p) => (array) $p, $result);

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Trouver un produit par son SKU
     */
    protected function findProductBySku(string $sku): ?array
    {
        try {
            $query = "
                SELECT 
                    produit.sku as sku,
                    CAST(product_char.name AS CHAR CHARACTER SET utf8mb4) as title,
                    ROUND(product_decimal.price, 2) as price
                FROM catalog_product_entity as produit
                LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
                LEFT JOIN product_decimal ON product_decimal.entity_id = produit.entity_id
                LEFT JOIN product_int ON product_int.entity_id = produit.entity_id
                WHERE produit.sku = ?
                AND product_int.status >= 0
            ";

            $result = DB::connection('mysqlMagento')->select($query, [$sku]);

            if (!empty($result)) {
                $product = (array) $result[0];
                $product['price'] = $this->cleanPrice($product['price']);
                return $product;
            }

            return null;

        } catch (\Exception $e) {
            return null;
        }
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
     * Obtenir l'image d'un concurrent pour l'affichage
     */
    public function getCompetitorImageUrl($competitor): string
    {
        if (isset($competitor->image) && !empty($competitor->image)) {
            return $competitor->image;
        }

        return $this->getCompetitorImage($competitor);
    }

    // =========================================================================
    // GESTION DE LA PAGINATION
    // =========================================================================

    public function goToPage($page): void
    {
        if ($page < 1 || $page > $this->totalPages || $page === $this->page) {
            return;
        }

        $this->loading = true;
        $this->page = (int) $page;
        $this->expandedProducts = [];
        $this->selectedSitesByProduct = [];
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
        $this->expandedProducts = [];
        $this->competitorResults = [];
        $this->selectedProducts = [];
        $this->selectedSitesByProduct = [];
        $this->loadListTitle();
    }

    public function getPaginationButtons(): array
    {
        $buttons = [];
        $current = $this->page;
        $total = $this->totalPages;

        $buttons[] = ['page' => 1, 'label' => '1', 'active' => $current === 1];

        $start = max(2, $current - 2);
        $end = min($total - 1, $current + 2);

        if ($start > 2) {
            $buttons[] = ['page' => null, 'label' => '...', 'disabled' => true];
        }

        for ($i = $start; $i <= $end; $i++) {
            $buttons[] = ['page' => $i, 'label' => (string) $i, 'active' => $current === $i];
        }

        if ($end < $total - 1) {
            $buttons[] = ['page' => null, 'label' => '...', 'disabled' => true];
        }

        if ($total > 1) {
            $buttons[] = ['page' => $total, 'label' => (string) $total, 'active' => $current === $total];
        }

        return $buttons;
    }

    // =========================================================================
    // GESTION DE LA SÉLECTION MULTIPLE
    // =========================================================================

    public function toggleProductSelection(string $sku): void
    {
        $key = array_search($sku, $this->selectedProducts);

        if ($key !== false) {
            unset($this->selectedProducts[$key]);
            $this->selectedProducts = array_values($this->selectedProducts);
        } else {
            $this->selectedProducts[] = $sku;
        }
    }

    public function selectAllOnPage(): void
    {
        $currentProducts = $this->getCurrentPageProducts();
        $currentSkus = array_column($currentProducts, 'sku');

        $allSelected = !array_diff($currentSkus, $this->selectedProducts);

        if ($allSelected) {
            $this->selectedProducts = array_diff($this->selectedProducts, $currentSkus);
        } else {
            $newSelections = array_diff($currentSkus, $this->selectedProducts);
            $this->selectedProducts = array_merge($this->selectedProducts, $newSelections);
        }
    }

    public function deselectAll(): void
    {
        $this->selectedProducts = [];
    }

    public function isProductSelected(string $sku): bool
    {
        return in_array($sku, $this->selectedProducts);
    }

    public function areAllProductsOnPageSelected(): bool
    {
        $currentProducts = $this->getCurrentPageProducts();

        if (empty($currentProducts) || empty($this->selectedProducts)) {
            return false;
        }

        $currentSkus = array_column($currentProducts, 'sku');
        return empty(array_diff($currentSkus, $this->selectedProducts));
    }

    // =========================================================================
    // SUPPRESSION DE PRODUITS
    // =========================================================================

    public function removeProduct(string $sku): void
    {
        try {
            $exists = DetailProduct::where('list_product_id', $this->id)
                ->where('EAN', $sku)
                ->exists();

            if (!$exists) {
                $this->error('Produit non trouvé dans la liste.');
                return;
            }

            $deleted = DetailProduct::where('list_product_id', $this->id)
                ->where('EAN', $sku)
                ->delete();

            if ($deleted) {
                Cache::forget("list_skus_{$this->id}");

                unset($this->competitorResults[$sku]);
                unset($this->expandedProducts[$sku]);
                unset($this->selectedSitesByProduct[$sku]);

                $this->selectedProducts = array_filter(
                    $this->selectedProducts,
                    fn($selectedSku) => $selectedSku !== $sku
                );

                $this->success('Produit supprimé avec succès.');
                $this->refreshProducts();
            } else {
                $this->error('Erreur lors de la suppression du produit.');
            }

        } catch (\Exception $e) {
            $this->error('Erreur: ' . $e->getMessage());
        }
    }

    public function removeMultipleProducts(array $skus): void
    {
        try {
            if (empty($skus)) {
                $this->warning('Aucun produit sélectionné.');
                return;
            }

            $deletedCount = DetailProduct::where('list_product_id', $this->id)
                ->whereIn('EAN', $skus)
                ->delete();

            if ($deletedCount > 0) {
                Cache::forget("list_skus_{$this->id}");

                foreach ($skus as $sku) {
                    unset($this->competitorResults[$sku]);
                    unset($this->expandedProducts[$sku]);
                    unset($this->selectedSitesByProduct[$sku]);
                }

                $this->selectedProducts = [];
                $this->success($deletedCount . ' produit(s) supprimé(s) avec succès.');
                $this->loading = true;
            } else {
                $this->error('Erreur lors de la suppression des produits.');
            }

        } catch (\Exception $e) {
            $this->error('Erreur: ' . $e->getMessage());
        }
    }

    public function removeSelectedProducts(): void
    {
        if (empty($this->selectedProducts)) {
            $this->warning('Aucun produit sélectionné.');
            return;
        }

        $this->removeMultipleProducts($this->selectedProducts);
    }

    // =========================================================================
    // DONNÉES POUR LA VUE
    // =========================================================================

    public function with(): array
    {
        try {
            $allSkus = DetailProduct::where('list_product_id', $this->id)
                ->pluck('EAN')
                ->unique()
                ->values()
                ->toArray();

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

            $products = $result['data'] ?? [];
            $products = array_map(fn($p) => (array) $p, $products);

            foreach ($products as &$product) {
                $product['price'] = $this->cleanPrice($product['price'] ?? 0);
                $product['special_price'] = $this->cleanPrice($product['special_price'] ?? 0);
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
                    product_char.swatch_image as swatch_image
                FROM catalog_product_entity as produit
                LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
                LEFT JOIN product_decimal ON product_decimal.entity_id = produit.entity_id
                LEFT JOIN product_int ON product_int.entity_id = produit.entity_id
                LEFT JOIN cataloginventory_stock_item AS stock_item ON stock_item.product_id = produit.entity_id 
                LEFT JOIN cataloginventory_stock_status AS stock_status ON stock_status.product_id = stock_item.product_id 
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
            ];

        } catch (\Throwable $e) {
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

<div>
    <!-- Overlay de chargement -->
    <div wire:loading.delay.flex class="hidden fixed inset-0 z-50 items-center justify-center bg-transparent">
        <div class="flex flex-col items-center justify-center bg-white/90 backdrop-blur-sm rounded-2xl p-8 shadow-2xl border border-white/20 min-w-[200px]">
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mb-4"></div>
            <p class="text-lg font-semibold text-gray-800">Chargement</p>
            <p class="text-sm text-gray-600 mt-1">Veuillez patienter...</p>
        </div>
    </div>

    <!-- En-tête -->
    <div class="mx-auto w-full px-4 py-6 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $listTitle }}</h1>
                <p class="mt-1 text-sm text-gray-600">
                    <span class="font-semibold">Recherche par EAN</span> - 
                    Sites : <span class="badge badge-sm badge-info">1</span>
                    <span class="badge badge-sm badge-info">2</span>
                    <span class="badge badge-sm badge-info">8</span>
                    <span class="badge badge-sm badge-info">16</span>
                </p>
            </div>
            
            <div class="flex space-x-3">
                @if(!empty($selectedProducts))
                <button wire:click="removeSelectedProducts"
                    wire:confirm="Êtes-vous sûr de vouloir supprimer {{ count($selectedProducts) }} produit(s) ?"
                    class="btn btn-sm btn-error"
                    wire:loading.attr="disabled">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                    </svg>
                    Supprimer ({{ count($selectedProducts) }})
                </button>
                    
                <button wire:click="deselectAll"
                    class="btn btn-sm btn-ghost"
                    wire:loading.attr="disabled">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                    Désélectionner
                </button>
                @endif

                <x-button wire:navigate href="{{ route('top-product.edit', $id) }}" label="Ajouter produit" class="btn-primary btn-sm" />

                <button wire:click="refreshProducts"
                    class="btn btn-sm btn-outline"
                    wire:loading.attr="disabled">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    Actualiser
                </button>
                
                <button wire:click="searchAllCompetitorsOnPage"
                    class="btn btn-sm btn-success"
                    wire:loading.attr="disabled">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    Rechercher tous
                </button>
            </div>
        </div>

        <!-- Indicateur de chargement -->
        @if($searchingCompetitors)
            <div class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <div class="flex items-center">
                    <div class="animate-spin rounded-full h-5 w-5 border-b-2 border-blue-600 mr-3"></div>
                    <span class="text-sm font-medium text-blue-800">
                        Recherche des concurrents en cours...
                    </span>
                </div>
            </div>
        @endif

        <!-- Statistiques -->
        <div class="mb-6 grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="stats shadow">
                <div class="stat">
                    <div class="stat-title">Total produits</div>
                    <div class="stat-value text-primary">{{ $totalItems ?? 0 }}</div>
                    <div class="stat-desc">Dans la liste</div>
                </div>
            </div>
            <div class="stats shadow">
                <div class="stat">
                    <div class="stat-title">Page actuelle</div>
                    <div class="stat-value text-secondary">{{ $page }}/{{ $totalPages }}</div>
                    <div class="stat-desc">{{ $perPage }} produits par page</div>
                </div>
            </div>
            <div class="stats shadow">
                <div class="stat">
                    <div class="stat-title">Sur cette page</div>
                    <div class="stat-value text-info">{{ count($products) }}</div>
                    <div class="stat-desc">Produits affichés</div>
                </div>
            </div>
        </div>

        <!-- Pagination haut -->
        @if($totalPages > 1)
            <div class="mb-6 flex items-center justify-between">
                <div class="hidden sm:flex items-center">
                    <p class="text-sm text-gray-700">
                        Affichage de
                        <span class="font-medium">{{ min(($page - 1) * $perPage + 1, $totalItems) }}</span>
                        à
                        <span class="font-medium">{{ min($page * $perPage, $totalItems) }}</span>
                        sur
                        <span class="font-medium">{{ $totalItems }}</span>
                    </p>
                </div>
                <div class="join">
                    <button wire:click="previousPage" class="join-item btn btn-sm" :disabled="$page <= 1">«</button>
                    @foreach($this->getPaginationButtons() as $button)
                        @if($button['page'] === null)
                            <button class="join-item btn btn-sm btn-disabled">{{ $button['label'] }}</button>
                        @else
                            <button wire:click="goToPage({{ $button['page'] }})"
                                class="join-item btn btn-sm {{ $button['active'] ? 'btn-active' : '' }}">
                                {{ $button['label'] }}
                            </button>
                        @endif
                    @endforeach
                    <button wire:click="nextPage" class="join-item btn btn-sm" :disabled="$page >= $totalPages">»</button>
                </div>
            </div>
        @endif

        <!-- Tableau -->
        <div class="overflow-x-auto" wire:loading.class="opacity-50">
            <table class="table table-xs">
                <thead>
                    <tr>
                        <th>
                            <input type="checkbox" 
                                class="checkbox checkbox-xs" 
                                wire:click="selectAllOnPage"
                                {{ $this->areAllProductsOnPageSelected() ? 'checked' : '' }}>
                        </th>
                        <th>#</th>
                        <th>EAN</th>
                        <th>Image</th>
                        <th>Produit</th>
                        <th>Notre Prix</th>
                        <th>Concurrents</th>
                        <th>Type</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($products as $index => $product)
                        @php
                            $hasCompetitors = isset($competitorResults[$product['sku']]);
                            $isSearching = isset($searchingProducts[$product['sku']]);
                            $rowNumber = ($page - 1) * $perPage + $index + 1;

                            $imageUrl = null;
                            if (!empty($product['swatch_image'])) {
                                $imageUrl = 'https://www.cosma-parfumeries.com/media/catalog/product' . $product['swatch_image'];
                            } elseif (!empty($product['thumbnail']) && filter_var($product['thumbnail'], FILTER_VALIDATE_URL)) {
                                $imageUrl = $product['thumbnail'];
                            }

                            $filteredCompetitors = $this->getFilteredCompetitors($product['sku']);
                            $filteredCount = count($filteredCompetitors);
                        @endphp
                        <tr class="hover">
                            <td>
                                <input type="checkbox" 
                                    class="checkbox checkbox-xs" 
                                    wire:click="toggleProductSelection('{{ $product['sku'] }}')"
                                    {{ $this->isProductSelected($product['sku']) ? 'checked' : '' }}>
                            </td>
                            <th>{{ $rowNumber }}</th>
                            <td>
                                <div class="font-mono text-xs font-bold">{{ $product['sku'] }}</div>
                            </td>
                            <td>
                                <div class="avatar">
                                    <div class="w-12 h-12 rounded border border-gray-200 bg-gray-50">
                                        @if($imageUrl)
                                            <img src="{{ $imageUrl }}" 
                                                 alt="{{ $product['title'] }}"
                                                 class="w-full h-full object-contain p-0.5"
                                                 loading="lazy"
                                                 onerror="this.onerror=null; this.src='https://placehold.co/48x48/cccccc/999999?text=No+Image';">
                                        @else
                                            <div class="w-full h-full flex items-center justify-center bg-gray-100">
                                                <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                                </svg>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="font-medium max-w-xs truncate">{{ $product['title'] }}</div>
                                @if(!empty($product['vendor']))
                                    <div class="text-xs opacity-70">{{ $product['vendor'] }}</div>
                                @endif
                            </td>
                            <td>
                                <div class="font-bold text-success">{{ $this->formatPrice($product['price']) }}</div>
                            </td>
                            <td>
                                <button wire:click="toggleCompetitors('{{ $product['sku'] }}')"
                                    class="btn btn-xs btn-info btn-outline w-full"
                                    wire:loading.attr="disabled">
                                    @if($isSearching)
                                        <span class="loading loading-spinner loading-xs"></span>
                                        Recherche...
                                    @else
                                        @if($hasCompetitors)
                                            @if($filteredCount > 0)
                                                <span class="badge badge-success badge-sm mr-1">{{ $filteredCount }}</span>
                                                site(s)
                                            @else
                                                Aucun
                                            @endif
                                        @else
                                            Rechercher
                                        @endif
                                    @endif
                                </button>
                            </td>
                            <td>
                                @if(!empty($product['type']))
                                    <span class="badge badge-outline badge-sm">{{ $product['type'] }}</span>
                                @else
                                    <span class="text-xs opacity-70">N/A</span>
                                @endif
                            </td>
                            <td>
                                <button wire:click="removeProduct('{{ $product['sku'] }}')"
                                    class="btn btn-xs btn-error btn-outline"
                                    title="Supprimer"
                                    onclick="return confirm('Supprimer ce produit ?')">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                </button>
                            </td>
                        </tr>
                        
                        <!-- Résultats des concurrents -->
                        @if($hasCompetitors && isset($expandedProducts[$product['sku']]))
                            @php
                                $availableSites = $this->getAvailableSitesForProduct($product['sku']);
                                $stats = $this->getFilterStats($product['sku']);
                            @endphp
                            <tr class="bg-base-100">
                                <td colspan="9" class="p-0">
                                    <div class="p-4 bg-gradient-to-r from-blue-50 to-indigo-50 border-l-4 border-blue-500 m-2 rounded-lg">
                                        <!-- En-tête -->
                                        <div class="flex justify-between items-center mb-4">
                                            <div>
                                                <h4 class="font-bold text-sm flex items-center gap-2">
                                                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                    </svg>
                                                    <span class="text-blue-900">Résultats par EAN (Correspondance exacte à 100%)</span>
                                                    <span class="badge badge-success">{{ $filteredCount }} site(s)</span>
                                                </h4>
                                                <p class="text-xs text-gray-600 mt-1 ml-7">
                                                    EAN: <span class="font-mono font-bold">{{ $product['sku'] }}</span> | 
                                                    Notre prix: <span class="font-bold text-success">{{ $this->formatPrice($product['price']) }}</span>
                                                </p>
                                            </div>
                                            <button wire:click="toggleCompetitors('{{ $product['sku'] }}')" class="btn btn-xs btn-ghost">
                                                × Fermer
                                            </button>
                                        </div>
                                        
                                        <!-- Filtre par site -->
                                        @if(!empty($availableSites))
                                            <div class="mb-4 p-3 bg-white border border-gray-200 rounded-lg shadow-sm">
                                                <div class="flex justify-between items-center mb-2">
                                                    <div class="text-xs font-semibold text-gray-700 flex items-center gap-1">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path>
                                                        </svg>
                                                        Filtrer par site
                                                        @if(isset($selectedSitesByProduct[$product['sku']]))
                                                            <span class="badge badge-xs badge-info">
                                                                {{ count($selectedSitesByProduct[$product['sku']]) }} sélectionné(s)
                                                            </span>
                                                        @endif
                                                    </div>
                                                    <div class="flex gap-1">
                                                        <button wire:click="selectAllSites('{{ $product['sku'] }}')" 
                                                                class="btn btn-xs btn-outline btn-success">
                                                            Tous
                                                        </button>
                                                        <button wire:click="deselectAllSites('{{ $product['sku'] }}')" 
                                                                class="btn btn-xs btn-outline btn-error">
                                                            Aucun
                                                        </button>
                                                    </div>
                                                </div>
                                                
                                                <div class="flex flex-wrap gap-2">
                                                    @foreach($availableSites as $site)
                                                        @php $isSelected = $this->isSiteSelected($product['sku'], $site['id']); @endphp
                                                        <label class="cursor-pointer">
                                                            <input type="checkbox" 
                                                                   class="checkbox checkbox-xs hidden"
                                                                   wire:click="toggleSiteFilter('{{ $product['sku'] }}', {{ $site['id'] }})"
                                                                   {{ $isSelected ? 'checked' : '' }}>
                                                            <span class="badge {{ $isSelected ? 'badge-info' : 'badge-outline' }} transition-all hover:scale-105">
                                                                {{ $site['name'] }}
                                                            </span>
                                                        </label>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif
                                        
                                        <!-- Tableau des résultats -->
                                        @if($filteredCount > 0)
                                            <div class="overflow-x-auto bg-white rounded-lg shadow-sm">
                                                <table class="table table-xs">
                                                    <thead>
                                                        <tr class="bg-gray-100">
                                                            <th class="text-xs">Image</th>
                                                            <th class="text-xs">Site concurrent</th>
                                                            <th class="text-xs">Produit</th>
                                                            <th class="text-xs">Prix concurrent</th>
                                                            <th class="text-xs">Différence</th>
                                                            <th class="text-xs">Statut</th>
                                                            <th class="text-xs">Dernière MAJ</th>
                                                            <th class="text-xs">Lien</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach($filteredCompetitors as $competitor)
                                                            @php
                                                                $competitorImage = $this->getCompetitorImageUrl($competitor);
                                                                $priceStatusClass = $this->getPriceStatusClass($competitor->price_status ?? 'same');
                                                                $priceStatusLabel = $this->getPriceStatusLabel($competitor->price_status ?? 'same');
                                                                $difference = $this->formatPriceDifference($competitor->price_difference ?? 0);
                                                                $percentage = $this->formatPercentage($competitor->price_difference_percent ?? 0);
                                                            @endphp
                                                            <tr class="hover">
                                                                <td>
                                                                    <div class="avatar">
                                                                        <div class="w-10 h-10 rounded border">
                                                                            <img src="{{ $competitorImage }}" 
                                                                                 alt="{{ $competitor->name ?? 'Concurrent' }}"
                                                                                 class="w-full h-full object-contain"
                                                                                 loading="lazy"
                                                                                 onerror="this.src='https://placehold.co/40x40/cccccc/999999?text=No+Img';">
                                                                        </div>
                                                                    </div>
                                                                </td>
                                                                <td class="text-xs">
                                                                    <div class="font-medium">{{ $competitor->vendor ?? 'N/A' }}</div>
                                                                    <span class="badge badge-xs badge-outline">{{ $competitor->site_name ?? 'N/A' }}</span>
                                                                </td>
                                                                <td class="text-xs">
                                                                    <div class="font-medium">{{ $competitor->name ?? 'N/A' }}</div>
                                                                    <div class="text-[10px] opacity-70">{{ $competitor->variation ?? 'Standard' }}</div>
                                                                </td>
                                                                <td class="text-xs font-bold text-success">
                                                                    {{ $this->formatPrice($competitor->clean_price ?? $competitor->prix_ht) }}
                                                                </td>
                                                                <td class="text-xs">
                                                                    <div class="{{ $competitor->price_difference < 0 ? 'text-error' : 'text-success' }}">
                                                                        <div class="font-medium">{{ $difference }}</div>
                                                                        <div class="text-[10px]">{{ $percentage }}</div>
                                                                    </div>
                                                                </td>
                                                                <td>
                                                                    <span class="badge badge-xs {{ $priceStatusClass }}">{{ $priceStatusLabel }}</span>
                                                                </td>
                                                                <td class="text-xs">
                                                                    {{ \Carbon\Carbon::parse($competitor->updated_at)->format('d/m/Y') }}
                                                                </td>
                                                                <td>
                                                                    @if(!empty($competitor->url))
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
                                            <div class="text-center py-8 bg-white rounded-lg">
                                                <svg class="w-12 h-12 mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                <p class="text-sm text-gray-600 mt-2">Aucun concurrent trouvé sur les sites 1, 2, 8, 16</p>
                                            </div>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="9" class="text-center py-8">
                                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <h3 class="mt-4 text-lg font-medium text-gray-900">Aucun produit trouvé</h3>
                                <p class="mt-2 text-sm text-gray-500">La liste est vide</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination bas -->
        @if($totalPages > 1 && count($products) > 0)
            <div class="mt-6 flex justify-center">
                <div class="join">
                    <button wire:click="previousPage" class="join-item btn btn-sm" :disabled="$page <= 1">«</button>
                    @foreach($this->getPaginationButtons() as $button)
                        @if($button['page'] === null)
                            <button class="join-item btn btn-sm btn-disabled">{{ $button['label'] }}</button>
                        @else
                            <button wire:click="goToPage({{ $button['page'] }})"
                                class="join-item btn btn-sm {{ $button['active'] ? 'btn-active' : '' }}">
                                {{ $button['label'] }}
                            </button>
                        @endif
                    @endforeach
                    <button wire:click="nextPage" class="join-item btn btn-sm" :disabled="$page >= $totalPages">»</button>
                </div>
            </div>
        @endif
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
</div>