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
    public $aiCorrection = null;

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
        $this->aiCorrection = null;
        session()->forget(['error', 'warning', 'success', 'info']);
        
        try {
            // Étape 1 : Extraction AI (simplifiée ici pour brevité)
            $extractionResponse = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => 'Expert extraction produits. Extrait vendor, name, variation, type. JSON uniquement.'],
                    ['role' => 'user', 'content' => "Extrait: {$this->productName}"]
                ],
                'temperature' => 0.1,
                'max_tokens' => 300
            ]);

            if (!$extractionResponse->successful()) {
                throw new \Exception('Erreur API');
            }

            $content = trim(preg_replace('/```json\s*|\s*```/', '', $extractionResponse->json()['choices'][0]['message']['content']));
            $this->extractedData = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE || empty($this->extractedData['vendor']) || empty($this->extractedData['name'])) {
                $this->extractedData = $this->basicExtractionFallback($this->productName);
            }

            $this->searchMatchingProducts();
            
        } catch (\Exception $e) {
            \Log::error('Erreur', ['msg' => $e->getMessage()]);
            session()->flash('error', 'Erreur lors de l\'extraction');
        } finally {
            $this->isLoading = false;
        }
    }

    private function basicExtractionFallback(string $productName): array
    {
        $known = ['Azzaro', 'Dior', 'Guerlain', 'Chanel', 'Yves Saint Laurent'];
        foreach ($known as $v) {
            if (stripos($productName, $v) !== false) {
                return ['vendor' => $v, 'name' => trim(str_ireplace($v, '', $productName)), 'variation' => '', 'type' => ''];
            }
        }
        return ['vendor' => '', 'name' => $productName, 'variation' => '', 'type' => ''];
    }

    public function searchMatchingProducts()
    {
        if (!$this->extractedData || empty($this->extractedData['vendor']) || empty($this->extractedData['name'])) {
            session()->flash('warning', 'Marque et nom du produit requis');
            return;
        }

        $vendor    = trim($this->extractedData['vendor']);
        $name      = trim($this->extractedData['name']);
        $variation = trim($this->extractedData['variation'] ?? '');

        $vendor_clean = preg_replace('/[^a-zA-Z0-9\s]/', '', $vendor);
        $name_clean   = preg_replace('/[^a-zA-Z0-9\s]/', '', $name);

        // FULLTEXT en mode BOOLEAN : on force vendor + name
        $fulltext_query = "+{$vendor_clean} +{$name_clean}";

        // Pour la variation (optionnel)
        $variation_number = preg_replace('/[^0-9]/', '', $variation);
        $variation_like   = $variation_number ?: $variation;

        $query = "
            SELECT 
                id, vendor, name, type, variation, prix_ht, url, image_url
            FROM last_price_scraped_product
            WHERE 
                MATCH(name, vendor, type, variation) AGAINST (? IN BOOLEAN MODE)
                OR (
                    LOWER(vendor) LIKE ? 
                    AND LOWER(name) LIKE ?
                )
        ";

        $bindings = [
            $fulltext_query,
            "%$vendor_clean%",
            "%$name_clean%"
        ];

        // Ajout filtre variation si présent
        if ($variation_number || $variation_like) {
            $query .= " AND (
                variation REGEXP ? 
                OR variation LIKE ? 
                OR name LIKE ? 
                OR type LIKE ?
            )";
            $bindings[] = "[[:<:]]$variation_number";
            $bindings[] = "%$variation_like%";
            $bindings[] = "%$variation_like%";
            $bindings[] = "%$variation_like%";
        }

        $query .= " ORDER BY prix_ht ASC LIMIT 20";

        $results = DB::select($query, $bindings);

        // Filtrage supplémentaire côté PHP pour être très strict sur vendor + name
        $this->matchingProducts = array_filter($results, function ($row) use ($vendor_clean, $name_clean) {
            $rowVendor = strtolower($row->vendor ?? '');
            $rowName   = strtolower($row->name ?? '');

            // Doit contenir au minimum une partie du vendor ET du name
            return 
                (str_contains($rowVendor, strtolower($vendor_clean)) || str_contains(strtolower($vendor_clean), $rowVendor)) &&
                (str_contains($rowName, strtolower($name_clean))   || str_contains(strtolower($name_clean), $rowName));
        });

        $this->matchingProducts = array_map(fn($r) => (array) $r, $this->matchingProducts);

        if (empty($this->matchingProducts)) {
            session()->flash('warning', 'Aucun produit concurrent trouvé avec correspondance sur marque ET nom');
        }
    }

    public function selectProduct($productId)
    {
        $product = DB::table('last_price_scraped_product')->find($productId);
        if ($product) {
            session()->flash('success', 'Produit sélectionné : ' . $product->name);
            $this->dispatch('product-selected', productId: $productId);
        }
    }

}; ?>

<div class="p-6 bg-white rounded-lg shadow">
    <div class="mb-4">
        <h2 class="text-xl font-bold mb-2">Recherche de concurrents</h2>
        <p class="text-gray-600">Produit analysé : {{ $productName }}</p>
    </div>

    <button 
        wire:click="extractSearchTerme"
        wire:loading.attr="disabled"
        class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600 disabled:opacity-50"
    >
        <span wire:loading.remove>Rechercher concurrents</span>
        <span wire:loading>Recherche en cours...</span>
    </button>

    @foreach(['error' => 'red', 'success' => 'green', 'warning' => 'yellow'] as $key => $color)
        @if(session($key))
            <div class="mt-4 p-4 bg-{{ $color }}-100 text-{{ $color }}-700 rounded border border-{{ $color }}-200">
                {{ session($key) }}
            </div>
        @endif
    @endforeach

    @if($extractedData)
        <div class="mt-6 p-4 bg-gray-50 rounded">
            <h3 class="font-bold mb-3">Critères de recherche :</h3>
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div><span class="font-semibold">Marque :</span> {{ $extractedData['vendor'] ?? '—' }}</div>
                <div><span class="font-semibold">Nom :</span> {{ $extractedData['name'] ?? '—' }}</div>
                <div><span class="font-semibold">Type :</span> {{ $extractedData['type'] ?? '—' }}</div>
                <div><span class="font-semibold">Contenance :</span> {{ $extractedData['variation'] ?? '—' }}</div>
            </div>
        </div>
    @endif

    @if(!empty($matchingProducts))
        <div class="mt-8">
            <h3 class="font-bold text-lg mb-4">Produits concurrents trouvés ({{ count($matchingProducts) }})</h3>
            <div class="space-y-3 max-h-[500px] overflow-y-auto pr-2">
                @foreach($matchingProducts as $product)
                    <div wire:click="selectProduct({{ $product['id'] }})"
                         class="p-4 border rounded-lg hover:bg-blue-50 cursor-pointer transition">
                        <div class="flex justify-between items-start">
                            <div class="flex items-center gap-4 flex-1">
                                @if($product['image_url'] ?? false)
                                    <img src="{{ $product['image_url'] }}" alt="" class="w-16 h-16 object-cover rounded">
                                @endif
                                <div>
                                    <p class="font-semibold">{{ $product['vendor'] }} — {{ $product['name'] }}</p>
                                    <p class="text-sm text-gray-600 mt-1">
                                        {{ $product['type'] ?? '—' }} • {{ $product['variation'] ?? '—' }}
                                    </p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="font-bold text-lg">{{ number_format($product['prix_ht'] ?? 0, 2) }} €</p>
                                @if($product['url'] ?? false)
                                    <a href="{{ $product['url'] }}" target="_blank" class="text-xs text-blue-600 hover:underline">Voir</a>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @elseif($extractedData && !$isLoading)
        <div class="mt-8 p-6 bg-gray-50 rounded-lg text-center text-gray-600">
            Aucun concurrent trouvé avec correspondance sur **marque** et **nom** du produit.
        </div>
    @endif
</div>