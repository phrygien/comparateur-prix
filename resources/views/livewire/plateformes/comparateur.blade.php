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

            // Analyse approfondie de la recherche
            $searchAnalysis = $this->analyzePerfumeSearch($search);
            
            // Recherche avancée avec compréhension du contexte parfumerie
            $results = $this->advancedPerfumeSearch($searchAnalysis);
            
            // Classement intelligent des résultats
            $rankedResults = $this->rankPerfumeResults($results, $searchAnalysis);

            return [
                "data" => $rankedResults,
                "search_analysis" => $searchAnalysis,
                "total_results" => count($rankedResults),
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
     * Analyse spécialisée pour les recherches parfumerie
     */
    private function analyzePerfumeSearch(string $search): array
    {
        $cleanSearch = $this->cleanSearchString($search);
        
        // Détection des composants typiques des parfums
        $components = [
            'brand' => '',
            'collection' => '',
            'product_name' => '',
            'volume' => '',
            'type' => '',
            'is_set' => false,
            'keywords' => []
        ];

        // Marques de parfums connues
        $perfumeBrands = [
            'guerlain', 'chanel', 'dior', 'ysl', 'lancôme', 'givenchy', 'hermès',
            'prada', 'versace', 'armani', 'dolce', 'gabbana', 'victoria', 'secret',
            'jean paul', 'aultier', 'mugler', 'pacorabanne', 'cartier', 'bvlgari'
        ];

        // Collections connues
        $collections = [
            'aqua allegoria', 'la petite', 'black opium', 'shalimar', 'j\'adore',
            'miss dior', 'coco mademoiselle', 'flowerbomb', 'angel', 'one million'
        ];

        // Types de produits
        $productTypes = [
            'eau de parfum', 'eau de toilette', 'parfum', 'extrait', 'coffret',
            'set', 'box', 'edp', 'edt', 'spray', 'vaporisateur'
        ];

        // Détection de la marque
        foreach ($perfumeBrands as $brand) {
            if (str_contains($cleanSearch, $brand)) {
                $components['brand'] = $brand;
                $cleanSearch = str_replace($brand, '', $cleanSearch);
                break;
            }
        }

        // Détection des collections
        foreach ($collections as $collection) {
            if (str_contains($cleanSearch, $collection)) {
                $components['collection'] = $collection;
                $cleanSearch = str_replace($collection, '', $cleanSearch);
                break;
            }
        }

        // Détection du type de produit
        foreach ($productTypes as $type) {
            if (str_contains($cleanSearch, $type)) {
                $components['type'] = $type;
                $cleanSearch = str_replace($type, '', $cleanSearch);
                break;
            }
        }

        // Détection des volumes (ex: 75 ml, 100ml, 7,5 ml)
        preg_match_all('/(\d+[,\.]?\d*)\s*(ml|mls|millilitres?)/i', $search, $volumeMatches);
        if (!empty($volumeMatches[0])) {
            $components['volume'] = implode(' + ', $volumeMatches[0]);
        }

        // Détection des coffrets (présence de + ou "coffret")
        $components['is_set'] = str_contains($cleanSearch, '+') || 
                               str_contains($cleanSearch, 'coffret') || 
                               str_contains($cleanSearch, 'set');

        // Mots-clés restants (nom du parfum)
        $remainingKeywords = array_filter(explode(' ', trim($cleanSearch)));
        $components['product_name'] = implode(' ', $remainingKeywords);
        $components['keywords'] = $remainingKeywords;

        return $components;
    }

    /**
     * Recherche avancée spécialisée parfumerie
     */
    private function advancedPerfumeSearch(array $searchAnalysis): array
    {
        $params = [];
        $scoringClauses = [];
        $whereConditions = [];

        // 1. SCORING PAR MARQUE (très important)
        if (!empty($searchAnalysis['brand'])) {
            $scoringClauses[] = "CASE WHEN LOWER(name) LIKE ? THEN 800 ELSE 0 END";
            $params[] = "%" . $searchAnalysis['brand'] . "%";
            
            $scoringClauses[] = "CASE WHEN LOWER(vendor) LIKE ? THEN 400 ELSE 0 END";
            $params[] = "%" . $searchAnalysis['brand'] . "%";
            
            $whereConditions[] = "LOWER(name) LIKE ?";
            $params[] = "%" . $searchAnalysis['brand'] . "%";
        }

        // 2. SCORING PAR COLLECTION
        if (!empty($searchAnalysis['collection'])) {
            $scoringClauses[] = "CASE WHEN LOWER(name) LIKE ? THEN 600 ELSE 0 END";
            $params[] = "%" . $searchAnalysis['collection'] . "%";
            
            $whereConditions[] = "LOWER(name) LIKE ?";
            $params[] = "%" . $searchAnalysis['collection'] . "%";
        }

        // 3. SCORING PAR NOM DU PRODUIT
        if (!empty($searchAnalysis['product_name'])) {
            $scoringClauses[] = "CASE WHEN LOWER(name) LIKE ? THEN 500 ELSE 0 END";
            $params[] = "%" . $searchAnalysis['product_name'] . "%";
            
            $scoringClauses[] = "CASE WHEN LOWER(name) LIKE ? THEN 300 ELSE 0 END";
            $params[] = "%" . str_replace(' ', '%', $searchAnalysis['product_name']) . "%";
            
            $whereConditions[] = "LOWER(name) LIKE ?";
            $params[] = "%" . $searchAnalysis['product_name'] . "%";
        }

        // 4. SCORING PAR TYPE DE PRODUIT
        if (!empty($searchAnalysis['type'])) {
            $scoringClauses[] = "CASE WHEN LOWER(name) LIKE ? THEN 200 ELSE 0 END";
            $params[] = "%" . $searchAnalysis['type'] . "%";
        }

        // 5. SCORING PAR VOLUME
        if (!empty($searchAnalysis['volume'])) {
            $scoringClauses[] = "CASE WHEN LOWER(name) LIKE ? THEN 150 ELSE 0 END";
            $params[] = "%" . $searchAnalysis['volume'] . "%";
            
            // Recherche aussi les volumes séparés
            $volumes = explode(' + ', $searchAnalysis['volume']);
            foreach ($volumes as $volume) {
                $scoringClauses[] = "CASE WHEN LOWER(name) LIKE ? THEN 100 ELSE 0 END";
                $params[] = "%" . trim($volume) . "%";
            }
        }

        // 6. SCORING POUR COFFRETS/SETS
        if ($searchAnalysis['is_set']) {
            $scoringClauses[] = "CASE WHEN LOWER(name) LIKE ? THEN 200 ELSE 0 END";
            $params[] = "%coffret%";
            
            $scoringClauses[] = "CASE WHEN LOWER(name) LIKE ? THEN 200 ELSE 0 END";
            $params[] = "%set%";
            
            $scoringClauses[] = "CASE WHEN LOWER(name) LIKE '%+%' THEN 150 ELSE 0 END";
            
            $scoringClauses[] = "CASE WHEN LOWER(name) LIKE ? THEN 100 ELSE 0 END";
            $params[] = "%kit%";
        }

        // 7. SCORING PAR MOTS-CLÉS INDIVIDUELS
        foreach ($searchAnalysis['keywords'] as $index => $keyword) {
            if (strlen($keyword) > 2) { // Ignorer les mots trop courts
                $weight = (count($searchAnalysis['keywords']) - $index) * 30;
                
                $scoringClauses[] = "CASE WHEN LOWER(name) LIKE ? THEN $weight ELSE 0 END";
                $params[] = $keyword . "%";
                
                $scoringClauses[] = "CASE WHEN LOWER(name) LIKE ? THEN " . ($weight * 0.8) . " ELSE 0 END";
                $params[] = "%" . $keyword . "%";
                
                $whereConditions[] = "LOWER(name) LIKE ?";
                $params[] = "%" . $keyword . "%";
            }
        }

        // 8. FACTEURS DE QUALITÉ GÉNÉRAUX
        $scoringClauses[] = "CASE WHEN LENGTH(name) BETWEEN 20 AND 200 THEN 50 ELSE 0 END";
        $scoringClauses[] = "CASE WHEN prix_ht > 0 THEN 30 ELSE 0 END";
        $scoringClauses[] = "CASE WHEN image_url IS NOT NULL THEN 20 ELSE 0 END";

        // Construction de la requête
        $totalScore = "(" . implode(" + ", $scoringClauses) . ")";
        $whereClause = !empty($whereConditions) ? "WHERE (" . implode(" OR ", $whereConditions) . ")" : "WHERE 1=1";

        $query = "
            SELECT 
                *,
                $totalScore AS relevance_score,
                LENGTH(name) as name_length
            FROM scraped_product 
            $whereClause
            HAVING relevance_score > 50  -- Seuil plus élevé pour la précision
            ORDER BY relevance_score DESC, name_length ASC
            LIMIT 100
        ";

        return DB::connection('mysql')->select($query, $params);
    }

    /**
     * Classement intelligent des résultats parfumerie
     */
    private function rankPerfumeResults(array $results, array $searchAnalysis): array
    {
        if (empty($results)) {
            return [];
        }

        foreach ($results as &$result) {
            $score = $result->relevance_score;
            
            // Bonus pour la correspondance exacte de marque
            if (!empty($searchAnalysis['brand']) && $this->containsWord($result->name, $searchAnalysis['brand'])) {
                $score += 100;
            }
            
            // Bonus pour la collection
            if (!empty($searchAnalysis['collection']) && $this->containsWord($result->name, $searchAnalysis['collection'])) {
                $score += 80;
            }
            
            // Bonus pour le nom du produit
            if (!empty($searchAnalysis['product_name']) && $this->containsWord($result->name, $searchAnalysis['product_name'])) {
                $score += 60;
            }
            
            // Bonus pour les coffrets si recherche de coffret
            if ($searchAnalysis['is_set'] && (
                str_contains(strtolower($result->name), 'coffret') ||
                str_contains(strtolower($result->name), 'set') ||
                str_contains(strtolower($result->name), '+')
            )) {
                $score += 70;
            }
            
            // Bonus pour les volumes correspondants
            if (!empty($searchAnalysis['volume'])) {
                $volumes = explode(' + ', $searchAnalysis['volume']);
                $volumeMatches = 0;
                foreach ($volumes as $volume) {
                    if (str_contains($result->name, trim($volume))) {
                        $volumeMatches++;
                    }
                }
                $score += $volumeMatches * 40;
            }
            
            // Pénalité pour les noms trop longs (souvent des descriptions)
            if (strlen($result->name) > 250) {
                $score -= 30;
            }
            
            $result->final_score = $score;
        }

        // Tri par score final
        usort($results, function($a, $b) {
            return $b->final_score <=> $a->final_score;
        });

        return array_slice($results, 0, 50); // Limiter aux 50 meilleurs
    }

    /**
     * Vérifie si un mot est présent dans le texte (recherche de mot entier)
     */
    private function containsWord(string $text, string $word): bool
    {
        $text = ' ' . strtolower($text) . ' ';
        $word = ' ' . strtolower(trim($word)) . ' ';
        return str_contains($text, $word);
    }

    /**
     * Nettoie la chaîne de recherche
     */
    private function cleanSearchString(string $search): string
    {
        // Conservation des caractères spéciaux importants pour les parfums (+ , . -)
        $clean = preg_replace("/[^\p{L}\p{N}\s\-\+\.]/u", " ", $search);
        $clean = preg_replace("/\s+/", " ", $clean);
        return mb_strtolower(trim($clean), 'UTF-8');
    }

    /**
     * Trier les produits par prix HT décroissant
     */
    public function sortByPriceDesc()
    {
        usort($this->products, function($a, $b) {
            $priceA = $a->prix_ht ?? 0;
            $priceB = $b->prix_ht ?? 0;
            return $priceB <=> $priceA;
        });
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

        <!-- Products Table -->
        @if(!$isLoading && !$hasError && !empty($products))
            <div class="mb-6 flex justify-between items-center">
                <p class="text-gray-600">
                    <span class="font-semibold">{{ count($products) }}</span> 
                    produit(s) trouvé(s) pour votre recherche
                </p>
                <div class="flex space-x-4">
                    <button 
                        wire:click="sortByPriceDesc"
                        class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                    >
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h9m5-4v12m0 0l-4-4m4 4l4-4" />
                        </svg>
                        Trier par prix HT décroissant
                    </button>
                </div>
            </div>

            <!-- Tableau des produits -->
            <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Produit
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Vendeur
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Catégorie
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Variation
                                </th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Prix HT
                                </th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Prix Promo
                                </th>
                                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Pertinence
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach($products as $product)
                                <tr class="hover:bg-gray-50 transition-colors duration-150">
                                    <!-- Colonne Produit -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-10 w-10">
                                                @if(!empty($product->image_url))
                                                    <img class="h-10 w-10 rounded-lg object-cover" src="{{ $product->image_url }}" alt="{{ $product->name }}" onerror="this.src='https://images.unsplash.com/photo-1556228720-195a672e8a03?w=400&q=80'">
                                                @else
                                                    <div class="h-10 w-10 bg-gray-200 rounded-lg flex items-center justify-center">
                                                        <svg class="h-6 w-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                        </svg>
                                                    </div>
                                                @endif
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900 line-clamp-2">
                                                    {{ $product->name }}
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Colonne Vendeur -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            {{ $product->vendor ?? 'N/A' }}
                                        </div>
                                    </td>

                                    <!-- Colonne Catégorie -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            {{ $product->type ?? 'N/A' }}
                                        </div>
                                    </td>

                                    <!-- Colonne Variation -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        @if(!empty($product->variation))
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                {{ $product->variation }}
                                            </span>
                                        @else
                                            <span class="text-sm text-gray-500">-</span>
                                        @endif
                                    </td>

                                    <!-- Colonne Prix HT -->
                                    <td class="px-6 py-4 whitespace-nowrap text-right">
                                        <div class="text-sm font-semibold text-gray-900">
                                            {{ number_format($product->prix_ht ?? 0, 2, ',', ' ') }} €
                                        </div>
                                    </td>

                                    <!-- Colonne Prix Promo -->
                                    <td class="px-6 py-4 whitespace-nowrap text-right">
                                        @if(!empty($product->discounted_price) && $product->discounted_price < $product->prix_ht)
                                            <div class="text-sm font-semibold text-green-600">
                                                {{ number_format($product->discounted_price, 2, ',', ' ') }} €
                                            </div>
                                            <div class="text-xs text-red-600 mt-1">
                                                Économie: {{ number_format($product->prix_ht - $product->discounted_price, 2, ',', ' ') }} €
                                            </div>
                                        @else
                                            <span class="text-sm text-gray-500">-</span>
                                        @endif
                                    </td>

                                    <!-- Colonne Pertinence -->
                                    <td class="px-6 py-4 whitespace-nowrap text-center">
                                        @if(!empty($product->relevance_score))
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                {{ round($product->relevance_score) }}
                                            </span>
                                        @else
                                            <span class="text-sm text-gray-500">-</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
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