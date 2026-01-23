<?php

namespace App\Livewire;

use Mary\Traits\Toast;
use Livewire\Volt\Component;
use App\Models\DetailProduct;
use App\Models\Comparaison;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;

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

    // Configuration OpenAI
    protected bool $useOpenAI = true; // Activer/désactiver OpenAI
    protected string $openAIModel = 'gpt-3.5-turbo'; // Utiliser GPT-3.5 pour commencer (moins cher)
    protected int $openAIMaxTokens = 1000;

    public function mount($id): void
    {
        $this->id = $id;
        $this->loadListTitle();
        $this->loadAvailableSites();
        
        // Tester OpenAI au montage
        dd($this->testOpenAIConnection());
    }

    /**
     * Tester la connexion OpenAI
     */
    protected function testOpenAIConnection(): void
    {
        try {
            $test = OpenAI::chat()->create([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    ['role' => 'user', 'content' => 'Test']
                ],
                'max_tokens' => 5
            ]);
            \Log::info('OpenAI connection test successful');
        } catch (\Exception $e) {
            \Log::error('OpenAI connection failed: ' . $e->getMessage());
            $this->useOpenAI = false;
            $this->warning('OpenAI désactivé: ' . $e->getMessage());
        }
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
            
            // Utiliser l'algorithme de recherche avec OpenAI
            $competitors = $this->findCompetitorsWithOpenAI($cleanedProductName, $cleanPrice);
            
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
            $competitors = $this->findCompetitorsWithOpenAI($searchQuery, $cleanPrice);
            
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
     * NOUVELLE MÉTHODE : Recherche de concurrents avec OpenAI
     */
    protected function findCompetitorsWithOpenAI(string $search, float $ourPrice): array
    {
        try {
            // Cache
            $cacheKey = 'openai_competitors_' . md5($search . '_' . $ourPrice);
            $cached = Cache::get($cacheKey);
            
            if ($cached !== null) {
                return $cached;
            }
            
            // Si OpenAI est désactivé, utiliser la méthode classique
            if (!$this->useOpenAI) {
                $competitors = $this->findCompetitorsWithMl($search, $ourPrice);
                Cache::put($cacheKey, $competitors, now()->addHours(1));
                return $competitors;
            }
            
            // 1. Analyser le produit avec OpenAI
            $analysis = $this->analyzeProductWithOpenAI($search);
            
            if (empty($analysis)) {
                throw new \Exception('OpenAI analysis failed');
            }
            
            // 2. Générer des termes de recherche basés sur l'analyse
            $searchQueries = $this->generateSearchQueriesFromAnalysis($analysis, $search);
            
            // 3. Rechercher dans la base de données
            $allCompetitors = [];
            foreach ($searchQueries as $query) {
                $competitors = $this->executeSearchQuery($query);
                $allCompetitors = array_merge($allCompetitors, $competitors);
            }
            
            // 4. Dédupliquer
            $uniqueCompetitors = $this->deduplicateCompetitors($allCompetitors);
            
            // 5. Noter la similarité avec OpenAI
            $scoredCompetitors = $this->scoreCompetitorsWithOpenAI($uniqueCompetitors, $search, $ourPrice);
            
            // 6. Filtrer et trier
            $filteredCompetitors = $this->filterAndSortCompetitors($scoredCompetitors, $ourPrice);
            
            // 7. Ajouter les comparaisons de prix
            $competitorsWithComparison = $this->addPriceComparisons($filteredCompetitors, $ourPrice);
            
            Cache::put($cacheKey, $competitorsWithComparison, now()->addHours(1));
            
            return $competitorsWithComparison;

        } catch (\Exception $e) {
            \Log::error('OpenAI search failed: ' . $e->getMessage());
            
            // Fallback vers la méthode ML
            return $this->findCompetitorsWithMl($search, $ourPrice);
        }
    }

    /**
     * Analyser le produit avec OpenAI (version simplifiée)
     */
    protected function analyzeProductWithOpenAI(string $productName): array
    {
        try {
            $cacheKey = 'openai_analysis_' . md5($productName);
            $cached = Cache::get($cacheKey);
            
            if ($cached !== null) {
                return $cached;
            }
            
            $response = OpenAI::chat()->create([
                'model' => $this->openAIModel,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => "Tu es un expert en produits cosmétiques et parfums. Analyse le nom du produit et extrais :
                        1. La marque (vendor) - si présente
                        2. Le nom clé du produit (sans la marque)
                        3. Le type de produit (eau de parfum, crème, rouge à lèvres, etc.)
                        4. La variation (volume, couleur, édition limitée, etc.)
                        
                        Réponds au format JSON uniquement avec ces 4 champs.
                        Si un champ n'est pas identifiable, laisse-le vide."
                    ],
                    [
                        'role' => 'user',
                        'content' => "Produit à analyser: \"{$productName}\"
                        
                        Exemples :
                        - 'Guerlain - Rouge G La recharge- Le rouge à lèvres soin personnalisable Edition Limitée 12 LE BRUN AMARANTE'
                        => {\"vendor\": \"Guerlain\", \"key_name\": \"Rouge G La recharge\", \"type\": \"rouge à lèvres\", \"variation\": \"Edition Limitée 12 LE BRUN AMARANTE\"}
                        
                        - 'Chanel - Coco Mademoiselle - Eau de Parfum - 50ml'
                        => {\"vendor\": \"Chanel\", \"key_name\": \"Coco Mademoiselle\", \"type\": \"eau de parfum\", \"variation\": \"50ml\"}
                        
                        - 'Crème hydratante visage 24h'
                        => {\"vendor\": \"\", \"key_name\": \"Crème hydratante visage 24h\", \"type\": \"crème hydratante\", \"variation\": \"\"}"
                    ]
                ],
                'temperature' => 0.1,
                'max_tokens' => 500
            ]);
            
            $content = $response->choices[0]->message->content;
            
            // Nettoyer le contenu (parfois OpenAI ajoute des backticks)
            $content = trim($content);
            $content = preg_replace('/^```json\s*/', '', $content);
            $content = preg_replace('/\s*```$/', '', $content);
            
            $analysis = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                \Log::warning('Invalid JSON from OpenAI: ' . $content);
                return $this->analyzeProductManually($productName);
            }
            
            // Valider que nous avons les champs requis
            $requiredFields = ['vendor', 'key_name', 'type', 'variation'];
            foreach ($requiredFields as $field) {
                if (!isset($analysis[$field])) {
                    $analysis[$field] = '';
                }
            }
            
            // Nettoyer les valeurs
            foreach ($analysis as $key => $value) {
                $analysis[$key] = $this->normalizeAndCleanText($value);
            }
            
            Cache::put($cacheKey, $analysis, now()->addHours(12));
            
            return $analysis;
            
        } catch (\Exception $e) {
            \Log::error('OpenAI analysis failed: ' . $e->getMessage());
            return $this->analyzeProductManually($productName);
        }
    }

    /**
     * Générer des requêtes de recherche basées sur l'analyse
     */
    protected function generateSearchQueriesFromAnalysis(array $analysis, string $originalSearch): array
    {
        $queries = [];
        $vendor = $analysis['vendor'] ?? '';
        $keyName = $analysis['key_name'] ?? '';
        $type = $analysis['type'] ?? '';
        $variation = $analysis['variation'] ?? '';
        
        // 1. Requête par marque et nom clé (la plus précise)
        if (!empty($vendor) && !empty($keyName)) {
            $searchTerms = $this->prepareSearchTermsForDB("{$vendor} {$keyName}");
            $queries[] = [
                'type' => 'vendor_key_name',
                'sql' => $this->buildSearchSQL($searchTerms),
                'priority' => 1
            ];
        }
        
        // 2. Requête par nom clé seulement
        if (!empty($keyName)) {
            $searchTerms = $this->prepareSearchTermsForDB($keyName);
            $queries[] = [
                'type' => 'key_name_only',
                'sql' => $this->buildSearchSQL($searchTerms),
                'priority' => 2
            ];
        }
        
        // 3. Requête par type et mots-clés
        if (!empty($type)) {
            $keywords = $this->extractKeywords($originalSearch);
            if (!empty($keywords)) {
                $searchTerms = $this->prepareSearchTermsForDB("{$type} " . implode(' ', $keywords));
                $queries[] = [
                    'type' => 'type_keywords',
                    'sql' => $this->buildSearchSQL($searchTerms),
                    'priority' => 3
                ];
            }
        }
        
        // 4. Requête par variation (volume, couleur, etc.)
        if (!empty($variation)) {
            $searchTerms = $this->prepareSearchTermsForDB($variation);
            $queries[] = [
                'type' => 'variation',
                'sql' => $this->buildSearchSQL($searchTerms),
                'priority' => 4
            ];
        }
        
        // 5. Requête fallback avec l'original
        if (empty($queries)) {
            $searchTerms = $this->prepareSearchTermsForDB($originalSearch);
            $queries[] = [
                'type' => 'fallback',
                'sql' => $this->buildSearchSQL($searchTerms),
                'priority' => 5
            ];
        }
        
        return $queries;
    }

    /**
     * Préparer les termes de recherche pour la base de données
     */
    protected function prepareSearchTermsForDB(string $text): string
    {
        $text = $this->normalizeAndCleanText($text);
        $words = explode(' ', $text);
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
        
        if (empty($significantWords)) {
            // Si aucun mot significatif, utiliser les 3 premiers mots
            $words = array_slice($words, 0, 3);
            foreach ($words as $word) {
                $word = trim($word);
                if (strlen($word) > 1) {
                    $significantWords[] = '+' . $word . '*';
                }
            }
        }
        
        return implode(' ', array_slice($significantWords, 0, 5));
    }

    /**
     * Construire la requête SQL de recherche
     */
    protected function buildSearchSQL(string $searchTerms): string
    {
        return "
            SELECT 
                lp.*,
                ws.name as site_name,
                lp.image_url as image_url,
                lp.url as product_url
            FROM last_price_scraped_product lp
            LEFT JOIN web_site ws ON lp.web_site_id = ws.id
            WHERE MATCH (lp.name, lp.vendor, lp.type, lp.variation) 
                AGAINST ('" . addslashes($searchTerms) . "' IN BOOLEAN MODE)
            AND (lp.variation != 'Standard' OR lp.variation IS NULL OR lp.variation = '')
            AND lp.prix_ht > 0
            ORDER BY 
                CASE 
                    WHEN lp.vendor LIKE '%" . addslashes($searchTerms) . "%' THEN 1
                    WHEN lp.name LIKE '%" . addslashes($searchTerms) . "%' THEN 2
                    ELSE 3
                END,
                lp.prix_ht ASC
            LIMIT 50
        ";
    }

    /**
     * Exécuter une requête de recherche
     */
    protected function executeSearchQuery(array $queryInfo): array
    {
        try {
            $competitors = DB::connection('mysql')->select($queryInfo['sql']);
            
            foreach ($competitors as $competitor) {
                $competitor->prix_ht = $this->cleanPrice($competitor->prix_ht ?? 0);
                $competitor->image = $this->getCompetitorImage($competitor);
                $competitor->search_type = $queryInfo['type'];
                $competitor->priority = $queryInfo['priority'];
            }
            
            return $competitors;
            
        } catch (\Exception $e) {
            \Log::warning('Search query failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Dédupliquer les concurrents
     */
    protected function deduplicateCompetitors(array $competitors): array
    {
        $unique = [];
        $seen = [];
        
        foreach ($competitors as $competitor) {
            // Créer une signature unique basée sur le nom, la marque et le prix
            $signature = md5(
                mb_strtolower(trim($competitor->name ?? '')) . '|' .
                mb_strtolower(trim($competitor->vendor ?? '')) . '|' .
                $this->cleanPrice($competitor->prix_ht ?? 0) . '|' .
                ($competitor->web_site_id ?? 0)
            );
            
            if (!in_array($signature, $seen)) {
                $seen[] = $signature;
                $unique[] = $competitor;
            }
        }
        
        return $unique;
    }

    /**
     * Filtrer et trier les concurrents
     */
    protected function filterAndSortCompetitors(array $competitors, float $ourPrice): array
    {
        // Filtrer par score de similarité
        $filtered = array_filter($competitors, function($c) {
            return ($c->similarity_score ?? 0) >= 0.6;
        });
        
        // Trier par score décroissant
        usort($filtered, function($a, $b) {
            $scoreA = $a->similarity_score ?? 0;
            $scoreB = $b->similarity_score ?? 0;
            $priorityA = $a->priority ?? 5;
            $priorityB = $b->priority ?? 5;
            
            // Priorité à la similarité, puis à la priorité de la requête
            if (abs($scoreA - $scoreB) > 0.1) {
                return $scoreB <=> $scoreA;
            }
            
            return $priorityA <=> $priorityB;
        });
        
        return array_slice($filtered, 0, 30); // Limiter à 30 résultats
    }

    /**
     * Extraire les mots-clés d'un texte
     */
    protected function extractKeywords(string $text): array
    {
        $text = $this->normalizeAndCleanText($text);
        $words = explode(' ', $text);
        $keywords = [];
        
        $stopWords = array_merge(
            $this->getGeneralStopWords(),
            $this->getProductStopWords()
        );
        
        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) > 3 && !in_array($word, $stopWords) && !is_numeric($word)) {
                $keywords[] = $word;
            }
        }
        
        return array_slice($keywords, 0, 5);
    }

    /**
     * Analyser le produit manuellement (fallback)
     */
    protected function analyzeProductManually(string $productName): array
    {
        $productName = $this->normalizeAndCleanText($productName);
        
        $analysis = [
            'vendor' => '',
            'key_name' => '',
            'type' => '',
            'variation' => ''
        ];
        
        // Essayer d'extraire le vendor (marque)
        if (preg_match('/^([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)\s*[-–]/u', $productName, $matches)) {
            $analysis['vendor'] = trim($matches[1]);
            $analysis['key_name'] = trim(substr($productName, strlen($matches[0])));
        } else {
            $analysis['key_name'] = $productName;
        }
        
        // Détecter le type de produit
        $typePatterns = [
            'eau de parfum' => '/eau\s+de\s+parfum|edp/i',
            'eau de toilette' => '/eau\s+de\s+toilette|edt/i',
            'parfum' => '/parfum(?!\w)/i',
            'rouge à lèvres' => '/rouge\s+(?:à|a)\s+l[eèè]vres|rouge\s+l[eèè]vres|lipstick/i',
            'crème' => '/cr[eèè]me|cream/i',
            'lotion' => '/lotion/i',
            'gel' => '/gel/i',
            'sérum' => '/s[eé]rum|serum/i',
            'masque' => '/masque|mask/i',
            'shampooing' => '/shampooing|shampoo/i'
        ];
        
        foreach ($typePatterns as $type => $pattern) {
            if (preg_match($pattern, $productName)) {
                $analysis['type'] = $type;
                break;
            }
        }
        
        // Détecter la variation (volume, couleur, etc.)
        if (preg_match('/(\d+\s*(?:ml|cl|l|g|kg|fl\s*oz)|rouge|noir|blanc|rose|brun|marron|[A-Z]+\s+\d+)/i', $productName, $matches)) {
            $analysis['variation'] = trim($matches[1]);
        }
        
        return $analysis;
    }

    /**
     * Noter les concurrents avec OpenAI
     */
    protected function scoreCompetitorsWithOpenAI(array $competitors, string $originalProduct, float $ourPrice): array
    {
        if (empty($competitors)) {
            return [];
        }
        
        // Limiter le nombre de concurrents à analyser
        $competitorsToAnalyze = array_slice($competitors, 0, 20);
        
        try {
            $response = OpenAI::chat()->create([
                'model' => $this->openAIModel,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => "Tu es un expert en comparaison de produits cosmétiques et parfums. 
                        Évalue la similarité entre le produit original et les produits concurrents.
                        Donne un score entre 0 et 1.
                        1 = produit identique, 0.8 = très similaire, 0.6 = similaire, <0.5 = pas similaire.
                        
                        Format de réponse JSON :
                        [
                            {
                                \"competitor_id\": \"identifiant\",
                                \"score\": 0.85,
                                \"reasons\": [\"marque identique\", \"même type\"]
                            }
                        ]"
                    ],
                    [
                        'role' => 'user',
                        'content' => "Produit original : \"" . $originalProduct . "\"
                        
                        Produits à comparer : " . json_encode(array_map(function($c) {
                            return [
                                'id' => $c->id ?? $c->url,
                                'name' => $c->name ?? '',
                                'vendor' => $c->vendor ?? '',
                                'type' => $c->type ?? '',
                                'variation' => $c->variation ?? ''
                            ];
                        }, $competitorsToAnalyze))
                    ]
                ],
                'temperature' => 0.2,
                'max_tokens' => 1000
            ]);
            
            $scores = json_decode($response->choices[0]->message->content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($scores)) {
                return $this->scoreCompetitorsManually($competitors, $originalProduct, $ourPrice);
            }
            
            // Créer un map des scores
            $scoreMap = [];
            foreach ($scores as $scoreData) {
                if (isset($scoreData['competitor_id']) && isset($scoreData['score'])) {
                    $scoreMap[$scoreData['competitor_id']] = [
                        'score' => (float) $scoreData['score'],
                        'reasons' => $scoreData['reasons'] ?? []
                    ];
                }
            }
            
            // Appliquer les scores
            $scoredCompetitors = [];
            foreach ($competitors as $competitor) {
                $competitorId = $competitor->id ?? $competitor->url;
                
                if (isset($scoreMap[$competitorId])) {
                    $scoreData = $scoreMap[$competitorId];
                    $competitor->similarity_score = $scoreData['score'];
                    $competitor->match_reasons = $scoreData['reasons'];
                    $competitor->similarity_explanation = implode(', ', $scoreData['reasons']);
                } else {
                    // Score par défaut si non trouvé
                    $competitor->similarity_score = $this->calculateManualSimilarity($competitor, $originalProduct);
                    $competitor->match_reasons = [];
                    $competitor->similarity_explanation = 'Score calculé automatiquement';
                }
                
                $competitor->match_level = $this->getMatchLevel($competitor->similarity_score);
                $competitor->price_assessment = $this->assessPrice($competitor->prix_ht, $ourPrice);
                $scoredCompetitors[] = $competitor;
            }
            
            return $scoredCompetitors;
            
        } catch (\Exception $e) {
            \Log::warning('OpenAI scoring failed: ' . $e->getMessage());
            return $this->scoreCompetitorsManually($competitors, $originalProduct, $ourPrice);
        }
    }

    /**
     * Noter les concurrents manuellement
     */
    protected function scoreCompetitorsManually(array $competitors, string $originalProduct, float $ourPrice): array
    {
        $scoredCompetitors = [];
        
        foreach ($competitors as $competitor) {
            $competitor->similarity_score = $this->calculateManualSimilarity($competitor, $originalProduct);
            $competitor->similarity_explanation = 'Score calculé automatiquement (fallback)';
            $competitor->match_reasons = [];
            $competitor->price_assessment = $this->assessPrice($competitor->prix_ht, $ourPrice);
            $competitor->match_level = $this->getMatchLevel($competitor->similarity_score);
            
            $scoredCompetitors[] = $competitor;
        }
        
        return $scoredCompetitors;
    }

    /**
     * Calculer la similarité manuellement
     */
    protected function calculateManualSimilarity($competitor, string $originalProduct): float
    {
        $score = 0.0;
        
        $originalLower = mb_strtolower($originalProduct);
        $competitorName = mb_strtolower($competitor->name ?? '');
        $competitorVendor = mb_strtolower($competitor->vendor ?? '');
        $competitorType = mb_strtolower($competitor->type ?? '');
        
        // Similarité du vendor (30%)
        if (!empty($competitorVendor) && str_contains($originalLower, $competitorVendor)) {
            $score += 0.3;
        }
        
        // Similarité du nom (40%)
        similar_text($originalLower, $competitorName, $nameSimilarity);
        $score += ($nameSimilarity / 100) * 0.4;
        
        // Vérifier les mots communs (20%)
        $originalWords = explode(' ', $originalLower);
        $competitorWords = explode(' ', $competitorName);
        $commonWords = array_intersect($originalWords, $competitorWords);
        
        if (count($commonWords) > 0) {
            $score += min(0.2, count($commonWords) * 0.05);
        }
        
        // Bonus pour le type correspondant (10%)
        if (!empty($competitorType) && str_contains($originalLower, $competitorType)) {
            $score += 0.1;
        }
        
        return min(1.0, $score);
    }

    /**
     * Évaluer le prix
     */
    protected function assessPrice($competitorPrice, float $ourPrice): string
    {
        $competitorPriceFloat = $this->cleanPrice($competitorPrice);
        
        if ($ourPrice == 0 || $competitorPriceFloat == 0) {
            return 'non évalué';
        }
        
        $ratio = $competitorPriceFloat / $ourPrice;
        
        if ($ratio < 0.7) return 'très bon marché';
        if ($ratio < 0.9) return 'bon marché';
        if ($ratio < 1.1) return 'équivalent';
        if ($ratio < 1.3) return 'légèrement plus cher';
        return 'beaucoup plus cher';
    }

    /**
     * Ajouter les comparaisons de prix
     */
    protected function addPriceComparisons(array $competitors, float $ourPrice): array
    {
        foreach ($competitors as $competitor) {
            $competitorPrice = $this->cleanPrice($competitor->prix_ht ?? 0);
            
            $competitor->price_difference = $ourPrice - $competitorPrice;
            $competitor->price_difference_percent = $ourPrice > 0 ? (($ourPrice - $competitorPrice) / $ourPrice) * 100 : 0;
            
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
            
            $competitor->clean_price = $competitorPrice;
        }
        
        return $competitors;
    }

    /**
     * Obtenir le niveau de correspondance
     */
    protected function getMatchLevel(float $similarityScore): string
    {
        if ($similarityScore >= 0.8) return 'excellent';
        if ($similarityScore >= 0.7) return 'très bon';
        if ($similarityScore >= 0.6) return 'bon';
        if ($similarityScore >= 0.5) return 'moyen';
        return 'faible';
    }

    /**
     * Normaliser et nettoyer un texte
     */
    protected function normalizeAndCleanText(string $text): string
    {
        if (empty($text)) {
            return '';
        }

        if (!mb_check_encoding($text, 'UTF-8')) {
            $encodings = ['ISO-8859-1', 'ISO-8859-15', 'Windows-1252', 'CP1252'];
            foreach ($encodings as $encoding) {
                $converted = mb_convert_encoding($text, 'UTF-8', $encoding);
                if (mb_check_encoding($converted, 'UTF-8')) {
                    $text = $converted;
                    break;
                }
            }
        }

        // Convertir les caractères spéciaux
        $replacements = [
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
            '�' => "'", '�' => "'",
            '�' => '"', '�' => '"',
            '�' => '€',
            '�' => '...',
            '[' => '', ']' => '', '(' => '', ')' => '',
        ];

        $text = strtr($text, $replacements);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Normaliser les espaces et caractères de contrôle
        $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        
        return $text;
    }

    /**
     * Obtenir les stop words généraux
     */
    protected function getGeneralStopWords(): array
    {
        return [
            'de', 'le', 'la', 'les', 'un', 'une', 'des', 'du', 'et', 'ou', 'pour', 'avec', 'sans',
            'the', 'a', 'an', 'and', 'or', 'in', 'on', 'at', 'by', 'to', 'of', 'for', 'with',
            'à', 'au', 'aux', 'dans', 'sur', 'sous', 'chez', 'par', 'entre',
            'ml', 'g', 'kg', 'l', 'oz', 'fl', 'cm', 'mm',
        ];
    }
    
    /**
     * Obtenir les stop words produits
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
            'jour', 'nuit', 'matin', 'soir',
            'edition', 'édition', 'coffret', 'spray', 'vapo', 'vaporisateur',
            'limitée', 'limitee', 'spéciale', 'speciale', 'exclusive', 'exclusif'
        ];
    }

    /**
     * Méthodes ML simplifiées pour fallback
     */
    protected function findCompetitorsWithMl(string $search, float $ourPrice): array
    {
        try {
            // Recherche basique sans OpenAI
            $searchTerms = $this->prepareSearchTermsForDB($search);
            
            $sql = "
                SELECT 
                    lp.*,
                    ws.name as site_name,
                    lp.image_url as image_url,
                    lp.url as product_url
                FROM last_price_scraped_product lp
                LEFT JOIN web_site ws ON lp.web_site_id = ws.id
                WHERE MATCH (lp.name, lp.vendor, lp.type, lp.variation) 
                    AGAINST ('" . addslashes($searchTerms) . "' IN BOOLEAN MODE)
                AND (lp.variation != 'Standard' OR lp.variation IS NULL OR lp.variation = '')
                AND lp.prix_ht > 0
                ORDER BY lp.prix_ht ASC
                LIMIT 30
            ";
            
            $competitors = DB::connection('mysql')->select($sql);
            
            foreach ($competitors as $competitor) {
                $competitor->prix_ht = $this->cleanPrice($competitor->prix_ht ?? 0);
                $competitor->image = $this->getCompetitorImage($competitor);
                $competitor->similarity_score = $this->calculateManualSimilarity($competitor, $search);
                $competitor->match_level = $this->getMatchLevel($competitor->similarity_score);
                $competitor->price_assessment = $this->assessPrice($competitor->prix_ht, $ourPrice);
            }
            
            $competitors = $this->addPriceComparisons($competitors, $ourPrice);
            
            // Filtrer par similarité
            $filtered = array_filter($competitors, function($c) {
                return ($c->similarity_score ?? 0) >= 0.6;
            });
            
            // Trier par score décroissant
            usort($filtered, function($a, $b) {
                return ($b->similarity_score ?? 0) <=> ($a->similarity_score ?? 0);
            });
            
            return array_values($filtered);
            
        } catch (\Exception $e) {
            \Log::error('ML search failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtenir l'image d'un concurrent
     */
    protected function getCompetitorImage($competitor): string
    {
        if (!empty($competitor->image_url)) {
            $imageUrl = $this->normalizeAndCleanText($competitor->image_url);
            
            if (filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                return $imageUrl;
            }
        }
        
        if (!empty($competitor->product_url)) {
            return $competitor->product_url;
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
     * Valider une URL d'image
     */
    public function isValidImageUrl($url): bool
    {
        if (empty($url)) {
            return false;
        }
        
        $url = $this->normalizeAndCleanText($url);
        
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'];
        $path = parse_url($url, PHP_URL_PATH);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        
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
        if (isset($competitor->image) && !empty($competitor->image)) {
            return $this->normalizeAndCleanText($competitor->image);
        }
        
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
        $this->expandedProducts = [];
        $this->manualSearchQueries = [];
        $this->manualSearchResults = [];
        $this->manualSearchExpanded = [];
        $this->selectedSitesByProduct = [];
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
        $this->selectedProducts = [];
        $this->selectedSitesByProduct = [];
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

            $this->totalPages = max(1, ceil($totalItems / $this->perPage));

            $result = $this->fetchProductsFromDatabase($allSkus, $this->page, $this->perPage);

            if (isset($result['error'])) {
                $products = [];
            } else {
                $products = $result['data'] ?? [];
                $products = array_map(fn($p) => (array) $p, $products);
                
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
            $exists = DetailProduct::where('list_product_id', $this->id)
                ->where('EAN', $sku)
                ->exists();
            
            if (!$exists) {
                $this->error('Produit non trouvé dans la liste.');
                return;
            }
            
            $deleted = DetailProduct::removeFromList($this->id, $sku);
            
            if ($deleted) {
                Cache::forget("list_skus_{$this->id}");
                
                unset($this->competitorResults[$sku]);
                unset($this->expandedProducts[$sku]);
                unset($this->manualSearchQueries[$sku]);
                unset($this->manualSearchResults[$sku]);
                unset($this->manualSearchExpanded[$sku]);
                unset($this->searchingProducts[$sku]);
                unset($this->manualSearchLoading[$sku]);
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
            if (empty($skus)) {
                $this->warning('Aucun produit sélectionné.');
                return;
            }

            $countBefore = DetailProduct::where('list_product_id', $this->id)
                ->whereIn('EAN', $skus)
                ->count();
            
            if ($countBefore === 0) {
                $this->warning('Aucun des produits sélectionnés n\'existe dans cette liste.');
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
                    unset($this->manualSearchQueries[$sku]);
                    unset($this->manualSearchResults[$sku]);
                    unset($this->manualSearchExpanded[$sku]);
                    unset($this->searchingProducts[$sku]);
                    unset($this->manualSearchLoading[$sku]);
                    unset($this->selectedSitesByProduct[$sku]);
                }
                
                $this->selectedProducts = [];
                $this->success('produit(s) supprimé(s) avec succès.');
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
            unset($this->selectedProducts[$key]);
            $this->selectedProducts = array_values($this->selectedProducts);
        } else {
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
        
        $allSelected = !array_diff($currentSkus, $this->selectedProducts);
        
        if ($allSelected) {
            $this->selectedProducts = array_diff($this->selectedProducts, $currentSkus);
        } else {
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
                        <th>SKU</th>
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
                                <div class="font-medium">{{ Str::limit($product['title'], 50) }}</div>
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
                                                            <th class="text-xs">Score</th>
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
                                                                    <div class="font-medium">{{ Str::limit($competitor->name ?? 'N/A', 30) }}</div>
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
                                                                <td class="text-xs">
                                                                    <div class="flex flex-col items-center">
                                                                        <span class="badge badge-xs {{ $scoreClass }}">
                                                                            {{ $scorePercentage }}%
                                                                        </span>
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