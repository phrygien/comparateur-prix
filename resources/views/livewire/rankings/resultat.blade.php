<?php

use Livewire\Volt\Component;
use App\Models\TopProduct;
use App\Models\Product;
use App\Models\Site;
use App\Models\HistoImportTopFile;
use Illuminate\Support\Facades\DB;

new class extends Component {
    public $histoId;
    public $perPage = 20;
    public $currentPage = 1;
    public $totalPages = 0;

    public function mount($id)
    {
        $this->histoId = $id;
    }

    public function with(): array
    {
        // Récupérer les informations de l'import
        $import = HistoImportTopFile::find($this->histoId);
        
        if (!$import) {
            return [
                'import' => null,
                'comparisons' => collect([]),
                'sites' => collect([]),
                'totalPages' => 0,
            ];
        }

        // Récupérer uniquement les sites spécifiques
        $sites = Site::whereIn('id', [1, 2, 8, 16])
            ->orderBy('name')
            ->get();

        // Compter le total de produits
        $totalProducts = TopProduct::where('histo_import_top_file_id', $this->histoId)
            ->whereNotNull('ean')
            ->where('ean', '!=', '')
            ->count();

        $this->totalPages = (int) ceil($totalProducts / $this->perPage);

        // Récupérer les top produits avec pagination
        $topProducts = TopProduct::where('histo_import_top_file_id', $this->histoId)
            ->whereNotNull('ean')
            ->where('ean', '!=', '')
            ->orderBy('rank_qty')
            ->skip(($this->currentPage - 1) * $this->perPage)
            ->take($this->perPage)
            ->get();

        $comparisons = $topProducts->map(function ($topProduct) use ($sites) {
            // Rechercher les produits scrapés correspondants par EAN UNIQUEMENT pour les sites sélectionnés
            $scrapedProducts = Product::where('ean', $topProduct->ean)
                ->whereIn('web_site_id', [1, 2, 8, 16])
                ->with('website')
                ->get()
                ->keyBy('web_site_id');

            // Créer un tableau avec les données du top produit
            $comparison = [
                'rank_qty' => $topProduct->rank_qty,
                'rank_ca' => $topProduct->rank_chriffre_affaire,
                'ean' => $topProduct->ean,
                'designation' => $topProduct->designation,
                'marque' => $topProduct->marque,
                'prix_cosma' => $topProduct->prix_vente_cosma,
                'pght' => $topProduct->pght,
                'pamp' => $topProduct->pamp,
                'sites' => [],
            ];

            // Pour chaque site, ajouter le prix ou null
            foreach ($sites as $site) {
                if (isset($scrapedProducts[$site->id])) {
                    $scrapedProduct = $scrapedProducts[$site->id];
                    $comparison['sites'][$site->id] = [
                        'prix_ht' => $scrapedProduct->prix_ht,
                        'url' => $scrapedProduct->url,
                        'name' => $scrapedProduct->name,
                        'vendor' => $scrapedProduct->vendor,
                        'diff' => $topProduct->prix_vente_cosma 
                            ? round((($scrapedProduct->prix_ht - $topProduct->prix_vente_cosma) / $topProduct->prix_vente_cosma) * 100, 2)
                            : null,
                    ];
                } else {
                    $comparison['sites'][$site->id] = null;
                }
            }

            return $comparison;
        });

        return [
            'import' => $import,
            'comparisons' => $comparisons,
            'sites' => $sites,
            'totalPages' => $this->totalPages,
            'totalProducts' => $totalProducts,
        ];
    }

    public function goToPage($page)
    {
        if ($page >= 1 && $page <= $this->totalPages) {
            $this->currentPage = $page;
        }
    }

    public function previousPage()
    {
        if ($this->currentPage > 1) {
            $this->currentPage--;
        }
    }

    public function nextPage()
    {
        if ($this->currentPage < $this->totalPages) {
            $this->currentPage++;
        }
    }

    public function getPaginationButtons()
    {
        $buttons = [];
        $current = $this->currentPage;
        $total = $this->totalPages;

        if ($total <= 7) {
            // Afficher toutes les pages si <= 7
            for ($i = 1; $i <= $total; $i++) {
                $buttons[] = ['type' => 'page', 'value' => $i];
            }
        } else {
            // Toujours afficher la première page
            $buttons[] = ['type' => 'page', 'value' => 1];

            if ($current > 3) {
                $buttons[] = ['type' => 'dots'];
            }

            // Pages autour de la page courante
            $start = max(2, $current - 1);
            $end = min($total - 1, $current + 1);

            for ($i = $start; $i <= $end; $i++) {
                $buttons[] = ['type' => 'page', 'value' => $i];
            }

            if ($current < $total - 2) {
                $buttons[] = ['type' => 'dots'];
            }

            // Toujours afficher la dernière page
            $buttons[] = ['type' => 'page', 'value' => $total];
        }

        return $buttons;
    }

    public function exportResults()
    {
        // TODO: Implémenter l'export Excel/CSV
    }
}; ?>

<div class="w-full">
    <x-header title="Ranking" subtitle="Comparaison des prix des produits classés" separator>
        <x-slot:middle class="!justify-end">
            @if($import)
                <div class="text-sm">
                    <span class="font-semibold">Import:</span> {{ $import->nom_fichier }}
                    <span class="text-gray-500 ml-2">{{ $import->created_at->format('d/m/Y H:i') }}</span>
                </div>
            @endif
        </x-slot:middle>
        <x-slot:actions>
            <x-button
                icon="o-arrow-down-tray"
                label="Exporter résultat"
                class="btn-primary"
                wire:click="exportResults"
            />
        </x-slot:actions>
    </x-header>

    @if(!$import)
        <x-card class="mt-4">
            <div class="text-center py-8 text-error">
                Import non trouvé
            </div>
        </x-card>
    @elseif($comparisons->isEmpty())
        <x-card class="mt-4">
            <div class="text-center py-8 text-gray-500">
                Aucun produit trouvé pour cet import
            </div>
        </x-card>
    @else
        <div class="mt-4">
            <div class="flex justify-between items-center mb-4">
                <div class="text-sm text-gray-600">
                    {{ $totalProducts }} produit(s) trouvé(s) - 
                    Page {{ $currentPage }} sur {{ $totalPages }}
                </div>
                
                <div class="join">
                    <button 
                        class="join-item btn btn-sm"
                        wire:click="previousPage"
                        @if($currentPage === 1) disabled @endif
                    >
                        «
                    </button>
                    
                    @foreach($this->getPaginationButtons() as $button)
                        @if($button['type'] === 'page')
                            <button 
                                class="join-item btn btn-sm {{ $button['value'] === $currentPage ? 'btn-active' : '' }}"
                                wire:click="goToPage({{ $button['value'] }})"
                            >
                                {{ $button['value'] }}
                            </button>
                        @else
                            <button class="join-item btn btn-sm btn-disabled">...</button>
                        @endif
                    @endforeach
                    
                    <button 
                        class="join-item btn btn-sm"
                        wire:click="nextPage"
                        @if($currentPage === $totalPages) disabled @endif
                    >
                        »
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="table table-xs table-pin-rows table-pin-cols">
                    <thead>
                        <tr>
                            <th class="bg-base-200">Rang Qty</th>
                            <th class="bg-base-200">Rang CA</th>
                            <th class="bg-base-200">EAN</th>
                            <th class="bg-base-200">Désignation</th>
                            <th class="bg-base-200">Marque</th>
                            <th class="bg-base-200 text-right">Prix Cosma</th>
                            <th class="bg-base-200 text-right">PGHT</th>
                            <th class="bg-base-200 text-right">PAMP</th>
                            @foreach($sites as $site)
                                <th class="bg-base-200 text-right">{{ $site->name }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($comparisons as $comparison)
                            <tr class="hover">
                                <td class="font-semibold">{{ $comparison['rank_qty'] }}</td>
                                <td>{{ $comparison['rank_ca'] }}</td>
                                <td>
                                    <span class="font-mono text-xs">{{ $comparison['ean'] }}</span>
                                </td>
                                <td>
                                    <div class="max-w-xs truncate" title="{{ $comparison['designation'] }}">
                                        {{ $comparison['designation'] }}
                                    </div>
                                </td>
                                <td>{{ $comparison['marque'] }}</td>
                                <td class="text-right font-semibold text-primary">
                                    {{ number_format($comparison['prix_cosma'], 2) }} €
                                </td>
                                <td class="text-right text-xs">
                                    {{ number_format($comparison['pght'], 2) }} €
                                </td>
                                <td class="text-right text-xs">
                                    {{ number_format($comparison['pamp'], 2) }} €
                                </td>
                                @foreach($sites as $site)
                                    <td class="text-right">
                                        @if($comparison['sites'][$site->id])
                                            @php
                                                $siteData = $comparison['sites'][$site->id];
                                                $diffClass = '';
                                                if ($siteData['diff'] !== null) {
                                                    $diffClass = $siteData['diff'] < 0 ? 'text-success' : 'text-error';
                                                }
                                            @endphp
                                            <div class="flex flex-col gap-1">
                                                <a 
                                                    href="{{ $siteData['url'] }}" 
                                                    target="_blank"
                                                    class="link link-primary text-xs font-semibold"
                                                    title="{{ $siteData['name'] }}"
                                                >
                                                    {{ number_format($siteData['prix_ht'], 2) }} €
                                                </a>
                                                @if($siteData['diff'] !== null)
                                                    <span class="text-xs {{ $diffClass }} font-bold">
                                                        {{ $siteData['diff'] > 0 ? '+' : '' }}{{ $siteData['diff'] }}%
                                                    </span>
                                                @endif
                                                @if($siteData['vendor'])
                                                    <span class="text-xs text-gray-500 truncate" title="{{ $siteData['vendor'] }}">
                                                        {{ Str::limit($siteData['vendor'], 15) }}
                                                    </span>
                                                @endif
                                            </div>
                                        @else
                                            <span class="text-gray-400 text-xs">N/A</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Pagination en bas -->
            <div class="flex justify-center mt-6">
                <div class="join">
                    <button 
                        class="join-item btn btn-sm"
                        wire:click="previousPage"
                        @if($currentPage === 1) disabled @endif
                    >
                        «
                    </button>
                    
                    @foreach($this->getPaginationButtons() as $button)
                        @if($button['type'] === 'page')
                            <button 
                                class="join-item btn btn-sm {{ $button['value'] === $currentPage ? 'btn-active' : '' }}"
                                wire:click="goToPage({{ $button['value'] }})"
                            >
                                {{ $button['value'] }}
                            </button>
                        @else
                            <button class="join-item btn btn-sm btn-disabled">...</button>
                        @endif
                    @endforeach
                    
                    <button 
                        class="join-item btn btn-sm"
                        wire:click="nextPage"
                        @if($currentPage === $totalPages) disabled @endif
                    >
                        »
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>