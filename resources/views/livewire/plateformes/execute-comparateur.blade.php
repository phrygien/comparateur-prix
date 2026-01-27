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
            // ───────────────────────────────────────────────────────────────
            // ÉTAPE 1 : Extraction structurée avec GPT-4o-mini
            // ───────────────────────────────────────────────────────────────
            $extractionResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => $this->getExtractionPrompt()],
                    ['role' => 'user',   'content' => "Nom du produit : {$this->productName}"]
                ],
                'temperature' => 0.2,
                'max_tokens' => 300,
            ]);

            $extractionResponse->throw();

            $content = trim($extractionResponse->json()['choices'][0]['message']['content'] ?? '');
            $content = preg_replace('/^```json\s*|\s*```$/', '', $content);

            $this->extractedData = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($this->extractedData)) {
                throw new \Exception('Réponse JSON invalide de l\'extraction');
            }

            // ───────────────────────────────────────────────────────────────
            // ÉTAPE 2 : Vérification / correction intelligente
            // ───────────────────────────────────────────────────────────────
            $verificationResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => $this->getVerificationPrompt()],
                    ['role' => 'user',   'content' => $this->buildVerificationUserMessage()]
                ],
                'temperature' => 0.1,
                'max_tokens' => 400,
            ]);

            if ($verificationResponse->successful()) {
                $verifContent = trim($verificationResponse->json()['choices'][0]['message']['content'] ?? '');
                $verifContent = preg_replace('/^```json\s*|\s*```$/', '', $verifContent);

                $this->aiCorrection = json_decode($verifContent, true);

                if (json_last_error() === JSON_ERROR_NONE && isset($this->aiCorrection['is_correct'])) {
                    if (!$this->aiCorrection['is_correct'] && !empty($this->aiCorrection['correction'])) {
                        $this->extractedData = array_merge($this->extractedData, array_filter($this->aiCorrection['correction']));
                        session()->flash('info', 'Correction appliquée : ' . ($this->aiCorrection['explanation'] ?? ''));
                    }
                }
            }

            // ───────────────────────────────────────────────────────────────
            // ÉTAPE 3 : Recherche dans la base
            // ───────────────────────────────────────────────────────────────
            $this->searchMatchingProducts();

        } catch (\Exception $e) {
            \Log::error('Erreur extraction/matching', [
                'message' => $e->getMessage(),
                'product_name' => $this->productName
            ]);
            session()->flash('error', 'Erreur : ' . $e->getMessage());
        } finally {
            $this->isLoading = false;
        }
    }

    // ───────────────────────────────────────────────────────────────
    // Recherche principale (améliorée)
    // ───────────────────────────────────────────────────────────────
    private function searchMatchingProducts()
    {
        if (!$this->extractedData) return;

        $vendor   = trim($this->extractedData['vendor']   ?? '');
        $name     = trim($this->extractedData['name']     ?? '');
        $type     = trim($this->extractedData['type']     ?? '');
        $variation = trim($this->extractedData['variation'] ?? '');

        if (empty($vendor) || empty($name)) {
            session()->flash('warning', 'Impossible de chercher : vendor et/ou name manquant(s).');
            return;
        }

        $vendorNorm   = Str::lower(Str::ascii($vendor));
        $nameNorm     = Str::lower(Str::ascii($name));
        $typeNorm     = Str::lower(Str::ascii($type));

        // ─── Stratégies de recherche (par ordre de priorité) ───
        $queries = [];

        // 1. Recherche la plus stricte : vendor + name + type
        $queries[] = [
            'vendor' => ['value' => $vendorNorm, 'strict' => true],
            'name'   => ['value' => $nameNorm,   'strict' => true],
            'type'   => ['value' => $typeNorm,   'strict' => true],
            'weight' => 100
        ];

        // 2. vendor + name (type approximatif)
        $queries[] = [
            'vendor' => ['value' => $vendorNorm, 'strict' => true],
            'name'   => ['value' => $nameNorm,   'strict' => true],
            'type'   => ['value' => $typeNorm,   'strict' => false],
            'weight' => 85
        ];

        // 3. name seul + type (cas où vendor est implicite ou mal extrait)
        $queries[] = [
            'vendor' => ['value' => null],
            'name'   => ['value' => $nameNorm, 'strict' => true],
            'type'   => ['value' => $typeNorm, 'strict' => true],
            'weight' => 70
        ];

        // 4. vendor + type (cas parfums où le nom dans la base est différent)
        if ($this->isPerfumeRelated($typeNorm)) {
            $queries[] = [
                'vendor' => ['value' => $vendorNorm, 'strict' => true],
                'name'   => ['value' => null],
                'type'   => ['value' => $typeNorm,   'strict' => true],
                'weight' => 60
            ];
        }

        $candidates = collect();

        foreach ($queries as $q) {
            $query = Product::query();

            // Vendor
            if ($q['vendor']['value']) {
                if ($q['vendor']['strict']) {
                    $query->whereRaw('LOWER(vendor) = ?', [$q['vendor']['value']]);
                } else {
                    $query->whereRaw('LOWER(vendor) LIKE ?', ["%{$q['vendor']['value']}%"]);
                }
            }

            // Name
            if ($q['name']['value']) {
                $nameValue = $q['name']['value'];
                if ($q['name']['strict']) {
                    $query->where(function($qry) use ($nameValue) {
                        $qry->whereRaw('LOWER(name) = ?', [$nameValue])
                            ->orWhereRaw('LOWER(name) = ?', [Str::replace(' ', '', $nameValue)]);
                    });
                } else {
                    $query->whereRaw('LOWER(name) LIKE ?', ["%{$nameValue}%"]);
                }
            }

            // Type
            if ($q['type']['value']) {
                $typeValue = $q['type']['value'];
                if ($q['type']['strict']) {
                    $query->where(function($qry) use ($typeValue) {
                        $qry->whereRaw('LOWER(type) = ?', [$typeValue])
                            ->orWhereRaw('LOWER(type) LIKE ?', ["{$typeValue}%"])
                            ->orWhereRaw('LOWER(type) LIKE ?', ["%{$typeValue}"]);
                    });
                } else {
                    $query->whereRaw('LOWER(type) LIKE ?', ["%{$typeValue}%"]);
                }
            }

            $results = $query->get();

            foreach ($results as $product) {
                $score = $this->calculateMatchScore($product, $vendorNorm, $nameNorm, $typeNorm);
                $candidates->push([
                    'product' => $product,
                    'score'   => $score + $q['weight']
                ]);
            }
        }

        // Trier par score descendant
        $sorted = $candidates
            ->sortByDesc('score')
            ->unique('product.id');

        $this->matchingProducts = $sorted->pluck('product')->toArray();
        
        if ($sorted->isNotEmpty()) {
            $this->bestMatch = $sorted->first()['product'];
        } else {
            session()->flash('error', 'Aucun produit correspondant trouvé.');
        }
    }

    // ───────────────────────────────────────────────────────────────
    // Calcul d’un score de pertinence (0-100)
    // ───────────────────────────────────────────────────────────────
    private function calculateMatchScore($product, string $vendorNorm, string $nameNorm, string $typeNorm): int
    {
        $score = 0;

        $dbVendor = Str::lower(Str::ascii($product->vendor ?? ''));
        $dbName   = Str::lower(Str::ascii($product->name ?? ''));
        $dbType   = Str::lower(Str::ascii($product->type ?? ''));

        // Vendor exact
        if ($dbVendor === $vendorNorm) {
            $score += 40;
        } elseif (str_contains($dbVendor, $vendorNorm) || str_contains($vendorNorm, $dbVendor)) {
            $score += 25;
        }

        // Name
        $nameSimilarity = similar_text($dbName, $nameNorm, $percent);
        if ($percent > 85) {
            $score += 45;
        } elseif ($percent > 65 || str_contains($dbName, $nameNorm) || str_contains($nameNorm, $dbName)) {
            $score += 30;
        }

        // Type
        if ($dbType === $typeNorm || str_starts_with($dbType, $typeNorm)) {
            $score += 25;
        } elseif (str_contains($dbType, $typeNorm)) {
            $score += 15;
        }

        // Bonus petite variation contenance
        if ($product->variation && $this->extractedData['variation']) {
            if (str_contains($product->variation, $this->extractedData['variation'])) {
                $score += 10;
            }
        }

        return min(100, $score);
    }

    private function isPerfumeRelated(string $type): bool
    {
        return Str::contains($type, ['parfum', 'eau de', 'deodorant', 'déodorant', 'after shave', 'lotion']);
    }

    // ───────────────────────────────────────────────────────────────
    // Prompts (plus clairs et robustes)
    // ───────────────────────────────────────────────────────────────
    private function getExtractionPrompt(): string
    {
        return <<<'PROMPT'
Tu es un expert en extraction de produits cosmétiques / parfumerie.
Retourne UNIQUEMENT un objet JSON valide avec les clés suivantes :

- vendor: marque principale (ex: Dior, Chanel, Azzaro, Lancôme...)
- name: nom du parfum / gamme / produit (ex: Sauvage, J'adore, Chrome, Ultra Facial...)
- variation: contenance / poids (ex: "100 ml", "50 g", "200 ml") — ou vide
- type: type précis du produit (ex: "Eau de Parfum", "Déodorant", "Crème Visage", "Sérum", "Gel Douche"...)

Règles importantes pour les parfums :
- Si le nom ressemble à "Azzaro Chrome", → vendor: "Azzaro", name: "Chrome"
- Si "Dior Sauvage" → vendor: "Dior", name: "Sauvage"
- Ne jamais mettre la contenance dans le name

Exemple de réponse attendue :
{"vendor":"Azzaro","name":"Chrome","variation":"100 ml","type":"Eau de Toilette"}
PROMPT;
    }

    private function getVerificationPrompt(): string
    {
        return <<<'PROMPT'
Tu es un expert parfumerie/cosmétiques.
Analyse la cohérence entre vendor, name et type.

Retourne UNIQUEMENT un JSON avec :
{
  "is_correct": boolean,
  "confidence": "high"|"medium"|"low",
  "explanation": "courte explication",
  "correction": { "vendor": "...", "name": "...", "type": "..." }  // uniquement les champs à corriger
}
PROMPT;
    }

    private function buildVerificationUserMessage(): string
    {
        return <<<MSG
Produit original : {$this->productName}

Extrait actuellement :
- vendor : {$this->extractedData['vendor'] ?? '—'}
- name   : {$this->extractedData['name'] ?? '—'}
- type   : {$this->extractedData['type'] ?? '—'}

Est-ce cohérent ? Si non, propose une correction.
MSG;
    }

    public function selectProduct($productId)
    {
        $product = Product::find($productId);
        if ($product) {
            $this->bestMatch = $product->toArray();
            session()->flash('success', "Produit sélectionné : {$product->name}");
            $this->dispatch('product-selected', productId: $productId);
        }
    }
}; ?>

<!-- La vue reste presque identique, vous pouvez la conserver -->

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