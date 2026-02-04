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
        
        // Recherche avec configuration optimale Typesense
        $this->products = $this->performSearch($vendor, $productName, $type, $searchTerm);
    }
    
    private function performSearch(?string $vendor, ?string $productName, ?string $type, string $fallbackSearch): Collection
    {
        // Si on a un format parsé complet
        if ($vendor && $productName) {
            return Product::search($productName, function ($typesenseSearchParams) use ($vendor, $productName, $type) {
                $filters = [];
                
                // Filtre EXACT sur vendor
                $filters[] = "vendor:={$vendor}";
                
                // Filtre sur type si présent
                if ($type) {
                    $cleanType = preg_replace('/\s*\d+\s*(ml|g|oz|L)\s*$/i', '', $type);
                    $cleanType = trim($cleanType);
                    if ($cleanType) {
                        $filters[] = "type:={$cleanType}";
                    }
                }
                
                // Recherche sur le nom du produit
                $typesenseSearchParams['q'] = $productName;
                $typesenseSearchParams['query_by'] = 'name';
                
                // Configuration pour correspondance EXACTE
                $typesenseSearchParams['filter_by'] = implode(' && ', $filters);
                
                // CLÉS IMPORTANTES pour éviter les faux positifs
                $typesenseSearchParams['prefix'] = false; // Pas de recherche par préfixe
                $typesenseSearchParams['num_typos'] = 0; // Aucune tolérance aux fautes
                $typesenseSearchParams['drop_tokens_threshold'] = 0; // Ne pas supprimer de mots
                $typesenseSearchParams['prioritize_exact_match'] = true; // Prioriser les correspondances exactes
                
                // Tri par pertinence textuelle
                $typesenseSearchParams['sort_by'] = '_text_match:desc,created_at:desc';
                
                // Type de matching : max_score pour prioriser les correspondances exactes
                $typesenseSearchParams['text_match_type'] = 'max_score';
                
                $typesenseSearchParams['per_page'] = 100;
                
                return $typesenseSearchParams;
            })->query(fn($query) => $query->with('website')->orderByDesc('created_at'))->get();
        }
        
        // Recherche de secours si pas de format parsé
        return Product::search($fallbackSearch)
            ->query(fn($query) => $query->with('website')->orderByDesc('created_at'))
            ->get();
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
                <div class="flex items-center justify-between">
                    <p class="text-sm text-gray-600">
                        {{ $products->count() }} {{ $products->count() > 1 ? 'produits trouvés' : 'produit trouvé' }}
                    </p>
                    <p class="text-xs text-gray-500">
                        Triés par pertinence
                    </p>
                </div>
            </div>

            <!-- Grille de tous les produits -->
            <div class="-mx-px grid grid-cols-2 border-l border-gray-200 sm:mx-0 md:grid-cols-3 lg:grid-cols-4">
                @foreach($products as $index => $product)
                    <div class="group relative border-r border-b border-gray-200 p-4 sm:p-6 {{ $index === 0 ? 'bg-green-50/30' : '' }}">
                        <div class="aspect-square rounded-lg bg-gray-200 overflow-hidden">
                            <img 
                                src="{{ $product->image_url }}" 
                                alt="{{ $product->vendor }} - {{ $product->name }}" 
                                class="h-full w-full object-cover group-hover:opacity-75"
                            >
                        </div>
                        <div class="pt-10 pb-4 text-center">
                            <!-- Badge de meilleure correspondance -->
                            @if($index === 0)
                                <div class="mb-2">
                                    <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-semibold text-green-800">
                                        <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                        </svg>
                                        Meilleure correspondance
                                    </span>
                                </div>
                            @endif
                            
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
                <div class="mt-4 p-4 bg-blue-50 rounded-lg text-left max-w-md mx-auto">
                    <p class="text-sm font-medium text-gray-700 mb-2">💡 La recherche était trop stricte</p>
                    <p class="text-xs text-gray-600">
                        Essayez avec une recherche plus générale ou vérifiez l'orthographe exacte de la marque et du nom du produit.
                    </p>
                </div>
            </div>
        @endif
    </div>
</div>