<?php

namespace App\Services;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ApiScraperService
{

    public function __construct(){}

    public function scrapwebsite($site_id, $url_site): array
    {

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post("https://dev.astucom.com:9038/scrap", [
                'site_id' => $site_id,
                'url_site' => $url_site
            ]);

            if ($response->failed()) {
                throw new \Exception('API request failed: ' . $response->body());
            }

            return $response->json();

        } catch (\Exception $e) {
            Log::error('Google Merchant API Error: ' . $e->getMessage());
            throw $e;
        }
    }

}
