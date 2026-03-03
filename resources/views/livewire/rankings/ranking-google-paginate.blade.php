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

    public int $perPage = 25;
    public int $currentPage = 1;
    public string $activeCountry = 'FR';
    public string $activePeriod = 'WEEKLY';
    public string $MondayWeekly = '2026-01-19';
    public string $dateMonthly = '2026-01-01';

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
        'Mensuel' => 'MONTHLY',
    ];

    public array $periodCodeMap = [
        'WEEKLY' => 'WEEKLY',
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

        $eanList = array_values(array_unique($eanList));
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
            // $results = Product::with([
            //     'website' => function ($query) {
            //         $query->where('country_code', $this->activeCountry);
            //     }
            // ])
            //     ->whereIn('ean', $eanList)
            //     ->whereHas('website', function ($query) {
            //         $query->where('country_code', $this->activeCountry);
            //     })
            //     ->get();

            
            $results = Product::with([
    'website' => function ($query) {
        $query->where('country_code', $this->activeCountry);
    }
])
->whereIn('ean', $eanList)
->whereHas('website', function ($query) {
    $query->where('country_code', $this->activeCountry);
})
->orderBy('scrap_reference_id', 'desc')
->get()
->unique(function ($item) {
    return $item->ean . '_' . $item->website_id;  // Unicité par couple EAN-site
});

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
        $periodCode = $this->periodCodeMap[$this->activePeriod] ?? $this->activePeriod;
        $date = $periodCode === 'WEEKLY' ? $this->MondayWeekly : $this->dateMonthly;

        // Vérifier le cache
        $cacheKey = 'google_popularity_all_' . md5($countryCode . $periodCode . $date);

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($countryCode, $periodCode, $date) {

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
                    $data = $row['bestSellersProductClusterView'] ?? [];
                    $rank = isset($data['rank']) ? (int) $data['rank'] : null;
                    $prevRank = isset($data['previousRank']) ? (int) $data['previousRank'] : null;
                    $delta = ($rank !== null && $prevRank !== null) ? ($prevRank - $rank) : null;

                    $ranks[] = [
                        'rank' => $rank,
                        'previous_rank' => $prevRank,
                        'delta' => $delta,
                        'delta_sign' => match (true) {
                            $delta === null => null,
                            $delta > 0 => '+',
                            $delta < 0 => '-',
                            default => '=',
                    },
                        'relative_demand' => $data['relativeDemand'] ?? null,
                        'title' => $data['title'] ?? null,
                        'brand' => $data['brand'] ?? null,
                        'ean_list' => array_map(
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
        $periodCode = $this->periodCodeMap[$this->activePeriod] ?? $this->activePeriod;
        $date = $periodCode === 'WEEKLY' ? $this->MondayWeekly : $this->dateMonthly;

        Cache::forget('google_popularity_all_' . md5($countryCode . $periodCode . $date));
    }

    public function getSitesProperty()
    {
        return Site::where('country_code', $this->activeCountry)
            ->orderBy('name')
            ->get();
    }

    public function exportXlsx(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $popularityRanks = $this->popularityRanksAll;
        $sites = $this->sites;

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $countryLabel = $this->countries[$this->activeCountry] ?? $this->activeCountry;

        $periodLabel = $this->activePeriod === 'WEEKLY' ? 'Semaine' : 'Mois';
        $dateValue = $this->activePeriod === 'WEEKLY' ? $this->MondayWeekly : $this->dateMonthly;
        $sheet->setTitle('Popularité ' . $countryLabel);

        // En-têtes exactement comme dans le tableau
        $headers = [
            'Rang Google',
            'Google Group',
            'Google Titre',
            'EAN Google',
            'Magento',
            'Demande relative',
        ];

        // Ajouter les sites comme en-têtes
        foreach ($sites as $site) {
            $headers[] = $site->name;
        }

        // Positionner les en-têtes (ligne 1)
        $col = 1;
        foreach ($headers as $header) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . '1';
            $sheet->setCellValue($cell, $header);
            $col++;
        }

        // Style des en-têtes (comme dans le tableau)
        $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle('A1:' . $lastColLetter . '1')->applyFromArray([
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => '2D3748']
            ],
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'name' => 'Arial',
                'size' => 10
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER
            ],
        ]);

        // Remplir les données exactement comme dans le tableau
        $row = 2;
        foreach ($popularityRanks as $item) {
            $col = 1;

            // === COLONNE 1: Rang Google (avec évolution) ===
            $rankCell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;

            if ($item['rank'] !== null) {
                $richText = new \PhpOffice\PhpSpreadsheet\RichText\RichText();

                // Rang
                $runRank = $richText->createTextRun('#' . $item['rank']);
                $runRank->getFont()->setBold(true)->setName('Arial')->setSize(9);

                // Évolution
                if ($item['delta'] !== null) {
                    $runDelta = $richText->createTextRun("\n" . ($item['delta_sign'] === '+' ? '+' : '') . $item['delta']);
                    $deltaColor = match ($item['delta_sign']) {
                        '+' => '1A7A3C',
                        '-' => 'CC0000',
                        default => '888888',
                    };
                    $runDelta->getFont()->setBold(true)->setName('Arial')->setSize(8);
                    $runDelta->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($deltaColor));
                }

                $sheet->getCell($rankCell)->setValue($richText);
                $sheet->getStyle($rankCell)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
                $sheet->getStyle($rankCell)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            } else {
                $sheet->setCellValue($rankCell, '—');
            }
            $col++;

            // === COLONNE 2: Google Group (Marque) ===
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
            $sheet->setCellValue($cell, $item['brand'] ?? '—');
            $sheet->getStyle($cell)->getFont()->setBold(true);
            $col++;

            // === COLONNE 3: Google Titre ===
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
            $sheet->setCellValue($cell, $item['title'] ?? '—');
            $sheet->getStyle($cell)->getFont()->setBold(true);
            $col++;

            // === COLONNE 4: EAN Google ===
            $eanCell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
            if (!empty($item['ean_list'])) {
                $richText = new \PhpOffice\PhpSpreadsheet\RichText\RichText();
                $first = true;
                foreach ($item['ean_list'] as $ean) {
                    if (!$first) {
                        $richText->createText("\n");
                    }
                    $runEan = $richText->createTextRun($ean);

                    // Colorer selon présence dans Magento
                    $eanColor = isset($item['magento_products'][$ean]) ? '1A7A3C' : 'CC0000';
                    $runEan->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($eanColor));
                    $runEan->getFont()->setBold(isset($item['magento_products'][$ean]));

                    $first = false;
                }
                $sheet->getCell($eanCell)->setValue($richText);
            } else {
                $sheet->setCellValue($eanCell, '—');
                $sheet->getStyle($eanCell)->getFont()->getColor()->setRGB('AAAAAA');
            }
            $col++;

            // === COLONNE 5: Magento ===
            $magentoCell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
            if (!empty($item['magento_products'])) {
                $richText = new \PhpOffice\PhpSpreadsheet\RichText\RichText();
                $first = true;
                foreach ($item['magento_products'] as $ean => $mag) {
                    if (!$first) {
                        $richText->createText("\n\n");
                    }

                    // SKU
                    $runSku = $richText->createTextRun($mag['sku']);
                    $runSku->getFont()->setBold(true)->setName('Arial')->setSize(9);

                    // Titre
                    $richText->createText("\n");
                    $runTitle = $richText->createTextRun(utf8_encode($mag['title']));
                    $runTitle->getFont()->setName('Arial')->setSize(8);

                    // Prix
                    $richText->createText("\n");
                    if (!empty($mag['special_price'])) {
                        $runPrice = $richText->createTextRun(
                            number_format($mag['price'] ?? 0, 2, ',', ' ') . '€ → ' .
                            number_format($mag['special_price'], 2, ',', ' ') . '€'
                        );
                        $runPrice->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('1A7A3C'));
                    } else {
                        $priceText = number_format($mag['price'] ?? 0, 2, ',', ' ') . '€';
                        $runPrice = $richText->createTextRun($priceText);
                        if (($mag['price'] ?? 0) > 0) {
                            $runPrice->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('1A7A3C'));
                        }
                    }
                    $runPrice->getFont()->setBold(true)->setName('Arial')->setSize(8);

                    $first = false;
                }
                $sheet->getCell($magentoCell)->setValue($richText);
                $sheet->getStyle($magentoCell)->getAlignment()->setWrapText(true);
            } else {
                $richText = new \PhpOffice\PhpSpreadsheet\RichText\RichText();
                $runText = $richText->createTextRun('Non référencé');
                $runText->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('AAAAAA'));
                $runText->getFont()->setItalic(true);
                $sheet->getCell($magentoCell)->setValue($richText);
            }
            $col++;

            // === COLONNE 6: Demande relative ===
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
            $sheet->setCellValue($cell, $item['relative_demand'] ?? '—');
            $sheet->getStyle($cell)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $col++;

            // === COLONNES DES SITES ===
            foreach ($sites as $site) {
                $siteCell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;

                $productsForSite = [];
                foreach ($item['ean_list'] as $ean) {
                    if (isset($item['scraped_products'][$ean])) {
                        foreach ($item['scraped_products'][$ean] as $scrapedProduct) {
                            if ($scrapedProduct['site_id'] == $site->id) {
                                $productsForSite[] = $scrapedProduct;
                            }
                        }
                    }
                }

                if (!empty($productsForSite)) {
                    $richText = new \PhpOffice\PhpSpreadsheet\RichText\RichText();
                    $first = true;

                    foreach ($productsForSite as $product) {
                        if (!$first) {
                            $richText->createText("\n\n");
                        }

                        // EAN
                        $runEan = $richText->createTextRun($product['ean']);
                        $runEan->getFont()->setBold(true)->setName('Arial')->setSize(8);
                        $runEan->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(
                            $product['is_available'] ? '1A7A3C' : 'CC0000'
                        ));

                        // Nom du produit
                        $richText->createText("\n");
                        $runName = $richText->createTextRun(\Illuminate\Support\Str::limit($product['name'], 25));
                        $runName->getFont()->setName('Arial')->setSize(8);

                        // Prix
                        $richText->createText("\n");
                        $runPrice = $richText->createTextRun(
                            number_format($product['price'], 2, ',', ' ') . ' ' . ($product['currency'] ?? '€')
                        );
                        $runPrice->getFont()->setBold(true)->setName('Arial')->setSize(8);
                        $runPrice->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(
                            $product['is_available'] ? '1A7A3C' : 'CC0000'
                        ));

                        // URL (si disponible)
                        if ($product['url']) {
                            $richText->createText("\n");
                            $runUrl = $richText->createTextRun('🔗 Lien');
                            $runUrl->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('0563C1'));
                            $runUrl->getFont()->setUnderline(true)->setName('Arial')->setSize(7);

                            // Ajouter le lien hypertexte
                            $sheet->getCell($siteCell)->getHyperlink()->setUrl($product['url']);
                        }

                        $first = false;
                    }

                    $sheet->getCell($siteCell)->setValue($richText);
                    $sheet->getStyle($siteCell)->getAlignment()->setWrapText(true);
                    $sheet->getStyle($siteCell)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
                } else {
                    $richText = new \PhpOffice\PhpSpreadsheet\RichText\RichText();
                    $runText = $richText->createTextRun('Aucun produit');
                    $runText->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('AAAAAA'));
                    $runText->getFont()->setItalic(true);
                    $sheet->getCell($siteCell)->setValue($richText);
                    $sheet->getStyle($siteCell)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                }

                $col++;
            }

            // Alterner les couleurs de fond (comme dans le tableau)
            if (($row - 2) % 2 === 0) {
                $sheet->getStyle('A' . $row . ':' . $lastColLetter . $row)->applyFromArray([
                    'fill' => [
                        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'F7FAFC']
                    ]
                ]);
            }

            $row++;
        }

        // Ajuster la largeur des colonnes
        $sheet->getColumnDimension('A')->setWidth(12);  // Rang Google
        $sheet->getColumnDimension('B')->setWidth(15);  // Google Group
        $sheet->getColumnDimension('C')->setWidth(40);  // Google Titre
        $sheet->getColumnDimension('D')->setWidth(20);  // EAN Google
        $sheet->getColumnDimension('E')->setWidth(35);  // Magento
        $sheet->getColumnDimension('F')->setWidth(15);  // Demande relative

        // Colonnes des sites (auto-size)
        for ($i = 7; $i <= count($headers); $i++) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            $sheet->getColumnDimension($colLetter)->setWidth(25);
        }

        // Geler la première ligne
        $sheet->freezePane('A2');

        // Style pour toutes les cellules de données
        $sheet->getStyle('A2:' . $lastColLetter . ($row - 1))->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 9],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['rgb' => 'DDDDDD']
                ]
            ]
        ]);

        // Alignement vertical en haut pour toutes les cellules
        $sheet->getStyle('A2:' . $lastColLetter . ($row - 1))
            ->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);

        // Créer le dossier d'export si nécessaire
        $exportDir = storage_path('app/public/exports');
        if (!file_exists($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        // Générer le nom du fichier
        $fileName = 'popularite_google_' . strtolower($this->activeCountry)
            . '_' . ($this->activePeriod === 'WEEKLY' ? 'semaine' : 'mois')
            . '_' . $dateValue
            . '_' . date('Ymd_His') . '.xlsx';
        $filePath = $exportDir . '/' . $fileName;

        // Sauvegarder le fichier
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($filePath);

        // Retourner la réponse de téléchargement
        return response()->download($filePath, $fileName)->deleteFileAfterSend(true);
    }


    public function with(): array
    {
        $total = $this->popularityTotal;
        $lastPage = max(1, (int) ceil($total / $this->perPage));

        return [
            'sites' => $this->sites,
            'popularityRanks' => $this->popularityRanks,
            'total' => $total,
            'lastPage' => $lastPage,
            'currentPage' => $this->currentPage,
            'perPage' => $this->perPage,
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

                            <div class="flex items-center gap-2">
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

                                <button type="button" wire:click="exportXlsx" wire:loading.attr="disabled" class="btn btn-sm btn-success gap-2">
                                    <span wire:loading.remove wire:target="exportXlsx" class="flex items-center gap-2">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                        </svg>
                                        Export Excel
                                    </span>
                                    <span wire:loading wire:target="exportXlsx" class="flex items-center gap-2">
                                        <span class="loading loading-spinner loading-xs"></span>
                                        Export en cours...
                                    </span>
                                </button>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
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
                            <div wire:loading wire:target="activePeriod, MondayWeekly, dateMonthly, perPage, setPage, clearCache, exportXlsx"
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
                                                <th class="text-center">Google Group</th>
                                                <th class="text-center">Google Titre</th>
                                                <th class="text-center">EAN Google</th>
                                                <th class="min-w-[420px] text-center">
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

                                                    <td class="font-semibold text-center">
                                                        {{ $item['brand'] ?? '—' }}
                                                    </td>

                                                    <td class="font-bold max-w-xs truncate text-center" title="{{ $item['title'] ?? '' }}">
                                                        {{ $item['title'] ?? '—' }}
                                                    </td>

                                                    <td class="p-1 align-center text-center">
                                                        @if(!empty($item['ean_list']))
                                                            <div class="flex flex-col gap-0.5">
                                                                @foreach($item['ean_list'] as $ean)
                                                                    <span class="font-mono text-xs
                                                                        {{ isset($item['magento_products'][$ean]) ? 'text-success font-semibold' : 'text-error font-medium' }}">
                                                                        {{ $ean }}
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
                                                                foreach ($item['ean_list'] as $ean) {
                                                                    if (isset($item['scraped_products'][$ean])) {
                                                                        foreach ($item['scraped_products'][$ean] as $scrapedProduct) {
                                                                            if ($scrapedProduct['site_id'] == $site->id) {
                                                                                $productsForSite[] = $scrapedProduct;
                                                                            }
                                                                        }
                                                                    }
                                                                }
                                                            @endphp

                                                            @if(!empty($productsForSite))
                                                                <div class="space-y-2">
                                                                    @foreach($productsForSite as $product)
                                                                        <div class="bg-base-50 rounded p-2 border border-base-200 hover:border-primary/30 transition-colors">
                                                                            <div class="flex items-center justify-between gap-2 mb-1">
                                                                                <span class="font-mono font-bold text-xs {{ $product['is_available'] ? 'text-success' : 'text-error' }}">
                                                                                    {{ $product['ean'] }}
                                                                                </span>
                                                                            </div>

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
