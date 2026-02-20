<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Models\Product;
use App\Models\Site;

class ExportSalesService
{
    private array $countries = [
        'FR' => 'France',
        'BE' => 'Belgique',
        'NL' => 'Pays-Bas',
        'DE' => 'Allemagne',
        'ES' => 'Espagne',
        'IT' => 'Italie',
    ];

    /**
     * Génère un fichier XLSX pour un pays donné et retourne le chemin du fichier.
     */
    public function generateForCountry(
        string $countryCode,
        string $dateFrom,
        string $dateTo,
        string $sortBy = 'rank_qty',
        array  $groupeFilter = []
    ): string {
        $countryLabel = $this->countries[$countryCode] ?? $countryCode;
        $sites        = Site::where('country_code', $countryCode)->orderBy('name')->get();
        $sales        = $this->getSales($countryCode, $dateFrom, $dateTo, $sortBy, $groupeFilter);
        $comparisons  = $this->getComparisons($sales, $sites);

        return $this->buildSpreadsheet(
            $countryCode,
            $countryLabel,
            $dateFrom,
            $dateTo,
            $sortBy,
            $groupeFilter,
            $sites,
            collect($comparisons)
        );
    }

    // =========================================================================
    // REQUÊTE SQL
    // =========================================================================

    private function getSales(
        string $countryCode,
        string $dateFrom,
        string $dateTo,
        string $sortBy,
        array  $groupeFilter
    ): array {
        $dtFrom   = $dateFrom . ' 00:00:00';
        $dtTo     = $dateTo   . ' 23:59:59';
        $orderCol = $sortBy === 'rank_ca' ? 'total_revenue' : 'total_qty_sold';

        $groupeCondition = '';
        $params = [$dtFrom, $dtTo, $countryCode];

        if (!empty($groupeFilter)) {
            $placeholders    = implode(',', array_fill(0, count($groupeFilter), '?'));
            $groupeCondition = " WHERE groupe IN ($placeholders)";
            $params          = array_merge($params, $groupeFilter);
        }

        $sql = "
            WITH sales AS (
                SELECT
                    addr.country_id AS country,
                    oi.sku AS ean,
                    SUBSTRING_INDEX(CAST(product_char.name AS CHAR CHARACTER SET utf8mb4), ' - ', 1) AS groupe,
                    SUBSTRING_INDEX(SUBSTRING_INDEX(CAST(product_char.name AS CHAR CHARACTER SET utf8mb4), ' - ', 2), ' - ', -1) AS marque,
                    SUBSTRING_INDEX(SUBSTRING_INDEX(CAST(product_char.name AS CHAR CHARACTER SET utf8mb4), ' - ', 3), ' - ', -1) AS designation_produit,
                    (CASE
                        WHEN ROUND(product_decimal.special_price, 2) IS NOT NULL THEN ROUND(product_decimal.special_price, 2)
                        ELSE ROUND(product_decimal.price, 2)
                    END) AS prix_vente_cosma,
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
            LIMIT 100
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
    }

    // =========================================================================
    // COMPARAISONS
    // =========================================================================

    private function getComparisons(array $sales, $sites): array
    {
        $siteIds     = $sites->pluck('id')->toArray();
        $comparisons = [];

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
                'row'              => $row,
                'sites'            => [],
                'prix_moyen_marche' => null,
                'percentage_marche' => null,
                'difference_marche' => null,
            ];

            $somme_prix_marche = 0;
            $nombre_site       = 0;

            foreach ($sites as $site) {
                if (isset($scrapedProducts[$site->id])) {
                    $sp            = $scrapedProducts[$site->id];
                    $prixCosma     = $row->prix_vente_cosma;
                    $priceDiff     = null;
                    $pricePct      = null;

                    if ($prixCosma > 0 && $sp->prix_ht > 0) {
                        $priceDiff = $sp->prix_ht - $prixCosma;
                        $pricePct  = round(($priceDiff / $prixCosma) * 100, 2);
                    }

                    $comparison['sites'][$site->id] = [
                        'prix_ht'          => $sp->prix_ht,
                        'url'              => $sp->url,
                        'name'             => $sp->name,
                        'vendor'           => $sp->vendor,
                        'ean'              => $sp->ean ?? null,
                        'price_diff'       => $priceDiff,
                        'price_percentage' => $pricePct,
                        'site_name'        => $site->name,
                    ];

                    $somme_prix_marche += $sp->prix_ht;
                    $nombre_site++;
                } else {
                    $comparison['sites'][$site->id] = null;
                }
            }

            $prixCosma = $row->prix_vente_cosma;

            if ($somme_prix_marche > 0 && $prixCosma > 0) {
                $prixMoyen                        = $somme_prix_marche / $nombre_site;
                $diff                             = $prixMoyen - $prixCosma;
                $comparison['prix_moyen_marche']  = $prixMoyen;
                $comparison['percentage_marche']  = round(($diff / $prixCosma) * 100, 2);
                $comparison['difference_marche']  = $diff;
            }

            $comparisons[] = $comparison;
        }

        return $comparisons;
    }

    // =========================================================================
    // CONSTRUCTION DU SPREADSHEET
    // =========================================================================

    public function buildSpreadsheet(
        string $countryCode,
        string $countryLabel,
        string $dateFrom,
        string $dateTo,
        string $sortBy,
        array  $groupeFilter,
        $sites,
        $comparisons
    ): string {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Ventes ' . $countryLabel);

        $baseHeaders = [
            'Rang Qty', 'Rang CA', 'EAN', 'Groupe', 'Marque',
            'Désignation', 'Prix Cosma', 'Qté vendue', 'CA total', 'PGHT',
        ];

        $lastColIndex  = count($baseHeaders) + $sites->count();
        $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastColIndex + 1);

        $sheet->getColumnDimension('A')->setAutoSize(false)->setWidth(10);
        $sheet->getColumnDimension('B')->setAutoSize(false)->setWidth(10);
        $sheet->getColumnDimension('C')->setAutoSize(false)->setWidth(16);
        $sheet->getColumnDimension('F')->setAutoSize(false)->setWidth(35);

        // --- PASS 1 : calcul stats ---
        $somme_total = 0;
        $somme_gain  = 0;
        $somme_perte = 0;
        $nbCompare   = 0;

        foreach ($comparisons as $comparison) {
            if ($comparison['prix_moyen_marche'] !== null) {
                $somme_total += $comparison['prix_moyen_marche'];
                $diff         = $comparison['difference_marche'];
                if ($diff > 0) $somme_gain  += $diff;
                else           $somme_perte += $diff;
                $nbCompare++;
            }
        }

        $pct_gain  = $somme_total > 0 ? ((($somme_total + $somme_gain)  * 100) / $somme_total) - 100 : 0;
        $pct_perte = $somme_total > 0 ? ((($somme_total + $somme_perte) * 100) / $somme_total) - 100 : 0;

        // --- Ligne 1 : infos contextuelles ---
        $groupeLabel = !empty($groupeFilter) ? implode(', ', $groupeFilter) : 'Tous';
        $infoLine = [
            'Pays'              => $countryLabel,
            'Période'           => $dateFrom . ' → ' . $dateTo,
            'Groupe(s)'         => $groupeLabel,
            'Tri'               => $sortBy === 'rank_qty' ? 'Qté vendue' : 'CA total',
            'Produits exportés' => count($comparisons),
            'Produits comparés' => $nbCompare,
        ];

        $col = 1;
        foreach ($infoLine as $label => $value) {
            $lc = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . '1';
            $vc = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1) . '1';
            $sheet->setCellValue($lc, $label . ' :');
            $sheet->setCellValue($vc, $value);
            $sheet->getStyle($lc)->getFont()->setBold(true)->setName('Arial')->setSize(9);
            $sheet->getStyle($vc)->getFont()->setName('Arial')->setSize(9);
            $col += 2;
        }

        // --- Ligne 2 : KPIs ---
        $kpis = [
            ['↓ Moins chers (€)', $nbCompare > 0 ? number_format(abs($somme_gain  / $nbCompare), 2, ',', ' ') . ' €' : 'N/A', '1A7A3C'],
            ['↓ Moins chers (%)', number_format(abs($pct_gain),  2, ',', ' ') . ' %',                                           '1A7A3C'],
            ['↑ Plus chers (€)',  $nbCompare > 0 ? number_format(abs($somme_perte / $nbCompare), 2, ',', ' ') . ' €' : 'N/A',  'CC0000'],
            ['↑ Plus chers (%)',  number_format(abs($pct_perte), 2, ',', ' ') . ' %',                                           'CC0000'],
        ];

        $col = 1;
        foreach ($kpis as [$label, $value, $color]) {
            $lc = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . '2';
            $vc = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1) . '2';
            $sheet->setCellValue($lc, $label . ' :');
            $sheet->getStyle($lc)->getFont()->setBold(true)->setName('Arial')->setSize(9);
            $sheet->setCellValue($vc, $value);
            $sheet->getStyle($vc)->getFont()->getColor()->setRGB($color);
            $sheet->getStyle($vc)->getFont()->setBold(true)->setName('Arial')->setSize(9);
            $col += 2;
        }

        // Style fond stats
        $sheet->getStyle('A1:' . $lastColLetter . '2')->applyFromArray([
            'fill'    => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EDF2F7']],
            'borders' => ['outline' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, 'color' => ['rgb' => 'CBD5E0']]],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(16);
        $sheet->getRowDimension(2)->setRowHeight(16);

        // --- Ligne 3 : en-têtes colonnes ---
        $headerRow    = 3;
        $dataStartRow = 4;

        $hCol = 0;
        foreach ($baseHeaders as $header) {
            $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($hCol + 1) . $headerRow, $header);
            $hCol++;
        }
        foreach ($sites as $site) {
            $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($hCol + 1) . $headerRow, $site->name);
            $hCol++;
        }
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($hCol + 1) . $headerRow, 'Prix marché');

        $sheet->getStyle('A' . $headerRow . ':' . $lastColLetter . $headerRow)->applyFromArray([
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '2D3748']],
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Arial', 'size' => 10],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($headerRow)->setRowHeight(20);

        // --- Données ---
        $row = $dataStartRow;

        foreach ($comparisons as $comparison) {
            $r = $comparison['row'];

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

            $sheet->getStyle('G' . $row)->getNumberFormat()->setFormatCode('#,##0.00 "€"');
            $sheet->getStyle('I' . $row)->getNumberFormat()->setFormatCode('#,##0.00 "€"');
            $sheet->getStyle('J' . $row)->getNumberFormat()->setFormatCode('#,##0.00 "€"');

            if (($row - $dataStartRow) % 2 === 0) {
                $sheet->getStyle('A' . $row . ':' . $lastColLetter . $row)->applyFromArray([
                    'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F7FAFC']],
                ]);
            }

            $colIdx = 10;
            foreach ($sites as $site) {
                $cellCoord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1) . $row;
                $siteData  = $comparison['sites'][$site->id] ?? null;

                if ($siteData) {
                    $pct        = $siteData['price_percentage'];
                    $priceColor = 'FF000000';
                    if ($pct !== null) {
                        $priceColor = $r->prix_vente_cosma > $siteData['prix_ht'] ? 'FFCC0000' : 'FF1A7A3C';
                    }

                    $prixText = number_format($siteData['prix_ht'], 2, ',', ' ') . ' €';
                    if ($pct !== null) {
                        $prixText .= ' (' . ($pct > 0 ? '+' : '') . $pct . '%)';
                    }

                    $richText = new \PhpOffice\PhpSpreadsheet\RichText\RichText();

                    $runPrix = $richText->createTextRun($prixText);
                    $runPrix->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color($priceColor))->setName('Arial');

                    if (!empty($siteData['ean'])) {
                        $runEan = $richText->createTextRun("\nEAN : " . $siteData['ean']);
                        $runEan->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF888888'))->setName('Arial')->setSize(8);
                    }

                    if (!empty($siteData['url'])) {
                        $runLien = $richText->createTextRun("\nVoir le produit");
                        $runLien->getFont()
                            ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF0563C1'))
                            ->setUnderline(\PhpOffice\PhpSpreadsheet\Style\Font::UNDERLINE_SINGLE)
                            ->setName('Arial');
                    }

                    $sheet->getCell($cellCoord)->setValue($richText);
                    $sheet->getStyle($cellCoord)->getAlignment()->setWrapText(true)
                        ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);

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

            // Prix marché
            $marcheCoord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1) . $row;
            if ($comparison['prix_moyen_marche'] !== null) {
                $pm    = $comparison['prix_moyen_marche'];
                $pct   = $comparison['percentage_marche'];
                $color = $r->prix_vente_cosma > $pm ? 'CC0000' : '1A7A3C';
                $sheet->setCellValue($marcheCoord, number_format($pm, 2, ',', ' ') . ' € (' . ($pct > 0 ? '+' : '') . $pct . '%)');
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

        // --- Formatage final ---
        foreach (range('D', $lastColLetter) as $col) {
            if (!in_array($col, ['A', 'B', 'C', 'F'])) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
        }

        $sheet->freezePane('A' . $dataStartRow);

        $sheet->getStyle('A' . $headerRow . ':' . $lastColLetter . $lastDataRow)->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'DDDDDD']]],
            'font'    => ['name' => 'Arial', 'size' => 9],
        ]);

        $sheet->getStyle('G' . $dataStartRow . ':' . $lastColLetter . $lastDataRow)
            ->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);

        // --- Sauvegarde ---
        $exportDir = storage_path('app/public/exports');
        if (!file_exists($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        $groupeSuffix = !empty($groupeFilter) ? '_' . implode('-', array_map('strtolower', $groupeFilter)) : '';
        $fileName     = 'ventes_' . strtolower($countryCode)
            . '_' . $dateFrom . '_' . $dateTo
            . $groupeSuffix . '_' . date('His') . '.xlsx';
        $filePath     = $exportDir . '/' . $fileName;

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($filePath);

        return $filePath;
    }
}
