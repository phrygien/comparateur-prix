<?php

namespace App\Livewire;

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Models\Site as WebSite;
use Illuminate\Support\Str;

new class extends Component {
    public $products = [];
    public $hasData = false;
    public $searchTerms = [];
    public $searchVolumes = [];
    public $searchVariationKeywords = [];
    public $id;
    public $mydata;
    public $similarityThreshold = 0.6;
    public $matchedProducts = [];
    public $searchQuery = '';
    public $price;
    public $referencePrice;
    public $cosmashopPrice;
    public $filters = [
        'vendor' => '',
        'name' => '',
        'variation' => '',
        'type' => '',
        //'site_source' => ''
        'site_source' => [] // Changé de '' à []
    ];
    public $sites = [];
    public $showTable = false;
    public $isAutomaticSearch = true;
    public $originalAutomaticResults = [];
    public $hasAppliedFilters = false;

    // Cache des vendors
    private array $knownVendors = [];
    private bool $vendorsLoaded = false;
    private const CACHE_TTL = 60;

    public function mount($name, $id, $price)
    {
        $this->id = $id;
        $this->price = $this->cleanPrice($price);
        $this->referencePrice = $this->cleanPrice($price);
        $this->cosmashopPrice = $this->cleanPrice($price) * 1.05;
        $this->searchQuery = $name;

        // Extraire le vendor par défaut (méthode améliorée)
        $this->extractDefaultVendor($name);

        // Charger les sites
        $this->loadSites();

        // Toujours afficher le tableau
        $this->showTable = true;
        
        // Recherche automatique avec la même logique que la recherche manuelle
        $this->getCompetitorPrice($name);
    }

    /**
     * Charger tous les vendors depuis la base de données
     */
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

    /**
     * Extrait le vendor de manière intelligente
     */
    private function extractDefaultVendor(string $search): void
    {
        $this->loadVendorsFromDatabase();
        
        $vendor = '';
        $searchLower = mb_strtolower(trim($search));
        
        // Extraire la première partie (avant le premier tiret)
        $parts = preg_split('/\s*-\s*/', $search, 2);
        $firstPart = trim($parts[0]);
        
        // Mots-clés qui indiquent qu'on n'est plus dans le vendor
        $productKeywords = [
            'eau de', 'edp', 'edt', 'parfum', 'coffret', 'spray', 'ml',
            'vapo', 'vaporisateur', 'intense', 'pour homme', 'pour femme'
        ];
        
        // Vérifier si la première partie contient un mot-clé produit
        $hasProductKeyword = false;
        foreach ($productKeywords as $keyword) {
            if (stripos(mb_strtolower($firstPart), $keyword) !== false) {
                $hasProductKeyword = true;
                break;
            }
        }
        
        // Si pas de mot-clé produit, considérer comme vendor potentiel
        if (!$hasProductKeyword) {
            $cleanFirstPart = preg_replace('/\d+\s*ml/i', '', $firstPart);
            $cleanFirstPart = preg_replace('/[0-9]+/', '', $cleanFirstPart);
            $cleanFirstPart = trim($cleanFirstPart);
            
            $vendor = $this->findMatchingVendor($cleanFirstPart);
            
            // SI ON A TROUVÉ UN VENDOR, ON PEUT EXTRAIRE LE PREMIER MOT DU PRODUIT
            if (!empty($vendor) && isset($parts[1])) {
                // Extraire le premier mot du produit pour référence
                $firstProductWord = $this->extractFirstProductWord($search, $vendor);
                \Log::info('Extracted vendor and first product word in mount:', [
                    'vendor' => $vendor,
                    'first_product_word' => $firstProductWord
                ]);
            }
        }
        
        // Si toujours pas trouvé, faire une recherche plus large
        if (empty($vendor)) {
            $vendor = $this->guessVendorFromSearch($search);
        }
        
        // Définir le vendor comme filtre par défaut
        if (!empty($vendor)) {
            $this->filters['vendor'] = $vendor;
        }
    }

    /**
     * Trouve le vendor correspondant
     */
    private function findMatchingVendor(string $searchVendor): string
    {
        if (empty($searchVendor) || empty($this->knownVendors)) {
            return '';
        }
        
        $searchVendorLower = mb_strtolower(trim($searchVendor));
        $bestMatch = '';
        $bestScore = 0;
        
        foreach ($this->knownVendors as $knownVendor) {
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
     * Devine le vendor à partir de la recherche complète
     */
    private function guessVendorFromSearch(string $search): string
    {
        $this->loadVendorsFromDatabase();
        
        if (empty($this->knownVendors)) {
            return '';
        }
        
        $searchLower = mb_strtolower($search);
        $scores = [];
        
        foreach ($this->knownVendors as $vendor) {
            $vendorLower = mb_strtolower($vendor);
            $position = mb_stripos($searchLower, $vendorLower);
            
            if ($position !== false) {
                $score = 100 - ($position * 5);
                
                if ($position === 0) {
                    $score += 100;
                }
                
                // Bonus pour mot complet
                $before = $position > 0 ? mb_substr($searchLower, $position - 1, 1) : ' ';
                $after = $position + mb_strlen($vendorLower) < mb_strlen($searchLower) 
                    ? mb_substr($searchLower, $position + mb_strlen($vendorLower), 1) 
                    : ' ';
                
                if (in_array($before, [' ', '-', '(', '[']) && in_array($after, [' ', '-', ')', ']', ','])) {
                    $score += 50;
                }
                
                // Bonus pour la longueur
                $score += mb_strlen($vendor) * 2;
                
                $scores[$vendor] = max(0, $score);
            }
        }
        
        if (!empty($scores)) {
            arsort($scores);
            $bestVendor = array_key_first($scores);
            $bestScore = $scores[$bestVendor];
            
            return $bestScore >= 50 ? $bestVendor : '';
        }
        
        return '';
    }

    /**
     * Extrait le premier mot du nom du produit (après le vendor)
     * Exemple: "Lancôme - Idole Peach'n Roses - Eau de Parfum 50 ml" → "Idole"
     */
    private function extractFirstProductWord(string $search, ?string $vendor = null): string
    {
        // Nettoyer la recherche
        $search = trim($search);
        
        // Supprimer le vendor si présent
        if (!empty($vendor)) {
            // Essayer de supprimer le vendor au début de la chaîne
            $pattern = '/^' . preg_quote($vendor, '/') . '\s*-\s*/i';
            $searchWithoutVendor = preg_replace($pattern, '', $search);
            
            // Si la suppression a fonctionné, utiliser cette chaîne
            if ($searchWithoutVendor !== $search) {
                $search = $searchWithoutVendor;
            } else {
                // Sinon, essayer de supprimer le vendor n'importe où
                $search = str_ireplace($vendor, '', $search);
            }
        }
        
        // Supprimer les préfixes communs
        $search = preg_replace('/^\s*-\s*/', '', $search);
        
        // Extraire les parties séparées par des tirets
        $parts = preg_split('/\s*-\s*/', $search, 3);
        
        // Le premier mot après le vendor est généralement dans la première partie
        // (ou la deuxième si on a bien supprimé le vendor)
        $potentialPart = $parts[0] ?? '';
        
        // Si la partie contient des mots clés de produit, passer à la suivante
        $productKeywords = [
            'eau de parfum', 'eau de toilette', 'parfum', 'edp', 'edt',
            'coffret', 'spray', 'ml', 'pour homme', 'pour femme'
        ];
        
        $hasProductKeyword = false;
        foreach ($productKeywords as $keyword) {
            if (stripos($potentialPart, $keyword) !== false) {
                $hasProductKeyword = true;
                break;
            }
        }
        
        if ($hasProductKeyword && isset($parts[1])) {
            $potentialPart = $parts[1];
        }
        
        // Extraire le premier mot
        $words = preg_split('/\s+/', $potentialPart, 2);
        $firstWord = $words[0] ?? '';
        
        // Nettoyer le mot (supprimer la ponctuation)
        $firstWord = preg_replace('/[^a-zA-ZÀ-ÿ0-9]/', '', $firstWord);
        
        // Vérifier que ce n'est pas un mot vide ou un mot clé
        $stopWords = ['le', 'la', 'les', 'de', 'des', 'du', 'et', 'pour', 'avec'];
        if (strlen($firstWord) > 2 && !in_array(strtolower($firstWord), $stopWords)) {
            return $firstWord;
        }
        
        // Si pas trouvé, essayer d'extraire un mot significatif de toute la chaîne
        $allWords = preg_split('/\s+/', $search);
        foreach ($allWords as $word) {
            $cleanWord = preg_replace('/[^a-zA-ZÀ-ÿ0-9]/', '', $word);
            if (strlen($cleanWord) > 2 && !in_array(strtolower($cleanWord), $stopWords)) {
                // Vérifier que ce n'est pas un volume ou un mot clé technique
                if (!is_numeric($cleanWord) && 
                    !preg_match('/\d+ml/i', $cleanWord) && 
                    !in_array(strtolower($cleanWord), ['ml', 'edp', 'edt', 'eau'])) {
                    return $cleanWord;
                }
            }
        }
        
        return '';
    }

    /**
     * Extrait le premier mot d'une chaîne
     */
    private function extractFirstWordFromString(string $text): string
    {
        $text = trim($text);
        $words = preg_split('/\s+/', $text, 2);
        $firstWord = $words[0] ?? '';
        
        // Nettoyer la ponctuation
        $firstWord = preg_replace('/[^a-zA-ZÀ-ÿ0-9]/', '', $firstWord);
        
        return $firstWord;
    }

    /**
     * Extrait les composants de la recherche AMÉLIORÉ
     */
    private function extractSearchComponents(string $search): array
    {
        $components = [
            'vendor' => '',
            'product_name' => '',
            'first_product_word' => '',
            'variation' => '',
            'volumes' => [],
            'type' => ''
        ];
        
        // Extraire les volumes
        if (preg_match_all('/(\d+)\s*ml/i', $search, $matches)) {
            $components['volumes'] = $matches[1];
        }
        
        // Extraire le vendor
        $vendor = $this->filters['vendor'] ?? $this->guessVendorFromSearch($search);
        $components['vendor'] = $vendor;
        
        // Extraire le premier mot du produit
        $components['first_product_word'] = $this->extractFirstProductWord($search, $vendor);
        
        // Nettoyer la recherche pour extraire le nom du produit
        $remainingSearch = $search;
        
        if (!empty($vendor)) {
            // Supprimer le vendor
            $pattern = '/^' . preg_quote($vendor, '/') . '\s*-\s*/i';
            $remainingSearch = preg_replace($pattern, '', $remainingSearch);
            
            // Si ça n'a pas fonctionné, essayer autrement
            if ($remainingSearch === $search) {
                $remainingSearch = str_ireplace($vendor, '', $remainingSearch);
                $remainingSearch = preg_replace('/^\s*-\s*/', '', $remainingSearch);
            }
        }
        
        // Supprimer les parties technique (type, volume, etc.)
        $technicalParts = [
            'eau de parfum', 'eau de toilette', 'eau fraiche', 'eau de cologne',
            'edp', 'edt', 'edc', 'parfum', 'cologne', 'intense', 'absolu',
            'coffret', 'spray', 'vapo', 'vaporisateur', 'pour homme', 'pour femme'
        ];
        
        foreach ($technicalParts as $part) {
            $remainingSearch = str_ireplace($part, '', $remainingSearch);
        }
        
        // Supprimer les volumes
        $remainingSearch = preg_replace('/\d+\s*ml/i', '', $remainingSearch);
        
        // Nettoyer
        $remainingSearch = trim(preg_replace('/\s+/', ' ', $remainingSearch));
        $remainingSearch = preg_replace('/^\s*-\s*|\s*-\s*$/i', '', $remainingSearch);
        
        $components['product_name'] = $remainingSearch;
        
        // Extraire le type
        foreach ($technicalParts as $type) {
            if (stripos($search, $type) !== false) {
                $components['type'] = $type;
                break;
            }
        }
        
        return $components;
    }

    /**
     * Prépare les termes de recherche POUR LA RECHERCHE AUTOMATIQUE
     */
    private function prepareAutomaticSearchTerms(string $search): string
    {
        // Nettoyer la recherche
        $searchClean = preg_replace('/[^a-zA-ZÀ-ÿ\s]/', ' ', $search);
        $searchClean = trim(preg_replace('/\s+/', ' ', $searchClean));
        $searchClean = mb_strtolower($searchClean);
        
        // Extraire les mots significatifs
        $words = explode(' ', $searchClean);
        $significantWords = [];
        $stopWords = [
            'de', 'le', 'la', 'les', 'un', 'une', 'des', 'du', 'et', 'ou',
            'pour', 'avec', 'the', 'a', 'an', 'and', 'or', 'eau', 'ml',
            'edition', 'édition', 'coffret', 'spray', 'vapo', 'vaporisateur'
        ];
        
        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) > 2 && !in_array($word, $stopWords)) {
                $significantWords[] = '+' . $word . '*';
            }
        }
        
        // Si peu de mots significatifs, utiliser une recherche plus permissive
        if (count($significantWords) < 2) {
            // Recherche moins restrictive
            return implode(' ', array_map(function($word) {
                $cleanWord = trim($word);
                if (strlen($cleanWord) > 2) {
                    return '+' . $cleanWord . '*';
                }
                return '';
            }, array_filter($words, function($word) use ($stopWords) {
                return !in_array($word, $stopWords) && strlen($word) > 1;
            })));
        }
        
        // Limiter à 5 mots maximum pour FULLTEXT
        $significantWords = array_slice($significantWords, 0, 5);
        
        return implode(' ', $significantWords);
    }

    /**
     * Récupère toutes les variations d'une marque
     */
    private function getVendorVariations(string $vendor): array
    {
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
        
        // Chercher des variations similaires
        $this->loadVendorsFromDatabase();
        $vendorLower = mb_strtolower($vendor);
        
        foreach ($this->knownVendors as $knownVendor) {
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
     * Gestion du cache
     */
    private function getCacheKey(string $search, array $filters = [], bool $isManual = false): string
    {
        $keyData = [
            'search' => trim(strtolower($search)),
            'filters' => $filters,
            'type' => $isManual ? 'manual' : 'automatic',
            'threshold' => $this->similarityThreshold
        ];
        return 'search_results:' . md5(serialize($keyData));
    }

    private function getManualSearchCacheKey(): string
    {
        $cacheData = [
            'filters' => $this->filters,
            'vendor_variations' => !empty($this->filters['vendor']) ? $this->getVendorVariations($this->filters['vendor']) : [],
            'threshold' => $this->similarityThreshold
        ];
        return 'manual_search:' . md5(serialize($cacheData));
    }

    private function getCachedResults(string $cacheKey)
    {
        return Cache::get($cacheKey);
    }

    private function cacheResults(string $cacheKey, $results): void
    {
        Cache::put($cacheKey, $results, now()->addMinutes(self::CACHE_TTL));
    }

    private function forgetCache(string $cacheKey): void
    {
        Cache::forget($cacheKey);
    }

    /**
     * Charger la liste des sites
     */
    public function loadSites()
    {
        try {
            $cacheKey = 'sites_list';
            $cachedSites = Cache::get($cacheKey);

            if ($cachedSites !== null) {
                $this->sites = $cachedSites;
                return;
            }

            $sites = WebSite::orderBy('name')->get();
            $this->sites = $sites;
            Cache::put($cacheKey, $sites, now()->addHours(24));

        } catch (\Throwable $e) {
            \Log::error('Error loading sites:', ['message' => $e->getMessage()]);
            $this->sites = [];
        }
    }

    /**
     * Recherche manuelle
     */
public function searchManual()
{
    try {
        $this->hasData = false;
        $this->matchedProducts = [];
        $this->products = [];

        $cacheKey = $this->getManualSearchCacheKey();
        $cachedResults = $this->getCachedResults($cacheKey);

        if ($cachedResults !== null) {
            $this->products = $cachedResults;
            $this->matchedProducts = $cachedResults;
            $this->hasData = !empty($cachedResults);
            $this->isAutomaticSearch = false;
            $this->hasAppliedFilters = true;
            return;
        }

        // Construire les conditions de filtre
        $vendorConditions = [];
        $vendorParams = [];
        
        if (!empty($this->filters['vendor'])) {
            $vendorVariations = $this->getVendorVariations($this->filters['vendor']);
            
            foreach ($vendorVariations as $variation) {
                $vendorConditions[] = "t.vendor LIKE ?";
                $vendorParams[] = '%' . $variation . '%';
            }
        }

        // Requête optimisée avec ROW_NUMBER
        $sql = "SELECT 
                    t.*,
                    ws.name as site_name,
                    t.url as product_url,
                    t.image_url as image
                FROM (
                    SELECT
                        sp.*,
                        ROW_NUMBER() OVER (
                            PARTITION BY sp.url, sp.vendor, sp.name, sp.type, sp.variation
                            ORDER BY sp.created_at DESC
                        ) AS row_num
                    FROM scraped_product sp
                ) AS t
                LEFT JOIN web_site ws ON t.web_site_id = ws.id
                WHERE t.row_num = 1
                AND (t.variation != 'Standard' OR t.variation IS NULL OR t.variation = '')";
        
        $params = [];

        // Appliquer les filtres
        if (!empty($vendorConditions)) {
            $sql .= " AND (" . implode(' OR ', $vendorConditions) . ")";
            $params = array_merge($params, $vendorParams);
        }

        if (!empty($this->filters['name'])) {
            $sql .= " AND t.name LIKE ?";
            $params[] = '%' . $this->filters['name'] . '%';
        }

        if (!empty($this->filters['variation'])) {
            $sql .= " AND t.variation LIKE ?";
            $params[] = '%' . $this->filters['variation'] . '%';
        }

        if (!empty($this->filters['type'])) {
            $sql .= " AND t.type LIKE ?";
            $params[] = '%' . $this->filters['type'] . '%';
        }

        // FILTRE MULTI-SELECT POUR SITE_SOURCE
        if (!empty($this->filters['site_source']) && is_array($this->filters['site_source'])) {
            $placeholders = implode(',', array_fill(0, count($this->filters['site_source']), '?'));
            $sql .= " AND t.web_site_id IN (" . $placeholders . ")";
            $params = array_merge($params, $this->filters['site_source']);
        }

        $sql .= " ORDER BY t.vendor ASC, t.prix_ht DESC LIMIT 100";

        $result = DB::connection('mysql')->select($sql, $params);

        // Traiter les résultats
        $processedResults = [];
        foreach ($result as $product) {
            if (isset($product->prix_ht)) {
                $product->prix_ht = $this->cleanPrice($product->prix_ht);
            }

            $product->product_url = $product->product_url ?? $product->url ?? null;
            $product->image = $product->image ?? $product->image_url ?? null;
            $product->similarity_score = null;
            $product->match_level = null;
            $product->is_manual_search = true;

            $processedResults[] = $product;
        }

        $this->products = $processedResults;
        $this->matchedProducts = $processedResults;
        $this->hasData = !empty($processedResults);
        $this->isAutomaticSearch = false;
        $this->hasAppliedFilters = true;

        $this->cacheResults($cacheKey, $processedResults);

    } catch (\Throwable $e) {
        \Log::error('Error in manual search:', ['message' => $e->getMessage()]);
        $this->products = [];
        $this->hasData = false;
    }
}

    /**
     * Recherche automatique AVEC LA MÊME LOGIQUE QUE LA RECHERCHE MANUELLE
     */
public function getCompetitorPrice($search)
{
    try {
        if (empty($search)) {
            return $this->resetSearchState();
        }

        $cacheKey = $this->getCacheKey($search, [], false);
        $cachedData = $this->getCachedResults($cacheKey);

        if ($cachedData !== null) {
            $this->matchedProducts = $cachedData['products'];
            $this->products = $cachedData['products'];
            $this->originalAutomaticResults = $cachedData['products'];
            $this->hasAppliedFilters = false;
            $this->hasData = !empty($cachedData['products']);
            $this->isAutomaticSearch = true;
            $this->showTable = true;

            return $cachedData['full_result'];
        }

        // Log pour débogage
        \Log::info('Automatic search started:', [
            'search' => $search,
            'current_vendor_filter' => $this->filters['vendor']
        ]);

        // NOUVELLE MÉTHODE : Utiliser la même logique que la recherche manuelle
        // mais sans les filtres sauf pour le vendor
        $allProducts = [];
        
        // Construire les conditions comme dans la recherche manuelle
        $vendorConditions = [];
        $vendorParams = [];
        
        // Récupérer le vendor extrait
        $vendor = $this->filters['vendor'] ?? '';
        if (empty($vendor)) {
            // Si pas de vendor, extraire depuis la recherche
            $vendor = $this->guessVendorFromSearch($search);
        }
        
        if (!empty($vendor)) {
            $vendorVariations = $this->getVendorVariations($vendor);
            
            foreach ($vendorVariations as $variation) {
                $vendorConditions[] = "lp.vendor LIKE ?";
                $vendorParams[] = '%' . $variation . '%';
            }
        }
        
        // Extraire le premier mot du nom du produit (après le vendor)
        $firstProductWord = $this->extractFirstProductWord($search, $vendor);
        \Log::info('Extracted first product word:', [
            'search' => $search,
            'vendor' => $vendor,
            'first_product_word' => $firstProductWord
        ]);
        
        // Construire la requête SQL comme dans la recherche manuelle
        $sql = "SELECT 
                    lp.*, 
                    ws.name as site_name, 
                    lp.url as product_url, 
                    lp.image_url as image
                FROM last_price_scraped_product lp
                LEFT JOIN web_site ws ON lp.web_site_id = ws.id
                WHERE (lp.variation != 'Standard' OR lp.variation IS NULL OR lp.variation = '')";
        
        $params = [];

        // Appliquer les conditions du vendor
        if (!empty($vendorConditions)) {
            $sql .= " AND (" . implode(' OR ', $vendorConditions) . ")";
            $params = array_merge($params, $vendorParams);
        }
        
        // Ajouter une condition pour le premier mot du produit si trouvé
        if (!empty($firstProductWord)) {
            $sql .= " AND (lp.name LIKE ? OR lp.variation LIKE ?)";
            $params[] = '%' . $firstProductWord . '%';
            $params[] = '%' . $firstProductWord . '%';
        }

        // FILTRE MULTI-SELECT POUR SITE_SOURCE (si appliqué)
        if (!empty($this->filters['site_source']) && is_array($this->filters['site_source'])) {
            $placeholders = implode(',', array_fill(0, count($this->filters['site_source']), '?'));
            $sql .= " AND lp.web_site_id IN (" . $placeholders . ")";
            $params = array_merge($params, $this->filters['site_source']);
        }

        $sql .= " ORDER BY lp.prix_ht DESC LIMIT 100";

        \Log::info('Automatic search SQL:', [
            'sql' => $sql,
            'params' => $params
        ]);

        $allProducts = DB::connection('mysql')->select($sql, $params);

        \Log::info('Total products found:', ['count' => count($allProducts)]);

        // Si peu de résultats avec cette méthode, essayer la méthode originale
        if (count($allProducts) < 5) {
            \Log::info('Too few results with manual-like search, trying full search');
            
            // ÉTAPE 2 : Recherche FULLTEXT comme avant
            $searchQuery = $this->prepareAutomaticSearchTerms($search);
            
            $sqlFullSearch = "SELECT 
                    lp.*, 
                    ws.name as site_name, 
                    lp.url as product_url, 
                    lp.image_url as image
                FROM last_price_scraped_product lp
                LEFT JOIN web_site ws ON lp.web_site_id = ws.id
                WHERE MATCH (lp.name, lp.vendor, lp.type, lp.variation) 
                    AGAINST (? IN BOOLEAN MODE)
                AND (lp.variation != 'Standard' OR lp.variation IS NULL OR lp.variation = '')";

            $fullSearchParams = [$searchQuery];

            // FILTRE MULTI-SELECT POUR SITE_SOURCE dans la recherche FULLTEXT
            if (!empty($this->filters['site_source']) && is_array($this->filters['site_source'])) {
                $placeholders = implode(',', array_fill(0, count($this->filters['site_source']), '?'));
                $sqlFullSearch .= " AND lp.web_site_id IN (" . $placeholders . ")";
                $fullSearchParams = array_merge($fullSearchParams, $this->filters['site_source']);
            }

            $sqlFullSearch .= " ORDER BY lp.prix_ht DESC LIMIT 200";

            $fullSearchProducts = DB::connection('mysql')->select($sqlFullSearch, $fullSearchParams);
            
            // Combiner les résultats, éviter les doublons
            $allProductsIds = [];
            foreach ($allProducts as $product) {
                $allProductsIds[] = $product->id ?? $product->url;
            }
            
            foreach ($fullSearchProducts as $product) {
                $productId = $product->id ?? $product->url;
                if (!in_array($productId, $allProductsIds)) {
                    $allProducts[] = $product;
                    $allProductsIds[] = $productId;
                }
            }
        }

        \Log::info('Total products after all searches:', ['count' => count($allProducts)]);

        if (empty($allProducts)) {
            return $this->handleEmptyResults($cacheKey);
        }

        // Traiter les produits
        $processedProducts = [];
        foreach ($allProducts as $product) {
            if (isset($product->prix_ht)) {
                $product->prix_ht = $this->cleanPrice($product->prix_ht);
            }
            if (isset($product->vendor)) {
                $product->vendor = $this->normalizeVendor($product->vendor);
            }
            $product->product_url = $product->product_url ?? $product->url ?? null;
            $product->image = $product->image ?? $product->image_url ?? null;
            $product->is_manual_search = false;
            $processedProducts[] = $product;
        }

        // Extraction des composants pour la similarité
        $components = $this->extractSearchComponents($search);
        $this->searchVolumes = $components['volumes'];
        $this->extractSearchVariationKeywords($search);

        \Log::info('Components extracted:', [
            'vendor' => $components['vendor'],
            'product_name' => $components['product_name'],
            'first_product_word' => $components['first_product_word'],
            'volumes' => $components['volumes']
        ]);

        // Calcul de similarité avec seuil réduit pour plus de résultats
        $matchedProducts = [];
        $tempThreshold = $this->similarityThreshold;
        
        // Si peu de résultats, baisser le seuil temporairement
        if (count($processedProducts) < 10) {
            $tempThreshold = max(0.3, $this->similarityThreshold - 0.2);
            \Log::info('Lowering threshold for more results:', [
                'original' => $this->similarityThreshold,
                'temp' => $tempThreshold
            ]);
        }
        
        foreach ($processedProducts as $product) {
            $similarityScore = $this->computeOverallSimilarityImproved($product, $search, $components);
            
            if ($similarityScore >= $tempThreshold) {
                $product->similarity_score = $similarityScore;
                $product->match_level = $this->getMatchLevel($similarityScore);
                $product->search_source = 'mixed';
                $matchedProducts[] = $product;
            }
        }

        // Trier par score décroissant
        usort($matchedProducts, function ($a, $b) {
            return $b->similarity_score <=> $a->similarity_score;
        });

        \Log::info('Products after similarity filter:', [
            'count' => count($matchedProducts),
            'threshold_used' => $tempThreshold
        ]);

        // Préparer le résultat final
        $fullResult = [
            'count' => count($matchedProducts),
            'has_data' => !empty($matchedProducts),
            'products' => $matchedProducts,
            'product' => $this->getOneProductDetails($this->id),
            'query' => $search,
            'vendor_used' => $vendor,
            'first_product_word' => $firstProductWord,
            'volumes' => $components['volumes'],
            'variation_keywords' => $this->searchVariationKeywords,
            'search_strategy' => 'manual_like_search'
        ];

        // Mettre en cache
        $this->cacheResults($cacheKey, [
            'products' => $matchedProducts,
            'full_result' => $fullResult
        ]);

        // Mettre à jour les propriétés
        $this->matchedProducts = $matchedProducts;
        $this->products = $matchedProducts;
        $this->originalAutomaticResults = $matchedProducts;
        $this->hasAppliedFilters = false;
        $this->hasData = !empty($matchedProducts);
        $this->isAutomaticSearch = true;
        $this->showTable = true;

        return $fullResult;

    } catch (\Throwable $e) {
        \Log::error('Competitor price error:', [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        $this->products = [];
        $this->hasData = false;
        $this->originalAutomaticResults = [];
        $this->hasAppliedFilters = false;
        $this->showTable = true;
        return ['error' => $e->getMessage()];
    }
}

    /**
     * Méthode de similarité AMÉLIORÉE avec premier mot du produit
     */
    private function computeOverallSimilarityImproved($product, $search, $components)
    {
        $weights = [
            'vendor' => 0.25,
            'first_product_word' => 0.20,
            'name' => 0.20,
            'variation' => 0.15,
            'volumes' => 0.15,
            'type' => 0.05
        ];

        $totalScore = 0;

        // 1. Score du vendor
        $vendorScore = $this->computeVendorSimilarityEnhanced($product, $components['vendor']);
        $totalScore += $vendorScore * $weights['vendor'];

        // 2. Score du premier mot du produit (NOUVEAU)
        $firstWordScore = $this->computeFirstWordSimilarity($product, $components['first_product_word']);
        $totalScore += $firstWordScore * $weights['first_product_word'];

        // 3. Score du nom
        $nameScore = $this->computeNameSimilarityImproved($product->name ?? '', $search, $components['product_name']);
        $totalScore += $nameScore * $weights['name'];

        // 4. Score de la variation
        $variationScore = $this->computeVariationSimilarityImproved($product, $search);
        $totalScore += $variationScore * $weights['variation'];

        // 5. Score des volumes
        $volumeScore = $this->computeVolumeMatch($product, $components['volumes']);
        $totalScore += $volumeScore * $weights['volumes'];

        // 6. Score du type
        $typeScore = $this->computeTypeSimilarity($product, $search);
        $totalScore += $typeScore * $weights['type'];

        return min(1.0, $totalScore);
    }

    /**
     * Calcule la similarité basée sur le premier mot du produit
     */
    private function computeFirstWordSimilarity($product, $firstProductWord): float
    {
        if (empty($firstProductWord)) {
            return 0;
        }
        
        $productName = $product->name ?? '';
        $productVariation = $product->variation ?? '';
        
        $searchLower = mb_strtolower($firstProductWord);
        $nameLower = mb_strtolower($productName);
        $variationLower = mb_strtolower($productVariation);
        
        // Vérifier si le premier mot est dans le nom ou la variation
        if (str_contains($nameLower, $searchLower) || str_contains($variationLower, $searchLower)) {
            return 0.9;
        }
        
        // Vérifier la similarité avec le début du nom
        $firstWordOfName = $this->extractFirstWordFromString($productName);
        if (!empty($firstWordOfName) && $this->computeStringSimilarity($searchLower, mb_strtolower($firstWordOfName)) > 0.8) {
            return 0.85;
        }
        
        // Vérifier la similarité partielle
        $words = preg_split('/\s+/', $nameLower);
        foreach ($words as $word) {
            if ($this->computeStringSimilarity($searchLower, $word) > 0.7) {
                return 0.7;
            }
        }
        
        return 0;
    }

    /**
     * Similarité du nom améliorée
     */
    private function computeNameSimilarityImproved($productName, $search, $searchProductName)
    {
        if (empty($productName)) {
            return 0;
        }

        $productNameLower = mb_strtolower(trim($productName));
        $searchLower = mb_strtolower(trim($search));
        $searchProductNameLower = mb_strtolower(trim($searchProductName));

        // Si le nom du produit contient le nom recherché (ou vice versa)
        if (str_contains($productNameLower, $searchProductNameLower) || 
            str_contains($searchProductNameLower, $productNameLower)) {
            return 0.9;
        }

        // Extraire les mots clés du nom recherché
        $searchKeywords = array_filter(explode(' ', $searchProductNameLower), function($word) {
            return strlen($word) > 2 && !$this->isStopWord($word);
        });

        $matches = 0;
        foreach ($searchKeywords as $keyword) {
            if (str_contains($productNameLower, $keyword)) {
                $matches++;
            }
        }

        if (!empty($searchKeywords)) {
            $keywordScore = $matches / count($searchKeywords);
        } else {
            $keywordScore = 0;
        }

        // Score de similarité de chaîne
        $stringScore = $this->computeStringSimilarity($search, $productName);

        // Prendre le meilleur score
        return max($keywordScore, $stringScore);
    }

    /**
     * Similarité de la variation améliorée
     */
    private function computeVariationSimilarityImproved($product, $search)
    {
        $productVariation = $product->variation ?? '';
        
        if (empty($productVariation)) {
            return 0.5;
        }

        $searchVariation = $this->extractSearchVariationFromSearch($search);

        if (empty($searchVariation)) {
            return 0.5;
        }

        return $this->computeStringSimilarity($searchVariation, $productVariation);
    }

    /**
     * Vérifie si un mot est un stop word
     */
    private function isStopWord(string $word): bool
    {
        $stopWords = [
            'de', 'le', 'la', 'les', 'un', 'une', 'des', 'du', 'et', 'ou',
            'pour', 'avec', 'the', 'a', 'an', 'and', 'or', 'eau', 'ml',
            'edition', 'édition', 'coffret', 'spray', 'vapo', 'vaporisateur'
        ];
        
        return in_array(mb_strtolower($word), $stopWords);
    }

    /**
     * Score vendor amélioré
     */
    private function computeVendorSimilarityEnhanced($product, $searchVendor)
    {
        $productVendor = $product->vendor ?? '';
        
        if (empty($productVendor) || empty($searchVendor)) {
            return 0;
        }

        $productLower = mb_strtolower(trim($productVendor));
        $searchLower = mb_strtolower(trim($searchVendor));

        if ($productLower === $searchLower) {
            return 1.0;
        }
        
        if (str_starts_with($productLower, $searchLower) || str_starts_with($searchLower, $productLower)) {
            return 0.95;
        }
        
        if (str_contains($productLower, $searchLower) || str_contains($searchLower, $productLower)) {
            return 0.85;
        }

        return $this->computeStringSimilarity($searchVendor, $productVendor);
    }

    /**
     * Score des volumes
     */
    private function computeVolumeMatch($product, $searchVolumes)
    {
        if (empty($searchVolumes)) {
            return 0.5;
        }

        $productVolumes = $this->extractVolumesFromText($product->name . ' ' . ($product->variation ?? ''));

        if (empty($productVolumes)) {
            return 0;
        }

        $matches = array_intersect($searchVolumes, $productVolumes);
        
        if (count($matches) === count($searchVolumes)) {
            return 1.0;
        }

        return count($matches) / count($searchVolumes);
    }

    /**
     * Normalisation du vendor
     */
    private function normalizeVendor(string $vendor): string
    {
        if (empty(trim($vendor))) {
            return '';
        }
        
        $this->loadVendorsFromDatabase();
        $vendorLower = mb_strtolower(trim($vendor));
        
        foreach ($this->knownVendors as $knownVendor) {
            $knownLower = mb_strtolower($knownVendor);
            
            if ($vendorLower === $knownLower) {
                return $knownVendor;
            }
            
            if (str_contains($knownLower, $vendorLower) || str_contains($vendorLower, $knownLower)) {
                $levenshtein = levenshtein($vendorLower, $knownLower);
                $maxLength = max(strlen($vendorLower), strlen($knownLower));
                
                if ($maxLength > 0 && ($levenshtein / $maxLength) < 0.3) {
                    return $knownVendor;
                }
            }
        }
        
        return trim($vendor);
    }

    /**
     * Méthodes utilitaires
     */
    public function applyFilters()
    {
        $cacheKey = $this->getManualSearchCacheKey();
        $this->forgetCache($cacheKey);

        if ($this->isAutomaticSearch && $this->hasData) {
            $this->searchManual();
        } else {
            $this->searchManual();
        }
    }

    public function resetFilters()
    {
        $manualCacheKey = $this->getManualSearchCacheKey();
        $this->forgetCache($manualCacheKey);

        if (!empty($this->searchQuery)) {
            $autoCacheKey = $this->getCacheKey($this->searchQuery, [], false);
            $this->forgetCache($autoCacheKey);
        }

        $this->filters = [
            'vendor' => $this->filters['vendor'],
            'name' => '',
            'variation' => '',
            'type' => '',
            'site_source' => ''
        ];

        $this->hasAppliedFilters = false;

        if (!empty($this->originalAutomaticResults) && !$this->hasAppliedFilters) {
            $this->matchedProducts = $this->originalAutomaticResults;
            $this->products = $this->matchedProducts;
            $this->hasData = !empty($this->matchedProducts);
            $this->isAutomaticSearch = true;
        } else {
            if (!empty($this->searchQuery)) {
                $this->getCompetitorPrice($this->searchQuery);
            }
        }
    }

    public function updatedFilters($value, $key)
    {
        $cacheKey = $this->getManualSearchCacheKey();
        $this->forgetCache($cacheKey);

        if (!empty($value)) {
            if ($this->isAutomaticSearch && $this->hasData) {
                $this->hasAppliedFilters = true;
            }
        }
        
        $this->applyFilters();
    }

    private function resetSearchState(): ?array
    {
        $this->products = [];
        $this->hasData = false;
        $this->originalAutomaticResults = [];
        $this->hasAppliedFilters = false;
        $this->showTable = true;
        return null;
    }

    private function handleEmptyResults(string $cacheKey): array
    {
        $emptyResult = [
            'count' => 0,
            'has_data' => false,
            'products' => [],
            'product' => $this->getOneProductDetails($this->id),
            'query' => '',
            'vendor_used' => $this->filters['vendor'] ?? '',
            'first_product_word' => '',
            'volumes' => $this->searchVolumes ?? [],
            'variation_keywords' => $this->searchVariationKeywords ?? []
        ];

        $this->cacheResults($cacheKey, [
            'products' => [],
            'full_result' => $emptyResult
        ]);

        $this->products = [];
        $this->hasData = false;
        $this->originalAutomaticResults = [];
        $this->hasAppliedFilters = false;
        $this->showTable = true;
        $this->isAutomaticSearch = true;

        return $emptyResult;
    }

    private function computeStringSimilarity($str1, $str2)
    {
        $str1 = mb_strtolower(trim($str1));
        $str2 = mb_strtolower(trim($str2));

        if (empty($str1) || empty($str2)) {
            return 0;
        }

        if ($str1 === $str2) {
            return 1.0;
        }

        $len1 = mb_strlen($str1);
        $len2 = mb_strlen($str2);

        $matchDistance = (int) floor(max($len1, $len2) / 2) - 1;
        $matches1 = array_fill(0, $len1, false);
        $matches2 = array_fill(0, $len2, false);

        $matches = 0;
        $transpositions = 0;

        for ($i = 0; $i < $len1; $i++) {
            $start = max(0, $i - $matchDistance);
            $end = min($i + $matchDistance + 1, $len2);

            for ($j = $start; $j < $end; $j++) {
                if (!$matches2[$j] && mb_substr($str1, $i, 1) === mb_substr($str2, $j, 1)) {
                    $matches1[$i] = true;
                    $matches2[$j] = true;
                    $matches++;
                    break;
                }
            }
        }

        if ($matches === 0) {
            return 0;
        }

        $k = 0;
        for ($i = 0; $i < $len1; $i++) {
            if ($matches1[$i]) {
                while (!$matches2[$k]) {
                    $k++;
                }
                if (mb_substr($str1, $i, 1) !== mb_substr($str2, $k, 1)) {
                    $transpositions++;
                }
                $k++;
            }
        }

        $transpositions = $transpositions / 2;
        $jaro = (($matches / $len1) + ($matches / $len2) + (($matches - $transpositions) / $matches)) / 3;

        $prefix = 0;
        $maxPrefix = min(4, min($len1, $len2));

        for ($i = 0; $i < $maxPrefix; $i++) {
            if (mb_substr($str1, $i, 1) === mb_substr($str2, $i, 1)) {
                $prefix++;
            } else {
                break;
            }
        }

        return $jaro + ($prefix * 0.1 * (1 - $jaro));
    }

    private function computeTypeSimilarity($product, $search)
    {
        $productType = $product->type ?? '';
        if (empty($productType)) {
            return 0;
        }

        $searchType = $this->extractProductTypeFromSearch($search);

        if (empty($searchType)) {
            return 0;
        }

        return $this->computeStringSimilarity($searchType, $productType);
    }

    private function extractSearchVariationFromSearch($search)
    {
        $pattern = '/^[^-]+\s*-\s*[^-]+\s*-\s*/i';
        $variation = preg_replace($pattern, '', $search);
        return trim($variation);
    }

    private function extractProductTypeFromSearch($search)
    {
        $types = ['parfum', 'eau de parfum', 'eau de toilette', 'coffret', 'gel douche', 'lotion'];

        foreach ($types as $type) {
            if (stripos($search, $type) !== false) {
                return $type;
            }
        }

        return '';
    }

    private function getMatchLevel($similarityScore)
    {
        if ($similarityScore >= 0.9) return 'excellent';
        if ($similarityScore >= 0.7) return 'bon';
        if ($similarityScore >= 0.6) return 'moyen';
        return 'faible';
    }

    private function extractSearchVolumes(string $search): void
    {
        $this->searchVolumes = [];

        if (preg_match_all('/(\d+)\s*ml/i', $search, $matches)) {
            $this->searchVolumes = $matches[1];
        }
    }

    private function extractSearchVariationKeywords(string $search): void
    {
        $this->searchVariationKeywords = [];

        $pattern = '/^[^-]+\s*-\s*[^-]+\s*-\s*/i';
        $variation = preg_replace($pattern, '', $search);

        $variationClean = preg_replace('/[^a-zA-ZÀ-ÿ0-9\s]/', ' ', $variation);
        $variationClean = trim(preg_replace('/\s+/', ' ', $variationClean));
        $variationClean = mb_strtolower($variationClean);

        $words = explode(" ", $variationClean);

        $stopWords = [
            'de', 'le', 'la', 'les', 'un', 'une', 'des', 'du', 'et', 'ou',
            'pour', 'avec', 'the', 'a', 'an', 'and', 'or', 'ml', 'edition', 'édition'
        ];

        foreach ($words as $word) {
            $word = trim($word);

            if ((strlen($word) > 1 && !in_array($word, $stopWords)) || is_numeric($word)) {
                $this->searchVariationKeywords[] = $word;
            }
        }
    }

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

    public function getOneProductDetails($entity_id)
    {
        try {
            $cacheKey = 'product_details:' . $entity_id;
            $cachedDetails = Cache::get($cacheKey);

            if ($cachedDetails !== null) {
                return $cachedDetails;
            }

            $dataQuery = "
                SELECT 
                    produit.entity_id as id,
                    produit.sku as sku,
                    product_char.reference as parkode,
                    CAST(product_char.name AS CHAR CHARACTER SET utf8mb4) as title,
                    CAST(product_parent_char.name AS CHAR CHARACTER SET utf8mb4) as parent_title,
                    SUBSTRING_INDEX(product_char.name, ' - ', 1) as vendor,
                    SUBSTRING_INDEX(eas.attribute_set_name, '_', -1) as type,
                    product_char.thumbnail as thumbnail,
                    product_char.swatch_image as swatch_image,
                    product_char.reference as parkode,
                    product_char.reference_us as reference_us,
                    CAST(product_text.description AS CHAR CHARACTER SET utf8mb4) as description,
                    CAST(product_text.short_description AS CHAR CHARACTER SET utf8mb4) as short_description,
                    CAST(product_parent_text.description AS CHAR CHARACTER SET utf8mb4) as parent_description,
                    CAST(product_parent_text.short_description AS CHAR CHARACTER SET utf8mb4) as parent_short_description,
                    CAST(product_text.composition AS CHAR CHARACTER SET utf8mb4) as composition,
                    CAST(product_text.olfactive_families AS CHAR CHARACTER SET utf8mb4) as olfactive_families,
                    CAST(product_text.product_benefit AS CHAR CHARACTER SET utf8mb4) as product_benefit,
                    ROUND(product_decimal.price, 2) as price,
                    ROUND(product_decimal.special_price, 2) as special_price,
                    ROUND(product_decimal.cost, 2) as cost,
                    ROUND(product_decimal.pvc, 2) as pvc,
                    ROUND(product_decimal.prix_achat_ht, 2) as prix_achat_ht,
                    ROUND(product_decimal.prix_us, 2) as prix_us,
                    product_int.status as status,
                    product_int.color as color,
                    product_int.capacity as capacity,
                    product_int.product_type as product_type,
                    product_media.media_gallery as media_gallery,
                    CAST(product_categorie.name AS CHAR CHARACTER SET utf8mb4) as categorie,
                    REPLACE(product_categorie.name, ' > ', ',') as tags,
                    stock_item.qty as quatity,
                    stock_status.stock_status as quatity_status,
                    options.configurable_product_id as configurable_product_id,
                    parent_child_table.parent_id as parent_id,
                    options.attribute_code as option_name,
                    options.attribute_value as option_value
                FROM catalog_product_entity as produit
                LEFT JOIN catalog_product_relation as parent_child_table ON parent_child_table.child_id = produit.entity_id 
                LEFT JOIN catalog_product_super_link as cpsl ON cpsl.product_id = produit.entity_id 
                LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
                LEFT JOIN product_text ON product_text.entity_id = produit.entity_id 
                LEFT JOIN product_decimal ON product_decimal.entity_id = produit.entity_id
                LEFT JOIN product_int ON product_int.entity_id = produit.entity_id
                LEFT JOIN product_media ON product_media.entity_id = produit.entity_id
                LEFT JOIN product_categorie ON product_categorie.entity_id = produit.entity_id 
                LEFT JOIN cataloginventory_stock_item AS stock_item ON stock_item.product_id = produit.entity_id 
                LEFT JOIN cataloginventory_stock_status AS stock_status ON stock_item.product_id = stock_status.product_id 
                LEFT JOIN option_super_attribut AS options ON options.simple_product_id = produit.entity_id 
                LEFT JOIN eav_attribute_set AS eas ON produit.attribute_set_id = eas.attribute_set_id 
                LEFT JOIN catalog_product_entity as produit_parent ON parent_child_table.parent_id = produit_parent.entity_id 
                LEFT JOIN product_char as product_parent_char ON product_parent_char.entity_id = produit_parent.entity_id
                LEFT JOIN product_text as product_parent_text ON product_parent_text.entity_id = produit_parent.entity_id 
                WHERE product_int.status >= 0 AND produit.entity_id = ? 
                ORDER BY product_char.entity_id DESC
            ";

            $result = DB::connection('mysqlMagento')->select($dataQuery, [$entity_id]);
            Cache::put($cacheKey, $result, now()->addMinutes(30));

            return $result;

        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function formatPrice($price)
    {
        $cleanPrice = $this->cleanPrice($price);

        if ($cleanPrice !== null) {
            return number_format($cleanPrice, 2, ',', ' ') . ' €';
        }

        return 'N/A';
    }

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
            \Log::error('Error extracting domain:', ['url' => $url]);
        }

        return 'N/A';
    }

    public function extractVolumesFromText($text)
    {
        if (empty($text)) {
            return [];
        }

        $volumes = [];
        if (preg_match_all('/(\d+)\s*ml/i', $text, $matches)) {
            $volumes = $matches[1];
        }

        return $volumes;
    }

    public function isVolumeMatching($volume)
    {
        return in_array($volume, $this->searchVolumes);
    }

    public function hasMatchingVolume($product)
    {
        if (empty($this->searchVolumes)) {
            return false;
        }

        $productVolumes = $this->extractVolumesFromText($product->name . ' ' . $product->variation);
        return !empty(array_intersect($this->searchVolumes, $productVolumes));
    }

    public function hasMatchingVariationKeyword($product)
    {
        if (empty($this->searchVariationKeywords) || empty($product->variation)) {
            return false;
        }

        $productVariationLower = mb_strtolower($product->variation);

        foreach ($this->searchVariationKeywords as $keyword) {
            if (str_contains($productVariationLower, $keyword)) {
                return true;
            }
        }

        return false;
    }

    public function isPerfectMatch($product)
    {
        $hasMatchingVolume = $this->hasMatchingVolume($product);
        $hasMatchingVariationKeyword = $this->hasMatchingVariationKeyword($product);

        return $hasMatchingVolume && $hasMatchingVariationKeyword;
    }

    public function hasExactVariationMatch($product)
    {
        $searchVariation = $this->extractSearchVariation();
        $productVariation = $product->variation ?? '';

        $searchNormalized = $this->normalizeVariation($searchVariation);
        $productNormalized = $this->normalizeVariation($productVariation);

        return $searchNormalized === $productNormalized;
    }

    private function extractSearchVariation()
    {
        $pattern = '/^[^-]+\s*-\s*[^-]+\s*-\s*/i';
        $variation = preg_replace($pattern, '', $this->search ?? '');
        return trim($variation);
    }

    private function normalizeVariation($variation)
    {
        if (empty($variation)) {
            return '';
        }

        $normalized = mb_strtolower(trim($variation));
        $normalized = preg_replace('/[^a-zA-ZÀ-ÿ0-9\s]/', ' ', $normalized);
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized));

        return $normalized;
    }

    public function hasSameVolumeAndExactVariation($product)
    {
        $hasMatchingVolume = $this->hasMatchingVolume($product);
        $hasExactVariation = $this->hasExactVariationMatch($product);

        return $hasMatchingVolume && $hasExactVariation;
    }

    public function highlightMatchingTerms($text)
    {
        if (empty($text)) {
            return $text;
        }

        $patterns = [];

        if (!empty($this->searchVolumes)) {
            foreach ($this->searchVolumes as $volume) {
                $patterns[] = '\b' . preg_quote($volume, '/') . '\s*ml\b';
            }
        }

        if (!empty($this->searchVariationKeywords)) {
            foreach ($this->searchVariationKeywords as $keyword) {
                if (empty($keyword) || is_numeric($keyword)) {
                    continue;
                }
                $patterns[] = '\b' . preg_quote(trim($keyword), '/') . '\b';
            }
        }

        if (empty($patterns)) {
            return $text;
        }

        $pattern = '/(' . implode('|', $patterns) . ')/iu';

        $text = preg_replace_callback($pattern, function ($matches) {
            return '<span class="bg-green-100 text-green-800 font-semibold px-1 py-0.5 rounded">'
                . $matches[0]
                . '</span>';
        }, $text);

        return $text;
    }

    public function adjustSimilarityThreshold($threshold)
    {
        $this->similarityThreshold = $threshold;

        if (!empty($this->searchQuery)) {
            $this->getCompetitorPrice($this->searchQuery);
        }
    }

    public function calculatePriceDifference($competitorPrice)
    {
        $cleanCompetitorPrice = $this->cleanPrice($competitorPrice);
        $cleanReferencePrice = $this->cleanPrice($this->referencePrice);

        if ($cleanCompetitorPrice === null || $cleanReferencePrice === null) {
            return null;
        }

        return $cleanReferencePrice - $cleanCompetitorPrice;
    }

    public function calculatePriceDifferencePercentage($competitorPrice)
    {
        $cleanCompetitorPrice = $this->cleanPrice($competitorPrice);
        $cleanReferencePrice = $this->cleanPrice($this->referencePrice);

        if ($cleanCompetitorPrice === null || $cleanReferencePrice === null || $cleanCompetitorPrice == 0) {
            return null;
        }

        return (($cleanReferencePrice - $cleanCompetitorPrice) / $cleanCompetitorPrice) * 100;
    }

    public function getPriceCompetitiveness($competitorPrice)
    {
        $difference = $this->calculatePriceDifference($competitorPrice);

        if ($difference === null) {
            return 'unknown';
        }

        if ($difference > 10) {
            return 'higher';
        } elseif ($difference > 0) {
            return 'slightly_higher';
        } elseif ($difference == 0) {
            return 'same';
        } elseif ($difference >= -10) {
            return 'competitive';
        } else {
            return 'very_competitive';
        }
    }

    public function getPriceStatusLabel($competitorPrice)
    {
        $status = $this->getPriceCompetitiveness($competitorPrice);

        $labels = [
            'very_competitive' => 'Nous sommes beaucoup moins cher',
            'competitive' => 'Nous sommes moins cher',
            'same' => 'Prix identique',
            'slightly_higher' => 'Nous sommes légèrement plus cher',
            'higher' => 'Nous sommes beaucoup plus cher',
            'unknown' => 'Non comparable'
        ];

        return $labels[$status] ?? $labels['unknown'];
    }

    public function getPriceStatusClass($competitorPrice)
    {
        $status = $this->getPriceCompetitiveness($competitorPrice);

        $classes = [
            'very_competitive' => 'bg-green-100 text-green-800 border-green-300',
            'competitive' => 'bg-emerald-100 text-emerald-800 border-emerald-300',
            'same' => 'bg-blue-100 text-blue-800 border-blue-300',
            'slightly_higher' => 'bg-yellow-100 text-yellow-800 border-yellow-300',
            'higher' => 'bg-red-100 text-red-800 border-red-300',
            'unknown' => 'bg-gray-100 text-gray-800 border-gray-300'
        ];

        return $classes[$status] ?? $classes['unknown'];
    }

    public function calculateCosmashopPriceDifference($competitorPrice)
    {
        $cleanCompetitorPrice = $this->cleanPrice($competitorPrice);
        $cleanCosmashopPrice = $this->cleanPrice($this->cosmashopPrice);

        if ($cleanCompetitorPrice === null || $cleanCosmashopPrice === null) {
            return null;
        }

        return $cleanCosmashopPrice - $cleanCompetitorPrice;
    }

    public function calculateCosmashopPriceDifferencePercentage($competitorPrice)
    {
        $cleanCompetitorPrice = $this->cleanPrice($competitorPrice);
        $cleanCosmashopPrice = $this->cleanPrice($this->cosmashopPrice);

        if ($cleanCompetitorPrice === null || $cleanCosmashopPrice === null || $cleanCompetitorPrice == 0) {
            return null;
        }

        return (($cleanCosmashopPrice - $cleanCompetitorPrice) / $cleanCompetitorPrice) * 100;
    }

    public function getCosmashopPriceCompetitiveness($competitorPrice)
    {
        $difference = $this->calculateCosmashopPriceDifference($competitorPrice);

        if ($difference === null) {
            return 'unknown';
        }

        if ($difference > 10) {
            return 'higher';
        } elseif ($difference > 0) {
            return 'slightly_higher';
        } elseif ($difference == 0) {
            return 'same';
        } elseif ($difference >= -10) {
            return 'competitive';
        } else {
            return 'very_competitive';
        }
    }

    public function getCosmashopPriceStatusLabel($competitorPrice)
    {
        $status = $this->getCosmashopPriceCompetitiveness($competitorPrice);

        $labels = [
            'very_competitive' => 'Cosmashop serait beaucoup moins cher',
            'competitive' => 'Cosmashop serait moins cher',
            'same' => 'Prix identique à Cosmashop',
            'slightly_higher' => 'Cosmashop serait légèrement plus cher',
            'higher' => 'Cosmashop serait beaucoup plus cher',
            'unknown' => 'Non comparable'
        ];

        return $labels[$status] ?? $labels['unknown'];
    }

    public function getCosmashopPriceStatusClass($competitorPrice)
    {
        $status = $this->getCosmashopPriceCompetitiveness($competitorPrice);

        $classes = [
            'very_competitive' => 'bg-green-100 text-green-800 border-green-300',
            'competitive' => 'bg-emerald-100 text-emerald-800 border-emerald-300',
            'same' => 'bg-blue-100 text-blue-800 border-blue-300',
            'slightly_higher' => 'bg-yellow-100 text-yellow-800 border-yellow-300',
            'higher' => 'bg-red-100 text-red-800 border-red-300',
            'unknown' => 'bg-gray-100 text-gray-800 border-gray-300'
        ];

        return $classes[$status] ?? $classes['unknown'];
    }

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

    public function formatPercentageDifference($percentage)
    {
        if ($percentage === null) {
            return 'N/A';
        }

        if ($percentage == 0) {
            return '0%';
        }

        $formatted = number_format(abs($percentage), 1, ',', ' ');
        return $percentage > 0 ? "+{$formatted}%" : "-{$formatted}%";
    }

    public function getPriceAnalysis()
    {
        $prices = [];

        foreach ($this->matchedProducts as $product) {
            $price = $product->price_ht ?? $product->prix_ht;
            $cleanPrice = $this->cleanPrice($price);

            if ($cleanPrice !== null) {
                $prices[] = $cleanPrice;
            }
        }

        if (empty($prices)) {
            return null;
        }

        $minPrice = min($prices);
        $maxPrice = max($prices);
        $avgPrice = array_sum($prices) / count($prices);
        $ourPrice = $this->cleanPrice($this->referencePrice);

        return [
            'min' => $minPrice,
            'max' => $maxPrice,
            'average' => $avgPrice,
            'our_price' => $ourPrice,
            'count' => count($prices),
            'our_position' => $ourPrice <= $avgPrice ? 'competitive' : 'above_average'
        ];
    }

    public function getCosmashopPriceAnalysis()
    {
        $prices = [];

        foreach ($this->matchedProducts as $product) {
            $price = $product->price_ht ?? $product->prix_ht;
            $cleanPrice = $this->cleanPrice($price);

            if ($cleanPrice !== null) {
                $prices[] = $cleanPrice;
            }
        }

        if (empty($prices)) {
            return null;
        }

        $minPrice = min($prices);
        $maxPrice = max($prices);
        $avgPrice = array_sum($prices) / count($prices);
        $cosmashopPrice = $this->cleanPrice($this->cosmashopPrice);

        $belowCosmashop = 0;
        $aboveCosmashop = 0;

        foreach ($prices as $price) {
            if ($price < $cosmashopPrice) {
                $belowCosmashop++;
            } else {
                $aboveCosmashop++;
            }
        }

        return [
            'min' => $minPrice,
            'max' => $maxPrice,
            'average' => $avgPrice,
            'cosmashop_price' => $cosmashopPrice,
            'count' => count($prices),
            'below_cosmashop' => $belowCosmashop,
            'above_cosmashop' => $aboveCosmashop,
            'cosmashop_position' => $cosmashopPrice <= $avgPrice ? 'competitive' : 'above_average'
        ];
    }

    public function getProductUrl($product)
    {
        if (isset($product->product_url)) {
            return $product->product_url;
        }

        if (isset($product->url)) {
            return $product->url;
        }

        return null;
    }

    public function calculateManualSimilarity($product)
    {
        if (isset($product->similarity_score) && isset($product->match_level)) {
            return [
                'similarity_score' => $product->similarity_score,
                'match_level' => $product->match_level
            ];
        }

        if (!empty($this->searchQuery)) {
            $components = $this->extractSearchComponents($this->searchQuery);
            $similarityScore = $this->computeOverallSimilarityImproved($product, $this->searchQuery, $components);
            $matchLevel = $this->getMatchLevel($similarityScore);

            return [
                'similarity_score' => $similarityScore,
                'match_level' => $matchLevel
            ];
        }

        return [
            'similarity_score' => null,
            'match_level' => null
        ];
    }

    public function getProductImage($product)
    {
        if (isset($product->image) && !empty($product->image)) {
            return $product->image;
        }

        if (isset($product->image_url) && !empty($product->image_url)) {
            return $product->image_url;
        }

        if (isset($product->thumbnail) && !empty($product->thumbnail)) {
            return $product->thumbnail;
        }

        if (isset($product->swatch_image) && !empty($product->swatch_image)) {
            return $product->swatch_image;
        }

        if (isset($product->media_gallery) && !empty($product->media_gallery)) {
            $images = json_decode($product->media_gallery, true);
            if (is_array($images) && !empty($images)) {
                return $images[0] ?? null;
            }
        }

        return 'https://placehold.co/400x400/cccccc/999999?text=No+Image';
    }

    public function isValidImageUrl($url)
    {
        if (empty($url)) {
            return false;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        $path = parse_url($url, PHP_URL_PATH);
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return in_array(strtolower($extension), $imageExtensions);
    }
}; ?>

<div>
    <!-- Overlay de chargement global - Uniquement visible lors d'une action Livewire -->
    <div wire:loading.delay.flex class="hidden fixed inset-0 z-50 items-center justify-center bg-transparent">
        <div class="flex flex-col items-center justify-center bg-white/90 backdrop-blur-sm rounded-2xl p-8 shadow-2xl border border-white/20 min-w-[200px]">
            <!-- Spinner -->
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mb-4"></div>
            
            <!-- Texte de chargement -->
            <p class="text-lg font-semibold text-gray-800">Chargement</p>
            <p class="text-sm text-gray-600 mt-1">Veuillez patienter...</p>
        </div>
    </div>

    <!-- Indicateur de chargement pour les filtres - Uniquement lors du filtrage -->
    <div wire:loading.delay.flex wire:target="filters.vendor, filters.name, filters.variation, filters.type, filters.site_source" class="hidden fixed top-4 right-4 z-40 items-center justify-center">
        <div class="bg-blue-500/90 backdrop-blur-sm text-white px-4 py-2 rounded-lg shadow-lg flex items-center space-x-2">
            <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-white"></div>
            <span class="text-sm">Filtrage en cours...</span>
        </div>
    </div>

    <livewire:plateformes.detail :id="$id"/>

    <!-- Section d'analyse des prix (uniquement si on a des données) -->
    @if($hasData && $referencePrice && count($matchedProducts) > 0)
        @php
            $priceAnalysis = $this->getPriceAnalysis();
            $cosmashopAnalysis = $this->getCosmashopPriceAnalysis();
        @endphp
        @if($priceAnalysis && $cosmashopAnalysis)
            <div class="mx-auto w-full px-4 py-4 sm:px-6 lg:px-8">
                <!-- Analyse Cosmaparfumerie -->
                <div class="bg-gradient-to-r from-purple-50 to-indigo-50 rounded-lg border border-purple-200 p-4 mb-4">
                    <h4 class="text-lg font-semibold text-purple-800 mb-3 flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                        Analyse Cosmaparfumerie
                    </h4>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-3">
                        <div class="text-center p-3 bg-white rounded-lg shadow-sm">
                            <div class="text-2xl font-bold text-green-600">{{ $this->formatPrice($priceAnalysis['min']) }}</div>
                            <div class="text-xs text-gray-600">Prix minimum concurrent</div>
                        </div>
                        <div class="text-center p-3 bg-white rounded-lg shadow-sm">
                            <div class="text-2xl font-bold text-red-600">{{ $this->formatPrice($priceAnalysis['max']) }}</div>
                            <div class="text-xs text-gray-600">Prix maximum concurrent</div>
                        </div>
                        <div class="text-center p-3 bg-white rounded-lg shadow-sm">
                            <div class="text-2xl font-bold text-blue-600">{{ $this->formatPrice($priceAnalysis['average']) }}</div>
                            <div class="text-xs text-gray-600">Prix moyen concurrent</div>
                        </div>
                        <div class="text-center p-3 bg-white rounded-lg shadow-sm border-2 
                            {{ $priceAnalysis['our_position'] === 'competitive' ? 'border-green-300' : 'border-yellow-300' }}">
                            <div class="text-2xl font-bold text-purple-600">{{ $this->formatPrice($priceAnalysis['our_price']) }}</div>
                            <div class="text-xs {{ $priceAnalysis['our_position'] === 'competitive' ? 'text-green-600' : 'text-yellow-600' }} font-semibold">
                                Notre prix actuel
                            </div>
                        </div>
                    </div>

                    <div class="text-sm text-purple-700">
                        <strong>Analyse :</strong> 
                        Notre prix est 
                        <span class="font-semibold {{ $priceAnalysis['our_position'] === 'competitive' ? 'text-green-600' : 'text-yellow-600' }}">
                            {{ $priceAnalysis['our_position'] === 'competitive' ? 'compétitif' : 'au-dessus de la moyenne' }}
                        </span>
                        par rapport aux {{ $priceAnalysis['count'] }} concurrents analysés.
                        @if($priceAnalysis['our_price'] > $priceAnalysis['average'])
                            <span class="text-yellow-600">({{ $this->formatPriceDifference($priceAnalysis['our_price'] - $priceAnalysis['average']) }} par rapport à la moyenne)</span>
                        @endif
                    </div>
                </div>

                <!-- Analyse Cosmashop -->
                <div class="bg-gradient-to-r from-orange-50 to-amber-50 rounded-lg border border-orange-200 p-4">
                    <h4 class="text-lg font-semibold text-orange-800 mb-3 flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Analyse Cosmashop (Prix +5%)
                    </h4>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-3">
                        <div class="text-center p-3 bg-white rounded-lg shadow-sm">
                            <div class="text-2xl font-bold text-green-600">{{ $this->formatPrice($cosmashopAnalysis['min']) }}</div>
                            <div class="text-xs text-gray-600">Prix minimum concurrent</div>
                        </div>
                        <div class="text-center p-3 bg-white rounded-lg shadow-sm">
                            <div class="text-2xl font-bold text-red-600">{{ $this->formatPrice($cosmashopAnalysis['max']) }}</div>
                            <div class="text-xs text-gray-600">Prix maximum concurrent</div>
                        </div>
                        <div class="text-center p-3 bg-white rounded-lg shadow-sm">
                            <div class="text-2xl font-bold text-blue-600">{{ $this->formatPrice($cosmashopAnalysis['average']) }}</div>
                            <div class="text-xs text-gray-600">Prix moyen concurrent</div>
                        </div>
                        <div class="text-center p-3 bg-white rounded-lg shadow-sm border-2 border-orange-300">
                            <div class="text-2xl font-bold text-orange-600">{{ $this->formatPrice($cosmashopAnalysis['cosmashop_price']) }}</div>
                            <div class="text-xs text-orange-600 font-semibold">
                                Prix Cosmashop
                            </div>
                        </div>
                    </div>

                    <div class="text-sm text-orange-700">
                        <strong>Analyse :</strong> 
                        Le prix Cosmashop serait 
                        <span class="font-semibold {{ $cosmashopAnalysis['cosmashop_position'] === 'competitive' ? 'text-green-600' : 'text-yellow-600' }}">
                            {{ $cosmashopAnalysis['cosmashop_position'] === 'competitive' ? 'compétitif' : 'au-dessus de la moyenne' }}
                        </span>.
                        <span class="font-semibold text-green-600">{{ $cosmashopAnalysis['below_cosmashop'] }} concurrent(s)</span> en dessous et 
                        <span class="font-semibold text-red-600">{{ $cosmashopAnalysis['above_cosmashop'] }} concurrent(s)</span> au-dessus.
                    </div>
                </div>
            </div>
        @endif
    @endif

    <!-- Section des résultats - TOUJOURS AFFICHÉE -->
    <div class="mx-auto w-full px-4 py-6 sm:px-6 lg:px-8">
        <!-- Message d'information si pas de résultats automatiques -->
        @if(!$hasData && $showTable)
            <div class="mb-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-yellow-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span class="text-sm font-medium text-yellow-800">
                        Aucun résultat trouvé automatiquement. Utilisez les filtres ci-dessous pour rechercher manuellement.
                    </span>
                </div>
                <p class="mt-2 text-sm text-yellow-700">
                    Le vendor a été pré-rempli à partir de votre recherche. Vous pouvez ajuster les autres filtres pour trouver des produits.
                </p>
            </div>
        @endif

        @if($hasData && $isAutomaticSearch)
            <!-- Indicateur de similarité (uniquement si on a des résultats automatiques) -->
            <div class="mb-4 p-4 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg border">
                <div class="flex flex-col space-y-3">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 text-blue-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                            </svg>
                            <span class="text-sm font-medium text-blue-800">
                                Algorithme de similarité activé - 
                                {{ count($matchedProducts) }} produit(s) correspondant(s) au seuil de {{ $similarityThreshold * 100 }}%
                            </span>
                        </div>
                        <div class="flex items-center space-x-2">
                            <span class="text-xs text-blue-600 font-semibold">Ajuster le seuil :</span>
                            <!-- Boutons avec indicateurs de chargement -->
                            <button wire:click="adjustSimilarityThreshold(0.5)" 
                                    class="px-2 py-1 text-xs {{ $similarityThreshold == 0.5 ? 'bg-blue-600 text-white' : 'bg-blue-100 text-blue-800' }} rounded transition-colors flex items-center justify-center min-w-[50px]"
                                    wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="adjustSimilarityThreshold(0.5)">50%</span>
                                <span wire:loading wire:target="adjustSimilarityThreshold(0.5)">
                                    <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-blue-600"></div>
                                </span>
                            </button>
                            <button wire:click="adjustSimilarityThreshold(0.6)" 
                                    class="px-2 py-1 text-xs {{ $similarityThreshold == 0.6 ? 'bg-blue-600 text-white' : 'bg-blue-100 text-blue-800' }} rounded transition-colors flex items-center justify-center min-w-[50px]"
                                    wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="adjustSimilarityThreshold(0.6)">60%</span>
                                <span wire:loading wire:target="adjustSimilarityThreshold(0.6)">
                                    <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-blue-600"></div>
                                </span>
                            </button>
                            <button wire:click="adjustSimilarityThreshold(0.7)" 
                                    class="px-2 py-1 text-xs {{ $similarityThreshold == 0.7 ? 'bg-blue-600 text-white' : 'bg-blue-100 text-blue-800' }} rounded transition-colors flex items-center justify-center min-w-[50px]"
                                    wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="adjustSimilarityThreshold(0.7)">70%</span>
                                <span wire:loading wire:target="adjustSimilarityThreshold(0.7)">
                                    <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-blue-600"></div>
                                </span>
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center space-x-4 text-xs text-blue-600">
                        <span class="font-semibold">Légende :</span>
                        <span class="flex items-center">
                            <span class="w-3 h-3 bg-green-500 rounded-full mr-1"></span>
                            Excellent (90-100%)
                        </span>
                        <span class="flex items-center">
                            <span class="w-3 h-3 bg-blue-500 rounded-full mr-1"></span>
                            Bon (70-89%)
                        </span>
                        <span class="flex items-center">
                            <span class="w-3 h-3 bg-yellow-500 rounded-full mr-1"></span>
                            Moyen (60-69%)
                        </span>
                    </div>
                </div>
            </div>

            <!-- Critères de recherche -->
            @if(!empty($searchVolumes) || !empty($searchVariationKeywords))
                <div class="mb-4 p-4 bg-blue-50 rounded-lg">
                    <div class="flex flex-col space-y-2">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 text-blue-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <span class="text-sm font-medium text-blue-800">Critères de recherche détectés :</span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if(!empty($searchVolumes))
                                <div class="flex items-center">
                                    <span class="text-xs text-blue-700 mr-1">Volumes :</span>
                                    @foreach($searchVolumes as $volume)
                                        <span class="bg-green-100 text-green-800 font-semibold px-2 py-1 rounded text-xs">{{ $volume }} ml</span>
                                    @endforeach
                                </div>
                            @endif
                            @php
                                $searchVariation = $this->extractSearchVariation();
                            @endphp
                            @if($searchVariation)
                                <div class="flex items-center">
                                    <span class="text-xs text-blue-700 mr-1">Variation :</span>
                                    <span class="bg-blue-100 text-blue-800 font-semibold px-2 py-1 rounded text-xs">{{ $searchVariation }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endif
        @endif

        <!-- Indicateur des filtres actifs -->
        @if(array_filter($filters))
            <div class="mb-4 p-3 bg-blue-50 rounded-lg border border-blue-200">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 text-blue-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path>
                        </svg>
                        <span class="text-sm font-medium text-blue-800">Filtres actifs :</span>
                    </div>
                    <!-- Bouton Réinitialiser avec indicateur de chargement -->
                    <button wire:click="resetFilters" 
                            class="px-3 py-1.5 text-sm bg-red-50 text-red-700 hover:bg-red-100 rounded-md transition-colors duration-200 flex items-center border border-red-200"
                            wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="resetFilters">
                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                            Réinitialiser les filtres
                        </span>
                        <span wire:loading wire:target="resetFilters">
                            <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-red-600 mr-1"></div>
                            Réinitialisation...
                        </span>
                    </button>
                </div>

                <div class="mt-2 flex flex-wrap gap-2">
                    <!-- FILTRE VENDOR AJOUTÉ -->
                    @if($filters['vendor'])
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800 border border-blue-200">
                            Marque: {{ $filters['vendor'] }}
                            <button wire:click="$set('filters.vendor', '')" 
                                    class="ml-2 text-blue-600 hover:text-blue-800 flex items-center"
                                    wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="$set('filters.vendor', '')">×</span>
                                <span wire:loading wire:target="$set('filters.vendor', '')">
                                    <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-blue-600"></div>
                                </span>
                            </button>
                        </span>
                    @endif

                    @if($filters['name'])
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800 border border-green-200">
                            Nom: {{ $filters['name'] }}
                            <button wire:click="$set('filters.name', '')" 
                                    class="ml-2 text-green-600 hover:text-green-800 flex items-center"
                                    wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="$set('filters.name', '')">×</span>
                                <span wire:loading wire:target="$set('filters.name', '')">
                                    <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-green-600"></div>
                                </span>
                            </button>
                        </span>
                    @endif

                    @if($filters['variation'])
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-800 border border-purple-200">
                            Variation: {{ $filters['variation'] }}
                            <button wire:click="$set('filters.variation', '')" 
                                    class="ml-2 text-purple-600 hover:text-purple-800 flex items-center"
                                    wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="$set('filters.variation', '')">×</span>
                                <span wire:loading wire:target="$set('filters.variation', '')">
                                    <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-purple-600"></div>
                                </span>
                            </button>
                        </span>
                    @endif

                    @if($filters['type'])
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-orange-100 text-orange-800 border border-orange-200">
                            Type: {{ $filters['type'] }}
                            <button wire:click="$set('filters.type', '')" 
                                    class="ml-2 text-orange-600 hover:text-orange-800 flex items-center"
                                    wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="$set('filters.type', '')">×</span>
                                <span wire:loading wire:target="$set('filters.type', '')">
                                    <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-orange-600"></div>
                                </span>
                            </button>
                        </span>
                    @endif

                    @if($filters['site_source'])
@if(!empty($this->filters['site_source']))
    @foreach($this->filters['site_source'] as $siteId)
        @php
            $selectedSite = $sites->firstWhere('id', $siteId);
            $siteName = $selectedSite ? $selectedSite->name : 'Site ID: ' . $siteId;
        @endphp
        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800 border border-indigo-200">
            Site: {{ $siteName }}
            <button wire:click="$set('filters.site_source', {{ json_encode(array_values(array_diff($this->filters['site_source'], [$siteId]))) }})" 
                    class="ml-2 text-indigo-600 hover:text-indigo-800 flex items-center"
                    wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="$set('filters.site_source', {{ json_encode(array_values(array_diff($this->filters['site_source'], [$siteId]))) }})">×</span>
                <span wire:loading wire:target="$set('filters.site_source', {{ json_encode(array_values(array_diff($this->filters['site_source'], [$siteId]))) }})">
                    <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-indigo-600"></div>
                </span>
            </button>
        </span>
    @endforeach
@endif
                    @endif
                </div>
            </div>
        @endif

        <!-- Tableau des résultats - TOUJOURS AFFICHÉ -->
        @if($showTable)
            <div class="bg-white shadow-sm border border-gray-300 overflow-hidden" wire:loading.class="opacity-50" wire:target="adjustSimilarityThreshold, resetFilters, updatedFilters">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">
                        @if($hasData)
                            @if($isAutomaticSearch)
                                Résultats de la recherche automatique ({{ count($matchedProducts) }} produit(s))
                            @else
                                Résultats de la recherche manuelle ({{ count($matchedProducts) }} produit(s))
                            @endif
                        @else
                            Recherche manuelle - Utilisez les filtres
                        @endif
                    </h3>
                    <p class="mt-1 text-sm text-gray-500">
                        @if($hasData)
                            <span wire:loading.remove wire:target="adjustSimilarityThreshold, resetFilters, updatedFilters">
                                {{ count($matchedProducts) }} produit(s) trouvé(s)
                            </span>
                        @else
                            Aucun résultat automatique. Utilisez les filtres pour rechercher manuellement.
                        @endif
                    </p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full border-collapse border border-gray-300">
                        <thead class="bg-gray-100">
                            <tr>
                                <!-- NOUVELLE COLONNE : Image (TOUJOURS VISIBLE) -->
                                <th class="border border-gray-300 px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider bg-gray-200">
                                    <div class="flex flex-col">
                                        <span>Image</span>
                                    </div>
                                </th>
                                
                                @if($hasData && $isAutomaticSearch)
                                <!-- Colonne Score (uniquement si résultats automatiques) -->
                                <th class="border border-gray-300 px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider bg-gray-200">
                                    <div class="flex flex-col">
                                        <span>Score</span>
                                    </div>
                                </th>
                                
                                <!-- Colonne Correspondance (uniquement si résultats automatiques) -->
                                <th class="border border-gray-300 px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider bg-gray-200">
                                    <div class="flex flex-col">
                                        <span>Correspondance</span>
                                    </div>
                                </th>
                                @endif
                                
                                <!-- Colonne Vendor avec filtre AJOUTÉE -->
                                <th class="border border-gray-300 px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider bg-gray-200 min-w-48">
                                    <div class="flex flex-col space-y-2">
                                        <span class="whitespace-nowrap">Marque/Vendor</span>
                                        <div class="relative">
                                            <input type="text" 
                                                   disabled
                                                   wire:model.live.debounce.800ms="filters.vendor"
                                                   placeholder="Filtrer par marque..."
                                                   class="px-3 py-2 text-sm border border-gray-400 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 w-full"
                                                   wire:loading.attr="disabled">
                                            <div wire:loading wire:target="filters.vendor" class="absolute right-3 top-1/2 transform -translate-y-1/2">
                                                <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-blue-600"></div>
                                            </div>
                                        </div>
                                    </div>
                                </th>
                                
                                <!-- Colonne Nom avec filtre -->
                                <th class="border border-gray-300 px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider bg-gray-200 min-w-64" style="width: 30%;">
                                    <!-- Largeur ajustée -->
                                    <div class="flex flex-col space-y-2">
                                        <span class="whitespace-nowrap">Nom</span>
                                        <div class="relative">
                                            <input type="text" 
                                                wire:model.live.debounce.800ms="filters.name"
                                                placeholder="Filtrer..."
                                                class="px-3 py-2 text-sm border border-gray-400 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 w-full"
                                                wire:loading.attr="disabled">
                                            <div wire:loading wire:target="filters.name" class="absolute right-3 top-1/2 transform -translate-y-1/2">
                                                <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-blue-600"></div>
                                            </div>
                                        </div>
                                    </div>
                                </th>
                                
                                <!-- Colonne Variation avec filtre -->
                                <th class="border border-gray-300 px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider bg-gray-200">
                                    <div class="flex flex-col space-y-1">
                                        <span>Variation</span>
                                        <div class="relative">
                                            <input type="text" 
                                                   wire:model.live.debounce.800ms="filters.variation"
                                                   placeholder="Filtrer..."
                                                   class="px-3 py-2 text-sm border border-gray-400 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 w-full"
                                                   wire:loading.attr="disabled">
                                            <div wire:loading wire:target="filters.variation" class="absolute right-2 top-1/2 transform -translate-y-1/2">
                                                <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-blue-600"></div>
                                            </div>
                                        </div>
                                    </div>
                                </th>
                                
                                <!-- Colonne Site Source avec filtre -->
<th class="border border-gray-300 px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider bg-gray-200">
    <div class="flex flex-col space-y-1">
        <span>Site Source</span>
        <div class="relative">
            <select wire:model.live="filters.site_source"
                    multiple
                    class="px-2 py-1 text-xs border border-gray-400 rounded focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 w-full"
                    wire:loading.attr="disabled"
                    size="3">
                @foreach($sites as $site)
                    <option value="{{ $site->id }}">{{ $site->name }}</option>
                @endforeach
            </select>
            <div wire:loading wire:target="filters.site_source" class="absolute right-2 top-1/2 transform -translate-y-1/2">
                <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-blue-600"></div>
            </div>
        </div>
    </div>
</th>
                                
                                <!-- Colonne Prix HT -->
                                <th class="border border-gray-300 px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider bg-gray-200">
                                    <div class="flex flex-col">
                                        <span>Prix HT</span>
                                    </div>
                                </th>
                                
                                <!-- Colonne Date MAJ Prix -->
                                <th class="border border-gray-300 px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider bg-gray-200">
                                    <div class="flex flex-col">
                                        <span>Date MAJ Prix</span>
                                    </div>
                                </th>
                                
                                @if($hasData && $referencePrice)
                                <!-- Colonne Vs Cosmaparfumerie (uniquement si on a un prix de référence) -->
                                <th class="border border-gray-300 px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider bg-gray-200">
                                    <div class="flex flex-col">
                                        <span>Vs Cosmaparfumerie</span>
                                    </div>
                                </th>
                                
                                <!-- Colonne Vs Cosmashop (uniquement si on a un prix de référence) -->
                                <th class="border border-gray-300 px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider bg-gray-200">
                                    <div class="flex flex-col">
                                        <span>Vs Cosmashop</span>
                                    </div>
                                </th>
                                @endif
                                
                                <!-- Colonne Type avec filtre -->
                                <th class="border border-gray-300 px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider bg-gray-200">
                                    <div class="flex flex-col space-y-1">
                                        <span>Type</span>
                                        <div class="relative">
                                            <input type="text" 
                                                   wire:model.live.debounce.800ms="filters.type"
                                                   placeholder="Filtrer..."
                                                   class="px-2 py-1 text-xs border border-gray-400 rounded focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 w-full"
                                                   wire:loading.attr="disabled">
                                            <div wire:loading wire:target="filters.type" class="absolute right-2 top-1/2 transform -translate-y-1/2">
                                                <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-blue-600"></div>
                                            </div>
                                        </div>
                                    </div>
                                </th>
                                
                                <!-- Colonne Actions -->
                                <th class="border border-gray-300 px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider bg-gray-200">
                                    <div class="flex flex-col">
                                        <span>Actions</span>
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @if(count($matchedProducts) > 0)
                                @foreach($matchedProducts as $index => $product)
                                    @php
                                        // Pour la recherche manuelle, on calcule la similarité à la volée si nécessaire
                                        if ($isAutomaticSearch) {
                                            $similarityScore = $product->similarity_score ?? null;
                                            $matchLevel = $product->match_level ?? null;
                                        } else {
                                            // Pour la recherche manuelle, on calcule la similarité
                                            $similarityData = $this->calculateManualSimilarity($product);
                                            $similarityScore = $similarityData['similarity_score'];
                                            $matchLevel = $similarityData['match_level'];
                                        }
                                        
                                        // Définir la classe de match si disponible
                                        if ($matchLevel) {
                                            $matchClass = [
                                                'excellent' => 'bg-green-100 text-green-800 border-green-300',
                                                'bon' => 'bg-blue-100 text-blue-800 border-blue-300',
                                                'moyen' => 'bg-yellow-100 text-yellow-800 border-yellow-300',
                                                'faible' => 'bg-gray-100 text-gray-800 border-gray-300'
                                            ][$matchLevel];
                                        }

                                        $productVolumes = $this->extractVolumesFromText($product->name . ' ' . $product->variation);
                                        $hasMatchingVolume = $this->hasMatchingVolume($product);
                                        $hasExactVariation = $this->hasExactVariationMatch($product);

                                        // Données pour la comparaison de prix (uniquement si référencePrice)
                                        if ($referencePrice) {
                                            $competitorPrice = $product->price_ht ?? $product->prix_ht;
                                            $priceDifference = $this->calculatePriceDifference($competitorPrice);
                                            $priceDifferencePercent = $this->calculatePriceDifferencePercentage($competitorPrice);
                                            $priceStatusClass = $this->getPriceStatusClass($competitorPrice);
                                            $priceStatusLabel = $this->getPriceStatusLabel($competitorPrice);

                                            // Données pour Cosmashop
                                            $cosmashopDifference = $this->calculateCosmashopPriceDifference($competitorPrice);
                                            $cosmashopDifferencePercent = $this->calculateCosmashopPriceDifferencePercentage($competitorPrice);
                                            $cosmashopStatusClass = $this->getCosmashopPriceStatusClass($competitorPrice);
                                            $cosmashopStatusLabel = $this->getCosmashopPriceStatusLabel($competitorPrice);
                                        }

                                        // Alternance des couleurs de ligne pour style Excel
                                        $rowClass = $index % 2 === 0 ? 'bg-white' : 'bg-gray-50';
                                    @endphp
                                    <tr class="{{ $rowClass }} hover:bg-gray-100 transition-colors duration-150 border-b border-gray-300">
                                        <!-- NOUVELLE COLONNE : Image (TOUJOURS VISIBLE) -->
                                        <td class="border border-gray-300 px-4 py-3 whitespace-nowrap">
                                            @php
                                                $productImage = $this->getProductImage($product);
                                                $productName = $product->name ?? 'Produit sans nom';
                                            @endphp
                                            <div class="relative group">
                                                <img src="{{ $productImage }}" 
                                                     alt="{{ $productName }}" 
                                                     class="h-20 w-20 object-cover border border-gray-300 hover:shadow-sm transition-shadow duration-200"
                                                     loading="lazy"
                                                     onerror="this.onerror=null; this.src='https://placehold.co/400x400/cccccc/999999?text=No+Image'">
                                                
                                                <!-- Overlay au survol pour agrandir -->
                                                <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-10 transition-all duration-200 flex items-center justify-center opacity-0 group-hover:opacity-100">
                                                    <svg class="w-6 h-6 text-white opacity-0 group-hover:opacity-70 transition-opacity duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7"></path>
                                                    </svg>
                                                </div>
                                            </div>
                                            
                                            <!-- Indicateur si pas d'image -->
                                            @if(!$this->isValidImageUrl($productImage) || str_contains($productImage, 'https://placehold.co/400x400/cccccc/999999?text=No+Image'))
                                                <div class="mt-1 text-center">
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800 border border-gray-300">
                                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                                        </svg>
                                                        Sans image
                                                    </span>
                                                </div>
                                            @endif
                                        </td>
                                        
                                        @if($hasData && $isAutomaticSearch)
                                        <!-- Colonne Score (uniquement si résultats automatiques) -->
                                        <td class="border border-gray-300 px-4 py-3 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="w-16 bg-gray-300 rounded-full h-2 mr-3">
                                                    <div class="h-2 rounded-full 
                                                        @if($similarityScore >= 0.9) bg-green-500
                                                        @elseif($similarityScore >= 0.7) bg-blue-500
                                                        @elseif($similarityScore >= 0.6) bg-yellow-500
                                                        @else bg-gray-500 @endif"
                                                        style="width: {{ ($similarityScore ?? 0) * 100 }}%">
                                                    </div>
                                                </div>
                                                <span class="text-sm font-mono font-semibold 
                                                    @if($similarityScore >= 0.9) text-green-600
                                                    @elseif($similarityScore >= 0.7) text-blue-600
                                                    @elseif($similarityScore >= 0.6) text-yellow-600
                                                    @else text-gray-600 @endif">
                                                    {{ $similarityScore ? number_format($similarityScore * 100, 0) : 'N/A' }}%
                                                </span>
                                            </div>
                                        </td>

                                        <!-- Colonne Correspondance (uniquement si résultats automatiques) -->
                                        <td class="border border-gray-300 px-4 py-3 whitespace-nowrap">
                                            @if($matchLevel)
                                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium border {{ $matchClass ?? '' }}">
                                                    @if($matchLevel === 'excellent')
                                                        <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                                        </svg>
                                                    @endif
                                                    {{ ucfirst($matchLevel) }}
                                                </span>
                                            @else
                                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium border border-gray-300 bg-gray-100 text-gray-800">
                                                    N/A
                                                </span>
                                            @endif
                                        </td>
                                        @endif

                                        <!-- Colonne Vendor AJOUTÉE -->
                                        <td class="border border-gray-300 px-4 py-3 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">
                                                {{ $product->vendor ?? 'N/A' }}
                                            </div>
                                        </td>

                                        <!-- Colonne Nom -->
                                        <td class="border border-gray-300 px-4 py-3">
                                            <div class="text-sm font-medium text-gray-900 max-w-xs" title="{{ $product->name ?? 'N/A' }}">
                                                @if($isAutomaticSearch && !empty($searchVolumes))
                                                    {!! $this->highlightMatchingTerms($product->name) !!}
                                                @else
                                                    {{ $product->name ?? 'N/A' }}
                                                @endif
                                            </div>
                                            <!-- Badges des volumes du produit -->
                                            @if(!empty($productVolumes))
                                                <div class="mt-2 flex flex-wrap gap-1">
                                                    @foreach($productVolumes as $volume)
                                                        <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium 
                                                            @if($this->isVolumeMatching($volume))
                                                                bg-green-100 text-green-800 border border-green-300
                                                            @else
                                                                bg-gray-100 text-gray-800 border border-gray-300
                                                            @endif">
                                                            {{ $volume }} ml
                                                            @if($this->isVolumeMatching($volume))
                                                                <svg class="w-3 h-3 ml-1 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                                                </svg>
                                                            @endif
                                                        </span>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </td>

                                        <!-- Colonne Variation -->
                                        <td class="border border-gray-300 px-4 py-3 whitespace-nowrap">
                                            <div class="text-sm text-gray-900 max-w-xs" title="{{ $product->variation ?? 'Standard' }}">
                                                @if($isAutomaticSearch && !empty($searchVariationKeywords))
                                                    {!! $this->highlightMatchingTerms($product->variation ?? 'Standard') !!}
                                                @else
                                                    {{ $product->variation ?? 'Standard' }}
                                                @endif
                                            </div>
                                            @if($hasData && $hasExactVariation)
                                                <div class="mt-1">
                                                    <span class="inline-flex items-center px-2 py-1 bg-blue-100 text-blue-800 text-xs font-medium rounded-full border border-blue-300">
                                                        <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                                        </svg>
                                                        Variation identique
                                                    </span>
                                                </div>
                                            @endif
                                        </td>

                                        <!-- Colonne Site Source -->
                                        <td class="border border-gray-300 px-4 py-3 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-8 w-8 bg-gray-100 rounded-full flex items-center justify-center mr-3 border border-gray-300">
                                                    <span class="text-xs font-medium text-gray-600">
                                                        @php
                                                            $productUrl = $this->getProductUrl($product);
                                                            $domain = $this->extractDomain($productUrl ?? '');
                                                            echo strtoupper(substr($domain, 0, 2));
                                                        @endphp
                                                    </span>
                                                </div>
                                                <div>
                                                    <div class="text-sm font-medium text-gray-900">
                                                        {{ $product->site_name ?? $this->extractDomain($productUrl ?? '') }}
                                                    </div>
                                                    {{-- @if(isset($product->web_site_id))
                                                        <div class="text-xs text-gray-500">
                                                            ID: {{ $product->web_site_id }}
                                                        </div>
                                                    @endif --}}
                                                </div>
                                            </div>
                                        </td>

                                        <!-- Colonne Prix HT -->
                                        <td class="border border-gray-300 px-4 py-3 whitespace-nowrap">
                                            <div class="text-sm font-semibold text-green-600">
                                                {{ $this->formatPrice($product->price_ht ?? $product->prix_ht) }}
                                            </div>
                                        </td>

                                        <!-- Colonne Date MAJ Prix -->
                                        <td class="border border-gray-300 px-4 py-3 whitespace-nowrap">
                                            <div class="text-xs text-gray-400">
                                                {{ \Carbon\Carbon::parse($product->updated_at)->translatedFormat('j F Y \\à H:i') }}
                                            </div>
                                        </td>

                                        @if($referencePrice)
                                        <!-- Colonne Vs Cosmaparfumerie (uniquement si référencePrice) -->
                                        <td class="border border-gray-300 px-4 py-3 whitespace-nowrap">
                                            @if(is_numeric($competitorPrice) && is_numeric($referencePrice))
                                                <div class="space-y-1">
                                                    <div class="text-xs text-gray-500">
                                                        prix cosma-parfumerie: {{ number_format($referencePrice, 2, ',', ' ') }} €
                                                    </div>
                                                    <!-- Statut -->
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $priceStatusClass }}">
                                                        {{ $priceStatusLabel }}
                                                    </span>

                                                    <!-- Différence -->
                                                    <div class="text-xs font-semibold 
                                                        {{ $priceDifference > 0 ? 'text-green-600' : ($priceDifference < 0 ? 'text-red-600' : 'text-blue-600') }}">
                                                        {{ $this->formatPriceDifference($priceDifference) }}
                                                    </div>

                                                    <!-- Pourcentage -->
                                                    @if($priceDifferencePercent !== null && $priceDifference != 0)
                                                        <div class="text-xs text-gray-500">
                                                            {{ $this->formatPercentageDifference($priceDifferencePercent) }}
                                                        </div>
                                                    @endif
                                                </div>
                                            @else
                                                <span class="text-xs text-gray-400">N/A</span>
                                            @endif
                                        </td>

                                        <!-- Colonne Vs Cosmashop (uniquement si référencePrice) -->
                                        <td class="border border-gray-300 px-4 py-3 whitespace-nowrap">
                                            @if(is_numeric($competitorPrice) && is_numeric($cosmashopPrice))
                                                <div class="space-y-1">
                                                    <div class="text-xs text-gray-500">
                                                        prix cosmashop: {{ number_format($cosmashopPrice, 2, ',', ' ') }} €
                                                    </div>
                                                    <!-- Statut Cosmashop -->
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $cosmashopStatusClass }}">
                                                        {{ $cosmashopStatusLabel }}
                                                    </span>

                                                    <!-- Différence Cosmashop -->
                                                    <div class="text-xs font-semibold 
                                                        {{ $cosmashopDifference > 0 ? 'text-green-600' : ($cosmashopDifference < 0 ? 'text-red-600' : 'text-blue-600') }}">
                                                        {{ $this->formatPriceDifference($cosmashopDifference) }}
                                                    </div>

                                                    <!-- Pourcentage Cosmashop -->
                                                    @if($cosmashopDifferencePercent !== null && $cosmashopDifference != 0)
                                                        <div class="text-xs text-gray-500">
                                                            {{ $this->formatPercentageDifference($cosmashopDifferencePercent) }}
                                                        </div>
                                                    @endif
                                                </div>
                                            @else
                                                <span class="text-xs text-gray-400">N/A</span>
                                            @endif
                                        </td>
                                        @endif

                                        <!-- Colonne Type -->
                                        <td class="border border-gray-300 px-4 py-3 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800 border border-purple-300">
                                                {{ $product->type ?? 'N/A' }}
                                            </span>
                                        </td>

                                        <!-- Colonne Actions -->
                                        <td class="border border-gray-300 px-4 py-3 whitespace-nowrap text-sm font-medium">
                                            <div class="flex space-x-2">
                                                @php
                                                    $productUrl = $this->getProductUrl($product);
                                                @endphp
                                                @if(!empty($productUrl))
                                                    <a href="{{ $productUrl }}" target="_blank" rel="noopener noreferrer"
                                                        class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-full shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors duration-200">
                                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                                d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                                        </svg>
                                                        Voir
                                                    </a>
                                                @else
                                                    <span class="inline-flex items-center px-2 py-1 text-xs text-gray-400 bg-gray-100 rounded-full">
                                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                                        </svg>
                                                        Indisponible
                                                    </span>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            @else
                                <!-- Aucun résultat avec les filtres appliqués -->
                                <tr>
                                    <td colspan="{{ ($hasData && $isAutomaticSearch ? 15 : 13) }}" class="border border-gray-300 px-6 py-12 text-center">
                                        <svg class="mx-auto h-16 w-16 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        <h3 class="mt-4 text-lg font-medium text-gray-900">
                                            @if(array_filter($filters))
                                                Aucun résultat avec les filtres actuels
                                            @else
                                                Aucun produit trouvé
                                            @endif
                                        </h3>
                                        <p class="mt-2 text-sm text-gray-500">
                                            @if(array_filter($filters))
                                                Aucun produit ne correspond à vos critères de recherche. Essayez de modifier les filtres.
                                            @else
                                                Ajustez les filtres pour trouver des produits.
                                            @endif
                                        </p>
                                        @if(array_filter($filters))
                                            <div class="mt-4 flex justify-center space-x-3">
                                                <!-- Bouton Réinitialiser avec loading -->
                                                <button wire:click="resetFilters" 
                                                        class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-red-600 hover:bg-red-700 focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-colors duration-200"
                                                        wire:loading.attr="disabled">
                                                    <span wire:loading.remove wire:target="resetFilters">
                                                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                                        </svg>
                                                        Réinitialiser les filtres
                                                    </span>
                                                    <span wire:loading wire:target="resetFilters">
                                                        <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2"></div>
                                                        Réinitialisation...
                                                    </span>
                                                </button>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>


  
@push('styles')
    <style>
        /* Style Excel-like pour le tableau */
        table {
            border-collapse: collapse;
            border-spacing: 0;
        }
        
        th, td {
            border: 1px solid #d1d5db;
        }
        
        th {
            background-color: #f3f4f6;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #9ca3af;
        }
        
        /* Alternance des lignes comme Excel */
        tbody tr:nth-child(even) {
            background-color: #f9fafb;
        }
        
        tbody tr:hover {
            background-color: #f0f9ff;
        }
        
        /* Style pour les cellules avec des bordures complètes */
        .border-gray-300 {
            border-color: #d1d5db !important;
        }
        
        /* Style pour les inputs de filtres - préservé */
        input[type="text"], select {
            transition: all 0.2s ease;
            border: 1px solid #d1d5db;
        }

        input[type="text"]:focus, select:focus {
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
            border-color: #3b82f6;
        }

        /* Style pour les boutons - préservé */
        button {
            transition: all 0.2s ease;
        }

        button:hover {
            transform: translateY(-1px);
        }

        button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        /* Animation de spin pour les loaders */
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .animate-spin {
            animation: spin 1s linear infinite;
        }

        /* Style pour l'overlay de chargement global - Transparent */
        .fixed.inset-0 {
            z-index: 9999;
            background-color: transparent !important;
        }

        .fixed.inset-0 > div {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(8px);
            box-shadow: 
                0 20px 40px rgba(0, 0, 0, 0.1),
                0 0 0 1px rgba(255, 255, 255, 0.1);
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        /* Style pour le loader des filtres */
        .fixed.top-4.right-4 {
            z-index: 9998;
            animation: slideInRight 0.3s ease-out;
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        /* Transition pour l'opacité du tableau */
        .opacity-50 {
            transition: opacity 0.3s ease;
        }

        /* Style pour les images */
        img {
            border: 1px solid #d1d5db;
        }

        /* Style pour les badges */
        .rounded-full {
            border-radius: 9999px;
        }

        /* Conteneur principal du tableau */
        .overflow-x-auto {
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 #f1f5f9;
        }

        .overflow-x-auto::-webkit-scrollbar {
            height: 8px;
        }

        .overflow-x-auto::-webkit-scrollbar-track {
            background: #f1f5f9;
        }

        .overflow-x-auto::-webkit-scrollbar-thumb {
            background-color: #cbd5e1;
            border-radius: 4px;
        }
    </style>
@endpush

@push('scripts')
    <script>
        // Script pour gérer les indicateurs de chargement
        document.addEventListener('livewire:init', () => {
            // Désactiver les inputs pendant le chargement
            Livewire.hook('request', ({ fail }) => {
                // Ajouter un indicateur visuel
                document.body.style.cursor = 'wait';

                fail(() => {
                    document.body.style.cursor = 'default';
                });
            });

            Livewire.hook('response', ({ component }) => {
                document.body.style.cursor = 'default';
            });
        });
    </script>
@endpush  
</div>