<?php

use Livewire\Volt\Component;
use App\Models\Product;
use App\Models\Site;
use Illuminate\Support\Facades\Http;

new class extends Component {

    public string $productName = '';
    public $productPrice;
    public $productId;
    public $extractedData = null;
    public $isLoading = false;
    public $matchingProducts = [];
    public $bestMatch = null;
    public $aiValidation = null;
    public $availableSites = [];
    public $selectedSites = [];
    public $groupedResults = [];

    public function mount($name, $id, $price): void
    {
        $this->productName = $name;
        $this->productId = $id;
        $this->productPrice = $price;

        // Récupérer tous les sites disponibles
        $this->availableSites = Site::orderBy('name')->get()->toArray();

        // Par défaut, tous les sites sont sélectionnés
        $this->selectedSites = collect($this->availableSites)->pluck('id')->toArray();
    }

    public function extractSearchTerme()
    {
        $this->isLoading = true;
        $this->extractedData = null;
        $this->matchingProducts = [];
        $this->bestMatch = null;
        $this->aiValidation = null;
        $this->groupedResults = [];

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            ])->post('https://api.openai.com/v1/chat/completions', [
                        'model' => 'gpt-4o-mini',
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => 'Tu es un expert en extraction de données de produits cosmétiques. Sépare bien le type principal des détails du type. Réponds UNIQUEMENT avec un objet JSON valide, sans markdown ni texte supplémentaire.'
                            ],
                            [
                                'role' => 'user',
                                'content' => "Extrait les informations suivantes du nom de produit et retourne-les au format JSON strict :

RÈGLES IMPORTANTES :
- vendor : la marque du produit (ex: Dior, Shiseido, Chanel, Lancôme)
- name : le nom de la gamme/ligne de produit UNIQUEMENT (ex: \"J'adore\", \"Vital Perfection\", \"La Nuit Trésor\")
- type : la catégorie principale (ex: \"Eau de Parfum\", \"Crème\", \"Sérum\", \"Huile\")
- type_details : TOUS les qualificatifs du type séparés par des virgules (ex: \"Intense, Vaporisateur\", \"Scintillante, pour le corps\", \"Enriched\")
- variation : la contenance/taille avec unité (ex: \"200 ml\", \"50 ml\", \"30 g\")
- is_coffret : true si c'est un coffret/set/kit, false sinon

Nom du produit : {$this->productName}

EXEMPLES DE FORMAT ATTENDU :

Exemple 1 - Produit : \"Lancôme La Nuit Trésor Rouge Drama Eau de Parfum Intense Vaporisateur 30ml\"
{
  \"vendor\": \"Lancôme\",
  \"name\": \"La Nuit Trésor Rouge Drama\",
  \"type\": \"Eau de Parfum\",
  \"type_details\": \"Intense, Vaporisateur\",
  \"variation\": \"30 ml\",
  \"is_coffret\": false
}

Exemple 2 - Produit : \"Dior J'adore Les Adorables Huile Scintillante Huile pour le corps 200ml\"
{
  \"vendor\": \"Dior\",
  \"name\": \"J'adore Les Adorables\",
  \"type\": \"Huile\",
  \"type_details\": \"Scintillante, pour le corps\",
  \"variation\": \"200 ml\",
  \"is_coffret\": false
}

Exemple 3 - Produit : \"Chanel N°5 Eau de Parfum Vaporisateur 100 ml\"
{
  \"vendor\": \"Chanel\",
  \"name\": \"N°5\",
  \"type\": \"Eau de Parfum\",
  \"type_details\": \"Vaporisateur\",
  \"variation\": \"100 ml\",
  \"is_coffret\": false
}

Exemple 4 - Produit : \"Shiseido Vital Perfection Uplifting and Firming Cream Enriched 50ml\"
{
  \"vendor\": \"Shiseido\",
  \"name\": \"Vital Perfection Uplifting and Firming\",
  \"type\": \"Crème\",
  \"type_details\": \"Enriched, visage\",
  \"variation\": \"50 ml\",
  \"is_coffret\": false
}"
                            ]
                        ],
                        'temperature' => 0.3,
                        'max_tokens' => 500
                    ]);

            if ($response->successful()) {
                $data = $response->json();
                $content = $data['choices'][0]['message']['content'];

                // Nettoyer le contenu
                $content = preg_replace('/```json\s*|\s*```/', '', $content);
                $content = trim($content);

                $decodedData = json_decode($content, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    \Log::error('Erreur parsing JSON OpenAI', [
                        'content' => $content,
                        'error' => json_last_error_msg()
                    ]);
                    throw new \Exception('Erreur de parsing JSON: ' . json_last_error_msg());
                }

                // Valider que les données essentielles existent
                if (empty($decodedData) || !is_array($decodedData)) {
                    throw new \Exception('Les données extraites sont vides ou invalides');
                }

                $this->extractedData = array_merge([
                    'vendor' => '',
                    'name' => '',
                    'variation' => '',
                    'type' => '',
                    'type_details' => '',
                    'is_coffret' => false
                ], $decodedData);

                \Log::info('Données extraites', [
                    'vendor' => $this->extractedData['vendor'] ?? '',
                    'name' => $this->extractedData['name'] ?? '',
                    'type' => $this->extractedData['type'] ?? '',
                    'type_details' => $this->extractedData['type_details'] ?? '',
                    'variation' => $this->extractedData['variation'] ?? '',
                    'is_coffret' => $this->extractedData['is_coffret'] ?? false
                ]);

                // Rechercher les produits correspondants
                $this->searchMatchingProducts();

            } else {
                $errorBody = $response->body();
                \Log::error('Erreur API OpenAI', [
                    'status' => $response->status(),
                    'body' => $errorBody
                ]);
                throw new \Exception('Erreur API OpenAI: ' . $response->status() . ' - ' . $errorBody);
            }

        } catch (\Exception $e) {
            \Log::error('Erreur extraction', [
                'message' => $e->getMessage(),
                'product_name' => $this->productName
            ]);

            session()->flash('error', 'Erreur lors de l\'extraction: ' . $e->getMessage());
        } finally {
            $this->isLoading = false;
        }
    }

    /**
     * Vérifie si un produit est un coffret
     */
    private function isCoffret($product): bool
    {
        $cofferKeywords = ['coffret', 'set', 'kit', 'duo', 'trio', 'collection'];

        $nameCheck = false;
        $typeCheck = false;

        // Vérifier dans le name
        if (isset($product['name'])) {
            $nameLower = mb_strtolower($product['name']);
            foreach ($cofferKeywords as $keyword) {
                if (str_contains($nameLower, $keyword)) {
                    $nameCheck = true;
                    break;
                }
            }
        }

        // Vérifier dans le type
        if (isset($product['type'])) {
            $typeLower = mb_strtolower($product['type']);
            foreach ($cofferKeywords as $keyword) {
                if (str_contains($typeLower, $keyword)) {
                    $typeCheck = true;
                    break;
                }
            }
        }

        return $nameCheck || $typeCheck;
    }

    /**
     * NOUVELLE LOGIQUE DE RECHERCHE
     * 1. Recherche LARGE : tous les produits de la base (filtrés par sites sélectionnés)
     * 2. Scoring progressif avec critères en cascade :
     *    - VENDOR match
     *    - NAME match (tous les mots)
     *    - TYPE PRINCIPAL match
     *    - TYPE DETAILS match étape par étape
     *    - VARIATION match
     *    - BONUS COFFRET
     */
    private function searchMatchingProducts()
    {
        // Vérifier que extractedData est valide
        if (empty($this->extractedData) || !is_array($this->extractedData)) {
            \Log::warning('searchMatchingProducts: extractedData invalide', [
                'extractedData' => $this->extractedData
            ]);
            return;
        }

        // S'assurer que toutes les clés existent avec des valeurs par défaut
        $extractedData = array_merge([
            'vendor' => '',
            'name' => '',
            'variation' => '',
            'type' => '',
            'type_details' => '',
            'is_coffret' => false
        ], $this->extractedData);

        $vendor = $extractedData['vendor'] ?? '';
        $name = $extractedData['name'] ?? '';
        $type = $extractedData['type'] ?? '';
        $typeDetails = $extractedData['type_details'] ?? '';
        $variation = $extractedData['variation'] ?? '';
        $isCoffretSource = $extractedData['is_coffret'] ?? false;

        // Extraire les mots-clés
        $vendorWords = $this->extractKeywords($vendor);
        $nameWords = $this->extractKeywords($name);
        $typeWords = $this->extractKeywords($type);
        
        // Extraire les détails du type comme liste
        $typeDetailsList = [];
        if (!empty($typeDetails)) {
            $typeDetailsList = array_map('trim', explode(',', mb_strtolower($typeDetails)));
            $typeDetailsList = array_filter($typeDetailsList);
        }

        \Log::info('Critères de recherche extraits', [
            'vendor' => $vendor,
            'vendorWords' => $vendorWords,
            'name' => $name,
            'nameWords' => $nameWords,
            'type' => $type,
            'typeWords' => $typeWords,
            'typeDetails' => $typeDetails,
            'typeDetailsList' => $typeDetailsList,
            'variation' => $variation,
            'isCoffret' => $isCoffretSource
        ]);

        // ÉTAPE 1: Recherche LARGE - tous les produits filtrés par sites sélectionnés
        $allProducts = Product::query()
            ->when(!empty($this->selectedSites), function ($q) {
                $q->whereIn('web_site_id', $this->selectedSites);
            })
            ->orderByDesc('id')
            ->limit(5000) // Limite raisonnable pour éviter la surcharge
            ->get();

        if ($allProducts->isEmpty()) {
            \Log::info('Aucun produit trouvé dans la base');
            return;
        }

        \Log::info('Produits récupérés pour le scoring', [
            'count' => $allProducts->count()
        ]);

        // ÉTAPE 2: Filtrer par statut coffret EN PREMIER
        $filteredProducts = $this->filterByCoffretStatus($allProducts, $isCoffretSource);

        if (empty($filteredProducts)) {
            \Log::info('Aucun produit après filtrage coffret');
            return;
        }

        \Log::info('Produits après filtrage coffret', [
            'count' => count($filteredProducts)
        ]);

        // ÉTAPE 3: SCORING PROGRESSIF
        $scoredProducts = collect($filteredProducts)->map(function ($product) use (
            $vendor, $vendorWords, 
            $name, $nameWords, 
            $type, $typeWords, 
            $typeDetailsList,
            $variation,
            $isCoffretSource
        ) {
            $score = 0;
            $debug = [
                'id' => $product['id'] ?? 0,
                'product_name' => $product['name'] ?? '',
                'product_type' => $product['type'] ?? '',
                'breakdown' => []
            ];
            
            $productVendor = mb_strtolower($product['vendor'] ?? '');
            $productName = mb_strtolower($product['name'] ?? '');
            $productType = mb_strtolower($product['type'] ?? '');
            $productVariation = mb_strtolower($product['variation'] ?? '');

            // ==========================================
            // 1. VENDOR MATCH (très important)
            // ==========================================
            $vendorScore = 0;
            $vendorLower = mb_strtolower($vendor);
            
            if (str_contains($productVendor, $vendorLower)) {
                $vendorScore = 100; // Match exact du vendor
            } else {
                // Vérifier mot par mot
                $vendorMatchCount = 0;
                foreach ($vendorWords as $word) {
                    if (str_contains($productVendor, $word)) {
                        $vendorMatchCount++;
                    }
                }
                if ($vendorMatchCount > 0) {
                    $vendorScore = 50 + ($vendorMatchCount * 10);
                }
            }
            
            $score += $vendorScore;
            $debug['breakdown']['vendor'] = $vendorScore;

            // ==========================================
            // 2. NAME MATCH (important)
            // ==========================================
            $nameScore = 0;
            $nameMatchCount = 0;
            
            if (!empty($nameWords)) {
                foreach ($nameWords as $word) {
                    if (str_contains($productName, $word)) {
                        $nameMatchCount++;
                    }
                }
                
                $nameMatchRatio = count($nameWords) > 0 ? $nameMatchCount / count($nameWords) : 0;
                
                if ($nameMatchRatio === 1.0) {
                    $nameScore = 150; // Tous les mots du name matchent
                } elseif ($nameMatchRatio >= 0.5) {
                    $nameScore = 75 + ($nameMatchCount * 15); // Au moins 50% des mots
                } else {
                    $nameScore = $nameMatchCount * 20; // Quelques mots
                }
            }
            
            $score += $nameScore;
            $debug['breakdown']['name'] = [
                'score' => $nameScore,
                'matched' => $nameMatchCount,
                'total' => count($nameWords)
            ];

            // ==========================================
            // 3. TYPE PRINCIPAL MATCH (très important)
            // ==========================================
            $typeScore = 0;
            $typeLower = mb_strtolower(trim($type));
            
            // Vérifier si le type principal est présent
            if (!empty($typeLower) && str_contains($productType, $typeLower)) {
                $typeScore = 200; // ÉNORME bonus si type principal match
                
                // Bonus si c'est au début
                if (str_starts_with($productType, $typeLower)) {
                    $typeScore += 50;
                }
            } else {
                // Sinon vérifier mot par mot
                $typeMatchCount = 0;
                foreach ($typeWords as $word) {
                    if (str_contains($productType, $word)) {
                        $typeMatchCount++;
                    }
                }
                
                if ($typeMatchCount > 0) {
                    $typeScore = 50 + ($typeMatchCount * 25);
                }
            }
            
            $score += $typeScore;
            $debug['breakdown']['type_principal'] = $typeScore;

            // ==========================================
            // 4. TYPE DETAILS MATCH PROGRESSIF (vérification étape par étape)
            // ==========================================
            $typeDetailsScore = 0;
            $detailsMatched = [];
            
            if (!empty($typeDetailsList)) {
                foreach ($typeDetailsList as $detail) {
                    $detail = trim($detail);
                    if (empty($detail)) continue;
                    
                    // Vérifier si ce détail est présent dans le type du produit
                    if (str_contains($productType, $detail)) {
                        $typeDetailsScore += 50; // +50 pour chaque détail qui match
                        $detailsMatched[] = $detail;
                    }
                }
                
                // BONUS si tous les détails matchent
                if (count($detailsMatched) === count($typeDetailsList)) {
                    $typeDetailsScore += 100;
                }
            }
            
            $score += $typeDetailsScore;
            $debug['breakdown']['type_details'] = [
                'score' => $typeDetailsScore,
                'matched' => $detailsMatched,
                'total' => $typeDetailsList
            ];

            // ==========================================
            // 5. VARIATION MATCH
            // ==========================================
            $variationScore = 0;
            $variationLower = mb_strtolower(trim($variation));
            
            if (!empty($variationLower) && str_contains($productVariation, $variationLower)) {
                $variationScore = 30;
            }
            
            $score += $variationScore;
            $debug['breakdown']['variation'] = $variationScore;

            // ==========================================
            // 6. BONUS COFFRET
            // ==========================================
            $coffretBonus = 0;
            $productIsCoffret = $this->isCoffret($product);
            
            if ($isCoffretSource && $productIsCoffret) {
                $coffretBonus = 300; // ÉNORME BONUS pour coffret
            }
            
            $score += $coffretBonus;
            $debug['breakdown']['coffret_bonus'] = $coffretBonus;
            $debug['total_score'] = $score;

            return [
                'product' => $product,
                'score' => $score,
                'debug' => $debug,
                'is_coffret' => $productIsCoffret
            ];
        })
        // Trier par score décroissant
        ->sortByDesc('score')
        ->values();

        \Log::info('Scoring détaillé complet', [
            'total_products_scored' => $scoredProducts->count(),
            'top_20_scores' => $scoredProducts->take(20)->map(function($item) {
                return $item['debug'];
            })->toArray()
        ]);

        // Ne garder que ceux qui ont un score > 0
        $scoredProducts = $scoredProducts->filter(fn($item) => $item['score'] > 0);

        if ($scoredProducts->isEmpty()) {
            \Log::info('Aucun produit avec score > 0');
            return;
        }

        // Extraire uniquement les produits des résultats scorés
        $rankedProducts = $scoredProducts->pluck('product')->toArray();

        // Limiter à 100 résultats
        $this->matchingProducts = array_slice($rankedProducts, 0, 100);

        \Log::info('Résultats finaux', [
            'count' => count($this->matchingProducts),
            'best_score' => $scoredProducts->first()['score'] ?? 0,
            'worst_score' => $scoredProducts->last()['score'] ?? 0
        ]);

        // Grouper et valider avec l'IA
        $this->groupResultsByScrapeReference($this->matchingProducts);
        $this->validateBestMatchWithAI();
    }

    /**
     * Organise les résultats pour afficher TOUS les produits qui matchent par site
     * Tri : par scrape_reference_id décroissant (les plus récents en premier)
     */
    private function groupResultsByScrapeReference(array $products)
    {
        if (empty($products)) {
            $this->matchingProducts = [];
            $this->groupedResults = [];
            return;
        }

        // Convertir en collection
        $productsCollection = collect($products)->map(function ($product) {
            return array_merge([
                'scrape_reference' => 'unknown_' . ($product['id'] ?? uniqid()),
                'scrape_reference_id' => $product['scrape_reference_id'] ?? 0,
                'web_site_id' => 0,
                'id' => 0
            ], $product);
        });

        \Log::info('Avant tri des résultats', [
            'total_produits' => $productsCollection->count()
        ]);

        // GARDER TOUS LES PRODUITS, juste les trier par scrape_reference_id décroissant
        // Cela met les produits les plus récents en premier pour chaque site
        $sortedProducts = $productsCollection
            ->sortByDesc('scrape_reference_id')
            ->values();

        // Limiter à 100 résultats maximum pour éviter la surcharge
        $this->matchingProducts = $sortedProducts->take(100)->toArray();

        \Log::info('Résultats après tri par scrape_reference_id', [
            'total_produits' => count($this->matchingProducts),
            'par_site' => $sortedProducts->groupBy('web_site_id')->map(fn($group) => $group->count())->toArray()
        ]);

        // ÉTAPE 2: Grouper par scrape_reference pour les statistiques
        $grouped = $productsCollection->groupBy('scrape_reference');

        // Grouper aussi par site pour les statistiques
        $bySiteStats = $productsCollection->groupBy('web_site_id')->map(function ($siteProducts, $siteId) {
            return [
                'site_id' => $siteId,
                'total_products' => $siteProducts->count(),
                'max_scrape_ref_id' => $siteProducts->max('scrape_reference_id'),
                'min_scrape_ref_id' => $siteProducts->min('scrape_reference_id'),
                'products' => $siteProducts->sortByDesc('scrape_reference_id')->values()->toArray()
            ];
        });

        // Stocker les résultats groupés pour l'affichage des statistiques
        $this->groupedResults = $grouped->map(function ($group, $reference) {
            // Groupe par site pour les statistiques
            $bySite = $group->groupBy('web_site_id')->map(function ($siteProducts) {
                // Pour chaque site, garde tous les produits triés par scrape_reference_id
                $sortedSiteProducts = $siteProducts->sortByDesc('scrape_reference_id')->values();
                return [
                    'count' => $siteProducts->count(),
                    'products' => $sortedSiteProducts->toArray(),
                    'max_scrape_ref_id' => $siteProducts->max('scrape_reference_id'),
                    'lowest_price' => $siteProducts->min('prix_ht'),
                    'highest_price' => $siteProducts->max('prix_ht'),
                ];
            });

            return [
                'reference' => $reference,
                'total_count' => $group->count(),
                'sites_count' => $bySite->count(),
                'sites' => $bySite->map(function ($siteData, $siteId) {
                    return [
                        'site_id' => $siteId,
                        'products_count' => $siteData['count'],
                        'max_scrape_ref_id' => $siteData['max_scrape_ref_id'],
                        'price_range' => [
                            'min' => $siteData['lowest_price'],
                            'max' => $siteData['highest_price']
                        ],
                        'variations_count' => $siteData['count']
                    ];
                })->values()->toArray(),
                'best_price' => $group->min('prix_ht'),
                'site_ids' => $group->pluck('web_site_id')->unique()->values()->toArray()
            ];
        })->toArray();

        // Ajouter les stats par site
        $this->groupedResults['_site_stats'] = $bySiteStats->toArray();
    }

    /**
     * Extrait les mots-clés significatifs d'une chaîne
     */
    private function extractKeywords(string $text): array
    {
        if (empty($text)) {
            return [];
        }

        // Mots à ignorer (stop words)
        $stopWords = ['de', 'la', 'le', 'les', 'des', 'du', 'un', 'une', 'et', 'ou', 'pour', 'avec', 'sans'];

        // Nettoyer et découper
        $text = mb_strtolower($text);
        $words = preg_split('/[\s\-]+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        // Filtrer les mots courts et les stop words
        $keywords = array_filter($words, function ($word) use ($stopWords) {
            return mb_strlen($word) >= 3 && !in_array($word, $stopWords);
        });

        return array_values($keywords);
    }

    /**
     * Filtre les produits selon leur statut coffret
     */
    private function filterByCoffretStatus($products, bool $sourceisCoffret): array
    {
        return $products->filter(function ($product) use ($sourceisCoffret) {
            $productIsCoffret = $this->isCoffret($product->toArray());

            // Si la source est un coffret, garder seulement les coffrets
            // Si la source n'est pas un coffret, exclure les coffrets
            return $sourceisCoffret ? $productIsCoffret : !$productIsCoffret;
        })->values()->toArray();
    }

    /**
     * Utilise OpenAI pour valider le meilleur match
     */
    private function validateBestMatchWithAI()
    {
        if (empty($this->matchingProducts)) {
            return;
        }

        // Préparer les données pour l'IA
        $candidateProducts = array_slice($this->matchingProducts, 0, 5); // Max 5 produits

        $productsInfo = array_map(function ($product) {
            return [
                'id' => $product['id'] ?? 0,
                'vendor' => $product['vendor'] ?? '',
                'name' => $product['name'] ?? '',
                'type' => $product['type'] ?? '',
                'variation' => $product['variation'] ?? '',
                'prix_ht' => $product['prix_ht'] ?? 0
            ];
        }, $candidateProducts);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            ])->timeout(15)->post('https://api.openai.com/v1/chat/completions', [
                        'model' => 'gpt-4o-mini',
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => 'Tu es un expert en matching de produits cosmétiques. Tu dois analyser la correspondance entre un produit source et une liste de candidats, puis retourner l\'ID du meilleur match avec un score de confiance. Réponds UNIQUEMENT avec un objet JSON.'
                            ],
                            [
                                'role' => 'user',
                                'content' => "Produit source : {$this->productName}

Critères extraits :
- Vendor: " . ($this->extractedData['vendor'] ?? 'N/A') . "
- Name: " . ($this->extractedData['name'] ?? 'N/A') . "
- Type: " . ($this->extractedData['type'] ?? 'N/A') . "
- Type Details: " . ($this->extractedData['type_details'] ?? 'N/A') . "
- Variation: " . ($this->extractedData['variation'] ?? 'N/A') . "

Produits candidats :
" . json_encode($productsInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "

Analyse chaque candidat et détermine le meilleur match. Retourne au format JSON :
{
  \"best_match_id\": 123,
  \"confidence_score\": 0.95,
  \"reasoning\": \"Explication courte du choix\",
  \"all_scores\": [
    {\"id\": 123, \"score\": 0.95, \"reason\": \"...\"},
    {\"id\": 124, \"score\": 0.60, \"reason\": \"...\"}
  ]
}

Critères de scoring :
- Vendor exact = +40 points
- Name similaire = +30 points
- Type identique = +20 points
- Type details matchent = +10 points (chacun)
- Variation identique = +10 points
Score de confiance entre 0 et 1."
                            ]
                        ],
                        'temperature' => 0.2,
                        'max_tokens' => 800
                    ]);

            if ($response->successful()) {
                $data = $response->json();
                $content = $data['choices'][0]['message']['content'];

                // Nettoyer le contenu
                $content = preg_replace('/```json\s*|\s*```/', '', $content);
                $content = trim($content);

                $this->aiValidation = json_decode($content, true);

                if ($this->aiValidation && isset($this->aiValidation['best_match_id'])) {
                    // Trouver le produit correspondant à l'ID recommandé par l'IA
                    $bestMatchId = $this->aiValidation['best_match_id'];
                    $found = collect($this->matchingProducts)->firstWhere('id', $bestMatchId);

                    if ($found) {
                        $this->bestMatch = $found;
                    } else {
                        // Fallback sur le premier résultat (le mieux scoré)
                        $this->bestMatch = $this->matchingProducts[0] ?? null;
                    }
                } else {
                    // Fallback sur le premier résultat (le mieux scoré)
                    $this->bestMatch = $this->matchingProducts[0] ?? null;
                }
            }

        } catch (\Exception $e) {
            \Log::error('Erreur validation IA', [
                'message' => $e->getMessage(),
                'product_name' => $this->productName
            ]);

            // Fallback sur le premier résultat en cas d'erreur (le mieux scoré)
            $this->bestMatch = $this->matchingProducts[0] ?? null;
        }
    }

    public function selectProduct($productId)
    {
        $product = Product::find($productId);

        if ($product) {
            session()->flash('success', 'Produit sélectionné : ' . $product->name);
            $this->bestMatch = $product->toArray();

            // Émettre un événement si besoin
            $this->dispatch('product-selected', productId: $productId);
        }
    }

    /**
     * Rafraîchir les résultats quand on change les sites sélectionnés
     */
    public function updatedSelectedSites()
    {
        if (!empty($this->extractedData)) {
            $this->searchMatchingProducts();
        }
    }

    /**
     * Sélectionner/désélectionner tous les sites
     */
    public function toggleAllSites()
    {
        if (count($this->selectedSites) === count($this->availableSites)) {
            $this->selectedSites = [];
        } else {
            $this->selectedSites = collect($this->availableSites)->pluck('id')->toArray();
        }

        if (!empty($this->extractedData)) {
            $this->searchMatchingProducts();
        }
    }

}; ?>

<div class="bg-white">
    <!-- Header avec le bouton de recherche -->
    <div class="px-6 py-4 border-b border-gray-200">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-bold text-gray-900">Recherche de produit</h2>
            <button wire:click="extractSearchTerme" wire:loading.attr="disabled"
                class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 font-medium shadow-sm">
                <span wire:loading.remove>Extraire et rechercher</span>
                <span wire:loading>Extraction en cours...</span>
            </button>
        </div>
    </div>

    <livewire:plateformes.detail :id="$productId" />

    <!-- Filtres par site -->
    @if(!empty($availableSites))
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-semibold text-gray-700">Filtrer par site</h3>
                <button wire:click="toggleAllSites" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">
                    {{ count($selectedSites) === count($availableSites) ? 'Tout désélectionner' : 'Tout sélectionner' }}
                </button>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2">
                @foreach($availableSites as $site)
                    <label class="flex items-center space-x-2 cursor-pointer hover:bg-gray-100 p-2 rounded">
                        <input type="checkbox" wire:model.live="selectedSites" value="{{ $site['id'] }}"
                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        <span class="text-sm">{{ $site['name'] }}</span>
                    </label>
                @endforeach
            </div>
            <p class="text-xs text-gray-500 mt-2">
                {{ count($selectedSites) }} site(s) sélectionné(s)
            </p>
        </div>
    @endif

    @if(session('error'))
        <div class="mx-6 mt-4 p-4 bg-red-100 text-red-700 rounded-lg">
            {{ session('error') }}
        </div>
    @endif

    @if(session('success'))
        <div class="mx-6 mt-4 p-4 bg-green-100 text-green-700 rounded-lg">
            {{ session('success') }}
        </div>
    @endif

    <!-- Contenu principal -->
    <div class="p-6">
        <!-- Statistiques -->
        @if(!empty($groupedResults))
            <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <p class="text-sm text-blue-800">
                    <span class="font-semibold">{{ count($matchingProducts) }}</span> produit(s) trouvé(s)
                </p>
                @if(isset($groupedResults['_site_stats']))
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach($groupedResults['_site_stats'] as $siteId => $stats)
                            @php
                                $siteInfo = collect($availableSites)->firstWhere('id', $siteId);
                            @endphp
                            @if($siteInfo)
                                <span class="px-2 py-1 bg-white border border-blue-300 rounded text-xs">
                                    <span class="font-semibold">{{ $siteInfo['name'] }}</span>: 
                                    <span class="text-blue-700 font-bold">{{ $stats['total_products'] }}</span>
                                </span>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        <!-- Section des produits -->
        @if(!empty($matchingProducts))
            <div class="mx-auto max-w-7xl overflow-hidden sm:px-6 lg:px-8">
                <h2 class="sr-only">Produits</h2>

                <div class="-mx-px grid grid-cols-2 border-l border-gray-200 sm:mx-0 md:grid-cols-3 lg:grid-cols-4">
                    @foreach($matchingProducts as $product)
                        <div wire:click="selectProduct({{ $product['id'] }})" 
                            class="group relative border-r border-b border-gray-200 p-4 sm:p-6 cursor-pointer transition hover:bg-gray-50 {{ $bestMatch && $bestMatch['id'] === $product['id'] ? 'ring-2 ring-indigo-500 bg-indigo-50' : '' }}">
                            
                            <!-- Image du produit -->
                            @if(!empty($product['image_url']))
                                <img src="{{ $product['image_url'] }}" 
                                     alt="{{ $product['name'] }}"
                                     class="aspect-square rounded-lg bg-gray-200 object-cover group-hover:opacity-75"
                                     onerror="this.src='https://placehold.co/600x400/e5e7eb/9ca3af?text=No+Image'">
                            @else
                                <img src="https://placehold.co/600x400/e5e7eb/9ca3af?text=No+Image" 
                                     alt="Image non disponible"
                                     class="aspect-square rounded-lg bg-gray-200 object-cover group-hover:opacity-75">
                            @endif

                            <div class="pt-4 pb-4 text-center">
                                <!-- Badge coffret -->
                                @if($product['name'] && (str_contains(strtolower($product['name']), 'coffret') || str_contains(strtolower($product['name']), 'set') || str_contains(strtolower($product['type'] ?? ''), 'coffret')))
                                    <div class="mb-2">
                                        <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-purple-100 text-purple-800">🎁 Coffret</span>
                                    </div>
                                @endif

                                <!-- Nom du produit -->
                                <h3 class="text-sm font-medium text-gray-900">
                                    <a href="#">
                                        <span aria-hidden="true" class="absolute inset-0"></span>
                                        {{ $product['vendor'] }}
                                    </a>
                                </h3>
                                <p class="text-xs text-gray-600 mt-1 truncate">{{ $product['name'] }}</p>
                                <p class="text-xs text-gray-500 truncate">{{ $product['type'] }}</p>
                                <p class="text-xs text-gray-400 mt-1">{{ $product['variation'] }}</p>

                                <!-- Site -->
                                @php
                                    $siteInfo = collect($availableSites)->firstWhere('id', $product['web_site_id']);
                                @endphp
                                @if($siteInfo)
                                    <div class="mt-2">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                            {{ $siteInfo['name'] }}
                                        </span>
                                    </div>
                                @endif

                                <!-- Prix -->
                                <p class="mt-4 text-base font-medium text-gray-900">
                                    {{ number_format((float)($product['prix_ht'] ?? 0), 2) }} €
                                </p>

                                <!-- Bouton voir produit -->
                                @if($product['url'] ?? false)
                                    <div class="mt-2">
                                        <a href="{{ $product['url'] }}" target="_blank" 
                                           onclick="event.stopPropagation()"
                                           class="inline-flex items-center text-xs font-medium text-indigo-600 hover:text-indigo-500">
                                            Voir produit
                                            <svg class="ml-1 h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                            </svg>
                                        </a>
                                    </div>
                                @endif

                                <!-- ID scrape -->
                                @if(isset($product['scrape_reference_id']))
                                    <p class="text-xs text-gray-400 mt-2">ID: {{ $product['scrape_reference_id'] }}</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @elseif($extractedData)
            <!-- Aucun résultat -->
            <div class="text-center py-12 bg-gray-50 rounded-lg border-2 border-dashed border-gray-300">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <h3 class="mt-2 text-sm font-medium text-gray-900">Aucun produit trouvé</h3>
                <p class="mt-1 text-sm text-gray-500">Essayez de modifier les filtres par site</p>
            </div>
        @else
            <!-- État initial -->
            <div class="text-center py-12 bg-gray-50 rounded-lg border-2 border-dashed border-gray-300">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <h3 class="mt-2 text-sm font-medium text-gray-900">Prêt à rechercher</h3>
                <p class="mt-1 text-sm text-gray-500">Cliquez sur "Extraire et rechercher" pour commencer</p>
            </div>
        @endif
    </div>
</div>