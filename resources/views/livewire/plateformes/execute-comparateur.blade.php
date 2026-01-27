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
- vendor : la marque du produit
- name : le nom exact de la gamme/ligne de produit (SANS le mot coffret, SANS vaporisateur, SANS spray)
- variation : la contenance/taille complète (ex: \"105ml\", \"100 ml\", \"50ml\")
- type : le type exact de produit (ex: \"Eau de Parfum\", \"Eau de Toilette\", \"Crème\") (SANS vaporisateur, SANS spray, SANS coffret)
- is_coffret : true si le produit est un coffret/kit/set, false sinon

IMPORTANT:
- Enlève \"Vaporisateur\", \"Spray\", \"Atomiseur\" du type
- Le type doit être court et précis (Eau de Parfum, Eau de Toilette, Crème, Sérum, etc.)
- La variation doit contenir uniquement la contenance avec l'unité

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

        $query = Product::query();

        // 1. PRIORITÉ MAXIMALE : Vendor + Name + Type (exact match)
        $vendorNameType = (clone $query)
            ->where('vendor', 'LIKE', "%{$vendor}%")
            ->where('name', 'LIKE', "%{$name}%")
            ->where(function($q) use ($cleanType, $type) {
                // Chercher le type nettoyé OU le type original
                $q->where('type', 'LIKE', "%{$cleanType}%");
                if ($cleanType !== $type) {
                    $q->orWhere('type', 'LIKE', "%{$type}%");
                }
            })
            ->when($isCoffret, function($q) {
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
                $q->where('name', 'NOT LIKE', '%coffret%')
                  ->where('type', 'NOT LIKE', '%coffret%')
                  ->where('name', 'NOT LIKE', '%kit%')
                  ->where('type', 'NOT LIKE', '%kit%')
                  ->where('name', 'NOT LIKE', '%set%')
                  ->where('type', 'NOT LIKE', '%set%');
            })
            ->get();

        if ($vendorNameType->isNotEmpty()) {
            // Si on a plusieurs résultats, prioriser ceux qui matchent aussi la variation
            if (!empty($variation)) {
                $withVariation = $vendorNameType->filter(function($product) use ($variation) {
                    return stripos($product->variation, $variation) !== false;
                });
                
                if ($withVariation->isNotEmpty()) {
                    $this->matchingProducts = $withVariation->values()->toArray();
                    $this->bestMatch = $withVariation->first();
                    return;
                }
            }
            
            $this->matchingProducts = $vendorNameType->toArray();
            $this->bestMatch = $vendorNameType->first();
            return;
        }

        // 2. Vendor + Name + Type + Variation (tous les critères)
        if (!empty($variation)) {
            $exactMatch = (clone $query)
                ->where('vendor', 'LIKE', "%{$vendor}%")
                ->where('name', 'LIKE', "%{$name}%")
                ->where('variation', 'LIKE', "%{$variation}%")
                ->where(function($q) use ($cleanType, $type) {
                    $q->where('type', 'LIKE', "%{$cleanType}%");
                    if ($cleanType !== $type) {
                        $q->orWhere('type', 'LIKE', "%{$type}%");
                    }
                })
                ->when($isCoffret, function($q) {
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
                    $q->where('name', 'NOT LIKE', '%coffret%')
                      ->where('type', 'NOT LIKE', '%coffret%')
                      ->where('name', 'NOT LIKE', '%kit%')
                      ->where('type', 'NOT LIKE', '%kit%')
                      ->where('name', 'NOT LIKE', '%set%')
                      ->where('type', 'NOT LIKE', '%set%');
                })
                ->get();

            if ($exactMatch->isNotEmpty()) {
                $this->matchingProducts = $exactMatch->toArray();
                $this->bestMatch = $exactMatch->first();
                return;
            }
        }

        // 3. Vendor + Name (sans type)
        $vendorAndName = (clone $query)
            ->where('vendor', 'LIKE', "%{$vendor}%")
            ->where('name', 'LIKE', "%{$name}%")
            ->when($isCoffret, function($q) {
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
                $q->where('name', 'NOT LIKE', '%coffret%')
                  ->where('type', 'NOT LIKE', '%coffret%')
                  ->where('name', 'NOT LIKE', '%kit%')
                  ->where('type', 'NOT LIKE', '%kit%')
                  ->where('name', 'NOT LIKE', '%set%')
                  ->where('type', 'NOT LIKE', '%set%');
            })
            ->get();

        if ($vendorAndName->isNotEmpty()) {
            $this->matchingProducts = $vendorAndName->toArray();
            $this->bestMatch = $vendorAndName->first();
            return;
        }

        // 4. Recherche spécifique coffret si is_coffret = true
        if ($isCoffret) {
            $coffretSearch = (clone $query)
                ->where('vendor', 'LIKE', "%{$vendor}%")
                ->where(function($q) use ($name) {
                    $q->where('name', 'LIKE', "%{$name}%")
                      ->orWhere('name', 'LIKE', "%{$name}%coffret%")
                      ->orWhere('name', 'LIKE', "%coffret%{$name}%");
                })
                ->where(function($q) {
                    $q->where('name', 'LIKE', '%coffret%')
                      ->orWhere('type', 'LIKE', '%coffret%')
                      ->orWhere('name', 'LIKE', '%kit%')
                      ->orWhere('type', 'LIKE', '%kit%')
                      ->orWhere('name', 'LIKE', '%set%')
                      ->orWhere('type', 'LIKE', '%set%');
                })
                ->get();

            if ($coffretSearch->isNotEmpty()) {
                $this->matchingProducts = $coffretSearch->toArray();
                $this->bestMatch = $coffretSearch->first();
                return;
            }
        }

        // 5. Full-text search avec filtre coffret
        if (method_exists(Product::class, 'scopeFullTextSearch')) {
            $searchQuery = trim("{$vendor} {$name} {$cleanType} {$variation}");
            if ($isCoffret) {
                $searchQuery .= " coffret";
            }
            
            $fullTextResults = Product::fullTextSearch($searchQuery)
                ->limit(20)
                ->get()
                ->filter(function($product) use ($isCoffret) {
                    $coffretKeywords = ['coffret', 'kit', 'set', 'box', 'trousse'];
                    $searchText = strtolower($product->name . ' ' . $product->type);
                    $productIsCoffret = false;
                    
                    foreach ($coffretKeywords as $keyword) {
                        if (str_contains($searchText, $keyword)) {
                            $productIsCoffret = true;
                            break;
                        }
                    }
                    
                    return $productIsCoffret === $isCoffret;
                });

            if ($fullTextResults->isNotEmpty()) {
                $this->matchingProducts = $fullTextResults->values()->toArray();
                $this->bestMatch = $fullTextResults->first();
                return;
            }
        }

        // 6. Recherche très flexible (dernier recours)
        $flexible = Product::where(function($q) use ($vendor, $name) {
            $q->where('vendor', 'LIKE', "%{$vendor}%")
              ->where('name', 'LIKE', "%{$name}%");
        })
        ->when($isCoffret, function($q) {
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
            $q->where('name', 'NOT LIKE', '%coffret%')
              ->where('type', 'NOT LIKE', '%coffret%')
              ->where('name', 'NOT LIKE', '%kit%')
              ->where('type', 'NOT LIKE', '%kit%')
              ->where('name', 'NOT LIKE', '%set%')
              ->where('type', 'NOT LIKE', '%set%');
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
                <div class="col-span-2">
                    <span class="font-semibold">Coffret:</span> 
                    @if($extractedData['is_coffret'] ?? false)
                        <span class="px-2 py-1 bg-purple-100 text-purple-700 rounded text-sm">📦 Oui</span>
                    @else
                        <span class="px-2 py-1 bg-gray-100 text-gray-700 rounded text-sm">Non</span>
                    @endif
                </div>
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