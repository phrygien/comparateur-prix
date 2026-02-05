<?php

use Livewire\Volt\Component;
use App\Models\Product;
use Illuminate\Support\Collection;

new class extends Component {
    public string $name;
    public string $id;
    public string $price;
    public Collection $products;
    public int $minSimilarityScore = 30; // Seuil minimum de similarité (ajustable)

    public function mount($name, $id, $price): void
    {
        $this->name = $name;
        $this->id = $id;
        $this->price = $price;

        $searchTerm = html_entity_decode($this->name);
        
        // Récupérer et filtrer les produits par similarité
        $this->products = Product::search($searchTerm)
            ->query(fn($query) => $query->with('website')->orderByDesc('created_at'))
            ->get()
            ->map(function ($product) use ($searchTerm) {
                // Combiner vendor - name - type - variation
                $combinedText = collect([
                    $product->vendor,
                    $product->name,
                    $product->type,
                    $product->variation
                ])->filter()->implode(' - ');
                
                $product->combined_text = $combinedText;
                
                // Calculer le score de similarité
                $product->similarity_score = $this->calculateSimilarity(
                    strtolower($searchTerm),
                    strtolower($combinedText)
                );
                
                return $product;
            })
            ->filter(function ($product) {
                // Ne garder que les produits au-dessus du seuil minimum
                return $product->similarity_score >= $this->minSimilarityScore;
            })
            ->sortByDesc('similarity_score') // Trier par pertinence
            ->values(); // Réindexer la collection
    }

    /**
     * Calcule la similarité entre le texte de recherche et le texte combiné du produit
     */
    private function calculateSimilarity(string $search, string $text): float
    {
        $search = trim($search);
        $text = trim($text);
        
        // Score de base
        $score = 0;
        
        // 1. Correspondance exacte = 100%
        if ($search === $text) {
            return 100;
        }
        
        // 2. Le texte contient la recherche complète = 85%
        if (str_contains($text, $search)) {
            $score = max($score, 85);
        }
        
        // 3. Similarité textuelle générale
        similar_text($search, $text, $percent);
        $score = max($score, $percent);
        
        // 4. Analyse par mots
        $searchWords = array_unique(array_filter(
            preg_split('/[\s\-_,\.]+/', $search, -1, PREG_SPLIT_NO_EMPTY)
        ));
        $textWords = array_unique(array_filter(
            preg_split('/[\s\-_,\.]+/', $text, -1, PREG_SPLIT_NO_EMPTY)
        ));
        
        if (count($searchWords) > 0) {
            $commonWords = count(array_intersect($searchWords, $textWords));
            $wordMatchPercent = ($commonWords / count($searchWords)) * 100;
            $score = max($score, $wordMatchPercent);
            
            // Bonus si tous les mots de recherche sont présents
            if ($commonWords === count($searchWords)) {
                $score += 15;
            }
        }
        
        // 5. Bonus si la recherche est au début du texte
        if (str_starts_with($text, $search)) {
            $score += 10;
        }
        
        // 6. Vérifier la distance de Levenshtein pour les petites chaînes
        if (strlen($search) < 50 && strlen($text) < 100) {
            $distance = levenshtein(
                substr($search, 0, 255), 
                substr($text, 0, 255)
            );
            $maxLength = max(strlen($search), strlen($text));
            if ($maxLength > 0) {
                $levenshteinPercent = (1 - ($distance / $maxLength)) * 100;
                $score = max($score, $levenshteinPercent);
            }
        }
        
        return min(100, $score);
    }

}; ?>

<div class="bg-white">

    <livewire:plateformes.detail :id="$id" />

    <div class="mx-auto max-w-7xl overflow-hidden sm:px-6 lg:px-8">
        <h2 class="text-2xl font-bold text-gray-900 px-4 sm:px-0 py-6">
            Résultats pour : {{ $name }}
        </h2>

        @if($products->count() > 0)
            <div class="mb-4 px-4 sm:px-0 flex justify-between items-center">
                <p class="text-sm text-gray-600">
                    {{ $products->count() }} {{ $products->count() > 1 ? 'produits trouvés' : 'produit trouvé' }}
                </p>
                <p class="text-xs text-gray-500">
                    Triés par pertinence
                </p>
            </div>

            <!-- Grille de tous les produits -->
            <div class="-mx-px grid grid-cols-2 border-l border-gray-200 sm:mx-0 md:grid-cols-3 lg:grid-cols-4">
                @foreach($products as $product)
                    <div class="group relative border-r border-b border-gray-200 p-4 sm:p-6">
                        <div class="aspect-square rounded-lg bg-gray-200 overflow-hidden">
                            <img
                                src="{{ $product->image_url }}"
                                alt="{{ $product->combined_text }}"
                                class="h-full w-full object-cover group-hover:opacity-75"
                            >
                        </div>
                        <div class="pt-10 pb-4 text-center">
                            <!-- Badge du site -->
                            @if($product->website)
                                <div class="mb-2">
                                    <span class="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800">
                                        {{ $product->website->name }}
                                    </span>
                                </div>
                            @endif

                            <!-- Score de pertinence (optionnel, peut être caché en production) -->
                            @if(config('app.debug'))
                                <div class="mb-2">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                                        {{ $product->similarity_score >= 80 ? 'bg-green-100 text-green-800' : 
                                           ($product->similarity_score >= 50 ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800') }}">
                                        {{ number_format($product->similarity_score, 0) }}% pertinent
                                    </span>
                                </div>
                            @endif

                            <h3 class="text-sm font-medium text-gray-900">
                                <a href="{{ $product->url }}" target="_blank">
                                    <span aria-hidden="true" class="absolute inset-0"></span>
                                    {{ $product->combined_text }}
                                </a>
                            </h3>
                            
                            <div class="mt-3 flex flex-col items-center">
                                <!-- Détails individuels (optionnel) -->
                                <div class="text-xs text-gray-500 space-y-1">
                                    @if($product->vendor && $product->vendor !== $product->name)
                                        <p><span class="font-medium">Vendeur:</span> {{ $product->vendor }}</p>
                                    @endif
                                    @if($product->type)
                                        <p><span class="font-medium">Type:</span> {{ $product->type }}</p>
                                    @endif
                                    @if($product->variation)
                                        <p><span class="font-medium">Variation:</span> {{ $product->variation }}</p>
                                    @endif
                                </div>
                                
                                @if($product->scrap_reference_id)
                                    <p class="mt-2 text-xs text-gray-400">Réf: {{ $product->scrap_reference_id }}</p>
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
        @else
            <div class="text-center py-12">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <h3 class="mt-2 text-sm font-semibold text-gray-900">Aucun produit pertinent trouvé</h3>
                <p class="mt-1 text-sm text-gray-500">Aucun résultat pertinent pour "{{ $name }}"</p>
                <p class="mt-1 text-xs text-gray-400">Essayez avec des termes de recherche différents</p>
            </div>
        @endif
    </div>
</div>