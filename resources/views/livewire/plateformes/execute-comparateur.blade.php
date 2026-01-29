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
     * ✨ NOUVEAU : Vérifie si le vendor nécessite un traitement spécial
     */
    private function isSpecialVendor(string $vendor): bool
    {
        $specialVendors = ['valentino', 'valent'];
        $vendorLower = mb_strtolower(trim($vendor));
        
        foreach ($specialVendors as $special) {
            if (str_contains($vendorLower, $special)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * ✨ NOUVEAU : Vérifie si le produit est un produit Météorites (éditions spéciales)
     */
    private function isMeteoritesProduct(string $name, string $type): bool
    {
        $nameLower = mb_strtolower($name);
        $typeLower = mb_strtolower($type);
        
        // Mots-clés pour identifier les Météorites
        $meteoritesKeywords = ['meteorites', 'météorites'];
        
        foreach ($meteoritesKeywords as $keyword) {
            if (str_contains($nameLower, $keyword) || str_contains($typeLower, $keyword)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * ✨ NOUVEAU : Vérifie si c'est une édition limitée/spéciale
     */
    private function isLimitedEdition(string $name, string $type): bool
    {
        $combinedText = mb_strtolower($name . ' ' . $type);
        
        $limitedKeywords = [
            'édition limitée',
            'edition limitée',
            'édition limite',
            'limited edition',
            'édition spéciale',
            'special edition',
            'blooming glow',
            'midnight glow',
            'phoenix'
        ];
        
        foreach ($limitedKeywords as $keyword) {
            if (str_contains($combinedText, $keyword)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * ✨ NOUVEAU : Vérifie si le produit est "La Petite Robe Noire" CLASSIQUE
     * Détection ULTRA STRICTE: uniquement le produit de base, AUCUNE déclinaison
     * 
     * RÈGLE STRICTE similaire à Valentino :
     * - Recherché : "La Petite Robe Noire" → Accepté : "La Petite Robe Noire" uniquement
     * - Recherché : "La Petite Robe Noire" → Rejeté : "La Petite Robe Noire Velours" (contient "Velours")
     */
    private function isPetiteRobeNoireProduct(string $name, string $vendor): bool
    {
        $nameLower = mb_strtolower(trim($name));
        $vendorLower = mb_strtolower(trim($vendor));
        
        // Doit être Guerlain
        if (!str_contains($vendorLower, 'guerlain')) {
            return false;
        }
        
        // Doit contenir "la petite robe noire" ou "petite robe noire"
        $hasBaseName = str_contains($nameLower, 'la petite robe noire') || 
                       str_contains($nameLower, 'petite robe noire');
        
        if (!$hasBaseName) {
            return false;
        }
        
        // EXCLUSIONS: Si le name contient un de ces mots, ce n'est PAS le produit classique
        $exclusions = [
            'ma petite robe',           // Ma Petite Robe Noire
            'ma robe',                  // Ma Robe
            'petales',                  // Ma Robe Petales
            'pétales',                  // Ma Robe Pétales
            'velours',                  // ❌ VELOURS (déclinaison)
            'sous le vent',             // Ma Robe Sous le Vent
            'couture',                  // Couture
            'intense',                  // Intense
            'legere',                   // Legere
            'légère',                   // Légère
            'fraiche',                  // Fraiche
            'fraîche',                  // Fraîche
            'black perfecto',           // Black Perfecto
            'sexy',                     // Sexy
            'intensa',                  // Intensa
            'elixir',                   // Elixir
            'absolu',                   // Absolu (mais pas "absolue" qui est un type)
            'flanelle',                 // Flanelle
            'dentelle',                 // Dentelle
            'born',                     // Born in Roma style
            'purple',                   // Purple
            'melancholia'               // Melancholia
        ];
        
        foreach ($exclusions as $exclusion) {
            if (str_contains($nameLower, $exclusion)) {
                \Log::debug('❌ LA PETITE ROBE NOIRE - Déclinaison détectée et exclue', [
                    'name' => $name,
                    'exclusion_trouvée' => $exclusion
                ]);
                return false;
            }
        }
        
        \Log::info('✅ LA PETITE ROBE NOIRE CLASSIQUE détectée (sans déclinaison)', [
            'name' => $name
        ]);
        
        return true;
    }
    
    /**
     * ✨ NOUVEAU : Vérifie si le nom du produit est valide pour "La Petite Robe Noire" CLASSIQUE
     * RÈGLE STRICTE : Le nom du produit doit contenir EXACTEMENT "La Petite Robe Noire" sans mots supplémentaires
     */
    private function isValidPetiteRobeNoireSingleMatch(string $searchName, string $productName): bool
    {
        $searchNameLower = mb_strtolower(trim($searchName));
        $productNameLower = mb_strtolower(trim($productName));
        
        // Vérifier que le nom recherché contient "petite robe noire"
        if (!str_contains($searchNameLower, 'petite robe noire')) {
            return true; // Pas La Petite Robe Noire, pas de validation spéciale
        }
        
        // Compter les mots significatifs dans le nom recherché (sans "la", "de", etc.)
        $searchWords = preg_split('/[\s\-]+/', $searchNameLower, -1, PREG_SPLIT_NO_EMPTY);
        $searchWords = array_filter($searchWords, function($word) {
            $stopWords = ['la', 'le', 'les', 'de', 'des', 'du'];
            return mb_strlen($word) >= 3 && !in_array($word, $stopWords);
        });
        $searchWordsCount = count($searchWords);
        
        // Compter les mots significatifs dans le nom du produit
        $productWords = preg_split('/[\s\-]+/', $productNameLower, -1, PREG_SPLIT_NO_EMPTY);
        $productWords = array_filter($productWords, function($word) {
            $stopWords = ['la', 'le', 'les', 'de', 'des', 'du'];
            return mb_strlen($word) >= 3 && !in_array($word, $stopWords);
        });
        $productWordsCount = count($productWords);
        
        // RÈGLE STRICTE : Le produit ne doit pas avoir PLUS de mots que le nom recherché
        if ($productWordsCount > $searchWordsCount) {
            \Log::debug('❌ LA PETITE ROBE NOIRE - Nom avec mots supplémentaires rejeté', [
                'nom_recherché' => $searchName,
                'nom_produit' => $productName,
                'mots_recherchés' => $searchWordsCount,
                'mots_produit' => $productWordsCount,
                'mots_recherchés_liste' => array_values($searchWords),
                'mots_produit_liste' => array_values($productWords),
                'raison' => 'Le nom du produit contient plus de mots que le nom recherché'
            ]);
            return false;
        }
        
        // Vérifier que tous les mots du nom recherché sont présents dans le produit
        foreach ($searchWords as $searchWord) {
            $found = false;
            foreach ($productWords as $productWord) {
                if (str_contains($productWord, $searchWord) || str_contains($searchWord, $productWord)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                \Log::debug('❌ LA PETITE ROBE NOIRE - Mot manquant', [
                    'nom_recherché' => $searchName,
                    'nom_produit' => $productName,
                    'mot_manquant' => $searchWord
                ]);
                return false;
            }
        }
        
        \Log::debug('✅ LA PETITE ROBE NOIRE - Nom validé (pas de mots supplémentaires)', [
            'nom_recherché' => $searchName,
            'nom_produit' => $productName,
            'mots_recherchés' => $searchWordsCount,
            'mots_produit' => $productWordsCount
        ]);
        
        return true;
    }

    /**
     * ✨ NOUVEAU : Vérifie si le nom du produit est valide pour un cas Valentino avec un seul mot
     * Si le nom recherché est un seul mot, le nom du produit ne doit contenir QUE ce mot (pas de mots supplémentaires)
     * 
     * RÈGLE STRICTE : 
     * - Recherché : "Uomo" → Accepté : "Uomo" uniquement
     * - Recherché : "Uomo" → Rejeté : "Uomo Born in Roma" (contient "Born", "Roma")
     */
    private function isValidValentinoSingleWordMatch(string $searchName, string $productName, string $productType): bool
    {
        // Extraire les mots du nom recherché (sans le vendor)
        $searchWords = $this->extractKeywords($searchName, true);
        
        // Si le nom recherché contient plus d'un mot, pas de validation spéciale
        if (count($searchWords) > 1) {
            return true;
        }
        
        // Si c'est un seul mot, le nom du produit doit contenir EXACTEMENT ce mot et RIEN D'AUTRE
        $searchWordLower = mb_strtolower($searchWords[0]);
        $productNameLower = mb_strtolower(trim($productName));
        
        // Extraire TOUS les mots significatifs du nom du produit (≥3 caractères)
        // SANS utiliser extractKeywords qui a des exclusions
        $productNameWords = preg_split('/[\s\-]+/', $productNameLower, -1, PREG_SPLIT_NO_EMPTY);
        $productNameWords = array_filter($productNameWords, function($word) {
            return mb_strlen($word) >= 3;
        });
        $productNameWords = array_values($productNameWords);
        
        // RÈGLE STRICTE : Le nom du produit doit contenir EXACTEMENT 1 mot significatif
        if (count($productNameWords) !== 1) {
            \Log::debug('❌ VALENTINO - Nom avec plusieurs mots rejeté', [
                'nom_recherché' => $searchName,
                'nom_produit' => $productName,
                'mot_recherché' => $searchWordLower,
                'mots_dans_nom_produit' => $productNameWords,
                'nombre_mots' => count($productNameWords),
                'raison' => 'Le nom du produit contient ' . count($productNameWords) . ' mots au lieu de 1'
            ]);
            return false;
        }
        
        // Vérifier que le seul mot du produit correspond au mot recherché
        if ($productNameWords[0] !== $searchWordLower) {
            \Log::debug('❌ VALENTINO - Mot différent rejeté', [
                'nom_recherché' => $searchName,
                'nom_produit' => $productName,
                'mot_recherché' => $searchWordLower,
                'mot_produit' => $productNameWords[0],
                'raison' => 'Le mot du produit ne correspond pas au mot recherché'
            ]);
            return false;
        }
        
        \Log::debug('✅ VALENTINO - Nom validé (exactement 1 mot correspondant)', [
            'nom_recherché' => $searchName,
            'nom_produit' => $productName,
            'mot_vérifié' => $searchWordLower
        ]);
        
        return true;
    }

    /**
     * LOGIQUE DE RECHERCHE OPTIMISÉE
     * 1. Filtrer par VENDOR (obligatoire)
     * 2. Filtrer par statut COFFRET
     * 3. FILTRAGE PROGRESSIF par NAME : Plus de mots matchent, mieux c'est
     * 4. SCORING ÉQUILIBRÉ entre NAME et TYPE
     * 
     * ✨ NOUVEAU : Traitement spécial pour :
     * - VALENTINO + COFFRETS (matching flexible)
     * - VALENTINO + NOM D'UN SEUL MOT (validation stricte contre les mots supplémentaires)
     * - MÉTÉORITES (Guerlain) + ÉDITIONS LIMITÉES (matching flexible)
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

        // ✨ NOUVEAU : Détecter si c'est un vendor spécial (Valentino)
        $isSpecialVendor = $this->isSpecialVendor($vendor);
        
        // ✨ NOUVEAU : Détecter si c'est un produit Météorites (Guerlain)
        $isMeteoritesProduct = $this->isMeteoritesProduct($name, $type);
        
        // ✨ NOUVEAU : Détecter si c'est La Petite Robe Noire CLASSIQUE (Guerlain)
        $isPetiteRobeNoire = $this->isPetiteRobeNoireProduct($name, $vendor);
        
        // ✨ NOUVEAU : Détecter si c'est une édition limitée
        $isLimitedEdition = $this->isLimitedEdition($name, $type);
        
        if ($isSpecialVendor || $isMeteoritesProduct || $isPetiteRobeNoire) {
            \Log::info('🎯 PRODUIT SPÉCIAL DÉTECTÉ', [
                'vendor' => $vendor,
                'is_valentino' => $isSpecialVendor,
                'is_meteorites' => $isMeteoritesProduct,
                'is_petite_robe_noire' => $isPetiteRobeNoire,
                'is_coffret' => $isCoffretSource,
                'is_limited_edition' => $isLimitedEdition
            ]);
        }

        // Extraire les parties du TYPE pour matching hiérarchique
        $typeParts = $this->extractTypeParts($type);
        
        // Extraire les mots du name EN EXCLUANT le vendor
        $allNameWords = $this->extractKeywords($name, $isSpecialVendor);
        
        // Retirer le vendor des mots du name pour éviter les faux positifs
        $vendorWords = $this->extractKeywords($vendor, false);
        $nameWordsFiltered = array_diff($allNameWords, $vendorWords);
        
        // PRENDRE TOUS LES MOTS significatifs
        $nameWords = array_values($nameWordsFiltered);

        \Log::info('Mots-clés pour la recherche', [
            'vendor' => $vendor,
            'is_special_vendor' => $isSpecialVendor,
            'is_meteorites' => $isMeteoritesProduct,
            'is_limited_edition' => $isLimitedEdition,
            'name' => $name,
            'nameWords_brut' => $allNameWords,
            'nameWords_filtres' => $nameWords,
            'type' => $type,
            'type_parts' => $typeParts
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
            return;
        }

        \Log::info('Produits trouvés pour le vendor', [
            'vendor' => $vendor,
            'count' => $vendorProducts->count()
        ]);

        // ÉTAPE 2: Filtrer par statut coffret
        $filteredProducts = $this->filterByCoffretStatus($vendorProducts, $isCoffretSource);

        if (empty($filteredProducts)) {
            \Log::info('Aucun produit après filtrage coffret');
            return;
        }

        // ✨ ÉTAPE 2.5 MODIFIÉE : Filtrage par TYPE de base 
        // SKIP pour:
        // 1. Valentino + coffrets
        // 2. Météorites (Guerlain) + éditions limitées
        $shouldSkipTypeFilter = ($isSpecialVendor && $isCoffretSource) || 
                                ($isMeteoritesProduct && $isLimitedEdition);
        
        if (!$shouldSkipTypeFilter) {
            $typeFilteredProducts = $this->filterByBaseType($filteredProducts, $type);
            
            if (!empty($typeFilteredProducts)) {
                \Log::info('✅ Produits après filtrage par TYPE DE BASE', [
                    'count' => count($typeFilteredProducts),
                    'type_recherché' => $type
                ]);
                $filteredProducts = $typeFilteredProducts;
            } else {
                \Log::info('Aucun produit après filtrage par type de base, on garde tous les produits');
            }
        } else {
            \Log::info('⚠️ CAS SPÉCIAL DÉTECTÉ - Skip du filtrage strict par TYPE', [
                'vendor' => $vendor,
                'type_recherché' => $type,
                'is_valentino_coffret' => ($isSpecialVendor && $isCoffretSource),
                'is_meteorites_limited' => ($isMeteoritesProduct && $isLimitedEdition),
                'produits_conservés' => count($filteredProducts)
            ]);
        }

        // ÉTAPE 2.6: FILTRAGE PROGRESSIF par les mots du NAME
        $nameFilteredProducts = $filteredProducts;
        
        if (!empty($nameWords)) {
            // TENTATIVE 1: TOUS les mots doivent être présents (filtrage le plus strict)
            $allWordsMatch = collect($filteredProducts)->filter(function ($product) use ($nameWords, $shouldSkipTypeFilter) {
                $productName = mb_strtolower($product['name'] ?? '');
                $productType = mb_strtolower($product['type'] ?? '');
                
                $matchCount = 0;
                foreach ($nameWords as $word) {
                    // ✨ Pour cas spéciaux, chercher aussi dans le TYPE
                    if (str_contains($productName, $word) || 
                        ($shouldSkipTypeFilter && str_contains($productType, $word))) {
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
                $minRequired = max(1, (int)ceil(count($nameWords) * 0.8));
                
                $mostWordsMatch = collect($filteredProducts)->filter(function ($product) use ($nameWords, $minRequired, $shouldSkipTypeFilter) {
                    $productName = mb_strtolower($product['name'] ?? '');
                    $productType = mb_strtolower($product['type'] ?? '');
                    
                    $matchCount = 0;
                    foreach ($nameWords as $word) {
                        if (str_contains($productName, $word) || 
                            ($shouldSkipTypeFilter && str_contains($productType, $word))) {
                            $matchCount++;
                        }
                    }
                    
                    return $matchCount >= $minRequired;
                })->values()->toArray();
                
                if (!empty($mostWordsMatch)) {
                    $nameFilteredProducts = $mostWordsMatch;
                    \Log::info('✅ Produits après filtrage 80% par NAME', [
                        'count' => count($nameFilteredProducts),
                        'nameWords_used' => $nameWords
                    ]);
                } else {
                    // ✨ TENTATIVE 3: Pour cas spéciaux, 50% suffit
                    $minRequired = max(1, (int)ceil(count($nameWords) * 0.5));
                    
                    $halfWordsMatch = collect($filteredProducts)->filter(function ($product) use ($nameWords, $minRequired, $shouldSkipTypeFilter, $isMeteoritesProduct) {
                        $productName = mb_strtolower($product['name'] ?? '');
                        $productType = mb_strtolower($product['type'] ?? '');
                        
                        $matchCount = 0;
                        $matchedWords = [];
                        
                        foreach ($nameWords as $word) {
                            if (str_contains($productName, $word) || 
                                ($shouldSkipTypeFilter && str_contains($productType, $word))) {
                                $matchCount++;
                                $matchedWords[] = $word;
                            }
                        }
                        
                        // Log pour cas spéciaux
                        if ($shouldSkipTypeFilter && $matchCount > 0) {
                            \Log::debug('🎯 CAS SPÉCIAL - Matching partiel', [
                                'product_id' => $product['id'] ?? 0,
                                'product_name' => $product['name'] ?? '',
                                'product_type' => $product['type'] ?? '',
                                'matched_words' => $matchedWords,
                                'match_count' => $matchCount,
                                'required' => $minRequired,
                                'is_meteorites' => $isMeteoritesProduct,
                                'passes' => $matchCount >= $minRequired
                            ]);
                        }
                        
                        return $matchCount >= $minRequired;
                    })->values()->toArray();
                    
                    if (!empty($halfWordsMatch)) {
                        $nameFilteredProducts = $halfWordsMatch;
                        \Log::info('✅ Produits après filtrage 50% par NAME', [
                            'count' => count($nameFilteredProducts),
                            'nameWords_used' => $nameWords,
                            'is_special_case' => $shouldSkipTypeFilter
                        ]);
                    } else {
                        // FALLBACK FINAL: Au moins 1 mot
                        $anyWordMatch = collect($filteredProducts)->filter(function ($product) use ($nameWords, $shouldSkipTypeFilter) {
                            $productName = mb_strtolower($product['name'] ?? '');
                            $productType = mb_strtolower($product['type'] ?? '');
                            
                            foreach ($nameWords as $word) {
                                if (str_contains($productName, $word) || 
                                    ($shouldSkipTypeFilter && str_contains($productType, $word))) {
                                    return true;
                                }
                            }
                            return false;
                        })->values()->toArray();
                        
                        if (!empty($anyWordMatch)) {
                            $nameFilteredProducts = $anyWordMatch;
                            \Log::info('⚠️ Produits après filtrage SOUPLE par NAME', [
                                'count' => count($nameFilteredProducts),
                                'nameWords_used' => $nameWords
                            ]);
                        }
                    }
                }
            }
            
            $filteredProducts = $nameFilteredProducts;
        }

        // ✨ ÉTAPE 2.65: FILTRAGE STRICT pour Valentino avec nom d'un seul mot
        if ($isSpecialVendor && !empty($nameWords) && count($nameWords) === 1 && !empty($filteredProducts)) {
            $valentinStrictFiltered = collect($filteredProducts)->filter(function ($product) use ($name) {
                return $this->isValidValentinoSingleWordMatch(
                    $name,
                    $product['name'] ?? '',
                    $product['type'] ?? ''
                );
            })->values()->toArray();
            
            if (!empty($valentinStrictFiltered)) {
                \Log::info('✅ VALENTINO - Filtrage strict appliqué (nom d\'un seul mot)', [
                    'produits_avant' => count($filteredProducts),
                    'produits_après' => count($valentinStrictFiltered),
                    'nom_recherché' => $name,
                    'nombre_mots' => count($nameWords)
                ]);
                $filteredProducts = $valentinStrictFiltered;
            } else {
                \Log::warning('⚠️ VALENTINO - Aucun produit après filtrage strict, conservation des résultats précédents', [
                    'nom_recherché' => $name,
                    'nombre_mots' => count($nameWords)
                ]);
            }
        }

        // ✨ ÉTAPE 2.66: FILTRAGE STRICT pour La Petite Robe Noire
        if ($isPetiteRobeNoire && !empty($filteredProducts)) {
            $petiteRobeStrictFiltered = collect($filteredProducts)->filter(function ($product) use ($name) {
                return $this->isValidPetiteRobeNoireSingleMatch(
                    $name,
                    $product['name'] ?? ''
                );
            })->values()->toArray();
            
            if (!empty($petiteRobeStrictFiltered)) {
                \Log::info('✅ LA PETITE ROBE NOIRE - Filtrage strict appliqué', [
                    'produits_avant' => count($filteredProducts),
                    'produits_après' => count($petiteRobeStrictFiltered),
                    'nom_recherché' => $name
                ]);
                $filteredProducts = $petiteRobeStrictFiltered;
            } else {
                \Log::warning('⚠️ LA PETITE ROBE NOIRE - Aucun produit après filtrage strict, conservation des résultats précédents', [
                    'nom_recherché' => $name
                ]);
            }
        }

        // ÉTAPE 3: Scoring avec PRIORITÉ sur le NAME
        $scoredProducts = collect($filteredProducts)->map(function ($product) use ($typeParts, $type, $isCoffretSource, $nameWords, $shouldSkipTypeFilter, $isMeteoritesProduct, $isLimitedEdition, $isPetiteRobeNoire) {
            $score = 0;
            $productType = mb_strtolower($product['type'] ?? '');
            $productName = mb_strtolower($product['name'] ?? '');
            
            $matchedTypeParts = [];
            $typePartsCount = count($typeParts);

            // ==========================================
            // PRIORITÉ ABSOLUE : BONUS COFFRET
            // ==========================================
            $productIsCoffret = $this->isCoffret($product);
            
            if ($isCoffretSource && $productIsCoffret) {
                $score += 500; // MEGA BONUS pour coffrets
                
                // ✨ BONUS SUPPLÉMENTAIRE pour cas spéciaux
                if ($shouldSkipTypeFilter) {
                    $score += 100;
                }
            }
            
            // ✨ BONUS SPÉCIAL pour Météorites + édition limitée
            if ($isMeteoritesProduct && $isLimitedEdition) {
                $productIsMeteoritesEdition = $this->isMeteoritesProduct($product['name'] ?? '', $product['type'] ?? '') &&
                                             $this->isLimitedEdition($product['name'] ?? '', $product['type'] ?? '');
                
                if ($productIsMeteoritesEdition) {
                    $score += 400; // MEGA BONUS pour Météorites éditions limitées
                }
            }
            
            // ✨ BONUS SPÉCIAL pour La Petite Robe Noire CLASSIQUE
            if ($isPetiteRobeNoire) {
                $productIsPetiteRobeNoire = $this->isPetiteRobeNoireProduct($product['name'] ?? '', $product['vendor'] ?? '');
                
                if ($productIsPetiteRobeNoire) {
                    $score += 600; // MEGA BONUS pour La Petite Robe Noire classique validé
                    
                    \Log::debug('✅ LA PETITE ROBE NOIRE CLASSIQUE avec bonus', [
                        'product_id' => $product['id'] ?? 0,
                        'product_name' => $product['name'] ?? '',
                        'product_type' => $product['type'] ?? '',
                        'bonus' => 600
                    ]);
                }
            }

            // ==========================================
            // BONUS NAME : PRIORITÉ PRINCIPALE
            // ==========================================
            $nameMatchCount = 0;
            $matchedNameWords = [];
            
            if (!empty($nameWords)) {
                foreach ($nameWords as $word) {
                    // ✨ Pour cas spéciaux, chercher aussi dans TYPE
                    if (str_contains($productName, $word) || 
                        ($shouldSkipTypeFilter && str_contains($productType, $word))) {
                        $nameMatchCount++;
                        $matchedNameWords[] = $word;
                    }
                }
                
                // Bonus proportionnel
                $nameMatchRatio = count($nameWords) > 0 ? ($nameMatchCount / count($nameWords)) : 0;
                $nameBonus = (int)($nameMatchRatio * 300);
                $score += $nameBonus;
                
                // BONUS EXTRA si TOUS les mots matchent
                if ($nameMatchCount === count($nameWords)) {
                    $score += 200;
                }
            }

            // ==========================================
            // MATCHING TYPE : Obligatoire mais flexible pour cas spéciaux
            // ==========================================
            
            $typeMatched = false;
            $hasStrongNameMatch = $nameMatchCount >= 2; // Au moins 2 mots du NAME
            
            if (!empty($typeParts) && !empty($productType)) {
                // Vérifier le type de base (OBLIGATOIRE pour être considéré, sauf cas spéciaux)
                if (!empty($typeParts[0])) {
                    $baseTypeLower = mb_strtolower(trim($typeParts[0]));
                    
                    if (str_contains($productType, $baseTypeLower)) {
                        $score += 300;
                        $typeMatched = true;
                        
                        \Log::debug('✅ TYPE DE BASE correspondant', [
                            'product_id' => $product['id'] ?? 0,
                            'base_type_recherché' => $baseTypeLower,
                            'product_type' => $productType,
                            'bonus' => 300
                        ]);
                    } else {
                        // ✨ MALUS réduit pour cas spéciaux
                        if ($shouldSkipTypeFilter) {
                            $score -= 50; // Malus léger
                        } else {
                            $score -= 200; // Malus normal
                        }
                        
                        \Log::debug('❌ TYPE DE BASE non correspondant', [
                            'product_id' => $product['id'] ?? 0,
                            'base_type_recherché' => $baseTypeLower,
                            'product_type' => $productType,
                            'is_special_case' => $shouldSkipTypeFilter,
                            'name_match_count' => $nameMatchCount,
                            'malus' => $shouldSkipTypeFilter ? -50 : -200
                        ]);
                    }
                }
                
                // Vérifier chaque partie du type
                foreach ($typeParts as $index => $part) {
                    $partLower = mb_strtolower(trim($part));
                    if (!empty($partLower)) {
                        if (str_contains($productType, $partLower)) {
                            $partBonus = 100 - ($index * 20);
                            $partBonus = max($partBonus, 20);
                            
                            $score += $partBonus;
                            $matchedTypeParts[] = [
                                'part' => $part,
                                'bonus' => $partBonus,
                                'position' => $index + 1
                            ];
                            
                            if ($index == 0 || $typeMatched) {
                                $typeMatched = true;
                            }
                        }
                    }
                }
                
                // Bonus pour match complet du type
                if (count($matchedTypeParts) === $typePartsCount && $typePartsCount > 0) {
                    $score += 150;
                }
                
                // Bonus pour type exact
                $typeLower = mb_strtolower(trim($type));
                if (!empty($typeLower) && str_contains($productType, $typeLower)) {
                    $score += 200;
                    $typeMatched = true;
                }
                
                // Bonus si le type commence par le type recherché
                if (!empty($typeLower) && str_starts_with($productType, $typeLower)) {
                    $score += 100;
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
                'has_strong_name_match' => $hasStrongNameMatch,
                'is_coffret' => $productIsCoffret,
                'coffret_bonus_applied' => ($isCoffretSource && $productIsCoffret),
                'name_match_count' => $nameMatchCount,
                'name_words_total' => count($nameWords),
                'matched_name_words' => $matchedNameWords,
                'is_special_case' => $shouldSkipTypeFilter,
                'is_meteorites' => $isMeteoritesProduct,
                'is_limited_edition' => $isLimitedEdition,
                'is_petite_robe_noire' => $isPetiteRobeNoire
            ];
        })
        ->sortByDesc('score')
        ->values();

        \Log::info('Scoring détaillé (NAME ET TYPE OBLIGATOIRES sauf cas spéciaux)', [
            'total_products' => $scoredProducts->count(),
            'type_recherche' => $type,
            'type_parts' => $typeParts,
            'name_words' => $nameWords,
            'recherche_coffret' => $isCoffretSource,
            'is_special_case' => $shouldSkipTypeFilter,
            'is_meteorites' => $isMeteoritesProduct,
            'is_limited_edition' => $isLimitedEdition,
            'is_petite_robe_noire' => $isPetiteRobeNoire,
            'top_10_scores' => $scoredProducts->take(10)->map(function($item) {
                return [
                    'id' => $item['product']['id'] ?? 0,
                    'score' => $item['score'],
                    'is_coffret' => $item['is_coffret'],
                    'is_special_case' => $item['is_special_case'],
                    'is_meteorites' => $item['is_meteorites'],
                    'is_limited_edition' => $item['is_limited_edition'],
                    'is_petite_robe_noire' => $item['is_petite_robe_noire'] ?? false,
                    'coffret_bonus' => $item['coffret_bonus_applied'],
                    'name_match' => $item['name_match_count'] . '/' . $item['name_words_total'],
                    'matched_words' => $item['matched_name_words'] ?? [],
                    'has_strong_name' => $item['has_strong_name_match'],
                    'type_match' => $item['type_matched'],
                    'name' => $item['product']['name'] ?? '',
                    'type' => $item['product']['type'] ?? '',
                    'matched_type_parts' => array_map(function($part) {
                        return "{$part['part']} (+{$part['bonus']} pts)";
                    }, $item['matched_type_parts']),
                    'all_type_matched' => $item['all_type_parts_matched'],
                    'match_ratio' => $item['type_parts_count'] > 0 
                        ? round(($item['matched_count'] / $item['type_parts_count']) * 100) . '%'
                        : '0%'
                ];
            })->toArray()
        ]);

        // ✨ FILTRAGE STRICT : NAME ET TYPE doivent TOUS LES DEUX matcher
        // SAUF pour cas spéciaux (Valentino + coffrets OU Météorites + éditions limitées)
        $scoredProducts = $scoredProducts->filter(function($item) use ($nameWords, $shouldSkipTypeFilter) {
            $hasNameMatch = !empty($nameWords) ? $item['name_match_count'] > 0 : true;
            $hasStrongNameMatch = $item['has_strong_name_match']; // 2+ mots du NAME
            $hasTypeMatch = $item['type_matched']; // Au moins le type de base matche
            
            // ✨ RÈGLE ASSOUPLIE pour cas spéciaux :
            // - Pour cas spéciaux : NAME doit matcher, TYPE est optionnel
            // - Pour les autres : NAME ET TYPE doivent matcher
            if ($shouldSkipTypeFilter) {
                $keepProduct = $item['score'] > 0 && $hasNameMatch;
            } else {
                $keepProduct = $item['score'] > 0 && $hasNameMatch && $hasTypeMatch;
            }
            
            if (!$keepProduct) {
                \Log::debug('Produit exclu', [
                    'product_id' => $item['product']['id'] ?? 0,
                    'product_name' => $item['product']['name'] ?? '',
                    'product_type' => $item['product']['type'] ?? '',
                    'score' => $item['score'],
                    'is_special_case' => $shouldSkipTypeFilter,
                    'is_meteorites' => $item['is_meteorites'] ?? false,
                    'is_limited_edition' => $item['is_limited_edition'] ?? false,
                    'name_match' => $hasNameMatch,
                    'strong_name_match' => $hasStrongNameMatch,
                    'type_match' => $hasTypeMatch,
                    'name_match_count' => $item['name_match_count'],
                    'name_words_total' => $item['name_words_total'],
                    'raison' => !$hasNameMatch ? 'NAME ne matche pas' : (!$hasTypeMatch && !$shouldSkipTypeFilter ? 'TYPE ne matche pas' : 'Score trop faible')
                ]);
            }
            
            return $keepProduct;
        });

        \Log::info('Après filtrage (NAME ET TYPE OBLIGATOIRES sauf cas spéciaux)', [
            'produits_restants' => $scoredProducts->count(),
            'is_special_case' => $shouldSkipTypeFilter
        ]);

        if ($scoredProducts->isEmpty()) {
            \Log::info('Aucun produit après filtrage');
            $this->matchingProducts = [];
            $this->groupedResults = [];
            return;
        }

        $rankedProducts = $scoredProducts->pluck('product')->toArray();
        $this->matchingProducts = $rankedProducts;

        \Log::info('Produits après scoring (avant déduplication)', [
            'count' => count($this->matchingProducts),
            'best_score' => $scoredProducts->first()['score'] ?? 0,
            'worst_score' => $scoredProducts->last()['score'] ?? 0
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
        
        if (count($parts) === 1) {
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
            
            foreach ($perfumeKeywords as $keyword) {
                if (str_contains($typeLower, $keyword)) {
                    $startPos = mb_strpos($typeLower, $keyword);
                    $originalPart = mb_substr($type, $startPos, mb_strlen($keyword));
                    $foundParts[] = $originalPart;
                    $typeLower = str_replace($keyword, '', $typeLower);
                    break;
                }
            }
            
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
            
            if (!empty($foundParts)) {
                \Log::info('Type décomposé en parties hiérarchiques', [
                    'type_original' => $type,
                    'parties' => $foundParts
                ]);
                return $foundParts;
            }
            
            $words = preg_split('/\s+/', $type);
            $words = array_filter($words, function($word) {
                return mb_strlen($word) >= 3;
            });
            return array_values($words);
        }
        
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
     * ✨ VERSION AMÉLIORÉE : Extrait les mots-clés significatifs
     * Pour Valentino, les mots-clés "coffret", "set", "kit" sont exclus
     * Pour Météorites (Guerlain), le traitement spécial est géré ailleurs (via isMeteoritesProduct)
     */
    private function extractKeywords(string $text, bool $isSpecialVendor = false): array
    {
        if (empty($text)) {
            return [];
        }

        // Stop words de base
        $stopWords = ['de', 'la', 'le', 'les', 'des', 'du', 'un', 'une', 'et', 'ou', 'pour', 'avec', 'sans'];
        
        // ✨ Pour Valentino uniquement, ajouter les mots-clés coffret aux stop words
        if ($isSpecialVendor) {
            $stopWords = array_merge($stopWords, ['coffret', 'set', 'kit', 'duo', 'trio', 'collection']);
        }

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
     * Filtre les produits par type de base
     */
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
            \Log::info('Type de recherche non catégorisé, pas de filtrage par type de base', [
                'type' => $searchType
            ]);
            return $products;
        }

        \Log::info('Filtrage par catégorie de type', [
            'type_recherché' => $searchType,
            'catégorie' => $searchCategory,
            'mots_clés_catégorie' => $typeCategories[$searchCategory]
        ]);

        $filtered = collect($products)->filter(function ($product) use ($searchCategory, $typeCategories) {
            $productType = mb_strtolower($product['type'] ?? '');
            
            if (empty($productType)) {
                return false;
            }

            $productCategory = null;
            foreach ($typeCategories as $category => $keywords) {
                foreach ($keywords as $keyword) {
                    if (str_contains($productType, $keyword)) {
                        $productCategory = $category;
                        break 2;
                    }
                }
            }

            if (!$productCategory) {
                return true;
            }

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