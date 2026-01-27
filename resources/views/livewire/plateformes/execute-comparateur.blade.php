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

    public function mount($name, $id, $price): void
    {
        $this->productName = $name;
        $this->productId = $id;
        $this->productPrice = $price;
    }

    public function extractSearchTerme()
    {
        $this->isLoading = true;
        
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            ])->timeout(30)->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Tu es un expert en extraction de données de produits cosmétiques. Tu dois extraire vendor, name, variation, type et is_coffret du nom de produit fourni. Réponds UNIQUEMENT avec un objet JSON valide, sans markdown ni texte supplémentaire.'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Extrait les informations suivantes du nom de produit et retourne-les au format JSON strict :
- vendor : la marque du produit (ex: Shiseido, Dior, Lancôme)
- name : le nom de la gamme/ligne de produit (ex: Vital Perfection, J'adore)
- variation : la contenance/taille uniquement (ex: 20 ml, 50 g, 100 ml) - SANS le texte 'Coffret' ou 'Set'
- type : le type de produit (Crème, Sérum, Concentré, Huile, etc.)
- is_coffret : true si c'est un coffret/set/kit, false sinon. Mots-clés : Coffret, Set, Kit, Duo, Trio, Routine

Nom du produit : {$this->productName}

IMPORTANT pour is_coffret:
- Si le nom contient 'Coffret', 'Set', 'Kit', 'Duo', 'Trio', 'Routine' ou plusieurs produits → is_coffret = true
- Si c'est un produit unique → is_coffret = false

Exemple de format attendu :
{
  \"vendor\": \"Shiseido\",
  \"name\": \"Vital Perfection\",
  \"variation\": \"20 ml\",
  \"type\": \"Concentré\",
  \"is_coffret\": false
}

Pour un coffret :
{
  \"vendor\": \"Dior\",
  \"name\": \"J'adore\",
  \"variation\": \"50 ml\",
  \"type\": \"Eau de Parfum\",
  \"is_coffret\": true
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
                
                // Normaliser les données extraites
                $this->normalizeExtractedData();
                
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

    private function normalizeExtractedData()
    {
        if (!$this->extractedData) {
            return;
        }

        // Nettoyer et normaliser les champs
        foreach (['vendor', 'name', 'variation', 'type'] as $field) {
            if (isset($this->extractedData[$field])) {
                $this->extractedData[$field] = trim($this->extractedData[$field]);
            }
        }

        // S'assurer que is_coffret est un booléen
        if (!isset($this->extractedData['is_coffret'])) {
            $this->extractedData['is_coffret'] = $this->detectCoffret($this->productName);
        }

        // Double vérification de la détection des coffrets
        if (!$this->extractedData['is_coffret']) {
            $this->extractedData['is_coffret'] = $this->detectCoffret($this->productName);
        }
    }

    private function detectCoffret(string $text): bool
    {
        $coffretKeywords = [
            'coffret', 'set', 'kit', 'duo', 'trio', 'routine', 
            'pack', 'bundle', 'collection', 'ensemble', 'box',
            'cadeau', 'gift', 'discovery', 'découverte'
        ];

        $lowerText = strtolower($text);
        
        foreach ($coffretKeywords as $keyword) {
            if (strpos($lowerText, $keyword) !== false) {
                return true;
            }
        }

        return false;
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
        $isCoffret = $this->extractedData['is_coffret'] ?? false;

        // Stratégie de recherche en cascade avec scoring
        $allResults = collect();

        // 1. Recherche exacte (tous les critères + vérification coffret dans name/type)
        $exactMatch = Product::query()
            ->where('vendor', 'LIKE', "%{$vendor}%")
            ->where('name', 'LIKE', "%{$name}%")
            ->where('variation', 'LIKE', "%{$variation}%")
            ->when($type, fn($q) => $q->where('type', 'LIKE', "%{$type}%"))
            ->where(function($q) use ($isCoffret) {
                if ($isCoffret) {
                    // Si on cherche un coffret, le name OU type doit contenir un mot-clé coffret
                    $q->where(function($query) {
                        $query->where('name', 'LIKE', '%coffret%')
                              ->orWhere('name', 'LIKE', '%set%')
                              ->orWhere('name', 'LIKE', '%kit%')
                              ->orWhere('name', 'LIKE', '%duo%')
                              ->orWhere('name', 'LIKE', '%trio%')
                              ->orWhere('type', 'LIKE', '%coffret%')
                              ->orWhere('type', 'LIKE', '%set%')
                              ->orWhere('type', 'LIKE', '%kit%');
                    });
                } else {
                    // Si on cherche un produit unitaire, exclure les coffrets
                    $q->where('name', 'NOT LIKE', '%coffret%')
                      ->where('name', 'NOT LIKE', '%set%')
                      ->where('name', 'NOT LIKE', '%kit%')
                      ->where('name', 'NOT LIKE', '%duo%')
                      ->where('name', 'NOT LIKE', '%trio%')
                      ->where('type', 'NOT LIKE', '%coffret%')
                      ->where('type', 'NOT LIKE', '%set%')
                      ->where('type', 'NOT LIKE', '%kit%');
                }
            })
            ->get();

        if ($exactMatch->isNotEmpty()) {
            $allResults = $allResults->merge($exactMatch->map(fn($p) => [
                'product' => $p,
                'score' => 100
            ]));
        }

        // 2. Recherche sans variation mais avec vérification coffret
        if ($allResults->isEmpty()) {
            $withoutVariation = Product::query()
                ->where('vendor', 'LIKE', "%{$vendor}%")
                ->where('name', 'LIKE', "%{$name}%")
                ->when($type, fn($q) => $q->where('type', 'LIKE', "%{$type}%"))
                ->where(function($q) use ($isCoffret) {
                    if ($isCoffret) {
                        $q->where(function($query) {
                            $query->where('name', 'LIKE', '%coffret%')
                                  ->orWhere('name', 'LIKE', '%set%')
                                  ->orWhere('name', 'LIKE', '%kit%')
                                  ->orWhere('name', 'LIKE', '%duo%')
                                  ->orWhere('name', 'LIKE', '%trio%')
                                  ->orWhere('type', 'LIKE', '%coffret%')
                                  ->orWhere('type', 'LIKE', '%set%')
                                  ->orWhere('type', 'LIKE', '%kit%');
                        });
                    } else {
                        $q->where('name', 'NOT LIKE', '%coffret%')
                          ->where('name', 'NOT LIKE', '%set%')
                          ->where('name', 'NOT LIKE', '%kit%')
                          ->where('name', 'NOT LIKE', '%duo%')
                          ->where('name', 'NOT LIKE', '%trio%')
                          ->where('type', 'NOT LIKE', '%coffret%')
                          ->where('type', 'NOT LIKE', '%set%')
                          ->where('type', 'NOT LIKE', '%kit%');
                    }
                })
                ->get();

            $allResults = $allResults->merge($withoutVariation->map(fn($p) => [
                'product' => $p,
                'score' => 85
            ]));
        }

        // 3. Recherche vendor + name avec scoring (sans filtre strict coffret)
        if ($allResults->isEmpty() || $allResults->count() < 5) {
            $vendorAndName = Product::query()
                ->where('vendor', 'LIKE', "%{$vendor}%")
                ->where('name', 'LIKE', "%{$name}%")
                ->limit(20)
                ->get();

            $scoredResults = $vendorAndName->map(function($product) use ($type, $variation, $isCoffret) {
                $score = 70;

                // Bonus pour type similaire
                if ($type && stripos($product->type, $type) !== false) {
                    $score += 10;
                }

                // Bonus pour variation similaire
                if ($variation && stripos($product->variation, $variation) !== false) {
                    $score += 10;
                }

                // Vérifier la cohérence coffret
                $productIsCoffret = $this->isProductCoffret($product);
                if ($isCoffret === $productIsCoffret) {
                    $score += 15;
                } else {
                    $score -= 20; // Pénalité si incohérence coffret
                }

                return [
                    'product' => $product,
                    'score' => $score
                ];
            });

            $allResults = $allResults->merge($scoredResults);
        }

        // 4. Recherche flexible (vendor OU name) avec scoring
        if ($allResults->isEmpty()) {
            $flexible = Product::where(function($q) use ($vendor, $name) {
                $q->where('vendor', 'LIKE', "%{$vendor}%")
                  ->orWhere('name', 'LIKE', "%{$name}%");
            })
            ->limit(20)
            ->get();

            $scoredResults = $flexible->map(function($product) use ($vendor, $name, $type, $isCoffret) {
                $score = 40;

                if (stripos($product->vendor, $vendor) !== false) {
                    $score += 20;
                }
                if (stripos($product->name, $name) !== false) {
                    $score += 20;
                }
                if ($type && stripos($product->type, $type) !== false) {
                    $score += 10;
                }

                $productIsCoffret = $this->isProductCoffret($product);
                if ($isCoffret === $productIsCoffret) {
                    $score += 10;
                } else {
                    $score -= 15;
                }

                return [
                    'product' => $product,
                    'score' => $score
                ];
            });

            $allResults = $allResults->merge($scoredResults);
        }

        // Trier par score et éliminer les doublons
        $sortedResults = $allResults
            ->unique(fn($item) => $item['product']->id)
            ->sortByDesc('score')
            ->values();

        // Filtrer les résultats avec un score minimum de 50
        $filteredResults = $sortedResults->filter(fn($item) => $item['score'] >= 50);

        if ($filteredResults->isNotEmpty()) {
            $this->matchingProducts = $filteredResults->pluck('product')->toArray();
            $this->bestMatch = $filteredResults->first()['product'];
        } else {
            $this->matchingProducts = [];
            $this->bestMatch = null;
        }
    }

    /**
     * Vérifie si un produit est un coffret en analysant son name et type
     */
    private function isProductCoffret($product): bool
    {
        $coffretKeywords = ['coffret', 'set', 'kit', 'duo', 'trio', 'pack', 'bundle'];
        
        // Combiner name et type pour la recherche
        $searchText = strtolower(($product->name ?? '') . ' ' . ($product->type ?? ''));

        foreach ($coffretKeywords as $keyword) {
            if (strpos($searchText, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    public function selectProduct($productId)
    {
        $product = Product::find($productId);
        
        if ($product) {
            session()->flash('success', 'Produit sélectionné : ' . $product->name);
            $this->bestMatch = $product;
            
            // Émettre un événement si besoin
            $this->dispatch('product-selected', productId: $productId);
        }
    }

}; ?>

<div class="p-6 bg-white rounded-lg shadow">
    <div class="mb-4">
        <h2 class="text-xl font-bold mb-2">Extraction et recherche de produit</h2>
        <p class="text-gray-600">Produit: {{ $productName }}</p>
        @if($productPrice)
            <p class="text-sm text-gray-500">Prix: {{ $productPrice }}</p>
        @endif
    </div>

    <button 
        wire:click="extractSearchTerme"
        wire:loading.attr="disabled"
        class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600 disabled:opacity-50 transition"
    >
        <span wire:loading.remove wire:target="extractSearchTerme">Extraire et rechercher</span>
        <span wire:loading wire:target="extractSearchTerme">
            <svg class="inline animate-spin h-4 w-4 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            Extraction en cours...
        </span>
    </button>

    @if(session('error'))
        <div class="mt-4 p-4 bg-red-100 text-red-700 rounded border border-red-300">
            <strong>Erreur:</strong> {{ session('error') }}
        </div>
    @endif

    @if(session('success'))
        <div class="mt-4 p-4 bg-green-100 text-green-700 rounded border border-green-300">
            {{ session('success') }}
        </div>
    @endif

    @if($extractedData)
        <div class="mt-6 p-4 bg-gray-50 rounded border border-gray-200">
            <h3 class="font-bold mb-3 flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                </svg>
                Critères extraits :
            </h3>
            <div class="grid grid-cols-2 gap-4">
                <div class="p-2 bg-white rounded">
                    <span class="font-semibold text-gray-700">Vendor:</span> 
                    <span class="text-gray-900">{{ $extractedData['vendor'] ?? 'N/A' }}</span>
                </div>
                <div class="p-2 bg-white rounded">
                    <span class="font-semibold text-gray-700">Name:</span> 
                    <span class="text-gray-900">{{ $extractedData['name'] ?? 'N/A' }}</span>
                </div>
                <div class="p-2 bg-white rounded">
                    <span class="font-semibold text-gray-700">Variation:</span> 
                    <span class="text-gray-900">{{ $extractedData['variation'] ?? 'N/A' }}</span>
                </div>
                <div class="p-2 bg-white rounded">
                    <span class="font-semibold text-gray-700">Type:</span> 
                    <span class="text-gray-900">{{ $extractedData['type'] ?? 'N/A' }}</span>
                </div>
                <div class="p-2 bg-white rounded col-span-2">
                    <span class="font-semibold text-gray-700">Coffret:</span> 
                    @if($extractedData['is_coffret'] ?? false)
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                            <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M5 3a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2V5a2 2 0 00-2-2H5zM5 11a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2v-2a2 2 0 00-2-2H5zM11 5a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V5zM11 13a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path>
                            </svg>
                            Oui, c'est un coffret
                        </span>
                    @else
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                            <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM7 9a1 1 0 100-2 1 1 0 000 2zm7-1a1 1 0 11-2 0 1 1 0 012 0zm-.464 5.535a1 1 0 10-1.415-1.414 3 3 0 01-4.242 0 1 1 0 00-1.415 1.414 5 5 0 007.072 0z" clip-rule="evenodd"></path>
                            </svg>
                            Non, produit unitaire
                        </span>
                    @endif
                    <span class="text-xs text-gray-500 ml-2">(détecté via name/type)</span>
                </div>
            </div>
        </div>
    @endif

    @if($bestMatch)
        <div class="mt-6 p-4 bg-green-50 border-2 border-green-500 rounded-lg">
            <h3 class="font-bold text-green-700 mb-3 flex items-center gap-2">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                </svg>
                Meilleur résultat trouvé
                @php
                    $isBestMatchCoffret = stripos($bestMatch['name'], 'coffret') !== false || 
                                          stripos($bestMatch['name'], 'set') !== false || 
                                          stripos($bestMatch['name'], 'kit') !== false ||
                                          stripos($bestMatch['type'], 'coffret') !== false ||
                                          stripos($bestMatch['type'], 'set') !== false ||
                                          stripos($bestMatch['type'], 'kit') !== false;
                @endphp
                @if($isBestMatchCoffret)
                    <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800">
                        Coffret
                    </span>
                @endif
            </h3>
            <div class="flex items-start gap-4">
                @if($bestMatch['image_url'])
                    <img src="{{ $bestMatch['image_url'] }}" alt="{{ $bestMatch['name'] }}" class="w-24 h-24 object-cover rounded-lg shadow">
                @else
                    <div class="w-24 h-24 bg-gray-200 rounded-lg flex items-center justify-center">
                        <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                @endif
                <div class="flex-1">
                    <p class="font-bold text-lg">{{ $bestMatch['vendor'] }} - {{ $bestMatch['name'] }}</p>
                    <p class="text-sm text-gray-600 mt-1">
                        <span class="font-medium">{{ $bestMatch['type'] }}</span> | 
                        <span>{{ $bestMatch['variation'] }}</span>
                    </p>
                    <p class="text-lg font-bold text-green-600 mt-2">{{ number_format((float)$bestMatch['prix_ht'], 2) }} {{ $bestMatch['currency'] }}</p>
                    @if($bestMatch['url'])
                        <a href="{{ $bestMatch['url'] }}" target="_blank" class="inline-flex items-center gap-1 text-sm text-blue-600 hover:text-blue-800 hover:underline mt-2">
                            Voir le produit
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                            </svg>
                        </a>
                    @endif
                    <p class="text-xs text-gray-400 mt-1">ID: {{ $bestMatch['id'] }}</p>
                </div>
            </div>
        </div>
    @endif

    @if(!empty($matchingProducts) && count($matchingProducts) > 1)
        <div class="mt-6">
            <h3 class="font-bold mb-3 text-gray-700">
                Autres résultats trouvés ({{ count($matchingProducts) }}) :
            </h3>
            <div class="space-y-2 max-h-96 overflow-y-auto border border-gray-200 rounded-lg p-2">
                @foreach($matchingProducts as $product)
                    @if($bestMatch && $bestMatch['id'] !== $product['id'])
                        @php
                            $isProductCoffret = stripos($product['name'], 'coffret') !== false || 
                                              stripos($product['name'], 'set') !== false || 
                                              stripos($product['name'], 'kit') !== false ||
                                              stripos($product['type'], 'coffret') !== false ||
                                              stripos($product['type'], 'set') !== false ||
                                              stripos($product['type'], 'kit') !== false;
                        @endphp
                        <div 
                            wire:click="selectProduct({{ $product['id'] }})"
                            class="p-3 border rounded-lg hover:bg-blue-50 hover:border-blue-300 cursor-pointer transition bg-white"
                        >
                            <div class="flex items-center gap-3">
                                @if($product['image_url'])
                                    <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}" class="w-16 h-16 object-cover rounded shadow-sm">
                                @else
                                    <div class="w-16 h-16 bg-gray-100 rounded flex items-center justify-center">
                                        <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                        </svg>
                                    </div>
                                @endif
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <p class="font-medium text-sm truncate">{{ $product['vendor'] }} - {{ $product['name'] }}</p>
                                        @if($isProductCoffret)
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800 flex-shrink-0">
                                                Coffret
                                            </span>
                                        @endif
                                    </div>
                                    <p class="text-xs text-gray-500 truncate">{{ $product['type'] }} | {{ $product['variation'] }}</p>
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <p class="font-bold text-sm whitespace-nowrap">{{ number_format((float)$product['prix_ht'], 2) }} {{ $product['currency'] }}</p>
                                    <p class="text-xs text-gray-400">ID: {{ $product['id'] }}</p>
                                </div>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    @endif

    @if($extractedData && empty($matchingProducts))
        <div class="mt-6 p-4 bg-yellow-50 border-2 border-yellow-300 rounded-lg">
            <div class="flex items-start gap-3">
                <svg class="w-6 h-6 text-yellow-600 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                </svg>
                <div>
                    <p class="font-semibold text-yellow-800">Aucun produit trouvé</p>
                    <p class="text-sm text-yellow-700 mt-1">Aucun produit ne correspond aux critères extraits. Vérifiez les données ou essayez une recherche manuelle.</p>
                </div>
            </div>
        </div>
    @endif
</div>
