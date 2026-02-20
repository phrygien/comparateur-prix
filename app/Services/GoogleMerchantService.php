<?php

namespace App\Services;

use Google\Client as GoogleClient;
use Google\Auth\Credentials\ServiceAccountCredentials;
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
        try {
            // Récupérer les credentials depuis la config
            $credentials = [
                'type' => config('services.google.service_account.type'),
                'project_id' => config('services.google.service_account.project_id'),
                'private_key_id' => config('services.google.service_account.private_key_id'),
                'private_key' => config('services.google.service_account.private_key'),
                'client_email' => config('services.google.service_account.client_email'),
                'client_id' => config('services.google.service_account.client_id'),
                'auth_uri' => config('services.google.service_account.auth_uri'),
                'token_uri' => config('services.google.service_account.token_uri'),
                'auth_provider_x509_cert_url' => config('services.google.service_account.auth_provider_x509_cert_url'),
                'client_x509_cert_url' => config('services.google.service_account.client_x509_cert_url'),
                'universe_domain' => config('services.google.service_account.universe_domain'),
            ];

            // Utiliser les credentials directement
            $this->client->useApplicationDefaultCredentials();
            $this->client->setAuthConfig($credentials);
            $this->client->addScope('https://www.googleapis.com/auth/content');
            $this->client->setAccessType('offline');
            
        } catch (\Exception $e) {
            Log::error('Failed to initialize Google Client: ' . $e->getMessage());
            throw $e;
        }
    }

    protected function getAccessToken(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        try {
            $this->client->fetchAccessTokenWithAssertion();
            $token = $this->client->getAccessToken();
            
            if (!isset($token['access_token'])) {
                throw new \Exception('No access token in response: ' . json_encode($token));
            }
            
            $this->accessToken = $token['access_token'];
            return $this->accessToken;

        } catch (\Exception $e) {
            Log::error('Failed to get access token: ' . $e->getMessage());
            throw $e;
        }
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