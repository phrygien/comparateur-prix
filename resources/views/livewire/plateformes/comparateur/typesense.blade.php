<?php

use Livewire\Volt\Component;
use App\Models\Product;
use Livewire\Attributes\Url;
use Livewire\Attributes\Computed;

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
        // Charger les types disponibles
        $this->availableTypes = Product::query()
            ->whereNotNull('type')
            ->distinct()
            ->pluck('type')
            ->filter()
            ->sort()
            ->values()
            ->toArray();
    }

    #[Computed]
    public function products()
    {
        $query = Product::search('*');

        // 1. Filtrer par vendor d'abord
        if (!empty($this->searchVendor)) {
            $query->where('vendor', $this->searchVendor);
        }

        // 2. Filtrer par name
        if (!empty($this->filterName)) {
            $query->where('name', $this->filterName);
        }

        // 3. Filtrer par type
        if (!empty($this->filterType)) {
            $query->where('type', $this->filterType);
        }

        return $query->get();
    }

    public function clearFilters(): void
    {
        $this->searchVendor = '';
        $this->filterName = '';
        $this->filterType = '';
    }
}; ?>

<div>
    <!-- Filtres -->
    <div class="bg-white p-6 mb-6 rounded-lg shadow">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <!-- Recherche par Vendor -->
            <div>
                <label for="searchVendor" class="block text-sm font-medium text-gray-700 mb-2">
                    Vendor
                </label>
                <input
                    type="text"
                    id="searchVendor"
                    wire:model.live.debounce.300ms="searchVendor"
                    placeholder="Rechercher par vendor..."
                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
            </div>

            <!-- Filtrer par Name -->
            <div>
                <label for="filterName" class="block text-sm font-medium text-gray-700 mb-2">
                    Nom du produit
                </label>
                <input
                    type="text"
                    id="filterName"
                    wire:model.live.debounce.300ms="filterName"
                    placeholder="Rechercher par nom..."
                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
            </div>

            <!-- Filtrer par Type -->
            <div>
                <label for="filterType" class="block text-sm font-medium text-gray-700 mb-2">
                    Type
                </label>
                <select
                    id="filterType"
                    wire:model.live="filterType"
                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
                    <option value="">Tous les types</option>
                    @foreach($availableTypes as $type)
                        <option value="{{ $type }}">{{ $type }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <!-- Bouton Clear Filters -->
        @if($searchVendor || $filterName || $filterType)
            <div class="mt-4 flex items-center justify-between">
                <button
                    wire:click="clearFilters"
                    class="text-sm text-indigo-600 hover:text-indigo-500 font-medium"
                >
                    Effacer tous les filtres
                </button>
                <span class="text-sm text-gray-500">
                    {{ $this->products->count() }} produit(s) trouvé(s)
                </span>
            </div>
        @endif
    </div>

    <!-- Grid des produits -->
    <div class="bg-white">
        <div class="mx-auto max-w-7xl overflow-hidden sm:px-6 lg:px-8">
            <h2 class="sr-only">Produits</h2>

            @if($this->products->isEmpty())
                <div class="text-center py-12">
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                    </svg>
                    <h3 class="mt-2 text-sm font-medium text-gray-900">Aucun produit trouvé</h3>
                    <p class="mt-1 text-sm text-gray-500">Essayez de modifier vos critères de recherche.</p>
                </div>
            @else
                <div class="-mx-px grid grid-cols-2 border-l border-gray-200 sm:mx-0 md:grid-cols-3 lg:grid-cols-4">
                    @foreach($this->products as $product)
                        <div class="group relative border-r border-b border-gray-200 p-4 sm:p-6">
                            <!-- Image du produit -->
                            <div class="aspect-square rounded-lg bg-gray-200 overflow-hidden">
                                <img
                                    src="{{ $product->image_url ?? 'https://via.placeholder.com/400?text=No+Image' }}"
                                    alt="{{ $product->name }}"
                                    class="h-full w-full object-cover object-center group-hover:opacity-75 transition-opacity"
                                    loading="lazy"
                                    onerror="this.src='https://via.placeholder.com/400?text=No+Image'"
                                >
                            </div>

                            <div class="pt-10 pb-4 text-center">
                                <!-- Nom du produit -->
                                <h3 class="text-sm font-medium text-gray-900">
                                    <a href="{{ $product->url }}" target="_blank" class="hover:text-indigo-600">
                                        <span aria-hidden="true" class="absolute inset-0"></span>
                                        {{ $product->name }}
                                    </a>
                                </h3>

                                <!-- Vendor -->
                                @if($product->vendor)
                                    <p class="mt-2 text-xs text-gray-500 font-medium">
                                        {{ $product->vendor }}
                                    </p>
                                @endif

                                <!-- Type et Variation -->
                                <div class="mt-2 flex flex-wrap justify-center gap-2">
                                    @if($product->type)
                                        <span class="inline-flex items-center rounded-full bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-700/10">
                                            {{ $product->type }}
                                        </span>
                                    @endif
                                    @if($product->variation)
                                        <span class="inline-flex items-center rounded-full bg-gray-50 px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/10">
                                            {{ $product->variation }}
                                        </span>
                                    @endif
                                </div>

                                <!-- Prix -->
                                @if($product->prix_ht)
                                    <p class="mt-4 text-base font-medium text-gray-900">
                                        {{ number_format($product->prix_ht, 2) }} {{ $product->currency ?? '€' }}
                                    </p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
