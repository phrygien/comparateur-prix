<?php

namespace App\Http\Controllers;

use App\Services\GoogleMerchantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GoogleMerchantController extends Controller
{
    protected GoogleMerchantService $googleMerchant;

    public function __construct(GoogleMerchantService $googleMerchant)
    {
        $this->googleMerchant = $googleMerchant;
    }

    public function getPopularityRank()
    {
        // best_sellers_product_cluster_view
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
                AND variant_gtins CONTAINS ANY ('03614274752106')
            LIMIT 20
        ";

        // Autres requêtes commentées disponibles :
        
        /*
        // product_performance_view
        $query = "
            SELECT
                date,
                offer_id,
                clicks,
                impressions,
                title,
                brand
            FROM product_performance_view
            WHERE date >= '2024-01-01'
            LIMIT 10
        ";
        
        // product_view
        $query = "
            SELECT
                id,
                offer_id,
                item_group_id,
                gtin,
                brand,
                title
            FROM product_view
            LIMIT 10
        ";
        
        // best_sellers_brand_view
        $query = "
            SELECT
                report_granularity,
                report_date,
                report_category_id,
                brand,
                rank,
                previous_rank,
                report_country_code,
                relative_demand
            FROM best_sellers_brand_view
            WHERE report_country_code = 'FR'
            AND report_granularity = 'WEEKLY'
            LIMIT 20
        ";
        
        // price_competitiveness_product_view
        $query = "
            SELECT
                id,
                offer_id,
                brand,
                title,
                report_country_code
            FROM price_competitiveness_product_view
            WHERE report_country_code = 'FR'
            LIMIT 20
        ";
        */

        try {
            $result = $this->googleMerchant->searchReports($query);
            
            // Log pour débogage
            Log::info('Google Merchant API Response', ['data' => $result]);
            
            return response()->json([
                'success' => true,
                'data' => $result
            ]);

        } catch (\Exception $e) {
            Log::error('Error in getPopularityRank: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Méthode pour les autres types de requêtes
    public function getProductPerformance()
    {
        $query = "
            SELECT
                date,
                offer_id,
                clicks,
                impressions,
                title,
                brand
            FROM product_performance_view
            WHERE date >= '2024-01-01'
            LIMIT 10
        ";

        try {
            $result = $this->googleMerchant->searchReports($query);
            
            return response()->json([
                'success' => true,
                'data' => $result
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}