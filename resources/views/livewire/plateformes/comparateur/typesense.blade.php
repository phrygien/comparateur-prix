<?php

use Livewire\Volt\Component;
use App\Models\Product;
use Illuminate\Support\Collection;

new class extends Component {
    public string $name;
    public string $id;
    public string $price;
    public Collection $productsBySite;
    public array $selectedTypes = [];
    public array $availableTypes = [];

    public function mount($name, $id, $price): void
    {
        $this->name = $name;
        $this->id = $id;
        $this->price = $price;
        
        $this->loadProducts();
    }

    public function loadProducts(): void
    {
        $searchTerm = html_entity_decode($this->name);
        
        $search = Product::search($searchTerm)
            ->query(fn($query) => $query->with('website'));

        // Appliquer le filtre par type si des types sont sélectionnés
        if (!empty($this->selectedTypes)) {
            $search->where('type', $this->selectedTypes);
        }

        $results = $search->get();
        $products = $results instanceof \Illuminate\Pagination\LengthAwarePaginator 
            ? $results->items() 
            : $results;

        // Récupérer les facets pour les types disponibles
        $this->availableTypes = $this->getTypeFacets($searchTerm);

        $this->productsBySite = collect($products)
            ->groupBy('web_site_id')
            ->map(function ($siteProducts) {
                return $siteProducts
                    ->groupBy('scrap_reference_id')
                    ->map(function ($refProducts) {
                        return $refProducts->sortByDesc('created_at')->first();
                    })
                    ->values();
            });
    }

    protected function getTypeFacets(string $searchTerm): array
    {
        // Effectuer une recherche pour obtenir les facets
        $search = Product::search($searchTerm);
        
        try {
            $rawResults = $search->raw();
            
            if (isset($rawResults['facet_counts']) && is_array($rawResults['facet_counts'])) {
                foreach ($rawResults['facet_counts'] as $facet) {
                    if ($facet['field_name'] === 'type') {
                        return collect($facet['counts'])
                            ->map(fn($count) => [
                                'value' => $count['value'],
                                'count' => $count['count']
                            ])
                            ->toArray();
                    }
                }
            }
        } catch (\Exception $e) {
            // En cas d'erreur, retourner un tableau vide
        }

        return [];
    }

    public function updatedSelectedTypes(): void
    {
        $this->loadProducts();
    }

    public function clearFilters(): void
    {
        $this->selectedTypes = [];
        $this->loadProducts();
    }
}; ?>

<div class="bg-white">
    <livewire:plateformes.detail :id="$id" />

    <div class="mx-auto max-w-7xl overflow-hidden sm:px-6 lg:px-8">
        <div class="px-4 sm:px-0 py-6">
            <h2 class="text-2xl font-bold text-gray-900">
                Résultats pour : {{ $name }}
            </h2>

            <!-- Filtres -->
            @if(count($availableTypes) > 0)
                <div class="mt-6 bg-gray-50 rounded-lg p-4 border border-gray-200">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold text-gray-900">Filtrer par type</h3>
                        @if(count($selectedTypes) > 0)
                            <button 
                                wire:click="clearFilters" 
                                class="text-xs text-blue-600 hover:text-blue-800"
                            >
                                Effacer les filtres
                            </button>
                        @endif
                    </div>
                    
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
                        @foreach($availableTypes as $type)
                            <label class="flex items-start space-x-2 cursor-pointer group">
                                <input 
                                    type="checkbox" 
                                    wire:model.live="selectedTypes"
                                    value="{{ $type['value'] }}"
                                    class="mt-0.5 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                >
                                <span class="text-sm text-gray-700 group-hover:text-gray-900">
                                    {{ $type['value'] }}
                                    <span class="text-gray-500">({{ $type['count'] }})</span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    @if(count($selectedTypes) > 0)
                        <div class="mt-3 pt-3 border-t border-gray-200">
                            <p class="text-xs text-gray-600">
                                Filtres actifs: 
                                <span class="font-medium">{{ implode(', ', $selectedTypes) }}</span>
                            </p>
                        </div>
                    @endif
                </div>
            @endif
        </div>

        @if($productsBySite->count() > 0)
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
                <p class="mt-1 text-sm text-gray-500">
                    @if(count($selectedTypes) > 0)
                        Aucun résultat pour "{{ $name }}" avec les filtres sélectionnés
                    @else
                        Aucun résultat pour "{{ $name }}"
                    @endif
                </p>
            </div>
        @endif
    </div>
</div>