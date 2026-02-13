<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;

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

    public function setCountry(string $country): void
    {
        $this->activeCountry = $country;
    }

    public function sortBy(string $column): void
    {
        $this->sortBy = $column;
    }

    public function with(): array
    {
        $dateFrom = ($this->dateFrom ?: date('Y-01-01')) . ' 00:00:00';
        $dateTo   = ($this->dateTo   ?: date('Y-12-31')) . ' 23:59:59';

        $orderCol = $this->sortBy === 'rownum_revenue' ? 'total_revenue' : 'total_qty_sold';

        $sql = "
            WITH sales AS (
                SELECT
                    addr.country_id AS country,
                    oi.sku,
                    CAST(product_char.name AS CHAR CHARACTER SET utf8mb4) AS title,
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
                GROUP BY oi.sku, oi.name, addr.country_id
            )
            SELECT
                *,
                ROW_NUMBER() OVER (ORDER BY total_qty_sold DESC) AS rownum_qty,
                ROW_NUMBER() OVER (ORDER BY total_revenue DESC) AS rownum_revenue
            FROM sales
            ORDER BY {$orderCol} DESC
            LIMIT 100
        ";

        $sales = DB::connection('mysqlMagento')
            ->select($sql, [$dateFrom, $dateTo, $this->activeCountry]);

        // Définition des headers pour le tableau
        $headers = [
            ['key' => 'rank', 'label' => 'Rang', 'class' => 'w-20'],
            ['key' => 'sku', 'label' => 'SKU', 'class' => 'font-mono'],
            ['key' => 'title', 'label' => 'Produit'],
            ['key' => 'price', 'label' => 'Prix', 'class' => 'text-right'],
            ['key' => 'total_qty_sold', 'label' => 'Qté vendue', 'class' => 'text-right'],
            ['key' => 'total_revenue', 'label' => 'CA total', 'class' => 'text-right'],
            ['key' => 'ranks', 'label' => 'Rangs', 'class' => 'text-right'],
        ];

        return compact('sales', 'headers');
    }
}; ?>

<div>

    {{-- ─── Header : dates + tabs ───────────────────────────── --}}
    <div class="px-4 sm:px-6 lg:px-8 pt-6 pb-2">

        {{-- Filtres de date --}}
        <div class="flex flex-wrap items-end gap-4 mb-6">
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Date de début</label>
                <input
                    type="date"
                    wire:model.live="dateFrom"
                    class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-900
                           shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                />
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Date de fin</label>
                <input
                    type="date"
                    wire:model.live="dateTo"
                    class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-900
                           shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                />
            </div>
        </div>

        {{-- Tabs pays --}}
        <div>
            {{-- Mobile select --}}
            <div class="grid grid-cols-1 sm:hidden">
                <select
                    aria-label="Sélectionner un pays"
                    @change="$wire.setCountry($event.target.value)"
                    class="col-start-1 row-start-1 w-full appearance-none rounded-md bg-white py-2 pr-8 pl-3 text-base text-gray-900 outline-1 -outline-offset-1 outline-gray-300 focus:outline-2 focus:-outline-offset-2 focus:outline-indigo-600"
                >
                    @foreach($countries as $code => $label)
                        <option value="{{ $code }}" {{ $activeCountry === $code ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                <svg class="pointer-events-none col-start-1 row-start-1 mr-2 size-5 self-center justify-self-end fill-gray-500" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M4.22 6.22a.75.75 0 0 1 1.06 0L8 8.94l2.72-2.72a.75.75 0 1 1 1.06 1.06l-3.25 3.25a.75.75 0 0 1-1.06 0L4.22 7.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                </svg>
            </div>

            {{-- Desktop tabs --}}
            <div class="hidden sm:block">
                <nav class="flex space-x-4" aria-label="Pays">
                    @foreach($countries as $code => $label)
                        @php $isActive = $activeCountry === $code; @endphp
                        <button
                            type="button"
                            @click="$wire.setCountry('{{ $code }}')"
                            class="rounded-md px-3 py-2 text-sm font-medium transition-colors duration-150
                                   {{ $isActive ? 'bg-gray-100 text-gray-700' : 'text-gray-500 hover:text-gray-700' }}"
                            @if($isActive) aria-current="page" @endif
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </nav>
            </div>
        </div>
    </div>

    {{-- ─── Tableau ──────────────────────────────────────────────── --}}
    <div class="px-4 sm:px-6 lg:px-8 py-6">

        {{-- Toolbar : titre + compteur + tri --}}
        <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
            <div>
                <h1 class="text-base font-semibold text-gray-900">
                    Ventes — {{ $countries[$activeCountry] ?? $activeCountry }}
                </h1>
                <p class="mt-0.5 text-sm text-gray-500">
                    Top 100 produits · {{ $dateFrom ?: date('Y-01-01') }} → {{ $dateTo ?: date('Y-12-31') }}
                </p>
            </div>

            {{-- Boutons de tri --}}
            <div class="flex items-center gap-2">
                <span class="text-xs text-gray-400 mr-1">Trier par</span>
                <button
                    type="button"
                    @click="$wire.sortBy('rownum_qty')"
                    class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium ring-1 ring-inset transition-colors
                           {{ $sortBy === 'rownum_qty'
                               ? 'bg-indigo-50 text-indigo-700 ring-indigo-200'
                               : 'bg-white text-gray-500 ring-gray-200 hover:text-gray-700 hover:bg-gray-50' }}"
                >
                    @if($sortBy === 'rownum_qty')
                        <svg class="w-3 h-3" viewBox="0 0 16 16" fill="currentColor"><path d="M8 12L4 6h8z"/></svg>
                    @endif
                    Qté vendue
                </button>
                <button
                    type="button"
                    @click="$wire.sortBy('rownum_revenue')"
                    class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium ring-1 ring-inset transition-colors
                           {{ $sortBy === 'rownum_revenue'
                               ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
                               : 'bg-white text-gray-500 ring-gray-200 hover:text-gray-700 hover:bg-gray-50' }}"
                >
                    @if($sortBy === 'rownum_revenue')
                        <svg class="w-3 h-3" viewBox="0 0 16 16" fill="currentColor"><path d="M8 12L4 6h8z"/></svg>
                    @endif
                    CA total
                </button>

                <span class="text-xs text-gray-400 ml-2">{{ count($sales) }} résultat(s)</span>
            </div>
        </div>

        {{-- Tableau avec x-table --}}
        <div class="relative">
            {{-- Spinner overlay --}}
            <div
                wire:loading
                wire:target="setCountry, dateFrom, dateTo, sortBy"
                class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-3 rounded-xl bg-white/70 backdrop-blur-sm"
            >
                <svg class="animate-spin h-8 w-8 text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                </svg>
                <span class="text-sm font-medium text-indigo-600">Chargement en cours…</span>
            </div>

            @if(count($sales) === 0)
                <div class="text-center py-16 text-gray-400 text-sm">
                    Aucune vente trouvée pour cette période et ce pays.
                </div>
            @else
                <x-table :headers="$headers" :rows="$sales" striped>
                    
                    {{-- Colonne Rang personnalisée --}}
                    @scope('cell_rank', $row)
                        <div class="flex items-center justify-center w-10 h-10 rounded-full
                                    {{ $sortBy === 'rownum_qty' ? 'bg-indigo-50' : 'bg-emerald-50' }}">
                            <span class="text-xs font-bold
                                         {{ $sortBy === 'rownum_qty' ? 'text-indigo-600' : 'text-emerald-600' }}">
                                #{{ $sortBy === 'rownum_qty' ? $row->rownum_qty : $row->rownum_revenue }}
                            </span>
                        </div>
                    @endscope

                    {{-- Colonne SKU --}}
                    @scope('cell_sku', $row)
                        <span class="font-mono text-xs">{{ $row->sku }}</span>
                    @endscope

                    {{-- Colonne Produit --}}
                    @scope('cell_title', $row)
                        <div>
                            <p class="font-semibold text-gray-900 truncate" title="{{ $row->title }}">
                                {{ $row->title ?? '—' }}
                            </p>
                        </div>
                    @endscope

                    {{-- Colonne Prix --}}
                    @scope('cell_price', $row)
                        @if($row->special_price)
                            <div class="text-right">
                                <span class="text-green-600 font-medium">
                                    {{ number_format($row->special_price, 2, ',', ' ') }} €
                                </span>
                                <br>
                                <span class="text-xs text-gray-400 line-through">
                                    {{ number_format($row->price, 2, ',', ' ') }} €
                                </span>
                            </div>
                        @elseif($row->price)
                            <span class="text-right">{{ number_format($row->price, 2, ',', ' ') }} €</span>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    @endscope

                    {{-- Colonne Quantité vendue --}}
                    @scope('cell_total_qty_sold', $row)
                        <span class="font-semibold {{ $sortBy === 'rownum_qty' ? 'text-indigo-600' : 'text-gray-900' }}">
                            {{ number_format($row->total_qty_sold, 0, ',', ' ') }}
                        </span>
                    @endscope

                    {{-- Colonne CA total --}}
                    @scope('cell_total_revenue', $row)
                        <div class="text-right">
                            <span class="font-semibold {{ $sortBy === 'rownum_revenue' ? 'text-emerald-600' : 'text-gray-900' }}">
                                {{ number_format($row->total_revenue, 2, ',', ' ') }} €
                            </span>
                            @if($row->cost)
                                <br>
                                <span class="text-xs text-gray-500">
                                    coût: {{ number_format($row->cost, 2, ',', ' ') }} €
                                </span>
                            @endif
                        </div>
                    @endscope

                    {{-- Colonne Rangs --}}
                    @scope('cell_ranks', $row)
                        <div class="text-xs text-gray-500 text-right space-y-1">
                            <div>
                                Qté: <span class="font-medium text-indigo-500">#{{ $row->rownum_qty }}</span>
                            </div>
                            <div>
                                CA: <span class="font-medium text-emerald-500">#{{ $row->rownum_revenue }}</span>
                            </div>
                        </div>
                    @endscope

                </x-table>
            @endif
        </div>
    </div>
</div>