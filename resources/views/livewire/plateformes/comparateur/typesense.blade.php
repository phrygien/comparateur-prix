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
                'model' => 'gpt-4o-mini', // ou 'gpt-4' selon vos besoins
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Tu es un assistant spécialisé dans l\'extraction d\'informations de produits cosmétiques et parfums. Tu dois extraire: Vendor (marque), name (nom du produit), type (ex: Eau de Toilette, Eau de Parfum, etc.), et variation (ex: 100ml, 50ml, etc.). Réponds UNIQUEMENT en format JSON avec ces clés exactes: vendor, name, type, variation. Si une information n\'est pas disponible, utilise null.'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Extrait les informations de ce produit: {$this->name}"
                    ]
                ],
                'temperature' => 0.3,
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