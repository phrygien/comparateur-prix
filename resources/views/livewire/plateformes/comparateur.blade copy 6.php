<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;

new class extends Component {
    public $products = [];
    public $hasData = false;
    public $searchTerms = [];
    public $searchVolumes = [];
    public $searchType = '';
    
    public function mount($name)
    {
        $this->getCompetitorPrice($name);
    }

    public function getCompetitorPrice($search)
    {
        try {
            if (empty($search)) {
                $this->products = [];
                $this->hasData = false;
                return null;
            }
            
            // Extraire les volumes et le type de la recherche
            $this->extractSearchVolumes($search);
            $this->extractSearchType($search);
            
            // Préparer les termes de recherche
            $searchQuery = $this->prepareSearchTerms($search);
            
            if (empty($searchQuery)) {
                $this->products = [];
                $this->hasData = false;
                return null;
            }
            
            // Construction de la requête SQL avec paramètres liés
            $sql = "SELECT *, 
                           prix_ht,
                           image_url as image,
                           url as product_url
                    FROM last_price_scraped_product 
                    WHERE MATCH (name, vendor, type, variation) 
                    AGAINST (? IN BOOLEAN MODE)
                    ORDER BY prix_ht DESC";
            
            \Log::info('SQL Query:', [
                'original_search' => $search,
                'search_query' => $searchQuery,
                'search_volumes' => $this->searchVolumes,
                'search_type' => $this->searchType
            ]);
            
            // Exécution de la requête avec binding
            $result = DB::connection('mysql')->select($sql, [$searchQuery]);
            
            \Log::info('Query result:', [
                'count' => count($result)
            ]);
            
            $this->products = $result;
            $this->hasData = !empty($result);
            
            return [
                'count' => count($result),
                'has_data' => $this->hasData,
                'products' => $this->products,
                'query' => $searchQuery,
                'volumes' => $this->searchVolumes,
                'type' => $this->searchType
            ];
            
        } catch (\Throwable $e) {
            \Log::error('Error loading products:', [
                'message' => $e->getMessage(),
                'search' => $search ?? null,
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->products = [];
            $this->hasData = false;
            
            return [
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Extrait les volumes (ml) de la recherche
     */
    private function extractSearchVolumes(string $search): void
    {
        $this->searchVolumes = [];
        
        // Recherche de motifs comme "50 ml", "75ml", "100 ml", etc.
        if (preg_match_all('/(\d+)\s*ml/i', $search, $matches)) {
            $this->searchVolumes = $matches[1];
        }
        
        \Log::info('Extracted search volumes:', [
            'search' => $search,
            'volumes' => $this->searchVolumes
        ]);
    }

    /**
     * Extrait le type de parfum de la recherche
     */
    private function extractSearchType(string $search): void
    {
        $this->searchType = '';
        
        // Recherche des types de parfum courants
        $types = [
            'eau de parfum' => '/eau\s*de\s*parfum/i',
            'eau de toilette' => '/eau\s*de\s*toilette/i',
            'parfum' => '/\bparfum\b/i',
            'extrait' => '/\bextrait\b/i',
            'coffret' => '/\bcoffret\b/i',
            'set' => '/\bset\b/i'
        ];
        
        foreach ($types as $type => $pattern) {
            if (preg_match($pattern, $search)) {
                $this->searchType = $type;
                break;
            }
        }
        
        \Log::info('Extracted search type:', [
            'search' => $search,
            'type' => $this->searchType
        ]);
    }
    
    /**
     * Prépare les termes de recherche pour le mode BOOLEAN FULLTEXT
     * Extrait uniquement les 3 premiers mots significatifs (marque, gamme, type)
     * 
     * Format: +mot1* +mot2* +mot3*
     * 
     * Exemple: "Guerlain - Shalimar - Coffret Eau de Parfum 50 ml + 5 ml + 75 ml (Édition"
     * Résultat: "+guerlain* +shalimar* +coffret*"
     * 
     * @param string $search
     * @return string
     */
    private function prepareSearchTerms(string $search): string
    {
        // Nettoyage agressif : supprimer tous les caractères spéciaux et chiffres
        $searchClean = preg_replace('/[^a-zA-ZÀ-ÿ\s]/', ' ', $search);
        
        // Normaliser les espaces multiples
        $searchClean = trim(preg_replace('/\s+/', ' ', $searchClean));
        
        // Convertir en minuscules
        $searchClean = mb_strtolower($searchClean);
        
        // Séparer les mots
        $words = explode(" ", $searchClean);
        
        // Stop words français et anglais à ignorer
        $stopWords = [
            'de', 'le', 'la', 'les', 'un', 'une', 'des', 'du', 'et', 'ou', 'pour', 'avec',
            'the', 'a', 'an', 'and', 'or', 'eau', 'ml', 'edition', 'édition', 'coffret'
        ];
        
        // Mots significatifs seulement (marque, gamme, produit)
        $significantWords = [];
        
        foreach ($words as $word) {
            $word = trim($word);
            
            // Garder uniquement les mots de plus de 2 caractères, non-stop words
            if (strlen($word) > 2 && !in_array($word, $stopWords)) {
                $significantWords[] = $word;
            }
            
            // Limiter à 3 mots maximum (marque + gamme + type) SEULEMENT
            if (count($significantWords) >= 3) {
                break;
            }
        }
        
        // Construire la requête boolean avec seulement 3 termes
        $booleanTerms = array_map(function($word) {
            return '+' . $word . '*';
        }, $significantWords);
        
        return implode(' ', $booleanTerms);
    }

    /**
     * Formate le prix pour l'affichage
     */
    public function formatPrice($price)
    {
        if (is_numeric($price)) {
            return number_format($price, 2, ',', ' ') . ' €';
        }
        return 'N/A';
    }

    /**
     * Extrait le domaine d'une URL
     */
    public function extractDomain($url)
    {
        if (empty($url)) {
            return 'N/A';
        }
        
        try {
            $parsedUrl = parse_url($url);
            if (isset($parsedUrl['host'])) {
                $domain = $parsedUrl['host'];
                // Retirer www. si présent
                if (strpos($domain, 'www.') === 0) {
                    $domain = substr($domain, 4);
                }
                return $domain;
            }
        } catch (\Exception $e) {
            \Log::error('Error extracting domain:', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
        }
        
        return 'N/A';
    }

    /**
     * Ouvre la page du produit
     */
    public function viewProduct($productUrl)
    {
        if ($productUrl) {
            return redirect()->away($productUrl);
        }
    }

    /**
     * Formate la variation pour l'affichage
     */
    public function formatVariation($variation)
    {
        if (empty($variation)) {
            return 'Standard';
        }
        
        // Limiter la longueur pour l'affichage
        return Str::limit($variation, 30);
    }

    /**
     * Extrait les volumes d'un texte (nom ou variation)
     */
    public function extractVolumesFromText($text)
    {
        if (empty($text)) {
            return [];
        }
        
        $volumes = [];
        if (preg_match_all('/(\d+)\s*ml/i', $text, $matches)) {
            $volumes = $matches[1];
        }
        
        return $volumes;
    }

    /**
     * Vérifie si un volume correspond aux volumes recherchés
     */
    public function isVolumeMatching($volume)
    {
        return in_array($volume, $this->searchVolumes);
    }

    /**
     * Vérifie si le type correspond au type recherché
     */
    public function isTypeMatching($type)
    {
        if (empty($this->searchType) || empty($type)) {
            return false;
        }
        
        $typeLower = mb_strtolower($type);
        $searchTypeLower = mb_strtolower($this->searchType);
        
        return str_contains($typeLower, $searchTypeLower) || str_contains($searchTypeLower, $typeLower);
    }

    /**
     * Vérifie si le produit contient AU MOINS UN volume recherché
     */
    public function hasMatchingVolume($product)
    {
        $productVolumes = $this->extractVolumesFromText($product->name . ' ' . $product->variation);
        return !empty(array_intersect($this->searchVolumes, $productVolumes));
    }

    /**
     * Vérifie si le produit correspond parfaitement (volumes ET type)
     */
    public function isPerfectMatch($product)
    {
        return $this->hasMatchingVolume($product) && $this->isTypeMatching($product->type);
    }

    /**
     * Met en évidence les volumes correspondants dans un texte
     */
    public function highlightMatchingVolumes($text)
    {
        if (empty($text) || empty($this->searchVolumes)) {
            return $text;
        }

        foreach ($this->searchVolumes as $volume) {
            $pattern = '/\b' . preg_quote($volume, '/') . '\s*ml\b/i';
            $replacement = '<span class="bg-green-100 text-green-800 font-semibold px-1 py-0.5 rounded">' . $volume . ' ml</span>';
            $text = preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }

    /**
     * Met en évidence le type correspondant dans un texte
     */
    public function highlightMatchingType($text)
    {
        if (empty($text) || empty($this->searchType)) {
            return $text;
        }

        $pattern = '/\b' . preg_quote($this->searchType, '/') . '\b/i';
        $replacement = '<span class="bg-green-100 text-green-800 font-semibold px-1 py-0.5 rounded">$0</span>';
        $text = preg_replace($pattern, $replacement, $text);

        return $text;
    }
}; ?>

<div>
<div class="w-full px-4 py-2 sm:px-2 sm:py-4 lg:grid lg:grid-cols-2 lg:gap-x-8 lg:px-8">
    <!-- Product image -->
    <div class="mt-10 lg:col-start-1 lg:row-span-2 lg:mt-0 lg:self-center">
        <img src="https://images.unsplash.com/photo-1556228720-195a672e8a03?w=800&q=80" alt="Model wearing light green backpack with black canvas straps and front zipper pouch." class="aspect-square w-full rounded-lg object-cover">
    </div>

    <!-- Product details -->
    <div class="lg:max-w-lg lg:self-end lg:col-start-2">
        <nav aria-label="Breadcrumb">
            <ol role="list" class="flex items-center space-x-2">
                <li>
                    <div class="flex items-center text-sm">
                        <a href="#" class="font-medium text-gray-500 hover:text-gray-900">CHANEL</a>
                        <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" class="ml-2 size-5 shrink-0 text-gray-300">
                            <path d="M5.555 17.776l8-16 .894.448-8 16-.894-.448z" />
                        </svg>
                    </div>
                </li>
                <li>
                    <div class="flex items-center text-sm">
                        <a href="#" class="font-medium text-gray-500 hover:text-gray-900">Bags</a>
                    </div>
                </li>
            </ol>
        </nav>

        <div class="mt-4">
            <h1 class="text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">Crème Visage Premium</h1>
        </div>

        <section aria-labelledby="information-heading" class="mt-4">
            <h2 id="information-heading" class="sr-only">Product information</h2>

            <div class="mt-4 space-y-6">
                <p class="text-base text-gray-500">Don&#039;t compromise on snack-carrying capacity with this lightweight and spacious bag. The drawstring top keeps all your favorite chips, crisps, fries, biscuits, crackers, and cookies secure.</p>
            </div>

            <div class="mt-6 flex items-center">
                <svg class="size-5 shrink-0 text-green-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" data-slot="icon">
                    <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" />
                </svg>
                <p class="ml-2 text-sm text-gray-500">In stock and ready to ship</p>
            </div>
        </section>
    </div>

    <!-- Product form -->
    <div class="mt-10 lg:col-start-2 lg:row-start-2 lg:max-w-lg lg:self-start">
        <section aria-labelledby="options-heading">
            <h2 id="options-heading" class="sr-only">Product options</h2>

            <form>
                <div class="sm:flex sm:justify-between">
                    <!-- Size selector -->
                    <fieldset>
                        <legend class="block text-sm font-medium text-gray-700">Variant (s)</legend>
                        <div class="mt-1 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <!-- Active: "ring-2 ring-indigo-500" -->
                            <div aria-label="18L" aria-description="Perfect for a reasonable amount of snacks." class="relative block cursor-pointer rounded-lg border border-gray-300 p-4 focus:outline-hidden">
                                <input type="radio" name="size-choice" value="18L" class="sr-only">
                                <div class="flex justify-between items-start">
                                    <p class="text-base font-medium text-gray-900">18 ML</p>
                                    <p class="text-base font-semibold text-gray-900">$65</p>
                                </div>
                                <p class="mt-1 text-sm text-gray-500">Perfect for a reasonable amount of snacks.</p>
                                <div class="pointer-events-none absolute -inset-px rounded-lg border-2" aria-hidden="true"></div>
                            </div>
                            
                            <!-- Active: "ring-2 ring-indigo-500" -->
                            <div aria-label="20L" aria-description="Enough room for a serious amount of snacks." class="relative block cursor-pointer rounded-lg border border-gray-300 p-4 focus:outline-hidden">
                                <input type="radio" name="size-choice" value="20L" class="sr-only">
                                <div class="flex justify-between items-start">
                                    <p class="text-base font-medium text-gray-900">20 ML</p>
                                    <p class="text-base font-semibold text-gray-900">$85</p>
                                </div>
                                <p class="mt-1 text-sm text-gray-500">Enough room for a serious amount of snacks.</p>
                                <div class="pointer-events-none absolute -inset-px rounded-lg border-2" aria-hidden="true"></div>
                            </div>
                        </div>
                    </fieldset>
                </div>
                <div class="mt-4">
                    <a href="#" class="group inline-flex text-sm text-gray-500 hover:text-gray-700">
                        <span>Nos produits</span>
                        <svg class="ml-2 size-5 shrink-0 text-gray-400 group-hover:text-gray-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" data-slot="icon">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0ZM8.94 6.94a.75.75 0 1 1-1.061-1.061 3 3 0 1 1 2.871 5.026v.345a.75.75 0 0 1-1.5 0v-.5c0-.72.57-1.172 1.081-1.287A1.5 1.5 0 1 0 8.94 6.94ZM10 15a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd" />
                        </svg>
                    </a>
                </div>
            </form>
        </section>
    </div>
</div>

    <!-- Section des résultats -->
    <div class="mx-auto w-full px-4 py-6 sm:px-6 lg:px-8">
        @if($hasData)
            <!-- Indicateur des critères recherchés -->
            @if(!empty($searchVolumes) || !empty($searchType))
                <div class="mb-4 p-4 bg-blue-50 rounded-lg">
                    <div class="flex flex-col space-y-2">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 text-blue-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <span class="text-sm font-medium text-blue-800">Critères recherchés :</span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if(!empty($searchVolumes))
                                <div class="flex items-center">
                                    <span class="text-xs text-blue-700 mr-1">Volumes :</span>
                                    @foreach($searchVolumes as $volume)
                                        <span class="bg-green-100 text-green-800 font-semibold px-2 py-1 rounded text-xs">{{ $volume }} ml</span>
                                    @endforeach
                                </div>
                            @endif
                            @if(!empty($searchType))
                                <div class="flex items-center">
                                    <span class="text-xs text-blue-700 mr-1">Type :</span>
                                    <span class="bg-green-100 text-green-800 font-semibold px-2 py-1 rounded text-xs">{{ ucfirst($searchType) }}</span>
                                </div>
                            @endif
                        </div>
                        <div class="text-xs text-blue-600 mt-1">
                            <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            Les produits en vert correspondent aux volumes ET au type recherchés
                        </div>
                    </div>
                </div>
            @endif

            <!-- Tableau des résultats -->
            <div class="bg-white shadow-sm rounded-lg overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Résultats de la recherche</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ count($products) }} produit(s) trouvé(s)</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Image</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nom</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Variation</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Site Source</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Prix HT</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach($products as $product)
                                @php
                                    $productVolumes = $this->extractVolumesFromText($product->name . ' ' . $product->variation);
                                    $hasMatchingVolume = $this->hasMatchingVolume($product);
                                    $hasMatchingType = $this->isTypeMatching($product->type);
                                    $isPerfectMatch = $this->isPerfectMatch($product);
                                @endphp
                                <tr class="hover:bg-gray-50 @if($isPerfectMatch) bg-green-50 border-l-4 border-green-500 @endif">
                                    <!-- Colonne Image -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        @if(!empty($product->image))
                                            <img src="{{ $product->image }}" 
                                                 alt="{{ $product->name ?? 'Produit' }}" 
                                                 class="h-12 w-12 object-cover rounded-lg"
                                                 onerror="this.src='https://via.placeholder.com/48?text=No+Image'">
                                        @else
                                            <div class="h-12 w-12 bg-gray-200 rounded-lg flex items-center justify-center">
                                                <span class="text-xs text-gray-500">No Image</span>
                                            </div>
                                        @endif
                                        @if($isPerfectMatch)
                                            <div class="mt-1 text-center">
                                                <span class="inline-flex items-center px-2 py-1 bg-green-100 text-green-800 text-xs font-medium rounded-full">
                                                    <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                                    </svg>
                                                    Match
                                                </span>
                                            </div>
                                        @endif
                                    </td>
                                    
                                    <!-- Colonne Nom -->
                                    <td class="px-6 py-4">
                                        <div class="text-sm font-medium text-gray-900 max-w-xs" title="{{ $product->name ?? 'N/A' }}">
                                            {!! $this->highlightMatchingVolumes($product->name ?? 'N/A') !!}
                                        </div>
                                        @if(!empty($product->vendor))
                                            <div class="text-xs text-gray-500 mt-1">
                                                {{ $product->vendor }}
                                            </div>
                                        @endif
                                        <!-- Badges des volumes du produit -->
                                        @if(!empty($productVolumes))
                                            <div class="mt-2 flex flex-wrap gap-1">
                                                @foreach($productVolumes as $volume)
                                                    <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium 
                                                        @if($this->isVolumeMatching($volume))
                                                            bg-green-100 text-green-800 border border-green-300
                                                        @else
                                                            bg-gray-100 text-gray-800
                                                        @endif">
                                                        {{ $volume }} ml
                                                        @if($this->isVolumeMatching($volume))
                                                            <svg class="w-3 h-3 ml-1 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                                            </svg>
                                                        @endif
                                                    </span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>

                                    <!-- Colonne Variation -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 max-w-xs" title="{{ $product->variation ?? 'Standard' }}">
                                            {!! $this->highlightMatchingVolumes($product->variation ?? 'Standard') !!}
                                        </div>
                                    </td>

                                    <!-- Colonne Site Source -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-8 w-8 bg-gray-100 rounded-full flex items-center justify-center mr-3">
                                                <span class="text-xs font-medium text-gray-600">
                                                    {{ strtoupper(substr($this->extractDomain($product->product_url), 0, 2)) }}
                                                </span>
                                            </div>
                                            <div>
                                                <div class="text-sm font-medium text-gray-900">
                                                    {{ $this->extractDomain($product->product_url) }}
                                                </div>
                                                <div class="text-xs text-gray-500 truncate max-w-xs" title="{{ $product->product_url ?? 'N/A' }}">
                                                    {{ Str::limit($product->product_url, 40) }}
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    
                                    <!-- Colonne Prix HT -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-semibold text-green-600">
                                            {{ $this->formatPrice($product->price_ht ?? $product->prix_ht) }}
                                        </div>
                                    </td>
                                    
                                    <!-- Colonne Type -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                            @if($hasMatchingType) bg-green-100 text-green-800 border border-green-300
                                            @else bg-purple-100 text-purple-800 @endif">
                                            {!! $this->highlightMatchingType($product->type ?? 'N/A') !!}
                                            @if($hasMatchingType)
                                                <svg class="w-3 h-3 ml-1 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                                </svg>
                                            @endif
                                        </span>
                                    </td>
                                    
                                    <!-- Colonne Actions -->
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            @if(!empty($product->product_url))
                                                <button wire:click="viewProduct('{{ $product->product_url }}')" 
                                                        class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-full shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors duration-200">
                                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                                    </svg>
                                                    Voir
                                                </button>
                                            @else
                                                <span class="inline-flex items-center px-2 py-1 text-xs text-gray-400 bg-gray-100 rounded-full">
                                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                                    </svg>
                                                    Indisponible
                                                </span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @else
            <!-- Empty State -->
            <div class="text-center py-12">
                <svg class="mx-auto h-24 w-24 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <h3 class="mt-4 text-lg font-medium text-gray-900">Aucun résultat trouvé</h3>
                <p class="mt-2 text-sm text-gray-500">Aucun produit ne correspond à la recherche : {{ $search ?? 'N/A' }}</p>
            </div>
        @endif
    </div>
</div>