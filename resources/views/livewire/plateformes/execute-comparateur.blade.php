<?php

use Livewire\Volt\Component;
use App\Models\Product;
use Illuminate\Support\Facades\Http;

new class extends Component {

    public string $productName = '';
    public $productPrice;
    public $productId;
    public $extractedData = null;
    public $isLoading = false;
    public $matchingProducts = [];
    public $bestMatch = null;
    public $useAIMatching = true;
    public $extractedKeywords = [];

    public function mount($name, $id, $price): void
    {
        $this->productName = $name;
        $this->productId = $id;
        $this->productPrice = $price;
    }

    public function extractSearchTerme()
    {
        $this->isLoading = true;
        $this->extractedData = null;
        $this->matchingProducts = [];
        $this->bestMatch = null;
        $this->extractedKeywords = [];

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            ])->post('https://api.openai.com/v1/chat/completions', [
                        'model' => 'gpt-4o-mini',
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => 'Tu es un expert en extraction de données de produits cosmétiques. Tu dois extraire vendor, name, variation et type du nom de produit fourni. Réponds UNIQUEMENT avec un objet JSON valide, sans markdown ni texte supplémentaire.'
                            ],
                            [
                                'role' => 'user',
                                'content' => "Extrait les informations suivantes du nom de produit et retourne-les au format JSON strict :
- vendor : la marque du produit
- name : le nom de la gamme/ligne de produit
- variation : la contenance/taille (ml, g, etc.)
- type : le type de produit (Crème, Sérum, Concentré, etc.)

Nom du produit : {$this->productName}

Exemple de format attendu :
{
  \"vendor\": \"Shiseido\",
  \"name\": \"Vital Perfection\",
  \"variation\": \"20 ml\",
  \"type\": \"Concentré Correcteur Rides\"
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

                // Si pas de match ou matching faible, utiliser l'IA pour améliorer
                if ($this->useAIMatching && (empty($this->matchingProducts) || count($this->matchingProducts) < 3)) {
                    $this->enhanceMatchingWithAI();
                }

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

    private function searchMatchingProducts()
    {
        if (!$this->extractedData) {
            return;
        }

        $vendor = $this->extractedData['vendor'] ?? '';
        $name = $this->extractedData['name'] ?? '';
        $variation = $this->extractedData['variation'] ?? '';
        $type = $this->extractedData['type'] ?? '';

        // Extraire les mots-clés et les stocker pour l'affichage
        $this->extractedKeywords = [
            'name' => $this->extractKeywords($name),
            'type' => $this->extractKeywords($type)
        ];

        // Recherche avec matching exact et par mots
        $this->matchingProducts = $this->performAdvancedSearch(
            $vendor,
            $this->extractedKeywords['name'],
            $this->extractedKeywords['type'],
            $variation
        );

        // Déterminer le meilleur match
        $this->determineBestMatch();
    }

    private function extractKeywords($text): array
    {
        if (empty($text))
            return [];

        // Supprimer les caractères spéciaux, garder les lettres et chiffres
        $cleaned = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);

        // Convertir en minuscules
        $cleaned = mb_strtolower($cleaned);

        // Diviser en mots
        $words = preg_split('/\s+/', $cleaned, -1, PREG_SPLIT_NO_EMPTY);

        // Filtrer les mots vides (stop words)
        $stopWords = ['de', 'la', 'le', 'et', 'à', 'en', 'pour', 'avec', 'sur', 'par', 'du', 'des'];
        $filteredWords = array_diff($words, $stopWords);

        // Retourner les mots uniques
        return array_values(array_unique($filteredWords));
    }

    private function performAdvancedSearch($vendor, $nameWords, $typeWords, $variation): array
    {
        $results = collect();

        // 1. Recherche exacte avec matching par mots
        $exactMatches = Product::where(function ($query) use ($vendor, $nameWords, $typeWords, $variation) {
            // Matching vendor
            if (!empty($vendor)) {
                $query->where('vendor', 'LIKE', "%{$vendor}%");
            }

            // Matching par mots dans le name
            foreach ($nameWords as $word) {
                $query->where('name', 'LIKE', "%{$word}%");
            }

            // Matching par mots dans le type
            foreach ($typeWords as $word) {
                $query->where('type', 'LIKE', "%{$word}%");
            }

            // Matching variation si disponible
            if (!empty($variation)) {
                $query->where('variation', 'LIKE', "%{$variation}%");
            }
        })->get();

        if ($exactMatches->isNotEmpty()) {
            $results = $results->merge($exactMatches);
        }

        // 2. Recherche partielle avec scoring
        $partialMatches = Product::all()->map(function ($product) use ($vendor, $nameWords, $typeWords) {
            $score = 0;

            // Score vendor
            if (!empty($vendor) && stripos($product->vendor, $vendor) !== false) {
                $score += 30;
            }

            // Score par mots dans name
            foreach ($nameWords as $word) {
                if (stripos($product->name, $word) !== false) {
                    $score += 20;
                }
            }

            // Score par mots dans type
            foreach ($typeWords as $word) {
                if (stripos($product->type, $word) !== false) {
                    $score += 15;
                }
            }

            // Score pour les mots partiels (similarité)
            foreach ($nameWords as $word) {
                similar_text(strtolower($word), strtolower($product->name), $percent);
                if ($percent > 70) {
                    $score += 10;
                }
            }

            return ['product' => $product, 'score' => $score];
        })
            ->filter(fn($item) => $item['score'] > 20)
            ->sortByDesc('score')
            ->take(10)
            ->pluck('product');

        $results = $results->merge($partialMatches)->unique('id');

        // 3. Recherche flexible si pas assez de résultats
        if ($results->count() < 3) {
            $flexibleMatches = Product::where(function ($query) use ($vendor, $nameWords) {
                if (!empty($vendor)) {
                    $query->where('vendor', 'LIKE', "%{$vendor}%");
                }

                foreach ($nameWords as $word) {
                    $query->orWhere('name', 'LIKE', "%{$word}%");
                }
            })->limit(10)->get();

            $results = $results->merge($flexibleMatches)->unique('id');
        }

        return $results->toArray();
    }

    private function enhanceMatchingWithAI()
    {
        try {
            // Préparer les données existantes pour l'analyse IA
            $existingMatches = array_slice($this->matchingProducts, 0, 5);
            $existingData = array_map(function ($product) {
                return [
                    'id' => $product['id'],
                    'vendor' => $product['vendor'],
                    'name' => $product['name'],
                    'type' => $product['type'],
                    'variation' => $product['variation']
                ];
            }, $existingMatches);

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            ])->post('https://api.openai.com/v1/chat/completions', [
                        'model' => 'gpt-4o-mini',
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => 'Tu es un expert en matching de produits cosmétiques. Analyse le produit source et les produits cibles pour trouver le meilleur match.'
                            ],
                            [
                                'role' => 'user',
                                'content' => "Trouve le meilleur match pour le produit source parmi les produits cibles.
                        
PRODUIT SOURCE:
- Nom original: {$this->productName}
- Vendor: {$this->extractedData['vendor']}
- Name: {$this->extractedData['name']}
- Type: {$this->extractedData['type']}
- Variation: {$this->extractedData['variation']}

PRODUITS CIBLES EXISTANTS:
" . json_encode($existingData, JSON_PRETTY_PRINT) . "

Pour chaque produit cible, évalue:
1. Similarité du vendor (0-30 points)
2. Similarité du name/line (0-30 points)  
3. Similarité du type (0-20 points)
4. Similarité de la variation (0-20 points)
5. Score total (0-100)

Si aucun produit cible n'est bon (score < 50), suggère des termes de recherche alternatifs.

Réponds au format JSON:
{
  \"matches\": [
    {
      \"id\": \"id_du_produit\",
      \"score\": 85,
      \"reasoning\": \"Explication du matching\"
    }
  ],
  \"alternative_search_terms\": [
    \"terme1\",
    \"terme2\"
  ]
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

                $aiAnalysis = json_decode($content, true);

                if ($aiAnalysis && isset($aiAnalysis['matches'])) {
                    // Mettre à jour les scores des produits existants
                    foreach ($aiAnalysis['matches'] as $aiMatch) {
                        foreach ($this->matchingProducts as &$product) {
                            if ($product['id'] == $aiMatch['id']) {
                                $product['ai_score'] = $aiMatch['score'];
                                $product['ai_reasoning'] = $aiMatch['reasoning'] ?? '';
                                break;
                            }
                        }
                    }

                    // Trier par score IA
                    usort($this->matchingProducts, function ($a, $b) {
                        $scoreA = $a['ai_score'] ?? 0;
                        $scoreB = $b['ai_score'] ?? 0;
                        return $scoreB <=> $scoreA;
                    });

                    // Mettre à jour le best match
                    if (!empty($this->matchingProducts)) {
                        $this->bestMatch = (object) $this->matchingProducts[0];
                    }

                    // Si des termes alternatifs sont suggérés et pas de bon match
                    if (empty($this->matchingProducts) && isset($aiAnalysis['alternative_search_terms'])) {
                        $this->performAlternativeSearch($aiAnalysis['alternative_search_terms']);
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::warning('Erreur matching IA', ['error' => $e->getMessage()]);
        }
    }

    private function performAlternativeSearch($terms)
    {
        if (empty($terms))
            return;

        $searchQuery = implode(' OR ', array_slice($terms, 0, 3));

        $alternativeResults = Product::where(function ($query) use ($searchQuery) {
            foreach (explode(' OR ', $searchQuery) as $term) {
                $query->orWhere('vendor', 'LIKE', "%{$term}%")
                    ->orWhere('name', 'LIKE', "%{$term}%")
                    ->orWhere('type', 'LIKE', "%{$term}%");
            }
        })->limit(10)->get();

        if ($alternativeResults->isNotEmpty()) {
            $this->matchingProducts = $alternativeResults->toArray();
            $this->bestMatch = $alternativeResults->first();
        }
    }

    private function determineBestMatch()
    {
        if (empty($this->matchingProducts)) {
            $this->bestMatch = null;
            return;
        }

        // Si des scores IA sont disponibles, utiliser ceux-ci
        if (isset($this->matchingProducts[0]['ai_score'])) {
            $this->bestMatch = (object) $this->matchingProducts[0];
            return;
        }

        // Sinon, calculer un score basique
        $scoredProducts = array_map(function ($product) {
            $score = 0;

            // Comparer avec les données extraites
            if (
                !empty($this->extractedData['vendor']) &&
                stripos($product['vendor'], $this->extractedData['vendor']) !== false
            ) {
                $score += 30;
            }

            if (!empty($this->extractedData['name'])) {
                similar_text(
                    strtolower($product['name']),
                    strtolower($this->extractedData['name']),
                    $percent
                );
                $score += $percent * 0.3;
            }

            return ['product' => $product, 'score' => $score];
        }, $this->matchingProducts);

        usort($scoredProducts, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $this->bestMatch = (object) ($scoredProducts[0]['product'] ?? null);
    }

    public function selectProduct($productId)
    {
        $product = Product::find($productId);

        if ($product) {
            session()->flash('success', 'Produit sélectionné : ' . $product->name);
            $this->bestMatch = $product;
            $this->dispatch('product-selected', productId: $productId);
        }
    }

    public function getKeywordsForDisplay()
    {
        if (empty($this->extractedKeywords)) {
            return [
                'name' => [],
                'type' => []
            ];
        }

        return $this->extractedKeywords;
    }

}; ?>
<div class="p-6 bg-white rounded-lg shadow">
    <div class="mb-4">
        <h2 class="text-xl font-bold mb-2">Extraction et recherche de produit</h2>
        <p class="text-gray-600">Produit: {{ $productName }}</p>

        <div class="mt-2 flex items-center gap-2">
            <input type="checkbox" id="useAIMatching" wire:model="useAIMatching" class="rounded">
            <label for="useAIMatching" class="text-sm">Utiliser le matching IA avancé</label>
        </div>
    </div>

    <button wire:click="extractSearchTerme" wire:loading.attr="disabled"
        class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600 disabled:opacity-50 flex items-center gap-2">
        <span wire:loading.remove>🔍 Extraire et rechercher</span>
        <span wire:loading>
            <svg class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none"
                viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor"
                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                </path>
            </svg>
            Extraction en cours...
        </span>
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
                    <span class="font-semibold">Vendor:</span>
                    <span class="bg-blue-100 px-2 py-1 rounded text-sm">{{ $extractedData['vendor'] ?? 'N/A' }}</span>
                </div>
                <div>
                    <span class="font-semibold">Name:</span>
                    <span class="bg-green-100 px-2 py-1 rounded text-sm">{{ $extractedData['name'] ?? 'N/A' }}</span>
                </div>
                <div>
                    <span class="font-semibold">Variation:</span>
                    <span class="bg-yellow-100 px-2 py-1 rounded text-sm">{{ $extractedData['variation'] ?? 'N/A' }}</span>
                </div>
                <div>
                    <span class="font-semibold">Type:</span>
                    <span class="bg-purple-100 px-2 py-1 rounded text-sm">{{ $extractedData['type'] ?? 'N/A' }}</span>
                </div>
            </div>

            @php
                $keywords = $this->getKeywordsForDisplay();
            @endphp

            @if(!empty($keywords['name']) || !empty($keywords['type']))
                <div class="mt-3 pt-3 border-t">
                    <h4 class="font-semibold text-sm mb-2">Mots-clés extraits:</h4>
                    <div class="flex flex-wrap gap-1">
                        @foreach($keywords['name'] as $word)
                            <span class="px-2 py-1 bg-blue-200 text-blue-800 rounded text-xs">{{ $word }}</span>
                        @endforeach
                        @foreach($keywords['type'] as $word)
                            <span class="px-2 py-1 bg-purple-200 text-purple-800 rounded text-xs">{{ $word }}</span>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif

    @if($bestMatch)
        <div class="mt-6 p-4 bg-green-50 border-2 border-green-500 rounded">
            <div class="flex justify-between items-start mb-3">
                <h3 class="font-bold text-green-700">✓ Meilleur résultat :</h3>
                @if(property_exists($bestMatch, 'ai_score') && $bestMatch->ai_score)
                    <span class="px-2 py-1 bg-green-200 text-green-800 rounded text-sm">
                        Score IA: {{ $bestMatch->ai_score }}/100
                    </span>
                @endif
            </div>
            <div class="flex items-start gap-4">
                @if($bestMatch->image_url)
                    <img src="{{ $bestMatch->image_url }}" alt="{{ $bestMatch->name }}" class="w-20 h-20 object-cover rounded">
                @endif
                <div class="flex-1">
                    <p class="font-semibold">{{ $bestMatch->vendor }} - {{ $bestMatch->name }}</p>
                    <p class="text-sm text-gray-600">{{ $bestMatch->type }} | {{ $bestMatch->variation }}</p>
                    <p class="text-sm font-bold text-green-600 mt-1">{{ $bestMatch->prix_ht }} {{ $bestMatch->currency }}
                    </p>
                    <a href="{{ $bestMatch->url }}" target="_blank" class="text-xs text-blue-500 hover:underline">Voir le
                        produit</a>

                    @if(property_exists($bestMatch, 'ai_reasoning') && $bestMatch->ai_reasoning)
                        <div class="mt-2 p-2 bg-green-100 rounded text-xs text-green-800">
                            <span class="font-semibold">Analyse IA:</span> {{ $bestMatch->ai_reasoning }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    @if(!empty($matchingProducts) && count($matchingProducts) > 1)
        <div class="mt-6">
            <h3 class="font-bold mb-3">Résultats trouvés ({{ count($matchingProducts) }}) :</h3>
            <div class="space-y-2 max-h-96 overflow-y-auto">
                @foreach($matchingProducts as $product)
                    <div wire:click="selectProduct({{ $product['id'] }})"
                        class="p-3 border rounded hover:bg-blue-50 cursor-pointer transition {{ $bestMatch && $bestMatch->id === $product['id'] ? 'bg-blue-100 border-blue-500' : 'bg-white' }}">
                        <div class="flex items-center gap-3">
                            @if($product['image_url'])
                                <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}"
                                    class="w-12 h-12 object-cover rounded">
                            @endif
                            <div class="flex-1">
                                <div class="flex items-center gap-2">
                                    <p class="font-medium text-sm">{{ $product['vendor'] }} - {{ $product['name'] }}</p>
                                    @if(isset($product['ai_score']))
                                        <span class="px-1 py-0.5 bg-green-100 text-green-700 text-xs rounded">
                                            {{ $product['ai_score'] }}
                                        </span>
                                    @endif
                                </div>
                                <p class="text-xs text-gray-500">{{ $product['type'] }} | {{ $product['variation'] }}</p>
                            </div>
                            <div class="text-right">
                                <p class="font-bold text-sm">{{ $product['prix_ht'] }} {{ $product['currency'] }}</p>
                                <p class="text-xs text-gray-500">ID: {{ $product['id'] }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if($extractedData && empty($matchingProducts))
        <div class="mt-6 p-4 bg-yellow-50 border border-yellow-300 rounded">
            <p class="text-yellow-800">❌ Aucun produit trouvé avec ces critères</p>
            <p class="text-sm mt-2">Essayez de modifier les termes de recherche ou activez le matching IA avancé.</p>
        </div>
    @endif
</div>