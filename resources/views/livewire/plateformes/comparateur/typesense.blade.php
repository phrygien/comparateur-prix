<?php

use Livewire\Volt\Component;
use App\Models\Product;
use App\Services\ProductSearchService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

new class extends Component {
    public string $name;
    public string $id;
    public string $price;
    public Collection $products;
    public ?string $extractedVendor = null;
    public ?string $extractedName = null;
    public ?string $extractedType = null;
    public ?string $extractedVariation = null;
    public int $stepResults = 0; // Pour debug: nombre de résultats à chaque étape

    public function mount($name, $id, $price): void
    {
        $this->name = $name;
        $this->id = $id;
        $this->price = $price;

        $searchTerm = html_entity_decode($this->name);

        // Extraire les informations avec OpenAI
        $productSearchService = app(ProductSearchService::class);
        $extracted = $productSearchService->extractProductInfo($searchTerm);

        $this->extractedVendor = $extracted['vendor'];
        $this->extractedName = $extracted['name'];
        $this->extractedType = $extracted['type'];
        $this->extractedVariation = $extracted['variation'];

        // Rechercher avec filtrage progressif
        $this->products = $this->searchProductsProgressive($extracted, $searchTerm);
    }

    private function searchProductsProgressive(array $extracted, string $fallbackSearch): Collection
    {
        $productSearchService = app(ProductSearchService::class);

        // ÉTAPE 1: Recherche Scout par vendor
        $searchQuery = $extracted['vendor'] ?? $fallbackSearch;
        $results = Product::search($searchQuery)
            ->query(fn($q) => $q->with('website'))
            ->get();

        $this->stepResults = $results->count();

        // Si pas de résultats après Scout, retourner vide
        if ($results->isEmpty()) {
            return collect();
        }

        // ÉTAPE 2: Filtrer par name (normalisé)
        if (!empty($extracted['name'])) {
            $normalizedSearchName = $productSearchService->normalizeForSearch($extracted['name']);

            $results = $results->filter(function($product) use ($normalizedSearchName) {
                $normalizedDbName = app(ProductSearchService::class)->normalizeForSearch($product->name);

                // Vérifier si le name normalisé contient le terme recherché
                return $normalizedDbName && str_contains($normalizedDbName, $normalizedSearchName);
            });
        }

        // Si pas de résultats après filtrage name, retourner ce qu'on a
        if ($results->isEmpty()) {
            return $results;
        }

        // ÉTAPE 3: Filtrer par type (normalisé)
        if (!empty($extracted['type'])) {
            $normalizedSearchType = $productSearchService->normalizeForSearch($extracted['type']);

            $results = $results->filter(function($product) use ($normalizedSearchType) {
                $normalizedDbType = app(ProductSearchService::class)->normalizeForSearch($product->type);

                // Vérifier si le type normalisé contient le terme recherché
                return $normalizedDbType && str_contains($normalizedDbType, $normalizedSearchType);
            });
        }

        // Si pas de résultats après filtrage type, on recule d'un step (on retourne sans le filtre type)
        if ($results->isEmpty() && !empty($extracted['type'])) {
            // Refiltrer juste par name
            $results = Product::search($searchQuery)
                ->query(fn($q) => $q->with('website'))
                ->get();

            if (!empty($extracted['name'])) {
                $normalizedSearchName = $productSearchService->normalizeForSearch($extracted['name']);

                $results = $results->filter(function($product) use ($normalizedSearchName) {
                    $normalizedDbName = app(ProductSearchService::class)->normalizeForSearch($product->name);
                    return $normalizedDbName && str_contains($normalizedDbName, $normalizedSearchName);
                });
            }
        }

        // Trier par date de création
        return $results->sortByDesc('created_at');
    }

}; ?>

<div class="bg-white">

    <livewire:plateformes.detail :id="$id" />

    <div class="mx-auto max-w-7xl overflow-hidden sm:px-6 lg:px-8">
        <h2 class="text-2xl font-bold text-gray-900 px-4 sm:px-0 py-6">
            Résultats pour : {{ $name }}
        </h2>

        <!-- Informations d'extraction détaillées -->
        @if($extractedVendor || $extractedName || $extractedType || $extractedVariation)
            <div class="mb-4 px-4 sm:px-0 bg-gray-50 p-3 rounded-lg">
                <p class="text-xs font-semibold text-gray-700 mb-2">Extraction des informations :</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-xs">
                    @if($extractedVendor)
                        <div>
                            <span class="text-gray-600">Vendor (Scout):</span>
                            <span class="font-medium text-blue-700">{{ $extractedVendor }}</span>
                        </div>
                    @endif
                    @if($extractedName)
                        <div>
                            <span class="text-gray-600">Name (Filtre 1):</span>
                            <span class="font-medium text-green-700">{{ $extractedName }}</span>
                        </div>
                    @endif
                    @if($extractedType)
                        <div>
                            <span class="text-gray-600">Type (Filtre 2):</span>
                            <span class="font-medium text-purple-700">{{ $extractedType }}</span>
                        </div>
                    @endif
                    @if($extractedVariation)
                        <div>
                            <span class="text-gray-600">Variation:</span>
                            <span class="font-medium text-gray-700">{{ $extractedVariation }}</span>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        @if($products->count() > 0)
            <div class="mb-4 px-4 sm:px-0">
                <p class="text-sm text-gray-600">
                    {{ $products->count() }} {{ $products->count() > 1 ? 'produits trouvés' : 'produit trouvé' }}
                </p>
            </div>

            <div class="-mx-px grid grid-cols-2 border-l border-gray-200 sm:mx-0 md:grid-cols-3 lg:grid-cols-4">
                @foreach($products as $product)
                    <div class="group relative border-r border-b border-gray-200 p-4 sm:p-6">
                        <div class="aspect-square rounded-lg bg-gray-200 overflow-hidden">
                            <img
                                src="{{ $product->image_url }}"
                                alt="{{ $product->vendor }} - {{ $product->name }}"
                                class="h-full w-full object-cover group-hover:opacity-75"
                            >
                        </div>
                        <div class="pt-10 pb-4 text-center">
                            @if($product->website)
                                <div class="mb-2">
                                    <span class="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800">
                                        {{ $product->website->name }}
                                    </span>
                                </div>
                            @endif

                            <h3 class="text-sm font-medium text-gray-900">
                                <a href="{{ $product->url }}" target="_blank">
                                    <span aria-hidden="true" class="absolute inset-0"></span>
                                    {{ $product->vendor }} - {{ $product->name }}
                                </a>
                            </h3>
                            <div class="mt-3 flex flex-col items-center">
                                <p class="text-xs text-gray-600">{{ $product->type }}</p>
                                @if($product->variation)
                                    <p class="mt-1 text-xs text-gray-500">{{ $product->variation }}</p>
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
