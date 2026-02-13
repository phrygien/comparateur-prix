<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;

new class extends Component {

    public string $activeCountry = 'FR';
    public string $dateFrom      = '';
    public string $dateTo        = '';

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

    public function with(): array
    {
        $dateFrom = ($this->dateFrom ?: date('Y-01-01')) . ' 00:00:00';
        $dateTo   = ($this->dateTo   ?: date('Y-12-31')) . ' 23:59:59';

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

        $sales = DB::connection('mysqlMagento')
            ->select($sql, [$dateFrom, $dateTo, $this->activeCountry]);

        return compact('sales');
    }
}; ?>

<div class="min-h-screen bg-gray-50">

    {{-- ─── Loading Overlay ──────────────────────────────────── --}}
    <div
        wire:loading
        wire:target="setCountry, dateFrom, dateTo"
        class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-4 bg-white/60 backdrop-blur-sm"
    >
        <svg class="animate-spin h-10 w-10 text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
        </svg>
        <span class="text-sm font-medium text-indigo-600 tracking-wide">Chargement en cours…</span>
    </div>

    {{-- ─── Date Filters ─────────────────────────────────────── --}}
    <div class="bg-white border-b border-gray-200 px-4 sm:px-6 lg:px-8 py-4">
        <div class="flex flex-wrap items-end gap-4">
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
    </div>

    {{-- ─── Country Tabs ──────────────────────────────────────── --}}
    <div class="bg-white border-b border-gray-200 px-4 sm:px-6 lg:px-8">

        {{-- Mobile select --}}
        <div class="grid grid-cols-1 sm:hidden py-3">
            <select
                aria-label="Sélectionner un pays"
                @change="$wire.setCountry($event.target.value)"
                class="col-start-1 row-start-1 w-full appearance-none rounded-md bg-white py-2 pr-8 pl-3
                       text-base text-gray-900 outline outline-1 -outline-offset-1 outline-gray-300
                       focus:outline-2 focus:-outline-offset-2 focus:outline-indigo-600"
            >
                @foreach($countries as $code => $label)
                    <option value="{{ $code }}" {{ $activeCountry === $code ? 'selected' : '' }}>{{ $label }}</option>
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
                @php $isActive = $activeCountry === $code; @endphp
                <button
                    type="button"
                    @click="$wire.setCountry('{{ $code }}')"
                    class="rounded-t-md px-4 py-2.5 text-sm font-medium transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-indigo-500 {{ $isActive ? 'bg-indigo-600 text-white shadow-sm' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-100' }}"
                    @if($isActive) aria-current="page" @endif
                >
                    {{ $label }}
                    <span class="ml-1 text-xs opacity-70">({{ $code }})</span>
                </button>
            @endforeach
        </nav>
    </div>

    {{-- ─── Table ─────────────────────────────────────────────── --}}
    <div class="px-4 sm:px-6 lg:px-8 py-8" wire:loading.remove wire:target="setCountry, dateFrom, dateTo">

        <div class="sm:flex sm:items-center mb-6">
            <div class="sm:flex-auto">
                <h1 class="text-base font-semibold text-gray-900">
                    Ventes — {{ $countries[$activeCountry] ?? $activeCountry }}
                </h1>
                <p class="mt-1 text-sm text-gray-500">
                    Top 100 produits du {{ $dateFrom ?: date('Y-01-01') }} au {{ $dateTo ?: date('Y-12-31') }}
                </p>
            </div>
            <div class="mt-3 sm:mt-0 sm:ml-4 text-sm text-gray-500">
                {{ count($sales) }} résultat(s)
            </div>
        </div>

        @if(count($sales) === 0)
            <div class="text-center py-16 text-gray-400 text-sm">
                Aucune vente trouvée pour cette période et ce pays.
            </div>
        @else

            <div class="overflow-x-auto rounded-lg border border-gray-200">
                <table class="table table-xs w-full">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Produit</th>
                            <th class="text-right">Prix</th>
                            <th class="text-right">Prix spécial</th>
                            <th class="text-right">Coût</th>
                            <th class="text-right">PVC</th>
                            <th class="text-right">Qté vendue</th>
                            <th class="text-right">CA total</th>
                            <th class="text-center text-indigo-600">Rang Qté</th>
                            <th class="text-center text-emerald-600">Rang CA</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($sales as $row)
                            <tr class="hover">
                                <td class="font-mono">{{ $row->sku }}</td>
                                <td class="max-w-[200px] truncate" title="{{ $row->title }}">{{ $row->title ?? '—' }}</td>
                                <td class="text-right">{{ $row->price ? number_format($row->price, 2, ',', ' ') . ' €' : '—' }}</td>
                                <td class="text-right {{ $row->special_price ? 'text-green-600 font-medium' : 'text-gray-400' }}">
                                    {{ $row->special_price ? number_format($row->special_price, 2, ',', ' ') . ' €' : '—' }}
                                </td>
                                <td class="text-right">{{ $row->cost ? number_format($row->cost, 2, ',', ' ') . ' €' : '—' }}</td>
                                <td class="text-right">{{ $row->pvc ? number_format($row->pvc, 2, ',', ' ') . ' €' : '—' }}</td>
                                <td class="text-right font-semibold">{{ number_format($row->total_qty_sold, 0, ',', ' ') }}</td>
                                <td class="text-right font-semibold">{{ number_format($row->total_revenue, 2, ',', ' ') }} €</td>
                                <td class="text-center">
                                    <span class="badge badge-soft badge-primary badge-sm"># {{ $row->rownum_qty }}</span>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-soft badge-success badge-sm"># {{ $row->rownum_revenue }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <th>SKU</th>
                            <th>Produit</th>
                            <th class="text-right">Prix</th>
                            <th class="text-right">Prix spécial</th>
                            <th class="text-right">Coût</th>
                            <th class="text-right">PVC</th>
                            <th class="text-right">Qté vendue</th>
                            <th class="text-right">CA total</th>
                            <th class="text-center text-indigo-600">Rang Qté</th>
                            <th class="text-center text-emerald-600">Rang CA</th>
                        </tr>
                    </tfoot>
                </table>
            </div>

        @endif
    </div>
</div>
