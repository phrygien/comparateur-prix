<?php

use Livewire\Volt\Component;
use App\Models\Product;
use Illuminate\Support\Collection;

new class extends Component {
    public string $name;
    public string $id;
    public string $price;
    public Collection $productsBySite;

    public function mount($name, $id, $price): void
    {
        $this->name = $name;
        $this->id = $id;
        $this->price = $price;
        
        $searchTerm = html_entity_decode($this->name);
        
        // 1. Récupérer les produits triés directement par requête
        $products = Product::search($searchTerm)
            ->query(function($query) {
                $query->with('website')
                    ->orderBy('web_site_id')
                    ->orderBy('scrap_reference_id')
                    ->orderByDesc('created_at');
            })
            ->get();

        // 2. Filtrer pour garder uniquement le plus récent par site et référence
        $uniqueProducts = new Collection();
        
        foreach ($products as $product) {
            $key = $product->web_site_id . '-' . $product->scrap_reference_id;
            
            // Si on n'a pas encore ce couple site-référence, on l'ajoute
            // Ou si on l'a mais que le produit est plus récent
            if (!$uniqueProducts->has($key) || 
                $product->created_at > $uniqueProducts[$key]->created_at) {
                $uniqueProducts[$key] = $product;
            }
        }

        // 3. Grouper par site
        $this->productsBySite = $uniqueProducts->values()
            ->groupBy('web_site_id')
            ->map(function($siteProducts) {
                // Pour chaque site, retourner directement la collection
                return $siteProducts->values();
            });
    }
    
    // Méthode pour compter le nombre total de produits uniques
    public function getTotalProductsProperty(): int
    {
        return $this->productsBySite->sum(fn($products) => $products->count());
    }
};

<div class="bg-white">

    <livewire:plateformes.detail :id="$id" />

    <div class="mx-auto max-w-7xl overflow-hidden sm:px-6 lg:px-8">
        <h2 class="text-2xl font-bold text-gray-900 px-4 sm:px-0 py-6">
            Résultats pour : {{ $name }}
        </h2>

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
                <p class="mt-1 text-sm text-gray-500">Aucun résultat pour "{{ $name }}"</p>
            </div>
        @endif
    </div>
</div>