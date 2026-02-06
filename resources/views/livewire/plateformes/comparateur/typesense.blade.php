<?php

use Livewire\Volt\Component;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

new class extends Component {
    public string $name;
    public string $id;
    public string $price;
    
    public ?string $vendor = null;
    public ?string $productName = null;
    public ?string $type = null;
    public ?string $variation = null;
    public bool $isProcessing = false;
    public bool $isProcessed = false;
    
    // Nouveaux champs pour la recherche
    public bool $isSearching = false;
    public ?Collection $similarProducts = null;
    public ?Product $matchedProduct = null;
    public ?float $similarityScore = null;
    public int $totalProductsChecked = 0;

    public function mount($name, $id, $price): void
    {
        $this->name = $name;
        $this->id = $id;
        $this->price = $price;
        
        // Extraction automatique au montage du composant
        $this->extractProductInfo();
    }

    public function extractProductInfo(): void
    {
        $this->isProcessing = true;
        
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Tu es un expert en extraction d\'informations de produits cosmétiques et parfums.

Règles d\'extraction:
- Vendor: La marque du produit (ex: Coach, Biotherm, Dior)
- Name: Le nom complet du produit sans la marque (ex: Coach Green Homme, Life Plankton)
- Type: Le type de produit de manière générale (ex: Eau de Toilette, Eau de Parfum, Lait pour le corps, Crème visage, Sérum, Gel douche, etc.)
- Variation: La contenance ou taille (ex: 100ml, 50ml, 40ml, 200ml)

Exemples:
1. "Coach - Coach Green Homme - Eau de Toilette 100 ml"
   → vendor: "Coach", name: "Coach Green Homme", type: "Eau de Toilette", variation: "100ml"

2. "Biotherm - Life Plankton - Lait pour le corps lissant et raffermissant 40ml"
   → vendor: "Biotherm", name: "Life Plankton", type: "Lait pour le corps", variation: "40ml"

3. "Dior - J\'adore - Eau de Parfum 50ml"
   → vendor: "Dior", name: "J\'adore", type: "Eau de Parfum", variation: "50ml"

IMPORTANT: Pour le type, garde uniquement la catégorie générale (Lait pour le corps, Crème visage, etc.) sans les descriptions marketing (lissant, raffermissant, hydratant, etc.)

Réponds UNIQUEMENT en format JSON avec ces clés: vendor, name, type, variation.'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Extrait les informations de ce produit: {$this->name}"
                    ]
                ],
                'temperature' => 0.2,
                'response_format' => ['type' => 'json_object']
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $content = json_decode($data['choices'][0]['message']['content'], true);
                
                $this->vendor = $content['vendor'] ?? null;
                $this->productName = $content['name'] ?? null;
                $this->type = $content['type'] ?? null;
                $this->variation = $content['variation'] ?? null;
                
                $this->isProcessed = true;
                
                // Lancer automatiquement la recherche après l'extraction
                $this->searchSimilarProducts();
            }
        } catch (\Exception $e) {
            logger()->error('OpenAI extraction error: ' . $e->getMessage());
        } finally {
            $this->isProcessing = false;
        }
    }

    public function searchSimilarProducts(): void
    {
        if (!$this->vendor || !$this->productName) {
            return;
        }

        $this->isSearching = true;
        $this->similarProducts = collect();
        $this->matchedProduct = null;
        $this->similarityScore = null;
        $this->totalProductsChecked = 0;

        try {
            // Rechercher les produits du même vendor dans la base de données - LIMITE 300
            $products = Product::where('vendor', 'LIKE', "%{$this->vendor}%")
                ->limit(300)
                ->get();

            $this->totalProductsChecked = $products->count();

            if ($products->isEmpty()) {
                logger()->info("Aucun produit trouvé pour le vendor: {$this->vendor}");
                $this->isSearching = false;
                return;
            }

            $bestMatch = null;
            $bestScore = 0;

            // Pour chaque produit trouvé, calculer la similarité
            foreach ($products as $product) {
                try {
                    // Appeler l'API de similarité
                    $similarityResponse = Http::timeout(10)->post('http://127.0.0.1:8000/similarity', [
                        'text1' => $this->productName,
                        'text2' => $product->name
                    ]);

                    if ($similarityResponse->successful()) {
                        $similarityData = $similarityResponse->json();
                        $score = $similarityData['similarity'] ?? 0;
                        
                        // Ajouter à la collection avec le score
                        $product->similarity_score = round($score, 4);
                        $this->similarProducts->push($product);

                        // Garder le meilleur match
                        if ($score > $bestScore) {
                            $bestScore = $score;
                            $bestMatch = $product;
                        }
                    }
                } catch (\Exception $e) {
                    logger()->error("Similarity API error for product {$product->id}: " . $e->getMessage());
                    continue;
                }
            }

            // Trier par score de similarité (décroissant)
            $this->similarProducts = $this->similarProducts->sortByDesc('similarity_score')->values();

            // Définir le meilleur match si le score est >= 0.93
            if ($bestMatch && $bestScore >= 0.93) {
                $this->matchedProduct = $bestMatch;
                $this->similarityScore = $bestScore;
            }

        } catch (\Exception $e) {
            logger()->error('Similarity search error: ' . $e->getMessage());
        } finally {
            $this->isSearching = false;
        }
    }

}; ?>

<div class="bg-white p-6 rounded-lg shadow-lg max-w-4xl mx-auto">
    <div class="mb-6">
        <h3 class="text-xl font-bold mb-2 text-gray-800">Produit Original:</h3>
        <p class="text-gray-700 text-lg">{{ $name }}</p>
        <p class="text-sm text-gray-500 mt-1">Prix: {{ $price }}</p>
        <p class="text-xs text-gray-400">ID: {{ $id }}</p>
    </div>

    @if($isProcessing)
        <div class="flex items-center gap-3 text-blue-600 bg-blue-50 p-4 rounded-lg">
            <svg class="animate-spin h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span class="font-medium">Extraction en cours...</span>
        </div>
    @endif

    @if($isProcessed)
        <div class="mt-6 border-t-2 border-gray-200 pt-6">
            <h3 class="text-xl font-bold mb-4 text-gray-800">Informations Extraites:</h3>
            <div class="grid grid-cols-2 gap-6">
                <div class="bg-gradient-to-br from-blue-50 to-blue-100 p-4 rounded-lg">
                    <span class="font-semibold text-blue-900 block mb-1">Vendor:</span>
                    <p class="text-blue-800 text-lg">{{ $vendor ?? 'N/A' }}</p>
                </div>
                <div class="bg-gradient-to-br from-green-50 to-green-100 p-4 rounded-lg">
                    <span class="font-semibold text-green-900 block mb-1">Name:</span>
                    <p class="text-green-800 text-lg">{{ $productName ?? 'N/A' }}</p>
                </div>
                <div class="bg-gradient-to-br from-purple-50 to-purple-100 p-4 rounded-lg">
                    <span class="font-semibold text-purple-900 block mb-1">Type:</span>
                    <p class="text-purple-800 text-lg">{{ $type ?? 'N/A' }}</p>
                </div>
                <div class="bg-gradient-to-br from-orange-50 to-orange-100 p-4 rounded-lg">
                    <span class="font-semibold text-orange-900 block mb-1">Variation:</span>
                    <p class="text-orange-800 text-lg">{{ $variation ?? 'N/A' }}</p>
                </div>
            </div>
        </div>
    @endif

    @if($isSearching)
        <div class="mt-6 border-t-2 border-gray-200 pt-6">
            <div class="flex items-center gap-3 text-indigo-600 bg-indigo-50 p-4 rounded-lg">
                <svg class="animate-spin h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span class="font-medium">Recherche de produits similaires... (max 300 produits)</span>
            </div>
        </div>
    @endif

    @if(!$isSearching && $totalProductsChecked > 0)
        <div class="mt-4">
            <div class="bg-gray-100 border border-gray-300 rounded-lg p-3 inline-block">
                <span class="text-sm font-medium text-gray-700">
                    📊 {{ $totalProductsChecked }} produit{{ $totalProductsChecked > 1 ? 's' : '' }} analysé{{ $totalProductsChecked > 1 ? 's' : '' }}
                    @if($totalProductsChecked >= 300)
                        <span class="text-orange-600">(limite atteinte)</span>
                    @endif
                </span>
            </div>
        </div>
    @endif

    @if(!$isSearching && $matchedProduct)
        <div class="mt-6 border-t-2 border-green-500 pt-6">
            <h3 class="text-xl font-bold mb-4 text-green-700 flex items-center gap-2">
                <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                </svg>
                Correspondance Parfaite Trouvée! (≥ 93%)
            </h3>
            <div class="bg-gradient-to-br from-green-50 to-emerald-100 border-2 border-green-300 rounded-xl p-6">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <span class="font-semibold text-gray-700 block mb-1">Produit correspondant:</span>
                        <p class="text-gray-900 text-lg font-medium">{{ $matchedProduct->name }}</p>
                    </div>
                    <div>
                        <span class="font-semibold text-gray-700 block mb-1">Vendor:</span>
                        <p class="text-gray-900">{{ $matchedProduct->vendor }}</p>
                    </div>
                    <div>
                        <span class="font-semibold text-gray-700 block mb-1">Type:</span>
                        <p class="text-gray-900">{{ $matchedProduct->type ?? 'N/A' }}</p>
                    </div>
                    <div>
                        <span class="font-semibold text-gray-700 block mb-1">Variation:</span>
                        <p class="text-gray-900">{{ $matchedProduct->variation ?? 'N/A' }}</p>
                    </div>
                    <div>
                        <span class="font-semibold text-gray-700 block mb-1">Score de similarité:</span>
                        <p class="text-green-700 text-2xl font-bold">{{ number_format($similarityScore * 100, 2) }}%</p>
                    </div>
                    <div>
                        <span class="font-semibold text-gray-700 block mb-1">ID Produit:</span>
                        <p class="text-gray-900 font-mono">{{ $matchedProduct->id }}</p>
                    </div>
                    <div>
                        <span class="font-semibold text-gray-700 block mb-1">Prix HT:</span>
                        <p class="text-gray-900">{{ $matchedProduct->prix_ht }} {{ $matchedProduct->currency }}</p>
                    </div>
                    <div>
                        <span class="font-semibold text-gray-700 block mb-1">Site:</span>
                        <p class="text-gray-900">{{ $matchedProduct->website->name ?? 'N/A' }}</p>
                    </div>
                </div>
                @if($matchedProduct->url)
                    <div class="mt-4">
                        <a href="{{ $matchedProduct->url }}" target="_blank" class="inline-flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                            </svg>
                            Voir le produit
                        </a>
                    </div>
                @endif
            </div>
        </div>
    @endif

    @if(!$isSearching && $similarProducts && $similarProducts->isNotEmpty() && !$matchedProduct)
        <div class="mt-6 border-t-2 border-gray-200 pt-6">
            <h3 class="text-xl font-bold mb-4 text-gray-800">Produits Similaires Trouvés (< 93%):</h3>
            <div class="space-y-3">
                @foreach($similarProducts->take(5) as $product)
                    <div class="bg-white border-2 border-gray-200 hover:border-gray-300 rounded-lg p-4 transition">
                        <div class="flex justify-between items-start gap-4">
                            <div class="flex-1">
                                <p class="font-semibold text-gray-900 text-lg mb-1">{{ $product->name }}</p>
                                <div class="grid grid-cols-2 gap-2 text-sm text-gray-600">
                                    <p><span class="font-medium">Vendor:</span> {{ $product->vendor }}</p>
                                    <p><span class="font-medium">Type:</span> {{ $product->type ?? 'N/A' }}</p>
                                    <p><span class="font-medium">Variation:</span> {{ $product->variation ?? 'N/A' }}</p>
                                    <p><span class="font-medium">ID:</span> {{ $product->id }}</p>
                                </div>
                            </div>
                            <div class="flex flex-col items-end gap-2">
                                <span class="inline-flex items-center px-4 py-2 rounded-full text-base font-bold whitespace-nowrap
                                    {{ $product->similarity_score >= 0.90 ? 'bg-green-100 text-green-800 border-2 border-green-300' : 
                                       ($product->similarity_score >= 0.80 ? 'bg-yellow-100 text-yellow-800 border-2 border-yellow-300' : 
                                       ($product->similarity_score >= 0.70 ? 'bg-orange-100 text-orange-800 border-2 border-orange-300' : 
                                       'bg-gray-100 text-gray-800 border-2 border-gray-300')) }}">
                                    {{ number_format($product->similarity_score * 100, 2) }}%
                                </span>
                                <span class="text-xs text-gray-500">
                                    {{ $product->prix_ht }} {{ $product->currency }}
                                </span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if(!$isSearching && $isProcessed && (!$similarProducts || $similarProducts->isEmpty()) && $vendor)
        <div class="mt-6 border-t-2 border-gray-200 pt-6">
            <div class="bg-yellow-50 border-2 border-yellow-300 rounded-lg p-4 flex items-start gap-3">
                <svg class="w-6 h-6 text-yellow-600 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>
                <div>
                    <p class="text-yellow-800 font-semibold">Aucun produit trouvé</p>
                    <p class="text-yellow-700 text-sm mt-1">Aucun produit trouvé pour le vendor "{{ $vendor }}" dans la base de données (max 300 produits).</p>
                </div>
            </div>
        </div>
    @endif
</div>