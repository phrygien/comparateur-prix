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
    
    // Nouvelles propriétés pour la recherche de concurrents
    public array $competitorResults = [];
    public bool $searchingCompetitors = false;
    public bool $competitorsLoaded = false;
    public array $sites = [];
    public array $siteGroupedResults = [];

    // Cache
    protected $cacheTTL = 3600;

    public function mount($id): void
    {
        $this->id = $id;
        $this->loadListTitle();
        $this->loadSites();
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

    /**
     * Charger la liste des sites
     */
    public function loadSites()
    {
        try {
            $cacheKey = 'sites_list_all';
            $cachedSites = Cache::get($cacheKey);

            if ($cachedSites !== null) {
                $this->sites = $cachedSites;
                return;
            }

            $sites = DB::connection('mysql')->table('web_site')
                ->select('id', 'name')
                ->orderBy('name')
                ->get()
                ->keyBy('id')
                ->toArray();

            $this->sites = $sites;
            Cache::put($cacheKey, $sites, now()->addHours(24));

        } catch (\Throwable $e) {
            Log::error('Error loading sites:', ['message' => $e->getMessage()]);
            $this->sites = [];
        }
    }

    /**
     * Rechercher les produits concurrents pour TOUS les produits de la liste
     */
    public function searchAllCompetitors(): void
    {
        $this->searchingCompetitors = true;
        $this->competitorsLoaded = false;
        $this->competitorResults = [];
        $this->siteGroupedResults = [];

        try {
            // Récupérer tous les SKU de la liste avec leurs informations
            $allProducts = Cache::remember("list_all_products_{$this->id}", 300, function () {
                return DB::connection('mysqlMagento')->select("
                    SELECT 
                        produit.sku as sku,
                        CAST(product_char.name AS CHAR CHARACTER SET utf8mb4) as title,
                        ROUND(product_decimal.price, 2) as price
                    FROM catalog_product_entity as produit
                    LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
                    LEFT JOIN product_decimal ON product_decimal.entity_id = produit.entity_id
                    LEFT JOIN product_int ON product_int.entity_id = produit.entity_id
                    WHERE produit.sku IN (
                        SELECT DISTINCT EAN 
                        FROM detail_products 
                        WHERE list_product_id = ?
                    )
                    AND product_int.status >= 0
                ", [$this->id]);
            });

            if (empty($allProducts)) {
                Log::info('Aucun produit trouvé dans la liste');
                $this->searchingCompetitors = false;
                return;
            }

            Log::info('Début recherche concurrents pour ' . count($allProducts) . ' produits');

            // Limiter le nombre de produits pour éviter le timeout
            $productsToSearch = array_slice($allProducts, 0, 50); // Limite à 50 produits pour éviter les problèmes

            $allCompetitors = [];
            
            foreach ($productsToSearch as $product) {
                $productName = $product->title ?? '';
                $productPrice = $product->price ?? 0;
                $productSku = $product->sku ?? '';
                
                if (empty($productName)) {
                    continue;
                }

                Log::info("Recherche concurrents pour: {$productName} (SKU: {$productSku})");

                // Utiliser l'algorithme de recherche similaire à votre deuxième composant
                $competitors = $this->findCompetitorsForProduct($productName, $productPrice);
                
                if (!empty($competitors)) {
                    $allCompetitors[$productSku] = [
                        'product_name' => $productName,
                        'our_price' => $productPrice,
                        'competitors' => $competitors,
                        'count' => count($competitors)
                    ];
                    
                    Log::info("Trouvé " . count($competitors) . " concurrents pour {$productName}");
                } else {
                    Log::info("Aucun concurrent trouvé pour {$productName}");
                }
                
                // Petite pause pour éviter la surcharge
                usleep(100000); // 100ms
            }

            // Grouper les résultats par site
            $siteGrouped = $this->groupCompetitorsBySite($allCompetitors);
            
            $this->competitorResults = $allCompetitors;
            $this->siteGroupedResults = $siteGrouped;
            $this->competitorsLoaded = true;
            
            Log::info('Recherche terminée. ' . count($allCompetitors) . ' produits avec concurrents trouvés.');

        } catch (\Exception $e) {
            Log::error('Erreur recherche concurrents: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
        } finally {
            $this->searchingCompetitors = false;
        }
    }

    /**
     * Algorithme de recherche de concurrents (similaire à votre deuxième composant)
     */
    protected function findCompetitorsForProduct(string $search, float $ourPrice): array
    {
        try {
            // Cache pour éviter les recherches répétées
            $cacheKey = 'competitor_search_' . md5($search . '_' . $ourPrice);
            $cached = Cache::get($cacheKey);
            
            if ($cached !== null) {
                return $cached;
            }

            // 1. Extraire le vendor
            $vendor = $this->extractVendorFromSearch($search);
            
            // 2. Extraire le premier mot du produit
            $firstProductWord = $this->extractFirstProductWord($search, $vendor);
            
            // 3. Préparer les variations du vendor
            $vendorVariations = $this->getVendorVariations($vendor);
            
            // 4. Requête de recherche
            $competitors = $this->searchCompetitorsInDatabase($search, $vendor, $vendorVariations, $firstProductWord);
            
            // 5. Filtrer par similarité
            $filteredCompetitors = $this->filterBySimilarity($competitors, $search, $vendor, $firstProductWord);
            
            // 6. Ajouter les comparaisons de prix
            $competitorsWithComparison = $this->addPriceComparisons($filteredCompetitors, $ourPrice);
            
            Cache::put($cacheKey, $competitorsWithComparison, now()->addHours(1));
            
            return $competitorsWithComparison;

        } catch (\Exception $e) {
            Log::error('Erreur findCompetitorsForProduct: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Extraire le vendor d'une recherche
     */
    protected function extractVendorFromSearch(string $search): string
    {
        $searchLower = mb_strtolower(trim($search));
        
        // Essayer d'extraire la partie avant le premier tiret
        $parts = preg_split('/\s*-\s*/', $searchLower, 2);
        $firstPart = trim($parts[0]);
        
        // Liste des mots qui indiquent qu'on n'est plus dans le vendor
        $productKeywords = [
            'eau de',
            'edp',
            'edt',
            'parfum',
            'coffret',
            'spray',
            'ml',
            'vapo',
            'vaporisateur',
            'intense',
            'pour homme',
            'pour femme'
        ];
        
        // Vérifier si la première partie contient un mot-clé produit
        $hasProductKeyword = false;
        foreach ($productKeywords as $keyword) {
            if (str_contains($firstPart, $keyword)) {
                $hasProductKeyword = true;
                break;
            }
        }
        
        if (!$hasProductKeyword && !empty($firstPart)) {
            return ucwords($firstPart);
        }
        
        return '';
    }

    /**
     * Extraire le premier mot du produit
     */
    protected function extractFirstProductWord(string $search, ?string $vendor = null): string
    {
        $search = trim($search);
        
        // Supprimer le vendor si présent
        if (!empty($vendor)) {
            $search = preg_replace('/^' . preg_quote($vendor, '/') . '\s*-\s*/i', '', $search);
        }
        
        // Extraire les parties séparées par des tirets
        $parts = preg_split('/\s*-\s*/', $search, 3);
        
        // Le premier mot après le vendor
        $potentialPart = $parts[0] ?? '';
        
        // Nettoyer et extraire le premier mot
        $words = preg_split('/\s+/', $potentialPart, 2);
        $firstWord = $words[0] ?? '';
        
        // Nettoyer le mot
        $firstWord = preg_replace('/[^a-zA-ZÀ-ÿ0-9]/', '', $firstWord);
        
        // Vérifier que ce n'est pas un mot vide ou un mot clé
        $stopWords = ['le', 'la', 'les', 'de', 'des', 'du', 'et', 'pour', 'avec'];
        
        if (strlen($firstWord) > 2 && !in_array(strtolower($firstWord), $stopWords)) {
            return $firstWord;
        }
        
        return '';
    }

    /**
     * Générer les variations d'un vendor
     */
    protected function getVendorVariations(string $vendor): array
    {
        if (empty($vendor)) {
            return [];
        }
        
        $variations = [trim($vendor)];
        
        // Variations de casse
        $variations[] = mb_strtoupper($vendor);
        $variations[] = mb_strtolower($vendor);
        $variations[] = ucwords(mb_strtolower($vendor));
        
        // Variations sans espaces
        if (str_contains($vendor, ' ')) {
            $noSpace = str_replace(' ', '', $vendor);
            $variations[] = $noSpace;
            $variations[] = mb_strtoupper($noSpace);
            $variations[] = mb_strtolower($noSpace);
        }
        
        return array_unique($variations);
    }

    /**
     * Rechercher les concurrents dans la base de données
     */
    protected function searchCompetitorsInDatabase(string $search, string $vendor, array $vendorVariations, string $firstProductWord): array
    {
        try {
            $query = "
                SELECT 
                    lp.*,
                    ws.name as site_name
                FROM last_price_scraped_product lp
                LEFT JOIN web_site ws ON lp.web_site_id = ws.id
                WHERE (lp.variation != 'Standard' OR lp.variation IS NULL OR lp.variation = '')
            ";
            
            $params = [];
            
            // Conditions du vendor
            if (!empty($vendorVariations)) {
                $vendorConditions = [];
                foreach ($vendorVariations as $variation) {
                    $vendorConditions[] = "lp.vendor LIKE ?";
                    $params[] = '%' . $variation . '%';
                }
                $query .= " AND (" . implode(' OR ', $vendorConditions) . ")";
            }
            
            // Condition pour le premier mot du produit
            if (!empty($firstProductWord)) {
                $query .= " AND (lp.name LIKE ? OR lp.variation LIKE ?)";
                $params[] = '%' . $firstProductWord . '%';
                $params[] = '%' . $firstProductWord . '%';
            }
            
            $query .= " ORDER BY lp.prix_ht ASC LIMIT 20";
            
            return DB::connection('mysql')->select($query, $params);
            
        } catch (\Exception $e) {
            Log::error('Erreur searchCompetitorsInDatabase: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Filtrer par similarité
     */
    protected function filterBySimilarity(array $competitors, string $search, string $vendor, string $firstProductWord): array
    {
        $filtered = [];
        
        foreach ($competitors as $competitor) {
            $similarityScore = $this->computeSimilarityScore($competitor, $search, $vendor, $firstProductWord);
            
            // Seuil de similarité (ajustable)
            if ($similarityScore >= 0.4) { // Seuil plus bas pour plus de résultats
                $competitor->similarity_score = $similarityScore;
                $filtered[] = $competitor;
            }
        }
        
        // Trier par score décroissant
        usort($filtered, function($a, $b) {
            return $b->similarity_score <=> $a->similarity_score;
        });
        
        return $filtered;
    }

    /**
     * Calculer le score de similarité
     */
    protected function computeSimilarityScore($competitor, $search, $vendor, $firstProductWord): float
    {
        $score = 0;
        
        // 1. Score du vendor (40%)
        if (!empty($vendor) && !empty($competitor->vendor)) {
            $vendorLower = mb_strtolower($vendor);
            $competitorVendorLower = mb_strtolower($competitor->vendor);
            
            if ($vendorLower === $competitorVendorLower) {
                $score += 0.4;
            } elseif (str_contains($competitorVendorLower, $vendorLower) || str_contains($vendorLower, $competitorVendorLower)) {
                $score += 0.3;
            }
        }
        
        // 2. Score du premier mot du produit (30%)
        if (!empty($firstProductWord)) {
            $competitorNameLower = mb_strtolower($competitor->name ?? '');
            $competitorVariationLower = mb_strtolower($competitor->variation ?? '');
            
            if (str_contains($competitorNameLower, $firstProductWord) || str_contains($competitorVariationLower, $firstProductWord)) {
                $score += 0.3;
            }
        }
        
        // 3. Score des volumes (20%)
        $searchVolumes = $this->extractVolumesFromText($search);
        $competitorVolumes = $this->extractVolumesFromText(($competitor->name ?? '') . ' ' . ($competitor->variation ?? ''));
        
        if (!empty($searchVolumes) && !empty($competitorVolumes)) {
            $matchingVolumes = array_intersect($searchVolumes, $competitorVolumes);
            if (!empty($matchingVolumes)) {
                $score += 0.2;
            }
        }
        
        // 4. Score du type (10%)
        $searchType = $this->extractProductType($search);
        $competitorType = $competitor->type ?? '';
        
        if (!empty($searchType) && !empty($competitorType)) {
            if (str_contains(mb_strtolower($competitorType), mb_strtolower($searchType))) {
                $score += 0.1;
            }
        }
        
        return $score;
    }

    /**
     * Extraire les volumes d'un texte
     */
    protected function extractVolumesFromText(string $text): array
    {
        $volumes = [];
        if (preg_match_all('/(\d+)\s*ml/i', $text, $matches)) {
            $volumes = $matches[1];
        }
        return $volumes;
    }

    /**
     * Extraire le type de produit
     */
    protected function extractProductType(string $text): string
    {
        $types = ['eau de parfum', 'eau de toilette', 'parfum', 'coffret', 'edp', 'edt'];
        
        foreach ($types as $type) {
            if (stripos($text, $type) !== false) {
                return $type;
            }
        }
        
        return '';
    }

    /**
     * Ajouter les comparaisons de prix
     */
    protected function addPriceComparisons(array $competitors, float $ourPrice): array
    {
        foreach ($competitors as $competitor) {
            $competitorPrice = $this->cleanPrice($competitor->prix_ht ?? 0);
            
            // Différence de prix
            $competitor->price_difference = $ourPrice - $competitorPrice;
            $competitor->price_difference_percent = $ourPrice > 0 ? (($ourPrice - $competitorPrice) / $ourPrice) * 100 : 0;
            
            // Statut
            if ($competitorPrice < $ourPrice * 0.9) {
                $competitor->price_status = 'much_cheaper';
            } elseif ($competitorPrice < $ourPrice) {
                $competitor->price_status = 'cheaper';
            } elseif ($competitorPrice == $ourPrice) {
                $competitor->price_status = 'same';
            } elseif ($competitorPrice <= $ourPrice * 1.1) {
                $competitor->price_status = 'slightly_higher';
            } else {
                $competitor->price_status = 'much_higher';
            }
        }
        
        return $competitors;
    }

    /**
     * Nettoyer un prix
     */
    protected function cleanPrice($price)
    {
        if ($price === null || $price === '') {
            return 0;
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

        return 0;
    }

    /**
     * Grouper les concurrents par site
     */
    protected function groupCompetitorsBySite(array $allCompetitors): array
    {
        $grouped = [];
        
        foreach ($allCompetitors as $sku => $productData) {
            foreach ($productData['competitors'] as $competitor) {
                $siteId = $competitor->web_site_id ?? null;
                $siteName = $competitor->site_name ?? 'Inconnu';
                
                if (!isset($grouped[$siteId])) {
                    $grouped[$siteId] = [
                        'site_id' => $siteId,
                        'site_name' => $siteName,
                        'products' => [],
                        'count' => 0
                    ];
                }
                
                if (!isset($grouped[$siteId]['products'][$sku])) {
                    $grouped[$siteId]['products'][$sku] = [
                        'product_name' => $productData['product_name'],
                        'our_price' => $productData['our_price'],
                        'competitors' => []
                    ];
                }
                
                $grouped[$siteId]['products'][$sku]['competitors'][] = $competitor;
                $grouped[$siteId]['count']++;
            }
        }
        
        // Trier par nombre de produits trouvés
        usort($grouped, function($a, $b) {
            return $b['count'] <=> $a['count'];
        });
        
        return $grouped;
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
            'much_cheaper' => 'text-green-600 bg-green-50 border-green-200',
            'cheaper' => 'text-green-500 bg-green-50 border-green-200',
            'same' => 'text-blue-600 bg-blue-50 border-blue-200',
            'slightly_higher' => 'text-yellow-600 bg-yellow-50 border-yellow-200',
            'much_higher' => 'text-red-600 bg-red-50 border-red-200'
        ];
        
        return $classes[$status] ?? 'text-gray-600 bg-gray-50 border-gray-200';
    }

    // Changer de page
    public function goToPage($page): void
    {
        if ($page < 1 || $page > $this->totalPages || $page === $this->page) {
            return;
        }

        $this->loading = true;
        $this->page = (int) $page;
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
                LEFT JOIN cataloginventory_stock_status AS stock_status ON stock_status.product_id = stock_item.product_id 
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
}; ?>

<div>
    <!-- En-tête avec information -->    
    <x-header title="{{ $listTitle }}" subtitle="Page {{ $page }} sur {{ $totalPages }} ({{ $totalItems }} produits)" separator>
        <x-slot:middle class="!justify-end">
            <x-input icon="o-bolt" placeholder="Search..." />
        </x-slot:middle>
        <x-slot:actions>
            <x-button 
                label="Rechercher les prix concurrents pour la liste" 
                class="btn-primary" 
                wire:click="searchAllCompetitors"
                wire:loading.attr="disabled"
            />
        </x-slot:actions>
    </x-header>

    <!-- Indicateur de chargement pour la recherche de concurrents -->
    @if($searchingCompetitors)
        <div class="alert alert-info mb-4">
            <div class="flex items-center">
                <span class="loading loading-spinner loading-sm mr-2"></span>
                <span>Recherche des prix concurrents en cours... Cette opération peut prendre quelques minutes.</span>
            </div>
        </div>
    @endif

    <!-- Résultats des concurrents -->
    @if($competitorsLoaded)
        <div class="mb-6">
            <div class="card bg-base-100 shadow">
                <div class="card-body">
                    <h2 class="card-title text-lg font-bold">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                        Analyse des prix concurrents
                    </h2>
                    
                    <!-- Vue groupée par site -->
                    @if(!empty($siteGroupedResults))
                        <div class="mt-4">
                            <h3 class="text-md font-semibold mb-3">Résultats groupés par site</h3>
                            <div class="overflow-x-auto">
                                <table class="table table-zebra">
                                    <thead>
                                        <tr>
                                            <th>Site</th>
                                            <th>Nombre de produits trouvés</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($siteGroupedResults as $siteData)
                                            <tr>
                                                <td>
                                                    <div class="font-medium">{{ $siteData['site_name'] }}</div>
                                                </td>
                                                <td>
                                                    <span class="badge badge-primary">{{ $siteData['count'] }} produits</span>
                                                </td>
                                                <td>
                                                    <button class="btn btn-xs btn-outline" onclick="showSiteDetails({{ $siteData['site_id'] }})">
                                                        Voir détails
                                                    </button>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                    
                    <!-- Détails par produit -->
                    @if(!empty($competitorResults))
                        <div class="mt-6">
                            <h3 class="text-md font-semibold mb-3">Détails par produit</h3>
                            <div class="space-y-4">
                                @foreach($competitorResults as $sku => $productData)
                                    <div class="collapse collapse-arrow border border-base-300">
                                        <input type="checkbox" />
                                        <div class="collapse-title font-medium">
                                            <div class="flex justify-between items-center">
                                                <span>{{ $productData['product_name'] }}</span>
                                                <span class="badge badge-outline">{{ $productData['count'] }} concurrent(s) trouvé(s)</span>
                                            </div>
                                        </div>
                                        <div class="collapse-content">
                                            <div class="mb-2">
                                                <span class="font-semibold">Notre prix:</span>
                                                <span class="ml-2 badge badge-neutral">{{ number_format($productData['our_price'], 2) }} €</span>
                                            </div>
                                            
                                            @if(!empty($productData['competitors']))
                                                <div class="overflow-x-auto mt-3">
                                                    <table class="table table-xs">
                                                        <thead>
                                                            <tr>
                                                                <th>Site</th>
                                                                <th>Vendor</th>
                                                                <th>Nom</th>
                                                                <th>Prix HT</th>
                                                                <th>Différence</th>
                                                                <th>Statut</th>
                                                                <th>Score</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            @foreach($productData['competitors'] as $competitor)
                                                                @php
                                                                    $priceDiff = $competitor->price_difference ?? 0;
                                                                    $priceDiffPercent = $competitor->price_difference_percent ?? 0;
                                                                    $priceStatus = $competitor->price_status ?? 'unknown';
                                                                    $similarityScore = $competitor->similarity_score ?? 0;
                                                                @endphp
                                                                <tr>
                                                                    <td>{{ $competitor->site_name ?? 'Inconnu' }}</td>
                                                                    <td>{{ $competitor->vendor ?? 'N/A' }}</td>
                                                                    <td class="max-w-xs truncate">{{ $competitor->name ?? 'N/A' }}</td>
                                                                    <td class="font-semibold">{{ number_format($competitor->prix_ht ?? 0, 2) }} €</td>
                                                                    <td>
                                                                        <span class="{{ $priceDiff > 0 ? 'text-green-600' : ($priceDiff < 0 ? 'text-red-600' : 'text-gray-600') }}">
                                                                            {{ $priceDiff > 0 ? '+' : '' }}{{ number_format($priceDiff, 2) }} €
                                                                        </span>
                                                                        <div class="text-xs text-gray-500">
                                                                            ({{ $priceDiffPercent > 0 ? '+' : '' }}{{ number_format($priceDiffPercent, 1) }}%)
                                                                        </div>
                                                                    </td>
                                                                    <td>
                                                                        <span class="badge {{ $this->getPriceStatusClass($priceStatus) }}">
                                                                            {{ $this->getPriceStatusLabel($priceStatus) }}
                                                                        </span>
                                                                    </td>
                                                                    <td>
                                                                        <div class="w-16 bg-gray-300 rounded-full h-2">
                                                                            <div class="h-2 rounded-full 
                                                                                @if($similarityScore >= 0.7) bg-green-500
                                                                                @elseif($similarityScore >= 0.5) bg-yellow-500
                                                                                @else bg-red-500 @endif"
                                                                                style="width: {{ $similarityScore * 100 }}%">
                                                                            </div>
                                                                        </div>
                                                                        <div class="text-xs text-center mt-1">
                                                                            {{ number_format($similarityScore * 100, 0) }}%
                                                                        </div>
                                                                    </td>
                                                                </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                </div>
                                            @else
                                                <div class="alert alert-warning">
                                                    Aucun concurrent trouvé pour ce produit.
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
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
                    <th>Stock</th>
                    <th>Statut</th>
                </tr>
            </thead>
            <tbody>
                @if($loading)
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
                        @endphp
                        <tr wire:key="product-{{ $product['sku'] }}-{{ $page }}-{{ $index }}">
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
    
    <!-- Bouton rafraîchir -->
    <!-- <div class="mt-6 flex justify-center">
        <button 
            wire:click="refreshProducts"
            wire:loading.attr="disabled"
            wire:target="refreshProducts"
            class="btn btn-primary"
        >
            <span wire:loading.remove wire:target="refreshProducts">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd" />
                </svg>
                Rafraîchir la liste
            </span>
            <span wire:loading wire:target="refreshProducts" class="flex items-center gap-2">
                <span class="loading loading-spinner loading-sm"></span>
                Chargement...
            </span>
        </button>
    </div> -->
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
    
    // Fonction pour afficher les détails d'un site
    function showSiteDetails(siteId) {
        // Implémenter selon vos besoins
        alert('Détails du site ID: ' + siteId + ' - À implémenter');
    }
</script>