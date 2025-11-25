<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;

new class extends Component {
    public $products = [];
    public $hasData = false;
    public $searchTerms = [];
    public $searchVolumes = [];
    public $searchKeywords = []; // Nouveaux mots-clés à rechercher (Coffret, Eau de Parfum, etc.)
    
    public function mount($name, $id)
    {
        $this->getCompetitorPrice($name);
        $this->getOneProductDetails($id);
    }

    public function getCompetitorPrice($search)
    {
        try {
            if (empty($search)) {
                $this->products = [];
                $this->hasData = false;
                return null;
            }
            
            // Extraire les volumes ET les mots-clés de la recherche
            $this->extractSearchVolumes($search);
            $this->extractSearchKeywords($search);
            
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
                'search_keywords' => $this->searchKeywords
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
                'keywords' => $this->searchKeywords
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
     * Extrait les mots-clés importants de la variation (Coffret, Eau de Parfum, etc.)
     */
    private function extractSearchKeywords(string $search): void
    {
        $this->searchKeywords = [];
        
        // Mots-clés à rechercher (insensible à la casse)
        $keywords = [
            'coffret',
            'eau de parfum',
            'eau de toilette',
            'parfum',
            'toilette',
            'spray',
            'vaporisateur',
            'edition',
            'édition',
            'limitée',
            'rechargeable'
        ];
        
        $searchLower = mb_strtolower($search);
        
        foreach ($keywords as $keyword) {
            if (stripos($searchLower, $keyword) !== false) {
                $this->searchKeywords[] = $keyword;
            }
        }
        
        \Log::info('Extracted search keywords:', [
            'search' => $search,
            'keywords' => $this->searchKeywords
        ]);
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
     * Vérifie si la variation contient TOUS les critères recherchés
     * (au moins un volume ET au moins un mot-clé)
     */
    public function isVariationMatching($text)
    {
        if (empty($text)) {
            return false;
        }
        
        $textLower = mb_strtolower($text);
        
        // Vérifier si au moins un volume est présent
        $hasVolume = false;
        foreach ($this->searchVolumes as $volume) {
            if (preg_match('/\b' . preg_quote($volume, '/') . '\s*ml\b/i', $text)) {
                $hasVolume = true;
                break;
            }
        }
        
        // Vérifier si au moins un mot-clé est présent
        $hasKeyword = false;
        foreach ($this->searchKeywords as $keyword) {
            if (stripos($textLower, $keyword) !== false) {
                $hasKeyword = true;
                break;
            }
        }
        
        // Retourner true seulement si VOLUME ET MOT-CLÉ sont présents
        return $hasVolume && $hasKeyword;
    }

    /**
     * Met en évidence les volumes ET mots-clés correspondants dans un texte
     */
    public function highlightMatchingTerms($text)
    {
        if (empty($text)) {
            return $text;
        }

        // Mettre en évidence les volumes
        foreach ($this->searchVolumes as $volume) {
            $pattern = '/\b' . preg_quote($volume, '/') . '\s*ml\b/i';
            $replacement = '<span class="bg-green-200 text-green-900 font-semibold px-1 py-0.5 rounded">' . $volume . ' ml</span>';
            $text = preg_replace($pattern, $replacement, $text);
        }
        
        // Mettre en évidence les mots-clés
        foreach ($this->searchKeywords as $keyword) {
            $pattern = '/\b' . preg_quote($keyword, '/') . '\b/i';
            $replacement = '<span class="bg-green-200 text-green-900 font-semibold px-1 py-0.5 rounded">$0</span>';
            $text = preg_replace($pattern, $replacement, $text);
        }

        return $text;
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
};