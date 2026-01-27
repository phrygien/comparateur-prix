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
                        'content' => 'Tu es un expert en extraction de données de produits cosmétiques. Tu dois extraire vendor, name, variation, type et is_coffret du nom de produit fourni. Réponds UNIQUEMENT avec un objet JSON valide, sans markdown ni texte supplémentaire.'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Extrait les informations suivantes du nom de produit et retourne-les au format JSON strict :
- vendor : la marque du produit (exemple: \"Armaf\", \"Azzaro\", \"Dior\")
- name : le nom exact de la gamme/ligne de produit (exemple: \"Club de Nuit Woman Intense\", \"Wanted\") (SANS coffret, SANS vaporisateur)
- variation : la contenance/taille (exemple: \"105ml\", \"100 ml\", \"50ml\")
- type : le type exact de produit (exemple: \"Eau de Parfum\", \"Eau de Toilette\", \"Crème\") (SANS vaporisateur, SANS spray, SANS coffret)
- is_coffret : true si le produit est un coffret/kit/set, false sinon

RÈGLES STRICTES:
- Enlève \"Vaporisateur\", \"Spray\", \"Atomiseur\" du type
- Le type doit être court : \"Eau de Parfum\", \"Eau de Toilette\", \"Crème\", \"Sérum\"
- Le name doit être exact, sans ajouter ni retirer de mots

Nom du produit : {$this->productName}

Exemples :
Input: \"Armaf - Club de Nuit Woman Intense - Eau de Parfum Vaporisateur 105ml\"
Output:
{
  \"vendor\": \"Armaf\",
  \"name\": \"Club de Nuit Woman Intense\",
  \"variation\": \"105ml\",
  \"type\": \"Eau de Parfum\",
  \"is_coffret\": false
}

Input: \"Azzaro - Coffret Wanted - Eau de Toilette 100ml + 2 produits\"
Output:
{
  \"vendor\": \"Azzaro\",
  \"name\": \"Wanted\",
  \"variation\": \"100ml\",
  \"type\": \"Eau de Toilette\",
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
        $isCoffret = $this->extractedData['is_coffret'] ?? false;

        // Nettoyer le type pour enlever les mots comme "Vaporisateur", "Spray"
        $cleanType = preg_replace('/\b(vaporisateur|spray|atomiseur)\b/i', '', $type);
        $cleanType = trim($cleanType);

        // RECHERCHE STRICTE : Vendor + Name + Type OBLIGATOIRES
        $strictMatch = Product::query()
            ->where('vendor', 'LIKE', "%{$vendor}%")
            ->where('name', 'LIKE', "%{$name}%")
            ->where(function($q) use ($cleanType, $type) {
                // Chercher le type nettoyé OU le type original
                $q->where('type', 'LIKE', "%{$cleanType}%");
                if ($cleanType !== $type) {
                    $q->orWhere('type', 'LIKE', "%{$type}%");
                }
            })
            // Filtre STRICT pour coffret
            ->when($isCoffret, function($q) {
                // Si c'est un coffret, le produit DOIT contenir un mot-clé coffret
                $q->where(function($subQ) {
                    $subQ->where('name', 'LIKE', '%coffret%')
                         ->orWhere('type', 'LIKE', '%coffret%')
                         ->orWhere('name', 'LIKE', '%kit%')
                         ->orWhere('type', 'LIKE', '%kit%')
                         ->orWhere('name', 'LIKE', '%set%')
                         ->orWhere('type', 'LIKE', '%set%');
                });
            })
            ->when(!$isCoffret, function($q) {
                // Si ce n'est PAS un coffret, exclure TOUS les produits coffret
                $q->where('name', 'NOT LIKE', '%coffret%')
                  ->where('type', 'NOT LIKE', '%coffret%')
                  ->where('name', 'NOT LIKE', '%kit%')
                  ->where('type', 'NOT LIKE', '%kit%')
                  ->where('name', 'NOT LIKE', '%set%')
                  ->where('type', 'NOT LIKE', '%set%')
                  ->where('name', 'NOT LIKE', '%box%')
                  ->where('type', 'NOT LIKE', '%box%');
            })
            ->get();

        // Si on a des résultats, prioriser ceux qui matchent aussi la variation
        if ($strictMatch->isNotEmpty() && !empty($variation)) {
            // Extraire juste le nombre de la variation (ex: "105" de "105ml")
            preg_match('/(\d+)\s*(ml|g|oz)?/i', $variation, $matches);
            $variationNumber = $matches[1] ?? '';
            
            // Chercher d'abord les matchs exacts de variation
            $withExactVariation = $strictMatch->filter(function($product) use ($variation) {
                return stripos($product->variation, $variation) !== false;
            });
            
            if ($withExactVariation->isNotEmpty()) {
                $this->matchingProducts = $withExactVariation->values()->toArray();
                $this->bestMatch = $withExactVariation->first();
                return;
            }
            
            // Sinon, chercher le nombre de variation (plus flexible)
            if (!empty($variationNumber)) {
                $withSimilarVariation = $strictMatch->filter(function($product) use ($variationNumber) {
                    return stripos($product->variation, $variationNumber) !== false;
                });
                
                if ($withSimilarVariation->isNotEmpty()) {
                    $this->matchingProducts = $withSimilarVariation->values()->toArray();
                    $this->bestMatch = $withSimilarVariation->first();
                    return;
                }
            }
        }
        
        // Retourner tous les résultats qui matchent vendor + name + type
        if ($strictMatch->isNotEmpty()) {
            $this->matchingProducts = $strictMatch->toArray();
            $this->bestMatch = $strictMatch->first();
            return;
        }

        // SI AUCUN RÉSULTAT : Ne rien retourner (pas de fallback)
        $this->matchingProducts = [];
        $this->bestMatch = null;
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

    public function getProductsForList()
    {
        return collect($this->matchingProducts)->map(function($product) {
            // Détection si c'est un coffret pour l'affichage
            $isCoffret = false;
            $coffretKeywords = ['coffret', 'kit', 'set', 'box', 'trousse'];
            $searchText = strtolower($product['name'] . ' ' . $product['type']);
            
            foreach ($coffretKeywords as $keyword) {
                if (str_contains($searchText, $keyword)) {
                    $isCoffret = true;
                    break;
                }
            }
            
            $displayType = $product['type'] . ' | ' . $product['variation'];
            if ($isCoffret) {
                $displayType = '📦 ' . $displayType;
            }
            
            return (object)[
                'id' => $product['id'],
                'title' => $product['vendor'] . ' - ' . $product['name'],
                'username' => $displayType,
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
                    <span class="font-semibold">Vendor:</span> 
                    <span class="px-2 py-1 bg-blue-50 text-blue-700 rounded text-sm">{{ $extractedData['vendor'] ?? 'N/A' }}</span>
                </div>
                <div>
                    <span class="font-semibold">Name:</span> 
                    <span class="px-2 py-1 bg-blue-50 text-blue-700 rounded text-sm">{{ $extractedData['name'] ?? 'N/A' }}</span>
                </div>
                <div>
                    <span class="font-semibold">Type:</span> 
                    <span class="px-2 py-1 bg-blue-50 text-blue-700 rounded text-sm">{{ $extractedData['type'] ?? 'N/A' }}</span>
                </div>
                <div>
                    <span class="font-semibold">Variation:</span> 
                    <span class="px-2 py-1 bg-gray-100 text-gray-700 rounded text-sm">{{ $extractedData['variation'] ?? 'N/A' }}</span>
                </div>
                <div class="col-span-2">
                    <span class="font-semibold">Coffret:</span> 
                    @if($extractedData['is_coffret'] ?? false)
                        <span class="px-2 py-1 bg-purple-100 text-purple-700 rounded text-sm">📦 Oui</span>
                    @else
                        <span class="px-2 py-1 bg-gray-100 text-gray-700 rounded text-sm">Non</span>
                    @endif
                </div>
            </div>
            
            <div class="mt-3 p-3 bg-blue-50 border-l-4 border-blue-500 text-sm">
                <p class="font-semibold text-blue-900">Critères de recherche STRICTS :</p>
                <p class="text-blue-700">Vendor = "{{ $extractedData['vendor'] }}" ET Name = "{{ $extractedData['name'] }}" ET Type = "{{ $extractedData['type'] }}"</p>
            </div>
        </div>
    @endif

    @if($bestMatch)
        <div class="mt-6 p-4 bg-green-50 border-2 border-green-500 rounded">
            <h3 class="font-bold text-green-700 mb-3">✓ Meilleur résultat :</h3>
            @php
                $isCoffret = false;
                $coffretKeywords = ['coffret', 'kit', 'set', 'box', 'trousse'];
                $searchText = strtolower($bestMatch['name'] . ' ' . $bestMatch['type']);
                
                foreach ($coffretKeywords as $keyword) {
                    if (str_contains($searchText, $keyword)) {
                        $isCoffret = true;
                        break;
                    }
                }
                
                $displayType = $bestMatch['type'] . ' | ' . $bestMatch['variation'];
                if ($isCoffret) {
                    $displayType = '📦 ' . $displayType;
                }
                
                $bestMatchObj = (object)[
                    'id' => $bestMatch['id'],
                    'title' => $bestMatch['vendor'] . ' - ' . $bestMatch['name'],
                    'username' => $displayType,
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
            <p class="text-sm text-gray-600 mb-2">Tous ces produits ont le même vendor, name et type</p>
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
            <p class="text-yellow-800 font-semibold">❌ Aucun produit trouvé</p>
            <p class="text-yellow-700 text-sm mt-2">
                Aucun produit ne correspond exactement aux critères :
            </p>
            <ul class="text-yellow-700 text-sm mt-2 space-y-1">
                <li>• Vendor : <strong>{{ $extractedData['vendor'] }}</strong></li>
                <li>• Name : <strong>{{ $extractedData['name'] }}</strong></li>
                <li>• Type : <strong>{{ $extractedData['type'] }}</strong></li>
                <li>• Coffret : <strong>{{ $extractedData['is_coffret'] ? 'Oui' : 'Non' }}</strong></li>
            </ul>
        </div>
    @endif
</div>