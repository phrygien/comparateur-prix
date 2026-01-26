<?php

use Livewire\Volt\Component;
use App\Models\Site;
use Illuminate\Support\Facades\Http;

new class extends Component {
    public $productName = 'Prada - Prada Paradigme - Eau de Parfum Rechargeable 30 ml';
    public $searchResults = [];
    public $loading = false;
    public $errorMessage = '';

    public function mount()
    {
        // Initialiser avec un exemple de recherche si nécessaire
    }

    public function searchProduct()
    {
        $this->loading = true;
        $this->errorMessage = '';
        $this->searchResults = [];

        try {
            // Récupérer tous les sites concurrents
            $sites = Site::all();

            if ($sites->isEmpty()) {
                $this->errorMessage = 'Aucun site concurrent trouvé dans la base de données.';
                $this->loading = false;
                return;
            }

            // Pour chaque site, utiliser OpenAI pour générer une requête de recherche
            foreach ($sites as $site) {
                $result = $this->searchOnSite($site);
                if ($result) {
                    $this->searchResults[] = $result;
                }
            }
            

        } catch (\Exception $e) {
            $this->errorMessage = 'Erreur: ' . $e->getMessage();
        }

        $this->loading = false;
    }

    private function searchOnSite($site)
    {
        try {
            // Appel à l'API OpenAI pour construire une stratégie de recherche
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Tu es un assistant spécialisé dans la recherche de produits sur des sites e-commerce. Tu dois analyser le nom du produit et suggérer comment le trouver sur un site concurrent.'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Produit à rechercher: {$this->productName}\nSite concurrent: {$site->name} ({$site->url})\n\nGénère une URL de recherche probable et estime un prix basé sur des produits similaires. Réponds au format JSON avec les clés: search_url, estimated_price, product_name, notes"
                    ]
                ],
                'temperature' => 0.7,
                'max_tokens' => 500
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $content = $data['choices'][0]['message']['content'] ?? '';
                
                // Extraire le JSON de la réponse
                preg_match('/\{[\s\S]*\}/', $content, $matches);
                if (!empty($matches[0])) {
                    $parsedData = json_decode($matches[0], true);
                    
                    return [
                        'site_name' => $site->name,
                        'site_url' => $site->url,
                        'search_url' => $parsedData['search_url'] ?? $site->url,
                        'estimated_price' => $parsedData['estimated_price'] ?? 'N/A',
                        'product_name' => $parsedData['product_name'] ?? $this->productName,
                        'notes' => $parsedData['notes'] ?? 'Recherche générée par IA'
                    ];
                }
            }

            // Fallback si l'API ne répond pas correctement
            return [
                'site_name' => $site->name,
                'site_url' => $site->url,
                'search_url' => $site->url . '/search?q=' . urlencode($this->productName),
                'estimated_price' => 'À vérifier',
                'product_name' => $this->productName,
                'notes' => 'Recherche manuelle nécessaire'
            ];

        } catch (\Exception $e) {
            return null;
        }
    }

    public function clearResults()
    {
        $this->searchResults = [];
        $this->errorMessage = '';
    }
}; ?>

<div class="space-y-4 p-4">
    <!-- Formulaire de recherche -->
    <div class="card bg-base-200">
        <div class="card-body">
            <h2 class="card-title">Recherche de produits concurrents</h2>
            
            <div class="form-control">
                <label class="label">
                    <span class="label-text">Nom du produit</span>
                </label>
                <input 
                    type="text" 
                    wire:model="productName" 
                    placeholder="Ex: Prada - Prada Paradigme - Eau de Parfum..."
                    class="input input-bordered w-full"
                />
            </div>

            <div class="card-actions justify-end mt-4">
                <button 
                    wire:click="searchProduct" 
                    class="btn btn-primary"
                    :disabled="loading"
                >
                    @if($loading)
                        <span class="loading loading-spinner"></span>
                        Recherche en cours...
                    @else
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        Rechercher
                    @endif
                </button>
                
                @if(!empty($searchResults))
                    <button wire:click="clearResults" class="btn btn-ghost">
                        Effacer
                    </button>
                @endif
            </div>
        </div>
    </div>

    <!-- Message d'erreur -->
    @if($errorMessage)
        <div class="alert alert-error">
            <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span>{{ $errorMessage }}</span>
        </div>
    @endif

    <!-- Tableau des résultats -->
    @if(!empty($searchResults))
        <div class="overflow-x-auto rounded-box border border-base-content/5 bg-base-100">
            <table class="table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Site concurrent</th>
                        <th>Produit trouvé</th>
                        <th>Prix estimé</th>
                        <th>Notes</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($searchResults as $index => $result)
                        <tr>
                            <th>{{ $index + 1 }}</th>
                            <td>
                                <div class="font-bold">{{ $result['site_name'] }}</div>
                                <div class="text-sm opacity-50">{{ $result['site_url'] }}</div>
                            </td>
                            <td>{{ $result['product_name'] }}</td>
                            <td>
                                <span class="badge badge-success">{{ $result['estimated_price'] }}</span>
                            </td>
                            <td>
                                <div class="text-sm max-w-xs truncate" title="{{ $result['notes'] }}">
                                    {{ $result['notes'] }}
                                </div>
                            </td>
                            <td>
                                <a 
                                    href="{{ $result['search_url'] }}" 
                                    target="_blank"
                                    class="btn btn-sm btn-outline"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                    </svg>
                                    Visiter
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <!-- Statistiques -->
        <div class="stats shadow">
            <div class="stat">
                <div class="stat-title">Sites analysés</div>
                <div class="stat-value">{{ count($searchResults) }}</div>
                <div class="stat-desc">Résultats trouvés via IA</div>
            </div>
        </div>
    @else
        @if(!$loading && !$errorMessage)
            <div class="alert alert-info">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="stroke-current shrink-0 w-6 h-6">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span>Entrez un nom de produit et cliquez sur "Rechercher" pour commencer.</span>
            </div>
        @endif
    @endif
</div>