<?php

use Livewire\Volt\Component;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

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
                        'content' => 'Tu es un expert en extraction de données de produits cosmétiques. Tu dois extraire vendor, name, variation et type de manière FLEXIBLE pour une recherche FULLTEXT efficace. Réponds UNIQUEMENT avec un objet JSON valide, sans markdown.'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Analyse ce nom de produit et extrait les informations pour une recherche FULLTEXT MySQL optimale :

Nom du produit : {$this->productName}

OBJECTIF : Créer des termes de recherche FLEXIBLES pour MySQL FULLTEXT BOOLEAN MODE

RÈGLES D'EXTRACTION :

1. **VENDOR** - Extraire toutes les variations possibles :
   - Nom complet : \"Jean Paul Gaultier\"
   - Variations : [\"Jean Paul Gaultier\", \"Jean Paul\", \"Gaultier\", \"JPG\"]
   - Pour acronymes connus : YSL, JPG, etc.

2. **NAME** - Extraire le nom principal + variations :
   - Sans le vendor : \"Divine\", \"Coffret Divine\"
   - Mots-clés principaux seulement
   - Variations : [\"Divine\", \"Coffret Divine\", \"Gaultier Divine\"]

3. **TYPE** - Catégorie produit avec synonymes :
   - \"Eau de Parfum\" → [\"Eau de Parfum\", \"Parfum\", \"EDP\"]
   - \"Eau de Toilette\" → [\"Eau de Toilette\", \"EDT\", \"Toilette\"]
   - \"Lait pour le corps\" → [\"Lait corps\", \"Lait pour le corps\", \"Body Lotion\"]

4. **VARIATION** - Contenance :
   - Extraire : \"50ml\", \"100ml\", etc.
   - Pour coffrets : \"50ml + 75ml\" ou juste \"50ml\"

5. **SEARCH_TERMS** - Mots-clés pour recherche FULLTEXT :
   - Combiner les termes les plus importants
   - Format BOOLEAN : \"+vendor +name type\"
   - Exemples :
     * \"+Gaultier +Divine Parfum\"
     * \"+Dior +Sauvage Toilette\"
     * \"+Azzaro +Chrome EDT\"

EXEMPLES :

EXEMPLE 1 (Coffret) :
Input: \"Jean Paul Gaultier - Coffret Gaultier Divine - Eau de Parfum 50ml + Lait pour le corps 75ml\"
Output:
{
  \"vendor\": \"Jean Paul Gaultier\",
  \"vendor_variations\": [\"Jean Paul Gaultier\", \"Gaultier\", \"Jean Paul\", \"JPG\"],
  \"name\": \"Divine\",
  \"name_variations\": [\"Divine\", \"Coffret Divine\", \"Gaultier Divine\"],
  \"type\": \"Eau de Parfum\",
  \"type_variations\": [\"Eau de Parfum\", \"Parfum\", \"EDP\"],
  \"variation\": \"50ml\",
  \"is_coffret\": true,
  \"search_terms\": \"+Gaultier +Divine Parfum coffret\",
  \"keywords\": [\"Gaultier\", \"Divine\", \"Parfum\", \"coffret\"]
}

EXEMPLE 2 :
Input: \"AZZARO - CHROME - Eau de Toilette Vaporisateur 100ml\"
Output:
{
  \"vendor\": \"AZZARO\",
  \"vendor_variations\": [\"AZZARO\", \"Azzaro\"],
  \"name\": \"CHROME\",
  \"name_variations\": [\"CHROME\", \"Chrome\"],
  \"type\": \"Eau de Toilette\",
  \"type_variations\": [\"Eau de Toilette\", \"EDT\", \"Toilette\"],
  \"variation\": \"100ml\",
  \"is_coffret\": false,
  \"search_terms\": \"+Azzaro +Chrome Toilette\",
  \"keywords\": [\"Azzaro\", \"Chrome\", \"Toilette\"]
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
  \"is_coffret\": false,
  \"search_terms\": \"+Dior +Jadore Parfum\",
  \"keywords\": [\"Dior\", \"Jadore\", \"Parfum\"]
}

IMPORTANT :
- search_terms doit être optimisé pour MySQL FULLTEXT BOOLEAN MODE
- Utiliser + pour les termes obligatoires (vendor et name principalement)
- keywords = liste des mots-clés les plus importants pour le matching
- Toujours fournir des variations pour augmenter les chances de match

Maintenant, traite ce produit :"
                    ]
                ],
                'temperature' => 0.3,
                'max_tokens' => 800
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $content = $data['choices'][0]['message']['content'];
                
                $content = preg_replace('/```json\s*|\s*```/', '', $content);
                $content = trim($content);
                
                $this->extractedData = json_decode($content, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('Erreur de parsing JSON: ' . json_last_error_msg());
                }
                
                $this->normalizeExtractedData();
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

        if (!isset($this->extractedData['is_coffret'])) {
            $this->extractedData['is_coffret'] = $this->detectCoffret($this->productName);
        }

        // Générer search_terms si absent
        if (!isset($this->extractedData['search_terms'])) {
            $this->extractedData['search_terms'] = $this->generateSearchTerms();
        }

        // Générer keywords si absent
        if (!isset($this->extractedData['keywords'])) {
            $this->extractedData['keywords'] = $this->extractKeywords();
        }
    }

    private function generateSearchTerms(): string
    {
        $vendor = $this->extractedData['vendor'] ?? '';
        $name = $this->extractedData['name'] ?? '';
        $type = $this->extractedData['type'] ?? '';
        
        // Nettoyer et simplifier
        $vendor = trim($vendor);
        $name = trim($name);
        $type = $this->simplifyType($type);
        
        // Format BOOLEAN : +vendor +name type
        $terms = [];
        if (!empty($vendor)) {
            $terms[] = "+{$vendor}";
        }
        if (!empty($name)) {
            $terms[] = "+{$name}";
        }
        if (!empty($type)) {
            $terms[] = $type;
        }
        
        return implode(' ', $terms);
    }

    private function simplifyType(string $type): string
    {
        $type = strtolower($type);
        
        $simplifications = [
            'eau de parfum' => 'Parfum',
            'eau de toilette' => 'Toilette',
            'lait pour le corps' => 'Lait',
            'gel douche' => 'Gel',
            'déodorant' => 'Deo',
        ];
        
        foreach ($simplifications as $original => $simplified) {
            if (strpos($type, $original) !== false) {
                return $simplified;
            }
        }
        
        return $type;
    }

    private function extractKeywords(): array
    {
        $keywords = [];
        
        if (isset($this->extractedData['vendor'])) {
            $keywords[] = $this->extractedData['vendor'];
        }
        
        if (isset($this->extractedData['name'])) {
            $keywords[] = $this->extractedData['name'];
        }
        
        if (isset($this->extractedData['type'])) {
            $keywords[] = $this->simplifyType($this->extractedData['type']);
        }
        
        if ($this->extractedData['is_coffret'] ?? false) {
            $keywords[] = 'coffret';
        }
        
        return array_filter($keywords);
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

        $searchTerms = $this->extractedData['search_terms'] ?? '';
        $vendorVariations = $this->extractedData['vendor_variations'] ?? [];
        $nameVariations = $this->extractedData['name_variations'] ?? [];
        $typeVariations = $this->extractedData['type_variations'] ?? [];

        if (empty($searchTerms) && empty($vendorVariations)) {
            \Log::warning('Aucun terme de recherche disponible', [
                'extracted_data' => $this->extractedData
            ]);
            $this->matchingProducts = [];
            $this->bestMatch = null;
            return;
        }

        \Log::info('Recherche FULLTEXT', [
            'search_terms' => $searchTerms,
            'vendor_variations' => $vendorVariations,
            'name_variations' => $nameVariations,
            'type_variations' => $typeVariations
        ]);

        // Stratégie de recherche progressive avec FULLTEXT
        $candidates = collect();

        // NIVEAU 1: Recherche FULLTEXT avec les termes optimisés
        if (!empty($searchTerms)) {
            $results = $this->executeFulltextSearch($searchTerms);
            $candidates = $candidates->merge($results);
            
            \Log::info('Résultats FULLTEXT niveau 1', [
                'search_terms' => $searchTerms,
                'count' => count($results)
            ]);
        }

        // NIVEAU 2: Si peu de résultats, essayer avec chaque variation de vendor + name
        if ($candidates->count() < 10) {
            foreach ($vendorVariations as $vendor) {
                foreach ($nameVariations as $name) {
                    $terms = "+{$vendor} +{$name}";
                    $results = $this->executeFulltextSearch($terms);
                    $candidates = $candidates->merge($results);
                    
                    if (count($results) > 0) {
                        \Log::info('Résultats FULLTEXT niveau 2', [
                            'terms' => $terms,
                            'count' => count($results)
                        ]);
                    }
                }
            }
        }

        // NIVEAU 3: Si toujours peu de résultats, chercher seulement avec vendor
        if ($candidates->count() < 10) {
            foreach ($vendorVariations as $vendor) {
                $terms = "+{$vendor}";
                $results = $this->executeFulltextSearch($terms);
                $candidates = $candidates->merge($results);
                
                if (count($results) > 0) {
                    \Log::info('Résultats FULLTEXT niveau 3', [
                        'terms' => $terms,
                        'count' => count($results)
                    ]);
                }
            }
        }

        // NIVEAU 4: Recherche de secours avec LIKE si FULLTEXT ne donne rien
        if ($candidates->isEmpty() && !empty($vendorVariations)) {
            $vendor = $vendorVariations[0];
            $results = $this->executeLikeSearch($vendor);
            $candidates = $candidates->merge($results);
            
            \Log::info('Résultats LIKE (secours)', [
                'vendor' => $vendor,
                'count' => count($results)
            ]);
        }

        // Dédupliquer par ID
        $uniqueCandidates = $candidates->unique(function($item) {
            return $item->id ?? $item['id'] ?? null;
        })->values();

        \Log::info('Total candidats après déduplication', [
            'count' => $uniqueCandidates->count()
        ]);

        if ($uniqueCandidates->isEmpty()) {
            \Log::warning('Aucun candidat trouvé après toutes les recherches');
            $this->matchingProducts = [];
            $this->bestMatch = null;
            return;
        }

        // Utiliser OpenAI pour matcher et scorer les produits
        $scoredProducts = $this->matchProductsWithAI(
            $uniqueCandidates,
            $vendorVariations,
            $nameVariations,
            $typeVariations,
            $this->extractedData['variation'] ?? '',
            $this->extractedData['is_coffret'] ?? false
        );

        if (!empty($scoredProducts)) {
            $this->matchingProducts = array_column($scoredProducts, 'product');
            $this->bestMatch = $scoredProducts[0]['product'] ?? null;
            
            \Log::info('Matching réussi', [
                'matches' => count($scoredProducts),
                'best_match' => $this->bestMatch['name'] ?? 'N/A'
            ]);
        } else {
            \Log::warning('Aucun match après AI');
            $this->matchingProducts = [];
            $this->bestMatch = null;
        }
    }

    private function executeFulltextSearch(string $searchTerms): array
    {
        try {
            $query = "
                SELECT 
                    lp.*,
                    ws.name as site_name,
                    lp.image_url as image_url,
                    lp.url as product_url,
                    MATCH (lp.name, lp.vendor, lp.type, lp.variation) 
                        AGAINST (? IN BOOLEAN MODE) as relevance
                FROM last_price_scraped_product lp
                LEFT JOIN web_site ws ON lp.web_site_id = ws.id
                WHERE MATCH (lp.name, lp.vendor, lp.type, lp.variation) 
                    AGAINST (? IN BOOLEAN MODE)
                AND (lp.variation != 'Standard' OR lp.variation IS NULL OR lp.variation = '')
                ORDER BY relevance DESC, lp.prix_ht ASC
                LIMIT 50
            ";

            $results = DB::select($query, [$searchTerms, $searchTerms]);
            
            return collect($results)->map(function($item) {
                return (object)[
                    'id' => $item->id,
                    'vendor' => $item->vendor,
                    'name' => $item->name,
                    'type' => $item->type,
                    'variation' => $item->variation,
                    'prix_ht' => $item->prix_ht,
                    'currency' => $item->currency ?? 'EUR',
                    'image_url' => $item->image_url,
                    'url' => $item->product_url,
                    'site_name' => $item->site_name,
                    'relevance' => $item->relevance ?? 0
                ];
            })->toArray();
            
        } catch (\Exception $e) {
            \Log::error('Erreur recherche FULLTEXT', [
                'message' => $e->getMessage(),
                'search_terms' => $searchTerms
            ]);
            return [];
        }
    }

    private function executeLikeSearch(string $vendor): array
    {
        try {
            $query = "
                SELECT 
                    lp.*,
                    ws.name as site_name,
                    lp.image_url as image_url,
                    lp.url as product_url
                FROM last_price_scraped_product lp
                LEFT JOIN web_site ws ON lp.web_site_id = ws.id
                WHERE LOWER(lp.vendor) LIKE ?
                AND (lp.variation != 'Standard' OR lp.variation IS NULL OR lp.variation = '')
                ORDER BY lp.prix_ht ASC
                LIMIT 50
            ";

            $results = DB::select($query, ['%' . strtolower($vendor) . '%']);
            
            return collect($results)->map(function($item) {
                return (object)[
                    'id' => $item->id,
                    'vendor' => $item->vendor,
                    'name' => $item->name,
                    'type' => $item->type,
                    'variation' => $item->variation,
                    'prix_ht' => $item->prix_ht,
                    'currency' => $item->currency ?? 'EUR',
                    'image_url' => $item->image_url,
                    'url' => $item->product_url,
                    'site_name' => $item->site_name,
                    'relevance' => 0
                ];
            })->toArray();
            
        } catch (\Exception $e) {
            \Log::error('Erreur recherche LIKE', [
                'message' => $e->getMessage(),
                'vendor' => $vendor
            ]);
            return [];
        }
    }

    private function matchProductsWithAI($candidates, $vendorVariations, $nameVariations, $typeVariations, $variation, $isCoffret)
    {
        $productsList = collect($candidates)->map(function($product, $index) {
            return [
                'id' => $index,
                'vendor' => $product->vendor ?? '',
                'name' => $product->name ?? '',
                'type' => $product->type ?? '',
                'variation' => $product->variation ?? '',
                'product_id' => $product->id ?? 0,
                'relevance' => $product->relevance ?? 0
            ];
        })->toArray();

        \Log::info('Envoi à OpenAI pour matching', [
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
                        'content' => 'Tu es un expert en matching de produits cosmétiques. Analyse les produits candidats et retourne les IDs de ceux qui correspondent le mieux aux variations fournies. Sois FLEXIBLE mais INTELLIGENT. Réponds avec un tableau JSON des IDs, sans markdown.'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Produit recherché (VARIATIONS MULTIPLES):

**VENDOR** (accepter N'IMPORTE LAQUELLE):
" . json_encode($vendorVariations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "

**NAME** (accepter si contient des mots-clés):
" . json_encode($nameVariations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "

**TYPE** (accepter si catégorie compatible):
" . json_encode($typeVariations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "

Variation: {$variation}
Coffret: " . ($isCoffret ? 'OUI' : 'NON') . "

Produits candidats (déjà filtrés par FULLTEXT, donc pertinents):
" . json_encode(array_slice($productsList, 0, 30), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "

RÈGLES DE MATCHING:

1. **VENDOR** - TRÈS FLEXIBLE:
   - Le vendor du candidat doit correspondre à UNE variation
   - Ignorer totalement la casse
   - \"Gaultier\" match \"Jean Paul Gaultier\" ✅
   - \"Jean Paul\" match \"Jean Paul Gaultier\" ✅

2. **NAME** - FLEXIBLE INTELLIGENT:
   - Le name du candidat doit contenir AU MOINS 50% des mots significatifs d'UNE variation
   - Ignorer: articles (le, la, de), mots courts (<3 lettres)
   - \"Divine\" peut être dans \"Coffret Divine\" ✅
   - \"Chrome Intense\" contient \"Chrome\" ✅
   - Ordre des mots pas important

3. **TYPE** - FLEXIBLE CATÉGORIE:
   - Le type doit être de la MÊME CATÉGORIE qu'une variation
   - \"Eau de Parfum\" ≈ \"Parfum\" ✅
   - \"Lait pour le corps\" ≈ \"Lait corps\" ✅
   - MAIS: Parfum ≠ Déodorant ❌

4. **SCORING**:
   - Match parfait (vendor + name + type exact): 100
   - Match vendor + 80%+ name + type compatible: 90
   - Match vendor + 60%+ name + type compatible: 75
   - Match vendor + mots-clés name + type: 60
   - Match vendor + type seulement: 40

5. **PRIORITÉ**:
   - Utilise le champ 'relevance' comme indicateur (FULLTEXT score)
   - En cas d'égalité, privilégie relevance plus élevée

STRATÉGIE:
1. Évalue chaque candidat avec les variations
2. Calcule un score de matching
3. Retourne les IDs avec score ≥ 60
4. Trie par score décroissant
5. Limite à 20 meilleurs résultats

Format réponse: [id1, id2, id3, ...]
Si aucun match valide: []"
                    ]
                ],
                'temperature' => 0.2,
                'max_tokens' => 1000
            ]);

            if (!$response->successful()) {
                \Log::error('Erreur API OpenAI matching', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return [];
            }

            $data = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? '';
            
            $content = preg_replace('/```json\s*|\s*```/', '', $content);
            $content = trim($content);
            
            $matchedIds = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($matchedIds)) {
                \Log::error('Erreur parsing JSON matching', [
                    'content' => $content,
                    'error' => json_last_error_msg()
                ]);
                return [];
            }

            \Log::info('IDs matchés par OpenAI', [
                'matched_ids' => $matchedIds,
                'count' => count($matchedIds)
            ]);

            $scoredProducts = [];
            $score = 100;
            
            foreach ($matchedIds as $id) {
                if (isset($candidates[$id])) {
                    $product = $candidates[$id];
                    
                    // Convertir en array si c'est un objet
                    $productArray = is_array($product) ? $product : (array)$product;
                    
                    $scoredProducts[] = [
                        'product' => $productArray,
                        'score' => $score
                    ];
                    
                    \Log::info('Produit matché', [
                        'id' => $productArray['id'],
                        'vendor' => $productArray['vendor'],
                        'name' => $productArray['name'],
                        'score' => $score,
                        'relevance' => $productArray['relevance'] ?? 0
                    ]);
                    
                    $score -= 3;
                }
            }

            return $scoredProducts;

        } catch (\Exception $e) {
            \Log::error('Erreur matching AI', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    public function selectProduct($productId)
    {
        // Chercher dans last_price_scraped_product
        $product = DB::table('last_price_scraped_product')->where('id', $productId)->first();
        
        if ($product) {
            session()->flash('success', 'Produit sélectionné : ' . $product->name);
            $this->bestMatch = (array)$product;
            
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
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
                Termes de recherche FULLTEXT :
            </h3>
            
            <div class="mb-4 p-3 bg-indigo-50 rounded border border-indigo-200">
                <span class="font-semibold text-indigo-700">Query BOOLEAN:</span>
                <code class="block mt-1 text-sm bg-white p-2 rounded border">{{ $extractedData['search_terms'] ?? 'N/A' }}</code>
            </div>

            <div class="space-y-3">
                <!-- Vendor Variations -->
                <div class="p-3 bg-white rounded border border-blue-200">
                    <span class="font-semibold text-blue-700">Vendor Variations:</span>
                    <div class="mt-1 flex flex-wrap gap-2">
                        @foreach(($extractedData['vendor_variations'] ?? []) as $variation)
                            <span class="px-2 py-1 bg-blue-50 text-blue-800 text-sm rounded border border-blue-300">
                                {{ $variation }}
                            </span>
                        @endforeach
                    </div>
                </div>

                <!-- Name Variations -->
                <div class="p-3 bg-white rounded border border-green-200">
                    <span class="font-semibold text-green-700">Name Variations:</span>
                    <div class="mt-1 flex flex-wrap gap-2">
                        @foreach(($extractedData['name_variations'] ?? []) as $variation)
                            <span class="px-2 py-1 bg-green-50 text-green-800 text-sm rounded border border-green-300">
                                {{ $variation }}
                            </span>
                        @endforeach
                    </div>
                </div>

                <!-- Type Variations -->
                <div class="p-3 bg-white rounded border border-purple-200">
                    <span class="font-semibold text-purple-700">Type Variations:</span>
                    <div class="mt-1 flex flex-wrap gap-2">
                        @foreach(($extractedData['type_variations'] ?? []) as $variation)
                            <span class="px-2 py-1 bg-purple-50 text-purple-800 text-sm rounded border border-purple-300">
                                {{ $variation }}
                            </span>
                        @endforeach
                    </div>
                </div>

                <!-- Keywords -->
                @if(isset($extractedData['keywords']))
                <div class="p-3 bg-white rounded border border-yellow-200">
                    <span class="font-semibold text-yellow-700">Keywords:</span>
                    <div class="mt-1 flex flex-wrap gap-2">
                        @foreach($extractedData['keywords'] as $keyword)
                            <span class="px-2 py-1 bg-yellow-50 text-yellow-800 text-sm rounded border border-yellow-300">
                                {{ $keyword }}
                            </span>
                        @endforeach
                    </div>
                </div>
                @endif

                <!-- Info supplémentaires -->
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
                Meilleur résultat
                @if(isset($bestMatch['relevance']) && $bestMatch['relevance'] > 0)
                    <span class="text-xs font-normal text-green-600">(Score FULLTEXT: {{ number_format($bestMatch['relevance'], 2) }})</span>
                @endif
            </h3>
            <div class="flex items-start gap-4">
                @if($bestMatch['image_url'] ?? false)
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
                    @if(isset($bestMatch['site_name']))
                        <p class="text-xs text-gray-500 mt-1">Source: {{ $bestMatch['site_name'] }}</p>
                    @endif
                    <p class="text-lg font-bold text-green-600 mt-2">{{ number_format((float)($bestMatch['prix_ht'] ?? 0), 2) }} {{ $bestMatch['currency'] ?? 'EUR' }}</p>
                    @if($bestMatch['url'] ?? false)
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
                                @if($product['image_url'] ?? false)
                                    <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}" class="w-16 h-16 object-cover rounded shadow-sm">
                                @else
                                    <div class="w-16 h-16 bg-gray-100 rounded flex items-center justify-center">
                                        <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                        </svg>
                                    </div>
                                @endif
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <p class="font-medium text-sm truncate">{{ $product['vendor'] }} - {{ $product['name'] }}</p>
                                        @if(isset($product['relevance']) && $product['relevance'] > 0)
                                            <span class="text-xs text-gray-500 flex-shrink-0">({{ number_format($product['relevance'], 1) }})</span>
                                        @endif
                                    </div>
                                    <p class="text-xs text-gray-500 truncate">{{ $product['type'] }} | {{ $product['variation'] }}</p>
                                    @if(isset($product['site_name']))
                                        <p class="text-xs text-gray-400">{{ $product['site_name'] }}</p>
                                    @endif
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <p class="font-bold text-sm whitespace-nowrap">{{ number_format((float)($product['prix_ht'] ?? 0), 2) }} {{ $product['currency'] ?? 'EUR' }}</p>
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
                    <p class="text-sm text-yellow-700 mt-1">La recherche FULLTEXT n'a retourné aucun résultat correspondant. Vérifiez que les index FULLTEXT sont correctement configurés sur la table.</p>
                </div>
            </div>
        </div>
    @endif
</div>