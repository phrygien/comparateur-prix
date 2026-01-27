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
        session()->forget(['error', 'warning', 'success', 'info']);
        
        try {
            // Extraction avec prompt adapté (name court + mots clés pertinents)
            $extractionResponse = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Expert extraction cosmétiques. Retourne UNIQUEMENT JSON : vendor (marque), name (nom principal court, 2-5 mots max), type (type principal). Ne pas inclure la variation.'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Extrait du nom: {$this->productName}\n\nExemple: {\"vendor\":\"Dior\",\"name\":\"Capture Totale Crème Nuit\",\"type\":\"Crème de nuit\"}"
                    ]
                ],
                'temperature' => 0.2,
                'max_tokens' => 250
            ]);

            if (!$extractionResponse->successful()) {
                throw new \Exception('Erreur API extraction');
            }

            $content = trim(preg_replace('/```json\s*|\s*```/', '', $extractionResponse->json()['choices'][0]['message']['content']));
            $this->extractedData = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE || empty($this->extractedData['vendor']) || empty($this->extractedData['name'])) {
                $this->extractedData = $this->basicExtractionFallback($this->productName);
            }

            $this->searchMatchingProducts();
            
        } catch (\Exception $e) {
            \Log::error('Erreur extraction', ['msg' => $e->getMessage()]);
            session()->flash('error', 'Erreur lors de l\'extraction');
        } finally {
            $this->isLoading = false;
        }
    }

    private function basicExtractionFallback(string $productName): array
    {
        $known = ['Dior', 'Chanel', 'Lancôme', 'Guerlain', 'YSL', 'Azzaro'];
        foreach ($known as $v) {
            if (stripos($productName, $v) !== false) {
                return [
                    'vendor' => $v,
                    'name'   => trim(str_ireplace($v, '', $productName)),
                    'type'   => ''
                ];
            }
        }
        return ['vendor' => '', 'name' => $productName, 'type' => ''];
    }

    public function searchMatchingProducts()
    {
        if (!$this->extractedData || empty($this->extractedData['vendor']) || empty($this->extractedData['name'])) {
            session()->flash('warning', 'Marque et nom requis pour la recherche');
            return;
        }

        $vendor = trim($this->extractedData['vendor']);
        $name   = trim($this->extractedData['name']);
        $type   = trim($this->extractedData['type'] ?? '');

        $vendor_clean = preg_replace('/[^a-zA-Z0-9\s]/', '', strtolower($vendor));
        $name_clean   = preg_replace('/[^a-zA-Z0-9\s]/', '', strtolower($name));
        $type_clean   = preg_replace('/[^a-zA-Z0-9\s]/', '', strtolower($type));

        // Mots clés du name (on prend les plus significatifs)
        $name_words = array_filter(explode(' ', $name_clean), fn($w) => strlen($w) > 2);
        $search_words = array_slice($name_words, 0, 4); // max 4 mots pour éviter trop de bruit

        // Recherche SQL large
        $query = "
            SELECT id, vendor, name, type, variation, prix_ht, url, image_url
            FROM last_price_scraped_product
            WHERE LOWER(vendor) LIKE ?
        ";
        $bindings = ["%$vendor_clean%"];

        if (!empty($search_words)) {
            $query .= " AND (";
            $conditions = [];
            foreach ($search_words as $word) {
                $conditions[] = "LOWER(name) LIKE ?";
                $bindings[] = "%$word%";
            }
            foreach ($search_words as $word) {
                $conditions[] = "LOWER(type) LIKE ?";
                $bindings[] = "%$word%";
            }
            $query .= implode(' OR ', $conditions) . ")";
        }

        $query .= " ORDER BY prix_ht ASC LIMIT 30";

        $results = DB::select($query, $bindings);
        $results = array_map(fn($r) => (array)$r, $results);

        // Filtre PHP souple : vendor + au moins 2 mots du name ou dans type
        $this->matchingProducts = array_filter($results, function ($p) use ($vendor_clean, $search_words) {
            $pVendor = strtolower($p['vendor'] ?? '');
            $pName   = strtolower($p['name'] ?? '');
            $pType   = strtolower($p['type'] ?? '');

            // Vendor doit matcher
            if (!str_contains($pVendor, $vendor_clean) && !str_contains($vendor_clean, $pVendor)) {
                return false;
            }

            // Au moins 2 mots du name doivent être présents dans name OU type
            $matchCount = 0;
            foreach ($search_words as $word) {
                if (str_contains($pName, $word) || str_contains($pType, $word)) {
                    $matchCount++;
                }
            }

            return $matchCount >= 2 || count($search_words) <= 2;
        });

        if (empty($this->matchingProducts)) {
            session()->flash('warning', 'Aucun produit trouvé avec la marque + au moins 2 mots du nom.');
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
        <h2 class="text-xl font-bold mb-2">Recherche produits similaires</h2>
        <p class="text-gray-600">Produit analysé : {{ $productName }}</p>
    </div>

    <button 
        wire:click="extractSearchTerme"
        wire:loading.attr="disabled"
        class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600 disabled:opacity-50"
    >
        <span wire:loading.remove>Rechercher produits proches</span>
        <span wire:loading>Recherche...</span>
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
            <h3 class="font-bold mb-3">Critères utilisés :</h3>
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div><span class="font-semibold">Marque :</span> {{ $extractedData['vendor'] ?? '—' }}</div>
                <div><span class="font-semibold">Nom principal :</span> {{ $extractedData['name'] ?? '—' }}</div>
                <div><span class="font-semibold">Type :</span> {{ $extractedData['type'] ?? '—' }}</div>
            </div>
        </div>
    @endif

    @if(!empty($matchingProducts))
        <div class="mt-8">
            <h3 class="font-bold text-lg mb-4">Produits trouvés ({{ count($matchingProducts) }})</h3>
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
                                <p class="font-bold text-lg">
                                    {{ is_numeric($product['prix_ht'] ?? null) 
                                        ? number_format((float)$product['prix_ht'], 2, ',', ' ') 
                                        : ($product['prix_ht'] ?? '—') }} €
                                </p>
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
            Aucun produit trouvé avec la marque + au moins 2 mots du nom (dans name ou type).
        </div>
    @endif
</div>