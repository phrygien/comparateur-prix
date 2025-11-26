<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

new class extends Component {
    public $products = [];
    public $hasData = false;
    public $searchTerms = [];
    public $searchVolumes = [];
    public $searchVariationKeywords = [];

    // one product
    public $id;
    public $name;
    public $oneProduct;

    /**
     * Récupère les détails d'un produit spécifique
     */
    public function getOneProductDetails($entity_id)
    {
        try {
            $dataQuery = "
                SELECT 
                    produit.entity_id as id,
                    produit.sku as sku,
                    product_char.reference as parkode,
                    CAST(product_char.name AS CHAR CHARACTER SET utf8mb4) as title,
                    CAST(product_parent_char.name AS CHAR CHARACTER SET utf8mb4) as parent_title,
                    SUBSTRING_INDEX(product_char.name, ' - ', 1) as vendor,
                    SUBSTRING_INDEX(eas.attribute_set_name, '_', -1) as type,
                    product_char.thumbnail as thumbnail,
                    product_char.swatch_image as swatch_image,
                    product_char.reference as parkode,
                    product_char.reference_us as reference_us,
                    CAST(product_text.description AS CHAR CHARACTER SET utf8mb4) as description,
                    CAST(product_text.short_description AS CHAR CHARACTER SET utf8mb4) as short_description,
                    CAST(product_parent_text.description AS CHAR CHARACTER SET utf8mb4) as parent_description,
                    CAST(product_parent_text.short_description AS CHAR CHARACTER SET utf8mb4) as parent_short_description,
                    CAST(product_text.composition AS CHAR CHARACTER SET utf8mb4) as composition,
                    CAST(product_text.olfactive_families AS CHAR CHARACTER SET utf8mb4) as olfactive_families,
                    CAST(product_text.product_benefit AS CHAR CHARACTER SET utf8mb4) as product_benefit,
                    ROUND(product_decimal.price, 2) as price,
                    ROUND(product_decimal.special_price, 2) as special_price,
                    ROUND(product_decimal.cost, 2) as cost,
                    ROUND(product_decimal.pvc, 2) as pvc,
                    ROUND(product_decimal.prix_achat_ht, 2) as prix_achat_ht,
                    ROUND(product_decimal.prix_us, 2) as prix_us,
                    product_int.status as status,
                    product_int.color as color,
                    product_int.capacity as capacity,
                    product_int.product_type as product_type,
                    product_media.media_gallery as media_gallery,
                    CAST(product_categorie.name AS CHAR CHARACTER SET utf8mb4) as categorie,
                    REPLACE(product_categorie.name, ' > ', ',') as tags,
                    stock_item.qty as quatity,
                    stock_status.stock_status as quatity_status,
                    options.configurable_product_id as configurable_product_id,
                    parent_child_table.parent_id as parent_id,
                    options.attribute_code as option_name,
                    options.attribute_value as option_value
                FROM catalog_product_entity as produit
                LEFT JOIN catalog_product_relation as parent_child_table ON parent_child_table.child_id = produit.entity_id 
                LEFT JOIN catalog_product_super_link as cpsl ON cpsl.product_id = produit.entity_id 
                LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
                LEFT JOIN product_text ON product_text.entity_id = produit.entity_id 
                LEFT JOIN product_decimal ON product_decimal.entity_id = produit.entity_id
                LEFT JOIN product_int ON product_int.entity_id = produit.entity_id
                LEFT JOIN product_media ON product_media.entity_id = produit.entity_id
                LEFT JOIN product_categorie ON product_categorie.entity_id = produit.entity_id 
                LEFT JOIN cataloginventory_stock_item AS stock_item ON stock_item.product_id = produit.entity_id 
                LEFT JOIN cataloginventory_stock_status AS stock_status ON stock_item.product_id = stock_status.product_id 
                LEFT JOIN option_super_attribut AS options ON options.simple_product_id = produit.entity_id 
                LEFT JOIN eav_attribute_set AS eas ON produit.attribute_set_id = eas.attribute_set_id 
                LEFT JOIN catalog_product_entity as produit_parent ON parent_child_table.parent_id = produit_parent.entity_id 
                LEFT JOIN product_char as product_parent_char ON product_parent_char.entity_id = produit_parent.entity_id
                LEFT JOIN product_text as product_parent_text ON product_parent_text.entity_id = produit_parent.entity_id 
                WHERE product_int.status >= 0 AND produit.entity_id = ? 
                ORDER BY product_char.entity_id DESC
            ";

            $result = DB::connection('mysqlMagento')->select($dataQuery, [$entity_id]);

            return !empty($result) ? $result[0] : null;

        } catch (\Throwable $e) {
            \Log::error('Error loading product details:', [
                'entity_id' => $entity_id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return null;
        }
    }

    /**
     * Récupère les prix des concurrents
     */
    public function getCompetitorPrice($search)
    {
        try {
            if (empty($search)) {
                $this->products = [];
                $this->hasData = false;
                return [];
            }
            
            // Extraire les volumes et les mots clés de la variation
            $this->extractSearchVolumes($search);
            $this->extractSearchVariationKeywords($search);
            
            // Préparer les termes de recherche
            $searchQuery = $this->prepareSearchTerms($search);
            
            if (empty($searchQuery)) {
                $this->products = [];
                $this->hasData = false;
                return [];
            }
            
            // Construction de la requête SQL avec paramètres liés
            $sql = "SELECT *, 
                           prix_ht,
                           image_url as image,
                           url as product_url
                    FROM last_price_scraped_product 
                    WHERE MATCH (name, vendor, type, variation) 
                    AGAINST (? IN BOOLEAN MODE)
                    ORDER BY prix_ht ASC
                    LIMIT 50";
            
            \Log::info('Competitor search:', [
                'original_search' => $search,
                'search_query' => $searchQuery,
                'search_volumes' => $this->searchVolumes,
                'search_variation_keywords' => $this->searchVariationKeywords
            ]);
            
            // Exécution de la requête avec binding
            $result = DB::connection('mysql')->select($sql, [$searchQuery]);
            
            $this->hasData = !empty($result);
            
            return $result;
            
        } catch (\Throwable $e) {
            \Log::error('Error loading competitor prices:', [
                'message' => $e->getMessage(),
                'search' => $search ?? null,
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->hasData = false;
            
            return [];
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
            $this->searchVolumes = array_unique($matches[1]);
        }
    }

    /**
     * Extrait les mots clés de la variation de la recherche
     */
    private function extractSearchVariationKeywords(string $search): void
    {
        $this->searchVariationKeywords = [];
        
        // Supprimer la marque et le nom du produit pour isoler la variation
        $pattern = '/^[^-]+\s*-\s*[^-]+\s*-\s*/i';
        $variation = preg_replace($pattern, '', $search);
        
        // Nettoyer les caractères spéciaux et garder lettres, chiffres et espaces
        $variationClean = preg_replace('/[^a-zA-ZÀ-ÿ0-9\s]/', ' ', $variation);
        
        // Normaliser les espaces multiples
        $variationClean = trim(preg_replace('/\s+/', ' ', $variationClean));
        
        // Convertir en minuscules
        $variationClean = mb_strtolower($variationClean);
        
        // Séparer les mots
        $words = explode(" ", $variationClean);
        
        // Stop words à ignorer
        $stopWords = [
            'de', 'le', 'la', 'les', 'un', 'une', 'des', 'du', 'et', 'ou', 'pour', 'avec',
            'the', 'a', 'an', 'and', 'or', 'ml', 'edition', 'édition'
        ];
        
        // Garder les mots significatifs
        foreach ($words as $word) {
            $word = trim($word);
            
            // Garder les mots de plus de 1 caractère, non-stop words, et les chiffres
            if ((strlen($word) > 1 && !in_array($word, $stopWords)) || is_numeric($word)) {
                $this->searchVariationKeywords[] = $word;
            }
        }
    }
    
    /**
     * Prépare les termes de recherche pour le mode BOOLEAN FULLTEXT
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
            
            // Limiter à 3 mots maximum (marque + gamme + type)
            if (count($significantWords) >= 3) {
                break;
            }
        }
        
        // Construire la requête boolean
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
     * Formate la variation pour l'affichage
     */
    public function formatVariation($variation)
    {
        if (empty($variation)) {
            return 'Standard';
        }
        
        // Limiter la longueur pour l'affichage
        return Str::limit($variation, 50);
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
            $volumes = array_unique($matches[1]);
        }
        
        return $volumes;
    }

    /**
     * Vérifie si le produit contient AU MOINS UN volume recherché
     */
    public function hasMatchingVolume($product)
    {
        if (empty($this->searchVolumes)) {
            return false;
        }
        
        $productVolumes = $this->extractVolumesFromText($product->name . ' ' . $product->variation);
        return !empty(array_intersect($this->searchVolumes, $productVolumes));
    }

    /**
     * Vérifie si la variation du produit contient AU MOINS UN mot clé de la variation recherchée
     */
    public function hasMatchingVariationKeyword($product)
    {
        if (empty($this->searchVariationKeywords) || empty($product->variation)) {
            return false;
        }
        
        $productVariationLower = mb_strtolower($product->variation);
        
        foreach ($this->searchVariationKeywords as $keyword) {
            if (str_contains($productVariationLower, $keyword)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Vérifie si le produit correspond parfaitement (volumes ET mots clés de variation)
     */
    public function isPerfectMatch($product)
    {
        $hasMatchingVolume = $this->hasMatchingVolume($product);
        $hasMatchingVariationKeyword = $this->hasMatchingVariationKeyword($product);
        
        // Correspondance parfaite si contient AU MOINS un volume ET AU MOINS un mot clé de variation
        return $hasMatchingVolume && $hasMatchingVariationKeyword;
    }

    /**
     * Met en évidence les volumes et mots clés correspondants dans un texte
     */
    public function highlightMatchingTerms($text)
    {
        if (empty($text)) {
            return $text;
        }

        $patterns = [];
        
        // Ajouter les patterns pour les volumes (priorité aux volumes complets "X ml")
        if (!empty($this->searchVolumes)) {
            foreach ($this->searchVolumes as $volume) {
                $patterns[] = '\b' . preg_quote($volume, '/') . '\s*ml\b';
            }
        }
        
        // Ajouter les patterns pour les mots-clés de variation (sauf les chiffres seuls)
        if (!empty($this->searchVariationKeywords)) {
            foreach ($this->searchVariationKeywords as $keyword) {
                if (empty($keyword) || is_numeric($keyword)) {
                    continue;
                }
                $patterns[] = '\b' . preg_quote(trim($keyword), '/') . '\b';
            }
        }
        
        if (empty($patterns)) {
            return $text;
        }
        
        // Combiner tous les patterns
        $pattern = '/(' . implode('|', $patterns) . ')/iu';
        
        $text = preg_replace_callback($pattern, function($matches) {
            return '<span class="bg-green-100 text-green-800 font-semibold px-1 py-0.5 rounded">' 
                   . htmlspecialchars($matches[0]) 
                   . '</span>';
        }, $text);
        
        return $text;
    }

    /**
     * Calcule la différence de prix en pourcentage
     */
    public function calculatePriceDifference($competitorPrice, $ourPrice)
    {
        if (!is_numeric($competitorPrice) || !is_numeric($ourPrice) || $ourPrice == 0) {
            return null;
        }
        
        $difference = (($competitorPrice - $ourPrice) / $ourPrice) * 100;
        return round($difference, 1);
    }

    /**
     * Détermine la classe CSS selon la différence de prix
     */
    public function getPriceDifferenceClass($difference)
    {
        if ($difference === null) {
            return 'text-gray-500';
        }
        
        if ($difference < -10) {
            return 'text-green-600'; // Beaucoup moins cher
        } elseif ($difference < 0) {
            return 'text-green-500'; // Moins cher
        } elseif ($difference < 10) {
            return 'text-orange-500'; // Légèrement plus cher
        } else {
            return 'text-red-600'; // Beaucoup plus cher
        }
    }

    /**
     * Méthode with() pour passer les données à la vue
     */
    public function with(): array
    {
        // Récupérer les données du produit
        $oneProduct = $this->getOneProductDetails($this->id);
        
        // Récupérer les prix des concurrents
        $products = $this->getCompetitorPrice($this->name);
        
        //dd($products);
        return [
            'oneProduct' => $oneProduct,
            'products' => $products,
            'hasData' => $this->hasData,
            'searchVolumes' => $this->searchVolumes,
            'searchVariationKeywords' => $this->searchVariationKeywords,
        ];
    }
}; 

?>

<div class="mx-auto w-full px-4 py-6 sm:px-6 lg:px-8">
    {{-- Affichage du produit principal --}}
    @if($oneProduct)
        <div class="mb-6 bg-white shadow-sm rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-indigo-50 to-blue-50">
                <h2 class="text-xl font-semibold text-gray-900">Notre Produit</h2>
            </div>
            <div class="p-6">
                <div class="flex items-start gap-6">
                    {{-- Image du produit --}}
                    <div class="flex-shrink-0">
                        @if(!empty($oneProduct->thumbnail))
                            <img src="{{ asset('https://www.cosma-parfumeries.com/media/catalog/product/' . $oneProduct->thumbnail) }}" 
                                 alt="{{ $oneProduct->title ?? 'Product image' }}" 
                                 class="h-32 w-32 object-cover rounded-lg shadow-sm">
                        @else
                            <div class="h-32 w-32 bg-gray-200 rounded-lg flex items-center justify-center">
                                <span class="text-sm text-gray-500">No Image</span>
                            </div>
                        @endif
                    </div>
                    
                    {{-- Détails du produit --}}
                    <div class="flex-1">
                        <div class="flex items-start justify-between">
                            <div>
                                <h3 class="text-lg font-medium text-gray-900">{{ $oneProduct->title }}</h3>
                                <p class="text-sm text-gray-500 mt-1">{{ $oneProduct->vendor }}</p>
                                @if(!empty($oneProduct->option_value))
                                    <p class="text-sm text-gray-600 mt-1">
                                        <span class="font-medium">Variant:</span> {{ $oneProduct->option_value }}
                                    </p>
                                @endif
                            </div>
                            <div class="text-right">
                                @if($oneProduct->special_price && $oneProduct->special_price < $oneProduct->price)
                                    <p class="text-sm text-gray-500 line-through">{{ $this->formatPrice($oneProduct->price) }}</p>
                                    <p class="text-2xl font-bold text-red-600">{{ $this->formatPrice($oneProduct->special_price) }}</p>
                                @else
                                    <p class="text-2xl font-bold text-gray-900">{{ $this->formatPrice($oneProduct->price) }}</p>
                                @endif
                            </div>
                        </div>
                        
                        {{-- Description courte --}}
                        @if(!empty($oneProduct->short_description))
                            <p class="text-sm text-gray-600 mt-3">
                                {{ strip_tags(html_entity_decode($oneProduct->short_description)) }}
                            </p>
                        @endif
                        
                        {{-- Stock --}}
                        <div class="mt-4 flex items-center">
                            @if($oneProduct->quatity_status == 1)
                                <svg class="h-5 w-5 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                </svg>
                                <span class="text-sm text-gray-700">En stock ({{ $oneProduct->quatity }} disponible{{ $oneProduct->quatity > 1 ? 's' : '' }})</span>
                            @else
                                <svg class="h-5 w-5 text-red-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                                </svg>
                                <span class="text-sm text-gray-700">Rupture de stock</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Résultats des concurrents --}}
    @if($hasData && count($products) > 0)
        <!-- Indicateur des critères recherchés -->
        @if(!empty($searchVolumes) || !empty($searchVariationKeywords))
            <div class="mb-4 p-4 bg-blue-50 rounded-lg">
                <div class="flex flex-col space-y-2">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 text-blue-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span class="text-sm font-medium text-blue-800">Critères de correspondance parfaite :</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if(!empty($searchVolumes))
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-blue-700">Volumes :</span>
                                @foreach($searchVolumes as $volume)
                                    <span class="bg-green-100 text-green-800 font-semibold px-2 py-1 rounded text-xs">{{ $volume }} ml</span>
                                @endforeach
                            </div>
                        @endif
                        @if(!empty($searchVariationKeywords))
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-blue-700">Mots clés variation :</span>
                                @foreach($searchVariationKeywords as $keyword)
                                    <span class="bg-green-100 text-green-800 font-semibold px-2 py-1 rounded text-xs">{{ $keyword }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div class="text-xs text-blue-600 mt-1">
                        <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                        Les produits en vert contiennent AU MOINS un volume ET AU MOINS un mot clé de la variation recherchée
                    </div>
                </div>
            </div>
        @endif

        <!-- Tableau des résultats -->
        <div class="bg-white shadow-sm rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Prix de la Concurrence</h3>
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
                            @if($oneProduct)
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Différence</th>
                            @endif
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($products as $product)
                            @php
                                $productVolumes = $this->extractVolumesFromText($product->name . ' ' . ($product->variation ?? ''));
                                $hasMatchingVolume = $this->hasMatchingVolume($product);
                                $hasMatchingVariationKeyword = $this->hasMatchingVariationKeyword($product);
                                $isPerfectMatch = $this->isPerfectMatch($product);
                                
                                // Calcul de la différence de prix
                                $ourPrice = $oneProduct ? ($oneProduct->special_price ?? $oneProduct->price) : null;
                                $competitorPrice = $product->prix_ht ?? $product->price_ht ?? null;
                                $priceDiff = $ourPrice && $competitorPrice ? $this->calculatePriceDifference($competitorPrice, $ourPrice) : null;
                                $diffClass = $this->getPriceDifferenceClass($priceDiff);
                            @endphp
                            <tr class="hover:bg-gray-50 @if($isPerfectMatch) bg-green-50 border-l-4 border-green-500 @endif">
                                <!-- Colonne Image -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if(!empty($product->image) || !empty($product->image_url))
                                        <img src="{{ $product->image ?? $product->image_url }}" 
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
                                    <div class="text-sm font-medium text-gray-900 max-w-xs" title="{{ $this->cleanText($product->name ?? 'N/A') }}">
                                        {!! $this->highlightMatchingTerms($this->cleanText($product->name ?? 'N/A')) !!}
                                    </div>
                                    @if(!empty($product->vendor))
                                        <div class="text-xs text-gray-500 mt-1">
                                            {{ $product->vendor }}
                                        </div>
                                    @endif
                                </td>

                                <!-- Colonne Variation -->
                                <td class="px-6 py-4">
                                    <div class="text-sm text-gray-900 max-w-xs" title="{{ $this->cleanText($product->variation ?? 'Standard') }}">
                                        {!! $this->highlightMatchingTerms($this->cleanText($product->variation ?? 'Standard')) !!}
                                    </div>
                                </td>

                                <!-- Colonne Site Source -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-8 w-8 bg-gray-100 rounded-full flex items-center justify-center mr-3">
                                            <span class="text-xs font-medium text-gray-600">
                                                {{ strtoupper(substr($this->extractDomain($product->product_url ?? $product->url ?? ''), 0, 2)) }}
                                            </span>
                                        </div>
                                        <div>
                                            <div class="text-sm font-medium text-gray-900">
                                                {{ $this->extractDomain($product->product_url ?? $product->url ?? '') }}
                                            </div>
                                            <div class="text-xs text-gray-500 truncate max-w-xs" title="{{ $product->product_url ?? $product->url ?? 'N/A' }}">
                                                {{ Str::limit($product->product_url ?? $product->url ?? 'N/A', 40) }}
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                
                                <!-- Colonne Prix HT -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-semibold text-gray-900">
                                        {{ $this->formatPrice($product->prix_ht ?? $product->price_ht ?? 0) }}
                                    </div>
                                </td>
                                
                                <!-- Colonne Différence de prix -->
                                @if($oneProduct)
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        @if($priceDiff !== null)
                                            <div class="flex items-center">
                                                <span class="text-sm font-medium {{ $diffClass }}">
                                                    {{ $priceDiff > 0 ? '+' : '' }}{{ $priceDiff }}%
                                                </span>
                                                @if($priceDiff < 0)
                                                    <svg class="w-4 h-4 ml-1 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M14.707 10.293a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 111.414-1.414L9 12.586V5a1 1 0 012 0v7.586l2.293-2.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                                    </svg>
                                                @elseif($priceDiff > 0)
                                                    <svg class="w-4 h-4 ml-1 text-red-500" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M5.293 9.707a1 1 0 010-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 01-1.414 1.414L11 7.414V15a1 1 0 11-2 0V7.414L6.707 9.707a1 1 0 01-1.414 0z" clip-rule="evenodd"></path>
                                                    </svg>
                                                @endif
                                            </div>
                                        @else
                                            <span class="text-xs text-gray-400">-</span>
                                        @endif
                                    </td>
                                @endif
                                
                                <!-- Colonne Type -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                        {{ $product->type ?? 'N/A' }}
                                    </span>
                                </td>
                                
                                <!-- Colonne Actions -->
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <div class="flex space-x-2">
                                        @if(!empty($product->product_url) || !empty($product->url))
                                            <a href="{{ $product->product_url ?? $product->url }}" 
                                               target="_blank"
                                               class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-full shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors duration-200">
                                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                                </svg>
                                                Voir
                                            </a>
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
        <div class="text-center py-12 bg-white rounded-lg shadow-sm">
            <svg class="mx-auto h-24 w-24 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <h3 class="mt-4 text-lg font-medium text-gray-900">Aucun résultat trouvé</h3>
            <p class="mt-2 text-sm text-gray-500">
                @if($oneProduct)
                    Aucun produit concurrent trouvé pour : {{ $oneProduct->title }}
                @else
                    Aucun produit ne correspond à la recherche
                @endif
            </p>
        </div>
    @endif
</div>