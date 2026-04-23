<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\Product;
use App\Models\Site;
use App\Services\GoogleMerchantService;
use App\Services\ApiScraperService;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public int $perPage = 25;
    public int $currentPage = 1;
    public string $activeCountry = 'FR';
    public array $groupeFilter = [];

    public array $countries = [
        'FR' => 'France',
        'BE' => 'Belgique',
        'NL' => 'Pays-Bas',
        'DE' => 'Allemagne',
        'ES' => 'Espagne',
        'IT' => 'Italie',
    ];

    protected array $countryCodeMap = [
        'FR' => 'FR', 'BE' => 'BE', 'NL' => 'NL',
        'DE' => 'DE', 'ES' => 'ES', 'IT' => 'IT',
    ];

    public $somme_prix_marche_total = 0;
    public $somme_gain = 0;
    public $somme_perte = 0;
    public $percentage_gain_marche = 0;
    public $percentage_perte_marche = 0;

    protected GoogleMerchantService $googleMerchantService;
    protected ApiScraperService $apiScraperService;

    public function boot(GoogleMerchantService $googleMerchantService, ApiScraperService $apiScraperService): void
    {
        $this->googleMerchantService = $googleMerchantService;
        $this->apiScraperService     = $apiScraperService;
    }

    // ─── Computed: Total count ────────────────────────────────────────────────

    public function getSalesTotalProperty(): int
    {
        set_time_limit(0);

        $groupeCondition = '';
        $params = [];

        if (!empty($this->groupeFilter)) {
            $placeholders    = implode(',', array_fill(0, count($this->groupeFilter), '?'));
            $groupeCondition = "AND SUBSTRING_INDEX(CAST(product_char.name AS CHAR CHARACTER SET utf8mb4), ' - ', 1) IN ($placeholders)";
            $params          = $this->groupeFilter;
        }

        $cacheKey = 'top_products_total_' . md5($this->activeCountry . date('Y-m-d') . implode(',', $this->groupeFilter));

        return Cache::remember($cacheKey, now()->addHour(), function () use ($groupeCondition, $params) {
            $sql = "
                SELECT COUNT(*) as total
                FROM (
                    SELECT produit.sku
                    FROM catalog_product_entity AS produit
                    LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
                    INNER JOIN product_int ON product_int.entity_id = produit.entity_id
                        AND product_int.status IN (0, 1)
                    WHERE 1=1
                        AND produit.sku REGEXP '^[0-9]+$'
                        {$groupeCondition}
                    GROUP BY produit.sku
                ) AS subquery
            ";

            $result = DB::connection('mysqlMagento')->selectOne($sql, $params);
            return (int) ($result->total ?? 0);
        });
    }

    // ─── Computed: Paginated sales ────────────────────────────────────────────

    public function getSalesProperty()
    {
        set_time_limit(0);

        $groupeCondition = '';
        $params = [];

        if (!empty($this->groupeFilter)) {
            $placeholders    = implode(',', array_fill(0, count($this->groupeFilter), '?'));
            $groupeCondition = "AND SUBSTRING_INDEX(product_char.name, ' - ', 1) IN ($placeholders)";
            $params          = $this->groupeFilter;
        }

        $offset    = ($this->currentPage - 1) * $this->perPage;
        $params[]  = $this->perPage;
        $params[]  = $offset;

        $cacheKey = 'top_products_' . md5(
            $this->activeCountry . date('Y-m-d')
            . implode(',', $this->groupeFilter)
            . $this->currentPage . $this->perPage
        );

        return Cache::remember($cacheKey, now()->addHour(), function () use ($groupeCondition, $params) {
            $sql = "
                SELECT
                    produit.sku AS ean,
                    SUBSTRING_INDEX(product_char.name, ' - ', 1) AS groupe,
                    SUBSTRING_INDEX(SUBSTRING_INDEX(product_char.name, ' - ', 2), ' - ', -1) AS marque,
                    SUBSTRING_INDEX(SUBSTRING_INDEX(product_char.name, ' - ', 3), ' - ', -1) AS designation_produit,
                    CASE
                        WHEN product_decimal.special_price IS NOT NULL
                            THEN ROUND(product_decimal.special_price, 2)
                        ELSE ROUND(product_decimal.price, 2)
                    END AS prix_vente_cosma,
                    ROUND(product_decimal.cost, 2) AS cost,
                    ROUND(product_decimal.prix_achat_ht, 2) AS pght,
                    stock_item.qty as quantity
                FROM catalog_product_entity AS produit
                LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
                LEFT JOIN product_decimal ON product_decimal.entity_id = produit.entity_id
                LEFT JOIN cataloginventory_stock_item AS stock_item ON stock_item.product_id = produit.entity_id
                INNER JOIN product_int ON product_int.entity_id = produit.entity_id
                    AND product_int.status IN (0, 1)
                WHERE 1=1
                    AND produit.sku REGEXP '^[0-9]+$'
                {$groupeCondition}
                GROUP BY produit.sku
                ORDER BY produit.entity_id DESC
                LIMIT ? OFFSET ?
            ";

            DB::connection('mysqlMagento')->getPdo()->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

            $results = DB::connection('mysqlMagento')->select($sql, $params);

            foreach ($results as $result) {
                foreach (['ean', 'designation_produit', 'marque', 'groupe'] as $field) {
                    if (!isset($result->$field)) continue;
                    if (!mb_check_encoding($result->$field, 'UTF-8')) {
                        $result->$field = mb_convert_encoding($result->$field, 'UTF-8', 'ISO-8859-1');
                    }
                }
            }

            return $results;
        });
    }

    // ─── Computed: Google Merchant popularity ranks ───────────────────────────

    public function getPopularityRanksProperty(): array
    {
        set_time_limit(0);

        $sales = $this->sales;
        if (empty($sales)) return [];

        $eans = collect($sales)
            ->pluck('ean')->filter()
            ->map(fn($ean) => mb_check_encoding($ean, 'UTF-8') ? $ean : mb_convert_encoding($ean, 'UTF-8', 'ISO-8859-1'))
            ->unique()->values()->toArray();

        if (empty($eans)) return [];

        $toGtin14    = fn(string $ean): string => str_pad(preg_replace('/\D/', '', $ean), 14, '0', STR_PAD_LEFT);
        $countryCode = $this->countryCodeMap[$this->activeCountry] ?? $this->activeCountry;
        $gtins14     = array_unique(array_map($toGtin14, $eans));

        $cacheKey = 'google_popularity_v2_' . md5($countryCode . implode(',', $gtins14));

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($gtins14, $countryCode, $toGtin14) {
            $gtinList = implode("', '", $gtins14);

            $query = "
                SELECT
                    report_granularity, report_date, report_category_id,
                    category_l1, category_l2, category_l3,
                    brand, title, variant_gtins, rank, previous_rank,
                    report_country_code, relative_demand, previous_relative_demand,
                    relative_demand_change, inventory_status, brand_inventory_status
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
                    $data        = $row['bestSellersProductClusterView'] ?? [];
                    $variantGtins = $data['variantGtins'] ?? [];

                    $rank     = isset($data['rank'])         ? (int) $data['rank']         : null;
                    $prevRank = isset($data['previousRank']) ? (int) $data['previousRank'] : null;
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

                return $ranksByGtin;

            } catch (\Exception $e) {
                Log::error('Google Merchant popularity rank error: ' . $e->getMessage());
                return [];
            }
        });
    }

    // ─── Computed: Available groupes ──────────────────────────────────────────

    public function getAvailableGroupesProperty()
    {
        $cacheKey = 'available_groupes_' . md5($this->activeCountry . date('Y-m-d'));

        return Cache::remember($cacheKey, now()->addHour(), function () {
            $sql = "
                SELECT DISTINCT
                    SUBSTRING_INDEX(CAST(product_char.name AS CHAR CHARACTER SET utf8mb4), ' - ', 1) AS groupe
                FROM catalog_product_entity AS produit
                LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
                INNER JOIN product_int ON product_int.entity_id = produit.entity_id
                    AND product_int.status IN (0, 1, 2)
                WHERE 1=1
                    AND produit.sku REGEXP '^[0-9]+$'
                    AND SUBSTRING_INDEX(CAST(product_char.name AS CHAR CHARACTER SET utf8mb4), ' - ', 1) IS NOT NULL
                    AND SUBSTRING_INDEX(CAST(product_char.name AS CHAR CHARACTER SET utf8mb4), ' - ', 1) != ''
                ORDER BY SUBSTRING_INDEX(CAST(product_char.name AS CHAR CHARACTER SET utf8mb4), ' - ', 1) ASC
            ";

            return collect(DB::connection('mysqlMagento')->select($sql, []))
                ->pluck('groupe')
                ->map(fn($g) => mb_check_encoding($g, 'UTF-8') ? $g : mb_convert_encoding($g, 'UTF-8', 'ISO-8859-1'))
                ->toArray();
        });
    }

    // ─── Computed: Price comparisons ──────────────────────────────────────────

    public function getComparisonsProperty()
    {
        set_time_limit(0);

        $sites   = Site::where('country_code', $this->activeCountry)->orderBy('name')->get();
        $siteIds = $sites->pluck('id')->toArray();

        $this->somme_prix_marche_total = 0;
        $this->somme_gain  = 0;
        $this->somme_perte = 0;

        $eans = collect($this->sales)->pluck('ean')->filter()->unique()->values()->toArray();

        $allScrapedProducts = !empty($eans) && !empty($siteIds)
            ? Product::whereIn('ean', $eans)
                ->whereIn('web_site_id', $siteIds)
                ->with('website')
                ->get()
                ->groupBy('ean')
                ->map(fn($g) => $g->keyBy('web_site_id'))
            : collect([]);

        $comparisons = [];

        foreach ($this->sales as $row) {
            $scrapedProducts = $allScrapedProducts[$row->ean] ?? collect([]);

            $comparison = [
                'row'               => $row,
                'sites'             => [],
                'prix_moyen_marche' => null,
                'percentage_marche' => null,
                'difference_marche' => null,
            ];

            $somme_prix_marche = 0;
            $nombre_site       = 0;
            $prixCosma         = $row->prix_vente_cosma;

            foreach ($sites as $site) {
                if (!isset($scrapedProducts[$site->id])) {
                    $comparison['sites'][$site->id] = null;
                    continue;
                }

                $scrapedProduct  = $scrapedProducts[$site->id];
                $priceDiff       = null;
                $pricePercentage = null;

                if ($prixCosma > 0 && $scrapedProduct->prix_ht > 0) {
                    $priceDiff       = $scrapedProduct->prix_ht - $prixCosma;
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
            }

            if ($somme_prix_marche > 0 && $prixCosma > 0) {
                $comparison['prix_moyen_marche'] = $somme_prix_marche / $nombre_site;
                $priceDiff_marche                = $comparison['prix_moyen_marche'] - $prixCosma;
                $comparison['percentage_marche'] = round(($priceDiff_marche / $prixCosma) * 100, 2);
                $comparison['difference_marche'] = $priceDiff_marche;

                $this->somme_prix_marche_total += $comparison['prix_moyen_marche'];
                if ($priceDiff_marche > 0) $this->somme_gain  += $priceDiff_marche;
                else                       $this->somme_perte += $priceDiff_marche;
            }

            $comparisons[] = $comparison;
        }

        if ($this->somme_prix_marche_total > 0) {
            $this->percentage_gain_marche  = ((($this->somme_prix_marche_total + $this->somme_gain)  * 100) / $this->somme_prix_marche_total) - 100;
            $this->percentage_perte_marche = ((($this->somme_prix_marche_total + $this->somme_perte) * 100) / $this->somme_prix_marche_total) - 100;
        }

        return collect($comparisons);
    }

    // ─── Computed: Sites ──────────────────────────────────────────────────────

    public function getSitesProperty()
    {
        return Site::where('country_code', $this->activeCountry)->orderBy('name')->get();
    }

    // ─── Lifecycle hooks ──────────────────────────────────────────────────────

    public function setPage(int $page): void
    {
        $this->currentPage = $page;
    }

    // ─── Actions ──────────────────────────────────────────────────────────────

    public function clearCache(): void
    {
        $toGtin14    = fn(string $ean): string => str_pad(preg_replace('/\D/', '', $ean), 14, '0', STR_PAD_LEFT);
        $countryCode = $this->countryCodeMap[$this->activeCountry] ?? $this->activeCountry;
        $gtins14     = array_unique(array_map($toGtin14, collect($this->sales)->pluck('ean')->filter()->toArray()));

        Cache::forget('top_products_' . md5(
            $this->activeCountry . date('Y-m-d') . implode(',', $this->groupeFilter)
        ));
        Cache::forget('top_products_total_' . md5(
            $this->activeCountry . date('Y-m-d') . implode(',', $this->groupeFilter)
        ));
        Cache::forget('available_groupes_' . md5(
            $this->activeCountry . date('Y-m-d')
        ));
        Cache::forget('google_popularity_v2_' . md5($countryCode . implode(',', $gtins14)));

        $this->resetScrapingState();
    }

    // ─── Export XLSX ──────────────────────────────────────────────────────────

    public function exportXlsx(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        set_time_limit(0);

        $sites           = $this->sites;
        $comparisons     = $this->comparisons;
        $popularityRanks = $this->popularityRanks;

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $countryLabel = $this->countries[$this->activeCountry] ?? $this->activeCountry;
        $sheet->setTitle('Ventes ' . $countryLabel);

        $baseHeaders = ['EAN', 'Groupe', 'Marque', 'Désignation', 'Prix Cosma', 'PGHT', 'Quantite', 'Rang Google'];

        $lastColIndex  = count($baseHeaders) + $sites->count();
        $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastColIndex + 1);

        $sheet->getColumnDimension('A')->setAutoSize(false)->setWidth(16);
        $sheet->getColumnDimension('B')->setAutoSize(false)->setWidth(18);
        $sheet->getColumnDimension('C')->setAutoSize(false)->setWidth(18);
        $sheet->getColumnDimension('D')->setAutoSize(false)->setWidth(40);

        // ── Pass 1: compute summary stats ─────────────────────────────────────
        $somme_prix_marche_total = 0;
        $somme_gain  = 0;
        $somme_perte = 0;
        $comparisonsAvecPrix = 0;

        foreach ($comparisons as $comparison) {
            if ($comparison['prix_moyen_marche'] === null) continue;
            $somme_prix_marche_total += $comparison['prix_moyen_marche'];
            $diff = $comparison['difference_marche'];
            if ($diff > 0) $somme_gain  += $diff;
            else           $somme_perte += $diff;
            $comparisonsAvecPrix++;
        }

        $pct_gain  = $somme_prix_marche_total > 0 ? ((($somme_prix_marche_total + $somme_gain)  * 100) / $somme_prix_marche_total) - 100 : 0;
        $pct_perte = $somme_prix_marche_total > 0 ? ((($somme_prix_marche_total + $somme_perte) * 100) / $somme_prix_marche_total) - 100 : 0;

        // ── Row 1: info line ──────────────────────────────────────────────────
        $groupeLabel = !empty($this->groupeFilter) ? implode(', ', $this->groupeFilter) : 'Tous';
        $infoLine = [
            'Pays'              => $countryLabel,
            'Groupe(s)'         => $groupeLabel,
            'Produits exportés' => count($this->sales),
            'Produits comparés' => $comparisonsAvecPrix,
        ];

        $col = 1;
        foreach ($infoLine as $label => $value) {
            $labelCell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . '1';
            $valueCell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1) . '1';
            $sheet->setCellValue($labelCell, $label . ' :');
            $sheet->setCellValue($valueCell, $value);
            $sheet->getStyle($labelCell)->getFont()->setBold(true)->setName('Arial')->setSize(9);
            $sheet->getStyle($valueCell)->getFont()->setName('Arial')->setSize(9);
            $col += 2;
        }

        // ── Row 2: KPI line ───────────────────────────────────────────────────
        $kpis = [
            ['↓ Moins chers (€)',  $comparisonsAvecPrix > 0 ? number_format(abs($somme_gain  / $comparisonsAvecPrix), 2, ',', ' ') . ' €' : 'N/A', '1A7A3C'],
            ['↓ Moins chers (%)',  number_format(abs($pct_gain),  2, ',', ' ') . ' %', '1A7A3C'],
            ['↑ Plus chers (€)',   $comparisonsAvecPrix > 0 ? number_format(abs($somme_perte / $comparisonsAvecPrix), 2, ',', ' ') . ' €' : 'N/A', 'CC0000'],
            ['↑ Plus chers (%)',   number_format(abs($pct_perte), 2, ',', ' ') . ' %', 'CC0000'],
        ];

        $col = 1;
        foreach ($kpis as [$label, $value, $color]) {
            $labelCell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . '2';
            $valueCell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1) . '2';
            $sheet->setCellValue($labelCell, $label . ' :');
            $sheet->getStyle($labelCell)->getFont()->setBold(true)->setName('Arial')->setSize(9);
            $sheet->setCellValue($valueCell, $value);
            $sheet->getStyle($valueCell)->getFont()->getColor()->setRGB($color);
            $sheet->getStyle($valueCell)->getFont()->setBold(true)->setName('Arial')->setSize(9);
            $col += 2;
        }

        $sheet->getStyle('A1:' . $lastColLetter . '2')->applyFromArray([
            'fill'    => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EDF2F7']],
            'borders' => ['outline' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, 'color' => ['rgb' => 'CBD5E0']]],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(16);
        $sheet->getRowDimension(2)->setRowHeight(16);

        // ── Row 3: column headers ─────────────────────────────────────────────
        $headerRow    = 3;
        $dataStartRow = 4;
        $row          = $dataStartRow;

        $hColIdx = 0;
        foreach ($baseHeaders as $header) {
            $sheet->setCellValue(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($hColIdx + 1) . $headerRow,
                $header
            );
            $hColIdx++;
        }
        foreach ($sites as $site) {
            $sheet->setCellValue(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($hColIdx + 1) . $headerRow,
                $site->name
            );
            $hColIdx++;
        }
        $sheet->setCellValue(
            \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($hColIdx + 1) . $headerRow,
            'Prix marché'
        );

        $sheet->getStyle('A' . $headerRow . ':' . $lastColLetter . $headerRow)->applyFromArray([
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '2D3748']],
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Arial', 'size' => 10],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension($headerRow)->setRowHeight(20);

        // ── Data rows ─────────────────────────────────────────────────────────
        $colGoogle = 'H';

        foreach ($comparisons as $comparison) {
            $r      = $comparison['row'];
            $ean    = $r->ean ?? null;
            $eanKey = $ean ? str_pad(preg_replace('/\D/', '', $ean), 14, '0', STR_PAD_LEFT) : null;
            $pop    = $eanKey ? ($popularityRanks[$eanKey] ?? null) : null;

            $sheet->setCellValue('A' . $row, $r->ean ?? '');
            $sheet->setCellValue('B' . $row, $r->groupe ?? '');
            $sheet->setCellValue('C' . $row, $r->marque ?? '');
            $sheet->setCellValue('D' . $row, $r->designation_produit ?? '');
            $sheet->setCellValue('E' . $row, $r->prix_vente_cosma);
            $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('#,##0.00 "€"');
            $sheet->setCellValue('F' . $row, $r->pght ?: '');
            if ($r->pght) {
                $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0.00 "€"');
            }
            $sheet->setCellValue('G' . $row, number_format($r->quantity, 0, ',', ' '));

            if ($pop && ($pop['rank'] ?? null) !== null) {
                $googleRank = $pop['rank'];
                $delta      = $pop['delta'] ?? null;
                $deltaSign  = $pop['delta_sign'] ?? null;

                $richText = new \PhpOffice\PhpSpreadsheet\RichText\RichText();
                $runRank  = $richText->createTextRun('#' . $googleRank);
                $runRank->getFont()->setBold(true)->setName('Arial');
                $runRank->getFont()->getColor()->setRGB('000000');

                if ($delta !== null) {
                    $deltaColor = match($deltaSign) {
                        '+'     => 'FF1A7A3C',
                        '-'     => 'FFCC0000',
                        default => 'FF888888',
                    };
                    $runDelta = $richText->createTextRun(' (' . ($deltaSign === '+' ? '+' : '') . $delta . ')');
                    $runDelta->getFont()->setBold(true)->setName('Arial');
                    $runDelta->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($deltaColor));
                }

                $sheet->getCell($colGoogle . $row)->setValue($richText);
                $sheet->getStyle($colGoogle . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            } else {
                $sheet->setCellValue($colGoogle . $row, '—');
                $sheet->getStyle($colGoogle . $row)->getFont()->getColor()->setRGB('AAAAAA');
                $sheet->getStyle($colGoogle . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            }

            if (($row - $dataStartRow) % 2 === 0) {
                $sheet->getStyle('A' . $row . ':' . $lastColLetter . $row)->applyFromArray([
                    'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F7FAFC']],
                ]);
            }

            $colIdx = count($baseHeaders);
            foreach ($sites as $site) {
                $cellCoord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1) . $row;

                $liveEntry = $this->livePrices[$r->ean][$site->id] ?? null;
                $hasError  = $this->scrapeErrors[$r->ean][$site->id] ?? false;
                $siteData  = $liveEntry
                    ? array_merge($comparison['sites'][$site->id] ?? [], $liveEntry)
                    : ($comparison['sites'][$site->id] ?? null);

                if ($siteData) {
                    $pricePercentage = isset($siteData['prix_ht']) && $r->prix_vente_cosma > 0
                        ? round((($siteData['prix_ht'] - $r->prix_vente_cosma) / $r->prix_vente_cosma) * 100, 2)
                        : ($siteData['price_percentage'] ?? null);

                    $priceColor = $r->prix_vente_cosma > $siteData['prix_ht'] ? 'FFCC0000' : 'FF1A7A3C';
                    if ($pricePercentage === null) $priceColor = 'FF000000';

                    $statusLabel = $liveEntry ? '[LIVE ' . ($liveEntry['scraped_at'] ?? '') . ']'
                                             : ($hasError ? '[ERREUR SCRAPE]' : '[DB]');

                    $prixText  = number_format((float)$siteData['prix_ht'], 2, ',', ' ') . ' €';
                    if ($pricePercentage !== null) {
                        $prixText .= ' (' . ($pricePercentage > 0 ? '+' : '') . $pricePercentage . '%)';
                    }
                    $prixText .= ' ' . $statusLabel;

                    $richText = new \PhpOffice\PhpSpreadsheet\RichText\RichText();
                    $runPrix  = $richText->createTextRun($prixText);
                    $runPrix->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($priceColor));
                    $runPrix->getFont()->setName('Arial');

                    $eanToDisplay = $liveEntry['ean_live'] ?? ($siteData['ean'] ?? null);
                    if (!empty($eanToDisplay)) {
                        $runEan = $richText->createTextRun("\nEAN : " . $eanToDisplay);
                        $runEan->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF888888'));
                        $runEan->getFont()->setName('Arial')->setSize(8);
                    }

                    $urlToLink = $liveEntry['url'] ?? ($siteData['url'] ?? null);
                    if (!empty($urlToLink)) {
                        $runLien = $richText->createTextRun("\nVoir le produit");
                        $runLien->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF0563C1'));
                        $runLien->getFont()->setUnderline(\PhpOffice\PhpSpreadsheet\Style\Font::UNDERLINE_SINGLE);
                        $runLien->getFont()->setName('Arial');
                    }

                    $sheet->getCell($cellCoord)->setValue($richText);
                    $sheet->getStyle($cellCoord)->getAlignment()->setWrapText(true);
                    $sheet->getStyle($cellCoord)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);

                    if (!empty($urlToLink)) {
                        $sheet->getCell($cellCoord)->getHyperlink()->setUrl($urlToLink);
                    }
                } else {
                    $label = $hasError ? 'Erreur scrape' : 'N/A';
                    $sheet->setCellValue($cellCoord, $label);
                    $sheet->getStyle($cellCoord)->getFont()->getColor()->setRGB($hasError ? 'CC0000' : 'AAAAAA');
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

        for ($i = 5; $i <= $lastColIndex + 1; $i++) {
            $sheet->getColumnDimension(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i)
            )->setAutoSize(true);
        }

        $sheet->freezePane('A' . $dataStartRow);
        $sheet->getStyle('A' . $headerRow . ':' . $lastColLetter . $lastDataRow)->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'DDDDDD']]],
            'font'    => ['name' => 'Arial', 'size' => 9],
        ]);
        $sheet->getStyle('E' . $dataStartRow . ':' . $lastColLetter . $lastDataRow)
            ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);

        $exportDir = storage_path('app/public/exports');
        if (!file_exists($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        $groupeSuffix = !empty($this->groupeFilter)
            ? '_' . implode('-', array_map('strtolower', $this->groupeFilter))
            : '';

        $fileName = 'produit_' . strtolower($this->activeCountry) . $groupeSuffix . '_' . date('His') . '.xlsx';
        $filePath = $exportDir . '/' . $fileName;

        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($filePath);

        return response()->download($filePath, $fileName)->deleteFileAfterSend(true);
    }

    // ─── View data ────────────────────────────────────────────────────────────

    public function with(): array
    {
        $comparisons = $this->comparisons;
        $total       = $this->salesTotal;
        $lastPage    = (int) ceil($total / $this->perPage);

        return [
            'sales'                     => $this->sales,
            'comparisons'               => $comparisons,
            'sites'                     => $this->sites,
            'availableGroupes'          => $this->availableGroupes,
            'popularityRanks'           => $this->popularityRanks,
            'comparisonsAvecPrixMarche' => $comparisons->filter(fn($c) => $c['prix_moyen_marche'] !== null)->count(),
            'somme_gain'                => $this->somme_gain,
            'somme_perte'               => $this->somme_perte,
            'percentage_gain_marche'    => $this->percentage_gain_marche,
            'percentage_perte_marche'   => $this->percentage_perte_marche,
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

                        {{-- Toolbar --}}
                        <div class="flex flex-wrap items-center justify-between gap-4 mb-4 mt-6">
                            <div>
                                <h1 class="text-base font-semibold text-gray-900">Marché — {{ $label }}</h1>
                                <p class="mt-0.5 text-sm text-gray-500">
                                    Produits · {{ count($sales) }} résultat(s)
                                    @if(!empty($groupeFilter))
                                        · Groupe(s) : {{ implode(', ', $groupeFilter) }}
                                    @endif
                                </p>
                            </div>

                            <div class="flex flex-wrap items-center gap-3">

                                {{-- Groupe filter --}}
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

                                {{-- Rafraîchir --}}
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

                                <div class="divider divider-horizontal mx-0"></div>

                                {{-- Export XLSX --}}
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

                                <div class="divider divider-horizontal mx-0"></div>

                                {{-- ── Statut du scraping live (piloté par JS pur) ── --}}
                                <div id="live-scraping-status" class="flex flex-col items-center gap-1">
                                    <div class="flex items-center gap-1.5 text-xs text-gray-400">
                                        <span class="loading loading-dots loading-xs"></span>
                                        Initialisation…
                                    </div>
                                </div>
                                {{-- ── /Statut scraping ── --}}

                            </div>
                        </div>

                        <div class="flex items-center justify-between w-full mt-3">
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-gray-400">Par page</span>
                                <select wire:model.live="perPage" class="select select-sm select-bordered w-20">
                                    <option value="25">25</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                    <option value="200">200</option>
                                    <option value="500">500</option>
                                    <option value="1000">1000</option>
                                    <option value="{{ $salesTotal }}">Tous</option>
                                </select>
                            </div>

                            <p class="mt-0.5 text-sm text-gray-500 text-center">
                                {{ $salesTotal }} résultat(s) au total · Page {{ $currentPage }}/{{ $lastPage }}
                                @if(!empty($groupeFilter))
                                    · Groupe(s) : {{ implode(', ', $groupeFilter) }}
                                @endif
                            </p>

                            @if($lastPage > 1)
                                <div class="flex items-center">
                                    <div class="join">
                                        <button class="join-item btn btn-sm" wire:click="setPage(1)" @disabled($currentPage === 1)>«</button>
                                        <button class="join-item btn btn-sm" wire:click="setPage({{ $currentPage - 1 }})" @disabled($currentPage === 1)>‹</button>

                                        @foreach(range(max(1, $currentPage - 2), min($lastPage, $currentPage + 2)) as $p)
                                            <button class="join-item btn btn-sm {{ $p === $currentPage ? 'btn-active btn-primary' : '' }}"
                                                wire:click="setPage({{ $p }})">{{ $p }}</button>
                                        @endforeach

                                        <button class="join-item btn btn-sm" wire:click="setPage({{ $currentPage + 1 }})" @disabled($currentPage === $lastPage)>›</button>
                                        <button class="join-item btn btn-sm" wire:click="setPage({{ $lastPage }})" @disabled($currentPage === $lastPage)>»</button>
                                    </div>
                                </div>
                            @else
                                <div></div>
                            @endif
                        </div>

                        <br>

                        {{-- Table --}}
                        <div class="relative">
                            <div wire:loading wire:target="groupeFilter, perPage, setPage"
                                class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-3 rounded-lg bg-white/70 backdrop-blur-sm">
                                <span class="loading loading-spinner loading-lg text-primary"></span>
                                <span class="text-sm font-medium">Mise à jour…</span>
                            </div>

                            @if(count($sales) === 0)
                                <div class="alert alert-info">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="stroke-current shrink-0 w-6 h-6">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    <span>Aucune vente trouvée{{ !empty($groupeFilter) ? ' pour ce(s) groupe(s)' : '' }}.</span>
                                </div>
                            @else
                                <div class="overflow-x-auto overflow-y-auto max-h-[70vh]"
                                    wire:loading.class="opacity-40 pointer-events-none"
                                    wire:target="groupeFilter">
                                    <table class="table table-xs table-pin-rows table-pin-cols">
                                        <thead>
                                            <tr>
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
                                                <th>Quantite</th>
                                                <th>Prix Cosma</th>
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
                                                    $row       = $comparison['row'];
                                                    $prixCosma = $row->prix_vente_cosma;
                                                    $ean       = $row->ean ?? null;
                                                    $eanKey    = $ean ? str_pad(preg_replace('/\D/', '', $ean), 14, '0', STR_PAD_LEFT) : null;
                                                    $pop       = $eanKey ? ($popularityRanks[$eanKey] ?? null) : null;
                                                    $googleRank = $pop['rank'] ?? null;
                                                    $delta     = $pop['delta'] ?? null;
                                                    $deltaSign = $pop['delta_sign'] ?? null;
                                                @endphp
                                                <tr class="hover">
                                                    <td class="text-center">
                                                        @if($googleRank)
                                                            <div class="flex flex-col items-center gap-0.5">
                                                                <span class="font-bold font-mono text-sm">#{{ number_format($googleRank, 0, ',', '') }}</span>
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
                                                    <td><span class="font-mono text-xs">{{ $row->ean }}</span></td>
                                                    <td><div class="text-xs">{{ $row->groupe ?? '—' }}</div></td>
                                                    <td><div class="text-xs font-semibold">{{ $row->marque ?? '—' }}</div></td>
                                                    <td>
                                                        <div class="font-bold max-w-xs truncate" title="{{ $row->designation_produit }}">
                                                            {{ $row->designation_produit ?? '—' }}
                                                        </div>
                                                    </td>
                                                    <td class="text-right text-xs">
                                                        {{ number_format($row->quantity, 0, ',', ' ') ?? '0' }}
                                                    </td>
                                                    <td class="text-right font-semibold text-primary">
                                                        {{ number_format($prixCosma, 2, ',', ' ') }} €
                                                    </td>
                                                    <td class="text-right text-xs">
                                                        @if($row->pght)
                                                            {{ number_format($row->pght, 2, ',', ' ') }} €
                                                        @else
                                                            <span class="text-gray-400">—</span>
                                                        @endif
                                                    </td>

                                                    @foreach($this->sites as $site)
                                                        @php
                                                            $siteData = $comparison['sites'][$site->id] ?? null;
                                                        @endphp
                                                        @if($siteData)
                                                            @php
                                                                $prixAffiché = (float) $siteData['prix_ht'];
                                                                $prixDiff    = $prixCosma > 0 ? $prixAffiché - $prixCosma : null;
                                                                $prixPct     = ($prixCosma > 0 && $prixAffiché > 0)
                                                                    ? round(($prixDiff / $prixCosma) * 100, 2)
                                                                    : null;
                                                                $colorClass  = $prixPct !== null
                                                                    ? ($prixCosma > $prixAffiché ? 'text-error' : 'text-success')
                                                                    : '';
                                                                $url = $siteData['url'] ?? '#';
                                                            @endphp
                                                            {{--
                                                                data-* : lus par liveScraper.js pour patcher le DOM en live.
                                                                data-prix-cosma : pour recalculer le % côté JS.
                                                                data-scrape-url : URL à scraper.
                                                                data-ean         : EAN du produit.
                                                                data-site-id     : ID du site.
                                                            --}}
                                                            <td class="text-right price-cell"
                                                                data-ean="{{ $row->ean }}"
                                                                data-site-id="{{ $site->id }}"
                                                                data-prix-cosma="{{ $prixCosma }}"
                                                                data-scrape-url="{{ $url }}"
                                                                data-db-prix="{{ $prixAffiché }}"
                                                                data-db-url="{{ $url }}"
                                                                data-db-vendor="{{ $siteData['vendor'] ?? '' }}"
                                                                data-db-name="{{ $siteData['name'] ?? '' }}">
                                                                {{-- Contenu initial : prix DB — le JS le remplacera si live disponible --}}
                                                                <div class="flex flex-col gap-0.5 items-end">
                                                                    <div class="flex items-center gap-1">
                                                                        <span class="badge badge-xs badge-ghost opacity-50 badge-db">DB</span>
                                                                        <a href="{{ $url }}" target="_blank"
                                                                            class="link link-primary text-xs font-semibold price-link"
                                                                            title="{{ $siteData['name'] ?? '' }}">
                                                                            {{ number_format($prixAffiché, 2) }} €
                                                                        </a>
                                                                    </div>
                                                                    @if($prixPct !== null)
                                                                        <span class="text-xs {{ $colorClass }} font-bold price-pct">
                                                                            {{ $prixPct > 0 ? '+' : '' }}{{ $prixPct }}%
                                                                        </span>
                                                                    @endif
                                                                    @if(!empty($siteData['vendor']))
                                                                        <span class="text-xs text-gray-500 truncate max-w-[120px] price-vendor"
                                                                            title="{{ $siteData['vendor'] }}">
                                                                            {{ Str::limit($siteData['vendor'], 15) }}
                                                                        </span>
                                                                    @endif
                                                                </div>
                                                            </td>
                                                        @else
                                                            <td class="text-right price-cell-na"
                                                                data-ean="{{ $row->ean }}"
                                                                data-site-id="{{ $site->id }}"
                                                                data-prix-cosma="{{ $prixCosma }}"
                                                            >
                                                                <span class="text-gray-400 text-xs price-na">N/A</span>
                                                            </td>
                                                        @endif
                                                    @endforeach

                                                    <td class="text-right text-xs">
                                                        @if($comparison['prix_moyen_marche'])
                                                            @php
                                                                $textClassMoyen = $prixCosma > $comparison['prix_moyen_marche'] ? 'text-error' : 'text-success';
                                                            @endphp
                                                            <div class="flex flex-col gap-1 items-end">
                                                                <span class="font-semibold">{{ number_format($comparison['prix_moyen_marche'], 2, ',', ' ') }} €</span>
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
                                                <th class="text-center">Popularite Google</th>
                                                <th>EAN</th>
                                                <th>Groupe</th>
                                                <th>Marque</th>
                                                <th>Désignation</th>
                                                <th>Quantite</th>
                                                <th>Prix Cosma</th>
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

{{-- ═══════════════════════════════════════════════════════════════════════════
     LIVE SCRAPER — JS pur, zéro Livewire
     ─────────────────────────────────────────────────────────────────────────
     Fonctionnement :
      1. Au chargement de la table, on collecte tous les <td class="price-cell">
         qui ont un data-scrape-url non vide.
      2. On les scrape par batches de 4 via l'endpoint https://dev.astucom.com:9038/scrap.
      3. Pour chaque résultat, on patch le DOM directement :
         - badge ⚡ live   → scraping réussi
         - badge 🔴 Erreur → scraping échoué, prix DB conservé
      4. La barre de progression dans #live-scraping-status est mise à jour en JS.
      5. Un bouton "Relancer" réinitialise et relance le cycle.
═══════════════════════════════════════════════════════════════════════════ --}}
<script>
(function () {
    'use strict';

    const BATCH_SIZE  = 4;
    const API_ENDPOINT = 'https://dev.astucom.com:9038/scrap'; // → ApiScraperController@scrape

    // ── Helpers DOM ──────────────────────────────────────────────────────────

    function fmt(num) {
        return parseFloat(num).toLocaleString('fr-FR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }) + ' €';
    }

    function pct(live, cosma) {
        if (!cosma || !live) return null;
        return (((live - cosma) / cosma) * 100).toFixed(2);
    }

    // ── Patch DOM d'une cellule après succès ─────────────────────────────────

    function patchCell(td, data, scrapedAt) {
        const cosma   = parseFloat(td.dataset.prixCosma) || 0;
        const prix    = parseFloat(data.prix_ht)         || 0;
        const url     = data.url     || td.dataset.dbUrl   || '#';
        const vendor  = data.vendor  || td.dataset.dbVendor || '';
        const name    = data.name    || td.dataset.dbName   || '';
        const p       = pct(prix, cosma);
        const cheaper = cosma > prix;
        const pctClass = p !== null ? (cheaper ? 'text-error' : 'text-success') : '';

        td.innerHTML = `
            <span class="badge badge-xs badge-warning font-bold" title="Prix live scrapé à ${scrapedAt}">⚡ live</span>
            <div class="flex flex-col gap-0.5 items-end">
                <div class="flex items-center gap-1">
                    <a href="${url}" target="_blank"
                       class="link link-primary text-xs font-semibold underline decoration-warning"
                       title="${name}">${fmt(prix)}</a>
                </div>
                ${p !== null ? `<span class="text-xs ${pctClass} font-bold">${p > 0 ? '+' : ''}${p}%</span>` : ''}
                ${vendor ? `<span class="text-xs text-gray-500 truncate max-w-[120px]" title="${vendor}">${vendor.substring(0, 15)}</span>` : ''}
                <span class="text-[10px] text-warning/70">${scrapedAt}</span>
            </div>`;
    }

    // ── Marquer une cellule comme erreur (garde le prix DB) ──────────────────

    function patchCellError(td) {
        const dbPrix  = td.dataset.dbPrix;
        const cosma   = parseFloat(td.dataset.prixCosma) || 0;
        const prix    = parseFloat(dbPrix) || 0;
        const url     = td.dataset.dbUrl   || '#';
        const vendor  = td.dataset.dbVendor || '';
        const name    = td.dataset.dbName   || '';

        if (!dbPrix) {
            // Pas de prix DB non plus → vrai N/A
            td.innerHTML = `<span class="text-gray-400 text-xs">N/A</span>`;
            return;
        }

        const p       = pct(prix, cosma);
        const cheaper = cosma > prix;
        const pctClass = p !== null ? (cheaper ? 'text-error' : 'text-success') : '';

        td.innerHTML = `
            <div class="flex flex-col gap-0.5 items-end">
                <span class="badge badge-xs badge-error opacity-70" title="Scraping échoué — prix issu de la base de données">DB</span>
                <div class="flex items-center gap-1">
                    <a href="${url}" target="_blank"
                       class="link link-primary text-xs font-semibold"
                       title="${name}">${fmt(prix)}</a>
                </div>
                ${p !== null ? `<span class="text-xs ${pctClass} font-bold">${p > 0 ? '+' : ''}${p}%</span>` : ''}
                ${vendor ? `<span class="text-xs text-gray-500 truncate max-w-[120px]">${vendor.substring(0, 15)}</span>` : ''}
            </div>`;
    }

    // ── Mettre une cellule en état "en cours de scraping" ────────────────────

    function setLoading(td) {
        // On garde le prix DB visible en fond avec un loader par-dessus
        td.style.position = 'relative';
        const loader = document.createElement('span');
        loader.className = 'loading loading-dots loading-xs text-warning opacity-60 absolute top-0 right-0';
        loader.dataset.liveLoader = '1';
        // Retirer tout loader précédent
        td.querySelectorAll('[data-live-loader]').forEach(el => el.remove());
        td.appendChild(loader);
    }

    function clearLoading(td) {
        td.querySelectorAll('[data-live-loader]').forEach(el => el.remove());
        td.style.position = '';
    }

    // ── Scraper un job individuel ─────────────────────────────────────────────

    async function scrapeJob(td) {
        const ean    = td.dataset.ean;
        const siteId = td.dataset.siteId;
        const url    = td.dataset.scrapeUrl;

        if (!url) {
            patchCellError(td);
            return;
        }

        setLoading(td);

        try {
            const res = await fetch(API_ENDPOINT, {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({ site_id: siteId, url_site: url }),
            });

            if (!res.ok) throw new Error(`HTTP ${res.status}`);

            const json = await res.json();
            const items = json.result ?? [];

            // Priorité 1 : match EAN exact / Priorité 2 : premier résultat
            const match = items.find(i => i.ean && i.ean === ean) ?? items[0] ?? null;

            clearLoading(td);

            if (match && parseFloat(match.prix_ht) > 0) {
                const now = new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                patchCell(td, match, now);
            } else {
                patchCellError(td);
            }

        } catch (e) {
            clearLoading(td);
            patchCellError(td);
            console.warn('[LiveScraper] KO', ean, siteId, e.message);
        }
    }

    // ── Lancer le scraping sur tous les TDs ──────────────────────────────────

    async function runScraping() {
        const allTds  = Array.from(document.querySelectorAll('td.price-cell[data-scrape-url]'))
                             .filter(td => td.dataset.scrapeUrl && td.dataset.scrapeUrl.trim() !== "");
        const total   = allTds.length;
        let   done    = 0;

        const statusEl = document.getElementById('live-scraping-status');

        function updateStatus() {
            if (!statusEl) return;
            const pct = total > 0 ? Math.round((done / total) * 100) : 0;
            if(done == 0){
                const liveCount  = document.querySelectorAll('.badge-warning.font-bold').length;
                const errorCount = document.querySelectorAll('.badge-error.opacity-70').length;
                statusEl.innerHTML = `
                    <div class="flex flex-col items-center gap-1.5">
                        <button id="btn-relancer" class="btn btn-xs btn-ghost gap-1 text-gray-500 hover:text-warning">
                            Rechercher les prix en live
                        </button>
                    </div>`;
                document.getElementById('btn-relancer')?.addEventListener('click', runScraping);
            } else if (done < total && done > 0) {
                statusEl.innerHTML = `
                    <div class="flex flex-col items-center gap-1">
                        <div class="flex items-center gap-2">
                            <span class="loading loading-spinner loading-xs text-warning"></span>
                            <span class="text-xs font-medium text-warning">Scraping… ${done}/${total}</span>
                        </div>
                        <progress class="progress progress-warning w-40" value="${done}" max="${total}"></progress>
                        <span class="text-xs text-gray-400">${pct}%</span>
                    </div>`;
            } else {
                const liveCount  = document.querySelectorAll('.badge-warning.font-bold').length;
                const errorCount = document.querySelectorAll('.badge-error.opacity-70').length;
                statusEl.innerHTML = `
                    <div class="flex flex-col items-center gap-1.5">
                        <div class="flex items-center gap-2">
                            ${liveCount  > 0 ? `<span class="badge badge-warning badge-sm">⚡ ${liveCount} live</span>` : ''}
                            ${errorCount > 0 ? `<span class="badge badge-error badge-sm">⚠ ${errorCount} erreur(s)</span>` : ''}
                        </div>
                        <button id="btn-relancer" class="btn btn-xs btn-ghost gap-1 text-gray-500 hover:text-warning">
                            ↺ Relancer
                        </button>
                    </div>`;
                document.getElementById('btn-relancer')?.addEventListener('click', runScraping);
            }
        }

        updateStatus();

        if (total === 0) {
            if (statusEl) statusEl.innerHTML = '<span class="text-xs text-gray-400">Aucun produit à scraper</span>';
            return;
        }

        // Traitement par batches
        for (let i = 0; i < allTds.length; i += BATCH_SIZE) {
            const batch = allTds.slice(i, i + BATCH_SIZE);
            await Promise.all(batch.map(td => scrapeJob(td).then(() => { done++; updateStatus(); })));
        }
    }

    // ── Point d'entrée : lancer dès que la table est dans le DOM ─────────────
    // On utilise un MutationObserver pour détecter quand Livewire injecte la
    // table (après le premier render), puis on démarre.

    function waitForTable(callback) {
        if (document.querySelector('td.price-cell')) {
            callback();
            return;
        }
        const obs = new MutationObserver(() => {
            if (document.querySelector('td.price-cell')) {
                obs.disconnect();
                callback();
            }
        });
        obs.observe(document.body, { childList: true, subtree: true });
    }

    // Relancer automatiquement quand Livewire re-render la page (pagination, filtre…)
    document.addEventListener('livewire:navigated', () => waitForTable(runScraping));
    document.addEventListener('livewire:morph-updated', () => {
        // Petit délai pour laisser le DOM se stabiliser après le morph
        setTimeout(() => {
            if (document.querySelector('td.price-cell')) runScraping();
        }, 200);
    });

    // // Lancement initial
    // if (document.readyState === 'loading') {
    //     document.addEventListener('DOMContentLoaded', () => waitForTable(runScraping));
    // } else {
    //     waitForTable(runScraping);
    // }

})();
</script>
