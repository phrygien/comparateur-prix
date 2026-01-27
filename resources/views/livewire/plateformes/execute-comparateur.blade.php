<?php

use Livewire\Volt\Component;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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
        session()->forget(['error', 'warning', 'success', 'info']);
        
        try {
            // Étape 1: Extraction des données (prompt amélioré pour plus de flexibilité)
            $extractionResponse = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Tu es un expert en extraction de données de produits cosmétiques et parfums. Extrait vendor (marque), name (nom de gamme/parfum, ex: "Chrome" pour Azzaro Chrome ou "Vital Perfection" pour soins), variation (taille, ex: "100 ml") et type (ex: "Eau de Toilette", "Déodorant", "Sérum"). Pour parfums, sépare vendor et name même si composés. Réponds UNIQUEMENT avec un JSON valide, sans texte supplémentaire.'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Extrait du nom: {$this->productName}

Format JSON strict :
{
  \"vendor\": \"Marque\",
  \"name\": \"Nom gamme/parfum\",
  \"variation\": \"100 ml\",
  \"type\": \"Eau de Toilette\"
}

Exemples:
- 'Azzaro Chrome Déodorant 150ml' → {\"vendor\": \"Azzaro\", \"name\": \"Chrome\", \"variation\": \"150 ml\", \"type\": \"Déodorant\"}
- 'Dior Sauvage Eau de Toilette 100ml' → {\"vendor\": \"Dior\", \"name\": \"Sauvage\", \"variation\": \"100 ml\", \"type\": \"Eau de Toilette\"}
- 'Shiseido Vital Perfection Uplifting and Firming Cream 50ml' → {\"vendor\": \"Shiseido\", \"name\": \"Vital Perfection\", \"variation\": \"50 ml\", \"type\": \"Crème Uplifting and Firming\"}"
                    ]
                ],
                'temperature' => 0.1, // Plus déterministe
                'max_tokens' => 300
            ]);

            if (!$extractionResponse->successful()) {
                throw new \Exception('Erreur API OpenAI extraction: ' . $extractionResponse->body());
            }

            $extractionData = $extractionResponse->json();
            $content = trim(preg_replace('/```json\s*|\s*```/', '', $extractionData['choices'][0]['message']['content']));
            
            $this->extractedData = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE || empty($this->extractedData['vendor']) || empty($this->extractedData['name']) || empty($this->extractedData['type'])) {
                // Fallback: Extraction basique si AI échoue
                $this->extractedData = $this->basicExtractionFallback($this->productName);
                \Log::warning('Fallback extraction utilisée', ['product' => $this->productName]);
            }

            // Étape 2: Vérification et correction AI (prompt amélioré)
            $verificationResponse = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Expert cosmétiques. Vérifie cohérence vendor/name/type. Pour parfums: "Chrome" + "Déodorant" OK. Pour soins: "Vital Perfection" + "Crème" OK. Réponds JSON: {"is_correct": bool, "confidence": "high/medium/low", "explanation": "court", "correction": {vendor/name/type si besoin, sinon {}}}.'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Vérifie:
Original: {$this->productName}
Extrait: vendor={$this->extractedData['vendor']}, name={$this->extractedData['name']}, type={$this->extractedData['type']}

Considère incohérences (ex: nom parfum avec type crème). Sugère corrections si faux.

Exemple OK: {\"is_correct\": true, \"confidence\": \"high\", \"explanation\": \"Cohérent pour parfum\", \"correction\": {}}
Exemple faux: {\"is_correct\": false, ... \"correction\": {\"type\": \"Déodorant\"}}"
                    ]
                ],
                'temperature' => 0.1,
                'max_tokens' => 400
            ]);

            if ($verificationResponse->successful()) {
                $verificationData = $verificationResponse->json();
                $verificationContent = trim(preg_replace('/```json\s*|\s*```/', '', $verificationData['choices'][0]['message']['content']));
                
                $this->aiCorrection = json_decode($verificationContent, true);
                
                if (json_last_error() === JSON_ERROR_NONE && !$this->aiCorrection['is_correct'] && !empty($this->aiCorrection['correction'])) {
                    $correction = $this->aiCorrection['correction'];
                    $this->extractedData = array_merge($this->extractedData, array_filter($correction));
                    session()->flash('info', 'Correction AI appliquée: ' . ($this->aiCorrection['explanation'] ?? ''));
                }
            } else {
                \Log::warning('Vérification AI échouée');
            }
            
            // Recherche
            $this->searchMatchingProducts();
            
        } catch (\Exception $e) {
            \Log::error('Erreur extraction globale', [
                'message' => $e->getMessage(),
                'product_name' => $this->productName
            ]);
            session()->flash('error', 'Erreur extraction: ' . $e->getMessage());
        } finally {
            $this->isLoading = false;
        }
    }

    // Fallback basique si AI échoue (extraction par regex simple)
    private function basicExtractionFallback(string $productName): array
    {
        // Exemple simple: Cherche marque connue, reste comme name/type
        $knownVendors = ['Azzaro', 'Dior', 'Shiseido']; // À étendre
        foreach ($knownVendors as $vendor) {
            if (Str::contains(strtoupper($productName), strtoupper($vendor))) {
                return [
                    'vendor' => $vendor,
                    'name' => trim(str_replace($vendor, '', $productName)),
                    'variation' => '', // À parser
                    'type' => 'Inconnu' // À améliorer
                ];
            }
        }
        return ['vendor' => '', 'name' => $productName, 'variation' => '', 'type' => ''];
    }

    private function searchMatchingProducts()
    {
        if (!$this->extractedData || empty($this->extractedData['vendor']) || empty($this->extractedData['name']) || empty($this->extractedData['type'])) {
            session()->flash('warning', 'Champs manquants pour recherche.');
            return;
        }

        $vendor = trim($this->extractedData['vendor']);
        $name = trim($this->extractedData['name']);
        $type = trim($this->extractedData['type']);
        $originalType = $type; // Garder original pour matching

        $cleanType = $this->cleanType($type);
        $nameVariations = $this->getNameVariations($vendor, $name, $cleanType);

        // Collecter tous les candidats avec scores
        $candidates = collect();

        // 1. Recherche stricte: vendor + name_var + type (original OU clean)
        foreach ($nameVariations as $nameVar) {
            $matches = Product::where(function($q) use ($vendor) {
                $q->whereRaw('LOWER(vendor) LIKE ?', ['%' . strtolower($vendor) . '%']);
            })->where(function($q) use ($nameVar) {
                $q->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($nameVar) . '%']);
            })->where(function($q) use ($originalType, $cleanType) {
                $q->whereRaw('LOWER(type) LIKE ?', ['%' . strtolower($originalType) . '%'])
                  ->orWhereRaw('LOWER(type) LIKE ?', ['%' . strtolower($cleanType) . '%']);
            })->limit(5)->get(); // Limite pour perf

            foreach ($matches as $match) {
                $score = $this->calculateMatchScore($match, $vendor, $nameVar, $type);
                $candidates->push(['product' => $match, 'score' => $score]);
            }
        }

        // 2. Flexible: vendor + name_var + mots-clés type
        if ($candidates->isEmpty()) {
            $typeKeywords = array_filter(explode(' ', strtolower($cleanType)), fn($k) => strlen($k) > 2);
            foreach ($nameVariations as $nameVar) {
                $matches = Product::where(function($q) use ($vendor) {
                    $q->whereRaw('LOWER(vendor) LIKE ?', ['%' . strtolower($vendor) . '%']);
                })->where(function($q) use ($nameVar) {
                    $q->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($nameVar) . '%']);
                })->where(function($q) use ($typeKeywords) {
                    foreach ($typeKeywords as $kw) {
                        $q->orWhereRaw('LOWER(type) LIKE ?', ['%' . $kw . '%']);
                    }
                })->limit(5)->get();

                foreach ($matches as $match) {
                    $score = $this->calculateMatchScore($match, $vendor, $nameVar, $type) * 0.8; // Pénalité flexible
                    $candidates->push(['product' => $match, 'score' => $score]);
                }
            }
        }

        // 3. Fallback: vendor + type seulement (si type spécifique)
        if ($candidates->isEmpty() && $this->isSpecificProductType($cleanType)) {
            $matches = Product::where(function($q) use ($vendor) {
                $q->whereRaw('LOWER(vendor) LIKE ?', ['%' . strtolower($vendor) . '%']);
            })->where(function($q) use ($originalType, $cleanType) {
                $q->whereRaw('LOWER(type) LIKE ?', ['%' . strtolower($originalType) . '%'])
                  ->orWhereRaw('LOWER(type) LIKE ?', ['%' . strtolower($cleanType) . '%']);
            })->limit(10)->get();

            foreach ($matches as $match) {
                $score = $this->calculateMatchScore($match, $vendor, $name, $type, false); // Sans name
                $candidates->push(['product' => $match, 'score' => $score * 0.6]);
            }
        }

        // Trier par score et limiter
        $candidates = $candidates->sortByDesc('score')->take(10);
        $this->matchingProducts = $candidates->pluck('product')->toArray();
        
        if ($candidates->isNotEmpty()) {
            $best = $candidates->first();
            $this->bestMatch = $best['product'];
            if ($best['score'] < 0.7) {
                session()->flash('warning', "Meilleur match (score: " . round($best['score'] * 100, 0) . "%). Vérifiez manuellement.");
            }
        } else {
            session()->flash('error', 'Aucun produit trouvé. Essayez une recherche manuelle.');
        }

        \Log::info('Recherche terminée', [
            'candidates_count' => $candidates->count(),
            'best_score' => $candidates->isNotEmpty() ? $candidates->first()['score'] : 0,
            'query' => $this->productName
        ]);
    }

    // Score simple de matching (0-1)
    private function calculateMatchScore($product, string $vendor, string $name, string $type, bool $requireName = true): float
    {
        $score = 0;
        $total = 0;

        // Vendor match
        $vendorMatch = (str_contains(strtolower($product->vendor), strtolower($vendor)) || str_contains(strtolower($vendor), strtolower($product->vendor)));
        $score += $vendorMatch ? 1 : 0;
        $total += 1;

        // Name match
        if ($requireName) {
            $nameMatch = str_contains(strtolower($product->name), strtolower($name)) || str_contains(strtolower($name), strtolower($product->name));
            $score += $nameMatch ? 1 : 0;
            $total += 1;
        }

        // Type match
        $typeMatch = str_contains(strtolower($product->type), strtolower($type)) || str_contains(strtolower($type), strtolower($product->type));
        $score += $typeMatch ? 1 : 0;
        $total += 1;

        return $score / $total;
    }

    /**
     * Variations de nom étendues
     */
    private function getNameVariations(string $vendor, string $name, string $type): array
    {
        $variations = [$name];

        if ($this->isPerfumeType($type)) {
            $variations = array_merge($variations, [
                $name . ' Pour Homme',
                $name . ' Pour Femme',
                $name . ' Eau de Toilette',
                $name . ' Eau de Parfum',
                $name . ' Aftershave',
                $vendor . ' ' . $name,
                $vendor,
                ucfirst(strtolower($name)) // Normalisation
            ]);
            // Variantes spécifiques
            if (str_contains(strtolower($name), 'chrome')) $variations[] = 'Chrome pour Homme';
            if (str_contains(strtolower($name), 'sauvage')) $variations[] = 'Sauvage Eau de Toilette';
        }

        // Nettoyage: sans articles
        $clean = preg_replace('/\b(le|la|les|un|une|du|de|des|pour|et)\b/i', '', $name);
        $clean = trim(preg_replace('/\s+/', ' ', $clean));
        if ($clean && $clean !== $name) $variations[] = $clean;

        return array_unique(array_filter($variations));
    }
    
    private function isPerfumeType(string $type): bool
    {
        $types = ['déodorant', 'parfum', 'eau de toilette', 'eau de parfum', 'after shave', 'lotion après-rasage'];
        return collect($types)->some(fn($t) => str_contains(strtolower($type), $t));
    }
    
    private function isSpecificProductType(string $type): bool
    {
        $types = ['déodorant', 'shampooing', 'gel douche', 'crème', 'sérum'];
        return collect($types)->some(fn($t) => str_contains(strtolower($type), $t));
    }

    /**
     * Clean type amélioré: Préserve termes essentiels
     */
    private function cleanType(string $type): string
    {
        $essentialWords = ['eau', 'toilette', 'parfum', 'crème', 'sérum', 'concentré']; // À préserver
        $stopWords = [
            'vaporisateur', 'spray', 'pompe', 'tube', 'pot', 'roll-on', 'stick', 'ml', 'g', 'unité',
            'sans', 'avec', 'le', 'la', 'les', 'en', 'par', 'à', 'des'
        ];
        
        $cleaned = strtolower($type);
        foreach ($stopWords as $word) {
            if (!in_array($word, $essentialWords)) {
                $cleaned = preg_replace('/\b' . preg_quote($word, '/') . '\b/i', '', $cleaned);
            }
        }
        
        $cleaned = preg_replace('/[\(\)\[\]-]/', ' ', $cleaned);
        $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned));
        
        return $cleaned;
    }

    public function selectProduct($productId)
    {
        $product = Product::find($productId);
        if ($product) {
            $this->bestMatch = $product;
            session()->flash('success', 'Produit sélectionné: ' . $product->name);
            $this->dispatch('product-selected', productId: $productId);
        }
    }

    // Méthode pour relancer avec corrections manuelles (bonus)
    public function manualCorrect($field, $value)
    {
        if ($this->extractedData) {
            $this->extractedData[$field] = $value;
            $this->searchMatchingProducts();
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
            
            @if($aiCorrection)
                <div class="mb-4 p-3 rounded {{ $aiCorrection['is_correct'] ? 'bg-green-50 border border-green-200' : 'bg-yellow-50 border border-yellow-200' }}">
                    <div class="flex items-start">
                        <span class="text-{{ $aiCorrection['is_correct'] ? 'green' : 'yellow' }}-600 mr-2">{{ $aiCorrection['is_correct'] ? '✓' : '⚠️' }}</span>
                        <div>
                            <p class="font-semibold">{{ $aiCorrection['explanation'] }}</p>
                            <p class="text-sm text-gray-600">Confiance: {{ $aiCorrection['confidence'] ?? 'N/A' }}</p>
                        </div>
                    </div>
                </div>
            @endif
            
            <div class="grid grid-cols-2 gap-4 mb-4">
                @foreach(['vendor' => 'Vendor', 'name' => 'Name', 'type' => 'Type'] as $key => $label)
                    <div class="{{ empty($extractedData[$key]) ? 'text-red-600 font-semibold' : '' }}">
                        <span class="font-semibold">{{ $label }}:</span> 
                        {{ $extractedData[$key] ?? 'N/A' }}
                        @if(empty($extractedData[$key])) <span class="text-xs">(requis)</span> @endif
                        {{-- Bonus: Édition manuelle --}}
                        @if($extractedData)
                            <input type="text" wire:model.debounce.500ms="extractedData.{{ $key }}" wire:change="searchMatchingProducts" class="ml-2 text-xs p-1 border rounded" placeholder="Corriger">
                        @endif
                    </div>
                @endforeach
                <div>
                    <span class="font-semibold text-gray-500">Variation:</span> 
                    <span class="text-gray-400">{{ $extractedData['variation'] ?? 'N/A' }}</span>
                </div>
            </div>
        </div>
    @endif

    @if($bestMatch)
        <div class="mt-6 p-4 bg-green-50 border-2 border-green-500 rounded">
            <h3 class="font-bold text-green-700 mb-3">✓ Meilleur match (Score: {{ isset($bestMatch->score) ? round($bestMatch->score * 100) . '%' : 'N/A' }}) :</h3>
            <div class="flex items-start gap-4">
                @if($bestMatch['image_url'] ?? $bestMatch->image_url)
                    <img src="{{ $bestMatch['image_url'] ?? $bestMatch->image_url }}" alt="{{ $bestMatch['name'] ?? $bestMatch->name }}" class="w-20 h-20 object-cover rounded">
                @endif
                <div class="flex-1">
                    <p class="font-semibold">{{ ($bestMatch['vendor'] ?? $bestMatch->vendor) }} - {{ ($bestMatch['name'] ?? $bestMatch->name) }}</p>
                    <p class="text-sm text-gray-600">{{ ($bestMatch['type'] ?? $bestMatch->type) }} | {{ ($bestMatch['variation'] ?? $bestMatch->variation) }}</p>
                    <p class="text-sm font-bold text-green-600 mt-1">{{ ($bestMatch['prix_ht'] ?? $bestMatch->prix_ht) }} {{ ($bestMatch['currency'] ?? $bestMatch->currency) }}</p>
                    <a href="{{ $bestMatch['url'] ?? $bestMatch->url }}" target="_blank" class="text-xs text-blue-500 hover:underline">Voir</a>
                </div>
            </div>
        </div>
    @endif

    @if(!empty($matchingProducts) && count($matchingProducts) > 1)
        <div class="mt-6">
            <h3 class="font-bold mb-3">Autres résultats ({{ count($matchingProducts) }}) :</h3>
            <div class="space-y-2 max-h-96 overflow-y-auto">
                @foreach($matchingProducts as $product)
                    @php $score = $this->calculateMatchScore($product, $extractedData['vendor'] ?? '', $extractedData['name'] ?? '', $extractedData['type'] ?? ''); @endphp
                    <div 
                        wire:click="selectProduct({{ $product['id'] ?? $product->id }})"
                        class="p-3 border rounded hover:bg-blue-50 cursor-pointer transition {{ $bestMatch && ($bestMatch['id'] ?? $bestMatch->id) === ($product['id'] ?? $product->id) ? 'bg-blue-100 border-blue-500' : 'bg-white' }}"
                    >
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                @if($product['image_url'] ?? $product->image_url)
                                    <img src="{{ $product['image_url'] ?? $product->image_url }}" alt="{{ $product['name'] ?? $product->name }}" class="w-12 h-12 object-cover rounded">
                                @endif
                                <div class="flex-1">
                                    <p class="font-medium text-sm">{{ ($product['vendor'] ?? $product->vendor) }} - {{ ($product['name'] ?? $product->name) }}</p>
                                    <p class="text-xs text-gray-500">{{ ($product['type'] ?? $product->type) }} | {{ ($product['variation'] ?? $product->variation) }}</p>
                                    <div class="text-xs text-blue-600">Score: {{ round($score * 100) }}%</div>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="font-bold text-sm">{{ ($product['prix_ht'] ?? $product->prix_ht) }} {{ ($product['currency'] ?? $product->currency) }}</p>
                                <p class="text-xs text-gray-500">ID: {{ $product['id'] ?? $product->id }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>