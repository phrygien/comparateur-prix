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
                                'content' => 'Tu es un expert en extraction de données de produits cosmétiques. IMPORTANT: Le champ "type" doit contenir UNIQUEMENT la catégorie du produit (Crème, Huile, Sérum, Eau de Parfum, Baume, etc.), PAS le nom de la gamme. Réponds UNIQUEMENT avec un objet JSON valide, sans markdown ni texte supplémentaire.'
                            ],
                            [
                                'role' => 'user',
                                'content' => "Extrait les informations suivantes du nom de produit et retourne-les au format JSON strict :

RÈGLES IMPORTANTES :
- vendor : la marque du produit (ex: Dior, Shiseido, Chanel, Clarins)
- name : le nom de la gamme/ligne de produit UNIQUEMENT - C'EST LE PLUS IMPORTANT (ex: \"J'adore\", \"Vital Perfection\", \"Multi-Intensive\", \"Les essentiels\")
- type : UNIQUEMENT la catégorie/type du produit (ex: \"Huile pour le corps\", \"Eau de Parfum\", \"Crème visage\", \"Baume lèvres\", \"Coffret\")
- variation : la contenance/taille avec unité (ex: \"200 ml\", \"50 ml\", \"30 g\")
- is_coffret : true si c'est un coffret/set/kit, false sinon

RÈGLE CRITIQUE POUR LE 'name' :
- Le 'name' doit être le NOM COMMERCIAL/GAMME du produit, PAS une description
- Cherche le nom propre ou la ligne de produit (souvent en majuscules ou après un tiret)
- Exemples : \"Multi-Intensive\", \"Les essentiels\", \"ClarinsMen\", \"J'adore\", \"N°5\"
- NE PAS mettre de descriptions génériques comme \"Crème visage\" dans le name

RÈGLE IMPORTANTE POUR CLARINS :
- Pour Clarins, le \"name\" doit être le nom de la gamme sans les mots génériques
- Exemple : \"Clarins - Body Fit Active 200 ml\" → name: \"Body Fit Active\" (garder les 3 mots)
- Exemple : \"Clarins Body Fit Anti-Eau Soin minceur\" → name: \"Body Fit Anti-Eau\"
- Ne pas raccourcir les noms composés de plusieurs mots

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

Exemple 5 - Produit : \"Clarins - Les essentiels ClarinsMen\"
{
  \"vendor\": \"Clarins\",
  \"name\": \"Les essentiels ClarinsMen\",
  \"type\": \"Coffret\",
  \"variation\": \"\",
  \"is_coffret\": true
}

Exemple 6 - Produit : \"Clarins - Coffret Multi-Intensive Crème visage anti-rides\"
{
  \"vendor\": \"Clarins\",
  \"name\": \"Multi-Intensive\",
  \"type\": \"Coffret Crème visage\",
  \"variation\": \"\",
  \"is_coffret\": true
}

Exemple 7 - Produit : \"Clarins Baume Beauté Éclair Soin illuminateur instantané 50ml\"
{
  \"vendor\": \"Clarins\",
  \"name\": \"Baume Beauté Éclair\",
  \"type\": \"Soin illuminateur\",
  \"variation\": \"50 ml\",
  \"is_coffret\": false
}

Exemple 8 - Produit : \"Clarins - Hydra-Essentiel [HA²+ PEPTIDE] - Baume lèvres réparateur 15 ml\"
{
  \"vendor\": \"Clarins\",
  \"name\": \"Hydra-Essentiel [HA²+ PEPTIDE]\",
  \"type\": \"Baume lèvres réparateur\",
  \"variation\": \"15 ml\",
  \"is_coffret\": false
}

Exemple 9 - Produit : \"Clarins Baume Corps Eclat Suprême 200 ml\"
{
  \"vendor\": \"Clarins\",
  \"name\": \"Eclat Suprême\",
  \"type\": \"Baume Corps\",
  \"variation\": \"200 ml\",
  \"is_coffret\": false
}

Exemple 10 - Produit : \"Clarins - Body Fit Active 200 ml\"
{
  \"vendor\": \"Clarins\",
  \"name\": \"Body Fit Active\",
  \"type\": \"Soin corps\",
  \"variation\": \"200 ml\",
  \"is_coffret\": false
}

Exemple 11 - Produit : \"Clarins Body Fit Anti-Eau Soin minceur 200ml\"
{
  \"vendor\": \"Clarins\",
  \"name\": \"Body Fit Anti-Eau\",
  \"type\": \"Soin minceur\",
  \"variation\": \"200 ml\",
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

                // Post-traitement : Correction spécifique pour les baumes
                if (!empty($this->extractedData['type'])) {
                    // Correction spécifique pour les baumes
                    $this->extractedData['type'] = $this->correctProductType(
                        $this->extractedData['type'],
                        $this->productName
                    );
                    
                    // Mettre à jour le champ manuel aussi
                    $this->manualType = $this->extractedData['type'];
                    
                    \Log::info('Type après correction', [
                        'type_original' => $this->extractedData['type'] ?? '',
                        'type_corrigé' => $this->extractedData['type'] ?? '',
                        'product_name' => $this->productName
                    ]);
                }

                \Log::info('Données extraites', [
                    'vendor' => $this->extractedData['vendor'] ?? '',
                    'name' => $this->extractedData['name'] ?? '',
                    'type' => $this->extractedData['type'] ?? '',
                    'variation' => $this->extractedData['variation'] ?? '',
                    'is_coffret' => $this->extractedData['is_coffret'] ?? false
                ]);

                // ✨ NOUVEAU CLARINS : Post-traitement du name pour Clarins
                if (!empty($this->extractedData['vendor']) && 
                    str_contains(mb_strtolower($this->extractedData['vendor']), 'clarins')) {
                    
                    $originalName = $this->extractedData['name'];
                    $this->extractedData['name'] = $this->cleanClarinsName(
                        $this->extractedData['name'], 
                        $this->extractedData['type']
                    );
                    
                    // Mettre à jour le champ manuel aussi
                    $this->manualName = $this->extractedData['name'];
                    
                    if ($originalName !== $this->extractedData['name']) {
                        \Log::info('📝 CLARINS - Name modifié après nettoyage', [
                            'name_avant' => $originalName,
                            'name_après' => $this->extractedData['name']
                        ]);
                    }
                }

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
     * ✨ NOUVEAU CLARINS : Nettoie le nom extrait pour les produits Clarins
     * Enlève les mots génériques qui ne devraient pas être dans le name
     */
    private function cleanClarinsName(string $name, string $type): string
    {
        $nameLower = mb_strtolower(trim($name));
        
        // Mots à enlever du name s'ils y sont (ce sont des mots de TYPE, pas de NAME)
        $typeWords = [
            'coffret',
            'set',
            'kit',
            'crème',
            'creme',
            'soin',
            'huile',
            'sérum',
            'serum',
            'lotion',
            'gel',
            'masque',
            // 'baume', // NE PAS ENLEVER "baume" - il fait partie du type, pas du name
            'eau',
            'parfum',
            'visage',
            'corps',
            'yeux',
            'anti-rides',
            'anti-âge',
            'hydratant',
            'nourrissant'
        ];
        
        // Ne pas nettoyer si le name contient "essentiels" (c'est un vrai nom de gamme)
        $essentielsKeywords = ['essentiels', 'essentials', 'essentiel', 'essential'];
        foreach ($essentielsKeywords as $keyword) {
            if (str_contains($nameLower, $keyword)) {
                \Log::info('✅ CLARINS - Name contient "essentiels", pas de nettoyage', [
                    'name_original' => $name
                ]);
                return $name;
            }
        }
        
        // Nettoyer le name
        $cleanedName = $name;
        $originalName = $name;
        $modified = false;
        
        foreach ($typeWords as $word) {
            $pattern = '/\b' . preg_quote($word, '/') . '\b/i';
            $beforeClean = $cleanedName;
            $cleanedName = preg_replace($pattern, '', $cleanedName);
            $cleanedName = preg_replace('/\s+/', ' ', $cleanedName); // Nettoyer espaces multiples
            $cleanedName = trim($cleanedName);
            
            if ($beforeClean !== $cleanedName) {
                $modified = true;
            }
        }
        
        // Si le name a été vidé, garder l'original
        if (empty($cleanedName) || mb_strlen($cleanedName) < 3) {
            \Log::warning('⚠️ CLARINS - Nettoyage aurait vidé le name, conservation de l\'original', [
                'name_original' => $originalName,
                'name_nettoyé' => $cleanedName
            ]);
            return $originalName;
        }
        
        if ($modified) {
            \Log::info('✅ CLARINS - Name nettoyé', [
                'name_original' => $originalName,
                'name_nettoyé' => $cleanedName
            ]);
        }
        
        return $cleanedName;
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
     * LOGIQUE DE RECHERCHE STRICTE : NAME ET TYPE doivent matcher
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

        // Normaliser le type
        $normalizedType = $this->normalizeProductType($type);
        
        // Extraire les mots-clés du name
        $nameWords = $this->extractKeywords($name, false, false);
        
        // Extraire les parties du type pour matching
        $typeParts = $this->extractTypeParts($normalizedType);

        \Log::info('🔍 RECHERCHE STRICTE - NAME ET TYPE OBLIGATOIRES', [
            'product_name_source' => $this->productName,
            'vendor' => $vendor,
            'name' => $name,
            'name_words' => $nameWords,
            'type' => $type,
            'normalized_type' => $normalizedType,
            'type_parts' => $typeParts,
            'is_coffret' => $isCoffretSource
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
            \Log::info('Aucun produit trouvé pour le vendor: ' . $vendor);
            $this->matchingProducts = [];
            return;
        }

        \Log::info('Produits trouvés pour le vendor', [
            'vendor' => $vendor,
            'count' => $vendorProducts->count()
        ]);

        // ÉTAPE 2: Filtrer par statut coffret (OBLIGATOIRE)
        $filteredProducts = $this->filterByCoffretStatus($vendorProducts, $isCoffretSource);

        if (empty($filteredProducts)) {
            \Log::info('Aucun produit après filtrage coffret');
            $this->matchingProducts = [];
            return;
        }

        \Log::info('Produits après filtrage coffret', [
            'count' => count($filteredProducts),
            'recherche_coffret' => $isCoffretSource
        ]);

        // ÉTAPE 3: FILTRAGE STRICT par NAME (OBLIGATOIRE)
        $nameFilteredProducts = [];
        
        if (!empty($nameWords)) {
            // RÈGLE STRICTE : TOUS les mots du name doivent matcher
            $nameFilteredProducts = collect($filteredProducts)->filter(function ($product) use ($nameWords) {
                $productName = mb_strtolower($product['name'] ?? '');
                
                // Vérifier que TOUS les mots du name sont présents
                $allWordsMatch = true;
                foreach ($nameWords as $word) {
                    if (!str_contains($productName, $word)) {
                        $allWordsMatch = false;
                        break;
                    }
                }
                
                return $allWordsMatch;
            })->values()->toArray();
            
            \Log::info('Produits après filtrage STRICT par NAME', [
                'count' => count($nameFilteredProducts),
                'name_words_requis' => $nameWords,
                'name_recherché' => $name
            ]);
            
            $filteredProducts = $nameFilteredProducts;
        } else {
            \Log::warning('Aucun mot-clé de name à rechercher');
            $this->matchingProducts = [];
            return;
        }

        if (empty($filteredProducts)) {
            \Log::info('Aucun produit après filtrage strict par NAME');
            $this->matchingProducts = [];
            return;
        }

        // ÉTAPE 4: FILTRAGE STRICT par TYPE (OBLIGATOIRE)
        $typeFilteredProducts = [];
        
        if (!empty($normalizedType)) {
            // RÈGLE STRICTE : Le type doit matcher
            $typeFilteredProducts = collect($filteredProducts)->filter(function ($product) use ($normalizedType, $typeParts) {
                $productType = mb_strtolower($product['type'] ?? '');
                $searchTypeLower = mb_strtolower($normalizedType);
                
                // Vérification 1: Type exact ou contenu
                $typeMatches = str_contains($productType, $searchTypeLower) || 
                               str_contains($searchTypeLower, $productType);
                
                // Vérification 2: Toutes les parties du type doivent matcher
                if (!$typeMatches && !empty($typeParts)) {
                    $allPartsMatch = true;
                    foreach ($typeParts as $part) {
                        $partLower = mb_strtolower(trim($part));
                        if (!empty($partLower) && !str_contains($productType, $partLower)) {
                            $allPartsMatch = false;
                            break;
                        }
                    }
                    $typeMatches = $allPartsMatch;
                }
                
                // Log détaillé pour le débogage
                if (!$typeMatches) {
                    \Log::debug('Produit exclu par TYPE', [
                        'product_id' => $product['id'] ?? 0,
                        'product_name' => $product['name'] ?? '',
                        'product_type' => $product['type'] ?? '',
                        'type_recherché' => $normalizedType,
                        'type_parts_recherchés' => $typeParts,
                        'product_type_lower' => $productType,
                        'search_type_lower' => $searchTypeLower,
                        'contains_check' => str_contains($productType, $searchTypeLower),
                        'reverse_check' => str_contains($searchTypeLower, $productType)
                    ]);
                }
                
                return $typeMatches;
            })->values()->toArray();
            
            \Log::info('Produits après filtrage STRICT par TYPE', [
                'count' => count($typeFilteredProducts),
                'type_recherché' => $normalizedType,
                'type_parts' => $typeParts
            ]);
            
            $filteredProducts = $typeFilteredProducts;
        } else {
            \Log::warning('Type non spécifié pour la recherche');
            $this->matchingProducts = [];
            return;
        }

        if (empty($filteredProducts)) {
            \Log::info('Aucun produit après filtrage strict par TYPE');
            $this->matchingProducts = [];
            return;
        }

        // ÉTAPE 5: SCORING pour classer les résultats
        $scoredProducts = collect($filteredProducts)->map(function ($product) use ($nameWords, $normalizedType, $typeParts, $isCoffretSource) {
            $score = 0;
            $productType = mb_strtolower($product['type'] ?? '');
            $productName = mb_strtolower($product['name'] ?? '');
            $searchTypeLower = mb_strtolower($normalizedType);
            
            // ==========================================
            // BONUS NAME : TOUS les mots doivent déjà matcher (pré-filtré)
            // ==========================================
            $nameMatchCount = 0;
            $matchedNameWords = [];
            
            foreach ($nameWords as $word) {
                if (str_contains($productName, $word)) {
                    $nameMatchCount++;
                    $matchedNameWords[] = $word;
                }
            }
            
            // Bonus proportionnel (déjà tous matchés, donc max)
            $nameBonus = 300;
            $score += $nameBonus;
            
            // Bonus supplémentaire pour match exact du name
            $normalizedProductName = $this->normalizeProductName($product['name'] ?? '');
            $normalizedSearchName = $this->normalizeProductName($this->extractedData['name'] ?? '');
            
            if ($normalizedProductName === $normalizedSearchName) {
                $score += 200;
            }

            // ==========================================
            // BONUS TYPE : Le type doit déjà matcher (pré-filtré)
            // ==========================================
            $typeMatchBonus = 0;
            
            // Bonus pour type exact
            if ($productType === $searchTypeLower) {
                $typeMatchBonus += 300;
            } 
            // Bonus pour type contenu
            else if (str_contains($productType, $searchTypeLower) || str_contains($searchTypeLower, $productType)) {
                $typeMatchBonus += 200;
            }
            
            // Bonus pour chaque partie du type qui match
            foreach ($typeParts as $index => $part) {
                $partLower = mb_strtolower(trim($part));
                if (!empty($partLower) && str_contains($productType, $partLower)) {
                    $typeMatchBonus += 100 - ($index * 20);
                }
            }
            
            $score += $typeMatchBonus;

            // ==========================================
            // BONUS COFFRET
            // ==========================================
            $productIsCoffret = $this->isCoffret($product);
            
            if ($isCoffretSource && $productIsCoffret) {
                $score += 500;
            } else if (!$isCoffretSource && !$productIsCoffret) {
                $score += 100;
            }

            // ==========================================
            // BONUS SPÉCIFIQUES
            // ==========================================
            // Bonus pour les baumes
            $isBaumeType = str_contains($searchTypeLower, 'baume');
            $productIsBaumeType = str_contains($productType, 'baume');
            
            if ($isBaumeType && $productIsBaumeType) {
                $score += 150;
            }
            
            // Bonus pour Body Fit
            $isBodyFit = str_contains(mb_strtolower($this->extractedData['name'] ?? ''), 'body fit');
            $productIsBodyFit = str_contains($productName, 'body fit');
            
            if ($isBodyFit && $productIsBodyFit) {
                $score += 200;
            }
            
            // Bonus pour Clarins
            if (str_contains(mb_strtolower($vendor), 'clarins') && 
                str_contains(mb_strtolower($product['vendor'] ?? ''), 'clarins')) {
                $score += 50;
            }

            return [
                'product' => $product,
                'score' => $score,
                'name_match_count' => $nameMatchCount,
                'name_words_total' => count($nameWords),
                'matched_name_words' => $matchedNameWords,
                'type_match_bonus' => $typeMatchBonus,
                'is_coffret' => $productIsCoffret,
                'coffret_match' => ($isCoffretSource && $productIsCoffret) || (!$isCoffretSource && !$productIsCoffret)
            ];
        })
        ->sortByDesc('score')
        ->values();

        \Log::info('Scoring final (NAME ET TYPE OBLIGATOIRES)', [
            'total_products' => $scoredProducts->count(),
            'type_recherche' => $normalizedType,
            'name_words' => $nameWords,
            'top_scores' => $scoredProducts->take(5)->map(function($item) {
                return [
                    'id' => $item['product']['id'] ?? 0,
                    'score' => $item['score'],
                    'name' => $item['product']['name'] ?? '',
                    'type' => $item['product']['type'] ?? '',
                    'name_match' => $item['name_match_count'] . '/' . $item['name_words_total'],
                    'type_bonus' => $item['type_match_bonus'],
                    'coffret_match' => $item['coffret_match']
                ];
            })->toArray()
        ]);

        // FILTRAGE FINAL : Score minimum requis
        $finalProducts = $scoredProducts->filter(function($item) {
            // Score minimum pour être considéré
            return $item['score'] >= 400; // Score minimum ajustable
        });

        if ($finalProducts->isEmpty()) {
            \Log::info('Aucun produit avec score suffisant');
            $this->matchingProducts = [];
            $this->groupedResults = [];
            return;
        }

        $rankedProducts = $finalProducts->pluck('product')->toArray();
        $this->matchingProducts = $rankedProducts;

        \Log::info('Produits finaux après filtrage strict', [
            'count' => count($this->matchingProducts),
            'best_score' => $finalProducts->first()['score'] ?? 0
        ]);

        $this->groupResultsByScrapeReference($this->matchingProducts);
        $this->validateBestMatchWithAI();
    }

    /**
     * Extrait les parties d'un type pour matching hiérarchique
     */
    private function extractTypeParts(string $type): array
    {
        if (empty($type)) {
            return [];
        }

        $separators = [' - ', ' / ', ' + ', ', ', ' et ', ' & '];
        
        $normalized = $type;
        foreach ($separators as $separator) {
            $normalized = str_replace($separator, '|', $normalized);
        }
        
        $parts = explode('|', $normalized);
        $parts = array_map('trim', $parts);
        $parts = array_filter($parts, function($part) {
            return !empty($part);
        });
        
        return array_values($parts);
    }

    /**
     * Organise les résultats en ne gardant que le dernier scrape_reference_id
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

        \Log::info('Avant déduplication des résultats', [
            'total_produits' => $productsCollection->count()
        ]);

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
            'produits_apres' => $uniqueProducts->count(),
            'produits_supprimés' => $productsCollection->count() - $uniqueProducts->count()
        ]);

        $this->matchingProducts = $uniqueProducts->take(200)->toArray();

        \Log::info('Résultats finaux après déduplication', [
            'total_produits' => count($this->matchingProducts),
            'par_site' => $uniqueProducts->groupBy('web_site_id')->map(fn($group) => $group->count())->toArray()
        ]);

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
    }

    /**
     * Extrait les mots-clés significatifs
     */
    private function extractKeywords(string $text, bool $isSpecialVendor = false, bool $isClarinsEssentiels = false): array
    {
        if (empty($text)) {
            return [];
        }

        // Stop words de base
        $stopWords = ['de', 'la', 'le', 'les', 'des', 'du', 'un', 'une', 'et', 'ou', 'pour', 'avec', 'sans'];
        
        $text = mb_strtolower($text);
        $words = preg_split('/[\s\-]+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        $keywords = array_filter($words, function ($word) use ($stopWords, $isClarinsEssentiels) {
            // ✨ Pour Clarins Essentiels, toujours garder "essentiels"
            if ($isClarinsEssentiels) {
                $essentielsVariants = ['essentiels', 'essentials', 'essentiel', 'essential'];
                if (in_array($word, $essentielsVariants)) {
                    return true;
                }
            }
            
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
     * Utilise OpenAI pour valider le meilleur match
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
                                'content' => 'Tu es un expert en matching de produits cosmétiques. Tu dois analyser la correspondance entre un produit source et une liste de candidats, puis retourner l\'ID du meilleur match avec un score de confiance. Réponds UNIQUEMENT avec un objet JSON.'
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
                'message' => $e->getMessage(),
                'product_name' => $this->productName
            ]);

            $this->bestMatch = $this->matchingProducts[0] ?? null;
        }
    }

    /**
     * ✨ NOUVEAU : Correction spécifique pour le type de produit
     */
    private function correctProductType(string $type, string $productName): string
    {
        $typeLower = mb_strtolower(trim($type));
        $productNameLower = mb_strtolower(trim($productName));
        
        // Détecter les baumes à lèvres
        $lipBaumeKeywords = ['lèvres', 'levres', 'lip'];
        $isLipBaume = false;
        
        foreach ($lipBaumeKeywords as $keyword) {
            if (str_contains($productNameLower, $keyword) && 
                (str_contains($typeLower, 'baume') || str_contains($typeLower, 'crème') || str_contains($typeLower, 'soin'))) {
                $isLipBaume = true;
                break;
            }
        }
        
        if ($isLipBaume) {
            // S'assurer que le type contient "Baume lèvres"
            if (!str_contains($typeLower, 'baume')) {
                $type = 'Baume lèvres';
                
                if (str_contains($productNameLower, 'réparateur') || str_contains($productNameLower, 'reparateur')) {
                    $type .= ' réparateur';
                } elseif (str_contains($productNameLower, 'nourrissant')) {
                    $type .= ' nourrissant';
                } elseif (str_contains($productNameLower, 'hydratant')) {
                    $type .= ' hydratant';
                }
            }
        }
        
        // Détecter les baumes corps
        $bodyBaumeKeywords = ['corps', 'body'];
        $isBodyBaume = false;
        
        foreach ($bodyBaumeKeywords as $keyword) {
            if (str_contains($productNameLower, $keyword) && 
                (str_contains($typeLower, 'baume') || str_contains($typeLower, 'crème') || str_contains($typeLower, 'soin'))) {
                $isBodyBaume = true;
                break;
            }
        }
        
        if ($isBodyBaume) {
            if (!str_contains($typeLower, 'baume')) {
                $type = 'Baume corps';
                
                if (str_contains($productNameLower, 'hydratant')) {
                    $type .= ' hydratant';
                } elseif (str_contains($productNameLower, 'nourrissant')) {
                    $type .= ' nourrissant';
                } elseif (str_contains($productNameLower, 'apaisant')) {
                    $type .= ' apaisant';
                }
            }
        }
        
        // Détecter Body Fit
        if (str_contains($productNameLower, 'body fit')) {
            if (!str_contains($typeLower, 'soin') && !str_contains($typeLower, 'crème') && !str_contains($typeLower, 'baume')) {
                $type = 'Soin corps';
                
                if (str_contains($productNameLower, 'minceur') || str_contains($productNameLower, 'anti-capitons')) {
                    $type = 'Soin minceur';
                }
            }
        }
        
        // Normalisation
        $type = $this->normalizeProductType($type);
        
        return $type;
    }

    /**
     * ✨ NOUVEAU : Normalise les types de produits
     */
    private function normalizeProductType(string $type): string
    {
        $typeLower = mb_strtolower(trim($type));
        
        // Mapping des types similaires
        $typeMapping = [
            'crème' => ['creme', 'cream'],
            'baume' => ['balm', 'balsam'],
            'sérum' => ['serum'],
            'lotion' => ['lotion', 'tonique'],
            'gel' => ['gel', 'jelly'],
            'eau' => ['water', 'eau'],
            'parfum' => ['perfume', 'fragrance'],
            'lèvres' => ['levres', 'lip'],
            'corps' => ['body'],
            'visage' => ['face'],
            'yeux' => ['eye'],
            'mains' => ['hand'],
            'soin' => ['care', 'treatment'],
        ];
        
        $normalizedType = $type;
        
        foreach ($typeMapping as $normalized => $variants) {
            foreach ($variants as $variant) {
                if (str_contains($typeLower, $variant)) {
                    $pattern = '/\b' . preg_quote($variant, '/') . '\b/i';
                    $normalizedType = preg_replace($pattern, $normalized, $normalizedType);
                    break;
                }
            }
        }
        
        // Capitaliser les mots importants
        $words = explode(' ', $normalizedType);
        $words = array_map(function($word) {
            if (in_array(mb_strtolower($word), ['de', 'pour', 'et', 'avec', 'sans', 'le', 'la', 'les'])) {
                return $word;
            }
            return mb_convert_case($word, MB_CASE_TITLE, 'UTF-8');
        }, $words);
        
        $normalizedType = implode(' ', $words);
        
        if ($normalizedType !== $type) {
            \Log::info('Type normalisé', [
                'type_original' => $type,
                'type_normalisé' => $normalizedType
            ]);
        }
        
        return $normalizedType;
    }

    /**
     * Normalise un nom de produit pour la comparaison
     */
    private function normalizeProductName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        
        // Enlever les caractères spéciaux
        $name = preg_replace('/[^\w\s\-]/', '', $name);
        
        // Enlever les espaces multiples
        $name = preg_replace('/\s+/', ' ', $name);
        
        return trim($name);
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
            <h3 class="font-semibold text-gray-900 mb-3">Recherche Manuelle</h3>
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
                    <span wire:loading.remove>Lancer la recherche</span>
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

        <!-- Section des produits GROUPÉS PAR SITE -->
        @if(!empty($matchingProducts) && !$isLoading)
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-8">
                <h2 class="sr-only">Produits</h2>

                @php
                    // Grouper les produits par site
                    $productsBySite = collect($matchingProducts)->groupBy('web_site_id');
                @endphp

                @foreach($productsBySite as $siteId => $siteProducts)
                    @php
                        $siteInfo = collect($availableSites)->firstWhere('id', $siteId);
                    @endphp
                    
                    <!-- En-tête de section par site -->
                    <div class="mb-8">
                        <div class="flex items-center justify-between mb-4 pb-3 border-b-2 border-gray-300">
                            <h3 class="text-lg font-bold text-gray-900">
                                @if($siteInfo)
                                    {{ $siteInfo['name'] }}
                                @else
                                    Site inconnu
                                @endif
                            </h3>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-indigo-100 text-indigo-800">
                                {{ $siteProducts->count() }} produit(s)
                            </span>
                        </div>

                        <!-- Grille des produits pour ce site -->
                        <div class="-mx-px grid grid-cols-2 border-l border-gray-200 sm:mx-0 md:grid-cols-3 lg:grid-cols-4">
                            @foreach($siteProducts as $product)
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

                                        <!-- Prix -->
                                        <p class="mt-4 text-base font-medium text-gray-900">
                                            {{ number_format((float)($product['prix_ht'] ?? 0), 2) }} €
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
                @endforeach
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