<?php

namespace App\Livewire\Components;

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

new class extends Component {
    public $products = [];
    public $product = null;
    public $hasData = false;
    public $searchTerms = [];
    public $searchVolumes = [];
    public $searchVariationKeywords = [];

    public $id;
    public $mydata;

    public $similarityThreshold = 0.6;
    public $matchedProducts = [];
    public $searchQuery = '';
    
    public $price;
    public $referencePrice;
    public $cosmashopPrice;

    // Propriété pour stocker les informations extraites de la recherche
    public $extractedData = [
        'vendor' => '',
        'name' => '',
        'type' => '',
        'variation' => '',
        'volume_ml' => null,
        'full_variation' => ''
    ];

    public function mount($name, $id, $price)
    {
        // Extrait les informations structurées de la recherche
        $this->extractProductInfoFromSearch($name);
        
        $this->getCompetitorPrice($name);
        $this->id = $id;
        $this->price = $this->cleanPrice($price);
        $this->referencePrice = $this->cleanPrice($price);
        $this->cosmashopPrice = $this->cleanPrice($price) * 1.05;
        $this->searchQuery = $name;
    }

    /**
     * Extrait les informations structurées du terme de recherche
     * Format: VENDOR - NOM - TYPE/VARIATION (VOLUME ML)
     * Exemple: "Jean Paul Gaultier - Scandal Le Parfum - Eau de Parfum Vaporisateur 80 ml"
     */
    private function extractProductInfoFromSearch(string $search): void
    {
        // Réinitialiser les données extraites
        $this->extractedData = [
            'vendor' => '',
            'name' => '',
            'type' => '',
            'variation' => '',
            'volume_ml' => null,
            'full_variation' => ''
        ];

        \Log::info('Début extraction recherche:', ['recherche' => $search]);

        // 1. Extraire d'abord le volume en ML
        if (preg_match('/(\d+)\s*ml/i', $search, $volumeMatches)) {
            $this->extractedData['volume_ml'] = (int)$volumeMatches[1];
            \Log::info('Volume extrait:', ['volume' => $this->extractedData['volume_ml']]);
            
            // Supprimer le volume pour faciliter l'extraction d'autres parties
            $search = preg_replace('/(\d+)\s*ml/i', '', $search);
        }

        // 2. Découper par séparateur "-"
        $parts = array_map('trim', explode('-', $search));
        $parts = array_filter($parts, function($part) {
            return !empty($part);
        });
        
        \Log::info('Parties après découpage:', ['parts' => $parts, 'count' => count($parts)]);

        // 3. Assigner les parties selon leur position et contenu
        if (count($parts) >= 1) {
            $this->extractedData['vendor'] = $parts[0];
            \Log::info('Vendor extrait:', ['vendor' => $this->extractedData['vendor']]);
        }
        
        if (count($parts) >= 2) {
            $this->extractedData['name'] = $parts[1];
            \Log::info('Nom extrait:', ['name' => $this->extractedData['name']]);
        }
        
        // 4. Gestion spéciale pour la troisième partie qui peut contenir type + variation
        if (count($parts) >= 3) {
            $typeVariationPart = $parts[2];
            \Log::info('Partie type/variation originale:', ['part' => $typeVariationPart]);
            
            // Extraire le type de produit
            $this->extractedData['type'] = $this->extractProductTypeFromString($typeVariationPart);
            \Log::info('Type extrait:', ['type' => $this->extractedData['type']]);
            
            // Ce qui reste après extraction du type est la variation
            $variation = $this->removeProductTypeFromString($typeVariationPart, $this->extractedData['type']);
            $this->extractedData['variation'] = trim($variation);
            \Log::info('Variation extraite:', ['variation' => $this->extractedData['variation']]);
            
            // Stocker aussi la variation complète
            $this->extractedData['full_variation'] = $typeVariationPart;
        } elseif (count($parts) == 2) {
            // Si seulement 2 parties, la variation peut être dans le nom
            $this->extractedData['variation'] = $this->extractedData['name'];
        }

        // 5. Si on a plus de parties, les ajouter à la variation
        if (count($parts) > 3) {
            $extraParts = array_slice($parts, 3);
            $extraVariation = implode(' - ', $extraParts);
            if (!empty($this->extractedData['variation'])) {
                $this->extractedData['variation'] .= ' - ' . $extraVariation;
            } else {
                $this->extractedData['variation'] = $extraVariation;
            }
        }

        // 6. Nettoyer et valider les données extraites
        $this->cleanExtractedData();

        \Log::info('Données finales extraites:', $this->extractedData);
    }

    /**
     * Extrait le type de produit d'une chaîne avec une logique améliorée
     */
    private function extractProductTypeFromString(string $text): string
    {
        $text = trim($text);
        $textLower = mb_strtolower($text);
        
        // Liste des types avec leurs variations et priorités
        $typePatterns = [
            'eau de parfum vaporisateur' => ['eau de parfum vaporisateur', 'edp vaporisateur'],
            'eau de parfum' => ['eau de parfum', 'edp', 'parfum vaporisateur'],
            'eau de toilette vaporisateur' => ['eau de toilette vaporisateur', 'edt vaporisateur'],
            'eau de toilette' => ['eau de toilette', 'edt'],
            'eau de cologne' => ['eau de cologne', 'edc'],
            'parfum' => ['parfum', 'extrait de parfum'],
            'coffret' => ['coffret', 'set', 'kit', 'collection', 'coffret cadeau'],
            'gel douche' => ['gel douche', 'shower gel', 'gel lavant'],
            'lotion' => ['lotion', 'body lotion', 'lotion pour le corps'],
            'crème' => ['crème', 'cream', 'body cream'],
            'savon' => ['savon', 'soap'],
            'baume' => ['baume', 'balm'],
            'déodorant' => ['déodorant', 'deodorant'],
            'shampooing' => ['shampooing', 'shampoo'],
            'après-rasage' => ['après-rasage', 'after shave']
        ];

        // Chercher d'abord les types les plus spécifiques
        foreach ($typePatterns as $typeName => $patterns) {
            foreach ($patterns as $pattern) {
                if (strpos($textLower, $pattern) !== false) {
                    \Log::info('Type détecté:', [
                        'texte' => $text,
                        'type_trouve' => $typeName,
                        'pattern' => $pattern
                    ]);
                    return $typeName;
                }
            }
        }

        // Si aucun type spécifique n'est trouvé, chercher des mots-clés génériques
        $genericKeywords = [
            'vaporisateur', 'spray', 'flacon', 'bottle', 'roll-on',
            'stick', 'tube', 'pot', 'atomiseur'
        ];

        foreach ($genericKeywords as $keyword) {
            if (strpos($textLower, $keyword) !== false) {
                \Log::info('Mot-clé générique détecté:', [
                    'texte' => $text,
                    'mot_cle' => $keyword
                ]);
                return $keyword;
            }
        }

        return '';
    }

    /**
     * Retire le type de produit d'une chaîne
     */
    private function removeProductTypeFromString(string $text, string $type): string
    {
        if (empty($type)) {
            return $text;
        }

        $textLower = mb_strtolower($text);
        $typeLower = mb_strtolower($type);

        // Liste des patterns pour ce type
        $typePatterns = [
            'eau de parfum vaporisateur' => ['eau de parfum vaporisateur', 'edp vaporisateur'],
            'eau de parfum' => ['eau de parfum', 'edp', 'parfum vaporisateur'],
            'eau de toilette vaporisateur' => ['eau de toilette vaporisateur', 'edt vaporisateur'],
            'eau de toilette' => ['eau de toilette', 'edt'],
            'eau de cologne' => ['eau de cologne', 'edc'],
            'parfum' => ['parfum', 'extrait de parfum'],
            'coffret' => ['coffret', 'set', 'kit', 'collection'],
            'gel douche' => ['gel douche', 'shower gel'],
            'lotion' => ['lotion', 'body lotion'],
            'crème' => ['crème', 'cream'],
            'savon' => ['savon', 'soap'],
            'baume' => ['baume', 'balm']
        ];

        // Supprimer tous les patterns correspondant au type
        $patterns = $typePatterns[$type] ?? [$type];
        
        foreach ($patterns as $pattern) {
            $patternLower = mb_strtolower($pattern);
            $textLower = str_replace($patternLower, '', $textLower);
        }

        // Nettoyer les espaces multiples
        $result = preg_replace('/\s+/', ' ', $textLower);
        return trim($result);
    }

    /**
     * Nettoie les données extraites
     */
    private function cleanExtractedData(): void
    {
        foreach ($this->extractedData as $key => $value) {
            if (is_string($value)) {
                $this->extractedData[$key] = trim($value);
                
                // Capitaliser correctement les noms propres pour le vendor
                if ($key === 'vendor') {
                    $this->extractedData[$key] = $this->formatVendorName($value);
                }
            }
        }

        // Si la variation est vide mais qu'on a full_variation, l'utiliser
        if (empty($this->extractedData['variation']) && !empty($this->extractedData['full_variation'])) {
            $this->extractedData['variation'] = $this->extractedData['full_variation'];
        }

        // Si le type est dans la variation, l'en retirer
        if (!empty($this->extractedData['type']) && !empty($this->extractedData['variation'])) {
            $variationWithoutType = $this->removeProductTypeFromString(
                $this->extractedData['variation'],
                $this->extractedData['type']
            );
            if (!empty($variationWithoutType)) {
                $this->extractedData['variation'] = $variationWithoutType;
            }
        }
    }

    /**
     * Formate correctement le nom du vendeur
     */
    private function formatVendorName(string $vendor): string
    {
        // Mots à ne pas capitaliser entièrement
        $smallWords = ['de', 'la', 'le', 'et', '&', 'and', 'or', 'for', 'the'];
        
        $words = explode(' ', $vendor);
        $formattedWords = [];
        
        foreach ($words as $word) {
            if (in_array(mb_strtolower($word), $smallWords)) {
                $formattedWords[] = mb_strtolower($word);
            } else {
                $formattedWords[] = mb_convert_case($word, MB_CASE_TITLE, 'UTF-8');
            }
        }
        
        return implode(' ', $formattedWords);
    }

    /**
     * Nettoie et convertit un prix en nombre décimal
     */
    private function cleanPrice($price)
    {
        if ($price === null || $price === '') {
            return null;
        }
        
        if (is_numeric($price)) {
            return (float) $price;
        }
        
        if (is_string($price)) {
            $cleanPrice = preg_replace('/[^\d,.-]/', '', $price);
            $cleanPrice = str_replace(',', '.', $cleanPrice);
            
            if (is_numeric($cleanPrice)) {
                return (float) $cleanPrice;
            }
        }
        
        return null;
    }

    /**
     * Récupère les détails d'un produit
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
            return $result;

        } catch (\Throwable $e) {
            \Log::error('Error loading products:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Récupère les prix des concurrents avec recherche EXACTE par colonne
     */
    public function getCompetitorPrice($search)
    {
        try {
            if (empty($search)) {
                $this->products = [];
                $this->hasData = false;
                return null;
            }

            // Extraire les informations structurées
            $this->extractProductInfoFromSearch($search);
            
            // Préparer la requête de recherche EXACTE par colonne
            $searchQuery = $this->buildExactSearchQuery();
            
            \Log::info('Recherche EXACTE par colonne:', [
                'recherche_originale' => $search,
                'donnees_extrait' => $this->extractedData,
                'requete_sql' => $searchQuery['sql'],
                'parametres' => $searchQuery['params']
            ]);

            $result = DB::connection('mysql')->select($searchQuery['sql'], $searchQuery['params']);

            // Nettoyer les prix
            foreach ($result as $product) {
                if (isset($product->prix_ht)) {
                    $product->prix_ht = $this->cleanPrice($product->prix_ht);
                }
            }

            // Si pas de résultats avec recherche exacte, essayer une recherche plus large
            if (empty($result)) {
                \Log::info('Aucun résultat avec recherche exacte, tentative avec recherche élargie');
                $searchQuery = $this->buildExpandedSearchQuery();
                $result = DB::connection('mysql')->select($searchQuery['sql'], $searchQuery['params']);
                
                // Nettoyer les prix
                foreach ($result as $product) {
                    if (isset($product->prix_ht)) {
                        $product->prix_ht = $this->cleanPrice($product->prix_ht);
                    }
                }
            }

            // Calculer la similarité avec les données structurées
            $this->matchedProducts = $this->calculateSimilarityWithExtractedData($result);
            $this->products = $this->matchedProducts;
            $this->hasData = !empty($result);

            return [
                'count' => count($result),
                'has_data' => $this->hasData,
                'products' => $this->matchedProducts,
                'product' => $this->getOneProductDetails($this->id),
                'extracted_data' => $this->extractedData
            ];

        } catch (\Throwable $e) {
            \Log::error('Error loading products:', [
                'message' => $e->getMessage(),
                'search' => $search ?? null,
                'trace' => $e->getTraceAsString()
            ]);

            $this->products = [];
            $this->hasData = false;

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Construit une requête de recherche EXACTE par colonne
     */
    private function buildExactSearchQuery(): array
    {
        $conditions = [];
        $params = [];
        
        // 1. Recherche EXACTE par vendor (priorité maximale)
        if (!empty($this->extractedData['vendor'])) {
            $vendor = $this->extractedData['vendor'];
            // Recherche exacte du vendor
            $conditions[] = "vendor = ?";
            $params[] = $vendor;
            
            // Recherche aussi avec vendor contenant le mot exact
            $conditions[] = "vendor LIKE ?";
            $params[] = "%{$vendor}%";
        }
        
        // 2. Recherche EXACTE par nom du produit
        if (!empty($this->extractedData['name'])) {
            $name = $this->extractedData['name'];
            // Recherche exacte du nom
            $conditions[] = "name = ?";
            $params[] = $name;
            
            // Recherche avec nom contenant le mot exact
            $conditions[] = "name LIKE ?";
            $params[] = "%{$name}%";
        }
        
        // 3. Recherche EXACTE par volume
        if (!empty($this->extractedData['volume_ml'])) {
            $volume = $this->extractedData['volume_ml'];
            // Recherche exacte du volume dans name et variation
            $conditions[] = "(name LIKE ? OR variation LIKE ? OR name LIKE ? OR variation LIKE ?)";
            $params[] = "{$volume} ml";
            $params[] = "{$volume} ml";
            $params[] = "{$volume}ml";
            $params[] = "{$volume}ml";
        }
        
        // 4. Recherche EXACTE par type
        if (!empty($this->extractedData['type'])) {
            $type = $this->extractedData['type'];
            // Recherche exacte du type
            $conditions[] = "type = ?";
            $params[] = $type;
            
            // Recherche avec type contenant le mot exact
            $conditions[] = "type LIKE ?";
            $params[] = "%{$type}%";
        }
        
        // 5. Recherche EXACTE par variation
        if (!empty($this->extractedData['variation'])) {
            $variation = $this->extractedData['variation'];
            // Recherche exacte de la variation
            $conditions[] = "variation = ?";
            $params[] = $variation;
            
            // Recherche avec variation contenant le mot exact
            $conditions[] = "variation LIKE ?";
            $params[] = "%{$variation}%";
        }

        // Construire la requête SQL avec recherche EXACTE
        $sql = "SELECT *, 
                       prix_ht,
                       image_url as image,
                       url as product_url,
                       vendor,
                       name,
                       type,
                       variation
                FROM last_price_scraped_product 
                WHERE 1=1 ";
        
        if (!empty($conditions)) {
            $sql .= " AND (" . implode(" OR ", $conditions) . ")";
        }
        
        // Ajouter un ordre de priorité TRÈS STRICT
        $sql .= " ORDER BY ";
        
        $orderConditions = [];
        
        // Priorité ABSOLUE: Correspondance EXACTE du vendor
        if (!empty($this->extractedData['vendor'])) {
            $vendor = addslashes($this->extractedData['vendor']);
            $orderConditions[] = "vendor = '{$vendor}' DESC";
            $orderConditions[] = "vendor LIKE '{$vendor}%' DESC";
        }
        
        // Priorité 2: Correspondance EXACTE du volume
        if (!empty($this->extractedData['volume_ml'])) {
            $volume = $this->extractedData['volume_ml'];
            $orderConditions[] = "name = '{$volume} ml' DESC";
            $orderConditions[] = "variation = '{$volume} ml' DESC";
            $orderConditions[] = "name LIKE '%{$volume} ml%' DESC";
            $orderConditions[] = "variation LIKE '%{$volume} ml%' DESC";
        }
        
        // Priorité 3: Correspondance EXACTE du type
        if (!empty($this->extractedData['type'])) {
            $type = addslashes($this->extractedData['type']);
            $orderConditions[] = "type = '{$type}' DESC";
            $orderConditions[] = "type LIKE '{$type}%' DESC";
        }
        
        // Priorité 4: Correspondance EXACTE du nom
        if (!empty($this->extractedData['name'])) {
            $name = addslashes($this->extractedData['name']);
            $orderConditions[] = "name = '{$name}' DESC";
            $orderConditions[] = "name LIKE '{$name}%' DESC";
        }
        
        if (!empty($orderConditions)) {
            $sql .= implode(", ", $orderConditions) . ", ";
        }
        
        $sql .= "prix_ht DESC LIMIT 30";
        
        return [
            'sql' => $sql,
            'params' => $params
        ];
    }

    /**
     * Construit une requête de recherche élargie (fallback)
     */
    private function buildExpandedSearchQuery(): array
    {
        $conditions = [];
        $params = [];
        
        // Recherche élargie mais toujours ciblée
        
        // 1. Recherche par mots-clés significatifs du vendor
        if (!empty($this->extractedData['vendor'])) {
            $vendorKeywords = $this->extractSignificantKeywords($this->extractedData['vendor']);
            foreach ($vendorKeywords as $keyword) {
                if (strlen($keyword) > 3) {
                    $conditions[] = "vendor LIKE ?";
                    $params[] = "%{$keyword}%";
                }
            }
        }
        
        // 2. Recherche par mots-clés significatifs du nom
        if (!empty($this->extractedData['name'])) {
            $nameKeywords = $this->extractSignificantKeywords($this->extractedData['name']);
            foreach ($nameKeywords as $keyword) {
                if (strlen($keyword) > 3) {
                    $conditions[] = "name LIKE ?";
                    $params[] = "%{$keyword}%";
                }
            }
        }
        
        // 3. Recherche par volume (toujours prioritaire)
        if (!empty($this->extractedData['volume_ml'])) {
            $volume = $this->extractedData['volume_ml'];
            $conditions[] = "(name LIKE ? OR variation LIKE ?)";
            $params[] = "%{$volume} ml%";
            $params[] = "%{$volume}ml%";
        }
        
        // Construire la requête SQL élargie
        $sql = "SELECT *, 
                       prix_ht,
                       image_url as image,
                       url as product_url,
                       vendor,
                       name,
                       type,
                       variation
                FROM last_price_scraped_product 
                WHERE 1=1 ";
        
        if (!empty($conditions)) {
            $sql .= " AND (" . implode(" OR ", $conditions) . ")";
        }
        
        // Ordre de priorité pour la recherche élargie
        $sql .= " ORDER BY ";
        
        $orderConditions = [];
        
        // Volume en priorité
        if (!empty($this->extractedData['volume_ml'])) {
            $volume = $this->extractedData['volume_ml'];
            $orderConditions[] = "(name LIKE '%{$volume} ml%' OR variation LIKE '%{$volume} ml%') DESC";
        }
        
        // Puis vendor
        if (!empty($this->extractedData['vendor'])) {
            $vendor = addslashes($this->extractedData['vendor']);
            $orderConditions[] = "vendor LIKE '%{$vendor}%' DESC";
        }
        
        if (!empty($orderConditions)) {
            $sql .= implode(", ", $orderConditions) . ", ";
        }
        
        $sql .= "prix_ht DESC LIMIT 20";
        
        return [
            'sql' => $sql,
            'params' => $params
        ];
    }

    /**
     * Extrait les mots-clés significatifs (mots complets, pas trop courts)
     */
    private function extractSignificantKeywords(string $text): array
    {
        $text = preg_replace('/[^a-zA-ZÀ-ÿ0-9\s]/', ' ', $text);
        $words = explode(' ', $text);
        
        $stopWords = [
            'de', 'le', 'la', 'les', 'un', 'une', 'des', 'du', 'et', 'ou',
            'pour', 'avec', 'the', 'a', 'an', 'and', 'or', 'ml', 'edition',
            'édition', 'coffret', 'vaporisateur', 'spray', 'flacon', 'bottle',
            'eau', 'parfum', 'toilette', 'cologne', 'gel', 'douche', 'lotion',
            'crème', 'savon', 'baume', 'déodorant', 'shampooing'
        ];
        
        $keywords = [];
        foreach ($words as $word) {
            $word = trim($word);
            if (!empty($word) && strlen($word) >= 4 && !in_array(mb_strtolower($word), $stopWords)) {
                $keywords[] = $word;
            }
        }
        
        return array_unique($keywords);
    }

    /**
     * Extrait les mots-clés d'un vendeur
     */
    private function extractKeywordsFromVendor(string $vendor): array
    {
        $vendor = trim($vendor);
        $keywords = [];
        
        // Pour les vendeurs multi-mots, prendre chaque mot significatif
        $words = explode(' ', $vendor);
        $stopWords = ['&', 'and', 'de', 'la', 'le', 'the'];
        
        foreach ($words as $word) {
            $word = trim($word);
            if (!empty($word) && !in_array(mb_strtolower($word), $stopWords) && strlen($word) > 2) {
                $keywords[] = $word;
            }
        }
        
        // Ajouter aussi le nom complet du vendeur si significatif
        if (strlen($vendor) > 3) {
            $keywords[] = $vendor;
        }
        
        return array_unique($keywords);
    }

    /**
     * Extrait les mots-clés d'un type
     */
    private function extractTypeKeywords(string $type): array
    {
        $keywords = [];
        
        // Ajouter seulement le type lui-même s'il est significatif
        if (strlen($type) > 2) {
            $keywords[] = mb_strtolower($type);
        }
        
        // Ajouter des variations du type seulement si elles sont significatives
        $typeVariations = [
            'eau de parfum' => ['edp', 'parfum'],
            'eau de toilette' => ['edt'],
            'eau de cologne' => ['edc'],
            'parfum' => ['extrait'],
            'coffret' => ['set', 'kit'],
            'vaporisateur' => ['spray', 'atomiseur']
        ];
        
        $typeLower = mb_strtolower($type);
        foreach ($typeVariations as $mainType => $variations) {
            if ($typeLower === $mainType || strpos($typeLower, $mainType) !== false) {
                foreach ($variations as $variation) {
                    if (strlen($variation) > 2) {
                        $keywords[] = $variation;
                    }
                }
            }
        }
        
        return array_unique($keywords);
    }

    /**
     * Extrait les mots-clés significatifs d'un texte
     */
    private function extractKeywords(string $text): array
    {
        $text = preg_replace('/[^a-zA-ZÀ-ÿ0-9\s]/', ' ', $text);
        $words = explode(' ', $text);
        
        $stopWords = [
            'de', 'le', 'la', 'les', 'un', 'une', 'des', 'du', 'et', 'ou',
            'pour', 'avec', 'the', 'a', 'an', 'and', 'or', 'ml', 'edition',
            'édition', 'coffret', 'vaporisateur', 'spray', 'flacon', 'bottle'
        ];
        
        $keywords = [];
        foreach ($words as $word) {
            $word = trim($word);
            if (!empty($word) && strlen($word) > 2 && !in_array(mb_strtolower($word), $stopWords)) {
                $keywords[] = $word;
            }
        }
        
        return array_unique($keywords);
    }

    /**
     * Calcule la similarité avec les données structurées extraites
     */
    private function calculateSimilarityWithExtractedData($products)
    {
        $scoredProducts = [];

        foreach ($products as $product) {
            $similarityScore = $this->computeEnhancedSimilarity($product);

            if ($similarityScore >= $this->similarityThreshold) {
                $product->similarity_score = $similarityScore;
                $product->match_level = $this->getMatchLevel($similarityScore);
                $product->match_details = $this->getMatchDetails($product);
                $scoredProducts[] = $product;
            }
        }

        // Trier par score de similarité
        usort($scoredProducts, function ($a, $b) {
            return $b->similarity_score <=> $a->similarity_score;
        });

        return $scoredProducts;
    }

    /**
     * Calcule la similarité améliorée avec pondération
     */
    private function computeEnhancedSimilarity($product): float
    {
        $weights = [
            'vendor' => 0.30,  // Augmenté
            'name' => 0.25,
            'type' => 0.15,
            'variation' => 0.10,  // Réduit
            'volume' => 0.20
        ];

        $scores = [];
        
        // Score pour le vendor (plus strict)
        $scores['vendor'] = $this->computeVendorSimilarity($product);
        
        // Score pour le nom (plus strict)
        $scores['name'] = $this->computeNameSimilarity($product);
        
        // Score pour le type
        $scores['type'] = $this->computeTypeSimilarity($product);
        
        // Score pour la variation
        $scores['variation'] = $this->computeVariationSimilarity($product);
        
        // Score pour le volume
        $scores['volume'] = $this->computeVolumeSimilarity($product);
        
        // Calcul du score total pondéré
        $totalScore = 0;
        foreach ($weights as $field => $weight) {
            $totalScore += ($scores[$field] ?? 0) * $weight;
        }
        
        // Bonus pour les correspondances EXACTES (augmenté)
        $bonus = $this->calculateExactMatchBonus($product);
        $totalScore = min(1.0, $totalScore + $bonus);
        
        \Log::info('Score de similarité calculé:', [
            'produit' => $product->name ?? 'N/A',
            'vendor' => $product->vendor ?? 'N/A',
            'scores' => $scores,
            'total' => $totalScore,
            'bonus' => $bonus
        ]);
        
        return $totalScore;
    }

    /**
     * Calcule la similarité du vendeur (plus strict)
     */
    private function computeVendorSimilarity($product): float
    {
        $searchVendor = $this->extractedData['vendor'] ?? '';
        $productVendor = $product->vendor ?? '';
        
        if (empty($searchVendor) || empty($productVendor)) {
            return 0;
        }
        
        $searchVendorLower = mb_strtolower(trim($searchVendor));
        $productVendorLower = mb_strtolower(trim($productVendor));
        
        // Correspondance exacte
        if ($searchVendorLower === $productVendorLower) {
            return 1.0;
        }
        
        // Le vendeur recherché est contenu dans le vendeur du produit
        if (strpos($productVendorLower, $searchVendorLower) !== false) {
            return 0.9;
        }
        
        // Le vendeur du produit est contenu dans le vendeur recherché
        if (strpos($searchVendorLower, $productVendorLower) !== false) {
            return 0.8;
        }
        
        // Vérifier les mots-clés significatifs
        $searchKeywords = $this->extractSignificantKeywords($searchVendor);
        $productKeywords = $this->extractSignificantKeywords($productVendor);
        
        $matchingKeywords = array_intersect($searchKeywords, $productKeywords);
        if (!empty($matchingKeywords)) {
            $matchRatio = count($matchingKeywords) / max(1, count($searchKeywords));
            return $matchRatio * 0.7;
        }
        
        // Similarité textuelle de base
        $similarity = $this->computeStringSimilarity($searchVendor, $productVendor);
        
        // Pénaliser les similarités faibles
        return $similarity > 0.6 ? $similarity : 0;
    }

    /**
     * Calcule la similarité du nom (plus strict)
     */
    private function computeNameSimilarity($product): float
    {
        $searchName = $this->extractedData['name'] ?? '';
        $productName = $product->name ?? '';
        
        if (empty($searchName) || empty($productName)) {
            return 0;
        }
        
        $searchNameLower = mb_strtolower(trim($searchName));
        $productNameLower = mb_strtolower(trim($productName));
        
        // Correspondance exacte
        if ($searchNameLower === $productNameLower) {
            return 1.0;
        }
        
        // Le nom recherché est contenu dans le nom du produit
        if (strpos($productNameLower, $searchNameLower) !== false) {
            return 0.9;
        }
        
        // Le nom du produit est contenu dans le nom recherché
        if (strpos($searchNameLower, $productNameLower) !== false) {
            return 0.8;
        }
        
        // Extraire les mots-clés significatifs
        $searchKeywords = $this->extractSignificantKeywords($searchName);
        $productKeywords = $this->extractSignificantKeywords($productName);
        
        $matchingKeywords = array_intersect($searchKeywords, $productKeywords);
        if (!empty($matchingKeywords)) {
            $matchRatio = count($matchingKeywords) / max(1, count($searchKeywords));
            return $matchRatio * 0.8;
        }
        
        // Similarité textuelle
        $similarity = $this->computeStringSimilarity($searchName, $productName);
        
        // Pénaliser les similarités faibles
        return $similarity > 0.6 ? $similarity : 0;
    }

    /**
     * Calcule la similarité du type
     */
    private function computeTypeSimilarity($product): float
    {
        $searchType = $this->extractedData['type'] ?? '';
        $productType = $product->type ?? '';
        
        if (empty($searchType) || empty($productType)) {
            return 0;
        }
        
        $searchTypeLower = mb_strtolower(trim($searchType));
        $productTypeLower = mb_strtolower(trim($productType));
        
        // Correspondance exacte
        if ($searchTypeLower === $productTypeLower) {
            return 1.0;
        }
        
        // Vérifier les variations de type
        $searchTypeKeywords = $this->extractTypeKeywords($searchType);
        $productTypeKeywords = $this->extractTypeKeywords($productType);
        
        $matchingKeywords = array_intersect($searchTypeKeywords, $productTypeKeywords);
        if (!empty($matchingKeywords)) {
            $matchRatio = count($matchingKeywords) / max(1, count($searchTypeKeywords));
            return $matchRatio * 0.8;
        }
        
        // Similarité textuelle
        return $this->computeStringSimilarity($searchType, $productType);
    }

    /**
     * Calcule la similarité de la variation
     */
    private function computeVariationSimilarity($product): float
    {
        $searchVariation = $this->extractedData['variation'] ?? '';
        $productVariation = $product->variation ?? '';
        
        if (empty($searchVariation) || empty($productVariation)) {
            return 0;
        }
        
        // Extraire les volumes des variations
        $searchVolume = $this->extractedData['volume_ml'];
        $productVolume = $this->extractVolumeFromText($productVariation);
        
        // Bonus si les volumes correspondent
        $volumeBonus = 0;
        if ($searchVolume && $productVolume && $searchVolume == $productVolume) {
            $volumeBonus = 0.3;
        }
        
        // Similarité de base
        $baseSimilarity = $this->computeStringSimilarity($searchVariation, $productVariation);
        
        return min(1.0, $baseSimilarity + $volumeBonus);
    }

    /**
     * Calcule la similarité du volume
     */
    private function computeVolumeSimilarity($product): float
    {
        $searchVolume = $this->extractedData['volume_ml'];
        
        if (!$searchVolume) {
            return 0;
        }
        
        // Extraire le volume du produit
        $productVolume = $this->extractVolumeFromProduct($product);
        
        if (!$productVolume) {
            return 0;
        }
        
        // Correspondance exacte
        if ($searchVolume == $productVolume) {
            return 1.0;
        }
        
        // Correspondance approximative
        $difference = abs($searchVolume - $productVolume);
        $percentageDiff = ($difference / $searchVolume) * 100;
        
        if ($percentageDiff <= 10) {
            return 0.8;
        } elseif ($percentageDiff <= 25) {
            return 0.5;
        }
        
        return 0.1;
    }

    /**
     * Extrait le volume d'un produit
     */
    private function extractVolumeFromProduct($product): ?int
    {
        $textToSearch = implode(' ', [
            $product->name ?? '',
            $product->variation ?? ''
        ]);
        
        return $this->extractVolumeFromText($textToSearch);
    }

    /**
     * Extrait un volume d'un texte
     */
    private function extractVolumeFromText(string $text): ?int
    {
        if (preg_match('/(\d+)\s*ml/i', $text, $matches)) {
            return (int)$matches[1];
        }
        
        return null;
    }

    /**
     * Calcule le bonus pour les correspondances exactes
     */
    private function calculateExactMatchBonus($product): float
    {
        $bonus = 0;
        
        // Bonus pour correspondance exacte du vendor
        $searchVendor = $this->extractedData['vendor'] ?? '';
        $productVendor = $product->vendor ?? '';
        
        if (!empty($searchVendor) && !empty($productVendor) &&
            strcasecmp(trim($searchVendor), trim($productVendor)) === 0) {
            $bonus += 0.20;  // Augmenté
        }
        
        // Bonus pour correspondance exacte du volume
        $searchVolume = $this->extractedData['volume_ml'];
        $productVolume = $this->extractVolumeFromProduct($product);
        
        if ($searchVolume && $productVolume && $searchVolume == $productVolume) {
            $bonus += 0.25;  // Augmenté
        }
        
        // Bonus pour correspondance exacte du type
        $searchType = $this->extractedData['type'] ?? '';
        $productType = $product->type ?? '';
        
        if (!empty($searchType) && !empty($productType) &&
            strcasecmp(trim($searchType), trim($productType)) === 0) {
            $bonus += 0.15;  // Augmenté
        }
        
        // Bonus pour correspondance exacte du nom
        $searchName = $this->extractedData['name'] ?? '';
        $productName = $product->name ?? '';
        
        if (!empty($searchName) && !empty($productName) &&
            strcasecmp(trim($searchName), trim($productName)) === 0) {
            $bonus += 0.20;  // Nouveau bonus
        }
        
        return $bonus;
    }

    /**
     * Obtient les détails de la correspondance
     */
    private function getMatchDetails($product): array
    {
        $details = [];
        
        $searchVendor = $this->extractedData['vendor'] ?? '';
        $productVendor = $product->vendor ?? '';
        if (!empty($searchVendor) && !empty($productVendor)) {
            similar_text(
                mb_strtolower($searchVendor),
                mb_strtolower($productVendor),
                $vendorPercent
            );
            $details['vendor_similarity'] = $vendorPercent;
            $details['vendor_match'] = strcasecmp(trim($searchVendor), trim($productVendor)) === 0;
        }
        
        $searchName = $this->extractedData['name'] ?? '';
        $productName = $product->name ?? '';
        if (!empty($searchName) && !empty($productName)) {
            similar_text(
                mb_strtolower($searchName),
                mb_strtolower($productName),
                $namePercent
            );
            $details['name_similarity'] = $namePercent;
            $details['name_match'] = strcasecmp(trim($searchName), trim($productName)) === 0;
        }
        
        // Volume matching
        $searchVolume = $this->extractedData['volume_ml'];
        $productVolume = $this->extractVolumeFromProduct($product);
        
        if ($searchVolume && $productVolume) {
            $details['volume_match'] = $searchVolume == $productVolume;
            $details['search_volume'] = $searchVolume;
            $details['product_volume'] = $productVolume;
            $details['volume_difference'] = abs($searchVolume - $productVolume);
        }
        
        // Type matching
        $searchType = $this->extractedData['type'] ?? '';
        $productType = $product->type ?? '';
        if (!empty($searchType) && !empty($productType)) {
            $details['type_match'] = strcasecmp(trim($searchType), trim($productType)) === 0;
            $details['type_similarity'] = $this->computeStringSimilarity($searchType, $productType);
        }
        
        return $details;
    }

    /**
     * Similarité de chaîne (algorithme de Jaro-Winkler amélioré)
     */
    private function computeStringSimilarity($str1, $str2): float
    {
        $str1 = mb_strtolower(trim($str1));
        $str2 = mb_strtolower(trim($str2));

        if (empty($str1) || empty($str2)) {
            return 0;
        }

        if ($str1 === $str2) {
            return 1.0;
        }

        $len1 = mb_strlen($str1);
        $len2 = mb_strlen($str2);

        $matchDistance = (int) floor(max($len1, $len2) / 2) - 1;
        $matches1 = array_fill(0, $len1, false);
        $matches2 = array_fill(0, $len2, false);

        $matches = 0;
        $transpositions = 0;

        for ($i = 0; $i < $len1; $i++) {
            $start = max(0, $i - $matchDistance);
            $end = min($i + $matchDistance + 1, $len2);

            for ($j = $start; $j < $end; $j++) {
                if (!$matches2[$j] && mb_substr($str1, $i, 1) === mb_substr($str2, $j, 1)) {
                    $matches1[$i] = true;
                    $matches2[$j] = true;
                    $matches++;
                    break;
                }
            }
        }

        if ($matches === 0) {
            return 0;
        }

        $k = 0;
        for ($i = 0; $i < $len1; $i++) {
            if ($matches1[$i]) {
                while (!$matches2[$k]) {
                    $k++;
                }
                if (mb_substr($str1, $i, 1) !== mb_substr($str2, $k, 1)) {
                    $transpositions++;
                }
                $k++;
            }
        }

        $transpositions = $transpositions / 2;
        $jaro = (($matches / $len1) + ($matches / $len2) + (($matches - $transpositions) / $matches)) / 3;

        $prefix = 0;
        $maxPrefix = min(4, min($len1, $len2));

        for ($i = 0; $i < $maxPrefix; $i++) {
            if (mb_substr($str1, $i, 1) === mb_substr($str2, $i, 1)) {
                $prefix++;
            } else {
                break;
            }
        }

        return $jaro + ($prefix * 0.1 * (1 - $jaro));
    }

    /**
     * Détermine le niveau de correspondance
     */
    private function getMatchLevel($similarityScore)
    {
        if ($similarityScore >= 0.9) return 'excellent';
        if ($similarityScore >= 0.7) return 'bon';
        if ($similarityScore >= 0.6) return 'moyen';
        return 'faible';
    }

    // Les autres méthodes restent identiques...

    /**
     * Extrait les volumes de la recherche
     */
    private function extractSearchVolumes(string $search): void
    {
        $this->searchVolumes = [];

        if (preg_match_all('/(\d+)\s*ml/i', $search, $matches)) {
            $this->searchVolumes = $matches[1];
        }
    }

    /**
     * Extrait les mots clés de la variation
     */
    private function extractSearchVariationKeywords(string $search): void
    {
        $this->searchVariationKeywords = [];

        $pattern = '/^[^-]+\s*-\s*[^-]+\s*-\s*/i';
        $variation = preg_replace($pattern, '', $search);

        $variationClean = preg_replace('/[^a-zA-ZÀ-ÿ0-9\s]/', ' ', $variation);
        $variationClean = trim(preg_replace('/\s+/', ' ', $variationClean));
        $variationClean = mb_strtolower($variationClean);

        $words = explode(" ", $variationClean);

        $stopWords = [
            'de', 'le', 'la', 'les', 'un', 'une', 'des', 'du', 'et', 'ou',
            'pour', 'avec', 'the', 'a', 'an', 'and', 'or', 'ml', 'edition', 'édition'
        ];

        foreach ($words as $word) {
            $word = trim($word);
            if ((strlen($word) > 1 && !in_array($word, $stopWords)) || is_numeric($word)) {
                $this->searchVariationKeywords[] = $word;
            }
        }
    }

    /**
     * Formate le prix
     */
    public function formatPrice($price)
    {
        $cleanPrice = $this->cleanPrice($price);
        if ($cleanPrice !== null) {
            return number_format($cleanPrice, 2, ',', ' ') . ' €';
        }
        return 'N/A';
    }

    /**
     * Extrait le domaine d'une URL
     */
    public function extractDomain($url)
    {
        if (empty($url)) return 'N/A';
        
        try {
            $parsedUrl = parse_url($url);
            if (isset($parsedUrl['host'])) {
                $domain = $parsedUrl['host'];
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
     * Ajuste le seuil de similarité
     */
    public function adjustSimilarityThreshold($threshold)
    {
        $this->similarityThreshold = $threshold;
        if (!empty($this->searchQuery)) {
            $this->getCompetitorPrice($this->searchQuery);
        }
    }

    /**
     * Calcule la différence de prix
     */
    public function calculatePriceDifference($competitorPrice)
    {
        $cleanCompetitorPrice = $this->cleanPrice($competitorPrice);
        $cleanReferencePrice = $this->cleanPrice($this->referencePrice);
        
        if ($cleanCompetitorPrice === null || $cleanReferencePrice === null) {
            return null;
        }

        return $cleanReferencePrice - $cleanCompetitorPrice;
    }

    /**
     * Calcule le pourcentage de différence
     */
    public function calculatePriceDifferencePercentage($competitorPrice)
    {
        $cleanCompetitorPrice = $this->cleanPrice($competitorPrice);
        $cleanReferencePrice = $this->cleanPrice($this->referencePrice);
        
        if ($cleanCompetitorPrice === null || $cleanReferencePrice === null || $cleanCompetitorPrice == 0) {
            return null;
        }

        return (($cleanReferencePrice - $cleanCompetitorPrice) / $cleanCompetitorPrice) * 100;
    }

    /**
     * Détermine le statut de compétitivité
     */
    public function getPriceCompetitiveness($competitorPrice)
    {
        $difference = $this->calculatePriceDifference($competitorPrice);

        if ($difference === null) {
            return 'unknown';
        }

        if ($difference > 10) {
            return 'higher';
        } elseif ($difference > 0) {
            return 'slightly_higher';
        } elseif ($difference == 0) {
            return 'same';
        } elseif ($difference >= -10) {
            return 'competitive';
        } else {
            return 'very_competitive';
        }
    }

    /**
     * Retourne le libellé pour le statut de prix
     */
    public function getPriceStatusLabel($competitorPrice)
    {
        $status = $this->getPriceCompetitiveness($competitorPrice);

        $labels = [
            'very_competitive' => 'Nous sommes beaucoup moins cher',
            'competitive' => 'Nous sommes moins cher',
            'same' => 'Prix identique',
            'slightly_higher' => 'Nous sommes légèrement plus cher',
            'higher' => 'Nous sommes beaucoup plus cher',
            'unknown' => 'Non comparable'
        ];

        return $labels[$status] ?? $labels['unknown'];
    }

    /**
     * Retourne la classe CSS pour le statut de prix
     */
    public function getPriceStatusClass($competitorPrice)
    {
        $status = $this->getPriceCompetitiveness($competitorPrice);

        $classes = [
            'very_competitive' => 'bg-green-100 text-green-800 border-green-300',
            'competitive' => 'bg-emerald-100 text-emerald-800 border-emerald-300',
            'same' => 'bg-blue-100 text-blue-800 border-blue-300',
            'slightly_higher' => 'bg-yellow-100 text-yellow-800 border-yellow-300',
            'higher' => 'bg-red-100 text-red-800 border-red-300',
            'unknown' => 'bg-gray-100 text-gray-800 border-gray-300'
        ];

        return $classes[$status] ?? $classes['unknown'];
    }

    /**
     * Calcule la différence de prix Cosmashop
     */
    public function calculateCosmashopPriceDifference($competitorPrice)
    {
        $cleanCompetitorPrice = $this->cleanPrice($competitorPrice);
        $cleanCosmashopPrice = $this->cleanPrice($this->cosmashopPrice);
        
        if ($cleanCompetitorPrice === null || $cleanCosmashopPrice === null) {
            return null;
        }

        return $cleanCosmashopPrice - $cleanCompetitorPrice;
    }

    /**
     * Calcule le pourcentage de différence Cosmashop
     */
    public function calculateCosmashopPriceDifferencePercentage($competitorPrice)
    {
        $cleanCompetitorPrice = $this->cleanPrice($competitorPrice);
        $cleanCosmashopPrice = $this->cleanPrice($this->cosmashopPrice);
        
        if ($cleanCompetitorPrice === null || $cleanCosmashopPrice === null || $cleanCompetitorPrice == 0) {
            return null;
        }

        return (($cleanCosmashopPrice - $cleanCompetitorPrice) / $cleanCompetitorPrice) * 100;
    }

    /**
     * Détermine le statut de compétitivité de Cosmashop
     */
    public function getCosmashopPriceCompetitiveness($competitorPrice)
    {
        $difference = $this->calculateCosmashopPriceDifference($competitorPrice);

        if ($difference === null) {
            return 'unknown';
        }

        if ($difference > 10) {
            return 'higher';
        } elseif ($difference > 0) {
            return 'slightly_higher';
        } elseif ($difference == 0) {
            return 'same';
        } elseif ($difference >= -10) {
            return 'competitive';
        } else {
            return 'very_competitive';
        }
    }

    /**
     * Retourne le libellé pour le statut Cosmashop
     */
    public function getCosmashopPriceStatusLabel($competitorPrice)
    {
        $status = $this->getCosmashopPriceCompetitiveness($competitorPrice);

        $labels = [
            'very_competitive' => 'Cosmashop serait beaucoup moins cher',
            'competitive' => 'Cosmashop serait moins cher',
            'same' => 'Prix identique à Cosmashop',
            'slightly_higher' => 'Cosmashop serait légèrement plus cher',
            'higher' => 'Cosmashop serait beaucoup plus cher',
            'unknown' => 'Non comparable'
        ];

        return $labels[$status] ?? $labels['unknown'];
    }

    /**
     * Retourne la classe CSS pour le statut Cosmashop
     */
    public function getCosmashopPriceStatusClass($competitorPrice)
    {
        $status = $this->getCosmashopPriceCompetitiveness($competitorPrice);

        $classes = [
            'very_competitive' => 'bg-green-100 text-green-800 border-green-300',
            'competitive' => 'bg-emerald-100 text-emerald-800 border-emerald-300',
            'same' => 'bg-blue-100 text-blue-800 border-blue-300',
            'slightly_higher' => 'bg-yellow-100 text-yellow-800 border-yellow-300',
            'higher' => 'bg-red-100 text-red-800 border-red-300',
            'unknown' => 'bg-gray-100 text-gray-800 border-gray-300'
        ];

        return $classes[$status] ?? $classes['unknown'];
    }

    /**
     * Formate la différence de prix
     */
    public function formatPriceDifference($difference)
    {
        if ($difference === null) {
            return 'N/A';
        }

        if ($difference == 0) {
            return '0 €';
        }

        $formatted = number_format(abs($difference), 2, ',', ' ');
        return $difference > 0 ? "+{$formatted} €" : "-{$formatted} €";
    }

    /**
     * Formate le pourcentage de différence
     */
    public function formatPercentageDifference($percentage)
    {
        if ($percentage === null) {
            return 'N/A';
        }

        if ($percentage == 0) {
            return '0%';
        }

        $formatted = number_format(abs($percentage), 1, ',', ' ');
        return $percentage > 0 ? "+{$formatted}%" : "-{$formatted}%";
    }

    /**
     * Analyse globale des prix
     */
    public function getPriceAnalysis()
    {
        $prices = [];

        foreach ($this->matchedProducts as $product) {
            $price = $product->price_ht ?? $product->prix_ht;
            $cleanPrice = $this->cleanPrice($price);
            
            if ($cleanPrice !== null) {
                $prices[] = $cleanPrice;
            }
        }

        if (empty($prices)) {
            return null;
        }

        $minPrice = min($prices);
        $maxPrice = max($prices);
        $avgPrice = array_sum($prices) / count($prices);
        $ourPrice = $this->cleanPrice($this->referencePrice);

        return [
            'min' => $minPrice,
            'max' => $maxPrice,
            'average' => $avgPrice,
            'our_price' => $ourPrice,
            'count' => count($prices),
            'our_position' => $ourPrice <= $avgPrice ? 'competitive' : 'above_average'
        ];
    }

    /**
     * Analyse globale pour Cosmashop
     */
    public function getCosmashopPriceAnalysis()
    {
        $prices = [];

        foreach ($this->matchedProducts as $product) {
            $price = $product->price_ht ?? $product->prix_ht;
            $cleanPrice = $this->cleanPrice($price);
            
            if ($cleanPrice !== null) {
                $prices[] = $cleanPrice;
            }
        }

        if (empty($prices)) {
            return null;
        }

        $minPrice = min($prices);
        $maxPrice = max($prices);
        $avgPrice = array_sum($prices) / count($prices);
        $cosmashopPrice = $this->cleanPrice($this->cosmashopPrice);

        // Compter les concurrents en dessous/au-dessus de Cosmashop
        $belowCosmashop = 0;
        $aboveCosmashop = 0;

        foreach ($prices as $price) {
            if ($price < $cosmashopPrice) {
                $belowCosmashop++;
            } else {
                $aboveCosmashop++;
            }
        }

        return [
            'min' => $minPrice,
            'max' => $maxPrice,
            'average' => $avgPrice,
            'cosmashop_price' => $cosmashopPrice,
            'count' => count($prices),
            'below_cosmashop' => $belowCosmashop,
            'above_cosmashop' => $aboveCosmashop,
            'cosmashop_position' => $cosmashopPrice <= $avgPrice ? 'competitive' : 'above_average'
        ];
    }

    /**
     * Formate la variation pour l'affichage
     */
    public function formatVariation($variation)
    {
        if (empty($variation)) {
            return 'Standard';
        }

        return Str::limit($variation, 30);
    }

    /**
     * Extrait les volumes d'un texte
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
     * Vérifie si le produit correspond parfaitement
     */
    public function isPerfectMatch($product)
    {
        $hasMatchingVolume = $this->hasMatchingVolume($product);
        $hasMatchingVariationKeyword = $this->hasMatchingVariationKeyword($product);

        return $hasMatchingVolume && $hasMatchingVariationKeyword;
    }

    /**
     * Extrait la variation de la recherche complète
     */
    private function extractSearchVariation()
    {
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

        $normalized = mb_strtolower(trim($variation));
        $normalized = preg_replace('/[^a-zA-ZÀ-ÿ0-9\s]/', ' ', $normalized);
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized));

        return $normalized;
    }

    /**
     * Vérifie si le produit a exactement la même variation que la recherche
     */
    public function hasExactVariationMatch($product)
    {
        $searchVariation = $this->extractSearchVariation();
        $productVariation = $product->variation ?? '';

        $searchNormalized = $this->normalizeVariation($searchVariation);
        $productNormalized = $this->normalizeVariation($productVariation);

        return $searchNormalized === $productNormalized;
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
     * Met en évidence les termes correspondants
     */
    public function highlightMatchingTerms($text)
    {
        if (empty($text)) {
            return $text;
        }

        $patterns = [];

        // Mettre en évidence le volume
        if (!empty($this->extractedData['volume_ml'])) {
            $patterns[] = '\b' . preg_quote($this->extractedData['volume_ml'], '/') . '\s*ml\b';
        }

        // Mettre en évidence le vendor
        if (!empty($this->extractedData['vendor'])) {
            $vendorPatterns = $this->extractSignificantKeywords($this->extractedData['vendor']);
            foreach ($vendorPatterns as $pattern) {
                $patterns[] = '\b' . preg_quote($pattern, '/') . '\b';
            }
        }

        // Mettre en évidence le nom
        if (!empty($this->extractedData['name'])) {
            $namePatterns = $this->extractSignificantKeywords($this->extractedData['name']);
            foreach ($namePatterns as $pattern) {
                if (strlen($pattern) > 2) {
                    $patterns[] = '\b' . preg_quote($pattern, '/') . '\b';
                }
            }
        }

        // Mettre en évidence le type
        if (!empty($this->extractedData['type'])) {
            $typePatterns = $this->extractTypeKeywords($this->extractedData['type']);
            foreach ($typePatterns as $pattern) {
                $patterns[] = '\b' . preg_quote($pattern, '/') . '\b';
            }
        }

        if (empty($patterns)) {
            return $text;
        }

        $pattern = '/(' . implode('|', $patterns) . ')/iu';

        $text = preg_replace_callback($pattern, function ($matches) {
            return '<span class="bg-yellow-100 text-yellow-800 font-semibold px-1 py-0.5 rounded">'
                . $matches[0]
                . '</span>';
        }, $text);

        return $text;
    }

    /**
     * Vérifie si un produit correspond aux critères extraits
     */
    public function isProductMatchingExtractedData($product): bool
    {
        $matchScore = 0;
        $requiredScore = 0.7;
        
        // Vérifier le vendor (plus strict)
        if (!empty($this->extractedData['vendor']) && !empty($product->vendor)) {
            if (strcasecmp(trim($this->extractedData['vendor']), trim($product->vendor)) === 0) {
                $matchScore += 0.4;
            } elseif (stripos($product->vendor, $this->extractedData['vendor']) !== false) {
                $matchScore += 0.2;
            }
        }
        
        // Vérifier le volume (prioritaire)
        $searchVolume = $this->extractedData['volume_ml'];
        $productVolume = $this->extractVolumeFromProduct($product);
        
        if ($searchVolume && $productVolume && $searchVolume == $productVolume) {
            $matchScore += 0.4;
        }
        
        // Vérifier le type
        if (!empty($this->extractedData['type']) && !empty($product->type)) {
            if (strcasecmp(trim($this->extractedData['type']), trim($product->type)) === 0) {
                $matchScore += 0.2;
            }
        }
        
        return $matchScore >= $requiredScore;
    }
}; ?>

<div>
    <livewire:plateformes.detail :id="$id"/>

    <!-- Section d'analyse des prix -->
    @if($hasData && $referencePrice)
        @php
    $priceAnalysis = $this->getPriceAnalysis();
    $cosmashopAnalysis = $this->getCosmashopPriceAnalysis();
        @endphp
        @if($priceAnalysis && $cosmashopAnalysis)
            <div class="mx-auto w-full px-4 py-4 sm:px-6 lg:px-8">
                <!-- Analyse Cosmaparfumerie -->
                <div class="bg-gradient-to-r from-purple-50 to-indigo-50 rounded-lg border border-purple-200 p-4 mb-4">
                    <h4 class="text-lg font-semibold text-purple-800 mb-3 flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                        Analyse Cosmaparfumerie
                    </h4>
                    
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-3">
                        <div class="text-center p-3 bg-white rounded-lg shadow-sm">
                            <div class="text-2xl font-bold text-green-600">{{ $this->formatPrice($priceAnalysis['min']) }}</div>
                            <div class="text-xs text-gray-600">Prix minimum concurrent</div>
                        </div>
                        <div class="text-center p-3 bg-white rounded-lg shadow-sm">
                            <div class="text-2xl font-bold text-red-600">{{ $this->formatPrice($priceAnalysis['max']) }}</div>
                            <div class="text-xs text-gray-600">Prix maximum concurrent</div>
                        </div>
                        <div class="text-center p-3 bg-white rounded-lg shadow-sm">
                            <div class="text-2xl font-bold text-blue-600">{{ $this->formatPrice($priceAnalysis['average']) }}</div>
                            <div class="text-xs text-gray-600">Prix moyen concurrent</div>
                        </div>
                        <div class="text-center p-3 bg-white rounded-lg shadow-sm border-2 
                            {{ $priceAnalysis['our_position'] === 'competitive' ? 'border-green-300' : 'border-yellow-300' }}">
                            <div class="text-2xl font-bold text-purple-600">{{ $this->formatPrice($priceAnalysis['our_price']) }}</div>
                            <div class="text-xs {{ $priceAnalysis['our_position'] === 'competitive' ? 'text-green-600' : 'text-yellow-600' }} font-semibold">
                                Notre prix actuel
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-sm text-purple-700">
                        <strong>Analyse :</strong> 
                        Notre prix est 
                        <span class="font-semibold {{ $priceAnalysis['our_position'] === 'competitive' ? 'text-green-600' : 'text-yellow-600' }}">
                            {{ $priceAnalysis['our_position'] === 'competitive' ? 'compétitif' : 'au-dessus de la moyenne' }}
                        </span>
                        par rapport aux {{ $priceAnalysis['count'] }} concurrents analysés.
                        @if($priceAnalysis['our_price'] > $priceAnalysis['average'])
                            <span class="text-yellow-600">({{ $this->formatPriceDifference($priceAnalysis['our_price'] - $priceAnalysis['average']) }} par rapport à la moyenne)</span>
                        @endif
                    </div>
                </div>

                <!-- Analyse Cosmashop -->
                <div class="bg-gradient-to-r from-orange-50 to-amber-50 rounded-lg border border-orange-200 p-4">
                    <h4 class="text-lg font-semibold text-orange-800 mb-3 flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Analyse Cosmashop (Prix +5%)
                    </h4>
                    
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-3">
                        <div class="text-center p-3 bg-white rounded-lg shadow-sm">
                            <div class="text-2xl font-bold text-green-600">{{ $this->formatPrice($cosmashopAnalysis['min']) }}</div>
                            <div class="text-xs text-gray-600">Prix minimum concurrent</div>
                        </div>
                        <div class="text-center p-3 bg-white rounded-lg shadow-sm">
                            <div class="text-2xl font-bold text-red-600">{{ $this->formatPrice($cosmashopAnalysis['max']) }}</div>
                            <div class="text-xs text-gray-600">Prix maximum concurrent</div>
                        </div>
                        <div class="text-center p-3 bg-white rounded-lg shadow-sm">
                            <div class="text-2xl font-bold text-blue-600">{{ $this->formatPrice($cosmashopAnalysis['average']) }}</div>
                            <div class="text-xs text-gray-600">Prix moyen concurrent</div>
                        </div>
                        <div class="text-center p-3 bg-white rounded-lg shadow-sm border-2 border-orange-300">
                            <div class="text-2xl font-bold text-orange-600">{{ $this->formatPrice($cosmashopAnalysis['cosmashop_price']) }}</div>
                            <div class="text-xs text-orange-600 font-semibold">
                                Prix Cosmashop
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-sm text-orange-700">
                        <strong>Analyse :</strong> 
                        Le prix Cosmashop serait 
                        <span class="font-semibold {{ $cosmashopAnalysis['cosmashop_position'] === 'competitive' ? 'text-green-600' : 'text-yellow-600' }}">
                            {{ $cosmashopAnalysis['cosmashop_position'] === 'competitive' ? 'compétitif' : 'au-dessus de la moyenne' }}
                        </span>.
                        <span class="font-semibold text-green-600">{{ $cosmashopAnalysis['below_cosmashop'] }} concurrent(s)</span> en dessous et 
                        <span class="font-semibold text-red-600">{{ $cosmashopAnalysis['above_cosmashop'] }} concurrent(s)</span> au-dessus.
                    </div>
                </div>
            </div>
        @endif
    @endif

    <!-- Section des résultats -->
    <div class="mx-auto w-full px-4 py-6 sm:px-6 lg:px-8">
        @if($hasData)
                <!-- Indicateur de similarité -->
                <div class="mb-4 p-4 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg border">
                    <div class="flex flex-col space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <svg class="w-5 h-5 text-blue-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                                </svg>
                                <span class="text-sm font-medium text-blue-800">
                                    Algorithme de similarité activé - 
                                    {{ count($matchedProducts) }} produit(s) correspondant(s) au seuil de {{ $similarityThreshold * 100 }}%
                                </span>
                            </div>
                            <div class="flex items-center space-x-2">
                                <span class="text-xs text-blue-600 font-semibold">Ajuster le seuil :</span>
                                <button wire:click="adjustSimilarityThreshold(0.5)" 
                                        class="px-2 py-1 text-xs {{ $similarityThreshold == 0.5 ? 'bg-blue-600 text-white' : 'bg-blue-100 text-blue-800' }} rounded transition-colors">
                                    50%
                                </button>
                                <button wire:click="adjustSimilarityThreshold(0.6)" 
                                        class="px-2 py-1 text-xs {{ $similarityThreshold == 0.6 ? 'bg-blue-600 text-white' : 'bg-blue-100 text-blue-800' }} rounded transition-colors">
                                    60%
                                </button>
                                <button wire:click="adjustSimilarityThreshold(0.7)" 
                                        class="px-2 py-1 text-xs {{ $similarityThreshold == 0.7 ? 'bg-blue-600 text-white' : 'bg-blue-100 text-blue-800' }} rounded transition-colors">
                                    70%
                                </button>
                            </div>
                        </div>

                        <div class="flex items-center space-x-4 text-xs text-blue-600">
                            <span class="font-semibold">Légende :</span>
                            <span class="flex items-center">
                                <span class="w-3 h-3 bg-green-500 rounded-full mr-1"></span>
                                Excellent (90-100%)
                            </span>
                            <span class="flex items-center">
                                <span class="w-3 h-3 bg-blue-500 rounded-full mr-1"></span>
                                Bon (70-89%)
                            </span>
                            <span class="flex items-center">
                                <span class="w-3 h-3 bg-yellow-500 rounded-full mr-1"></span>
                                Moyen (60-69%)
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Critères de recherche -->
                @if(!empty($searchVolumes) || !empty($searchVariationKeywords))
                    <div class="mb-4 p-4 bg-blue-50 rounded-lg">
                        <div class="flex flex-col space-y-2">
                            <div class="flex items-center">
                                <svg class="w-5 h-5 text-blue-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <span class="text-sm font-medium text-blue-800">Critères de recherche détectés :</span>
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
                                @php
            $searchVariation = $this->extractSearchVariation();
                                @endphp
                                @if($searchVariation)
                                    <div class="flex items-center">
                                        <span class="text-xs text-blue-700 mr-1">Variation :</span>
                                        <span class="bg-blue-100 text-blue-800 font-semibold px-2 py-1 rounded text-xs">{{ $searchVariation }}</span>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif

                <!-- Tableau des résultats -->
                <div class="bg-white shadow-sm rounded-lg overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">Résultats de la recherche</h3>
                        <p class="mt-1 text-sm text-gray-500">{{ count($matchedProducts) }} produit(s) correspondant(s)</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Score</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Correspondance</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Image</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nom</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Variation</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Site Source</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Prix HT</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date MAJ Prix</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vs Cosmaparfumerie</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vs Cosmashop</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach($matchedProducts as $product)
                                                            @php
                                    $matchClass = [
                                        'excellent' => 'bg-green-100 text-green-800 border-green-300',
                                        'bon' => 'bg-blue-100 text-blue-800 border-blue-300',
                                        'moyen' => 'bg-yellow-100 text-yellow-800 border-yellow-300',
                                        'faible' => 'bg-gray-100 text-gray-800 border-gray-300'
                                    ][$product->match_level ?? 'faible'];

                                    $productVolumes = $this->extractVolumesFromText($product->name . ' ' . $product->variation);
                                    $hasMatchingVolume = $this->hasMatchingVolume($product);
                                    $hasExactVariation = $this->hasExactVariationMatch($product);

                                    // Données pour la comparaison de prix
                                    $competitorPrice = $product->price_ht ?? $product->prix_ht;
                                    $priceDifference = $this->calculatePriceDifference($competitorPrice);
                                    $priceDifferencePercent = $this->calculatePriceDifferencePercentage($competitorPrice);
                                    $priceStatusClass = $this->getPriceStatusClass($competitorPrice);
                                    $priceStatusLabel = $this->getPriceStatusLabel($competitorPrice);

                                    // Données pour Cosmashop
                                    $cosmashopDifference = $this->calculateCosmashopPriceDifference($competitorPrice);
                                    $cosmashopDifferencePercent = $this->calculateCosmashopPriceDifferencePercentage($competitorPrice);
                                    $cosmashopStatusClass = $this->getCosmashopPriceStatusClass($competitorPrice);
                                    $cosmashopStatusLabel = $this->getCosmashopPriceStatusLabel($competitorPrice);
                                                            @endphp
                                                            <tr class="hover:bg-gray-50 transition-colors duration-150">
                                                                <!-- Colonne Score -->
                                                                <td class="px-6 py-4 whitespace-nowrap">
                                                                    <div class="flex items-center">
                                                                        <div class="w-16 bg-gray-200 rounded-full h-2 mr-3">
                                                                            <div class="h-2 rounded-full 
                                                                                @if(($product->similarity_score ?? 0) >= 0.9) bg-green-500
                                                                                @elseif(($product->similarity_score ?? 0) >= 0.7) bg-blue-500
                                                                                @elseif(($product->similarity_score ?? 0) >= 0.6) bg-yellow-500
                                                                                @else bg-gray-500 @endif"
                                                                                style="width: {{ ($product->similarity_score ?? 0) * 100 }}%">
                                                                            </div>
                                                                        </div>
                                                                        <span class="text-sm font-mono font-semibold 
                                                                            @if(($product->similarity_score ?? 0) >= 0.9) text-green-600
                                                                            @elseif(($product->similarity_score ?? 0) >= 0.7) text-blue-600
                                                                            @elseif(($product->similarity_score ?? 0) >= 0.6) text-yellow-600
                                                                            @else text-gray-600 @endif">
                                                                            {{ number_format(($product->similarity_score ?? 0) * 100, 0) }}%
                                                                        </span>
                                                                    </div>
                                                                </td>

                                                                <!-- Colonne Correspondance -->
                                                                <td class="px-6 py-4 whitespace-nowrap">
                                                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium border {{ $matchClass }}">
                                                                        @if($product->match_level === 'excellent')
                                                                            <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                                                            </svg>
                                                                        @endif
                                                                        {{ ucfirst($product->match_level) }}
                                                                    </span>
                                                                </td>

                                                                <!-- Colonne Image - TAILLE AUGMENTÉE -->
                                                                <td class="px-6 py-4 whitespace-nowrap">
                                                                    @if(!empty($product->image))
                                                                        <img src="{{ $product->image }}" 
                                                                             alt="{{ $product->name ?? 'Produit' }}" 
                                                                             class="h-20 w-20 object-cover rounded-lg shadow-md border border-gray-200"
                                                                             onerror="this.src='https://placehold.co/400'">
                                                                    @else
                                                                        <div class="h-20 w-20 bg-gray-100 rounded-lg flex items-center justify-center shadow-md border border-gray-200">
                                                                            <span class="text-xs text-gray-500 text-center px-1">No Image</span>
                                                                        </div>
                                                                    @endif
                                                                </td>

                                                                <!-- Colonne Nom -->
                                                                <td class="px-6 py-4">
                                                                    <div class="text-sm font-medium text-gray-900 max-w-xs" title="{{ $product->name ?? 'N/A' }}">
                                                                        {!! $this->highlightMatchingTerms($product->name) !!}
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
                                                                            {{-- <div class="text-xs text-gray-500 truncate max-w-xs" title="{{ $product->product_url ?? 'N/A' }}">
                                                                                {{ Str::limit($product->product_url, 40) }}
                                                                            </div> --}}
                                                                        </div>
                                                                    </div>
                                                                </td>

                                                                <!-- Colonne Prix HT -->
                                                                <td class="px-6 py-4 whitespace-nowrap">
                                                                    <div class="text-sm font-semibold text-green-600">
                                                                        {{-- {{ $this->formatPrice($product->price_ht ?? $product->prix_ht) }} --}}
                                                                        {{ $this->formatPrice($product->price_ht ?? $product->prix_ht) }}
                                                                    </div>
                                                                </td>

                                                                                                                                <!-- Colonne Prix HT -->
                                                                <td class="px-6 py-4 whitespace-nowrap">
                                                                    <div class="text-xs text-gray-400">
                                                                        {{ \Carbon\Carbon::parse($product->updated_at)->translatedFormat('j F Y') }}
                                                                    </div>
                                                                </td>

                                                                <!-- Colonne Vs Cosmaparfumerie -->
                                                                <td class="px-6 py-4 whitespace-nowrap">
                                                                    @if(is_numeric($competitorPrice) && is_numeric($referencePrice))
                                                                        <div class="space-y-1">
                                                                            <div class="text-xs text-gray-500">
                                                                                prix cosma-parfumerie: {{ number_format($price, 2, ',', ' ') }} €
                                                                            </div>
                                                                            <!-- Statut -->
                                                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $priceStatusClass }}">
                                                                                {{ $priceStatusLabel }}
                                                                            </span>

                                                                            <!-- Différence -->
                                                                            <div class="text-xs font-semibold 
                                                                                {{ $priceDifference > 0 ? 'text-green-600' : ($priceDifference < 0 ? 'text-red-600' : 'text-blue-600') }}">
                                                                                {{ $this->formatPriceDifference($priceDifference) }}
                                                                            </div>

                                                                            <!-- Pourcentage -->
                                                                            @if($priceDifferencePercent !== null && $priceDifference != 0)
                                                                                <div class="text-xs text-gray-500">
                                                                                    {{ $this->formatPercentageDifference($priceDifferencePercent) }}
                                                                                </div>
                                                                            @endif
                                                                        </div>
                                                                    @else
                                                                        <span class="text-xs text-gray-400">N/A</span>
                                                                    @endif
                                                                </td>

                                                                <!-- Colonne Vs Cosmashop -->
                                                                <td class="px-6 py-4 whitespace-nowrap">
                                                                    @if(is_numeric($competitorPrice) && is_numeric($cosmashopPrice))
                                                                        <div class="space-y-1">
                                                                            <div class="text-xs text-gray-500">
                                                                                prix cosmashop: {{ number_format($cosmashopPrice, 2, ',', ' ') }} €
                                                                            </div>
                                                                            <!-- Statut Cosmashop -->
                                                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $cosmashopStatusClass }}">
                                                                                {{ $cosmashopStatusLabel }}
                                                                            </span>

                                                                            <!-- Différence Cosmashop -->
                                                                            <div class="text-xs font-semibold 
                                                                                {{ $cosmashopDifference > 0 ? 'text-green-600' : ($cosmashopDifference < 0 ? 'text-red-600' : 'text-blue-600') }}">
                                                                                {{ $this->formatPriceDifference($cosmashopDifference) }}
                                                                            </div>

                                                                            <!-- Pourcentage Cosmashop -->
                                                                            @if($cosmashopDifferencePercent !== null && $cosmashopDifference != 0)
                                                                                <div class="text-xs text-gray-500">
                                                                                    {{ $this->formatPercentageDifference($cosmashopDifferencePercent) }}
                                                                                </div>
                                                                            @endif
                                                                        </div>
                                                                    @else
                                                                        <span class="text-xs text-gray-400">N/A</span>
                                                                    @endif
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
                                                                            {{-- <button wire:click="viewProduct('{{ $product->product_url }}')" 
                                                                                    class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-full shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors duration-200">
                                                                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                                                                </svg>
                                                                                Voir
                                                                            </button> --}}
                                                                            <a href="{{ $product->product_url }}" target="_blank" rel="noopener noreferrer"
                                                                                class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-full shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors duration-200">

                                                                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                                                        d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
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
            <div class="text-center py-12">
                <svg class="mx-auto h-24 w-24 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <h3 class="mt-4 text-lg font-medium text-gray-900">Aucun résultat trouvé</h3>
                <p class="mt-2 text-sm text-gray-500">
                    Aucun produit ne correspond au seuil de similarité de {{ $similarityThreshold * 100 }}%
                </p>
                <div class="mt-4">
                    <button wire:click="adjustSimilarityThreshold(0.5)" 
                            class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        Baisser le seuil à 50%
                    </button>
                </div>
            </div>
        @endif
    </div>
</div>