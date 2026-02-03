<?php

use Livewire\Volt\Component;
use App\Models\Product;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

new class extends Component {
    public string $name;
    public string $id;
    public string $price;
    public Collection $productsBySite;

    #[Url]
    public string $variationFilter = '';

    #[Url]
    public string $typeFilter = '';

    #[Url]
    public string $nameFilter = '';

    public function mount($name, $id, $price): void
    {
        $this->name = $name;
        $this->id = $id;
        $this->price = $price;

        if (empty($this->nameFilter)) {
            $this->nameFilter = $name;
        }

        $this->filterProducts();
    }

    public function filterProducts(): void
    {
        // Recherche avec Typesense avec filtres
        $searchTerm = html_entity_decode($this->nameFilter);

        // Construire les options de recherche
        $searchOptions = [
            'sort_by' => 'created_at:desc',
            'per_page' => 100,
        ];

        // Construire les conditions de filtrage
        $filterConditions = [];

        if ($this->typeFilter) {
            $filterConditions[] = "type:= {$this->typeFilter}";
        }

        if ($this->variationFilter) {
            $filterConditions[] = "variation:= {$this->variationFilter}";
        }

        if (!empty($filterConditions)) {
            $searchOptions['filter_by'] = implode(' && ', $filterConditions);
        }

        $products = Product::search($searchTerm)
            ->options($searchOptions)
            ->query(fn($query) => $query->with('website'))
            ->get();

        $this->productsBySite = $products
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

    public function updated($property): void
    {
        if (in_array($property, ['nameFilter', 'typeFilter', 'variationFilter'])) {
            $this->filterProducts();
        }
    }

    public function resetFilters(): void
    {
        $this->nameFilter = $this->name;
        $this->typeFilter = '';
        $this->variationFilter = '';
        $this->filterProducts();
    }

}; ?>

<div class="bg-white">

    <livewire:plateformes.detail :id="$id" />

    <div class="mx-auto max-w-7xl overflow-hidden sm:px-6 lg:px-8">
        <h2 class="text-2xl font-bold text-gray-900 px-4 sm:px-0 py-6">
            Résultats pour : {{ $name }}
        </h2>

        <!-- Zone de filtres -->
        <div class="bg-gray-50 p-4 sm:p-6 mb-6 rounded-lg border border-gray-200">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <!-- Filtre par nom -->
                <div>
                    <label for="nameFilter" class="block text-sm font-medium text-gray-700 mb-1">
                        Nom du produit
                    </label>
                    <input
                        type="text"
                        id="nameFilter"
                        wire:model.live.debounce.500ms="nameFilter"
                        placeholder="Filtrer par nom..."
                        class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                    >
                </div>

                <!-- Filtre par type -->
                <div>
                    <label for="typeFilter" class="block text-sm font-medium text-gray-700 mb-1">
                        Type
                    </label>
                    <input
                        type="text"
                        id="typeFilter"
                        wire:model.live.debounce.500ms="typeFilter"
                        placeholder="Filtrer par type..."
                        class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                    >
                </div>

                <!-- Filtre par variation -->
                <div>
                    <label for="variationFilter" class="block text-sm font-medium text-gray-700 mb-1">
                        Variation
                    </label>
                    <input
                        type="text"
                        id="variationFilter"
                        wire:model.live.debounce.500ms="variationFilter"
                        placeholder="Filtrer par variation..."
                        class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                    >
                </div>
            </div>

            <!-- Bouton de réinitialisation -->
            <div class="mt-4 flex justify-end">
                <button
                    type="button"
                    wire:click="resetFilters"
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                >
                    Réinitialiser les filtres
                </button>
            </div>
        </div>

        <!-- Statistiques des filtres -->
        @if($typeFilter || $variationFilter)
            <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-md">
                <p class="text-sm text-blue-800">
                    Filtres actifs :
                    @if($typeFilter)
                        <span class="inline-flex items-center px-2 py-1 mr-2 text-xs font-medium text-blue-800 bg-blue-100 rounded-full">
                            Type: {{ $typeFilter }}
                        </span>
                    @endif
                    @if($variationFilter)
                        <span class="inline-flex items-center px-2 py-1 mr-2 text-xs font-medium text-blue-800 bg-blue-100 rounded-full">
                            Variation: {{ $variationFilter }}
                        </span>
                    @endif
                </p>
            </div>
        @endif

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
                    Aucun résultat pour
                    @if($nameFilter !== $name)
                        "{{ $nameFilter }}"
                    @else
                        "{{ $name }}"
                    @endif
                    @if($typeFilter || $variationFilter)
                        avec les filtres appliqués
                    @endif
                </p>
                @if($typeFilter || $variationFilter)
                    <button
                        type="button"
                        wire:click="resetFilters"
                        class="mt-4 px-4 py-2 text-sm font-medium text-blue-600 hover:text-blue-800"
                    >
                        Réinitialiser les filtres
                    </button>
                @endif
            </div>
        @endif
    </div>
</div>
