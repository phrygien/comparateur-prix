<?php

use Livewire\Volt\Component;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

new class extends Component {
    public string $name;
    public string $id;
    public string $price;
    public Collection $productsBySite;

    // Nouvelles propriétés pour l'extraction
    public string $vendor = '';
    public string $productName = '';
    public string $productType = '';
    public string $variation = '';
    public bool $isExtracting = false;
    public string $extractionError = '';

    public function mount($name, $id, $price): void
    {
        $this->name = $name;
        $this->id = $id;
        $this->price = $price;

        // Extraire automatiquement les informations lors du montage
        $this->extractProductInfo();
    }

    public function extractProductInfo(): void
    {
        $this->isExtracting = true;
        $this->extractionError = '';

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Tu es un expert en extraction d\'informations produits. Extrais les informations suivantes du texte fourni: vendor (marque), name (nom du produit), type (type de produit), variation (taille/variante). Réponds uniquement au format JSON: {"vendor": "", "name": "", "type": "", "variation": ""}'
                    ],
                    [
                        'role' => 'user',
                        'content' => $this->name
                    ]
                ],
                'temperature' => 0.3,
                'max_tokens' => 150
            ]);

            if ($response->successful()) {
                $result = $response->json();
                $content = $result['choices'][0]['message']['content'];

                // Nettoyer le contenu JSON
                $content = trim($content);

                // Extraire le JSON même s'il y a du texte autour
                preg_match('/\{.*\}/s', $content, $matches);

                if (!empty($matches[0])) {
                    $extractedData = json_decode($matches[0], true);

                    if (json_last_error() === JSON_ERROR_NONE) {
                        $this->vendor = $extractedData['vendor'] ?? '';
                        $this->productName = $extractedData['name'] ?? '';
                        $this->productType = $extractedData['type'] ?? '';
                        $this->variation = $extractedData['variation'] ?? '';
                    } else {
                        $this->extractionError = 'Erreur de décodage JSON';
                    }
                } else {
                    $this->extractionError = 'Format de réponse invalide';
                }
            } else {
                $this->extractionError = 'Erreur API OpenAI: ' . $response->status();
            }
        } catch (\Exception $e) {
            $this->extractionError = 'Exception: ' . $e->getMessage();
        } finally {
            $this->isExtracting = false;
        }
    }

}; ?>

<div>
    <!-- Affichage des informations extraites -->
    @if($isExtracting)
        <div class="text-blue-500">Extraction en cours...</div>
    @elseif($extractionError)
        <div class="text-red-500">Erreur: {{ $extractionError }}</div>
    @else
        <div class="space-y-2">
            <div><strong>Vendor:</strong> {{ $vendor }}</div>
            <div><strong>Nom du produit:</strong> {{ $productName }}</div>
            <div><strong>Type:</strong> {{ $productType }}</div>
            <div><strong>Variation:</strong> {{ $variation }}</div>
        </div>

    @endif

    <!-- Bouton pour ré-extraire si nécessaire -->
    <button wire:click="extractProductInfo"
            wire:loading.attr="disabled"
            class="mt-2 px-4 py-2 bg-blue-500 text-white rounded">
        <span wire:loading.remove>Extraire à nouveau</span>
        <span wire:loading>Extraction...</span>
    </button>
</div>
