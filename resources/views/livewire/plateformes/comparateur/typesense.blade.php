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
        
        // Extraire les informations avec OpenAI
        $parsedSearch = $this->extractWithOpenAI($searchTerm);
        
        // Rechercher les produits avec correspondance exacte
        $products = $this->searchExactMatch($parsedSearch);
        
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
     * Rechercher les produits avec correspondance exacte sur TOUS les critères
     */
    private function searchExactMatch(array $parsedSearch): Collection
    {
        // Commencer avec une recherche large
        $query = Product::query()->with('website');
        
        // Filtrer par vendor (OBLIGATOIRE)
        if (!empty($parsedSearch['vendor'])) {
            $query->where('vendor', 'LIKE', '%' . $parsedSearch['vendor'] . '%');
        } else {
            // Si pas de vendor, retourner vide car c'est un critère essentiel
            return collect();
        }
        
        // Filtrer par name (OBLIGATOIRE)
        if (!empty($parsedSearch['name'])) {
            $query->where('name', 'LIKE', '%' . $parsedSearch['name'] . '%');
        } else {
            // Si pas de name, retourner vide
            return collect();
        }
        
        // Filtrer par type (OBLIGATOIRE si présent dans la recherche)
        if (!empty($parsedSearch['type'])) {
            $query->where('type', 'LIKE', '%' . $parsedSearch['type'] . '%');
        }
        
        // Filtrer par variation (OPTIONNEL - peut être flexible)
        if (!empty($parsedSearch['variation'])) {
            // Normaliser la variation pour la comparaison (ex: 100ml, 100 ml, 100ML)
            $normalizedVariation = preg_replace('/\s+/', '', strtolower($parsedSearch['variation']));
            
            $query->where(function ($q) use ($parsedSearch, $normalizedVariation) {
                $q->where('variation', 'LIKE', '%' . $parsedSearch['variation'] . '%')
                  ->orWhereRaw('LOWER(REPLACE(variation, " ", "")) LIKE ?', ['%' . $normalizedVariation . '%']);
            });
        }
        
        // Récupérer les produits
        $products = $query->get();
        
        // Scoring plus strict pour s'assurer de la meilleure correspondance
        return $products->map(function ($product) use ($parsedSearch) {
            $score = 0;
            $matches = 0;
            
            // Vérification vendor (poids le plus élevé)
            if (!empty($parsedSearch['vendor'])) {
                $vendorMatch = $this->calculateSimilarity($product->vendor, $parsedSearch['vendor']);
                $score += $vendorMatch * 40;
                if ($vendorMatch > 0.7) $matches++;
            }
            
            // Vérification name
            if (!empty($parsedSearch['name'])) {
                $nameMatch = $this->calculateSimilarity($product->name, $parsedSearch['name']);
                $score += $nameMatch * 30;
                if ($nameMatch > 0.6) $matches++;
            }
            
            // Vérification type
            if (!empty($parsedSearch['type'])) {
                $typeMatch = $this->calculateSimilarity($product->type, $parsedSearch['type']);
                $score += $typeMatch * 20;
                if ($typeMatch > 0.7) $matches++;
            }
            
            // Vérification variation
            if (!empty($parsedSearch['variation'])) {
                $variationMatch = $this->calculateSimilarity($product->variation, $parsedSearch['variation']);
                $score += $variationMatch * 10;
                if ($variationMatch > 0.6) $matches++;
            }
            
            $product->match_score = $score;
            $product->match_count = $matches;
            
            return $product;
        })
        ->filter(function ($product) use ($parsedSearch) {
            // Ne garder que les produits avec au moins 3 correspondances sur 4
            // Ou tous les critères non-vides doivent matcher
            $requiredMatches = collect($parsedSearch)->filter(fn($v) => !empty($v))->count();
            
            // Seuil minimum : au moins 75% des critères doivent correspondre
            return $product->match_count >= ($requiredMatches * 0.75) && $product->match_score >= 60;
        })
        ->sortByDesc('match_score')
        ->values();
    }
    
    /**
     * Calculer la similarité entre deux chaînes (0 à 1)
     */
    private function calculateSimilarity(string $str1, string $str2): float
    {
        if (empty($str1) || empty($str2)) {
            return 0;
        }
        
        // Normaliser les chaînes
        $str1 = strtolower(trim($str1));
        $str2 = strtolower(trim($str2));
        
        // Si correspondance exacte
        if ($str1 === $str2) {
            return 1.0;
        }
        
        // Si l'un contient l'autre
        if (strpos($str1, $str2) !== false || strpos($str2, $str1) !== false) {
            return 0.9;
        }
        
        // Utiliser similar_text pour calculer la similarité
        similar_text($str1, $str2, $percent);
        
        return $percent / 100;
    }
    
    /**
     * Extraire les informations du produit avec OpenAI
     */
    private function extractWithOpenAI(string $searchTerm): array
    {
        $cacheKey = 'openai_extract_' . md5($searchTerm);
        
        return Cache::remember($cacheKey, now()->addHours(24), function () use ($searchTerm) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . config('services.openai.api_key'),
                    'Content-Type' => 'application/json',
                ])
                ->timeout(10)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Tu es un assistant spécialisé dans l\'extraction d\'informations de produits cosmétiques et parfums. 
                            Extrait les informations suivantes du texte fourni et retourne UNIQUEMENT un JSON valide sans aucun texte supplémentaire.
                            
                            Règles importantes :
                            - Pour le vendor : extrait UNIQUEMENT le nom de la marque principale (ex: "Valentino", "Coach", "Dior", "Chanel")
                            - Pour le name : extrait le nom du produit COMPLET sans la marque mais avec tous les détails (ex: "Born In Roma", "Coach Poppy", "J\'adore")
                            - Pour le type : utilise les termes EXACTS et standards (Eau de Parfum, Eau de Toilette, Parfum, Cologne, Body Lotion, Shower Gel, Deodorant)
                            - Pour la variation : extrait TOUT ce qui décrit la variation (contenance, édition, pour homme/femme, etc.) (ex: "100 ml", "Edition Limitée", "pour Homme", "Edition Limitée pour Homme 100ml")
                            
                            Exemples :
                            Input: "Valentino - Born In Roma - Eau de Toilette pour Homme - Edition Limitée"
                            Output: {
                                "vendor": "Valentino",
                                "name": "Born In Roma",
                                "type": "Eau de Toilette",
                                "variation": "pour Homme - Edition Limitée"
                            }
                            
                            Input: "Coach - Coach Poppy - Eau de Parfum 100 ml"
                            Output: {
                                "vendor": "Coach",
                                "name": "Coach Poppy",
                                "type": "Eau de Parfum",
                                "variation": "100 ml"
                            }
                            
                            Format attendu :
                            {
                                "vendor": "nom de la marque uniquement",
                                "name": "nom du produit complet sans marque",
                                "type": "type de produit standardisé",
                                "variation": "toutes les variations et détails"
                            }
                            
                            Si une information n\'est pas trouvée, retourne une chaîne vide pour ce champ.'
                        ],
                        [
                            'role' => 'user',
                            'content' => "Extrait les informations de ce produit : $searchTerm"
                        ]
                    ],
                    'temperature' => 0.1,
                    'max_tokens' => 250,
                    'response_format' => ['type' => 'json_object']
                ]);

                if ($response->successful()) {
                    $content = $response->json('choices.0.message.content');
                    $extracted = json_decode($content, true);
                    
                    return [
                        'vendor' => trim($extracted['vendor'] ?? ''),
                        'name' => trim($extracted['name'] ?? ''),
                        'type' => trim($extracted['type'] ?? ''),
                        'variation' => trim($extracted['variation'] ?? ''),
                    ];
                }
                
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
                                    <!-- Badge de correspondance -->
                                    @if(isset($product->match_score) && $product->match_score >= 85)
                                        <span class="absolute top-2 right-2 inline-flex items-center rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700">
                                            Correspondance exacte
                                        </span>
                                    @endif
                                    
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
                                        @if(config('app.debug') && isset($product->match_score))
                                            <p class="mt-1 text-xs text-blue-500 font-semibold">
                                                Score: {{ round($product->match_score, 1) }} ({{ $product->match_count ?? 0 }}/4 critères)
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
                <p class="mt-1 text-sm text-gray-500">Aucune correspondance exacte pour "{{ $name }}"</p>
            </div>
        @endif
    </div>
</div>