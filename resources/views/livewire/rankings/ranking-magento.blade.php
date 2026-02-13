<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;

new class extends Component {

    public string $activeCountry = 'FR';
    public string $dateFrom = '';
    public string $dateTo = '';
    public array $sales = [];

    // Top 5 countries by order volume (from the provided list)
    public array $countries = [
        'FR' => 'France',
        'BE' => 'Belgique',
        'ES' => 'Espagne',
        'IT' => 'Italie',
        'DE' => 'Allemagne',
    ];

    public function mount(): void
    {
        $this->dateFrom = date('Y-01-01');
        $this->dateTo   = date('Y-12-31');
        $this->loadSales();
    }

    public function setCountry(string $country): void
    {
        $this->activeCountry = $country;
        $this->loadSales();
    }

    public function applyFilters(): void
    {
        $this->loadSales();
    }

    public function loadSales(): void
    {
        $dateFrom = $this->dateFrom ? $this->dateFrom . ' 00:00:00' : '2025-01-01 00:00:00';
        $dateTo   = $this->dateTo   ? $this->dateTo   . ' 23:59:59' : '2025-12-31 23:59:59';
        $country  = $this->activeCountry;

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
            ORDER BY total_qty_sold DESC
            LIMIT 100
        ";

        $this->sales = DB::connection('mysqlMagento')
            ->select($sql, [$dateFrom, $dateTo, $country]);
    }
}; ?>

<div class="min-h-screen bg-gray-50 max-w-7xl mx-auto">

    {{-- ─── Date Filters ─────────────────────────────────────── --}}
    <div class="bg-white border-b border-gray-200 px-4 sm:px-6 lg:px-8 py-4">
        <div class="flex flex-wrap items-end gap-4">
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Date de début</label>
                <input
                    type="date"
                    wire:model="dateFrom"
                    class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-900
                           shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                />
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Date de fin</label>
                <input
                    type="date"
                    wire:model="dateTo"
                    class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-900
                           shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                />
            </div>
            <button
                wire:click="applyFilters"
                wire:loading.attr="disabled"
                class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold
                       text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2
                       focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="applyFilters">Appliquer</span>
                <span wire:loading wire:target="applyFilters" class="flex items-center gap-1">
                    <svg class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                    </svg>
                    Chargement…
                </span>
            </button>
        </div>
    </div>

    {{-- ─── Country Tabs ──────────────────────────────────────── --}}
    <div class="bg-white border-b border-gray-200 px-4 sm:px-6 lg:px-8">

        {{-- Mobile select --}}
        <div class="grid grid-cols-1 sm:hidden py-3">
            <select
                aria-label="Sélectionner un pays"
                wire:change="setCountry($event.target.value)"
                class="col-start-1 row-start-1 w-full appearance-none rounded-md bg-white py-2 pr-8 pl-3
                       text-base text-gray-900 outline outline-1 -outline-offset-1 outline-gray-300
                       focus:outline-2 focus:-outline-offset-2 focus:outline-indigo-600"
            >
                @foreach($countries as $code => $label)
                    <option value="{{ $code }}" @selected($activeCountry === $code)>{{ $label }}</option>
                @endforeach
            </select>
            <svg class="pointer-events-none col-start-1 row-start-1 mr-2 size-5 self-center justify-self-end fill-gray-500"
                 viewBox="0 0 16 16" aria-hidden="true">
                <path fill-rule="evenodd"
                      d="M4.22 6.22a.75.75 0 0 1 1.06 0L8 8.94l2.72-2.72a.75.75 0 1 1 1.06 1.06l-3.25 3.25a.75.75 0 0 1-1.06 0L4.22 7.28a.75.75 0 0 1 0-1.06Z"
                      clip-rule="evenodd" />
            </svg>
        </div>

        {{-- Desktop tabs --}}
        <nav class="hidden sm:flex space-x-1 pt-3" aria-label="Pays">
            @foreach($countries as $code => $label)
                <button
                    wire:click="setCountry('{{ $code }}')"
                    class="rounded-t-md px-4 py-2.5 text-sm font-medium transition-colors duration-150
                           focus:outline-none focus:ring-2 focus:ring-inset focus:ring-indigo-500
                           {{ $activeCountry === $code
                               ? 'bg-indigo-600 text-white shadow-sm'
                               : 'text-gray-500 hover:text-gray-700 hover:bg-gray-100' }}"
                    @if($activeCountry === $code) aria-current="page" @endif
                >
                    {{ $label }}
                    <span class="ml-1 text-xs opacity-70">({{ $code }})</span>
                </button>
            @endforeach
        </nav>
    </div>

    {{-- ─── Table ─────────────────────────────────────────────── --}}
    <div class="px-4 sm:px-6 lg:px-8 py-8">

        <div class="sm:flex sm:items-center mb-6">
            <div class="sm:flex-auto">
                <h1 class="text-base font-semibold text-gray-900">
                    Ventes — {{ $countries[$activeCountry] ?? $activeCountry }}
                </h1>
                <p class="mt-1 text-sm text-gray-500">
                    Top 100 produits du {{ $dateFrom }} au {{ $dateTo }}
                </p>
            </div>
            <div class="mt-3 sm:mt-0 sm:ml-4 text-sm text-gray-500">
                {{ count($sales) }} résultat(s)
            </div>
        </div>

        <div wire:loading wire:target="setCountry, applyFilters"
             class="flex justify-center items-center py-16">
            <svg class="animate-spin h-8 w-8 text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
            </svg>
        </div>

        <div wire:loading.remove wire:target="setCountry, applyFilters"
             class="flow-root">
            <div class="-mx-4 -my-2 overflow-x-auto sm:-mx-6 lg:-mx-8">
                <div class="inline-block min-w-full py-2 align-middle">

                    @if(count($sales) === 0)
                        <div class="text-center py-16 text-gray-400 text-sm">
                            Aucune vente trouvée pour cette période et ce pays.
                        </div>
                    @else
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="py-3 pl-4 pr-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 sm:pl-6 lg:pl-8">#</th>
                                    <th class="py-3 px-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">SKU</th>
                                    <th class="py-3 px-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Produit</th>
                                    <th class="py-3 px-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Prix</th>
                                    <th class="py-3 px-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Prix spécial</th>
                                    <th class="py-3 px-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Coût</th>
                                    <th class="py-3 px-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">PVC</th>
                                    <th class="py-3 px-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Qté vendue</th>
                                    <th class="py-3 px-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">CA total</th>
                                    <th class="py-3 pl-3 pr-4 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 sm:pr-6 lg:pr-8">Rang qté / CA</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @foreach($sales as $row)
                                    <tr class="hover:bg-gray-50 transition-colors duration-100">
                                        <td class="py-3 pl-4 pr-3 text-sm text-gray-400 sm:pl-6 lg:pl-8">
                                            {{ $loop->iteration }}
                                        </td>
                                        <td class="px-3 py-3 text-sm font-mono text-gray-600 whitespace-nowrap">
                                            {{ $row->sku }}
                                        </td>
                                        <td class="px-3 py-3 text-sm text-gray-900 max-w-xs truncate" title="{{ $row->title }}">
                                            {{ $row->title ?? '—' }}
                                        </td>
                                        <td class="px-3 py-3 text-sm text-gray-600 text-right whitespace-nowrap">
                                            {{ $row->price ? number_format($row->price, 2, ',', ' ') . ' €' : '—' }}
                                        </td>
                                        <td class="px-3 py-3 text-sm text-right whitespace-nowrap
                                                   {{ $row->special_price ? 'text-green-600 font-medium' : 'text-gray-400' }}">
                                            {{ $row->special_price ? number_format($row->special_price, 2, ',', ' ') . ' €' : '—' }}
                                        </td>
                                        <td class="px-3 py-3 text-sm text-gray-600 text-right whitespace-nowrap">
                                            {{ $row->cost ? number_format($row->cost, 2, ',', ' ') . ' €' : '—' }}
                                        </td>
                                        <td class="px-3 py-3 text-sm text-gray-600 text-right whitespace-nowrap">
                                            {{ $row->pvc ? number_format($row->pvc, 2, ',', ' ') . ' €' : '—' }}
                                        </td>
                                        <td class="px-3 py-3 text-sm font-semibold text-indigo-700 text-right whitespace-nowrap">
                                            {{ number_format($row->total_qty_sold, 0, ',', ' ') }}
                                        </td>
                                        <td class="px-3 py-3 text-sm font-semibold text-gray-900 text-right whitespace-nowrap">
                                            {{ number_format($row->total_revenue, 2, ',', ' ') }} €
                                        </td>
                                        <td class="pl-3 pr-4 py-3 text-right text-xs text-gray-400 whitespace-nowrap sm:pr-6 lg:pr-8">
                                            <span class="inline-flex items-center gap-1">
                                                <span title="Rang par quantité" class="bg-indigo-50 text-indigo-600 rounded px-1.5 py-0.5 font-medium">
                                                    #{{ $row->rownum_qty }}
                                                </span>
                                                <span class="text-gray-300">/</span>
                                                <span title="Rang par CA" class="bg-emerald-50 text-emerald-600 rounded px-1.5 py-0.5 font-medium">
                                                    #{{ $row->rownum_revenue }}
                                                </span>
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>