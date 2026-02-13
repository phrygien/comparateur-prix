<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use App\Models\Product;
use App\Models\Site;

new class extends Component {

    public string $activeCountry = 'FR';
    public string $dateFrom      = '';
    public string $dateTo        = '';
    public string $sortBy        = 'rownum_qty'; // 'rownum_qty' | 'rownum_revenue'

    public array $countries = [
        'FR' => 'France',
        'BE' => 'Belgique',
        'ES' => 'Espagne',
        'IT' => 'Italie',
        'DE' => 'Allemagne',
    ];

    // Statistiques de marché
    public $somme_prix_marche_total = 0;
    public $somme_gain = 0;
    public $somme_perte = 0;
    public $percentage_gain_marche = 0;
    public $percentage_perte_marche = 0;

    public function getSalesProperty()
    {
        $dateFrom = ($this->dateFrom ?: date('Y-01-01')) . ' 00:00:00';
        $dateTo   = ($this->dateTo   ?: date('Y-12-31')) . ' 23:59:59';

        $orderCol = $this->sortBy === 'rownum_revenue' ? 'total_revenue' : 'total_qty_sold';

        $sql = "
            WITH sales AS (
                SELECT
                    addr.country_id AS country,
                    oi.sku,
                    oi.name AS title,
                    produit.ean,
                    ROUND(product_decimal.price, 2) AS price,
                    ROUND(product_decimal.special_price, 2) AS special_price,
                    ROUND(product_decimal.cost, 2) AS cost,
                    ROUND(product_decimal.pvc, 2) AS pvc,
                    ROUND(product_decimal.prix_achat_ht, 2) AS prix_achat_ht,
                    ROUND(product_decimal.prix_us, 2) AS prix_us,
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
                GROUP BY oi.sku, oi.name, addr.country_id, produit.ean
            )
            SELECT
                *,
                ROW_NUMBER() OVER (ORDER BY total_qty_sold DESC) AS rownum_qty,
                ROW_NUMBER() OVER (ORDER BY total_revenue DESC) AS rownum_revenue
            FROM sales
            ORDER BY {$orderCol} DESC
            LIMIT 100
        ";

        // Forcer l'encodage UTF-8 pour la connexion
        DB::connection('mysqlMagento')->getPdo()->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        $results = DB::connection('mysqlMagento')
            ->select($sql, [$dateFrom, $dateTo, $this->activeCountry]);
        
        // Nettoyer l'encodage pour chaque résultat
        foreach ($results as $result) {
            if (isset($result->title)) {
                // Convertir en UTF-8 si nécessaire
                if (!mb_check_encoding($result->title, 'UTF-8')) {
                    $result->title = mb_convert_encoding($result->title, 'UTF-8', 'ISO-8859-1');
                }
                // Nettoyer les caractères invalides
                $result->title = mb_convert_encoding($result->title, 'UTF-8', 'UTF-8');
            }
        }
        
        return $results;
    }

    public function getComparisonsProperty()
    {
        // Récupérer les sites à comparer
        $sites = Site::whereIn('id', [1, 2, 8, 16])
            ->orderBy('name')
            ->get();

        // Réinitialiser les statistiques
        $this->somme_prix_marche_total = 0;
        $this->somme_gain = 0;
        $this->somme_perte = 0;

        $sales = $this->sales;
        $comparisons = [];

        foreach ($sales as $row) {
            // Rechercher les produits scrapés correspondants par EAN
            $scrapedProducts = collect([]);
            
            if (!empty($row->ean)) {
                $scrapedProducts = Product::where('ean', $row->ean)
                    ->whereIn('web_site_id', [1, 2, 8, 16])
                    ->with('website')
                    ->get()
                    ->keyBy('web_site_id');
            }

            // Créer la comparaison
            $comparison = [
                'row' => $row,
                'sites' => [],
                'prix_moyen_marche' => null,
                'percentage_marche' => null,
                'difference_marche' => null
            ];

            // Calcul du prix moyen du marché
            $somme_prix_marche = 0;
            $nombre_site = 0;

            // Pour chaque site, ajouter le prix ou null
            foreach ($sites as $site) {
                if (isset($scrapedProducts[$site->id])) {
                    $scrapedProduct = $scrapedProducts[$site->id];

                    // Calculer la différence de prix et le pourcentage
                    $priceDiff = null;
                    $pricePercentage = null;

                    // Utiliser le prix spécial s'il existe, sinon le prix normal
                    $prixCosma = $row->special_price ?: $row->price;

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

            // Calculer le prix moyen du marché
            $prixCosma = $row->special_price ?: $row->price;
            
            if ($somme_prix_marche > 0 && $prixCosma > 0) {
                $comparison['prix_moyen_marche'] = $somme_prix_marche / $nombre_site;
                $priceDiff_marche = $comparison['prix_moyen_marche'] - $prixCosma;
                $comparison['percentage_marche'] = round(($priceDiff_marche / $prixCosma) * 100, 2);
                $comparison['difference_marche'] = $priceDiff_marche;

                // Statistiques globales
                $this->somme_prix_marche_total += $comparison['prix_moyen_marche'];
                if ($priceDiff_marche > 0) {
                    $this->somme_gain += $priceDiff_marche;
                } else {
                    $this->somme_perte += $priceDiff_marche;
                }
            }

            $comparisons[] = $comparison;
        }

        // Calculer les pourcentages globaux
        if ($this->somme_prix_marche_total > 0) {
            $this->percentage_gain_marche = ((($this->somme_prix_marche_total + $this->somme_gain) * 100) / $this->somme_prix_marche_total) - 100;
            $this->percentage_perte_marche = ((($this->somme_prix_marche_total + $this->somme_perte) * 100) / $this->somme_prix_marche_total) - 100;
        }

        return collect($comparisons);
    }

    public function getSitesProperty()
    {
        return Site::whereIn('id', [1, 2, 8, 16])
            ->orderBy('name')
            ->get();
    }

    public function sortBy(string $column): void
    {
        $this->sortBy = $column;
    }
}; ?>

<div>

    {{-- ─── Header : tabs pays ───────────────────────────── --}}
    <div class="px-4 sm:px-6 lg:px-8 pt-6 pb-2">
        <x-tabs wire:model.live="activeCountry">
            @foreach($countries as $code => $label)
                <x-tab name="{{ $code }}" label="{{ $label }}">
                    
                    {{-- Spinner sur le tab --}}
                    <div
                        wire:loading
                        wire:target="activeCountry"
                        class="flex flex-col items-center justify-center gap-3 py-16"
                    >
                        <span class="loading loading-spinner loading-lg text-primary"></span>
                        <span class="text-sm font-medium">Chargement des données pour {{ $label }}…</span>
                    </div>

                    {{-- Contenu du tab --}}
                    <div wire:loading.remove wire:target="activeCountry">
                        
                        {{-- Statistiques de marché --}}
                        @if(count($this->comparisons) > 0 && $somme_prix_marche_total > 0)
                            <div class="grid grid-cols-4 gap-4 mb-6 mt-6">
                                <x-stat
                                    title="Moins chers en moyenne de"
                                    value="{{ number_format(abs($somme_gain / count($this->comparisons)), 2, ',', ' ') }} €"
                                    description="sur certains produits"
                                    color="text-primary"
                                />

                                <x-stat
                                    class="text-green-500"
                                    title="Moins chers en moyenne de (%)"
                                    description="sur certains produits"
                                    value="{{ number_format(abs($percentage_gain_marche), 2, ',', ' ') }} %"
                                    icon="o-arrow-trending-down"
                                />

                                <x-stat
                                    title="Plus chers en moyenne de"
                                    value="{{ number_format(abs($somme_perte / count($this->comparisons)), 2, ',', ' ') }} €"
                                    description="sur certains produits"
                                />

                                <x-stat
                                    title="Plus chers en moyenne de (%)"
                                    value="{{ number_format(abs($percentage_perte_marche), 2, ',', ' ') }} %"
                                    description="sur certains produits"
                                    icon="o-arrow-trending-up"
                                    class="text-pink-500"
                                    color="text-pink-500"
                                />
                            </div>
                        @endif

                        {{-- Toolbar : titre + compteur + filtres --}}
                        <div class="flex flex-wrap items-center justify-between gap-4 mb-4 mt-6">
                            <div>
                                <h1 class="text-base font-semibold text-gray-900">
                                    Ventes — {{ $label }}
                                </h1>
                                <p class="mt-0.5 text-sm text-gray-500">
                                    Top 100 produits · {{ count($this->sales) }} résultat(s)
                                </p>
                            </div>

                            {{-- Filtres : dates + tri --}}
                            <div class="flex flex-wrap items-center gap-3">
                                {{-- Filtres de date --}}
                                <div class="flex items-center gap-2">
                                    <input
                                        type="date"
                                        wire:model.live="dateFrom"
                                        placeholder="Date début"
                                        class="input input-bordered input-sm w-36"
                                    />
                                    <span class="text-xs text-gray-400">→</span>
                                    <input
                                        type="date"
                                        wire:model.live="dateTo"
                                        placeholder="Date fin"
                                        class="input input-bordered input-sm w-36"
                                    />
                                </div>

                                <div class="divider divider-horizontal mx-0"></div>

                                {{-- Boutons de tri --}}
                                <div class="flex items-center gap-2">
                                    <span class="text-xs text-gray-400">Trier par</span>
                                    <button
                                        type="button"
                                        @click="$wire.sortBy('rownum_qty')"
                                        class="btn btn-xs {{ $sortBy === 'rownum_qty' ? 'btn-primary' : 'btn-ghost' }}"
                                    >
                                        @if($sortBy === 'rownum_qty')
                                            <svg class="w-3 h-3" viewBox="0 0 16 16" fill="currentColor"><path d="M8 12L4 6h8z"/></svg>
                                        @endif
                                        Qté vendue
                                    </button>
                                    <button
                                        type="button"
                                        @click="$wire.sortBy('rownum_revenue')"
                                        class="btn btn-xs {{ $sortBy === 'rownum_revenue' ? 'btn-success' : 'btn-ghost' }}"
                                    >
                                        @if($sortBy === 'rownum_revenue')
                                            <svg class="w-3 h-3" viewBox="0 0 16 16" fill="currentColor"><path d="M8 12L4 6h8z"/></svg>
                                        @endif
                                        CA total
                                    </button>
                                </div>
                            </div>
                        </div>

                        {{-- Table wrapper --}}
                        <div class="relative">

                            {{-- Spinner pour les filtres et tri --}}
                            <div
                                wire:loading
                                wire:target="dateFrom, dateTo, sortBy"
                                class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-3 rounded-lg bg-white/70 backdrop-blur-sm"
                            >
                                <span class="loading loading-spinner loading-lg text-primary"></span>
                                <span class="text-sm font-medium">Mise à jour…</span>
                            </div>

                            @if(count($this->sales) === 0)
                                <div class="alert alert-info">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="stroke-current shrink-0 w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                    <span>Aucune vente trouvée pour cette période et ce pays.</span>
                                </div>
                            @else
                                <div 
                                    class="overflow-x-auto"
                                    wire:loading.class="opacity-40 pointer-events-none"
                                    wire:target="dateFrom, dateTo, sortBy"
                                >
                                    <table class="table table-xs table-pin-rows table-pin-cols">
                                        <thead>
                                            <tr>
                                                <th>Rang</th>
                                                <th>SKU</th>
                                                <th>EAN</th>
                                                <th>Produit</th>
                                                <th>Prix</th>
                                                <th>
                                                    <button @click="$wire.sortBy('rownum_qty')" class="flex items-center gap-1 hover:underline cursor-pointer">
                                                        Qté vendue
                                                        <svg class="w-3 h-3" viewBox="0 0 16 16" fill="currentColor">
                                                            <path d="M8 4l3 4H5l3-4zm0 8l-3-4h6l-3 4z"/>
                                                        </svg>
                                                    </button>
                                                </th>
                                                <th>
                                                    <button @click="$wire.sortBy('rownum_revenue')" class="flex items-center gap-1 hover:underline cursor-pointer">
                                                        CA total
                                                        <svg class="w-3 h-3" viewBox="0 0 16 16" fill="currentColor">
                                                            <path d="M8 4l3 4H5l3-4zm0 8l-3-4h6l-3 4z"/>
                                                        </svg>
                                                    </button>
                                                </th>
                                                <th>Coût</th>
                                                @foreach($this->sites as $site)
                                                    <th class="text-right">{{ $site->name }}</th>
                                                @endforeach
                                                <th class="text-right">Prix marché</th>
                                                <th>Rang Qté</th>
                                                <th>Rang CA</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($this->comparisons as $comparison)
                                                @php
                                                    $row = $comparison['row'];
                                                    $prixCosma = $row->special_price ?: $row->price;
                                                @endphp
                                                <tr class="hover">
                                                    
                                                    {{-- Rang --}}
                                                    <th>
                                                        <span class="font-semibold {{ $sortBy === 'rownum_qty' ? 'text-primary' : 'text-success' }}">
                                                            #{{ $sortBy === 'rownum_qty' ? $row->rownum_qty : $row->rownum_revenue }}
                                                        </span>
                                                    </th>

                                                    {{-- SKU --}}
                                                    <td>
                                                        <span class="font-mono text-xs">{{ $row->sku }}</span>
                                                    </td>

                                                    {{-- EAN --}}
                                                    <td>
                                                        <span class="font-mono text-xs">{{ $row->ean ?? '—' }}</span>
                                                    </td>

                                                    {{-- Produit --}}
                                                    <td>
                                                        <div class="font-bold">{{ $row->title ?? '—' }}</div>
                                                    </td>

                                                    {{-- Prix --}}
                                                    <td>
                                                        @if($row->special_price)
                                                            <div>
                                                                <span class="text-success font-semibold">
                                                                    {{ number_format($row->special_price, 2, ',', ' ') }} €
                                                                </span>
                                                                <br>
                                                                <span class="text-xs text-gray-400 line-through">
                                                                    {{ number_format($row->price, 2, ',', ' ') }} €
                                                                </span>
                                                            </div>
                                                        @elseif($row->price)
                                                            {{ number_format($row->price, 2, ',', ' ') }} €
                                                        @else
                                                            <span class="text-gray-400">—</span>
                                                        @endif
                                                    </td>

                                                    {{-- Quantité vendue --}}
                                                    <td>
                                                        <span class="font-semibold {{ $sortBy === 'rownum_qty' ? 'text-primary' : '' }}">
                                                            {{ number_format($row->total_qty_sold, 0, ',', ' ') }}
                                                        </span>
                                                    </td>

                                                    {{-- CA total --}}
                                                    <td>
                                                        <span class="font-semibold {{ $sortBy === 'rownum_revenue' ? 'text-success' : '' }}">
                                                            {{ number_format($row->total_revenue, 2, ',', ' ') }} €
                                                        </span>
                                                    </td>

                                                    {{-- Coût --}}
                                                    <td>
                                                        @if($row->cost)
                                                            <span class="text-xs text-gray-600">
                                                                {{ number_format($row->cost, 2, ',', ' ') }} €
                                                            </span>
                                                        @else
                                                            <span class="text-gray-400">—</span>
                                                        @endif
                                                    </td>

                                                    {{-- Prix des sites concurrents --}}
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

                                                    {{-- Prix moyen marché --}}
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

                                                    {{-- Rang Qté --}}
                                                    <td>
                                                        <span class="text-xs font-medium text-primary">
                                                            #{{ $row->rownum_qty }}
                                                        </span>
                                                    </td>

                                                    {{-- Rang CA --}}
                                                    <td>
                                                        <span class="text-xs font-medium text-success">
                                                            #{{ $row->rownum_revenue }}
                                                        </span>
                                                    </td>

                                                </tr>
                                            @endforeach
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <th>Rang</th>
                                                <th>SKU</th>
                                                <th>EAN</th>
                                                <th>Produit</th>
                                                <th>Prix</th>
                                                <th>Qté vendue</th>
                                                <th>CA total</th>
                                                <th>Coût</th>
                                                @foreach($this->sites as $site)
                                                    <th class="text-right">{{ $site->name }}</th>
                                                @endforeach
                                                <th class="text-right">Prix marché</th>
                                                <th>Rang Qté</th>
                                                <th>Rang CA</th>
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
