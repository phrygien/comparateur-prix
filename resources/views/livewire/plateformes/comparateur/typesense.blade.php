<?php

use Livewire\Volt\Component;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

new class extends Component {
    public string $name;
    public string $id;
    public string $price;
    public Collection $productsBySite;

    public function mount($name, $id, $price): void
    {
        $this->name = $name;
        $this->id = $id;
        $this->price = $price;
        
        // Décoder les entités HTML
        $searchTerm = html_entity_decode($this->name);
        
        // Extraire les informations avec OpenAI (avec cache)
        $parsedSearch = $this->extractWithOpenAI($searchTerm);
        
        // Construire la recherche Typesense
        $products = Product::search($searchTerm, function ($typesenseClient, $query, $params) use ($parsedSearch) {
            $params['query_by'] = 'vendor,name,type,variation';
            $params['query_by_weights'] = '4,3,2,1';
            $params['exhaustive_search'] = true;
            $params['per_page'] = 250;
            
            // Construire les filtres basés sur l'extraction OpenAI
            $filters = [];
            
            if (!empty($parsedSearch['vendor'])) {
                // Recherche flexible sur le vendor
                $filters[] = "vendor:~{$parsedSearch['vendor']}";
            }
            
            if (!empty($parsedSearch['type'])) {
                $filters[] = "type:~{$parsedSearch['type']}";
            }
            
            // Optionnel : filtrer par variation si elle est spécifique
            if (!empty($parsedSearch['variation']) && strlen($parsedSearch['variation']) > 2) {
                $filters[] = "variation:~{$parsedSearch['variation']}";
            }
            
            if (!empty($filters)) {
                $params['filter_by'] = implode(' && ', $filters);
            }
            
            return $typesenseClient->collections[$params['collection']]->documents->search($params);
        })
        ->query(fn($query) => $query->with('website'))
        ->get();
        
        // Grouper par site et sélectionner le dernier produit scrapé
        $this->productsBySite = $products
            ->groupBy('web_site_id')
            ->map(function ($siteProducts) {
                return $siteProducts
                    ->groupBy('scrap_reference_id')
                    ->map(function ($refProducts) {
                        return $refProducts->sortByDesc('created_at')->first();
                    })
                    ->values();
            });
    }
    
    /**
     * Extraire les informations du produit avec OpenAI
     */
    private function extractWithOpenAI(string $searchTerm): array
    {
        // Cache pour éviter les appels répétés à l'API
        $cacheKey = 'openai_extract_' . md5($searchTerm);
        
        return Cache::remember($cacheKey, now()->addHours(24), function () use ($searchTerm) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . config('services.openai.api_key'),
                    'Content-Type' => 'application/json',
                ])
                ->timeout(10)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini', // Ou 'gpt-4o' pour plus de précision
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Tu es un assistant spécialisé dans l\'extraction d\'informations de produits cosmétiques et parfums. 
                            Extrait les informations suivantes du texte fourni et retourne UNIQUEMENT un JSON valide sans aucun texte supplémentaire.
                            Format attendu :
                            {
                                "vendor": "nom de la marque",
                                "name": "nom du produit",
                                "type": "type de produit (Eau de Parfum, Eau de Toilette, etc.)",
                                "variation": "contenance ou variation (100 ml, 50ml, etc.)"
                            }
                            Si une information n\'est pas trouvée, retourne une chaîne vide pour ce champ.'
                        ],
                        [
                            'role' => 'user',
                            'content' => "Extrait les informations de ce produit : $searchTerm"
                        ]
                    ],
                    'temperature' => 0.1,
                    'max_tokens' => 200,
                    'response_format' => ['type' => 'json_object']
                ]);

                if ($response->successful()) {
                    $content = $response->json('choices.0.message.content');
                    $extracted = json_decode($content, true);
                    
                    return [
                        'vendor' => $extracted['vendor'] ?? '',
                        'name' => $extracted['name'] ?? '',
                        'type' => $extracted['type'] ?? '',
                        'variation' => $extracted['variation'] ?? '',
                    ];
                }
                
                // En cas d'erreur, retourner des valeurs vides
                \Log::warning('OpenAI extraction failed', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                
            } catch (\Exception $e) {
                \Log::error('OpenAI extraction error', [
                    'message' => $e->getMessage(),
                    'search_term' => $searchTerm
                ]);
            }
            
            // Fallback : retourner des valeurs vides
            return [
                'vendor' => '',
                'name' => '',
                'type' => '',
                'variation' => '',
            ];
        });
    }
    
}; ?>

<div class="bg-white">
    <div class="mx-auto max-w-7xl overflow-hidden sm:px-6 lg:px-8">
        <h2 class="text-2xl font-bold text-gray-900 px-4 sm:px-0 py-6">
            Résultats pour : {{ $name }}
        </h2>

        @if($productsBySite->count() > 0)
            @foreach($productsBySite as $siteId => $siteProducts)
                @php
                    $site = $siteProducts->first()->website ?? null;
                @endphp
                
                <div class="mb-8">
                    <!-- En-tête du site -->
                    <div class="bg-gray-50 px-4 sm:px-6 py-4 border-b-2 border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">
                            {{ $site?->name ?? 'Site inconnu' }}
                        </h3>
                        @if($site?->url)
                            <a href="{{ $site->url }}" target="_blank" class="text-sm text-blue-600 hover:text-blue-800">
                                {{ $site->url }}
                            </a>
                        @endif
                        <p class="text-sm text-gray-500 mt-1">
                            {{ $siteProducts->count() }} {{ $siteProducts->count() > 1 ? 'produits' : 'produit' }}
                        </p>
                    </div>

                    <!-- Grille des produits du site -->
                    <div class="-mx-px grid grid-cols-2 border-l border-gray-200 sm:mx-0 md:grid-cols-3 lg:grid-cols-4">
                        @foreach($siteProducts as $product)
                            <div class="group relative border-r border-b border-gray-200 p-4 sm:p-6">
                                <div class="aspect-square rounded-lg bg-gray-200 overflow-hidden">
                                    <img 
                                        src="{{ $product->image_url }}" 
                                        alt="{{ $product->vendor }} - {{ $product->name }}" 
                                        class="h-full w-full object-cover group-hover:opacity-75"
                                    >
                                </div>
                                <div class="pt-10 pb-4 text-center">
                                    <h3 class="text-sm font-medium text-gray-900">
                                        <a href="{{ $product->url }}" target="_blank">
                                            <span aria-hidden="true" class="absolute inset-0"></span>
                                            {{ $product->vendor }} - {{ $product->name }}
                                        </a>
                                    </h3>
                                    <div class="mt-3 flex flex-col items-center">
                                        <p class="text-xs text-gray-600">{{ $product->type }}</p>
                                        <p class="mt-1 text-xs text-gray-500">{{ $product->variation }}</p>
                                        @if($product->scrap_reference_id)
                                            <p class="mt-1 text-xs text-gray-400">Réf: {{ $product->scrap_reference_id }}</p>
                                        @endif
                                        @if($product->created_at)
                                            <p class="mt-1 text-xs text-gray-400">
                                                Scrapé le {{ $product->created_at->format('d/m/Y') }}
                                            </p>
                                        @endif
                                    </div>
                                    <p class="mt-4 text-base font-medium text-gray-900">
                                        {{ $product->prix_ht }} {{ $product->currency }}
                                    </p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        @else
            <div class="text-center py-12">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <h3 class="mt-2 text-sm font-semibold text-gray-900">Aucun produit trouvé</h3>
                <p class="mt-1 text-sm text-gray-500">Aucun résultat pour "{{ $name }}"</p>
            </div>
        @endif
    </div>
</div>