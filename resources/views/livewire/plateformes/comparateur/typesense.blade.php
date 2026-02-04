<?php

use Livewire\Volt\Component;
use App\Models\Product;
use App\Services\ProductSearchParser;
use Livewire\WithPagination;
use Livewire\Attributes\Computed;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public array $parsedSearch = [];
    public bool $showResults = false;

    public function updatedSearch()
    {
        $this->resetPage();
        $this->showResults = strlen($this->search) >= 3;
    }

    #[Computed]
    public function results()
    {
        if (strlen($this->search) < 3) {
            return collect([]);
        }

        // Parser la recherche
        $parser = new ProductSearchParser();
        $this->parsedSearch = $parser->parse($this->search);

        // Recherche avec Typesense
        $query = Product::search('', function ($typesenseSearchParams, $query) use ($parser) {
            $filters = [];
            
            // Filtre STRICT sur vendor (exact match)
            if (!empty($this->parsedSearch['vendor'])) {
                $filters[] = "vendor:={$this->parsedSearch['vendor']}";
            }
            
            // Filtre STRICT sur type (exact match)
            if (!empty($this->parsedSearch['type'])) {
                $filters[] = "type:={$this->parsedSearch['type']}";
            }
            
            // Recherche FLEXIBLE sur le nom mais avec tri par pertinence
            if (!empty($this->parsedSearch['name'])) {
                $typesenseSearchParams['q'] = $this->parsedSearch['name'];
                $typesenseSearchParams['query_by'] = 'name';
                
                // Configuration pour recherche flexible
                $typesenseSearchParams['prefix'] = true; // Permet la recherche par préfixe
                $typesenseSearchParams['num_typos'] = 2; // Tolère 2 fautes de frappe
                $typesenseSearchParams['typo_tokens_threshold'] = 1; // Nombre minimum de tokens pour activer la tolérance
                
                // Pondération pour prioriser les correspondances exactes
                $typesenseSearchParams['query_by_weights'] = '1'; // Poids sur le nom
                
            } else {
                // Recherche globale si pas de parsing réussi
                $typesenseSearchParams['q'] = $this->search;
                $typesenseSearchParams['query_by'] = 'name,vendor,type';
                $typesenseSearchParams['query_by_weights'] = '3,2,1'; // Prioriser le nom
            }
            
            // Appliquer les filtres
            if (!empty($filters)) {
                $typesenseSearchParams['filter_by'] = implode(' && ', $filters);
            }
            
            // TRI PAR PERTINENCE (text match score)
            $typesenseSearchParams['sort_by'] = '_text_match:desc,created_at:desc';
            
            $typesenseSearchParams['per_page'] = 50; // Plus de résultats pour mieux voir la pertinence
            
            return $typesenseSearchParams;
        });

        return $query->paginate(50);
    }

    public function clearSearch()
    {
        $this->search = '';
        $this->parsedSearch = [];
        $this->showResults = false;
        $this->resetPage();
    }
}; ?>

<div class="w-full max-w-4xl mx-auto p-6">
    <div class="mb-6">
        <label class="block text-sm font-medium text-gray-700 mb-2">
            Recherche de produit
            <span class="text-xs text-gray-500 font-normal ml-2">
                Format: Marque - Nom - Type (ex: Hermès - Un Jardin Sous la Mer - Eau de Toilette)
            </span>
        </label>
        <div class="relative">
            <input 
                type="text" 
                wire:model.live.debounce.500ms="search"
                placeholder="Ex: Hermès - Un Jardin Sous la Mer - Eau de Toilette"
                class="w-full px-4 py-3 pr-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            />
            @if($search)
                <button 
                    wire:click="clearSearch"
                    type="button"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            @endif
        </div>

        <!-- Affichage des critères parsés -->
        @if(!empty($parsedSearch) && ($parsedSearch['vendor'] ?? false || $parsedSearch['name'] ?? false || $parsedSearch['type'] ?? false))
            <div class="mt-3 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <p class="font-semibold text-gray-700 mb-2 text-sm flex items-center">
                    <svg class="w-4 h-4 mr-2 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                    </svg>
                    Filtres de recherche (triés par pertinence)
                </p>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    @if(!empty($parsedSearch['vendor']))
                        <div class="bg-white p-2 rounded border-l-4 border-red-500">
                            <span class="text-xs text-gray-600 block">Marque (EXACT):</span>
                            <span class="font-bold text-red-700">{{ $parsedSearch['vendor'] }}</span>
                        </div>
                    @endif
                    @if(!empty($parsedSearch['name']))
                        <div class="bg-white p-2 rounded border-l-4 border-blue-500">
                            <span class="text-xs text-gray-600 block">Nom (FLEXIBLE):</span>
                            <span class="font-bold text-blue-700">{{ $parsedSearch['name'] }}</span>
                        </div>
                    @endif
                    @if(!empty($parsedSearch['type']))
                        <div class="bg-white p-2 rounded border-l-4 border-red-500">
                            <span class="text-xs text-gray-600 block">Type (EXACT):</span>
                            <span class="font-bold text-red-700">{{ $parsedSearch['type'] }}</span>
                        </div>
                    @endif
                </div>
                <p class="text-xs text-blue-600 mt-2 flex items-center">
                    <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                    </svg>
                    Les résultats sont triés par pertinence (meilleure correspondance en premier)
                </p>
            </div>
        @endif
    </div>

    <!-- Résultats -->
    @if($showResults)
        <div class="bg-white rounded-lg shadow-lg border border-gray-200">
            @if($this->results->count() > 0)
                <div class="p-4 border-b bg-gradient-to-r from-green-50 to-emerald-50">
                    <p class="text-sm font-medium text-gray-700 flex items-center">
                        <svg class="w-5 h-5 mr-2 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        {{ $this->results->total() }} résultat(s) trouvé(s) - triés par pertinence
                    </p>
                </div>

                <div class="divide-y divide-gray-200">
                    @foreach($this->results as $index => $product)
                        <div class="p-4 hover:bg-gray-50 transition-colors duration-150 {{ $index < 3 ? 'bg-green-50/30' : '' }}">
                            <div class="flex items-start gap-4">
                                <!-- Badge de pertinence pour les 3 premiers -->
                                @if($index < 3)
                                    <div class="flex-shrink-0">
                                        <div class="w-8 h-8 rounded-full bg-gradient-to-br from-green-400 to-emerald-600 flex items-center justify-center text-white font-bold text-sm shadow-lg">
                                            {{ $index + 1 }}
                                        </div>
                                    </div>
                                @endif
                                
                                @if($product->image_url)
                                    <div class="flex-shrink-0">
                                        <img 
                                            src="{{ $product->image_url }}" 
                                            alt="{{ $product->name }}"
                                            class="w-24 h-24 object-cover rounded-lg border-2 {{ $index < 3 ? 'border-green-300' : 'border-gray-200' }}"
                                        />
                                    </div>
                                @endif
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="flex-1">
                                            <!-- Indicateur de meilleure correspondance -->
                                            @if($index === 0)
                                                <div class="inline-flex items-center px-2 py-1 rounded-full bg-green-100 text-green-800 text-xs font-semibold mb-1">
                                                    <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                                    </svg>
                                                    Meilleure correspondance
                                                </div>
                                            @endif
                                            
                                            <h3 class="font-semibold text-gray-900 text-lg">
                                                <span class="text-blue-600">{{ $product->vendor }}</span> - {{ $product->name }}
                                            </h3>
                                            <div class="mt-1 space-y-1">
                                                <p class="text-sm text-gray-600">
                                                    <span class="font-medium">Type:</span> {{ $product->type }}
                                                </p>
                                                @if($product->variation)
                                                    <p class="text-sm text-gray-600">
                                                        <span class="font-medium">Variation:</span> {{ $product->variation }}
                                                    </p>
                                                @endif
                                            </div>
                                        </div>
                                        @if($product->prix_ht)
                                            <div class="flex-shrink-0 text-right">
                                                <p class="text-lg font-bold text-blue-600">
                                                    {{ number_format($product->prix_ht, 2) }} {{ $product->currency ?? '€' }}
                                                </p>
                                                <p class="text-xs text-gray-500">HT</p>
                                            </div>
                                        @endif
                                    </div>
                                    @if($product->url)
                                        <a 
                                            href="{{ $product->url }}" 
                                            target="_blank"
                                            class="inline-flex items-center text-sm text-blue-600 hover:text-blue-800 hover:underline mt-2 font-medium"
                                        >
                                            Voir le produit 
                                            <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                            </svg>
                                        </a>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="p-4 border-t bg-gray-50">
                    {{ $this->results->links() }}
                </div>
            @else
                <div class="p-12 text-center">
                    <svg class="w-16 h-16 mx-auto text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Aucun résultat trouvé</h3>
                    <p class="text-gray-500 text-sm">
                        Aucun produit ne correspond à vos critères de recherche.
                    </p>
                    <div class="mt-4 p-4 bg-blue-50 rounded-lg text-left max-w-md mx-auto">
                        <p class="text-sm font-medium text-gray-700 mb-2">💡 Suggestions :</p>
                        <ul class="text-xs text-gray-600 space-y-1 list-disc list-inside">
                            <li>Vérifiez l'orthographe de la marque</li>
                            <li>Essayez avec seulement le nom du produit</li>
                            <li>Retirez le type si trop spécifique</li>
                            <li>Utilisez moins de mots</li>
                        </ul>
                    </div>
                </div>
            @endif
        </div>
    @endif

    <!-- Indicateur de chargement -->
    <div wire:loading class="fixed inset-0 bg-black bg-opacity-30 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 shadow-xl">
            <div class="flex items-center space-x-3">
                <svg class="animate-spin h-6 w-6 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span class="text-gray-700 font-medium">Recherche en cours...</span>
            </div>
        </div>
    </div>
</div>