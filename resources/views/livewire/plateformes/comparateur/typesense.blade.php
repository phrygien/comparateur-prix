<?php

use Livewire\Volt\Component;
use App\Models\Product;
use Livewire\Attributes\Url;
use Livewire\Attributes\Computed;
use OpenAI\Laravel\Facades\OpenAI;

new class extends Component {
    public string $searchText = '';

    #[Url(as: 'vendor')]
    public string $searchVendor = '';

    #[Url(as: 'name')]
    public string $filterName = '';

    #[Url(as: 'type')]
    public string $filterType = '';

    #[Url(as: 'variation')]
    public string $filterVariation = '';

    public array $availableTypes = [];
    public bool $isExtracting = false;

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

    public function extractWithAI(): void
    {
        if (empty($this->searchText)) {
            return;
        }

        $this->isExtracting = true;

        try {
            $result = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Tu es un assistant qui extrait des informations de produits de parfumerie/cosmétiques. Tu dois retourner UNIQUEMENT un JSON valide avec les clés: vendor, name, type, variation. Si une information n\'est pas trouvée, utilise null. Le vendor est la marque. Le name est le nom complet du produit. Le type est le format (Eau de Toilette, Eau de Parfum, etc). La variation est la taille ou autre variante (ex: 100 ml, 50ml, Travel Size, etc).'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Extrait les informations de ce produit: {$this->searchText}"
                    ]
                ],
                'temperature' => 0.3,
                'response_format' => ['type' => 'json_object']
            ]);

            $extracted = json_decode($result->choices[0]->message->content, true);

            // Mettre à jour les filtres avec les valeurs extraites
            $this->searchVendor = $extracted['vendor'] ?? '';
            $this->filterName = $extracted['name'] ?? '';
            $this->filterType = $extracted['type'] ?? '';
            $this->filterVariation = $extracted['variation'] ?? '';

        } catch (\Exception $e) {
            // En cas d'erreur, faire une recherche simple sur le texte
            $this->filterName = $this->searchText;
        } finally {
            $this->isExtracting = false;
        }
    }

    #[Computed]
    public function products()
    {
        $query = Product::search('*');

        // 1. Filtrer par vendor d'abord (TOUJOURS maintenu)
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

        // 4. Filtrer par variation
        if (!empty($this->filterVariation)) {
            $query->where('variation', $this->filterVariation);
        }

        return $query->get();
    }

    public function clearFilters(): void
    {
        $this->searchText = '';
        $this->searchVendor = '';
        $this->filterName = '';
        $this->filterType = '';
        $this->filterVariation = '';
    }

    public function updatedSearchText(): void
    {
        // Auto-extraction quand le texte change (avec debounce via wire:model.live.debounce)
        if (strlen($this->searchText) > 3) {
            $this->extractWithAI();
        }
    }
}; ?>

<div>
    <!-- Recherche intelligente avec AI -->
    <div class="bg-gradient-to-r from-indigo-500 to-purple-600 p-6 mb-6 rounded-lg shadow-lg">
        <div class="max-w-3xl mx-auto">
            <label for="searchText" class="block text-sm font-medium text-white mb-2">
                <span class="flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    Recherche intelligente (ex: Coach - Coach Green Homme - Eau de Toilette 100 ml)
                </span>
            </label>
            <div class="relative">
                <input
                    type="text"
                    id="searchText"
                    wire:model.live.debounce.500ms="searchText"
                    placeholder="Entrez le nom complet du produit..."
                    class="w-full rounded-lg border-0 shadow-sm focus:ring-2 focus:ring-white text-lg py-3 px-4"
                >
                @if($isExtracting)
                    <div class="absolute right-3 top-1/2 -translate-y-1/2">
                        <svg class="animate-spin h-5 w-5 text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Filtres extraits -->
    <div class="bg-white p-6 mb-6 rounded-lg shadow">
        <h3 class="text-sm font-semibold text-gray-700 mb-4">Filtres appliqués :</h3>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <!-- Vendor (toujours maintenu) -->
            <div>
                <label for="searchVendor" class="block text-sm font-medium text-gray-700 mb-2">
                    <span class="flex items-center gap-1">
                        Vendor
                        <svg class="w-4 h-4 text-indigo-500" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
                        </svg>
                    </span>
                </label>
                <input
                    type="text"
                    id="searchVendor"
                    wire:model.live.debounce.300ms="searchVendor"
                    placeholder="Ex: Coach"
                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 bg-indigo-50"
                    readonly
                >
                <p class="mt-1 text-xs text-indigo-600">🔒 Maintenu automatiquement</p>
            </div>

            <!-- Name -->
            <div>
                <label for="filterName" class="block text-sm font-medium text-gray-700 mb-2">
                    Nom du produit
                </label>
                <input
                    type="text"
                    id="filterName"
                    wire:model.live.debounce.300ms="filterName"
                    placeholder="Ex: Coach Green Homme"
                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
            </div>

            <!-- Type -->
            <div>
                <label for="filterType" class="block text-sm font-medium text-gray-700 mb-2">
                    Type
                </label>
                <input
                    type="text"
                    id="filterType"
                    wire:model.live.debounce.300ms="filterType"
                    placeholder="Ex: Eau de Toilette"
                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
            </div>

            <!-- Variation -->
            <div>
                <label for="filterVariation" class="block text-sm font-medium text-gray-700 mb-2">
                    Variation
                </label>
                <input
                    type="text"
                    id="filterVariation"
                    wire:model.live.debounce.300ms="filterVariation"
                    placeholder="Ex: 100 ml"
                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
            </div>
        </div>

        <!-- Actions -->
        @if($searchVendor || $filterName || $filterType || $filterVariation)
            <div class="mt-4 flex items-center justify-between border-t pt-4">
                <button
                    wire:click="clearFilters"
                    class="inline-flex items-center gap-2 text-sm text-indigo-600 hover:text-indigo-500 font-medium"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                    Effacer tous les filtres
                </button>
                <span class="text-sm text-gray-500 font-medium">
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
