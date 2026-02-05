<?php

use Livewire\Volt\Component;
use App\Models\Product;
use App\Services\ProductSearchParser;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

new class extends Component {
    public string $name = '';
    public string $id = '';
    public string $price = '';
    public Collection $products;
    
    public array $parsedResult = [];
    public bool $loading = false;
    public ?string $error = null;
    public Collection $searchResults;

    public function mount($name = '', $id = '', $price = ''): void
    {
        $this->name = $name;
        $this->id = $id;
        $this->price = $price;
        $this->products = collect();
        $this->searchResults = collect();
    }
    
    /**
     * Normalise un texte pour la recherche
     */
    private function normalizeForSearch(string $text): string
    {
        $normalized = Str::lower($text);
        $normalized = str_replace(['-', '_', '/', '\\'], ' ', $normalized);
        $normalized = preg_replace('/[^a-z0-9\s]/', '', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        return trim($normalized);
    }
    
    /**
     * Vérifie le matching mot par mot et retourne le score
     */
    private function matchWordByWord(string $searchText, string $productText, int $minMatchRatio = 60): array
    {
        $normalizedProductText = $this->normalizeForSearch($productText);
        
        // Découpe le texte de recherche en mots
        $searchWords = array_filter(explode(' ', $this->normalizeForSearch($searchText)));
        
        if (empty($searchWords)) {
            return [
                'matched' => false,
                'score' => 0,
                'words' => [],
                'ratio' => 0,
                'in_order' => false
            ];
        }
        
        $wordScores = [];
        $totalWordScore = 0;
        
        foreach ($searchWords as $index => $word) {
            if (empty($word)) continue;
            
            // Position dans le texte du produit
            $position = mb_strpos($normalizedProductText, $word);
            
            if ($position !== false) {
                // Le mot est trouvé
                // Score plus élevé si le mot est au début
                $positionScore = max(0, 100 - ($position * 2));
                
                // Score plus élevé pour les premiers mots de la recherche
                $orderScore = max(0, 50 - ($index * 10));
                
                $wordScore = $positionScore + $orderScore;
                $totalWordScore += $wordScore;
                
                $wordScores[] = [
                    'word' => $word,
                    'found' => true,
                    'position' => $position,
                    'score' => $wordScore
                ];
            } else {
                $wordScores[] = [
                    'word' => $word,
                    'found' => false,
                    'position' => null,
                    'score' => 0
                ];
            }
        }
        
        // Calcul du pourcentage de mots trouvés
        $wordsFound = count(array_filter($wordScores, fn($w) => $w['found']));
        $matchRatio = count($searchWords) > 0 ? ($wordsFound / count($searchWords)) * 100 : 0;
        
        // Vérification du ratio minimum
        if ($matchRatio < $minMatchRatio) {
            return [
                'matched' => false,
                'score' => 0,
                'words' => $wordScores,
                'ratio' => $matchRatio,
                'in_order' => false
            ];
        }
        
        // Bonus si tous les mots sont trouvés
        if ($matchRatio === 100) {
            $totalWordScore += 100;
        }
        
        // Bonus si les mots sont dans le bon ordre
        $inOrder = true;
        $lastPosition = -1;
        foreach ($wordScores as $ws) {
            if ($ws['found']) {
                if ($ws['position'] < $lastPosition) {
                    $inOrder = false;
                    break;
                }
                $lastPosition = $ws['position'];
            }
        }
        
        if ($inOrder && $wordsFound > 1) {
            $totalWordScore += 50;
        }
        
        return [
            'matched' => true,
            'score' => $totalWordScore,
            'words' => $wordScores,
            'ratio' => $matchRatio,
            'in_order' => $inOrder
        ];
    }
    
    public function parseProduct(): void
    {
        $this->loading = true;
        $this->error = null;
        $this->parsedResult = [];
        $this->searchResults = collect();
        
        try {
            if (empty($this->name)) {
                $this->error = 'Veuillez entrer un nom de produit';
                return;
            }
            
            $parser = new ProductSearchParser();
            $this->parsedResult = $parser->parseProductName($this->name);
            
            // Recherche des produits après le parsing
            $this->searchProductsFromParsed();
            
        } catch (\Exception $e) {
            $this->error = 'Erreur: ' . $e->getMessage();
        } finally {
            $this->loading = false;
        }
    }
    
/**
 * Recherche des produits en utilisant Typesense avec filtrage par étapes
 * ÉTAPE 1: Filtre par vendor
 * ÉTAPE 2: Recherche par name
 * ÉTAPE 3: Filtre par type
 */
private function searchProductsFromParsed(): void
{
    if (empty($this->parsedResult)) {
        return;
    }
    
    $vendor = $this->parsedResult['vendor'] ?? null;
    $name = $this->parsedResult['name'] ?? null;
    $type = $this->parsedResult['type'] ?? null;
    
    if (!$vendor || !$name) {
        $this->error = 'Vendor et Name sont requis pour la recherche';
        return;
    }
    
    // Construction de la query de recherche
    $searchQuery = $name;
    
    // Options de recherche Typesense
    $searchOptions = [
        'query_by' => 'name,type,variation',
        'query_by_weights' => '4,2,1',
        'filter_by' => 'vendor:=' . $vendor, // ÉTAPE 1: Filtre strict sur vendor
        'prioritize_exact_match' => true,
        'prefix' => true,
        'num_typos' => 1,
        'typo_tokens_threshold' => 1,
        'per_page' => 30,
        'sort_by' => '_text_match:desc',
        // 'infix' => 'always', // RETIRER CETTE LIGNE - infix est configuré dans le schéma
        'split_join_tokens' => 'always',
    ];
    
    // ÉTAPE 3: Si le type est présent, l'ajouter au filtre
    if ($type) {
        $searchOptions['filter_by'] .= ' && type:*' . $type . '*';
    }
    
    try {
        // Exécution de la recherche Typesense
        $results = Product::search($searchQuery)
            ->options($searchOptions)
            ->get();
        
        // Post-traitement pour affiner et scorer les résultats
        $this->searchResults = collect($results)
            ->map(function($product) use ($vendor, $name, $type) {
                return $this->calculateProductScore($product, $vendor, $name, $type);
            })
            ->filter(function($product) {
                // Filtrer les produits avec un score négatif
                return $product->match_score > 0;
            })
            ->sortByDesc('match_score')
            ->take(10)
            ->values();
        
        // Si aucun résultat avec filtre strict, essayer une recherche plus souple
        if ($this->searchResults->isEmpty()) {
            $this->fallbackSearch($vendor, $name, $type);
        }
        
    } catch (\Exception $e) {
        $this->error = 'Erreur de recherche Typesense: ' . $e->getMessage();
        $this->searchResults = collect();
    }
}

/**
 * Recherche de secours avec critères plus souples
 */
private function fallbackSearch(?string $vendor, ?string $name, ?string $type): void
{
    if (!$vendor || !$name) {
        return;
    }
    
    // Construction de la query complète
    $searchParts = array_filter([$vendor, $name, $type]);
    $searchQuery = implode(' ', $searchParts);
    
    $searchOptions = [
        'query_by' => 'vendor,name,type,variation',
        'query_by_weights' => '2,4,2,1',
        'prioritize_exact_match' => true,
        'prefix' => true,
        'num_typos' => 2, // Plus tolérant
        'per_page' => 20,
        'sort_by' => '_text_match:desc',
        // 'infix' => 'always', // RETIRER CETTE LIGNE
    ];
    
    try {
        $results = Product::search($searchQuery)
            ->options($searchOptions)
            ->get();
        
        $this->searchResults = collect($results)
            ->map(function($product) use ($vendor, $name, $type) {
                return $this->calculateProductScore($product, $vendor, $name, $type);
            })
            ->filter(fn($product) => $product->match_score > -200) // Plus tolérant
            ->sortByDesc('match_score')
            ->take(10)
            ->values();
            
    } catch (\Exception $e) {
        // Ignorer l'erreur du fallback
    }
}
    
    /**
     * Calcule le score de pertinence d'un produit
     */
    private function calculateProductScore($product, ?string $vendor, ?string $name, ?string $type): mixed
    {
        $score = 0;
        $details = [];
        
        // SCORE VENDOR (0-100 points)
        if ($vendor) {
            $normalizedVendor = $this->normalizeForSearch($vendor);
            $normalizedProductVendor = $this->normalizeForSearch($product->vendor ?? '');
            
            if ($normalizedProductVendor === $normalizedVendor) {
                $score += 100;
                $details['vendor_match'] = 'exact';
            } elseif (Str::contains($normalizedProductVendor, $normalizedVendor)) {
                $score += 50;
                $details['vendor_match'] = 'partial';
            } else {
                // Vendor ne match pas = score très bas
                $score -= 500;
                $details['vendor_match'] = 'none';
            }
        }
        
        // SCORE NAME (0-300+ points) - Le plus important
        if ($name) {
            $nameMatch = $this->matchWordByWord($name, $product->name ?? '', 70);
            
            if ($nameMatch['matched']) {
                $score += $nameMatch['score'];
                $details['name_match'] = [
                    'ratio' => $nameMatch['ratio'],
                    'in_order' => $nameMatch['in_order'],
                    'words' => $nameMatch['words'],
                ];
                
                // Bonus si match à 100%
                if ($nameMatch['ratio'] === 100.0) {
                    $score += 100;
                }
            } else {
                // Name ne match pas assez = score très bas
                $score -= 1000;
                $details['name_match'] = [
                    'ratio' => $nameMatch['ratio'],
                    'matched' => false,
                ];
            }
        }
        
        // SCORE TYPE (0-200+ points)
        if ($type) {
            $typeMatch = $this->matchWordByWord($type, $product->type ?? '', 60);
            
            if ($typeMatch['matched']) {
                $score += $typeMatch['score'];
                $details['type_match'] = [
                    'ratio' => $typeMatch['ratio'],
                    'in_order' => $typeMatch['in_order'],
                    'words' => $typeMatch['words'],
                ];
                
                // Bonus si match à 100%
                if ($typeMatch['ratio'] === 100.0) {
                    $score += 50;
                }
            } else {
                // Type ne match pas = pénalité légère
                $score -= 50;
                $details['type_match'] = [
                    'ratio' => $typeMatch['ratio'],
                    'matched' => false,
                ];
            }
        }
        
        $product->match_score = $score;
        $product->match_details = $details;
        
        return $product;
    }
    
    public function testWithExamples(): void
    {
        $this->loading = true;
        $this->error = null;
        
        try {
            $parser = new ProductSearchParser();
            
            $examples = [
                'Cacharel - Ella Ella Flora Azura - Eau de Parfum Vaporisateur 30ml',
                'Dior - J\'adore - Eau de Parfum 50ml',
                'Chanel - N°5 - Eau de Toilette Spray 100ml',
                'Shiseido Men - Revitalisant Total Crème - Recharge 50 ml',
            ];
            
            $this->products = collect($parser->parseMultipleProducts($examples));
            
        } catch (\Exception $e) {
            $this->error = 'Erreur: ' . $e->getMessage();
        } finally {
            $this->loading = false;
        }
    }
    
    public function clear(): void
    {
        $this->name = '';
        $this->parsedResult = [];
        $this->products = collect();
        $this->searchResults = collect();
        $this->error = null;
    }
}; ?>

<div class="max-w-6xl mx-auto p-6">
    <div class="bg-white rounded-lg shadow-lg p-6">
        <h2 class="text-2xl font-bold mb-6 text-gray-800">🧪 Test Product Search Parser</h2>
        
        {{-- Formulaire de test --}}
        <div class="mb-6">
            <label for="product-name" class="block text-sm font-medium text-gray-700 mb-2">
                Nom du produit
            </label>
            <input 
                type="text" 
                id="product-name"
                wire:model="name"
                placeholder="Ex: Cacharel - Ella Ella Flora Azura - Eau de Parfum Vaporisateur 30ml"
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            />
        </div>
        
        {{-- Boutons d'action --}}
        <div class="flex gap-3 mb-6">
            <button 
                wire:click="parseProduct"
                wire:loading.attr="disabled"
                class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition"
            >
                <span wire:loading.remove wire:target="parseProduct">🔍 Analyser & Rechercher</span>
                <span wire:loading wire:target="parseProduct">⏳ Analyse en cours...</span>
            </button>
            
            <button 
                wire:click="testWithExamples"
                wire:loading.attr="disabled"
                class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed transition"
            >
                <span wire:loading.remove wire:target="testWithExamples">📋 Tester avec exemples</span>
                <span wire:loading wire:target="testWithExamples">⏳ Chargement...</span>
            </button>
            
            <button 
                wire:click="clear"
                class="px-6 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition"
            >
                🗑️ Effacer
            </button>
        </div>
        
        {{-- Message d'erreur --}}
        @if($error)
            <div class="mb-6 p-4 bg-red-50 border-l-4 border-red-500 text-red-700 rounded">
                <p class="font-medium">❌ {{ $error }}</p>
            </div>
        @endif
        
        {{-- Résultat unique --}}
        @if(!empty($parsedResult))
            <div class="mb-6">
                <h3 class="text-lg font-semibold mb-3 text-gray-800">📊 Résultat de l'analyse</h3>
                <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <span class="font-semibold text-gray-700">Vendor:</span>
                            <span class="ml-2 text-gray-900">{{ $parsedResult['vendor'] ?? 'N/A' }}</span>
                        </div>
                        <div>
                            <span class="font-semibold text-gray-700">Name:</span>
                            <span class="ml-2 text-gray-900">{{ $parsedResult['name'] ?? 'N/A' }}</span>
                        </div>
                        <div>
                            <span class="font-semibold text-gray-700">Type:</span>
                            <span class="ml-2 text-gray-900">{{ $parsedResult['type'] ?? 'N/A' }}</span>
                        </div>
                        <div>
                            <span class="font-semibold text-gray-700">Variation:</span>
                            <span class="ml-2 text-gray-900">{{ $parsedResult['variation'] ?? 'N/A' }}</span>
                        </div>
                    </div>
                </div>
            </div>
        @endif
        
        {{-- Résultats de recherche --}}
        @if($searchResults->isNotEmpty())
            <div class="mb-6">
                <h3 class="text-lg font-semibold mb-3 text-gray-800">
                    🎯 Produits trouvés ({{ $searchResults->count() }})
                </h3>
                <div class="space-y-3">
                    @foreach($searchResults as $result)
                        <div class="bg-white border border-gray-300 rounded-lg p-4 hover:shadow-md transition">
                            <div class="flex items-start gap-4">
                                @if($result->image_url)
                                    <img 
                                        src="{{ $result->image_url }}" 
                                        alt="{{ $result->name }}"
                                        class="w-20 h-20 object-cover rounded"
                                        onerror="this.style.display='none'"
                                    />
                                @endif
                                <div class="flex-1">
                                    <div class="flex justify-between items-start mb-2">
                                        <div class="flex-1">
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <span class="text-xs font-semibold text-blue-600 uppercase">{{ $result->vendor }}</span>
                                                <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded">
                                                    Score: {{ $result->match_score ?? 0 }}
                                                </span>
                                                @if(isset($result->match_details['name_match_ratio']))
                                                    <span class="text-xs bg-purple-100 text-purple-700 px-2 py-1 rounded">
                                                        Name: {{ round($result->match_details['name_match_ratio']) }}%
                                                    </span>
                                                @endif
                                                @if(isset($result->match_details['type_match_ratio']))
                                                    <span class="text-xs bg-orange-100 text-orange-700 px-2 py-1 rounded">
                                                        Type: {{ round($result->match_details['type_match_ratio']) }}%
                                                    </span>
                                                @endif
                                                @if(isset($result->match_details['name_in_order']) && $result->match_details['name_in_order'])
                                                    <span class="text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded">
                                                        ✓ Ordre Name
                                                    </span>
                                                @endif
                                                @if(isset($result->match_details['type_in_order']) && $result->match_details['type_in_order'])
                                                    <span class="text-xs bg-cyan-100 text-cyan-700 px-2 py-1 rounded">
                                                        ✓ Ordre Type
                                                    </span>
                                                @endif
                                            </div>
                                            <h4 class="text-lg font-semibold text-gray-900 mt-1">{{ $result->name }}</h4>
                                            
                                            {{-- Détails du matching NAME mot par mot --}}
                                            @if(isset($result->match_details['name_words']))
                                                <div class="mt-2">
                                                    <span class="text-xs font-semibold text-gray-600">Name matching:</span>
                                                    <div class="flex gap-1 flex-wrap mt-1">
                                                        @foreach($result->match_details['name_words'] as $wordInfo)
                                                            @if($wordInfo['found'])
                                                                <span class="text-xs bg-green-50 text-green-800 px-2 py-1 rounded border border-green-200">
                                                                    ✓ {{ $wordInfo['word'] }}
                                                                </span>
                                                            @else
                                                                <span class="text-xs bg-red-50 text-red-800 px-2 py-1 rounded border border-red-200">
                                                                    ✗ {{ $wordInfo['word'] }}
                                                                </span>
                                                            @endif
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endif
                                            
                                            {{-- Détails du matching TYPE mot par mot --}}
                                            @if(isset($result->match_details['type_words']))
                                                <div class="mt-2">
                                                    <span class="text-xs font-semibold text-gray-600">Type matching:</span>
                                                    <div class="flex gap-1 flex-wrap mt-1">
                                                        @foreach($result->match_details['type_words'] as $wordInfo)
                                                            @if($wordInfo['found'])
                                                                <span class="text-xs bg-green-50 text-green-800 px-2 py-1 rounded border border-green-200">
                                                                    ✓ {{ $wordInfo['word'] }}
                                                                </span>
                                                            @else
                                                                <span class="text-xs bg-red-50 text-red-800 px-2 py-1 rounded border border-red-200">
                                                                    ✗ {{ $wordInfo['word'] }}
                                                                </span>
                                                            @endif
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endif
                                        </div>
                                        <div class="text-right">
                                            <p class="text-lg font-bold text-green-600">
                                                {{ number_format((float)($result->prix_ht ?? 0), 2) }} {{ $result->currency ?? '' }}
                                            </p>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-2 gap-2 text-sm text-gray-600 mt-2">
                                        <div>
                                            <span class="font-medium">Type:</span> {{ $result->type ?? 'N/A' }}
                                        </div>
                                        <div>
                                            <span class="font-medium">Variation:</span> {{ $result->variation ?? 'N/A' }}
                                        </div>
                                    </div>
                                    @if($result->url)
                                        <a 
                                            href="{{ $result->url }}" 
                                            target="_blank"
                                            class="inline-block mt-2 text-sm text-blue-600 hover:underline"
                                        >
                                            Voir le produit →
                                        </a>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @elseif(!empty($parsedResult) && $searchResults->isEmpty())
            <div class="mb-6 p-4 bg-yellow-50 border-l-4 border-yellow-500 text-yellow-700 rounded">
                <p class="font-medium">⚠️ Aucun produit trouvé avec ces critères stricts</p>
                <p class="text-sm mt-1">Les produits doivent avoir au moins 80% des mots du name et 70% des mots du type qui correspondent.</p>
            </div>
        @endif
        
        {{-- Résultats multiples --}}
        @if($products->isNotEmpty())
            <div>
                <h3 class="text-lg font-semibold mb-3 text-gray-800">📋 Résultats des exemples</h3>
                <div class="space-y-4">
                    @foreach($products as $product)
                        <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                            <div class="mb-3">
                                <span class="text-sm font-medium text-gray-500">Original:</span>
                                <p class="text-gray-900 font-medium">{{ $product['original'] }}</p>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-2 pt-3 border-t border-gray-300">
                                <div>
                                    <span class="text-sm font-semibold text-gray-700">Vendor:</span>
                                    <span class="ml-2 text-sm text-gray-900">{{ $product['parsed']['vendor'] ?? 'N/A' }}</span>
                                </div>
                                <div>
                                    <span class="text-sm font-semibold text-gray-700">Name:</span>
                                    <span class="ml-2 text-sm text-gray-900">{{ $product['parsed']['name'] ?? 'N/A' }}</span>
                                </div>
                                <div>
                                    <span class="text-sm font-semibold text-gray-700">Type:</span>
                                    <span class="ml-2 text-sm text-gray-900">{{ $product['parsed']['type'] ?? 'N/A' }}</span>
                                </div>
                                <div>
                                    <span class="text-sm font-semibold text-gray-700">Variation:</span>
                                    <span class="ml-2 text-sm text-gray-900">{{ $product['parsed']['variation'] ?? 'N/A' }}</span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
        
        {{-- État vide --}}
        @if(empty($parsedResult) && $products->isEmpty() && !$error && !$loading)
            <div class="text-center py-8 text-gray-500">
                <p class="text-lg">👆 Entrez un nom de produit ou testez avec les exemples</p>
            </div>
        @endif
    </div>
</div>