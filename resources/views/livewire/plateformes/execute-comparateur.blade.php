<?php

use Livewire\Volt\Component;
use App\Models\Product;
use Illuminate\Support\Facades\Http;

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
        $this->matchingProducts = [];
        $this->bestMatch = null;
        session()->forget(['error', 'warning', 'success']);
        
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
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
- type : le type de produit (Crème, Sérum, Concentré, Déodorant, Eau de Toilette, etc.)

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
                
                // Nettoyer le contenu
                $content = preg_replace('/```json\s*|\s*```/', '', $content);
                $content = trim($content);
                
                $this->extractedData = json_decode($content, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('Erreur de parsing JSON: ' . json_last_error_msg());
                }
                
                // Rechercher les produits correspondants
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
        $type = $this->extractedData['type'] ?? '';

        // Vérifier que les trois critères sont présents
        if (empty($vendor) || empty($name) || empty($type)) {
            $missingFields = [];
            if (empty($vendor)) $missingFields[] = 'vendor';
            if (empty($name)) $missingFields[] = 'name';
            if (empty($type)) $missingFields[] = 'type';
            
            session()->flash('warning', 'Critères insuffisants pour la recherche. Champs manquants: ' . implode(', ', $missingFields));
            return;
        }

        // Nettoyer le type extrait
        $cleanType = $this->cleanType($type);

        // Stratégie de recherche STRICTE - les trois critères doivent correspondre
        
        // 1. Recherche exacte : vendor + name + type complet
        $exactMatch = Product::where('vendor', 'LIKE', "%{$vendor}%")
            ->where('name', 'LIKE', "%{$name}%")
            ->where('type', 'LIKE', "%{$cleanType}%")
            ->get();

        if ($exactMatch->isNotEmpty()) {
            $this->matchingProducts = $exactMatch->toArray();
            $this->bestMatch = $exactMatch->first();
            return;
        }

        // 2. Recherche plus flexible sur le type (le type de la BDD contient le type nettoyé)
        $typeContains = Product::where('vendor', 'LIKE', "%{$vendor}%")
            ->where('name', 'LIKE', "%{$name}%")
            ->where(function($query) use ($cleanType) {
                $query->whereRaw('LOWER(type) LIKE ?', ['%' . strtolower($cleanType) . '%'])
                      ->orWhere('type', 'LIKE', "%{$cleanType}%");
            })
            ->get();

        if ($typeContains->isNotEmpty()) {
            $this->matchingProducts = $typeContains->toArray();
            $this->bestMatch = $typeContains->first();
            session()->flash('warning', 'Résultats trouvés avec correspondance flexible sur le type.');
            return;
        }

        // 3. Recherche vendor + name + type partiel (mot-clé dans le type)
        $keywords = explode(' ', $cleanType);
        $keywords = array_filter($keywords, function($word) {
            return strlen($word) > 2; // Ignorer les mots trop courts
        });

        if (!empty($keywords)) {
            $typeKeywordMatch = Product::where('vendor', 'LIKE', "%{$vendor}%")
                ->where('name', 'LIKE', "%{$name}%")
                ->where(function($query) use ($keywords) {
                    foreach ($keywords as $keyword) {
                        $query->orWhere('type', 'LIKE', "%{$keyword}%");
                    }
                })
                ->get();

            if ($typeKeywordMatch->isNotEmpty()) {
                $this->matchingProducts = $typeKeywordMatch->toArray();
                $this->bestMatch = $typeKeywordMatch->first();
                session()->flash('warning', 'Résultats trouvés avec correspondance partielle sur le type.');
                return;
            }
        }

        // 4. Aucun résultat avec les trois critères
        session()->flash('error', '❌ Aucun produit trouvé avec la combinaison vendor + name + type.');
        $this->matchingProducts = [];
        $this->bestMatch = null;
    }

    /**
     * Nettoyer le type en enlevant les conditionnements et formats
     */
    private function cleanType(string $type): string
    {
        // Mots à supprimer (conditionnements, formats, unités)
        $stopWords = [
            'vaporisateur', 'spray', 'pompe', 'tube', 'pot', 'flacon', 
            'roll-on', 'rollon', 'stick', 'roll', 'on',
            'ml', 'mg', 'gr', 'g', 'l', 'unité', 'unités',
            'sans', 'avec', 'pour', 'et', 'ou', 'le', 'la', 'les',
            'en', 'par', 'à', 'de', 'des', 'du'
        ];
        
        $cleanedType = strtolower($type);
        
        // Supprimer chaque mot de la liste
        foreach ($stopWords as $word) {
            $cleanedType = preg_replace('/\b' . preg_quote($word, '/') . '\b/i', '', $cleanedType);
        }
        
        // Supprimer les caractères spéciaux
        $cleanedType = preg_replace('/[\(\)\[\]\-\+\=\*]/', ' ', $cleanedType);
        
        // Nettoyer les espaces multiples et trim
        $cleanedType = preg_replace('/\s+/', ' ', $cleanedType);
        $cleanedType = trim($cleanedType);
        
        return $cleanedType;
    }

    public function selectProduct($productId)
    {
        $product = Product::find($productId);
        
        if ($product) {
            session()->flash('success', 'Produit sélectionné : ' . $product->name);
            $this->bestMatch = $product;
            
            // Émettre un événement si besoin
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

    @if(session('warning'))
        <div class="mt-4 p-4 bg-yellow-100 text-yellow-700 rounded">
            ⚠️ {{ session('warning') }}
        </div>
    @endif

    @if($extractedData)
        <div class="mt-6 p-4 bg-gray-50 rounded">
            <h3 class="font-bold mb-3">Critères extraits :</h3>
            <div class="grid grid-cols-2 gap-4">
                <div class="{{ empty($extractedData['vendor']) ? 'text-red-600 font-semibold' : '' }}">
                    <span class="font-semibold">Vendor:</span> 
                    {{ $extractedData['vendor'] ?? 'N/A' }}
                    @if(empty($extractedData['vendor'])) <span class="text-xs">(requis)</span> @endif
                </div>
                <div class="{{ empty($extractedData['name']) ? 'text-red-600 font-semibold' : '' }}">
                    <span class="font-semibold">Name:</span> 
                    {{ $extractedData['name'] ?? 'N/A' }}
                    @if(empty($extractedData['name'])) <span class="text-xs">(requis)</span> @endif
                </div>
                <div class="{{ empty($extractedData['type']) ? 'text-red-600 font-semibold' : '' }}">
                    <span class="font-semibold">Type:</span> 
                    {{ $extractedData['type'] ?? 'N/A' }}
                    @if(empty($extractedData['type'])) <span class="text-xs">(requis)</span> @endif
                </div>
                <div>
                    <span class="font-semibold text-gray-500">Variation:</span> 
                    <span class="text-gray-400">{{ $extractedData['variation'] ?? 'N/A' }}</span>
                </div>
            </div>
        </div>
    @endif

    @if($bestMatch)
        <div class="mt-6 p-4 bg-green-50 border-2 border-green-500 rounded">
            <h3 class="font-bold text-green-700 mb-3">✓ Produit trouvé (vendor + name + type) :</h3>
            <div class="flex items-start gap-4">
                @if($bestMatch['image_url'])
                    <img src="{{ $bestMatch['image_url'] }}" alt="{{ $bestMatch['name'] }}" class="w-20 h-20 object-cover rounded">
                @endif
                <div class="flex-1">
                    <p class="font-semibold">{{ $bestMatch['vendor'] }} - {{ $bestMatch['name'] }}</p>
                    <p class="text-sm text-gray-600">{{ $bestMatch['type'] }} | {{ $bestMatch['variation'] }}</p>
                    <p class="text-sm font-bold text-green-600 mt-1">{{ $bestMatch['prix_ht'] }} {{ $bestMatch['currency'] }}</p>
                    <a href="{{ $bestMatch['url'] }}" target="_blank" class="text-xs text-blue-500 hover:underline">Voir le produit</a>
                </div>
            </div>
        </div>
    @endif

    @if(!empty($matchingProducts) && count($matchingProducts) > 1)
        <div class="mt-6">
            <h3 class="font-bold mb-3">Autres résultats trouvés ({{ count($matchingProducts) }}) :</h3>
            <p class="text-sm text-gray-600 mb-2">Critères: vendor + name + type</p>
            <div class="space-y-2 max-h-96 overflow-y-auto">
                @foreach($matchingProducts as $product)
                    <div 
                        wire:click="selectProduct({{ $product['id'] }})"
                        class="p-3 border rounded hover:bg-blue-50 cursor-pointer transition {{ $bestMatch && $bestMatch['id'] === $product['id'] ? 'bg-blue-100 border-blue-500' : 'bg-white' }}"
                    >
                        <div class="flex items-center gap-3">
                            @if($product['image_url'])
                                <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}" class="w-12 h-12 object-cover rounded">
                            @endif
                            <div class="flex-1">
                                <p class="font-medium text-sm">{{ $product['vendor'] }} - {{ $product['name'] }}</p>
                                <p class="text-xs text-gray-500">{{ $product['type'] }} | {{ $product['variation'] }}</p>
                                <div class="text-xs text-blue-600 mt-1">
                                    ✓ Correspondance complète
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="font-bold text-sm">{{ $product['prix_ht'] }} {{ $product['currency'] }}</p>
                                <p class="text-xs text-gray-500">ID: {{ $product['id'] }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>