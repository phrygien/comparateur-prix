<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;

new class extends Component {
    public $products = [];
    public $product = null;
    public $hasData = false;
    public $searchTerms = [];
    public $searchVolumes = [];
    public $searchVariationKeywords = [];
    
    public $id;

    public function mount($name, $id)
    {
        dd($this->getCompetitorPrice($name));
        //$this->getOneProductDetails($id);
        
        //$this->getOneProductDetails($id);
    
        $this->id = $id;
    }

    public function getOneProductDetails($entity_id){
        try{

            // Paginated data
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

            return [
                "data" => $result
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

    public function getCompetitorPrice($search)
    {
        try {
            if (empty($search)) {
                $this->products = [];
                $this->hasData = false;
                return null;
            }
            
            // Extraire les volumes et les mots clés de la variation
            $this->extractSearchVolumes($search);
            $this->extractSearchVariationKeywords($search);
            
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
                'search_variation_keywords' => $this->searchVariationKeywords
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
                'product' => $this->getOneProductDetails($this->id),
                'query' => $searchQuery,
                'volumes' => $this->searchVolumes,
                'variation_keywords' => $this->searchVariationKeywords
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
     * Extrait les mots clés de la variation de la recherche
     * Exemple: "Guerlain - Shalimar - Coffret Eau de Parfum 50 ml + 5 ml + 75 ml"
     * Mots clés: ["coffret", "eau", "parfum", "50", "5", "75"]
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
        
        \Log::info('Extracted search variation keywords:', [
            'search' => $search,
            'variation' => $variation,
            'keywords' => $this->searchVariationKeywords
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
 * Met en évidence les volumes correspondants dans un texte
 */
/**
 * Met en évidence les volumes correspondants dans un texte
 */
public function highlightMatchingVolumes($text)
{
    if (empty($text) || empty($this->searchVolumes)) {
        return $text;
    }

    foreach ($this->searchVolumes as $volume) {
        // Recherche le volume suivi de "ml" (avec ou sans espace)
        $pattern = '/\b' . preg_quote($volume, '/') . '\s*ml\b/i';
        
        // Utilise une fonction de callback pour éviter les problèmes d'échappement
        $text = preg_replace_callback($pattern, function($matches) {
            return '<span class="bg-green-100 text-green-800 font-semibold px-1 py-0.5 rounded">' 
                   . htmlspecialchars($matches[0]) 
                   . '</span>';
        }, $text);
    }

    return $text;
}

/**
 * Met en évidence les mots clés de variation correspondants dans un texte
 */
public function highlightMatchingVariationKeywords($text)
{
    if (empty($text) || empty($this->searchVariationKeywords)) {
        return $text;
    }

    foreach ($this->searchVariationKeywords as $keyword) {
        // Recherche le mot clé exact (avec limites de mots)
        $pattern = '/\b' . preg_quote($keyword, '/') . '\b/i';
        
        // Utilise une fonction de callback pour éviter les problèmes d'échappement
        $text = preg_replace_callback($pattern, function($matches) {
            return '<span class="bg-green-100 text-green-800 font-semibold px-1 py-0.5 rounded">' 
                   . htmlspecialchars($matches[0]) 
                   . '</span>';
        }, $text);
    }

    return $text;
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
                continue; // Ignorer les chiffres seuls
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
               . $matches[0] 
               . '</span>';
    }, $text);
    
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
                                <div class="flex items-center">
                                    <span class="text-xs text-blue-700 mr-1">Volumes :</span>
                                    @foreach($searchVolumes as $volume)
                                        <span class="bg-green-100 text-green-800 font-semibold px-2 py-1 rounded text-xs">{{ $volume }} ml</span>
                                    @endforeach
                                </div>
                            @endif
                            @if(!empty($searchVariationKeywords))
                                <div class="flex items-center">
                                    <span class="text-xs text-blue-700 mr-1">Mots clés variation :</span>
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
                                    $hasMatchingVariationKeyword = $this->hasMatchingVariationKeyword($product);
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
                                                    Correspondance parfaite
                                                </span>
                                            </div>
                                        @endif
                                    </td>
                                    
                                    <!-- Colonne Nom -->
                                    <td class="px-6 py-4">
                                        <div class="text-sm font-medium text-gray-900 max-w-xs" title="{{ $product->name ?? 'N/A' }}">
                                            {{-- {!! $this->highlightMatchingVolumes($product->name ?? 'N/A') !!} --}}
                                            {{ $product->name }}
                                        </div>
                                        @if(!empty($product->vendor))
                                            <div class="text-xs text-gray-500 mt-1">
                                                {{ $product->vendor }}
                                            </div>
                                        @endif
                                        <!-- Badges des volumes du produit -->
                                        {{-- @if(!empty($productVolumes))
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
                                        @endif --}}
                                    </td>

                                    <!-- Colonne Variation -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 max-w-xs" title="{{ $product->variation ?? 'Standard' }}">
                                            {{-- {!! $this->highlightMatchingVariationKeywords($product->variation ?? 'Standard') !!} --}}
                                            {!! $this->highlightMatchingTerms($product->variation ?? 'Standard') !!}
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
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                            {{ $product->type ?? 'N/A' }}
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