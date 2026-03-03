<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Services\GoogleMerchantService;
use Livewire\WithPagination;
use App\Models\Site;
use App\Models\Product;

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
                produit.sku                                              AS ean,
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
            WHERE produit.sku IN ({$placeholders})
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

    protected function getScrapedProductsByEans(array $eanList): array
    {
        if (empty($eanList)) {
            return [];
        }

        $eanList = array_values(array_unique($eanList));

        try {
            $results = Product::with(['website' => function($query) {
                    $query->where('country_code', $this->activeCountry);
                }])
                ->whereIn('ean', $eanList)
                ->whereHas('website', function($query) {
                    $query->where('country_code', $this->activeCountry);
                })
                ->get();

            $indexed = [];
            foreach ($results as $product) {
                $ean = (string) $product->ean;
                if (!isset($indexed[$ean])) {
                    $indexed[$ean] = [];
                }
                
                $indexed[$ean][] = [
                    'id' => $product->id,
                    'site_id' => $product->web_site_id,
                    'site_name' => $product->website->name ?? null,
                    'site_country' => $product->website->country_code ?? null,
                    'ean' => $product->ean,
                    'name' => $product->name,
                    'vendor' => $product->vendor,
                    'price' => $product->prix_ht,
                    'currency' => $product->currency,
                    'url' => $product->url,
                    'image_url' => $product->image_url,
                    'type' => $product->type,
                    'variation' => $product->variation,
                    'is_available' => !empty($product->prix_ht) && $product->prix_ht > 0,
                    'last_checked' => $product->updated_at,
                    'created_at' => $product->created_at,
                ];
            }

            return $indexed;

        } catch (\Exception $e) {
            Log::error('Scraped products by EAN lookup error: ' . $e->getMessage());
            return [];
        }
    }

    public function getPopularityRanksAllProperty(): array
    {
        $countryCode = $this->countryCodeMap[$this->activeCountry] ?? $this->activeCountry;
        $periodCode  = $this->periodCodeMap[$this->activePeriod]   ?? $this->activePeriod;
        $date        = $periodCode === 'WEEKLY' ? $this->MondayWeekly : $this->dateMonthly;

        // Vérifier le cache
        $cacheKey = 'google_popularity_all_' . md5($countryCode . $periodCode . $date);
        
        return Cache::remember($cacheKey, now()->addHours(6), function() use ($countryCode, $periodCode, $date) {
            
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
                        'scraped_products' => [],
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
                $allEans = array_unique($allEans);

                $magentoIndex = $this->getMagentoProductsByEans($allEans);
                
                $scrapedIndex = $this->getScrapedProductsByEans($allEans);

                foreach ($ranks as &$item) {
                    $matchedMagento = [];
                    foreach ($item['ean_list'] as $ean) {
                        if (isset($magentoIndex[$ean])) {
                            $matchedMagento[$ean] = $magentoIndex[$ean];
                        }
                    }
                    $item['magento_products'] = $matchedMagento;

                    $matchedScraped = [];
                    foreach ($item['ean_list'] as $ean) {
                        if (isset($scrapedIndex[$ean])) {
                            $matchedScraped[$ean] = $scrapedIndex[$ean];
                        }
                    }
                    $item['scraped_products'] = $matchedScraped;
                }
                unset($item);

                return $ranks;

            } catch (\Exception $e) {
                Log::error('Google Merchant popularity rank error: ' . $e->getMessage());
                return [];
            }
        });
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

    public function updatedActiveCountry(): void 
    { 
        $this->currentPage = 1; 
        $this->clearCache();
    }
    
    public function updatedActivePeriod(): void  
    { 
        $this->currentPage = 1; 
        $this->clearCache();
    }
    
    public function updatedMondayWeekly(): void
    {
        $this->clearCache();
    }
    
    public function updatedDateMonthly(): void
    {
        $this->clearCache();
    }
    
    public function updatedPerPage(): void       
    { 
        $this->currentPage = 1; 
    }

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

    public function getSitesProperty()
    {
        return Site::where('country_code', $this->activeCountry)
            ->orderBy('name')
            ->get();
    }

    public function with(): array
    {
        $total    = $this->popularityTotal;
        $lastPage = max(1, (int) ceil($total / $this->perPage));

        return [
            'sites' => $this->sites,
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

<div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
    <!-- Barre principale avec les contrôles -->
    <div class="p-4 border-b border-gray-200">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <!-- Section période -->
            <div class="flex items-center gap-4">
                <span class="text-sm font-medium text-gray-700">Période</span>
                <div class="flex gap-1 p-1 bg-gray-100 rounded-lg">
                    @foreach($period as $periodLabel => $value)
                        <button type="button"
                            wire:click="$set('activePeriod', '{{ $value }}')"
                            class="px-3 py-1.5 text-sm font-medium rounded-md transition-colors duration-150
                                {{ $activePeriod === $value 
                                    ? 'bg-white text-orange-600 shadow-sm' 
                                    : 'text-gray-600 hover:text-gray-900 hover:bg-white/50' }}">
                            {{ $periodLabel }}
                        </button>
                    @endforeach
                </div>
            </div>

            <!-- Sélecteur de date -->
            <div class="flex items-center gap-3">
                <div class="flex items-center gap-2">
                    @if($activePeriod === 'WEEKLY')
                        <span class="text-sm text-gray-600">Semaine du</span>
                        <div class="relative">
                            <input type="date" wire:model.live="MondayWeekly"
                                class="pl-3 pr-8 py-1.5 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-orange-500/20 focus:border-orange-500"/>
                            <svg class="absolute right-2 top-2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                        </div>
                    @else
                        <span class="text-sm text-gray-600">Mois</span>
                        <div class="relative">
                            <input type="month" wire:model.live="dateMonthly"
                                class="pl-3 pr-8 py-1.5 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-orange-500/20 focus:border-orange-500"/>
                            <svg class="absolute right-2 top-2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                        </div>
                    @endif
                </div>

                <!-- Séparateur vertical -->
                <div class="w-px h-8 bg-gray-200"></div>

                <!-- Bouton rafraîchir -->
                <button type="button" 
                    wire:click="clearCache"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500 disabled:opacity-50 disabled:cursor-not-allowed">
                    <span wire:loading.remove wire:target="clearCache" class="flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        Rafraîchir
                    </span>
                    <span wire:loading wire:target="clearCache" class="flex items-center gap-2">
                        <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Chargement...
                    </span>
                </button>
            </div>
        </div>
    </div>

    <!-- Barre secondaire avec pagination et contrôles -->
    <div class="px-4 py-3 bg-gray-50/50 rounded-b-lg">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <!-- Lignes par page -->
            <div class="flex items-center gap-3">
                <span class="text-sm text-gray-600">Afficher</span>
                <select wire:model.live="perPage" 
                    class="pl-3 pr-8 py-1.5 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-orange-500/20 focus:border-orange-500 bg-white">
                    <option value="25">25 lignes</option>
                    <option value="50">50 lignes</option>
                    <option value="100">100 lignes</option>
                </select>
            </div>

            <!-- Statistiques -->
            <div class="flex items-center gap-4">
                <div class="flex items-center gap-2">
                    <span class="text-sm text-gray-600">{{ $total }} résultat(s)</span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                        Page {{ $currentPage }}/{{ $lastPage }}
                    </span>
                </div>

                <!-- Pagination -->
                @if($lastPage > 1)
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-gray-500">
                            {{ (($currentPage - 1) * $perPage) + 1 }}–{{ min($currentPage * $perPage, $total) }}
                            sur {{ $total }}
                        </span>
                        
                        <nav class="flex items-center gap-1" aria-label="Pagination">
                            <button class="p-1.5 text-gray-400 hover:text-gray-600 rounded-md hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed"
                                wire:click="setPage(1)"
                                @disabled($currentPage === 1)>
                                <span class="sr-only">Première page</span>
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M15.707 15.707a1 1 0 01-1.414 0l-5-5a1 1 0 010-1.414l5-5a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 010 1.414zm-6 0a1 1 0 01-1.414 0l-5-5a1 1 0 010-1.414l5-5a1 1 0 011.414 1.414L5.414 10l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd" />
                                </svg>
                            </button>

                            <button class="p-1.5 text-gray-400 hover:text-gray-600 rounded-md hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed"
                                wire:click="setPage({{ $currentPage - 1 }})"
                                @disabled($currentPage === 1)>
                                <span class="sr-only">Page précédente</span>
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                                </svg>
                            </button>

                            @foreach(range(max(1, $currentPage - 2), min($lastPage, $currentPage + 2)) as $p)
                                <button class="min-w-[2rem] px-2 py-1 text-sm font-medium rounded-md 
                                    {{ $p === $currentPage 
                                        ? 'bg-orange-500 text-white' 
                                        : 'text-gray-700 hover:bg-gray-100' }}"
                                    wire:click="setPage({{ $p }})">
                                    {{ $p }}
                                </button>
                            @endforeach

                            <button class="p-1.5 text-gray-400 hover:text-gray-600 rounded-md hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed"
                                wire:click="setPage({{ $currentPage + 1 }})"
                                @disabled($currentPage === $lastPage)>
                                <span class="sr-only">Page suivante</span>
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                                </svg>
                            </button>

                            <button class="p-1.5 text-gray-400 hover:text-gray-600 rounded-md hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed"
                                wire:click="setPage({{ $lastPage }})"
                                @disabled($currentPage === $lastPage)>
                                <span class="sr-only">Dernière page</span>
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M4.293 15.707a1 1 0 010-1.414L8.586 10 4.293 6.707a1 1 0 011.414-1.414l5 5a1 1 0 010 1.414l-5 5a1 1 0 01-1.414 0zm6 0a1 1 0 010-1.414L14.586 10l-4.293-4.293a1 1 0 011.414-1.414l5 5a1 1 0 010 1.414l-5 5a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                                </svg>
                            </button>
                        </nav>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

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
                                                <th>Google Group</th>
                                                <th>Google Titre</th>
                                                <th>EAN Google</th>
                                                <th class="min-w-[420px]">
                                                    <div class="flex items-center gap-1">
                                                        <svg class="w-3.5 h-3.5 text-orange-500" viewBox="0 0 24 24" fill="currentColor">
                                                            <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                                                        </svg>
                                                        Magento
                                                    </div>
                                                </th>
                                                <th class="text-center">Demande relative</th>
                                                @foreach($sites as $site)
                                                    <th class="text-center min-w-[150px]">{{ $site->name }}</th>
                                                @endforeach
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

                                                    <td class="font-bold max-w-xs truncate" title="{{ $item['title'] ?? '' }}">
                                                        {{ $item['title'] ?? '—' }}
                                                    </td>

                                                    <td class="p-1 align-top">
                                                        @if(!empty($item['ean_list']))
                                                            <div class="flex flex-col gap-0.5">
                                                                @foreach($item['ean_list'] as $ean)
                                                                    <span class="font-mono text-xs
                                                                        {{ isset($item['magento_products'][$ean]) ? 'text-success font-semibold' : 'text-error font-medium' }}">
                                                                        {{ $ean }}
                                                                        @if(isset($item['magento_products'][$ean]))
                                                                            <span class="text-success" title="Trouvé dans Magento"></span>
                                                                        @else
                                                                            <span class="text-error" title="Non trouvé dans Magento"></span>
                                                                        @endif
                                                                    </span>
                                                                @endforeach
                                                            </div>
                                                        @else
                                                            <span class="text-gray-300">—</span>
                                                        @endif
                                                    </td>

                                                    <td class="align-top p-2">
                                                        @if(!empty($item['magento_products']))
                                                            <div class="space-y-1.5">
                                                                @foreach($item['magento_products'] as $ean => $mag)
                                                                    <div class="bg-white border border-base-200 rounded-md p-2 hover:border-primary/30 transition-colors">
                                                                        <div class="flex items-center justify-between gap-2 mb-1">
                                                                            <span class="font-mono text-xs font-bold text-primary truncate max-w-[100px]" title="{{ $mag['sku'] }}">
                                                                                {{ $mag['sku'] }}
                                                                            </span>
                                                                        </div>
                                                                        
                                                                        <div class="text-xs mb-1 line-clamp-1" title="{{ $mag['title'] }}">
                                                                            {{ utf8_encode($mag['title']) }}
                                                                        </div>
                                                                        
                                                                        <div class="text-right">
                                                                            @if(!empty($mag['special_price']))
                                                                                <span class="text-[10px] line-through text-gray-400 mr-1">
                                                                                    {{ number_format($mag['price'] ?? 0, 2, ',', ' ') }}€
                                                                                </span>
                                                                                <span class="text-xs font-bold text-success">
                                                                                    {{ number_format($mag['special_price'], 2, ',', ' ') }}€
                                                                                </span>
                                                                            @else
                                                                                <span class="text-xs font-bold {{ $mag['price'] > 0 ? 'text-success' : 'text-gray-400' }}">
                                                                                    {{ number_format($mag['price'] ?? 0, 2, ',', ' ') }}€
                                                                                </span>
                                                                            @endif
                                                                        </div>
                                                                    </div>
                                                                @endforeach
                                                            </div>
                                                        @else
                                                            <div class="flex items-center justify-center h-full min-h-[80px]">
                                                                <div class="text-gray-300 text-xs italic flex flex-col items-center gap-1">
                                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                                            d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                                                                    </svg>
                                                                    <span>Non référencé</span>
                                                                </div>
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

                                                    {{-- Colonnes des sites avec les produits scrapés --}}
                                                    @foreach($sites as $site)
                                                        <td class="align-top p-2 border-l border-base-200 first:border-l-0">
                                                            @php
                                                                $productsForSite = [];
                                                                foreach($item['ean_list'] as $ean) {
                                                                    if(isset($item['scraped_products'][$ean])) {
                                                                        foreach($item['scraped_products'][$ean] as $scrapedProduct) {
                                                                            if($scrapedProduct['site_id'] == $site->id) {
                                                                                $productsForSite[] = $scrapedProduct;
                                                                            }
                                                                        }
                                                                    }
                                                                }
                                                            @endphp
                                                            
                                                            @if(!empty($productsForSite))
                                                                <div class="space-y-2">
                                                                    @foreach($productsForSite as $product)
                                                                        {{-- Conteneur pour chaque produit avec bordure et espacement --}}
                                                                        <div class="bg-base-50 rounded p-2 border border-base-200 hover:border-primary/30 transition-colors">
                                                                            {{-- Ligne 1: EAN et statut --}}
                                                                            <div class="flex items-center justify-between gap-2 mb-1">
                                                                                <span class="font-mono font-bold text-xs {{ $product['is_available'] ? 'text-success' : 'text-error' }}">
                                                                                    {{ $product['ean'] }}
                                                                                </span>
                                                                            </div>
                                                                            
                                                                            {{-- Ligne 2: Nom du produit (cliquable) --}}
                                                                            @if($product['url'])
                                                                                <a href="{{ $product['url'] }}" 
                                                                                target="_blank" 
                                                                                class="link link-primary link-hover text-xs block mb-1 hover:underline"
                                                                                title="{{ $product['name'] }}">
                                                                                    {{ Str::limit($product['name'], 25) }}
                                                                                </a>
                                                                            @else
                                                                                <div class="text-xs text-gray-700 block mb-1" title="{{ $product['name'] }}">
                                                                                    {{ Str::limit($product['name'], 25) }}
                                                                                </div>
                                                                            @endif
                                                                            
                                                                            {{-- Ligne 3: Prix et vendeur --}}
                                                                            <div class="flex items-center justify-between text-xs">
                                                                                <span class="font-semibold {{ $product['is_available'] ? 'text-success' : 'text-error' }}">
                                                                                    {{ number_format($product['price'], 2, ',', ' ') }} {{ $product['currency'] ?? '€' }}
                                                                                </span>
                                                                            </div>
                                                                        </div>
                                                                    @endforeach
                                                                </div>
                                                            @else
                                                                <div class="flex items-center justify-center h-full min-h-[80px] bg-base-50/50 rounded border border-dashed border-base-300">
                                                                    <span class="text-gray-400 text-xs italic">Aucun produit</span>
                                                                </div>
                                                            @endif
                                                        </td>
                                                    @endforeach

                                                </tr>
                                            @endforeach
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <th class="text-center">Rang Google</th>
                                                <th>Google Group</th>
                                                <th>Google Titre</th>
                                                <th>EAN Google</th>
                                                <th>Magento</th>
                                                <th class="text-center">Demande relative</th>
                                                @foreach($sites as $site)
                                                    <th class="text-center">{{ $site->name }}</th>
                                                @endforeach
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