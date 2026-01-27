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
        
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            ])->timeout(30)->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Tu es un expert en extraction de données de produits cosmétiques. Tu dois extraire vendor, name, variation et type de manière FLEXIBLE. Réponds UNIQUEMENT avec un objet JSON valide, sans markdown ni texte supplémentaire.'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Analyse ce nom de produit et extrait les informations au format JSON strict :

Nom du produit : {$this->productName}

RÈGLES D'EXTRACTION FLEXIBLES :

1. **VENDOR** - Extraction intelligente :
   - Peut être le nom complet : \"Jean Paul Gaultier\"
   - Peut être partiel : \"Jean Paul\" ou \"Gaultier\"
   - Si le vendor est composé, extraire la partie la plus distinctive
   - Exemples :
     * \"Jean Paul Gaultier\" → vendor peut être \"Jean Paul Gaultier\", \"Jean Paul\", ou \"Gaultier\"
     * \"Yves Saint Laurent\" → vendor peut être \"Yves Saint Laurent\" ou \"YSL\"

2. **NAME** - Extraction flexible du nom principal :
   - Peut contenir une partie du vendor (ex: \"Gaultier Divine\")
   - Peut être juste le nom distinctif (ex: \"Divine\")
   - Peut inclure \"Coffret\" si c'est un coffret
   - Exemples :
     * \"Coffret Gaultier Divine\" → \"Coffret Gaultier Divine\" OU \"Coffret Divine\" OU \"Divine\"
     * \"Chrome\" → \"Chrome\"
     * \"Pour Un Homme de Caron\" → \"Pour Un Homme de Caron\" OU \"Pour Un Homme\"

3. **TYPE** - Extraction flexible de la catégorie :
   - Peut être le type complet : \"Eau de Parfum\"
   - Peut être abrégé : \"Parfum\", \"EDT\", \"EDP\"
   - Peut inclure des variantes : \"Eau de Parfum Vaporisateur\", \"Lait pour le corps\", \"Lait corps\"
   - Pour les coffrets, extraire le type principal du produit principal
   - Exemples :
     * \"Eau de Parfum 50ml + Lait pour le corps 75ml\" → type: \"Eau de Parfum\" OU \"Parfum\"
     * \"Lait pour le corps\" → \"Lait pour le corps\" OU \"Lait corps\"

4. **VARIATION** - Contenance :
   - Extraire toutes les contenances trouvées
   - Format : \"50ml\", \"100ml\", \"75ml + 50ml\" pour les coffrets
   - Exemples :
     * \"50ml + Lait pour le corps 75ml\" → \"50ml + 75ml\" OU \"50ml\"

5. **IS_COFFRET** - Détection :
   - true si contient : Coffret, Set, Kit, Duo, Trio, Routine, Pack, Bundle, etc.
   - true si plusieurs produits sont mentionnés (ex: \"50ml + Lait 75ml\")

EXEMPLES D'EXTRACTION :

EXEMPLE 1 (Coffret) :
Input: \"Jean Paul Gaultier - Coffret Gaultier Divine - Eau de Parfum 50ml + Lait pour le corps 75ml\"
Output:
{
  \"vendor\": \"Jean Paul Gaultier\",
  \"vendor_variations\": [\"Jean Paul Gaultier\", \"Jean Paul\", \"Gaultier\"],
  \"name\": \"Coffret Divine\",
  \"name_variations\": [\"Coffret Gaultier Divine\", \"Coffret Divine\", \"Divine\", \"Gaultier Divine\"],
  \"type\": \"Eau de Parfum\",
  \"type_variations\": [\"Eau de Parfum\", \"Parfum\", \"EDP\"],
  \"variation\": \"50ml + 75ml\",
  \"is_coffret\": true
}

EXEMPLE 2 (Produit simple) :
Input: \"AZZARO - CHROME - Eau de Toilette Vaporisateur 100ml\"
Output:
{
  \"vendor\": \"AZZARO\",
  \"vendor_variations\": [\"AZZARO\", \"Azzaro\"],
  \"name\": \"CHROME\",
  \"name_variations\": [\"CHROME\", \"Chrome\"],
  \"type\": \"Eau de Toilette\",
  \"type_variations\": [\"Eau de Toilette\", \"Eau de Toilette Vaporisateur\", \"EDT\"],
  \"variation\": \"100ml\",
  \"is_coffret\": false
}

EXEMPLE 3 :
Input: \"Dior - J'adore - Eau de Parfum 50ml\"
Output:
{
  \"vendor\": \"Dior\",
  \"vendor_variations\": [\"Dior\", \"Christian Dior\"],
  \"name\": \"J'adore\",
  \"name_variations\": [\"J'adore\", \"Jadore\"],
  \"type\": \"Eau de Parfum\",
  \"type_variations\": [\"Eau de Parfum\", \"Parfum\", \"EDP\"],
  \"variation\": \"50ml\",
  \"is_coffret\": false
}

IMPORTANT : Fournis TOUJOURS les variations possibles pour vendor, name et type pour augmenter les chances de matching.

Maintenant, traite ce produit :"
                    ]
                ],
                'temperature' => 0.3,
                'max_tokens' => 800
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
                
                // Normaliser les données extraites
                $this->normalizeExtractedData();
                
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

    private function normalizeExtractedData()
    {
        if (!$this->extractedData) {
            return;
        }

        // S'assurer que les variations existent
        if (!isset($this->extractedData['vendor_variations'])) {
            $this->extractedData['vendor_variations'] = [$this->extractedData['vendor'] ?? ''];
        }
        
        if (!isset($this->extractedData['name_variations'])) {
            $this->extractedData['name_variations'] = [$this->extractedData['name'] ?? ''];
        }
        
        if (!isset($this->extractedData['type_variations'])) {
            $this->extractedData['type_variations'] = [$this->extractedData['type'] ?? ''];
        }

        // S'assurer que is_coffret est un booléen
        if (!isset($this->extractedData['is_coffret'])) {
            $this->extractedData['is_coffret'] = $this->detectCoffret($this->productName);
        }
    }

    private function detectCoffret(string $text): bool
    {
        $coffretKeywords = [
            'coffret', 'set', 'kit', 'duo', 'trio', 'routine', 
            'pack', 'bundle', 'collection', 'ensemble', 'box',
            'cadeau', 'gift', 'discovery', 'découverte', ' + ', ' & '
        ];

        $lowerText = strtolower($text);
        
        foreach ($coffretKeywords as $keyword) {
            if (strpos($lowerText, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    private function searchMatchingProducts()
    {
        if (!$this->extractedData) {
            return;
        }

        $vendorVariations = $this->extractedData['vendor_variations'] ?? [];
        $nameVariations = $this->extractedData['name_variations'] ?? [];
        $typeVariations = $this->extractedData['type_variations'] ?? [];
        $isCoffret = $this->extractedData['is_coffret'] ?? false;

        if (empty($vendorVariations)) {
            \Log::warning('Aucune variation de vendor', [
                'product_name' => $this->productName,
                'extracted_data' => $this->extractedData
            ]);
            $this->matchingProducts = [];
            $this->bestMatch = null;
            return;
        }

        \Log::info('Recherche avec variations', [
            'vendor_variations' => $vendorVariations,
            'name_variations' => $nameVariations,
            'type_variations' => $typeVariations,
            'is_coffret' => $isCoffret
        ]);

        // Recherche progressive avec les variations
        $candidates = collect();

        // Niveau 1: Essayer chaque combinaison vendor + name
        foreach ($vendorVariations as $vendor) {
            foreach ($nameVariations as $name) {
                $results = Product::query()
                    ->whereRaw('LOWER(vendor) LIKE ?', ['%' . strtolower($vendor) . '%'])
                    ->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($name) . '%'])
                    ->limit(100)
                    ->get();
                
                $candidates = $candidates->merge($results);
                
                if ($results->count() > 0) {
                    \Log::info('Résultats niveau 1', [
                        'vendor' => $vendor,
                        'name' => $name,
                        'count' => $results->count()
                    ]);
                }
            }
        }

        // Niveau 2: Si peu de résultats, chercher avec vendor + mots-clés du name
        if ($candidates->count() < 5) {
            foreach ($vendorVariations as $vendor) {
                foreach ($nameVariations as $name) {
                    $nameWords = array_filter(
                        explode(' ', strtolower($name)), 
                        fn($word) => strlen($word) > 2 && !in_array($word, ['pour', 'les', 'des', 'une', 'coffret', 'set'])
                    );
                    
                    if (!empty($nameWords)) {
                        $query = Product::whereRaw('LOWER(vendor) LIKE ?', ['%' . strtolower($vendor) . '%']);
                        
                        foreach ($nameWords as $word) {
                            $query->where(function($q) use ($word) {
                                $q->whereRaw('LOWER(name) LIKE ?', ['%' . $word . '%'])
                                  ->orWhereRaw('LOWER(type) LIKE ?', ['%' . $word . '%']);
                            });
                        }
                        
                        $results = $query->limit(100)->get();
                        $candidates = $candidates->merge($results);
                        
                        if ($results->count() > 0) {
                            \Log::info('Résultats niveau 2', [
                                'vendor' => $vendor,
                                'name_words' => $nameWords,
                                'count' => $results->count()
                            ]);
                        }
                    }
                }
            }
        }

        // Niveau 3: Si toujours peu de résultats, chercher seulement avec vendor
        if ($candidates->count() < 10) {
            foreach ($vendorVariations as $vendor) {
                $results = Product::whereRaw('LOWER(vendor) LIKE ?', ['%' . strtolower($vendor) . '%'])
                    ->limit(100)
                    ->get();
                
                $candidates = $candidates->merge($results);
                
                if ($results->count() > 0) {
                    \Log::info('Résultats niveau 3', [
                        'vendor' => $vendor,
                        'count' => $results->count()
                    ]);
                }
            }
        }

        // Dédupliquer les candidats
        $candidates = $candidates->unique('id');

        \Log::info('Total candidats après recherche', [
            'count' => $candidates->count()
        ]);

        if ($candidates->isEmpty()) {
            \Log::warning('Aucun candidat trouvé');
            $this->matchingProducts = [];
            $this->bestMatch = null;
            return;
        }

        // Utiliser OpenAI pour matcher les produits avec les variations
        $scoredProducts = $this->matchProductsWithAI(
            $candidates, 
            $vendorVariations, 
            $nameVariations, 
            $typeVariations, 
            $this->extractedData['variation'] ?? '',
            $isCoffret
        );

        if (!empty($scoredProducts)) {
            $this->matchingProducts = array_column($scoredProducts, 'product');
            $this->bestMatch = $scoredProducts[0]['product'] ?? null;
            
            \Log::info('Matching réussi', [
                'matches' => count($scoredProducts)
            ]);
        } else {
            \Log::warning('Aucun match après AI');
            $this->matchingProducts = [];
            $this->bestMatch = null;
        }
    }

    private function matchProductsWithAI($candidates, $vendorVariations, $nameVariations, $typeVariations, $variation, $isCoffret)
    {
        $productsList = $candidates->map(function($product, $index) {
            return [
                'id' => $index,
                'vendor' => $product->vendor,
                'name' => $product->name,
                'type' => $product->type,
                'variation' => $product->variation,
                'product_id' => $product->id
            ];
        })->toArray();

        \Log::info('Envoi à OpenAI pour matching avec variations', [
            'vendor_variations' => $vendorVariations,
            'name_variations' => $nameVariations,
            'type_variations' => $typeVariations,
            'candidates_count' => count($productsList)
        ]);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            ])->timeout(30)->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Tu es un expert en matching de produits cosmétiques. Tu dois analyser les produits candidats et retourner ceux qui correspondent aux variations fournies. Sois TRÈS FLEXIBLE sur les variations de vendor, name et type. Réponds UNIQUEMENT avec un tableau JSON des IDs correspondants, sans markdown.'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Produit recherché avec VARIATIONS:

**VENDOR** (accepter N'IMPORTE LAQUELLE de ces variations):
" . json_encode($vendorVariations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "

**NAME** (accepter N'IMPORTE LAQUELLE de ces variations ou une partie):
" . json_encode($nameVariations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "

**TYPE** (accepter N'IMPORTE LAQUELLE de ces variations ou similaire):
" . json_encode($typeVariations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "

Variation: {$variation}
Est un coffret: " . ($isCoffret ? 'OUI' : 'NON') . "

Produits candidats:
" . json_encode($productsList, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "

RÈGLES DE MATCHING FLEXIBLES:

1. **VENDOR** - TRÈS FLEXIBLE:
   - Le vendor du produit peut correspondre à N'IMPORTE QUELLE variation fournie
   - \"Jean Paul Gaultier\" match \"Jean Paul\" ✅
   - \"Jean Paul Gaultier\" match \"Gaultier\" ✅
   - \"AZZARO\" match \"Azzaro\" ✅
   - Ignorer totalement la casse
   - Une correspondance partielle suffit

2. **NAME** - ULTRA FLEXIBLE:
   - Le name du produit peut contenir UNE PARTIE de n'importe quelle variation
   - \"Divine\" peut matcher \"Coffret Divine\" ✅
   - \"Gaultier Divine\" peut matcher \"Divine\" ✅
   - \"Chrome Intense\" peut matcher \"Chrome\" ✅
   - Les mots principaux doivent être présents (ignorer mots-outils)
   - Ignorer totalement la casse
   - Si le name du produit contient AU MOINS 50% des mots significatifs d'une variation, c'est OK

3. **TYPE** - FLEXIBLE sur la catégorie:
   - Le type peut correspondre à UNE variation ou être similaire
   - \"Eau de Parfum\" match \"Parfum\" ✅
   - \"Eau de Toilette Vaporisateur\" match \"Eau de Toilette\" ✅
   - \"Lait pour le corps\" match \"Lait corps\" ✅
   - Ignorer la casse et les articles (le, la, pour, de)
   - MAIS attention aux catégories incompatibles:
     * Parfum ≠ Déodorant ❌
     * Parfum ≠ Gel douche ❌
     * Soin visage ≠ Parfum ❌

4. **VARIATION** - IGNORER:
   - Ne pas rejeter pour différence de contenance
   - 50ml = 100ml = 200ml pour le matching

5. **COFFRET**:
   - Si coffret recherché, privilégier les coffrets
   - Mais ne pas rejeter complètement les produits unitaires si bon match

STRATÉGIE DE MATCHING:

1. Pour chaque produit candidat, vérifier:
   - Son vendor correspond-il à UNE des variations vendor? (flexible)
   - Son name contient-il des mots d'UNE des variations name? (très flexible)
   - Son type est-il compatible avec UNE des variations type? (flexible)

2. Scoring:
   - Match parfait (vendor + name + type) → score 100
   - Match vendor + name partiel + type → score 85
   - Match vendor + mots-clés name + type similaire → score 70
   - Match vendor + type compatible → score 50

3. Retourner les produits avec score ≥ 50, triés par score décroissant

IMPORTANT:
- Sois GÉNÉREUX dans l'acceptation des matchs
- Une correspondance partielle INTELLIGENTE vaut mieux qu'aucun résultat
- Privilégie les produits où le maximum d'éléments correspondent
- En cas de doute entre 2 produits, retourne les deux

Format de réponse: [id1, id2, id3, ...]
Si vraiment aucun match raisonnable: []"
                    ]
                ],
                'temperature' => 0.2,
                'max_tokens' => 1000
            ]);

            if (!$response->successful()) {
                \Log::error('Erreur API OpenAI lors du matching', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return [];
            }

            $data = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? '';
            
            \Log::info('Réponse OpenAI matching', [
                'raw_content' => $content
            ]);
            
            $content = preg_replace('/```json\s*|\s*```/', '', $content);
            $content = trim($content);
            
            $matchedIds = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($matchedIds)) {
                \Log::error('Erreur parsing JSON du matching', [
                    'content' => $content,
                    'error' => json_last_error_msg()
                ]);
                return [];
            }

            $scoredProducts = [];
            $score = 100;
            
            foreach ($matchedIds as $id) {
                if (isset($candidates[$id])) {
                    $product = $candidates[$id];
                    $scoredProducts[] = [
                        'product' => $product->toArray(),
                        'score' => $score
                    ];
                    
                    \Log::info('Produit matché', [
                        'id' => $product->id,
                        'vendor' => $product->vendor,
                        'name' => $product->name,
                        'type' => $product->type,
                        'score' => $score
                    ]);
                    
                    $score -= 5;
                }
            }

            return $scoredProducts;

        } catch (\Exception $e) {
            \Log::error('Erreur lors du matching AI', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    public function selectProduct($productId)
    {
        $product = Product::find($productId);
        
        if ($product) {
            session()->flash('success', 'Produit sélectionné : ' . $product->name);
            $this->bestMatch = $product;
            
            $this->dispatch('product-selected', productId: $productId);
        }
    }

}; ?>

<div class="p-6 bg-white rounded-lg shadow">
    <div class="mb-4">
        <h2 class="text-xl font-bold mb-2">Extraction et recherche de produit</h2>
        <p class="text-gray-600">Produit: {{ $productName }}</p>
        @if($productPrice)
            <p class="text-sm text-gray-500">Prix: {{ $productPrice }}</p>
        @endif
    </div>

    <button 
        wire:click="extractSearchTerme"
        wire:loading.attr="disabled"
        class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600 disabled:opacity-50 transition"
    >
        <span wire:loading.remove wire:target="extractSearchTerme">Extraire et rechercher</span>
        <span wire:loading wire:target="extractSearchTerme">
            <svg class="inline animate-spin h-4 w-4 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            Extraction en cours...
        </span>
    </button>

    @if(session('error'))
        <div class="mt-4 p-4 bg-red-100 text-red-700 rounded border border-red-300">
            <strong>Erreur:</strong> {{ session('error') }}
        </div>
    @endif

    @if(session('success'))
        <div class="mt-4 p-4 bg-green-100 text-green-700 rounded border border-green-300">
            {{ session('success') }}
        </div>
    @endif

    @if($extractedData)
        <div class="mt-6 p-4 bg-gray-50 rounded border border-gray-200">
            <h3 class="font-bold mb-3 flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                </svg>
                Critères extraits avec variations :
            </h3>
            
            <div class="space-y-3">
                <!-- Vendor -->
                <div class="p-3 bg-white rounded border border-blue-200">
                    <span class="font-semibold text-blue-700">Vendor:</span>
                    <div class="mt-1 flex flex-wrap gap-2">
                        @foreach(($extractedData['vendor_variations'] ?? []) as $variation)
                            <span class="px-2 py-1 bg-blue-50 text-blue-800 text-sm rounded border border-blue-300">
                                {{ $variation }}
                            </span>
                        @endforeach
                    </div>
                </div>

                <!-- Name -->
                <div class="p-3 bg-white rounded border border-green-200">
                    <span class="font-semibold text-green-700">Name:</span>
                    <div class="mt-1 flex flex-wrap gap-2">
                        @foreach(($extractedData['name_variations'] ?? []) as $variation)
                            <span class="px-2 py-1 bg-green-50 text-green-800 text-sm rounded border border-green-300">
                                {{ $variation }}
                            </span>
                        @endforeach
                    </div>
                </div>

                <!-- Type -->
                <div class="p-3 bg-white rounded border border-purple-200">
                    <span class="font-semibold text-purple-700">Type:</span>
                    <div class="mt-1 flex flex-wrap gap-2">
                        @foreach(($extractedData['type_variations'] ?? []) as $variation)
                            <span class="px-2 py-1 bg-purple-50 text-purple-800 text-sm rounded border border-purple-300">
                                {{ $variation }}
                            </span>
                        @endforeach
                    </div>
                </div>

                <!-- Variation et Coffret -->
                <div class="grid grid-cols-2 gap-3">
                    <div class="p-2 bg-white rounded">
                        <span class="font-semibold text-gray-700">Variation:</span> 
                        <span class="text-gray-900">{{ $extractedData['variation'] ?? 'N/A' }}</span>
                    </div>
                    <div class="p-2 bg-white rounded">
                        <span class="font-semibold text-gray-700">Coffret:</span> 
                        @if($extractedData['is_coffret'] ?? false)
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                Oui
                            </span>
                        @else
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                Non
                            </span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if($bestMatch)
        <div class="mt-6 p-4 bg-green-50 border-2 border-green-500 rounded-lg">
            <h3 class="font-bold text-green-700 mb-3 flex items-center gap-2">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                </svg>
                Meilleur résultat trouvé
            </h3>
            <div class="flex items-start gap-4">
                @if($bestMatch['image_url'])
                    <img src="{{ $bestMatch['image_url'] }}" alt="{{ $bestMatch['name'] }}" class="w-24 h-24 object-cover rounded-lg shadow">
                @else
                    <div class="w-24 h-24 bg-gray-200 rounded-lg flex items-center justify-center">
                        <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                @endif
                <div class="flex-1">
                    <p class="font-bold text-lg">{{ $bestMatch['vendor'] }} - {{ $bestMatch['name'] }}</p>
                    <p class="text-sm text-gray-600 mt-1">
                        <span class="font-medium">{{ $bestMatch['type'] }}</span> | 
                        <span>{{ $bestMatch['variation'] }}</span>
                    </p>
                    <p class="text-lg font-bold text-green-600 mt-2">{{ number_format((float)$bestMatch['prix_ht'], 2) }} {{ $bestMatch['currency'] }}</p>
                    @if($bestMatch['url'])
                        <a href="{{ $bestMatch['url'] }}" target="_blank" class="inline-flex items-center gap-1 text-sm text-blue-600 hover:text-blue-800 hover:underline mt-2">
                            Voir le produit
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                            </svg>
                        </a>
                    @endif
                    <p class="text-xs text-gray-400 mt-1">ID: {{ $bestMatch['id'] }}</p>
                </div>
            </div>
        </div>
    @endif

    @if(!empty($matchingProducts) && count($matchingProducts) > 1)
        <div class="mt-6">
            <h3 class="font-bold mb-3 text-gray-700">
                Autres résultats trouvés ({{ count($matchingProducts) - 1 }}) :
            </h3>
            <div class="space-y-2 max-h-96 overflow-y-auto border border-gray-200 rounded-lg p-2">
                @foreach($matchingProducts as $product)
                    @if($bestMatch && $bestMatch['id'] !== $product['id'])
                        <div 
                            wire:click="selectProduct({{ $product['id'] }})"
                            class="p-3 border rounded-lg hover:bg-blue-50 hover:border-blue-300 cursor-pointer transition bg-white"
                        >
                            <div class="flex items-center gap-3">
                                @if($product['image_url'])
                                    <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}" class="w-16 h-16 object-cover rounded shadow-sm">
                                @else
                                    <div class="w-16 h-16 bg-gray-100 rounded flex items-center justify-center">
                                        <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                        </svg>
                                    </div>
                                @endif
                                <div class="flex-1 min-w-0">
                                    <p class="font-medium text-sm truncate">{{ $product['vendor'] }} - {{ $product['name'] }}</p>
                                    <p class="text-xs text-gray-500 truncate">{{ $product['type'] }} | {{ $product['variation'] }}</p>
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <p class="font-bold text-sm whitespace-nowrap">{{ number_format((float)$product['prix_ht'], 2) }} {{ $product['currency'] }}</p>
                                    <p class="text-xs text-gray-400">ID: {{ $product['id'] }}</p>
                                </div>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    @endif

    @if($extractedData && empty($matchingProducts))
        <div class="mt-6 p-4 bg-yellow-50 border-2 border-yellow-300 rounded-lg">
            <div class="flex items-start gap-3">
                <svg class="w-6 h-6 text-yellow-600 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                </svg>
                <div>
                    <p class="font-semibold text-yellow-800">Aucun produit trouvé</p>
                    <p class="text-sm text-yellow-700 mt-1">Aucun produit ne correspond aux variations extraites. Vérifiez les données ou essayez une recherche manuelle.</p>
                </div>
            </div>
        </div>
    @endif
</div>