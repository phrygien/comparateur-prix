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
     * LOGIQUE DE RECHERCHE OPTIMISÉE
     * 1. Filtrer par VENDOR (obligatoire)
     * 2. Filtrer par statut COFFRET
     * 3. FILTRAGE STRICT par NAME : TOUS les mots du name (hors vendor) doivent être présents
     *    Fallback : au moins 1 mot si filtrage strict ne donne rien
     * 4. SCORER avec :
     *    - BONUS ÉNORME (+500) si recherche coffret ET produit est coffret (PRIORITÉ ABSOLUE)
     *    - Matching HIÉRARCHIQUE sur le TYPE : vérifier étape par étape
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
        
        // Prendre les 2 premiers mots APRÈS avoir retiré le vendor
        $nameWords = array_slice(array_values($nameWordsFiltered), 0, 2);

        \Log::info('Mots-clés pour la recherche', [
            'vendor' => $vendor,
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

        // ÉTAPE 2.5: FILTRAGE STRICT par les mots du NAME
        // Si on a des mots du name, on filtre pour garder seulement les produits qui contiennent TOUS les mots
        if (!empty($nameWords)) {
            $nameFilteredProducts = collect($filteredProducts)->filter(function ($product) use ($nameWords) {
                $productName = mb_strtolower($product['name'] ?? '');
                
                // Le produit doit contenir TOUS les mots du name (pas juste 1)
                $matchCount = 0;
                foreach ($nameWords as $word) {
                    if (str_contains($productName, $word)) {
                        $matchCount++;
                    }
                }
                
                // Retourner true seulement si TOUS les mots sont trouvés
                return $matchCount === count($nameWords);
            })->values()->toArray();

            // Si on a des résultats après filtrage strict par name, on utilise ces résultats
            // Sinon essayer un filtrage plus souple (au moins 1 mot)
            if (!empty($nameFilteredProducts)) {
                $filteredProducts = $nameFilteredProducts;
                \Log::info('Produits après filtrage STRICT par NAME (tous les mots)', [
                    'count' => count($filteredProducts),
                    'nameWords_required' => $nameWords
                ]);
            } else {
                // Fallback : au moins 1 mot du name doit être présent
                $nameFilteredProductsSoft = collect($filteredProducts)->filter(function ($product) use ($nameWords) {
                    $productName = mb_strtolower($product['name'] ?? '');
                    foreach ($nameWords as $word) {
                        if (str_contains($productName, $word)) {
                            return true;
                        }
                    }
                    return false;
                })->values()->toArray();
                
                if (!empty($nameFilteredProductsSoft)) {
                    $filteredProducts = $nameFilteredProductsSoft;
                    \Log::info('Produits après filtrage SOUPLE par NAME (au moins 1 mot)', [
                        'count' => count($filteredProducts),
                        'nameWords_used' => $nameWords
                    ]);
                } else {
                    \Log::info('Aucun produit après filtrage NAME, on garde tous les produits du vendor');
                }
            }
        }

        // ÉTAPE 3: Scoring hiérarchique basé sur le TYPE + BONUS COFFRET
        $scoredProducts = collect($filteredProducts)->map(function ($product) use ($typeParts, $type, $isCoffretSource) {
            $score = 0;
            $productType = mb_strtolower($product['type'] ?? '');
            
            $matchedTypeParts = [];
            $typePartsCount = count($typeParts);

            // ==========================================
            // PRIORITÉ ABSOLUE : BONUS COFFRET
            // ==========================================
            $productIsCoffret = $this->isCoffret($product);
            
            // Si on cherche un coffret ET que le produit est un coffret, ÉNORME BONUS
            if ($isCoffretSource && $productIsCoffret) {
                $score += 500; // MEGA BONUS pour prioriser les coffrets
            }

            // ==========================================
            // MATCHING HIÉRARCHIQUE SUR LE TYPE
            // ==========================================
            
            if (!empty($typeParts) && !empty($productType)) {
                // Vérifier chaque partie du type dans l'ordre
                foreach ($typeParts as $index => $part) {
                    $partLower = mb_strtolower(trim($part));
                    if (!empty($partLower)) {
                        // Vérifier si cette partie du type est présente dans le type du produit
                        if (str_contains($productType, $partLower)) {
                            // Bonus décroissant selon la position dans la hiérarchie
                            // La première partie (la plus importante) donne plus de points
                            $partBonus = 100 - ($index * 20); // 100, 80, 60, 40, etc.
                            $partBonus = max($partBonus, 20); // Minimum 20 points
                            
                            $score += $partBonus;
                            $matchedTypeParts[] = [
                                'part' => $part,
                                'bonus' => $partBonus,
                                'position' => $index + 1
                            ];
                        }
                    }
                }
                
                // BONUS supplémentaire si TOUTES les parties du type correspondent
                if (count($matchedTypeParts) === $typePartsCount && $typePartsCount > 0) {
                    $score += 150; // +150 points pour un match complet
                }
                
                // BONUS MAXIMUM si le type complet est une sous-chaîne exacte
                $typeLower = mb_strtolower(trim($type));
                if (!empty($typeLower) && str_contains($productType, $typeLower)) {
                    $score += 200; // +200 points pour type exact dans le produit
                }
                
                // BONUS supplémentaire si le type du produit commence par le type recherché
                if (!empty($typeLower) && str_starts_with($productType, $typeLower)) {
                    $score += 100; // +100 points si le type est au début
                }
            }

            return [
                'product' => $product,
                'score' => $score,
                'matched_type_parts' => $matchedTypeParts,
                'all_type_parts_matched' => count($matchedTypeParts) === $typePartsCount,
                'type_parts_count' => $typePartsCount,
                'matched_count' => count($matchedTypeParts),
                'is_coffret' => $productIsCoffret,
                'coffret_bonus_applied' => ($isCoffretSource && $productIsCoffret)
            ];
        })
        // Trier par score décroissant (les coffrets auront le score le plus élevé)
        ->sortByDesc('score')
        ->values();

        \Log::info('Scoring détaillé (TYPE HIÉRARCHIQUE + BONUS COFFRET)', [
            'total_products' => $scoredProducts->count(),
            'type_recherche' => $type,
            'type_parts' => $typeParts,
            'recherche_coffret' => $isCoffretSource,
            'top_10_scores' => $scoredProducts->take(10)->map(function($item) {
                return [
                    'id' => $item['product']['id'] ?? 0,
                    'score' => $item['score'],
                    'is_coffret' => $item['is_coffret'],
                    'coffret_bonus' => $item['coffret_bonus_applied'],
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

        // Ne garder que ceux qui ont un score > 0
        $scoredProducts = $scoredProducts->filter(fn($item) => $item['score'] > 0);

        if ($scoredProducts->isEmpty()) {
            \Log::info('Aucun produit avec score > 0', [
                'typeParts' => $typeParts
            ]);
            
            // Fallback: si aucun match par type, on prend les 50 premiers produits du vendor
            $this->matchingProducts = array_slice($filteredProducts, 0, 50);
            $this->groupResultsByScrapeReference($this->matchingProducts);
            $this->validateBestMatchWithAI();
            return;
        }

        // Extraire uniquement les produits des résultats scorés
        $rankedProducts = $scoredProducts->pluck('product')->toArray();

        // Limiter à 50 résultats
        $this->matchingProducts = array_slice($rankedProducts, 0, 50);

        \Log::info('Produits après scoring', [
            'count' => count($this->matchingProducts),
            'best_score' => $scoredProducts->first()['score'] ?? 0,
            'worst_score' => $scoredProducts->last()['score'] ?? 0
        ]);

        // Grouper et valider avec l'IA
        $this->groupResultsByScrapeReference($this->matchingProducts);
        $this->validateBestMatchWithAI();
    }

    /**
     * Extrait les parties d'un type pour matching hiérarchique
     * Exemple: "Eau de Parfum Intense Vaporisateur" => ["Eau de Parfum", "Intense", "Vaporisateur"]
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
            // Mots-clés hiérarchiques pour les parfums
            $perfumeKeywords = ['eau de parfum', 'eau de toilette', 'parfum', 'eau fraiche', 'extrait'];
            $intensityKeywords = ['intense', 'extrême', 'absolu', 'concentré', 'léger', 'doux'];
            $formatKeywords = ['vaporisateur', 'spray', 'atomiseur', 'flacon', 'roller', 'stick'];
            
            $typeLower = mb_strtolower($type);
            $foundParts = [];
            
            // Chercher d'abord le type de parfum
            foreach ($perfumeKeywords as $keyword) {
                if (str_contains($typeLower, $keyword)) {
                    $foundParts[] = ucfirst($keyword);
                    break;
                }
            }
            
            // Chercher l'intensité
            foreach ($intensityKeywords as $keyword) {
                if (str_contains($typeLower, $keyword)) {
                    $foundParts[] = ucfirst($keyword);
                    break;
                }
            }
            
            // Chercher le format
            foreach ($formatKeywords as $keyword) {
                if (str_contains($typeLower, $keyword)) {
                    $foundParts[] = ucfirst($keyword);
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
                                    <p class="text-xs text-gray-400 mt-2">ID: {{ $product['scrape_reference_id'] }}</p>
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