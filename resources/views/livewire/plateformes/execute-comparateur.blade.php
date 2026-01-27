<?php

use Livewire\Volt\Component;
use App\Models\Product;

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
                
                $content = preg_replace('/```json\s*|\s*```/', '', $content);
                $content = trim($content);
                
                $this->extractedData = json_decode($content, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('Erreur de parsing JSON: ' . json_last_error_msg());
                }
                
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

        $query = Product::query();

        // 1. Recherche exacte
        $exactMatch = (clone $query)
            ->where('vendor', 'LIKE', "%{$vendor}%")
            ->where('name', 'LIKE', "%{$name}%")
            ->where('variation', 'LIKE', "%{$variation}%")
            ->when($type, fn($q) => $q->where('type', 'LIKE', "%{$type}%"))
            ->get();

        if ($exactMatch->isNotEmpty()) {
            $this->matchingProducts = $exactMatch->toArray();
            $this->bestMatch = $exactMatch->first();
            return;
        }

        // 2. Recherche sans variation
        $withoutVariation = (clone $query)
            ->where('vendor', 'LIKE', "%{$vendor}%")
            ->where('name', 'LIKE', "%{$name}%")
            ->when($type, fn($q) => $q->where('type', 'LIKE', "%{$type}%"))
            ->get();

        if ($withoutVariation->isNotEmpty()) {
            $this->matchingProducts = $withoutVariation->toArray();
            $this->bestMatch = $withoutVariation->first();
            return;
        }

        // 3. Recherche vendor + name
        $vendorAndName = (clone $query)
            ->where('vendor', 'LIKE', "%{$vendor}%")
            ->where('name', 'LIKE', "%{$name}%")
            ->get();

        if ($vendorAndName->isNotEmpty()) {
            $this->matchingProducts = $vendorAndName->toArray();
            $this->bestMatch = $vendorAndName->first();
            return;
        }

        // 4. Full-text search
        if (method_exists(Product::class, 'scopeFullTextSearch')) {
            $searchQuery = trim("{$vendor} {$name} {$type} {$variation}");
            $fullTextResults = Product::fullTextSearch($searchQuery)->get();

            if ($fullTextResults->isNotEmpty()) {
                $this->matchingProducts = $fullTextResults->toArray();
                $this->bestMatch = $fullTextResults->first();
                return;
            }
        }

        // 5. Recherche flexible
        $flexible = Product::where(function($q) use ($vendor, $name) {
            $q->where('vendor', 'LIKE', "%{$vendor}%")
              ->orWhere('name', 'LIKE', "%{$name}%");
        })
        ->limit(10)
        ->get();

        $this->matchingProducts = $flexible->toArray();
        $this->bestMatch = $flexible->first();
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

    // Préparer les produits pour le composant x-list-item
    public function getProductsForList()
    {
        return collect($this->matchingProducts)->map(function($product) {
            return (object)[
                'id' => $product['id'],
                'title' => $product['vendor'] . ' - ' . $product['name'],
                'username' => $product['type'] . ' | ' . $product['variation'],
                'subtitle' => $product['prix_ht'] . ' ' . $product['currency'],
                'avatar' => $product['image_url'] ?? null,
                'url' => $product['url'] ?? null,
            ];
        });
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
            @php
                $bestMatchObj = (object)[
                    'id' => $bestMatch['id'],
                    'title' => $bestMatch['vendor'] . ' - ' . $bestMatch['name'],
                    'username' => $bestMatch['type'] . ' | ' . $bestMatch['variation'],
                    'subtitle' => $bestMatch['prix_ht'] . ' ' . $bestMatch['currency'],
                    'avatar' => $bestMatch['image_url'] ?? null,
                ];
            @endphp
            <x-list-item 
                :item="$bestMatchObj" 
                value="title"
                sub-value="username" 
                :link="$bestMatch['url'] ?? '#'" 
            />
        </div>
    @endif

    @if(!empty($matchingProducts) && count($matchingProducts) > 1)
        <div class="mt-6">
            <h3 class="font-bold mb-3">Autres résultats trouvés ({{ count($matchingProducts) }}) :</h3>
            <div class="space-y-2 max-h-96 overflow-y-auto">
                @foreach($this->getProductsForList() as $product)
                    <div wire:click="selectProduct({{ $product->id }})" class="cursor-pointer">
                        <x-list-item 
                            :item="$product" 
                            value="title"
                            sub-value="username" 
                            :link="$product->url ?? '#'" 
                        />
                    </div>
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