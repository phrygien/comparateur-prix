<?php

namespace App\Console\Commands;

use App\Services\GoogleMerchantService;
use Illuminate\Console\Command;

class TestGoogleMerchant extends Command
{
    protected $signature = 'google:test-merchant';
    protected $description = 'Test Google Merchant API';

    public function handle(GoogleMerchantService $googleMerchant)
    {
        $this->info('Testing Google Merchant API...');

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
            WHERE report_country_code = 'FR'
                AND report_granularity = 'WEEKLY'
                AND category_l1 LIKE '%Health & Beauty%'
                AND variant_gtins CONTAINS ANY ('03614274752106')
            LIMIT 20
        ";

        try {
            $result = $googleMerchant->searchReports($query);
            $this->info('Success!');
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
        }
    }
}