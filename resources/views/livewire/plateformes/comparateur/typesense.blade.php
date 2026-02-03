<?php

use Livewire\Volt\Component;
use App\Models\Product;
use OpenAI\Laravel\Facades\OpenAI;

new class extends Component {
    public string $name;
    public string $id;
    public string $price;
    public $products;
    public $extractedData;
    public $isLoading = true;
    public $allResults;
    public $canSearch = false;

    public function mount($name, $id, $price): void
    {
        $this->name = $name;
        $this->id = $id;
        $this->price = $price;
        
        // Extraire les informations avec OpenAI
        $this->extractedData = $this->extractProductInfo($this->name);
        
        // Vérifier si on a les 3 champs requis
        $this->canSearch = $this->extractedData['vendor'] && 
                          $this->extractedData['name'] && 
                          $this->extractedData['type'];
        
        if ($this->canSearch) {
            // Rechercher les produits
            $this->searchProducts();
            
            // Filtrer les résultats selon les critères
            $this->filterResults();
        } else {
            $this->products = collect();
            $this->allResults = collect();
            \Log::warning('Cannot search: Missing required fields', $this->extractedData);
        }
        
        $this->isLoading = false;
    }
    
    private function extractProductInfo(string $productName): array
    {
        try {
            $result = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Tu es un assistant qui extrait les informations structurées des noms de produits cosmétiques et parfums. Réponds uniquement en JSON avec les clés: vendor, name, type, variation. 
                        
IMPORTANT: 
- vendor: la marque du produit (OBLIGATOIRE - ex: Hermès, Shiseido, Dior)
- name: le nom exact du produit (OBLIGATOIRE - ex: Barénia, Vital Perfection, J\'adore)
- type: la catégorie/type précis (OBLIGATOIRE - ex: Eau de Parfum Intense, Concentré Correcteur Rides et Taches, Crème Hydratante)
- variation: uniquement la taille/volume (optionnel - ex: 60ml, 20ml, 100g)

Ces 3 premiers champs sont OBLIGATOIRES pour identifier le produit. Ne mets null que si vraiment impossible à extraire.'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Extrait les informations de ce produit: {$productName}\n\nExemples:\n- \"Hermès - Barénia Eau de Parfum Intense Vaporisateur 60ml\" → {\"vendor\": \"Hermès\", \"name\": \"Barénia\", \"type\": \"Eau de Parfum Intense\", \"variation\": \"60ml\"}\n- \"Shiseido - Vital Perfection - Concentré Correcteur Rides & Taches A+ 20 ml\" → {\"vendor\": \"Shiseido\", \"name\": \"Vital Perfection\", \"type\": \"Concentré Correcteur Rides et Taches\", \"variation\": \"20ml\"}"
                    ]
                ],
                'temperature' => 0.2,
                'response_format' => ['type' => 'json_object']
            ]);

            $content = $result->choices[0]->message->content;
            $extracted = json_decode($content, true);
            
            \Log::info('OpenAI Extraction:', $extracted);
            
            return $extracted;
            
        } catch (\Exception $e) {
            \Log::error('OpenAI extraction error: ' . $e->getMessage());
            return [
                'vendor' => null,
                'name' => null,
                'type' => null,
                'variation' => null
            ];
        }
    }
    
    private function searchProducts(): void
    {
        // STRATÉGIE: Rechercher UNIQUEMENT avec vendor + name + type
        // Si l'un des 3 manque, pas de recherche
        
        if (!$this->extractedData['vendor'] || 
            !$this->extractedData['name'] || 
            !$this->extractedData['type']) {
            \Log::warning('Search aborted: Missing required fields');
            $this->allResults = collect();
            return;
        }
        
        // Recherche UNIQUE avec les 3 champs combinés
        $query = trim(implode(' ', [
            $this->extractedData['vendor'],
            $this->extractedData['name'],
            $this->extractedData['type']
        ]));
        
        \Log::info('Search query (vendor+name+type):', ['query' => $query]);
        
        // Rechercher avec Typesense
        $results = Product::search($query)->take(100)->get();
        
        $this->allResults = $results;
        
        \Log::info('Total results found:', ['count' => $this->allResults->count()]);
    }
    
    private function filterResults(): void
    {
        if ($this->allResults->isEmpty()) {
            $this->products = collect();
            return;
        }
        
        $filtered = $this->allResults->filter(function ($product) {
            $score = 0;
            $matches = [];
            
            // 1. Vérifier le vendor (OBLIGATOIRE)
            $vendorMatch = $this->fuzzyMatch(
                $this->extractedData['vendor'], 
                $product->vendor,
                0.75
            );
            
            if (!$vendorMatch) {
                \Log::debug('Vendor mismatch - REJECTED:', [
                    'extracted' => $this->extractedData['vendor'],
                    'product' => $product->vendor,
                    'product_id' => $product->id
                ]);
                return false; // REJET si vendor ne correspond pas
            }
            $score += 30;
            $matches['vendor'] = true;
            
            // 2. Vérifier le name (OBLIGATOIRE)
            $nameMatch = $this->fuzzyMatch(
                $this->extractedData['name'], 
                $product->name,
                0.70
            );
            
            if (!$nameMatch) {
                \Log::debug('Name mismatch - REJECTED:', [
                    'extracted' => $this->extractedData['name'],
                    'product' => $product->name,
                    'product_id' => $product->id
                ]);
                return false; // REJET si name ne correspond pas
            }
            $score += 30;
            $matches['name'] = true;
            
            // 3. Vérifier le type (OBLIGATOIRE)
            $typeMatch = $this->fuzzyMatch(
                $this->extractedData['type'], 
                $product->type,
                0.70
            );
            
            if (!$typeMatch) {
                \Log::debug('Type mismatch - REJECTED:', [
                    'extracted' => $this->extractedData['type'],
                    'product' => $product->type,
                    'product_id' => $product->id
                ]);
                return false; // REJET si type ne correspond pas
            }
            $score += 25;
            $matches['type'] = true;
            
            // 4. Vérifier la variation (OPTIONNEL mais strict si extrait)
            if ($this->extractedData['variation']) {
                $variationMatch = $this->matchVariation(
                    $this->extractedData['variation'], 
                    $product->variation
                );
                
                if (!$variationMatch) {
                    \Log::debug('Variation mismatch - REJECTED:', [
                        'extracted' => $this->extractedData['variation'],
                        'product' => $product->variation,
                        'product_id' => $product->id
                    ]);
                    return false; // REJET si variation extraite mais ne correspond pas
                }
                $score += 15;
                $matches['variation'] = true;
            }
            
            // Si on arrive ici, les 3 champs obligatoires matchent
            $product->match_score = $score;
            $product->matches = $matches;
            $product->match_count = count($matches);
            
            \Log::debug('Product ACCEPTED:', [
                'product_id' => $product->id,
                'score' => $score,
                'matches' => array_keys($matches)
            ]);
            
            return true;
        });
        
        // Trier par score de pertinence
        $this->products = $filtered->sortByDesc('match_score')->values();
        
        \Log::info('Filtered results:', [
            'total_found' => $this->allResults->count(),
            'after_filter' => $this->products->count()
        ]);
    }
    
    private function fuzzyMatch(string $extracted, ?string $productValue, float $threshold = 0.6): bool
    {
        if (empty($productValue)) {
            return false;
        }
        
        $extracted = $this->normalize($extracted);
        $productValue = $this->normalize($productValue);
        
        // Correspondance exacte
        if ($extracted === $productValue) {
            return true;
        }
        
        // Contient (dans les deux sens)
        if (str_contains($productValue, $extracted) || str_contains($extracted, $productValue)) {
            return true;
        }
        
        // Similarité de Levenshtein
        $similarity = 0;
        similar_text($extracted, $productValue, $similarity);
        
        return ($similarity / 100) >= $threshold;
    }
    
    private function matchVariation(string $extracted, ?string $productVariation): bool
    {
        if (empty($productVariation)) {
            return false;
        }
        
        // Normaliser les variations
        $extractedNorm = $this->normalizeVariation($extracted);
        $productNorm = $this->normalizeVariation($productVariation);
        
        // Correspondance exacte
        if ($extractedNorm === $productNorm) {
            return true;
        }
        
        // Extraire les nombres et unités
        preg_match('/(\d+)\s*(ml|g|mg|l|oz|gr|cl)/i', $extractedNorm, $extractedMatches);
        preg_match('/(\d+)\s*(ml|g|mg|l|oz|gr|cl)/i', $productNorm, $productMatches);
        
        if (!empty($extractedMatches) && !empty($productMatches)) {
            $extractedValue = (int)$extractedMatches[1];
            $extractedUnit = strtolower($extractedMatches[2]);
            
            $productValue = (int)$productMatches[1];
            $productUnit = strtolower($productMatches[2]);
            
            // Conversion cl -> ml
            if ($extractedUnit === 'cl') {
                $extractedValue *= 10;
                $extractedUnit = 'ml';
            }
            if ($productUnit === 'cl') {
                $productValue *= 10;
                $productUnit = 'ml';
            }
            
            // Même valeur et même unité
            if ($extractedValue === $productValue && $extractedUnit === $productUnit) {
                return true;
            }
        }
        
        return false;
    }
    
    private function normalize(string $text): string
    {
        $text = html_entity_decode($text);
        $text = strtolower($text);
        // Garder les accents mais supprimer les caractères spéciaux
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }
    
    private function normalizeVariation(string $variation): string
    {
        $variation = html_entity_decode($variation);
        $variation = strtolower($variation);
        $variation = str_replace([' ', '-', '_'], '', $variation);
        return trim($variation);
    }
    
}; ?>

<div class="bg-white">
    <div class="mx-auto max-w-7xl overflow-hidden sm:px-6 lg:px-8">
        <!-- En-tête avec informations extraites -->
        <div class="px-4 sm:px-0 py-6">
            <h2 class="text-2xl font-bold text-gray-900">
                Résultats pour : {{ $name }}
            </h2>
            
            @if($extractedData)
                <div class="mt-4 space-y-3">
                    <!-- Affichage des champs extraits -->
                    <div class="flex flex-wrap gap-2">
                        @if($extractedData['vendor'])
                            <span class="inline-flex items-center rounded-md bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-700/10">
                                <svg class="mr-1 h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z"/>
                                </svg>
                                Marque: {{ $extractedData['vendor'] }}
                            </span>
                        @else
                            <span class="inline-flex items-center rounded-md bg-red-50 px-2 py-1 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-600/10">
                                <svg class="mr-1 h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                                </svg>
                                Marque: non détectée
                            </span>
                        @endif
                        
                        @if($extractedData['name'])
                            <span class="inline-flex items-center rounded-md bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20">
                                <svg class="mr-1 h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M4 3a2 2 0 100 4h12a2 2 0 100-4H4z"/>
                                    <path fill-rule="evenodd" d="M3 8h14v7a2 2 0 01-2 2H5a2 2 0 01-2-2V8zm5 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z" clip-rule="evenodd"/>
                                </svg>
                                Produit: {{ $extractedData['name'] }}
                            </span>
                        @else
                            <span class="inline-flex items-center rounded-md bg-red-50 px-2 py-1 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-600/10">
                                <svg class="mr-1 h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                                </svg>
                                Produit: non détecté
                            </span>
                        @endif
                        
                        @if($extractedData['type'])
                            <span class="inline-flex items-center rounded-md bg-purple-50 px-2 py-1 text-xs font-medium text-purple-700 ring-1 ring-inset ring-purple-700/10">
                                <svg class="mr-1 h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M2 5a2 2 0 012-2h12a2 2 0 012 2v10a2 2 0 01-2 2H4a2 2 0 01-2-2V5zm3.293 1.293a1 1 0 011.414 0l3 3a1 1 0 010 1.414l-3 3a1 1 0 01-1.414-1.414L7.586 10 5.293 7.707a1 1 0 010-1.414zM11 12a1 1 0 100 2h3a1 1 0 100-2h-3z" clip-rule="evenodd"/>
                                </svg>
                                Type: {{ $extractedData['type'] }}
                            </span>
                        @else
                            <span class="inline-flex items-center rounded-md bg-red-50 px-2 py-1 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-600/10">
                                <svg class="mr-1 h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                                </svg>
                                Type: non détecté
                            </span>
                        @endif
                        
                        @if($extractedData['variation'])
                            <span class="inline-flex items-center rounded-md bg-yellow-50 px-2 py-1 text-xs font-medium text-yellow-800 ring-1 ring-inset ring-yellow-600/20">
                                <svg class="mr-1 h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/>
                                </svg>
                                Volume: {{ $extractedData['variation'] }}
                            </span>
                        @endif
                    </div>
                    
                    @if(!$canSearch)
                        <!-- Alerte si recherche impossible -->
                        <div class="rounded-md bg-yellow-50 p-4">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
                                    </svg>
                                </div>
                                <div class="ml-3">
                                    <h3 class="text-sm font-medium text-yellow-800">Recherche impossible</h3>
                                    <div class="mt-2 text-sm text-yellow-700">
                                        <p>Les 3 champs obligatoires (Marque, Produit, Type) doivent être détectés pour effectuer une recherche.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @else
                        <!-- Statistiques de recherche -->
                        <div class="flex items-center justify-between border-t pt-3">
                            <p class="text-sm text-gray-600">
                                <span class="font-semibold text-gray-900">{{ $products->count() }}</span> 
                                produit(s) correspondant(s)
                                @if($allResults->count() > $products->count())
                                    <span class="text-gray-400">
                                        ({{ $allResults->count() - $products->count() }} filtrés)
                                    </span>
                                @endif
                            </p>
                            
                            <div class="text-xs text-gray-500">
                                <span class="inline-flex items-center gap-1">
                                    <svg class="h-4 w-4 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"/>
                                    </svg>
                                    Recherche 3 critères (vendor+name+type)
                                </span>
                            </div>
                        </div>
                    @endif
                </div>
            @endif
        </div>

        @if($products->count() > 0)
            <div class="-mx-px grid grid-cols-2 border-l border-gray-200 sm:mx-0 md:grid-cols-3 lg:grid-cols-4">
                @foreach($products as $product)
                    <div class="group relative border-r border-b border-gray-200 p-4 sm:p-6">
                        <!-- Badge de pertinence -->
                        <div class="absolute top-2 right-2 z-10 flex flex-col gap-1 items-end">
                            <span class="inline-flex items-center rounded-full bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20">
                                ✓ Match parfait
                            </span>
                        </div>
                        
                        <img 
                            src="{{ $product->image_url }}" 
                            alt="{{ $product->vendor }} - {{ $product->name }}" 
                            class="aspect-square rounded-lg bg-gray-200 object-cover group-hover:opacity-75"
                            loading="lazy"
                        >
                        <div class="pt-10 pb-4 text-center">
                            <h3 class="text-sm font-medium text-gray-900">
                                <a href="{{ $product->url }}" target="_blank">
                                    <span aria-hidden="true" class="absolute inset-0"></span>
                                    {{ $product->vendor }} - {{ $product->name }}
                                </a>
                            </h3>
                            <div class="mt-3 flex flex-col items-center gap-1">
                                <p class="text-xs text-gray-600 font-medium">{{ $product->type }}</p>
                                <p class="text-xs text-gray-500">{{ $product->variation }}</p>
                                
                                <!-- Indicateurs de match -->
                                @if(isset($product->matches))
                                    <div class="mt-2 flex gap-1">
                                        @if(isset($product->matches['vendor']))
                                            <span class="inline-block h-1.5 w-1.5 rounded-full bg-blue-600" title="Marque vérifiée"></span>
                                        @endif
                                        @if(isset($product->matches['name']))
                                            <span class="inline-block h-1.5 w-1.5 rounded-full bg-green-600" title="Nom vérifié"></span>
                                        @endif
                                        @if(isset($product->matches['type']))
                                            <span class="inline-block h-1.5 w-1.5 rounded-full bg-purple-600" title="Type vérifié"></span>
                                        @endif
                                        @if(isset($product->matches['variation']))
                                            <span class="inline-block h-1.5 w-1.5 rounded-full bg-yellow-600" title="Volume vérifié"></span>
                                        @endif
                                    </div>
                                @endif
                            </div>
                            <p class="mt-4 text-base font-medium text-gray-900">
                                {{ $product->prix_ht }} {{ $product->currency }}
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>
        @elseif($canSearch)
            <div class="text-center py-12">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <h3 class="mt-2 text-sm font-semibold text-gray-900">Aucun produit correspondant</h3>
                <p class="mt-1 text-sm text-gray-500">
                    Aucun produit ne correspond exactement aux 3 critères obligatoires
                </p>
                <div class="mt-4 text-xs text-gray-500 bg-gray-50 rounded-lg p-4 inline-block">
                    <p class="font-semibold mb-2">Critères de recherche appliqués :</p>
                    <ul class="text-left space-y-1">
                        <li>✓ Marque: {{ $extractedData['vendor'] }}</li>
                        <li>✓ Produit: {{ $extractedData['name'] }}</li>
                        <li>✓ Type: {{ $extractedData['type'] }}</li>
                        @if($extractedData['variation'])
                            <li>✓ Volume: {{ $extractedData['variation'] }}</li>
                        @endif
                    </ul>
                    <p class="mt-3 text-gray-400">{{ $allResults->count() }} produit(s) trouvé(s) mais aucun ne correspond exactement</p>
                </div>
            </div>
        @endif
    </div>
</div>