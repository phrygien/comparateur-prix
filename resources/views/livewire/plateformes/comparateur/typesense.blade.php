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

    public function mount($name, $id, $price): void
    {
        $this->name = $name;
        $this->id = $id;
        $this->price = $price;
        
        // Extraire les informations avec OpenAI
        $this->extractedData = $this->extractProductInfo($this->name);
        
        // Rechercher les produits
        $this->searchProducts();
        
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
                        'content' => 'Tu es un assistant qui extrait les informations structurées des noms de produits cosmétiques et parfums. Réponds uniquement en JSON avec les clés: vendor, name, type, variation. Si une information n\'est pas présente, mets null.'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Extrait les informations de ce produit: {$productName}\n\nExemple de réponse:\n{\"vendor\": \"Hermès\", \"name\": \"Barénia\", \"type\": \"Eau de Parfum Intense\", \"variation\": \"60ml\"}"
                    ]
                ],
                'temperature' => 0.3,
                'response_format' => ['type' => 'json_object']
            ]);

            $content = $result->choices[0]->message->content;
            return json_decode($content, true);
            
        } catch (\Exception $e) {
            // En cas d'erreur, retourner des valeurs par défaut
            return [
                'vendor' => null,
                'name' => $productName,
                'type' => null,
                'variation' => null
            ];
        }
    }
    
    private function searchProducts(): void
    {
        $searches = collect();
        
        // Recherche 1: Recherche complète avec le nom original
        $fullSearch = Product::search(html_entity_decode($this->name))->get();
        $searches = $searches->merge($fullSearch);
        
        // Recherche 2: Par vendor + name
        if ($this->extractedData['vendor'] && $this->extractedData['name']) {
            $vendorNameSearch = Product::search(
                $this->extractedData['vendor'] . ' ' . $this->extractedData['name']
            )->get();
            $searches = $searches->merge($vendorNameSearch);
        }
        
        // Recherche 3: Par name seul
        if ($this->extractedData['name']) {
            $nameSearch = Product::search($this->extractedData['name'])->get();
            $searches = $searches->merge($nameSearch);
        }
        
        // Recherche 4: Par vendor seul si disponible
        if ($this->extractedData['vendor']) {
            $vendorSearch = Product::search($this->extractedData['vendor'])->get();
            $searches = $searches->merge($vendorSearch);
        }
        
        // Recherche 5: Par type si disponible
        if ($this->extractedData['type']) {
            $typeSearch = Product::search($this->extractedData['type'])->get();
            $searches = $searches->merge($typeSearch);
        }
        
        // Fusionner et dédupliquer par ID
        $this->products = $searches->unique('id')->values();
        
        // Optionnel: Trier par pertinence (produits qui matchent vendor + name en premier)
        if ($this->extractedData['vendor'] && $this->extractedData['name']) {
            $this->products = $this->products->sortByDesc(function ($product) {
                $score = 0;
                
                // Bonus si le vendor correspond
                if (stripos($product->vendor, $this->extractedData['vendor']) !== false) {
                    $score += 10;
                }
                
                // Bonus si le name correspond
                if (stripos($product->name, $this->extractedData['name']) !== false) {
                    $score += 10;
                }
                
                // Bonus si le type correspond
                if ($this->extractedData['type'] && 
                    stripos($product->type, $this->extractedData['type']) !== false) {
                    $score += 5;
                }
                
                // Bonus si la variation correspond
                if ($this->extractedData['variation'] && 
                    stripos($product->variation, $this->extractedData['variation']) !== false) {
                    $score += 3;
                }
                
                return $score;
            })->values();
        }
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
                <div class="mt-4 flex flex-wrap gap-2">
                    @if($extractedData['vendor'])
                        <span class="inline-flex items-center rounded-md bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-700/10">
                            Marque: {{ $extractedData['vendor'] }}
                        </span>
                    @endif
                    
                    @if($extractedData['name'])
                        <span class="inline-flex items-center rounded-md bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20">
                            Produit: {{ $extractedData['name'] }}
                        </span>
                    @endif
                    
                    @if($extractedData['type'])
                        <span class="inline-flex items-center rounded-md bg-purple-50 px-2 py-1 text-xs font-medium text-purple-700 ring-1 ring-inset ring-purple-700/10">
                            Type: {{ $extractedData['type'] }}
                        </span>
                    @endif
                    
                    @if($extractedData['variation'])
                        <span class="inline-flex items-center rounded-md bg-yellow-50 px-2 py-1 text-xs font-medium text-yellow-800 ring-1 ring-inset ring-yellow-600/20">
                            Variation: {{ $extractedData['variation'] }}
                        </span>
                    @endif
                </div>
            @endif
            
            <p class="mt-2 text-sm text-gray-600">
                {{ $products->count() }} résultat(s) trouvé(s)
            </p>
        </div>

        @if($products->count() > 0)
            <div class="-mx-px grid grid-cols-2 border-l border-gray-200 sm:mx-0 md:grid-cols-3 lg:grid-cols-4">
                @foreach($products as $product)
                    <div class="group relative border-r border-b border-gray-200 p-4 sm:p-6">
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
                            <div class="mt-3 flex flex-col items-center">
                                <p class="text-xs text-gray-600">{{ $product->type }}</p>
                                <p class="mt-1 text-xs text-gray-500">{{ $product->variation }}</p>
                            </div>
                            <p class="mt-4 text-base font-medium text-gray-900">
                                {{ $product->prix_ht }} {{ $product->currency }}
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="text-center py-12">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <h3 class="mt-2 text-sm font-semibold text-gray-900">Aucun produit trouvé</h3>
                <p class="mt-1 text-sm text-gray-500">Aucun résultat pour "{{ $name }}"</p>
            </div>
        @endif
    </div>
</div>