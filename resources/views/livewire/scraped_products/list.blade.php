<?php

use Livewire\Volt\Component;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;

new class extends Component {
    public $vendor = '';
    public $name = '';
    public $type = '';
    public $variation = '';
    public $site_id = '';
    public $showResults = false;
    public $currentPage = 1;
    public $perPage = 50;
    
    public function applyFilter()
    {
        $this->showResults = true;
        $this->currentPage = 1; // Réinitialiser à la première page
    }
    
    public function resetFilter()
    {
        $this->vendor = '';
        $this->name = '';
        $this->type = '';
        $this->variation = '';
        $this->site_id = '';
        $this->showResults = false;
        $this->currentPage = 1;
    }
    
    public function previousPage()
    {
        if ($this->currentPage > 1) {
            $this->currentPage--;
        }
    }
    
    public function nextPage()
    {
        $this->currentPage++;
    }
    
    public function goToPage($page)
    {
        if ($page >= 1) {
            $this->currentPage = $page;
        }
    }
    
    public function with()
    {
        if (!$this->showResults) {
            return [
                'products' => collect(),
                'sites' => Site::orderBy('name')->get(),
                'paginator' => null,
            ];
        }
        
        $query = DB::table(DB::raw("(
            SELECT
                sp.*,
                ROW_NUMBER() OVER (
                    PARTITION BY sp.url, sp.vendor, sp.name, sp.type, sp.variation
                    ORDER BY sp.created_at DESC
                ) AS row_num
            FROM scraped_product sp
        ) AS t"))
            ->select('t.*')
            ->where('t.row_num', 1);
        
        // Appliquer les filtres individuellement - CORRECTION ICI
        if (!empty($this->vendor)) {
            $query->where('t.vendor', 'like', '%' . $this->vendor . '%');
        }
        
        if (!empty($this->name)) {
            $query->where('t.name', 'like', '%' . $this->name . '%');
        }
        
        if (!empty($this->type)) {
            $query->where('t.type', 'like', '%' . $this->type . '%');
        }
        
        if (!empty($this->variation)) {
            $query->where('t.variation', 'like', '%' . $this->variation . '%');
        }
        
        if (!empty($this->site_id)) {
            $query->where('t.web_site_id', $this->site_id);
        }
        
        // Compter le total des résultats
        $totalResults = $query->count();
        
        // Récupérer les résultats paginés
        $products = $query->orderBy('t.vendor', 'asc')
            ->skip(($this->currentPage - 1) * $this->perPage)
            ->take($this->perPage)
            ->get();
        
        // Créer un paginator manuel
        $paginator = new LengthAwarePaginator(
            $products,
            $totalResults,
            $this->perPage,
            $this->currentPage,
            ['path' => request()->url()]
        );
        
        return [
            'products' => $products,
            'sites' => Site::orderBy('name')->get(),
            'paginator' => $paginator,
            'totalResults' => $totalResults,
            'totalPages' => ceil($totalResults / $this->perPage),
        ];
    }
}; ?>

<div>
    <x-header title="Produits de concurent" subtitle="Tous les prix des produits sur le concurent" separator>
        <x-slot:actions>
            <x-button icon="o-plus" class="btn-primary" />
        </x-slot:actions>
    </x-header>
    
    <!-- Filtres -->
    <div class="card bg-base-100 shadow-md mb-6">
        <div class="card-body">
            <h3 class="card-title mb-4">Filtres de recherche</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                <!-- Vendeur -->
                <div>
                    <x-input 
                        wire:model="vendor"
                        label="Vendeur" 
                        placeholder="Filtrer par vendeur..."
                        icon="o-building-storefront"
                    />
                </div>
                
                <!-- Nom du produit -->
                <div>
                    <x-input 
                        wire:model="name"
                        label="Nom du produit" 
                        placeholder="Filtrer par nom..."
                        icon="o-tag"
                    />
                </div>
                
                <!-- Type -->
                <div>
                    <x-input 
                        wire:model="type"
                        label="Type" 
                        placeholder="Filtrer par type..."
                        icon="o-cube"
                    />
                </div>
                
                <!-- Variation -->
                <div>
                    <x-input 
                        wire:model="variation"
                        label="Variation" 
                        placeholder="Filtrer par variation..."
                        icon="o-arrows-pointing-out"
                    />
                </div>
                
                <!-- Site web -->
                <div>
                    <x-select 
                        wire:model="site_id"
                        label="Site web" 
                        placeholder="Tous les sites"
                        icon="o-globe-alt"
                        :options="$sites->map(function($site) {
                            return ['id' => $site->id, 'name' => $site->name];
                        })->toArray()"
                        option-value="id"
                        option-label="name"
                    />
                </div>
            </div>
            
            <!-- Boutons d'action -->
            <div class="flex justify-end gap-2 mt-6">
                @if($showResults)
                    <x-button 
                        wire:click="resetFilter" 
                        icon="o-x-mark"
                        label="Réinitialiser"
                        class="btn-ghost"
                    />
                @endif
                <x-button 
                    wire:click="applyFilter" 
                    icon="o-funnel"
                    label="Appliquer les filtres"
                    class="btn-primary"
                    spinner
                />
            </div>
        </div>
    </div>
    
    <!-- Affichage des résultats -->
    @if($showResults)
        <div class="overflow-x-auto rounded-box border border-base-content/5 bg-base-100 mb-6">
            @if($products->count() > 0)
                <table class="table">
                    <!-- head -->
                    <thead>
                        <tr class="bg-base-200">
                            <th class="font-bold">#</th>
                            <th class="font-bold">Image</th>
                            <th class="font-bold">Vendeur</th>
                            <th class="font-bold">Nom</th>
                            <th class="font-bold">Type</th>
                            <th class="font-bold">Variation</th>
                            <th class="font-bold">Prix HT</th>
                            <th class="font-bold">Site</th>
                            <th class="font-bold">Date</th>
                            <th class="font-bold">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($products as $index => $product)
                            @php
                                $rowNumber = (($currentPage - 1) * $perPage) + $index + 1;
                                $site = \App\Models\Site::find($product->web_site_id);
                            @endphp
                            <tr class="hover:bg-base-100">
                                <th class="font-normal">{{ $rowNumber }}</th>
                                <td>
                                    @if($product->image_url)
                                        <div class="avatar">
                                            <div class="mask mask-squircle w-12 h-12">
                                                <img src="{{ $product->image_url }}" 
                                                     alt="{{ $product->name }}"
                                                     onerror="this.src='https://via.placeholder.com/50x50?text=No+Image'">
                                            </div>
                                        </div>
                                    @else
                                        <div class="text-gray-400">
                                            <x-icon name="o-photo" class="w-6 h-6" />
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <div class="font-medium">{{ $product->vendor }}</div>
                                </td>
                                <td>
                                    <div class="font-medium">{{ $product->name }}</div>
                                </td>
                                <td>
                                    <div class="badge badge-outline">{{ $product->type ?? 'N/A' }}</div>
                                </td>
                                <td>
                                    <div class="badge badge-ghost">{{ $product->variation ?? 'N/A' }}</div>
                                </td>
                                <td>
                                    <div class="font-bold text-primary">
                                        {{ $product->prix_ht }} {{ $product->currency }}
                                    </div>
                                </td>
                                <td>
                                    @if($site)
                                        <div class="flex items-center gap-2">
                                            <x-icon name="o-globe-alt" class="w-4 h-4" />
                                            <span>{{ $site->name }}</span>
                                        </div>
                                    @else
                                        <span class="text-gray-400">N/A</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="text-sm text-gray-500">
                                        {{ \Carbon\Carbon::parse($product->created_at)->format('d/m/Y') }}
                                    </div>
                                    <div class="text-xs text-gray-400">
                                        {{ \Carbon\Carbon::parse($product->created_at)->format('H:i') }}
                                    </div>
                                </td>
                                <td>
                                    <div class="flex gap-2">
                                        @if($product->url)
                                            <a href="{{ $product->url }}" 
                                               target="_blank" 
                                               class="btn btn-sm btn-outline btn-square"
                                               title="Voir sur le site">
                                                <x-icon name="o-arrow-top-right-on-square" class="w-4 h-4" />
                                            </a>
                                        @endif
                                        <button class="btn btn-sm btn-ghost btn-square" title="Voir détails">
                                            <x-icon name="o-eye" class="w-4 h-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="p-8 text-center">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-warning/10 mb-4">
                        <x-icon name="o-magnifying-glass" class="w-8 h-8 text-warning" />
                    </div>
                    <h3 class="text-lg font-semibold mb-2">Aucun produit trouvé</h3>
                    <p class="text-gray-600 mb-4">
                        Aucun produit ne correspond à vos critères de filtrage.
                    </p>
                    <x-button 
                        wire:click="resetFilter" 
                        icon="o-arrow-path"
                        label="Réinitialiser les filtres"
                        class="btn-outline"
                    />
                </div>
            @endif
        </div>
        
        <!-- Pagination -->
        @if($products->count() > 0 && $paginator)
            <div class="card bg-base-100 shadow-md">
                <div class="card-body">
                    <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                        <!-- Informations sur les résultats -->
                        <div class="text-sm text-gray-600">
                            Affichage de 
                            <span class="font-semibold">{{ (($currentPage - 1) * $perPage) + 1 }}</span>
                            à 
                            <span class="font-semibold">{{ min($currentPage * $perPage, $totalResults) }}</span>
                            sur 
                            <span class="font-semibold">{{ $totalResults }}</span>
                            produit(s) - Page 
                            <span class="font-semibold">{{ $currentPage }}</span>
                            sur 
                            <span class="font-semibold">{{ $totalPages }}</span>
                        </div>
                        
                        <!-- Contrôles de pagination -->
                        <div class="join">
                            <!-- Bouton Précédent -->
                            <button 
                                wire:click="previousPage" 
                                class="join-item btn {{ $currentPage <= 1 ? 'btn-disabled' : '' }}"
                                {{ $currentPage <= 1 ? 'disabled' : '' }}
                            >
                                <x-icon name="o-chevron-left" class="w-4 h-4" />
                            </button>
                            
                            <!-- Pages -->
                            @php
                                $startPage = max(1, $currentPage - 2);
                                $endPage = min($totalPages, $currentPage + 2);
                            @endphp
                            
                            @if($startPage > 1)
                                <button 
                                    wire:click="goToPage(1)" 
                                    class="join-item btn {{ $currentPage == 1 ? 'btn-active' : '' }}"
                                >
                                    1
                                </button>
                                @if($startPage > 2)
                                    <button class="join-item btn btn-disabled" disabled>...</button>
                                @endif
                            @endif
                            
                            @for($page = $startPage; $page <= $endPage; $page++)
                                <button 
                                    wire:click="goToPage({{ $page }})" 
                                    class="join-item btn {{ $currentPage == $page ? 'btn-active' : '' }}"
                                >
                                    {{ $page }}
                                </button>
                            @endfor
                            
                            @if($endPage < $totalPages)
                                @if($endPage < $totalPages - 1)
                                    <button class="join-item btn btn-disabled" disabled>...</button>
                                @endif
                                <button 
                                    wire:click="goToPage({{ $totalPages }})" 
                                    class="join-item btn {{ $currentPage == $totalPages ? 'btn-active' : '' }}"
                                >
                                    {{ $totalPages }}
                                </button>
                            @endif
                            
                            <!-- Bouton Suivant -->
                            <button 
                                wire:click="nextPage" 
                                class="join-item btn {{ $currentPage >= $totalPages ? 'btn-disabled' : '' }}"
                                {{ $currentPage >= $totalPages ? 'disabled' : '' }}
                            >
                                <x-icon name="o-chevron-right" class="w-4 h-4" />
                            </button>
                        </div>
                        
                        <!-- Sélection du nombre de résultats par page -->
                        <div class="flex items-center gap-2">
                            <span class="text-sm text-gray-600">Résultats par page:</span>
                            <select class="select select-bordered select-sm" wire:model.live="perPage">
                                <option value="20">20</option>
                                <option value="50" selected>50</option>
                                <option value="100">100</option>
                                <option value="200">200</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @else
        <div class="card bg-base-100 shadow-md">
            <div class="card-body text-center py-12">
                <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-primary/10 mb-4">
                    <x-icon name="o-funnel" class="w-10 h-10 text-primary" />
                </div>
                <h3 class="text-xl font-semibold mb-2">Aucun filtre appliqué</h3>
                <p class="text-gray-600 max-w-md mx-auto mb-6">
                    Remplissez les champs de filtrage et cliquez sur "Appliquer les filtres" pour voir les résultats.
                    <br>
                    <span class="text-sm">Seuls les produits les plus récents pour chaque combinaison unique sont affichés.</span>
                </p>
                <div class="stats shadow">
                    <div class="stat">
                        <div class="stat-figure text-primary">
                            <x-icon name="o-cube" class="w-8 h-8" />
                        </div>
                        <div class="stat-title">Produits uniques</div>
                        <div class="stat-value text-primary">?</div>
                        <div class="stat-desc">Cliquez pour voir</div>
                    </div>
                    
                    <div class="stat">
                        <div class="stat-figure text-secondary">
                            <x-icon name="o-building-storefront" class="w-8 h-8" />
                        </div>
                        <div class="stat-title">Vendeurs</div>
                        <div class="stat-value text-secondary">?</div>
                        <div class="stat-desc">En attente de filtre</div>
                    </div>
                    
                    <div class="stat">
                        <div class="stat-figure text-accent">
                            <x-icon name="o-globe-alt" class="w-8 h-8" />
                        </div>
                        <div class="stat-title">Sites web</div>
                        <div class="stat-value text-accent">{{ \App\Models\Site::count() }}</div>
                        <div class="stat-desc">Sites disponibles</div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>