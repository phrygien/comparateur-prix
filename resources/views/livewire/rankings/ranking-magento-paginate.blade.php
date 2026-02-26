<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\Product;
use App\Models\Site;
use App\Services\GoogleMerchantService;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public int $perPage = 25;
    public int $currentPage = 1;

    public string $activeCountry = 'FR';
    public string $dateFrom      = '';
    public string $dateTo        = '';
    public string $sortBy        = 'rank_qty';
    public array $groupeFilter  = [];

    public array $countries = [
        'FR' => 'France',
        'BE' => 'Belgique',
        'NL' => 'Pays-Bas',
        'DE' => 'Allemagne',
        'ES' => 'Espagne',
        'IT' => 'Italie',
    ];

    // Mapping pays → code Google Merchant
    protected array $countryCodeMap = [
        'FR' => 'FR',
        'BE' => 'BE',
        'NL' => 'NL',
        'DE' => 'DE',
        'ES' => 'ES',
        'IT' => 'IT',
    ];

    public $somme_prix_marche_total = 0;
    public $somme_gain = 0;
    public $somme_perte = 0;
    public $percentage_gain_marche = 0;
    public $percentage_perte_marche = 0;

    protected GoogleMerchantService $googleMerchantService;

    public function boot(GoogleMerchantService $googleMerchantService): void
    {
        $this->googleMerchantService = $googleMerchantService;
    }

    public function mount(): void
    {
        $this->dateFrom = date('Y-01-01');
        $this->dateTo = date('Y-12-31');
    }

    public function getSalesTotalProperty(): int
    {
        $dateFrom = ($this->dateFrom ?: date('Y-01-01')) . ' 00:00:00';
        $dateTo   = ($this->dateTo   ?: date('Y-12-31')) . ' 23:59:59';

        $groupeCondition = '';
        $params = [$dateFrom, $dateTo, $this->activeCountry];

        if (!empty($this->groupeFilter)) {
            $placeholders    = implode(',', array_fill(0, count($this->groupeFilter), '?'));
            $groupeCondition = "AND groupe IN ($placeholders)";
            $params          = array_merge($params, $this->groupeFilter);
        }

        $cacheKey = 'top_products_total_' . md5(
            $this->activeCountry . $dateFrom . $dateTo . implode(',', $this->groupeFilter)
        );

        return Cache::remember($cacheKey, now()->addHour(), function () use ($dateFrom, $dateTo, $groupeCondition, $params) {

            $sql = "
                SELECT COUNT(*) as total
                FROM (
                    SELECT oi.sku
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
                    {$groupeCondition}
                    GROUP BY oi.sku, addr.country_id
                ) AS counted
            ";

            $result = DB::connection('mysqlMagento')->selectOne($sql, $params);
            return (int) ($result->total ?? 0);
        });
    }

    public function getSalesProperty()
    {
        $dateFrom = ($this->dateFrom ?: date('Y-01-01')) . ' 00:00:00';
        $dateTo   = ($this->dateTo   ?: date('Y-12-31')) . ' 23:59:59';

        $orderCol = $this->sortBy === 'rank_ca' ? 'total_revenue' : 'total_qty_sold';

        $groupeCondition = '';
        $params = [$dateFrom, $dateTo, $this->activeCountry];

        if (!empty($this->groupeFilter)) {
            $placeholders    = implode(',', array_fill(0, count($this->groupeFilter), '?'));
            $groupeCondition = "WHERE groupe IN ($placeholders)";
            $params          = array_merge($params, $this->groupeFilter);
        }

        $offset = ($this->currentPage - 1) * $this->perPage;

        // On ajoute LIMIT et OFFSET en fin de params (valeurs entières, bindées proprement)
        $params[] = $this->perPage;
        $params[] = $offset;

        $cacheKey = 'top_products_' . md5(
            $this->activeCountry . $dateFrom . $dateTo . $orderCol
            . implode(',', $this->groupeFilter)
            . $this->currentPage . $this->perPage
        );

        return Cache::remember($cacheKey, now()->addHour(), function () use ($dateFrom, $dateTo, $groupeCondition, $params, $orderCol) {

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
                {$groupeCondition}
                ORDER BY {$orderCol} DESC
                LIMIT ? OFFSET ?
            ";

            DB::connection('mysqlMagento')->getPdo()->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

            $results = DB::connection('mysqlMagento')->select($sql, $params);

            foreach ($results as $result) {
                foreach (['designation_produit', 'marque', 'groupe'] as $field) {
                    if (isset($result->$field)) {
                        if (!mb_check_encoding($result->$field, 'UTF-8')) {
                            $result->$field = mb_convert_encoding($result->$field, 'UTF-8', 'ISO-8859-1');
                        }
                        $result->$field = mb_convert_encoding($result->$field, 'UTF-8', 'UTF-8');
                    }
                }
            }

            return $results;
        });
    }

    public function getPopularityRanksProperty(): array
    {
        $sales = $this->sales;

        if (empty($sales)) {
            return [];
        }

        $eans = collect($sales)
            ->pluck('ean')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        if (empty($eans)) {
            return [];
        }

        $toGtin14 = fn(string $ean): string => str_pad(preg_replace('/\D/', '', $ean), 14, '0', STR_PAD_LEFT);

        $countryCode = $this->countryCodeMap[$this->activeCountry] ?? $this->activeCountry;

        $gtins14 = array_unique(array_map($toGtin14, $eans));

        $cacheKey = 'google_popularity_v2_' . md5($countryCode . implode(',', $gtins14));

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($gtins14, $countryCode, $toGtin14) {

            $gtinList = implode("', '", $gtins14);

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
                  AND report_granularity = 'WEEKLY'
                  AND category_l1 LIKE '%Health & Beauty%'
                  AND variant_gtins CONTAINS ANY ('{$gtinList}')
                LIMIT 1000
            ";

            try {
                $response = $this->googleMerchantService->searchReports($query);

                Log::info('Google Merchant raw response', ['response' => $response]);

                $ranksByGtin = [];

                foreach ($response['results'] ?? [] as $row) {
                    $data         = $row['bestSellersProductClusterView'] ?? [];
                    $variantGtins = $data['variantGtins'] ?? [];

                    $rank     = isset($data['rank'])          ? (int) $data['rank']          : null;
                    $prevRank = isset($data['previousRank'])  ? (int) $data['previousRank']  : null;
                    $delta    = ($rank !== null && $prevRank !== null) ? ($prevRank - $rank) : null;

                    $rankInfo = [
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
                        'title'           => $data['title'] ?? null,
                        'brand'           => $data['brand'] ?? null,
                    ];

                    foreach ($variantGtins as $gtin) {
                        $key = $toGtin14((string) $gtin);
                        if (!isset($ranksByGtin[$key]) || ($rank < ($ranksByGtin[$key]['rank'] ?? PHP_INT_MAX))) {
                            $ranksByGtin[$key] = $rankInfo;
                        }
                    }
                }

                Log::info('Google Merchant ranks by GTIN-14', ['ranksByGtin' => $ranksByGtin]);

                return $ranksByGtin;

            } catch (\Exception $e) {
                Log::error('Google Merchant popularity rank error: ' . $e->getMessage());
                return [];
            }
        });
    }

    public function debugPopularity(): void
    {
        $toGtin14 = fn(string $ean): string => str_pad(preg_replace('/\D/', '', $ean), 14, '0', STR_PAD_LEFT);

        $sales = $this->sales;
        $eans  = collect($sales)->pluck('ean')->filter()->unique()->values()->toArray();

        Log::info('[DEBUG] EANs Magento bruts', ['eans' => $eans]);

        $gtins14 = array_unique(array_map($toGtin14, $eans));
        Log::info('[DEBUG] EANs normalisés GTIN-14 envoyés à Google', ['gtins14' => $gtins14]);

        $countryCode = $this->countryCodeMap[$this->activeCountry] ?? $this->activeCountry;
        $gtinList    = implode("', '", $gtins14);

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
              AND report_granularity = 'WEEKLY'
              AND category_l1 LIKE '%Health & Beauty%'
              AND variant_gtins CONTAINS ANY ('{$gtinList}')
            LIMIT 10
        ";

        Log::info('[DEBUG] Requête Google Merchant', ['query' => $query]);

        try {
            $response = $this->googleMerchantService->searchReports($query);
            Log::info('[DEBUG] Réponse brute Google', ['response' => $response]);

            $results = $response['results'] ?? [];
            Log::info('[DEBUG] Nombre de résultats', ['count' => count($results)]);

            foreach ($results as $i => $row) {
                $data = $row['bestSellersProductClusterView'] ?? [];
                Log::info("[DEBUG] Résultat #{$i}", [
                    'title'        => $data['title'] ?? null,
                    'rank'         => $data['rank'] ?? null,
                    'previousRank' => $data['previousRank'] ?? null,
                    'variantGtins' => $data['variantGtins'] ?? [],
                    'normalized'   => array_map($toGtin14, array_map('strval', $data['variantGtins'] ?? [])),
                ]);
            }

            foreach ($gtins14 as $gtin) {
                $found = false;
                foreach ($results as $row) {
                    $data    = $row['bestSellersProductClusterView'] ?? [];
                    $gtins   = array_map(fn($g) => $toGtin14((string) $g), $data['variantGtins'] ?? []);
                    if (in_array($gtin, $gtins)) {
                        $found = true;
                        Log::info("[DEBUG] MATCH trouvé", ['gtin' => $gtin, 'rank' => $data['rank'] ?? null]);
                    }
                }
                if (!$found) {
                    Log::warning("[DEBUG] Pas de match Google pour ce GTIN", ['gtin' => $gtin]);
                }
            }

        } catch (\Exception $e) {
            Log::error('[DEBUG] Erreur API Google Merchant', ['message' => $e->getMessage()]);
        }
    }

    public function getAvailableGroupesProperty()
    {
        $dateFrom = ($this->dateFrom ?: date('Y-01-01')) . ' 00:00:00';
        $dateTo   = ($this->dateTo   ?: date('Y-12-31')) . ' 23:59:59';

        $cacheKey = 'available_groupes_' . md5(
            $this->activeCountry
            . $dateFrom
            . $dateTo
        );;

        return Cache::remember($cacheKey, now()->addHour(), function () use ($dateFrom, $dateTo) {

            $sql = "
                WITH sales AS (
                    SELECT DISTINCT
                        SUBSTRING_INDEX(CAST(product_char.name AS CHAR CHARACTER SET utf8mb4), ' - ', 1) AS groupe
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
                SELECT groupe
                FROM sales
                WHERE groupe IS NOT NULL
                  AND groupe != ''
                ORDER BY groupe ASC
            ";

            $groupes = DB::connection('mysqlMagento')
                ->select($sql, [$dateFrom, $dateTo, $this->activeCountry]);

            return collect($groupes)->pluck('groupe')->toArray();
        });
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
                'difference_marche' => null,
            ];

            $somme_prix_marche = 0;
            $nombre_site = 0;

            foreach ($sites as $site) {
                if (isset($scrapedProducts[$site->id])) {
                    $scrapedProduct = $scrapedProducts[$site->id];
                    $prixCosma = $row->prix_vente_cosma;
                    $priceDiff = null;
                    $pricePercentage = null;

                    if ($prixCosma > 0 && $scrapedProduct->prix_ht > 0) {
                        $priceDiff = $scrapedProduct->prix_ht - $prixCosma;
                        $pricePercentage = round(($priceDiff / $prixCosma) * 100, 2);
                    }

                    $comparison['sites'][$site->id] = [
                        'prix_ht'          => $scrapedProduct->prix_ht,
                        'url'              => $scrapedProduct->url,
                        'name'             => $scrapedProduct->name,
                        'vendor'           => $scrapedProduct->vendor,
                        'ean'              => $scrapedProduct->ean ?? null,
                        'price_diff'       => $priceDiff,
                        'price_percentage' => $pricePercentage,
                        'site_name'        => $site->name,
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
            $this->percentage_gain_marche  = ((($this->somme_prix_marche_total + $this->somme_gain)  * 100) / $this->somme_prix_marche_total) - 100;
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

    public function updatedActiveCountry(): void  { $this->currentPage = 1; }
    public function updatedDateFrom(): void        { $this->currentPage = 1; }
    public function updatedDateTo(): void          { $this->currentPage = 1; }
    public function updatedGroupeFilter(): void    { $this->currentPage = 1; }
    public function updatedPerPage(): void         { $this->currentPage = 1; }

    public function setSortBy(string $column): void
    {
        $this->sortBy = $column;
        $this->currentPage = 1;
    }

    public function setPage(int $page): void
    {
        $this->currentPage = $page;
    }

    public function clearCache(): void
    {
        $dateFrom = ($this->dateFrom ?: date('Y-01-01')) . ' 00:00:00';
        $dateTo   = ($this->dateTo   ?: date('Y-12-31')) . ' 23:59:59';

        $orderCol = $this->sortBy === 'rank_ca' ? 'total_revenue' : 'total_qty_sold';

        Cache::forget('top_products_' . md5(
            $this->activeCountry
            . $dateFrom
            . $dateTo
            . $orderCol
            . implode(',', $this->groupeFilter)
        ));

        Cache::forget('available_groupes_' . md5(
            $this->activeCountry
            . $dateFrom
            . $dateTo
        ));

        Cache::forget('top_products_total_' . md5(
            $this->activeCountry . $dateFrom . $dateTo . implode(',', $this->groupeFilter)
        ));

        // Vider le cache popularité avec la même clé normalisée GTIN-14
        $toGtin14    = fn(string $ean): string => str_pad(preg_replace('/\D/', '', $ean), 14, '0', STR_PAD_LEFT);
        $countryCode = $this->countryCodeMap[$this->activeCountry] ?? $this->activeCountry;
        $gtins14     = array_unique(array_map($toGtin14, collect($this->sales)->pluck('ean')->filter()->toArray()));

        Cache::forget('google_popularity_v2_' . md5($countryCode . implode(',', $gtins14)));
    }

    public function exportXlsx(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $sites          = $this->sites;
        $comparisons    = $this->comparisons;
        $popularityRanks = $this->popularityRanks;

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $countryLabel = $this->countries[$this->activeCountry] ?? $this->activeCountry;
        $sheet->setTitle('Ventes ' . $countryLabel);

        $baseHeaders = [
            'Rang Qty', 'Rang CA', 'EAN', 'Groupe', 'Marque',
            'Désignation', 'Prix Cosma', 'Qté vendue', 'CA total', 'PGHT',
            'Rang Google', // ← popularité Google Merchant
        ];

        $lastColIndex  = count($baseHeaders) + $sites->count();
        $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastColIndex + 1);

        $sheet->getColumnDimension('A')->setAutoSize(false)->setWidth(10);
        $sheet->getColumnDimension('B')->setAutoSize(false)->setWidth(10);
        $sheet->getColumnDimension('C')->setAutoSize(false)->setWidth(16);
        $sheet->getColumnDimension('F')->setAutoSize(false)->setWidth(35);

        // PASS 1 : statistiques
        $somme_prix_marche_total = 0;
        $somme_gain  = 0;
        $somme_perte = 0;
        $comparisonsAvecPrix = 0;

        foreach ($comparisons as $comparison) {
            if ($comparison['prix_moyen_marche'] !== null) {
                $prixMoyen = $comparison['prix_moyen_marche'];
                $somme_prix_marche_total += $prixMoyen;
                $diff = $comparison['difference_marche'];
                if ($diff > 0) $somme_gain  += $diff;
                else           $somme_perte += $diff;
                $comparisonsAvecPrix++;
            }
        }

        $pct_gain = $somme_prix_marche_total > 0
            ? ((($somme_prix_marche_total + $somme_gain)  * 100) / $somme_prix_marche_total) - 100 : 0;
        $pct_perte = $somme_prix_marche_total > 0
            ? ((($somme_prix_marche_total + $somme_perte) * 100) / $somme_prix_marche_total) - 100 : 0;

        $row1 = 1;
        $groupeLabel = !empty($this->groupeFilter) ? implode(', ', $this->groupeFilter) : 'Tous';
        $infoLine = [
            'Pays'               => $countryLabel,
            'Période'            => $this->dateFrom . ' → ' . $this->dateTo,
            'Groupe(s)'          => $groupeLabel,
            'Tri'                => $this->sortBy === 'rank_qty' ? 'Qté vendue' : 'CA total',
            'Produits exportés'  => count($this->sales),
            'Produits comparés'  => $comparisonsAvecPrix,
        ];

        $col = 1;
        foreach ($infoLine as $label => $value) {
            $labelCell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row1;
            $valueCell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1) . $row1;
            $sheet->setCellValue($labelCell, $label . ' :');
            $sheet->setCellValue($valueCell, $value);
            $sheet->getStyle($labelCell)->getFont()->setBold(true)->setName('Arial')->setSize(9);
            $sheet->getStyle($valueCell)->getFont()->setName('Arial')->setSize(9);
            $col += 2;
        }

        $row2 = 2;
        $kpis = [
            ['↓ Moins chers (€)',  $comparisonsAvecPrix > 0 ? number_format(abs($somme_gain  / $comparisonsAvecPrix), 2, ',', ' ') . ' €' : 'N/A', '1A7A3C'],
            ['↓ Moins chers (%)',  number_format(abs($pct_gain),  2, ',', ' ') . ' %', '1A7A3C'],
            ['↑ Plus chers (€)',   $comparisonsAvecPrix > 0 ? number_format(abs($somme_perte / $comparisonsAvecPrix), 2, ',', ' ') . ' €' : 'N/A', 'CC0000'],
            ['↑ Plus chers (%)',   number_format(abs($pct_perte), 2, ',', ' ') . ' %', 'CC0000'],
        ];

        $col = 1;
        foreach ($kpis as [$label, $value, $color]) {
            $labelCell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row2;
            $valueCell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1) . $row2;
            $sheet->setCellValue($labelCell, $label . ' :');
            $sheet->getStyle($labelCell)->getFont()->setBold(true)->setName('Arial')->setSize(9);
            $sheet->setCellValue($valueCell, $value);
            $sheet->getStyle($valueCell)->getFont()->getColor()->setRGB($color);
            $sheet->getStyle($valueCell)->getFont()->setBold(true)->setName('Arial')->setSize(9);
            $col += 2;
        }

        $sheet->getStyle('A1:' . $lastColLetter . '2')->applyFromArray([
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EDF2F7']],
            'borders' => ['outline' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, 'color' => ['rgb' => 'CBD5E0']]],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(16);
        $sheet->getRowDimension(2)->setRowHeight(16);

        $r2          = 3;
        $dataStartRow = $r2 + 1;
        $headerRow    = $r2;
        $row          = $dataStartRow;

        $hColIdx = 0;
        foreach ($baseHeaders as $header) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($hColIdx + 1) . $headerRow;
            $sheet->setCellValue($cell, $header);
            $hColIdx++;
        }
        foreach ($sites as $site) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($hColIdx + 1) . $headerRow;
            $sheet->setCellValue($cell, $site->name);
            $hColIdx++;
        }
        $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($hColIdx + 1) . $headerRow;
        $sheet->setCellValue($cell, 'Prix marché');

        $sheet->getStyle('A' . $headerRow . ':' . $lastColLetter . $headerRow)->applyFromArray([
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '2D3748']],
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Arial', 'size' => 10],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($headerRow)->setRowHeight(20);

        foreach ($comparisons as $comparison) {
            $r   = $comparison['row'];
            $ean = $r->ean ?? null;
            // Normaliser l'EAN Magento en GTIN-14 pour matcher la clé de $popularityRanks
            $eanKey = $ean ? str_pad(preg_replace('/\D/', '', $ean), 14, '0', STR_PAD_LEFT) : null;
            $pop = $eanKey ? ($popularityRanks[$eanKey] ?? null) : null;

            $sheet->setCellValue('A' . $row, $r->rank_qty);
            $sheet->setCellValue('B' . $row, $r->rank_ca);
            $sheet->setCellValue('C' . $row, $r->ean);
            $sheet->setCellValue('D' . $row, $r->groupe ?? '');
            $sheet->setCellValue('E' . $row, $r->marque ?? '');
            $sheet->setCellValue('F' . $row, $r->designation_produit ?? '');
            $sheet->setCellValue('G' . $row, $r->prix_vente_cosma);
            $sheet->setCellValue('H' . $row, $r->total_qty_sold);
            $sheet->setCellValue('I' . $row, $r->total_revenue);
            $sheet->setCellValue('J' . $row, $r->pght ?: '');

            // Colonne popularité Google Merchant (K uniquement)
            if ($pop) {
                $googleRank = $pop['rank'] ?? null;
                $delta      = $pop['delta'] ?? null;
                $deltaSign  = $pop['delta_sign'] ?? null;

                if ($googleRank !== null) {
                    $richText = new \PhpOffice\PhpSpreadsheet\RichText\RichText();

                    $runRank = $richText->createTextRun('#' . $googleRank);
                    $runRank->getFont()->setBold(true)->setName('Arial');
                    $runRank->getFont()->getColor()->setRGB('000000');

                    if ($delta !== null) {
                        $deltaColor = match($deltaSign) {
                            '+'     => 'FF1A7A3C',
                            '-'     => 'FFCC0000',
                            default => 'FF888888',
                        };
                        $deltaStr = ' (' . ($deltaSign === '+' ? '+' : '') . $delta . ')';
                        $runDelta = $richText->createTextRun($deltaStr);
                        $runDelta->getFont()->setBold(true)->setName('Arial');
                        $runDelta->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($deltaColor));
                    }

                    $sheet->getCell('K' . $row)->setValue($richText);
                } else {
                    $sheet->setCellValue('K' . $row, '—');
                    $sheet->getStyle('K' . $row)->getFont()->getColor()->setRGB('AAAAAA');
                }
            } else {
                $sheet->setCellValue('K' . $row, '—');
                $sheet->getStyle('K' . $row)->getFont()->getColor()->setRGB('AAAAAA');
            }

            $sheet->getStyle('G' . $row)->getNumberFormat()->setFormatCode('#,##0.00 "€"');
            $sheet->getStyle('I' . $row)->getNumberFormat()->setFormatCode('#,##0.00 "€"');
            $sheet->getStyle('J' . $row)->getNumberFormat()->setFormatCode('#,##0.00 "€"');

            if (($row - $dataStartRow) % 2 === 0) {
                $sheet->getStyle('A' . $row . ':' . $lastColLetter . $row)->applyFromArray([
                    'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F7FAFC']],
                ]);
            }

            $colIdx = count($baseHeaders); // commence après les colonnes de base
            foreach ($sites as $site) {
                $cellCoord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1) . $row;
                $siteData  = $comparison['sites'][$site->id] ?? null;

                if ($siteData) {
                    $pricePercentage = $siteData['price_percentage'];
                    $priceColor = 'FF000000';

                    if ($pricePercentage !== null) {
                        $priceColor = $r->prix_vente_cosma > $siteData['prix_ht'] ? 'FFCC0000' : 'FF1A7A3C';
                    }

                    $prixText = number_format($siteData['prix_ht'], 2, ',', ' ') . ' €';
                    if ($pricePercentage !== null) {
                        $prixText .= ' (' . ($pricePercentage > 0 ? '+' : '') . $pricePercentage . '%)';
                    }

                    $richText = new \PhpOffice\PhpSpreadsheet\RichText\RichText();
                    $runPrix  = $richText->createTextRun($prixText);
                    $runPrix->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($priceColor));
                    $runPrix->getFont()->setName('Arial');

                    if (!empty($siteData['ean'])) {
                        $runEan = $richText->createTextRun("\nEAN : " . $siteData['ean']);
                        $runEan->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF888888'));
                        $runEan->getFont()->setName('Arial')->setSize(8);
                    }

                    if (!empty($siteData['url'])) {
                        $runLien = $richText->createTextRun("\nVoir le produit");
                        $runLien->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF0563C1'));
                        $runLien->getFont()->setUnderline(\PhpOffice\PhpSpreadsheet\Style\Font::UNDERLINE_SINGLE);
                        $runLien->getFont()->setName('Arial');
                    }

                    $sheet->getCell($cellCoord)->setValue($richText);
                    $sheet->getStyle($cellCoord)->getAlignment()->setWrapText(true);
                    $sheet->getStyle($cellCoord)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);

                    if (!empty($siteData['url'])) {
                        $sheet->getCell($cellCoord)->getHyperlink()->setUrl($siteData['url']);
                    }
                } else {
                    $sheet->setCellValue($cellCoord, 'N/A');
                    $sheet->getStyle($cellCoord)->getFont()->getColor()->setRGB('AAAAAA');
                    $sheet->getStyle($cellCoord)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                }

                $colIdx++;
            }

            $marcheCoord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1) . $row;

            if ($comparison['prix_moyen_marche'] !== null) {
                $prixMoyen = $comparison['prix_moyen_marche'];
                $pct       = $comparison['percentage_marche'];
                $color     = $r->prix_vente_cosma > $prixMoyen ? 'CC0000' : '1A7A3C';
                $sheet->setCellValue($marcheCoord, number_format($prixMoyen, 2, ',', ' ') . ' € (' . ($pct > 0 ? '+' : '') . $pct . '%)');
                $sheet->getStyle($marcheCoord)->getFont()->getColor()->setRGB($color);
                $sheet->getStyle($marcheCoord)->getFont()->setBold(true);
            } else {
                $sheet->setCellValue($marcheCoord, 'N/A');
                $sheet->getStyle($marcheCoord)->getFont()->getColor()->setRGB('AAAAAA');
                $sheet->getStyle($marcheCoord)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            }

            $row++;
        }

        $lastDataRow = $row - 1;

        foreach (range('D', $lastColLetter) as $col) {
            if (!in_array($col, ['A', 'B', 'C', 'F'])) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
        }

        $sheet->freezePane('A' . $dataStartRow);

        $sheet->getStyle('A' . $headerRow . ':' . $lastColLetter . $lastDataRow)->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'DDDDDD']]],
            'font' => ['name' => 'Arial', 'size' => 9],
        ]);

        $sheet->getStyle('G' . $dataStartRow . ':' . $lastColLetter . $lastDataRow)
            ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);

        $exportDir = storage_path('app/public/exports');
        if (!file_exists($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        $groupeSuffix = !empty($this->groupeFilter) ? '_' . implode('-', array_map('strtolower', $this->groupeFilter)) : '';
        $fileName = 'ventes_' . strtolower($this->activeCountry)
            . '_' . $this->dateFrom . '_' . $this->dateTo
            . $groupeSuffix
            . '_' . date('His') . '.xlsx';
        $filePath = $exportDir . '/' . $fileName;

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($filePath);

        return response()->download($filePath, $fileName)->deleteFileAfterSend(true);
    }

    public function with(): array
    {
        $comparisons = $this->comparisons;
        $comparisonsAvecPrixMarche = $comparisons->filter(fn($c) => $c['prix_moyen_marche'] !== null)->count();
        $total                     = $this->salesTotal;
        $lastPage                  = (int) ceil($total / $this->perPage);

        return [
            'sales'                     => $this->sales,
            'comparisons'               => $comparisons,
            'sites'                     => $this->sites,
            'availableGroupes'          => $this->availableGroupes,
            'popularityRanks'           => $this->popularityRanks,
            'comparisonsAvecPrixMarche' => $comparisonsAvecPrixMarche,
            'somme_gain'                => $this->somme_gain,
            'somme_perte'               => $this->somme_perte,
            'percentage_gain_marche'    => $this->percentage_gain_marche,
            'percentage_perte_marche'   => $this->percentage_perte_marche,
            'dateFrom'                  => $this->dateFrom,
            'dateTo'                    => $this->dateTo,
            'salesTotal'                => $total,
            'lastPage'                  => $lastPage,
            'currentPage'               => $this->currentPage,
            'perPage'                   => $this->perPage,
        ];
    }
}; ?>

<div>
    <div class="px-4 sm:px-6 lg:px-8 pt-6 pb-2">
        <x-tabs wire:model.live="activeCountry">
            @foreach($countries as $code => $label)
                <x-tab name="{{ $code }}" label="{{ $label }}">

                    <div wire:loading wire:target="activeCountry" class="flex flex-col items-center justify-center gap-3 py-16">
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

                        {{-- Barre d'outils --}}
                        <div class="flex flex-wrap items-center justify-between gap-4 mb-4 mt-6">
                            <div>
                                <h1 class="text-base font-semibold text-gray-900">Ventes — {{ $label }}</h1>
                                <p class="mt-0.5 text-sm text-gray-500">
                                    Top produits · {{ count($sales) }} résultat(s)
                                    @if(!empty($groupeFilter))
                                        · Groupe(s) : {{ implode(', ', $groupeFilter) }}
                                    @endif
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-3">

                                {{-- Filtre Groupe --}}
                                <div class="form-control" x-data="{
                                    open: false,
                                    search: '',
                                    get filteredGroupes() {
                                        if (this.search === '') return @js($availableGroupes);
                                        return @js($availableGroupes).filter(g =>
                                            g.toLowerCase().includes(this.search.toLowerCase())
                                        );
                                    }
                                }">
                                    <div class="flex flex-wrap gap-2 mb-2">
                                        @foreach($groupeFilter as $selectedGroupe)
                                            <div class="badge badge-primary gap-2 py-3 px-3">
                                                <span class="text-xs font-medium">{{ $selectedGroupe }}</span>
                                                <button type="button"
                                                    wire:click="$set('groupeFilter', {{ json_encode(array_values(array_diff($groupeFilter, [$selectedGroupe]))) }})"
                                                    class="btn btn-ghost btn-xs btn-circle">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                                    </svg>
                                                </button>
                                            </div>
                                        @endforeach
                                        @if(count($groupeFilter) > 0)
                                            <button type="button" wire:click="$set('groupeFilter', [])" class="badge badge-ghost gap-2 py-3 px-3 hover:badge-error">
                                                <span class="text-xs">Tout effacer</span>
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                                </svg>
                                            </button>
                                        @endif
                                    </div>
                                    <div class="relative">
                                        <button type="button" @click="open = !open" class="btn btn-sm btn-outline btn-primary gap-2">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                            </svg>
                                            {{ count($groupeFilter) > 0 ? 'Ajouter un vendor' : 'Sélectionner des vendor' }}
                                        </button>
                                        <div x-show="open" @click.away="open = false" x-transition
                                            class="absolute z-50 mt-2 w-80 bg-base-100 rounded-lg shadow-xl border border-base-300">
                                            <div class="p-3 border-b border-base-300">
                                                <input type="text" x-model="search" placeholder="Rechercher un vendor..."
                                                    class="input input-sm input-bordered w-full" @click.stop/>
                                            </div>
                                            <div class="max-h-64 overflow-y-auto p-2">
                                                <template x-for="groupe in filteredGroupes" :key="groupe">
                                                    <button type="button"
                                                        @click="$wire.set('groupeFilter', [...@js($groupeFilter), groupe].filter((v, i, a) => a.indexOf(v) === i)); search = ''"
                                                        class="w-full text-left px-3 py-2 rounded-md text-sm flex items-center justify-between group hover:bg-base-200"
                                                        x-show="!@js($groupeFilter).includes(groupe)">
                                                        <span x-text="groupe"></span>
                                                        <svg class="w-4 h-4 opacity-0 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                                        </svg>
                                                    </button>
                                                </template>
                                                <div x-show="filteredGroupes.length === 0" class="text-center py-8 text-gray-400 text-sm">Aucun groupe trouvé</div>
                                                <div x-show="filteredGroupes.length > 0 && filteredGroupes.every(g => @js($groupeFilter).includes(g))" class="text-center py-8 text-gray-400 text-sm">
                                                    Tous les groupes filtrés sont déjà sélectionnés
                                                </div>
                                            </div>
                                            <div class="p-3 border-t border-base-300 text-xs text-gray-500 flex items-center justify-between">
                                                <span>{{ count($groupeFilter) }} groupe(s) sélectionné(s)</span>
                                                <button type="button" @click="open = false" class="text-primary hover:underline">Fermer</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="divider divider-horizontal mx-0"></div>

                                {{-- Dates --}}
                                <div class="flex items-center gap-2">
                                    <input type="date" wire:model.live="dateFrom" value="{{ $dateFrom }}" class="input input-bordered input-sm w-36"/>
                                    <span class="text-xs text-gray-400">→</span>
                                    <input type="date" wire:model.live="dateTo" value="{{ $dateTo }}" class="input input-bordered input-sm w-36"/>
                                </div>

                                <div class="divider divider-horizontal mx-0"></div>

                                {{-- Tri --}}
                                <div class="flex items-center gap-2">
                                    <span class="text-xs text-gray-400">Trier par</span>
                                    {{-- <button type="button" @click="$wire.setSortBy ('rank_qty')" --}}
                                    <button type="button" wire:click="setSortBy('rank_qty')"
                                        class="btn btn-xs {{ $sortBy === 'rank_qty' ? 'bg-orange-900 text-white' : 'btn-outline btn-white' }}">
                                        @if($sortBy === 'rank_qty')<svg class="w-3 h-3" viewBox="0 0 16 16" fill="currentColor"><path d="M8 12L4 6h8z"/></svg>@endif
                                        Qté vendue
                                    </button>
                                    {{-- <button type="button" @click="$wire.setSortBy ('rank_ca')" --}}
                                    <button type="button" wire:click="setSortBy('rank_ca')"
                                        class="btn btn-xs {{ $sortBy === 'rank_ca' ? 'bg-orange-900 text-white' : 'btn-outline btn-white' }}">
                                        @if($sortBy === 'rank_ca')<svg class="w-3 h-3" viewBox="0 0 16 16" fill="currentColor"><path d="M8 12L4 6h8z"/></svg>@endif
                                        CA total
                                    </button>
                                </div>

                                <div class="divider divider-horizontal mx-0"></div>

                                <button type="button" wire:click="clearCache"
                                    wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed"
                                    class="btn btn-sm btn-ghost gap-2" title="Vider le cache et recharger les données">
                                    <span wire:loading.remove wire:target="clearCache" class="flex items-center gap-2">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                        </svg>
                                        Rafraîchir
                                    </span>
                                    <span wire:loading wire:target="clearCache" class="flex items-center gap-2">
                                        <span class="loading loading-spinner loading-xs"></span>
                                        Rafraîchissement…
                                    </span>
                                </button>

                                {{-- DEBUG temporaire --}}
                                {{-- <button type="button" wire:click="debugPopularity"
                                    class="btn btn-sm btn-warning gap-2" title="Debug popularité Google dans les logs">
                                    <span wire:loading.remove wire:target="debugPopularity">🔍 Debug Google</span>
                                    <span wire:loading wire:target="debugPopularity" class="flex items-center gap-2">
                                        <span class="loading loading-spinner loading-xs"></span>
                                        Analyse…
                                    </span>
                                </button> --}}

                                <div class="divider divider-horizontal mx-0"></div>

                                <button type="button" wire:click="exportXlsx"
                                    wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-not-allowed"
                                    class="btn btn-sm btn-success gap-2" title="Exporter les données affichées en Excel">
                                    <span wire:loading.remove wire:target="exportXlsx" class="flex items-center gap-2">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                        </svg>
                                        Export XLSX
                                    </span>
                                    <span wire:loading wire:target="exportXlsx" class="flex items-center gap-2">
                                        <span class="loading loading-spinner loading-xs"></span>
                                        Export en cours…
                                    </span>
                                </button>

                            </div>
                        </div>

                        <div class="flex items-center justify-between w-full">
                            <!-- Par page -->
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-gray-400">Par page</span>
                                <select wire:model.live="perPage" class="select select-sm select-bordered w-20">
                                    <option value="25">25</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                    <option value="200">200</option>
                                </select>
                            </div>

                            <!-- Résultats + pagination info -->
                            <p class="mt-0.5 text-sm text-gray-500 text-center">
                                {{ $salesTotal }} résultat(s) au total
                                · Page {{ $currentPage }}/{{ $lastPage }}
                                @if(!empty($groupeFilter))
                                    · Groupe(s) : {{ implode(', ', $groupeFilter) }}
                                @endif
                            </p>

                            <!-- Pagination -->
                            @if($lastPage > 1)
                                <div class="flex items-center">
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

                            <div wire:loading wire:target="dateFrom, dateTo, sortBy, groupeFilter, setSortBy, perPage, setPage"
                                class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-3 rounded-lg bg-white/70 backdrop-blur-sm">
                                <span class="loading loading-spinner loading-lg text-primary"></span>
                                <span class="text-sm font-medium">Mise à jour…</span>
                            </div>

                            @if(count($sales) === 0)
                                <div class="alert alert-info">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="stroke-current shrink-0 w-6 h-6">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    <span>Aucune vente trouvée pour cette période{{ !empty($groupeFilter) ? ' et ce(s) groupe(s)' : '' }}.</span>
                                </div>
                            @else
                                <div class="overflow-x-auto overflow-y-auto max-h-[70vh]"
                                    wire:loading.class="opacity-40 pointer-events-none"
                                    wire:target="dateFrom, dateTo, sortBy, groupeFilter">
                                    <table class="table table-xs table-pin-rows table-pin-cols">
                                        <thead>
                                            <tr>
                                                <th>Rang Qty</th>
                                                <th>Rang CA</th>
                                                <th class="text-center" title="Rang de popularité Google Merchant (Best Sellers)">
                                                    <div class="flex items-center justify-center gap-1">
                                                        <svg class="w-3 h-3 text-blue-500" viewBox="0 0 24 24" fill="currentColor">
                                                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14H9V8h2v8zm4 0h-2V8h2v8z"/>
                                                        </svg>
                                                        Popularite Google
                                                    </div>
                                                </th>
                                                <th>EAN</th>
                                                <th>Groupe</th>
                                                <th>Marque</th>
                                                <th>Désignation</th>
                                                <th>Prix Cosma</th>
                                                <th>
                                                    <button @click="$wire.setSortBy ('rank_qty')" class="flex items-center gap-1 hover:underline cursor-pointer">
                                                        Qté vendue
                                                        <svg class="w-3 h-3" viewBox="0 0 16 16" fill="currentColor"><path d="M8 4l3 4H5l3-4zm0 8l-3-4h6l-3 4z"/></svg>
                                                    </button>
                                                </th>
                                                <th>
                                                    <button @click="$wire.setSortBy ('rank_ca')" class="flex items-center gap-1 hover:underline cursor-pointer">
                                                        CA total
                                                        <svg class="w-3 h-3" viewBox="0 0 16 16" fill="currentColor"><path d="M8 4l3 4H5l3-4zm0 8l-3-4h6l-3 4z"/></svg>
                                                    </button>
                                                </th>
                                                <th>PGHT</th>
                                                {{-- ▲ Fin colonnes Google --}}
                                                @foreach($sites as $site)
                                                    <th class="text-right">{{ $site->name }}</th>
                                                @endforeach
                                                <th class="text-right">Prix marché</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($comparisons as $comparison)
                                                @php
                                                    $row       = $comparison['row'];
                                                    $prixCosma = $row->prix_vente_cosma;
                                                    $ean       = $row->ean ?? null;
                                                    $eanKey    = $ean ? str_pad(preg_replace('/\D/', '', $ean), 14, '0', STR_PAD_LEFT) : null;
                                                    $pop       = $eanKey ? ($popularityRanks[$eanKey] ?? null) : null;

                                                    $googleRank = $pop['rank'] ?? null;
                                                    $delta      = $pop['delta'] ?? null;
                                                    $deltaSign  = $pop['delta_sign'] ?? null;
                                                @endphp
                                                <tr class="hover">
                                                    <th>
                                                        <span class="font-semibold {{ $sortBy === 'rank_qty' ? 'text-orange-900' : '' }}">
                                                            {{ $sortBy === 'rank_qty' ? "#".$row->rank_qty : $row->rank_qty }}
                                                        </span>
                                                    </th>
                                                    <th>
                                                        <span class="font-semibold {{ $sortBy === 'rank_ca' ? 'text-orange-900' : '' }}">
                                                            {{ $sortBy === 'rank_ca' ? "#".$row->rank_ca : $row->rank_ca }}
                                                        </span>
                                                    </th>

                                                    <td class="text-center">
                                                        @if($googleRank)
                                                            <div class="flex flex-col items-center gap-0.5">
                                                                <span class="font-bold font-mono text-sm">
                                                                    #{{ number_format($googleRank, 0, ',', '') }}
                                                                </span>
                                                                @if($delta !== null)
                                                                    <span class="text-xs font-bold {{ $deltaSign === '+' ? 'text-success' : ($deltaSign === '-' ? 'text-error' : 'text-gray-400') }}">
                                                                        {{ $deltaSign === '+' ? '+' : '' }}{{ $delta }}
                                                                    </span>
                                                                @endif
                                                            </div>
                                                        @else
                                                            <span class="text-gray-300 text-xs">—</span>
                                                        @endif
                                                    </td>

                                                    <td>
                                                        <span class="font-mono text-xs">{{ $row->ean }}</span>
                                                    </td>
                                                    <td><div class="text-xs">{{ $row->groupe ?? '—' }}</div></td>
                                                    <td><div class="text-xs font-semibold">{{ $row->marque ?? '—' }}</div></td>
                                                    <td>
                                                        <div class="font-bold max-w-xs truncate" title="{{ $row->designation_produit }}">
                                                            {{ $row->designation_produit ?? '—' }}
                                                        </div>
                                                    </td>
                                                    <td class="text-right font-semibold text-primary">
                                                        {{ number_format($prixCosma, 2, ',', ' ') }} €
                                                    </td>
                                                    <td>
                                                        <span class="font-semibold {{ $sortBy === 'rank_qty' ? 'text-orange-900' : '' }}">
                                                            {{ number_format($row->total_qty_sold, 0, ',', ' ') }}
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="font-semibold {{ $sortBy === 'rank_ca' ? 'text-orange-900' : '' }}">
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
                                                                    $siteData  = $comparison['sites'][$site->id];
                                                                    $textClass = '';
                                                                    if ($siteData['price_percentage'] !== null) {
                                                                        $textClass = $prixCosma > $siteData['prix_ht'] ? 'text-error' : 'text-success';
                                                                    }
                                                                @endphp
                                                                <div class="flex flex-col gap-1 items-end">
                                                                    <a href="{{ $siteData['url'] }}" target="_blank"
                                                                        class="link link-primary text-xs font-semibold"
                                                                        title="{{ $siteData['name'] }}">
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
                                                                $textClassMoyen = $prixCosma > $comparison['prix_moyen_marche'] ? 'text-error' : 'text-success';
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
                                                <th class="text-center">Popularite Google</th>
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
