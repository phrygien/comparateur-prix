<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

new class extends Component {
    
    public string $productName = '';
    public $productPrice;
    public $productId;
    public $extractedData = null;
    public $isLoading = false;
    public $matchingProducts = [];
    public $bestMatch = null;
    public $aiCorrection = null;
    public float $bestMatchScore = 0.0;

    public function mount($name, $id, $price): void
    {
        $this->productName = $name;
        $this->productId = $id;
        $this->productPrice = $price;
    }

    public function extractSearchTerme()
    {
        $this->isLoading = true;
        $this->matchingProducts = [];
        $this->bestMatch = null;
        $this->aiCorrection = null;
        $this->bestMatchScore = 0.0;
        session()->forget(['error', 'warning', 'success', 'info']);
        
        try {
            // Étape 1 : Extraction AI
            $extractionResponse = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Expert extraction produits cosmétiques/parfums. Extrait vendor (marque), name (nom gamme/parfum), variation (taille), type (ex: Eau de Toilette, Déodorant). Réponds UNIQUEMENT JSON valide.'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Extrait du nom: {$this->productName}\n\nFormat:\n{\"vendor\":\"Marque\",\"name\":\"Nom\",\"variation\":\"100 ml\",\"type\":\"Eau de Toilette\"}"
                    ]
                ],
                'temperature' => 0.1,
                'max_tokens' => 300
            ]);

            if (!$extractionResponse->successful()) {
                throw new \Exception('Erreur OpenAI extraction');
            }

            $content = trim(preg_replace('/```json\s*|\s*```/', '', $extractionResponse->json()['choices'][0]['message']['content']));
            $this->extractedData = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE || empty($this->extractedData['vendor']) || empty($this->extractedData['name'])) {
                $this->extractedData = $this->basicExtractionFallback($this->productName);
            }

            // Étape 2 : Recherche concurrents
            $this->searchMatchingProducts();
            
        } catch (\Exception $e) {
            \Log::error('Erreur extraction', ['message' => $e->getMessage()]);
            session()->flash('error', 'Erreur : ' . $e->getMessage());
        } finally {
            $this->isLoading = false;
        }
    }

    private function basicExtractionFallback(string $productName): array
    {
        $knownVendors = ['Azzaro', 'Dior', 'Guerlain', 'Shiseido', 'Chanel'];
        foreach ($knownVendors as $v) {
            if (Str::contains(strtoupper($productName), strtoupper($v))) {
                return [
                    'vendor' => $v,
                    'name' => trim(str_replace($v, '', $productName)),
                    'variation' => '',
                    'type' => ''
                ];
            }
        }
        return ['vendor' => '', 'name' => $productName, 'variation' => '', 'type' => ''];
    }

    public function searchMatchingProducts()
    {
        if (!$this->extractedData || empty($this->extractedData['vendor']) || empty($this->extractedData['name'])) {
            session()->flash('warning', 'Vendor et Name requis pour la recherche.');
            return;
        }

        $vendor    = trim($this->extractedData['vendor']);
        $name      = trim($this->extractedData['name']);
        $type      = trim($this->extractedData['type'] ?? '');
        $variation = trim($this->extractedData['variation'] ?? '');

        // Nettoyage pour FULLTEXT (éviter caractères spéciaux)
        $vendor_clean  = preg_replace('/[^a-zA-Z0-9\s]/', '', $vendor);
        $name_clean    = preg_replace('/[^a-zA-Z0-9\s]/', '', $name);

        // FULLTEXT query en mode BOOLEAN
        $fulltext_terms = [];
        if ($vendor_clean)  $fulltext_terms[] = '+' . $vendor_clean;
        if ($name_clean)    $fulltext_terms[] = '+' . $name_clean;
        $fulltext_query = implode(' ', $fulltext_terms);

        // Extraction du nombre pour la variation (ex: "100 ml" → "100")
        $variation_number = preg_replace('/[^0-9]/', '', $variation) ?: '';
        $variation_like   = $variation_number ?: $variation;

        // Requête concurrents
        $results = DB::select("
            SELECT 
                id, vendor, name, type, variation, prix_ht, url, image_url,
                MATCH(name, vendor, type, variation) AGAINST (? IN BOOLEAN MODE) AS relevance_score
            FROM last_price_scraped_product
            WHERE 
                (
                    MATCH(name, vendor, type, variation) AGAINST (? IN BOOLEAN MODE) > 0.1
                    OR LOWER(name)   LIKE ?
                    OR LOWER(vendor) LIKE ?
                    OR LOWER(type)   LIKE ?
                )
                " . ($variation_number ? "AND (
                    variation REGEXP ? 
                    OR variation LIKE ?
                    OR name      LIKE ?
                    OR type      LIKE ?
                )" : "") . "
            ORDER BY relevance_score DESC, prix_ht ASC
            LIMIT 15
        ", array_filter([
            $fulltext_query,
            $fulltext_query,
            "%$name_clean%",
            "%$vendor_clean%",
            "%$name_clean%",
            $variation_number ? "[[:<:]]$variation_number" : null,
            $variation_number ? "%$variation_like%" : null,
            $variation_number ? "%$variation_like%" : null,
            $variation_number ? "%$variation_like%" : null,
        ]));

        $this->matchingProducts = array_map(fn($r) => (array) $r, $results);

        if (!empty($this->matchingProducts)) {
            // Meilleur match = premier résultat avec meilleur score
            $this->bestMatch = $this->matchingProducts[0];
            $this->bestMatchScore = $this->bestMatch['relevance_score'] ?? 0;

            if ($this->bestMatchScore < 0.2) {
                session()->flash('warning', 'Correspondance faible. Vérifiez les critères.');
            }
        } else {
            session()->flash('error', 'Aucun concurrent trouvé.');
        }
    }

    public function selectProduct($productId)
    {
        $product = DB::table('last_price_scraped_product')->find($productId);
        if ($product) {
            $this->bestMatch = (array) $product;
            session()->flash('success', 'Produit sélectionné : ' . $product->name);
            $this->dispatch('product-selected', productId: $productId);
        }
    }

}; ?>

<div class="p-6 bg-white rounded-lg shadow">
    <div class="mb-4">
        <h2 class="text-xl font-bold mb-2">Recherche concurrents</h2>
        <p class="text-gray-600">Produit : {{ $productName }}</p>
    </div>

    <button 
        wire:click="extractSearchTerme"
        wire:loading.attr="disabled"
        class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600 disabled:opacity-50"
    >
        <span wire:loading.remove>Rechercher concurrents</span>
        <span wire:loading>Recherche...</span>
    </button>

    @foreach(['error' => 'red', 'success' => 'green', 'warning' => 'yellow', 'info' => 'blue'] as $key => $color)
        @if(session($key))
            <div class="mt-4 p-4 bg-{{ $color }}-100 text-{{ $color }}-700 rounded border border-{{ $color }}-200">
                {{ session($key) }}
            </div>
        @endif
    @endforeach

    @if($extractedData)
        <div class="mt-6 p-4 bg-gray-50 rounded">
            <h3 class="font-bold mb-3">Critères utilisés :</h3>
            <div class="grid grid-cols-2 gap-4">
                <div><span class="font-semibold">Vendor:</span> {{ $extractedData['vendor'] ?? '—' }}</div>
                <div><span class="font-semibold">Name:</span> {{ $extractedData['name'] ?? '—' }}</div>
                <div><span class="font-semibold">Type:</span> {{ $extractedData['type'] ?? '—' }}</div>
                <div><span class="font-semibold">Variation:</span> {{ $extractedData['variation'] ?? '—' }}</div>
            </div>
        </div>
    @endif

    @if($bestMatch)
        <div class="mt-6 p-4 bg-green-50 border-2 border-green-500 rounded">
            <h3 class="font-bold text-green-700 mb-3">Meilleur concurrent trouvé (score : {{ round($bestMatchScore, 2) }})</h3>
            <div class="flex items-start gap-4">
                @if($bestMatch['image_url'] ?? false)
                    <img src="{{ $bestMatch['image_url'] }}" alt="{{ $bestMatch['name'] }}" class="w-20 h-20 object-cover rounded">
                @endif
                <div class="flex-1">
                    <p class="font-semibold">{{ $bestMatch['vendor'] }} — {{ $bestMatch['name'] }}</p>
                    <p class="text-sm text-gray-600">{{ $bestMatch['type'] }} | {{ $bestMatch['variation'] }}</p>
                    <p class="text-sm font-bold text-green-600 mt-1">{{ $bestMatch['prix_ht'] ?? '?' }} €</p>
                    <a href="{{ $bestMatch['url'] ?? '#' }}" target="_blank" class="text-xs text-blue-500">Voir</a>
                </div>
            </div>
        </div>
    @endif

    @if(!empty($matchingProducts))
        <div class="mt-6">
            <h3 class="font-bold mb-3">Autres concurrents ({{ count($matchingProducts) }})</h3>
            <div class="space-y-3 max-h-96 overflow-y-auto">
                @foreach($matchingProducts as $product)
                    <div wire:click="selectProduct({{ $product['id'] }})" 
                         class="p-3 border rounded cursor-pointer hover:bg-blue-50 transition">
                        <div class="flex justify-between items-center">
                            <div class="flex items-center gap-3">
                                @if($product['image_url'] ?? false)
                                    <img src="{{ $product['image_url'] }}" alt="" class="w-10 h-10 object-cover rounded">
                                @endif
                                <div>
                                    <p class="font-medium">{{ $product['vendor'] }} - {{ $product['name'] }}</p>
                                    <p class="text-xs text-gray-600">{{ $product['type'] ?? '' }} • {{ $product['variation'] ?? '' }}</p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="font-bold">{{ $product['prix_ht'] ?? '?' }} €</p>
                                <p class="text-xs text-gray-500">Score : {{ round($product['relevance_score'] ?? 0, 2) }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>