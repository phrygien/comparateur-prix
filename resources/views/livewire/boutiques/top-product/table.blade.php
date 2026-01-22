<?php

use Livewire\Volt\Component;
use App\Models\DetailProduct;
use App\Models\Comparaison;
use App\Models\ProductPriceResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;

new class extends Component {

    public int $id;
    public string $listTitle = '';
    public bool $loading = true;
    public bool $loadingMore = false;
    public bool $hasMore = true;
    public int $page = 1;
    public int $perPage = 200;
    public int $totalPages = 1;
    
    // Nouveaux états pour la comparaison
    public bool $comparaisonEnCours = false;
    public float $progression = 0;
    public array $resultatsComparaison = [];
    public array $statistiques = [];
    public int $produitsTraites = 0;
    public int $produitsTotaux = 0;
    public string $erreurComparaison = '';
    public int $batchSize = 10;
    public float $similarityThreshold = 0.6;
    
    // Résultats détaillés par produit
    public array $resultatsDetails = [];
    public bool $showResultatsDetails = false;
    
    // Pour l'analyse des prix
    public array $analysePrix = [];
    public bool $showAnalysePrix = false;
    
    // Cache
    protected $cacheTTL = 3600;
    
    // Vendors connus pour la recherche
    private array $knownVendors = [];
    private bool $vendorsLoaded = false;

    public function mount($id): void
    {
        $this->id = $id;
        $this->loadListTitle();
        $this->chargerResultatsExistants();
        $this->loadVendorsFromDatabase();
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
     * Charger tous les vendors depuis la base de données
     */
    private function loadVendorsFromDatabase(): void
    {
        if ($this->vendorsLoaded) {
            return;
        }

        $cacheKey = 'all_vendors_list_comparateur';
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
            Log::error('Error loading vendors:', ['error' => $e->getMessage()]);
            $this->knownVendors = [];
            $this->vendorsLoaded = true;
        }
    }

    // Charger les résultats existants depuis la base
    public function chargerResultatsExistants(): void
    {
        try {
            $resultats = ProductPriceResult::where('comparaison_id', $this->id)
                ->orderBy('created_at', 'desc')
                ->get()
                ->groupBy('produit_sku')
                ->toArray();
            
            $this->resultatsComparaison = $resultats;
        } catch (\Exception $e) {
            Log::error('Erreur chargement résultats: ' . $e->getMessage());
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

    /**
     * EXÉCUTER LA COMPARAISON ASYNCHRONE
     */
    public function executerRecherchePrix(): void
    {
        if ($this->comparaisonEnCours) {
            return;
        }

        // Réinitialiser l'état
        $this->comparaisonEnCours = true;
        $this->progression = 0;
        $this->resultatsComparaison = [];
        $this->resultatsDetails = [];
        $this->statistiques = [];
        $this->produitsTraites = 0;
        $this->erreurComparaison = '';
        
        // Récupérer tous les produits de la liste courante
        $products = $this->getCurrentPageProducts();
        $this->produitsTotaux = count($products);
        
        if ($this->produitsTotaux === 0) {
            $this->erreurComparaison = "Aucun produit à comparer dans cette liste.";
            $this->comparaisonEnCours = false;
            return;
        }

        // Démarrer la comparaison asynchrone
        $this->dispatch('demarrer-comparaison', [
            'products' => $products,
            'batchSize' => $this->batchSize,
            'similarityThreshold' => $this->similarityThreshold,
            'comparaisonId' => $this->id
        ]);
    }

    /**
     * Récupère les produits de la page courante
     */
    private function getCurrentPageProducts(): array
    {
        try {
            $allSkus = $this->getAllSkus();
            
            if (empty($allSkus)) {
                return [];
            }
            
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
                    product_char.thumbnail as thumbnail,
                    SUBSTRING_INDEX(product_char.name, ' - ', 1) as vendor,
                    ROUND(product_decimal.price, 2) as price,
                    ROUND(product_decimal.special_price, 2) as special_price,
                    CAST(product_text.description AS CHAR CHARACTER SET utf8mb4) as description
                FROM catalog_product_entity as produit
                LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
                LEFT JOIN product_decimal ON product_decimal.entity_id = produit.entity_id
                LEFT JOIN product_text ON product_text.entity_id = produit.entity_id
                WHERE produit.sku IN ($placeholders)
                ORDER BY FIELD(produit.sku, " . implode(',', $pageSkus) . ")
            ";
            
            $result = DB::connection('mysqlMagento')->select($query, $pageSkus);
            
            return array_map(fn($p) => (array) $p, $result);
            
        } catch (\Exception $e) {
            Log::error('Erreur récupération produits: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Événement déclenché par JS pour démarrer la comparaison par lots
     */
    #[On('traiter-lot-produits')]
    public async function traiterLotProduits(array $lotProducts, int $lotIndex): void
    {
        try {
            $resultatsLot = [];
            
            // Traiter chaque produit en parallèle
            foreach ($lotProducts as $produit) {
                $resultat = await($this->rechercherProduitConcurrentAsync($produit));
                $resultatsLot[$produit['sku']] = $resultat;
                
                // Sauvegarder dans la base
                $this->sauvegarderResultat($produit['sku'], $resultat);
            }
            
            // Mettre à jour l'état
            $this->produitsTraites += count($lotProducts);
            $this->progression = ($this->produitsTraites / $this->produitsTotaux) * 100;
            
            // Ajouter aux résultats
            $this->resultatsComparaison = array_merge($this->resultatsComparaison, $resultatsLot);
            
            // Si c'est le dernier lot, finaliser
            if ($this->produitsTraites >= $this->produitsTotaux) {
                $this->finaliserComparaison();
            } else {
                // Démarrer le lot suivant
                $this->dispatch('lot-termine', ['lotIndex' => $lotIndex + 1]);
            }
            
        } catch (\Exception $e) {
            Log::error('Erreur traitement lot: ' . $e->getMessage());
            $this->erreurComparaison = "Erreur lors du traitement d'un lot de produits.";
        }
    }

    /**
     * Recherche asynchrone d'un produit concurrent
     * UTILISE L'ALGORITHME DU CODE FOURNI
     */
    private async function rechercherProduitConcurrentAsync(array $produit): array
    {
        // Clé de cache
        $cacheKey = "recherche_concurrent:" . md5(json_encode($produit));
        
        if ($resultatCache = Cache::get($cacheKey)) {
            return $resultatCache;
        }
        
        try {
            // Préparer les données pour la recherche
            $search = $produit['title'] ?? '';
            $price = $produit['price'] ?? $produit['special_price'] ?? 0;
            
            // 1. Extraire les composants de la recherche
            $components = $this->extractSearchComponents($search);
            
            // 2. Recherche chez les concurrents
            $resultatsConcurrents = await($this->rechercherConcurrentsAsync($components, $search));
            
            // 3. Filtrer par similarité
            $resultatsFiltres = $this->filtrerParSimilarite($resultatsConcurrents, $search, $components);
            
            // 4. Analyser les résultats
            $resultat = $this->analyserResultats($produit, $resultatsFiltres, $price);
            
            // Mettre en cache
            Cache::put($cacheKey, $resultat, 1800); // 30 minutes
            
            return $resultat;
            
        } catch (\Exception $e) {
            Log::error('Erreur recherche produit ' . ($produit['sku'] ?? '') . ': ' . $e->getMessage());
            
            return [
                'produit' => $produit,
                'resultats' => [],
                'statistiques' => [
                    'meilleur_prix' => null,
                    'prix_moyen' => null,
                    'economie_potentielle' => 0,
                    'nombre_concurrents' => 0
                ],
                'erreur' => $e->getMessage(),
                'trouve' => false
            ];
        }
    }

    /**
     * Extrait les composants de la recherche (même algorithme que le code fourni)
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
        $vendor = $this->guessVendorFromSearch($search);
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
            'eau de parfum',
            'eau de toilette',
            'eau fraiche',
            'eau de cologne',
            'edp',
            'edt',
            'edc',
            'parfum',
            'cologne',
            'intense',
            'absolu',
            'coffret',
            'spray',
            'vapo',
            'vaporisateur',
            'pour homme',
            'pour femme'
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
     * Devine le vendor à partir de la recherche
     */
    private function guessVendorFromSearch(string $search): string
    {
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
     * Extrait le premier mot du produit
     */
    private function extractFirstProductWord(string $search, ?string $vendor = null): string
    {
        // Nettoyer la recherche
        $search = trim($search);

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

        // Supprimer les préfixes communs
        $search = preg_replace('/^\s*-\s*/', '', $search);

        // Extraire les parties séparées par des tirets
        $parts = preg_split('/\s*-\s*/', $search, 3);

        // Le premier mot après le vendor est généralement dans la première partie
        $potentialPart = $parts[0] ?? '';

        // Si la partie contient des mots clés de produit, passer à la suivante
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

        // Nettoyer le mot
        $firstWord = preg_replace('/[^a-zA-ZÀ-ÿ0-9]/', '', $firstWord);

        // Vérifier que ce n'est pas un mot vide ou un mot clé
        $stopWords = ['le', 'la', 'les', 'de', 'des', 'du', 'et', 'pour', 'avec'];
        if (strlen($firstWord) > 2 && !in_array(strtolower($firstWord), $stopWords)) {
            return $firstWord;
        }

        // Si pas trouvé, essayer d'extraire un mot significatif
        $allWords = preg_split('/\s+/', $search);
        foreach ($allWords as $word) {
            $cleanWord = preg_replace('/[^a-zA-ZÀ-ÿ0-9]/', '', $word);
            if (strlen($cleanWord) > 2 && !in_array(strtolower($cleanWord), $stopWords)) {
                if (!is_numeric($cleanWord) && !preg_match('/\d+ml/i', $cleanWord)) {
                    return $cleanWord;
                }
            }
        }

        return '';
    }

    /**
     * Recherche les concurrents asynchrone
     */
    private async function rechercherConcurrentsAsync(array $components, string $search): array
    {
        $vendorVariations = $this->getVendorVariations($components['vendor']);
        
        // Construire la requête SQL
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
        if (!empty($vendorVariations)) {
            $vendorConditions = [];
            foreach ($vendorVariations as $variation) {
                $vendorConditions[] = "lp.vendor LIKE ?";
                $params[] = '%' . $variation . '%';
            }
            $sql .= " AND (" . implode(' OR ', $vendorConditions) . ")";
        }

        // Ajouter une condition pour le premier mot du produit si trouvé
        if (!empty($components['first_product_word'])) {
            $sql .= " AND (lp.name LIKE ? OR lp.variation LIKE ?)";
            $params[] = '%' . $components['first_product_word'] . '%';
            $params[] = '%' . $components['first_product_word'] . '%';
        }

        $sql .= " ORDER BY lp.prix_ht DESC LIMIT 50";

        return DB::connection('mysql')->select($sql, $params);
    }

    /**
     * Récupère les variations d'une marque
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

        return array_unique(array_filter($variations));
    }

    /**
     * Filtre les résultats par similarité
     */
    private function filtrerParSimilarite(array $resultats, string $search, array $components): array
    {
        $resultatsFiltres = [];
        
        foreach ($resultats as $resultat) {
            $similarityScore = $this->computeOverallSimilarityImproved($resultat, $search, $components);
            
            if ($similarityScore >= $this->similarityThreshold) {
                $resultat->similarity_score = $similarityScore;
                $resultat->match_level = $this->getMatchLevel($similarityScore);
                $resultatsFiltres[] = $resultat;
            }
        }
        
        // Trier par score décroissant
        usort($resultatsFiltres, function ($a, $b) {
            return $b->similarity_score <=> $a->similarity_score;
        });
        
        return $resultatsFiltres;
    }

    /**
     * Calcule la similarité globale améliorée
     */
    private function computeOverallSimilarityImproved($product, $search, $components): float
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

        // 2. Score du premier mot du produit
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
     * Score du vendor amélioré
     */
    private function computeVendorSimilarityEnhanced($product, $searchVendor): float
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
     * Extraire le premier mot d'une chaîne
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
     * Similarité du nom améliorée
     */
    private function computeNameSimilarityImproved($productName, $search, $searchProductName): float
    {
        if (empty($productName)) {
            return 0;
        }

        $productNameLower = mb_strtolower(trim($productName));
        $searchLower = mb_strtolower(trim($search));
        $searchProductNameLower = mb_strtolower(trim($searchProductName));

        // Si le nom du produit contient le nom recherché (ou vice versa)
        if (
            str_contains($productNameLower, $searchProductNameLower) ||
            str_contains($searchProductNameLower, $productNameLower)
        ) {
            return 0.9;
        }

        // Extraire les mots clés du nom recherché
        $searchKeywords = array_filter(explode(' ', $searchProductNameLower), function ($word) {
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
     * Vérifie si un mot est un stop word
     */
    private function isStopWord(string $word): bool
    {
        $stopWords = [
            'de',
            'le',
            'la',
            'les',
            'un',
            'une',
            'des',
            'du',
            'et',
            'ou',
            'pour',
            'avec',
            'the',
            'a',
            'an',
            'and',
            'or',
            'eau',
            'ml',
            'edition',
            'édition',
            'coffret',
            'spray',
            'vapo',
            'vaporisateur'
        ];

        return in_array(mb_strtolower($word), $stopWords);
    }

    /**
     * Similarité de la variation améliorée
     */
    private function computeVariationSimilarityImproved($product, $search): float
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
     * Extrait la variation de la recherche
     */
    private function extractSearchVariationFromSearch($search): string
    {
        $pattern = '/^[^-]+\s*-\s*[^-]+\s*-\s*/i';
        $variation = preg_replace($pattern, '', $search);
        return trim($variation);
    }

    /**
     * Score des volumes
     */
    private function computeVolumeMatch($product, $searchVolumes): float
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
     * Extrait les volumes d'un texte
     */
    private function extractVolumesFromText($text): array
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

    /**
     * Score du type
     */
    private function computeTypeSimilarity($product, $search): float
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

    /**
     * Extrait le type de produit de la recherche
     */
    private function extractProductTypeFromSearch($search): string
    {
        $types = ['parfum', 'eau de parfum', 'eau de toilette', 'coffret', 'gel douche', 'lotion'];

        foreach ($types as $type) {
            if (stripos($search, $type) !== false) {
                return $type;
            }
        }

        return '';
    }

    /**
     * Calcule la similarité entre deux chaînes (Jaro-Winkler)
     */
    private function computeStringSimilarity($str1, $str2): float
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

    /**
     * Obtient le niveau de correspondance
     */
    private function getMatchLevel($similarityScore): string
    {
        if ($similarityScore >= 0.9)
            return 'excellent';
        if ($similarityScore >= 0.7)
            return 'bon';
        if ($similarityScore >= 0.6)
            return 'moyen';
        return 'faible';
    }

    /**
     * Analyse les résultats
     */
    private function analyserResultats(array $produit, array $resultats, float $ourPrice): array
    {
        if (empty($resultats)) {
            return [
                'produit' => $produit,
                'resultats' => [],
                'statistiques' => [
                    'meilleur_prix' => null,
                    'prix_moyen' => null,
                    'economie_potentielle' => 0,
                    'nombre_concurrents' => 0
                ],
                'trouve' => false
            ];
        }

        // Extraire les prix
        $prix = [];
        foreach ($resultats as $resultat) {
            $price = $this->cleanPrice($resultat->price_ht ?? $resultat->prix_ht);
            if ($price !== null) {
                $prix[] = $price;
            }
        }

        $meilleurPrix = !empty($prix) ? min($prix) : null;
        $prixMoyen = !empty($prix) ? array_sum($prix) / count($prix) : null;
        $economiePotentielle = ($meilleurPrix !== null && $ourPrice > 0) ? $ourPrice - $meilleurPrix : 0;

        return [
            'produit' => $produit,
            'resultats' => $resultats,
            'statistiques' => [
                'meilleur_prix' => $meilleurPrix,
                'prix_moyen' => $prixMoyen,
                'economie_potentielle' => $economiePotentielle,
                'nombre_concurrents' => count($resultats)
            ],
            'trouve' => true
        ];
    }

    /**
     * Nettoie un prix
     */
    private function cleanPrice($price): ?float
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

    /**
     * Sauvegarde un résultat dans la base
     */
    private function sauvegarderResultat(string $sku, array $resultat): void
    {
        try {
            ProductPriceResult::updateOrCreate(
                [
                    'comparaison_id' => $this->id,
                    'produit_sku' => $sku
                ],
                [
                    'resultats' => json_encode($resultat),
                    'statistiques' => json_encode($resultat['statistiques']),
                    'trouve' => $resultat['trouve']
                ]
            );
        } catch (\Exception $e) {
            Log::error('Erreur sauvegarde résultat: ' . $e->getMessage());
        }
    }

    /**
     * Finalise la comparaison
     */
    private function finaliserComparaison(): void
    {
        $this->comparaisonEnCours = false;
        $this->calculerStatistiquesGlobales();
        
        // Préparer les résultats détaillés
        $this->preparerResultatsDetails();
    }

    /**
     * Calcule les statistiques globales
     */
    private function calculerStatistiquesGlobales(): void
    {
        $produitsTrouves = 0;
        $produitsNonTrouves = 0;
        $economieTotale = 0;
        $prixConcurrents = [];
        $prixNotre = [];

        foreach ($this->resultatsComparaison as $sku => $resultat) {
            if ($resultat['trouve'] && !empty($resultat['resultats'])) {
                $produitsTrouves++;
                $economieTotale += $resultat['statistiques']['economie_potentielle'];
                
                // Collecter les prix pour l'analyse
                foreach ($resultat['resultats'] as $concurrent) {
                    $price = $this->cleanPrice($concurrent->price_ht ?? $concurrent->prix_ht);
                    if ($price !== null) {
                        $prixConcurrents[] = $price;
                    }
                }
                
                $ourPrice = $this->cleanPrice($resultat['produit']['price'] ?? $resultat['produit']['special_price'] ?? 0);
                if ($ourPrice !== null) {
                    $prixNotre[] = $ourPrice;
                }
            } else {
                $produitsNonTrouves++;
            }
        }

        $this->statistiques = [
            'produits_trouves' => $produitsTrouves,
            'produits_non_trouves' => $produitsNonTrouves,
            'taux_reussite' => $this->produitsTotaux > 0 
                ? round(($produitsTrouves / $this->produitsTotaux) * 100, 2)
                : 0,
            'economie_totale' => $economieTotale,
            'prix_moyen_concurrents' => !empty($prixConcurrents) 
                ? round(array_sum($prixConcurrents) / count($prixConcurrents), 2)
                : 0,
            'prix_moyen_notre' => !empty($prixNotre) 
                ? round(array_sum($prixNotre) / count($prixNotre), 2)
                : 0,
            'difference_moyenne' => !empty($prixConcurrents) && !empty($prixNotre)
                ? round(array_sum($prixNotre) / count($prixNotre) - array_sum($prixConcurrents) / count($prixConcurrents), 2)
                : 0
        ];
    }

    /**
     * Prépare les résultats détaillés pour l'affichage
     */
    private function preparerResultatsDetails(): void
    {
        $this->resultatsDetails = [];
        
        foreach ($this->resultatsComparaison as $sku => $resultat) {
            if ($resultat['trouve']) {
                $this->resultatsDetails[$sku] = [
                    'produit' => $resultat['produit'],
                    'concurrents' => array_slice($resultat['resultats'], 0, 5), // Top 5
                    'statistiques' => $resultat['statistiques']
                ];
            }
        }
    }

    /**
     * Affiche les détails d'un produit
     */
    public function afficherDetailsProduit(string $sku): void
    {
        if (isset($this->resultatsDetails[$sku])) {
            $this->showResultatsDetails = true;
        }
    }

    /**
     * Cache les détails
     */
    public function cacherDetails(): void
    {
        $this->showResultatsDetails = false;
    }

    /**
     * Analyse les prix de la liste
     */
    public function analyserPrix(): void
    {
        $this->analysePrix = $this->calculerAnalysePrix();
        $this->showAnalysePrix = true;
    }

    /**
     * Calcule l'analyse des prix
     */
    private function calculerAnalysePrix(): array
    {
        $prixConcurrents = [];
        $prixNotre = [];
        $economies = [];

        foreach ($this->resultatsComparaison as $sku => $resultat) {
            if ($resultat['trouve']) {
                $ourPrice = $this->cleanPrice($resultat['produit']['price'] ?? $resultat['produit']['special_price'] ?? 0);
                
                if ($ourPrice !== null && $resultat['statistiques']['meilleur_prix'] !== null) {
                    $prixNotre[] = $ourPrice;
                    $prixConcurrents[] = $resultat['statistiques']['meilleur_prix'];
                    $economies[] = $resultat['statistiques']['economie_potentielle'];
                }
            }
        }

        if (empty($prixNotre) || empty($prixConcurrents)) {
            return [];
        }

        return [
            'total_produits' => count($prixNotre),
            'prix_moyen_notre' => round(array_sum($prixNotre) / count($prixNotre), 2),
            'prix_moyen_concurrents' => round(array_sum($prixConcurrents) / count($prixConcurrents), 2),
            'economie_moyenne' => round(array_sum($economies) / count($economies), 2),
            'economie_totale' => round(array_sum($economies), 2),
            'produits_plus_chers' => count(array_filter($economies, fn($e) => $e < 0)),
            'produits_moins_chers' => count(array_filter($economies, fn($e) => $e > 0)),
            'produits_identique' => count(array_filter($economies, fn($e) => $e == 0))
        ];
    }

    /**
     * Cache l'analyse des prix
     */
    public function cacherAnalysePrix(): void
    {
        $this->showAnalysePrix = false;
    }

    /**
     * Récupère tous les SKU de la liste
     */
    private function getAllSkus(): array
    {
        return Cache::remember("list_skus_{$this->id}", 300, function () {
            return DetailProduct::where('list_product_id', $this->id)
                ->pluck('EAN')
                ->unique()
                ->values()
                ->toArray();
        });
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
                LEFT JOIN cataloginventory_stock_status AS stock_status ON stock_item.product_id = stock_status.product_id 
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
    <!-- JavaScript pour gérer l'async/await -->
    @script
    <script>
        // Classe pour gérer la comparaison asynchrone
        class AsyncComparateur {
            constructor(component) {
                this.component = component;
                this.isRunning = false;
                this.progress = 0;
                this.initEvents();
            }
            
            initEvents() {
                // Démarrer la comparaison asynchrone
                this.component.$wire.on('demarrer-comparaison', async (data) => {
                    await this.executerComparaison(data);
                });
                
                // Traiter un lot de produits
                this.component.$wire.on('lot-termine', async (data) => {
                    await this.traiterLotSuivant(data.lotIndex);
                });
            }
            
            async executerComparaison(data) {
                if (this.isRunning) return;
                
                this.isRunning = true;
                this.showLoading();
                
                try {
                    // Découper les produits en lots
                    const lots = this.chunkArray(data.products, data.batchSize);
                    
                    // Traiter chaque lot
                    for (let i = 0; i < lots.length; i++) {
                        await this.traiterLot(lots[i], i);
                        
                        // Petite pause entre les lots pour éviter de surcharger
                        if (i < lots.length - 1) {
                            await this.pause(100);
                        }
                    }
                    
                } catch (error) {
                    console.error('Erreur lors de la comparaison:', error);
                } finally {
                    this.isRunning = false;
                    this.hideLoading();
                }
            }
            
            async traiterLot(lot, index) {
                try {
                    // Envoyer le lot au serveur
                    await this.component.$wire.call('traiterLotProduits', lot, index);
                    
                } catch (error) {
                    console.error(`Erreur traitement lot ${index}:`, error);
                }
            }
            
            async traiterLotSuivant(index) {
                // Récupérer les produits suivants
                const products = this.component.$wire.get('products');
                const batchSize = this.component.$wire.get('batchSize');
                
                const lots = this.chunkArray(products, batchSize);
                
                if (index < lots.length) {
                    await this.traiterLot(lots[index], index);
                }
            }
            
            chunkArray(array, size) {
                const chunks = [];
                for (let i = 0; i < array.length; i += size) {
                    chunks.push(array.slice(i, i + size));
                }
                return chunks;
            }
            
            async pause(ms) {
                return new Promise(resolve => setTimeout(resolve, ms));
            }
            
            showLoading() {
                // Afficher un overlay de chargement
                const overlay = document.createElement('div');
                overlay.id = 'comparaison-loading-overlay';
                overlay.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
                overlay.innerHTML = `
                    <div class="bg-white rounded-lg p-8 shadow-xl">
                        <div class="animate-spin rounded-full h-16 w-16 border-b-2 border-blue-600 mx-auto"></div>
                        <p class="mt-4 text-lg font-semibold text-gray-800">Comparaison en cours...</p>
                        <p class="text-gray-600 mt-2" id="comparaison-progress">0%</p>
                    </div>
                `;
                document.body.appendChild(overlay);
                
                // Mettre à jour la progression
                this.component.$wire.watch('progression', (value) => {
                    const progressEl = document.getElementById('comparaison-progress');
                    if (progressEl) {
                        progressEl.textContent = `${Math.round(value)}%`;
                    }
                });
            }
            
            hideLoading() {
                const overlay = document.getElementById('comparaison-loading-overlay');
                if (overlay) {
                    overlay.remove();
                }
            }
        }
        
        // Initialiser quand Livewire est chargé
        Alpine.nextTick(() => {
            window.comparateur = new AsyncComparateur($wire);
        });
    </script>
    @endscript

    <x-header title="{{ $listTitle }}" subtitle=" Page {{ $page }} sur {{ $totalPages }} ({{ $totalItems }} produits)" separator>
        <x-slot:middle class="!justify-end">
            <x-input icon="o-bolt" placeholder="Search..." />
        </x-slot:middle>
        <x-slot:actions>
            <!-- Bouton principal avec état -->
            <x-button 
                label="Exécuter la recherche de prix concurrent" 
                class="btn-primary" 
                wire:click="executerRecherchePrix"
                wire:loading.attr="disabled"
                wire:target="executerRecherchePrix"
            />
            
            @if(!empty($resultatsComparaison))
                <x-button 
                    label="Analyser les prix" 
                    class="btn-success" 
                    wire:click="analyserPrix"
                    wire:loading.attr="disabled"
                    wire:target="analyserPrix"
                />
            @endif
        </x-slot:actions>
    </x-header>

    <!-- Section de configuration -->
    <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Seuil de similarité
                </label>
                <input 
                    type="range" 
                    min="0" 
                    max="100" 
                    wire:model.live="similarityThreshold"
                    class="w-full"
                    step="5"
                />
                <div class="text-sm text-gray-600 mt-1">
                    {{ round($similarityThreshold * 100) }}% 
                    <span class="text-xs">
                        ({{ $similarityThreshold >= 0.9 ? 'Excellente' : ($similarityThreshold >= 0.7 ? 'Bonne' : ($similarityThreshold >= 0.6 ? 'Moyenne' : 'Faible')) }} correspondance)
                    </span>
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Taille des lots
                </label>
                <select wire:model="batchSize" class="w-full border rounded-lg px-3 py-2">
                    <option value="5">5 produits/lot</option>
                    <option value="10">10 produits/lot</option>
                    <option value="20">20 produits/lot</option>
                    <option value="50">50 produits/lot</option>
                </select>
            </div>
            
            <div class="flex items-end">
                <div class="text-sm text-gray-600">
                    <div class="font-semibold">État:</div>
                    @if($comparaisonEnCours)
                        <div class="text-blue-600">Comparaison en cours...</div>
                        <div class="text-sm">{{ $produitsTraites }}/{{ $produitsTotaux }} produits traités</div>
                    @elseif(!empty($resultatsComparaison))
                        <div class="text-green-600">Comparaison terminée</div>
                    @else
                        <div class="text-gray-500">Prêt à comparer</div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Statistiques de comparaison -->
    @if(!empty($statistiques))
        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg border border-blue-200 p-4 mb-6">
            <h3 class="text-lg font-semibold text-blue-800 mb-4">Résultats de la comparaison</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-white p-4 rounded-lg shadow-sm text-center">
                    <div class="text-2xl font-bold text-blue-600">{{ $statistiques['produits_trouves'] }}</div>
                    <div class="text-sm text-gray-600">Produits trouvés</div>
                </div>
                
                <div class="bg-white p-4 rounded-lg shadow-sm text-center">
                    <div class="text-2xl font-bold text-green-600">{{ $statistiques['taux_reussite'] }}%</div>
                    <div class="text-sm text-gray-600">Taux de réussite</div>
                </div>
                
                <div class="bg-white p-4 rounded-lg shadow-sm text-center">
                    <div class="text-2xl font-bold text-purple-600">
                        {{ number_format($statistiques['economie_totale'], 2) }} €
                    </div>
                    <div class="text-sm text-gray-600">Économie potentielle</div>
                </div>
                
                <div class="bg-white p-4 rounded-lg shadow-sm text-center">
                    <div class="text-2xl font-bold text-orange-600">
                        {{ number_format($statistiques['difference_moyenne'], 2) }} €
                    </div>
                    <div class="text-sm text-gray-600">Différence moyenne</div>
                </div>
            </div>
        </div>
    @endif

    <!-- Analyse des prix -->
    @if($showAnalysePrix && !empty($analysePrix))
        <div class="bg-gradient-to-r from-green-50 to-emerald-50 rounded-lg border border-green-200 p-4 mb-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-green-800">Analyse des prix</h3>
                <button wire:click="cacherAnalysePrix" class="text-gray-500 hover:text-gray-700">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-4">
                    <div class="bg-white p-4 rounded-lg shadow-sm">
                        <h4 class="font-semibold text-gray-800 mb-3">Comparaison des prix moyens</h4>
                        <div class="space-y-2">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Notre prix moyen:</span>
                                <span class="font-bold">{{ number_format($analysePrix['prix_moyen_notre'], 2) }} €</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Prix moyen concurrents:</span>
                                <span class="font-bold">{{ number_format($analysePrix['prix_moyen_concurrents'], 2) }} €</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Différence moyenne:</span>
                                <span class="font-bold {{ $analysePrix['prix_moyen_notre'] > $analysePrix['prix_moyen_concurrents'] ? 'text-red-600' : 'text-green-600' }}">
                                    {{ number_format($analysePrix['prix_moyen_notre'] - $analysePrix['prix_moyen_concurrents'], 2) }} €
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white p-4 rounded-lg shadow-sm">
                        <h4 class="font-semibold text-gray-800 mb-3">Économies potentielles</h4>
                        <div class="space-y-2">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Économie moyenne par produit:</span>
                                <span class="font-bold {{ $analysePrix['economie_moyenne'] > 0 ? 'text-green-600' : 'text-red-600' }}">
                                    {{ number_format($analysePrix['economie_moyenne'], 2) }} €
                                </span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Économie totale:</span>
                                <span class="font-bold {{ $analysePrix['economie_totale'] > 0 ? 'text-green-600' : 'text-red-600' }}">
                                    {{ number_format($analysePrix['economie_totale'], 2) }} €
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white p-4 rounded-lg shadow-sm">
                    <h4 class="font-semibold text-gray-800 mb-3">Répartition des produits</h4>
                    <div class="space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <div class="w-3 h-3 bg-red-100 border border-red-300 rounded mr-2"></div>
                                <span>Plus chers que les concurrents:</span>
                            </div>
                            <span class="font-bold text-red-600">{{ $analysePrix['produits_plus_chers'] }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <div class="w-3 h-3 bg-green-100 border border-green-300 rounded mr-2"></div>
                                <span>Moins chers que les concurrents:</span>
                            </div>
                            <span class="font-bold text-green-600">{{ $analysePrix['produits_moins_chers'] }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <div class="w-3 h-3 bg-blue-100 border border-blue-300 rounded mr-2"></div>
                                <span>Prix identiques:</span>
                            </div>
                            <span class="font-bold text-blue-600">{{ $analysePrix['produits_identique'] }}</span>
                        </div>
                        <div class="pt-3 border-t">
                            <div class="text-sm text-gray-600">
                                Total analysé: {{ $analysePrix['total_produits'] }} produits
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Table des produits avec résultats de comparaison -->
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
                    <th>Résultat comparaison</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @if($loading)
                    <!-- État de chargement initial -->
                    <tr>
                        <td colspan="11" class="text-center py-12">
                            <div class="flex flex-col items-center gap-3">
                                <span class="loading loading-spinner loading-lg text-primary"></span>
                                <span class="text-lg">Chargement des produits...</span>
                            </div>
                        </td>
                    </tr>
                @elseif(count($products) === 0 && !$loading)
                    <!-- Aucun produit -->
                    <tr>
                        <td colspan="11" class="text-center py-12 text-base-content/50">
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
                            $sku = $product['sku'] ?? '';
                            $resultat = $resultatsComparaison[$sku] ?? null;
                            $hasResult = $resultat && $resultat['trouve'];
                            $concurrentCount = $hasResult ? $resultat['statistiques']['nombre_concurrents'] : 0;
                            $meilleurPrix = $hasResult ? $resultat['statistiques']['meilleur_prix'] : null;
                            $ourPrice = $product['special_price'] > 0 ? $product['special_price'] : $product['price'];
                            $economie = $meilleurPrix ? $ourPrice - $meilleurPrix : 0;
                        @endphp
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
                            <td>
                                @if($hasResult)
                                    <div class="space-y-1">
                                        <div class="text-xs">
                                            <span class="font-semibold {{ $concurrentCount > 0 ? 'text-success' : 'text-error' }}">
                                                {{ $concurrentCount }} concurrent(s)
                                            </span>
                                        </div>
                                        @if($meilleurPrix)
                                            <div class="text-xs">
                                                Meilleur prix: 
                                                <span class="font-bold {{ $meilleurPrix < $ourPrice ? 'text-success' : 'text-error' }}">
                                                    {{ number_format($meilleurPrix, 2) }} €
                                                </span>
                                            </div>
                                            <div class="text-xs">
                                                Économie: 
                                                <span class="font-bold {{ $economie > 0 ? 'text-success' : ($economie < 0 ? 'text-error' : 'text-info') }}">
                                                    {{ number_format($economie, 2) }} €
                                                </span>
                                            </div>
                                        @endif
                                    </div>
                                @elseif($comparaisonEnCours)
                                    <span class="text-xs text-warning">En cours...</span>
                                @else
                                    <span class="text-xs text-gray-500">Non comparé</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex space-x-2">
                                    @if($hasResult && isset($resultatsDetails[$sku]))
                                        <button 
                                            wire:click="afficherDetailsProduit('{{ $sku }}')"
                                            class="btn btn-xs btn-info"
                                            wire:loading.attr="disabled"
                                        >
                                            Voir
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                @endif
            </tbody>
        </table>
    </div>
    
    <!-- Modal pour les détails d'un produit -->
    @if($showResultatsDetails && !empty($resultatsDetails))
        @foreach($resultatsDetails as $sku => $details)
            <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4" 
                 wire:key="modal-{{ $sku }}"
                 x-data="{ open: true }"
                 x-show="open"
                 x-on:keydown.escape.window="open = false"
                 x-on:click.self="open = false">
                <div class="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] overflow-hidden">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-xl font-semibold text-gray-900">
                                Détails de la comparaison - {{ $details['produit']['title'] ?? 'Produit' }}
                            </h3>
                            <button 
                                wire:click="cacherDetails"
                                class="text-gray-500 hover:text-gray-700"
                                @click="open = false"
                            >
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        </div>
                        
                        <!-- Statistiques du produit -->
                        <div class="bg-gray-50 rounded-lg p-4 mb-6">
                            <h4 class="font-semibold text-gray-800 mb-3">Résumé</h4>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <div class="text-center">
                                    <div class="text-2xl font-bold text-blue-600">
                                        {{ $details['statistiques']['nombre_concurrents'] }}
                                    </div>
                                    <div class="text-sm text-gray-600">Concurrents trouvés</div>
                                </div>
                                <div class="text-center">
                                    <div class="text-2xl font-bold text-green-600">
                                        {{ number_format($details['statistiques']['meilleur_prix'] ?? 0, 2) }} €
                                    </div>
                                    <div class="text-sm text-gray-600">Meilleur prix</div>
                                </div>
                                <div class="text-center">
                                    <div class="text-2xl font-bold text-purple-600">
                                        {{ number_format($details['statistiques']['prix_moyen'] ?? 0, 2) }} €
                                    </div>
                                    <div class="text-sm text-gray-600">Prix moyen</div>
                                </div>
                                <div class="text-center">
                                    <div class="text-2xl font-bold {{ $details['statistiques']['economie_potentielle'] > 0 ? 'text-green-600' : 'text-red-600' }}">
                                        {{ number_format($details['statistiques']['economie_potentielle'], 2) }} €
                                    </div>
                                    <div class="text-sm text-gray-600">Économie potentielle</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Liste des concurrents -->
                        <div class="overflow-y-auto max-h-[60vh]">
                            <h4 class="font-semibold text-gray-800 mb-3">Concurrents trouvés</h4>
                            <div class="space-y-3">
                                @foreach($details['concurrents'] as $concurrent)
                                    @php
                                        $price = $this->cleanPrice($concurrent->price_ht ?? $concurrent->prix_ht);
                                        $ourPrice = $this->cleanPrice($details['produit']['price'] ?? $details['produit']['special_price'] ?? 0);
                                        $difference = $price ? $ourPrice - $price : 0;
                                        $score = $concurrent->similarity_score ?? 0;
                                        $matchLevel = $concurrent->match_level ?? 'faible';
                                    @endphp
                                    <div class="border rounded-lg p-4 hover:bg-gray-50">
                                        <div class="flex justify-between items-start">
                                            <div class="flex-1">
                                                <div class="flex items-center mb-2">
                                                    <span class="font-medium text-gray-900 mr-3">{{ $concurrent->name ?? 'N/A' }}</span>
                                                    <span class="text-xs px-2 py-1 rounded-full 
                                                        {{ $matchLevel === 'excellent' ? 'bg-green-100 text-green-800 border-green-300' : 
                                                           ($matchLevel === 'bon' ? 'bg-blue-100 text-blue-800 border-blue-300' : 
                                                           ($matchLevel === 'moyen' ? 'bg-yellow-100 text-yellow-800 border-yellow-300' : 
                                                           'bg-gray-100 text-gray-800 border-gray-300')) }}">
                                                        {{ ucfirst($matchLevel) }} ({{ round($score * 100) }}%)
                                                    </span>
                                                </div>
                                                <div class="text-sm text-gray-600 mb-2">
                                                    {{ $concurrent->vendor ?? 'N/A' }} - 
                                                    {{ $concurrent->variation ?? 'Standard' }}
                                                </div>
                                                <div class="text-sm">
                                                    <span class="font-semibold {{ $price < $ourPrice ? 'text-green-600' : 'text-red-600' }}">
                                                        {{ number_format($price ?? 0, 2) }} €
                                                    </span>
                                                    <span class="text-gray-500 ml-2">
                                                        Différence: 
                                                        <span class="{{ $difference > 0 ? 'text-green-600' : ($difference < 0 ? 'text-red-600' : 'text-blue-600') }}">
                                                            {{ number_format($difference, 2) }} €
                                                        </span>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="ml-4">
                                                <a 
                                                    href="{{ $concurrent->product_url ?? $concurrent->url ?? '#' }}" 
                                                    target="_blank"
                                                    class="btn btn-xs btn-outline"
                                                >
                                                    Voir
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    @endif
    
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