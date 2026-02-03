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
        
        // Décoder les entités HTML
        $searchTerm = html_entity_decode($this->name);
        
        // Parser le terme de recherche
        $parsedSearch = $this->parseSearchTerm($searchTerm);
        
        // Construire la recherche Typesense avec des poids
        $products = Product::search($searchTerm, function ($typesenseClient, $query, $params) use ($parsedSearch) {
            // Modifier les paramètres de recherche
            $params['query_by'] = 'vendor,name,type,variation';
            
            // Définir des poids pour prioriser les champs
            // vendor (poids 4), name (poids 3), type (poids 2), variation (poids 1)
            $params['query_by_weights'] = '4,3,2,1';
            
            // Utiliser la recherche exhaustive pour de meilleurs résultats
            $params['exhaustive_search'] = true;
            
            // Filtres optionnels basés sur le parsing
            $filters = [];
            
            if (!empty($parsedSearch['vendor'])) {
                $filters[] = "vendor:=[{$parsedSearch['vendor']}]";
            }
            
            if (!empty($parsedSearch['type'])) {
                $filters[] = "type:=[{$parsedSearch['type']}]";
            }
            
            if (!empty($filters)) {
                $params['filter_by'] = implode(' && ', $filters);
            }
            
            // Nombre de résultats
            $params['per_page'] = 250;
            
            return $typesenseClient->collections[$params['collection']]->documents->search($params);
        })
        ->query(fn($query) => $query->with('website'))
        ->get();
        
        // Grouper par site et sélectionner le dernier produit scrapé par scrap_reference_id
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
    
    /**
     * Parse le terme de recherche pour extraire vendor, name, type et variation
     */
    private function parseSearchTerm(string $searchTerm): array
    {
        $result = [
            'vendor' => '',
            'name' => '',
            'type' => '',
            'variation' => '',
        ];
        
        // Liste des types de produits courants
        $productTypes = [
            'Eau de Parfum',
            'Eau de Toilette',
            'Parfum',
            'Cologne',
            'Body Lotion',
            'Shower Gel',
            'Deodorant',
        ];
        
        // Extraire le type de produit
        foreach ($productTypes as $type) {
            if (stripos($searchTerm, $type) !== false) {
                $result['type'] = $type;
                // Retirer le type du terme de recherche
                $searchTerm = str_ireplace($type, '', $searchTerm);
                break;
            }
        }
        
        // Extraire la variation (ml, g, oz, etc.)
        if (preg_match('/(\d+\s*(ml|g|oz|cl|l))/i', $searchTerm, $matches)) {
            $result['variation'] = trim($matches[0]);
            // Retirer la variation du terme de recherche
            $searchTerm = str_replace($matches[0], '', $searchTerm);
        }
        
        // Nettoyer et séparer le reste (vendor et name)
        $searchTerm = preg_replace('/\s+/', ' ', trim($searchTerm));
        $searchTerm = trim($searchTerm, ' -');
        
        // Diviser par " - " pour séparer vendor et name
        $parts = array_map('trim', explode('-', $searchTerm));
        
        if (count($parts) >= 2) {
            $result['vendor'] = $parts[0];
            $result['name'] = implode(' - ', array_slice($parts, 1));
        } elseif (count($parts) === 1) {
            // Si pas de séparateur, considérer le premier mot comme vendor
            $words = explode(' ', $parts[0]);
            if (count($words) > 1) {
                $result['vendor'] = $words[0];
                $result['name'] = implode(' ', array_slice($words, 1));
            } else {
                $result['name'] = $parts[0];
            }
        }
        
        return $result;
    }
    
}; ?>

<div class="bg-white">
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