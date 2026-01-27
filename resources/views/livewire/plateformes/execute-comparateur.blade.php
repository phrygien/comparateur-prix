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
    public $allFoundProducts = []; // Nouveau: tous les produits trouvés
    public $debugInfo = null; // Pour le débogage

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
        $this->allFoundProducts = [];
        $this->bestMatch = null;
        $this->aiValidation = null;
        $this->groupedResults = [];
        $this->debugInfo = null;

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            ])->post('https://api.openai.com/v1/chat/completions', [
                        'model' => 'gpt-4o-mini',
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => 'Tu es un expert en extraction de données de produits cosmétiques. Pour les marques, utilise TOUJOURS le nom officiel en MAJUSCULES. Exemple: "Yves Saint Laurent" devient "YVES SAINT LAURENT". Réponds UNIQUEMENT avec un objet JSON valide.'
                            ],
                            [
                                'role' => 'user',
                                'content' => "Extrait les informations suivantes du nom de produit et retourne-les au format JSON strict :
- vendor : la marque du produit (TOUJOURS en MAJUSCULES, ex: YVES SAINT LAURENT)
- name : le nom de la gamme/ligne de produit
- variation : la contenance/taille (ml, g, etc.)
- type : le type de produit (Crème, Sérum, Concentré, etc.)
- is_coffret : true si c'est un coffret/set/kit/collection, false sinon

Nom du produit : {$this->productName}

Exemple de format attendu :
{
  \"vendor\": \"YVES SAINT LAURENT\",
  \"name\": \"MON PARIS\",
  \"variation\": \"50ml\",
  \"type\": \"Eau de Parfum\",
  \"is_coffret\": true
}"
                            ]
                        ],
                        'temperature' => 0.1, // Plus bas pour plus de cohérence
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

                // Normaliser le vendor en MAJUSCULES
                if (isset($decodedData['vendor'])) {
                    $decodedData['vendor'] = mb_strtoupper(trim($decodedData['vendor']));
                }

                $this->extractedData = array_merge([
                    'vendor' => '',
                    'name' => '',
                    'variation' => '',
                    'type' => '',
                    'is_coffret' => false
                ], $decodedData);

                // Debug info
                $this->debugInfo = [
                    'produit_source' => $this->productName,
                    'extracted_data' => $this->extractedData,
                    'sites_selectionnes' => count($this->selectedSites)
                ];

                \Log::info('Extraction IA réussie', $this->debugInfo);

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
     * Normalise un nom de vendor pour la recherche
     */
    private function normalizeVendor(string $vendor): string
    {
        $vendor = trim($vendor);

        // Convertir en majuscules
        $vendor = mb_strtoupper($vendor);

        // Normalisations spécifiques pour les marques courantes
        $normalizations = [
            'YVES SAINT LAURENT' => 'YVES SAINT LAURENT',
            'YSL' => 'YVES SAINT LAURENT',
            'ESTEE LAUDER' => 'ESTÉE LAUDER',
            'MAC COSMETICS' => 'M·A·C',
            'MAC' => 'M·A·C',
            'PUPA' => 'PUPA',
            'MAISON MARGIELA' => 'MAISON MARGIELA',
            'M·A·C' => 'M·A·C',
        ];

        return $normalizations[$vendor] ?? $vendor;
    }

    /**
     * Vérifie si un produit est un coffret
     */
    private function isCoffret($product): bool
    {
        $cofferKeywords = ['coffret', 'set', 'kit', 'duo', 'trio', 'collection', 'pack'];

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
        $isCoffretSource = $extractedData['is_coffret'] ?? false;

        // Normaliser le vendor
        $vendorNormalized = $this->normalizeVendor($vendor);
        $vendorSearch = mb_strtoupper(trim($vendorNormalized));

        \Log::info('Début recherche', [
            'vendor_original' => $vendor,
            'vendor_normalized' => $vendorNormalized,
            'vendor_search' => $vendorSearch,
            'name' => $name,
            'type' => $type,
            'is_coffret' => $isCoffretSource,
            'sites_selectionnes' => count($this->selectedSites)
        ]);

        // Initialiser la collection de tous les résultats
        $allFound = collect();

        // BASE QUERY - Recherche par VENDOR (flexible)
        $baseQuery = Product::query()
            ->when(!empty($vendorSearch), function ($q) use ($vendorSearch) {
                // Recherche FLEXIBLE par vendor
                $q->where(function ($subQuery) use ($vendorSearch) {
                    // 1. Recherche exacte (insensible à la casse)
                    $subQuery->whereRaw('UPPER(TRIM(vendor)) = ?', [$vendorSearch]);

                    // 2. Recherche par similarité (pour les variantes)
                    $vendorWords = explode(' ', $vendorSearch);
                    foreach ($vendorWords as $word) {
                        if (mb_strlen($word) > 2) {
                            $subQuery->orWhereRaw('UPPER(vendor) LIKE ?', ['%' . $word . '%']);
                        }
                    }

                    // 3. Cas spéciaux pour certaines marques
                    if ($vendorSearch === 'YVES SAINT LAURENT') {
                        $subQuery->orWhereRaw('UPPER(vendor) LIKE ?', ['%YSL%']);
                    }
                });
            })
            ->when(!empty($this->selectedSites), function ($q) {
                $q->whereIn('web_site_id', $this->selectedSites);
            });

        // DEBUG: Compter tous les produits de ce vendor
        $totalVendorProducts = (clone $baseQuery)->count();
        \Log::info('Produits du vendor trouvés', [
            'vendor' => $vendorSearch,
            'total' => $totalVendorProducts
        ]);

        // STRATÉGIE DE RECHERCHE EN CASCADE

        // 1. Recherche par NAME EXACT (si disponible)
        if (!empty($name)) {
            $nameNormalized = mb_strtoupper(trim($name));

            $exactNameMatch = (clone $baseQuery)
                ->where(function ($q) use ($nameNormalized) {
                    // Recherche exacte insensible à la casse
                    $q->whereRaw('UPPER(TRIM(name)) = ?', [$nameNormalized])
                        ->orWhere('name', '=', $nameNormalized);
                })
                ->get();

            if ($exactNameMatch->isNotEmpty()) {
                $filtered = $this->filterByCoffretStatus($exactNameMatch, $isCoffretSource);
                $allFound = $allFound->merge($filtered);
                \Log::info('Résultats name exact', ['count' => $filtered->count()]);
            }
        }

        // 2. Recherche par SIMILARITÉ de NAME (mots-clés)
        if (!empty($name)) {
            $nameNormalized = mb_strtoupper(trim($name));
            $nameWords = explode(' ', $nameNormalized);
            $nameWords = array_filter($nameWords, function ($word) {
                return mb_strlen($word) >= 3;
            });

            if (!empty($nameWords)) {
                $similarNameMatch = (clone $baseQuery);
                foreach ($nameWords as $word) {
                    $similarNameMatch->where(function ($q) use ($word) {
                        $q->whereRaw('UPPER(name) LIKE ?', ['%' . $word . '%'])
                            ->orWhere('name', 'LIKE', '%' . $word . '%');
                    });
                }
                $similarNameMatch = $similarNameMatch->get();

                if ($similarNameMatch->isNotEmpty()) {
                    $filtered = $this->filterByCoffretStatus($similarNameMatch, $isCoffretSource);
                    // Éviter les doublons
                    $newResults = $filtered->filter(function ($item) use ($allFound) {
                        return !$allFound->contains('id', $item->id);
                    });
                    $allFound = $allFound->merge($newResults);
                    \Log::info('Résultats name similaire', ['count' => $newResults->count()]);
                }
            }
        }

        // 3. Recherche par TYPE (si name n'a pas donné assez de résultats)
        if ($allFound->count() < 3 && !empty($type)) {
            $typeNormalized = mb_strtoupper(trim($type));
            $typeWords = explode(' ', $typeNormalized);
            $typeWords = array_filter($typeWords, function ($word) {
                return mb_strlen($word) >= 3;
            });

            if (!empty($typeWords)) {
                $typeMatch = (clone $baseQuery)
                    ->where(function ($q) use ($typeWords) {
                        foreach ($typeWords as $word) {
                            $q->orWhereRaw('UPPER(type) LIKE ?', ['%' . $word . '%'])
                                ->orWhere('type', 'LIKE', '%' . $word . '%');
                        }
                    })
                    ->limit(50)
                    ->get();

                if ($typeMatch->isNotEmpty()) {
                    $filtered = $this->filterByCoffretStatus($typeMatch, $isCoffretSource);
                    // Éviter les doublons
                    $newResults = $filtered->filter(function ($item) use ($allFound) {
                        return !$allFound->contains('id', $item->id);
                    });
                    $allFound = $allFound->merge($newResults);
                    \Log::info('Résultats par type', ['count' => $newResults->count()]);
                }
            }
        }

        // 4. Si toujours peu de résultats, prendre TOUS les produits du vendor (filtrés par coffret)
        if ($allFound->count() < 5 && !empty($vendorSearch)) {
            $allVendorProducts = (clone $baseQuery)
                ->orderByDesc('scrap_reference_id')
                ->orderByDesc('id')
                ->limit(100)
                ->get();

            if ($allVendorProducts->isNotEmpty()) {
                $filtered = $this->filterByCoffretStatus($allVendorProducts, $isCoffretSource);
                // Éviter les doublons
                $newResults = $filtered->filter(function ($item) use ($allFound) {
                    return !$allFound->contains('id', $item->id);
                });
                $allFound = $allFound->merge($newResults);
                \Log::info('Tous produits du vendor', ['count' => $newResults->count()]);
            }
        }

        // 5. Traitement des résultats finaux
        if ($allFound->isNotEmpty()) {
            // Éliminer les doublons par ID
            $allFound = $allFound->unique('id');

            // Trier par scrap_reference_id décroissant
            $allFound = $allFound->sortByDesc('scrap_reference_id')
                ->sortByDesc('id')
                ->values();

            // Stocker TOUS les résultats
            $this->allFoundProducts = $allFound->toArray();

            \Log::info('Résultats finaux', [
                'total' => $allFound->count(),
                'sites_uniques' => $allFound->pluck('web_site_id')->unique()->count(),
                'premiers_produits' => $allFound->take(3)->pluck('name', 'web_site_id')->toArray()
            ]);

            // Grouper pour affichage (1 par site)
            $this->groupResultsBySiteAndProduct($this->allFoundProducts);

            // Validation IA
            $this->validateBestMatchWithAI();
        } else {
            \Log::warning('AUCUN résultat trouvé', [
                'vendor' => $vendorSearch,
                'name' => $name,
                'sites_selectionnes' => count($this->selectedSites)
            ]);

            $this->matchingProducts = [];
            $this->allFoundProducts = [];
            $this->groupedResults = [];
        }
    }

    /**
     * Filtre les produits selon leur statut coffret
     * Accepte à la fois les Collections et les tableaux
     */
    private function filterByCoffretStatus($products, bool $sourceisCoffret)
    {
        // Si c'est déjà un tableau, convertir en Collection
        if (is_array($products)) {
            $products = collect($products);
        }

        // Maintenant $products est toujours une Collection
        return $products->filter(function ($product) use ($sourceisCoffret) {
            // S'assurer que $product est un tableau
            if (is_object($product) && method_exists($product, 'toArray')) {
                $productArray = $product->toArray();
            } elseif (is_array($product)) {
                $productArray = $product;
            } else {
                $productArray = (array) $product;
            }

            $productIsCoffret = $this->isCoffret($productArray);

            // Si la source est un coffret, garder seulement les coffrets
            // Si la source n'est pas un coffret, exclure les coffrets
            return $sourceisCoffret ? $productIsCoffret : !$productIsCoffret;
        })->values();
    }

    /**
     * Groupe les résultats par site et garde le produit avec le scrap_reference_id le plus élevé
     * Pour éviter les doublons sur le même site
     */
    private function groupResultsBySiteAndProduct(array $products)
    {
        if (empty($products)) {
            $this->matchingProducts = [];
            $this->groupedResults = [];
            return;
        }

        // Convertir en collection
        $productsCollection = collect($products);

        // 1. Grouper par site
        $groupedBySite = $productsCollection->groupBy('web_site_id');

        // 2. Pour chaque site, garder le produit avec le scrap_reference_id le plus élevé
        $uniqueProductsBySite = $groupedBySite->map(function ($siteProducts, $siteId) {
            return $siteProducts->sortByDesc('scrap_reference_id')
                ->sortByDesc('id')
                ->first();
        })->filter()->values();

        // Limiter à 50 résultats maximum pour l'affichage principal
        $this->matchingProducts = $uniqueProductsBySite->take(50)->toArray();

        // 3. Stocker les résultats groupés pour l'affichage
        $this->groupedResults = $groupedBySite->map(function ($siteProducts, $siteId) {
            return [
                'site_id' => $siteId,
                'total_products' => $siteProducts->count(),
                'max_scrap_reference_id' => $siteProducts->max('scrap_reference_id'),
                'latest_product' => $siteProducts->sortByDesc('scrap_reference_id')->first(),
                'product_ids' => $siteProducts->pluck('id')->toArray(),
                'site_name' => collect($this->availableSites)->firstWhere('id', $siteId)['name'] ?? 'Inconnu'
            ];
        })->toArray();

        \Log::info('Groupement résultats', [
            'produits_totaux' => count($products),
            'produits_uniques_par_site' => count($this->matchingProducts),
            'sites_avec_resultats' => count($this->groupedResults)
        ]);
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
        $text = str_replace(["'", "’", "-"], " ", $text);
        $words = preg_split('/[\s\-]+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        // Filtrer les mots courts et les stop words
        $keywords = array_filter($words, function ($word) use ($stopWords) {
            return mb_strlen($word) >= 2 && !in_array($word, $stopWords);
        });

        return array_values($keywords);
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

INSTRUCTION IMPORTANTE : Tous les produits candidats ont déjà le MÊME VENDOR. Concentre-toi sur la correspondance du nom et du type.

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
- Name très similaire = +40 points
- Type identique = +30 points
- Variation identique = +20 points
- Prix cohérent = +10 points
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
        
        <!-- Debug info -->
        @if($debugInfo)
            <div class="mt-2 p-2 bg-gray-100 rounded text-xs">
                <p class="font-semibold">Info extraction :</p>
                <p>Vendor: <span class="font-bold">{{ $extractedData['vendor'] ?? 'N/A' }}</span></p>
                <p>Sites sélectionnés: {{ $debugInfo['sites_selectionnes'] ?? 0 }}</p>
            </div>
        @endif
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
                {{ count($selectedSites) }} site(s) sélectionné(s) sur {{ count($availableSites) }}
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
            <h3 class="font-bold mb-3">Critères extraits par IA :</h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <span class="font-semibold">Vendor:</span> 
                    <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-sm font-bold">
                        {{ $extractedData['vendor'] ?? 'N/A' }}
                    </span>
                </div>
                <div>
                    <span class="font-semibold">Name:</span> 
                    <span class="bg-green-100 text-green-800 px-2 py-1 rounded text-sm">
                        {{ $extractedData['name'] ?? 'N/A' }}
                    </span>
                </div>
                <div>
                    <span class="font-semibold">Type:</span> {{ $extractedData['type'] ?? 'N/A' }}
                </div>
                <div>
                    <span class="font-semibold">Variation:</span> {{ $extractedData['variation'] ?? 'N/A' }}
                </div>
                <div class="col-span-2">
                    <span class="font-semibold">Est un coffret:</span>
                    <span class="px-2 py-1 rounded text-sm {{ ($extractedData['is_coffret'] ?? false) ? 'bg-purple-100 text-purple-800' : 'bg-gray-100 text-gray-800' }}">
                        {{ ($extractedData['is_coffret'] ?? false) ? 'Oui' : 'Non' }}
                    </span>
                </div>
            </div>
        </div>
    @endif

    @if(!empty($allFoundProducts))
        <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded">
            <p class="text-sm text-blue-800">
                <span class="font-semibold">{{ count($allFoundProducts) }}</span> produit(s) trouvé(s) pour 
                <span class="font-bold">{{ $extractedData['vendor'] ?? 'N/A' }}</span>
                <span class="text-xs ml-2">({{ count($matchingProducts) }} affichés - 1 par site)</span>
            </p>
            
            <!-- Liste des sites avec résultats -->
            @if(!empty($groupedResults))
                <div class="mt-2">
                    <p class="text-xs font-semibold text-blue-700">Sites avec résultats ({{ count($groupedResults) }}) :</p>
                    <div class="flex flex-wrap gap-1 mt-1">
                        @foreach($groupedResults as $siteId => $siteData)
                            @php
                                $siteName = $siteData['site_name'] ?? 'Site ' . $siteId;
                                $productCount = $siteData['total_products'] ?? 0;
                            @endphp
                            <span class="text-xs px-2 py-1 bg-white border border-blue-200 rounded">
                                {{ $siteName }} ({{ $productCount }})
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif

    @if(!empty($aiValidation) && is_array($aiValidation))
        <div class="mt-4 p-4 bg-blue-50 border border-blue-300 rounded">
            <h3 class="font-bold text-blue-700 mb-2">🤖 Validation IA :</h3>
            <p class="text-sm mb-1">
                <span class="font-semibold">Score de confiance:</span>
                <span class="text-lg font-bold {{ ($aiValidation['confidence_score'] ?? 0) >= 0.8 ? 'text-green-600' : (($aiValidation['confidence_score'] ?? 0) >= 0.6 ? 'text-yellow-600' : 'text-red-600') }}">
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
                    <p class="text-sm text-gray-600">{{ $bestMatch['type'] ?? '' }} | {{ $bestMatch['variation'] ?? '' }}</p>
                    <p class="text-xs text-gray-500 mt-1">
                        Ref ID: {{ $bestMatch['scrap_reference_id'] ?? 'N/A' }} | ID: {{ $bestMatch['id'] ?? 'N/A' }}
                    </p>
                    
                    <!-- Site info -->
                    @php
                        $siteInfo = collect($availableSites)->firstWhere('id', $bestMatch['web_site_id'] ?? 0);
                    @endphp
                    @if(!empty($siteInfo))
                        <div class="mt-2">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                {{ $siteInfo['name'] ?? '' }}
                            </span>
                        </div>
                    @endif
                    
                    <p class="text-sm font-bold text-green-600 mt-2">{{ $bestMatch['prix_ht'] ?? 0 }}
                        {{ $bestMatch['currency'] ?? '' }}</p>
                    @if(!empty($bestMatch['url']))
                        <a href="{{ $bestMatch['url'] }}" target="_blank" class="text-xs text-blue-500 hover:underline">Voir le produit</a>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <!-- Produits uniques par site -->
    @if(!empty($matchingProducts) && count($matchingProducts) > 0)
        <div class="mt-6">
            <h3 class="font-bold mb-3">
                Produits par site ({{ count($matchingProducts) }}) :
                <span class="text-sm font-normal text-gray-600">(Dernier scrap par site - Cliquez pour sélectionner)</span>
            </h3>
            <div class="space-y-3 max-h-96 overflow-y-auto">
                @foreach($matchingProducts as $product)
                    @php
                        $product = is_array($product) ? $product : [];
                        $siteInfo = collect($availableSites)->firstWhere('id', $product['web_site_id'] ?? 0);
                        $siteId = $product['web_site_id'] ?? 0;
                        $siteProductCount = $groupedResults[$siteId]['total_products'] ?? 0;
                    @endphp
                    <div wire:click="selectProduct({{ $product['id'] ?? 0 }})"
                        class="p-3 border rounded hover:bg-blue-50 cursor-pointer transition {{ !empty($bestMatch['id']) && $bestMatch['id'] === ($product['id'] ?? 0) ? 'bg-blue-100 border-blue-500' : 'bg-white' }}">
                        <div class="flex items-start gap-3">
                            @if(!empty($product['image_url']))
                                <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] ?? '' }}"
                                    class="w-12 h-12 object-cover rounded">
                            @endif
                            <div class="flex-1">
                                <div class="flex justify-between">
                                    <div>
                                        <p class="font-medium text-sm">{{ $product['vendor'] ?? '' }} - {{ $product['name'] ?? '' }}</p>
                                        <p class="text-xs text-gray-500">{{ $product['type'] ?? '' }} | {{ $product['variation'] ?? '' }}</p>
                                    </div>
                                    <p class="font-bold text-sm">{{ $product['prix_ht'] ?? 0 }} {{ $product['currency'] ?? '' }}</p>
                                </div>
                                
                                <div class="flex items-center justify-between mt-2">
                                    <div class="flex items-center gap-2">
                                        @if(!empty($siteInfo))
                                            <span class="text-xs px-2 py-1 rounded bg-gray-100 text-gray-800">
                                                {{ $siteInfo['name'] ?? '' }}
                                                @if($siteProductCount > 1)
                                                    <span class="ml-1">({{ $siteProductCount }} versions)</span>
                                                @endif
                                            </span>
                                        @endif
                                        <span class="text-xs text-gray-500">Ref ID: {{ $product['scrap_reference_id'] ?? 0 }}</span>
                                    </div>
                                    
                                    <div class="text-right">
                                        <span class="text-xs text-gray-500">ID: {{ $product['id'] ?? 0 }}</span>
                                        @if(!empty($product['url']))
                                            <a href="{{ $product['url'] }}" target="_blank" 
                                                class="ml-2 text-xs text-blue-500 hover:text-blue-700 hover:underline"
                                                onclick="event.stopPropagation();">
                                                Voir
                                            </a>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <!-- Tous les produits trouvés -->
    @if(!empty($allFoundProducts) && count($allFoundProducts) > count($matchingProducts))
        <div class="mt-6">
            <h3 class="font-bold mb-3">
                Tous les produits ({{ count($allFoundProducts) }}) :
                <span class="text-sm font-normal text-gray-400">(Toutes versions, tous sites)</span>
            </h3>
            <div class="space-y-2 max-h-96 overflow-y-auto">
                @foreach($allFoundProducts as $product)
                    @php
                        $product = is_array($product) ? $product : [];
                        $siteInfo = collect($availableSites)->firstWhere('id', $product['web_site_id'] ?? 0);
                    @endphp
                    <div class="p-3 border rounded bg-gray-50">
                        <div class="flex items-start gap-3">
                            <div class="flex-1">
                                <div class="flex justify-between">
                                    <div>
                                        <p class="font-medium text-sm">{{ $product['vendor'] ?? '' }} - {{ $product['name'] ?? '' }}</p>
                                        <p class="text-xs text-gray-500">{{ $product['type'] ?? '' }} | {{ $product['variation'] ?? '' }}</p>
                                    </div>
                                    <p class="font-bold text-sm">{{ $product['prix_ht'] ?? 0 }} {{ $product['currency'] ?? '' }}</p>
                                </div>
                                
                                <div class="flex items-center justify-between mt-1">
                                    <div class="flex items-center gap-2">
                                        @if(!empty($siteInfo))
                                            <span class="text-xs px-2 py-1 bg-gray-200 text-gray-700 rounded">
                                                {{ $siteInfo['name'] ?? '' }}
                                            </span>
                                        @endif
                                        <span class="text-xs text-gray-500">
                                            Ref ID: {{ $product['scrap_reference_id'] ?? 0 }}
                                        </span>
                                    </div>
                                    <span class="text-xs text-gray-500">ID: {{ $product['id'] ?? 0 }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if(!empty($extractedData) && empty($allFoundProducts))
        <div class="mt-6 p-4 bg-yellow-50 border border-yellow-300 rounded">
            <p class="text-yellow-800">
                ❌ Aucun produit trouvé pour la marque "{{ $extractedData['vendor'] ?? 'N/A' }}"
            </p>
            <p class="text-sm text-yellow-700 mt-1">
                Vérifiez que le vendor extrait correspond à celui dans la base de données.
            </p>
        </div>
    @endif
</div>