<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;

new class extends Component {
    public $products = [];
    public $searchTerm;
    public $isLoading = true;
    public $hasError = false;
    public $errorMessage = '';

    public function mount($name): void
    {
        $this->searchTerm = $name;
        $this->loadProducts($name);
    }

    public function loadProducts($search): void
    {
        try {
            $this->isLoading = true;
            $this->hasError = false;
            
            $results = $this->getCompetitorPrice($search);
            $this->products = $results['data'] ?? [];

        } catch (\Throwable $e) {
            $this->hasError = true;
            $this->errorMessage = 'Erreur lors du chargement des produits';
            $this->products = [];
        } finally {
            $this->isLoading = false;
        }
    }

    public function getCompetitorPrice($search)
    {
        try {
            if (empty(trim($search))) {
                return ["data" => []];
            }

            // Nettoyage et normalisation
            $cleanSearch = $this->cleanSearchString($search);
            $keywords = $this->extractKeywords($cleanSearch);
            
            if (empty($keywords)) {
                return ["data" => [], "keywords" => []];
            }
            
            // Recherche avec algorithme type YouTube
            $results = $this->youtubeStyleSearch($keywords, $cleanSearch);
            
            // Filtrer les résultats par score pertinent
            $filteredResults = $this->filterByRelevantScore($results);

            return [
                "data" => $filteredResults,
                "keywords" => $keywords,
                "total_results" => count($filteredResults),
                "original_search" => $search
            ];

        } catch (\Throwable $e) {
            \Log::error('Error loading products: ' . $e->getMessage(), [
                'search' => $search,
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                "data" => [],
                "error" => $e->getMessage()
            ];
        }
    }

    /**
     * Filtre les résultats pour garder ceux avec un score moyen ou élevé
     * Sans tenir compte de la variation
     */
    private function filterByRelevantScore(array $results): array
    {
        if (empty($results)) {
            return [];
        }
        
        // Récupérer le meilleur score
        $bestScore = $results[0]->relevance_score ?? 0;
        
        if ($bestScore == 0) {
            return [];
        }
        
        // Calculer le score moyen de tous les résultats
        $totalScore = array_reduce($results, function($sum, $product) {
            return $sum + ($product->relevance_score ?? 0);
        }, 0);
        $averageScore = $totalScore / count($results);
        
        // Garder les produits avec un score >= à la moyenne
        // OU >= 60% du meilleur score (pour éviter de tout filtrer)
        $threshold = max($averageScore, $bestScore * 0.6);
        
        $filtered = array_filter($results, function($product) use ($threshold) {
            return ($product->relevance_score ?? 0) >= $threshold;
        });
        
        return array_values($filtered);
    }

    /**
     * Algorithme de recherche type YouTube
     * Combine plusieurs facteurs de scoring comme YouTube le fait
     */
    private function youtubeStyleSearch(array $keywords, string $fullSearch): array
    {
        $params = [];
        $scoringClauses = [];
        $whereConditions = [];
        
        // 1. EXACT MATCH SCORE (poids le plus élevé)
        // Cherche dans name, vendor, type et variation
        $scoringClauses[] = "CASE WHEN LOWER(name) LIKE ? THEN 1000 ELSE 0 END";
        $params[] = "%" . $fullSearch . "%";
        
        $scoringClauses[] = "CASE WHEN LOWER(vendor) LIKE ? THEN 800 ELSE 0 END";
        $params[] = "%" . $fullSearch . "%";
        
        $scoringClauses[] = "CASE WHEN LOWER(type) LIKE ? THEN 600 ELSE 0 END";
        $params[] = "%" . $fullSearch . "%";
        
        $scoringClauses[] = "CASE WHEN LOWER(variation) LIKE ? THEN 400 ELSE 0 END";
        $params[] = "%" . $fullSearch . "%";
        
        // 2. STARTS WITH SCORE (commence par la recherche)
        $scoringClauses[] = "CASE WHEN LOWER(name) LIKE ? THEN 500 ELSE 0 END";
        $params[] = $fullSearch . "%";
        
        $scoringClauses[] = "CASE WHEN LOWER(vendor) LIKE ? THEN 400 ELSE 0 END";
        $params[] = $fullSearch . "%";
        
        // 3. WORD ORDER SCORE (mots dans le bon ordre)
        for ($i = 0; $i < count($keywords) - 1; $i++) {
            $pattern = "%" . $keywords[$i] . "%" . $keywords[$i + 1] . "%";
            $scoringClauses[] = "CASE WHEN LOWER(name) LIKE ? THEN " . (40 - ($i * 5)) . " ELSE 0 END";
            $params[] = $pattern;
        }
        
        // 4. INDIVIDUAL KEYWORD SCORE (chaque mot-clé)
        foreach ($keywords as $index => $keyword) {
            $weight = (count($keywords) - $index) * 10;
            
            // Recherche dans name
            $scoringClauses[] = "CASE WHEN LOWER(name) LIKE ? THEN " . ($weight * 2) . " ELSE 0 END";
            $params[] = $keyword . "%";
            
            $scoringClauses[] = "CASE WHEN LOWER(name) LIKE ? THEN $weight ELSE 0 END";
            $params[] = "%" . $keyword . "%";
            
            // Recherche dans vendor
            $scoringClauses[] = "CASE WHEN LOWER(vendor) LIKE ? THEN " . ($weight * 1.5) . " ELSE 0 END";
            $params[] = "%" . $keyword . "%";
            
            // Recherche dans type
            $scoringClauses[] = "CASE WHEN LOWER(type) LIKE ? THEN " . ($weight * 1.2) . " ELSE 0 END";
            $params[] = "%" . $keyword . "%";
            
            // Recherche dans variation
            $scoringClauses[] = "CASE WHEN LOWER(variation) LIKE ? THEN $weight ELSE 0 END";
            $params[] = "%" . $keyword . "%";
            
            // Conditions WHERE pour tous les champs (AVEC variation)
            $whereConditions[] = "LOWER(name) LIKE ?";
            $params[] = "%" . $keyword . "%";
            
            $whereConditions[] = "LOWER(vendor) LIKE ?";
            $params[] = "%" . $keyword . "%";
            
            $whereConditions[] = "LOWER(type) LIKE ?";
            $params[] = "%" . $keyword . "%";
            
            $whereConditions[] = "LOWER(variation) LIKE ?";
            $params[] = "%" . $keyword . "%";
        }
        
        // 5. WORD DENSITY SCORE (densité de mots)
        $matchCount = [];
        $matchCountParams = [];
        foreach ($keywords as $keyword) {
            $matchCount[] = "(
                CASE WHEN LOWER(name) LIKE ? THEN 1 ELSE 0 END +
                CASE WHEN LOWER(vendor) LIKE ? THEN 1 ELSE 0 END +
                CASE WHEN LOWER(type) LIKE ? THEN 1 ELSE 0 END +
                CASE WHEN LOWER(variation) LIKE ? THEN 1 ELSE 0 END
            )";
            $matchCountParams[] = "%" . $keyword . "%";
            $matchCountParams[] = "%" . $keyword . "%";
            $matchCountParams[] = "%" . $keyword . "%";
            $matchCountParams[] = "%" . $keyword . "%";
        }
        $densityScore = "(" . implode(" + ", $matchCount) . ") * 15";
        $scoringClauses[] = $densityScore;
        
        $params = array_merge($params, $matchCountParams);
        
        // 6. LENGTH PENALTY (pénalité pour noms trop longs)
        $scoringClauses[] = "CASE 
            WHEN LENGTH(name) <= 50 THEN 20
            WHEN LENGTH(name) <= 100 THEN 10
            WHEN LENGTH(name) <= 150 THEN 5
            ELSE 0 
        END";
        
        // 7. RECENCY SCORE (bonus pour les produits récents)
        $scoringClauses[] = "CASE 
            WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 30
            WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 15
            WHEN created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN 5
            ELSE 0 
        END";
        
        // Construction du score total
        $totalScore = "(" . implode(" + ", $scoringClauses) . ")";
        
        // Construction de la requête
        $whereClause = "(" . implode(" OR ", $whereConditions) . ")";
        
        $finalParams = array_merge($params, $matchCountParams);
        
        $query = "
            SELECT 
                *,
                $totalScore AS relevance_score,
                (" . implode(" + ", $matchCount) . ") AS matched_words_count
            FROM scraped_product 
            WHERE $whereClause
            HAVING relevance_score > 0
            ORDER BY relevance_score DESC, matched_words_count DESC, created_at DESC 
            LIMIT 100
        ";
        
        return DB::connection('mysql')->select($query, $finalParams);
    }

    /**
     * Nettoie la chaîne de recherche
     */
    private function cleanSearchString(string $search): string
    {
        $clean = preg_replace("/[^\p{L}\p{N}\s]/u", " ", $search);
        $clean = preg_replace("/\s+/", " ", $clean);
        return mb_strtolower(trim($clean), 'UTF-8');
    }

    /**
     * Extrait les mots-clés significatifs
     */
    private function extractKeywords(string $cleanSearch): array
    {
        $stopWords = [
            'le', 'la', 'les', 'de', 'du', 'des', 'un', 'une', 'et', 'ou', 'en', 
            'pour', 'avec', 'sans', 'sur', 'au', 'aux', 'ce', 'ces', 'son', 'sa'
        ];
        
        $words = explode(" ", $cleanSearch);
        
        $keywords = array_filter($words, function($word) use ($stopWords) {
            return mb_strlen($word, 'UTF-8') >= 2 && !in_array($word, $stopWords);
        });
        
        return array_values($keywords);
    }

}; ?>

<div>
    <!-- Section de comparaison de prix -->
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <!-- En-tête -->
        <div class="mb-8 text-center">
            <h2 class="text-2xl font-bold text-gray-900 mb-2">
                Comparaison de prix pour "{{ $searchTerm }}"
            </h2>
            <p class="text-gray-600">
                Trouvez les meilleurs prix chez différents vendeurs
            </p>
        </div>

        <!-- Loading State -->
        @if($isLoading)
            <div class="flex justify-center items-center py-12">
                <div class="text-center">
                    <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-indigo-600 mx-auto"></div>
                    <p class="mt-4 text-lg text-gray-600">Recherche des produits en cours...</p>
                </div>
            </div>
        @endif

        <!-- Error State -->
        @if($hasError && !$isLoading)
            <div class="bg-red-50 border border-red-200 rounded-lg p-8 text-center">
                <svg class="mx-auto h-12 w-12 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                </svg>
                <h3 class="mt-4 text-lg font-medium text-red-800">Erreur de chargement</h3>
                <p class="mt-2 text-red-600">{{ $errorMessage }}</p>
                <button 
                    wire:click="loadProducts('{{ $searchTerm }}')"
                    class="mt-4 inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                >
                    Réessayer
                </button>
            </div>
        @endif

        <!-- Empty State -->
        @if(!$isLoading && !$hasError && empty($products))
            <div class="bg-white border border-gray-200 rounded-xl p-12 text-center">
                <svg class="mx-auto h-16 w-16 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2M4 13h2m8-8V4a1 1 0 00-1-1h-2a1 1 0 00-1 1v1M9 7h6" />
                </svg>
                <h3 class="mt-4 text-2xl font-bold text-gray-900">Aucun produit trouvé</h3>
                <p class="mt-4 text-gray-500 max-w-md mx-auto">
                    Aucun produit correspondant à "{{ $searchTerm }}" n'a été trouvé dans notre base de données de comparaison de prix.
                </p>
                
                <div class="mt-8 bg-blue-50 border border-blue-200 rounded-lg p-6 text-left max-w-2xl mx-auto">
                    <h4 class="font-semibold text-blue-800 mb-3 flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Suggestions pour améliorer votre recherche :
                    </h4>
                    <ul class="text-blue-700 space-y-2">
                        <li class="flex items-start">
                            <span class="mr-2">•</span>
                            <span>Vérifiez l'orthographe des termes de recherche</span>
                        </li>
                        <li class="flex items-start">
                            <span class="mr-2">•</span>
                            <span>Utilisez des termes plus génériques ou simplifiés</span>
                        </li>
                        <li class="flex items-start">
                            <span class="mr-2">•</span>
                            <span>Essayez de rechercher uniquement la marque ou le type de produit</span>
                        </li>
                        <li class="flex items-start">
                            <span class="mr-2">•</span>
                            <span>Supprimez les caractères spéciaux ou les mots trop spécifiques</span>
                        </li>
                    </ul>
                </div>
            </div>
        @endif

        <!-- Products Grid -->
        @if(!$isLoading && !$hasError && !empty($products))
            <div class="mb-6 flex justify-between items-center">
                <p class="text-gray-600">
                    <span class="font-semibold">{{ count($products) }}</span> 
                    produit(s) trouvé(s) pour votre recherche
                </p>
                <div class="text-sm text-gray-500">
                    Triés par pertinence
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                @foreach($products as $product)
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden hover:shadow-md transition-shadow duration-300">
                        <!-- Product Image -->
                        <div class="aspect-w-16 aspect-h-10 bg-gray-100">
                            @if(!empty($product->image_url))
                                <img 
                                    src="{{ $product->image_url }}" 
                                    alt="{{ $product->name }}"
                                    class="w-full h-48 object-cover"
                                    onerror="this.src='https://images.unsplash.com/photo-1556228720-195a672e8a03?w=400&q=80'"
                                >
                            @else
                                <div class="w-full h-48 bg-gray-200 flex items-center justify-center">
                                    <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                </div>
                            @endif
                        </div>

                        <!-- Product Info -->
                        <div class="p-4">
                            <h3 class="font-semibold text-gray-900 text-lg line-clamp-2 mb-2">
                                {{ $product->name }}
                            </h3>
                            
                            @if(!empty($product->vendor))
                                <p class="text-sm text-gray-600 mb-2">
                                    <span class="font-medium">Vendeur :</span> {{ $product->vendor }}
                                </p>
                            @endif

                            @if(!empty($product->type))
                                <p class="text-sm text-gray-600 mb-3">
                                    <span class="font-medium">Catégorie :</span> {{ $product->type }}
                                </p>
                            @endif

                            <!-- Pricing -->
                            <div class="mb-3">
                                @if(!empty($product->discounted_price) && $product->discounted_price < $product->price)
                                    <div class="flex items-center space-x-2">
                                        <span class="text-xl font-bold text-green-600">
                                            {{ number_format($product->discounted_price, 2, ',', ' ') }} €
                                        </span>
                                        <span class="text-lg text-gray-500 line-through">
                                            {{ number_format($product->price, 2, ',', ' ') }} €
                                        </span>
                                        <span class="bg-red-100 text-red-800 text-xs font-medium px-2 py-1 rounded">
                                            Économie : {{ number_format($product->price - $product->discounted_price, 2, ',', ' ') }} €
                                        </span>
                                    </div>
                                @else
                                    <span class="text-xl font-bold text-gray-900">
                                        {{ number_format($product->price ?? 0, 2, ',', ' ') }} €
                                    </span>
                                @endif
                            </div>

                            <!-- Additional Info -->
                            <div class="flex justify-between items-center text-xs text-gray-500">
                                @if(!empty($product->variation))
                                    <span class="bg-gray-100 px-2 py-1 rounded text-gray-700">
                                        {{ $product->variation }}
                                    </span>
                                @endif
                                
                                @if(!empty($product->relevance_score))
                                    <span class="text-green-600 font-medium">
                                        Pertinence : {{ round($product->relevance_score) }}
                                    </span>
                                @endif
                            </div>

                            <!-- Matched Words -->
                            @if(!empty($product->matched_words_count))
                                <div class="mt-2 text-xs text-blue-600">
                                    Mots correspondants : {{ $product->matched_words_count }}
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <!-- Results Summary -->
            <div class="mt-8 p-4 bg-gray-50 rounded-lg border border-gray-200">
                <div class="flex flex-col sm:flex-row justify-between items-center">
                    <p class="text-gray-600 mb-2 sm:mb-0">
                        Affichage de <strong>{{ count($products) }}</strong> produit(s) 
                        pour "<strong>{{ $searchTerm }}</strong>"
                    </p>
                    <div class="text-sm text-gray-500">
                        Dernière mise à jour : {{ now()->format('d/m/Y H:i') }}
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>