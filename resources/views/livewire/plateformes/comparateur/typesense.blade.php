<?php

use Livewire\Volt\Component;
use App\Models\Product;
use Illuminate\Support\Collection;

new class extends Component {
    public string $name;
    public string $id;
    public string $price;
    public Collection $products;

    public function mount($name, $id, $price): void
    {
        $this->name = $name;
        $this->id = $id;
        $this->price = $price;
        
        $searchTerm = html_entity_decode($this->name);
        
        // Parser le produit : Valentino - Uomo - Eau de Toilette Vaporisateur 100 ml
        $parts = array_map('trim', explode(' - ', $searchTerm));
        
        $vendor = $parts[0] ?? null;
        $productName = $parts[1] ?? null;
        $type = $parts[2] ?? null;
        
        // Étape 1 : Recherche avec filtres stricts
        $products = $this->searchWithFilters($vendor, $productName, $type);
        
        // Étape 2 : Si pas de résultats, recherche plus flexible sur vendor + name
        if ($products->isEmpty() && $vendor && $productName) {
            $products = $this->searchVendorAndName($vendor, $productName);
        }
        
        // Étape 3 : Si toujours pas de résultats, recherche globale
        if ($products->isEmpty()) {
            $products = Product::search($searchTerm)
                ->query(fn($query) => $query->with('website')->orderByDesc('created_at'))
                ->get();
        }
        
        // Post-filtrage : éliminer les faux positifs
        $this->products = $this->filterExactMatches($products, $vendor, $productName, $type);
    }
    
    private function searchWithFilters(?string $vendor, ?string $productName, ?string $type): Collection
    {
        if (!$vendor || !$productName) {
            return collect([]);
        }
        
        return Product::search('', function ($typesenseSearchParams) use ($vendor, $productName, $type) {
            $filters = [];
            
            // Filtre exact sur vendor
            $filters[] = "vendor:={$vendor}";
            
            // Filtre exact sur type si présent
            if ($type) {
                // Nettoyer le type (enlever la contenance)
                $cleanType = preg_replace('/\s*\d+\s*(ml|g|oz|L)\s*$/i', '', $type);
                $cleanType = trim($cleanType);
                if ($cleanType) {
                    $filters[] = "type:={$cleanType}";
                }
            }
            
            $typesenseSearchParams['q'] = $productName;
            $typesenseSearchParams['query_by'] = 'name';
            $typesenseSearchParams['filter_by'] = implode(' && ', $filters);
            $typesenseSearchParams['prefix'] = false;
            $typesenseSearchParams['num_typos'] = 1;
            $typesenseSearchParams['per_page'] = 100;
            
            return $typesenseSearchParams;
        })->query(fn($query) => $query->with('website')->orderByDesc('created_at'))->get();
    }
    
    private function searchVendorAndName(string $vendor, string $productName): Collection
    {
        return Product::search('', function ($typesenseSearchParams) use ($vendor, $productName) {
            $typesenseSearchParams['q'] = $productName;
            $typesenseSearchParams['query_by'] = 'name';
            $typesenseSearchParams['filter_by'] = "vendor:={$vendor}";
            $typesenseSearchParams['prefix'] = true;
            $typesenseSearchParams['num_typos'] = 2;
            $typesenseSearchParams['per_page'] = 100;
            
            return $typesenseSearchParams;
        })->query(fn($query) => $query->with('website')->orderByDesc('created_at'))->get();
    }
    
    private function filterExactMatches(Collection $products, ?string $vendor, ?string $productName, ?string $type): Collection
    {
        if (!$productName) {
            return $products;
        }
        
        // Normaliser le nom recherché
        $targetName = mb_strtolower(trim($productName));
        $targetWords = preg_split('/\s+/', $targetName, -1, PREG_SPLIT_NO_EMPTY);
        
        return $products->filter(function ($product) use ($targetName, $targetWords, $vendor, $type) {
            $productNameLower = mb_strtolower(trim($product->name));
            
            // Vérification 1 : Le nom du produit doit être EXACTEMENT le nom recherché
            // OU contenir UNIQUEMENT les mots recherchés (pas de mots supplémentaires avant/après)
            
            // Cas 1 : Correspondance exacte
            if ($productNameLower === $targetName) {
                return true;
            }
            
            // Cas 2 : Le nom du produit ne doit PAS avoir de mots AVANT le premier mot recherché
            $productWords = preg_split('/\s+/', $productNameLower, -1, PREG_SPLIT_NO_EMPTY);
            
            // Trouver la position du premier mot recherché dans le nom du produit
            $firstWordIndex = null;
            foreach ($targetWords as $targetWord) {
                foreach ($productWords as $index => $productWord) {
                    if (str_contains($productWord, $targetWord) || str_contains($targetWord, $productWord)) {
                        if ($firstWordIndex === null || $index < $firstWordIndex) {
                            $firstWordIndex = $index;
                        }
                    }
                }
            }
            
            // Si le premier mot recherché n'est PAS au début du nom du produit, rejeter
            // Exemple: "Born in Roma Uomo" rejeté car "Uomo" n'est pas au début
            if ($firstWordIndex !== null && $firstWordIndex > 0) {
                return false;
            }
            
            // Vérification 2 : Tous les mots recherchés doivent être présents
            foreach ($targetWords as $targetWord) {
                if (!str_contains($productNameLower, $targetWord)) {
                    return false;
                }
            }
            
            // Vérification 3 : Le nom du produit ne doit pas contenir plus de 2 mots supplémentaires
            $extraWordsCount = count($productWords) - count($targetWords);
            if ($extraWordsCount > 2) {
                return false;
            }
            
            return true;
        });
    }
    
}; ?>

<div class="bg-white">

    <livewire:plateformes.detail :id="$id" />

    <div class="mx-auto max-w-7xl overflow-hidden sm:px-6 lg:px-8">
        <h2 class="text-2xl font-bold text-gray-900 px-4 sm:px-0 py-6">
            Résultats pour : {{ $name }}
        </h2>

        @if($products->count() > 0)
            <div class="mb-4 px-4 sm:px-0">
                <p class="text-sm text-gray-600">
                    {{ $products->count() }} {{ $products->count() > 1 ? 'produits trouvés' : 'produit trouvé' }}
                </p>
            </div>

            <!-- Grille de tous les produits -->
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
                            <!-- Badge du site -->
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