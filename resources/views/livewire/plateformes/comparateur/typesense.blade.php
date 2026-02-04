<?php

use Livewire\Volt\Component;
use App\Models\Product;
use App\Services\ProductSearchParser;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public $results = [];
    public $parsedSearch = [];
    public bool $showResults = false;

    public function updatedSearch()
    {
        $this->resetPage();
        $this->performSearch();
    }

    public function performSearch()
    {
        if (strlen($this->search) < 3) {
            $this->results = [];
            $this->showResults = false;
            return;
        }

        // Parser la recherche
        $parser = new ProductSearchParser();
        $this->parsedSearch = $parser->parse($this->search);

        // Recherche avec Typesense
        $query = Product::search('', function ($typesenseSearchParams, $query) {
            $filters = [];
            
            // Filtre strict sur vendor si présent
            if (!empty($this->parsedSearch['vendor'])) {
                // Utiliser :* pour une correspondance exacte insensible à la casse
                $filters[] = "vendor:=`{$this->parsedSearch['vendor']}`";
            }
            
            // Filtre strict sur type si présent
            if (!empty($this->parsedSearch['type'])) {
                $filters[] = "type:=`{$this->parsedSearch['type']}`";
            }
            
            // Si on a un nom, on cherche dessus
            if (!empty($this->parsedSearch['name'])) {
                $typesenseSearchParams['q'] = $this->parsedSearch['name'];
                $typesenseSearchParams['query_by'] = 'name';
            } else {
                // Sinon recherche globale
                $typesenseSearchParams['q'] = $this->search;
                $typesenseSearchParams['query_by'] = 'vendor,name,type';
            }
            
            // Appliquer les filtres
            if (!empty($filters)) {
                $typesenseSearchParams['filter_by'] = implode(' && ', $filters);
            }
            
            // Configuration stricte
            $typesenseSearchParams['prefix'] = false;
            $typesenseSearchParams['num_typos'] = 0;
            $typesenseSearchParams['per_page'] = 20;
            
            return $typesenseSearchParams;
        });

        $this->results = $query->paginate(20);
        $this->showResults = true;
    }

    public function clearSearch()
    {
        $this->search = '';
        $this->results = [];
        $this->parsedSearch = [];
        $this->showResults = false;
    }

    public function with(): array
    {
        return [];
    }
}; ?>

<div class="w-full max-w-4xl mx-auto p-6">
    <div class="mb-6">
        <label class="block text-sm font-medium text-gray-700 mb-2">
            Recherche de produit
            <span class="text-xs text-gray-500 font-normal ml-2">
                Format: Marque - Nom - Type (ex: Hermès - Un Jardin Sous la Mer - Eau de Toilette)
            </span>
        </label>
        <div class="relative">
            <input 
                type="text" 
                wire:model.live.debounce.500ms="search"
                placeholder="Ex: Hermès - Un Jardin Sous la Mer - Eau de Toilette"
                class="w-full px-4 py-3 pr-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            />
            @if($search)
                <button 
                    wire:click="clearSearch"
                    type="button"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            @endif
        </div>

        <!-- Affichage des critères parsés -->
        @if(!empty($parsedSearch) && ($parsedSearch['vendor'] || $parsedSearch['name'] || $parsedSearch['type']))
            <div class="mt-3 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <p class="font-semibold text-gray-700 mb-2 text-sm flex items-center">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                    </svg>
                    Filtres actifs (recherche stricte)
                </p>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    @if(!empty($parsedSearch['vendor']))
                        <div class="bg-white p-2 rounded border border-blue-300">
                            <span class="text-xs text-gray-600 block">Marque (exact):</span>
                            <span class="font-medium text-blue-700">{{ $parsedSearch['vendor'] }}</span>
                        </div>
                    @endif
                    @if(!empty($parsedSearch['name']))
                        <div class="bg-white p-2 rounded border border-blue-300">
                            <span class="text-xs text-gray-600 block">Nom:</span>
                            <span class="font-medium text-blue-700">{{ $parsedSearch['name'] }}</span>
                        </div>
                    @endif
                    @if(!empty($parsedSearch['type']))
                        <div class="bg-white p-2 rounded border border-blue-300">
                            <span class="text-xs text-gray-600 block">Type (exact):</span>
                            <span class="font-medium text-blue-700">{{ $parsedSearch['type'] }}</span>
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>

    <!-- Résultats -->
    @if($showResults)
        <div class="bg-white rounded-lg shadow-lg border border-gray-200">
            @if($results->count() > 0)
                <div class="p-4 border-b bg-gradient-to-r from-blue-50 to-indigo-50">
                    <p class="text-sm font-medium text-gray-700">
                        {{ $results->total() }} résultat(s) trouvé(s)
                    </p>
                </div>

                <div class="divide-y divide-gray-200">
                    @foreach($results as $product)
                        <div class="p-4 hover:bg-gray-50 transition-colors duration-150">
                            <div class="flex items-start gap-4">
                                @if($product->image_url)
                                    <div class="flex-shrink-0">
                                        <img 
                                            src="{{ $product->image_url }}" 
                                            alt="{{ $product->name }}"
                                            class="w-24 h-24 object-cover rounded-lg border border-gray-200"
                                        />
                                    </div>
                                @endif
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="flex-1">
                                            <h3 class="font-semibold text-gray-900 text-lg">
                                                <span class="text-blue-600">{{ $product->vendor }}</span> - {{ $product->name }}
                                            </h3>
                                            <div class="mt-1 space-y-1">
                                                <p class="text-sm text-gray-600">
                                                    <span class="font-medium">Type:</span> {{ $product->type }}
                                                </p>
                                                @if($product->variation)
                                                    <p class="text-sm text-gray-600">
                                                        <span class="font-medium">Variation:</span> {{ $product->variation }}
                                                    </p>
                                                @endif
                                            </div>
                                        </div>
                                        @if($product->prix_ht)
                                            <div class="flex-shrink-0 text-right">
                                                <p class="text-lg font-bold text-blue-600">
                                                    {{ number_format($product->prix_ht, 2) }} {{ $product->currency ?? '€' }}
                                                </p>
                                                <p class="text-xs text-gray-500">HT</p>
                                            </div>
                                        @endif
                                    </div>
                                    @if($product->url)
                                        <a 
                                            href="{{ $product->url }}" 
                                            target="_blank"
                                            class="inline-flex items-center text-sm text-blue-600 hover:text-blue-800 hover:underline mt-2 font-medium"
                                        >
                                            Voir le produit 
                                            <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                            </svg>
                                        </a>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="p-4 border-t bg-gray-50">
                    {{ $results->links() }}
                </div>
            @else
                <div class="p-12 text-center">
                    <svg class="w-16 h-16 mx-auto text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Aucun résultat trouvé</h3>
                    <p class="text-gray-500 text-sm">
                        Aucun produit ne correspond exactement à vos critères de recherche.
                    </p>
                    <p class="text-gray-400 text-xs mt-2">
                        Essayez de modifier les critères ou vérifiez l'orthographe.
                    </p>
                </div>
            @endif
        </div>
    @endif

    <!-- Indicateur de chargement -->
    <div wire:loading class="fixed inset-0 bg-black bg-opacity-30 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 shadow-xl">
            <div class="flex items-center space-x-3">
                <svg class="animate-spin h-6 w-6 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span class="text-gray-700 font-medium">Recherche en cours...</span>
            </div>
        </div>
    </div>
</div>