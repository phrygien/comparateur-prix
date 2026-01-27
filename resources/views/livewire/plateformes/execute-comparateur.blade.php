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

        // Sous-requête pour récupérer le dernier scrap_reference_id par site
        $latestProducts = DB::table('scraped_product as sp1')
            ->select('sp1.*')
            ->join(DB::raw('(SELECT web_site_id, MAX(scrap_reference_id) as max_ref_id 
                            FROM scraped_product 
                            GROUP BY web_site_id) as sp2'), function($join) {
                $join->on('sp1.web_site_id', '=', 'sp2.web_site_id')
                     ->on('sp1.scrap_reference_id', '=', 'sp2.max_ref_id');
            });

        // Stratégie de recherche en cascade
        
        // 1. Recherche exacte (tous les critères)
        $exactMatch = (clone $latestProducts)
            ->where('sp1.vendor', 'LIKE', "%{$vendor}%")
            ->where('sp1.name', 'LIKE', "%{$name}%")
            ->where('sp1.variation', 'LIKE', "%{$variation}%")
            ->when($type, fn($q) => $q->where('sp1.type', 'LIKE', "%{$type}%"))
            ->get();

        if ($exactMatch->isNotEmpty()) {
            $this->matchingProducts = $this->loadProductRelations($exactMatch);
            $this->bestMatch = $this->matchingProducts[0] ?? null;
            return;
        }

        // 2. Recherche sans variation
        $withoutVariation = (clone $latestProducts)
            ->where('sp1.vendor', 'LIKE', "%{$vendor}%")
            ->where('sp1.name', 'LIKE', "%{$name}%")
            ->when($type, fn($q) => $q->where('sp1.type', 'LIKE', "%{$type}%"))
            ->get();

        if ($withoutVariation->isNotEmpty()) {
            $this->matchingProducts = $this->loadProductRelations($withoutVariation);
            $this->bestMatch = $this->matchingProducts[0] ?? null;
            return;
        }

        // 3. Recherche vendor + name seulement
        $vendorAndName = (clone $latestProducts)
            ->where('sp1.vendor', 'LIKE', "%{$vendor}%")
            ->where('sp1.name', 'LIKE', "%{$name}%")
            ->get();

        if ($vendorAndName->isNotEmpty()) {
            $this->matchingProducts = $this->loadProductRelations($vendorAndName);
            $this->bestMatch = $this->matchingProducts[0] ?? null;
            return;
        }

        // 4. Recherche flexible avec dernier scrape par site
        $flexible = Product::query()
            ->with(['website', 'scraped_reference'])
            ->whereIn('id', function($query) {
                $query->select(DB::raw('MAX(id)'))
                    ->from('scraped_product')
                    ->groupBy('web_site_id', 'scrap_reference_id');
            })
            ->where(function($q) use ($vendor, $name) {
                $q->where('vendor', 'LIKE', "%{$vendor}%")
                  ->orWhere('name', 'LIKE', "%{$name}%");
            })
            ->orderBy('scrap_reference_id', 'DESC')
            ->limit(10)
            ->get();

        $this->matchingProducts = $flexible->toArray();
        $this->bestMatch = $flexible->first()?->toArray();
    }

    private function loadProductRelations($products)
    {
        $productIds = $products->pluck('id')->toArray();
        
        return Product::with(['website', 'scraped_reference'])
            ->whereIn('id', $productIds)
            ->get()
            ->toArray();
    }

    public function selectProduct($productId)
    {
        $product = Product::with(['website', 'scraped_reference'])->find($productId);
        
        if ($product) {
            session()->flash('success', 'Produit sélectionné : ' . $product->name);
            $this->bestMatch = $product->toArray();
            
            // Émettre un événement si besoin
            $this->dispatch('product-selected', productId: $productId);
        }
    }

}; ?>

<div class="p-6 bg-white rounded-lg shadow">
    <div class="mb-4">
        <h2 class="text-xl font-bold mb-2">Extraction et recherche de produit</h2>
        <p class="text-gray-600">Produit: {{ $productName }}</p>
    </div>

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
            </div>
        </div>
    @endif

    @if($bestMatch)
        <div class="mt-6 p-4 bg-green-50 border-2 border-green-500 rounded">
            <h3 class="font-bold text-green-700 mb-3">✓ Meilleur résultat :</h3>
            <div class="flex items-start gap-4">
                @if($bestMatch['image_url'])
                    <img src="{{ $bestMatch['image_url'] }}" alt="{{ $bestMatch['name'] }}" class="w-24 h-24 object-cover rounded">
                @endif
                <div class="flex-1">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="px-2 py-1 bg-blue-100 text-blue-700 text-xs font-semibold rounded">
                            {{ $bestMatch['website']['name'] ?? 'Site inconnu' }}
                        </span>
                        <span class="text-xs text-gray-500">
                            Réf. Scrape: #{{ $bestMatch['scrap_reference_id'] }}
                        </span>
                    </div>
                    <p class="font-semibold text-lg">{{ $bestMatch['vendor'] }} - {{ $bestMatch['name'] }}</p>
                    <p class="text-sm text-gray-600 mt-1">{{ $bestMatch['type'] }} | {{ $bestMatch['variation'] }}</p>
                    <p class="text-lg font-bold text-green-600 mt-2">{{ $bestMatch['prix_ht'] }} {{ $bestMatch['currency'] }}</p>
                    <div class="mt-2 flex gap-2">
                        <a href="{{ $bestMatch['url'] }}" target="_blank" class="text-sm text-blue-500 hover:underline inline-flex items-center gap-1">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                            </svg>
                            Voir sur {{ $bestMatch['website']['name'] ?? 'le site' }}
                        </a>
                    </div>
                    <p class="text-xs text-gray-400 mt-2">
                        Ajouté le {{ \Carbon\Carbon::parse($bestMatch['created_at'])->format('d/m/Y H:i') }}
                    </p>
                </div>
            </div>
        </div>
    @endif

    @if(!empty($matchingProducts) && count($matchingProducts) > 1)
        <div class="mt-6">
            <h3 class="font-bold mb-3">Autres résultats trouvés ({{ count($matchingProducts) }}) :</h3>
            <div class="space-y-2 max-h-96 overflow-y-auto">
                @foreach($matchingProducts as $product)
                    @if(!$bestMatch || $bestMatch['id'] !== $product['id'])
                        <div 
                            wire:click="selectProduct({{ $product['id'] }})"
                            class="p-3 border rounded hover:bg-blue-50 cursor-pointer transition bg-white"
                        >
                            <div class="flex items-center gap-3">
                                @if($product['image_url'])
                                    <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}" class="w-16 h-16 object-cover rounded">
                                @endif
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="px-2 py-0.5 bg-gray-100 text-gray-700 text-xs font-semibold rounded">
                                            {{ $product['website']['name'] ?? 'Site inconnu' }}
                                        </span>
                                        <span class="text-xs text-gray-400">
                                            Réf: #{{ $product['scrap_reference_id'] }}
                                        </span>
                                    </div>
                                    <p class="font-medium text-sm">{{ $product['vendor'] }} - {{ $product['name'] }}</p>
                                    <p class="text-xs text-gray-500">{{ $product['type'] }} | {{ $product['variation'] }}</p>
                                    <a href="{{ $product['url'] }}" target="_blank" class="text-xs text-blue-500 hover:underline inline-flex items-center gap-1 mt-1" onclick="event.stopPropagation()">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                        </svg>
                                        Voir le produit
                                    </a>
                                </div>
                                <div class="text-right">
                                    <p class="font-bold text-sm">{{ $product['prix_ht'] }} {{ $product['currency'] }}</p>
                                    <p class="text-xs text-gray-400">{{ \Carbon\Carbon::parse($product['created_at'])->format('d/m/Y') }}</p>
                                </div>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    @endif

    @if($extractedData && empty($matchingProducts))
        <div class="mt-6 p-4 bg-yellow-50 border border-yellow-300 rounded">
            <p class="text-yellow-800">❌ Aucun produit trouvé avec ces critères</p>
        </div>
    @endif
</div>