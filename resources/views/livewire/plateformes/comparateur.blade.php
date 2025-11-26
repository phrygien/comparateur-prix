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
    public $mydata;

    public function mount($name, $id)
    {
        $this->getCompetitorPrice($name);
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

            return $result;

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

            // one product
            
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
     * Amélioration du morcelage avec gestion des marques, produits et variations
     * 
     * Format: +mot1* +mot2* +mot3* +"phrase exacte"*
     * 
     * Exemple: "Burberry - Burberry pour Femme - Eau de Parfum Vaporisateur 100 ml"
     * Résultat: +burberry* +"burberry pour femme"* +"eau de parfum"* +vaporisateur*
     * 
     * @param string $search
     * @return string
     */
    private function prepareSearchTerms(string $search): string
    {
        if (empty($search)) {
            return '';
        }

        // Nettoyage de base : garder lettres, chiffres, espaces et traits d'union
        $searchClean = preg_replace('/[^a-zA-ZÀ-ÿ0-9\s\-]/', ' ', $search);
        
        // Normaliser les espaces multiples
        $searchClean = trim(preg_replace('/\s+/', ' ', $searchClean));
        
        // Convertir en minuscules pour le traitement
        $searchLower = mb_strtolower($searchClean);
        
        // Extraire les parties séparées par " - " (marque, produit, variation)
        $parts = explode(' - ', $searchLower);
        
        $booleanTerms = [];
        
        // Traiter chaque partie séparément
        foreach ($parts as $partIndex => $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }
            
            // Pour la première partie (marque) - traitement agressif
            if ($partIndex === 0) {
                $brandTerms = $this->extractBrandTerms($part);
                $booleanTerms = array_merge($booleanTerms, $brandTerms);
            }
            // Pour la deuxième partie (nom du produit) - traitement modéré
            elseif ($partIndex === 1) {
                $productTerms = $this->extractProductTerms($part);
                $booleanTerms = array_merge($booleanTerms, $productTerms);
            }
            // Pour la troisième partie (variation) - traitement conservateur
            else {
                $variationTerms = $this->extractVariationTerms($part);
                $booleanTerms = array_merge($booleanTerms, $variationTerms);
            }
        }
        
        // Si pas de parties séparées par " - ", traiter comme un texte simple
        if (empty($booleanTerms) && !empty($searchLower)) {
            $booleanTerms = $this->extractGenericTerms($searchLower);
        }
        
        // Limiter le nombre total de termes pour éviter les requêtes trop lourdes
        $booleanTerms = array_slice($booleanTerms, 0, 8);
        
        // Supprimer les doublons
        $booleanTerms = array_unique($booleanTerms);
        
        \Log::info('Search terms prepared:', [
            'original' => $search,
            'clean' => $searchClean,
            'terms' => $booleanTerms
        ]);
        
        return implode(' ', $booleanTerms);
    }

    /**
     * Extrait les termes pour la marque (traitement agressif)
     */
    private function extractBrandTerms(string $brandText): array
    {
        $terms = [];
        
        // Stop words spécifiques aux marques
        $brandStopWords = ['the', 'le', 'la', 'les', 'de'];
        
        // Séparer les mots
        $words = explode(' ', $brandText);
        $significantWords = [];
        
        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) > 2 && !in_array($word, $brandStopWords)) {
                $significantWords[] = $word;
            }
        }
        
        // Ajouter chaque mot significatif individuellement
        foreach ($significantWords as $word) {
            $terms[] = '+' . $word . '*';
        }
        
        // Ajouter la marque complète comme phrase exacte si elle a au moins 2 mots
        if (count($significantWords) >= 2) {
            $fullBrand = '"' . implode(' ', $significantWords) . '"*';
            $terms[] = '+' . $fullBrand;
        }
        
        return $terms;
    }

    /**
     * Extrait les termes pour le nom du produit (traitement modéré)
     */
    private function extractProductTerms(string $productText): array
    {
        $terms = [];
        
        // Stop words pour les noms de produits
        $productStopWords = [
            'pour', 'de', 'le', 'la', 'les', 'un', 'une', 'des', 'du', 'et', 'ou',
            'the', 'a', 'an', 'and', 'or', 'with', 'by'
        ];
        
        // Phrases courantes dans les parfums à garder ensemble
        $commonPhrases = [
            'eau de parfum', 'eau de toilette', 'eau fraiche', 'parfum', 
            'extrait de parfum', 'body lotion', 'body spray', 'hair mist',
            'after shave', 'body cream', 'shower gel', 'body wash'
        ];
        
        // Vérifier les phrases courantes d'abord
        foreach ($commonPhrases as $phrase) {
            if (str_contains($productText, $phrase)) {
                $terms[] = '+"' . $phrase . '"*';
                // Retirer la phrase du texte pour éviter les doublons
                $productText = str_replace($phrase, '', $productText);
            }
        }
        
        // Traiter les mots restants
        $words = explode(' ', trim($productText));
        $significantWords = [];
        
        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) > 2 && !in_array($word, $productStopWords)) {
                $significantWords[] = $word;
            }
        }
        
        // Ajouter chaque mot significatif individuellement
        foreach ($significantWords as $word) {
            $terms[] = '+' . $word . '*';
        }
        
        // Ajouter le nom complet du produit comme phrase si assez de mots
        if (count($significantWords) >= 2) {
            $fullProduct = '"' . implode(' ', $significantWords) . '"*';
            $terms[] = '+' . $fullProduct;
        }
        
        return $terms;
    }

    /**
     * Extrait les termes pour la variation (traitement conservateur)
     */
    private function extractVariationTerms(string $variationText): array
    {
        $terms = [];
        
        // Types de variations courants
        $variationTypes = [
            'vaporisateur', 'spray', 'coffret', 'set', 'pack', 'collection',
            'travel', 'voyage', 'miniature', 'sample', 'flacon', 'bottle',
            'roller', 'roll-on', 'stick', 'solid', 'cream', 'creme',
            'oil', 'huile', 'gel', 'lotion', 'milk', 'lait'
        ];
        
        // Unités de volume et tailles
        $volumeUnits = ['ml', 'l', 'oz', 'fl', 'g', 'kg'];
        
        // Extraire les volumes (pour les exclure du texte)
        $variationWithoutVolume = preg_replace('/\d+\s*(' . implode('|', $volumeUnits) . ')/i', '', $variationText);
        
        // Vérifier les types de variations
        foreach ($variationTypes as $type) {
            if (str_contains($variationWithoutVolume, $type)) {
                $terms[] = '+' . $type . '*';
            }
        }
        
        // Phrases spécifiques aux variations
        $variationPhrases = [
            'eau de parfum', 'eau de toilette', 'body spray', 'hair mist',
            'shower gel', 'body lotion', 'after shave', 'body cream'
        ];
        
        foreach ($variationPhrases as $phrase) {
            if (str_contains($variationWithoutVolume, $phrase)) {
                $terms[] = '+"' . $phrase . '"*';
            }
        }
        
        // Ajouter des termes génériques significatifs de la variation
        $words = explode(' ', trim($variationWithoutVolume));
        $significantWords = [];
        
        foreach ($words as $word) {
            $word = trim($word);
            // Garder les mots de plus de 3 caractères qui ne sont pas des stop words
            if (strlen($word) > 3) {
                $significantWords[] = $word;
            }
        }
        
        // Limiter à 3 mots maximum pour la variation
        $significantWords = array_slice($significantWords, 0, 3);
        
        foreach ($significantWords as $word) {
            $terms[] = '+' . $word . '*';
        }
        
        return $terms;
    }

    /**
     * Extraction générique quand le format n'est pas standard
     */
    private function extractGenericTerms(string $text): array
    {
        $terms = [];
        
        // Stop words complets
        $stopWords = [
            'de', 'le', 'la', 'les', 'un', 'une', 'des', 'du', 'et', 'ou', 'pour', 'avec', 'sur', 'sous',
            'the', 'a', 'an', 'and', 'or', 'with', 'by', 'in', 'on', 'at', 'to', 'for', 'of'
        ];
        
        // Phrases courantes à garder ensemble
        $commonPhrases = [
            'eau de parfum', 'eau de toilette', 'body spray', 'hair mist',
            'shower gel', 'body lotion', 'after shave', 'body cream',
            'roll on', 'roll-on', 'travel size', 'mini size'
        ];
        
        // Vérifier les phrases courantes d'abord
        foreach ($commonPhrases as $phrase) {
            if (str_contains($text, $phrase)) {
                $terms[] = '+"' . $phrase . '"*';
                $text = str_replace($phrase, '', $text);
            }
        }
        
        // Traiter les mots individuels
        $words = explode(' ', trim($text));
        $significantWords = [];
        
        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) > 2 && !in_array($word, $stopWords) && !is_numeric($word)) {
                $significantWords[] = $word;
            }
        }
        
        // Limiter à 6 mots maximum
        $significantWords = array_slice($significantWords, 0, 6);
        
        // Ajouter les mots individuels
        foreach ($significantWords as $word) {
            $terms[] = '+' . $word . '*';
        }
        
        // Ajouter des combinaisons de 2 mots si possible
        for ($i = 0; $i < count($significantWords) - 1; $i++) {
            $twoWordPhrase = $significantWords[$i] . ' ' . $significantWords[$i + 1];
            $terms[] = '+"' . $twoWordPhrase . '"*';
            
            // Limiter à 3 phrases de 2 mots
            if ($i >= 2) break;
        }
        
        return $terms;
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
     * Vérifie si le produit a exactement la même variation que la recherche
     */
    public function hasExactVariationMatch($product)
    {
        $searchVariation = $this->extractSearchVariation();
        $productVariation = $product->variation ?? '';
        
        // Comparaison insensible à la casse et en ignorant les espaces supplémentaires
        $searchNormalized = $this->normalizeVariation($searchVariation);
        $productNormalized = $this->normalizeVariation($productVariation);
        
        return $searchNormalized === $productNormalized;
    }
    
    /**
     * Extrait la variation de la recherche complète
     */
    private function extractSearchVariation()
    {
        // Supprimer la marque et le nom du produit pour isoler la variation
        $pattern = '/^[^-]+\s*-\s*[^-]+\s*-\s*/i';
        $variation = preg_replace($pattern, '', $this->search ?? '');
        
        return trim($variation);
    }
    
    /**
     * Normalise une variation pour la comparaison
     */
    private function normalizeVariation($variation)
    {
        if (empty($variation)) {
            return '';
        }
        
        // Convertir en minuscules
        $normalized = mb_strtolower(trim($variation));
        
        // Supprimer les caractères spéciaux et espaces multiples
        $normalized = preg_replace('/[^a-zA-ZÀ-ÿ0-9\s]/', ' ', $normalized);
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized));
        
        return $normalized;
    }

    /**
     * Vérifie si le produit a le même volume ET la même variation exacte que la recherche
     */
    public function hasSameVolumeAndExactVariation($product)
    {
        $hasMatchingVolume = $this->hasMatchingVolume($product);
        $hasExactVariation = $this->hasExactVariationMatch($product);
        
        return $hasMatchingVolume && $hasExactVariation;
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

    <livewire:plateformes.detail :id="$id"/>

    <!-- Section des résultats -->
    <div class="mx-auto w-full px-4 py-6 sm:px-6 lg:px-8">
        @if($hasData)
            <!-- Indicateur des critères recherchés -->
            @if(!empty($searchVolumes))
                <div class="mb-4 p-4 bg-blue-50 rounded-lg">
                    <div class="flex flex-col space-y-2">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 text-blue-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <span class="text-sm font-medium text-blue-800">Critères de correspondance exacte :</span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if(!empty($searchVolumes))
                                <div class="flex items-center">
                                    <span class="text-xs text-blue-700 mr-1">Volume recherché :</span>
                                    @foreach($searchVolumes as $volume)
                                        <span class="bg-green-100 text-green-800 font-semibold px-2 py-1 rounded text-xs">{{ $volume }} ml</span>
                                    @endforeach
                                </div>
                            @endif
                            @php
                                $searchVariation = $this->extractSearchVariation();
                            @endphp
                            @if($searchVariation)
                                <div class="flex items-center">
                                    <span class="text-xs text-blue-700 mr-1">Variation recherchée :</span>
                                    <span class="bg-blue-100 text-blue-800 font-semibold px-2 py-1 rounded text-xs">{{ $searchVariation }}</span>
                                </div>
                            @endif
                        </div>
                        <div class="text-xs text-blue-600 mt-1">
                            <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            Les produits avec <span class="text-green-600 font-semibold">✓</span> ont exactement le même volume ET la même variation
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
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"></th>
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
                                    $hasExactVariation = $this->hasExactVariationMatch($product);
                                    $hasSameVolumeAndExactVariation = $this->hasSameVolumeAndExactVariation($product);
                                @endphp
                                <tr class="hover:bg-gray-50">
                                    <!-- Colonne Check -->
                                    <td class="px-6 py-4 whitespace-nowrap text-center">
                                        @if($hasSameVolumeAndExactVariation)
                                            <div class="flex justify-center">
                                                <svg class="w-6 h-6 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                                </svg>
                                            </div>
                                        @endif
                                    </td>
                                    
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
                                    </td>
                                    
                                    <!-- Colonne Nom -->
                                    <td class="px-6 py-4">
                                        <div class="text-sm font-medium text-gray-900 max-w-xs" title="{{ $product->name ?? 'N/A' }}">
                                            {{ $product->name }}
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
                                            {!! $this->highlightMatchingTerms($product->variation ?? 'Standard') !!}
                                        </div>
                                        @if($hasExactVariation)
                                            <div class="mt-1">
                                                <span class="inline-flex items-center px-2 py-1 bg-blue-100 text-blue-800 text-xs font-medium rounded-full">
                                                    <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                                    </svg>
                                                    Variation identique
                                                </span>
                                            </div>
                                        @endif
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