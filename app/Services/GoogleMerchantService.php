<?php

namespace App\Services;

use Google\Client as GoogleClient;
use Google\Service\ShoppingContent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleMerchantService
{
    protected GoogleClient $client;
    protected string $merchantId;
    protected ?string $accessToken = null;

    public function __construct()
    {
        $this->merchantId = config('services.google.merchant_id');
        $this->client = new GoogleClient();
        $this->initializeClient();
    }

    protected function initializeClient(): void
    {
        // Chemin vers votre fichier JSON de credentials
        $keyFilePath = storage_path('app/google/merchant-center-api-488005-5d5010108e01.json');
        
        $this->client->setAuthConfig($keyFilePath);
        $this->client->addScope('https://www.googleapis.com/auth/content');
        $this->client->setAccessType('offline');
    }

    protected function getAccessToken(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        $this->client->fetchAccessTokenWithAssertion();
        $token = $this->client->getAccessToken();
        
        $this->accessToken = $token['access_token'] ?? null;
        
        if (!$this->accessToken) {
            throw new \Exception('Failed to obtain access token');
        }

        return $this->accessToken;
    }

    public function searchReports(string $query): array
    {
        $accessToken = $this->getAccessToken();
        
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ])->post("https://merchantapi.googleapis.com/reports/v1/accounts/{$this->merchantId}/reports:search", [
                'query' => $query
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