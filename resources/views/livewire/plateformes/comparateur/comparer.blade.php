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

            // Pour chaque site, utiliser OpenAI avec web search pour trouver le produit
            foreach ($sites as $site) {
                $result = $this->searchOnSiteWithAI($site);
                if ($result) {
                    $this->searchResults[] = $result;
                }
            }

        } catch (\Exception $e) {
            $this->errorMessage = 'Erreur: ' . $e->getMessage();
        }

        $this->loading = false;
    }

    private function searchOnSiteWithAI($site)
    {
        try {
            // Appel à l'API OpenAI avec web_search tool activé
            $response = Http::timeout(60)->withHeaders([
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Tu es un assistant spécialisé dans la recherche de produits e-commerce. Tu dois utiliser la recherche web pour trouver le produit exact sur le site indiqué et retourner UNIQUEMENT un JSON valide sans aucun texte avant ou après.'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Recherche ce produit: \"{$this->productName}\" sur le site {$site->name} ({$site->url})\n\nÉtapes:\n1. Recherche le produit exact sur {$site->url}\n2. Trouve l'URL DIRECTE de la page produit (pas de recherche)\n3. Récupère le prix exact affiché\n4. Vérifie que le lien fonctionne\n\nRéponds UNIQUEMENT avec ce JSON (sans markdown, sans texte additionnel):\n{\n  \"product_url\": \"URL complète de la page produit ou null si non trouvé\",\n  \"price\": \"Prix exact avec devise (ex: 89.90 EUR) ou null\",\n  \"product_name\": \"Nom exact du produit sur le site\",\n  \"availability\": \"en stock/rupture/inconnu\",\n  \"found\": true ou false\n}"
                    ]
                ],
                'temperature' => 0.2,
                'max_tokens' => 800
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $content = $data['choices'][0]['message']['content'] ?? '';
                
                // Nettoyer le contenu des éventuels markdown
                $content = preg_replace('/```json\s*|\s*```/', '', $content);
                $content = trim($content);
                
                // Parser le JSON
                $parsedData = json_decode($content, true);
                
                if ($parsedData && is_array($parsedData)) {
                    $found = $parsedData['found'] ?? false;
                    $productUrl = $parsedData['product_url'] ?? null;
                    $price = $parsedData['price'] ?? null;
                    
                    // Déterminer le statut
                    if ($found && $productUrl) {
                        $status = 'Trouvé ✓';
                        $confidence = 'high';
                    } elseif ($productUrl) {
                        $status = 'À vérifier';
                        $confidence = 'medium';
                    } else {
                        $status = 'Non trouvé';
                        $confidence = 'low';
                        $productUrl = null;
                    }
                    
                    return [
                        'site_name' => $site->name,
                        'site_url' => $site->url,
                        'product_url' => $productUrl,
                        'price' => $price ?? 'N/A',
                        'product_name' => $parsedData['product_name'] ?? $this->productName,
                        'availability' => $parsedData['availability'] ?? 'inconnu',
                        'confidence' => $confidence,
                        'status' => $status,
                        'found' => $found
                    ];
                }
            }

            // Fallback si erreur de parsing
            return [
                'site_name' => $site->name,
                'site_url' => $site->url,
                'product_url' => null,
                'price' => 'N/A',
                'product_name' => $this->productName,
                'availability' => 'inconnu',
                'confidence' => 'low',
                'status' => 'Erreur',
                'found' => false
            ];

        } catch (\Exception $e) {
            return [
                'site_name' => $site->name,
                'site_url' => $site->url,
                'product_url' => null,
                'price' => 'N/A',
                'product_name' => $this->productName,
                'availability' => 'inconnu',
                'confidence' => 'low',
                'status' => 'Erreur',
                'found' => false
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
            <h2 class="card-title">Recherche de produits concurrents avec OpenAI</h2>
            
            <div class="alert alert-info">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="stroke-current shrink-0 w-6 h-6">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <div>
                    <div class="font-bold">Recherche intelligente activée</div>
                    <div class="text-sm">OpenAI va rechercher le produit exact sur chaque site concurrent et récupérer l'URL et le prix réels.</div>
                </div>
            </div>

            <div class="form-control">
                <label class="label">
                    <span class="label-text font-semibold">Nom du produit</span>
                </label>
                <input 
                    type="text" 
                    wire:model="productName" 
                    placeholder="Ex: Lancôme – La Nuit Trésor Rouge Drama – Eau de Parfum Intense 30 ml"
                    class="input input-bordered w-full"
                />
                <label class="label">
                    <span class="label-text-alt">Soyez aussi précis que possible (marque, gamme, volume)</span>
                </label>
            </div>

            <div class="card-actions justify-end mt-4">
                <button 
                    wire:click="searchProduct" 
                    class="btn btn-primary"
                    :disabled="loading"
                >
                    @if($loading)
                        <span class="loading loading-spinner"></span>
                        Recherche en cours avec IA...
                    @else
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        Rechercher avec OpenAI
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
        <div class="overflow-x-auto rounded-box border border-base-content/5 bg-base-100 shadow-lg">
            <table class="table">
                <thead class="bg-base-200">
                    <tr>
                        <th>#</th>
                        <th>Site concurrent</th>
                        <th>Produit trouvé</th>
                        <th>Prix réel</th>
                        <th>Disponibilité</th>
                        <th>Statut</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($searchResults as $index => $result)
                        <tr class="{{ $result['found'] ? 'hover:bg-success/10' : 'opacity-60' }}">
                            <th>{{ $index + 1 }}</th>
                            <td>
                                <div class="flex items-center gap-2">
                                    <div>
                                        <div class="font-bold">{{ $result['site_name'] }}</div>
                                        <div class="text-xs opacity-50">{{ parse_url($result['site_url'], PHP_URL_HOST) }}</div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="max-w-xs">
                                    <div class="font-medium">{{ $result['product_name'] }}</div>
                                    @if($result['product_url'])
                                        <div class="text-xs opacity-60 mt-1 truncate" title="{{ $result['product_url'] }}">
                                            🔗 {{ $result['product_url'] }}
                                        </div>
                                    @endif
                                </div>
                            </td>
                            <td>
                                @if($result['price'] !== 'N/A')
                                    <div class="badge badge-lg badge-success gap-2">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        {{ $result['price'] }}
                                    </div>
                                @else
                                    <span class="badge badge-ghost">Prix non disponible</span>
                                @endif
                            </td>
                            <td>
                                @if($result['availability'] === 'en stock')
                                    <span class="badge badge-success gap-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                        </svg>
                                        En stock
                                    </span>
                                @elseif($result['availability'] === 'rupture')
                                    <span class="badge badge-error">Rupture</span>
                                @else
                                    <span class="badge badge-ghost">Inconnu</span>
                                @endif
                            </td>
                            <td>
                                @if($result['status'] === 'Trouvé ✓')
                                    <span class="badge badge-success gap-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                        </svg>
                                        {{ $result['status'] }}
                                    </span>
                                @elseif($result['status'] === 'À vérifier')
                                    <span class="badge badge-warning">{{ $result['status'] }}</span>
                                @else
                                    <span class="badge badge-error">{{ $result['status'] }}</span>
                                @endif
                            </td>
                            <td>
                                @if($result['product_url'])
                                    <a 
                                        href="{{ $result['product_url'] }}" 
                                        target="_blank"
                                        class="btn btn-sm btn-primary gap-2"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                        </svg>
                                        Voir le produit
                                    </a>
                                @else
                                    <button class="btn btn-sm btn-disabled" disabled>
                                        Non disponible
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <!-- Statistiques et résumé -->
        <div class="stats stats-vertical lg:stats-horizontal shadow w-full">
            <div class="stat">
                <div class="stat-figure text-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div class="stat-title">Sites analysés</div>
                <div class="stat-value text-primary">{{ count($searchResults) }}</div>
                <div class="stat-desc">Recherche OpenAI complète</div>
            </div>

            <div class="stat">
                <div class="stat-figure text-success">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                    </svg>
                </div>
                <div class="stat-title">Produits trouvés</div>
                <div class="stat-value text-success">{{ collect($searchResults)->where('found', true)->count() }}</div>
                <div class="stat-desc">Avec URL et prix réels</div>
            </div>

            <div class="stat">
                <div class="stat-figure text-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div class="stat-title">Prix moyen</div>
                <div class="stat-value text-secondary">
                    @php
                        $prices = collect($searchResults)
                            ->pluck('price')
                            ->filter(fn($p) => $p !== 'N/A')
                            ->map(fn($p) => (float) preg_replace('/[^0-9.]/', '', $p))
                            ->filter(fn($p) => $p > 0);
                        
                        $avgPrice = $prices->isNotEmpty() ? number_format($prices->average(), 2) : 'N/A';
                    @endphp
                    {{ $avgPrice !== 'N/A' ? $avgPrice . ' €' : 'N/A' }}
                </div>
                <div class="stat-desc">Calculé sur les prix trouvés</div>
            </div>
        </div>
    @else
        @if(!$loading && !$errorMessage)
            <div class="alert">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="stroke-info shrink-0 w-6 h-6">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <div>
                    <h3 class="font-bold">Prêt à rechercher</h3>
                    <div class="text-sm">Entrez le nom d'un produit et cliquez sur "Rechercher avec OpenAI" pour que l'IA trouve automatiquement les URLs et prix sur vos sites concurrents.</div>
                </div>
            </div>
        @endif
    @endif
</div>