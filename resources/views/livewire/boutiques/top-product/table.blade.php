<?php

namespace App\Livewire;

use Mary\Traits\Toast;
use Livewire\Volt\Component;
use App\Models\DetailProduct;
use App\Models\Comparaison;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

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
    
    // Nouvelles propriétés pour la recherche de concurrents
    public array $competitorResults = [];
    public bool $searchingCompetitors = false;
    public array $searchingProducts = []; // Track which products are being searched
    public array $expandedProducts = []; // Track which products are expanded
    
    // Cache
    protected $cacheTTL = 3600;

    // Nouvelle propriété pour la recherche manuelle par ligne
    public array $manualSearchQueries = [];
    public array $manualSearchResults = [];
    public array $manualSearchLoading = [];
    public array $manualSearchExpanded = [];

    // Sélection multiple
    public array $selectedProducts = [];

    // Filtres par site
    public array $siteFilters = [];
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
     * Charger la liste des sites disponibles
     */
    protected function loadAvailableSites(): void
    {
        try {
            $sites = DB::connection('mysql')
                ->table('web_site')
                ->select('id', 'name')
                ->whereNotNull('name')
                ->where('name', '!=', '')
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
    public function toggleSiteFilter(string $sku, int $siteId, string $siteName): void
    {
        if (!isset($this->selectedSitesByProduct[$sku])) {
            $this->selectedSitesByProduct[$sku] = [];
        }

        $key = array_search($siteId, $this->selectedSitesByProduct[$sku]);
        
        if ($key !== false) {
            // Retirer le site de la sélection
            unset($this->selectedSitesByProduct[$sku][$key]);
            $this->selectedSitesByProduct[$sku] = array_values($this->selectedSitesByProduct[$sku]);
        } else {
            // Ajouter le site à la sélection
            $this->selectedSitesByProduct[$sku][] = $siteId;
        }

        // Si aucun site n'est sélectionné, supprimer le filtre pour ce produit
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
        
        // Filtrer par niveau de similarité (≥ 0.6)
        $goodCompetitors = array_filter($competitors, fn($c) => ($c->similarity_score ?? 0) >= 0.6);
        
        // Appliquer le filtre par site si des sites sont sélectionnés
        if (isset($this->selectedSitesByProduct[$sku]) && !empty($this->selectedSitesByProduct[$sku])) {
            $selectedSiteIds = $this->selectedSitesByProduct[$sku];
            $filtered = array_filter($goodCompetitors, function($competitor) use ($selectedSiteIds) {
                $siteId = $competitor->web_site_id ?? null;
                return $siteId && in_array($siteId, $selectedSiteIds);
            });
            return array_values($filtered);
        }
        
        return array_values($goodCompetitors);
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
        $goodCompetitors = array_filter($competitors, fn($c) => ($c->similarity_score ?? 0) >= 0.6);
        
        $sites = [];
        foreach ($goodCompetitors as $competitor) {
            $siteId = $competitor->web_site_id ?? null;
            $siteName = $competitor->site_name ?? 'Inconnu';
            
            if ($siteId && !isset($sites[$siteId])) {
                $sites[$siteId] = [
                    'id' => $siteId,
                    'name' => $siteName,
                    'count' => 0
                ];
            }
            
            if ($siteId) {
                $sites[$siteId]['count']++;
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
        
        // Compter les bons résultats (≥ 0.6)
        $goodCompetitors = array_filter($competitors, fn($c) => ($c->similarity_score ?? 0) >= 0.6);
        $goodCount = count($goodCompetitors);
        
        // Compter les résultats filtrés
        $filteredCompetitors = $this->getFilteredCompetitors($sku);
        $filteredCount = count($filteredCompetitors);
        
        return [
            'total' => $total,
            'good' => $goodCount,
            'filtered' => $filteredCount
        ];
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
            // Nettoyer le nom du produit
            $cleanedProductName = $this->normalizeAndCleanText($productName);

            // Nettoyer le prix
            $cleanPrice = $this->cleanPrice($price);
            
            // Utiliser l'algorithme de recherche amélioré
            $competitors = $this->findCompetitorsForProduct($cleanedProductName, $cleanPrice);
            
            if (!empty($competitors)) {
                // Compter les bons résultats (similarité >= 0.6)
                $goodResults = array_filter($competitors, fn($c) => ($c->similarity_score ?? 0) >= 0.6);
                
                $this->competitorResults[$sku] = [
                    'product_name' => $cleanedProductName,
                    'our_price' => $cleanPrice,
                    'competitors' => $competitors,
                    'count' => count($competitors),
                    'good_count' => count($goodResults)
                ];
                
                // Initialiser les sites sélectionnés avec tous les sites disponibles
                $availableSites = $this->getAvailableSitesForProduct($sku);
                if (!empty($availableSites)) {
                    $siteIds = array_column($availableSites, 'id');
                    $this->selectedSitesByProduct[$sku] = $siteIds;
                }
            } else {
                $this->competitorResults[$sku] = [
                    'product_name' => $cleanedProductName,
                    'our_price' => $cleanPrice,
                    'competitors' => [],
                    'count' => 0,
                    'good_count' => 0
                ];
            }

        } catch (\Exception $e) {
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
     * NOUVELLE MÉTHODE : Recherche manuelle pour un produit spécifique
     */
    public function manualSearchForProduct(string $sku, string $productName = '', $price = 0): void
    {
        if (empty($this->manualSearchQueries[$sku])) {
            // Si pas de recherche spécifique, utiliser le nom du produit
            if (!empty($productName)) {
                $this->manualSearchQueries[$sku] = $productName;
            } else {
                return;
            }
        }

        $this->manualSearchLoading[$sku] = true;
        
        try {
            $searchQuery = $this->manualSearchQueries[$sku];
            $cleanPrice = $this->cleanPrice($price);
            
            // Utiliser la même logique de recherche que la recherche automatique
            $competitors = $this->findCompetitorsForProduct($searchQuery, $cleanPrice);
            
            if (!empty($competitors)) {
                $this->manualSearchResults[$sku] = [
                    'search_query' => $searchQuery,
                    'our_price' => $cleanPrice,
                    'competitors' => $competitors,
                    'count' => count($competitors)
                ];
            } else {
                $this->manualSearchResults[$sku] = [
                    'search_query' => $searchQuery,
                    'our_price' => $cleanPrice,
                    'competitors' => [],
                    'count' => 0
                ];
            }

        } catch (\Exception $e) {
            $this->manualSearchResults[$sku] = [
                'search_query' => $this->manualSearchQueries[$sku] ?? '',
                'our_price' => $this->cleanPrice($price),
                'competitors' => [],
                'count' => 0,
                'error' => $e->getMessage()
            ];
        } finally {
            unset($this->manualSearchLoading[$sku]);
        }
    }

    /**
     * Basculer l'affichage des résultats de recherche manuelle
     */
    public function toggleManualSearchResults(string $sku): void
    {
        if (isset($this->manualSearchExpanded[$sku])) {
            unset($this->manualSearchExpanded[$sku]);
        } else {
            $this->manualSearchExpanded[$sku] = true;
            
            // Si pas encore recherché, effectuer la recherche
            if (!isset($this->manualSearchResults[$sku])) {
                $product = $this->findProductBySku($sku);
                if ($product) {
                    $this->manualSearchForProduct($sku, $product['title'] ?? '', $product['price'] ?? 0);
                }
            }
        }
    }

    /**
     * Effacer la recherche manuelle pour un produit
     */
    public function clearManualSearch(string $sku): void
    {
        unset($this->manualSearchQueries[$sku]);
        unset($this->manualSearchResults[$sku]);
        unset($this->manualSearchExpanded[$sku]);
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
            // Erreur silencieuse
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
            // Réinitialiser les filtres de site pour ce produit
            unset($this->selectedSitesByProduct[$sku]);
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
            return null;
        }
    }

    /**
     * Algorithme de recherche de concurrents AMÉLIORÉ
     * Utilise la même logique que le premier composant
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
            
            // 1. Extraire le vendor de manière intelligente (comme le premier composant)
            $vendor = $this->extractVendorFromSearchImproved($search);
            
            // 2. Extraire les mots de recherche significatifs (méthode améliorée)
            $searchKeywords = $this->extractSearchKeywords($search, $vendor);
            
            // 3. Extraire les composants de la recherche
            $components = $this->extractSearchComponentsImproved($search, $vendor, $searchKeywords);
            
            // 4. Préparer les variations du vendor
            $vendorVariations = $this->getVendorVariationsImproved($vendor);
            
            // 5. Recherche avec plusieurs stratégies
            $competitors = $this->searchWithMultipleStrategies($search, $vendor, $vendorVariations, $searchKeywords, $components);
            
            // 6. Filtrer par similarité améliorée (avec seuil plus élevé et limite)
            $filteredCompetitors = $this->filterBySimilarityImproved($competitors, $search, $components);
            
            $competitorsWithComparison = $this->addPriceComparisons($filteredCompetitors, $ourPrice);
            
            Cache::put($cacheKey, $competitorsWithComparison, now()->addHours(1));
            
            return $competitorsWithComparison;

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Normaliser et nettoyer un texte (UTF-8)
     */
    protected function normalizeAndCleanText(string $text): string
    {
        // Vérifier si le texte est déjà en UTF-8
        if (!mb_check_encoding($text, 'UTF-8')) {
            // Essayer de convertir depuis différents encodages
            $encodings = ['ISO-8859-1', 'ISO-8859-15', 'Windows-1252', 'CP1252'];
            
            foreach ($encodings as $encoding) {
                $converted = mb_convert_encoding($text, 'UTF-8', $encoding);
                if (mb_check_encoding($converted, 'UTF-8') && !$this->hasInvalidUtf8($converted)) {
                    $text = $converted;
                    break;
                }
            }
        }
        
        // Normaliser les caractères Unicode (NFC normalization)
        if (function_exists('normalizer_normalize')) {
            $text = normalizer_normalize($text, \Normalizer::FORM_C);
        }
        
        // Remplacer les caractères mal encodés spécifiques
        $replacements = [
            // Caractères mal encodés courants
            '�' => 'é', '�' => 'è', '�' => 'ê', '�' => 'ë',
            '�' => 'à', '�' => 'â', '�' => 'ä',
            '�' => 'î', '�' => 'ï',
            '�' => 'ô', '�' => 'ö',
            '�' => 'ù', '�' => 'û', '�' => 'ü',
            '�' => 'ç',
            '�' => 'É', '�' => 'È', '�' => 'Ê', '�' => 'Ë',
            '�' => 'À', '�' => 'Â', '�' => 'Ä',
            '�' => 'Î', '�' => 'Ï',
            '�' => 'Ô', '�' => 'Ö',
            '�' => 'Ù', '�' => 'Û', '�' => 'Ü',
            '�' => 'Ç',
            // Caractères spéciaux
            '�' => "'", '�' => "'", // Guillemets simples
            '�' => '"', '�' => '"', // Guillemets doubles
            '�' => '€',
            '�' => '...',
            '[' => '', ']' => '', // Supprimer les crochets souvent mal formatés
        ];
        
        $text = strtr($text, $replacements);
        
        // Décoder les entités HTML
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Supprimer les caractères de contrôle et normaliser les espaces
        $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text);
        $text = trim(preg_replace('/\s+/', ' ', $text));
        
        return $text;
    }
    
    /**
     * Vérifier si une chaîne contient des caractères UTF-8 invalides
     */
    protected function hasInvalidUtf8(string $text): bool
    {
        return preg_match('//u', $text) !== 1;
    }

    /**
     * Extraire le vendor de manière plus intelligente
     * Similaire à la méthode du premier composant
     */
    protected function extractVendorFromSearchImproved(string $search): string
    {
        $search = $this->normalizeAndCleanText($search);
        $searchLower = mb_strtolower(trim($search));
        
        // Charger la liste des vendors connus
        $knownVendors = $this->loadKnownVendors();
        
        // Essayer plusieurs stratégies d'extraction
        $vendor = '';
        
        // 1. Recherche par motif "VENDOR - " au début
        if (preg_match('/^([^-]+)\s*-\s*/i', $search, $matches)) {
            $potentialVendor = trim($matches[1]);
            $vendor = $this->findMatchingVendor($potentialVendor, $knownVendors);
        }
        
        // 2. Si pas trouvé, chercher le vendor n'importe où dans la chaîne
        if (empty($vendor)) {
            foreach ($knownVendors as $knownVendor) {
                $knownLower = mb_strtolower($knownVendor);
                if (str_contains($searchLower, $knownLower)) {
                    // Vérifier qu'il n'est pas dans un mot plus long
                    $position = mb_strpos($searchLower, $knownLower);
                    $before = $position > 0 ? mb_substr($searchLower, $position - 1, 1) : ' ';
                    $afterCharPosition = $position + mb_strlen($knownLower);
                    $after = $afterCharPosition < mb_strlen($searchLower) 
                        ? mb_substr($searchLower, $afterCharPosition, 1) 
                        : ' ';
                    
                    // Le vendor doit être délimité par des espaces, tirets, etc.
                    $delimiters = [' ', '-', '(', '[', ',', '.', ';', ':', '/'];
                    if (in_array($before, $delimiters) && in_array($after, $delimiters)) {
                        $vendor = $knownVendor;
                        break;
                    }
                }
            }
        }
        
        // 3. Si toujours pas trouvé, utiliser la première partie avant le premier tiret
        if (empty($vendor)) {
            $parts = preg_split('/\s*-\s*/', $search, 2);
            $firstPart = trim($parts[0]);
            
            // Éviter les mots clés produits
            $productKeywords = $this->getProductKeywords();
            
            $hasProductKeyword = false;
            foreach ($productKeywords as $keyword) {
                if (stripos($firstPart, $keyword) !== false) {
                    $hasProductKeyword = true;
                    break;
                }
            }
            
            if (!$hasProductKeyword && !empty($firstPart)) {
                $vendor = $this->findMatchingVendor($firstPart, $knownVendors);
            }
        }
        
        return $vendor ?: '';
    }

    /**
     * Extraire les mots clés de recherche significatifs
     * Méthode générale qui fonctionne pour tous les types de produits
     */
    protected function extractSearchKeywords(string $search, ?string $vendor = null): array
    {
        $search = $this->normalizeAndCleanText($search);
        
        // Supprimer le vendor si présent
        if (!empty($vendor)) {
            $pattern = '/^' . preg_quote($vendor, '/') . '\s*-\s*/i';
            $searchWithoutVendor = preg_replace($pattern, '', $search);
            
            if ($searchWithoutVendor !== $search) {
                $search = $searchWithoutVendor;
            } else {
                $search = str_ireplace($vendor, '', $search);
            }
        }
        
        // Liste exhaustive de mots à exclure (stop words)
        $stopWords = array_merge(
            $this->getGeneralStopWords(),
            $this->getProductStopWords(),
            $this->getTechnicalTerms()
        );
        
        // Nettoyer et tokeniser
        $search = mb_strtolower($search);
        
        // Supprimer les ponctuations mais garder les tirets pour les mots composés
        $search = preg_replace('/[^\p{L}\p{N}\s-]/u', ' ', $search);
        
        // Tokeniser
        $tokens = preg_split('/[\s-]+/', $search);
        
        // Filtrer les tokens
        $keywords = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            
            // Exclure les stop words, nombres seuls, et tokens trop courts
            if (strlen($token) > 2 && 
                !in_array($token, $stopWords) && 
                !is_numeric($token) &&
                !preg_match('/^\d+ml$/i', $token) && // Exclure "50ml" etc.
                !preg_match('/^\d+g$/i', $token) &&  // Exclure "100g" etc.
                !preg_match('/^\d+\%$/i', $token)) { // Exclure "10%" etc.
                
                // Garder les tokens qui semblent être des mots significatifs
                if ($this->isSignificantWord($token)) {
                    $keywords[] = $token;
                }
            }
        }
        
        // Limiter le nombre de keywords et éviter les doublons
        $keywords = array_unique($keywords);
        $keywords = array_slice($keywords, 0, 10); // Maximum 10 keywords
        
        return $keywords;
    }
    
    /**
     * Vérifier si un mot est significatif
     */
    protected function isSignificantWord(string $word): bool
    {
        // Exclure les codes produits, références, etc.
        if (preg_match('/^[A-Z0-9]{2,}[A-Z0-9-]*$/i', $word) && strlen($word) >= 4) {
            // Probablement un code produit (ex: "HA", "SPF50", "N°5")
            return false;
        }
        
        // Exclure les mots qui sont des tailles/capacités
        $sizePatterns = ['\d+ml', '\d+l', '\d+g', '\d+kg', '\d+oz', '\d+fl'];
        foreach ($sizePatterns as $pattern) {
            if (preg_match('/^' . $pattern . '$/i', $word)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Obtenir les stop words généraux
     */
    protected function getGeneralStopWords(): array
    {
        return [
            'de', 'le', 'la', 'les', 'un', 'une', 'des', 'du', 'et', 'ou', 'pour', 'avec', 'sans',
            'the', 'a', 'an', 'and', 'or', 'in', 'on', 'at', 'by', 'to', 'of', 'for', 'with',
            'à', 'a', 'au', 'aux', 'dans', 'sur', 'sous', 'chez', 'par', 'entre',
            'ml', 'g', 'kg', 'l', 'oz', 'fl', 'cm', 'mm',
            'edition', 'édition', 'coffret', 'spray', 'vapo', 'vaporisateur',
            'limitée', 'limitee', 'spéciale', 'speciale', 'exclusive', 'exclusif'
        ];
    }
    
    /**
     * Obtenir les stop words spécifiques aux produits
     */
    protected function getProductStopWords(): array
    {
        return [
            'eau', 'parfum', 'cologne', 'toilette', 'fraiche',
            'crème', 'creme', 'lotion', 'gel', 'sérum', 'serum', 'baume', 'masque',
            'shampooing', 'après-shampooing', 'soin', 'traitement', 'nettoyant',
            'hydratant', 'hydratante', 'nourrissant', 'nourrissante', 'protecteur', 'protectrice',
            'anti', 'contre', 'pour', 'homme', 'femme', 'unisexe',
            'visage', 'corps', 'mains', 'pieds', 'cheveux', 'peau', 'levres', 'lèvres',
            'normales', 'normaux', 'sèches', 'seches', 'grasse', 'grasses', 'mixtes', 'sensibles',
            'jour', 'nuit', 'matin', 'soir'
        ];
    }
    
    /**
     * Obtenir les termes techniques
     */
    protected function getTechnicalTerms(): array
    {
        return [
            'spf', 'uv', 'uva', 'uvb', 'ha', 'na', 'ph', 'vitamine', 'vitamin',
            'q10', 'collagène', 'collagene', 'élastine', 'elastine', 'acide', 'hyaluronique',
            'rétinol', 'retinol', 'niacinamide', 'peptide', 'anti-oxydant', 'antioxydant'
        ];
    }
    
    /**
     * Obtenir les mots clés produits généraux
     */
    protected function getProductKeywords(): array
    {
        return array_merge(
            $this->getProductStopWords(),
            $this->getTechnicalTerms(),
            [
                'recharge', 'personnalisable', 'rouge', 'blanc', 'noir', 'bleu', 'vert', 'jaune',
                'rose', 'violet', 'orange', 'marron', 'gris', 'doré', 'dore', 'argenté', 'argente',
                'brun', 'amarante', 'satin', 'mat', 'brillant', 'nacré', 'nacre', 'perlé', 'perle'
            ]
        );
    }

    /**
     * Extraire tous les composants de la recherche (comme le premier composant)
     */
    protected function extractSearchComponentsImproved(string $search, ?string $vendor = null, array $keywords = []): array
    {
        $search = $this->normalizeAndCleanText($search);
        
        $components = [
            'vendor' => $vendor,
            'product_name' => '',
            'keywords' => $keywords,
            'variation' => '',
            'volumes' => [],
            'capacities' => [],
            'type' => '',
            'color' => '',
            'finish' => ''
        ];
        
        // Extraire les volumes (ml)
        if (preg_match_all('/(\d+)\s*ml/i', $search, $matches)) {
            $components['volumes'] = $matches[1];
        }
        
        // Extraire les capacités (g, kg, etc.)
        if (preg_match_all('/(\d+)\s*(g|kg|oz|l)/i', $search, $matches)) {
            $components['capacities'] = array_map(function($value, $unit) {
                return $value . strtolower($unit);
            }, $matches[1], $matches[2]);
        }
        
        // Extraire le nom du produit (reste de la recherche après nettoyage)
        $remainingSearch = $search;
        
        if (!empty($vendor)) {
            // Supprimer le vendor
            $pattern = '/^' . preg_quote($vendor, '/') . '\s*-\s*/i';
            $remainingSearch = preg_replace($pattern, '', $remainingSearch);
            
            if ($remainingSearch === $search) {
                $remainingSearch = str_ireplace($vendor, '', $remainingSearch);
            }
        }
        
        // Supprimer les parties techniques
        $technicalParts = array_merge(
            $this->getProductStopWords(),
            $this->getTechnicalTerms(),
            ['ml', 'g', 'kg', 'l', 'oz']
        );
        
        foreach ($technicalParts as $part) {
            $remainingSearch = preg_replace('/\b' . preg_quote($part, '/') . '\b/i', '', $remainingSearch);
        }
        
        // Supprimer les volumes et capacités
        $remainingSearch = preg_replace('/\d+\s*(ml|g|kg|oz|l)/i', '', $remainingSearch);
        
        // Supprimer les nombres isolés
        $remainingSearch = preg_replace('/\s\d+\s/', ' ', $remainingSearch);
        
        // Nettoyer
        $remainingSearch = trim(preg_replace('/\s+/', ' ', $remainingSearch));
        $remainingSearch = preg_replace('/^\s*-\s*|\s*-\s*$/i', '', $remainingSearch);
        
        $components['product_name'] = $remainingSearch;
        
        // Extraire le type
        $types = ['eau de parfum', 'eau de toilette', 'parfum', 'coffret', 'edp', 'edt', 
                 'crème', 'creme', 'lotion', 'gel', 'sérum', 'serum', 'baume', 'masque',
                 'shampooing', 'soin', 'traitement', 'nettoyant', 'hydratant', 'protecteur'];
        
        foreach ($types as $type) {
            if (stripos($search, $type) !== false) {
                $components['type'] = $type;
                break;
            }
        }
        
        // Extraire la couleur
        $colors = ['rouge', 'blanc', 'noir', 'bleu', 'vert', 'jaune', 'rose', 'violet', 
                  'orange', 'marron', 'gris', 'brun', 'amarante', 'doré', 'dore', 'argenté', 'argente'];
        
        foreach ($colors as $color) {
            if (stripos($search, $color) !== false) {
                $components['color'] = $color;
                break;
            }
        }
        
        // Extraire la finition
        $finishes = ['satin', 'mat', 'brillant', 'nacré', 'nacre', 'perlé', 'perle'];
        
        foreach ($finishes as $finish) {
            if (stripos($search, $finish) !== false) {
                $components['finish'] = $finish;
                break;
            }
        }
        
        // Extraire la variation (partie après le deuxième tiret)
        $parts = preg_split('/\s*-\s*/', $search, 3);
        if (count($parts) >= 3) {
            $components['variation'] = trim($parts[2]);
        } elseif (count($parts) == 2) {
            $components['variation'] = trim($parts[1]);
        }
        
        return $components;
    }

    /**
     * Recherche avec plusieurs stratégies
     */
    protected function searchWithMultipleStrategies(string $search, string $vendor, array $vendorVariations, array $keywords, array $components): array
    {
        $allCompetitors = [];
        $seenIds = [];
        
        try {
            // STRATÉGIE 1: Recherche par vendor + keywords
            if (!empty($vendorVariations) && !empty($keywords)) {
                $competitors1 = $this->searchByVendorAndKeywords($vendorVariations, $keywords);
                foreach ($competitors1 as $competitor) {
                    $id = $competitor->id ?? $competitor->url;
                    if (!in_array($id, $seenIds)) {
                        $allCompetitors[] = $competitor;
                        $seenIds[] = $id;
                    }
                }
            }
            
            // STRATÉGIE 2: Recherche FULLTEXT avec la recherche originale
            if (count($allCompetitors) < 10) {
                $searchQuery = $this->prepareSearchTermsForFulltext($search);
                if (!empty($searchQuery)) {
                    $competitors2 = $this->searchByFulltext($searchQuery);
                    foreach ($competitors2 as $competitor) {
                        $id = $competitor->id ?? $competitor->url;
                        if (!in_array($id, $seenIds)) {
                            $allCompetitors[] = $competitor;
                            $seenIds[] = $id;
                        }
                    }
                }
            }
            
            // STRATÉGIE 3: Recherche par vendor seul
            if (count($allCompetitors) < 5 && !empty($vendorVariations)) {
                $competitors3 = $this->searchByVendorOnly($vendorVariations);
                foreach ($competitors3 as $competitor) {
                    $id = $competitor->id ?? $competitor->url;
                    if (!in_array($id, $seenIds)) {
                        $allCompetitors[] = $competitor;
                        $seenIds[] = $id;
                    }
                }
            }
            
            // STRATÉGIE 4: Recherche par type et caractéristiques
            if (count($allCompetitors) < 5 && (!empty($components['type']) || !empty($components['color']))) {
                $competitors4 = $this->searchByTypeAndFeatures($components);
                foreach ($competitors4 as $competitor) {
                    $id = $competitor->id ?? $competitor->url;
                    if (!in_array($id, $seenIds)) {
                        $allCompetitors[] = $competitor;
                        $seenIds[] = $id;
                    }
                }
            }
            
            // Traiter les images
            foreach ($allCompetitors as $competitor) {
                $competitor->image = $this->getCompetitorImage($competitor);
            }
            
            return $allCompetitors;
            
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Recherche par vendor + keywords
     */
    protected function searchByVendorAndKeywords(array $vendorVariations, array $keywords): array
    {
        try {
            if (empty($keywords)) {
                return [];
            }
            
            $vendorConditions = [];
            $params = [];
            
            foreach ($vendorVariations as $variation) {
                $vendorConditions[] = "lp.vendor LIKE ?";
                $params[] = '%' . $variation . '%';
            }
            
            // Construire les conditions pour les keywords
            $keywordConditions = [];
            foreach (array_slice($keywords, 0, 5) as $keyword) { // Limiter à 5 keywords
                $keywordConditions[] = "(lp.name LIKE ? OR lp.variation LIKE ?)";
                $params[] = '%' . $keyword . '%';
                $params[] = '%' . $keyword . '%';
            }
            
            $query = "
                SELECT 
                    lp.*,
                    ws.name as site_name,
                    lp.image_url as image_url,
                    lp.url as product_url
                FROM last_price_scraped_product lp
                LEFT JOIN web_site ws ON lp.web_site_id = ws.id
                WHERE (lp.variation != 'Standard' OR lp.variation IS NULL OR lp.variation = '')
                AND (" . implode(' OR ', $vendorConditions) . ")
                AND (" . implode(' OR ', $keywordConditions) . ")
                ORDER BY lp.prix_ht ASC
                LIMIT 50  -- RÉDUIRE À 50
            ";
            
            return DB::connection('mysql')->select($query, $params);
            
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Recherche par type et caractéristiques
     */
    protected function searchByTypeAndFeatures(array $components): array
    {
        try {
            $conditions = [];
            $params = [];
            
            // Condition pour le type
            if (!empty($components['type'])) {
                $conditions[] = "lp.type LIKE ?";
                $params[] = '%' . $components['type'] . '%';
            }
            
            // Condition pour la couleur
            if (!empty($components['color'])) {
                $conditions[] = "(lp.name LIKE ? OR lp.variation LIKE ?)";
                $params[] = '%' . $components['color'] . '%';
                $params[] = '%' . $components['color'] . '%';
            }
            
            // Condition pour la finition
            if (!empty($components['finish'])) {
                $conditions[] = "(lp.name LIKE ? OR lp.variation LIKE ?)";
                $params[] = '%' . $components['finish'] . '%';
                $params[] = '%' . $components['finish'] . '%';
            }
            
            if (empty($conditions)) {
                return [];
            }
            
            $query = "
                SELECT 
                    lp.*,
                    ws.name as site_name,
                    lp.image_url as image_url,
                    lp.url as product_url
                FROM last_price_scraped_product lp
                LEFT JOIN web_site ws ON lp.web_site_id = ws.id
                WHERE (lp.variation != 'Standard' OR lp.variation IS NULL OR lp.variation = '')
                AND (" . implode(' OR ', $conditions) . ")
                ORDER BY lp.prix_ht ASC
                LIMIT 50  -- RÉDUIRE À 50
            ";
            
            return DB::connection('mysql')->select($query, $params);
            
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Préparer les termes de recherche pour FULLTEXT
     */
    protected function prepareSearchTermsForFulltext(string $search): string
    {
        $search = $this->normalizeAndCleanText($search);
        $searchClean = preg_replace('/[^a-zA-ZÀ-ÿ\s]/', ' ', $search);
        $searchClean = trim(preg_replace('/\s+/', ' ', $searchClean));
        $searchClean = mb_strtolower($searchClean);
        
        // Extraire les mots significatifs
        $words = explode(' ', $searchClean);
        $significantWords = [];
        
        $stopWords = array_merge(
            $this->getGeneralStopWords(),
            $this->getProductStopWords()
        );
        
        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) > 2 && !in_array($word, $stopWords) && !is_numeric($word)) {
                $significantWords[] = '+' . $word . '*';
            }
        }
        
        // Limiter à 5 mots maximum
        $significantWords = array_slice($significantWords, 0, 5);
        
        return implode(' ', $significantWords);
    }

    /**
     * Recherche FULLTEXT
     */
    protected function searchByFulltext(string $searchQuery): array
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
                WHERE MATCH (lp.name, lp.vendor, lp.type, lp.variation) 
                    AGAINST (? IN BOOLEAN MODE)
                AND (lp.variation != 'Standard' OR lp.variation IS NULL OR lp.variation = '')
                ORDER BY lp.prix_ht ASC
                LIMIT 50  -- RÉDUIRE À 50
            ";
            
            return DB::connection('mysql')->select($query, [$searchQuery]);
            
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Recherche par vendor seul
     */
    protected function searchByVendorOnly(array $vendorVariations): array
    {
        try {
            $vendorConditions = [];
            $params = [];
            
            foreach ($vendorVariations as $variation) {
                $vendorConditions[] = "lp.vendor LIKE ?";
                $params[] = '%' . $variation . '%';
            }
            
            $query = "
                SELECT 
                    lp.*,
                    ws.name as site_name,
                    lp.image_url as image_url,
                    lp.url as product_url
                FROM last_price_scraped_product lp
                LEFT JOIN web_site ws ON lp.web_site_id = ws.id
                WHERE (lp.variation != 'Standard' OR lp.variation IS NULL OR lp.variation = '')
                AND (" . implode(' OR ', $vendorConditions) . ")
                ORDER BY lp.prix_ht ASC
                LIMIT 50  -- RÉDUIRE À 50
            ";
            
            return DB::connection('mysql')->select($query, $params);
            
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Filtrer par similarité améliorée
     * MODIFIÉ : seuil augmenté à 0.6 et limité à 50 résultats
     */
    protected function filterBySimilarityImproved(array $competitors, string $search, array $components): array
    {
        $filtered = [];
        
        foreach ($competitors as $competitor) {
            $similarityScore = $this->computeSimilarityScoreImproved($competitor, $search, $components);
            
            // SEUIL AUGMENTÉ À 0.6 POUR UN BON NIVEAU DE SIMILARITÉ
            if ($similarityScore >= 0.6) {
                $competitor->similarity_score = $similarityScore;
                $competitor->match_level = $this->getMatchLevel($similarityScore);
                $filtered[] = $competitor;
            }
        }
        
        // Trier par score décroissant
        usort($filtered, function($a, $b) {
            return $b->similarity_score <=> $a->similarity_score;
        });
        
        // LIMITER À 50 RÉSULTATS
        return array_slice($filtered, 0, 50);
    }

    /**
     * Calculer le score de similarité amélioré
     */
    protected function computeSimilarityScoreImproved($competitor, $search, $components): float
    {
        $weights = [
            'vendor' => 0.25,
            'keywords' => 0.20,
            'name' => 0.15,
            'type' => 0.15,
            'variation' => 0.10,
            'volumes' => 0.05,
            'color' => 0.05,
            'features' => 0.05
        ];
        
        $totalScore = 0;
        
        // 1. Score du vendor
        $vendorScore = $this->computeVendorSimilarity($competitor, $components['vendor']);
        $totalScore += $vendorScore * $weights['vendor'];
        
        // 2. Score des keywords
        $keywordsScore = $this->computeKeywordsSimilarity($competitor, $components['keywords']);
        $totalScore += $keywordsScore * $weights['keywords'];
        
        // 3. Score du nom
        $nameScore = $this->computeNameSimilarity($competitor->name ?? '', $components['product_name']);
        $totalScore += $nameScore * $weights['name'];
        
        // 4. Score du type
        $typeScore = $this->computeTypeSimilarity($competitor, $components['type']);
        $totalScore += $typeScore * $weights['type'];
        
        // 5. Score de la variation
        $variationScore = $this->computeVariationSimilarity($competitor, $components['variation']);
        $totalScore += $variationScore * $weights['variation'];
        
        // 6. Score des volumes
        $volumeScore = $this->computeVolumeSimilarity($competitor, $components['volumes']);
        $totalScore += $volumeScore * $weights['volumes'];
        
        // 7. Score de la couleur
        $colorScore = $this->computeColorSimilarity($competitor, $components['color']);
        $totalScore += $colorScore * $weights['color'];
        
        // 8. Score des caractéristiques (finition, etc.)
        $featuresScore = $this->computeFeaturesSimilarity($competitor, $components);
        $totalScore += $featuresScore * $weights['features'];
        
        return min(1.0, $totalScore);
    }

    /**
     * Similarité du vendor
     */
    protected function computeVendorSimilarity($competitor, $searchVendor): float
    {
        $productVendor = $competitor->vendor ?? '';
        
        if (empty($productVendor) || empty($searchVendor)) {
            return 0;
        }
        
        $productLower = mb_strtolower(trim($productVendor));
        $searchLower = mb_strtolower(trim($searchVendor));
        
        if ($productLower === $searchLower) {
            return 1.0;
        }
        
        if (str_starts_with($productLower, $searchLower) || str_starts_with($searchLower, $productLower)) {
            return 0.9;
        }
        
        if (str_contains($productLower, $searchLower) || str_contains($searchLower, $productLower)) {
            return 0.8;
        }
        
        // Similarité de Levenshtein
        $levenshtein = levenshtein($productLower, $searchLower);
        $maxLength = max(strlen($productLower), strlen($searchLower));
        
        if ($maxLength > 0) {
            $similarity = 1 - ($levenshtein / $maxLength);
            if ($similarity > 0.7) {
                return $similarity;
            }
        }
        
        return 0;
    }

    /**
     * Similarité des keywords
     */
    protected function computeKeywordsSimilarity($competitor, $keywords): float
    {
        if (empty($keywords)) {
            return 0.5; // Pas de pénalité si pas de keywords
        }
        
        $productName = $competitor->name ?? '';
        $productVariation = $competitor->variation ?? '';
        $productType = $competitor->type ?? '';
        
        $productText = mb_strtolower($productName . ' ' . $productVariation . ' ' . $productType);
        
        $matches = 0;
        foreach ($keywords as $keyword) {
            $keywordLower = mb_strtolower($keyword);
            if (str_contains($productText, $keywordLower)) {
                $matches++;
            }
        }
        
        return $matches / count($keywords);
    }

    /**
     * Similarité du nom
     */
    protected function computeNameSimilarity($productName, $searchProductName): float
    {
        if (empty($productName) || empty($searchProductName)) {
            return 0.3; // Score minimum si un des deux est vide
        }
        
        $productNameLower = mb_strtolower(trim($productName));
        $searchProductNameLower = mb_strtolower(trim($searchProductName));
        
        // Si le nom du produit contient le nom recherché (ou vice versa)
        if (str_contains($productNameLower, $searchProductNameLower) || 
            str_contains($searchProductNameLower, $productNameLower)) {
            return 0.9;
        }
        
        // Extraire les mots clés du nom recherché
        $searchWords = preg_split('/\s+/', $searchProductNameLower);
        $searchWords = array_filter($searchWords, function($word) {
            return strlen($word) > 2 && !$this->isStopWord($word);
        });
        
        if (empty($searchWords)) {
            return 0.3;
        }
        
        $matches = 0;
        foreach ($searchWords as $word) {
            if (str_contains($productNameLower, $word)) {
                $matches++;
            }
        }
        
        return $matches / count($searchWords);
    }

    /**
     * Similarité du type
     */
    protected function computeTypeSimilarity($competitor, $searchType): float
    {
        $productType = $competitor->type ?? '';
        
        if (empty($productType) || empty($searchType)) {
            return 0.3; // Score minimum si un des deux est vide
        }
        
        $productLower = mb_strtolower($productType);
        $searchLower = mb_strtolower($searchType);
        
        if ($productLower === $searchLower) {
            return 1.0;
        }
        
        if (str_contains($productLower, $searchLower) || str_contains($searchLower, $productLower)) {
            return 0.8;
        }
        
        // Vérifier les abréviations (ex: "edp" pour "eau de parfum")
        $typeMappings = [
            'edp' => 'eau de parfum',
            'edt' => 'eau de toilette',
            'edc' => 'eau de cologne',
            'creme' => 'crème',
            'serum' => 'sérum'
        ];
        
        foreach ($typeMappings as $abbr => $full) {
            if (($productLower === $abbr && str_contains($searchLower, $full)) ||
                ($searchLower === $abbr && str_contains($productLower, $full))) {
                return 0.7;
            }
        }
        
        return 0.2;
    }

    /**
     * Similarité de la variation
     */
    protected function computeVariationSimilarity($competitor, $searchVariation): float
    {
        $productVariation = $competitor->variation ?? '';
        
        if (empty($productVariation) || empty($searchVariation)) {
            return 0.3; // Score minimum si un des deux est vide
        }
        
        $productLower = mb_strtolower($productVariation);
        $searchLower = mb_strtolower($searchVariation);
        
        if ($productLower === $searchLower) {
            return 1.0;
        }
        
        if (str_contains($productLower, $searchLower) || str_contains($searchLower, $productLower)) {
            return 0.8;
        }
        
        return 0.2;
    }

    /**
     * Similarité des volumes
     */
    protected function computeVolumeSimilarity($competitor, $searchVolumes): float
    {
        if (empty($searchVolumes)) {
            return 0.5; // Pas de pénalité si pas de volumes recherchés
        }
        
        $productVolumes = $this->extractVolumesFromText(
            ($competitor->name ?? '') . ' ' . ($competitor->variation ?? '')
        );
        
        if (empty($productVolumes)) {
            return 0;
        }
        
        $matches = array_intersect($searchVolumes, $productVolumes);
        
        if (!empty($matches)) {
            return count($matches) / count($searchVolumes);
        }
        
        return 0;
    }

    /**
     * Similarité de la couleur
     */
    protected function computeColorSimilarity($competitor, $searchColor): float
    {
        if (empty($searchColor)) {
            return 0.5; // Pas de pénalité si pas de couleur recherchée
        }
        
        $productName = $competitor->name ?? '';
        $productVariation = $competitor->variation ?? '';
        $productText = mb_strtolower($productName . ' ' . $productVariation);
        
        $colorMappings = [
            'rouge' => ['red', 'rosso', 'rojo'],
            'blanc' => ['white', 'blanco', 'bianco'],
            'noir' => ['black', 'nero', 'negro'],
            'bleu' => ['blue', 'blu', 'azul'],
            'vert' => ['green', 'verde', 'verde'],
            'rose' => ['pink', 'rosa', 'rosado'],
            'brun' => ['brown', 'marrone', 'marrón'],
            'amarante' => ['amaranth', 'amaranto'],
            'doré' => ['gold', 'golden', 'dorado', 'dore'],
            'argenté' => ['silver', 'silvery', 'plateado', 'argente']
        ];
        
        $searchLower = mb_strtolower($searchColor);
        
        // Vérifier la couleur exacte
        if (str_contains($productText, $searchLower)) {
            return 1.0;
        }
        
        // Vérifier les équivalents dans d'autres langues
        if (isset($colorMappings[$searchLower])) {
            foreach ($colorMappings[$searchLower] as $equivalent) {
                if (str_contains($productText, $equivalent)) {
                    return 0.8;
                }
            }
        }
        
        return 0;
    }

    /**
     * Similarité des caractéristiques
     */
    protected function computeFeaturesSimilarity($competitor, $components): float
    {
        $score = 0.5; // Score de base
        
        $productName = $competitor->name ?? '';
        $productVariation = $competitor->variation ?? '';
        $productText = mb_strtolower($productName . ' ' . $productVariation);
        
        // Vérifier la finition
        if (!empty($components['finish'])) {
            $finishLower = mb_strtolower($components['finish']);
            $finishMappings = [
                'satin' => ['satiny', 'satiné', 'satinée'],
                'mat' => ['matte', 'mate', 'opaque'],
                'brillant' => ['shiny', 'glossy', 'brillante', 'lustré'],
                'nacré' => ['pearly', 'iridescent', 'nacre', 'nacrée'],
                'perlé' => ['pearlescent', 'pearlized', 'perle', 'perlée']
            ];
            
            if (str_contains($productText, $finishLower)) {
                $score += 0.1;
            } elseif (isset($finishMappings[$finishLower])) {
                foreach ($finishMappings[$finishLower] as $equivalent) {
                    if (str_contains($productText, $equivalent)) {
                        $score += 0.05;
                        break;
                    }
                }
            }
        }
        
        return min(1.0, $score);
    }

    /**
     * Vérifier si un mot est un stop word
     */
    protected function isStopWord(string $word): bool
    {
        $stopWords = array_merge(
            $this->getGeneralStopWords(),
            $this->getProductStopWords()
        );
        
        return in_array(mb_strtolower($word), $stopWords);
    }

    /**
     * Obtenir le niveau de correspondance
     * MODIFIÉ : seuils ajustés
     */
    protected function getMatchLevel(float $similarityScore): string
    {
        // Ajuster les seuils pour être plus restrictifs
        if ($similarityScore >= 0.8) return 'excellent';
        if ($similarityScore >= 0.7) return 'très bon'; // Ajouter un niveau intermédiaire
        if ($similarityScore >= 0.6) return 'bon'; // Seuil pour "bon niveau"
        if ($similarityScore >= 0.5) return 'moyen';
        return 'faible';
    }

    /**
     * Charger la liste des vendors connus
     */
    protected function loadKnownVendors(): array
    {
        $cacheKey = 'all_vendors_list';
        $cachedVendors = Cache::get($cacheKey);
        
        if ($cachedVendors !== null) {
            return $cachedVendors;
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
                $clean = $this->normalizeAndCleanText($vendor);
                if (!empty($clean) && strlen($clean) > 1) {
                    $cleanVendors[] = $clean;
                }
            }
            
            $uniqueVendors = array_unique($cleanVendors);
            Cache::put($cacheKey, $uniqueVendors, now()->addHours(24));
            
            return $uniqueVendors;
            
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Trouver le vendor correspondant
     */
    protected function findMatchingVendor(string $searchVendor, array $knownVendors): string
    {
        if (empty($searchVendor) || empty($knownVendors)) {
            return '';
        }
        
        $searchVendor = $this->normalizeAndCleanText($searchVendor);
        $searchVendorLower = mb_strtolower(trim($searchVendor));
        $bestMatch = '';
        $bestScore = 0;
        
        foreach ($knownVendors as $knownVendor) {
            $knownVendor = $this->normalizeAndCleanText($knownVendor);
            $knownVendorLower = mb_strtolower($knownVendor);
            $score = 0;
            
            // 1. Correspondance exacte
            if ($searchVendorLower === $knownVendorLower) {
                return $knownVendor;
            }
            
            // 2. Le vendor recherché est contenu au début
            if (str_starts_with($knownVendorLower, $searchVendorLower)) {
                $score = 90;
            }
            
            // 3. Le vendor connu est contenu au début
            if (str_starts_with($searchVendorLower, $knownVendorLower)) {
                $score = 85;
            }
            
            // 4. Correspondance partielle
            if (str_contains($knownVendorLower, $searchVendorLower)) {
                $score = max($score, 70);
            }
            
            if (str_contains($searchVendorLower, $knownVendorLower)) {
                $score = max($score, 65);
            }
            
            // 5. Similarité de Levenshtein
            $levenshtein = levenshtein($searchVendorLower, $knownVendorLower);
            $maxLength = max(strlen($searchVendorLower), strlen($knownVendorLower));
            
            if ($maxLength > 0) {
                $similarity = (1 - ($levenshtein / $maxLength)) * 100;
                if ($similarity > 80) {
                    $score = max($score, $similarity);
                }
            }
            
            // 6. Correspondance sans caractères spéciaux
            $searchNoSpecial = preg_replace('/[^a-z0-9]/i', '', $searchVendorLower);
            $knownNoSpecial = preg_replace('/[^a-z0-9]/i', '', $knownVendorLower);
            
            if ($searchNoSpecial === $knownNoSpecial && !empty($searchNoSpecial)) {
                $score = max($score, 95);
            }
            
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $knownVendor;
            }
        }
        
        // Seuil minimum de confiance
        return $bestScore >= 60 ? $bestMatch : '';
    }

    /**
     * Générer les variations du vendor (améliorée)
     */
    protected function getVendorVariationsImproved(string $vendor): array
    {
        if (empty($vendor)) {
            return [];
        }
        
        $vendor = $this->normalizeAndCleanText($vendor);
        $variations = [trim($vendor)];
        
        // Variations de casse
        $variations[] = mb_strtoupper($vendor);
        $variations[] = mb_strtolower($vendor);
        $variations[] = mb_convert_case($vendor, MB_CASE_TITLE);
        $variations[] = ucfirst(mb_strtolower($vendor));
        
        // Variations sans espaces
        if (str_contains($vendor, ' ')) {
            $noSpace = str_replace(' ', '', $vendor);
            $variations[] = $noSpace;
            $variations[] = mb_strtoupper($noSpace);
            $variations[] = mb_strtolower($noSpace);
        }
        
        // Variations avec tirets
        if (str_contains($vendor, ' ')) {
            $withDash = str_replace(' ', '-', $vendor);
            $variations[] = $withDash;
            $variations[] = mb_strtoupper($withDash);
            $variations[] = mb_strtolower($withDash);
        }
        
        // Variations avec points
        if (str_contains($vendor, ' ')) {
            $withDot = str_replace(' ', '.', $vendor);
            $variations[] = $withDot;
            $variations[] = mb_strtoupper($withDot);
            $variations[] = mb_strtolower($withDot);
        }
        
        // Chercher des variations similaires dans la base de données
        $knownVendors = $this->loadKnownVendors();
        $vendorLower = mb_strtolower($vendor);
        
        foreach ($knownVendors as $knownVendor) {
            $knownLower = mb_strtolower($knownVendor);
            $distance = levenshtein($vendorLower, $knownLower);
            $maxLength = max(strlen($vendorLower), strlen($knownLower));
            
            if ($maxLength > 0 && ($distance / $maxLength) < 0.2) {
                $variations[] = $knownVendor;
            }
            
            if (str_contains($knownLower, $vendorLower) || str_contains($vendorLower, $knownLower)) {
                $variations[] = $knownVendor;
            }
        }
        
        return array_unique(array_filter($variations));
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
     * Obtenir l'image d'un concurrent
     */
    protected function getCompetitorImage($competitor): string
    {
        // Priorité 1: image_url direct
        if (!empty($competitor->image_url)) {
            $imageUrl = $this->normalizeAndCleanText($competitor->image_url);
            
            // Vérifier si c'est une URL complète ou un chemin relatif
            if (filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                return $imageUrl;
            }
            
            // Si c'est un chemin relatif, essayer de construire une URL complète
            if (strpos($imageUrl, 'http') !== 0) {
                // Essayer de deviner le domaine à partir de l'URL du produit
                $productUrl = $competitor->product_url ?? '';
                if (!empty($productUrl)) {
                    $parsed = parse_url($productUrl);
                    if (isset($parsed['scheme']) && isset($parsed['host'])) {
                        $baseUrl = $parsed['scheme'] . '://' . $parsed['host'];
                        
                        // Si l'image commence par /, ajouter au domaine
                        if (strpos($imageUrl, '/') === 0) {
                            return $baseUrl . $imageUrl;
                        }
                    }
                }
            }
            
            return $imageUrl;
        }
        
        // Priorité 2: extraire de l'URL du produit
        if (!empty($competitor->product_url)) {
            // Essayer d'extraire une image de l'URL
            $productUrl = $competitor->product_url;
            return $productUrl;
        }
        
        // Fallback: image par défaut
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
     * Valider une URL d'image
     */
    public function isValidImageUrl($url): bool
    {
        if (empty($url)) {
            return false;
        }
        
        $url = $this->normalizeAndCleanText($url);
        
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
            return $this->normalizeAndCleanText($competitor->image);
        }
        
        // Sinon, utiliser la méthode de secours
        return $this->getCompetitorImage($competitor);
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

    // Changer de page
    public function goToPage($page): void
    {
        if ($page < 1 || $page > $this->totalPages || $page === $this->page) {
            return;
        }

        $this->loading = true;
        $this->page = (int) $page;
        $this->expandedProducts = []; // Réinitialiser les produits étendus
        $this->manualSearchQueries = []; // Réinitialiser les recherches manuelles
        $this->manualSearchResults = []; // Réinitialiser les résultats manuels
        $this->manualSearchExpanded = []; // Réinitialiser l'expansion manuelle
        $this->selectedSitesByProduct = []; // Réinitialiser les filtres de site
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
        $this->manualSearchQueries = [];
        $this->manualSearchResults = [];
        $this->manualSearchExpanded = [];
        $this->selectedProducts = []; // Réinitialiser la sélection
        $this->selectedSitesByProduct = []; // Réinitialiser les filtres de site
        $this->loadListTitle();
    }

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

            // Calculer le nombre total de pages
            $this->totalPages = max(1, ceil($totalItems / $this->perPage));

            // Charger uniquement la page courante
            $result = $this->fetchProductsFromDatabase($allSkus, $this->page, $this->perPage);

            if (isset($result['error'])) {
                $products = [];
            } else {
                $products = $result['data'] ?? [];
                $products = array_map(fn($p) => (array) $p, $products);
                
                // Nettoyer les prix et noms des produits
                foreach ($products as &$product) {
                    $product['price'] = $this->cleanPrice($product['price'] ?? 0);
                    $product['special_price'] = $this->cleanPrice($product['special_price'] ?? 0);
                    if (isset($product['title'])) {
                        $product['title'] = $this->normalizeAndCleanText($product['title']);
                    }
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

    /**
     * Supprimer un produit de la liste
     */
    public function removeProduct(string $sku): void
    {
        try {
            // Vérifier si le produit existe dans la liste
            $exists = DetailProduct::where('list_product_id', $this->id)
                ->where('EAN', $sku)
                ->exists();
            
            if (!$exists) {
                $this->error('Produit non trouvé dans la liste.');
                return;
            }
            
            // Supprimer le produit
            $deleted = DetailProduct::removeFromList($this->id, $sku);
            
            if ($deleted) {
                // Supprimer des caches si nécessaire
                Cache::forget("list_skus_{$this->id}");
                
                // Réinitialiser les données associées au produit
                unset($this->competitorResults[$sku]);
                unset($this->expandedProducts[$sku]);
                unset($this->manualSearchQueries[$sku]);
                unset($this->manualSearchResults[$sku]);
                unset($this->manualSearchExpanded[$sku]);
                unset($this->searchingProducts[$sku]);
                unset($this->manualSearchLoading[$sku]);
                unset($this->selectedSitesByProduct[$sku]);
                
                // Retirer de la sélection
                $this->selectedProducts = array_filter(
                    $this->selectedProducts, 
                    fn($selectedSku) => $selectedSku !== $sku
                );
                
                // Rafraîchir la liste
                $this->success('Produit supprimé avec succès.');
                
                // Forcer le rechargement des données
                $this->refreshProducts();
            } else {
                $this->error('Erreur lors de la suppression du produit.');
            }
            
        } catch (\Exception $e) {
            $this->dispatch('alert', 
                type: 'error',
                message: 'Erreur: ' . $e->getMessage()
            );
        }
    }

    /**
     * Supprimer plusieurs produits de la liste
     */
    public function removeMultipleProducts(array $skus): void
    {
        try {
            // Valider que nous avons bien une liste de SKUs
            if (empty($skus)) {
                $this->warning('Aucun produit sélectionné.');
                return;
            }

            // Compter le nombre de produits avant suppression
            $countBefore = DetailProduct::where('list_product_id', $this->id)
                ->whereIn('EAN', $skus)
                ->count();
            
            if ($countBefore === 0) {
                $this->warning('Aucun des produits sélectionnés n\'existe dans cette liste.');
                return;
            }
            
            // Supprimer les produits
            $deletedCount = DetailProduct::where('list_product_id', $this->id)
                ->whereIn('EAN', $skus)
                ->delete();
            
            if ($deletedCount > 0) {
                // Supprimer les caches
                Cache::forget("list_skus_{$this->id}");
                
                // Supprimer les données associées aux produits supprimés
                foreach ($skus as $sku) {
                    unset($this->competitorResults[$sku]);
                    unset($this->expandedProducts[$sku]);
                    unset($this->manualSearchQueries[$sku]);
                    unset($this->manualSearchResults[$sku]);
                    unset($this->manualSearchExpanded[$sku]);
                    unset($this->searchingProducts[$sku]);
                    unset($this->manualSearchLoading[$sku]);
                    unset($this->selectedSitesByProduct[$sku]);
                }
                
                // Vider la sélection
                $this->selectedProducts = [];
                
                $this->success('produit(s) supprimé(s) avec succès.');
                
                // Forcer le rechargement sans changer de page
                $this->loading = true;
                
            } else {
                $this->error('Erreur lors de la suppression des produits.');
            }
            
        } catch (\Exception $e) {
            $this->dispatch('alert', 
                type: 'error',
                message: 'Erreur: ' . $e->getMessage()
            );
        }
    }

    /**
     * Basculer la sélection d'un produit
     */
    public function toggleProductSelection(string $sku): void
    {
        $key = array_search($sku, $this->selectedProducts);
        
        if ($key !== false) {
            // Retirer le produit de la sélection
            unset($this->selectedProducts[$key]);
            // Réindexer le tableau
            $this->selectedProducts = array_values($this->selectedProducts);
        } else {
            // Ajouter le produit à la sélection
            $this->selectedProducts[] = $sku;
        }
    }

    /**
     * Sélectionner tous les produits de la page courante
     */
    public function selectAllOnPage(): void
    {
        $currentProducts = $this->getCurrentPageProducts();
        $currentSkus = [];
        
        foreach ($currentProducts as $product) {
            if (isset($product['sku'])) {
                $currentSkus[] = $product['sku'];
            }
        }
        
        // Si tous les produits de la page sont déjà sélectionnés, les désélectionner tous
        $allSelected = !array_diff($currentSkus, $this->selectedProducts);
        
        if ($allSelected) {
            // Désélectionner tous les produits de la page
            $this->selectedProducts = array_diff($this->selectedProducts, $currentSkus);
        } else {
            // Ajouter les produits de la page qui ne sont pas déjà sélectionnés
            $newSelections = array_diff($currentSkus, $this->selectedProducts);
            $this->selectedProducts = array_merge($this->selectedProducts, $newSelections);
        }
    }

    /**
     * Désélectionner tous les produits
     */
    public function deselectAll(): void
    {
        $this->selectedProducts = [];
    }

    /**
     * Supprimer les produits sélectionnés
     */
    public function removeSelectedProducts(): void
    {
        if (empty($this->selectedProducts)) {
            $this->warning('Aucun produit sélectionné.');
            return;
        }
        
        // Appeler directement la suppression sans confirmation intermédiaire
        $this->removeMultipleProducts($this->selectedProducts);
    }

    /**
     * Vérifier si un produit est sélectionné
     */
    public function isProductSelected(string $sku): bool
    {
        return in_array($sku, $this->selectedProducts);
    }

    /**
     * Vérifier si tous les produits de la page sont sélectionnés
     */
    public function areAllProductsOnPageSelected(): bool
    {
        $currentProducts = $this->getCurrentPageProducts();
        
        if (empty($currentProducts) || empty($this->selectedProducts)) {
            return false;
        }
        
        $currentSkus = [];
        foreach ($currentProducts as $product) {
            if (isset($product['sku'])) {
                $currentSkus[] = $product['sku'];
            }
        }
        
        // Vérifier si tous les SKUs de la page sont dans la sélection
        return empty(array_diff($currentSkus, $this->selectedProducts));
    }

    // Modal de confirmation
    public bool $showConfirmModal = false;
    public array $confirmModalData = [];

    /**
     * Écouter les événements d'alerte
     */
    protected $listeners = [
        'alert' => 'showAlert',
        'confirm-delete' => 'showConfirmModal'
    ];

    public function showConfirmModal(array $data): void
    {
        $this->confirmModalData = $data;
        $this->showConfirmModal = true;
    }

    public function showAlert(string $type, string $message): void
    {
        session()->flash('alert', [
            'type' => $type,
            'message' => $message
        ]);
    }

    public function confirmedRemoveSelectedProducts(): void
    {
        $this->removeMultipleProducts($this->selectedProducts);
        $this->selectedProducts = [];
        $this->showConfirmModal = false;
    }

}; ?>

<div>
    <!-- Overlay de chargement -->
    <div wire:loading.delay.flex class="hidden fixed inset-0 z-50 items-center justify-center bg-transparent">
        <div
            class="flex flex-col items-center justify-center bg-white/90 backdrop-blur-sm rounded-2xl p-8 shadow-2xl border border-white/20 min-w-[200px]">
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mb-4"></div>
            <p class="text-lg font-semibold text-gray-800">Chargement</p>
            <p class="text-sm text-gray-600 mt-1">Veuillez patienter...</p>
        </div>
    </div>

    <!-- En-tête de la liste -->
    <div class="mx-auto w-full px-4 py-6 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $listTitle }}</h1>
                <p class="mt-1 text-sm text-gray-600">Gestion des produits de la liste</p>
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
                    Désélectionner tout
                </button>
            @endif

                <x-button wire:navigate href="{{ route('top-product.edit', $id) }}" label="Ajouter produit dans la list" class="btn-primary" />

                <button wire:click="refreshProducts"
                    class="btn btn-sm btn-outline"
                    wire:loading.attr="disabled">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                        </path>
                    </svg>
                    Actualiser
                </button>
                
                <button wire:click="searchAllCompetitorsOnPage"
                    class="btn btn-sm btn-success"
                    wire:loading.attr="disabled">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    Rechercher tous les concurrents
                </button>
            </div>
        </div>

        <!-- Indicateur de chargement des concurrents -->
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

        <!-- Statistiques de la page -->
        <div class="mb-6 grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="stats shadow">
                <div class="stat">
                    <div class="stat-title">Total produits</div>
                    <div class="stat-value">{{ $totalItems ?? 0 }}</div>
                </div>
            </div>
            <div class="stats shadow">
                <div class="stat">
                    <div class="stat-title">Page actuelle</div>
                    <div class="stat-value text-primary">{{ $page }} / {{ $totalPages }}</div>
                </div>
            </div>
            <div class="stats shadow">
                <div class="stat">
                    <div class="stat-title">Produits par page</div>
                    <div class="stat-value text-secondary">{{ $perPage }}</div>
                </div>
            </div>
            <div class="stats shadow">
                <div class="stat">
                    <div class="stat-title">Produits chargés</div>
                    <div class="stat-value text-info">{{ count($products) }}</div>
                </div>
            </div>
        </div>

        <!-- Pagination -->
        @if($totalPages > 1)
            <div class="mb-6 flex items-center justify-between">
                <div class="flex-1 flex justify-between sm:hidden">
                    <button wire:click="previousPage"
                        class="btn btn-sm"
                        :disabled="$page <= 1">
                        Précédent
                    </button>
                    <button wire:click="nextPage"
                        class="btn btn-sm ml-2"
                        :disabled="$page >= $totalPages">
                        Suivant
                    </button>
                </div>
                <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm text-gray-700">
                            Affichage de
                            <span class="font-medium">{{ min(($page - 1) * $perPage + 1, $totalItems) }}</span>
                            à
                            <span class="font-medium">{{ min($page * $perPage, $totalItems) }}</span>
                            sur
                            <span class="font-medium">{{ $totalItems }}</span>
                            résultats
                        </p>
                    </div>
                    <div>
                        <div class="join">
                            <button wire:click="previousPage"
                                class="join-item btn btn-sm"
                                :disabled="$page <= 1">
                                «
                            </button>
                            
                            @foreach($this->getPaginationButtons() as $button)
                                @if($button['page'] === null)
                                    <button class="join-item btn btn-sm btn-disabled">
                                        {{ $button['label'] }}
                                    </button>
                                @else
                                    <button wire:click="goToPage({{ $button['page'] }})"
                                        class="join-item btn btn-sm {{ $button['active'] ? 'btn-active' : '' }}">
                                        {{ $button['label'] }}
                                    </button>
                                @endif
                            @endforeach
                            
                            <button wire:click="nextPage"
                                class="join-item btn btn-sm"
                                :disabled="$page >= $totalPages">
                                »
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <!-- Tableau principal des produits -->
        <div class="overflow-x-auto" wire:loading.class="opacity-50">
            <table class="table table-xs">
                <thead>
                    <tr>
                        <th>
                            <!-- Case à cocher pour sélectionner/désélectionner tous les produits de la page -->
                            <label class="cursor-pointer">
                                <input type="checkbox" 
                                    class="checkbox checkbox-xs" 
                                    wire:click="selectAllOnPage"
                                    {{ $this->areAllProductsOnPageSelected() ? 'checked' : '' }}>
                            </label>
                        </th>
                        <th>#</th>
                        <th>EAN</th>
                        <th>Image</th>
                        <th>Produit</th>
                        <th>Notre Prix</th>
                        <th>Concurrents auto</th>
                        <th>Type</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($products as $index => $product)
                        @php
                            $hasCompetitors = isset($competitorResults[$product['sku']]);
                            $hasManualSearch = isset($manualSearchResults[$product['sku']]);
                            $isSearchingAuto = isset($searchingProducts[$product['sku']]);
                            $isSearchingManual = isset($manualSearchLoading[$product['sku']]);
                            $rowNumber = ($page - 1) * $perPage + $index + 1;
                            
                            // Correction pour l'image
                            $imageUrl = null;
                            if (!empty($product['swatch_image'])) {
                                $imageUrl = 'https://www.cosma-parfumeries.com/media/catalog/product' . $product['swatch_image'];
                            } elseif (!empty($product['thumbnail']) && filter_var($product['thumbnail'], FILTER_VALIDATE_URL)) {
                                $imageUrl = $product['thumbnail'];
                            } elseif (!empty($product['image']) && filter_var($product['image'], FILTER_VALIDATE_URL)) {
                                $imageUrl = $product['image'];
                            }
                            
                            // Compter les bons résultats (similarité >= 0.6)
                            $goodCompetitorsCount = 0;
                            if ($hasCompetitors && isset($competitorResults[$product['sku']]['good_count'])) {
                                $goodCompetitorsCount = $competitorResults[$product['sku']]['good_count'];
                            }
                            
                            // Obtenir les concurrents filtrés
                            $filteredCompetitors = $this->getFilteredCompetitors($product['sku']);
                            $filteredCount = count($filteredCompetitors);
                        @endphp
                        <tr class="hover">
                            <!-- Case à cocher pour sélectionner le produit -->
                            <td>
                                <label class="cursor-pointer">
                                    <input type="checkbox" 
                                        class="checkbox checkbox-xs" 
                                        wire:click="toggleProductSelection('{{ $product['sku'] }}')"
                                        {{ $this->isProductSelected($product['sku']) ? 'checked' : '' }}>
                                </label>
                            </td>
                            <!-- Numéro de ligne -->
                            <th>{{ $rowNumber }}</th>
                            
                            <!-- SKU -->
                            <td>
                                <div class="font-mono text-xs font-bold">{{ $product['sku'] }}</div>
                            </td>
                            
                            <!-- Image produit -->
                            <td>
                                <div class="avatar">
                                    <div class="w-12 h-12 rounded border border-gray-200 bg-gray-50">
                                        @if($imageUrl)
                                            <img src="{{ $imageUrl }}" 
                                                 alt="{{ $product['title'] }}"
                                                 class="w-full h-full object-contain p-0.5"
                                                 loading="lazy"
                                                 onerror="
                                                     this.onerror=null; 
                                                     this.src='https://placehold.co/48x48/cccccc/999999?text=No+Image';
                                                     this.classList.add('p-2');
                                                 ">
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
                            
                            <!-- Produit -->
                            <td>
                                <div class="font-medium">{{ $product['title'] }}</div>
                                @if(!empty($product['vendor']))
                                    <div class="text-xs opacity-70">
                                        {{ $product['vendor'] }}
                                    </div>
                                @endif
                            </td>
                            
                            <!-- Notre Prix -->
                            <td>
                                <div class="font-bold text-success">
                                    {{ $this->formatPrice($product['price']) }}
                                </div>
                                @if(!empty($product['special_price']) && $product['special_price'] < $product['price'])
                                    <div class="text-xs text-error line-through">
                                        {{ $this->formatPrice($product['special_price']) }}
                                    </div>
                                @endif
                            </td>
                            
                            <!-- Concurrents automatiques -->
                            <td>
                                <div class="space-y-1">
                                    <button wire:click="toggleCompetitors('{{ $product['sku'] }}')"
                                        class="btn btn-xs btn-info btn-outline w-full"
                                        wire:loading.attr="disabled"
                                        wire:target="toggleCompetitors('{{ $product['sku'] }}')">
                                        @if($isSearchingAuto)
                                            <span class="loading loading-spinner loading-xs"></span>
                                            Recherche...
                                        @else
                                            @if($hasCompetitors)
                                                @if($filteredCount > 0)
                                                    <span class="badge badge-success mr-1">{{ $filteredCount }}</span>
                                                    filtré(s)
                                                @else
                                                    Aucun résultat
                                                @endif
                                            @else
                                                Rechercher
                                            @endif
                                        @endif
                                    </button>
                                    
                                    @if($hasCompetitors && $goodCompetitorsCount > 0)
                                        <div class="text-xs text-center text-gray-500">
                                            ({{ $goodCompetitorsCount }} bon(s) résultat(s) au total)
                                        </div>
                                    @endif
                                </div>
                            </td>
                            
                            <!-- Type -->
                            <td>
                                @if(!empty($product['type']))
                                    <span class="badge badge-outline badge-sm">
                                        {{ $product['type'] }}
                                    </span>
                                @else
                                    <span class="text-xs opacity-70">N/A</span>
                                @endif
                            </td>
                            
                            <!-- Actions -->
                            <td>
                                <div class="flex space-x-1">
                                    <!-- Bouton Supprimer -->
                                    <button wire:click="removeProduct('{{ $product['sku'] }}')"
                                        class="btn btn-xs btn-error btn-outline"
                                        title="Supprimer de la liste"
                                        onclick="return confirm('Êtes-vous sûr de vouloir supprimer ce produit de la liste ?')">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                    </button>

                                    @if($hasCompetitors && $filteredCount > 0)
                                        <div class="tooltip" data-tip="{{ $filteredCount }} résultat(s) filtré(s) (sur {{ $goodCompetitorsCount }} bons résultats)">
                                            <div class="badge badge-success">
                                                {{ $filteredCount }}
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        
                        <!-- Tableau des résultats des concurrents automatiques -->
                        @if($hasCompetitors && isset($expandedProducts[$product['sku']]))
                            @php
                                // Obtenir les sites disponibles pour ce produit
                                $availableSites = $this->getAvailableSitesForProduct($product['sku']);
                                $hasAvailableSites = count($availableSites) > 0;
                                $stats = $this->getFilterStats($product['sku']);
                            @endphp
                            <tr class="bg-base-100 border-t-0">
                                <td colspan="9" class="p-0">
                                    <div class="p-4 bg-base-50 border border-base-300 rounded-lg m-2">
                                        <div class="flex justify-between items-center mb-4">
                                            <div>
                                                <h4 class="font-bold text-sm">
                                                    <span class="text-info">Résultats des concurrents automatiques</span>
                                                    <span class="badge badge-success ml-2">
                                                        {{ $filteredCount }} résultat(s) filtré(s)
                                                    </span>
                                                    @if($stats['good'] > $filteredCount)
                                                        <span class="badge badge-neutral ml-1">
                                                            {{ $stats['good'] - $filteredCount }} caché(s)
                                                        </span>
                                                    @endif
                                                </h4>
                                                <p class="text-xs text-gray-600 mt-1">
                                                    Produit: <span class="font-semibold">{{ $product['title'] }}</span> 
                                                    | Notre prix: <span class="font-bold text-success">{{ $this->formatPrice($product['price']) }}</span>
                                                    | Seuil de similarité: ≥60%
                                                </p>
                                            </div>
                                            <button wire:click="toggleCompetitors('{{ $product['sku'] }}')" 
                                                    class="btn btn-xs btn-ghost">
                                                × Fermer
                                            </button>
                                        </div>
                                        
                                        <!-- Filtre par site -->
                                        @if($hasAvailableSites)
                                            <div class="mb-4 p-3 bg-base-100 border border-base-300 rounded-lg">
                                                <div class="flex justify-between items-center mb-2">
                                                    <div class="text-xs font-semibold text-gray-700">
                                                        <i class="fas fa-filter mr-1"></i> Filtre par site
                                                        @if(isset($selectedSitesByProduct[$product['sku']]))
                                                            <span class="badge badge-xs badge-info ml-2">
                                                                {{ count($selectedSitesByProduct[$product['sku']]) }} sélectionné(s)
                                                            </span>
                                                        @endif
                                                    </div>
                                                    <div class="flex space-x-1">
                                                        <button wire:click="selectAllSites('{{ $product['sku'] }}')"
                                                                class="btn btn-xs btn-outline btn-success"
                                                                title="Sélectionner tous les sites">
                                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                                            </svg>
                                                            Tout
                                                        </button>
                                                        <button wire:click="deselectAllSites('{{ $product['sku'] }}')"
                                                                class="btn btn-xs btn-outline btn-error"
                                                                title="Désélectionner tous les sites">
                                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                            </svg>
                                                            Aucun
                                                        </button>
                                                    </div>
                                                </div>
                                                
                                                <div class="flex flex-wrap gap-2 mt-2">
                                                    @foreach($availableSites as $site)
                                                        @php
                                                            $isSelected = $this->isSiteSelected($product['sku'], $site['id']);
                                                        @endphp
                                                        <label class="cursor-pointer">
                                                            <input type="checkbox" 
                                                                   class="checkbox checkbox-xs hidden"
                                                                   wire:click="toggleSiteFilter('{{ $product['sku'] }}', {{ $site['id'] }}, '{{ $site['name'] }}')"
                                                                   {{ $isSelected ? 'checked' : '' }}>
                                                            <span class="badge badge-outline {{ $isSelected ? 'badge-info' : 'badge-neutral' }} hover:badge-info transition-colors duration-200">
                                                                {{ $site['name'] }}
                                                                <span class="badge badge-xs {{ $isSelected ? 'badge-success' : 'badge-neutral' }} ml-1">
                                                                    {{ $site['count'] }}
                                                                </span>
                                                            </span>
                                                        </label>
                                                    @endforeach
                                                </div>
                                                
                                                <!-- Statistiques de filtrage -->
                                                <div class="mt-3 text-xs text-gray-600">
                                                    <div class="grid grid-cols-3 gap-2">
                                                        <div class="text-center">
                                                            <div class="font-semibold">{{ $stats['total'] }}</div>
                                                            <div class="text-[10px]">Total</div>
                                                        </div>
                                                        <div class="text-center">
                                                            <div class="font-semibold text-warning">{{ $stats['good'] }}</div>
                                                            <div class="text-[10px]">Bons résultats</div>
                                                        </div>
                                                        <div class="text-center">
                                                            <div class="font-semibold text-success">{{ $stats['filtered'] }}</div>
                                                            <div class="text-[10px]">Filtrés</div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        @endif
                                        
                                        @if($filteredCount > 0)
                                            <div class="overflow-x-auto">
                                                <table class="table table-xs table-zebra">
                                                    <thead>
                                                        <tr class="bg-base-200">
                                                            <th class="text-xs">Image</th>
                                                            <th class="text-xs">Concurrent / Site</th>
                                                            <th class="text-xs">Produit / Variation</th>
                                                            <th class="text-xs">Prix concurrent</th>
                                                            <th class="text-xs">Différence</th>
                                                            <th class="text-xs">Statut de nos prix par rapport aux concurrents</th>
                                                            <th class="text-xs">Niveau de correspondance</th>
                                                            <th class="text-xs">Date MAJ</th>
                                                            <th class="text-xs">Actions</th>
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
                                                                $similarityScore = $competitor->similarity_score ?? 0;
                                                                $scorePercentage = round($similarityScore * 100);
                                                                $scoreClass = $similarityScore >= 0.8 ? 'badge-success' : 
                                                                              ($similarityScore >= 0.7 ? 'badge-primary' : 
                                                                              ($similarityScore >= 0.6 ? 'badge-warning' : 'badge-neutral'));
                                                            @endphp
                                                            <tr>
                                                                <!-- Image du concurrent -->
                                                                <td>
                                                                    <div class="avatar">
                                                                        <div class="w-10 h-10 rounded border border-gray-200 bg-gray-50">
                                                                            <img src="{{ $competitorImage }}" 
                                                                                 alt="{{ $competitor->name ?? 'Concurrent' }}"
                                                                                 class="w-full h-full object-contain p-0.5"
                                                                                 loading="lazy"
                                                                                 onerror="
                                                                                     this.onerror=null; 
                                                                                     this.src='https://placehold.co/40x40/cccccc/999999?text=No+Img';
                                                                                     this.classList.add('p-2');
                                                                                 ">
                                                                        </div>
                                                                    </div>
                                                                </td>
                                                                
                                                                <!-- Concurrent / Site -->
                                                                <td class="text-xs">
                                                                    <div class="font-medium">{{ $competitor->vendor ?? 'N/A' }}</div>
                                                                    <div class="text-[10px] opacity-70">
                                                                        <span class="badge badge-xs badge-outline">
                                                                            {{ $competitor->site_name ?? ($competitor->web_site_id ?? 'N/A') }}
                                                                        </span>
                                                                    </div>
                                                                </td>
                                                                
                                                                <!-- Produit / Variation -->
                                                                <td class="text-xs">
                                                                    <div class="font-medium">{{ $competitor->name ?? 'N/A' }}</div>
                                                                    <div class="text-[10px] opacity-70">
                                                                        {{ $competitor->variation ?? 'Standard' }}
                                                                        @if(!empty($competitor->type))
                                                                            | {{ $competitor->type }}
                                                                        @endif
                                                                    </div>
                                                                </td>
                                                                
                                                                <!-- Prix concurrent -->
                                                                <td class="text-xs font-bold text-success">
                                                                    {{ $this->formatPrice($competitor->clean_price ?? $competitor->prix_ht) }}
                                                                </td>
                                                                
                                                                <!-- Différence de prix -->
                                                                <td class="text-xs">
                                                                    <div class="flex flex-col">
                                                                        <span class="font-medium {{ $competitor->price_difference < 0 ? 'text-error' : 'text-success' }}">
                                                                            {{ $difference }}
                                                                        </span>
                                                                        <span class="text-[10px] {{ $competitor->price_difference_percent < 0 ? 'text-error' : 'text-success' }}">
                                                                            {{ $percentage }}
                                                                        </span>
                                                                    </div>
                                                                </td>
                                                                
                                                                <!-- Statut -->
                                                                <td>
                                                                    <span class="badge badge-xs {{ $priceStatusClass }}">
                                                                        {{ $priceStatusLabel }}
                                                                    </span>
                                                                </td>
                                                                
                                                                <!-- Niveau de correspondance -->
                                                                <td class="text-xs">
                                                                    <div class="flex flex-col items-center">
                                                                        <span class="badge badge-xs {{ $scoreClass }}">
                                                                            {{ $competitor->match_level ?? 'N/A' }}
                                                                        </span>
                                                                    </div>
                                                                </td>
                                                                
                                                                <!-- Score de similarité -->
                                                                {{-- <td class="text-xs">
                                                                    <div class="flex flex-col items-center">
                                                                        <span class="badge badge-xs {{ $scoreClass }}">
                                                                            {{ $scorePercentage }}%
                                                                        </span>
                                                                    </div>
                                                                </td> --}}
                                                                <td class="text-xs">
                                                                    <div class="flex flex-col items-center">
                                                                        {{ \Carbon\Carbon::parse($competitor->updated_at)->translatedFormat('j F Y \\à H:i') }}
                                                                    </div>
                                                                </td>                                                                
                                                                <!-- Actions -->
                                                                <td>
                                                                    @if(!empty($competitor->url))
                                                                        <a href="{{ $competitor->url }}" 
                                                                           target="_blank" 
                                                                           class="btn btn-xs btn-outline btn-info"
                                                                           title="Voir le produit">
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
                                            
                                            <!-- Légende des scores -->
                                            <div class="mt-4 p-3 bg-base-100 border border-base-300 rounded-lg">
                                                <div class="text-xs font-semibold mb-2">Légende des scores de similarité :</div>
                                                <div class="flex flex-wrap gap-2">
                                                    <div class="flex items-center">
                                                        <span class="badge badge-xs badge-success mr-1"></span>
                                                        <span class="text-xs">Excellent (≥80%)</span>
                                                    </div>
                                                    <div class="flex items-center">
                                                        <span class="badge badge-xs badge-primary mr-1"></span>
                                                        <span class="text-xs">Très bon (≥70%)</span>
                                                    </div>
                                                    <div class="flex items-center">
                                                        <span class="badge badge-xs badge-warning mr-1"></span>
                                                        <span class="text-xs">Bon (≥60%)</span>
                                                    </div>
                                                </div>
                                            </div>
                                        @else
                                            <div class="text-center py-8">
                                                <div class="text-gray-400 mb-2">
                                                    <svg class="w-12 h-12 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                    </svg>
                                                </div>
                                                <p class="text-sm text-gray-600">
                                                    @if($hasAvailableSites && isset($selectedSitesByProduct[$product['sku']]))
                                                        Aucun résultat ne correspond aux sites sélectionnés.
                                                    @else
                                                        Aucun concurrent avec un bon niveau de similarité trouvé.
                                                    @endif
                                                </p>
                                                <p class="text-xs text-gray-500 mt-1">Seuil minimum : 60% de similarité</p>
                                                @if($stats['good'] > 0)
                                                    <p class="text-xs text-gray-500 mt-1">
                                                        {{ $stats['good'] }} bon(s) résultat(s) trouvé(s) mais aucun n'est visible avec les filtres actuels.
                                                    </p>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="9" class="text-center py-8">
                                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24"
                                    stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                        d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <h3 class="mt-4 text-lg font-medium text-gray-900">Aucun produit trouvé</h3>
                                <p class="mt-2 text-sm text-gray-500">
                                    Aucun produit ne correspond à votre recherche ou la liste est vide.
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination en bas -->
        @if($totalPages > 1 && count($products) > 0)
            <div class="mt-6 flex items-center justify-between">
                <div class="flex-1 flex justify-between sm:hidden">
                    <button wire:click="previousPage"
                        class="btn btn-sm"
                        :disabled="$page <= 1">
                        Précédent
                    </button>
                    <button wire:click="nextPage"
                        class="btn btn-sm ml-2"
                        :disabled="$page >= $totalPages">
                        Suivant
                    </button>
                </div>
                <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-center">
                    <div class="join">
                        <button wire:click="previousPage"
                            class="join-item btn btn-sm"
                            :disabled="$page <= 1">
                            «
                        </button>
                        
                        @foreach($this->getPaginationButtons() as $button)
                            @if($button['page'] === null)
                                <button class="join-item btn btn-sm btn-disabled">
                                    {{ $button['label'] }}
                                </button>
                            @else
                                <button wire:click="goToPage({{ $button['page'] }})"
                                    class="join-item btn btn-sm {{ $button['active'] ? 'btn-active' : '' }}">
                                    {{ $button['label'] }}
                                </button>
                            @endif
                        @endforeach
                        
                        <button wire:click="nextPage"
                            class="join-item btn btn-sm"
                            :disabled="$page >= $totalPages">
                            »
                        </button>
                    </div>
                </div>
            </div>
        @endif


<!-- Modal de confirmation -->
@if(session()->has('confirm-delete'))
    <div class="modal modal-open">
        <div class="modal-box">
            <h3 class="font-bold text-lg">Confirmation de suppression</h3>
            <p class="py-4">{{ session('confirm-delete.message') }}</p>
            <div class="modal-action">
                <button class="btn btn-ghost" wire:click="$set('showConfirmModal', false)">Annuler</button>
                <button class="btn btn-error" wire:click="{{ session('confirm-delete.callback') }}">Confirmer</button>
            </div>
        </div>
    </div>
@endif        
    </div>

    @push('styles')
    <style>
        /* Animation de spin */
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

        /* Scrollbar pour les résultats */
        .overflow-y-auto::-webkit-scrollbar {
            width: 4px;
        }

        .overflow-y-auto::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 2px;
        }

        .overflow-y-auto::-webkit-scrollbar-thumb {
            background-color: #cbd5e1;
            border-radius: 2px;
        }

        .overflow-y-auto::-webkit-scrollbar-thumb:hover {
            background-color: #94a3b8;
        }

        /* Style pour les tooltips */
        .tooltip {
            position: relative;
            display: inline-block;
        }

        .tooltip:hover::before {
            content: attr(data-tip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            padding: 4px 8px;
            background: rgba(0, 0, 0, 0.8);
            color: white;
            font-size: 12px;
            border-radius: 4px;
            white-space: nowrap;
            z-index: 10;
        }

        /* Styles pour les images */
        .avatar img {
            object-fit: contain;
        }
        
        .avatar div {
            overflow: hidden;
        }
        
        /* Style pour les badges de score */
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
        
        .badge-primary {
            background-color: #0ea5e9 !important;
            color: white !important;
        }
        
        /* Animation pour l'expansion des résultats */
        .results-transition {
            transition: all 0.3s ease-in-out;
            max-height: 0;
            overflow: hidden;
        }
        
        .results-expanded {
            max-height: 1000px;
        }
        
        /* Style pour les tableaux de résultats */
        .results-table-container {
            background: linear-gradient(to bottom, #f8fafc, #f1f5f9);
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
        }
        
        .results-header {
            background: linear-gradient(to right, #dbeafe, #e0e7ff);
            border-bottom: 1px solid #c7d2fe;
        }
        
        /* Style pour les cellules de comparaison de prix */
        .price-comparison-cell {
            min-width: 100px;
        }
        
        /* Style pour les images des concurrents */
        .competitor-image {
            transition: transform 0.2s ease;
        }
        
        .competitor-image:hover {
            transform: scale(1.1);
        }
        
        /* Style pour les liens d'action */
        .action-link {
            transition: all 0.2s ease;
        }
        
        .action-link:hover {
            background-color: #3b82f6;
            color: white;
        }

/* Style pour les boutons de suppression */
.btn-error.btn-outline {
    border-color: #ef4444;
    color: #ef4444;
    background-color: transparent;
}

.btn-error.btn-outline:hover {
    background-color: #ef4444;
    color: white;
}

/* Animation pour la suppression */
@keyframes fadeOut {
    from { opacity: 1; }
    to { opacity: 0; }
}

.removing {
    animation: fadeOut 0.3s ease-out;
}

/* Style pour les cases à cocher */
.checkbox:checked {
    background-color: #3b82f6;
    border-color: #3b82f6;
}

/* Style pour les lignes sélectionnées */
tr.selected {
    background-color: #eff6ff !important;
}

/* Style pour les indicateurs de score */
.score-indicator {
    width: 60px;
    height: 6px;
    background: #e5e7eb;
    border-radius: 3px;
    overflow: hidden;
    position: relative;
}

.score-fill {
    height: 100%;
    position: absolute;
    left: 0;
    top: 0;
}

.score-fill.excellent { background: #10b981; }
.score-fill.very-good { background: #0ea5e9; }
.score-fill.good { background: #f59e0b; }
.score-fill.medium { background: #6b7280; }
.score-fill.poor { background: #ef4444; }

/* Légende des scores */
.score-legend {
    display: flex;
    gap: 12px;
    margin-top: 8px;
}

.score-legend-item {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    color: #6b7280;
}

.score-legend-color {
    width: 12px;
    height: 12px;
    border-radius: 2px;
}

/* Badge pour les bons résultats */
.badge-good {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    font-weight: 600;
}

/* Indicateur de seuil */
.threshold-indicator {
    position: relative;
    padding-left: 20px;
}

.threshold-indicator::before {
    content: "✓";
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    color: #10b981;
    font-weight: bold;
}

/* Style pour les résultats filtrés */
.filtered-results-info {
    background: #f0f9ff;
    border: 1px solid #bae6fd;
    border-radius: 6px;
    padding: 8px 12px;
    margin-bottom: 12px;
}

.filtered-results-info .badge {
    margin-right: 6px;
}

/* Style pour les filtres de site */
.site-filter-container {
    transition: all 0.3s ease;
}

.site-filter-badge {
    cursor: pointer;
    transition: all 0.2s ease;
    padding: 4px 8px;
    border-radius: 12px;
    border: 1px solid;
}

.site-filter-badge:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.site-filter-badge.selected {
    border-width: 2px;
}

/* Statistiques de filtrage */
.filter-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    margin-top: 12px;
}

.filter-stat-item {
    text-align: center;
    padding: 6px;
    background: #f8fafc;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
}

.filter-stat-value {
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 2px;
}

.filter-stat-label {
    font-size: 10px;
    color: #64748b;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .results-table-container {
        font-size: 12px;
    }
    
    .score-indicator {
        width: 40px;
    }
    
    .filter-stats {
        grid-template-columns: 1fr;
        gap: 4px;
    }
    
    .site-filter-badge {
        font-size: 11px;
        padding: 3px 6px;
    }
}        
    </style>
    
    @endpush

    @push('scripts')
    <script>
        // Script pour gérer l'affichage des modaux
        document.addEventListener('livewire:init', () => {
            Livewire.on('openModal', (data) => {
                // Vous pouvez ajouter ici une logique pour ouvrir un modal si nécessaire
                console.log('Ouvrir modal avec:', data);
            });
            
            // Écouter l'expansion des résultats
            Livewire.on('resultsExpanded', (sku) => {
                // Smooth scroll vers les résultats
                const element = document.querySelector(`[data-product-sku="${sku}"]`);
                if (element) {
                    element.scrollIntoView({ 
                        behavior: 'smooth', 
                        block: 'center' 
                    });
                }
            });
            
            // Afficher un message lorsque les résultats sont filtrés
            Livewire.hook('message.processed', (message) => {
                // Vérifier si nous avons des résultats de concurrents
                const competitorTables = document.querySelectorAll('.competitor-results-table');
                competitorTables.forEach(table => {
                    const rows = table.querySelectorAll('tbody tr');
                    if (rows.length === 0) {
                        const container = table.closest('.competitor-results-container');
                        if (container) {
                            const noResultsMsg = container.querySelector('.no-results-message');
                            if (!noResultsMsg) {
                                const msgDiv = document.createElement('div');
                                msgDiv.className = 'no-results-message text-center py-4 text-sm text-gray-600';
                                msgDiv.innerHTML = `
                                    <svg class="w-8 h-8 mx-auto mb-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    <p>Aucun résultat ne correspond aux filtres actuels.</p>
                                `;
                                table.parentNode.insertBefore(msgDiv, table.nextSibling);
                            }
                        }
                    }
                });
            });
        });
        
        // Fonction pour gérer les erreurs d'images
        document.addEventListener('DOMContentLoaded', function() {
            const images = document.querySelectorAll('img[onerror]');
            images.forEach(img => {
                img.addEventListener('error', function() {
                    if (!this.classList.contains('error-handled')) {
                        this.classList.add('error-handled');
                        this.src = 'https://placehold.co/48x48/cccccc/999999?text=No+Image';
                        this.classList.add('p-2');
                        this.classList.remove('object-contain');
                        this.classList.add('object-scale-down');
                    }
                });
            });
            
            // Initialiser les tooltips
            const tooltips = document.querySelectorAll('[data-tip]');
            tooltips.forEach(tooltip => {
                tooltip.addEventListener('mouseenter', function() {
                    const tipText = this.getAttribute('data-tip');
                    const tooltipEl = document.createElement('div');
                    tooltipEl.className = 'tooltip-content';
                    tooltipEl.textContent = tipText;
                    tooltipEl.style.position = 'absolute';
                    tooltipEl.style.background = 'rgba(0,0,0,0.8)';
                    tooltipEl.style.color = 'white';
                    tooltipEl.style.padding = '4px 8px';
                    tooltipEl.style.borderRadius = '4px';
                    tooltipEl.style.fontSize = '12px';
                    tooltipEl.style.zIndex = '1000';
                    tooltipEl.style.maxWidth = '200px';
                    tooltipEl.style.whiteSpace = 'nowrap';
                    
                    const rect = this.getBoundingClientRect();
                    tooltipEl.style.top = (rect.top - 30) + 'px';
                    tooltipEl.style.left = (rect.left + rect.width/2 - tooltipEl.offsetWidth/2) + 'px';
                    
                    document.body.appendChild(tooltipEl);
                    this.tooltipElement = tooltipEl;
                });
                
                tooltip.addEventListener('mouseleave', function() {
                    if (this.tooltipElement) {
                        this.tooltipElement.remove();
                        this.tooltipElement = null;
                    }
                });
            });
            
            // Ajouter des indicateurs visuels pour les scores
            const scoreCells = document.querySelectorAll('[data-score]');
            scoreCells.forEach(cell => {
                const score = parseFloat(cell.getAttribute('data-score'));
                if (!isNaN(score)) {
                    const percentage = Math.round(score * 100);
                    const indicator = document.createElement('div');
                    indicator.className = 'score-indicator';
                    
                    const fill = document.createElement('div');
                    fill.className = 'score-fill';
                    fill.style.width = percentage + '%';
                    
                    // Déterminer la classe en fonction du score
                    if (score >= 0.8) {
                        fill.className += ' excellent';
                    } else if (score >= 0.7) {
                        fill.className += ' very-good';
                    } else if (score >= 0.6) {
                        fill.className += ' good';
                    } else if (score >= 0.4) {
                        fill.className += ' medium';
                    } else {
                        fill.className += ' poor';
                    }
                    
                    indicator.appendChild(fill);
                    
                    // Ajouter un label
                    const label = document.createElement('div');
                    label.className = 'text-xs text-center mt-1';
                    label.textContent = percentage + '%';
                    
                    // Remplacer le contenu de la cellule
                    cell.innerHTML = '';
                    cell.appendChild(indicator);
                    cell.appendChild(label);
                }
            });
            
            // Gestion des filtres de site
            const siteFilters = document.querySelectorAll('.site-filter-badge');
            siteFilters.forEach(badge => {
                badge.addEventListener('click', function(e) {
                    if (e.target.type === 'checkbox') return;
                    const checkbox = this.querySelector('input[type="checkbox"]');
                    if (checkbox) {
                        checkbox.click();
                    }
                });
            });
        });
        
        // Fonction pour afficher un indicateur de chargement
        function showLoadingIndicator(element) {
            const loadingDiv = document.createElement('div');
            loadingDiv.className = 'loading-indicator';
            loadingDiv.innerHTML = `
                <div class="flex items-center justify-center p-4">
                    <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-blue-600 mr-3"></div>
                    <span class="text-sm text-gray-600">Recherche en cours...</span>
                </div>
            `;
            element.appendChild(loadingDiv);
            return loadingDiv;
        }
        
        // Fonction pour masquer l'indicateur de chargement
        function hideLoadingIndicator(loadingDiv) {
            if (loadingDiv && loadingDiv.parentNode) {
                loadingDiv.parentNode.removeChild(loadingDiv);
            }
        }
        
        // Fonction pour mettre à jour les compteurs de filtres
        function updateFilterCounts() {
            document.querySelectorAll('.site-filter-container').forEach(container => {
                const selectedCount = container.querySelectorAll('input[type="checkbox"]:checked').length;
                const countBadge = container.querySelector('.selected-count');
                if (countBadge) {
                    countBadge.textContent = selectedCount;
                }
            });
        }
        
        // Écouter les changements de checkbox pour mettre à jour les compteurs
        document.addEventListener('change', function(e) {
            if (e.target.type === 'checkbox' && e.target.closest('.site-filter-container')) {
                updateFilterCounts();
            }
        });
        
        // Initialiser les compteurs au chargement
        document.addEventListener('DOMContentLoaded', updateFilterCounts);
    </script>
    @endpush
</div>