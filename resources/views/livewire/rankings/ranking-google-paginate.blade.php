<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Services\GoogleMerchantService;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public int    $perPage       = 25;
    public int    $currentPage   = 1;
    public string $activeCountry = 'FR';
    public string $activePeriod  = 'WEEKLY';
    public string $MondayWeekly  = '2026-01-19';
    public string $dateMonthly   = '2026-01-01';

    public array $countries = [
        'FR' => 'France',
        'BE' => 'Belgique',
        'NL' => 'Pays-Bas',
        'DE' => 'Allemagne',
        'ES' => 'Espagne',
        'IT' => 'Italie',
    ];

    protected array $countryCodeMap = [
        'FR' => 'FR',
        'BE' => 'BE',
        'NL' => 'NL',
        'DE' => 'DE',
        'ES' => 'ES',
        'IT' => 'IT',
    ];

    public array $period = [
        'Hebdomadaire' => 'WEEKLY',
        'Mensuel'      => 'MONTHLY',
    ];

    public array $periodCodeMap = [
        'WEEKLY'  => 'WEEKLY',
        'MONTHLY' => 'MONTHLY',
    ];

    protected GoogleMerchantService $googleMerchantService;

    public function boot(GoogleMerchantService $googleMerchantService): void
    {
        $this->googleMerchantService = $googleMerchantService;
    }

    protected function getMagentoProductsByEans(array $eanList): array
    {
        dd($eanList);
        if (empty($eanList)) {
            return [];
        }

        $eanList      = array_values(array_unique($eanList));
        $placeholders = implode(',', array_fill(0, count($eanList), '?'));

        $query = "
            SELECT
                produit.entity_id                                        AS id,
                produit.sku                                              AS sku,
                product_char.reference                                   AS parkode,
                CAST(product_char.name AS CHAR CHARACTER SET utf8mb4)    AS title,
                product_char.ean                                         AS ean,
                ROUND(product_decimal.price, 2)                          AS price,
                ROUND(product_decimal.special_price, 2)                  AS special_price,
                ROUND(product_decimal.cost, 2)                           AS cost,
                stock_item.qty                                           AS quantity,
                stock_status.stock_status                                AS stock_status,
                product_int.status                                       AS status
            FROM catalog_product_entity AS produit
            LEFT JOIN product_char
                ON product_char.entity_id    = produit.entity_id
            LEFT JOIN product_decimal
                ON product_decimal.entity_id = produit.entity_id
            LEFT JOIN product_int
                ON product_int.entity_id     = produit.entity_id
            LEFT JOIN cataloginventory_stock_item AS stock_item
                ON stock_item.product_id     = produit.entity_id
            LEFT JOIN cataloginventory_stock_status AS stock_status
                ON stock_status.product_id   = produit.entity_id
            WHERE product_char.ean IN ({$placeholders})
        ";

        try {
            $results = DB::connection('mysqlMagento')->select($query, $eanList);

            $indexed = [];
            foreach ($results as $row) {
                $indexed[(string) $row->ean] = (array) $row;
            }

            return $indexed;

        } catch (\Exception $e) {
            Log::error('Magento EAN lookup error: ' . $e->getMessage());
            return [];
        }
    }

    public function getPopularityRanksAllProperty(): array
    {
        $countryCode = $this->countryCodeMap[$this->activeCountry] ?? $this->activeCountry;
        $periodCode  = $this->periodCodeMap[$this->activePeriod]   ?? $this->activePeriod;
        $date        = $periodCode === 'WEEKLY' ? $this->MondayWeekly : $this->dateMonthly;

        $query = "
            SELECT
                report_granularity,
                report_date,
                report_category_id,
                category_l1,
                category_l2,
                category_l3,
                brand,
                title,
                variant_gtins,
                rank,
                previous_rank,
                report_country_code,
                relative_demand,
                previous_relative_demand,
                relative_demand_change,
                inventory_status,
                brand_inventory_status
            FROM best_sellers_product_cluster_view
            WHERE report_country_code = '{$countryCode}'
                AND report_granularity = '{$periodCode}'
                AND category_l1 LIKE '%Health & Beauty%'
                AND report_date = '{$date}'
            ORDER BY rank ASC
            LIMIT 1000
        ";

        try {
            $response = $this->googleMerchantService->searchReports($query);

            Log::info('Google Merchant raw response', ['response' => $response]);

            $ranks = [];

            $normalizeGtin = function (string $gtin): string {
                $gtin = preg_replace('/\D/', '', $gtin);
                if (strlen($gtin) === 14 && $gtin[0] === '0') {
                    return substr($gtin, 1);
                }
                return $gtin;
            };

            foreach ($response['results'] ?? [] as $row) {
                $data     = $row['bestSellersProductClusterView'] ?? [];
                $rank     = isset($data['rank'])         ? (int) $data['rank']         : null;
                $prevRank = isset($data['previousRank']) ? (int) $data['previousRank'] : null;
                $delta    = ($rank !== null && $prevRank !== null) ? ($prevRank - $rank) : null;

                $ranks[] = [
                    'rank'          => $rank,
                    'previous_rank' => $prevRank,
                    'delta'         => $delta,
                    'delta_sign'    => match(true) {
                        $delta === null => null,
                        $delta > 0      => '+',
                        $delta < 0      => '-',
                        default         => '=',
                    },
                    'relative_demand' => $data['relativeDemand'] ?? null,
                    'title'           => $data['title']          ?? null,
                    'brand'           => $data['brand']          ?? null,
                    'ean_list'        => array_map(
                        fn($g) => $normalizeGtin((string) $g),
                        $data['variantGtins'] ?? []
                    ),
                    'magento_products' => [],
                ];
            }

            $allEans = [];
            foreach ($ranks as $item) {
                foreach ($item['ean_list'] as $ean) {
                    if ($ean !== '') {
                        $allEans[] = $ean;
                    }
                }
            }

            $magentoIndex = $this->getMagentoProductsByEans($allEans);

            foreach ($ranks as &$item) {
                $matched = [];
                foreach ($item['ean_list'] as $ean) {
                    if (isset($magentoIndex[$ean])) {
                        $matched[$ean] = $magentoIndex[$ean];
                    }
                }
                $item['magento_products'] = $matched;
            }
            unset($item);

            return $ranks;

        } catch (\Exception $e) {
            Log::error('Google Merchant popularity rank error: ' . $e->getMessage());
            return [];
        }
    }

    public function getPopularityRanksProperty(): array
    {
        return collect($this->popularityRanksAll)
            ->forPage($this->currentPage, $this->perPage)
            ->values()
            ->toArray();
    }

    public function getPopularityTotalProperty(): int
    {
        return count($this->popularityRanksAll);
    }

    public function updatedActiveCountry(): void { $this->currentPage = 1; }
    public function updatedActivePeriod(): void  { $this->currentPage = 1; }
    public function updatedPerPage(): void       { $this->currentPage = 1; }

    public function setPage(int $page): void
    {
        $this->currentPage = $page;
    }

    public function clearCache(): void
    {
        $countryCode = $this->countryCodeMap[$this->activeCountry] ?? $this->activeCountry;
        $periodCode  = $this->periodCodeMap[$this->activePeriod]   ?? $this->activePeriod;
        $date        = $periodCode === 'WEEKLY' ? $this->MondayWeekly : $this->dateMonthly;

        Cache::forget('google_popularity_all_' . md5($countryCode . $periodCode . $date));
    }

    public function with(): array
    {
        $total    = $this->popularityTotal;
        $lastPage = max(1, (int) ceil($total / $this->perPage));

        return [
            'popularityRanks' => $this->popularityRanks,
            'total'           => $total,
            'lastPage'        => $lastPage,
            'currentPage'     => $this->currentPage,
            'perPage'         => $this->perPage,
        ];
    }
}; ?>

<div>
    <div class="px-4 sm:px-6 lg:px-8 pt-6 pb-2">
        <x-tabs wire:model.live="activeCountry">
            @foreach($countries as $code => $label)
                <x-tab name="{{ $code }}" label="{{ $label }}">

                    <div wire:loading wire:target="activeCountry"
                        class="flex flex-col items-center justify-center gap-3 py-16">
                        <span class="loading loading-spinner loading-lg text-primary"></span>
                        <span class="text-sm font-medium">Chargement des données pour {{ $label }}…</span>
                    </div>

                    <div wire:loading.remove wire:target="activeCountry">

                        <div class="flex flex-wrap items-center justify-between gap-4 mb-4 mt-6">

                            <div class="flex items-center gap-2">
                                <span class="text-xs text-gray-400">Période</span>
                                @foreach($period as $periodLabel => $value)
                                    <button type="button"
                                        wire:click="$set('activePeriod', '{{ $value }}')"
                                        class="btn btn-xs {{ $activePeriod === $value ? 'bg-orange-900 text-white' : 'btn-outline' }}">
                                        {{ $periodLabel }}
                                    </button>
                                @endforeach
                            </div>

                            <div class="divider divider-horizontal mx-0"></div>

                            @if($activePeriod === 'WEEKLY')
                                <div class="flex items-center gap-2">
                                    <span class="text-xs text-gray-400">Semaine du lundi</span>
                                    <input type="date" wire:model.live="MondayWeekly"
                                        class="input input-bordered input-sm w-36"/>
                                </div>
                            @else
                                <div class="flex items-center gap-2">
                                    <span class="text-xs text-gray-400">Mois</span>
                                    <input type="date" wire:model.live="dateMonthly"
                                        class="input input-bordered input-sm w-36"/>
                                </div>
                            @endif

                            <div class="divider divider-horizontal mx-0"></div>

                            <button type="button" wire:click="clearCache"
                                wire:loading.attr="disabled"
                                wire:loading.class="opacity-60 cursor-not-allowed"
                                class="btn btn-sm btn-ghost gap-2" title="Vider le cache et recharger">
                                <span wire:loading.remove wire:target="clearCache" class="flex items-center gap-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                    </svg>
                                    Rafraîchir
                                </span>
                                <span wire:loading wire:target="clearCache" class="flex items-center gap-2">
                                    <span class="loading loading-spinner loading-xs"></span>
                                    Rafraîchissement…
                                </span>
                            </button>
                        </div>

                        <div class="flex flex-wrap items-center justify-around gap-4 mb-4">

                            <div class="flex items-center gap-2">
                                <span class="text-xs text-gray-400">Par page</span>
                                <select wire:model.live="perPage" class="select select-sm select-bordered w-20">
                                    <option value="25">25</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                </select>
                            </div>

                            <p class="text-sm text-gray-500">
                                {{ $total }} résultat(s) · Page {{ $currentPage }}/{{ $lastPage }}
                            </p>

                            @if($lastPage > 1)
                                <div class="flex items-center gap-4">
                                    <span class="text-xs text-gray-500">
                                        Affichage
                                        {{ (($currentPage - 1) * $perPage) + 1 }}–{{ min($currentPage * $perPage, $total) }}
                                        sur {{ $total }}
                                    </span>
                                    <div class="join">
                                        <button class="join-item btn btn-sm"
                                            wire:click="setPage(1)"
                                            @disabled($currentPage === 1)>«</button>
                                        <button class="join-item btn btn-sm"
                                            wire:click="setPage({{ $currentPage - 1 }})"
                                            @disabled($currentPage === 1)>‹</button>
                                        @foreach(range(max(1, $currentPage - 2), min($lastPage, $currentPage + 2)) as $p)
                                            <button class="join-item btn btn-sm {{ $p === $currentPage ? 'btn-active btn-primary' : '' }}"
                                                wire:click="setPage({{ $p }})">{{ $p }}</button>
                                        @endforeach
                                        <button class="join-item btn btn-sm"
                                            wire:click="setPage({{ $currentPage + 1 }})"
                                            @disabled($currentPage === $lastPage)>›</button>
                                        <button class="join-item btn btn-sm"
                                            wire:click="setPage({{ $lastPage }})"
                                            @disabled($currentPage === $lastPage)>»</button>
                                    </div>
                                </div>
                            @endif
                        </div>

                        <div class="relative">

                            <div wire:loading wire:target="activePeriod, MondayWeekly, dateMonthly, perPage, setPage, clearCache"
                                class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-3 rounded-lg bg-white/70 backdrop-blur-sm">
                                <span class="loading loading-spinner loading-lg text-primary"></span>
                                <span class="text-sm font-medium">Mise à jour…</span>
                            </div>

                            @if(count($popularityRanks) === 0)
                                <div class="alert alert-info">
                                    <svg xmlns="http:
                                        class="stroke-current shrink-0 w-6 h-6">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    <span>Aucun résultat trouvé pour cette période.</span>
                                </div>
                            @else
                                <div class="overflow-x-auto overflow-y-auto max-h-[70vh]">
                                    <table class="table table-xs table-pin-rows table-pin-cols">
                                        <thead>
                                            <tr>
                                                <th class="text-center w-24">Rang Google</th>
                                                <th>Google Group</th>
                                                <th>Google Titre</th>
                                                <th>EAN</th>
                                                <th class="min-w-[420px]">
                                                    <div class="flex items-center gap-1">
                                                        
                                                        <svg class="w-3.5 h-3.5 text-orange-500" viewBox="0 0 24 24" fill="currentColor">
                                                            <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                                                        </svg>
                                                        Magento
                                                    </div>
                                                </th>
                                                <th class="text-center">Demande relative</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($popularityRanks as $item)
                                                <tr class="hover">

                                                    <td class="text-center">
                                                        <div class="flex flex-col items-center gap-0.5">
                                                            <span class="font-bold font-mono text-sm">
                                                                #{{ number_format($item['rank'], 0, ',', '') }}
                                                            </span>
                                                            @if($item['delta'] !== null)
                                                                <span class="text-xs font-bold
                                                                    {{ $item['delta_sign'] === '+' ? 'text-success' : ($item['delta_sign'] === '-' ? 'text-error' : 'text-gray-400') }}">
                                                                    {{ $item['delta_sign'] === '+' ? '+' : '' }}{{ $item['delta'] }}
                                                                </span>
                                                            @endif
                                                        </div>
                                                    </td>

                                                    <td class="font-semibold">
                                                        {{ $item['brand'] ?? '—' }}
                                                    </td>

                                                    <td class="font-bold max-w-xs truncate">
                                                        {{ $item['title'] ?? '—' }}
                                                    </td>

                                                    <td>
                                                        @if(!empty($item['ean_list']))
                                                            <div class="flex flex-col gap-0.5">
                                                                @foreach($item['ean_list'] as $ean)
                                                                    <span class="font-mono text-xs
                                                                        {{ isset($item['magento_products'][$ean]) ? 'text-success font-semibold' : 'text-gray-400' }}">
                                                                        {{ $ean }}
                                                                        @if(isset($item['magento_products'][$ean]))
                                                                            <span title="Trouvé dans Magento">✓</span>
                                                                        @endif
                                                                    </span>
                                                                @endforeach
                                                            </div>
                                                        @else
                                                            <span class="text-gray-300">—</span>
                                                        @endif
                                                    </td>

                                                    <td>
                                                        @if(!empty($item['magento_products']))
                                                            <div class="flex flex-col gap-1">
                                                                @foreach($item['magento_products'] as $ean => $mag)
                                                                    <div class="rounded border border-base-200 bg-base-50 px-2 py-1">
                                                                        <div class="flex items-center justify-between gap-2 flex-wrap">

                                                                            <span class="font-mono text-xs text-gray-500">
                                                                                {{ $mag['sku'] }}
                                                                            </span>

                                                                            <span class="text-xs font-semibold truncate max-w-[180px]" title="{{ $mag['title'] }}">
                                                                                {{ $mag['title'] }}
                                                                            </span>

                                                                            <span class="text-xs whitespace-nowrap">
                                                                                @if(!empty($mag['special_price']))
                                                                                    <span class="line-through text-gray-400 mr-1">
                                                                                        {{ number_format($mag['price'] ?? 0, 2, ',', ' ') }} €
                                                                                    </span>
                                                                                    <span class="text-success font-bold">
                                                                                        {{ number_format($mag['special_price'], 2, ',', ' ') }} €
                                                                                    </span>
                                                                                @else
                                                                                    <span class="font-medium">
                                                                                        {{ number_format($mag['price'] ?? 0, 2, ',', ' ') }} €
                                                                                    </span>
                                                                                @endif
                                                                            </span>

                                                                            <span class="text-xs whitespace-nowrap">
                                                                                @if($mag['quantity'] !== null)
                                                                                    <span class="{{ $mag['quantity'] > 0 ? 'text-success' : 'text-warning' }} font-mono">
                                                                                        {{ number_format($mag['quantity'], 0, ',', ' ') }} u.
                                                                                    </span>
                                                                                @else
                                                                                    <span class="text-gray-300">— u.</span>
                                                                                @endif
                                                                            </span>

                                                                            @if($mag['stock_status'] == 1)
                                                                                <span class="badge badge-success badge-xs">En stock</span>
                                                                            @else
                                                                                <span class="badge badge-error badge-xs">Rupture</span>
                                                                            @endif

                                                                            @if(isset($mag['status']))
                                                                                @if($mag['status'] == 1)
                                                                                    <span class="badge badge-outline badge-xs badge-success">Activé</span>
                                                                                @else
                                                                                    <span class="badge badge-outline badge-xs badge-warning">Désactivé</span>
                                                                                @endif
                                                                            @endif

                                                                        </div>
                                                                    </div>
                                                                @endforeach
                                                            </div>
                                                        @else
                                                            
                                                            <div class="flex items-center gap-1 text-gray-300">
                                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                                        d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                                                                </svg>
                                                                <span class="text-xs italic">Non référencé</span>
                                                            </div>
                                                        @endif
                                                    </td>
                                                    
                                                    <td class="text-center">
                                                        @if($item['relative_demand'])
                                                            <span class="badge badge-ghost badge-sm">
                                                                {{ $item['relative_demand'] }}
                                                            </span>
                                                        @else
                                                            <span class="text-gray-300">—</span>
                                                        @endif
                                                    </td>

                                                </tr>
                                            @endforeach
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <th class="text-center">Rang Google</th>
                                                <th>Google Group</th>
                                                <th>Google Titre</th>
                                                <th>EAN</th>
                                                <th>Magento</th>
                                                <th class="text-center">Demande relative</th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            @endif
                        </div>

                        @if($lastPage > 1)
                            <div class="flex flex-wrap items-center justify-around gap-4 mt-4">
                                <span class="text-xs text-gray-500">
                                    Affichage
                                    {{ (($currentPage - 1) * $perPage) + 1 }}–{{ min($currentPage * $perPage, $total) }}
                                    sur {{ $total }}
                                </span>
                                <div class="join">
                                    <button class="join-item btn btn-sm"
                                        wire:click="setPage(1)"
                                        @disabled($currentPage === 1)>«</button>
                                    <button class="join-item btn btn-sm"
                                        wire:click="setPage({{ $currentPage - 1 }})"
                                        @disabled($currentPage === 1)>‹</button>
                                    @foreach(range(max(1, $currentPage - 2), min($lastPage, $currentPage + 2)) as $p)
                                        <button class="join-item btn btn-sm {{ $p === $currentPage ? 'btn-active btn-primary' : '' }}"
                                            wire:click="setPage({{ $p }})">{{ $p }}</button>
                                    @endforeach
                                    <button class="join-item btn btn-sm"
                                        wire:click="setPage({{ $currentPage + 1 }})"
                                        @disabled($currentPage === $lastPage)>›</button>
                                    <button class="join-item btn btn-sm"
                                        wire:click="setPage({{ $lastPage }})"
                                        @disabled($currentPage === $lastPage)>»</button>
                                </div>
                            </div>
                        @endif

                    </div>
                </x-tab>
            @endforeach
        </x-tabs>
    </div>
</div>
