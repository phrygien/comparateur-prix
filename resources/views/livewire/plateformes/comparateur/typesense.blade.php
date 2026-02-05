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
    public array $selectedVariations = [];

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
        
        // Construction de la requête de recherche
        $searchQuery = Product::search($searchTerm);

        // Ajout du filtre sur les variations sélectionnées
        if (!empty($this->selectedVariations)) {
            $searchQuery->options([
                'filter_by' => 'variation:=[' . implode(',', array_map(function($v) {
                    return str_replace("'", "\\'", $v);
                }, $this->selectedVariations)) . ']'
            ]);
        }

        // Ajout des options de recherche
        $searchQuery->options([
            'facet_by' => 'variation',
            'group_by' => 'scrap_reference_id',
            'group_limit' => 1,
            'sort_by' => 'created_at:desc',
            'max_facet_values' => 100,
        ]);

        // Exécution de la recherche avec eager loading
        $searchResult = $searchQuery
            ->query(fn($query) => $query->with('website'))
            ->raw();

        // Récupération des facettes depuis les métadonnées
        $this->facets = $searchResult['facet_counts'] ?? [];

        // Récupération des IDs des produits trouvés
        $productIds = collect($searchResult['hits'] ?? [])
            ->pluck('document.id')
            ->toArray();

        // Charger les produits avec leurs relations
        $products = Product::with('website')
            ->whereIn('id', $productIds)
            ->get()
            ->sortBy(function ($product) use ($productIds) {
                return array_search($product->id, $productIds);
            });

        // Grouper les produits par site
        $this->productsBySite = $products->groupBy('web_site_id');
    }

    public function toggleVariation(string $value): void
    {
        $key = array_search($value, $this->selectedVariations);
        
        if ($key !== false) {
            unset($this->selectedVariations[$key]);
            $this->selectedVariations = array_values($this->selectedVariations);
        } else {
            $this->selectedVariations[] = $value;
        }
        
        $this->searchProducts();
    }

    public function clearFilters(): void
    {
        $this->selectedVariations = [];
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
            @php
                $variationFacet = collect($facets)->firstWhere('field_name', 'variation');
            @endphp
            @if($variationFacet && isset($variationFacet['counts']) && count($variationFacet['counts']) > 0)
                <div class="bg-gray-50 border-y border-gray-200 px-4 sm:px-6 py-4 mb-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-900">Filtrer par variation</h3>
                        @if(!empty($selectedVariations))
                            <button 
                                wire:click="clearFilters" 
                                class="text-sm text-blue-600 hover:text-blue-800"
                            >
                                Réinitialiser les filtres
                            </button>
                        @endif
                    </div>

                    <div class="space-y-2 max-h-64 overflow-y-auto">
                        @foreach($variationFacet['counts'] as $facet)
                            @php
                                $isSelected = in_array($facet['value'], $selectedVariations);
                            @endphp
                            <label class="flex items-center space-x-2 cursor-pointer hover:bg-gray-100 p-2 rounded">
                                <input 
                                    type="checkbox" 
                                    wire:click="toggleVariation('{{ addslashes($facet['value']) }}')"
                                    {{ $isSelected ? 'checked' : '' }}
                                    class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                >
                                <span class="text-sm text-gray-600 flex-1">
                                    {{ $facet['value'] }}
                                </span>
                                <span class="text-xs text-gray-400 bg-gray-200 px-2 py-1 rounded-full">
                                    {{ $facet['count'] }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif

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
                                        @if($product->variation)
                                            <p class="mt-1 text-xs font-medium text-blue-600">{{ $product->variation }}</p>
                                        @endif
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