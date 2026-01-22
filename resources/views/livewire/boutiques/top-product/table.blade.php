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
    public array $searchingProducts = []; // Track which products are being searched
    public array $expandedProducts = []; // Track which products are expanded
    
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

    /**
     * Nettoyer un prix (assure qu'il est numérique)
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
            // Supprimer tous les caractères non numériques sauf les virgules, points et tirets
            $cleanPrice = preg_replace('/[^\d,.-]/', '', $price);
            // Remplacer les virgules par des points
            $cleanPrice = str_replace(',', '.', $cleanPrice);
            
            // Si le prix a plusieurs points (ex: 1.234.56), garder seulement le dernier
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
     * Rechercher les concurrents pour un produit spécifique
     */
    public function searchCompetitorsForProduct(string $sku, string $productName, $price): void
    {
        $this->searchingProducts[$sku] = true;
        
        try {
            Log::info("Recherche concurrents pour: {$productName} (SKU: {$sku})");

            // Nettoyer le prix
            $cleanPrice = $this->cleanPrice($price);
            
            // Utiliser l'algorithme de recherche
            $competitors = $this->findCompetitorsForProduct($productName, $cleanPrice);
            
            if (!empty($competitors)) {
                $this->competitorResults[$sku] = [
                    'product_name' => $productName,
                    'our_price' => $cleanPrice,
                    'competitors' => $competitors,
                    'count' => count($competitors)
                ];
                
                Log::info("Trouvé " . count($competitors) . " concurrents pour {$productName}");
            } else {
                $this->competitorResults[$sku] = [
                    'product_name' => $productName,
                    'our_price' => $cleanPrice,
                    'competitors' => [],
                    'count' => 0
                ];
                Log::info("Aucun concurrent trouvé pour {$productName}");
            }

        } catch (\Exception $e) {
            Log::error('Erreur recherche concurrents pour produit ' . $sku . ': ' . $e->getMessage());
            $this->competitorResults[$sku] = [
                'product_name' => $productName,
                'our_price' => $this->cleanPrice($price),
                'competitors' => [],
                'count' => 0,
                'error' => $e->getMessage()
            ];
        } finally {
            unset($this->searchingProducts[$sku]);
        }
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
                
                if (!empty($sku) && !empty($productName)) {
                    $this->searchCompetitorsForProduct($sku, $productName, $price);
                }
            }

        } catch (\Exception $e) {
            Log::error('Erreur recherche concurrents page: ' . $e->getMessage());
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
        } else {
            $this->expandedProducts[$sku] = true;
            
            // Si pas encore recherché, rechercher les concurrents
            if (!isset($this->competitorResults[$sku])) {
                $product = $this->findProductBySku($sku);
                if ($product) {
                    $this->searchCompetitorsForProduct($sku, $product['title'] ?? '', $product['price'] ?? 0);
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
                ORDER BY FIELD(produit.sku, " . implode(',', $pageSkus) . ")
            ";

            $result = DB::connection('mysqlMagento')->select($query, $pageSkus);
            return array_map(fn($p) => (array) $p, $result);

        } catch (\Exception $e) {
            Log::error('Erreur getCurrentPageProducts: ' . $e->getMessage());
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
                // Nettoyer le prix
                $product['price'] = $this->cleanPrice($product['price']);
                return $product;
            }
            
            return null;

        } catch (\Exception $e) {
            Log::error('Erreur findProductBySku: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Algorithme de recherche de concurrents
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
                    ws.name as site_name,
                    lp.image_url as image_url,
                    lp.url as product_url
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
            
            $competitors = DB::connection('mysql')->select($query, $params);
            
            // Traiter les images
            foreach ($competitors as $competitor) {
                $competitor->image = $this->getCompetitorImage($competitor);
            }
            
            return $competitors;
            
        } catch (\Exception $e) {
            Log::error('Erreur searchCompetitorsInDatabase: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtenir l'image d'un concurrent
     */
    protected function getCompetitorImage($competitor): string
    {
        // Priorité 1: image_url direct
        if (!empty($competitor->image_url)) {
            // Vérifier si c'est une URL complète ou un chemin relatif
            if (filter_var($competitor->image_url, FILTER_VALIDATE_URL)) {
                return $competitor->image_url;
            }
            
            // Si c'est un chemin relatif, essayer de construire une URL complète
            if (strpos($competitor->image_url, 'http') !== 0) {
                // Essayer de deviner le domaine à partir de l'URL du produit
                $productUrl = $competitor->product_url ?? '';
                if (!empty($productUrl)) {
                    $parsed = parse_url($productUrl);
                    if (isset($parsed['scheme']) && isset($parsed['host'])) {
                        $baseUrl = $parsed['scheme'] . '://' . $parsed['host'];
                        
                        // Si l'image commence par /, ajouter au domaine
                        if (strpos($competitor->image_url, '/') === 0) {
                            return $baseUrl . $competitor->image_url;
                        }
                    }
                }
            }
            
            return $competitor->image_url;
        }
        
        // Priorité 2: extraire de l'URL du produit
        if (!empty($competitor->product_url)) {
            // Essayer d'extraire une image de l'URL
            $productUrl = $competitor->product_url;
            // Vous pourriez ajouter ici une logique pour extraire des images spécifiques
            // Pour l'instant, retourner l'URL du produit comme placeholder
            return $productUrl;
        }
        
        // Fallback: image par défaut
        return 'https://placehold.co/100x100/cccccc/999999?text=No+Image';
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
            // Nettoyer le prix du concurrent
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
            
            // Ajouter le prix nettoyé
            $competitor->clean_price = $competitorPrice;
        }
        
        return $competitors;
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
     * Valider une URL d'image
     */
    public function isValidImageUrl($url): bool
    {
        if (empty($url)) {
            return false;
        }
        
        // Vérifier si c'est une URL valide
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        // Vérifier les extensions d'image courantes
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'];
        $path = parse_url($url, PHP_URL_PATH);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        
        // Si pas d'extension, on suppose que c'est OK (peut être une URL dynamique)
        if (empty($extension)) {
            return true;
        }
        
        return in_array(strtolower($extension), $imageExtensions);
    }

    /**
     * Obtenir l'image d'un concurrent pour l'affichage
     */
    public function getCompetitorImageUrl($competitor): string
    {
        // Si l'image est déjà définie dans l'objet
        if (isset($competitor->image) && !empty($competitor->image)) {
            return $competitor->image;
        }
        
        // Sinon, utiliser la méthode de secours
        return $this->getCompetitorImage($competitor);
    }

    // Changer de page
    public function goToPage($page): void
    {
        if ($page < 1 || $page > $this->totalPages || $page === $this->page) {
            return;
        }

        $this->loading = true;
        $this->page = (int) $page;
        $this->expandedProducts = []; // Réinitialiser les produits étendus
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
        $this->expandedProducts = [];
        $this->competitorResults = [];
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
                
                // Nettoyer les prix des produits
                foreach ($products as &$product) {
                    $product['price'] = $this->cleanPrice($product['price'] ?? 0);
                    $product['special_price'] = $this->cleanPrice($product['special_price'] ?? 0);
                }
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
                label="Rechercher les concurrents pour cette page" 
                class="btn-primary" 
                wire:click="searchAllCompetitorsOnPage"
                wire:loading.attr="disabled"
            />
            <x-button 
                label="Rafraîchir" 
                class="btn-outline" 
                wire:click="refreshProducts"
                wire:loading.attr="disabled"
            />
        </x-slot:actions>
    </x-header>

    <!-- Indicateur de chargement pour la recherche de concurrents -->
    @if($searchingCompetitors)
        <div class="alert alert-info mb-4">
            <div class="flex items-center">
                <span class="loading loading-spinner loading-sm mr-2"></span>
                <span>Recherche des prix concurrents pour tous les produits de la page...</span>
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
                    <th>Concurrents</th>
                </tr>
            </thead>
            <tbody>
                @if($loading)
                    <!-- État de chargement initial -->
                    <tr>
                        <td colspan="10" class="text-center py-12">
                            <div class="flex flex-col items-center gap-3">
                                <span class="loading loading-spinner loading-lg text-primary"></span>
                                <span class="text-lg">Chargement des produits...</span>
                            </div>
                        </td>
                    </tr>
                @elseif(count($products) === 0 && !$loading)
                    <!-- Aucun produit -->
                    <tr>
                        <td colspan="10" class="text-center py-12 text-base-content/50">
                            <div class="flex flex-col items-center gap-3">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 text-base-content/20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2 2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
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
                            $sku = $product['sku'] ?? '';
                            $isExpanded = isset($expandedProducts[$sku]);
                            $hasCompetitors = isset($competitorResults[$sku]);
                            $isSearching = isset($searchingProducts[$sku]);
                        @endphp
                        
                        <!-- Ligne du produit -->
                        <tr wire:key="product-{{ $sku }}-{{ $page }}-{{ $index }}">
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
                                                onerror="this.onerror=null; this.src='https://placehold.co/100x100/cccccc/999999?text=No+Image'"
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
                                                onerror="this.onerror=null; this.src='https://placehold.co/100x100/cccccc/999999?text=No+Image'"
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
                                        onclick="copySku('{{ $sku }}')"
                                        class="hover:text-primary transition-colors"
                                    >
                                        {{ $sku }}
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
                                            {{ $this->formatPrice($product['price'] ?? 0) }}
                                        </span>
                                        <span class="text-error font-semibold">
                                            {{ $this->formatPrice($product['special_price']) }}
                                        </span>
                                    </div>
                                @else
                                    <span class="font-semibold">
                                        {{ $this->formatPrice($product['price'] ?? 0) }}
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
                            <td>
                                <div class="flex items-center space-x-2">
                                    @if($isSearching)
                                        <span class="loading loading-spinner loading-xs"></span>
                                        <span class="text-xs text-info">Recherche...</span>
                                    @else
                                        <button 
                                            wire:click="toggleCompetitors('{{ $sku }}')"
                                            class="btn btn-xs {{ $hasCompetitors && $competitorResults[$sku]['count'] > 0 ? 'btn-primary' : 'btn-outline' }}"
                                            wire:loading.attr="disabled"
                                        >
                                            @if($isExpanded)
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                                                </svg>
                                            @else
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                                </svg>
                                            @endif
                                            @if($hasCompetitors)
                                                <span class="ml-1">{{ $competitorResults[$sku]['count'] }}</span>
                                            @else
                                                <span class="ml-1">Voir</span>
                                            @endif
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        
                        <!-- Ligne des concurrents (expandable) -->
                        @if($isExpanded)
                            <tr class="bg-base-200">
                                <td colspan="10" class="p-0">
                                    <div class="p-4">
                                        @if($isSearching)
                                            <div class="flex justify-center items-center py-8">
                                                <span class="loading loading-spinner loading-lg mr-2"></span>
                                                <span>Recherche des concurrents en cours...</span>
                                            </div>
                                        @elseif($hasCompetitors)
                                            @php
                                                $productData = $competitorResults[$sku];
                                            @endphp
                                            <div class="mb-4">
                                                <div class="flex items-center justify-between mb-2">
                                                    <h4 class="font-bold text-lg">
                                                        Concurrents pour: {{ $productData['product_name'] }}
                                                    </h4>
                                                    <div class="flex items-center space-x-2">
                                                        <span class="badge badge-lg">
                                                            Notre prix: {{ $this->formatPrice($productData['our_price']) }}
                                                        </span>
                                                        <span class="badge badge-primary badge-lg">
                                                            {{ $productData['count'] }} concurrent(s) trouvé(s)
                                                        </span>
                                                    </div>
                                                </div>
                                                
                                                @if($productData['count'] > 0)
                                                    <!-- Table des concurrents avec images -->
                                                    <div class="overflow-x-auto mt-4">
                                                        <table class="table table-xs">
                                                            <thead>
                                                                <tr>
                                                                    <th class="bg-base-300">Image</th>
                                                                    <th class="bg-base-300">Site</th>
                                                                    <th class="bg-base-300">Vendor</th>
                                                                    <th class="bg-base-300">Nom</th>
                                                                    <th class="bg-base-300">Variation</th>
                                                                    <th class="bg-base-300">Type</th>
                                                                    <th class="bg-base-300">Prix HT</th>
                                                                    <th class="bg-base-300">Différence</th>
                                                                    <th class="bg-base-300">%</th>
                                                                    <th class="bg-base-300">Statut</th>
                                                                    <th class="bg-base-300">Similarité</th>
                                                                    <th class="bg-base-300">Action</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                @foreach($productData['competitors'] as $competitor)
                                                                    @php
                                                                        $priceDiff = $competitor->price_difference ?? 0;
                                                                        $priceDiffPercent = $competitor->price_difference_percent ?? 0;
                                                                        $priceStatus = $competitor->price_status ?? 'unknown';
                                                                        $similarityScore = $competitor->similarity_score ?? 0;
                                                                        $competitorPrice = $competitor->clean_price ?? $this->cleanPrice($competitor->prix_ht ?? 0);
                                                                        $competitorImage = $this->getCompetitorImageUrl($competitor);
                                                                        $hasValidImage = $this->isValidImageUrl($competitorImage);
                                                                    @endphp
                                                                    <tr>
                                                                        <td>
                                                                            <div class="avatar">
                                                                                <div class="w-16 h-16 rounded border border-base-300">
                                                                                    <img 
                                                                                        src="{{ $competitorImage }}"
                                                                                        alt="{{ $competitor->name ?? 'Produit concurrent' }}"
                                                                                        class="object-cover w-full h-full"
                                                                                        loading="lazy"
                                                                                        onerror="this.onerror=null; this.src='https://placehold.co/100x100/cccccc/999999?text=No+Image'"
                                                                                    >
                                                                                </div>
                                                                                @if(!$hasValidImage)
                                                                                    <div class="mt-1 text-center">
                                                                                        <span class="badge badge-xs badge-outline">
                                                                                            Pas d'image
                                                                                        </span>
                                                                                    </div>
                                                                                @endif
                                                                            </div>
                                                                        </td>
                                                                        <td>
                                                                            <div class="font-medium">{{ $competitor->site_name ?? 'Inconnu' }}</div>
                                                                            @if(!empty($competitor->product_url))
                                                                                <a href="{{ $competitor->product_url }}" 
                                                                                   target="_blank" 
                                                                                   rel="noopener noreferrer"
                                                                                   class="text-xs text-primary hover:underline"
                                                                                   title="Voir le produit">
                                                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 inline mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                                                                    </svg>
                                                                                    Voir
                                                                                </a>
                                                                            @endif
                                                                        </td>
                                                                        <td>{{ $competitor->vendor ?? 'N/A' }}</td>
                                                                        <td class="max-w-xs truncate" title="{{ $competitor->name ?? 'N/A' }}">
                                                                            {{ $competitor->name ?? 'N/A' }}
                                                                        </td>
                                                                        <td>{{ $competitor->variation ?? 'Standard' }}</td>
                                                                        <td>
                                                                            <span class="badge badge-outline badge-xs">
                                                                                {{ $competitor->type ?? 'N/A' }}
                                                                            </span>
                                                                        </td>
                                                                        <td class="font-semibold">
                                                                            {{ $this->formatPrice($competitorPrice) }}
                                                                        </td>
                                                                        <td>
                                                                            <span class="{{ $priceDiff > 0 ? 'text-green-600' : ($priceDiff < 0 ? 'text-red-600' : 'text-gray-600') }} font-semibold">
                                                                                {{ $this->formatPriceDifference($priceDiff) }}
                                                                            </span>
                                                                        </td>
                                                                        <td>
                                                                            <span class="text-sm {{ $priceDiffPercent > 0 ? 'text-green-600' : ($priceDiffPercent < 0 ? 'text-red-600' : 'text-gray-600') }}">
                                                                                {{ $this->formatPercentage($priceDiffPercent) }}
                                                                            </span>
                                                                        </td>
                                                                        <td>
                                                                            <span class="badge badge-sm {{ $this->getPriceStatusClass($priceStatus) }}">
                                                                                {{ $this->getPriceStatusLabel($priceStatus) }}
                                                                            </span>
                                                                        </td>
                                                                        <td>
                                                                            <div class="flex items-center space-x-2">
                                                                                <div class="w-16 bg-gray-300 rounded-full h-2">
                                                                                    <div class="h-2 rounded-full 
                                                                                        @if($similarityScore >= 0.7) bg-green-500
                                                                                        @elseif($similarityScore >= 0.5) bg-yellow-500
                                                                                        @else bg-red-500 @endif"
                                                                                        style="width: {{ $similarityScore * 100 }}%">
                                                                                    </div>
                                                                                </div>
                                                                                <span class="text-xs font-medium">
                                                                                    {{ number_format($similarityScore * 100, 0) }}%
                                                                                </span>
                                                                            </div>
                                                                        </td>
                                                                        <td>
                                                                            @if(!empty($competitor->product_url))
                                                                                <a href="{{ $competitor->product_url }}" 
                                                                                   target="_blank" 
                                                                                   rel="noopener noreferrer"
                                                                                   class="btn btn-xs btn-outline btn-primary"
                                                                                   title="Visiter le site">
                                                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                                                                    </svg>
                                                                                </a>
                                                                            @else
                                                                                <span class="text-xs text-gray-400">N/A</span>
                                                                            @endif
                                                                        </td>
                                                                    </tr>
                                                                @endforeach
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                    
                                                    <!-- Statistiques des concurrents -->
                                                    <div class="mt-4 p-4 bg-base-100 rounded-lg border border-base-300">
                                                        <h5 class="font-semibold mb-2">Statistiques des concurrents</h5>
                                                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                                            @php
                                                                $prices = array_map(fn($c) => $this->cleanPrice($c->prix_ht ?? 0), $productData['competitors']);
                                                                $minPrice = min($prices);
                                                                $maxPrice = max($prices);
                                                                $avgPrice = array_sum($prices) / count($prices);
                                                                $cheaperCount = count(array_filter($productData['competitors'], fn($c) => $this->cleanPrice($c->prix_ht ?? 0) < $productData['our_price']));
                                                                $expensiveCount = count(array_filter($productData['competitors'], fn($c) => $this->cleanPrice($c->prix_ht ?? 0) > $productData['our_price']));
                                                                $samePriceCount = count(array_filter($productData['competitors'], fn($c) => $this->cleanPrice($c->prix_ht ?? 0) == $productData['our_price']));
                                                            @endphp
                                                            <div class="text-center">
                                                                <div class="text-2xl font-bold text-green-600">{{ $this->formatPrice($minPrice) }}</div>
                                                                <div class="text-xs text-gray-500">Prix minimum</div>
                                                            </div>
                                                            <div class="text-center">
                                                                <div class="text-2xl font-bold text-red-600">{{ $this->formatPrice($maxPrice) }}</div>
                                                                <div class="text-xs text-gray-500">Prix maximum</div>
                                                            </div>
                                                            <div class="text-center">
                                                                <div class="text-2xl font-bold text-blue-600">{{ $this->formatPrice($avgPrice) }}</div>
                                                                <div class="text-xs text-gray-500">Prix moyen</div>
                                                            </div>
                                                            <div class="text-center">
                                                                <div class="text-2xl font-bold {{ $cheaperCount > $expensiveCount ? 'text-green-600' : ($cheaperCount < $expensiveCount ? 'text-red-600' : 'text-blue-600') }}">
                                                                    {{ $cheaperCount }}
                                                                </div>
                                                                <div class="text-xs text-gray-500">Moins chers que nous</div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                @else
                                                    <div class="alert alert-warning">
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.998-.833-2.732 0L4.338 16.5c-.77.833.192 2.5 1.732 2.5z" />
                                                        </svg>
                                                        Aucun concurrent trouvé pour ce produit.
                                                    </div>
                                                @endif
                                            </div>
                                        @else
                                            <div class="flex justify-center items-center py-8">
                                                <div class="text-center">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 text-gray-400 mx-auto mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                                                    </svg>
                                                    <p class="text-gray-500">Cliquez sur "Rechercher" pour trouver les concurrents</p>
                                                    <button 
                                                        wire:click="searchCompetitorsForProduct('{{ $sku }}', '{{ addslashes($product['title'] ?? '') }}', {{ $product['price'] ?? 0 }})"
                                                        class="btn btn-sm btn-primary mt-2"
                                                        wire:loading.attr="disabled"
                                                    >
                                                        <span wire:loading.remove wire:target="searchCompetitorsForProduct">
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                                            </svg>
                                                            Rechercher les concurrents
                                                        </span>
                                                        <span wire:loading wire:target="searchCompetitorsForProduct">
                                                            <span class="loading loading-spinner loading-xs"></span>
                                                            Recherche...
                                                        </span>
                                                    </button>
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                @endif
            </tbody>
            <tfoot>
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
                    <th>Concurrents</th>
                </tr>
            </tfoot>
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
    
    <!-- Statistiques globales -->
    @if(count($competitorResults) > 0)
        <div class="mt-8 card bg-base-100 shadow">
            <div class="card-body">
                <h3 class="card-title">Résumé des concurrents</h3>
                <div class="stats stats-vertical lg:stats-horizontal shadow">
                    <div class="stat">
                        <div class="stat-title">Produits analysés</div>
                        <div class="stat-value">{{ count($competitorResults) }}</div>
                    </div>
                    
                    @php
                        $totalCompetitors = array_sum(array_column($competitorResults, 'count'));
                        $productsWithCompetitors = count(array_filter($competitorResults, fn($r) => $r['count'] > 0));
                        $totalCheaper = 0;
                        $totalExpensive = 0;
                        
                        foreach($competitorResults as $result) {
                            foreach($result['competitors'] as $competitor) {
                                $compPrice = $competitor->clean_price ?? $this->cleanPrice($competitor->prix_ht ?? 0);
                                if ($compPrice < $result['our_price']) {
                                    $totalCheaper++;
                                } elseif ($compPrice > $result['our_price']) {
                                    $totalExpensive++;
                                }
                            }
                        }
                    @endphp
                    
                    <div class="stat">
                        <div class="stat-title">Concurrents trouvés</div>
                        <div class="stat-value">{{ $totalCompetitors }}</div>
                    </div>
                    
                    <div class="stat">
                        <div class="stat-title">Avec concurrents</div>
                        <div class="stat-value">{{ $productsWithCompetitors }}</div>
                        <div class="stat-desc">sur {{ count($competitorResults) }} produits</div>
                    </div>
                    
                    <div class="stat">
                        <div class="stat-title">Moins chers</div>
                        <div class="stat-value text-green-600">{{ $totalCheaper }}</div>
                        <div class="stat-desc">concurrent(s) moins cher(s)</div>
                    </div>
                </div>
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
    
    // Fonction pour afficher une image en grand
    function showImage(imageUrl, productName) {
        const modal = document.createElement('div');
        modal.className = 'modal modal-open';
        modal.innerHTML = `
            <div class="modal-box max-w-4xl">
                <h3 class="font-bold text-lg mb-4">${productName}</h3>
                <div class="flex justify-center">
                    <img src="${imageUrl}" 
                         alt="${productName}" 
                         class="max-w-full max-h-[70vh] object-contain"
                         onerror="this.onerror=null; this.src='https://placehold.co/600x400/cccccc/999999?text=Image+non+disponible'">
                </div>
                <div class="modal-action">
                    <button class="btn" onclick="this.closest('.modal').remove()">Fermer</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }
</script>

<style>
    /* Style pour les images cliquables */
    img[onclick] {
        cursor: pointer;
        transition: transform 0.2s ease;
    }
    
    img[onclick]:hover {
        transform: scale(1.05);
    }
    
    /* Style pour le modal */
    .modal {
        background-color: rgba(0, 0, 0, 0.5);
    }
    
    .modal-box {
        background-color: white;
    }
    
    /* Style pour les lignes de concurrents */
    tr.bg-base-200 {
        transition: all 0.3s ease;
    }
    
    /* Style pour les images des concurrents */
    .avatar img {
        border: 1px solid #e5e7eb;
    }
    
    /* Style pour les badges de statut */
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
    
    .badge-info {
        background-color: #3b82f6 !important;
        color: white !important;
    }
    
    /* Style pour le tableau des concurrents */
    .table-xs th,
    .table-xs td {
        padding: 0.5rem;
        font-size: 0.75rem;
    }
    
    /* Responsive design */
    @media (max-width: 768px) {
        .table-xs {
            font-size: 0.7rem;
        }
        
        .avatar img {
            width: 12px;
            height: 12px;
        }
    }
</style>