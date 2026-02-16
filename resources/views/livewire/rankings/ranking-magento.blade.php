<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use App\Models\Product;
use App\Models\Site;

new class extends Component {

    public string $activeCountry = 'FR';
    public string $dateFrom      = '';
    public string $dateTo        = '';
    public string $sortBy        = 'rank_qty';
    public string $marqueFilter  = ''; // Nouveau filtre marque

    public array $countries = [
        'FR' => 'France',
        'BE' => 'Belgique',
        'ES' => 'Espagne',
        'IT' => 'Italie',
        'DE' => 'Allemagne',
    ];

    public $somme_prix_marche_total = 0;
    public $somme_gain = 0;
    public $somme_perte = 0;
    public $percentage_gain_marche = 0;
    public $percentage_perte_marche = 0;

    public function mount(): void
    {
        $this->dateFrom = date('Y-01-01');
        $this->dateTo = date('Y-12-31');
    }

    public function getSalesProperty()
    {
        $dateFrom = ($this->dateFrom ?: date('Y-01-01')) . ' 00:00:00';
        $dateTo   = ($this->dateTo   ?: date('Y-12-31')) . ' 23:59:59';

        $orderCol = $this->sortBy === 'rank_ca' ? 'total_revenue' : 'total_qty_sold';

        // Construire la condition de filtre marque (appliquée après le calcul des rangs)
        $marqueCondition = '';
        $params = [$dateFrom, $dateTo, $this->activeCountry];
        
        if (!empty($this->marqueFilter)) {
            $marqueCondition = " WHERE marque = ?";
            $params[] = $this->marqueFilter;
        }

        $sql = "
            WITH sales AS (
                SELECT
                    addr.country_id AS country,
                    oi.sku as ean,
                    SUBSTRING_INDEX(CAST(product_char.name AS CHAR CHARACTER SET utf8mb4), ' - ', 1) AS groupe,
                    SUBSTRING_INDEX(SUBSTRING_INDEX(CAST(product_char.name AS CHAR CHARACTER SET utf8mb4), ' - ', 2), ' - ', -1) AS marque,
                    SUBSTRING_INDEX(SUBSTRING_INDEX(CAST(product_char.name AS CHAR CHARACTER SET utf8mb4), ' - ', 3), ' - ', -1) AS designation_produit,
                    (CASE
                        WHEN ROUND(product_decimal.special_price, 2) IS NOT NULL THEN ROUND(product_decimal.special_price, 2)
                        ELSE ROUND(product_decimal.price, 2)
                    END) as prix_vente_cosma,
                    ROUND(product_decimal.cost, 2) AS cost,
                    ROUND(product_decimal.prix_achat_ht, 2) AS pght,
                    CAST(SUM(oi.qty_ordered) AS UNSIGNED) AS total_qty_sold,
                    ROUND(SUM(oi.base_row_total), 2) AS total_revenue
                FROM sales_order_item oi
                JOIN sales_order o ON oi.order_id = o.entity_id
                JOIN sales_order_address addr ON addr.parent_id = o.entity_id
                    AND addr.address_type = 'shipping'
                JOIN catalog_product_entity AS produit ON oi.sku = produit.sku
                LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
                LEFT JOIN product_decimal ON product_decimal.entity_id = produit.entity_id
                WHERE o.state IN ('processing', 'complete')
                  AND o.created_at >= ?
                  AND o.created_at <= ?
                  AND addr.country_id = ?
                  AND oi.row_total > 0
                GROUP BY oi.sku, oi.name, addr.country_id
            ),
            ranked_sales AS (
                SELECT
                    *,
                    ROW_NUMBER() OVER (ORDER BY total_qty_sold DESC) AS rank_qty,
                    ROW_NUMBER() OVER (ORDER BY total_revenue DESC) AS rank_ca
                FROM sales
            )
            SELECT *
            FROM ranked_sales
            {$marqueCondition}
            ORDER BY {$orderCol} DESC
            LIMIT 100
        ";

        DB::connection('mysqlMagento')->getPdo()->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        $results = DB::connection('mysqlMagento')
            ->select($sql, $params);
        
        foreach ($results as $result) {
            if (isset($result->designation_produit)) {
                if (!mb_check_encoding($result->designation_produit, 'UTF-8')) {
                    $result->designation_produit = mb_convert_encoding($result->designation_produit, 'UTF-8', 'ISO-8859-1');
                }
                $result->designation_produit = mb_convert_encoding($result->designation_produit, 'UTF-8', 'UTF-8');
            }
            if (isset($result->marque)) {
                if (!mb_check_encoding($result->marque, 'UTF-8')) {
                    $result->marque = mb_convert_encoding($result->marque, 'UTF-8', 'ISO-8859-1');
                }
                $result->marque = mb_convert_encoding($result->marque, 'UTF-8', 'UTF-8');
            }
        }
        
        return $results;
    }

    // Nouvelle méthode pour récupérer la liste des marques disponibles
    public function getAvailableMarquesProperty()
    {
        $dateFrom = ($this->dateFrom ?: date('Y-01-01')) . ' 00:00:00';
        $dateTo   = ($this->dateTo   ?: date('Y-12-31')) . ' 23:59:59';

        $sql = "
            WITH sales AS (
                SELECT DISTINCT
                    SUBSTRING_INDEX(SUBSTRING_INDEX(CAST(product_char.name AS CHAR CHARACTER SET utf8mb4), ' - ', 2), ' - ', -1) AS marque
                FROM sales_order_item oi
                JOIN sales_order o ON oi.order_id = o.entity_id
                JOIN sales_order_address addr ON addr.parent_id = o.entity_id
                    AND addr.address_type = 'shipping'
                JOIN catalog_product_entity AS produit ON oi.sku = produit.sku
                LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
                WHERE o.state IN ('processing', 'complete')
                  AND o.created_at >= ?
                  AND o.created_at <= ?
                  AND addr.country_id = ?
                  AND oi.row_total > 0
            )
            SELECT marque
            FROM sales
            WHERE marque IS NOT NULL
              AND marque != ''
            ORDER BY marque ASC
        ";

        $marques = DB::connection('mysqlMagento')
            ->select($sql, [$dateFrom, $dateTo, $this->activeCountry]);

        return collect($marques)->pluck('marque')->toArray();
    }

    public function getComparisonsProperty()
    {
        $sites = Site::where('country_code', $this->activeCountry)
            ->orderBy('name')
            ->get();

        $this->somme_prix_marche_total = 0;
        $this->somme_gain = 0;
        $this->somme_perte = 0;

        $sales = $this->sales;
        $comparisons = [];

        $siteIds = $sites->pluck('id')->toArray();

        foreach ($sales as $row) {
            $scrapedProducts = collect([]);
            
            if (!empty($row->ean) && !empty($siteIds)) {
                $scrapedProducts = Product::where('ean', $row->ean)
                    ->whereIn('web_site_id', $siteIds)
                    ->with('website')
                    ->get()
                    ->keyBy('web_site_id');
            }

            $comparison = [
                'row' => $row,
                'sites' => [],
                'prix_moyen_marche' => null,
                'percentage_marche' => null,
                'difference_marche' => null
            ];

            $somme_prix_marche = 0;
            $nombre_site = 0;

            foreach ($sites as $site) {
                if (isset($scrapedProducts[$site->id])) {
                    $scrapedProduct = $scrapedProducts[$site->id];

                    $priceDiff = null;
                    $pricePercentage = null;

                    $prixCosma = $row->prix_vente_cosma;

                    if ($prixCosma > 0 && $scrapedProduct->prix_ht > 0) {
                        $priceDiff = $scrapedProduct->prix_ht - $prixCosma;
                        $pricePercentage = round(($priceDiff / $prixCosma) * 100, 2);
                    }

                    $comparison['sites'][$site->id] = [
                        'prix_ht' => $scrapedProduct->prix_ht,
                        'url' => $scrapedProduct->url,
                        'name' => $scrapedProduct->name,
                        'vendor' => $scrapedProduct->vendor,
                        'price_diff' => $priceDiff,
                        'price_percentage' => $pricePercentage,
                        'site_name' => $site->name,
                    ];

                    $somme_prix_marche += $scrapedProduct->prix_ht;
                    $nombre_site++;

                } else {
                    $comparison['sites'][$site->id] = null;
                }
            }

            $prixCosma = $row->prix_vente_cosma;
            
            if ($somme_prix_marche > 0 && $prixCosma > 0) {
                $comparison['prix_moyen_marche'] = $somme_prix_marche / $nombre_site;
                $priceDiff_marche = $comparison['prix_moyen_marche'] - $prixCosma;
                $comparison['percentage_marche'] = round(($priceDiff_marche / $prixCosma) * 100, 2);
                $comparison['difference_marche'] = $priceDiff_marche;

                $this->somme_prix_marche_total += $comparison['prix_moyen_marche'];
                if ($priceDiff_marche > 0) {
                    $this->somme_gain += $priceDiff_marche;
                } else {
                    $this->somme_perte += $priceDiff_marche;
                }
            }

            $comparisons[] = $comparison;
        }

        if ($this->somme_prix_marche_total > 0) {
            $this->percentage_gain_marche = ((($this->somme_prix_marche_total + $this->somme_gain) * 100) / $this->somme_prix_marche_total) - 100;
            $this->percentage_perte_marche = ((($this->somme_prix_marche_total + $this->somme_perte) * 100) / $this->somme_prix_marche_total) - 100;
        }

        return collect($comparisons);
    }

    public function getSitesProperty()
    {
        return Site::where('country_code', $this->activeCountry)
            ->orderBy('name')
            ->get();
    }

    public function sortBy(string $column): void
    {
        $this->sortBy = $column;
    }

    public function with(): array
    {
        $comparisons = $this->comparisons;
        $comparisonsAvecPrixMarche = $comparisons->filter(function($comparison) {
            return $comparison['prix_moyen_marche'] !== null;
        })->count();

        return [
            'sales' => $this->sales,
            'comparisons' => $comparisons,
            'sites' => $this->sites,
            'availableMarques' => $this->availableMarques,
            'comparisonsAvecPrixMarche' => $comparisonsAvecPrixMarche,
            'somme_gain' => $this->somme_gain,
            'somme_perte' => $this->somme_perte,
            'percentage_gain_marche' => $this->percentage_gain_marche,
            'percentage_perte_marche' => $this->percentage_perte_marche,
            'dateFrom' => $this->dateFrom,
            'dateTo' => $this->dateTo,
        ];
    }
}; ?>

<div>
    <div class="px-4 sm:px-6 lg:px-8 pt-6 pb-2">
        <x-tabs wire:model.live="activeCountry">
            @foreach($countries as $code => $label)
                <x-tab name="{{ $code }}" label="{{ $label }}">
                    
                    <div
                        wire:loading
                        wire:target="activeCountry"
                        class="flex flex-col items-center justify-center gap-3 py-16"
                    >
                        <span class="loading loading-spinner loading-lg text-primary"></span>
                        <span class="text-sm font-medium">Chargement des données pour {{ $label }}…</span>
                    </div>

                    <div wire:loading.remove wire:target="activeCountry">
                        
                        @if($comparisonsAvecPrixMarche > 0)
                            <div class="grid grid-cols-4 gap-4 mb-6 mt-6">
                                <x-stat
                                    title="Moins chers en moyenne de"
                                    value="{{ number_format(abs($somme_gain / $comparisonsAvecPrixMarche), 2, ',', ' ') }} €"
                                    description="sur {{ $comparisonsAvecPrixMarche }} produit(s) comparé(s)"
                                    color="text-primary"
                                />

                                <x-stat
                                    class="text-green-500"
                                    title="Moins chers en moyenne de (%)"
                                    description="sur {{ $comparisonsAvecPrixMarche }} produit(s) comparé(s)"
                                    value="{{ number_format(abs($percentage_gain_marche), 2, ',', ' ') }} %"
                                    icon="o-arrow-trending-down"
                                />

                                <x-stat
                                    title="Plus chers en moyenne de"
                                    value="{{ number_format(abs($somme_perte / $comparisonsAvecPrixMarche), 2, ',', ' ') }} €"
                                    description="sur {{ $comparisonsAvecPrixMarche }} produit(s) comparé(s)"
                                />

                                <x-stat
                                    title="Plus chers en moyenne de (%)"
                                    value="{{ number_format(abs($percentage_perte_marche), 2, ',', ' ') }} %"
                                    description="sur {{ $comparisonsAvecPrixMarche }} produit(s) comparé(s)"
                                    icon="o-arrow-trending-up"
                                    class="text-pink-500"
                                    color="text-pink-500"
                                />
                            </div>
                        @endif

                        <div class="flex flex-wrap items-center justify-between gap-4 mb-4 mt-6">
                            <div>
                                <h1 class="text-base font-semibold text-gray-900">
                                    Ventes — {{ $label }}
                                </h1>
                                <p class="mt-0.5 text-sm text-gray-500">
                                    Top 100 produits · {{ count($sales) }} résultat(s)
                                    @if($marqueFilter)
                                        · Marque: {{ $marqueFilter }}
                                    @endif
                                </p>
                            </div>

                            <div class="flex flex-wrap items-center gap-3">
                                <!-- Filtre Marque -->
                                <select
                                    wire:model.live="marqueFilter"
                                    class="select select-bordered select-sm w-48"
                                >
                                    <option value="">Toutes les marques</option>
                                    @foreach($availableMarques as $marque)
                                        <option value="{{ $marque }}">{{ $marque }}</option>
                                    @endforeach
                                </select>

                                <div class="divider divider-horizontal mx-0"></div>

                                <div class="flex items-center gap-2">
                                    <input
                                        type="date"
                                        wire:model.live="dateFrom"
                                        value="{{ $dateFrom }}"
                                        placeholder="Date début"
                                        class="input input-bordered input-sm w-36"
                                    />
                                    <span class="text-xs text-gray-400">→</span>
                                    <input
                                        type="date"
                                        wire:model.live="dateTo"
                                        value="{{ $dateTo }}"
                                        placeholder="Date fin"
                                        class="input input-bordered input-sm w-36"
                                    />
                                </div>

                                <div class="divider divider-horizontal mx-0"></div>

                                <div class="flex items-center gap-2">
                                    <span class="text-xs text-gray-400">Trier par</span>
                                    <button
                                        type="button"
                                        @click="$wire.sortBy('rank_qty')"
                                        class="btn btn-xs {{ $sortBy === 'rank_qty' ? 'btn-primary' : 'btn-ghost' }}"
                                    >
                                        @if($sortBy === 'rank_qty')
                                            <svg class="w-3 h-3" viewBox="0 0 16 16" fill="currentColor"><path d="M8 12L4 6h8z"/></svg>
                                        @endif
                                        Qté vendue
                                    </button>
                                    <button
                                        type="button"
                                        @click="$wire.sortBy('rank_ca')"
                                        class="btn btn-xs {{ $sortBy === 'rank_ca' ? 'btn-success' : 'btn-ghost' }}"
                                    >
                                        @if($sortBy === 'rank_ca')
                                            <svg class="w-3 h-3" viewBox="0 0 16 16" fill="currentColor"><path d="M8 12L4 6h8z"/></svg>
                                        @endif
                                        CA total
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="relative">

                            <div
                                wire:loading
                                wire:target="dateFrom, dateTo, sortBy, marqueFilter"
                                class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-3 rounded-lg bg-white/70 backdrop-blur-sm"
                            >
                                <span class="loading loading-spinner loading-lg text-primary"></span>
                                <span class="text-sm font-medium">Mise à jour…</span>
                            </div>

                            @if(count($sales) === 0)
                                <div class="alert alert-info">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="stroke-current shrink-0 w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                    <span>Aucune vente trouvée pour cette période{{ $marqueFilter ? ' et cette marque' : '' }}.</span>
                                </div>
                            @else
                                <div 
                                    class="overflow-x-auto"
                                    wire:loading.class="opacity-40 pointer-events-none"
                                    wire:target="dateFrom, dateTo, sortBy, marqueFilter"
                                >
                                    <table class="table table-xs table-pin-rows table-pin-cols">
                                        <thead>
                                            <tr>
                                                <th>Rang Qty</th>
                                                <th>Rang CA</th>
                                                <th>EAN</th>
                                                <th>Groupe</th>
                                                <th>Marque</th>
                                                <th>Désignation</th>
                                                <th>Prix Cosma</th>
                                                <th>
                                                    <button @click="$wire.sortBy('rank_qty')" class="flex items-center gap-1 hover:underline cursor-pointer">
                                                        Qté vendue
                                                        <svg class="w-3 h-3" viewBox="0 0 16 16" fill="currentColor">
                                                            <path d="M8 4l3 4H5l3-4zm0 8l-3-4h6l-3 4z"/>
                                                        </svg>
                                                    </button>
                                                </th>
                                                <th>
                                                    <button @click="$wire.sortBy('rank_ca')" class="flex items-center gap-1 hover:underline cursor-pointer">
                                                        CA total
                                                        <svg class="w-3 h-3" viewBox="0 0 16 16" fill="currentColor">
                                                            <path d="M8 4l3 4H5l3-4zm0 8l-3-4h6l-3 4z"/>
                                                        </svg>
                                                    </button>
                                                </th>
                                                <th>PGHT</th>
                                                @foreach($sites as $site)
                                                    <th class="text-right">{{ $site->name }}</th>
                                                @endforeach
                                                <th class="text-right">Prix marché</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($comparisons as $comparison)
                                                @php
                                                    $row = $comparison['row'];
                                                    $prixCosma = $row->prix_vente_cosma;
                                                @endphp
                                                <tr class="hover">
                                                    
                                                    <th>
                                                        <span class="font-semibold {{ $sortBy === 'rank_qty' ? 'text-primary' : 'text-success' }}">
                                                            #{{ $sortBy === 'rank_qty' ? $row->rank_qty : $row->rank_ca }}
                                                        </span>
                                                    </th>

                                                    <th>
                                                        <span class="font-mono text-xs">{{ $row->rank_ca }}</span>
                                                    </th>
                                                    <td>
                                                        <span class="font-mono text-xs">{{ $row->ean }}</span>
                                                    </td>

                                                    <td>
                                                        <div class="text-xs">{{ $row->groupe ?? '—' }}</div>
                                                    </td>

                                                    <td>
                                                        <div class="text-xs font-semibold">{{ $row->marque ?? '—' }}</div>
                                                    </td>

                                                    <td>
                                                        <div class="font-bold max-w-xs truncate" title="{{ $row->designation_produit }}">
                                                            {{ $row->designation_produit ?? '—' }}
                                                        </div>
                                                    </td>

                                                    <td class="text-right font-semibold text-primary">
                                                        {{ number_format($prixCosma, 2, ',', ' ') }} €
                                                    </td>

                                                    <td>
                                                        <span class="font-semibold {{ $sortBy === 'rank_qty' ? 'text-primary' : '' }}">
                                                            {{ number_format($row->total_qty_sold, 0, ',', ' ') }}
                                                        </span>
                                                    </td>

                                                    <td>
                                                        <span class="font-semibold {{ $sortBy === 'rank_ca' ? 'text-success' : '' }}">
                                                            {{ number_format($row->total_revenue, 2, ',', ' ') }} €
                                                        </span>
                                                    </td>

                                                    <td class="text-right text-xs">
                                                        @if($row->pght)
                                                            {{ number_format($row->pght, 2, ',', ' ') }} €
                                                        @else
                                                            <span class="text-gray-400">—</span>
                                                        @endif
                                                    </td>

                                                    @foreach($this->sites as $site)
                                                        <td class="text-right">
                                                            @if($comparison['sites'][$site->id])
                                                                @php
                                                                    $siteData = $comparison['sites'][$site->id];
                                                                    $textClass = '';
                                                                    if ($siteData['price_percentage'] !== null) {
                                                                        if ($prixCosma > $siteData['prix_ht']) {
                                                                            $textClass = 'text-error';
                                                                        } else {
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
                                                        @if($comparison['prix_moyen_marche'])
                                                            @php
                                                                $textClassMoyen = '';
                                                                if ($prixCosma > $comparison['prix_moyen_marche']) {
                                                                    $textClassMoyen = 'text-error';
                                                                } else {
                                                                    $textClassMoyen = 'text-success';
                                                                }
                                                            @endphp
                                                            <div class="flex flex-col gap-1 items-end">
                                                                <span class="font-semibold">
                                                                    {{ number_format($comparison['prix_moyen_marche'], 2, ',', ' ') }} €
                                                                </span>

                                                                @if($comparison['percentage_marche'] !== null)
                                                                    <span class="text-xs {{ $textClassMoyen }} font-bold">
                                                                        {{ $comparison['percentage_marche'] > 0 ? '+' : '' }}{{ $comparison['percentage_marche'] }}%
                                                                    </span>
                                                                @endif
                                                            </div>
                                                        @else
                                                            <span class="text-gray-400">N/A</span>
                                                        @endif
                                                    </td>

                                                </tr>
                                            @endforeach
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <th>Rang Qty</th>
                                                <th>Rang CA</th>
                                                <th>EAN</th>
                                                <th>Groupe</th>
                                                <th>Marque</th>
                                                <th>Désignation</th>
                                                <th>Prix Cosma</th>
                                                <th>Qté vendue</th>
                                                <th>CA total</th>
                                                <th>PGHT</th>
                                                @foreach($sites as $site)
                                                    <th class="text-right">{{ $site->name }}</th>
                                                @endforeach
                                                <th class="text-right">Prix marché</th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            @endif
                        </div>

                    </div>

                </x-tab>
            @endforeach
        </x-tabs>
    </div>
</div>
