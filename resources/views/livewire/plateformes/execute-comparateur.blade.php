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
                'Authorization' => 'Bearer ' . config('services.openai.api_key'),
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

        // Stratégie de recherche en cascade
        
        // 1. Recherche exacte (tous les critères)
        $exactMatch = Product::with(['website', 'scraped_reference'])
            ->whereIn('id', $latestProductIds)
            ->where('vendor', 'LIKE', "%{$vendor}%")
            ->where('name', 'LIKE', "%{$name}%")
            ->where('variation', 'LIKE', "%{$variation}%")
            ->when($type, fn($q) => $q->where('type', 'LIKE', "%{$type}%"))
            ->orderBy('scrap_reference_id', 'DESC')
            ->get();

        if ($exactMatch->isNotEmpty()) {
            $this->matchingProducts = $exactMatch->toArray();
            $this->bestMatch = $exactMatch->first()->toArray();
            return;
        }

        // 2. Recherche sans variation
        $withoutVariation = Product::with(['website', 'scraped_reference'])
            ->whereIn('id', $latestProductIds)
            ->where('vendor', 'LIKE', "%{$vendor}%")
            ->where('name', 'LIKE', "%{$name}%")
            ->when($type, fn($q) => $q->where('type', 'LIKE', "%{$type}%"))
            ->orderBy('scrap_reference_id', 'DESC')
            ->get();

        if ($withoutVariation->isNotEmpty()) {
            $this->matchingProducts = $withoutVariation->toArray();
            $this->bestMatch = $withoutVariation->first()->toArray();
            return;
        }

        // 3. Recherche vendor + name seulement
        $vendorAndName = Product::with(['website', 'scraped_reference'])
            ->whereIn('id', $latestProductIds)
            ->where('vendor', 'LIKE', "%{$vendor}%")
            ->where('name', 'LIKE', "%{$name}%")
            ->orderBy('scrap_reference_id', 'DESC')
            ->get();

        if ($vendorAndName->isNotEmpty()) {
            $this->matchingProducts = $vendorAndName->toArray();
            $this->bestMatch = $vendorAndName->first()->toArray();
            return;
        }

        // 4. Recherche flexible (vendor OU name)
        $flexible = Product::with(['website', 'scraped_reference'])
            ->whereIn('id', $latestProductIds)
            ->where(function($q) use ($vendor, $name) {
                $q->where('vendor', 'LIKE', "%{$vendor}%")
                  ->orWhere('name', 'LIKE', "%{$name}%");
            })
            ->orderBy('scrap_reference_id', 'DESC')
            ->limit(20)
            ->get();

        $this->matchingProducts = $flexible->toArray();
        $this->bestMatch = $flexible->first()?->toArray();
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
        <span wire:loading>
            <svg class="animate-spin h-5 w-5 inline mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
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
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-white p-3 rounded shadow-sm">
                    <span class="text-xs text-gray-500 block mb-1">Vendor</span>
                    <span class="font-semibold">{{ $extractedData['vendor'] ?? 'N/A' }}</span>
                </div>
                <div class="bg-white p-3 rounded shadow-sm">
                    <span class="text-xs text-gray-500 block mb-1">Name</span>
                    <span class="font-semibold">{{ $extractedData['name'] ?? 'N/A' }}</span>
                </div>
                <div class="bg-white p-3 rounded shadow-sm">
                    <span class="text-xs text-gray-500 block mb-1">Variation</span>
                    <span class="font-semibold">{{ $extractedData['variation'] ?? 'N/A' }}</span>
                </div>
                <div class="bg-white p-3 rounded shadow-sm">
                    <span class="text-xs text-gray-500 block mb-1">Type</span>
                    <span class="font-semibold">{{ $extractedData['type'] ?? 'N/A' }}</span>
                </div>
            </div>
        </div>
    @endif

    @if($bestMatch)
        <div class="mt-6 p-4 bg-green-50 border-2 border-green-500 rounded-lg">
            <div class="flex items-center gap-2 mb-3">
                <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <h3 class="font-bold text-green-700 text-lg">Meilleur résultat</h3>
            </div>
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
                    <div class="flex items-center gap-2 mb-2">
                        <span class="px-3 py-1 bg-blue-100 text-blue-700 text-sm font-semibold rounded-full">
                            {{ $bestMatch['website']['name'] ?? 'Site inconnu' }}
                        </span>
                        <span class="text-xs text-gray-500">
                            Réf. #{{ $bestMatch['scrap_reference_id'] }}
                        </span>
                    </div>
                    <p class="font-bold text-lg mb-1">{{ $bestMatch['vendor'] }} - {{ $bestMatch['name'] }}</p>
                    <p class="text-sm text-gray-600 mb-2">
                        <span class="font-medium">{{ $bestMatch['type'] }}</span> 
                        <span class="text-gray-400">•</span> 
                        {{ $bestMatch['variation'] }}
                    </p>
                    <p class="text-2xl font-bold text-green-600 mb-3">{{ $bestMatch['prix_ht'] }} {{ $bestMatch['currency'] }}</p>
                    <div class="flex gap-3">
                        <a href="{{ $bestMatch['url'] }}" target="_blank" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-500 text-white text-sm font-medium rounded hover:bg-blue-600 transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                            </svg>
                            Voir sur {{ $bestMatch['website']['name'] ?? 'le site' }}
                        </a>
                    </div>
                    <p class="text-xs text-gray-400 mt-3">
                        <svg class="w-3 h-3 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Scrapé le {{ \Carbon\Carbon::parse($bestMatch['created_at'])->format('d/m/Y à H:i') }}
                    </p>
                </div>
            </div>
        </div>
    @endif

    @if(!empty($matchingProducts))
        <div class="mt-6">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-bold text-lg">
                    Tous les résultats ({{ count($matchingProducts) }} produit{{ count($matchingProducts) > 1 ? 's' : '' }})
                </h3>
                <span class="text-sm text-gray-500">Un produit par site (dernier scrape)</span>
            </div>
            <div class="space-y-3 max-h-[600px] overflow-y-auto pr-2">
                @foreach($matchingProducts as $product)
                    <div 
                        wire:click="selectProduct({{ $product['id'] }})"
                        class="p-4 border-2 rounded-lg hover:border-blue-400 hover:shadow-md cursor-pointer transition {{ $bestMatch && $bestMatch['id'] === $product['id'] ? 'bg-green-50 border-green-500' : 'bg-white border-gray-200' }}"
                    >
                        <div class="flex items-center gap-4">
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
                                        <span class="px-2 py-1 bg-green-500 text-white text-xs font-semibold rounded-full">
                                            Sélectionné
                                        </span>
                                    @endif
                                </div>
                                <p class="font-semibold text-base truncate">{{ $product['vendor'] }} - {{ $product['name'] }}</p>
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
        <div class="mt-6 p-4 bg-yellow-50 border-2 border-yellow-300 rounded-lg">
            <div class="flex items-start gap-3">
                <svg class="w-6 h-6 text-yellow-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
                <div>
                    <p class="font-semibold text-yellow-800">Aucun produit trouvé</p>
                    <p class="text-sm text-yellow-700 mt-1">Aucun produit ne correspond aux critères extraits dans la base de données.</p>
                </div>
            </div>
        </div>
    @endif
</div>