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
                                'content' => 'Tu es un expert en extraction de données de produits cosmétiques. Tu dois extraire vendor, name, variation, type et détecter si c\'est un coffret. Réponds UNIQUEMENT avec un objet JSON valide, sans markdown ni texte supplémentaire.'
                            ],
                            [
                                'role' => 'user',
                                'content' => "Extrait les informations suivantes du nom de produit et retourne-les au format JSON strict :
- vendor : la marque du produit
- name : le nom de la gamme/ligne de produit
- variation : la contenance/taille (ml, g, etc.)
- type : le type de produit (Crème, Sérum, Concentré, etc.)
- is_coffret : true si c'est un coffret/set/kit, false sinon

Nom du produit : {$this->productName}

Exemple de format attendu :
{
  \"vendor\": \"Shiseido\",
  \"name\": \"Vital Perfection\",
  \"variation\": \"20 ml\",
  \"type\": \"Concentré Correcteur Rides\",
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

    private function searchMatchingProducts()
    {
        // Vérifier plus rigoureusement que extractedData est valide
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
        $variation = $extractedData['variation'] ?? '';
        $isCoffretSource = $extractedData['is_coffret'] ?? false;

        // Si pas de vendor, abandonner la recherche
        if (empty($vendor)) {
            \Log::warning('Aucun vendor extrait, recherche impossible');
            return;
        }

        // Si pas de name ET pas de type, abandonner la recherche
        if (empty($name) && empty($type)) {
            \Log::warning('Ni name ni type extrait, recherche impossible');
            return;
        }

        // Extraire les mots clés
        $vendorWords = $this->extractKeywords($vendor);
        $nameWords = $this->extractKeywords($name);
        $typeWords = $this->extractKeywords($type);
        $variationWords = $this->extractKeywords($variation);

        // Construire la requête FULLTEXT en mode BOOLEAN
        $searchTerms = [];
        
        // Ajouter les mots du vendor (obligatoires avec +)
        foreach ($vendorWords as $word) {
            if (mb_strlen($word) >= 2) {
                $searchTerms[] = '+' . $word . '*';
            }
        }
        
        // Ajouter les mots du name (obligatoires avec +)
        foreach ($nameWords as $word) {
            if (mb_strlen($word) >= 2) {
                $searchTerms[] = '+' . $word . '*';
            }
        }
        
        // Ajouter les mots du type (obligatoires avec +)
        foreach ($typeWords as $word) {
            if (mb_strlen($word) >= 2) {
                $searchTerms[] = '+' . $word . '*';
            }
        }

        // Si on a aussi une variation, l'ajouter (optionnelle sans +)
        foreach ($variationWords as $word) {
            if (mb_strlen($word) >= 2 && is_numeric($word) === false) {
                $searchTerms[] = $word . '*';
            }
        }

        // Construire la chaîne de recherche finale
        $searchQuery = implode(' ', $searchTerms);

        if (empty($searchQuery)) {
            \Log::warning('Aucun terme de recherche valide construit');
            return;
        }

        \Log::info('Recherche FULLTEXT', [
            'search_query' => $searchQuery,
            'vendor' => $vendor,
            'name' => $name,
            'type' => $type
        ]);

        try {
            // Utiliser la vue last_price_scraped_product avec MATCH AGAINST
            $results = \DB::table('last_price_scraped_product')
                ->selectRaw('*')
                ->whereRaw(
                    "MATCH(name, vendor, type, variation) AGAINST (? IN BOOLEAN MODE)",
                    [$searchQuery]
                )
                ->when(!empty($this->selectedSites), function ($q) {
                    $q->whereIn('web_site_id', $this->selectedSites);
                })
                ->orderBy('created_at', 'DESC')
                ->orderBy('scrap_reference_id', 'DESC')
                ->limit(200)
                ->get();

            if ($results->isEmpty()) {
                \Log::info('Aucun résultat avec FULLTEXT, tentative de recherche alternative');
                
                // Fallback: recherche moins stricte si aucun résultat
                $searchTermsFallback = [];
                
                // Vendor obligatoire
                foreach ($vendorWords as $word) {
                    if (mb_strlen($word) >= 2) {
                        $searchTermsFallback[] = '+' . $word . '*';
                    }
                }
                
                // Name ou Type (au moins un des deux)
                $nameTypeTerms = [];
                foreach ($nameWords as $word) {
                    if (mb_strlen($word) >= 2) {
                        $nameTypeTerms[] = $word . '*';
                    }
                }
                foreach ($typeWords as $word) {
                    if (mb_strlen($word) >= 2) {
                        $nameTypeTerms[] = $word . '*';
                    }
                }
                
                if (!empty($nameTypeTerms)) {
                    $searchTermsFallback = array_merge($searchTermsFallback, $nameTypeTerms);
                }
                
                $searchQueryFallback = implode(' ', $searchTermsFallback);
                
                if (!empty($searchQueryFallback)) {
                    $results = \DB::table('last_price_scraped_product')
                        ->selectRaw('*')
                        ->whereRaw(
                            "MATCH(name, vendor, type, variation) AGAINST (? IN BOOLEAN MODE)",
                            [$searchQueryFallback]
                        )
                        ->when(!empty($this->selectedSites), function ($q) {
                            $q->whereIn('web_site_id', $this->selectedSites);
                        })
                        ->orderBy('created_at', 'DESC')
                        ->orderBy('scrap_reference_id', 'DESC')
                        ->limit(200)
                        ->get();
                }
            }

            // Convertir les résultats en array
            $productsArray = $results->map(function ($item) {
                return (array) $item;
            })->toArray();

            if (!empty($productsArray)) {
                // Filtrer par statut coffret
                $filtered = $this->filterByCoffretStatus(collect($productsArray), $isCoffretSource);
                
                if (!empty($filtered)) {
                    $this->groupAllProductsWithoutDuplicates($filtered);
                    $this->validateBestMatchWithAI();
                } else {
                    // Si le filtre coffret élimine tout, afficher quand même tous les résultats
                    $this->groupAllProductsWithoutDuplicates($productsArray);
                    $this->validateBestMatchWithAI();
                }
            }

        } catch (\Exception $e) {
            \Log::error('Erreur recherche FULLTEXT', [
                'message' => $e->getMessage(),
                'search_query' => $searchQuery ?? ''
            ]);
            
            // Fallback sur recherche LIKE classique en cas d'erreur
            $this->fallbackLikeSearch($vendor, $name, $type, $isCoffretSource);
        }
    }

    /**
     * Recherche de fallback utilisant LIKE si FULLTEXT échoue
     */
    private function fallbackLikeSearch($vendor, $name, $type, $isCoffretSource)
    {
        $vendorLower = mb_strtolower($vendor);
        $nameLower = mb_strtolower($name);
        $typeLower = mb_strtolower($type);

        try {
            $query = \DB::table('last_price_scraped_product')
                ->where(function ($q) use ($vendor, $vendorLower) {
                    $q->where('vendor', 'LIKE', "%{$vendor}%")
                        ->orWhereRaw('LOWER(vendor) LIKE ?', ['%' . $vendorLower . '%']);
                })
                ->when(!empty($this->selectedSites), function ($q) {
                    $q->whereIn('web_site_id', $this->selectedSites);
                });

            // Ajouter name ET/OU type
            if (!empty($name) && !empty($type)) {
                $query->where(function ($q) use ($name, $nameLower, $type, $typeLower) {
                    $q->where(function ($subQ) use ($name, $nameLower) {
                        $subQ->where('name', 'LIKE', "%{$name}%")
                            ->orWhereRaw('LOWER(name) LIKE ?', ['%' . $nameLower . '%']);
                    })
                    ->where(function ($subQ) use ($type, $typeLower) {
                        $subQ->where('type', 'LIKE', "%{$type}%")
                            ->orWhereRaw('LOWER(type) LIKE ?', ['%' . $typeLower . '%']);
                    });
                });
            } elseif (!empty($name)) {
                $query->where(function ($q) use ($name, $nameLower) {
                    $q->where('name', 'LIKE', "%{$name}%")
                        ->orWhereRaw('LOWER(name) LIKE ?', ['%' . $nameLower . '%']);
                });
            } elseif (!empty($type)) {
                $query->where(function ($q) use ($type, $typeLower) {
                    $q->where('type', 'LIKE', "%{$type}%")
                        ->orWhereRaw('LOWER(type) LIKE ?', ['%' . $typeLower . '%']);
                });
            }

            $results = $query->orderBy('created_at', 'DESC')
                ->orderBy('scrap_reference_id', 'DESC')
                ->limit(200)
                ->get();

            $productsArray = $results->map(function ($item) {
                return (array) $item;
            })->toArray();

            if (!empty($productsArray)) {
                $filtered = $this->filterByCoffretStatus(collect($productsArray), $isCoffretSource);
                
                if (!empty($filtered)) {
                    $this->groupAllProductsWithoutDuplicates($filtered);
                    $this->validateBestMatchWithAI();
                } else {
                    $this->groupAllProductsWithoutDuplicates($productsArray);
                    $this->validateBestMatchWithAI();
                }
            }
        } catch (\Exception $e) {
            \Log::error('Erreur recherche LIKE fallback', [
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Groupe les résultats par site et garde tous les produits UNIQUES par site
     * Élimine les doublons basés sur scrap_reference_id (garde le plus récent)
     */
    private function groupAllProductsWithoutDuplicates(array $products)
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
                'scrap_reference_id' => 0,
                'web_site_id' => 0,
                'id' => 0,
                'created_at' => now()->toDateTimeString()
            ], $product);
        });

        // 1. Grouper par site
        $groupedBySite = $productsCollection->groupBy('web_site_id');

        // 2. Pour chaque site, éliminer les doublons basés sur scrap_reference_id
        $uniqueProductsBySite = $groupedBySite->map(function ($siteProducts, $siteId) {
            $uniqueProducts = collect();
            $seenRefIds = [];

            // Trier par scrap_reference_id décroissant et created_at décroissant pour prendre le plus récent
            $sortedProducts = $siteProducts->sortByDesc('scrap_reference_id')
                ->sortByDesc('created_at');

            foreach ($sortedProducts as $product) {
                $scrapRefId = $product['scrap_reference_id'] ?? 0;
                
                // Si scrap_reference_id est 0, utiliser l'ID du produit comme clé
                $uniqueKey = $scrapRefId > 0 ? 'scrap_' . $scrapRefId : 'id_' . ($product['id'] ?? uniqid());
                
                // Ne garder que le premier (le plus récent) de chaque scrap_reference_id
                if (!isset($seenRefIds[$uniqueKey])) {
                    $seenRefIds[$uniqueKey] = true;
                    $uniqueProducts->push($product);
                }
            }

            return $uniqueProducts->values();
        });

        // 3. Aplatir tous les produits uniques de tous les sites
        $allUniqueProducts = $uniqueProductsBySite->flatMap(function ($products) {
            return $products;
        })->sortByDesc('scrap_reference_id')
          ->sortByDesc('created_at')
          ->values();

        // Limiter à 100 résultats maximum
        $this->matchingProducts = $allUniqueProducts->take(100)->toArray();

        // 4. Stocker les résultats groupés pour l'affichage
        $this->groupedResults = $groupedBySite->map(function ($siteProducts, $siteId) {
            $totalProducts = $siteProducts->count();
            
            // Éliminer les doublons pour les statistiques basés sur scrap_reference_id
            $uniqueProducts = collect();
            $seenRefIds = [];
            
            // Trier par scrap_reference_id décroissant et created_at décroissant
            $sortedProducts = $siteProducts->sortByDesc('scrap_reference_id')
                ->sortByDesc('created_at');
            
            foreach ($sortedProducts as $product) {
                $scrapRefId = $product['scrap_reference_id'] ?? 0;
                $uniqueKey = $scrapRefId > 0 ? 'scrap_' . $scrapRefId : 'id_' . ($product['id'] ?? uniqid());
                
                if (!isset($seenRefIds[$uniqueKey])) {
                    $seenRefIds[$uniqueKey] = true;
                    $uniqueProducts->push($product);
                }
            }
            
            $uniqueCount = $uniqueProducts->count();

            return [
                'site_id' => $siteId,
                'total_products' => $totalProducts,
                'unique_products' => $uniqueCount,
                'all_products' => $uniqueProducts->map(function ($product) {
                    return [
                        'id' => $product['id'] ?? 0,
                        'scrap_reference_id' => $product['scrap_reference_id'] ?? 0,
                        'scrape_reference' => $product['scrape_reference'] ?? '',
                        'vendor' => $product['vendor'] ?? '',
                        'name' => $product['name'] ?? '',
                        'type' => $product['type'] ?? '',
                        'variation' => $product['variation'] ?? '',
                        'price' => $product['prix_ht'] ?? 0,
                        'created_at' => $product['created_at'] ?? null,
                        'url' => $product['url'] ?? null,
                        'image_url' => $product['image_url'] ?? null
                    ];
                })->values()->toArray()
            ];
        })->toArray();
    }

    /**
     * Crée une clé unique pour un produit basée sur scrap_reference_id
     */
    private function createProductUniqueKey(array $product): string
    {
        // Utiliser scrap_reference_id comme clé unique principale
        $scrapRefId = $product['scrap_reference_id'] ?? 0;
        
        // Si scrap_reference_id est 0 ou null, utiliser l'ID du produit
        if (empty($scrapRefId)) {
            return 'id_' . ($product['id'] ?? uniqid());
        }
        
        return 'scrap_' . $scrapRefId;
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
        $stopWords = ['de', 'la', 'le', 'les', 'des', 'du', 'un', 'une', 'et', 'ou', 'pour', 'avec', 'sans', 'à'];

        // Nettoyer et découper (gérer les apostrophes)
        $text = mb_strtolower($text);
        // Remplacer les apostrophes par des espaces pour séparer les mots
        $text = str_replace(["'", "'", "-"], " ", $text);
        $words = preg_split('/[\s\-]+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        // Filtrer les mots courts et les stop words
        $keywords = array_filter($words, function ($word) use ($stopWords) {
            return mb_strlen($word) >= 2 && !in_array($word, $stopWords);
        });

        return array_values($keywords);
    }

    /**
     * Filtre les produits selon leur statut coffret
     * Retourne les produits filtrés, ou tous les produits si le filtre élimine tout
     */
    private function filterByCoffretStatus($products, bool $sourceisCoffret): array
    {
        // S'assurer que $products est une collection
        if (is_array($products)) {
            $products = collect($products);
        }
        
        $filtered = $products->filter(function ($product) use ($sourceisCoffret) {
            // Convertir en array si c'est un objet
            $productArray = is_array($product) ? $product : (array) $product;
            $productIsCoffret = $this->isCoffret($productArray);

            // Si la source est un coffret, garder seulement les coffrets
            // Si la source n'est pas un coffret, exclure les coffrets
            return $sourceisCoffret ? $productIsCoffret : !$productIsCoffret;
        })->values()->toArray();

        // Si le filtre élimine tout, retourner tous les produits originaux
        if (empty($filtered)) {
            return $products->toArray();
        }

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

        // Préparer les données pour l'IA - prendre plus de candidats
        $candidateProducts = array_slice($this->matchingProducts, 0, 10); // Max 10 produits

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
                                'content' => 'Tu es un expert en matching de produits cosmétiques. Tu dois analyser la correspondance entre un produit source et une liste de candidats, puis retourner l\'ID du meilleur match avec un score de confiance. IMPORTANT: Tu dois toujours retourner un résultat, même si le score est faible. Réponds UNIQUEMENT avec un objet JSON.'
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
  \"confidence_score\": 0.45,
  \"reasoning\": \"Explication courte du choix\",
  \"all_scores\": [
    {\"id\": 123, \"score\": 0.45, \"reason\": \"...\"},
    {\"id\": 124, \"score\": 0.30, \"reason\": \"...\"}
  ]
}

Critères de scoring :
- Vendor exact = +40 points
- Name similaire = +30 points
- Type identique = +20 points
- Variation identique = +10 points
Score de confiance entre 0 et 1.

IMPORTANT: Retourne TOUJOURS le meilleur candidat disponible, même si le score est faible (0.1, 0.2, etc.). Ne refuse jamais de retourner un résultat."
                            ]
                        ],
                        'temperature' => 0.2,
                        'max_tokens' => 1000
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
                        // Fallback sur le premier résultat
                        $this->bestMatch = $this->matchingProducts[0] ?? null;
                    }
                } else {
                    // Fallback sur le premier résultat
                    $this->bestMatch = $this->matchingProducts[0] ?? null;
                }
            } else {
                // En cas d'erreur API, toujours prendre le premier résultat
                $this->bestMatch = $this->matchingProducts[0] ?? null;
            }

        } catch (\Exception $e) {
            \Log::error('Erreur validation IA', [
                'message' => $e->getMessage(),
                'product_name' => $this->productName
            ]);

            // Fallback sur le premier résultat en cas d'erreur
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

<div class="p-6 bg-white rounded-lg shadow">
    <div class="mb-4">
        <h2 class="text-xl font-bold mb-2">Extraction et recherche de produit</h2>
        <p class="text-gray-600">Produit: {{ $productName }}</p>
        <p class="text-sm text-gray-500 mt-1">
            <span class="font-semibold">Affichage :</span> Tous les produits uniques groupés par site
        </p>
    </div>

    <!-- Filtres par site -->
    @if(!empty($availableSites))
        <div class="mb-6 p-4 bg-gray-50 rounded-lg border border-gray-200">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-semibold text-gray-700">Filtrer par site</h3>
                <button wire:click="toggleAllSites" class="text-sm text-blue-600 hover:text-blue-800 font-medium">
                    {{ count($selectedSites) === count($availableSites) ? 'Tout désélectionner' : 'Tout sélectionner' }}
                </button>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2">
                @foreach($availableSites as $site)
                    <label class="flex items-center space-x-2 cursor-pointer hover:bg-gray-100 p-2 rounded">
                        <input type="checkbox" wire:model.live="selectedSites" value="{{ $site['id'] ?? '' }}"
                            class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span class="text-sm">{{ $site['name'] ?? 'Site inconnu' }}</span>
                    </label>
                @endforeach
            </div>
            <p class="text-xs text-gray-500 mt-2">
                {{ count($selectedSites) }} site(s) sélectionné(s)
            </p>
        </div>
    @endif

    <button wire:click="extractSearchTerme" wire:loading.attr="disabled"
        class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600 disabled:opacity-50">
        <span wire:loading.remove>Extraire et rechercher</span>
        <span wire:loading>Extraction en cours...</span>
    </button>

    @if(session('error'))
        <div class="mt-4 p-4 bg-red-100 text-red-700 rounded">
            {{ session('error') }}
        </div>
    @endif

    @if(session('success'))
        <div class="mt-4 p-4 bg-green-100 text-green-700 rounded">
            {{ session('success') }}
        </div>
    @endif

    @if(!empty($extractedData) && is_array($extractedData))
        <div class="mt-6 p-4 bg-gray-50 rounded">
            <h3 class="font-bold mb-3">Critères extraits :</h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <span class="font-semibold">Vendor:</span> {{ $extractedData['vendor'] ?? 'N/A' }}
                </div>
                <div>
                    <span class="font-semibold">Name:</span> {{ $extractedData['name'] ?? 'N/A' }}
                </div>
                <div>
                    <span class="font-semibold">Variation:</span> {{ $extractedData['variation'] ?? 'N/A' }}
                </div>
                <div>
                    <span class="font-semibold">Type:</span> {{ $extractedData['type'] ?? 'N/A' }}
                </div>
                <div class="col-span-2">
                    <span class="font-semibold">Est un coffret:</span>
                    <span
                        class="px-2 py-1 rounded text-sm {{ ($extractedData['is_coffret'] ?? false) ? 'bg-purple-100 text-purple-800' : 'bg-gray-100 text-gray-800' }}">
                        {{ ($extractedData['is_coffret'] ?? false) ? 'Oui' : 'Non' }}
                    </span>
                </div>
            </div>
        </div>
    @endif

    @if(!empty($groupedResults))
        @php
            $totalUniqueProducts = 0;
            $totalAllProducts = 0;
            foreach ($groupedResults as $siteData) {
                $totalUniqueProducts += $siteData['unique_products'] ?? 0;
                $totalAllProducts += $siteData['total_products'] ?? 0;
            }
        @endphp
        
        <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded">
            <p class="text-sm text-blue-800">
                <span class="font-semibold">{{ count($matchingProducts) }}</span> produit(s) unique(s) trouvé(s)
                sur <span class="font-semibold">{{ count($groupedResults) }}</span> site(s)
            </p>
            <p class="text-xs text-blue-600 mt-1">
                Total produits avant déduplication : {{ $totalAllProducts }} |
                Produits uniques après déduplication : {{ $totalUniqueProducts }} |
                Doublons éliminés : {{ $totalAllProducts - $totalUniqueProducts }}
            </p>
        </div>
    @endif

    @if(!empty($aiValidation) && is_array($aiValidation))
        <div class="mt-4 p-4 bg-blue-50 border border-blue-300 rounded">
            <h3 class="font-bold text-blue-700 mb-2">🤖 Validation IA :</h3>
            <p class="text-sm mb-1">
                <span class="font-semibold">Score de confiance:</span>
                <span
                    class="text-lg font-bold {{ ($aiValidation['confidence_score'] ?? 0) >= 0.8 ? 'text-green-600' : (($aiValidation['confidence_score'] ?? 0) >= 0.6 ? 'text-yellow-600' : 'text-red-600') }}">
                    {{ number_format(($aiValidation['confidence_score'] ?? 0) * 100, 0) }}%
                </span>
            </p>
            <p class="text-sm text-gray-700">
                <span class="font-semibold">Analyse:</span> {{ $aiValidation['reasoning'] ?? 'N/A' }}
            </p>
        </div>
    @endif

    @if(!empty($bestMatch) && is_array($bestMatch))
        <div class="mt-6 p-4 bg-green-50 border-2 border-green-500 rounded">
            <h3 class="font-bold text-green-700 mb-3">✓ Meilleur résultat :</h3>
            <div class="flex items-start gap-4">
                @if(!empty($bestMatch['image_url']))
                    <img src="{{ $bestMatch['image_url'] }}" alt="{{ $bestMatch['name'] ?? '' }}"
                        class="w-20 h-20 object-cover rounded">
                @endif
                <div class="flex-1">
                    <p class="font-semibold">{{ $bestMatch['vendor'] ?? '' }} - {{ $bestMatch['name'] ?? '' }}</p>
                    <p class="text-sm text-gray-600">{{ $bestMatch['type'] ?? '' }} | {{ $bestMatch['variation'] ?? '' }}
                    </p>
                    <p class="text-xs text-gray-500 mt-1">
                        Ref: {{ $bestMatch['scrape_reference'] ?? 'N/A' }} |
                        Scraped Ref ID: {{ $bestMatch['scrap_reference_id'] ?? 'N/A' }} |
                        ID: {{ $bestMatch['id'] ?? 'N/A' }}
                    </p>

                    <!-- Indicateur du site -->
                    @php
                        $siteInfo = collect($availableSites)->firstWhere('id', $bestMatch['web_site_id'] ?? 0);
                    @endphp
                    @if(!empty($siteInfo))
                        <div class="mt-2">
                            <span
                                class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                {{ $siteInfo['name'] ?? '' }}
                            </span>
                        </div>
                    @endif

                    <p class="text-sm font-bold text-green-600 mt-2">{{ $bestMatch['prix_ht'] ?? 0 }}
                        {{ $bestMatch['currency'] ?? '' }}
                    </p>
                    @if(!empty($bestMatch['url']))
                        <a href="{{ $bestMatch['url'] }}" target="_blank" class="text-xs text-blue-500 hover:underline">Voir le
                            produit</a>
                    @endif
                </div>
            </div>
        </div>
    @endif

    @if(!empty($matchingProducts) && count($matchingProducts) > 0)
        <div class="mt-6">
            <h3 class="font-bold mb-3">
                Tous les produits uniques trouvés ({{ count($matchingProducts) }} produits) :
                <span class="text-sm font-normal text-gray-600">(Cliquez pour sélectionner)</span>
            </h3>
            
            <!-- Affichage des produits regroupés par site -->
            @if(!empty($groupedResults))
                <div class="space-y-6">
                    @foreach($groupedResults as $siteId => $siteData)
                        @php
                            $siteInfo = collect($availableSites)->firstWhere('id', $siteId);
                        @endphp
                        @if(!empty($siteInfo) && !empty($siteData['all_products']))
                            <div class="border-2 rounded-lg overflow-hidden">
                                <!-- En-tête du site -->
                                <div class="bg-gradient-to-r from-blue-500 to-blue-600 text-white p-4">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <h4 class="text-lg font-bold">{{ $siteInfo['name'] }}</h4>
                                            <p class="text-sm text-blue-100">
                                                {{ $siteData['unique_products'] ?? 0 }} produit(s) unique(s)
                                                @if(($siteData['total_products'] ?? 0) > ($siteData['unique_products'] ?? 0))
                                                    <span class="text-xs">
                                                        ({{ ($siteData['total_products'] ?? 0) - ($siteData['unique_products'] ?? 0) }} doublon(s) éliminé(s))
                                                    </span>
                                                @endif
                                            </p>
                                        </div>
                                        <div class="text-right">
                                            <span class="text-2xl font-bold">{{ $siteData['unique_products'] ?? 0 }}</span>
                                            <p class="text-xs text-blue-100">produits</p>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Liste des produits du site -->
                                <div class="bg-white p-4 space-y-3 max-h-96 overflow-y-auto">
                                    @foreach($siteData['all_products'] as $product)
                                        <div wire:click="selectProduct({{ $product['id'] ?? 0 }})"
                                            class="p-3 border rounded-lg hover:bg-blue-50 cursor-pointer transition-all {{ !empty($bestMatch['id']) && $bestMatch['id'] === ($product['id'] ?? 0) ? 'bg-blue-100 border-blue-500 shadow-md' : 'hover:shadow-sm' }}">
                                            <div class="flex items-start gap-3">
                                                @if(!empty($product['image_url']))
                                                    <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] ?? '' }}"
                                                        class="w-16 h-16 object-cover rounded-md flex-shrink-0">
                                                @else
                                                    <div class="w-16 h-16 bg-gray-200 rounded-md flex items-center justify-center flex-shrink-0">
                                                        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                                        </svg>
                                                    </div>
                                                @endif
                                                
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex justify-between items-start gap-2">
                                                        <div class="flex-1 min-w-0">
                                                            <p class="font-semibold text-sm text-gray-900 truncate">
                                                                {{ $product['vendor'] ?? '' }}
                                                            </p>
                                                            <p class="font-medium text-sm text-gray-700 line-clamp-2">
                                                                {{ $product['name'] ?? '' }}
                                                            </p>
                                                        </div>
                                                        <p class="font-bold text-base text-green-600 whitespace-nowrap">
                                                            {{ number_format((float)($product['price'] ?? 0), 2) }} €
                                                        </p>
                                                    </div>
                                                    
                                                    <div class="mt-2 flex flex-wrap gap-2">
                                                        @if(!empty($product['type']))
                                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800">
                                                                {{ $product['type'] }}
                                                            </span>
                                                        @endif
                                                        @if(!empty($product['variation']))
                                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                                                {{ $product['variation'] }}
                                                            </span>
                                                        @endif
                                                    </div>
                                                    
                                                    <div class="mt-2 flex items-center justify-between text-xs text-gray-500">
                                                        <div class="flex items-center gap-3">
                                                            <span>Ref ID: {{ $product['scrap_reference_id'] ?? 0 }}</span>
                                                            <span>ID: {{ $product['id'] ?? 0 }}</span>
                                                            @if(!empty($product['created_at']))
                                                                <span class="text-gray-400">
                                                                    {{ \Carbon\Carbon::parse($product['created_at'])->format('d/m/Y') }}
                                                                </span>
                                                            @endif
                                                        </div>
                                                        
                                                        @if(!empty($product['url']))
                                                            <a href="{{ $product['url'] }}" target="_blank"
                                                                class="text-blue-600 hover:text-blue-800 hover:underline font-medium"
                                                                onclick="event.stopPropagation();">
                                                                Voir ›
                                                            </a>
                                                        @endif
                                                    </div>
                                                    
                                                    @if(!empty($bestMatch['id']) && $bestMatch['id'] === ($product['id'] ?? 0))
                                                        <div class="mt-2">
                                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                                ✓ Meilleur match
                                                            </span>
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    @if(!empty($extractedData) && empty($matchingProducts))
        <div class="mt-6 p-4 bg-yellow-50 border border-yellow-300 rounded">
            <p class="text-yellow-800">❌ Aucun produit trouvé avec ces critères (même vendor:
                {{ $extractedData['vendor'] ?? 'N/A' }}, même statut coffret)
            </p>
        </div>
    @endif
</div>
