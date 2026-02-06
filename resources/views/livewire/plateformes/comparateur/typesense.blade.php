<?php

use Livewire\Volt\Component;
use App\Models\Product;
use Livewire\Attributes\Computed;

new class extends Component {
    public string $name = '';
    public string $price = '';
    public string $id = '';
    public string $searchText = '';

    public function mount($name = '', $price = '', $id = ''): void
    {
        $this->name = $name;
        $this->price = $price;
        $this->id = $id;

        // Initialiser la recherche avec le nom si fourni
        if (!empty($name)) {
            $this->searchText = $name;
        }
    }

    #[Computed]
    public function products()
    {
        if (empty($this->searchText)) {
            return collect([]);
        }

        return Product::search($this->searchText)->get();
    }

    public function clearSearch(): void
    {
        $this->searchText = '';
    }
}; ?>

<div>
    <!-- Informations du produit recherché -->
    @if($name || $price || $id)
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
            <h3 class="text-sm font-semibold text-blue-900 mb-2">Informations du produit :</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                @if($id)
                    <div>
                        <span class="text-blue-600 font-medium">ID:</span>
                        <span class="text-blue-900">{{ $id }}</span>
                    </div>
                @endif
                @if($name)
                    <div>
                        <span class="text-blue-600 font-medium">Nom:</span>
                        <span class="text-blue-900">{{ $name }}</span>
                    </div>
                @endif
                @if($price)
                    <div>
                        <span class="text-blue-600 font-medium">Prix:</span>
                        <span class="text-blue-900">{{ $price }}</span>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <!-- Barre de recherche -->
    <div class="bg-gradient-to-r from-indigo-500 to-purple-600 p-6 mb-6 rounded-lg shadow-lg">
        <div class="max-w-3xl mx-auto">
            <label for="searchText" class="block text-sm font-medium text-white mb-2">
                <span class="flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    Rechercher des produits similaires
                </span>
            </label>
            <div class="relative">
                <input
                    type="text"
                    id="searchText"
                    wire:model.live.debounce.300ms="searchText"
                    placeholder="Ex: Flacon coeur, Coach Green Homme..."
                    class="w-full rounded-lg border-0 shadow-sm focus:ring-2 focus:ring-white text-lg py-3 px-4 pr-12"
                >
                @if($searchText)
                    <button
                        wire:click="clearSearch"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                @endif
            </div>
            @if($searchText)
                <p class="mt-2 text-sm text-white/90">
                    {{ $this->products->count() }} produit(s) trouvé(s)
                </p>
            @endif
        </div>
    </div>

    <!-- Grid des produits -->
    <div class="bg-white">
        <div class="mx-auto max-w-7xl overflow-hidden sm:px-6 lg:px-8">
            <h2 class="sr-only">Produits</h2>

            @if(empty($searchText))
                <div class="text-center py-12">
                    <svg class="mx-auto h-16 w-16 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    <h3 class="mt-4 text-lg font-medium text-gray-900">
                        @if($name)
                            Recherche initialisée avec "{{ $name }}"
                        @else
                            Commencez votre recherche
                        @endif
                    </h3>
                    <p class="mt-2 text-sm text-gray-500">
                        @if($name)
                            Modifiez le texte ci-dessus pour affiner votre recherche.
                        @else
                            Entrez un mot-clé pour rechercher des produits.
                        @endif
                    </p>
                </div>
            @elseif($this->products->isEmpty())
                <div class="text-center py-12">
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                    </svg>
                    <h3 class="mt-2 text-sm font-medium text-gray-900">Aucun produit trouvé</h3>
                    <p class="mt-1 text-sm text-gray-500">Aucun résultat pour "{{ $searchText }}"</p>
                    <button
                        wire:click="clearSearch"
                        class="mt-4 inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-indigo-700 bg-indigo-100 hover:bg-indigo-200"
                    >
                        Effacer la recherche
                    </button>
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
                                <!-- Badge si c'est le produit recherché -->
                                @if($product->id == $id)
                                    <div class="absolute top-2 right-2">
                                        <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">
                                            Recherché
                                        </span>
                                    </div>
                                @endif

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
                                        {{ number_format((float) $product->prix_ht, 2) }} {{ $product->currency ?? '€' }}
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
