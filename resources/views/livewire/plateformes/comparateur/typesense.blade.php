<?php

use Livewire\Volt\Component;
use App\Models\Product;
use App\Services\ProductSearchService;
use Illuminate\Support\Collection;

new class extends Component {
    public string $name;
    public string $id;
    public string $price;
    public Collection $products;
    public ?string $extractedVendor = null;
    public ?string $extractedName = null;
    public ?string $extractedType = null;
    public ?string $extractedVariation = null;
    public string $searchStatus = '';
    public array $searchDebug = [];

    public function mount($name, $id, $price): void
    {
        $this->name = $name;
        $this->id = $id;
        $this->price = $price;

        $searchTerm = html_entity_decode($this->name);

        // Extraire les informations avec OpenAI
        $productSearchService = app(ProductSearchService::class);
        $extracted = $productSearchService->extractProductInfo($searchTerm);

        $this->extractedVendor = $extracted['vendor'];
        $this->extractedName = $extracted['name'];
        $this->extractedType = $extracted['type'];
        $this->extractedVariation = $extracted['variation'];

        // Rechercher avec similarité textuelle
        $this->products = $this->searchProductsWithSimilarity($extracted, $searchTerm);
    }

    private function searchProductsWithSimilarity(array $extracted, string $fallbackSearch): Collection
    {
        $this->searchDebug = [];
        $productSearchService = app(ProductSearchService::class);

        // Normaliser les termes de recherche
        $normalizedFallback = $this->normalizeText($fallbackSearch);
        $normalizedVendor = $extracted['vendor'] ? $this->normalizeText($extracted['vendor']) : null;
        $normalizedName = $extracted['name'] ? $this->normalizeText($extracted['name']) : null;
        $normalizedType = $extracted['type'] ? $this->normalizeText($extracted['type']) : null;

        $this->searchDebug['terms'] = [
            'fallback' => $normalizedFallback,
            'vendor' => $normalizedVendor,
            'name' => $normalizedName,
            'type' => $normalizedType
        ];

        // ÉTAPE 1: Recherche initiale plus large
        $searchQuery = $normalizedVendor ?: $normalizedFallback;
        $searchTerms = $this->extractKeywords($searchQuery);

        $query = Product::query()->with('website');

        // Recherche par mots-clés dans plusieurs champs
        foreach ($searchTerms as $term) {
            if (strlen($term) > 2) {
                $query->where(function($q) use ($term) {
                    $q->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($term) . '%'])
                        ->orWhereRaw('LOWER(vendor) LIKE ?', ['%' . strtolower($term) . '%'])
                        ->orWhereRaw('LOWER(type) LIKE ?', ['%' . strtolower($term) . '%'])
                        ->orWhereRaw('LOWER(variation) LIKE ?', ['%' . strtolower($term) . '%']);
                });
            }
        }

        $initialResults = $query->limit(100)->get();
        $this->searchDebug['initial_count'] = $initialResults->count();
        $this->searchDebug['initial_terms'] = $searchTerms;

        if ($initialResults->isEmpty()) {
            $this->searchStatus = 'Aucun résultat avec les termes initiaux';
            return collect();
        }

        // ÉTAPE 2: Calculer les scores de similarité
        $scoredResults = $initialResults->map(function($product) use (
            $normalizedVendor,
            $normalizedName,
            $normalizedType,
            $normalizedFallback,
            $productSearchService
        ) {
            $score = 0;
            $maxScore = 0;
            $details = [];

            // Normaliser les données du produit
            $prodName = $this->normalizeText($product->name);
            $prodVendor = $this->normalizeText($product->vendor);
            $prodType = $this->normalizeText($product->type);
            $prodVariation = $this->normalizeText($product->variation);

            // 1. Similarité avec le vendor (40% du score)
            if ($normalizedVendor) {
                $vendorSimilarity = $this->calculateSimilarity($prodVendor, $normalizedVendor);
                $vendorScore = $vendorSimilarity * 0.4;
                $score += $vendorScore;
                $maxScore += 0.4;
                $details['vendor'] = ['similarity' => $vendorSimilarity, 'score' => $vendorScore];
            }

            // 2. Similarité avec le nom (30% du score)
            if ($normalizedName) {
                $nameSimilarity = $this->calculateSimilarity($prodName, $normalizedName);
                $nameScore = $nameSimilarity * 0.3;
                $score += $nameScore;
                $maxScore += 0.3;
                $details['name'] = ['similarity' => $nameSimilarity, 'score' => $nameScore];
            }

            // 3. Similarité avec le type (20% du score)
            if ($normalizedType) {
                $typeSimilarity = $this->calculateSimilarity($prodType, $normalizedType);
                $typeScore = $typeSimilarity * 0.2;
                $score += $typeScore;
                $maxScore += 0.2;
                $details['type'] = ['similarity' => $typeSimilarity, 'score' => $typeScore];
            }

            // 4. Similarité avec le terme de fallback (10% du score)
            if ($normalizedFallback) {
                $fallbackSimilarity = $this->calculateSimilarity(
                    $prodName . ' ' . $prodVendor . ' ' . $prodType,
                    $normalizedFallback
                );
                $fallbackScore = $fallbackSimilarity * 0.1;
                $score += $fallbackScore;
                $maxScore += 0.1;
                $details['fallback'] = ['similarity' => $fallbackSimilarity, 'score' => $fallbackScore];
            }

            // Calcul du score final (normalisé)
            $finalScore = $maxScore > 0 ? ($score / $maxScore) * 100 : 0;

            return [
                'product' => $product,
                'score' => $finalScore,
                'details' => $details
            ];
        });

        // Filtrer et trier par score
        $filteredResults = $scoredResults
            ->filter(fn($item) => $item['score'] >= 60) // Seuil de 60%
            ->sortByDesc('score')
            ->values();

        $this->searchDebug['filtered_count'] = $filteredResults->count();
        $this->searchDebug['score_range'] = $filteredResults->isNotEmpty() ? [
            'min' => round($filteredResults->min('score'), 2),
            'max' => round($filteredResults->max('score'), 2),
            'avg' => round($filteredResults->avg('score'), 2)
        ] : null;

        // Si pas assez de résultats, baisser le seuil
        if ($filteredResults->isEmpty() && $scoredResults->isNotEmpty()) {
            $filteredResults = $scoredResults
                ->filter(fn($item) => $item['score'] >= 40)
                ->sortByDesc('score')
                ->values();
            $this->searchStatus = 'Seuil abaissé à 40%';
        } elseif ($filteredResults->isNotEmpty()) {
            $this->searchStatus = 'Résultats avec score ≥ 60%';
        }

        return $filteredResults->pluck('product');
    }

    /**
     * Normalise le texte pour la comparaison
     */
    private function normalizeText(string $text): string
    {
        $text = html_entity_decode($text);
        $text = mb_strtolower($text, 'UTF-8');

        // Supprimer les caractères spéciaux mais garder les espaces
        $text = preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', $text);

        // Remplacer les multi-espaces par un seul
        $text = preg_replace('/\s+/', ' ', $text);

        // Supprimer les mots vides courants
        $stopWords = ['le', 'la', 'les', 'de', 'des', 'du', 'et', 'ou', 'avec', 'sans', 'pour', 'sur'];
        $words = explode(' ', $text);
        $words = array_filter($words, function($word) use ($stopWords) {
            return !in_array($word, $stopWords) && strlen($word) > 1;
        });

        return trim(implode(' ', $words));
    }

    /**
     * Calcule la similarité entre deux chaînes (0-100%)
     */
    private function calculateSimilarity(string $str1, string $str2): float
    {
        if (empty($str1) || empty($str2)) {
            return 0;
        }

        // 1. Vérification de correspondance exacte ou partielle
        if (strpos($str1, $str2) !== false || strpos($str2, $str1) !== false) {
            return 100;
        }

        // 2. Calcul de similarité avec similar_text
        similar_text($str1, $str2, $similarityPercent);

        // 3. Calcul de distance de Levenshtein (pour compléter)
        $levenshtein = levenshtein($str1, $str2);
        $maxLen = max(strlen($str1), strlen($str2));
        $levenshteinPercent = $maxLen > 0 ? (1 - $levenshtein / $maxLen) * 100 : 0;

        // 4. Vérifier si les mots-clés sont présents
        $words1 = explode(' ', $str1);
        $words2 = explode(' ', $str2);
        $commonWords = array_intersect($words1, $words2);
        $keywordPercent = count($commonWords) > 0 ?
            (count($commonWords) / max(count($words1), count($words2))) * 100 : 0;

        // Moyenne pondérée des différentes méthodes
        $finalSimilarity = (
            $similarityPercent * 0.4 +
            $levenshteinPercent * 0.3 +
            $keywordPercent * 0.3
        );

        return min(100, max(0, $finalSimilarity));
    }

    /**
     * Extrait les mots-clés d'une phrase
     */
    private function extractKeywords(string $text): array
    {
        $text = $this->normalizeText($text);
        $words = explode(' ', $text);

        // Filtrer les mots trop courts et les doublons
        $keywords = array_filter($words, function($word) {
            return strlen($word) > 2;
        });

        return array_unique($keywords);
    }
}; ?>

<div class="bg-white">
    <livewire:plateformes.detail :id="$id" />

    <div class="mx-auto max-w-7xl overflow-hidden sm:px-6 lg:px-8">
        <h2 class="text-2xl font-bold text-gray-900 px-4 sm:px-0 py-6">
            Résultats pour : {{ $name }}
        </h2>

        <!-- Informations d'extraction et debug -->
        <div class="space-y-4 mb-6 px-4 sm:px-0">
            <!-- Extraction OpenAI -->
            @if($extractedVendor || $extractedName || $extractedType || $extractedVariation)
                <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                    <p class="text-sm font-semibold text-gray-700 mb-2">Extraction OpenAI :</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm">
                        @if($extractedVendor)
                            <div>
                                <span class="text-gray-600">Vendor:</span>
                                <span class="font-medium text-blue-700 ml-2">{{ $extractedVendor }}</span>
                            </div>
                        @endif
                        @if($extractedName)
                            <div>
                                <span class="text-gray-600">Name:</span>
                                <span class="font-medium text-green-700 ml-2">{{ $extractedName }}</span>
                            </div>
                        @endif
                        @if($extractedType)
                            <div>
                                <span class="text-gray-600">Type:</span>
                                <span class="font-medium text-purple-700 ml-2">{{ $extractedType }}</span>
                            </div>
                        @endif
                        @if($extractedVariation)
                            <div>
                                <span class="text-gray-600">Variation:</span>
                                <span class="font-medium text-gray-700 ml-2">{{ $extractedVariation }}</span>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            <!-- Debug de la recherche -->
            @if(!empty($searchDebug))
                <div class="bg-blue-50 p-4 rounded-lg border border-blue-200">
                    <p class="text-sm font-semibold text-blue-700 mb-2">Debug recherche ({{ $searchStatus }}) :</p>
                    <div class="text-xs space-y-1">
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <span class="text-gray-600">Résultats initiaux:</span>
                                <span class="font-medium">{{ $searchDebug['initial_count'] ?? 0 }}</span>
                            </div>
                            <div>
                                <span class="text-gray-600">Résultats filtrés:</span>
                                <span class="font-medium">{{ $searchDebug['filtered_count'] ?? 0 }}</span>
                            </div>
                        </div>
                        @if(!empty($searchDebug['score_range']))
                            <div class="grid grid-cols-3 gap-2 mt-2">
                                <div class="bg-white p-2 rounded border">
                                    <span class="text-gray-600 block">Score min</span>
                                    <span class="font-bold text-red-600">{{ $searchDebug['score_range']['min'] }}%</span>
                                </div>
                                <div class="bg-white p-2 rounded border">
                                    <span class="text-gray-600 block">Score max</span>
                                    <span class="font-bold text-green-600">{{ $searchDebug['score_range']['max'] }}%</span>
                                </div>
                                <div class="bg-white p-2 rounded border">
                                    <span class="text-gray-600 block">Score moyen</span>
                                    <span class="font-bold text-blue-600">{{ $searchDebug['score_range']['avg'] }}%</span>
                                </div>
                            </div>
                        @endif
                        @if(!empty($searchDebug['terms']))
                            <div class="mt-2 pt-2 border-t border-blue-100">
                                <span class="text-gray-600">Termes normalisés:</span>
                                <div class="mt-1 text-xs">
                                    @foreach($searchDebug['terms'] as $key => $value)
                                        @if($value)
                                            <span class="inline-block bg-white px-2 py-1 rounded border mr-2 mb-1">
                                                <span class="text-gray-500">{{ $key }}:</span>
                                                <span class="font-medium">{{ $value }}</span>
                                            </span>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        @if($products->count() > 0)
            <div class="mb-6 px-4 sm:px-0">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-gray-600">
                        {{ $products->count() }} {{ $products->count() > 1 ? 'produits correspondants trouvés' : 'produit correspondant trouvé' }}
                    </p>
                    <span class="inline-flex items-center rounded-full bg-green-100 px-3 py-1 text-xs font-medium text-green-800">
                        {{ $searchStatus }}
                    </span>
                </div>
            </div>

            <div class="-mx-px grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                @foreach($products as $product)
                    <div class="group relative border border-gray-200 rounded-lg p-4 hover:border-blue-300 hover:shadow-md transition-all">
                        <div class="aspect-square rounded-lg bg-gray-100 overflow-hidden mb-4">
                            <img
                                src="{{ $product->image_url }}"
                                alt="{{ $product->vendor }} - {{ $product->name }}"
                                class="h-full w-full object-cover group-hover:scale-105 transition-transform duration-300"
                                onerror="this.src='https://via.placeholder.com/400x400?text=Image+Non+Disponible'"
                            >
                        </div>

                        <div class="space-y-3">
                            @if($product->website)
                                <div class="flex items-center">
                                    <span class="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800">
                                        {{ $product->website->name }}
                                    </span>
                                    @if($product->created_at)
                                        <span class="ml-auto text-xs text-gray-400">
                                            {{ $product->created_at->diffForHumans() }}
                                        </span>
                                    @endif
                                </div>
                            @endif

                            <h3 class="text-sm font-semibold text-gray-900 line-clamp-2 h-10">
                                <a href="{{ $product->url }}" target="_blank" class="hover:text-blue-600">
                                    {{ $product->vendor }} - {{ $product->name }}
                                </a>
                            </h3>

                            <div class="space-y-1">
                                @if($product->type)
                                    <p class="text-xs text-gray-600">
                                        <span class="text-gray-500">Type:</span>
                                        <span class="font-medium ml-1">{{ $product->type }}</span>
                                    </p>
                                @endif
                                @if($product->variation)
                                    <p class="text-xs text-gray-600">
                                        <span class="text-gray-500">Variante:</span>
                                        <span class="font-medium ml-1">{{ $product->variation }}</span>
                                    </p>
                                @endif
                                @if($product->scrap_reference_id)
                                    <p class="text-xs text-gray-500">
                                        <span class="text-gray-400">Réf:</span>
                                        {{ $product->scrap_reference_id }}
                                    </p>
                                @endif
                            </div>

                            <div class="flex items-center justify-between pt-2 border-t border-gray-100">
                                <p class="text-lg font-bold text-gray-900">
                                    {{ number_format($product->prix_ht, 2, ',', ' ') }} {{ $product->currency }}
                                </p>
                                <a href="{{ $product->url }}" target="_blank"
                                   class="inline-flex items-center px-3 py-1.5 text-xs font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition-colors">
                                    Voir le produit
                                </a>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="text-center py-16">
                <div class="mx-auto h-24 w-24 text-gray-400 mb-4">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <h3 class="text-lg font-semibold text-gray-900">Aucun produit correspondant</h3>
                <p class="mt-2 text-sm text-gray-600 max-w-md mx-auto">
                    Nous n'avons pas trouvé de produits correspondant à "{{ $name }}".
                    Essayez avec des termes plus génériques ou vérifiez l'orthographe.
                </p>
                @if(!empty($searchDebug['initial_count']) && $searchDebug['initial_count'] > 0)
                    <div class="mt-4 p-3 bg-yellow-50 rounded-lg border border-yellow-200 max-w-md mx-auto">
                        <p class="text-sm text-yellow-800">
                            <span class="font-medium">{{ $searchDebug['initial_count'] }} produits</span>
                            ont été trouvés initialement mais aucun n'a atteint le seuil de similarité requis.
                        </p>
                    </div>
                @endif
            </div>
        @endif
    </div>
</div>
