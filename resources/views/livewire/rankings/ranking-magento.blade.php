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

        return compact('sales');
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
                    class="input input-bordered input-sm w-full max-w-xs"
                />
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Date de fin</label>
                <input
                    type="date"
                    wire:model.live="dateTo"
                    class="input input-bordered input-sm w-full max-w-xs"
                />
            </div>
        </div>

        {{-- Tabs pays --}}
        <div>
            {{-- Mobile select --}}
            <div class="sm:hidden">
                <select
                    aria-label="Sélectionner un pays"
                    @change="$wire.setCountry($event.target.value)"
                    class="select select-bordered w-full"
                >
                    @foreach($countries as $code => $label)
                        <option value="{{ $code }}" {{ $activeCountry === $code ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Desktop tabs --}}
            <div class="hidden sm:block">
                <div class="tabs tabs-boxed">
                    @foreach($countries as $code => $label)
                        @php $isActive = $activeCountry === $code; @endphp
                        <button
                            type="button"
                            @click="$wire.setCountry('{{ $code }}')"
                            class="tab {{ $isActive ? 'tab-active' : '' }}"
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
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

                <span class="text-xs text-gray-400 ml-2">{{ count($sales) }} résultat(s)</span>
            </div>
        </div>

        {{-- Table wrapper --}}
        <div class="relative">

            {{-- Spinner overlay --}}
            <div
                wire:loading
                wire:target="setCountry, dateFrom, dateTo, sortBy"
                class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-3 rounded-lg bg-white/70 backdrop-blur-sm"
            >
                <span class="loading loading-spinner loading-lg text-primary"></span>
                <span class="text-sm font-medium">Chargement en cours…</span>
            </div>

            @if(count($sales) === 0)
                <div class="alert alert-info">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="stroke-current shrink-0 w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span>Aucune vente trouvée pour cette période et ce pays.</span>
                </div>
            @else
                <div 
                    class="overflow-x-auto"
                    wire:loading.class="opacity-40 pointer-events-none"
                    wire:target="setCountry, dateFrom, dateTo, sortBy"
                >
                    <table class="table table-xs table-pin-rows table-pin-cols">
                        <thead>
                            <tr>
                                <th>Rang</th>
                                <th>SKU</th>
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
                                <th>Rangs</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($sales as $row)
                                <tr class="hover">
                                    
                                    {{-- Rang --}}
                                    <th>
                                        <div class="badge {{ $sortBy === 'rownum_qty' ? 'badge-primary' : 'badge-success' }} gap-2">
                                            #{{ $sortBy === 'rownum_qty' ? $row->rownum_qty : $row->rownum_revenue }}
                                        </div>
                                    </th>

                                    {{-- SKU --}}
                                    <td>
                                        <span class="font-mono text-xs">{{ $row->sku }}</span>
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
                                        <div>
                                            <span class="font-semibold {{ $sortBy === 'rownum_revenue' ? 'text-success' : '' }}">
                                                {{ number_format($row->total_revenue, 2, ',', ' ') }} €
                                            </span>
                                            @if($row->cost)
                                                <br>
                                                <span class="text-xs text-gray-500">
                                                    coût: {{ number_format($row->cost, 2, ',', ' ') }} €
                                                </span>
                                            @endif
                                        </div>
                                    </td>

                                    {{-- Rangs --}}
                                    <td>
                                        <div class="text-xs space-y-1">
                                            <div class="badge badge-primary badge-xs">
                                                Qté #{{ $row->rownum_qty }}
                                            </div>
                                            <br>
                                            <div class="badge badge-success badge-xs">
                                                CA #{{ $row->rownum_revenue }}
                                            </div>
                                        </div>
                                    </td>

                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <th>Rang</th>
                                <th>SKU</th>
                                <th>Produit</th>
                                <th>Prix</th>
                                <th>Qté vendue</th>
                                <th>CA total</th>
                                <th>Rangs</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>