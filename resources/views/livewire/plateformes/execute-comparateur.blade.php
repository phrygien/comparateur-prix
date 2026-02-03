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

    public $manualSearchMode = false;
    public $manualVendor = '';
    public $manualName = '';
    public $manualType = '';
    public $manualVariation = '';

    public $activeTab = 'all';

    public function mount($name, $id, $price): void
    {
        $this->productName = $name;
        $this->productId = $id;
        $this->productPrice = $price;

        $this->availableSites = Site::orderBy('name')->get()->toArray();
        $this->selectedSites = collect($this->availableSites)->pluck('id')->toArray();
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
- variation : la contenance/taille avec unité (ex: \"200 ml\", \"50 ml\", \"30 g\"). Si pas de contenance visible, retourne une chaîne vide.
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

                $this->manualVendor = $this->extractedData['vendor'] ?? '';
                $this->manualName = $this->extractedData['name'] ?? '';
                $this->manualType = $this->extractedData['type'] ?? '';
                $this->manualVariation = $this->extractedData['variation'] ?? '';

                if (!empty($this->extractedData['type'])) {
                    $type = $this->extractedData['type'];

                    if (!empty($this->extractedData['name'])) {
                        $name = $this->extractedData['name'];
                        $type = trim(str_ireplace($name, '', $type));
                    }

                    $type = preg_replace('/\s*-\s*/', ' ', $type);
                    $type = preg_replace('/\s+/', ' ', $type);

                    $this->extractedData['type'] = trim($type);
                    $this->manualType = $this->extractedData['type'];
                }

                if ($this->isHermesProduct($this->extractedData['vendor'] ?? '')) {
                    $originalName = $this->extractedData['name'];
                    $this->extractedData['name'] = $this->cleanHermesName(
                        $this->extractedData['name'],
                        $this->extractedData['type']
                    );

                    if ($originalName !== $this->extractedData['name']) {
                        \Log::info('🧹 HERMÈS - Nettoyage du NAME détecté', [
                            'name_original' => $originalName,
                            'name_nettoyé' => $this->extractedData['name'],
                            'mots_retirés' => array_diff(
                                explode(' ', mb_strtolower($originalName)),
                                explode(' ', mb_strtolower($this->extractedData['name']))
                            )
                        ]);

                        $this->manualName = $this->extractedData['name'];
                    }
                }

                \Log::info('Données extraites', [
                    'vendor' => $this->extractedData['vendor'] ?? '',
                    'name' => $this->extractedData['name'] ?? '',
                    'type' => $this->extractedData['type'] ?? '',
                    'variation' => $this->extractedData['variation'] ?? '',
                    'variation_normalisée' => $this->normalizeVariation($this->extractedData['variation'] ?? ''),
                    'is_coffret' => $this->extractedData['is_coffret'] ?? false
                ]);

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

    public function manualSearch()
    {
        $this->isLoading = true;
        $this->matchingProducts = [];
        $this->bestMatch = null;
        $this->aiValidation = null;
        $this->groupedResults = [];

        try {
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
                'variation' => $this->extractedData['variation'],
                'variation_normalisée' => $this->normalizeVariation($this->extractedData['variation'])
            ]);

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

    public function toggleManualSearch()
    {
        $this->manualSearchMode = !$this->manualSearchMode;
    }

    // ─── VARIATION HELPERS ────────────────────────────────────────────────────

    /**
     * Normalise une variation brute en ['value' => float, 'unit' => string]
     * Exemples : "200ml" → ['value'=>200,'unit'=>'ml']
     *            "50 g"  → ['value'=>50, 'unit'=>'g']
     *            "1,5 oz" → ['value'=>1.5,'unit'=>'oz']
     *            ""       → null
     */
    private function normalizeVariation(string $variation): ?array
    {
        $variation = trim($variation);
        if (empty($variation)) {
            return null;
        }

        // Chercher un nombre (entier ou décimal avec . ou ,) suivi d'une unité
        if (preg_match('/^([\d]+[.,]?[\d]*)\s*(ml|g|gr|oz|fl\.?\s*oz|cc|l|kg)$/i', $variation, $matches)) {
            $value = (float) str_replace(',', '.', $matches[1]);
            $unit = mb_strtolower(trim($matches[2]));

            // Normaliser les unités
            $unit = match(true) {
                in_array($unit, ['gr']) => 'g',
                in_array($unit, ['cc']) => 'ml',
                str_starts_with($unit, 'fl') => 'fl oz',
                default => $unit
            };

            return ['value' => $value, 'unit' => $unit];
        }

        return null;
    }

    /**
     * Compare deux variations normalisées.
     * Retourne :
     *   'exact'   → même valeur, même unité
     *   'close'   → même unité, différence ≤ 10%
     *   'unit_only' → même unité mais valeurs très différentes
     *   'none'    → unités différentes ou impossible à comparer
     */
    private function compareVariations(?array $source, ?array $product): string
    {
        if ($source === null || $product === null) {
            return 'none';
        }

        if ($source['unit'] !== $product['unit']) {
            return 'none';
        }

        if ($source['value'] === $product['value']) {
            return 'exact';
        }

        // Éviter division par zéro
        if ($source['value'] == 0) {
            return 'unit_only';
        }

        $diff = abs($source['value'] - $product['value']) / $source['value'];

        if ($diff <= 0.10) {
            return 'close';
        }

        return 'unit_only';
    }

    // ─── COFFRET HELPERS ──────────────────────────────────────────────────────

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

    private function isCoffret($product): bool
    {
        $cofferKeywords = ['coffret', 'set', 'kit', 'duo', 'trio', 'collection'];

        $nameCheck = false;
        $typeCheck = false;

        if (isset($product['name'])) {
            $nameLower = mb_strtolower($product['name']);
            foreach ($cofferKeywords as $keyword) {
                if (str_contains($nameLower, $keyword)) {
                    $nameCheck = true;
                    break;
                }
            }
        }

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

    // ─── VENDOR / PRODUCT TYPE HELPERS ────────────────────────────────────────

    private function isSpecialVendor(string $vendor): bool
    {
        $specialVendors = ['valentino', 'valent', 'hermès', 'hermes'];
        $vendorLower = mb_strtolower(trim($vendor));

        foreach ($specialVendors as $special) {
            if (str_contains($vendorLower, $special)) {
                return true;
            }
        }

        return false;
    }

    private function isHermesProduct(string $vendor): bool
    {
        $vendorLower = mb_strtolower(trim($vendor));
        return str_contains($vendorLower, 'hermès') || str_contains($vendorLower, 'hermes');
    }

    private function cleanHermesName(string $name, string $type): string
    {
        $typeKeywords = [
            'eau', 'parfum', 'toilette', 'cologne',
            'vaporisateur', 'spray', 'extrait', 'fraiche', 'fraîche'
        ];

        $words = preg_split('/[\s\-]+/', mb_strtolower(trim($name)), -1, PREG_SPLIT_NO_EMPTY);
        $originalWords = preg_split('/[\s\-]+/', $name, -1, PREG_SPLIT_NO_EMPTY);

        $cleanedWords = [];
        foreach ($words as $index => $wordLower) {
            if (!in_array($wordLower, $typeKeywords)) {
                $cleanedWords[] = $originalWords[$index];
            }
        }

        $cleanedName = implode(' ', $cleanedWords);
        return !empty($cleanedName) ? $cleanedName : $name;
    }

    private function isBareniaProduct(string $name, string $type): bool
    {
        return str_contains(mb_strtolower($name), 'barenia') || str_contains(mb_strtolower($type), 'barenia');
    }

    private function isMeteoritesProduct(string $name, string $type): bool
    {
        $nameLower = mb_strtolower($name);
        $typeLower = mb_strtolower($type);

        foreach (['meteorites', 'météorites'] as $keyword) {
            if (str_contains($nameLower, $keyword) || str_contains($typeLower, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function isLimitedEdition(string $name, string $type): bool
    {
        $combinedText = mb_strtolower($name . ' ' . $type);

        $limitedKeywords = [
            'édition limitée', 'edition limitée', 'édition limite',
            'limited edition', 'édition spéciale', 'special edition',
            'blooming glow', 'midnight glow', 'phoenix',
            'collector', 'exclusive', 'barenia'
        ];

        foreach ($limitedKeywords as $keyword) {
            if (str_contains($combinedText, $keyword)) {
                return true;
            }
        }

        return false;
    }

    // ─── VALENTINO / HERMÈS MATCH VALIDATORS ─────────────────────────────────

    private function isValidValentinoSingleWordMatch(string $searchName, string $productName, string $productType): bool
    {
        $searchWords = $this->extractKeywords($searchName, true);

        if (count($searchWords) > 1) {
            return true;
        }

        $searchWordLower = mb_strtolower($searchWords[0]);

        $productNameWords = preg_split('/[\s\-]+/', mb_strtolower(trim($productName)), -1, PREG_SPLIT_NO_EMPTY);
        $productNameWords = array_values(array_filter($productNameWords, fn($w) => mb_strlen($w) >= 3));

        if (count($productNameWords) !== 1) {
            \Log::debug('VALENTINO - Nom avec plusieurs mots rejeté', [
                'nom_recherché' => $searchName,
                'nom_produit' => $productName,
                'mot_recherché' => $searchWordLower,
                'mots_dans_nom_produit' => $productNameWords,
                'nombre_mots' => count($productNameWords),
            ]);
            return false;
        }

        if ($productNameWords[0] !== $searchWordLower) {
            \Log::debug('VALENTINO - Mot différent rejeté', [
                'nom_recherché' => $searchName,
                'nom_produit' => $productName,
                'mot_recherché' => $searchWordLower,
                'mot_produit' => $productNameWords[0],
            ]);
            return false;
        }

        \Log::debug('VALENTINO - Nom validé (exactement 1 mot correspondant)', [
            'nom_recherché' => $searchName,
            'nom_produit' => $productName,
            'mot_vérifié' => $searchWordLower
        ]);

        return true;
    }

    private function isValidHermesMatch(string $searchName, string $searchType, string $productName, string $productType, bool $isLimitedEdition): bool
    {
        $searchNameLower = mb_strtolower(trim($searchName));
        $searchTypeLower = mb_strtolower(trim($searchType));
        $productNameLower = mb_strtolower(trim($productName));
        $productTypeLower = mb_strtolower(trim($productType));

        $isSearchBarenia = str_contains($searchNameLower, 'barenia') || str_contains($searchTypeLower, 'barenia');
        $isProductBarenia = str_contains($productNameLower, 'barenia') || str_contains($productTypeLower, 'barenia');

        if ($isSearchBarenia) {
            if (!$isProductBarenia) {
                \Log::debug('HERMÈS - Produit Barenia non correspondant', [
                    'recherché_name' => $searchName, 'produit_name' => $productName,
                    'raison' => 'Barenia recherché mais pas trouvé dans le produit'
                ]);
                return false;
            }
            \Log::debug('HERMÈS - Produit Barenia correspondant', [
                'recherché_name' => $searchName, 'produit_name' => $productName
            ]);
            return true;
        }

        if ($isProductBarenia && !$isSearchBarenia) {
            \Log::debug('HERMÈS - Produit Barenia mais recherche non-Barenia', [
                'recherché_name' => $searchName, 'produit_name' => $productName
            ]);
            return false;
        }

        if ($isLimitedEdition) {
            $searchWords = $this->extractKeywords($searchName, true);
            $matchCount = 0;
            $matchedWords = [];

            foreach ($searchWords as $word) {
                if (str_contains($productNameLower, $word) || str_contains($productTypeLower, $word)) {
                    $matchCount++;
                    $matchedWords[] = $word;
                }
            }

            $minRequired = max(1, (int) ceil(count($searchWords) * 0.5));
            $isValid = $matchCount >= $minRequired;

            \Log::debug('HERMÈS - Édition limitée - ' . ($isValid ? 'Matching validé' : 'Matching insuffisant'), [
                'recherché_name' => $searchName, 'produit_name' => $productName,
                'mots_matchés' => $matchedWords, 'ratio' => $matchCount . '/' . count($searchWords),
                'minimum_requis' => $minRequired
            ]);

            return $isValid;
        }

        // Standard : 100% des mots doivent matcher
        $searchWords = $this->extractKeywords($searchName, true);
        $matchCount = 0;
        $matchedWords = [];
        $missingWords = [];

        foreach ($searchWords as $word) {
            if (str_contains($productNameLower, $word)) {
                $matchCount++;
                $matchedWords[] = $word;
            } else {
                $missingWords[] = $word;
            }
        }

        $isValid = $matchCount === count($searchWords) && empty($missingWords);

        \Log::debug('HERMÈS - Produit standard - ' . ($isValid ? 'Matching validé (100%)' : 'Matching strict échoué'), [
            'recherché_name' => $searchName, 'produit_name' => $productName,
            'mots_matchés' => $matchedWords, 'mots_manquants' => $missingWords,
            'ratio' => $matchCount . '/' . count($searchWords)
        ]);

        return $isValid;
    }

    // ─── RECHERCHE PRINCIPALE ─────────────────────────────────────────────────

    private function searchMatchingProducts()
    {
        if (empty($this->extractedData) || !is_array($this->extractedData)) {
            \Log::warning('searchMatchingProducts: extractedData invalide', ['extractedData' => $this->extractedData]);
            return;
        }

        $extractedData = array_merge([
            'vendor' => '', 'name' => '', 'variation' => '', 'type' => '', 'is_coffret' => false
        ], $this->extractedData);

        $vendor = $extractedData['vendor'] ?? '';
        $name = $extractedData['name'] ?? '';
        $type = $extractedData['type'] ?? '';
        $variation = $extractedData['variation'] ?? '';
        $isCoffretSource = $extractedData['is_coffret'] ?? false;

        if (empty($vendor)) {
            \Log::warning('searchMatchingProducts: vendor vide');
            return;
        }

        // Normaliser la variation source une seule fois
        $sourceVariation = $this->normalizeVariation($variation);

        $isSpecialVendor = $this->isSpecialVendor($vendor);
        $isHermesProduct = $this->isHermesProduct($vendor);
        $isBareniaProduct = $isHermesProduct && $this->isBareniaProduct($name, $type);
        $isMeteoritesProduct = $this->isMeteoritesProduct($name, $type);
        $isLimitedEdition = $this->isLimitedEdition($name, $type);

        if ($isSpecialVendor || $isMeteoritesProduct) {
            \Log::info('🎯 PRODUIT SPÉCIAL DÉTECTÉ', [
                'vendor' => $vendor,
                'is_valentino' => str_contains(mb_strtolower($vendor), 'valent'),
                'is_hermes' => $isHermesProduct,
                'is_barenia' => $isBareniaProduct,
                'is_meteorites' => $isMeteoritesProduct,
                'is_coffret' => $isCoffretSource,
                'is_limited_edition' => $isLimitedEdition
            ]);
        }

        $typeParts = $this->extractTypeParts($type);

        $allNameWords = $this->extractKeywords($name, $isSpecialVendor);
        $vendorWords = $this->extractKeywords($vendor, false);
        $nameWords = array_values(array_diff($allNameWords, $vendorWords));

        \Log::info('Mots-clés pour la recherche', [
            'vendor' => $vendor,
            'is_special_vendor' => $isSpecialVendor,
            'is_hermes' => $isHermesProduct,
            'is_barenia' => $isBareniaProduct,
            'is_meteorites' => $isMeteoritesProduct,
            'is_limited_edition' => $isLimitedEdition,
            'name' => $name,
            'nameWords_filtres' => $nameWords,
            'type' => $type,
            'type_parts' => $typeParts,
            'variation' => $variation,
            'variation_normalisée' => $sourceVariation
        ]);

        // ── ÉTAPE 1 : Recherche par vendor + sites ──
        $vendorProducts = Product::query()
            ->where('vendor', 'LIKE', "%{$vendor}%")
            ->when(!empty($this->selectedSites), fn($q) => $q->whereIn('web_site_id', $this->selectedSites))
            ->orderByDesc('id')
            ->get();

        if ($vendorProducts->isEmpty()) {
            \Log::info('Aucun produit trouvé pour le vendor: ' . $vendor);
            return;
        }

        \Log::info('Produits trouvés pour le vendor', ['vendor' => $vendor, 'count' => $vendorProducts->count()]);

        // ── ÉTAPE 2 : Filtrage coffret ──
        $filteredProducts = $this->filterByCoffretStatus($vendorProducts, $isCoffretSource);

        if (empty($filteredProducts)) {
            \Log::info('Aucun produit après filtrage coffret');
            return;
        }

        // ── ÉTAPE 2.5 : Filtrage TYPE (skip pour cas spéciaux) ──
        $shouldSkipTypeFilter = ($isSpecialVendor && $isCoffretSource) ||
            ($isHermesProduct && $isLimitedEdition) ||
            ($isMeteoritesProduct && $isLimitedEdition);

        if (!$shouldSkipTypeFilter) {
            $typeFilteredProducts = $this->filterByBaseType($filteredProducts, $type);

            if (!empty($typeFilteredProducts)) {
                \Log::info('Produits après filtrage par TYPE DE BASE', [
                    'count' => count($typeFilteredProducts), 'type_recherché' => $type
                ]);
                $filteredProducts = $typeFilteredProducts;
            } else {
                \Log::info('Aucun produit après filtrage par type de base, on garde tous les produits');
            }
        } else {
            \Log::info('CAS SPÉCIAL DÉTECTÉ - Skip du filtrage strict par TYPE', [
                'vendor' => $vendor, 'type_recherché' => $type,
                'produits_conservés' => count($filteredProducts)
            ]);
        }

        // ── ÉTAPE 2.6 : Filtrage progressif par NAME (100% → 80% → 50% → 1 mot) ──
        $nameFilteredProducts = $filteredProducts;

        if (!empty($nameWords)) {
            $allWordsMatch = collect($filteredProducts)->filter(function ($product) use ($nameWords, $shouldSkipTypeFilter) {
                $productName = mb_strtolower($product['name'] ?? '');
                $productType = mb_strtolower($product['type'] ?? '');
                $matchCount = 0;
                foreach ($nameWords as $word) {
                    if (str_contains($productName, $word) || ($shouldSkipTypeFilter && str_contains($productType, $word))) {
                        $matchCount++;
                    }
                }
                return $matchCount === count($nameWords);
            })->values()->toArray();

            if (!empty($allWordsMatch)) {
                $nameFilteredProducts = $allWordsMatch;
                \Log::info('Produits après filtrage STRICT par NAME (TOUS les mots)', [
                    'count' => count($nameFilteredProducts), 'nameWords_required' => $nameWords
                ]);
            } else {
                // 80%
                $minRequired80 = max(1, (int) ceil(count($nameWords) * 0.8));
                $mostWordsMatch = collect($filteredProducts)->filter(function ($product) use ($nameWords, $minRequired80, $shouldSkipTypeFilter) {
                    $productName = mb_strtolower($product['name'] ?? '');
                    $productType = mb_strtolower($product['type'] ?? '');
                    $matchCount = 0;
                    foreach ($nameWords as $word) {
                        if (str_contains($productName, $word) || ($shouldSkipTypeFilter && str_contains($productType, $word))) {
                            $matchCount++;
                        }
                    }
                    return $matchCount >= $minRequired80;
                })->values()->toArray();

                if (!empty($mostWordsMatch)) {
                    $nameFilteredProducts = $mostWordsMatch;
                    \Log::info('Produits après filtrage 80% par NAME', [
                        'count' => count($nameFilteredProducts), 'nameWords_used' => $nameWords
                    ]);
                } else {
                    // 50%
                    $minRequired50 = max(1, (int) ceil(count($nameWords) * 0.5));
                    $halfWordsMatch = collect($filteredProducts)->filter(function ($product) use ($nameWords, $minRequired50, $shouldSkipTypeFilter, $isMeteoritesProduct, $isHermesProduct) {
                        $productName = mb_strtolower($product['name'] ?? '');
                        $productType = mb_strtolower($product['type'] ?? '');
                        $matchCount = 0;
                        $matchedWords = [];
                        foreach ($nameWords as $word) {
                            if (str_contains($productName, $word) || ($shouldSkipTypeFilter && str_contains($productType, $word))) {
                                $matchCount++;
                                $matchedWords[] = $word;
                            }
                        }
                        if ($shouldSkipTypeFilter && $matchCount > 0) {
                            \Log::debug('🎯 CAS SPÉCIAL - Matching partiel', [
                                'product_id' => $product['id'] ?? 0,
                                'product_name' => $product['name'] ?? '',
                                'matched_words' => $matchedWords,
                                'match_count' => $matchCount,
                                'required' => $minRequired50,
                                'is_meteorites' => $isMeteoritesProduct,
                                'is_hermes' => $isHermesProduct,
                                'passes' => $matchCount >= $minRequired50
                            ]);
                        }
                        return $matchCount >= $minRequired50;
                    })->values()->toArray();

                    if (!empty($halfWordsMatch)) {
                        $nameFilteredProducts = $halfWordsMatch;
                        \Log::info('Produits après filtrage 50% par NAME', [
                            'count' => count($nameFilteredProducts), 'nameWords_used' => $nameWords
                        ]);
                    } else {
                        // Fallback : au moins 1 mot
                        $anyWordMatch = collect($filteredProducts)->filter(function ($product) use ($nameWords, $shouldSkipTypeFilter) {
                            $productName = mb_strtolower($product['name'] ?? '');
                            $productType = mb_strtolower($product['type'] ?? '');
                            foreach ($nameWords as $word) {
                                if (str_contains($productName, $word) || ($shouldSkipTypeFilter && str_contains($productType, $word))) {
                                    return true;
                                }
                            }
                            return false;
                        })->values()->toArray();

                        if (!empty($anyWordMatch)) {
                            $nameFilteredProducts = $anyWordMatch;
                            \Log::info('Produits après filtrage SOUPLE par NAME', [
                                'count' => count($nameFilteredProducts), 'nameWords_used' => $nameWords
                            ]);
                        }
                    }
                }
            }

            $filteredProducts = $nameFilteredProducts;
        }

        // ── ÉTAPE 2.65 : Filtrage strict Valentino (1 mot) ──
        if (str_contains(mb_strtolower($vendor), 'valent') && !empty($nameWords) && count($nameWords) === 1 && !empty($filteredProducts)) {
            $valentinStrictFiltered = collect($filteredProducts)->filter(function ($product) use ($name) {
                return $this->isValidValentinoSingleWordMatch($name, $product['name'] ?? '', $product['type'] ?? '');
            })->values()->toArray();

            if (!empty($valentinStrictFiltered)) {
                \Log::info('VALENTINO - Filtrage strict appliqué (nom d\'un seul mot)', [
                    'produits_avant' => count($filteredProducts),
                    'produits_après' => count($valentinStrictFiltered)
                ]);
                $filteredProducts = $valentinStrictFiltered;
            } else {
                \Log::warning('VALENTINO - Aucun produit après filtrage strict, conservation des résultats précédents');
            }
        }

        // ── ÉTAPE 2.66 : Filtrage strict Hermès ──
        if ($isHermesProduct && !empty($filteredProducts)) {
            $hermesFiltered = collect($filteredProducts)->filter(function ($product) use ($name, $type, $isLimitedEdition) {
                return $this->isValidHermesMatch($name, $type, $product['name'] ?? '', $product['type'] ?? '', $isLimitedEdition);
            })->values()->toArray();

            if (!empty($hermesFiltered)) {
                \Log::info('HERMÈS - Filtrage spécial appliqué', [
                    'produits_avant' => count($filteredProducts),
                    'produits_après' => count($hermesFiltered),
                    'is_barenia' => $isBareniaProduct,
                    'is_limited_edition' => $isLimitedEdition
                ]);
                $filteredProducts = $hermesFiltered;
            } else {
                \Log::warning('HERMÈS - Aucun produit après filtrage strict, conservation des résultats précédents');
            }
        }

        // ── ÉTAPE 2.7 : Pre-filtrage par variation ──
        // Si une variation source existe, on essaie d'isoler les produits avec la même variation.
        // Si aucun ne correspond exactement, on garde tout (la variation sera utilisée dans le scoring).
        if ($sourceVariation !== null && !empty($filteredProducts)) {
            $exactVariationProducts = collect($filteredProducts)->filter(function ($product) use ($sourceVariation) {
                $productVariation = $this->normalizeVariation($product['variation'] ?? '');
                return $this->compareVariations($sourceVariation, $productVariation) === 'exact';
            })->values()->toArray();

            if (!empty($exactVariationProducts)) {
                \Log::info('📦 Variation exacte trouvée — filtrage appliqué', [
                    'variation_source' => $sourceVariation,
                    'produits_avant' => count($filteredProducts),
                    'produits_après' => count($exactVariationProducts)
                ]);
                $filteredProducts = $exactVariationProducts;
            } else {
                // Pas de match exact → on garde tout, le scoring va pénaliser
                \Log::info('📦 Aucune variation exacte trouvée — scoring utilisé à la place', [
                    'variation_source' => $sourceVariation,
                    'produits_conservés' => count($filteredProducts)
                ]);
            }
        }

        // ── ÉTAPE 3 : Scoring ──
        $scoredProducts = collect($filteredProducts)->map(function ($product) use (
            $typeParts, $type, $isCoffretSource, $nameWords, $shouldSkipTypeFilter,
            $isMeteoritesProduct, $isLimitedEdition, $isHermesProduct, $isBareniaProduct,
            $sourceVariation
        ) {
            $score = 0;
            $productType = mb_strtolower($product['type'] ?? '');
            $productName = mb_strtolower($product['name'] ?? '');
            $matchedTypeParts = [];
            $typePartsCount = count($typeParts);

            // ── Bonus coffret ──
            $productIsCoffret = $this->isCoffret($product);
            if ($isCoffretSource && $productIsCoffret) {
                $score += 500;
                if ($shouldSkipTypeFilter) {
                    $score += 100;
                }
            }

            // ── Bonus cas spéciaux (Météorites / Barenia / Hermès limited) ──
            if ($isMeteoritesProduct && $isLimitedEdition) {
                if ($this->isMeteoritesProduct($product['name'] ?? '', $product['type'] ?? '') &&
                    $this->isLimitedEdition($product['name'] ?? '', $product['type'] ?? '')) {
                    $score += 400;
                }
            }

            if ($isHermesProduct && $isBareniaProduct) {
                if ($this->isBareniaProduct($product['name'] ?? '', $product['type'] ?? '')) {
                    $score += 450;
                }
            }

            if ($isHermesProduct && $isLimitedEdition) {
                if ($this->isLimitedEdition($product['name'] ?? '', $product['type'] ?? '')) {
                    $score += 400;
                }
            }

            // ── Bonus NAME ──
            $nameMatchCount = 0;
            $matchedNameWords = [];

            if (!empty($nameWords)) {
                foreach ($nameWords as $word) {
                    if (str_contains($productName, $word) || ($shouldSkipTypeFilter && str_contains($productType, $word))) {
                        $nameMatchCount++;
                        $matchedNameWords[] = $word;
                    }
                }

                $nameMatchRatio = count($nameWords) > 0 ? ($nameMatchCount / count($nameWords)) : 0;
                $score += (int) ($nameMatchRatio * 300);

                if ($nameMatchCount === count($nameWords)) {
                    $score += 200;
                }
            }

            // ── Bonus TYPE ──
            $typeMatched = false;
            $hasStrongNameMatch = $nameMatchCount >= 2;

            if (!empty($typeParts) && !empty($productType)) {
                if (!empty($typeParts[0])) {
                    $baseTypeLower = mb_strtolower(trim($typeParts[0]));

                    if (str_contains($productType, $baseTypeLower)) {
                        $score += 300;
                        $typeMatched = true;
                        \Log::debug('TYPE DE BASE correspondant', [
                            'product_id' => $product['id'] ?? 0,
                            'base_type_recherché' => $baseTypeLower,
                            'product_type' => $productType
                        ]);
                    } else {
                        $score -= $shouldSkipTypeFilter ? 50 : 200;
                        \Log::debug('TYPE DE BASE non correspondant', [
                            'product_id' => $product['id'] ?? 0,
                            'base_type_recherché' => $baseTypeLower,
                            'product_type' => $productType,
                            'malus' => $shouldSkipTypeFilter ? -50 : -200
                        ]);
                    }
                }

                foreach ($typeParts as $index => $part) {
                    $partLower = mb_strtolower(trim($part));
                    if (!empty($partLower) && str_contains($productType, $partLower)) {
                        $partBonus = max(100 - ($index * 20), 20);
                        $score += $partBonus;
                        $matchedTypeParts[] = ['part' => $part, 'bonus' => $partBonus, 'position' => $index + 1];
                        if ($index == 0 || $typeMatched) {
                            $typeMatched = true;
                        }
                    }
                }

                if (count($matchedTypeParts) === $typePartsCount && $typePartsCount > 0) {
                    $score += 150;
                }

                $typeLower = mb_strtolower(trim($type));
                if (!empty($typeLower) && str_contains($productType, $typeLower)) {
                    $score += 200;
                    $typeMatched = true;
                }
                if (!empty($typeLower) && str_starts_with($productType, $typeLower)) {
                    $score += 100;
                }
            }

            // ── Bonus / Malus VARIATION ──
            $productVariation = $this->normalizeVariation($product['variation'] ?? '');
            $variationMatch = $this->compareVariations($sourceVariation, $productVariation);

            $variationBonus = 0;
            if ($sourceVariation !== null) {
                $variationBonus = match($variationMatch) {
                    'exact'     => 250,   // Variation identique (ex: 200ml == 200ml)
                    'close'     => 100,   // Même unité, écart ≤ 10% (ex: 100ml vs 105ml)
                    'unit_only' => -150,  // Même unité mais taille très différente (ex: 30ml vs 200ml)
                    'none'      => -100,  // Unité différente ou variation absente sur le produit
                };
                $score += $variationBonus;

                \Log::debug('📦 Variation scoring', [
                    'product_id' => $product['id'] ?? 0,
                    'source_variation' => $sourceVariation,
                    'product_variation' => $productVariation,
                    'match_type' => $variationMatch,
                    'bonus' => $variationBonus
                ]);
            }

            return [
                'product' => $product,
                'score' => $score,
                'matched_type_parts' => $matchedTypeParts,
                'all_type_parts_matched' => count($matchedTypeParts) === $typePartsCount,
                'type_parts_count' => $typePartsCount,
                'matched_count' => count($matchedTypeParts),
                'type_matched' => $typeMatched,
                'has_strong_name_match' => $hasStrongNameMatch,
                'is_coffret' => $productIsCoffret,
                'coffret_bonus_applied' => ($isCoffretSource && $productIsCoffret),
                'name_match_count' => $nameMatchCount,
                'name_words_total' => count($nameWords),
                'matched_name_words' => $matchedNameWords,
                'is_special_case' => $shouldSkipTypeFilter,
                'is_meteorites' => $isMeteoritesProduct,
                'is_hermes' => $isHermesProduct,
                'is_barenia' => $isBareniaProduct,
                'is_limited_edition' => $isLimitedEdition,
                'variation_match' => $variationMatch,
                'variation_bonus' => $variationBonus,
                'source_variation' => $sourceVariation,
                'product_variation' => $productVariation
            ];
        })
            ->sortByDesc('score')
            ->values();

        \Log::info('Scoring détaillé', [
            'total_products' => $scoredProducts->count(),
            'type_recherche' => $type,
            'name_words' => $nameWords,
            'variation_source' => $sourceVariation,
            'recherche_coffret' => $isCoffretSource,
            'is_special_case' => $shouldSkipTypeFilter,
            'top_10_scores' => $scoredProducts->take(10)->map(function ($item) {
                return [
                    'id' => $item['product']['id'] ?? 0,
                    'score' => $item['score'],
                    'name' => $item['product']['name'] ?? '',
                    'type' => $item['product']['type'] ?? '',
                    'variation_produit' => $item['product']['variation'] ?? '',
                    'variation_match' => $item['variation_match'],
                    'variation_bonus' => $item['variation_bonus'],
                    'name_match' => $item['name_match_count'] . '/' . $item['name_words_total'],
                    'matched_words' => $item['matched_name_words'] ?? [],
                    'type_match' => $item['type_matched'],
                    'is_coffret' => $item['is_coffret'],
                    'coffret_bonus' => $item['coffret_bonus_applied'],
                    'matched_type_parts' => array_map(fn($p) => "{$p['part']} (+{$p['bonus']} pts)", $item['matched_type_parts']),
                ];
            })->toArray()
        ]);

        // ── ÉTAPE 4 : Filtrage final (NAME + TYPE obligatoires, variation n'exclut jamais) ──
        $scoredProducts = $scoredProducts->filter(function ($item) use ($nameWords, $shouldSkipTypeFilter) {
            $hasNameMatch = !empty($nameWords) ? $item['name_match_count'] > 0 : true;
            $hasTypeMatch = $item['type_matched'];

            $keepProduct = $shouldSkipTypeFilter
                ? ($item['score'] > 0 && $hasNameMatch)
                : ($item['score'] > 0 && $hasNameMatch && $hasTypeMatch);

            if (!$keepProduct) {
                \Log::debug('Produit exclu', [
                    'product_id' => $item['product']['id'] ?? 0,
                    'product_name' => $item['product']['name'] ?? '',
                    'product_variation' => $item['product']['variation'] ?? '',
                    'score' => $item['score'],
                    'variation_match' => $item['variation_match'],
                    'name_match' => $hasNameMatch,
                    'type_match' => $hasTypeMatch,
                    'raison' => !$hasNameMatch ? 'NAME ne matche pas' : (!$hasTypeMatch && !$shouldSkipTypeFilter ? 'TYPE ne matche pas' : 'Score trop faible')
                ]);
            }

            return $keepProduct;
        });

        \Log::info('Après filtrage final', [
            'produits_restants' => $scoredProducts->count(),
            'is_special_case' => $shouldSkipTypeFilter
        ]);

        if ($scoredProducts->isEmpty()) {
            \Log::info('Aucun produit après filtrage');
            $this->matchingProducts = [];
            $this->groupedResults = [];
            return;
        }

        // ── ÉTAPE 5 : Tri final par prix ascendant (le moins cher en premier) ──
        $scoredProducts = $scoredProducts->sortBy(function ($item) {
            $prix = $item['product']['prix_ht'] ?? null;
            // Produits sans prix poussés en bas
            return $prix !== null ? (float) $prix : PHP_FLOAT_MAX;
        })->values();

        $this->matchingProducts = $scoredProducts->pluck('product')->toArray();

        \Log::info('Produits après scoring + tri prix (avant déduplication)', [
            'count' => count($this->matchingProducts),
            'prix_min' => $scoredProducts->first()['product']['prix_ht'] ?? null,
            'prix_max' => $scoredProducts->last()['product']['prix_ht'] ?? null,
            'best_score' => $scoredProducts->max('score'),
            'top_variation_match' => $scoredProducts->first()['variation_match'] ?? 'N/A'
        ]);

        $this->groupResultsByScrapeReference($this->matchingProducts);
        $this->validateBestMatchWithAI();
    }

    // ─── TYPE DECOMPOSITION ───────────────────────────────────────────────────

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

        $parts = array_values(array_filter(array_map('trim', explode('|', $normalized)), fn($p) => !empty($p)));

        if (count($parts) === 1) {
            $perfumeKeywords = [
                'eau de parfum', 'eau de toilette', 'eau de cologne',
                'extrait de parfum', 'eau fraiche', 'parfum', 'extrait', 'cologne'
            ];
            $intensityKeywords = ['intense', 'extrême', 'absolu', 'concentré', 'léger', 'doux', 'fort', 'puissant'];
            $formatKeywords = ['vaporisateur', 'spray', 'atomiseur', 'flacon', 'roller', 'stick', 'roll-on'];

            $typeLower = mb_strtolower($type);
            $foundParts = [];

            foreach ($perfumeKeywords as $keyword) {
                if (str_contains($typeLower, $keyword)) {
                    $startPos = mb_strpos($typeLower, $keyword);
                    $foundParts[] = mb_substr($type, $startPos, mb_strlen($keyword));
                    $typeLower = str_replace($keyword, '', $typeLower);
                    break;
                }
            }

            foreach ($intensityKeywords as $keyword) {
                if (str_contains($typeLower, $keyword)) {
                    $startPos = mb_strpos($typeLower, $keyword);
                    if ($startPos !== false) {
                        $foundParts[] = ucfirst(mb_substr($type, $startPos, mb_strlen($keyword)));
                    }
                    break;
                }
            }

            foreach ($formatKeywords as $keyword) {
                if (str_contains($typeLower, $keyword)) {
                    $startPos = mb_strpos($typeLower, $keyword);
                    if ($startPos !== false) {
                        $foundParts[] = ucfirst(mb_substr($type, $startPos, mb_strlen($keyword)));
                    }
                    break;
                }
            }

            if (!empty($foundParts)) {
                \Log::info('Type décomposé en parties hiérarchiques', [
                    'type_original' => $type, 'parties' => $foundParts
                ]);
                return $foundParts;
            }

            $words = array_values(array_filter(preg_split('/\s+/', $type), fn($w) => mb_strlen($w) >= 3));
            return $words;
        }

        return $parts;
    }

    // ─── DEDUPLICATION / GROUPEMENT ───────────────────────────────────────────

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
                'web_site_id' => 0, 'id' => 0,
                'vendor' => '', 'name' => '', 'type' => '', 'variation' => ''
            ], $product);
        });

        \Log::info('Avant déduplication des résultats', ['total_produits' => $productsCollection->count()]);

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
            ->map(fn($group) => $group->sortByDesc('scrape_reference_id')->first())
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
            'par_site' => $uniqueProducts->groupBy('web_site_id')->map(fn($g) => $g->count())->toArray()
        ]);

        $bySiteStats = $uniqueProducts->groupBy('web_site_id')->map(function ($siteProducts, $siteId) {
            return [
                'site_id' => $siteId,
                'total_products' => $siteProducts->count(),
                'max_scrape_ref_id' => $siteProducts->max('scrape_reference_id'),
                'min_scrape_ref_id' => $siteProducts->min('scrape_reference_id'),
                'products' => $siteProducts->values()->toArray()
            ];
        });

        $this->groupedResults = $uniqueProducts->groupBy('scrape_reference')->map(function ($group, $reference) {
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
                        'price_range' => ['min' => $siteData['lowest_price'], 'max' => $siteData['highest_price']],
                        'variations_count' => $siteData['count']
                    ];
                })->values()->toArray(),
                'best_price' => $group->min('prix_ht'),
                'site_ids' => $group->pluck('web_site_id')->unique()->values()->toArray()
            ];
        })->toArray();

        $this->groupedResults['_site_stats'] = $bySiteStats->toArray();
    }

    // ─── KEYWORD EXTRACTION ───────────────────────────────────────────────────

    private function extractKeywords(string $text, bool $isSpecialVendor = false): array
    {
        if (empty($text)) {
            return [];
        }

        $stopWords = ['de', 'la', 'le', 'les', 'des', 'du', 'un', 'une', 'et', 'ou', 'pour', 'avec', 'sans'];

        if ($isSpecialVendor) {
            $stopWords = array_merge($stopWords, ['coffret', 'set', 'kit', 'duo', 'trio', 'collection']);
        }

        $words = preg_split('/[\s\-]+/', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter($words, fn($w) => mb_strlen($w) >= 3 && !in_array($w, $stopWords)));
    }

    // ─── FILTRAGE COFFRET / TYPE ──────────────────────────────────────────────

    private function filterByCoffretStatus($products, bool $sourceisCoffret): array
    {
        return $products->filter(function ($product) use ($sourceisCoffret) {
            $productIsCoffret = $this->isCoffret($product->toArray());
            return $sourceisCoffret ? $productIsCoffret : !$productIsCoffret;
        })->values()->toArray();
    }

    private function filterByBaseType(array $products, string $searchType): array
    {
        if (empty($searchType)) {
            return $products;
        }

        $typeCategories = [
            'parfum' => ['eau de parfum', 'parfum', 'eau de toilette', 'eau de cologne', 'eau fraiche', 'extrait de parfum', 'extrait', 'cologne'],
            'déodorant' => ['déodorant', 'deodorant', 'deo', 'anti-transpirant', 'antitranspirant'],
            'crème' => ['crème', 'creme', 'baume', 'gel', 'lotion', 'fluide', 'soin'],
            'huile' => ['huile', 'oil'],
            'sérum' => ['sérum', 'serum', 'concentrate', 'concentré'],
            'masque' => ['masque', 'mask', 'patch'],
            'shampooing' => ['shampooing', 'shampoing', 'shampoo'],
            'après-shampooing' => ['après-shampooing', 'conditioner', 'après shampooing'],
            'savon' => ['savon', 'soap', 'gel douche', 'mousse'],
            'maquillage' => ['fond de teint', 'rouge à lèvres', 'mascara', 'eye-liner', 'fard', 'poudre'],
        ];

        $searchTypeLower = mb_strtolower(trim($searchType));

        $searchCategory = null;
        foreach ($typeCategories as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($searchTypeLower, $keyword)) {
                    $searchCategory = $category;
                    break 2;
                }
            }
        }

        if (!$searchCategory) {
            \Log::info('Type de recherche non catégorisé, pas de filtrage par type de base', ['type' => $searchType]);
            return $products;
        }

        \Log::info('Filtrage par catégorie de type', [
            'type_recherché' => $searchType, 'catégorie' => $searchCategory
        ]);

        $filtered = collect($products)->filter(function ($product) use ($searchCategory, $typeCategories) {
            $productType = mb_strtolower($product['type'] ?? '');
            if (empty($productType)) return false;

            $productCategory = null;
            foreach ($typeCategories as $category => $keywords) {
                foreach ($keywords as $keyword) {
                    if (str_contains($productType, $keyword)) {
                        $productCategory = $category;
                        break 2;
                    }
                }
            }

            if (!$productCategory) return true;

            $match = ($productCategory === $searchCategory);
            if (!$match) {
                \Log::debug('Produit exclu par filtrage de type', [
                    'product_id' => $product['id'] ?? 0,
                    'product_name' => $product['name'] ?? '',
                    'product_type' => $productType,
                    'product_category' => $productCategory,
                    'search_category' => $searchCategory
                ]);
            }
            return $match;
        })->values()->toArray();

        \Log::info('Résultat du filtrage par type de base', [
            'produits_avant' => count($products),
            'produits_après' => count($filtered),
            'produits_exclus' => count($products) - count($filtered)
        ]);

        return $filtered;
    }

    // ─── VALIDATION IA ────────────────────────────────────────────────────────

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

        $sourceVariationLabel = $this->extractedData['variation'] ?? 'N/A';

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            ])->timeout(15)->post('https://api.openai.com/v1/chat/completions', [
                        'model' => 'gpt-4o-mini',
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => 'Tu es un expert en matching de produits cosmétiques. Tu dois analyser la correspondance entre un produit source et une liste de candidats, puis retourner l\'ID du meilleur match avec un score de confiance. La variation (contenance en ml, g, etc.) est un critère TRÈS IMPORTANT : un produit avec une variation différente est considéré comme un produit différent. Réponds UNIQUEMENT avec un objet JSON.'
                            ],
                            [
                                'role' => 'user',
                                'content' => "Produit source : {$this->productName}

Critères extraits :
- Vendor: " . ($this->extractedData['vendor'] ?? 'N/A') . "
- Name: " . ($this->extractedData['name'] ?? 'N/A') . "
- Type: " . ($this->extractedData['type'] ?? 'N/A') . "
- Variation (contenance) : {$sourceVariationLabel}

Produits candidats :
" . json_encode($productsInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "

Analyse chaque candidat et détermine le meilleur match.
IMPORTANT : La variation (ml, g, oz) doit correspondre exactement ou très proche pour être considéré comme un bon match.
Un candidat avec une variation très différente (ex: 30ml vs 200ml) ne peut pas être le meilleur match même si le nom et le type sont identiques.

Retourne au format JSON :
{
  \"best_match_id\": 123,
  \"confidence_score\": 0.95,
  \"reasoning\": \"Explication courte du choix incluant la variation\",
  \"all_scores\": [
    {\"id\": 123, \"score\": 0.95, \"reason\": \"...\"},
    {\"id\": 124, \"score\": 0.60, \"reason\": \"...\"}
  ]
}

Critères de scoring :
- Vendor exact = +30 points
- Name similaire = +25 points
- Type identique = +20 points
- Variation identique = +25 points (critère crucial)
- Variation proche (écart ≤ 10%) = +15 points
- Variation très différente = -20 points
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

                    $this->bestMatch = $found ?? ($this->matchingProducts[0] ?? null);
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

    // ─── ACTIONS PUBLIQUES ────────────────────────────────────────────────────

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
        @if(!empty($matchingProducts) && !$isLoading)
            <div class="mb-6">
                <div class="p-4 bg-blue-50 border border-blue-200 rounded-lg">
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
            </div>
        @endif

        <!-- Section des produits avec onglets -->
        @if(!empty($matchingProducts) && !$isLoading)
            @php
                // Grouper les produits par site
                $productsBySite = collect($matchingProducts)->groupBy('web_site_id');
                // Créer un tableau pour "Tous" les produits
                $allProducts = collect($matchingProducts);
            @endphp

            <!-- Composant Tabs -->
            <div class="mb-8">
                <div>
                    <!-- Version mobile avec select -->
                    <div class="grid grid-cols-1 sm:hidden mb-4">
                        <select wire:model.live="activeTab" aria-label="Select a tab"
                            class="col-start-1 row-start-1 w-full appearance-none rounded-md bg-white py-2 pr-8 pl-3 text-base text-gray-900 outline-1 -outline-offset-1 outline-gray-300 focus:outline-2 focus:-outline-offset-2 focus:outline-indigo-600">
                            <option value="all">Tous les sites ({{ count($allProducts) }})</option>
                            @foreach($productsBySite as $siteId => $siteProducts)
                                @php
                                    $siteInfo = collect($availableSites)->firstWhere('id', $siteId);
                                @endphp
                                <option value="{{ $siteId }}">
                                    {{ $siteInfo['name'] ?? 'Site inconnu' }} ({{ $siteProducts->count() }})
                                </option>
                            @endforeach
                        </select>
                        <svg class="pointer-events-none col-start-1 row-start-1 mr-2 size-5 self-center justify-self-end fill-gray-500"
                            viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" data-slot="icon">
                            <path fill-rule="evenodd"
                                d="M4.22 6.22a.75.75 0 0 1 1.06 0L8 8.94l2.72-2.72a.75.75 0 1 1 1.06 1.06l-3.25 3.25a.75.75 0 0 1-1.06 0L4.22 7.28a.75.75 0 0 1 0-1.06Z"
                                clip-rule="evenodd" />
                        </svg>
                    </div>

                    <!-- Version desktop avec onglets -->
                    <div class="hidden sm:block">
                        <nav class="flex space-x-4 border-b border-gray-200 pb-2" aria-label="Tabs">
                            <!-- Onglet "Tous" -->
                            <button type="button" wire:click="$set('activeTab', 'all')"
                                class="{{ $activeTab === 'all' ? 'bg-gray-100 text-gray-700' : 'text-gray-500 hover:text-gray-700' }} rounded-md px-3 py-2 text-sm font-medium transition-colors"
                                aria-current="{{ $activeTab === 'all' ? 'page' : false }}">
                                Tous les sites ({{ count($allProducts) }})
                            </button>

                            <!-- Onglets par site -->
                            @foreach($productsBySite as $siteId => $siteProducts)
                                @php
                                    $siteInfo = collect($availableSites)->firstWhere('id', $siteId);
                                @endphp
                                <button type="button" wire:click="$set('activeTab', '{{ $siteId }}')"
                                    class="{{ $activeTab == $siteId ? 'bg-gray-100 text-gray-700' : 'text-gray-500 hover:text-gray-700' }} rounded-md px-3 py-2 text-sm font-medium transition-colors"
                                    aria-current="{{ $activeTab == $siteId ? 'page' : false }}">
                                    {{ $siteInfo['name'] ?? 'Site inconnu' }} ({{ $siteProducts->count() }})
                                </button>
                            @endforeach
                        </nav>
                    </div>
                </div>
            </div>

            <!-- Contenu des onglets - STACKED LIST -->
            <div class="mt-6">
                <!-- Onglet "Tous" -->
                @if($activeTab === 'all')
                    <ul role="list" class="space-y-3">
                        @foreach($allProducts as $product)
                            @php
                                $hasUrl = !empty($product['url']);
                                $isBestMatch = $bestMatch && $bestMatch['id'] === $product['id'];

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

                                // Badge couleur pour le type
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

                                $siteInfo = collect($availableSites)->firstWhere('id', $product['web_site_id']);
                            @endphp

                            <li
                                class="relative flex justify-between gap-x-6 px-4 py-5 hover:bg-gray-50 sm:px-6 bg-white rounded-xl shadow-sm ring-1 ring-gray-900/5 transition-shadow hover:shadow-md {{ $isBestMatch ? 'ring-2 ring-indigo-500 bg-indigo-50' : '' }}">
                                <div class="flex min-w-0 gap-x-4">
                                    <!-- Image du produit -->
                                    @if(!empty($product['image_url']))
                                        <img class="size-16 flex-none rounded bg-gray-50 object-cover" src="{{ $product['image_url'] }}"
                                            alt="{{ $product['name'] }}"
                                            onerror="this.src='https://placehold.co/64x64/e5e7eb/9ca3af?text=No+Image'">
                                    @else
                                        <img class="size-16 flex-none rounded bg-gray-50 object-cover"
                                            src="https://placehold.co/64x64/e5e7eb/9ca3af?text=No+Image" alt="Image non disponible">
                                    @endif

                                    <div class="min-w-0 flex-auto">
                                        <p class="text-sm/6 font-semibold text-gray-900">
                                            @if($hasUrl)
                                                <a href="{{ $product['url'] }}" target="_blank" rel="noopener noreferrer">
                                                    <span class="absolute inset-x-0 -top-px bottom-0"></span>
                                                    {{ $product['vendor'] }} - {{ $product['name'] }}
                                                </a>
                                            @else
                                                <span>{{ $product['vendor'] }} - {{ $product['name'] }}</span>
                                            @endif
                                        </p>

                                        <!-- Badges et informations -->
                                        <div class="mt-1 flex flex-wrap items-center gap-2">
                                            <!-- Badge Type -->
                                            <span
                                                class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $badgeColor }}">
                                                {{ $product['type'] }}
                                            </span>

                                            <!-- Badge Variation -->
                                            @if($product['variation'])
                                                <span
                                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700">
                                                    {{ $product['variation'] }}
                                                </span>
                                            @endif

                                            <!-- Badge Name Match -->
                                            @if($nameMatches)
                                                <span
                                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                                    ✓ Name
                                                </span>
                                            @endif

                                            <!-- Badge Type Match -->
                                            @if($typeMatches)
                                                <span
                                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                                    ✓ Type
                                                </span>
                                            @endif

                                            <!-- Badge Coffret -->
                                            @if($product['name'] && (str_contains(strtolower($product['name']), 'coffret') || str_contains(strtolower($product['name']), 'set') || str_contains(strtolower($product['type'] ?? ''), 'coffret')))
                                                <span
                                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800">
                                                    🎁 Coffret
                                                </span>
                                            @endif

                                            <!-- Badge Site -->
                                            @if($siteInfo)
                                                <span
                                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-800">
                                                    {{ $siteInfo['name'] }}
                                                </span>
                                            @endif
                                        </div>

                                        <!-- Date de mise à jour -->
                                        @if(isset($product['updated_at']))
                                            <p class="mt-1 text-xs/5 text-gray-500">
                                                MAJ:
                                                {{ \Carbon\Carbon::parse($product['updated_at'])->translatedFormat('j F Y \\à H:i') }}
                                            </p>
                                        @endif
                                    </div>
                                </div>

                                <div class="flex shrink-0 items-center gap-x-4">
                                    <div class="hidden sm:flex sm:flex-col sm:items-end">
                                        <!-- Prix -->
                                        <p class="text-sm/6 font-semibold text-gray-900">
                                            {{ number_format((float) ($product['prix_ht'] ?? 0), 2) }} €
                                        </p>

                                        <!-- Statut du lien -->
                                        @if($hasUrl)
                                            <p class="mt-1 text-xs/5 text-indigo-600 flex items-center gap-1">
                                                Voir le produit
                                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                                </svg>
                                            </p>
                                        @else
                                            <p class="mt-1 text-xs/5 text-gray-400">
                                                URL non disponible
                                            </p>
                                        @endif
                                    </div>

                                    <!-- Chevron -->
                                    @if($hasUrl)
                                        <svg class="size-5 flex-none text-gray-400" viewBox="0 0 20 20" fill="currentColor"
                                            aria-hidden="true" data-slot="icon">
                                            <path fill-rule="evenodd"
                                                d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z"
                                                clip-rule="evenodd" />
                                        </svg>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <!-- Onglets par site spécifique -->
                    @php
                        $currentSiteProducts = $productsBySite->get($activeTab) ?? collect([]);
                    @endphp

                    @if($currentSiteProducts->count() > 0)
                        <ul role="list" class="space-y-3">
                            @foreach($currentSiteProducts as $product)
                                @php
                                    $hasUrl = !empty($product['url']);
                                    $isBestMatch = $bestMatch && $bestMatch['id'] === $product['id'];

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

                                    // Badge couleur pour le type
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

                                <li
                                    class="relative flex justify-between gap-x-6 px-4 py-5 hover:bg-gray-50 sm:px-6 bg-white rounded-xl shadow-sm ring-1 ring-gray-900/5 transition-shadow hover:shadow-md {{ $isBestMatch ? 'ring-2 ring-indigo-500 bg-indigo-50' : '' }}">
                                    <div class="flex min-w-0 gap-x-4">
                                        <!-- Image du produit -->
                                        @if(!empty($product['image_url']))
                                            <img class="size-16 flex-none rounded bg-gray-50 object-cover" src="{{ $product['image_url'] }}"
                                                alt="{{ $product['name'] }}"
                                                onerror="this.src='https://placehold.co/64x64/e5e7eb/9ca3af?text=No+Image'">
                                        @else
                                            <img class="size-16 flex-none rounded bg-gray-50 object-cover"
                                                src="https://placehold.co/64x64/e5e7eb/9ca3af?text=No+Image" alt="Image non disponible">
                                        @endif

                                        <div class="min-w-0 flex-auto">
                                            <p class="text-sm/6 font-semibold text-gray-900">
                                                @if($hasUrl)
                                                    <a href="{{ $product['url'] }}" target="_blank" rel="noopener noreferrer">
                                                        <span class="absolute inset-x-0 -top-px bottom-0"></span>
                                                        {{ $product['vendor'] }} - {{ $product['name'] }}
                                                    </a>
                                                @else
                                                    <span>{{ $product['vendor'] }} - {{ $product['name'] }}</span>
                                                @endif
                                            </p>

                                            <!-- Badges et informations -->
                                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                                <!-- Badge Type -->
                                                <span
                                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $badgeColor }}">
                                                    {{ $product['type'] }}
                                                </span>

                                                <!-- Badge Variation -->
                                                @if($product['variation'])
                                                    <span
                                                        class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700">
                                                        {{ $product['variation'] }}
                                                    </span>
                                                @endif

                                                <!-- Badge Name Match -->
                                                @if($nameMatches)
                                                    <span
                                                        class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                                        ✓ Name
                                                    </span>
                                                @endif

                                                <!-- Badge Type Match -->
                                                @if($typeMatches)
                                                    <span
                                                        class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                                        ✓ Type
                                                    </span>
                                                @endif

                                                <!-- Badge Coffret -->
                                                @if($product['name'] && (str_contains(strtolower($product['name']), 'coffret') || str_contains(strtolower($product['name']), 'set') || str_contains(strtolower($product['type'] ?? ''), 'coffret')))
                                                    <span
                                                        class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800">
                                                        🎁 Coffret
                                                    </span>
                                                @endif
                                            </div>

                                            <!-- Date de mise à jour -->
                                            @if(isset($product['updated_at']))
                                                <p class="mt-1 text-xs/5 text-gray-500">
                                                    MAJ:
                                                    {{ \Carbon\Carbon::parse($product['updated_at'])->translatedFormat('j F Y \\à H:i') }}
                                                </p>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="flex shrink-0 items-center gap-x-4">
                                        <div class="hidden sm:flex sm:flex-col sm:items-end">
                                            <!-- Prix -->
                                            <p class="text-sm/6 font-semibold text-gray-900">
                                                {{ number_format((float) ($product['prix_ht'] ?? 0), 2) }} €
                                            </p>

                                            <!-- Statut du lien -->
                                            @if($hasUrl)
                                                <p class="mt-1 text-xs/5 text-indigo-600 flex items-center gap-1">
                                                    Voir le produit
                                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                            d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                                    </svg>
                                                </p>
                                            @else
                                                <p class="mt-1 text-xs/5 text-gray-400">
                                                    URL non disponible
                                                </p>
                                            @endif
                                        </div>

                                        <!-- Chevron -->
                                        @if($hasUrl)
                                            <svg class="size-5 flex-none text-gray-400" viewBox="0 0 20 20" fill="currentColor"
                                                aria-hidden="true" data-slot="icon">
                                                <path fill-rule="evenodd"
                                                    d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z"
                                                    clip-rule="evenodd" />
                                            </svg>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <div class="text-center py-12 bg-gray-50 rounded-lg border-2 border-dashed border-gray-300">
                            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <h3 class="mt-2 text-sm font-medium text-gray-900">Aucun produit pour ce site</h3>
                            <p class="mt-1 text-sm text-gray-500">Sélectionnez un autre onglet pour voir plus de résultats</p>
                        </div>
                    @endif
                @endif
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
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <h3 class="mt-2 text-sm font-medium text-gray-900">Aucun produit trouvé</h3>
                <p class="mt-1 text-sm text-gray-500">Essayez de modifier les filtres par site ou utilisez la recherche
                    manuelle</p>
            </div>
        @else
            <!-- État initial (avant chargement) -->
            <div class="text-center py-12 bg-gray-50 rounded-lg border-2 border-dashed border-gray-300">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <h3 class="mt-2 text-sm font-medium text-gray-900">Prêt à rechercher</h3>
                <p class="mt-1 text-sm text-gray-500">L'extraction démarre automatiquement...</p>
            </div>
        @endif
    </div>
</div>