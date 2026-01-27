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
- type : le type de produit (Crème, Sérum, Concentré, Déodorant, Eau de Toilette, etc.)

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
        $type = $this->extractedData['type'] ?? '';

        // Extraire les mots clés importants du type (ignorer les articles, prépositions, etc.)
        $typeKeywords = $this->extractTypeKeywords($type);

        // Stratégie de recherche en cascade - SANS LA VARIATION
        
        // 1. Recherche exacte : vendor + name + tous les mots-clés du type
        if (!empty($typeKeywords)) {
            $exactMatch = Product::where('vendor', 'LIKE', "%{$vendor}%")
                ->where('name', 'LIKE', "%{$name}%")
                ->where(function($q) use ($typeKeywords) {
                    foreach ($typeKeywords as $keyword) {
                        $q->where('type', 'LIKE', "%{$keyword}%");
                    }
                })
                ->get();

            if ($exactMatch->isNotEmpty()) {
                $this->matchingProducts = $exactMatch->toArray();
                $this->bestMatch = $exactMatch->first();
                return;
            }
        }

        // 2. Recherche avec au moins UN mot-clé du type
        if (!empty($typeKeywords)) {
            $partialTypeMatch = Product::where('vendor', 'LIKE', "%{$vendor}%")
                ->where('name', 'LIKE', "%{$name}%")
                ->where(function($q) use ($typeKeywords) {
                    foreach ($typeKeywords as $keyword) {
                        $q->orWhere('type', 'LIKE', "%{$keyword}%");
                    }
                })
                ->get();

            if ($partialTypeMatch->isNotEmpty()) {
                // Scorer les résultats en fonction du nombre de mots-clés matchés
                $scored = $this->scoreProductsByTypeMatch($partialTypeMatch, $typeKeywords);
                
                if ($scored->isNotEmpty()) {
                    $this->matchingProducts = $scored->toArray();
                    $this->bestMatch = $scored->first();
                    return;
                }
            }
        }

        // 3. Recherche vendor + name seulement (fallback)
        $vendorAndName = Product::where('vendor', 'LIKE', "%{$vendor}%")
            ->where('name', 'LIKE', "%{$name}%")
            ->get();

        if ($vendorAndName->isNotEmpty()) {
            $this->matchingProducts = $vendorAndName->toArray();
            $this->bestMatch = $vendorAndName->first();
            
            // Avertir l'utilisateur que le type ne correspond pas exactement
            session()->flash('warning', 'Produits trouvés mais le type peut différer. Vérifiez les résultats.');
            return;
        }

        // 4. Recherche flexible (au moins vendor OU name avec type similaire)
        $flexible = Product::where(function($q) use ($vendor, $name) {
            $q->where('vendor', 'LIKE', "%{$vendor}%")
              ->orWhere('name', 'LIKE', "%{$name}%");
        })
        ->when(!empty($typeKeywords), function($q) use ($typeKeywords) {
            $q->where(function($subQ) use ($typeKeywords) {
                foreach ($typeKeywords as $keyword) {
                    $subQ->orWhere('type', 'LIKE', "%{$keyword}%");
                }
            });
        })
        ->limit(10)
        ->get();

        $this->matchingProducts = $flexible->toArray();
        $this->bestMatch = $flexible->first();
    }

    /**
     * Extraire les mots-clés significatifs du type de produit
     */
    private function extractTypeKeywords(string $type): array
    {
        // Mots à ignorer (articles, prépositions, conditionnements, formats)
        $stopWords = [
            // Articles et prépositions
            'de', 'du', 'des', 'le', 'la', 'les', 'un', 'une', 'pour', 'avec', 'sans', 'et', 'ou',
            // Formats et conditionnements
            'vaporisateur', 'spray', 'pompe', 'tube', 'pot', 'flacon', 'roll-on', 'stick',
            // Unités et tailles
            'ml', 'mg', 'gr', 'grand', 'petit', 'mini', 'maxi'
        ];
        
        // Nettoyer et séparer
        $words = preg_split('/[\s\-\/]+/', strtolower($type));
        
        // Filtrer les mots vides et les mots trop courts
        $keywords = array_filter($words, function($word) use ($stopWords) {
            return strlen($word) > 2 && !in_array($word, $stopWords);
        });

        return array_values($keywords);
    }

    /**
     * Scorer les produits selon le nombre de mots-clés du type qui matchent
     */
    private function scoreProductsByTypeMatch($products, array $typeKeywords)
    {
        return $products->map(function($product) use ($typeKeywords) {
            $productType = strtolower($product->type ?? '');
            $matchCount = 0;

            foreach ($typeKeywords as $keyword) {
                if (str_contains($productType, strtolower($keyword))) {
                    $matchCount++;
                }
            }

            $product->match_score = $matchCount;
            return $product;
        })
        ->sortByDesc('match_score')
        ->filter(function($product) {
            return $product->match_score > 0; // Garder uniquement ceux avec au moins 1 match
        });
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

    @if(session('warning'))
        <div class="mt-4 p-4 bg-yellow-100 text-yellow-700 rounded">
            ⚠️ {{ session('warning') }}
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
                    <span class="font-semibold">Type:</span> {{ $extractedData['type'] ?? 'N/A' }}
                </div>
                <div>
                    <span class="font-semibold text-gray-500">Variation (non utilisée):</span> 
                    <span class="text-gray-400">{{ $extractedData['variation'] ?? 'N/A' }}</span>
                </div>
            </div>
        </div>
    @endif

    @if($bestMatch)
        <div class="mt-6 p-4 bg-green-50 border-2 border-green-500 rounded">
            <h3 class="font-bold text-green-700 mb-3">✓ Meilleur résultat :</h3>
            <div class="flex items-start gap-4">
                @if($bestMatch['image_url'])
                    <img src="{{ $bestMatch['image_url'] }}" alt="{{ $bestMatch['name'] }}" class="w-20 h-20 object-cover rounded">
                @endif
                <div class="flex-1">
                    <p class="font-semibold">{{ $bestMatch['vendor'] }} - {{ $bestMatch['name'] }}</p>
                    <p class="text-sm text-gray-600">{{ $bestMatch['type'] }} | {{ $bestMatch['variation'] }}</p>
                    <p class="text-sm font-bold text-green-600 mt-1">{{ $bestMatch['prix_ht'] }} {{ $bestMatch['currency'] }}</p>
                    <a href="{{ $bestMatch['url'] }}" target="_blank" class="text-xs text-blue-500 hover:underline">Voir le produit</a>
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
                            @if($product['image_url'])
                                <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}" class="w-12 h-12 object-cover rounded">
                            @endif
                            <div class="flex-1">
                                <p class="font-medium text-sm">{{ $product['vendor'] }} - {{ $product['name'] }}</p>
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
        </div>
    @endif
</div>