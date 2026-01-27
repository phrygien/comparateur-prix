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
    public $bestMatchScore = null;

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
        $this->bestMatchScore = null;
        
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            ])->timeout(30)->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Tu es un expert en extraction de données de produits cosmétiques. Le format du nom est généralement: "Vendor - Name - Type". Tu dois extraire vendor, name, variation et type. La variation (ml, g, etc.) se trouve souvent dans le type. Réponds UNIQUEMENT avec un objet JSON valide, sans markdown ni texte supplémentaire.'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Analyse ce nom de produit en le splitant par les tirets (-) et extrait les informations au format JSON strict :

Nom du produit : {$this->productName}

RÈGLES D'EXTRACTION :
1. Split par tiret (-) pour identifier les parties
2. Format attendu: Vendor - Name - Type
3. Extraire la variation (ml, g, cl, L, etc.) qui se trouve dans le Type
4. Séparer le Type de la variation

EXEMPLE 1:
Input: \"Dior - Sauvage - Eau de Parfum 100ml\"
Output:
{
  \"vendor\": \"Dior\",
  \"name\": \"Sauvage\",
  \"type\": \"Eau de Parfum\",
  \"variation\": \"100ml\",
  \"is_coffret\": false
}

EXEMPLE 2:
Input: \"Jennifer Lopez - Glow - Eau de Toilette Vaporisateur 100ml\"
Output:
{
  \"vendor\": \"Jennifer Lopez\",
  \"name\": \"Glow\",
  \"type\": \"Eau de Toilette Vaporisateur\",
  \"variation\": \"100ml\",
  \"is_coffret\": false
}

EXEMPLE 3:
Input: \"Shiseido - Vital Perfection - Coffret Soin Anti-Âge 50ml\"
Output:
{
  \"vendor\": \"Shiseido\",
  \"name\": \"Vital Perfection\",
  \"type\": \"Soin Anti-Âge\",
  \"variation\": \"50ml\",
  \"is_coffret\": true
}

DÉTECTION COFFRET:
- is_coffret = true si le texte contient: Coffret, Set, Kit, Duo, Trio, Routine, Pack, Bundle
- is_coffret = false sinon

Maintenant, traite ce produit en suivant ces règles exactement."
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
            
            // Essayer une recherche directe même si l'extraction échoue
            $this->fallbackSearch();
        } finally {
            $this->isLoading = false;
        }
    }

    public function findMoreMatches()
    {
        if ($this->extractedData) {
            $this->searchMatchingProducts(true); // Force une recherche plus large
        }
    }

    private function fallbackSearch()
    {
        \Log::info('Fallback search pour:', ['product_name' => $this->productName]);
        
        // Recherche directe par mots-clés dans le nom
        $keywords = $this->extractKeywords($this->productName);
        
        if (empty($keywords)) {
            return;
        }
        
        $query = Product::query();
        foreach ($keywords as $keyword) {
            if (strlen($keyword) > 2) {
                $query->orWhere(function($q) use ($keyword) {
                    $q->whereRaw('LOWER(vendor) LIKE ?', ['%' . strtolower($keyword) . '%'])
                      ->orWhereRaw('LOWER(name) LIKE ?', ['%' . strtolower($keyword) . '%'])
                      ->orWhereRaw('LOWER(type) LIKE ?', ['%' . strtolower($keyword) . '%']);
                });
            }
        }
        
        $candidates = $query->limit(30)->get();
        
        if ($candidates->isNotEmpty()) {
            $this->matchingProducts = $candidates->take(10)->map(function($product) {
                return $product->toArray();
            })->toArray();
            
            $this->bestMatch = $this->matchingProducts[0] ?? null;
            $this->bestMatchScore = 'Modérée (fallback)';
            
            session()->flash('info', 'Recherche de fallback exécutée avec ' . count($this->matchingProducts) . ' résultats');
        }
    }

    private function normalizeExtractedData()
    {
        if (!$this->extractedData) {
            return;
        }

        // Nettoyer et normaliser les champs
        foreach (['vendor', 'name', 'variation', 'type'] as $field) {
            if (isset($this->extractedData[$field])) {
                $this->extractedData[$field] = trim($this->extractedData[$field]);
            }
        }

        // S'assurer que is_coffret est un booléen
        if (!isset($this->extractedData['is_coffret'])) {
            $this->extractedData['is_coffret'] = $this->detectCoffret($this->productName);
        }

        // Double vérification de la détection des coffrets
        if (!$this->extractedData['is_coffret']) {
            $this->extractedData['is_coffret'] = $this->detectCoffret($this->productName);
        }
    }

    private function detectCoffret(string $text): bool
    {
        $coffretKeywords = [
            'coffret', 'set', 'kit', 'duo', 'trio', 'routine', 
            'pack', 'bundle', 'collection', 'ensemble', 'box',
            'cadeau', 'gift', 'discovery', 'découverte'
        ];

        $lowerText = strtolower($text);
        
        foreach ($coffretKeywords as $keyword) {
            if (strpos($lowerText, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    private function searchMatchingProducts($forceBroadSearch = false)
    {
        if (!$this->extractedData) {
            return;
        }

        $vendor = $this->extractedData['vendor'] ?? '';
        $name = $this->extractedData['name'] ?? '';
        $variation = $this->extractedData['variation'] ?? '';
        $type = $this->extractedData['type'] ?? '';
        $isCoffret = $this->extractedData['is_coffret'] ?? false;

        \Log::info('Recherche de produits avec critères:', [
            'vendor' => $vendor,
            'name' => $name,
            'type' => $type,
            'is_coffret' => $isCoffret,
            'force_broad' => $forceBroadSearch
        ]);

        // Si vendor vide, essayer d'extraire du name
        if (empty($vendor) && !empty($name)) {
            $vendor = $this->extractVendorFromName($name);
            \Log::info('Vendor extrait du name:', ['extracted_vendor' => $vendor]);
        }

        // Recherche large par mots-clés
        $keywords = $this->extractKeywords($vendor . ' ' . $name);
        
        $query = Product::query();
        
        if (!empty($keywords)) {
            foreach ($keywords as $keyword) {
                if (strlen($keyword) > 2) { // Ignorer les mots trop courts
                    $query->orWhere(function($q) use ($keyword) {
                        $q->whereRaw('LOWER(vendor) LIKE ?', ['%' . strtolower($keyword) . '%'])
                          ->orWhereRaw('LOWER(name) LIKE ?', ['%' . strtolower($keyword) . '%'])
                          ->orWhereRaw('LOWER(type) LIKE ?', ['%' . strtolower($keyword) . '%']);
                    });
                }
            }
        }
        
        $candidates = $query->limit($forceBroadSearch ? 150 : 100)->get();

        \Log::info('Candidats trouvés', [
            'keywords' => $keywords,
            'count' => $candidates->count(),
            'first_3' => $candidates->take(3)->pluck('name')
        ]);

        if ($candidates->isEmpty() || $forceBroadSearch) {
            // Recherche de dernier recours : tous les produits similaires
            $fallbackCandidates = Product::where(function($q) use ($vendor, $name) {
                if (!empty($vendor)) {
                    $q->orWhere('vendor', 'like', '%' . substr($vendor, 0, 3) . '%');
                }
                if (!empty($name)) {
                    $words = explode(' ', $name);
                    foreach ($words as $word) {
                        if (strlen($word) > 3) {
                            $q->orWhere('name', 'like', '%' . $word . '%');
                        }
                    }
                }
            })->limit(50)->get();
            
            if (!$fallbackCandidates->isEmpty()) {
                $candidates = $candidates->isEmpty() ? $fallbackCandidates : $candidates->merge($fallbackCandidates)->unique('id');
                \Log::info('Candidats après fallback:', ['count' => $candidates->count()]);
            }
        }

        if ($candidates->isEmpty()) {
            \Log::warning('Aucun candidat trouvé', [
                'vendor' => $vendor,
                'name' => $name
            ]);
            
            // Dernière tentative: chercher n'importe quel produit avec le premier mot du nom
            if (!empty($name)) {
                $firstWord = explode(' ', trim($name))[0];
                if (strlen($firstWord) > 2) {
                    $candidates = Product::where('name', 'like', '%' . $firstWord . '%')
                        ->orWhere('vendor', 'like', '%' . $firstWord . '%')
                        ->limit(20)->get();
                }
            }
        }

        if ($candidates->isEmpty()) {
            $this->matchingProducts = [];
            $this->bestMatch = null;
            $this->bestMatchScore = null;
            session()->flash('warning', 'Aucun produit trouvé dans la base de données.');
            return;
        }

        // Utiliser OpenAI pour matcher les produits
        $scoredProducts = $this->matchProductsWithAI($candidates, $vendor, $name, $type, $variation, $isCoffret);

        if (!empty($scoredProducts)) {
            $this->matchingProducts = array_column($scoredProducts, 'product');
            $this->bestMatch = $scoredProducts[0]['product'] ?? null;
            $this->bestMatchScore = $this->calculateConfidenceLevel($scoredProducts[0]['score'] ?? 0);
            
            \Log::info('Matching réussi', [
                'matches' => count($scoredProducts),
                'best_match_score' => $scoredProducts[0]['score'] ?? 0,
                'confidence' => $this->bestMatchScore
            ]);
        } else {
            \Log::warning('Aucun match après AI', [
                'candidates_count' => $candidates->count()
            ]);
            
            // Fallback: prendre les premiers candidats
            $this->matchingProducts = $candidates->take(5)->map(function($product) {
                return $product->toArray();
            })->toArray();
            
            if (!empty($this->matchingProducts)) {
                $this->bestMatch = $this->matchingProducts[0];
                $this->bestMatchScore = 'Faible (fallback)';
                session()->flash('info', 'Aucun match exact trouvé. Affichage des produits les plus proches.');
            }
        }
    }

    private function calculateConfidenceLevel($score)
    {
        if ($score >= 80) return 'Élevée';
        if ($score >= 60) return 'Modérée';
        if ($score >= 40) return 'Faible';
        return 'Très faible';
    }

    private function extractKeywords($text)
    {
        $text = strtolower($text);
        
        // Mots à exclure
        $stopWords = ['de', 'la', 'le', 'et', 'à', 'pour', 'avec', 'sur', 'par', 'dans', 'un', 'une', 'des', 'du', 'au'];
        
        $words = preg_split('/\s+|-/', $text);
        $keywords = array_filter($words, function($word) use ($stopWords) {
            return strlen($word) > 2 && !in_array($word, $stopWords);
        });
        
        return array_values(array_unique($keywords));
    }

    private function extractVendorFromName($name)
    {
        // Liste de marques courantes à reconnaître
        $knownBrands = [
            'Dior', 'Chanel', 'Guerlain', 'Yves Saint Laurent', 'YSL', 'Lancôme', 
            'Givenchy', 'Hermès', 'Prada', 'Armani', 'Versace', 'Dolce Gabbana',
            'Bulgari', 'Cartier', 'Montblanc', 'Paco Rabanne', 'Jean Paul Gaultier',
            'Azzaro', 'Caron', 'Nina Ricci', 'Thierry Mugler', 'Van Cleef Arpels',
            'Boucheron', 'Chloé', 'Diesel', 'Burberry', 'Calvin Klein', 'Hugo Boss',
            'Estée Lauder', 'Clinique', 'La Roche-Posay', 'Vichy', 'Bioderma',
            'Nivea', 'L\'Oréal', 'Maybelline', 'Rimmel', 'MAC', 'Sephora',
            'Nuxe', 'Caudalie', 'Clarins', 'Shiseido', 'SK-II', 'Kiehl\'s'
        ];
        
        $nameLower = strtolower($name);
        foreach ($knownBrands as $brand) {
            if (stripos($nameLower, strtolower($brand)) !== false) {
                return $brand;
            }
        }
        
        return '';
    }

    private function matchProductsWithAI($candidates, $vendor, $name, $type, $variation, $isCoffret)
    {
        // Préparer la liste des produits candidats pour OpenAI
        $productsList = $candidates->map(function($product, $index) {
            return [
                'id' => $index,
                'vendor' => $product->vendor,
                'name' => $product->name,
                'type' => $product->type,
                'variation' => $product->variation,
                'product_id' => $product->id,
                'full_name' => $product->vendor . ' - ' . $product->name . ' - ' . $product->type . ' ' . $product->variation
            ];
        })->toArray();

        \Log::info('Envoi à OpenAI pour matching', [
            'search' => [
                'vendor' => $vendor,
                'name' => $name,
                'type' => $type,
                'variation' => $variation,
                'is_coffret' => $isCoffret
            ],
            'candidates_count' => count($productsList),
            'first_candidates' => array_slice($productsList, 0, 3)
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
                        'content' => 'Tu es un expert en matching de produits cosmétiques. Ton rôle est de trouver les produits les plus similaires, même si la correspondance n\'est pas parfaite. Sois TRÈS FLEXIBLE et inclusif. Réponds UNIQUEMENT avec un tableau JSON des IDs des produits qui pourraient correspondre, sans explication.'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Produit recherché:
Vendor: {$vendor}
Name: {$name}
Type: {$type}
Variation: {$variation}
Est un coffret: " . ($isCoffret ? 'OUI' : 'NON') . "

Liste des produits candidats à analyser:
" . json_encode($productsList, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "

**IMPORTANT: RÈGLES TRÈS FLEXIBLES:**

1. **VENDOR - FLEXIBLE:**
   - Ignorer totalement la casse
   - Accepter les variantes: \"Dior\" = \"DIOR\" = \"Christian Dior\" ✅
   - Accepter les abréviations
   - Si le vendor contient des mots similaires, c'est OK

2. **NAME - EXTRÊMEMENT FLEXIBLE:**
   - Ignorer la casse complètement
   - Si le nom contient des mots-clés similaires → MATCH
   - Ex: \"Sauvage\" ≈ \"Sauvage Elixir\" ≈ \"Sauvage Eau de Parfum\" ✅
   - Ex: \"J'adore\" ≈ \"J'adore L'Or\" ≈ \"J'adore Absolu\" ✅
   - Rechercher par mots-clés principaux

3. **TYPE - MODÉRÉMENT FLEXIBLE:**
   - Ignorer la casse
   - Groupes acceptables ensemble:
     - Parfums: \"Eau de Toilette\", \"Eau de Parfum\", \"Parfum\", \"Extrait\"
     - Soins: \"Crème\", \"Sérum\", \"Lotion\", \"Gel\"
   - Ne pas être trop strict

4. **COFFRET - LOGIQUE:**
   - Si recherche coffret → priorité aux coffrets mais accepter aussi produits unitaires
   - Si recherche produit unitaire → priorité aux unitaires mais accepter aussi coffrets

5. **VARIATION - IGNORER:**
   - La contenance n'est pas un critère de sélection

**STRATÉGIE DE MATCHING:**
1. Chercher d'abord les similitudes fortes (même vendor + mots-clés du name)
2. Si peu de résultats, élargir les critères
3. **Inclure TOUS les produits qui ont une ressemblance, même partielle**
4. Mieux vaut avoir trop de résultats que pas assez
5. Si un produit semble être une variante du même produit → INCLURE

**CRITÈRES D'INCLUSION (au moins 1 suffit):**
- Vendor similaire à 70% OU
- Nom contient au moins 1 mot-clé important OU
- Type dans la même catégorie générale

Retourne un tableau d'IDs des produits qui pourraient correspondre, classés du plus au moins pertinent.

Format de réponse: [id1, id2, id3, ...]
Minimum 3 produits si possible, maximum 10."
                    ]
                ],
                'temperature' => 0.3,
                'max_tokens' => 1000
            ]);

            if (!$response->successful()) {
                \Log::error('Erreur API OpenAI lors du matching', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return $this->manualMatching($candidates, $vendor, $name);
            }

            $data = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? '';
            
            \Log::info('Réponse OpenAI matching', [
                'raw_content' => $content
            ]);
            
            // Nettoyer le contenu
            $content = preg_replace('/```json\s*|\s*```/', '', $content);
            $content = trim($content);
            
            $matchedIds = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($matchedIds)) {
                \Log::error('Erreur parsing JSON du matching', [
                    'content' => $content,
                    'error' => json_last_error_msg()
                ]);
                return $this->manualMatching($candidates, $vendor, $name);
            }

            \Log::info('IDs matchés par OpenAI', [
                'matched_ids' => $matchedIds,
                'count' => count($matchedIds)
            ]);

            // Récupérer les produits correspondants avec score
            $scoredProducts = [];
            $baseScore = 100;
            
            foreach ($matchedIds as $id) {
                if (isset($candidates[$id])) {
                    $product = $candidates[$id];
                    
                    // Calculer un score de correspondance
                    $matchScore = $this->calculateMatchScore($product, $vendor, $name, $type);
                    
                    $scoredProducts[] = [
                        'product' => $product->toArray(),
                        'score' => $matchScore
                    ];
                    
                    \Log::info('Produit matché', [
                        'id' => $product->id,
                        'vendor' => $product->vendor,
                        'name' => $product->name,
                        'type' => $product->type,
                        'score' => $matchScore
                    ]);
                }
            }

            // Trier par score décroissant
            usort($scoredProducts, function($a, $b) {
                return $b['score'] <=> $a['score'];
            });

            return $scoredProducts;

        } catch (\Exception $e) {
            \Log::error('Erreur lors du matching AI', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->manualMatching($candidates, $vendor, $name);
        }
    }

    private function manualMatching($candidates, $vendor, $name)
    {
        \Log::info('Fallback manuel après échec OpenAI');
        
        $scoredProducts = [];
        $vendorLower = strtolower($vendor);
        $nameLower = strtolower($name);
        
        foreach ($candidates as $product) {
            $score = 0;
            $productVendorLower = strtolower($product->vendor);
            $productNameLower = strtolower($product->name);
            
            // Score par similarité du vendor
            similar_text($vendorLower, $productVendorLower, $vendorSimilarity);
            if ($vendorSimilarity > 40) { // Seuil bas
                $score += $vendorSimilarity;
            }
            
            // Score par similarité du name
            similar_text($nameLower, $productNameLower, $nameSimilarity);
            if ($nameSimilarity > 30) {
                $score += $nameSimilarity * 0.8;
            }
            
            // Score par mots-clés du name
            $nameWords = explode(' ', $nameLower);
            foreach ($nameWords as $word) {
                if (strlen($word) > 3 && strpos($productNameLower, $word) !== false) {
                    $score += 20;
                }
            }
            
            if ($score > 30) { // Seuil très bas
                $scoredProducts[] = [
                    'product' => $product->toArray(),
                    'score' => $score
                ];
            }
        }
        
        // Trier par score
        usort($scoredProducts, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        
        // Prendre les meilleurs
        $scoredProducts = array_slice($scoredProducts, 0, 8);
        
        \Log::info('Résultats matching manuel', [
            'count' => count($scoredProducts),
            'scores' => array_column($scoredProducts, 'score')
        ]);
        
        return $scoredProducts;
    }

    private function calculateMatchScore($product, $vendor, $name, $type)
    {
        $score = 0;
        
        // Vendor match (40% du score)
        similar_text(strtolower($vendor), strtolower($product->vendor), $vendorSimilarity);
        $score += $vendorSimilarity * 0.4;
        
        // Name match (40% du score)
        similar_text(strtolower($name), strtolower($product->name), $nameSimilarity);
        $score += $nameSimilarity * 0.4;
        
        // Type match (20% du score)
        if (!empty($type) && !empty($product->type)) {
            similar_text(strtolower($type), strtolower($product->type), $typeSimilarity);
            $score += $typeSimilarity * 0.2;
        }
        
        // Bonus pour les mots-clés communs
        $searchWords = array_merge(
            explode(' ', strtolower($vendor)),
            explode(' ', strtolower($name))
        );
        
        $productWords = array_merge(
            explode(' ', strtolower($product->vendor)),
            explode(' ', strtolower($product->name))
        );
        
        $commonWords = array_intersect($searchWords, $productWords);
        $score += count($commonWords) * 5;
        
        return min(100, $score); // Limiter à 100
    }

    public function selectProduct($productId)
    {
        $product = Product::find($productId);
        
        if ($product) {
            session()->flash('success', 'Produit sélectionné : ' . $product->name);
            $this->bestMatch = $product->toArray();
            
            // Émettre un événement si besoin
            $this->dispatch('product-selected', productId: $productId);
        }
    }

}; ?>

<div class="p-6 bg-white rounded-lg shadow">
    <div class="mb-4">
        <h2 class="text-xl font-bold mb-2">Extraction et recherche de produit</h2>
        <div class="bg-gray-50 p-3 rounded border">
            <p class="text-gray-800 font-medium">{{ $productName }}</p>
            @if($productPrice)
                <p class="text-sm text-gray-600 mt-1">Prix: {{ $productPrice }}</p>
            @endif
            @if($productId)
                <p class="text-xs text-gray-400 mt-1">ID: {{ $productId }}</p>
            @endif
        </div>
    </div>

    <button 
        wire:click="extractSearchTerme"
        wire:loading.attr="disabled"
        class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600 disabled:opacity-50 transition flex items-center gap-2"
    >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
        </svg>
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
            <strong>Succès:</strong> {{ session('success') }}
        </div>
    @endif

    @if(session('warning'))
        <div class="mt-4 p-4 bg-yellow-100 text-yellow-700 rounded border border-yellow-300">
            <strong>Attention:</strong> {{ session('warning') }}
        </div>
    @endif

    @if(session('info'))
        <div class="mt-4 p-4 bg-blue-100 text-blue-700 rounded border border-blue-300">
            <strong>Info:</strong> {{ session('info') }}
        </div>
    @endif

    @if($extractedData)
        <div class="mt-6 p-4 bg-gray-50 rounded border border-gray-200">
            <h3 class="font-bold mb-3 flex items-center gap-2 text-gray-700">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                </svg>
                Critères extraits :
            </h3>
            <div class="grid grid-cols-2 gap-3">
                <div class="p-2 bg-white rounded border">
                    <span class="font-semibold text-gray-700 block text-sm">Vendor:</span> 
                    <span class="text-gray-900">{{ $extractedData['vendor'] ?? 'N/A' }}</span>
                </div>
                <div class="p-2 bg-white rounded border">
                    <span class="font-semibold text-gray-700 block text-sm">Name:</span> 
                    <span class="text-gray-900">{{ $extractedData['name'] ?? 'N/A' }}</span>
                </div>
                <div class="p-2 bg-white rounded border">
                    <span class="font-semibold text-gray-700 block text-sm">Variation:</span> 
                    <span class="text-gray-900">{{ $extractedData['variation'] ?? 'N/A' }}</span>
                </div>
                <div class="p-2 bg-white rounded border">
                    <span class="font-semibold text-gray-700 block text-sm">Type:</span> 
                    <span class="text-gray-900">{{ $extractedData['type'] ?? 'N/A' }}</span>
                </div>
                <div class="p-2 bg-white rounded border col-span-2">
                    <span class="font-semibold text-gray-700 block text-sm">Coffret:</span> 
                    @if($extractedData['is_coffret'] ?? false)
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                            <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M5 3a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2V5a2 2 0 00-2-2H5zM5 11a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2v-2a2 2 0 00-2-2H5zM11 5a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V5zM11 13a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path>
                            </svg>
                            Oui, c'est un coffret
                        </span>
                    @else
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                            <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM7 9a1 1 0 100-2 1 1 0 000 2zm7-1a1 1 0 11-2 0 1 1 0 012 0zm-.464 5.535a1 1 0 10-1.415-1.414 3 3 0 01-4.242 0 1 1 0 00-1.415 1.414 5 5 0 007.072 0z" clip-rule="evenodd"></path>
                            </svg>
                            Non, produit unitaire
                        </span>
                    @endif
                    <span class="text-xs text-gray-500 ml-2">(détecté via name/type)</span>
                </div>
            </div>
        </div>
    @endif

    @if($bestMatch)
        <div class="mt-6 p-4 bg-green-50 border-2 border-green-500 rounded-lg">
            <div class="flex justify-between items-center mb-3">
                <h3 class="font-bold text-green-700 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                    </svg>
                    Meilleur résultat trouvé
                </h3>
                <div class="flex items-center gap-2">
                    <span class="text-xs px-2 py-1 bg-blue-100 text-blue-800 rounded-full font-medium">
                        Confiance: {{ $bestMatchScore }}
                    </span>
                    <button 
                        wire:click="findMoreMatches"
                        class="text-xs px-2 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded border border-gray-300 transition"
                    >
                        Voir plus
                    </button>
                </div>
            </div>
            
            @php
                $isBestMatchCoffret = isset($bestMatch['name']) && isset($bestMatch['type']) && (
                    stripos($bestMatch['name'], 'coffret') !== false || 
                    stripos($bestMatch['name'], 'set') !== false || 
                    stripos($bestMatch['name'], 'kit') !== false ||
                    stripos($bestMatch['type'], 'coffret') !== false ||
                    stripos($bestMatch['type'], 'set') !== false ||
                    stripos($bestMatch['type'], 'kit') !== false
                );
            @endphp
            
            <div class="flex items-start gap-4">
                @if(isset($bestMatch['image_url']) && $bestMatch['image_url'])
                    <img src="{{ $bestMatch['image_url'] }}" alt="{{ $bestMatch['name'] ?? '' }}" class="w-24 h-24 object-cover rounded-lg shadow">
                @else
                    <div class="w-24 h-24 bg-gray-200 rounded-lg flex items-center justify-center">
                        <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                @endif
                <div class="flex-1">
                    <div class="flex items-center gap-2 mb-1">
                        <p class="font-bold text-lg text-gray-800">
                            {{ $bestMatch['vendor'] ?? 'N/A' }} - {{ $bestMatch['name'] ?? 'N/A' }}
                        </p>
                        @if($isBestMatchCoffret)
                            <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800">
                                Coffret
                            </span>
                        @endif
                    </div>
                    <p class="text-sm text-gray-600 mt-1">
                        <span class="font-medium">{{ $bestMatch['type'] ?? 'N/A' }}</span> | 
                        <span>{{ $bestMatch['variation'] ?? 'N/A' }}</span>
                    </p>
                    @if(isset($bestMatch['prix_ht']))
                        <p class="text-lg font-bold text-green-600 mt-2">
                            {{ number_format((float)$bestMatch['prix_ht'], 2) }} {{ $bestMatch['currency'] ?? 'EUR' }}
                        </p>
                    @endif
                    @if(isset($bestMatch['url']) && $bestMatch['url'])
                        <a href="{{ $bestMatch['url'] }}" target="_blank" class="inline-flex items-center gap-1 text-sm text-blue-600 hover:text-blue-800 hover:underline mt-2">
                            Voir le produit
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                            </svg>
                        </a>
                    @endif
                    <p class="text-xs text-gray-400 mt-1">ID: {{ $bestMatch['id'] ?? 'N/A' }}</p>
                    
                    <button 
                        wire:click="selectProduct({{ $bestMatch['id'] ?? 0 }})"
                        class="mt-3 px-3 py-1.5 bg-green-500 hover:bg-green-600 text-white text-sm rounded transition"
                    >
                        Sélectionner ce produit
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if(!empty($matchingProducts) && count($matchingProducts) > 1)
        <div class="mt-6">
            <h3 class="font-bold mb-3 text-gray-700">
                Autres résultats trouvés ({{ count($matchingProducts) }}) :
            </h3>
            <div class="space-y-2 max-h-96 overflow-y-auto border border-gray-200 rounded-lg p-2">
                @foreach($matchingProducts as $product)
                    @if($bestMatch && isset($bestMatch['id']) && $bestMatch['id'] !== $product['id'])
                        @php
                            $isProductCoffret = isset($product['name']) && isset($product['type']) && (
                                stripos($product['name'], 'coffret') !== false || 
                                stripos($product['name'], 'set') !== false || 
                                stripos($product['name'], 'kit') !== false ||
                                stripos($product['type'], 'coffret') !== false ||
                                stripos($product['type'], 'set') !== false ||
                                stripos($product['type'], 'kit') !== false
                            );
                        @endphp
                        <div 
                            wire:click="selectProduct({{ $product['id'] ?? 0 }})"
                            class="p-3 border rounded-lg hover:bg-blue-50 hover:border-blue-300 cursor-pointer transition bg-white"
                        >
                            <div class="flex items-center gap-3">
                                @if(isset($product['image_url']) && $product['image_url'])
                                    <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] ?? '' }}" class="w-16 h-16 object-cover rounded shadow-sm">
                                @else
                                    <div class="w-16 h-16 bg-gray-100 rounded flex items-center justify-center">
                                        <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                        </svg>
                                    </div>
                                @endif
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <p class="font-medium text-sm truncate text-gray-800">
                                            {{ $product['vendor'] ?? 'N/A' }} - {{ $product['name'] ?? 'N/A' }}
                                        </p>
                                        @if($isProductCoffret)
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800 flex-shrink-0">
                                                Coffret
                                            </span>
                                        @endif
                                    </div>
                                    <p class="text-xs text-gray-500 truncate">
                                        {{ $product['type'] ?? 'N/A' }} | {{ $product['variation'] ?? 'N/A' }}
                                    </p>
                                </div>
                                <div class="text-right flex-shrink-0">
                                    @if(isset($product['prix_ht']))
                                        <p class="font-bold text-sm whitespace-nowrap text-gray-900">
                                            {{ number_format((float)$product['prix_ht'], 2) }} {{ $product['currency'] ?? 'EUR' }}
                                        </p>
                                    @endif
                                    <p class="text-xs text-gray-400">ID: {{ $product['id'] ?? 'N/A' }}</p>
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
                    <p class="text-sm text-yellow-700 mt-1">
                        Aucun produit ne correspond aux critères extraits. 
                        <button 
                            wire:click="findMoreMatches"
                            class="text-yellow-800 underline hover:text-yellow-900"
                        >
                            Cliquez ici pour une recherche plus large
                        </button>
                    </p>
                </div>
            </div>
        </div>
    @endif

    @if($isLoading)
        <div class="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
            <div class="flex items-center gap-3">
                <svg class="animate-spin h-5 w-5 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <p class="text-blue-700">Recherche en cours... Analyse des produits similaires</p>
            </div>
        </div>
    @endif
</div>