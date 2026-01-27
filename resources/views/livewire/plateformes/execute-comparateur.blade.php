<?php

use Livewire\Volt\Component;

new class extends Component {

    public string $productName = '';
    public $productPrice;
    public $productId;
    public $extractedData = null;
    public $isLoading = false;

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
                'Authorization' => 'Bearer ' . config('services.openai.api_key'),
            ])->post('https://api.openai.com/v1/chat/completions', [
                        'model' => 'gpt-4o-mini',
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => 'Tu es un expert en extraction de données de produits cosmétiques. Tu dois extraire vendor, name, variation et type du nom de produit fourni. Réponds UNIQUEMENT avec un objet JSON valide, sans markdown ni texte supplémentaire.'
                            ],
                            [
                                'role' => 'user',
                                'content' => "Extrait les informations suivantes du nom de produit et retourne-les au format JSON strict :
                                - vendor : la marque du produit
                                - name : le nom de la gamme/ligne de produit
                                - variation : la contenance/taille (ml, g, etc.)
                                - type : le type de produit (Crème, Sérum, Concentré, etc.)

                                Nom du produit : {$this->productName}

                                Exemple de format attendu :
                                {
                                \"vendor\": \"Shiseido\",
                                \"name\": \"Vital Perfection\",
                                \"variation\": \"20 ml\",
                                \"type\": \"Concentré Correcteur Rides\"
                                }"
                            ]
                        ],
                        'temperature' => 0.3,
                        'max_tokens' => 500
                    ]);

                    if ($response->successful()) {
                        $data = $response->json();
                        $content = $data['choices'][0]['message']['content'];

                        // Nettoyer le contenu si nécessaire (enlever les backticks markdown)
                        $content = preg_replace('/```json\s*|\s*```/', '', $content);
                        $content = trim($content);

                        $this->extractedData = json_decode($content, true);

                        if (json_last_error() !== JSON_ERROR_NONE) {
                            throw new \Exception('Erreur de parsing JSON: ' . json_last_error_msg());
                        }

                        // Optionnel : Logger ou sauvegarder les données
                        \Log::info('Extraction réussie', [
                            'product_id' => $this->productId,
                            'extracted' => $this->extractedData
                        ]);

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

}; ?>

<div class="p-6 bg-white rounded-lg shadow">
    <div class="mb-4">
        <h2 class="text-xl font-bold mb-2">Extraction de données produit</h2>
        <p class="text-gray-600">Produit: {{ $productName }}</p>
    </div>

    <button wire:click="extractSearchTerme" wire:loading.attr="disabled"
        class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600 disabled:opacity-50">
        <span wire:loading.remove>Extraire les informations</span>
        <span wire:loading>Extraction en cours...</span>
    </button>

    @if(session('error'))
        <div class="mt-4 p-4 bg-red-100 text-red-700 rounded">
            {{ session('error') }}
        </div>
    @endif

    @if($extractedData)
        <div class="mt-6 p-4 bg-gray-50 rounded">
            <h3 class="font-bold mb-3">Données extraites :</h3>
            <dl class="space-y-2">
                <div>
                    <dt class="font-semibold">Vendor:</dt>
                    <dd class="ml-4">{{ $extractedData['vendor'] ?? 'N/A' }}</dd>
                </div>
                <div>
                    <dt class="font-semibold">Name:</dt>
                    <dd class="ml-4">{{ $extractedData['name'] ?? 'N/A' }}</dd>
                </div>
                <div>
                    <dt class="font-semibold">Variation:</dt>
                    <dd class="ml-4">{{ $extractedData['variation'] ?? 'N/A' }}</dd>
                </div>
                <div>
                    <dt class="font-semibold">Type:</dt>
                    <dd class="ml-4">{{ $extractedData['type'] ?? 'N/A' }}</dd>
                </div>
            </dl>

            <div class="mt-4">
                <p class="text-sm font-semibold mb-2">JSON brut:</p>
                <pre
                    class="bg-gray-800 text-white p-3 rounded text-xs overflow-x-auto">{{ json_encode($extractedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </div>
        </div>
    @endif
</div>