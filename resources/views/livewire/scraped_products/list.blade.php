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
    <!-- Styles inline pour éviter les problèmes d'éléments multiples -->
    <style>
        /* Styles Excel-like supplémentaires */
        .excel-table {
            border-spacing: 0;
        }
        
        .excel-table th, .excel-table td {
            border-right: 1px solid #e5e7eb;
            border-bottom: 1px solid #e5e7eb;
            padding: 8px 12px;
        }
        
        .excel-table th:last-child, .excel-table td:last-child {
            border-right: none;
        }
        
        .excel-table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .excel-table tbody tr:hover {
            background-color: #f9fafb;
        }
        
        /* Amélioration du truncate */
        .excel-truncate {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        /* Style pour les cellules avec badges */
        .excel-badge-cell {
            max-width: 200px;
        }
    </style>
    
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
        <div class="overflow-x-auto rounded-none border border-gray-300 bg-white shadow-sm mb-6">
            @if($products->count() > 0)
                <table class="excel-table table-auto border-collapse w-full">
                    <!-- head -->
                    <thead>
                        <tr class="bg-gray-100 border-b border-gray-300">
                            <th class="font-bold text-gray-700 text-sm px-3 py-2 border-r border-gray-300 text-center w-12">#</th>
                            <th class="font-bold text-gray-700 text-sm px-3 py-2 border-r border-gray-300 text-center w-16">Image</th>
                            <th class="font-bold text-gray-700 text-sm px-3 py-2 border-r border-gray-300 min-w-32">Vendeur</th>
                            <th class="font-bold text-gray-700 text-sm px-3 py-2 border-r border-gray-300 min-w-48">Nom</th>
                            <th class="font-bold text-gray-700 text-sm px-3 py-2 border-r border-gray-300 min-w-32 max-w-48 excel-truncate">Type</th>
                            <th class="font-bold text-gray-700 text-sm px-3 py-2 border-r border-gray-300 min-w-32 max-w-48 excel-truncate">Variation</th>
                            <th class="font-bold text-gray-700 text-sm px-3 py-2 border-r border-gray-300 text-center w-32">Prix HT</th>
                            <th class="font-bold text-gray-700 text-sm px-3 py-2 border-r border-gray-300 min-w-32">Site</th>
                            <th class="font-bold text-gray-700 text-sm px-3 py-2 border-r border-gray-300 text-center w-32">Date</th>
                            <th class="font-bold text-gray-700 text-sm px-3 py-2 text-center w-24">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($products as $index => $product)
                            @php
                                $rowNumber = (($currentPage - 1) * $perPage) + $index + 1;
                                $site = \App\Models\Site::find($product->web_site_id);
                            @endphp
                            <tr class="border-b border-gray-200 hover:bg-gray-50 even:bg-gray-50/50">
                                <td class="text-gray-600 text-sm px-3 py-2 border-r border-gray-300 text-center font-mono">{{ $rowNumber }}</td>
                                <td class="px-3 py-2 border-r border-gray-300 text-center">
                                    @if($product->image_url)
                                        <div class="avatar mx-auto">
                                            <div class="mask mask-squircle w-10 h-10">
                                                <img src="{{ $product->image_url }}" 
                                                     alt="{{ $product->name }}"
                                                     class="object-cover"
                                                     onerror="this.src='https://via.placeholder.com/40x40?text=No+Image'">
                                            </div>
                                        </div>
                                    @else
                                        <div class="text-gray-300 mx-auto">
                                            <x-icon name="o-photo" class="w-5 h-5" />
                                        </div>
                                    @endif
                                </td>
                                <td class="text-gray-800 text-sm px-3 py-2 border-r border-gray-300 excel-truncate max-w-32" title="{{ $product->vendor }}">
                                    {{ $product->vendor }}
                                </td>
                                <td class="text-gray-800 text-sm px-3 py-2 border-r border-gray-300 excel-truncate max-w-48" title="{{ $product->name }}">
                                    {{ $product->name }}
                                </td>
                                <td class="text-gray-800 text-sm px-3 py-2 border-r border-gray-300 excel-truncate max-w-48" title="{{ $product->type ?? 'N/A' }}">
                                    <div class="inline-flex items-center px-2 py-1 rounded bg-blue-50 text-blue-700 border border-blue-200 text-xs">
                                        {{ $product->type ?? 'N/A' }}
                                    </div>
                                </td>
                                <td class="text-gray-800 text-sm px-3 py-2 border-r border-gray-300 excel-truncate max-w-48" title="{{ $product->variation ?? 'N/A' }}">
                                    <div class="inline-flex items-center px-2 py-1 rounded bg-gray-50 text-gray-700 border border-gray-200 text-xs">
                                        {{ $product->variation ?? 'N/A' }}
                                    </div>
                                </td>
                                <td class="text-gray-800 text-sm px-3 py-2 border-r border-gray-300 text-center font-semibold text-green-600">
                                    {{ $product->prix_ht }} {{ $product->currency }}
                                </td>
                                <td class="text-gray-800 text-sm px-3 py-2 border-r border-gray-300">
                                    @if($site)
                                        <div class="flex items-center gap-1 excel-truncate max-w-32" title="{{ $site->name }}">
                                            <x-icon name="o-globe-alt" class="w-3 h-3 text-gray-400 flex-shrink-0" />
                                            <span class="excel-truncate">{{ $site->name }}</span>
                                        </div>
                                    @else
                                        <span class="text-gray-400 italic">N/A</span>
                                    @endif
                                </td>
                                <td class="text-gray-600 text-sm px-3 py-2 border-r border-gray-300 text-center">
                                    <div class="whitespace-nowrap">
                                        {{ \Carbon\Carbon::parse($product->created_at)->format('d/m/Y') }}
                                    </div>
                                    <div class="text-xs text-gray-400">
                                        {{ \Carbon\Carbon::parse($product->created_at)->format('H:i') }}
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <div class="flex justify-center gap-1">
                                        @if($product->url)
                                            <a href="{{ $product->url }}" 
                                               target="_blank" 
                                               class="btn btn-xs btn-outline btn-square border-gray-300 hover:bg-blue-50 hover:border-blue-300"
                                               title="Voir sur le site">
                                                <x-icon name="o-arrow-top-right-on-square" class="w-3 h-3" />
                                            </a>
                                        @endif
                                        <button class="btn btn-xs btn-ghost btn-square border border-gray-300 hover:bg-gray-100" title="Voir détails">
                                            <x-icon name="o-eye" class="w-3 h-3" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="p-8 text-center">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-yellow-50 border border-yellow-200 mb-4">
                        <x-icon name="o-magnifying-glass" class="w-8 h-8 text-yellow-600" />
                    </div>
                    <h3 class="text-lg font-semibold mb-2">Aucun produit trouvé</h3>
                    <p class="text-gray-600 mb-4">
                        Aucun produit ne correspond à vos critères de filtrage.
                    </p>
                    <x-button 
                        wire:click="resetFilter" 
                        icon="o-arrow-path"
                        label="Réinitialiser les filtres"
                        class="btn-outline border-gray-300"
                    />
                </div>
            @endif
        </div>
        
        <!-- Pagination -->
        @if($products->count() > 0 && $paginator)
            <div class="card bg-white border border-gray-300 shadow-sm">
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
                        <div class="join border border-gray-300 rounded">
                            <!-- Bouton Précédent -->
                            <button 
                                wire:click="previousPage" 
                                class="join-item btn btn-sm bg-white border-gray-300 hover:bg-gray-50 {{ $currentPage <= 1 ? 'btn-disabled opacity-50' : '' }}"
                                {{ $currentPage <= 1 ? 'disabled' : '' }}
                            >
                                <x-icon name="o-chevron-left" class="w-3 h-3" />
                            </button>
                            
                            <!-- Pages -->
                            @php
                                $startPage = max(1, $currentPage - 2);
                                $endPage = min($totalPages, $currentPage + 2);
                            @endphp
                            
                            @if($startPage > 1)
                                <button 
                                    wire:click="goToPage(1)" 
                                    class="join-item btn btn-sm bg-white border-gray-300 hover:bg-gray-50 {{ $currentPage == 1 ? 'bg-blue-50 text-blue-600 border-blue-300' : '' }}"
                                >
                                    1
                                </button>
                                @if($startPage > 2)
                                    <button class="join-item btn btn-sm bg-white border-gray-300 btn-disabled" disabled>...</button>
                                @endif
                            @endif
                            
                            @for($page = $startPage; $page <= $endPage; $page++)
                                <button 
                                    wire:click="goToPage({{ $page }})" 
                                    class="join-item btn btn-sm bg-white border-gray-300 hover:bg-gray-50 {{ $currentPage == $page ? 'bg-blue-50 text-blue-600 border-blue-300' : '' }}"
                                >
                                    {{ $page }}
                                </button>
                            @endfor
                            
                            @if($endPage < $totalPages)
                                @if($endPage < $totalPages - 1)
                                    <button class="join-item btn btn-sm bg-white border-gray-300 btn-disabled" disabled>...</button>
                                @endif
                                <button 
                                    wire:click="goToPage({{ $totalPages }})" 
                                    class="join-item btn btn-sm bg-white border-gray-300 hover:bg-gray-50 {{ $currentPage == $totalPages ? 'bg-blue-50 text-blue-600 border-blue-300' : '' }}"
                                >
                                    {{ $totalPages }}
                                </button>
                            @endif
                            
                            <!-- Bouton Suivant -->
                            <button 
                                wire:click="nextPage" 
                                class="join-item btn btn-sm bg-white border-gray-300 hover:bg-gray-50 {{ $currentPage >= $totalPages ? 'btn-disabled opacity-50' : '' }}"
                                {{ $currentPage >= $totalPages ? 'disabled' : '' }}
                            >
                                <x-icon name="o-chevron-right" class="w-3 h-3" />
                            </button>
                        </div>
                        
                        <!-- Sélection du nombre de résultats par page -->
                        <div class="flex items-center gap-2">
                            <span class="text-sm text-gray-600">Résultats par page:</span>
                            <select class="select select-bordered select-sm border-gray-300 bg-white" wire:model.live="perPage">
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
        <div class="card bg-white border border-gray-300 shadow-sm">
            <div class="card-body text-center py-12">
                <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-blue-50 border border-blue-200 mb-4">
                    <x-icon name="o-funnel" class="w-10 h-10 text-blue-600" />
                </div>
                <h3 class="text-xl font-semibold mb-2 text-gray-800">Aucun filtre appliqué</h3>
                <p class="text-gray-600 max-w-md mx-auto mb-6">
                    Remplissez les champs de filtrage et cliquez sur "Appliquer les filtres" pour voir les résultats.
                    <br>
                    <span class="text-sm text-gray-500">Seuls les produits les plus récents pour chaque combinaison unique sont affichés.</span>
                </p>
                <div class="stats shadow border border-gray-300">
                    <div class="stat border-r border-gray-300">
                        <div class="stat-figure text-blue-600">
                            <x-icon name="o-cube" class="w-8 h-8" />
                        </div>
                        <div class="stat-title text-gray-600">Produits uniques</div>
                        <div class="stat-value text-blue-600 text-2xl">?</div>
                        <div class="stat-desc text-gray-500">Cliquez pour voir</div>
                    </div>
                    
                    <div class="stat border-r border-gray-300">
                        <div class="stat-figure text-gray-600">
                            <x-icon name="o-building-storefront" class="w-8 h-8" />
                        </div>
                        <div class="stat-title text-gray-600">Vendeurs</div>
                        <div class="stat-value text-gray-700 text-2xl">?</div>
                        <div class="stat-desc text-gray-500">En attente de filtre</div>
                    </div>
                    
                    <div class="stat">
                        <div class="stat-figure text-green-600">
                            <x-icon name="o-globe-alt" class="w-8 h-8" />
                        </div>
                        <div class="stat-title text-gray-600">Sites web</div>
                        <div class="stat-value text-green-600 text-2xl">{{ \App\Models\Site::count() }}</div>
                        <div class="stat-desc text-gray-500">Sites disponibles</div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>