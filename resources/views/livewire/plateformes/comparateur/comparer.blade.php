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
            // Étape 1: Demander à OpenAI de scraper/analyser le site pour trouver le produit exact
            $response = Http::timeout(30)->withHeaders([
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Tu es un expert en web scraping et recherche de produits. Tu dois trouver l\'URL EXACTE de la page produit sur un site e-commerce, pas une page de recherche. Analyse la structure typique des URLs du site pour construire l\'URL probable de la page produit.'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Produit à trouver: {$this->productName}\n\nSite concurrent: {$site->name}\nURL du site: {$site->url}\n\nInstructions:\n1. Analyse le nom du produit (marque, gamme, type, volume)\n2. Construis l'URL DIRECTE de la page produit (pas une recherche)\n3. Estime le prix basé sur ce type de produit\n4. Format de réponse STRICT en JSON:\n{\n  \"product_url\": \"URL complète de la page produit\",\n  \"estimated_price\": \"Prix en EUR\",\n  \"product_name\": \"Nom formaté du produit\",\n  \"confidence\": \"high/medium/low\",\n  \"notes\": \"Explication de ta logique\"\n}\n\nSi tu ne peux pas trouver une URL exacte, mets 'not_found' dans product_url."
                    ]
                ],
                'temperature' => 0.3,
                'max_tokens' => 600
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $content = $data['choices'][0]['message']['content'] ?? '';
                
                // Extraire le JSON de la réponse
                preg_match('/\{[\s\S]*\}/', $content, $matches);
                if (!empty($matches[0])) {
                    $parsedData = json_decode($matches[0], true);
                    
                    $productUrl = $parsedData['product_url'] ?? 'not_found';
                    $confidence = $parsedData['confidence'] ?? 'low';
                    
                    // Si l'URL n'a pas été trouvée, on laisse null
                    if ($productUrl === 'not_found' || empty($productUrl)) {
                        $productUrl = null;
                        $status = 'Non trouvé';
                    } else {
                        $status = $confidence === 'high' ? 'Trouvé ✓' : 'À vérifier';
                    }
                    
                    return [
                        'site_name' => $site->name,
                        'site_url' => $site->url,
                        'product_url' => $productUrl,
                        'estimated_price' => $parsedData['estimated_price'] ?? 'N/A',
                        'product_name' => $parsedData['product_name'] ?? $this->productName,
                        'confidence' => $confidence,
                        'status' => $status,
                        'notes' => $parsedData['notes'] ?? 'Analyse par IA'
                    ];
                }
            }

            // Fallback si l'API ne répond pas correctement
            return [
                'site_name' => $site->name,
                'site_url' => $site->url,
                'product_url' => null,
                'estimated_price' => 'N/A',
                'product_name' => $this->productName,
                'confidence' => 'low',
                'status' => 'Erreur API',
                'notes' => 'Impossible de contacter l\'API OpenAI'
            ];

        } catch (\Exception $e) {
            return [
                'site_name' => $site->name,
                'site_url' => $site->url,
                'product_url' => null,
                'estimated_price' => 'N/A',
                'product_name' => $this->productName,
                'confidence' => 'low',
                'status' => 'Erreur',
                'notes' => 'Erreur: ' . $e->getMessage()
            ];
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
                        <th>Statut</th>
                        <th>Confiance</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($searchResults as $index => $result)
                        <tr class="{{ $result['product_url'] ? '' : 'opacity-60' }}">
                            <th>{{ $index + 1 }}</th>
                            <td>
                                <div class="font-bold">{{ $result['site_name'] }}</div>
                                <div class="text-sm opacity-50">{{ $result['site_url'] }}</div>
                            </td>
                            <td>
                                <div>{{ $result['product_name'] }}</div>
                                @if($result['product_url'])
                                    <div class="text-xs opacity-70 mt-1 max-w-xs truncate" title="{{ $result['product_url'] }}">
                                        {{ $result['product_url'] }}
                                    </div>
                                @endif
                            </td>
                            <td>
                                <span class="badge {{ $result['estimated_price'] !== 'N/A' ? 'badge-success' : 'badge-ghost' }}">
                                    {{ $result['estimated_price'] }}
                                </span>
                            </td>
                            <td>
                                @if($result['status'] === 'Trouvé ✓')
                                    <span class="badge badge-success">{{ $result['status'] }}</span>
                                @elseif($result['status'] === 'À vérifier')
                                    <span class="badge badge-warning">{{ $result['status'] }}</span>
                                @else
                                    <span class="badge badge-error">{{ $result['status'] }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex items-center gap-1">
                                    @if($result['confidence'] === 'high')
                                        <div class="badge badge-sm badge-success gap-1">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                            </svg>
                                            Haute
                                        </div>
                                    @elseif($result['confidence'] === 'medium')
                                        <div class="badge badge-sm badge-warning gap-1">Moyenne</div>
                                    @else
                                        <div class="badge badge-sm badge-ghost gap-1">Faible</div>
                                    @endif
                                </div>
                            </td>
                            <td>
                                @if($result['product_url'])
                                    <a 
                                        href="{{ $result['product_url'] }}" 
                                        target="_blank"
                                        class="btn btn-sm btn-primary"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                        </svg>
                                        Voir produit
                                    </a>
                                @else
                                    <button class="btn btn-sm btn-disabled" disabled>
                                        Non disponible
                                    </button>
                                @endif
                            </td>
                        </tr>
                        @if($result['notes'])
                            <tr class="bg-base-200/50">
                                <td colspan="7" class="text-sm italic opacity-70">
                                    💡 {{ $result['notes'] }}
                                </td>
                            </tr>
                        @endif
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