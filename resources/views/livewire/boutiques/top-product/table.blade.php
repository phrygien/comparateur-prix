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
    
    public array $competitorResults = [];
    public bool $searchingCompetitors = false;
    public array $searchingProducts = [];
    public array $expandedProducts = [];
    
    protected $cacheTTL = 3600;

    public array $manualSearchQueries = [];
    public array $manualSearchResults = [];
    public array $manualSearchLoading = [];
    public array $manualSearchExpanded = [];

    public array $selectedProducts = [];
    public array $siteFilters = [];
    public array $availableSites = [];
    public array $selectedSitesByProduct = [];

    protected bool $useOpenAI = true;
    protected string $openAIModel = 'gpt-3.5-turbo';
    protected int $openAIMaxTokens = 1000;

    // NOUVEAU: Activer/désactiver le fallback manuel
    protected bool $enableManualFallback = true;
    
    // NOUVEAU: Logging détaillé
    public array $searchLogs = [];

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

    public function toggleSiteFilter(string $sku, int $siteId, string $siteName): void
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

    public function selectAllSites(string $sku): void
    {
        $siteIds = array_column($this->availableSites, 'id');
        $this->selectedSitesByProduct[$sku] = $siteIds;
    }

    public function deselectAllSites(string $sku): void
    {
        unset($this->selectedSitesByProduct[$sku]);
    }

    public function isSiteSelected(string $sku, int $siteId): bool
    {
        return isset($this->selectedSitesByProduct[$sku]) && 
               in_array($siteId, $this->selectedSitesByProduct[$sku]);
    }

    public function getFilteredCompetitors(string $sku): array
    {
        if (!isset($this->competitorResults[$sku]['competitors'])) {
            return [];
        }

        $competitors = $this->competitorResults[$sku]['competitors'];
        
        $goodCompetitors = array_filter($competitors, fn($c) => ($c->similarity_score ?? 0) >= 0.6);
        
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

    public function getFilterStats(string $sku): array
    {
        if (!isset($this->competitorResults[$sku])) {
            return ['total' => 0, 'good' => 0, 'filtered' => 0];
        }

        $competitors = $this->competitorResults[$sku]['competitors'] ?? [];
        $total = count($competitors);
        
        $goodCompetitors = array_filter($competitors, fn($c) => ($c->similarity_score ?? 0) >= 0.6);
        $goodCount = count($goodCompetitors);
        
        $filteredCompetitors = $this->getFilteredCompetitors($sku);
        $filteredCount = count($filteredCompetitors);
        
        return [
            'total' => $total,
            'good' => $goodCount,
            'filtered' => $filteredCount
        ];
    }

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
            $cleanedProductName = $this->normalizeAndCleanText($productName);
            $cleanPrice = $this->cleanPrice($price);
            
            // NOUVEAU: Log de départ
            $this->logSearch($sku, "Début recherche pour: {$cleanedProductName}");
            
            // Recherche avec plusieurs stratégies
            $allCompetitors = [];
            
            // Stratégie 1: Recherche intelligente avec OpenAI
            if ($this->useOpenAI) {
                $this->logSearch($sku, "Stratégie 1: Recherche OpenAI");
                $openAICompetitors = $this->findCompetitorsWithOpenAI($cleanedProductName, $cleanPrice);
                $allCompetitors = array_merge($allCompetitors, $openAICompetitors);
                $this->logSearch($sku, "OpenAI trouvé: " . count($openAICompetitors) . " résultats");
            }
            
            // Stratégie 2: Recherche manuelle (fallback)
            if ($this->enableManualFallback && count($allCompetitors) < 5) {
                $this->logSearch($sku, "Stratégie 2: Recherche manuelle (fallback)");
                $manualCompetitors = $this->findCompetitorsWithManualSearch($cleanedProductName, $cleanPrice);
                $allCompetitors = array_merge($allCompetitors, $manualCompetitors);
                $this->logSearch($sku, "Manuel trouvé: " . count($manualCompetitors) . " résultats");
            }
            
            // Stratégie 3: Recherche par mots-clés
            if (count($allCompetitors) < 3) {
                $this->logSearch($sku, "Stratégie 3: Recherche par mots-clés");
                $keywordCompetitors = $this->findCompetitorsWithKeywords($cleanedProductName, $cleanPrice);
                $allCompetitors = array_merge($allCompetitors, $keywordCompetitors);
                $this->logSearch($sku, "Mots-clés trouvé: " . count($keywordCompetitors) . " résultats");
            }
            
            // Dédupliquer
            $uniqueCompetitors = $this->deduplicateCompetitors($allCompetitors);
            $this->logSearch($sku, "Après déduplication: " . count($uniqueCompetitors) . " résultats uniques");
            
            if (!empty($uniqueCompetitors)) {
                // Trier par similarité
                usort($uniqueCompetitors, function($a, $b) {
                    return ($b->similarity_score ?? 0) <=> ($a->similarity_score ?? 0);
                });
                
                $goodResults = array_filter($uniqueCompetitors, fn($c) => ($c->similarity_score ?? 0) >= 0.6);
                
                $this->competitorResults[$sku] = [
                    'product_name' => $cleanedProductName,
                    'our_price' => $cleanPrice,
                    'competitors' => $uniqueCompetitors,
                    'count' => count($uniqueCompetitors),
                    'good_count' => count($goodResults),
                    'search_logs' => $this->getSearchLogs($sku)
                ];
                
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
                    'good_count' => 0,
                    'search_logs' => $this->getSearchLogs($sku)
                ];
                
                // NOUVEAU: Essayer une recherche directe en base
                $this->logSearch($sku, "Aucun résultat, tentative recherche directe");
                $directSearch = $this->directDatabaseSearch($cleanedProductName);
                if (!empty($directSearch)) {
                    $this->competitorResults[$sku]['competitors'] = $directSearch;
                    $this->competitorResults[$sku]['count'] = count($directSearch);
                    $this->logSearch($sku, "Recherche directe trouvé: " . count($directSearch) . " résultats");
                }
            }

        } catch (\Exception $e) {
            $this->logSearch($sku, "ERREUR: " . $e->getMessage());
            $this->competitorResults[$sku] = [
                'product_name' => $productName,
                'our_price' => $this->cleanPrice($price),
                'competitors' => [],
                'count' => 0,
                'good_count' => 0,
                'error' => $e->getMessage(),
                'search_logs' => $this->getSearchLogs($sku)
            ];
        } finally {
            unset($this->searchingProducts[$sku]);
        }
    }

    /**
     * NOUVEAU: Logging des recherches
     */
    protected function logSearch(string $sku, string $message): void
    {
        if (!isset($this->searchLogs[$sku])) {
            $this->searchLogs[$sku] = [];
        }
        
        $this->searchLogs[$sku][] = [
            'time' => now()->format('H:i:s'),
            'message' => $message
        ];
        
        // Garder seulement les 20 derniers logs
        if (count($this->searchLogs[$sku]) > 20) {
            array_shift($this->searchLogs[$sku]);
        }
    }

    /**
     * NOUVEAU: Récupérer les logs d'une recherche
     */
    protected function getSearchLogs(string $sku): array
    {
        return $this->searchLogs[$sku] ?? [];
    }

    /**
     * NOUVEAU: Recherche manuelle (fallback)
     */
    protected function findCompetitorsWithManualSearch(string $search, float $ourPrice): array
    {
        $competitors = [];
        
        try {
            // Analyser le produit manuellement
            $analysis = $this->analyzeProductManually($search);
            
            // Stratégies multiples
            $strategies = [
                ['type' => 'vendor_name', 'terms' => $analysis['vendor'] . ' ' . $analysis['key_name']],
                ['type' => 'key_name', 'terms' => $analysis['key_name']],
                ['type' => 'type', 'terms' => $analysis['type']],
                ['type' => 'variation', 'terms' => $analysis['variation']],
            ];
            
            foreach ($strategies as $strategy) {
                if (!empty($strategy['terms'])) {
                    $sql = $this->buildFlexibleSearchSQL($strategy['terms']);
                    $results = DB::connection('mysql')->select($sql);
                    
                    foreach ($results as $result) {
                        $result->prix_ht = $this->cleanPrice($result->prix_ht ?? 0);
                        $result->image = $this->getCompetitorImage($result);
                        $result->similarity_score = $this->calculateManualSimilarity($result, $search);
                        $result->match_level = $this->getMatchLevel($result->similarity_score);
                        $result->price_assessment = $this->assessPrice($result->prix_ht, $ourPrice);
                        $result->search_strategy = $strategy['type'];
                        
                        $competitors[] = $result;
                    }
                }
            }
            
        } catch (\Exception $e) {
            \Log::warning('Manual search failed: ' . $e->getMessage());
        }
        
        return $competitors;
    }

    /**
     * NOUVEAU: Recherche par mots-clés
     */
    protected function findCompetitorsWithKeywords(string $search, float $ourPrice): array
    {
        $competitors = [];
        
        try {
            // Extraire les mots-clés importants
            $keywords = $this->extractImportantKeywords($search);
            
            if (empty($keywords)) {
                return [];
            }
            
            // Construire la requête SQL
            $keywordString = implode(' ', $keywords);
            $sql = $this->buildKeywordSearchSQL($keywordString);
            
            $results = DB::connection('mysql')->select($sql);
            
            foreach ($results as $result) {
                $result->prix_ht = $this->cleanPrice($result->prix_ht ?? 0);
                $result->image = $this->getCompetitorImage($result);
                $result->similarity_score = $this->calculateManualSimilarity($result, $search);
                $result->match_level = $this->getMatchLevel($result->similarity_score);
                $result->price_assessment = $this->assessPrice($result->prix_ht, $ourPrice);
                $result->search_strategy = 'keywords';
                
                $competitors[] = $result;
            }
            
        } catch (\Exception $e) {
            \Log::warning('Keyword search failed: ' . $e->getMessage());
        }
        
        return $competitors;
    }

    /**
     * NOUVEAU: Recherche directe en base (méthode de dernier recours)
     */
    protected function directDatabaseSearch(string $search): array
    {
        $competitors = [];
        
        try {
            // Nettoyer le texte de recherche
            $cleanSearch = $this->normalizeAndCleanText($search);
            
            // Recherche LIKE simple sur plusieurs champs
            $sql = "
                SELECT 
                    lp.*,
                    ws.name as site_name,
                    lp.image_url as image_url,
                    lp.url as product_url
                FROM last_price_scraped_product lp
                LEFT JOIN web_site ws ON lp.web_site_id = ws.id
                WHERE (
                    lp.name LIKE ? OR
                    lp.vendor LIKE ? OR
                    lp.type LIKE ? OR
                    lp.variation LIKE ?
                )
                AND lp.prix_ht > 0
                LIMIT 20
            ";
            
            $searchPattern = '%' . $cleanSearch . '%';
            $results = DB::connection('mysql')->select($sql, [
                $searchPattern, $searchPattern, $searchPattern, $searchPattern
            ]);
            
            foreach ($results as $result) {
                $result->prix_ht = $this->cleanPrice($result->prix_ht ?? 0);
                $result->image = $this->getCompetitorImage($result);
                $result->similarity_score = $this->calculateManualSimilarity($result, $search);
                $result->match_level = $this->getMatchLevel($result->similarity_score);
                $result->search_strategy = 'direct_like';
                
                $competitors[] = $result;
            }
            
        } catch (\Exception $e) {
            \Log::warning('Direct database search failed: ' . $e->getMessage());
        }
        
        return $competitors;
    }

    /**
     * NOUVEAU: Extraire les mots-clés importants
     */
    protected function extractImportantKeywords(string $text): array
    {
        $text = $this->normalizeAndCleanText($text);
        $words = explode(' ', $text);
        $keywords = [];
        
        // Liste de stop words étendue
        $stopWords = array_merge(
            $this->getGeneralStopWords(),
            $this->getProductStopWords(),
            ['edition', 'limited', 'limitée', 'spray', 'vaporisateur', 'ml', 'g']
        );
        
        foreach ($words as $word) {
            $word = trim($word);
            
            // Ignorer les mots courts, les nombres, et les stop words
            if (strlen($word) <= 2 || is_numeric($word) || in_array(strtolower($word), $stopWords)) {
                continue;
            }
            
            // Conserver les mots qui semblent être des noms de produits
            if (preg_match('/^[A-Z][a-z]+$/', $word) || preg_match('/^[A-Z]+$/', $word)) {
                $keywords[] = $word;
            } elseif (preg_match('/^[a-z]{3,}$/', $word)) {
                // Vérifier si c'est un mot significatif
                $keywords[] = $word;
            }
        }
        
        return array_slice(array_unique($keywords), 0, 5);
    }

    /**
     * NOUVEAU: Construire une requête SQL flexible
     */
    protected function buildFlexibleSearchSQL(string $searchTerms): string
    {
        $searchTerms = trim($searchTerms);
        if (empty($searchTerms)) {
            return "SELECT NULL LIMIT 0";
        }
        
        // Séparer les termes
        $terms = explode(' ', $searchTerms);
        $likeConditions = [];
        
        foreach ($terms as $term) {
            if (strlen($term) > 2) {
                $likeConditions[] = "(lp.name LIKE '%" . addslashes($term) . "%' OR 
                                     lp.vendor LIKE '%" . addslashes($term) . "%' OR 
                                     lp.type LIKE '%" . addslashes($term) . "%')";
            }
        }
        
        if (empty($likeConditions)) {
            return "SELECT NULL LIMIT 0";
        }
        
        $whereClause = implode(' AND ', $likeConditions);
        
        return "
            SELECT 
                lp.*,
                ws.name as site_name,
                lp.image_url as image_url,
                lp.url as product_url
            FROM last_price_scraped_product lp
            LEFT JOIN web_site ws ON lp.web_site_id = ws.id
            WHERE {$whereClause}
            AND lp.prix_ht > 0
            LIMIT 30
        ";
    }

    /**
     * NOUVEAU: Construire une requête SQL par mots-clés
     */
    protected function buildKeywordSearchSQL(string $keywords): string
    {
        $keywords = trim($keywords);
        if (empty($keywords)) {
            return "SELECT NULL LIMIT 0";
        }
        
        // Préparer pour FULLTEXT
        $keywordArray = explode(' ', $keywords);
        $ftKeywords = [];
        
        foreach ($keywordArray as $keyword) {
            if (strlen($keyword) > 2) {
                $ftKeywords[] = '+' . $keyword . '*';
            }
        }
        
        $ftString = implode(' ', array_slice($ftKeywords, 0, 3));
        
        return "
            SELECT 
                lp.*,
                ws.name as site_name,
                lp.image_url as image_url,
                lp.url as product_url
            FROM last_price_scraped_product lp
            LEFT JOIN web_site ws ON lp.web_site_id = ws.id
            WHERE MATCH (lp.name, lp.vendor, lp.type, lp.variation) 
                AGAINST ('" . addslashes($ftString) . "' IN BOOLEAN MODE)
            AND lp.prix_ht > 0
            LIMIT 30
        ";
    }

    /**
     * Recherche de concurrents avec OpenAI
     */
    protected function findCompetitorsWithOpenAI(string $search, float $ourPrice): array
    {
        try {
            $cacheKey = 'openai_competitors_' . md5($search . '_' . $ourPrice);
            $cached = Cache::get($cacheKey);
            
            if ($cached !== null) {
                return $cached;
            }
            
            if (!$this->useOpenAI) {
                return [];
            }
            
            // 1. Analyser le produit avec OpenAI
            $analysis = $this->analyzeProductWithOpenAI($search);
            
            if (empty($analysis)) {
                throw new \Exception('OpenAI analysis failed');
            }
            
            // 2. Générer des requêtes de recherche
            $searchQueries = $this->generateSearchQueriesFromAnalysis($analysis, $search);
            
            // 3. Rechercher dans la base de données
            $allCompetitors = [];
            foreach ($searchQueries as $query) {
                $competitors = $this->executeSearchQuery($query);
                $allCompetitors = array_merge($allCompetitors, $competitors);
            }
            
            // 4. Noter la similarité
            if (!empty($allCompetitors)) {
                $scoredCompetitors = $this->scoreCompetitorsWithOpenAI($allCompetitors, $search, $ourPrice);
                $filteredCompetitors = $this->filterAndSortCompetitors($scoredCompetitors, $ourPrice);
                $competitorsWithComparison = $this->addPriceComparisons($filteredCompetitors, $ourPrice);
                
                Cache::put($cacheKey, $competitorsWithComparison, now()->addHours(1));
                
                return $competitorsWithComparison;
            }
            
            return [];
            
        } catch (\Exception $e) {
            \Log::error('OpenAI search failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Analyser le produit avec OpenAI
     */
    protected function analyzeProductWithOpenAI(string $productName): array
    {
        try {
            $cacheKey = 'openai_analysis_' . md5($productName);
            $cached = Cache::get($cacheKey);
            
            if ($cached !== null) {
                return $cached;
            }
            
            // Prompt amélioré pour mieux extraire les informations
            $response = OpenAI::chat()->create([
                'model' => $this->openAIModel,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => "Tu es un expert en produits cosmétiques et parfums. Analyse le nom du produit et extrais :
                        1. vendor (marque) - ex: Guerlain, Chanel
                        2. key_name (nom principal du produit sans la marque) - ex: Rouge G La recharge, Coco Mademoiselle
                        3. type (type de produit) - ex: rouge à lèvres, eau de parfum, crème
                        4. variation (spécifications) - ex: 50ml, Edition Limitée, LE BRUN AMARANTE
                        
                        Important: Si le vendor n'est pas clair, laisse vide. Le key_name doit être le nom du produit sans la marque ni les spécifications techniques.
                        Réponds uniquement au format JSON."
                    ],
                    [
                        'role' => 'user',
                        'content' => "Produit: \"{$productName}\""
                    ]
                ],
                'temperature' => 0.1,
                'max_tokens' => 300
            ]);
            
            $content = $response->choices[0]->message->content;
            
            // Nettoyer le JSON
            $content = trim($content);
            $content = preg_replace('/^```json\s*/', '', $content);
            $content = preg_replace('/\s*```$/', '', $content);
            
            $analysis = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                \Log::warning('Invalid JSON from OpenAI: ' . substr($content, 0, 200));
                return $this->analyzeProductManually($productName);
            }
            
            // Nettoyer les valeurs
            foreach ($analysis as $key => $value) {
                $analysis[$key] = is_string($value) ? $this->normalizeAndCleanText($value) : $value;
            }
            
            Cache::put($cacheKey, $analysis, now()->addHours(12));
            
            return $analysis;
            
        } catch (\Exception $e) {
            \Log::error('OpenAI analysis failed: ' . $e->getMessage());
            return $this->analyzeProductManually($productName);
        }
    }

    /**
     * Analyser le produit manuellement
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
        
        // Détecter le vendor (marque)
        $commonBrands = ['Guerlain', 'Chanel', 'Dior', 'Yves Saint Laurent', 'Lancôme', 
                        'Estée Lauder', 'Clarins', 'Shiseido', 'La Roche-Posay', 
                        'Vichy', 'Nuxe', 'Caudalie', 'Sephora', 'Nocibé'];
        
        foreach ($commonBrands as $brand) {
            if (stripos($productName, $brand) !== false) {
                $analysis['vendor'] = $brand;
                break;
            }
        }
        
        // Si pas de vendor détecté, essayer par pattern
        if (empty($analysis['vendor']) && preg_match('/^([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)\s*[-–]/u', $productName, $matches)) {
            $analysis['vendor'] = trim($matches[1]);
        }
        
        // Extraire le nom clé
        if (!empty($analysis['vendor'])) {
            $pattern = '/^' . preg_quote($analysis['vendor'], '/') . '\s*[-–]\s*(.+)$/iu';
            if (preg_match($pattern, $productName, $matches)) {
                $analysis['key_name'] = trim($matches[1]);
            }
        }
        
        if (empty($analysis['key_name'])) {
            $analysis['key_name'] = $productName;
        }
        
        // Détecter le type
        $typePatterns = [
            'eau de parfum' => '/eau\s+de\s+parfum|edp/i',
            'eau de toilette' => '/eau\s+de\s+toilette|edt/i',
            'parfum' => '/parfum(?!\w)/i',
            'rouge à lèvres' => '/rouge\s+(?:à|a)\s+l[eèè]vres|lipstick/i',
            'crème' => '/cr[eèè]me|cream/i',
            'lotion' => '/lotion/i',
            'gel' => '/gel/i',
            'sérum' => '/s[eé]rum|serum/i',
            'masque' => '/masque|mask/i',
            'shampooing' => '/shampooing|shampoo/i',
            'mascara' => '/mascara/i',
            'fond de teint' => '/fond\s+de\s+teint|foundation/i'
        ];
        
        foreach ($typePatterns as $type => $pattern) {
            if (preg_match($pattern, $productName)) {
                $analysis['type'] = $type;
                break;
            }
        }
        
        // Détecter la variation
        if (preg_match('/(\d+\s*(?:ml|cl|l|g|kg|fl\s*oz)|rouge|noir|blanc|rose|brun|marron|[A-Z]+\s+\d+)/i', $productName, $matches)) {
            $analysis['variation'] = trim($matches[1]);
        }
        
        // Nettoyer le key_name (enlever la variation si présente)
        if (!empty($analysis['variation']) && !empty($analysis['key_name'])) {
            $analysis['key_name'] = trim(str_replace($analysis['variation'], '', $analysis['key_name']));
        }
        
        return $analysis;
    }

    /**
     * Dédupliquer les concurrents
     */
    protected function deduplicateCompetitors(array $competitors): array
    {
        $unique = [];
        $seen = [];
        
        foreach ($competitors as $competitor) {
            $name = mb_strtolower(trim($competitor->name ?? ''));
            $vendor = mb_strtolower(trim($competitor->vendor ?? ''));
            $variation = mb_strtolower(trim($competitor->variation ?? ''));
            $price = $this->cleanPrice($competitor->prix_ht ?? 0);
            
            // Créer une signature plus simple
            $signature = md5($name . '|' . $vendor . '|' . $variation);
            
            if (!in_array($signature, $seen)) {
                $seen[] = $signature;
                $unique[] = $competitor;
            }
        }
        
        return $unique;
    }

    // ... [Le reste des méthodes reste similaire, gardez votre code existant]

    /**
     * NOUVEAU: Méthode de test pour déboguer la recherche
     */
    public function debugSearch(string $productName): void
    {
        try {
            $cleanedProductName = $this->normalizeAndCleanText($productName);
            
            $debugInfo = [
                'original' => $productName,
                'cleaned' => $cleanedProductName,
                'openai_analysis' => null,
                'manual_analysis' => null,
                'search_results' => [],
                'direct_search' => []
            ];
            
            // Analyse OpenAI
            if ($this->useOpenAI) {
                $debugInfo['openai_analysis'] = $this->analyzeProductWithOpenAI($cleanedProductName);
            }
            
            // Analyse manuelle
            $debugInfo['manual_analysis'] = $this->analyzeProductManually($cleanedProductName);
            
            // Recherche avec OpenAI
            if ($this->useOpenAI && !empty($debugInfo['openai_analysis'])) {
                $searchQueries = $this->generateSearchQueriesFromAnalysis(
                    $debugInfo['openai_analysis'], 
                    $cleanedProductName
                );
                
                foreach ($searchQueries as $query) {
                    $results = $this->executeSearchQuery($query);
                    $debugInfo['search_results'][] = [
                        'query_type' => $query['type'],
                        'sql' => $query['sql'],
                        'results' => count($results)
                    ];
                }
            }
            
            // Recherche directe
            $directResults = $this->directDatabaseSearch($cleanedProductName);
            $debugInfo['direct_search'] = [
                'count' => count($directResults),
                'sample' => array_slice($directResults, 0, 3)
            ];
            
            dd($debugInfo);
            
        } catch (\Exception $e) {
            dd('Debug error: ' . $e->getMessage());
        }
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