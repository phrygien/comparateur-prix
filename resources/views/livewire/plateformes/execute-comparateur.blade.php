<?php

use Livewire\Volt\Component;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

new class extends Component {
    
    public string $productName = '';
    public $productPrice;
    public $productId;
    public $extractedData = null;
    public $isLoading = false;
    public $matchingProducts = [];
    public $bestMatch = null;
    public $aiAnalysis = null;

    public function mount($name, $id, $price): void
    {
        $this->productName = $name;
        $this->productId = $id;
        $this->productPrice = $price;
    }

    public function extractAndSearchWithAI()
    {
        $this->isLoading = true;
        
        try {
            // Étape 1: Extraire les critères du produit recherché
            $extractedCriteria = $this->extractProductCriteria();
            
            if (!$extractedCriteria) {
                throw new \Exception('Impossible d\'extraire les critères du produit');
            }

            $this->extractedData = $extractedCriteria;

            // Étape 2: Pré-filtrer les produits dans la base de données
            $candidateProducts = $this->preFilterProducts($extractedCriteria);

            if ($candidateProducts->isEmpty()) {
                session()->flash('error', 'Aucun produit candidat trouvé dans la base de données.');
                $this->matchingProducts = [];
                return;
            }

            // Étape 3: Demander à l'IA de comparer avec les candidats (limité)
            $this->compareWithAI($extractedCriteria, $candidateProducts);

            session()->flash('success', 'Analyse IA terminée avec succès !');
            
        } catch (\Exception $e) {
            \Log::error('Erreur extraction AI', [
                'message' => $e->getMessage(),
                'product_name' => $this->productName,
                'trace' => $e->getTraceAsString()
            ]);
            
            session()->flash('error', 'Erreur lors de l\'analyse: ' . $e->getMessage());
        } finally {
            $this->isLoading = false;
        }
    }

    private function extractProductCriteria(): ?array
    {
        $response = Http::timeout(30)->withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-4o-mini',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Tu es un expert en extraction de données de produits cosmétiques. Extrait vendor, name, variation et type. Réponds UNIQUEMENT en JSON.'
                ],
                [
                    'role' => 'user',
                    'content' => "Extrait les informations de ce produit au format JSON :

Produit : {$this->productName}

Format attendu :
{
  \"vendor\": \"marque\",
  \"name\": \"nom du produit\",
  \"variation\": \"contenance (ml, g, etc.)\",
  \"type\": \"type de produit\"
}"
                ]
            ],
            'temperature' => 0.2,
            'max_tokens' => 300
        ]);

        if (!$response->successful()) {
            throw new \Exception('Erreur lors de l\'extraction des critères: ' . $response->body());
        }

        $data = $response->json();
        $content = $data['choices'][0]['message']['content'];
        $content = preg_replace('/```json\s*|\s*```/', '', $content);
        $content = trim($content);
        
        return json_decode($content, true);
    }

    private function preFilterProducts(array $criteria): \Illuminate\Database\Eloquent\Collection
    {
        $vendor = $criteria['vendor'] ?? '';
        $name = $criteria['name'] ?? '';
        $type = $criteria['type'] ?? '';

        // Récupérer les IDs des derniers produits par site
        $latestProductIds = DB::table('scraped_product as sp')
            ->select('sp.id')
            ->join(DB::raw('(
                SELECT web_site_id, MAX(scrap_reference_id) as max_ref_id 
                FROM scraped_product 
                GROUP BY web_site_id
            ) as latest'), function($join) {
                $join->on('sp.web_site_id', '=', 'latest.web_site_id')
                     ->on('sp.scrap_reference_id', '=', 'latest.max_ref_id');
            })
            ->pluck('id')
            ->toArray();

        // Recherche flexible pour réduire le nombre de candidats
        return Product::with(['website', 'scraped_reference'])
            ->whereIn('id', $latestProductIds)
            ->where(function($query) use ($vendor, $name, $type) {
                // Recherche sur vendor
                if ($vendor) {
                    $query->where('vendor', 'LIKE', "%{$vendor}%");
                }
                
                // OU recherche sur name
                if ($name) {
                    $query->orWhere('name', 'LIKE', "%{$name}%");
                }
                
                // OU recherche sur type
                if ($type) {
                    $query->orWhere('type', 'LIKE', "%{$type}%");
                }
            })
            ->limit(30) // Limiter à 30 candidats max
            ->get();
    }

    private function compareWithAI(array $criteria, $candidateProducts)
    {
        // Préparer les données des produits candidats (format compact)
        $productsData = $candidateProducts->map(function($product) {
            return [
                'id' => $product->id,
                'v' => $product->vendor, // v pour vendor (compact)
                'n' => $product->name,   // n pour name
                't' => $product->type,   // t pour type
                'var' => $product->variation,
                'p' => $product->prix_ht,
                's' => $product->website->name ?? 'Unknown',
                'ref' => $product->scrap_reference_id,
            ];
        })->toArray();

        $productsJson = json_encode($productsData, JSON_UNESCAPED_UNICODE);

        $response = Http::timeout(60)->withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-4o-mini', // Utiliser mini au lieu de gpt-4o
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Expert en comparaison de produits. Compare et score les produits. Réponds en JSON uniquement.'
                ],
                [
                    'role' => 'user',
                    'content' => $this->buildComparisonPrompt($criteria, $productsJson, count($productsData))
                ]
            ],
            'temperature' => 0.2,
            'max_tokens' => 1500
        ]);

        if (!$response->successful()) {
            throw new \Exception('Erreur API OpenAI comparaison: ' . $response->body());
        }

        $data = $response->json();
        $content = $data['choices'][0]['message']['content'];
        $content = preg_replace('/```json\s*|\s*```/', '', $content);
        $content = trim($content);
        
        $aiResult = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Erreur de parsing JSON: ' . json_last_error_msg());
        }

        // Stocker l'analyse
        $this->aiAnalysis = $aiResult['analysis'] ?? null;

        // Récupérer les produits correspondants
        if (!empty($aiResult['matching_product_ids'])) {
            $matchingIds = $aiResult['matching_product_ids'];
            
            $this->matchingProducts = Product::with(['website', 'scraped_reference'])
                ->whereIn('id', $matchingIds)
                ->get()
                ->sortBy(function($product) use ($matchingIds) {
                    return array_search($product->id, $matchingIds);
                })
                ->values()
                ->toArray();

            $this->bestMatch = $this->matchingProducts[0] ?? null;
        } else {
            $this->matchingProducts = [];
            $this->bestMatch = null;
        }
    }

    private function buildComparisonPrompt(array $criteria, string $productsJson, int $count): string
    {
        return <<<PROMPT
# PRODUIT RECHERCHÉ
Vendor: {$criteria['vendor']}
Name: {$criteria['name']}
Type: {$criteria['type']}
Variation: {$criteria['variation']}

# CANDIDATS ({$count} produits)
Format: id, v=vendor, n=name, t=type, var=variation, p=prix, s=site, ref=reference
{$productsJson}

# INSTRUCTIONS
1. Score chaque produit de 0-100 :
   - 100: match parfait (4/4 critères)
   - 80-99: excellent (3/4 critères)
   - 60-79: bon (2/4 critères)
   - <60: insuffisant

2. Tolère: variations orthographe, abréviations, accents, casse

3. Retourne UNIQUEMENT ce JSON :
{
  "matching_product_ids": [id1, id2, id3],
  "analysis": {
    "total_products_analyzed": {$count},
    "matches_found": 0,
    "confidence_level": "high|medium|low",
    "reasoning": "explication courte",
    "product_scores": [
      {"id": 123, "score": 95, "site": "Site", "match_details": "v:✓ n:✓ t:✓ var:~"}
    ]
  }
}

Retourne max 10 produits avec score >= 60, triés par score DESC.
PROMPT;
    }

    public function selectProduct($productId)
    {
        $product = Product::with(['website', 'scraped_reference'])->find($productId);
        
        if ($product) {
            session()->flash('success', 'Produit sélectionné : ' . $product->name);
            $this->bestMatch = $product->toArray();
            
            $this->dispatch('product-selected', productId: $productId);
        }
    }

}; ?>

<div class="p-6 bg-white rounded-lg shadow-lg">
    <div class="mb-6">
        <h2 class="text-2xl font-bold mb-2 text-gray-800">🤖 Recherche Intelligente IA</h2>
        <p class="text-gray-600">Produit recherché: <span class="font-semibold">{{ $productName }}</span></p>
        @if($productPrice)
            <p class="text-gray-600">Prix: <span class="font-semibold">{{ $productPrice }}</span></p>
        @endif
    </div>

    <button 
        wire:click="extractAndSearchWithAI"
        wire:loading.attr="disabled"
        class="px-6 py-3 bg-gradient-to-r from-blue-500 to-purple-600 text-white font-semibold rounded-lg hover:from-blue-600 hover:to-purple-700 disabled:opacity-50 transition-all shadow-md"
    >
        <span wire:loading.remove class="flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
            </svg>
            Lancer l'analyse IA
        </span>
        <span wire:loading class="flex items-center gap-2">
            <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            Analyse en cours...
        </span>
    </button>

    @if(session('error'))
        <div class="mt-4 p-4 bg-red-50 border-l-4 border-red-500 rounded">
            <div class="flex items-start gap-3">
                <svg class="w-6 h-6 text-red-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <p class="text-red-700">{{ session('error') }}</p>
            </div>
        </div>
    @endif

    @if(session('success'))
        <div class="mt-4 p-4 bg-green-50 border-l-4 border-green-500 rounded">
            <div class="flex items-start gap-3">
                <svg class="w-6 h-6 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <p class="text-green-700">{{ session('success') }}</p>
            </div>
        </div>
    @endif

    @if($aiAnalysis)
        <div class="mt-6 p-5 bg-gradient-to-br from-purple-50 to-blue-50 border border-purple-200 rounded-lg">
            <div class="flex items-center gap-2 mb-4">
                <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                </svg>
                <h3 class="font-bold text-lg text-purple-900">Analyse IA</h3>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <div class="bg-white p-3 rounded shadow-sm">
                    <p class="text-xs text-gray-500 mb-1">Produits analysés</p>
                    <p class="text-2xl font-bold text-gray-800">{{ $aiAnalysis['total_products_analyzed'] ?? 0 }}</p>
                </div>
                <div class="bg-white p-3 rounded shadow-sm">
                    <p class="text-xs text-gray-500 mb-1">Correspondances trouvées</p>
                    <p class="text-2xl font-bold text-green-600">{{ $aiAnalysis['matches_found'] ?? 0 }}</p>
                </div>
                <div class="bg-white p-3 rounded shadow-sm">
                    <p class="text-xs text-gray-500 mb-1">Niveau de confiance</p>
                    <p class="text-2xl font-bold {{ $aiAnalysis['confidence_level'] === 'high' ? 'text-green-600' : ($aiAnalysis['confidence_level'] === 'medium' ? 'text-yellow-600' : 'text-red-600') }}">
                        {{ strtoupper($aiAnalysis['confidence_level'] ?? 'N/A') }}
                    </p>
                </div>
            </div>

            @if($aiAnalysis['reasoning'])
                <div class="bg-white p-4 rounded shadow-sm mb-4">
                    <p class="text-sm font-semibold text-gray-700 mb-2">💡 Raisonnement de l'IA :</p>
                    <p class="text-sm text-gray-600 italic">{{ $aiAnalysis['reasoning'] }}</p>
                </div>
            @endif

            @if(!empty($aiAnalysis['product_scores']))
                <div class="bg-white p-4 rounded shadow-sm">
                    <p class="text-sm font-semibold text-gray-700 mb-3">📊 Scores de correspondance :</p>
                    <div class="space-y-2">
                        @foreach($aiAnalysis['product_scores'] as $score)
                            <div class="flex items-center gap-3 p-2 bg-gray-50 rounded">
                                <div class="flex-shrink-0 w-16">
                                    <div class="text-center">
                                        <div class="text-lg font-bold {{ $score['score'] >= 80 ? 'text-green-600' : ($score['score'] >= 60 ? 'text-yellow-600' : 'text-gray-600') }}">
                                            {{ $score['score'] }}
                                        </div>
                                        <div class="text-xs text-gray-500">pts</div>
                                    </div>
                                </div>
                                <div class="flex-1">
                                    <p class="text-sm font-medium text-gray-800">{{ $score['site'] }}</p>
                                    <p class="text-xs text-gray-500">{{ $score['match_details'] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif

    @if($extractedData)
        <div class="mt-6 p-4 bg-gray-50 rounded-lg border border-gray-200">
            <h3 class="font-bold mb-3 text-gray-800">🔍 Critères extraits par l'IA :</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-white p-3 rounded shadow-sm">
                    <span class="text-xs text-gray-500 block mb-1">Vendor</span>
                    <span class="font-semibold text-gray-800">{{ $extractedData['vendor'] ?? 'N/A' }}</span>
                </div>
                <div class="bg-white p-3 rounded shadow-sm">
                    <span class="text-xs text-gray-500 block mb-1">Name</span>
                    <span class="font-semibold text-gray-800">{{ $extractedData['name'] ?? 'N/A' }}</span>
                </div>
                <div class="bg-white p-3 rounded shadow-sm">
                    <span class="text-xs text-gray-500 block mb-1">Variation</span>
                    <span class="font-semibold text-gray-800">{{ $extractedData['variation'] ?? 'N/A' }}</span>
                </div>
                <div class="bg-white p-3 rounded shadow-sm">
                    <span class="text-xs text-gray-500 block mb-1">Type</span>
                    <span class="font-semibold text-gray-800">{{ $extractedData['type'] ?? 'N/A' }}</span>
                </div>
            </div>
        </div>
    @endif

    @if($bestMatch)
        <div class="mt-6 p-5 bg-gradient-to-br from-green-50 to-emerald-50 border-2 border-green-500 rounded-lg shadow-lg">
            <div class="flex items-center gap-2 mb-4">
                <svg class="w-7 h-7 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <h3 class="font-bold text-xl text-green-800">🏆 Meilleur résultat</h3>
            </div>
            <div class="flex items-start gap-4">
                @if($bestMatch['image_url'])
                    <img src="{{ $bestMatch['image_url'] }}" alt="{{ $bestMatch['name'] }}" class="w-32 h-32 object-cover rounded-lg shadow-md">
                @else
                    <div class="w-32 h-32 bg-gray-200 rounded-lg flex items-center justify-center shadow-md">
                        <svg class="w-16 h-16 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                @endif
                <div class="flex-1">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="px-3 py-1 bg-blue-500 text-white text-sm font-semibold rounded-full shadow">
                            {{ $bestMatch['website']['name'] ?? 'Site inconnu' }}
                        </span>
                        <span class="text-xs text-gray-600 bg-gray-100 px-2 py-1 rounded">
                            Réf. #{{ $bestMatch['scrap_reference_id'] }}
                        </span>
                    </div>
                    <p class="font-bold text-xl mb-2 text-gray-800">{{ $bestMatch['vendor'] }} - {{ $bestMatch['name'] }}</p>
                    <p class="text-base text-gray-600 mb-3">
                        <span class="font-medium">{{ $bestMatch['type'] }}</span> 
                        <span class="text-gray-400">•</span> 
                        {{ $bestMatch['variation'] }}
                    </p>
                    <p class="text-3xl font-bold text-green-600 mb-4">{{ $bestMatch['prix_ht'] }} {{ $bestMatch['currency'] }}</p>
                    <div class="flex gap-3">
                        <a href="{{ $bestMatch['url'] }}" target="_blank" class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-500 text-white text-sm font-medium rounded-lg hover:bg-blue-600 transition shadow-md">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                            </svg>
                            Voir sur {{ $bestMatch['website']['name'] ?? 'le site' }}
                        </a>
                    </div>
                    <p class="text-xs text-gray-500 mt-4 flex items-center gap-1">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Scrapé le {{ \Carbon\Carbon::parse($bestMatch['created_at'])->format('d/m/Y à H:i') }}
                    </p>
                </div>
            </div>
        </div>
    @endif

    @if(!empty($matchingProducts) && count($matchingProducts) > 1)
        <div class="mt-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-bold text-lg text-gray-800">
                    📦 Tous les résultats ({{ count($matchingProducts) }} produit{{ count($matchingProducts) > 1 ? 's' : '' }})
                </h3>
                <span class="text-sm text-gray-500 bg-gray-100 px-3 py-1 rounded-full">Triés par pertinence IA</span>
            </div>
            <div class="space-y-3 max-h-[600px] overflow-y-auto pr-2">
                @foreach($matchingProducts as $index => $product)
                    <div 
                        wire:click="selectProduct({{ $product['id'] }})"
                        class="p-4 border-2 rounded-lg hover:border-blue-400 hover:shadow-lg cursor-pointer transition-all {{ $bestMatch && $bestMatch['id'] === $product['id'] ? 'bg-green-50 border-green-500 shadow-md' : 'bg-white border-gray-200' }}"
                    >
                        <div class="flex items-center gap-4">
                            <div class="flex-shrink-0">
                                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-blue-400 to-purple-500 text-white flex items-center justify-center font-bold text-sm shadow">
                                    #{{ $index + 1 }}
                                </div>
                            </div>
                            @if($product['image_url'])
                                <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}" class="w-20 h-20 object-cover rounded-lg shadow-sm">
                            @else
                                <div class="w-20 h-20 bg-gray-100 rounded-lg flex items-center justify-center">
                                    <svg class="w-10 h-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                    </svg>
                                </div>
                            @endif
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="px-2 py-1 bg-blue-100 text-blue-700 text-xs font-semibold rounded-full">
                                        {{ $product['website']['name'] ?? 'Site inconnu' }}
                                    </span>
                                    <span class="text-xs text-gray-400">
                                        Réf: #{{ $product['scrap_reference_id'] }}
                                    </span>
                                    @if($bestMatch && $bestMatch['id'] === $product['id'])
                                        <span class="px-2 py-1 bg-green-500 text-white text-xs font-semibold rounded-full shadow">
                                            ✓ Sélectionné
                                        </span>
                                    @endif
                                </div>
                                <p class="font-semibold text-base truncate text-gray-800">{{ $product['vendor'] }} - {{ $product['name'] }}</p>
                                <p class="text-sm text-gray-600">{{ $product['type'] }} • {{ $product['variation'] }}</p>
                                <a href="{{ $product['url'] }}" target="_blank" class="inline-flex items-center gap-1 text-xs text-blue-500 hover:text-blue-700 hover:underline mt-1" onclick="event.stopPropagation()">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                    </svg>
                                    Voir le produit
                                </a>
                            </div>
                            <div class="text-right">
                                <p class="font-bold text-lg text-gray-800">{{ $product['prix_ht'] }} {{ $product['currency'] }}</p>
                                <p class="text-xs text-gray-400 mt-1">{{ \Carbon\Carbon::parse($product['created_at'])->format('d/m/Y') }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if($extractedData && empty($matchingProducts))
        <div class="mt-6 p-5 bg-yellow-50 border-2 border-yellow-300 rounded-lg">
            <div class="flex items-start gap-3">
                <svg class="w-7 h-7 text-yellow-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
                <div>
                    <p class="font-semibold text-yellow-800 text-lg">Aucun produit trouvé</p>
                    <p class="text-sm text-yellow-700 mt-1">L'IA n'a trouvé aucun produit correspondant aux critères avec un score de confiance suffisant (≥ 60%).</p>
                </div>
            </div>
        </div>
    @endif
</div>