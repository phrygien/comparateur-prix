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

                $this->extractedData = json_decode($content, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('Erreur de parsing JSON: ' . json_last_error_msg());
                }

                // Rechercher les produits correspondants
                $this->searchMatchingProducts();

            } else {
                throw new \Exception('Erreur API OpenAI: ' . $response->body());
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
        if (!$this->extractedData) {
            return;
        }

        $vendor = $this->extractedData['vendor'] ?? '';
        $name = $this->extractedData['name'] ?? '';
        $variation = $this->extractedData['variation'] ?? '';
        $type = $this->extractedData['type'] ?? '';
        $isCoffretSource = $this->extractedData['is_coffret'] ?? false;

        // Construire la requête de recherche en mode boolean
        $searchTerms = [];
        
        // Ajouter le vendor (obligatoire avec +)
        if (!empty($vendor)) {
            $vendorWords = $this->extractKeywords($vendor);
            foreach ($vendorWords as $word) {
                $searchTerms[] = "+{$word}";
            }
        }

        // Ajouter le name
        if (!empty($name)) {
            $nameWords = $this->extractKeywords($name);
            foreach ($nameWords as $word) {
                $searchTerms[] = "{$word}";
            }
        }

        // Ajouter le type
        if (!empty($type)) {
            $typeWords = $this->extractKeywords($type);
            foreach ($typeWords as $word) {
                $searchTerms[] = "{$word}";
            }
        }

        $searchQuery = implode(' ', $searchTerms);

        // Construction de la requête SQL avec FULLTEXT SEARCH
        $sql = "SELECT 
                lp.*, 
                ws.name as site_name, 
                lp.url as product_url, 
                lp.image_url as image
            FROM last_price_scraped_product lp
            LEFT JOIN web_site ws ON lp.web_site_id = ws.id
            WHERE MATCH (lp.name, lp.vendor, lp.type, lp.variation) 
                AGAINST (? IN BOOLEAN MODE)
            AND (lp.variation != 'Standard' OR lp.variation IS NULL OR lp.variation = '')";

        $params = [$searchQuery];

        // Ajouter le filtre par sites si des sites sont sélectionnés
        if (!empty($this->selectedSites)) {
            $placeholders = implode(',', array_fill(0, count($this->selectedSites), '?'));
            $sql .= " AND lp.web_site_id IN ({$placeholders})";
            $params = array_merge($params, $this->selectedSites);
        }

        // Limiter les résultats
        $sql .= " LIMIT 100";

        try {
            $results = \DB::select($sql, $params);
            
            if (!empty($results)) {
                // Convertir les résultats en array
                $products = array_map(function($item) {
                    return (array) $item;
                }, $results);

                // Filtrer par statut coffret
                $filtered = $this->filterByCoffretStatusFromArray($products, $isCoffretSource);
                
                if (!empty($filtered)) {
                    $this->groupResultsByScrapeReference($filtered);
                    $this->validateBestMatchWithAI();
                    return;
                }
            }

            // Si aucun résultat avec FULLTEXT, essayer une recherche par vendor exact
            $this->fallbackSearchByVendor($vendor, $name, $type, $isCoffretSource);

        } catch (\Exception $e) {
            \Log::error('Erreur recherche FULLTEXT', [
                'message' => $e->getMessage(),
                'search_query' => $searchQuery
            ]);

            // Fallback sur recherche classique
            $this->fallbackSearchByVendor($vendor, $name, $type, $isCoffretSource);
        }
    }

    /**
     * Recherche de secours avec LIKE si FULLTEXT échoue
     */
    private function fallbackSearchByVendor($vendor, $name, $type, $isCoffretSource)
    {
        $query = Product::query()
            ->select('last_price_scraped_product.*', 'web_site.name as site_name', 
                     'last_price_scraped_product.url as product_url',
                     'last_price_scraped_product.image_url as image')
            ->from('last_price_scraped_product')
            ->leftJoin('web_site', 'last_price_scraped_product.web_site_id', '=', 'web_site.id')
            ->where('last_price_scraped_product.vendor', 'LIKE', "%{$vendor}%")
            ->where(function($q) {
                $q->where('last_price_scraped_product.variation', '!=', 'Standard')
                  ->orWhereNull('last_price_scraped_product.variation')
                  ->orWhere('last_price_scraped_product.variation', '=', '');
            })
            ->when(!empty($this->selectedSites), function ($q) {
                $q->whereIn('last_price_scraped_product.web_site_id', $this->selectedSites);
            });

        // Recherche avec name
        if (!empty($name)) {
            $results = (clone $query)
                ->where('last_price_scraped_product.name', 'LIKE', "%{$name}%")
                ->limit(100)
                ->get()
                ->toArray();

            if (!empty($results)) {
                $filtered = $this->filterByCoffretStatusFromArray($results, $isCoffretSource);
                if (!empty($filtered)) {
                    $this->groupResultsByScrapeReference($filtered);
                    $this->validateBestMatchWithAI();
                    return;
                }
            }
        }

        // Recherche large sur vendor uniquement
        $results = $query->limit(100)->get()->toArray();
        
        if (!empty($results)) {
            $filtered = $this->filterByCoffretStatusFromArray($results, $isCoffretSource);
            if (!empty($filtered)) {
                $this->groupResultsByScrapeReference($filtered);
                $this->validateBestMatchWithAI();
            }
        }
    }

    /**
     * Groupe les résultats par scrape_reference en ne gardant qu'un produit par référence
     * Priorité : le produit avec le prix le plus bas
     */
    private function groupResultsByScrapeReference(array $products)
    {
        $grouped = collect($products)->groupBy('scrape_reference');
        
        // Pour chaque référence, garder le produit avec le prix le plus bas
        $uniqueProducts = $grouped->map(function ($group) {
            return $group->sortBy('prix_ht')->first();
        })->values();

        // Limiter à 50 résultats maximum
        $this->matchingProducts = $uniqueProducts->take(50)->toArray();
        
        // Stocker les résultats groupés pour l'affichage
        $this->groupedResults = $grouped->map(function ($group, $reference) {
            return [
                'reference' => $reference,
                'count' => $group->count(),
                'products' => $group->toArray(),
                'best_price' => $group->min('prix_ht'),
                'sites' => $group->pluck('web_site_id')->unique()->values()->toArray()
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
     * Filtre les produits selon leur statut coffret (pour Array simple)
     */
    private function filterByCoffretStatusFromArray(array $products, bool $sourceisCoffret): array
    {
        return array_values(array_filter($products, function ($product) use ($sourceisCoffret) {
            $productIsCoffret = $this->isCoffret($product);
            
            // Si la source est un coffret, garder seulement les coffrets
            // Si la source n'est pas un coffret, exclure les coffrets
            return $sourceisCoffret ? $productIsCoffret : !$productIsCoffret;
        }));
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
                'id' => $product['id'],
                'vendor' => $product['vendor'],
                'name' => $product['name'],
                'type' => $product['type'],
                'variation' => $product['variation'],
                'prix_ht' => $product['prix_ht']
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
- Vendor: {$this->extractedData['vendor']}
- Name: {$this->extractedData['name']}
- Type: {$this->extractedData['type']}
- Variation: {$this->extractedData['variation']}

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
                        // Fallback sur le premier résultat
                        $this->bestMatch = $this->matchingProducts[0];
                    }
                } else {
                    // Fallback sur le premier résultat
                    $this->bestMatch = $this->matchingProducts[0];
                }
            }

        } catch (\Exception $e) {
            \Log::error('Erreur validation IA', [
                'message' => $e->getMessage(),
                'product_name' => $this->productName
            ]);
            
            // Fallback sur le premier résultat en cas d'erreur
            $this->bestMatch = $this->matchingProducts[0];
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
        if ($this->extractedData) {
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
        
        if ($this->extractedData) {
            $this->searchMatchingProducts();
        }
    }

}; ?>

<div class="p-6 bg-white rounded-lg shadow">
    <div class="mb-4">
        <h2 class="text-xl font-bold mb-2">Extraction et recherche de produit</h2>
        <p class="text-gray-600">Produit: {{ $productName }}</p>
    </div>

    <!-- Filtres par site -->
    @if(!empty($availableSites))
        <div class="mb-6 p-4 bg-gray-50 rounded-lg border border-gray-200">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-semibold text-gray-700">Filtrer par site</h3>
                <button 
                    wire:click="toggleAllSites"
                    class="text-sm text-blue-600 hover:text-blue-800 font-medium"
                >
                    {{ count($selectedSites) === count($availableSites) ? 'Tout désélectionner' : 'Tout sélectionner' }}
                </button>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2">
                @foreach($availableSites as $site)
                    <label class="flex items-center space-x-2 cursor-pointer hover:bg-gray-100 p-2 rounded">
                        <input 
                            type="checkbox" 
                            wire:model.live="selectedSites"
                            value="{{ $site['id'] }}"
                            class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                        >
                        <span class="text-sm">{{ $site['name'] }}</span>
                    </label>
                @endforeach
            </div>
            <p class="text-xs text-gray-500 mt-2">
                {{ count($selectedSites) }} site(s) sélectionné(s)
            </p>
        </div>
    @endif

    <button 
        wire:click="extractSearchTerme"
        wire:loading.attr="disabled"
        class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600 disabled:opacity-50"
    >
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

    @if($extractedData)
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
                    <span class="px-2 py-1 rounded text-sm {{ ($extractedData['is_coffret'] ?? false) ? 'bg-purple-100 text-purple-800' : 'bg-gray-100 text-gray-800' }}">
                        {{ ($extractedData['is_coffret'] ?? false) ? 'Oui' : 'Non' }}
                    </span>
                </div>
            </div>
        </div>
    @endif

    @if(!empty($groupedResults))
        <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded">
            <p class="text-sm text-blue-800">
                <span class="font-semibold">{{ count($groupedResults) }}</span> référence(s) unique(s) trouvée(s)
                <span class="text-xs ml-2">(max 1 produit par référence affichée)</span>
            </p>
        </div>
    @endif

    @if($aiValidation)
        <div class="mt-4 p-4 bg-blue-50 border border-blue-300 rounded">
            <h3 class="font-bold text-blue-700 mb-2">🤖 Validation IA :</h3>
            <p class="text-sm mb-1">
                <span class="font-semibold">Score de confiance:</span> 
                <span class="text-lg font-bold {{ $aiValidation['confidence_score'] >= 0.8 ? 'text-green-600' : ($aiValidation['confidence_score'] >= 0.6 ? 'text-yellow-600' : 'text-red-600') }}">
                    {{ number_format($aiValidation['confidence_score'] * 100, 0) }}%
                </span>
            </p>
            <p class="text-sm text-gray-700">
                <span class="font-semibold">Analyse:</span> {{ $aiValidation['reasoning'] ?? 'N/A' }}
            </p>
        </div>
    @endif

    @if($bestMatch)
        <div class="mt-6 p-4 bg-green-50 border-2 border-green-500 rounded">
            <h3 class="font-bold text-green-700 mb-3">✓ Meilleur résultat :</h3>
            <div class="flex items-start gap-4">
                @if($bestMatch['image_url'] ?? false)
                    <img src="{{ $bestMatch['image_url'] }}" alt="{{ $bestMatch['name'] }}" class="w-20 h-20 object-cover rounded">
                @endif
                <div class="flex-1">
                    <p class="font-semibold">{{ $bestMatch['vendor'] }} - {{ $bestMatch['name'] }}</p>
                    <p class="text-sm text-gray-600">{{ $bestMatch['type'] }} | {{ $bestMatch['variation'] }}</p>
                    <p class="text-xs text-gray-500 mt-1">Ref: {{ $bestMatch['scrape_reference'] ?? 'N/A' }}</p>
                    <p class="text-sm font-bold text-green-600 mt-1">{{ $bestMatch['prix_ht'] }} {{ $bestMatch['currency'] }}</p>
                    @if($bestMatch['url'] ?? false)
                        <a href="{{ $bestMatch['url'] }}" target="_blank" class="text-xs text-blue-500 hover:underline">Voir le produit</a>
                    @endif
                </div>
            </div>
        </div>
    @endif

    @if(!empty($matchingProducts) && count($matchingProducts) > 1)
        <div class="mt-6">
            <h3 class="font-bold mb-3">Autres résultats trouvés ({{ count($matchingProducts) }}) :</h3>
            <div class="space-y-2 max-h-96 overflow-y-auto">
                @foreach($matchingProducts as $product)
                    <div 
                        wire:click="selectProduct({{ $product['id'] }})"
                        class="p-3 border rounded hover:bg-blue-50 cursor-pointer transition {{ $bestMatch && $bestMatch['id'] === $product['id'] ? 'bg-blue-100 border-blue-500' : 'bg-white' }}"
                    >
                        <div class="flex items-center gap-3">
                            @if($product['image_url'] ?? false)
                                <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}" class="w-12 h-12 object-cover rounded">
                            @endif
                            <div class="flex-1">
                                <p class="font-medium text-sm">{{ $product['vendor'] }} - {{ $product['name'] }}</p>
                                <p class="text-xs text-gray-500">{{ $product['type'] }} | {{ $product['variation'] }}</p>
                                <p class="text-xs text-gray-400 mt-1">Ref: {{ $product['scrape_reference'] ?? 'N/A' }}</p>
                            </div>
                            <div class="text-right">
                                <p class="font-bold text-sm">{{ $product['prix_ht'] }} {{ $product['currency'] }}</p>
                                <p class="text-xs text-gray-500">ID: {{ $product['id'] }}</p>
                                @php
                                    $siteInfo = collect($availableSites)->firstWhere('id', $product['web_site_id']);
                                @endphp
                                @if($siteInfo)
                                    <p class="text-xs text-blue-600 font-medium">{{ $siteInfo['name'] }}</p>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if($extractedData && empty($matchingProducts))
        <div class="mt-6 p-4 bg-yellow-50 border border-yellow-300 rounded">
            <p class="text-yellow-800">❌ Aucun produit trouvé avec ces critères (même vendor: {{ $extractedData['vendor'] }}, même statut coffret)</p>
        </div>
    @endif
</div>
