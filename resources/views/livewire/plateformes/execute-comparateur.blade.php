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

        // Construire la requête FULLTEXT avec opérateurs booléens
        // + signifie que le mot DOIT être présent
        $searchQuery = "+{$vendor} +{$name} +{$cleanType}";
        
        // Ajouter "coffret" si c'est un coffret
        if ($isCoffret) {
            $searchQuery .= " +(coffret kit set)";
        }

        // Exécuter la recherche FULLTEXT
        $sql = "SELECT 
                    lp.*, 
                    ws.name as site_name, 
                    lp.url as product_url, 
                    lp.image_url as image
                FROM last_price_scraped_product lp
                LEFT JOIN web_site ws ON lp.web_site_id = ws.id
                WHERE MATCH (lp.name, lp.vendor, lp.type, lp.variation) 
                    AGAINST (? IN BOOLEAN MODE)
                AND (lp.variation != 'Standard' OR lp.variation IS NULL OR lp.variation = '')";

        // Ajouter le filtre pour exclure les coffrets si ce n'est pas un coffret
        if (!$isCoffret) {
            $sql .= " AND lp.name NOT LIKE '%coffret%' 
                     AND lp.type NOT LIKE '%coffret%'
                     AND lp.name NOT LIKE '%kit%'
                     AND lp.type NOT LIKE '%kit%'
                     AND lp.name NOT LIKE '%set%'
                     AND lp.type NOT LIKE '%set%'
                     AND lp.name NOT LIKE '%box%'
                     AND lp.type NOT LIKE '%box%'";
        } else {
            // Si c'est un coffret, forcer la présence du mot-clé
            $sql .= " AND (lp.name LIKE '%coffret%' 
                          OR lp.type LIKE '%coffret%'
                          OR lp.name LIKE '%kit%'
                          OR lp.type LIKE '%kit%'
                          OR lp.name LIKE '%set%'
                          OR lp.type LIKE '%set%')";
        }

        $results = DB::select($sql, [$searchQuery]);

        // Convertir les résultats en collection
        $collection = collect($results);

        if ($collection->isEmpty()) {
            $this->matchingProducts = [];
            $this->bestMatch = null;
            return;
        }

        // Filtrage supplémentaire pour s'assurer de la correspondance stricte
        $filtered = $collection->filter(function($product) use ($vendor, $name, $cleanType, $type) {
            $productVendor = strtolower($product->vendor ?? '');
            $productName = strtolower($product->name ?? '');
            $productType = strtolower($product->type ?? '');
            
            $vendorMatch = str_contains($productVendor, strtolower($vendor));
            $nameMatch = str_contains($productName, strtolower($name));
            $typeMatch = str_contains($productType, strtolower($cleanType)) || 
                        str_contains($productType, strtolower($type));
            
            return $vendorMatch && $nameMatch && $typeMatch;
        });

        if ($filtered->isEmpty()) {
            $this->matchingProducts = [];
            $this->bestMatch = null;
            return;
        }

        // Prioriser les résultats qui matchent aussi la variation
        if (!empty($variation)) {
            // Extraire le nombre de la variation
            preg_match('/(\d+)\s*(ml|g|oz)?/i', $variation, $matches);
            $variationNumber = $matches[1] ?? '';
            
            // Trier par correspondance de variation
            $sorted = $filtered->sortByDesc(function($product) use ($variation, $variationNumber) {
                $productVariation = strtolower($product->variation ?? '');
                
                // Match exact = priorité maximale
                if (str_contains($productVariation, strtolower($variation))) {
                    return 3;
                }
                
                // Match sur le nombre = priorité moyenne
                if (!empty($variationNumber) && str_contains($productVariation, $variationNumber)) {
                    return 2;
                }
                
                // Pas de match = priorité faible
                return 1;
            });
            
            $this->matchingProducts = $sorted->values()->map(function($item) {
                return (array) $item;
            })->toArray();
        } else {
            $this->matchingProducts = $filtered->values()->map(function($item) {
                return (array) $item;
            })->toArray();
        }

        $this->bestMatch = $this->matchingProducts[0] ?? null;
    }

    public function selectProduct($productId)
    {
        $result = DB::selectOne(
            "SELECT lp.*, ws.name as site_name, lp.url as product_url, lp.image_url as image
             FROM last_price_scraped_product lp
             LEFT JOIN web_site ws ON lp.web_site_id = ws.id
             WHERE lp.id = ?",
            [$productId]
        );
        
        if ($result) {
            session()->flash('success', 'Produit sélectionné : ' . $result->name);
            $this->bestMatch = (array) $result;
            $this->dispatch('product-selected', productId: $productId);
        }
    }

    public function getProductsForList()
    {
        return collect($this->matchingProducts)->map(function($product) {
            // Détection si c'est un coffret pour l'affichage
            $isCoffret = false;
            $coffretKeywords = ['coffret', 'kit', 'set', 'box', 'trousse'];
            $searchText = strtolower(($product['name'] ?? '') . ' ' . ($product['type'] ?? ''));
            
            foreach ($coffretKeywords as $keyword) {
                if (str_contains($searchText, $keyword)) {
                    $isCoffret = true;
                    break;
                }
            }
            
            $displayType = ($product['type'] ?? 'N/A') . ' | ' . ($product['variation'] ?? 'N/A');
            if ($isCoffret) {
                $displayType = '📦 ' . $displayType;
            }
            
            return (object)[
                'id' => $product['id'] ?? null,
                'title' => ($product['vendor'] ?? 'N/A') . ' - ' . ($product['name'] ?? 'N/A'),
                'username' => $displayType,
                'subtitle' => ($product['prix_ht'] ?? 'N/A') . ' ' . ($product['currency'] ?? ''),
                'avatar' => $product['image'] ?? $product['image_url'] ?? null,
                'url' => $product['product_url'] ?? $product['url'] ?? null,
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
                <p class="font-semibold text-blue-900">Requête FULLTEXT :</p>
                <p class="text-blue-700 font-mono">+{{ $extractedData['vendor'] }} +{{ $extractedData['name'] }} +{{ $extractedData['type'] }}</p>
            </div>
        </div>
    @endif

    @if($bestMatch)
        <div class="mt-6 p-4 bg-green-50 border-2 border-green-500 rounded">
            <h3 class="font-bold text-green-700 mb-3">✓ Meilleur résultat :</h3>
            @php
                $isCoffret = false;
                $coffretKeywords = ['coffret', 'kit', 'set', 'box', 'trousse'];
                $searchText = strtolower(($bestMatch['name'] ?? '') . ' ' . ($bestMatch['type'] ?? ''));
                
                foreach ($coffretKeywords as $keyword) {
                    if (str_contains($searchText, $keyword)) {
                        $isCoffret = true;
                        break;
                    }
                }
                
                $displayType = ($bestMatch['type'] ?? 'N/A') . ' | ' . ($bestMatch['variation'] ?? 'N/A');
                if ($isCoffret) {
                    $displayType = '📦 ' . $displayType;
                }
                
                $bestMatchObj = (object)[
                    'id' => $bestMatch['id'] ?? null,
                    'title' => ($bestMatch['vendor'] ?? 'N/A') . ' - ' . ($bestMatch['name'] ?? 'N/A'),
                    'username' => $displayType,
                    'subtitle' => ($bestMatch['prix_ht'] ?? 'N/A') . ' ' . ($bestMatch['currency'] ?? ''),
                    'avatar' => $bestMatch['image'] ?? $bestMatch['image_url'] ?? null,
                ];
            @endphp
            <x-list-item 
                :item="$bestMatchObj" 
                value="title"
                sub-value="username" 
                :link="$bestMatch['product_url'] ?? $bestMatch['url'] ?? '#'" 
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