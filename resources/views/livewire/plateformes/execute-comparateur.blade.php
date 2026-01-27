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
            // Étape 1 : Extraction AI
            $extractionResponse = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Tu es un expert en extraction produits cosmétiques. Retourne UNIQUEMENT un JSON valide avec : vendor (marque), name (nom principal court, 1-4 mots), variation (taille), type (type principal).'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Extrait du nom: {$this->productName}\n\nExemple: {\"vendor\":\"Dior\",\"name\":\"Capture Crème Nuit\",\"variation\":\"50 ml\",\"type\":\"Crème Nuit\"}"
                    ]
                ],
                'temperature' => 0.1,
                'max_tokens' => 300
            ]);

            if (!$extractionResponse->successful()) {
                throw new \Exception('Erreur API OpenAI extraction');
            }

            $content = trim(preg_replace('/```json\s*|\s*```/', '', $extractionResponse->json()['choices'][0]['message']['content']));
            $this->extractedData = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE || empty($this->extractedData['vendor']) || empty($this->extractedData['name'])) {
                $this->extractedData = $this->basicExtractionFallback($this->productName);
                \Log::warning('Fallback extraction utilisée', ['product' => $this->productName]);
            }

            // Étape 2 : Vérification et correction AI (prompt amélioré)
            $verificationResponse = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Expert cosmétiques. Vérifie cohérence vendor/name/type. Réponds JSON: {"is_correct": bool, "confidence": "high/medium/low", "explanation": "court", "correction": {vendor/name/type si besoin, sinon {}}}.'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Vérifie: Original: {$this->productName} Extrait: vendor={$this->extractedData['vendor']}, name={$this->extractedData['name']}, type={$this->extractedData['type']}"
                    ]
                ],
                'temperature' => 0.1,
                'max_tokens' => 400
            ]);

            if ($verificationResponse->successful()) {
                $verificationContent = trim(preg_replace('/```json\s*|\s*```/', '', $verificationResponse->json()['choices'][0]['message']['content']));
                $this->aiCorrection = json_decode($verificationContent, true);
                
                if (!$this->aiCorrection['is_correct'] && !empty($this->aiCorrection['correction'])) {
                    $this->extractedData = array_merge($this->extractedData, array_filter($this->aiCorrection['correction']));
                    session()->flash('info', 'Correction appliquée: ' . $this->aiCorrection['explanation']);
                }
            }

            // Recherche
            $this->searchMatchingProducts();
            
        } catch (\Exception $e) {
            \Log::error('Erreur extraction globale', ['message' => $e->getMessage()]);
            session()->flash('error', 'Erreur extraction: ' . $e->getMessage());
        } finally {
            $this->isLoading = false;
        }
    }

    private function basicExtractionFallback(string $productName): array
    {
        $knownVendors = ['Azzaro', 'Dior', 'Shiseido', 'Guerlain'];
        foreach ($knownVendors as $vendor) {
            if (Str::contains(strtoupper($productName), strtoupper($vendor))) {
                return [
                    'vendor' => $vendor,
                    'name' => trim(str_replace($vendor, '', $productName)),
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
            session()->flash('warning', 'Champs manquants pour recherche.');
            return;
        }

        $vendor = trim($this->extractedData['vendor']);
        $name = trim($this->extractedData['name']);
        $type = trim($this->extractedData['type'] ?? '');
        $variation = trim($this->extractedData['variation'] ?? '');

        $vendor_clean = preg_replace('/[^a-zA-Z0-9\s]/', '', strtolower($vendor));
        $name_clean = preg_replace('/[^a-zA-Z0-9\s]/', '', strtolower($name));
        $type_clean = preg_replace('/[^a-zA-Z0-9\s]/', '', strtolower($type));
        $variation_clean = preg_replace('/[^a-zA-Z0-9\s]/', '', strtolower($variation));

        // Recherche large pour candidats
        $candidates = DB::select("
            SELECT id, vendor, name, type, variation, prix_ht, url, image_url
            FROM last_price_scraped_product
            WHERE LOWER(vendor) LIKE ?
              OR LOWER(name) LIKE ?
              OR LOWER(type) LIKE ?
            ORDER BY prix_ht ASC
            LIMIT 50
        ", [
            "%$vendor_clean%",
            "%$name_clean%",
            "%$type_clean%"
        ]);

        $candidates = array_map(fn($c) => (array)$c, $candidates);

        // Calcul de similarité (proche mais pas identique)
        $scored = [];
        foreach ($candidates as $cand) {
            $cand_vendor = strtolower($cand['vendor'] ?? '');
            $cand_name = strtolower($cand['name'] ?? '');
            $cand_type = strtolower($cand['type'] ?? '');
            $cand_variation = strtolower($cand['variation'] ?? '');

            $vendor_sim = Str::similarity($vendor_clean, $cand_vendor);
            $name_sim = Str::similarity($name_clean, $cand_name);
            $type_sim = Str::similarity($type_clean, $cand_type);
            $variation_sim = Str::similarity($variation_clean, $cand_variation);

            $score = ($vendor_sim * 0.3) + ($name_sim * 0.4) + ($type_sim * 0.2) + ($variation_sim * 0.1);

            if ($score >= 0.95 || $score < 0.5) continue; // Skip trop identique ou trop différent

            $cand['is_coffret'] = $this->isCoffret($cand);
            $scored[] = $cand;
        }

        // Trier par score descendant
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        $this->matchingProducts = $scored;

        if (empty($this->matchingProducts)) {
            session()->flash('error', 'Aucun produit proche trouvé.');
        }
    }

    private function isCoffret(array $product): bool
    {
        $keywords = ['coffret', 'kit', 'set', 'pack', 'gift', 'box'];
        $text = strtolower(($product['name'] ?? '') . ' ' . ($product['type'] ?? '') . ' ' . ($product['variation'] ?? ''));
        foreach ($keywords as $kw) {
            if (str_contains($text, $kw)) return true;
        }
        return false;
    }

    public function selectProduct($productId)
    {
        $product = DB::table('last_price_scraped_product')->find($productId);
        if ($product) {
            session()->flash('success', 'Produit sélectionné: ' . $product->name);
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
        <span wire:loading>En cours...</span>
    </button>

    @foreach(['error' => 'red', 'success' => 'green', 'warning' => 'yellow', 'info' => 'blue'] as $key => $color)
        @if(session($key))
            <div class="mt-4 p-4 bg-{{ $color }}-100 text-{{ $color }}-700 rounded border border-{{ $color }}-200">
                @if($key === 'warning') ⚠️ @endif {{ $key === 'info' ? 'ℹ️ ' : '' }}{{ session($key) }}
            </div>
        @endif
    @endforeach

    @if($extractedData)
        <div class="mt-6 p-4 bg-gray-50 rounded">
            <h3 class="font-bold mb-3">Critères extraits :</h3>
            <div class="grid grid-cols-2 gap-4">
                <div><span class="font-semibold">Vendor:</span> {{ $extractedData['vendor'] ?? 'N/A' }}</div>
                <div><span class="font-semibold">Name:</span> {{ $extractedData['name'] ?? 'N/A' }}</div>
                <div><span class="font-semibold">Type:</span> {{ $extractedData['type'] ?? 'N/A' }}</div>
                <div><span class="font-semibold">Variation:</span> {{ $extractedData['variation'] ?? 'N/A' }}</div>
            </div>
        </div>
    @endif

    @if(!empty($matchingProducts))
        <div class="mt-6">
            <h3 class="font-bold mb-3">Résultats proches trouvés ({{ count($matchingProducts) }}) :</h3>
            <div class="space-y-2 max-h-96 overflow-y-auto">
                @foreach($matchingProducts as $product)
                    <div 
                        wire:click="selectProduct({{ $product['id'] }})"
                        class="p-3 border rounded hover:bg-blue-50 cursor-pointer transition"
                    >
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                @if($product['image_url'])
                                    <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}" class="w-12 h-12 object-cover rounded">
                                @endif
                                <div class="flex-1">
                                    <p class="font-medium text-sm">{{ $product['vendor'] }} - {{ $product['name'] }} {{ $product['is_coffret'] ? '(Coffret)' : '' }}</p>
                                    <p class="text-xs text-gray-500">{{ $product['type'] }} | {{ $product['variation'] }}</p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="font-bold text-sm">
                                    {{ is_numeric($product['prix_ht'] ?? null) ? number_format((float) $product['prix_ht'], 2, ',', ' ') : ($product['prix_ht'] ?? 'N/A') }} €
                                </p>
                                <p class="text-xs text-gray-500">ID: {{ $product['id'] }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>