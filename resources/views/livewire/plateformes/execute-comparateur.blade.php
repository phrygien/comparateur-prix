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
                                'content' => 'Tu es un expert en extraction de données de produits cosmétiques. 
IMPORTANT: 
1. Le champ "type" doit contenir UNIQUEMENT la catégorie du produit (Crème, Huile, Sérum, Eau de Parfum, etc.), PAS le nom de la gamme. 
2. Pour le matching, une règle spéciale s\'applique : 
   - Si le type a plus de 3 mots, utiliser seulement les 3 premiers mots pour le matching
   - Si le type a 3 mots ou moins, utiliser seulement les 2 premiers mots pour le matching
Réponds UNIQUEMENT avec un objet JSON valide, sans markdown ni texte supplémentaire.'
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
}

Exemple 5 - Produit : \"Rabanne - Fame In Love - Parfum Elixir Vaporisateur 80ml Rechargeable\"
IMPORTANT : Le type complet est \"Parfum Elixir Vaporisateur Rechargeable\" 
Pour le matching : utiliser \"Parfum Elixir\" (2 premiers mots car type ≤ 3 mots)
{
  \"vendor\": \"Rabanne\",
  \"name\": \"Fame In Love\",
  \"type\": \"Parfum Elixir Vaporisateur Rechargeable\",
  \"variation\": \"80 ml\",
  \"is_coffret\": false
}

Exemple 6 - Produit : \"Sephora Collection Smoothing Primer Base de Maquillage Lissante 30ml\"
{
  \"vendor\": \"Sephora Collection\",
  \"name\": \"Smoothing Primer\",
  \"type\": \"Base de Maquillage Lissante\",
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
                    'type_matching_words' => $this->getTypeMatchingWords($this->extractedData['type'] ?? ''),
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
     * Récupère les mots à utiliser pour le matching selon la règle :
     * - Si type > 3 mots : prendre 3 mots
     * - Si type ≤ 3 mots : prendre 2 mots
     */
    private function getTypeMatchingWords(string $type): string
    {
        if (empty($type)) {
            return '';
        }

        $typeWords = $this->extractTypeWordsForDisplay($type);
        
        if (count($typeWords) > 3) {
            // Prendre 3 premiers mots
            $matchingWords = array_slice($typeWords, 0, 3);
        } else {
            // Prendre 2 premiers mots (ou moins si pas assez de mots)
            $matchingWords = array_slice($typeWords, 0, min(2, count($typeWords)));
        }
        
        return implode(' ', $matchingWords);
    }

    /**
     * Extrait les mots du type pour l'affichage (sans les stop words)
     */
    private function extractTypeWordsForDisplay(string $type): array
    {
        if (empty($type)) {
            return [];
        }

        $typeLower = mb_strtolower(trim($type));

        // Mots à IGNORER (articles, prépositions, etc.)
        $stopWords = ['de', 'du', 'la', 'le', 'les', 'des', 'pour', 'à', 'au', 'aux', 'et', 'ou'];

        // Découper par espaces et tirets
        $allWords = preg_split('/[\s\-]+/', $typeLower, -1, PREG_SPLIT_NO_EMPTY);

        // Filtrer les mots trop courts et les stop words
        $significantWords = array_filter($allWords, function ($word) use ($stopWords) {
            return mb_strlen($word) >= 3 && !in_array($word, $stopWords);
        });

        return array_values($significantWords);
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
                'type_matching_words' => $this->getTypeMatchingWords($this->extractedData['type']),
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
     * LOGIQUE DE RECHERCHE OPTIMISÉE AVEC MATCHING STRICT MOT PAR MOT SUR LE TYPE
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

        // NOUVELLE RÈGLE : Extraire les mots pour matching selon le nombre de mots
        $typeWords = $this->extractTypeWordsForMatching($type);

        // Afficher les mots utilisés pour le matching
        $matchingWordsString = implode(' ', $typeWords);
        \Log::info('📝 Règle de matching appliquée', [
            'type_complet' => $type,
            'mots_utilises_pour_matching' => $matchingWordsString,
            'nombre_mots_utilises' => count($typeWords),
            'regle_appliquee' => $this->getMatchingRuleDescription($type)
        ]);

        // Extraire les mots du name EN EXCLUANT le vendor
        $allNameWords = $this->extractKeywords($name);

        // Retirer le vendor des mots du name pour éviter les faux positifs
        $vendorWords = $this->extractKeywords($vendor);
        $nameWordsFiltered = array_diff($allNameWords, $vendorWords);

        $nameWords = array_values($nameWordsFiltered);

        \Log::info('🔍 Mots-clés pour la recherche STRICTE', [
            'vendor' => $vendor,
            'name' => $name,
            'nameWords' => $nameWords,
            'type' => $type,
            'typeWords_for_matching' => $typeWords,
            'typeWords_count' => count($typeWords),
            'matching_rule' => $this->getMatchingRuleDescription($type)
        ]);

        // ÉTAPE 1: Recherche de base - UNIQUEMENT sur le vendor et les sites sélectionnés
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

        \Log::info('✅ Produits trouvés pour le vendor', [
            'vendor' => $vendor,
            'count' => $vendorProducts->count()
        ]);

        // ÉTAPE 2: Filtrer par statut coffret
        $filteredProducts = $this->filterByCoffretStatus($vendorProducts, $isCoffretSource);

        if (empty($filteredProducts)) {
            \Log::info('❌ Aucun produit après filtrage coffret');
            return;
        }

        // ÉTAPE 3: FILTRAGE PROGRESSIF par les mots du NAME
        $nameFilteredProducts = $filteredProducts;

        if (!empty($nameWords)) {
            // TENTATIVE 1: TOUS les mots doivent être présents
            $allWordsMatch = collect($filteredProducts)->filter(function ($product) use ($nameWords) {
                $productName = mb_strtolower($product['name'] ?? '');

                $matchCount = 0;
                foreach ($nameWords as $word) {
                    if (str_contains($productName, $word)) {
                        $matchCount++;
                    }
                }

                return $matchCount === count($nameWords);
            })->values()->toArray();

            if (!empty($allWordsMatch)) {
                $nameFilteredProducts = $allWordsMatch;
                \Log::info('✅ Produits après filtrage STRICT par NAME (TOUS les mots)', [
                    'count' => count($nameFilteredProducts),
                    'nameWords_required' => $nameWords
                ]);
            } else {
                // TENTATIVE 2: Au moins 80% des mots doivent être présents
                $minRequired = max(1, (int) ceil(count($nameWords) * 0.8));

                $mostWordsMatch = collect($filteredProducts)->filter(function ($product) use ($nameWords, $minRequired) {
                    $productName = mb_strtolower($product['name'] ?? '');

                    $matchCount = 0;
                    foreach ($nameWords as $word) {
                        if (str_contains($productName, $word)) {
                            $matchCount++;
                        }
                    }

                    return $matchCount >= $minRequired;
                })->values()->toArray();

                if (!empty($mostWordsMatch)) {
                    $nameFilteredProducts = $mostWordsMatch;
                    \Log::info('✅ Produits après filtrage 80% par NAME', [
                        'count' => count($nameFilteredProducts)
                    ]);
                } else {
                    // TENTATIVE 3: Au moins 50% des mots doivent être présents
                    $minRequired = max(1, (int) ceil(count($nameWords) * 0.5));

                    $halfWordsMatch = collect($filteredProducts)->filter(function ($product) use ($nameWords, $minRequired) {
                        $productName = mb_strtolower($product['name'] ?? '');

                        $matchCount = 0;
                        foreach ($nameWords as $word) {
                            if (str_contains($productName, $word)) {
                                $matchCount++;
                            }
                        }

                        return $matchCount >= $minRequired;
                    })->values()->toArray();

                    if (!empty($halfWordsMatch)) {
                        $nameFilteredProducts = $halfWordsMatch;
                        \Log::info('⚠️ Produits après filtrage 50% par NAME', [
                            'count' => count($nameFilteredProducts)
                        ]);
                    } else {
                        // FALLBACK FINAL: Au moins 1 mot doit être présent
                        $anyWordMatch = collect($filteredProducts)->filter(function ($product) use ($nameWords) {
                            $productName = mb_strtolower($product['name'] ?? '');
                            foreach ($nameWords as $word) {
                                if (str_contains($productName, $word)) {
                                    return true;
                                }
                            }
                            return false;
                        })->values()->toArray();

                        if (!empty($anyWordMatch)) {
                            $nameFilteredProducts = $anyWordMatch;
                            \Log::info('⚠️ Produits après filtrage SOUPLE par NAME', [
                                'count' => count($nameFilteredProducts)
                            ]);
                        }
                    }
                }
            }

            $filteredProducts = $nameFilteredProducts;
        }

        // ÉTAPE 4: FILTRAGE STRICT MOT PAR MOT SUR LE TYPE selon la NOUVELLE RÈGLE
        if (!empty($typeWords)) {
            $typeFilteredProducts = collect($filteredProducts)->filter(function ($product) use ($typeWords) {
                $productType = mb_strtolower($product['type'] ?? '');

                // Si le produit n'a pas de type, on l'EXCLUT (pas de tolérance)
                if (empty($productType)) {
                    \Log::debug('❌ Produit EXCLU (type vide)', [
                        'product_id' => $product['id'] ?? 0,
                        'product_name' => $product['name'] ?? ''
                    ]);
                    return false;
                }

                // Vérifier que TOUS les mots du type recherché sont présents
                $matchCount = 0;
                $matchedWords = [];
                $missingWords = [];

                foreach ($typeWords as $word) {
                    if (str_contains($productType, $word)) {
                        $matchCount++;
                        $matchedWords[] = $word;
                    } else {
                        $missingWords[] = $word;
                    }
                }

                $allWordsPresent = ($matchCount === count($typeWords));

                if (!$allWordsPresent) {
                    \Log::debug('❌ Produit EXCLU (mots de type manquants)', [
                        'product_id' => $product['id'] ?? 0,
                        'product_name' => $product['name'] ?? '',
                        'product_type' => $productType,
                        'typeWords_required' => $typeWords,
                        'typeWords_count' => count($typeWords),
                        'matched_words' => $matchedWords,
                        'missing_words' => $missingWords,
                        'match_ratio' => $matchCount . '/' . count($typeWords)
                    ]);
                } else {
                    \Log::debug('✅ Produit ACCEPTÉ (tous les mots de type présents)', [
                        'product_id' => $product['id'] ?? 0,
                        'product_name' => $product['name'] ?? '',
                        'product_type' => $productType,
                        'typeWords_matched' => $matchedWords,
                        'match_ratio' => $matchCount . '/' . count($typeWords)
                    ]);
                }

                return $allWordsPresent;
            })->values()->toArray();

            \Log::info('🎯 Résultat du filtrage STRICT mot par mot sur le TYPE', [
                'produits_avant' => count($filteredProducts),
                'produits_après' => count($typeFilteredProducts),
                'produits_exclus' => count($filteredProducts) - count($typeFilteredProducts),
                'typeWords_required' => $typeWords,
                'typeWords_count' => count($typeWords),
                'matching_rule' => $this->getMatchingRuleDescription($type)
            ]);

            if (empty($typeFilteredProducts)) {
                \Log::warning('⚠️ AUCUN produit ne correspond au type exact mot par mot', [
                    'type_recherché' => $type,
                    'typeWords' => $typeWords,
                    'matching_words' => implode(' ', $typeWords),
                    'matching_rule' => $this->getMatchingRuleDescription($type)
                ]);

                $this->matchingProducts = [];
                $this->groupedResults = [];
                return;
            }

            $filteredProducts = $typeFilteredProducts;
        } else {
            \Log::info('ℹ️ Pas de mots de type à vérifier, on garde tous les produits filtrés par NAME');
        }

        // ÉTAPE 5: Scoring
        $scoredProducts = collect($filteredProducts)->map(function ($product) use ($typeWords, $type, $isCoffretSource, $nameWords) {
            $score = 0;
            $productType = mb_strtolower($product['type'] ?? '');
            $productName = mb_strtolower($product['name'] ?? '');

            // BONUS COFFRET
            $productIsCoffret = $this->isCoffret($product);

            if ($isCoffretSource && $productIsCoffret) {
                $score += 500;
            }

            // BONUS NAME
            if (!empty($nameWords)) {
                $nameMatchCount = 0;
                foreach ($nameWords as $word) {
                    if (str_contains($productName, $word)) {
                        $nameMatchCount++;
                    }
                }

                $nameMatchRatio = count($nameWords) > 0 ? ($nameMatchCount / count($nameWords)) : 0;
                $nameBonus = (int) ($nameMatchRatio * 300);
                $score += $nameBonus;

                if ($nameMatchCount === count($nameWords)) {
                    $score += 200;
                }
            }

            // BONUS TYPE - TOUS les mots sont présents (garanti par le filtrage)
            if (!empty($typeWords)) {
                $typeMatchCount = 0;
                foreach ($typeWords as $word) {
                    if (str_contains($productType, $word)) {
                        $typeMatchCount++;
                    }
                }

                // Si TOUS les mots matchent (ce qui doit être le cas)
                if ($typeMatchCount === count($typeWords)) {
                    $score += 1000; // ÉNORME BONUS car c'est un match PARFAIT
                }

                // Bonus supplémentaire si le type complet est identique
                $typeLower = mb_strtolower(trim($type));
                if (!empty($typeLower) && $productType === $typeLower) {
                    $score += 500; // BONUS pour type exactement identique
                }

                // Bonus spécial selon la règle appliquée
                $typeWordCount = count($this->extractTypeWordsForDisplay($type));
                if ($typeWordCount > 3 && count($typeWords) === 3) {
                    $score += 300; // Bonus pour règle ">3 mots → 3 mots"
                } elseif ($typeWordCount <= 3 && count($typeWords) === 2) {
                    $score += 200; // Bonus pour règle "≤3 mots → 2 mots"
                }
            }

            return [
                'product' => $product,
                'score' => $score,
                'type_words_matched' => !empty($typeWords) ? count($typeWords) : 0,
                'type_words_total' => count($typeWords),
                'is_coffret' => $productIsCoffret,
                'coffret_bonus_applied' => ($isCoffretSource && $productIsCoffret),
                'name_match_count' => !empty($nameWords) ? array_reduce($nameWords, function ($count, $word) use ($productName) {
                    return $count + (str_contains($productName, $word) ? 1 : 0);
                }, 0) : 0,
                'name_words_total' => count($nameWords),
                'matching_rule_applied' => $this->getMatchingRuleDescription($type)
            ];
        })
            ->sortByDesc('score')
            ->values();

        \Log::info('📊 Scoring final', [
            'total_products' => $scoredProducts->count(),
            'type_recherche' => $type,
            'matching_words' => implode(' ', $typeWords),
            'matching_rule' => $this->getMatchingRuleDescription($type),
            'top_10_scores' => $scoredProducts->take(10)->map(function ($item) {
                return [
                    'id' => $item['product']['id'] ?? 0,
                    'score' => $item['score'],
                    'name' => $item['product']['name'] ?? '',
                    'type' => $item['product']['type'] ?? '',
                    'type_match' => $item['type_words_matched'] . '/' . $item['type_words_total'],
                    'name_match' => $item['name_match_count'] . '/' . $item['name_words_total'],
                    'matching_rule' => $item['matching_rule_applied']
                ];
            })->toArray()
        ]);

        // Extraire uniquement les produits des résultats scorés
        $rankedProducts = $scoredProducts->pluck('product')->toArray();
        $this->matchingProducts = $rankedProducts;

        // Grouper et valider avec l'IA
        $this->groupResultsByScrapeReference($this->matchingProducts);
        $this->validateBestMatchWithAI();
    }

    /**
     * NOUVELLE RÈGLE : Extrait les mots pour matching selon la règle :
     * - Si le type a plus de 3 mots significatifs → prendre 3 premiers mots
     * - Si le type a 3 mots ou moins significatifs → prendre 2 premiers mots
     */
    private function extractTypeWordsForMatching(string $type): array
    {
        if (empty($type)) {
            return [];
        }

        // Extraire tous les mots significatifs
        $allSignificantWords = $this->extractTypeWordsForDisplay($type);
        
        // Appliquer la règle
        if (count($allSignificantWords) > 3) {
            // Plus de 3 mots → prendre 3 premiers mots
            $result = array_slice($allSignificantWords, 0, 3);
            \Log::info('🔤 Règle ">3 mots → 3 mots" appliquée', [
                'type_original' => $type,
                'mots_significatifs' => $allSignificantWords,
                'nombre_mots_significatifs' => count($allSignificantWords),
                'mots_pour_matching' => $result,
                'nombre_mots_matching' => count($result)
            ]);
        } else {
            // 3 mots ou moins → prendre 2 premiers mots (ou moins si pas assez)
            $result = array_slice($allSignificantWords, 0, min(2, count($allSignificantWords)));
            \Log::info('🔤 Règle "≤3 mots → 2 mots" appliquée', [
                'type_original' => $type,
                'mots_significatifs' => $allSignificantWords,
                'nombre_mots_significatifs' => count($allSignificantWords),
                'mots_pour_matching' => $result,
                'nombre_mots_matching' => count($result)
            ]);
        }

        return $result;
    }

    /**
     * Obtient la description de la règle de matching appliquée
     */
    private function getMatchingRuleDescription(string $type): string
    {
        if (empty($type)) {
            return 'Aucun type';
        }

        $allSignificantWords = $this->extractTypeWordsForDisplay($type);
        $wordCount = count($allSignificantWords);
        
        if ($wordCount > 3) {
            return "type > 3 mots → matching sur 3 mots";
        } elseif ($wordCount > 0) {
            return "type ≤ 3 mots → matching sur " . min(2, $wordCount) . " mots";
        } else {
            return "type vide → pas de matching sur type";
        }
    }

    /**
     * Organise les résultats
     */
    private function groupResultsByScrapeReference(array $products)
    {
        if (empty($products)) {
            $this->matchingProducts = [];
            $this->groupedResults = [];
            return;
        }

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

        $this->matchingProducts = $uniqueProducts->take(200)->toArray();

        $bySiteStats = $uniqueProducts->groupBy('web_site_id')->map(function ($siteProducts, $siteId) {
            return [
                'site_id' => $siteId,
                'total_products' => $siteProducts->count(),
                'max_scrape_ref_id' => $siteProducts->max('scrape_reference_id'),
                'min_scrape_ref_id' => $siteProducts->min('scrape_reference_id'),
                'products' => $siteProducts->values()->toArray()
            ];
        });

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

        $stopWords = ['de', 'la', 'le', 'les', 'des', 'du', 'un', 'une', 'et', 'ou', 'pour', 'avec', 'sans'];
        $text = mb_strtolower($text);
        $words = preg_split('/[\s\-]+/', $text, -1, PREG_SPLIT_NO_EMPTY);

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
            return $sourceisCoffret ? $productIsCoffret : !$productIsCoffret;
        })->values()->toArray();
    }

    /**
     * Valide le meilleur match avec l'IA
     */
    private function validateBestMatchWithAI()
    {
        if (empty($this->matchingProducts)) {
            return;
        }

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
                                'content' => 'Tu es un expert en matching de produits cosmétiques. 
NOTE IMPORTANTE : Pour le matching du type, une règle spéciale a été appliquée :
- Si le type a plus de 3 mots, matching sur les 3 premiers mots
- Si le type a 3 mots ou moins, matching sur les 2 premiers mots
Réponds UNIQUEMENT avec un objet JSON.'
                            ],
                            [
                                'role' => 'user',
                                'content' => "Produit source : {$this->productName}

Critères extraits :
- Vendor: " . ($this->extractedData['vendor'] ?? 'N/A') . "
- Name: " . ($this->extractedData['name'] ?? 'N/A') . "
- Type: " . ($this->extractedData['type'] ?? 'N/A') . "
- Variation: " . ($this->extractedData['variation'] ?? 'N/A') . "

IMPORTANT : Pour le matching du type, une règle spéciale a été appliquée :
- Si le type a plus de 3 mots → matching sur 3 mots
- Si le type a 3 mots ou moins → matching sur 2 mots

Produits candidats :
" . json_encode($productsInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "

Retourne au format JSON :
{
  \"best_match_id\": 123,
  \"confidence_score\": 0.95,
  \"reasoning\": \"Explication courte\",
  \"type_matching_method\": \"regle_speciale_3_ou_2_mots\"
}"
                            ]
                        ],
                        'temperature' => 0.2,
                        'max_tokens' => 800
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
            <h2 class="text-xl font-bold text-gray-900">Recherche de produit</h2>
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
    </div>

    <livewire:plateformes.detail :id="$productId" />

    <!-- Formulaire de recherche manuelle -->
    @if($manualSearchMode)
        <div class="px-6 py-4 bg-blue-50 border-b border-blue-200">
            <h3 class="font-semibold text-gray-900 mb-3">🔍 Recherche Manuelle</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Vendor (readonly) -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Marque (Vendor)</label>
                    <input type="text" wire:model="manualVendor" readonly
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-100 text-gray-600 cursor-not-allowed">
                </div>

                <!-- Name -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nom de la gamme</label>
                    <input type="text" wire:model="manualName"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                        placeholder="Ex: J'adore, N°5, Vital Perfection">
                </div>

                <!-- Type -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Type de produit</label>
                    <input type="text" wire:model="manualType"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                        placeholder="Ex: Eau de Parfum, Crème visage">
                </div>

                <!-- Variation -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Contenance</label>
                    <input type="text" wire:model="manualVariation"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                        placeholder="Ex: 50 ml, 200 ml">
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
        <!-- Indicateur de chargement -->
        @if($isLoading)
            <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <div class="flex items-center space-x-3">
                    <div class="animate-spin rounded-full h-5 w-5 border-b-2 border-indigo-600"></div>
                    <p class="text-sm text-blue-800">
                        Extraction et recherche en cours pour "<span class="font-semibold">{{ $productName }}</span>"...
                    </p>
                </div>
            </div>
        @endif

        <!-- Statistiques (quand la recherche est terminée) -->
        @if(!empty($groupedResults) && !$isLoading)
            <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <p class="text-sm text-blue-800">
                    <span class="font-semibold">{{ count($matchingProducts) }}</span> produit(s) unique(s) trouvé(s)
                    @if(isset($groupedResults['_site_stats']))
                        (après déduplication)
                    @endif
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
            $cardClass .= " ring-2 ring-indigo-500 bg-indigo-50";
        }
                        @endphp
                        
                        @if($hasUrl)
                            <a href="{{ $product['url'] }}" target="_blank" rel="noopener noreferrer" 
                               class="{{ $cardClass }}">
                        @else
                            <div class="{{ $cardClass }}">
                        @endif
                            
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
                                <!-- Badges de matching -->
                                <div class="mb-2 flex justify-center gap-1">
                                    @php
        // Vérifier si le name matche
        $nameMatches = false;
        if (!empty($extractedData['name'])) {
            $searchNameLower = mb_strtolower($extractedData['name']);
            $productNameLower = mb_strtolower($product['name'] ?? '');
            $nameMatches = str_contains($productNameLower, $searchNameLower);
        }

        // Vérifier si le type matche
        $typeMatches = false;
        if (!empty($extractedData['type'])) {
            $searchTypeLower = mb_strtolower($extractedData['type']);
            $productTypeLower = mb_strtolower($product['type'] ?? '');
            $typeMatches = str_contains($productTypeLower, $searchTypeLower);
        }
                                    @endphp
                                    
                                    @if($nameMatches)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                            ✓ Name
                                        </span>
                                    @endif
                                    
                                    @if($typeMatches)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                            ✓ Type
                                        </span>
                                    @endif
                                </div>

                                <!-- Badge coffret -->
                                @if($product['name'] && (str_contains(strtolower($product['name']), 'coffret') || str_contains(strtolower($product['name']), 'set') || str_contains(strtolower($product['type'] ?? ''), 'coffret')))
                                    <div class="mb-2">
                                        <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-purple-100 text-purple-800">🎁 Coffret</span>
                                    </div>
                                @endif

                                <!-- Nom du produit -->
                                <h3 class="text-sm font-medium text-gray-900">
                                    {{ $product['vendor'] }}
                                </h3>
                                <p class="text-xs text-gray-600 mt-1 truncate">{{ $product['name'] }}</p>
                                
                                <!-- Type avec badge coloré -->
                                @php
        $productTypeLower = strtolower($product['type'] ?? '');
        $badgeColor = 'bg-gray-100 text-gray-800';

        if (str_contains($productTypeLower, 'eau de toilette') || str_contains($productTypeLower, 'eau de parfum')) {
            $badgeColor = 'bg-purple-100 text-purple-800';
        } elseif (str_contains($productTypeLower, 'déodorant') || str_contains($productTypeLower, 'deodorant')) {
            $badgeColor = 'bg-green-100 text-green-800';
        } elseif (str_contains($productTypeLower, 'crème') || str_contains($productTypeLower, 'creme')) {
            $badgeColor = 'bg-pink-100 text-pink-800';
        } elseif (str_contains($productTypeLower, 'huile')) {
            $badgeColor = 'bg-yellow-100 text-yellow-800';
        }
                                @endphp
                                
                                <div class="mt-1">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $badgeColor }}">
                                        {{ $product['type'] }}
                                    </span>
                                </div>
                                
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
                                    {{ number_format((float) ($product['prix_ht'] ?? 0), 2) }} €
                                </p>

                                <!-- Bouton voir produit -->
                                @if($hasUrl)
                                    <div class="mt-2">
                                        <span class="inline-flex items-center text-xs font-medium text-indigo-600">
                                            Ouvrir dans un nouvel onglet
                                            <svg class="ml-1 h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                            </svg>
                                        </span>
                                    </div>
                                @else
                                    <div class="mt-2">
                                        <span class="inline-flex items-center text-xs font-medium text-gray-400">
                                            URL non disponible
                                        </span>
                                    </div>
                                @endif

                                <!-- ID scrape -->
                                @if(isset($product['scrape_reference_id']))
                                    <p class="text-xs text-gray-400 mt-2">Scrape ID: {{ $product['scrape_reference_id'] }}</p>
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
            <!-- État de chargement -->
            <div class="text-center py-12 bg-gray-50 rounded-lg border-2 border-dashed border-gray-300">
                <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-indigo-600 mx-auto"></div>
                <h3 class="mt-4 text-sm font-medium text-gray-900">Extraction en cours</h3>
                <p class="mt-1 text-sm text-gray-500">Analyse du produit et recherche des correspondances...</p>
            </div>
        @elseif($extractedData && empty($matchingProducts))
            <!-- Aucun résultat -->
            <div class="text-center py-12 bg-gray-50 rounded-lg border-2 border-dashed border-gray-300">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <h3 class="mt-2 text-sm font-medium text-gray-900">Aucun produit trouvé</h3>
                <p class="mt-1 text-sm text-gray-500">Essayez de modifier les filtres par site ou utilisez la recherche manuelle</p>
            </div>
        @else
            <!-- État initial (avant chargement) -->
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
