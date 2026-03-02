<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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

            foreach ($response['results'] ?? [] as $row) {
                $data     = $row['bestSellersProductClusterView'] ?? [];
                $rank     = isset($data['rank'])         ? (int) $data['rank']         : null;
                $prevRank = isset($data['previousRank']) ? (int) $data['previousRank'] : null;
                $delta    = ($rank !== null && $prevRank !== null) ? ($prevRank - $rank) : null;

                $ranks[] = [
                    'rank'            => $rank,
                    'previous_rank'   => $prevRank,
                    'delta'           => $delta,
                    'delta_sign'      => match(true) {
                        $delta === null => null,
                        $delta > 0      => '+',
                        $delta < 0      => '-',
                        default         => '=',
                    },
                    'relative_demand' => $data['relativeDemand'] ?? null,
                    'title'           => $data['title']          ?? null,
                    'brand'           => $data['brand']          ?? null,
                    'ean_list'        => $data['variantGtins']   ?? null
                ];
            }

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

                    {{-- Spinner changement de pays --}}
                    <div wire:loading wire:target="activeCountry"
                        class="flex flex-col items-center justify-center gap-3 py-16">
                        <span class="loading loading-spinner loading-lg text-primary"></span>
                        <span class="text-sm font-medium">Chargement des données pour {{ $label }}…</span>
                    </div>

                    <div wire:loading.remove wire:target="activeCountry">

                        {{-- Barre d'outils --}}
                        <div class="flex flex-wrap items-center justify-between gap-4 mb-4 mt-6">

                            {{-- Filtre période --}}
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-gray-400">Période</span>
                                @foreach($period as $label => $value)
                                    <button type="button"
                                        wire:click="$set('activePeriod', '{{ $value }}')"
                                        class="btn btn-xs {{ $activePeriod === $value ? 'bg-orange-900 text-white' : 'btn-outline' }}">
                                        {{ $label }}
                                    </button>
                                @endforeach
                            </div>

                            <div class="divider divider-horizontal mx-0"></div>

                            {{-- Date selon période --}}
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

                            {{-- Rafraîchir --}}
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

                        {{-- Barre pagination --}}
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

                        {{-- Tableau --}}
                        <div class="relative">

                            <div wire:loading wire:target="activePeriod, MondayWeekly, dateMonthly, perPage, setPage, clearCache"
                                class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-3 rounded-lg bg-white/70 backdrop-blur-sm">
                                <span class="loading loading-spinner loading-lg text-primary"></span>
                                <span class="text-sm font-medium">Mise à jour…</span>
                            </div>

                            @if(count($popularityRanks) === 0)
                                <div class="alert alert-info">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
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
                                                <th>Brand</th>
                                                <th>Titre</th>
                                                <th>Ean</th>
                                                <th class="text-center">Demande relative</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($popularityRanks as $item)
                                                <tr class="hover">

                                                    {{-- Rang + delta --}}
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

                                                    {{-- Brand --}}
                                                    <td class="font-semibold">
                                                        {{ $item['brand'] ?? '—' }}
                                                    </td>

                                                    {{-- Titre --}}
                                                    <td class="font-bold max-w-xs truncate">
                                                        {{ $item['title'] ?? '—' }}
                                                    </td>

                                                    {{-- EANs --}}
                                                    <td>
                                                        @if($item['ean_list'] != null)
                                                            <table>
                                                                <tbody>
                                                                    @foreach($item['ean_list'] as $ean14)
                                                                        <tr>
                                                                            <td>{{ $ean14 }}</td>
                                                                        </tr>
                                                                    @endforeach
                                                                </tbody>
                                                            </table>
                                                        @endif
                                                    </td>

                                                    {{-- Demande relative --}}
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
                                                <th>Brand</th>
                                                <th>Titre</th>
                                                <th>Ean</th>
                                                <th class="text-center">Demande relative</th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            @endif
                        </div>

                        {{-- Pagination bas de page --}}
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
