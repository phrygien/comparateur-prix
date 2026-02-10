<?php

use Livewire\Volt\Component;
use App\Models\TopProduct;
use App\Models\Product;
use App\Models\Site;
use App\Models\HistoImportTopFile;
use Illuminate\Support\Facades\DB;

new class extends Component {
    public $histoId;
    public $perPage = 100;
    public $currentPage = 1;
    public $totalPages = 0;

    // Paramètres de tri
    public $sortField = 'rank_qty'; // Par défaut, trier par rang quantity
    public $sortDirection = 'asc'; // Par défaut, ordre ascendant


    // calcule perte et gain sur le marche
    public $somme_prix_marche_total = 0;
    public $somme_gain = 0;
    public $somme_perte = 0;
    public $percentage_gain_marche = 0;
    public $percentage_perte_marche = 0;

    public function mount($id)
    {
        $this->histoId = $id;
    }

    public function sortBy($field)
    {
        // Si on clique sur la même colonne, inverser la direction
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            // Sinon, changer de colonne et mettre en ascendant
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        // Retourner à la première page lors du tri
        $this->currentPage = 1;
    }

    public function calculTargetGoogle($marge_percent)
    {
        switch (true) {
            case ($marge_percent >= 20 && $marge_percent <= 22):
                return 800;
                break;
            case ($marge_percent > 22 && $marge_percent <= 25):
                return 700;
                break;
            case ($marge_percent > 25 && $marge_percent <= 30):
                return 650;
                break;
            case ($marge_percent > 30 && $marge_percent <= 32):
                return 600;
                break;
            case ($marge_percent > 32 && $marge_percent <= 35):
                return 500;
                break;
            case ($marge_percent > 35 && $marge_percent <= 40):
                return 400;
                break;
            default:
                return 350;
        }
    }

    public function updatedPerPage()
    {
        // Retourner à la première page quand on change le nombre par page
        $this->currentPage = 1;
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

        // Récupérer les top produits avec pagination et tri
        $topProducts = TopProduct::where('histo_import_top_file_id', $this->histoId)
            ->whereNotNull('ean')
            ->where('ean', '!=', '')
            ->orderBy($this->sortField, $this->sortDirection)
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
                'marge' => (1 - ($topProduct->pamp * 1.2) / $topProduct->prix_vente_cosma) * 100,
                'target_google' => $this->calculTargetGoogle((1 - ($topProduct->pamp * 1.2) / $topProduct->prix_vente_cosma) * 100),
                'sites' => [],
                'prix_moyen_marche' => null,
                'percentage_marche' => null,
                'difference_marche' => null
            ];

            //somme du prix du marche
            $somme_prix_marche = 0;
            $nombre_site = 0;
            $priceDiff_marche = 0;

            // Pour chaque site, ajouter le prix ou null
            foreach ($sites as $site) {
                if (isset($scrapedProducts[$site->id])) {
                    $scrapedProduct = $scrapedProducts[$site->id];

                    // Calculer la différence de prix et le pourcentage
                    $priceDiff = null;
                    $pricePercentage = null;

                    // FIX: Vérifier explicitement que les prix sont > 0 pour éviter la division par zéro
                    if ($topProduct->prix_vente_cosma > 0 && $scrapedProduct->prix_ht > 0) {
                        $priceDiff = $scrapedProduct->prix_ht - $topProduct->prix_vente_cosma;
                        $pricePercentage = round(($priceDiff / $topProduct->prix_vente_cosma) * 100, 2);
                    }

                    $comparison['sites'][$site->id] = [
                        'prix_ht' => $scrapedProduct->prix_ht,
                        'url' => $scrapedProduct->url,
                        'name' => $scrapedProduct->name,
                        'vendor' => $scrapedProduct->vendor,
                        'price_diff' => $priceDiff,
                        'price_percentage' => $pricePercentage,
                    ];

                    $somme_prix_marche += $scrapedProduct->prix_ht;
                    $nombre_site++;

                } else {
                    $comparison['sites'][$site->id] = null;
                }
            }

            if ($somme_prix_marche > 0) {
                $comparison['prix_moyen_marche'] = $somme_prix_marche / $nombre_site;
                //calcule du porcentage
                $priceDiff_marche = $comparison['prix_moyen_marche'] - $topProduct->prix_vente_cosma;
                $comparison['percentage_marche'] = round(($priceDiff_marche / $topProduct->prix_vente_cosma) * 100, 2);
                $comparison['difference_marche'] = $priceDiff_marche;

                //moyen general
                $this->somme_prix_marche_total += $comparison['prix_moyen_marche'];
                if ($priceDiff_marche > 0) {
                    $this->somme_gain += $priceDiff_marche;
                } else {
                    $this->somme_perte += $priceDiff_marche;
                }
            }

            return $comparison;
        });

        // recapitulatif de gain
        $this->percentage_gain_marche = ((($this->somme_prix_marche_total + $this->somme_gain) * 100) / $this->somme_prix_marche_total) - 100;

        // recapitulatif de gain
        $this->percentage_perte_marche = ((($this->somme_prix_marche_total + $this->somme_perte) * 100) / $this->somme_prix_marche_total) - 100;

        return [
            'import' => $import,
            'comparisons' => $comparisons,
            'sites' => $sites,
            'totalPages' => $this->totalPages,
            'totalProducts' => $totalProducts,
            'somme_gain' => $this->somme_gain,
            'somme_perte' => $this->somme_perte,
            'percentage_gain_marche' => $this->percentage_gain_marche,
            'percentage_perte_marche' => $this->percentage_perte_marche
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

    <div class="grid grid-cols-4 gap-4">
        <x-stat title="Gain" value="{{ $somme_gain }}" tooltip="Hello" color="text-primary" />

        <x-stat title="Gain ( % )" description="Pourcentage gain" value="{{ $percentage_gain_marche }}" icon="o-arrow-trending-up"/>

        <x-stat title="Perte" description="" value="{{ $somme_perte }}" tooltip-left="{{ $somme_perte }}" />

        <x-stat title="Perte (%)" description="Pourcentage perte" value="{{ $percentage_perte_marche }}" icon="o-arrow-trending-down" class="text-orange-500"
            color="text-pink-500" />
    </div>

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
                <div class="flex items-center gap-4">
                    <div class="text-sm text-gray-600">
                        {{ $totalProducts }} produit(s) trouvé(s) - 
                        Page {{ $currentPage }} sur {{ $totalPages }}
                    </div>
                    
                    <!-- Select pour changer le nombre d'éléments par page -->
                    <div class="flex items-center gap-2">
                        <label for="perPage" class="text-sm text-gray-600">Afficher:</label>
                        <select 
                            id="perPage"
                            wire:model.live="perPage" 
                            class="select select-sm select-bordered w-24"
                        >
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                            <option value="200">200</option>
                            <option value="500">500</option>
                        </select>
                        <span class="text-sm text-gray-600">par page</span>
                    </div>
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
                            <th class="bg-base-200">
                                <button 
                                    wire:click="sortBy('rank_qty')" 
                                    class="flex items-center gap-1 hover:text-primary transition-colors cursor-pointer"
                                >
                                    Rang Qty
                                    @if($sortField === 'rank_qty')
                                        @if($sortDirection === 'asc')
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                                            </svg>
                                        @else
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                            </svg>
                                        @endif
                                    @else
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                                        </svg>
                                    @endif
                                </button>
                            </th>
                            <th class="bg-base-200">
                                <button 
                                    wire:click="sortBy('rank_chriffre_affaire')" 
                                    class="flex items-center gap-1 hover:text-primary transition-colors cursor-pointer"
                                >
                                    Rang CA
                                    @if($sortField === 'rank_chriffre_affaire')
                                        @if($sortDirection === 'asc')
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                                            </svg>
                                        @else
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                            </svg>
                                        @endif
                                    @else
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                                        </svg>
                                    @endif
                                </button>
                            </th>
                            <th class="bg-base-200">EAN</th>
                            <th class="bg-base-200">Désignation</th>
                            <th class="bg-base-200">Marque</th>
                            <th class="bg-base-200 text-right">Prix Cosma</th>
                            <th class="bg-base-200 text-right">PGHT</th>
                            <th class="bg-base-200 text-right">PAMP</th>
                            <th class="bg-base-200 text-right">Marge</th>
                            <th class="bg-base-200 text-right">Target google</th>
                            @foreach($sites as $site)
                                <th class="bg-base-200 text-right">{{ $site->name }}</th>
                            @endforeach
                            <th class="bg-base-200 text-right">Prix marche</th>
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
                                <td class="text-right text-xs">
                                    {{ number_format($comparison['marge'], 2) }} %
                                </td>
                                <td class="text-right text-xs">
                                    {{ number_format($comparison['target_google'], 2) }} 
                                </td>
                                @foreach($sites as $site)
                                    <td class="text-right">
                                        @if($comparison['sites'][$site->id])
                                            @php
                $siteData = $comparison['sites'][$site->id];

                // Déterminer la classe de couleur
                // ROUGE si prix Top Produit > prix Scraped (Top est plus cher)
                // VERT si prix Top Produit < prix Scraped (Top est moins cher)
                $textClass = '';
                if ($siteData['price_percentage'] !== null) {
                    if ($comparison['prix_cosma'] > $siteData['prix_ht']) {
                        // Prix Cosma SUPÉRIEUR au prix du site = ROUGE
                        $textClass = 'text-error';
                    } else {
                        // Prix Cosma INFÉRIEUR au prix du site = VERT
                        $textClass = 'text-success';
                    }
                }
                                            @endphp
                                            <div class="flex flex-col gap-1 items-end">
                                                <a 
                                                    href="{{ $siteData['url'] }}" 
                                                    target="_blank"
                                                    class="link link-primary text-xs font-semibold"
                                                    title="{{ $siteData['name'] }}"
                                                >
                                                    {{ number_format($siteData['prix_ht'], 2) }} €
                                                </a>
                                                
                                                @if($siteData['price_percentage'] !== null)
                                                    <span class="text-xs {{ $textClass }} font-bold">
                                                        {{ $siteData['price_percentage'] > 0 ? '+' : '' }}{{ $siteData['price_percentage'] }}%
                                                    </span>
                                                @endif
                                                
                                                @if($siteData['vendor'])
                                                    <span class="text-xs text-gray-500 truncate max-w-[120px]" title="{{ $siteData['vendor'] }}">
                                                        {{ Str::limit($siteData['vendor'], 15) }}
                                                    </span>
                                                @endif
                                            </div>
                                        @else
                                            <span class="text-gray-400 text-xs">N/A</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="text-right text-xs">
                                    @php
        // Déterminer la classe de couleur
        // ROUGE si prix Top Produit > prix Scraped (Top est plus cher)
        // VERT si prix Top Produit < prix Scraped (Top est moins cher)
        $textClassMoyen = '';
        if ($comparison['prix_moyen_marche'] !== null) {
            if ($comparison['prix_cosma'] > $comparison['prix_moyen_marche']) {
                // Prix Cosma SUPÉRIEUR au prix du site = ROUGE
                $textClassMoyen = 'text-error';
            } else {
                // Prix Cosma INFÉRIEUR au prix du site = VERT
                $textClassMoyen = 'text-success';
            }
        }
                                    @endphp
                                    <div class="flex flex-col gap-1 items-end">
                                        <a  
                                            target="_blank"
                                            class="link-primary text-xs font-semibold"
                                        >
                                            {{ number_format($comparison['prix_moyen_marche'], 2) }} €
                                        </a>
                                        
                                        @if($comparison['percentage_marche'] !== null)
                                            <span class="text-xs {{ $textClassMoyen }} font-bold">
                                                {{ $comparison['percentage_marche'] > 0 ? '+' : '' }}{{ $comparison['percentage_marche'] }}%
                                            </span>
                                        @endif
                                    </div>
                                </td>
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