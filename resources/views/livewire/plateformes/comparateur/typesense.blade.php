<?php

use Livewire\Volt\Component;
use App\Models\Product;
use Illuminate\Support\Collection;

new class extends Component {
    public string $name;
    public string $id;
    public string $price;
    public Collection $productsBySite;
    public array $facets = [];
    public array $selectedFilters = [
        'web_site_id' => [],
        'type' => [],
        'variation' => [],
    ];

    public function mount($name, $id, $price): void
    {
        $this->name = $name;
        $this->id = $id;
        $this->price = $price;
        
        $this->searchProducts();
    }

    public function searchProducts(): void
    {
        $searchTerm = html_entity_decode($this->name);
        
        // Construction des paramètres de recherche
        $searchParams = [
            'query_by' => 'name,vendor,type,variation',
            'facet_by' => 'web_site_id,type,variation',
            'group_by' => 'scrap_reference_id',
            'group_limit' => 1,
            'sort_by' => 'created_at:desc',
        ];

        // Ajout des filtres sélectionnés
        $filters = [];
        foreach ($this->selectedFilters as $field => $values) {
            if (!empty($values)) {
                $filters[] = $field . ':=[' . implode(',', $values) . ']';
            }
        }
        if (!empty($filters)) {
            $searchParams['filter_by'] = implode(' && ', $filters);
        }

        // Recherche avec Typesense
        $searchResult = Product::search($searchTerm, function ($typesenseSearch, $query, $searchParams) {
            return $typesenseSearch->with($searchParams);
        }, $searchParams)
            ->query(fn($query) => $query->with('website'))
            ->get();

        // Récupération des facettes
        $this->facets = $searchResult->metadata['facet_counts'] ?? [];

        // Grouper les produits par site
        $this->productsBySite = $searchResult->groupBy('web_site_id');
    }

    public function toggleFilter(string $field, string|int $value): void
    {
        $key = array_search($value, $this->selectedFilters[$field]);
        
        if ($key !== false) {
            unset($this->selectedFilters[$field][$key]);
            $this->selectedFilters[$field] = array_values($this->selectedFilters[$field]);
        } else {
            $this->selectedFilters[$field][] = $value;
        }
        
        $this->searchProducts();
    }

    public function clearFilters(): void
    {
        $this->selectedFilters = [
            'web_site_id' => [],
            'type' => [],
            'variation' => [],
        ];
        $this->searchProducts();
    }
    
}; ?>

<div class="bg-white">

    <livewire:plateformes.detail :id="$id" />

    <div class="mx-auto max-w-7xl overflow-hidden sm:px-6 lg:px-8">
        <h2 class="text-2xl font-bold text-gray-900 px-4 sm:px-0 py-6">
            Résultats pour : {{ $name }}
        </h2>

        @if($productsBySite->count() > 0)
            <!-- Section des filtres -->
            <div class="bg-gray-50 border-y border-gray-200 px-4 sm:px-6 py-4 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">Filtres</h3>
                    @if(collect($selectedFilters)->flatten()->isNotEmpty())
                        <button 
                            wire:click="clearFilters" 
                            class="text-sm text-blue-600 hover:text-blue-800"
                        >
                            Réinitialiser les filtres
                        </button>
                    @endif
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <!-- Filtre par site -->
                    @if(isset($facets['web_site_id']['counts']) && count($facets['web_site_id']['counts']) > 0)
                        <div>
                            <h4 class="text-sm font-medium text-gray-700 mb-2">Sites Web</h4>
                            <div class="space-y-2">
                                @foreach($facets['web_site_id']['counts'] as $facet)
                                    @php
                                        $website = \App\Models\Website::find($facet['value']);
                                        $isSelected = in_array($facet['value'], $selectedFilters['web_site_id']);
                                    @endphp
                                    <label class="flex items-center space-x-2 cursor-pointer">
                                        <input 
                                            type="checkbox" 
                                            wire:click="toggleFilter('web_site_id', {{ $facet['value'] }})"
                                            {{ $isSelected ? 'checked' : '' }}
                                            class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                        >
                                        <span class="text-sm text-gray-600">
                                            {{ $website?->name ?? 'Site inconnu' }} 
                                            <span class="text-gray-400">({{ $facet['count'] }})</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <!-- Filtre par type -->
                    @if(isset($facets['type']['counts']) && count($facets['type']['counts']) > 0)
                        <div>
                            <h4 class="text-sm font-medium text-gray-700 mb-2">Types</h4>
                            <div class="space-y-2">
                                @foreach($facets['type']['counts'] as $facet)
                                    @php
                                        $isSelected = in_array($facet['value'], $selectedFilters['type']);
                                    @endphp
                                    <label class="flex items-center space-x-2 cursor-pointer">
                                        <input 
                                            type="checkbox" 
                                            wire:click="toggleFilter('type', '{{ $facet['value'] }}')"
                                            {{ $isSelected ? 'checked' : '' }}
                                            class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                        >
                                        <span class="text-sm text-gray-600">
                                            {{ $facet['value'] }} 
                                            <span class="text-gray-400">({{ $facet['count'] }})</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <!-- Filtre par variation -->
                    @if(isset($facets['variation']['counts']) && count($facets['variation']['counts']) > 0)
                        <div>
                            <h4 class="text-sm font-medium text-gray-700 mb-2">Variations</h4>
                            <div class="space-y-2">
                                @foreach($facets['variation']['counts'] as $facet)
                                    @php
                                        $isSelected = in_array($facet['value'], $selectedFilters['variation']);
                                    @endphp
                                    <label class="flex items-center space-x-2 cursor-pointer">
                                        <input 
                                            type="checkbox" 
                                            wire:click="toggleFilter('variation', '{{ $facet['value'] }}')"
                                            {{ $isSelected ? 'checked' : '' }}
                                            class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                        >
                                        <span class="text-sm text-gray-600">
                                            {{ $facet['value'] }} 
                                            <span class="text-gray-400">({{ $facet['count'] }})</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            @foreach($productsBySite as $siteId => $siteProducts)
                @php
                    $site = $siteProducts->first()->website ?? null;
                @endphp
                
                <div class="mb-8">
                    <!-- En-tête du site -->
                    <div class="bg-gray-50 px-4 sm:px-6 py-4 border-b-2 border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">
                            {{ $site?->name ?? 'Site inconnu' }}
                        </h3>
                        @if($site?->url)
                            <a href="{{ $site->url }}" target="_blank" class="text-sm text-blue-600 hover:text-blue-800">
                                {{ $site->url }}
                            </a>
                        @endif
                        <p class="text-sm text-gray-500 mt-1">
                            {{ $siteProducts->count() }} {{ $siteProducts->count() > 1 ? 'produits' : 'produit' }}
                        </p>
                    </div>

                    <!-- Grille des produits du site -->
                    <div class="-mx-px grid grid-cols-2 border-l border-gray-200 sm:mx-0 md:grid-cols-3 lg:grid-cols-4">
                        @foreach($siteProducts as $product)
                            <div class="group relative border-r border-b border-gray-200 p-4 sm:p-6">
                                <div class="aspect-square rounded-lg bg-gray-200 overflow-hidden">
                                    <img 
                                        src="{{ $product->image_url }}" 
                                        alt="{{ $product->vendor }} - {{ $product->name }}" 
                                        class="h-full w-full object-cover group-hover:opacity-75"
                                    >
                                </div>
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
                                        @if($product->scrap_reference_id)
                                            <p class="mt-1 text-xs text-gray-400">Réf: {{ $product->scrap_reference_id }}</p>
                                        @endif
                                        @if($product->created_at)
                                            <p class="mt-1 text-xs text-gray-400">
                                                Scrapé le {{ $product->created_at->format('d/m/Y') }}
                                            </p>
                                        @endif
                                    </div>
                                    <p class="mt-4 text-base font-medium text-gray-900">
                                        {{ $product->prix_ht }} {{ $product->currency }}
                                    </p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
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