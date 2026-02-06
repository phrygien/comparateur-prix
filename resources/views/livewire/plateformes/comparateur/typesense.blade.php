<?php

use Livewire\Volt\Component;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

new class extends Component {
    public string $name;
    public string $id;
    public string $price;
    
    public ?string $vendor = null;
    public ?string $productName = null;
    public ?string $type = null;
    public ?string $variation = null;
    public bool $isProcessing = false;
    public bool $isProcessed = false;

    public function mount($name, $id, $price): void
    {
        $this->name = $name;
        $this->id = $id;
        $this->price = $price;
        
        // Extraction automatique au montage du composant
        $this->extractProductInfo();
    }

    public function extractProductInfo(): void
    {
        $this->isProcessing = true;
        
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Tu es un expert en extraction d\'informations de produits cosmétiques et parfums.

Règles d\'extraction:
- Vendor: La marque du produit (ex: Coach, Biotherm, Dior)
- Name: Le nom complet du produit sans la marque (ex: Coach Green Homme, Life Plankton)
- Type: Le type de produit de manière générale (ex: Eau de Toilette, Eau de Parfum, Lait pour le corps, Crème visage, Sérum, Gel douche, etc.)
- Variation: La contenance ou taille (ex: 100ml, 50ml, 40ml, 200ml)

Exemples:
1. "Coach - Coach Green Homme - Eau de Toilette 100 ml"
   → vendor: "Coach", name: "Coach Green Homme", type: "Eau de Toilette", variation: "100ml"

2. "Biotherm - Life Plankton - Lait pour le corps lissant et raffermissant 40ml"
   → vendor: "Biotherm", name: "Life Plankton", type: "Lait pour le corps", variation: "40ml"

3. "Dior - J\'adore - Eau de Parfum 50ml"
   → vendor: "Dior", name: "J\'adore", type: "Eau de Parfum", variation: "50ml"

IMPORTANT: Pour le type, garde uniquement la catégorie générale (Lait pour le corps, Crème visage, etc.) sans les descriptions marketing (lissant, raffermissant, hydratant, etc.)

Réponds UNIQUEMENT en format JSON avec ces clés: vendor, name, type, variation.'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Extrait les informations de ce produit: {$this->name}"
                    ]
                ],
                'temperature' => 0.2,
                'response_format' => ['type' => 'json_object']
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $content = json_decode($data['choices'][0]['message']['content'], true);
                
                $this->vendor = $content['vendor'] ?? null;
                $this->productName = $content['name'] ?? null;
                $this->type = $content['type'] ?? null;
                $this->variation = $content['variation'] ?? null;
                
                $this->isProcessed = true;
            }
        } catch (\Exception $e) {
            logger()->error('OpenAI extraction error: ' . $e->getMessage());
        } finally {
            $this->isProcessing = false;
        }
    }

}; ?>

<div class="bg-white p-6 rounded-lg shadow">
    <div class="mb-4">
        <h3 class="text-lg font-semibold mb-2">Produit Original:</h3>
        <p class="text-gray-700">{{ $name }}</p>
        <p class="text-sm text-gray-500">Prix: {{ $price }}</p>
    </div>

    @if($isProcessing)
        <div class="flex items-center gap-2 text-blue-600">
            <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span>Extraction en cours...</span>
        </div>
    @endif

    @if($isProcessed)
        <div class="mt-4 border-t pt-4">
            <h3 class="text-lg font-semibold mb-3">Informations Extraites:</h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <span class="font-medium text-gray-600">Vendor:</span>
                    <p class="text-gray-900">{{ $vendor ?? 'N/A' }}</p>
                </div>
                <div>
                    <span class="font-medium text-gray-600">Name:</span>
                    <p class="text-gray-900">{{ $productName ?? 'N/A' }}</p>
                </div>
                <div>
                    <span class="font-medium text-gray-600">Type:</span>
                    <p class="text-gray-900">{{ $type ?? 'N/A' }}</p>
                </div>
                <div>
                    <span class="font-medium text-gray-600">Variation:</span>
                    <p class="text-gray-900">{{ $variation ?? 'N/A' }}</p>
                </div>
            </div>
        </div>
    @endif
</div>