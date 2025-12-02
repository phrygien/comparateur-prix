<div>
    <x-header title="Nos produits" subtitle="Les produits de notre boutique à comparer" no-separator>
        <x-slot:middle class="!justify-end">
            <x-input 
                icon="o-bolt" 
                placeholder="Rechercher..." 
                wire:model.live.debounce.500ms="search"
            />
        </x-slot:middle>
        <x-slot:actions>
            <div class="flex items-center gap-4">
                <!-- Sélecteur d'éléments par page -->
                <div class="form-control">
                    <select wire:model.live="perPage" class="select select-bordered select-sm">
                        <option value="12">12 par page</option>
                        <option value="24">24 par page</option>
                        <option value="48">48 par page</option>
                        <option value="96">96 par page</option>
                        <option value="108">108 par page</option>
                        <option value="120">120 par page</option>
                    </select>
                </div>

                <!-- Bouton filtre avancé -->
                {{-- <div class="drawer drawer-end">
                    <input id="my-drawer-1" type="checkbox" class="drawer-toggle" />
                    <div class="drawer-content">
                        <label for="my-drawer-1" class="btn drawer-button btn-primary">Filtre avancé</label>
                    </div>
                    <div class="drawer-side z-50">
                        <label for="my-drawer-1" aria-label="close sidebar" class="drawer-overlay"></label>
                        <div class="bg-base-200 min-h-full w-[500px] p-8">
                            <h2 class="text-2xl font-bold mb-8">Filtres</h2>

                            <div class="space-y-6">
                                <!-- Filtre par nom -->
                                <div class="form-control">
                                    <label class="label">
                                        <span class="label-text font-semibold">Nom du produit</span>
                                    </label>
                                    <input 
                                        type="text" 
                                        wire:model="filterName"
                                        placeholder="Rechercher un parfum..."
                                        class="input input-bordered w-full" 
                                    />
                                </div>

                                <!-- Filtre par marque -->
                                <div class="form-control">
                                    <label class="label">
                                        <span class="label-text font-semibold">Marque</span>
                                    </label>
                                    <select wire:model="filterMarque" class="select select-bordered w-full">
                                        <option value="">Toutes les marques</option>
                                        <option value="Chanel">Chanel</option>
                                        <option value="Dior">Dior</option>
                                        <option value="Gucci">Gucci</option>
                                        <option value="Hermes">Hermès</option>
                                        <option value="Yves Saint Laurent">Yves Saint Laurent</option>
                                        <option value="Prada">Prada</option>
                                        <option value="Versace">Versace</option>
                                        <option value="Armani">Armani</option>
                                    </select>
                                </div>

                                <!-- Filtre par type de parfum -->
                                <div class="form-control">
                                    <label class="label">
                                        <span class="label-text font-semibold">Type de parfum</span>
                                    </label>
                                    <select wire:model="filterType" class="select select-bordered w-full">
                                        <option value="">Tous les types</option>
                                        <option value="eau_de_parfum">Eau de Parfum</option>
                                        <option value="eau_de_toilette">Eau de Toilette</option>
                                        <option value="eau_de_cologne">Eau de Cologne</option>
                                        <option value="parfum">Parfum</option>
                                    </select>
                                </div>

                                <!-- Filtre par capacité -->
                                <div class="form-control">
                                    <label class="label">
                                        <span class="label-text font-semibold">Capacité (ML)</span>
                                    </label>
                                    <select wire:model="filterCapacity" class="select select-bordered w-full">
                                        <option value="">Toutes les capacités</option>
                                        <option value="30">30 ML</option>
                                        <option value="50">50 ML</option>
                                        <option value="75">75 ML</option>
                                        <option value="100">100 ML</option>
                                        <option value="150">150 ML</option>
                                    </select>
                                </div>

                                <!-- Boutons d'action -->
                                <div class="flex gap-2">
                                    <button 
                                        type="button" 
                                        wire:click="applyFilters"
                                        class="btn btn-primary flex-1"
                                    >
                                        Appliquer
                                    </button>
                                    <button 
                                        type="button" 
                                        wire:click="resetFilters"
                                        class="btn btn-ghost flex-1"
                                    >
                                        Réinitialiser
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div> --}}
            </div>
        </x-slot:actions>
    </x-header>

    <!-- Résultats et compteur -->
    <div class="mb-4 flex justify-between items-center">
        <p class="text-sm text-gray-600">
            Affichage de {{ count($products) }} produit(s) sur {{ $totalItems }} au total
            @if($search)
                <span class="ml-2 badge badge-primary">Recherche: "{{ $search }}"</span>
            @endif
        </p>
        
        <!-- Info éléments par page -->
        <p class="text-sm text-gray-500">
            {{ $perPage }} éléments par page
        </p>
    </div>

    <!-- Grille de produits -->
    <div class="mx-auto overflow-hidden">
        @if(count($products) > 0)
            <div class="grid grid-cols-1 gap-x-6 gap-y-10 sm:grid-cols-2 lg:grid-cols-4 lg:gap-x-8">
                @foreach($products as $product)
                <a href="{{ route('article.comparate-prix', [
    utf8_encode($product->title), 
    $product->id, 
    $product->special_price ?? $product->price ?? 0
]) }}" class="group text-sm">
                        <div class="aspect-square w-full rounded-lg bg-gray-100 overflow-hidden">
                            @if($product->thumbnail)
                                <img 
                                    src="{{ asset('https://www.cosma-parfumeries.com/media/catalog/product/' . $product->thumbnail) }}"
                                    alt="{{ $product->title }}"
                                    class="w-full h-full object-cover group-hover:opacity-75 transition-opacity"
                                    onerror="this.src='https://images.unsplash.com/photo-1541643600914-78b084683601?w=800&q=80'"
                                >
                            @else
                                <img 
                                    src="https://images.unsplash.com/photo-1541643600914-78b084683601?w=800&q=80"
                                    alt="Parfum"
                                    class="w-full h-full object-cover group-hover:opacity-75 transition-opacity"
                                >
                            @endif
                        </div>
                        
                        <h3 class="mt-4 font-medium text-gray-900 line-clamp-2">
                            {{ utf8_encode($product->title) }}
                        </h3>
                        
                        @if($product->vendor)
                            <p class="text-gray-500 italic">{{ utf8_encode($product->vendor) }}</p>
                        @endif
                        
                        <div class="mt-2">
                            @if($product->special_price && $product->special_price < $product->price)
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-red-600">{{ number_format($product->special_price, 2) }} €</span>
                                    <span class="text-sm text-gray-500 line-through">{{ number_format($product->price, 2) }} €</span>
                                </div>
                            @else
                                <p class="font-medium text-gray-900">{{ number_format($product->price, 2) }} €</p>
                            @endif
                        </div>

                        @if($product->quatity_status == 1)
                            <span class="mt-1 inline-block text-xs text-green-600">En stock</span>
                        @else
                            <span class="mt-1 inline-block text-xs text-red-600">Rupture de stock</span>
                        @endif
                    </a>
                @endforeach
            </div>
        @else
            <div class="text-center py-12">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <h3 class="mt-2 text-sm font-medium text-gray-900">Aucun produit trouvé</h3>
                <p class="mt-1 text-sm text-gray-500">Essayez de modifier vos critères de recherche.</p>
                @if($search || $filterName || $filterMarque || $filterType || $filterCapacity)
                    <div class="mt-6">
                        <button 
                            wire:click="resetFilters" 
                            type="button" 
                            class="btn btn-primary"
                        >
                            Réinitialiser les filtres
                        </button>
                    </div>
                @endif
            </div>
        @endif

        <!-- Skeleton loader pour les produits -->
        <div wire:loading.flex class="grid grid-cols-1 gap-x-6 gap-y-10 sm:grid-cols-2 lg:grid-cols-4 lg:gap-x-8">
            @for($i = 0; $i < 8; $i++)
                <div class="group text-sm animate-pulse">
                    <!-- Image skeleton avec ombre -->
                    <div class="aspect-square w-full rounded-lg bg-gray-300 overflow-hidden shadow-md"></div>
                    
                    <!-- Titre skeleton -->
                    <div class="mt-4 h-4 bg-gray-300 rounded w-3/4 shadow-sm"></div>
                    
                    <!-- Marque skeleton -->
                    <div class="mt-2 h-3 bg-gray-200 rounded w-1/2 shadow-sm"></div>
                    
                    <!-- Prix skeleton -->
                    <div class="mt-2 h-4 bg-gray-300 rounded w-1/3 shadow-sm"></div>
                    
                    <!-- Stock skeleton -->
                    <div class="mt-1 h-3 bg-gray-200 rounded w-1/4 shadow-sm"></div>
                </div>
            @endfor
        </div>
    </div>

    <!-- Pagination -->
    @if($totalPages > 1)
        <div class="mt-8 flex justify-between items-center">
            <!-- Info pagination -->
            <div class="text-sm text-gray-600">
                Page {{ $currentPage }} sur {{ $totalPages }} - 
                {{ $totalItems }} produit(s) au total
            </div>

            <!-- Contrôles de pagination -->
            <div class="join">
                <!-- Bouton Précédent -->
                <button 
                    wire:click="previousPage"
                    class="join-item btn"
                    @if($currentPage == 1) disabled @endif
                >
                    «
                </button>

                <!-- Première page -->
                @if($currentPage > 3)
                    <button 
                        wire:click="goToPage(1)"
                        class="join-item btn"
                    >
                        1
                    </button>
                    @if($currentPage > 4)
                        <button class="join-item btn btn-disabled" disabled>...</button>
                    @endif
                @endif

                <!-- Pages autour de la page courante -->
                @for($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++)
                    <button 
                        wire:click="goToPage({{ $i }})"
                        class="join-item btn {{ $currentPage == $i ? 'btn-active' : '' }}"
                    >
                        {{ $i }}
                    </button>
                @endfor

                <!-- Dernière page -->
                @if($currentPage < $totalPages - 2)
                    @if($currentPage < $totalPages - 3)
                        <button class="join-item btn btn-disabled" disabled>...</button>
                    @endif
                    <button 
                        wire:click="goToPage({{ $totalPages }})"
                        class="join-item btn"
                    >
                        {{ $totalPages }}
                    </button>
                @endif

                <!-- Bouton Suivant -->
                <button 
                    wire:click="nextPage"
                    class="join-item btn"
                    @if($currentPage == $totalPages) disabled @endif
                >
                    »
                </button>
            </div>
        </div>
    @endif

    <!-- Loading indicator avec texte et ombre -->
    <div wire:loading.class.remove="hidden" class="hidden fixed inset-0 z-50 flex items-center justify-center">
        <div class="flex flex-col items-center justify-center bg-white/90 rounded-2xl p-8 shadow-2xl border border-white/20 min-w-[200px]">
            <!-- Spinner -->
            <div class="loading loading-spinner loading-lg text-primary mb-4"></div>
            
            <!-- Texte de chargement -->
            <p class="text-lg font-semibold text-gray-800">Chargement</p>
            <p class="text-sm text-gray-600 mt-1">Veuillez patienter...</p>
        </div>
    </div>
</div>