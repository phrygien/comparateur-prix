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
    
    // Nouveaux champs pour recherche manuelle
    public $manualSearchMode = false;
    public $manualVendor = '';
    public $manualName = '';
    public $manualType = '';
    public $manualVariation = '';

    public function mount($name, $id, $price): void
    {
        $this->productName = $name;
        $this->productId = $id;
        $this->productPrice = $price;

        // Récupérer tous les sites disponibles
        $this->availableSites = Site::orderBy('name')->get()->toArray();

        // Par défaut, tous les sites sont sélectionnés
        $this->selectedSites = collect($this->availableSites)->pluck('id')->toArray();
        
        // Lancer automatiquement l'extraction au chargement
        $this->extractSearchTerme();
    }

    public function extractSearchTerme()
    {
        $this->isLoading = true;
        $this->extractedData = null;
        $this->matchingProducts = [];
        $this->bestMatch = null;
        $this->aiValidation = null;
        $this->groupedResults = [];
        $this->manualSearchMode = false;

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            ])->post('https://api.openai.com/v1/chat/completions', [
                        'model' => 'gpt-4o-mini',
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => 'Tu es un expert en extraction de données de produits cosmétiques. IMPORTANT: Le champ "type" doit contenir UNIQUEMENT la catégorie du produit (Crème, Huile, Sérum, Eau de Parfum, etc.), PAS le nom de la gamme. Réponds UNIQUEMENT avec un objet JSON valide, sans markdown ni texte supplémentaire.'
                            ],
                            [
                                'role' => 'user',
                                'content' => "Extrait les informations suivantes du nom de produit et retourne-les au format JSON strict :

RÈGLES IMPORTANTES :
- vendor : la marque du produit (ex: Dior, Shiseido, Chanel)
- name : le nom de la gamme/ligne de produit UNIQUEMENT (ex: \"J'adore\", \"Vital Perfection\", \"La Vie Est Belle\")
- type : UNIQUEMENT la catégorie/type du produit (ex: \"Huile pour le corps\", \"Eau de Parfum\", \"Crème visage\", \"Sérum\")
- variation : la contenance/taille avec unité (ex: \"200 ml\", \"50 ml\", \"30 g\")
- is_coffret : true si c'est un coffret/set/kit, false sinon

Nom du produit : {$this->productName}

EXEMPLES DE FORMAT ATTENDU :

Exemple 1 - Produit : \"Dior J'adore Les Adorables Huile Scintillante Huile pour le corps 200ml\"
{
  \"vendor\": \"Dior\",
  \"name\": \"J'adore Les Adorables\",
  \"type\": \"Huile pour le corps\",
  \"variation\": \"200 ml\",
  \"is_coffret\": false
}

Exemple 2 - Produit : \"Chanel N°5 Eau de Parfum Vaporisateur 100 ml\"
{
  \"vendor\": \"Chanel\",
  \"name\": \"N°5\",
  \"type\": \"Eau de Parfum Vaporisateur\",
  \"variation\": \"100 ml\",
  \"is_coffret\": false
}

Exemple 3 - Produit : \"Shiseido Vital Perfection Uplifting and Firming Cream Enriched 50ml\"
{
  \"vendor\": \"Shiseido\",
  \"name\": \"Vital Perfection Uplifting and Firming\",
  \"type\": \"Crème visage Enrichie\",
  \"variation\": \"50 ml\",
  \"is_coffret\": false
}

Exemple 4 - Produit : \"Lancôme - La Nuit Trésor Rouge Drama - Eau de Parfum Intense Vaporisateur 30ml\"
{
  \"vendor\": \"Lancôme\",
  \"name\": \"La Nuit Trésor Rouge Drama\",
  \"type\": \"Eau de Parfum Intense Vaporisateur\",
  \"variation\": \"30 ml\",
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
                    'is_coffret' => false
                ], $decodedData);

                // Initialiser les champs de recherche manuelle
                $this->manualVendor = $this->extractedData['vendor'] ?? '';
                $this->manualName = $this->extractedData['name'] ?? '';
                $this->manualType = $this->extractedData['type'] ?? '';
                $this->manualVariation = $this->extractedData['variation'] ?? '';

                // Post-traitement : nettoyer le type s'il contient des informations parasites
                if (!empty($this->extractedData['type'])) {
                    $type = $this->extractedData['type'];
                    
                    // Si le type contient le nom de la gamme, essayer de le nettoyer
                    if (!empty($this->extractedData['name'])) {
                        $name = $this->extractedData['name'];
                        // Enlever le nom de la gamme du type s'il y est
                        $type = trim(str_ireplace($name, '', $type));
                    }
                    
                    // Enlever les tirets et espaces multiples
                    $type = preg_replace('/\s*-\s*/', ' ', $type);
                    $type = preg_replace('/\s+/', ' ', $type);
                    
                    $this->extractedData['type'] = trim($type);
                    $this->manualType = $this->extractedData['type'];
                }

                \Log::info('Données extraites', [
                    'vendor' => $this->extractedData['vendor'] ?? '',
                    'name' => $this->extractedData['name'] ?? '',
                    'type' => $this->extractedData['type'] ?? '',
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
     * Recherche manuelle avec les champs personnalisés
     */
    public function manualSearch()
    {
        $this->isLoading = true;
        $this->matchingProducts = [];
        $this->bestMatch = null;
        $this->aiValidation = null;
        $this->groupedResults = [];

        try {
            // Créer extractedData à partir des champs manuels
            $this->extractedData = [
                'vendor' => trim($this->manualVendor),
                'name' => trim($this->manualName),
                'type' => trim($this->manualType),
                'variation' => trim($this->manualVariation),
                'is_coffret' => $this->isCoffretFromString($this->manualName . ' ' . $this->manualType)
            ];

            \Log::info('Recherche manuelle', [
                'vendor' => $this->extractedData['vendor'],
                'name' => $this->extractedData['name'],
                'type' => $this->extractedData['type'],
                'variation' => $this->extractedData['variation']
            ]);

            // Lancer la recherche
            $this->searchMatchingProducts();

        } catch (\Exception $e) {
            \Log::error('Erreur recherche manuelle', [
                'message' => $e->getMessage()
            ]);

            session()->flash('error', 'Erreur lors de la recherche manuelle: ' . $e->getMessage());
        } finally {
            $this->isLoading = false;
        }
    }

    /**
     * Activer/désactiver le mode de recherche manuelle
     */
    public function toggleManualSearch()
    {
        $this->manualSearchMode = !$this->manualSearchMode;
    }

    /**
     * Vérifie si une chaîne contient des mots-clés de coffret
     */
    private function isCoffretFromString(string $text): bool
    {
        $cofferKeywords = ['coffret', 'set', 'kit', 'duo', 'trio', 'collection'];
        $textLower = mb_strtolower($text);
        
        foreach ($cofferKeywords as $keyword) {
            if (str_contains($textLower, $keyword)) {
                return true;
            }
        }
        
        return false;
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
     * LOGIQUE DE RECHERCHE ULTRA STRICTE
     * 1. Filtrer par VENDOR (obligatoire - exact match)
     * 2. Filtrer par statut COFFRET (obligatoire - exact match)
     * 3. FILTRAGE STRICT TYPE DE BASE (obligatoire - doit correspondre exactement)
     * 4. FILTRAGE STRICT NAME (obligatoire - 100% des mots doivent matcher)
     * 5. SCORING ULTRA STRICT avec pénalités sévères
     * 6. Seuil de score minimum très élevé (>= 700)
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
            'is_coffret' => false
        ], $this->extractedData);

        $vendor = $extractedData['vendor'] ?? '';
        $name = $extractedData['name'] ?? '';
        $type = $extractedData['type'] ?? '';
        $isCoffretSource = $extractedData['is_coffret'] ?? false;

        // Si pas de vendor, on ne peut pas faire de recherche fiable
        if (empty($vendor)) {
            \Log::warning('searchMatchingProducts: vendor vide');
            return;
        }

        // Extraire les parties du TYPE pour matching hiérarchique
        $typeParts = $this->extractTypeParts($type);
        
        // Extraire les mots du name EN EXCLUANT le vendor
        $allNameWords = $this->extractKeywords($name);
        
        // Retirer le vendor des mots du name pour éviter les faux positifs
        $vendorWords = $this->extractKeywords($vendor);
        $nameWordsFiltered = array_diff($allNameWords, $vendorWords);
        
        // TOUS LES MOTS du name sont requis
        $nameWords = array_values($nameWordsFiltered);

        \Log::info('🔥 RECHERCHE ULTRA STRICTE - Critères', [
            'vendor' => $vendor,
            'name' => $name,
            'nameWords_TOUS_REQUIS' => $nameWords,
            'type' => $type,
            'type_parts' => $typeParts,
            'is_coffret' => $isCoffretSource
        ]);

        // ÉTAPE 1: Recherche de base - VENDOR EXACT
        $baseQuery = Product::query()
            ->where('vendor', 'LIKE', "%{$vendor}%")
            ->when(!empty($this->selectedSites), function ($q) {
                $q->whereIn('web_site_id', $this->selectedSites);
            })
            ->orderByDesc('id');

        $vendorProducts = $baseQuery->get();

        if ($vendorProducts->isEmpty()) {
            \Log::info('❌ Aucun produit trouvé pour le vendor: ' . $vendor);
            return;
        }

        \Log::info('✅ ÉTAPE 1 - Produits trouvés pour le vendor', [
            'vendor' => $vendor,
            'count' => $vendorProducts->count()
        ]);

        // ÉTAPE 2: Filtrer par statut coffret - STRICT
        $filteredProducts = $this->filterByCoffretStatus($vendorProducts, $isCoffretSource);

        if (empty($filteredProducts)) {
            \Log::info('❌ ÉTAPE 2 - Aucun produit après filtrage coffret strict');
            return;
        }

        \Log::info('✅ ÉTAPE 2 - Produits après filtrage coffret', [
            'count' => count($filteredProducts)
        ]);

        // ÉTAPE 3: FILTRAGE ULTRA STRICT PAR TYPE DE BASE
        $typeFilteredProducts = $this->filterByBaseTypeStrict($filteredProducts, $type);
        
        if (empty($typeFilteredProducts)) {
            \Log::info('❌ ÉTAPE 3 - Aucun produit après filtrage STRICT par type de base');
            return;
        }

        \Log::info('✅ ÉTAPE 3 - Produits après filtrage TYPE DE BASE strict', [
            'count' => count($typeFilteredProducts),
            'type_recherché' => $type
        ]);

        $filteredProducts = $typeFilteredProducts;

        // ÉTAPE 4: FILTRAGE ULTRA STRICT PAR NAME - 100% des mots requis
        if (!empty($nameWords)) {
            $strictNameMatch = collect($filteredProducts)->filter(function ($product) use ($nameWords) {
                $productName = mb_strtolower($product['name'] ?? '');
                
                // TOUS les mots doivent être présents
                $matchCount = 0;
                foreach ($nameWords as $word) {
                    if (str_contains($productName, $word)) {
                        $matchCount++;
                    }
                }
                
                return $matchCount === count($nameWords);
            })->values()->toArray();

            if (empty($strictNameMatch)) {
                \Log::info('❌ ÉTAPE 4 - Aucun produit après filtrage STRICT par NAME (100% des mots requis)', [
                    'nameWords_required' => $nameWords
                ]);
                return;
            }

            $filteredProducts = $strictNameMatch;
            
            \Log::info('✅ ÉTAPE 4 - Produits après filtrage STRICT par NAME (100% match)', [
                'count' => count($filteredProducts),
                'nameWords_matched' => $nameWords
            ]);
        }

        // ÉTAPE 5: SCORING ULTRA STRICT avec pénalités sévères
        $scoredProducts = collect($filteredProducts)->map(function ($product) use ($typeParts, $type, $isCoffretSource, $nameWords) {
            $score = 0;
            $productType = mb_strtolower($product['type'] ?? '');
            $productName = mb_strtolower($product['name'] ?? '');
            
            $matchedTypeParts = [];
            $typePartsCount = count($typeParts);

            // ==========================================
            // BONUS COFFRET - PRIORITÉ MAXIMALE
            // ==========================================
            $productIsCoffret = $this->isCoffret($product);
            
            if ($isCoffretSource && $productIsCoffret) {
                $score += 1000; // MEGA BONUS pour coffrets
            }

            // ==========================================
            // BONUS NAME - 100% requis, sinon pénalité
            // ==========================================
            if (!empty($nameWords)) {
                $nameMatchCount = 0;
                foreach ($nameWords as $word) {
                    if (str_contains($productName, $word)) {
                        $nameMatchCount++;
                    }
                }
                
                // Si 100% des mots matchent
                if ($nameMatchCount === count($nameWords)) {
                    $score += 500; // ÉNORME BONUS pour match parfait NAME
                } else {
                    // Pénalité si pas 100% (ne devrait pas arriver vu le filtrage)
                    $score -= 500;
                }
            }

            // ==========================================
            // MATCHING ULTRA STRICT SUR LE TYPE
            // ==========================================
            
            $typeMatched = false;
            
            if (!empty($typeParts) && !empty($productType)) {
                // Le TYPE DE BASE DOIT correspondre exactement
                if (!empty($typeParts[0])) {
                    $baseTypeLower = mb_strtolower(trim($typeParts[0]));
                    if (str_contains($productType, $baseTypeLower)) {
                        $score += 500; // ÉNORME BONUS pour type de base
                        $typeMatched = true;
                    } else {
                        // ÉNORME PÉNALITÉ si pas de match (ne devrait pas arriver)
                        $score -= 1000;
                    }
                }
                
                // Vérifier TOUTES les parties du type
                foreach ($typeParts as $index => $part) {
                    $partLower = mb_strtolower(trim($part));
                    if (!empty($partLower)) {
                        if (str_contains($productType, $partLower)) {
                            // Bonus décroissant
                            $partBonus = 150 - ($index * 30);
                            $partBonus = max($partBonus, 50);
                            
                            $score += $partBonus;
                            $matchedTypeParts[] = [
                                'part' => $part,
                                'bonus' => $partBonus,
                                'position' => $index + 1
                            ];
                        } else {
                            // PÉNALITÉ si une partie ne match pas
                            $score -= 100;
                        }
                    }
                }
                
                // BONUS MAXIMAL si TOUTES les parties correspondent
                if (count($matchedTypeParts) === $typePartsCount && $typePartsCount > 0) {
                    $score += 300;
                } else {
                    // PÉNALITÉ si pas toutes les parties
                    $score -= 200;
                }
                
                // BONUS MAXIMUM si le type complet est présent
                $typeLower = mb_strtolower(trim($type));
                if (!empty($typeLower) && str_contains($productType, $typeLower)) {
                    $score += 400;
                    $typeMatched = true;
                } else {
                    // Vérifier si c'est un match partiel acceptable
                    $similarity = 0;
                    similar_text($typeLower, $productType, $similarity);
                    
                    if ($similarity < 70) {
                        // PÉNALITÉ si similarité trop faible
                        $score -= 150;
                    }
                }
                
                // BONUS si le type commence exactement par le type recherché
                if (!empty($typeLower) && str_starts_with($productType, $typeLower)) {
                    $score += 200;
                }
            }

            return [
                'product' => $product,
                'score' => $score,
                'matched_type_parts' => $matchedTypeParts,
                'all_type_parts_matched' => count($matchedTypeParts) === $typePartsCount,
                'type_parts_count' => $typePartsCount,
                'matched_count' => count($matchedTypeParts),
                'type_matched' => $typeMatched,
                'is_coffret' => $productIsCoffret,
                'coffret_bonus_applied' => ($isCoffretSource && $productIsCoffret),
                'name_match_count' => !empty($nameWords) ? array_reduce($nameWords, function($count, $word) use ($productName) {
                    return $count + (str_contains($productName, $word) ? 1 : 0);
                }, 0) : 0,
                'name_words_total' => count($nameWords),
                'name_100_percent_match' => !empty($nameWords) ? (array_reduce($nameWords, function($count, $word) use ($productName) {
                    return $count + (str_contains($productName, $word) ? 1 : 0);
                }, 0) === count($nameWords)) : true
            ];
        })
        // Trier par score décroissant
        ->sortByDesc('score')
        ->values();

        \Log::info('✅ ÉTAPE 5 - Scoring ULTRA STRICT terminé', [
            'total_products' => $scoredProducts->count(),
            'top_5_scores' => $scoredProducts->take(5)->map(function($item) {
                return [
                    'id' => $item['product']['id'] ?? 0,
                    'score' => $item['score'],
                    'name' => $item['product']['name'] ?? '',
                    'type' => $item['product']['type'] ?? '',
                    'name_100%' => $item['name_100_percent_match'],
                    'all_type_matched' => $item['all_type_parts_matched']
                ];
            })->toArray()
        ]);

        // ÉTAPE 6: SEUIL DE SCORE MINIMUM TRÈS ÉLEVÉ
        $scoredProducts = $scoredProducts->filter(function($item) {
            // SEUIL MINIMUM : 700 points
            $keepProduct = $item['score'] >= 700;
            
            if (!$keepProduct) {
                \Log::debug('❌ Produit exclu - Score insuffisant (< 700)', [
                    'product_id' => $item['product']['id'] ?? 0,
                    'product_name' => $item['product']['name'] ?? '',
                    'score' => $item['score']
                ]);
            }
            
            return $keepProduct;
        });

        \Log::info('✅ ÉTAPE 6 - Après filtrage par score minimum (>= 700)', [
            'produits_restants' => $scoredProducts->count()
        ]);

        if ($scoredProducts->isEmpty()) {
            \Log::info('❌ FINAL - Aucun produit ne répond aux critères ULTRA STRICTS');
            $this->matchingProducts = [];
            $this->groupedResults = [];
            return;
        }

        // Extraire uniquement les produits des résultats scorés
        $rankedProducts = $scoredProducts->pluck('product')->toArray();

        $this->matchingProducts = $rankedProducts;

        \Log::info('✅ FINAL - Produits validés avec critères ULTRA STRICTS', [
            'count' => count($this->matchingProducts),
            'best_score' => $scoredProducts->first()['score'] ?? 0,
            'worst_score' => $scoredProducts->last()['score'] ?? 0
        ]);

        // Grouper et valider avec l'IA
        $this->groupResultsByScrapeReference($this->matchingProducts);
        $this->validateBestMatchWithAI();
    }

    /**
     * FILTRAGE ULTRA STRICT PAR TYPE DE BASE
     * Exclut tout produit dont le type de base ne correspond pas exactement
     */
    private function filterByBaseTypeStrict(array $products, string $searchType): array
    {
        if (empty($searchType)) {
            return $products;
        }

        // Définir les catégories de types avec leurs variantes
        $typeCategories = [
            'parfum' => [
                'keywords' => ['eau de parfum', 'parfum', 'eau de toilette', 'eau de cologne', 'eau fraiche', 'extrait de parfum', 'extrait', 'cologne'],
                'strict_match' => true // Doit matcher exactement
            ],
            'déodorant' => [
                'keywords' => ['déodorant', 'deodorant', 'deo', 'anti-transpirant', 'antitranspirant'],
                'strict_match' => true
            ],
            'crème' => [
                'keywords' => ['crème', 'creme', 'baume', 'gel', 'lotion', 'fluide', 'soin'],
                'strict_match' => false // Plus flexible
            ],
            'huile' => [
                'keywords' => ['huile', 'oil'],
                'strict_match' => true
            ],
            'sérum' => [
                'keywords' => ['sérum', 'serum', 'concentrate', 'concentré'],
                'strict_match' => true
            ],
            'masque' => [
                'keywords' => ['masque', 'mask', 'patch'],
                'strict_match' => true
            ],
            'shampooing' => [
                'keywords' => ['shampooing', 'shampoing', 'shampoo'],
                'strict_match' => true
            ],
            'après-shampooing' => [
                'keywords' => ['après-shampooing', 'conditioner', 'après shampooing'],
                'strict_match' => true
            ],
            'savon' => [
                'keywords' => ['savon', 'soap', 'gel douche', 'mousse'],
                'strict_match' => false
            ],
            'maquillage' => [
                'keywords' => ['fond de teint', 'rouge à lèvres', 'mascara', 'eye-liner', 'fard', 'poudre'],
                'strict_match' => true
            ],
        ];

        $searchTypeLower = mb_strtolower(trim($searchType));
        
        // Trouver la catégorie ET les mots-clés exacts du type recherché
        $searchCategory = null;
        $searchKeywords = [];
        
        foreach ($typeCategories as $category => $config) {
            foreach ($config['keywords'] as $keyword) {
                if (str_contains($searchTypeLower, $keyword)) {
                    $searchCategory = $category;
                    $searchKeywords[] = $keyword;
                }
            }
        }

        // Si on n'a pas trouvé de catégorie, essayer un match direct
        if (!$searchCategory) {
            \Log::info('⚠️ Type non catégorisé, filtrage par match direct', [
                'type' => $searchType
            ]);
            
            // Filtrage direct : le type du produit doit contenir le type recherché
            $filtered = collect($products)->filter(function ($product) use ($searchTypeLower) {
                $productType = mb_strtolower($product['type'] ?? '');
                return str_contains($productType, $searchTypeLower);
            })->values()->toArray();
            
            \Log::info('Résultat filtrage direct', [
                'produits_avant' => count($products),
                'produits_après' => count($filtered)
            ]);
            
            return $filtered;
        }

        $categoryConfig = $typeCategories[$searchCategory];

        \Log::info('🔥 FILTRAGE ULTRA STRICT par type de base', [
            'type_recherché' => $searchType,
            'catégorie' => $searchCategory,
            'keywords_recherchés' => $searchKeywords,
            'strict_match' => $categoryConfig['strict_match']
        ]);

        // Filtrer les produits
        $filtered = collect($products)->filter(function ($product) use ($searchCategory, $typeCategories, $searchKeywords, $categoryConfig) {
            $productType = mb_strtolower($product['type'] ?? '');
            
            if (empty($productType)) {
                return false;
            }

            // VÉRIFICATION 1 : Le produit doit appartenir à la même catégorie
            $productCategory = null;
            foreach ($typeCategories as $category => $config) {
                foreach ($config['keywords'] as $keyword) {
                    if (str_contains($productType, $keyword)) {
                        $productCategory = $category;
                        break 2;
                    }
                }
            }

            // Si catégorie différente, EXCLUSION
            if ($productCategory !== $searchCategory) {
                \Log::debug('❌ EXCLU - Catégorie différente', [
                    'product_id' => $product['id'] ?? 0,
                    'product_type' => $productType,
                    'product_category' => $productCategory,
                    'search_category' => $searchCategory
                ]);
                return false;
            }

            // VÉRIFICATION 2 : Si strict_match, au moins un keyword recherché doit être présent
            if ($categoryConfig['strict_match']) {
                $hasKeyword = false;
                foreach ($searchKeywords as $keyword) {
                    if (str_contains($productType, $keyword)) {
                        $hasKeyword = true;
                        break;
                    }
                }
                
                if (!$hasKeyword) {
                    \Log::debug('❌ EXCLU - Aucun keyword strict trouvé', [
                        'product_id' => $product['id'] ?? 0,
                        'product_type' => $productType,
                        'required_keywords' => $searchKeywords
                    ]);
                    return false;
                }
            }

            return true;
        })->values()->toArray();

        \Log::info('Résultat filtrage ULTRA STRICT par type', [
            'produits_avant' => count($products),
            'produits_après' => count($filtered),
            'produits_exclus' => count($products) - count($filtered)
        ]);

        return $filtered;
    }

    /**
     * Extrait les parties d'un type pour matching hiérarchique
     */
    private function extractTypeParts(string $type): array
    {
        if (empty($type)) {
            return [];
        }

        // Liste des séparateurs possibles
        $separators = [' - ', ' / ', ' + ', ', ', ' et ', ' & '];
        
        // Remplacer les séparateurs par un séparateur unique
        $normalized = $type;
        foreach ($separators as $separator) {
            $normalized = str_replace($separator, '|', $normalized);
        }
        
        // Séparer par espace mais garder les mots composés ensemble
        $parts = explode('|', $normalized);
        
        // Nettoyer et filtrer les parties vides
        $parts = array_map('trim', $parts);
        $parts = array_filter($parts, function($part) {
            return !empty($part);
        });
        
        // Si on n'a pas de séparateurs explicites, essayer de séparer par mots clés
        if (count($parts) === 1) {
            // Mots-clés hiérarchiques pour les parfums - ORDRE IMPORTANT
            $perfumeKeywords = [
                'eau de parfum',
                'eau de toilette', 
                'eau de cologne',
                'extrait de parfum',
                'eau fraiche',
                'parfum',
                'extrait',
                'cologne'
            ];
            
            $intensityKeywords = ['intense', 'extrême', 'absolu', 'concentré', 'léger', 'doux', 'fort', 'puissant'];
            $formatKeywords = ['vaporisateur', 'spray', 'atomiseur', 'flacon', 'roller', 'stick', 'roll-on'];
            
            $typeLower = mb_strtolower($type);
            $foundParts = [];
            
            // Chercher d'abord le type de parfum (expressions composées d'abord)
            foreach ($perfumeKeywords as $keyword) {
                if (str_contains($typeLower, $keyword)) {
                    // Garder la capitalisation originale si possible
                    $startPos = mb_strpos($typeLower, $keyword);
                    $originalPart = mb_substr($type, $startPos, mb_strlen($keyword));
                    $foundParts[] = $originalPart;
                    
                    // Retirer cette partie du type pour continuer la recherche
                    $typeLower = str_replace($keyword, '', $typeLower);
                    break;
                }
            }
            
            // Chercher l'intensité
            foreach ($intensityKeywords as $keyword) {
                if (str_contains($typeLower, $keyword)) {
                    $startPos = mb_strpos($typeLower, $keyword);
                    if ($startPos !== false) {
                        $originalPart = mb_substr($type, $startPos, mb_strlen($keyword));
                        $foundParts[] = ucfirst($originalPart);
                    }
                    break;
                }
            }
            
            // Chercher le format
            foreach ($formatKeywords as $keyword) {
                if (str_contains($typeLower, $keyword)) {
                    $startPos = mb_strpos($typeLower, $keyword);
                    if ($startPos !== false) {
                        $originalPart = mb_substr($type, $startPos, mb_strlen($keyword));
                        $foundParts[] = ucfirst($originalPart);
                    }
                    break;
                }
            }
            
            // Si on a trouvé des parties avec cette méthode, les utiliser
            if (!empty($foundParts)) {
                return $foundParts;
            }
            
            // Sinon, simplement diviser par espace et garder les mots significatifs
            $words = preg_split('/\s+/', $type);
            $words = array_filter($words, function($word) {
                return mb_strlen($word) >= 3;
            });
            return array_values($words);
        }
        
        return array_values($parts);
    }

    /**
     * Organise les résultats en ne gardant que le dernier scrape_reference_id par produit unique
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
                'id' => 0,
                'vendor' => '',
                'name' => '',
                'type' => '',
                'variation' => ''
            ], $product);
        });

        // DÉDUPLICATION : Garder uniquement le produit avec le scrape_reference_id le plus élevé
        $uniqueProducts = $productsCollection
            ->groupBy(function ($product) {
                return md5(
                    strtolower(trim($product['vendor'])) . '|' .
                    strtolower(trim($product['name'])) . '|' .
                    strtolower(trim($product['type'])) . '|' .
                    strtolower(trim($product['variation'])) . '|' .
                    $product['web_site_id']
                );
            })
            ->map(function ($group) {
                return $group->sortByDesc('scrape_reference_id')->first();
            })
            ->values()
            ->sortByDesc('scrape_reference_id');

        \Log::info('Après déduplication', [
            'produits_avant' => $productsCollection->count(),
            'produits_apres' => $uniqueProducts->count()
        ]);

        // Limiter à 50 produits uniques (stricte sélection)
        $this->matchingProducts = $uniqueProducts->take(50)->toArray();

        \Log::info('✅ Résultats finaux ULTRA STRICTS', [
            'total_produits' => count($this->matchingProducts)
        ]);

        // Statistiques par site
        $bySiteStats = $uniqueProducts->groupBy('web_site_id')->map(function ($siteProducts, $siteId) {
            return [
                'site_id' => $siteId,
                'total_products' => $siteProducts->count(),
                'max_scrape_ref_id' => $siteProducts->max('scrape_reference_id'),
                'min_scrape_ref_id' => $siteProducts->min('scrape_reference_id'),
                'products' => $siteProducts->values()->toArray()
            ];
        });

        // Grouper par scrape_reference
        $grouped = $uniqueProducts->groupBy('scrape_reference');
        
        $this->groupedResults = $grouped->map(function ($group, $reference) {
            $bySite = $group->groupBy('web_site_id')->map(function ($siteProducts) {
                return [
                    'count' => $siteProducts->count(),
                    'products' => $siteProducts->values()->toArray(),
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
     * Filtre les produits selon leur statut coffret - STRICT
     */
    private function filterByCoffretStatus($products, bool $sourceisCoffret): array
    {
        return $products->filter(function ($product) use ($sourceisCoffret) {
            $productIsCoffret = $this->isCoffret($product->toArray());

            // Match EXACT requis
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
        $candidateProducts = array_slice($this->matchingProducts, 0, 5);

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
                                'content' => 'Tu es un expert en matching de produits cosmétiques. Analyse avec une extrême précision. Réponds UNIQUEMENT avec un objet JSON.'
                            ],
                            [
                                'role' => 'user',
                                'content' => "Produit source : {$this->productName}

Critères extraits :
- Vendor: " . ($this->extractedData['vendor'] ?? 'N/A') . "
- Name: " . ($this->extractedData['name'] ?? 'N/A') . "
- Type: " . ($this->extractedData['type'] ?? 'N/A') . "
- Variation: " . ($this->extractedData['variation'] ?? 'N/A') . "

Produits candidats :
" . json_encode($productsInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "

Retourne le meilleur match au format JSON :
{
  \"best_match_id\": 123,
  \"confidence_score\": 0.95,
  \"reasoning\": \"Explication\"
}"
                            ]
                        ],
                        'temperature' => 0.1,
                        'max_tokens' => 500
                    ]);

            if ($response->successful()) {
                $data = $response->json();
                $content = $data['choices'][0]['message']['content'];
                $content = preg_replace('/```json\s*|\s*```/', '', $content);
                $content = trim($content);

                $this->aiValidation = json_decode($content, true);

                if ($this->aiValidation && isset($this->aiValidation['best_match_id'])) {
                    $bestMatchId = $this->aiValidation['best_match_id'];
                    $found = collect($this->matchingProducts)->firstWhere('id', $bestMatchId);

                    if ($found) {
                        $this->bestMatch = $found;
                    } else {
                        $this->bestMatch = $this->matchingProducts[0] ?? null;
                    }
                } else {
                    $this->bestMatch = $this->matchingProducts[0] ?? null;
                }
            }

        } catch (\Exception $e) {
            \Log::error('Erreur validation IA', [
                'message' => $e->getMessage()
            ]);
            $this->bestMatch = $this->matchingProducts[0] ?? null;
        }
    }

    public function selectProduct($productId)
    {
        $product = Product::find($productId);

        if ($product) {
            session()->flash('success', 'Produit sélectionné : ' . $product->name);
            $this->bestMatch = $product->toArray();
            $this->dispatch('product-selected', productId: $productId);
        }
    }

    public function updatedSelectedSites()
    {
        if (!empty($this->extractedData)) {
            $this->searchMatchingProducts();
        }
    }

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
            <h2 class="text-xl font-bold text-gray-900">🔥 Recherche ULTRA STRICTE</h2>
            <div class="flex gap-2">
                <button wire:click="toggleManualSearch"
                    class="px-4 py-2 {{ $manualSearchMode ? 'bg-gray-600' : 'bg-green-600' }} text-white rounded-lg hover:opacity-90 font-medium shadow-sm">
                    {{ $manualSearchMode ? 'Mode Auto' : 'Recherche Manuelle' }}
                </button>
                <button wire:click="extractSearchTerme" wire:loading.attr="disabled"
                    class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 font-medium shadow-sm">
                    <span wire:loading.remove>Rechercher à nouveau</span>
                    <span wire:loading>Extraction en cours...</span>
                </button>
            </div>
        </div>
        <p class="text-xs text-gray-500 mt-2">⚠️ Mode strict activé : Seuil minimum 700 points, filtrage maximal sur NAME (100%), TYPE (exact) et COFFRET</p>
    </div>

    <livewire:plateformes.detail :id="$productId" />

    <!-- Formulaire de recherche manuelle -->
    @if($manualSearchMode)
        <div class="px-6 py-4 bg-blue-50 border-b border-blue-200">
            <h3 class="font-semibold text-gray-900 mb-3">🔍 Recherche Manuelle</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Marque (Vendor)</label>
                    <input type="text" wire:model="manualVendor" readonly
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-100 text-gray-600 cursor-not-allowed">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nom de la gamme</label>
                    <input type="text" wire:model="manualName"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                        placeholder="Ex: J'adore, N°5">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Type de produit</label>
                    <input type="text" wire:model="manualType"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                        placeholder="Ex: Eau de Parfum">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Contenance</label>
                    <input type="text" wire:model="manualVariation"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                        placeholder="Ex: 50 ml">
                </div>
            </div>
            <div class="mt-4 flex justify-end">
                <button wire:click="manualSearch" wire:loading.attr="disabled"
                    class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 font-medium shadow-sm">
                    <span wire:loading.remove>🔎 Lancer la recherche</span>
                    <span wire:loading>Recherche en cours...</span>
                </button>
            </div>
        </div>
    @endif

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
        @if($isLoading)
            <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <div class="flex items-center space-x-3">
                    <div class="animate-spin rounded-full h-5 w-5 border-b-2 border-indigo-600"></div>
                    <p class="text-sm text-blue-800">
                        Extraction et recherche STRICTE en cours pour "<span class="font-semibold">{{ $productName }}</span>"...
                    </p>
                </div>
            </div>
        @endif

        @if(!empty($groupedResults) && !$isLoading)
            <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg">
                <p class="text-sm text-green-800">
                    ✅ <span class="font-semibold">{{ count($matchingProducts) }}</span> produit(s) validé(s) avec critères ULTRA STRICTS
                </p>
            </div>
        @endif

        @if(!empty($matchingProducts) && !$isLoading)
            <div class="mx-auto max-w-7xl overflow-hidden sm:px-6 lg:px-8">
                <h2 class="sr-only">Produits</h2>
                <div class="-mx-px grid grid-cols-2 border-l border-gray-200 sm:mx-0 md:grid-cols-3 lg:grid-cols-4">
                    @foreach($matchingProducts as $product)
                        @php
                            $hasUrl = !empty($product['url']);
                            $isBestMatch = $bestMatch && $bestMatch['id'] === $product['id'];
                            $cardClass = "group relative border-r border-b border-gray-200 p-4 sm:p-6 cursor-pointer transition hover:bg-gray-50";
                            if ($isBestMatch) {
                                $cardClass .= " ring-2 ring-green-500 bg-green-50";
                            }
                        @endphp
                        
                        @if($hasUrl)
                            <a href="{{ $product['url'] }}" target="_blank" rel="noopener noreferrer" 
                               class="{{ $cardClass }}">
                        @else
                            <div class="{{ $cardClass }}">
                        @endif
                            
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
                                <div class="mb-2">
                                    <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-green-100 text-green-800">
                                        ✓ VALIDÉ STRICT
                                    </span>
                                </div>

                                @if($product['name'] && (str_contains(strtolower($product['name']), 'coffret') || str_contains(strtolower($product['name']), 'set')))
                                    <div class="mb-2">
                                        <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-purple-100 text-purple-800">🎁 Coffret</span>
                                    </div>
                                @endif

                                <h3 class="text-sm font-medium text-gray-900">{{ $product['vendor'] }}</h3>
                                <p class="text-xs text-gray-600 mt-1 truncate">{{ $product['name'] }}</p>
                                
                                <div class="mt-1">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                        {{ $product['type'] }}
                                    </span>
                                </div>
                                
                                <p class="text-xs text-gray-400 mt-1">{{ $product['variation'] }}</p>

                                @php
                                    $siteInfo = collect($availableSites)->firstWhere('id', $product['web_site_id']);
                                @endphp
                                @if($siteInfo)
                                    <div class="mt-2">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-800">
                                            {{ $siteInfo['name'] }}
                                        </span>
                                    </div>
                                @endif

                                <p class="mt-4 text-base font-medium text-gray-900">
                                    {{ number_format((float)($product['prix_ht'] ?? 0), 2) }} €
                                </p>

                                @if($hasUrl)
                                    <div class="mt-2">
                                        <span class="inline-flex items-center text-xs font-medium text-indigo-600">
                                            Ouvrir →
                                        </span>
                                    </div>
                                @endif
                            </div>
                            
                        @if($hasUrl)
                            </a>
                        @else
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        @elseif($isLoading)
            <div class="text-center py-12 bg-gray-50 rounded-lg border-2 border-dashed border-gray-300">
                <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-indigo-600 mx-auto"></div>
                <h3 class="mt-4 text-sm font-medium text-gray-900">Analyse STRICTE en cours</h3>
                <p class="mt-1 text-sm text-gray-500">Application des filtres maximaux...</p>
            </div>
        @elseif($extractedData && empty($matchingProducts))
            <div class="text-center py-12 bg-yellow-50 rounded-lg border-2 border-dashed border-yellow-300">
                <svg class="mx-auto h-12 w-12 text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <h3 class="mt-2 text-sm font-medium text-gray-900">Aucun produit ne répond aux critères STRICTS</h3>
                <p class="mt-1 text-sm text-gray-500">Essayez la recherche manuelle ou modifiez les filtres</p>
            </div>
        @else
            <div class="text-center py-12 bg-gray-50 rounded-lg border-2 border-dashed border-gray-300">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <h3 class="mt-2 text-sm font-medium text-gray-900">Prêt à rechercher</h3>
                <p class="mt-1 text-sm text-gray-500">L'extraction démarre automatiquement...</p>
            </div>
        @endif
    </div>
</div>
