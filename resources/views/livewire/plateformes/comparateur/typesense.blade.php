<?php

use Livewire\Volt\Component;
use App\Models\Product;
use Livewire\Attributes\Url;

new class extends Component {
    #[Url(as: 'vendor')]
    public string $searchVendor = '';
    
    #[Url(as: 'name')]
    public string $filterName = '';
    
    #[Url(as: 'type')]
    public string $filterType = '';
    
    public array $availableTypes = [];
    
    public function mount(): void
    {
        $this->loadFilters();
    }
    
    public function loadFilters(): void
    {
        // Récupérer les types disponibles via facettes
        $facets = Product::search('*')
            ->options([
                'query_by' => 'vendor',
                'facet_by' => 'type',
                'max_facet_values' => 50,
                'per_page' => 0,
            ])
            ->raw();
        
        if (isset($facets['facet_counts'])) {
            foreach ($facets['facet_counts'] as $facet) {
                if ($facet['field_name'] === 'type') {
                    $this->availableTypes = collect($facet['counts'])
                        ->pluck('value')
                        ->toArray();
                }
            }
        }
    }
    
    public function with(): array
    {
        return [
            'products' => $this->searchProducts(),
        ];
    }
    
    public function searchProducts()
    {
        // La recherche principale est sur le vendor
        $query = !empty($this->searchVendor) ? $this->searchVendor : '*';
        
        // Construire les filtres
        $filters = [];
        
        // Filtre par nom (exact ou partiel)
        if (!empty($this->filterName)) {
            $filters[] = "name:=" . $this->escapeFilterValue($this->filterName);
        }
        
        // Filtre par type
        if (!empty($this->filterType)) {
            $filters[] = "type:=" . $this->escapeFilterValue($this->filterType);
        }
        
        // Configuration de la recherche
        $searchOptions = [
            'query_by' => 'vendor', // Recherche uniquement sur vendor
            'exclude_fields' => 'embedding',
        ];
        
        // Ajouter les filtres si présents
        if (!empty($filters)) {
            $searchOptions['filter_by'] = implode(' && ', $filters);
        }
        
        return Product::search($query)
            ->options($searchOptions)
            ->get();
    }
    
    /**
     * Échapper les valeurs de filtre pour Typesense
     */
    protected function escapeFilterValue(string $value): string
    {
        return '`' . str_replace('`', '\`', $value) . '`';
    }
    
    public function clearFilters(): void
    {
        $this->searchVendor = '';
        $this->filterName = '';
        $this->filterType = '';
    }
    
    public function updatedSearchVendor(): void
    {
        // Auto-refresh lors de la modification de la recherche vendor
    }
    
    public function updatedFilterName(): void
    {
        // Auto-refresh lors de la modification du filtre name
    }
    
    public function updatedFilterType(): void
    {
        // Auto-refresh lors de la modification du filtre type
    }

}; ?>

<div class="bg-white">
    <div class="mx-auto max-w-2xl px-4 py-16 sm:px-6 sm:py-24 lg:max-w-7xl lg:px-8">
        
        {{-- Barre de recherche et filtres --}}
        <div class="mb-8 space-y-6">
            {{-- Recherche principale par VENDOR --}}
            <div>
                <label for="searchVendor" class="block text-sm font-medium text-gray-700 mb-2">
                    Rechercher par fournisseur
                </label>
                <div class="relative">
                    <input 
                        type="text" 
                        id="searchVendor"
                        wire:model.live.debounce.500ms="searchVendor"
                        placeholder="Recherchez un fournisseur (ex: Nike, Adidas...)"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 pl-10"
                    >
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <svg class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                </div>
                <p class="mt-1 text-xs text-gray-500">
                    La recherche principale s'effectue sur le nom du fournisseur
                </p>
            </div>
            
            {{-- Filtres secondaires --}}
            <div class="border-t pt-6">
                <h3 class="text-sm font-medium text-gray-700 mb-4">Filtres</h3>
                
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    {{-- Filtre par nom de produit --}}
                    <div>
                        <label for="filterName" class="block text-sm font-medium text-gray-700 mb-2">
                            Nom du produit
                        </label>
                        <input 
                            type="text" 
                            id="filterName"
                            wire:model.live.debounce.500ms="filterName"
                            placeholder="Filtrer par nom..."
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        >
                    </div>
                    
                    {{-- Filtre par type --}}
                    <div>
                        <label for="filterType" class="block text-sm font-medium text-gray-700 mb-2">
                            Type de produit
                        </label>
                        <select 
                            id="filterType"
                            wire:model.live="filterType"
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        >
                            <option value="">Tous les types</option>
                            @foreach($availableTypes as $type)
                                <option value="{{ $type }}">{{ ucfirst($type) }}</option>
                            @endforeach
                        </select>
                    </div>
                    
                    {{-- Bouton reset --}}
                    <div class="flex items-end">
                        <button 
                            wire:click="clearFilters"
                            class="w-full rounded-lg bg-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition-colors"
                        >
                            <span class="flex items-center justify-center">
                                <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                                Réinitialiser
                            </span>
                        </button>
                    </div>
                </div>
            </div>
            
            {{-- Indicateur de résultats et filtres actifs --}}
            <div class="flex items-center justify-between border-t pt-4">
                <div class="text-sm text-gray-600">
                    <span class="font-semibold">{{ count($products) }}</span> produit(s) trouvé(s)
                </div>
                
                {{-- Badges des filtres actifs --}}
                @if($searchVendor || $filterName || $filterType)
                    <div class="flex flex-wrap gap-2">
                        @if($searchVendor)
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800">
                                <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/>
                                </svg>
                                Vendor: {{ $searchVendor }}
                            </span>
                        @endif
                        @if($filterName)
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h8a2 2 0 012 2v12a1 1 0 110 2h-3a1 1 0 01-1-1v-2a1 1 0 00-1-1H9a1 1 0 00-1 1v2a1 1 0 01-1 1H4a1 1 0 110-2V4zm3 1h2v2H7V5zm2 4H7v2h2V9zm2-4h2v2h-2V5zm2 4h-2v2h2V9z" clip-rule="evenodd"/>
                                </svg>
                                Nom: {{ $filterName }}
                            </span>
                        @endif
                        @if($filterType)
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M7 2a1 1 0 00-.707 1.707L7 4.414v3.758a1 1 0 01-.293.707l-4 4C.817 14.769 2.156 18 4.828 18h10.343c2.673 0 4.012-3.231 2.122-5.121l-4-4A1 1 0 0113 8.172V4.414l.707-.707A1 1 0 0013 2H7zm2 6.172V4h2v4.172a3 3 0 00.879 2.12l1.027 1.028a4 4 0 00-2.171.102l-.47.156a4 4 0 01-2.53 0l-.563-.187a1.993 1.993 0 00-.114-.035l1.063-1.063A3 3 0 009 8.172z" clip-rule="evenodd"/>
                                </svg>
                                Type: {{ $filterType }}
                            </span>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        <h2 class="sr-only">Products</h2>

        {{-- Liste des produits --}}
        @if($products->count() > 0)
            <div class="grid grid-cols-1 gap-y-4 sm:grid-cols-2 sm:gap-x-6 sm:gap-y-10 lg:grid-cols-3 lg:gap-x-8">
                @foreach($products as $product)
                    <div class="group relative flex flex-col overflow-hidden rounded-lg border border-gray-200 bg-white hover:shadow-lg transition-shadow">
                        {{-- Image du produit --}}
                        @if($product->image_url)
                            <img 
                                src="{{ $product->image_url }}" 
                                alt="{{ $product->name }}" 
                                class="aspect-3/4 w-full bg-gray-200 object-cover group-hover:opacity-75 sm:aspect-auto sm:h-96 transition-opacity"
                                loading="lazy"
                            >
                        @else
                            <div class="aspect-3/4 w-full bg-gray-200 flex items-center justify-center sm:aspect-auto sm:h-96">
                                <svg class="h-24 w-24 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                            </div>
                        @endif
                        
                        {{-- Informations du produit --}}
                        <div class="flex flex-1 flex-col space-y-2 p-4">
                            <h3 class="text-sm font-medium text-gray-900">
                                @if($product->url)
                                    <a href="{{ $product->url }}" target="_blank" class="hover:text-indigo-600">
                                        <span aria-hidden="true" class="absolute inset-0"></span>
                                        {{ $product->name }}
                                    </a>
                                @else
                                    {{ $product->name }}
                                @endif
                            </h3>
                            
                            {{-- Vendor --}}
                            <p class="text-xs text-gray-500 font-medium">
                                <svg class="inline w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/>
                                </svg>
                                {{ $product->vendor }}
                            </p>
                            
                            {{-- Type et variation --}}
                            <div class="flex flex-wrap gap-1">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800">
                                    {{ $product->type }}
                                </span>
                                @if($product->variation)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                        {{ $product->variation }}
                                    </span>
                                @endif
                            </div>
                            
                            {{-- Prix --}}
                            <div class="flex flex-1 flex-col justify-end pt-2">
                                <p class="text-base font-medium text-gray-900">
                                    {{ $product->prix_ht }} {{ $product->currency }}
                                    <span class="text-xs text-gray-500">HT</span>
                                </p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            {{-- Message si aucun résultat --}}
            <div class="text-center py-12">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <h3 class="mt-2 text-sm font-medium text-gray-900">Aucun produit trouvé</h3>
                <p class="mt-1 text-sm text-gray-500">
                    @if($searchVendor)
                        Aucun produit trouvé pour le fournisseur "{{ $searchVendor }}"
                    @else
                        Recherchez un fournisseur pour commencer
                    @endif
                </p>
                @if($searchVendor || $filterName || $filterType)
                    <button 
                        wire:click="clearFilters"
                        class="mt-4 inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-indigo-700 bg-indigo-100 hover:bg-indigo-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                    >
                        Réinitialiser tous les filtres
                    </button>
                @endif
            </div>
        @endif
    </div>
</div>