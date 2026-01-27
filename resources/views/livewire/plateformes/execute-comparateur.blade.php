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
        $this->bestMatch = null;
        $this->aiCorrection = null;
        session()->forget(['error', 'warning', 'success']);
        
        try {
            // Étape 1: Extraction des données
            $extractionResponse = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Tu es un expert en extraction de données de produits cosmétiques. Tu dois extraire vendor, name, variation et type du nom de produit fourni. IMPORTANT: Pour les parfums, si le vendor et le nom semblent identiques (ex: "Azzaro Chrome"), traite "Chrome" comme le nom et "Azzaro" comme le vendor. Réponds UNIQUEMENT avec un objet JSON valide, sans markdown ni texte supplémentaire.'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Extrait les informations suivantes du nom de produit et retourne-les au format JSON strict :
- vendor : la marque du produit
- name : le nom de la gamme/ligne de produit (pour les parfums, c'est souvent le nom du parfum)
- variation : la contenance/taille (ml, g, etc.)
- type : le type de produit (Crème, Sérum, Concentré, Déodorant, Eau de Toilette, etc.)

Nom du produit : {$this->productName}

Exemple de format attendu :
{
  \"vendor\": \"Azzaro\",
  \"name\": \"Chrome\",
  \"variation\": \"150 ml\",
  \"type\": \"Déodorant Vaporisateur\"
}

Exemple 2 (parfum avec nom composé) :
{
  \"vendor\": \"Dior\",
  \"name\": \"Sauvage\",
  \"variation\": \"100 ml\",
  \"type\": \"Eau de Toilette\"
}

Exemple 3 (produit de soin) :
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

            if (!$extractionResponse->successful()) {
                throw new \Exception('Erreur API OpenAI: ' . $extractionResponse->body());
            }

            $extractionData = $extractionResponse->json();
            $content = $extractionData['choices'][0]['message']['content'];
            
            // Nettoyer le contenu
            $content = preg_replace('/```json\s*|\s*```/', '', $content);
            $content = trim($content);
            
            $this->extractedData = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Erreur de parsing JSON: ' . json_last_error_msg());
            }

            // Étape 2: Vérification de la correspondance avec OpenAI
            $verificationResponse = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Tu es un expert en produits cosmétiques et parfumerie. Tu dois analyser si le nom et le type de produit correspondent logiquement. Pour les parfums, si le nom ressemble à un nom de parfum (ex: "Chrome", "Sauvage", "J\'adore") et le type est "Déodorant", cela peut être correct. Réponds UNIQUEMENT avec un objet JSON valide contenant "is_correct" (booléen) et "correction" (objet avec vendor, name, type si correction nécessaire).'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Analyse cette combinaison nom/type de produit et vérifie si elle est cohérente :
                        
Nom du produit original: {$this->productName}
Vendor extrait: {$this->extractedData['vendor']}
Name extrait: {$this->extractedData['name']}
Type extrait: {$this->extractedData['type']}

Questions à considérer:
1. Est-ce que le 'name' ressemble à un nom de gamme/produit valide pour ce 'type'?
2. Pour les parfums: 'Chrome', 'Sauvage', 'J\'adore' sont des noms de parfums valides pour des déodorants
3. Pour les soins: 'Vital Perfection' est valide pour un 'Concentré Correcteur Rides'
4. Y a-t-il des incohérences évidentes?

Si la combinaison semble incorrecte, suggère une correction probable.

Format de réponse attendu:
{
  \"is_correct\": true/false,
  \"confidence\": \"high/medium/low\",
  \"explanation\": \"Explication courte\",
  \"correction\": {
    \"vendor\": \"Vendor corrigé si nécessaire\",
    \"name\": \"Name corrigé si nécessaire\",
    \"type\": \"Type corrigé si nécessaire\"
  }
}

Exemple de réponse pour 'Azzaro Chrome Déodorant':
{
  \"is_correct\": true,
  \"confidence\": \"high\",
  \"explanation\": \"Chrome est un nom de parfum Azzaro, compatible avec un déodorant\",
  \"correction\": {}
}

Exemple de réponse pour incohérence:
{
  \"is_correct\": false,
  \"confidence\": \"high\",
  \"explanation\": \"Le nom 'Nettoyant Visage' ne correspond pas au type 'Parfum'\",
  \"correction\": {
    \"vendor\": \"Azzaro\",
    \"name\": \"Chrome\",
    \"type\": \"Déodorant\"
  }
}"
                    ]
                ],
                'temperature' => 0.2,
                'max_tokens' => 500
            ]);

            if ($verificationResponse->successful()) {
                $verificationData = $verificationResponse->json();
                $verificationContent = $verificationData['choices'][0]['message']['content'];
                
                // Nettoyer le contenu
                $verificationContent = preg_replace('/```json\s*|\s*```/', '', $verificationContent);
                $verificationContent = trim($verificationContent);
                
                $this->aiCorrection = json_decode($verificationContent, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('Erreur de parsing JSON pour la vérification: ' . json_last_error_msg());
                }

                // Appliquer les corrections si nécessaire
                if (!$this->aiCorrection['is_correct'] && !empty($this->aiCorrection['correction'])) {
                    $correction = $this->aiCorrection['correction'];
                    
                    if (!empty($correction['vendor'])) {
                        $this->extractedData['vendor'] = $correction['vendor'];
                    }
                    if (!empty($correction['name'])) {
                        $this->extractedData['name'] = $correction['name'];
                    }
                    if (!empty($correction['type'])) {
                        $this->extractedData['type'] = $correction['type'];
                    }
                    
                    session()->flash('info', '⚠️ OpenAI a suggéré une correction: ' . ($this->aiCorrection['explanation'] ?? ''));
                }
            }
            
            // Rechercher les produits correspondants
            $this->searchMatchingProducts();
            
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
        
        // Pour les parfums, ajuster la recherche du nom
        // Certains parfums ont le nom identique au vendor dans la base
        $nameVariations = $this->getNameVariations($vendor, $name, $cleanType);

        // Stratégie de recherche STRICTE - les trois critères doivent correspondre
        
        // 1. Recherche avec variations de nom
        foreach ($nameVariations as $nameToSearch) {
            $exactMatch = Product::where(function($query) use ($vendor) {
                    $query->where('vendor', 'LIKE', "%{$vendor}%")
                          ->orWhereRaw('LOWER(vendor) = LOWER(?)', [$vendor]);
                })
                ->where(function($query) use ($nameToSearch) {
                    $query->where('name', 'LIKE', "%{$nameToSearch}%")
                          ->orWhereRaw('LOWER(name) = LOWER(?)', [$nameToSearch]);
                })
                ->where(function($query) use ($cleanType) {
                    $query->where('type', 'LIKE', "%{$cleanType}%")
                          ->orWhereRaw('LOWER(type) LIKE ?', ['%' . strtolower($cleanType) . '%']);
                })
                ->get();

            if ($exactMatch->isNotEmpty()) {
                $this->matchingProducts = $exactMatch->toArray();
                $this->bestMatch = $exactMatch->first();
                return;
            }
        }

        // 2. Recherche plus flexible sur le type (le type de la BDD contient le type nettoyé)
        foreach ($nameVariations as $nameToSearch) {
            $typeContains = Product::where(function($query) use ($vendor) {
                    $query->where('vendor', 'LIKE', "%{$vendor}%")
                          ->orWhereRaw('LOWER(vendor) = LOWER(?)', [$vendor]);
                })
                ->where(function($query) use ($nameToSearch) {
                    $query->where('name', 'LIKE', "%{$nameToSearch}%")
                          ->orWhereRaw('LOWER(name) = LOWER(?)', [$nameToSearch]);
                })
                ->where(function($query) use ($cleanType) {
                    // Recherche plus flexible sur le type
                    $keywords = explode(' ', $cleanType);
                    foreach ($keywords as $keyword) {
                        if (strlen($keyword) > 2) {
                            $query->orWhere('type', 'LIKE', "%{$keyword}%");
                        }
                    }
                })
                ->get();

            if ($typeContains->isNotEmpty()) {
                $this->matchingProducts = $typeContains->toArray();
                $this->bestMatch = $typeContains->first();
                session()->flash('warning', 'Résultats trouvés avec correspondance flexible sur le type.');
                return;
            }
        }

        // 3. Recherche par type seulement si c'est un type spécifique comme "Déodorant"
        // Pour les cas comme AZZARO où le nom dans la base est "AZZARO Pour Homme"
        $isSpecificType = $this->isSpecificProductType($cleanType);
        
        if ($isSpecificType) {
            foreach ($nameVariations as $nameToSearch) {
                $typeOnlyMatch = Product::where(function($query) use ($vendor) {
                        $query->where('vendor', 'LIKE', "%{$vendor}%")
                              ->orWhereRaw('LOWER(vendor) = LOWER(?)', [$vendor]);
                    })
                    ->where('type', 'LIKE', "%{$cleanType}%")
                    ->get();

                if ($typeOnlyMatch->isNotEmpty()) {
                    $this->matchingProducts = $typeOnlyMatch->toArray();
                    $this->bestMatch = $typeOnlyMatch->first();
                    session()->flash('warning', 'Résultats trouvés pour le vendor et type seulement (nom différent).');
                    return;
                }
            }
        }

        // 4. Aucun résultat avec les trois critères
        session()->flash('error', '❌ Aucun produit trouvé avec la combinaison vendor + name + type.');
        $this->matchingProducts = [];
        $this->bestMatch = null;
    }

    /**
     * Générer des variations de nom pour la recherche
     */
    private function getNameVariations(string $vendor, string $name, string $type): array
    {
        $variations = [];
        
        // 1. Le nom tel quel
        $variations[] = $name;
        
        // 2. Pour les parfums, ajouter "Pour Homme" ou "Pour Femme" si c'est un parfum
        if ($this->isPerfumeType($type)) {
            $variations[] = $name . ' Pour Homme';
            $variations[] = $name . ' Pour Femme';
            $variations[] = $vendor . ' ' . $name;
            $variations[] = $vendor; // Dans certains cas, le nom dans la base est juste le vendor
            
            // Ajouter des variantes courantes de parfums
            if (str_contains(strtolower($name), 'chrome')) {
                $variations[] = 'Chrome Azzaro';
            }
            if (str_contains(strtolower($name), 'sauvage')) {
                $variations[] = 'Sauvage Dior';
            }
        }
        
        // 3. Nom sans articles ou prépositions
        $cleanName = preg_replace('/\b(le|la|les|un|une|des|du|de|d\'|pour|and|the)\b/i', '', $name);
        $cleanName = preg_replace('/\s+/', ' ', trim($cleanName));
        if ($cleanName !== $name) {
            $variations[] = $cleanName;
        }
        
        return array_unique(array_filter($variations));
    }
    
    /**
     * Vérifier si c'est un type de parfum
     */
    private function isPerfumeType(string $type): bool
    {
        $perfumeTypes = ['déodorant', 'deodorant', 'parfum', 'eau de toilette', 'eau de parfum', 'after shave', 'lotion', 'baume'];
        $cleanType = strtolower($type);
        
        foreach ($perfumeTypes as $perfumeType) {
            if (str_contains($cleanType, $perfumeType)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Vérifier si c'est un type spécifique qui peut être recherché seul
     */
    private function isSpecificProductType(string $type): bool
    {
        $specificTypes = ['déodorant', 'deodorant', 'shampooing', 'shampoing', 'après-shampooing', 'gel douche', 'savon', 'lait corporel'];
        $cleanType = strtolower($type);
        
        foreach ($specificTypes as $specificType) {
            if (str_contains($cleanType, $specificType)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Nettoyer le type en enlevant les conditionnements et formats
     */
    private function cleanType(string $type): string
    {
        // Mots à supprimer (conditionnements, formats, unités)
        $stopWords = [
            'vaporisateur', 'spray', 'pompe', 'tube', 'pot', 'flacon', 
            'roll-on', 'rollon', 'stick', 'roll', 'on', 'atomiseur',
            'ml', 'mg', 'gr', 'g', 'l', 'unité', 'unités',
            'sans', 'avec', 'pour', 'et', 'ou', 'le', 'la', 'les',
            'en', 'par', 'à', 'de', 'des', 'du', 'd\''
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

    @if(session('info'))
        <div class="mt-4 p-4 bg-blue-100 text-blue-700 rounded">
            ℹ️ {{ session('info') }}
        </div>
    @endif

    @if($extractedData)
        <div class="mt-6 p-4 bg-gray-50 rounded">
            <h3 class="font-bold mb-3">Critères extraits :</h3>
            
            @if($aiCorrection)
                <div class="mb-4 p-3 rounded {{ $aiCorrection['is_correct'] ? 'bg-green-50 border border-green-200' : 'bg-yellow-50 border border-yellow-200' }}">
                    <div class="flex items-start">
                        @if($aiCorrection['is_correct'])
                            <span class="text-green-600 mr-2">✓</span>
                        @else
                            <span class="text-yellow-600 mr-2">⚠️</span>
                        @endif
                        <div>
                            <p class="font-semibold">{{ $aiCorrection['explanation'] }}</p>
                            <p class="text-sm text-gray-600 mt-1">Confiance: {{ $aiCorrection['confidence'] ?? 'N/A' }}</p>
                        </div>
                    </div>
                </div>
            @endif
            
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
            
            @if($aiCorrection && !$aiCorrection['is_correct'] && !empty($aiCorrection['correction']))
                <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded">
                    <h4 class="font-semibold text-blue-700 mb-2">Corrections suggérées par OpenAI:</h4>
                    <div class="grid grid-cols-2 gap-2 text-sm">
                        @if(!empty($aiCorrection['correction']['vendor']))
                            <div>
                                <span class="font-medium">Vendor:</span> 
                                <span class="text-blue-600">{{ $aiCorrection['correction']['vendor'] }}</span>
                            </div>
                        @endif
                        @if(!empty($aiCorrection['correction']['name']))
                            <div>
                                <span class="font-medium">Name:</span> 
                                <span class="text-blue-600">{{ $aiCorrection['correction']['name'] }}</span>
                            </div>
                        @endif
                        @if(!empty($aiCorrection['correction']['type']))
                            <div>
                                <span class="font-medium">Type:</span> 
                                <span class="text-blue-600">{{ $aiCorrection['correction']['type'] }}</span>
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    @endif

    @if($bestMatch)
        <div class="mt-6 p-4 bg-green-50 border-2 border-green-500 rounded">
            <h3 class="font-bold text-green-700 mb-3">✓ Produit trouvé :</h3>
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
                                    ✓ Correspondance trouvée
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