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
        $isCoffretSource = $extractedData['is_coffret'] ?? false;

        // Extraire les mots clés
        $vendorWords = $this->extractKeywords($vendor);
        $nameWords = $this->extractKeywords($name);
        $typeWords = $this->extractKeywords($type);

        // Stratégie de recherche en cascade AVEC FILTRE VENDOR ET SITES
        // NOTE: On ne recherche plus par variation
        $query = Product::query()
            ->when(!empty($vendor), function ($q) use ($vendor) {
                $q->where('vendor', 'LIKE', "%{$vendor}%");
            })
            ->when(!empty($this->selectedSites), function ($q) {
                $q->whereIn('web_site_id', $this->selectedSites);
            })
            ->orderByDesc('scrap_reference_id') // Trier par scrap_reference_id décroissant
            ->orderByDesc('id'); // Ensuite par ID décroissant

        // 1. Recherche exacte (vendor + name + type) - SANS variation
        if (!empty($name)) {
            $exactMatch = (clone $query)
                ->where('name', 'LIKE', "%{$name}%")
                ->when(!empty($type), function ($q) use ($type) {
                    $q->where('type', 'LIKE', "%{$type}%");
                })
                ->get();

            if ($exactMatch->isNotEmpty()) {
                $filtered = $this->filterByCoffretStatus($exactMatch, $isCoffretSource);
                if (!empty($filtered)) {
                    $this->groupResultsBySiteAndProduct($filtered);
                    $this->validateBestMatchWithAI();
                    return;
                }
            }
        }

        // 2. Recherche vendor + name seulement
        if (!empty($name)) {
            $vendorAndName = (clone $query)
                ->where('name', 'LIKE', "%{$name}%")
                ->get();

            if ($vendorAndName->isNotEmpty()) {
                $filtered = $this->filterByCoffretStatus($vendorAndName, $isCoffretSource);
                if (!empty($filtered)) {
                    $this->groupResultsBySiteAndProduct($filtered);
                    $this->validateBestMatchWithAI();
                    return;
                }
            }
        }

        // 3. Recherche flexible par mots-clés (name seulement)
        if (!empty($nameWords)) {
            $keywordSearch = (clone $query)
                ->where(function ($q) use ($nameWords) {
                    foreach ($nameWords as $word) {
                        $q->orWhere('name', 'LIKE', "%{$word}%");
                    }
                })
                ->when(!empty($typeWords), function ($q) use ($typeWords) {
                    $q->where(function ($subQ) use ($typeWords) {
                        foreach ($typeWords as $word) {
                            $subQ->orWhere('type', 'LIKE', "%{$word}%");
                        }
                    });
                })
                ->limit(100)
                ->get();

            if ($keywordSearch->isNotEmpty()) {
                $filtered = $this->filterByCoffretStatus($keywordSearch, $isCoffretSource);
                if (!empty($filtered)) {
                    $this->groupResultsBySiteAndProduct($filtered);
                    $this->validateBestMatchWithAI();
                    return;
                }
            }
        }

        // 4. Recherche très large : vendor + n'importe quel mot du name
        if (!empty($nameWords)) {
            $broadSearch = (clone $query)
                ->where(function ($q) use ($nameWords) {
                    foreach ($nameWords as $word) {
                        $q->orWhere('name', 'LIKE', "%{$word}%");
                    }
                })
                ->limit(100)
                ->get();

            if ($broadSearch->isNotEmpty()) {
                $filtered = $this->filterByCoffretStatus($broadSearch, $isCoffretSource);
                if (!empty($filtered)) {
                    $this->groupResultsBySiteAndProduct($filtered);
                    $this->validateBestMatchWithAI();
                    return;
                }
            }
        }

        // 5. Dernière tentative : vendor + type uniquement
        if (!empty($typeWords)) {
            $typeOnly = (clone $query)
                ->where(function ($q) use ($typeWords) {
                    foreach ($typeWords as $word) {
                        $q->orWhere('type', 'LIKE', "%{$word}%");
                    }
                })
                ->limit(100)
                ->get();

            $filtered = $this->filterByCoffretStatus($typeOnly, $isCoffretSource);
            if (!empty($filtered)) {
                $this->groupResultsBySiteAndProduct($filtered);
                $this->validateBestMatchWithAI();
            }
        }

        // Si aucun résultat n'a été trouvé, on peut tenter une recherche large par vendor seulement
        if (empty($this->matchingProducts) && !empty($vendor)) {
            $vendorOnly = Product::query()
                ->where('vendor', 'LIKE', "%{$vendor}%")
                ->when(!empty($this->selectedSites), function ($q) {
                    $q->whereIn('web_site_id', $this->selectedSites);
                })
                ->orderByDesc('scrap_reference_id')
                ->orderByDesc('id')
                ->limit(100)
                ->get();

            if ($vendorOnly->isNotEmpty()) {
                $filtered = $this->filterByCoffretStatus($vendorOnly, $isCoffretSource);
                if (!empty($filtered)) {
                    $this->groupResultsBySiteAndProduct($filtered);
                    $this->validateBestMatchWithAI();
                }
            }
        }
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

        // Convertir en collection et s'assurer que chaque produit a les champs nécessaires
        $productsCollection = collect($products)->map(function ($product) {
            return array_merge([
                'scrape_reference' => 'unknown_' . ($product['id'] ?? uniqid()),
                'scrap_reference_id' => 0, // ID numérique de la référence
                'web_site_id' => 0,
                'id' => 0,
                'created_at' => now()->toDateTimeString()
            ], $product);
        });

        // 1. Grouper par site
        $groupedBySite = $productsCollection->groupBy('web_site_id');

        // 2. Pour chaque site, garder le produit avec le scrap_reference_id le plus élevé
        // Si même scrap_reference_id, prendre le produit avec l'ID le plus élevé
        $uniqueProductsBySite = $groupedBySite->map(function ($siteProducts, $siteId) {
            // Trier d'abord par scrap_reference_id décroissant, puis par ID décroissant
            return $siteProducts->sortByDesc('scrap_reference_id')
                ->sortByDesc('id')
                ->first();
        })->filter()->values(); // Filtrer les valeurs null et réindexer

        // Limiter à 50 résultats maximum
        $this->matchingProducts = $uniqueProductsBySite->take(50)->toArray();

        // 3. Stocker les résultats groupés pour l'affichage
        $this->groupedResults = $groupedBySite->map(function ($siteProducts, $siteId) {
            // Pour les statistiques, on garde tous les produits du site
            $totalProducts = $siteProducts->count();
            $maxScrapedReferenceId = $siteProducts->max('scrap_reference_id');

            // Trouver le produit avec le scrap_reference_id le plus élevé
            $latestProduct = $siteProducts->sortByDesc('scrap_reference_id')
                ->sortByDesc('id')
                ->first();

            return [
                'site_id' => $siteId,
                'total_products' => $totalProducts,
                'max_scrap_reference_id' => $maxScrapedReferenceId,
                'latest_product' => $latestProduct,
                'all_products' => $siteProducts->map(function ($product) {
                    return [
                        'id' => $product['id'] ?? 0,
                        'scrap_reference_id' => $product['scrap_reference_id'] ?? 0,
                        'scrape_reference' => $product['scrape_reference'] ?? '',
                        'price' => $product['prix_ht'] ?? 0,
                        'created_at' => $product['created_at'] ?? null
                    ];
                })->sortByDesc('scrap_reference_id')->values()->toArray()
            ];
        })->toArray();
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
                        // Fallback sur le premier résultat (celui avec le scrap_reference_id le plus élevé)
                        $this->bestMatch = $this->matchingProducts[0] ?? null;
                    }
                } else {
                    // Fallback sur le premier résultat (celui avec le scrap_reference_id le plus élevé)
                    $this->bestMatch = $this->matchingProducts[0] ?? null;
                }
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
            <span class="font-semibold">Affichage :</span> Un seul produit par site (celui avec le scrap_reference_id
            le plus élevé)
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
        <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded">
            <p class="text-sm text-blue-800">
                <span class="font-semibold">{{ count($matchingProducts) }}</span> produit(s) unique(s) trouvé(s)
                <span class="text-xs ml-2">(1 par site, scrap_reference_id le plus élevé)</span>
            </p>
            @php
                $totalProductsAllSites = 0;
                if (!empty($groupedResults)) {
                    foreach ($groupedResults as $siteData) {
                        $totalProductsAllSites += $siteData['total_products'] ?? 0;
                    }
                }
            @endphp
            <p class="text-xs text-blue-600 mt-1">
                Produits affichés : {{ count($matchingProducts) }} |
                Produits totaux trouvés : {{ $totalProductsAllSites }} |
                Sites avec résultats : {{ count($groupedResults) }}
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
                        $siteId = $bestMatch['web_site_id'] ?? 0;
                        $isLatestForSite = false;
                        $totalProductsOnSite = 0;

                        if (!empty($groupedResults[$siteId])) {
                            $siteData = $groupedResults[$siteId];
                            $isLatestForSite = ($siteData['latest_product']['id'] ?? 0) === ($bestMatch['id'] ?? 0);
                            $totalProductsOnSite = $siteData['total_products'] ?? 0;
                        }
                    @endphp
                    @if(!empty($siteInfo))
                        <div class="mt-2">
                            <span
                                class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                {{ $siteInfo['name'] ?? '' }}
                                @if($isLatestForSite)
                                    <span class="ml-1">• Dernier scrap ({{ $totalProductsOnSite }} produits trouvés)</span>
                                @endif
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
                Résultats uniques par site ({{ count($matchingProducts) }} produits) :
                <span class="text-sm font-normal text-gray-600">(Cliquez pour sélectionner)</span>
            </h3>
            <div class="space-y-3 max-h-96 overflow-y-auto">
                @foreach($matchingProducts as $product)
                    @php
                        $product = is_array($product) ? $product : [];
                        $siteInfo = collect($availableSites)->firstWhere('id', $product['web_site_id'] ?? 0);
                        $siteId = $product['web_site_id'] ?? 0;
                        $isLatestForSite = false;
                        $totalProductsOnSite = 0;

                        if (!empty($groupedResults[$siteId])) {
                            $siteData = $groupedResults[$siteId];
                            $isLatestForSite = ($siteData['latest_product']['id'] ?? 0) === ($product['id'] ?? 0);
                            $totalProductsOnSite = $siteData['total_products'] ?? 0;
                        }
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
                                    <p class="font-medium text-sm">{{ $product['vendor'] ?? '' }} - {{ $product['name'] ?? '' }}
                                    </p>
                                    <p class="font-bold text-sm">{{ $product['prix_ht'] ?? 0 }} {{ $product['currency'] ?? '' }}
                                    </p>
                                </div>
                                <p class="text-xs text-gray-500">{{ $product['type'] ?? '' }} |
                                    {{ $product['variation'] ?? '' }}</p>

                                <!-- Informations site et référence -->
                                <div class="flex items-center justify-between mt-2">
                                    <div class="flex items-center gap-2">
                                        @if(!empty($siteInfo))
                                            <span
                                                class="text-xs px-2 py-1 rounded {{ $isLatestForSite ? 'bg-green-100 text-green-800 border border-green-200' : 'bg-gray-100 text-gray-800' }}">
                                                {{ $siteInfo['name'] ?? '' }}
                                                @if($isLatestForSite)
                                                    <span class="ml-1 text-xs">
                                                        (Dernier scrap • Ref ID: {{ $product['scrap_reference_id'] ?? 0 }} •
                                                        {{ $totalProductsOnSite }} produits)
                                                    </span>
                                                @endif
                                            </span>
                                        @endif

                                        <span class="text-xs text-gray-500">
                                            ID: {{ $product['id'] ?? 0 }}
                                        </span>
                                    </div>

                                    <div class="text-right">
                                        @if(!empty($product['url']))
                                            <a href="{{ $product['url'] }}" target="_blank"
                                                class="text-xs text-blue-500 hover:text-blue-700 hover:underline"
                                                onclick="event.stopPropagation();">
                                                Voir produit
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

    @if(!empty($extractedData) && empty($matchingProducts))
        <div class="mt-6 p-4 bg-yellow-50 border border-yellow-300 rounded">
            <p class="text-yellow-800">❌ Aucun produit trouvé avec ces critères (même vendor:
                {{ $extractedData['vendor'] ?? 'N/A' }}, même statut coffret)
            </p>
        </div>
    @endif
</div>