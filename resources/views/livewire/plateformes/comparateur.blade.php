<?php

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
    public $search;

    public $similarityThreshold = 0.6;
    public $matchedProducts = [];
    public $isLoading = false;
    public $originalProducts = []; // Stocke les produits originaux

    // prix a comparer
    public $price;
    public $referencePrice;
    public $cosmashopPrice;

    public function mount($name, $id, $price)
    {
        $this->search = $name;
        $this->getCompetitorPrice($name);
        $this->id = $id;
        $this->price = $this->cleanPrice($price);
        $this->referencePrice = $this->cleanPrice($price);
        $this->cosmashopPrice = $this->cleanPrice($price) * 1.05;
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

            $this->products = [];
            $this->hasData = false;

            return [
                'error' => $e->getMessage()
            ];
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
                return null;
            }

            $this->extractSearchVolumes($search);
            $this->extractSearchVariationKeywords($search);

            $searchQuery = $this->prepareEnhancedSearchTerms($search);

            if (empty($searchQuery)) {
                $this->products = [];
                $this->hasData = false;
                return null;
            }

            $sql = "SELECT *, 
                           prix_ht,
                           image_url as image,
                           url as product_url
                    FROM last_price_scraped_product 
                    WHERE (
                        MATCH (name, vendor, type, variation) 
                        AGAINST (? IN BOOLEAN MODE)
                        OR name LIKE ?
                        OR vendor LIKE ?
                        OR variation LIKE ?
                    )
                    AND prix_ht IS NOT NULL 
                    AND prix_ht > 0
                    ORDER BY 
                        CASE 
                            WHEN name LIKE ? THEN 1
                            WHEN vendor LIKE ? THEN 2
                            WHEN variation LIKE ? THEN 3
                            ELSE 4
                        END,
                        prix_ht DESC 
                    LIMIT 100";

            $likeTerm = '%' . $this->extractMainProductName($search) . '%';
            
            \Log::info('Enhanced SQL Query:', [
                'original_search' => $search,
                'search_query' => $searchQuery,
                'like_term' => $likeTerm,
                'search_volumes' => $this->searchVolumes,
                'search_variation_keywords' => $this->searchVariationKeywords
            ]);

            $result = DB::connection('mysql')->select($sql, [
                $searchQuery, 
                $likeTerm, 
                $likeTerm, 
                $likeTerm,
                $likeTerm,
                $likeTerm,
                $likeTerm
            ]);

            foreach ($result as $product) {
                if (isset($product->prix_ht)) {
                    $originalPrice = $product->prix_ht;
                    $cleanedPrice = $this->cleanPrice($product->prix_ht);
                    $product->prix_ht = $cleanedPrice;
                }
            }

            \Log::info('Enhanced query result:', [
                'count' => count($result),
                'first_products' => array_slice($result, 0, 3)
            ]);

            $this->matchedProducts = $this->calculateEnhancedSimilarity($result, $search);
            $this->products = $result;
            $this->originalProducts = $result; // Stocker les produits originaux
            $this->hasData = !empty($result);

            return [
                'count' => count($result),
                'has_data' => $this->hasData,
                'products' => $this->matchedProducts,
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
     * Prépare les termes de recherche améliorés avec morcelage optimisé
     */
    private function prepareEnhancedSearchTerms(string $search): string
    {
        // Nettoyage moins agressif - garder les chiffres et tirets
        $searchClean = preg_replace('/[^a-zA-ZÀ-ÿ0-9\s\-]/', ' ', $search);
        $searchClean = trim(preg_replace('/\s+/', ' ', $searchClean));
        $searchClean = mb_strtolower($searchClean);

        \Log::info('Search cleaning process:', [
            'original' => $search,
            'cleaned' => $searchClean
        ]);

        // Extraire les composants structurés
        $components = $this->extractSearchComponents($search);
        
        $booleanTerms = [];
        $significantWords = [];

        \Log::info('Extracted components:', $components);

        // 1. Traiter la marque (priorité haute)
        if (!empty($components['brand'])) {
            $brandWords = $this->splitIntoWords($components['brand']);
            foreach ($brandWords as $brandWord) {
                if (strlen($brandWord) > 1 && !$this->isStopWord($brandWord)) {
                    $booleanTerms[] = '+' . $brandWord . '*';
                    $significantWords[] = $brandWord;
                }
            }
        }

        // 2. Traiter le nom du produit (priorité haute)
        if (!empty($components['product_name'])) {
            $productNameWords = $this->splitIntoWords($components['product_name']);
            foreach ($productNameWords as $productWord) {
                if (strlen($productWord) > 1 && !$this->isStopWord($productWord)) {
                    $booleanTerms[] = '+' . $productWord . '*';
                    $significantWords[] = $productWord;
                }
            }
        }

        // 3. Traiter le type de produit (priorité moyenne)
        if (!empty($components['product_type'])) {
            $productTypeWords = $this->splitIntoWords($components['product_type']);
            foreach ($productTypeWords as $typeWord) {
                if (strlen($typeWord) > 2 && !$this->isStopWord($typeWord)) {
                    $booleanTerms[] = '+' . $typeWord . '*';
                    $significantWords[] = $typeWord;
                }
            }
        }

        // 4. Traiter la variation (priorité basse)
        if (!empty($components['variation'])) {
            $variationWords = $this->splitIntoWords($components['variation']);
            foreach ($variationWords as $variationWord) {
                if (strlen($variationWord) > 2 && !$this->isStopWord($variationWord)) {
                    $booleanTerms[] = $variationWord . '*';
                    $significantWords[] = $variationWord;
                }
            }
        }

        // 5. Ajouter les mots significatifs restants de la recherche complète
        $allWords = $this->splitIntoWords($searchClean);
        $stopWords = $this->getStopWords();

        foreach ($allWords as $word) {
            $word = trim($word);
            
            // Éviter les doublons et les mots non significatifs
            if (strlen($word) > 1 && 
                !in_array($word, $stopWords) && 
                !in_array($word, $significantWords) &&
                !is_numeric($word) &&
                !$this->isStopWord($word)) {
                
                $booleanTerms[] = $word . '*';
                $significantWords[] = $word;
            }

            // Limiter le nombre total de termes
            if (count($booleanTerms) >= 8) {
                break;
            }
        }

        // 6. Ajouter le volume spécifiquement s'il existe
        if (!empty($components['volume'])) {
            $booleanTerms[] = '+' . $components['volume'] . '*';
        }

        $finalQuery = implode(' ', $booleanTerms);

        \Log::info('Final search query construction:', [
            'original_search' => $search,
            'components' => $components,
            'significant_words' => $significantWords,
            'boolean_terms' => $booleanTerms,
            'final_query' => $finalQuery
        ]);

        return $finalQuery;
    }

    /**
     * Divise une chaîne en mots significatifs
     */
    private function splitIntoWords(string $text): array
    {
        if (empty($text)) {
            return [];
        }

        // Séparer par espaces et tirets
        $words = preg_split('/[\s\-]+/', $text);
        
        // Filtrer les mots vides
        $words = array_filter($words, function($word) {
            return !empty(trim($word));
        });

        return array_values($words);
    }

    /**
     * Vérifie si un mot est un stop word
     */
    private function isStopWord(string $word): bool
    {
        $stopWords = $this->getStopWords();
        return in_array($word, $stopWords);
    }

    /**
     * Amélioration de l'extraction des composants
     */
    private function extractSearchComponents(string $search): array
    {
        $components = [
            'brand' => '',
            'product_name' => '',
            'product_type' => '',
            'variation' => '',
            'volume' => ''
        ];

        // Nettoyer la recherche pour l'analyse
        $searchClean = preg_replace('/\s+/', ' ', trim($search));
        
        // Pattern pour le format: Marque - NomProduit - TypeProduit - Variation
        $pattern1 = '/^([^-]+)\s*-\s*([^-]+)\s*-\s*([^-]+)\s*-\s*(.+)$/i';
        
        // Pattern pour: Marque - NomProduit - TypeProduit
        $pattern2 = '/^([^-]+)\s*-\s*([^-]+)\s*-\s*(.+)$/i';
        
        // Pattern pour: Marque - NomProduit
        $pattern3 = '/^([^-]+)\s*-\s*(.+)$/i';

        if (preg_match($pattern1, $searchClean, $matches)) {
            // Format complet: Marque - Nom - Type - Variation
            $components['brand'] = trim($matches[1]);
            $components['product_name'] = trim($matches[2]);
            $components['product_type'] = trim($matches[3]);
            $components['variation'] = trim($matches[4]);
        } elseif (preg_match($pattern2, $searchClean, $matches)) {
            // Format: Marque - Nom - Type
            $components['brand'] = trim($matches[1]);
            $components['product_name'] = trim($matches[2]);
            
            // Le reste peut contenir type + variation
            $rest = trim($matches[3]);
            $restParts = explode(' - ', $rest, 2);
            $components['product_type'] = trim($restParts[0]);
            if (isset($restParts[1])) {
                $components['variation'] = trim($restParts[1]);
            }
        } elseif (preg_match($pattern3, $searchClean, $matches)) {
            // Format: Marque - Nom
            $components['brand'] = trim($matches[1]);
            
            // Le reste peut contenir nom + type + variation
            $rest = trim($matches[2]);
            $restParts = explode(' - ', $rest, 3);
            
            if (count($restParts) >= 1) {
                $components['product_name'] = trim($restParts[0]);
            }
            if (count($restParts) >= 2) {
                $components['product_type'] = trim($restParts[1]);
            }
            if (count($restParts) >= 3) {
                $components['variation'] = trim($restParts[2]);
            }
        } else {
            // Format libre - prendre le premier mot comme marque potentielle
            $words = explode(' ', $searchClean);
            if (count($words) > 0) {
                $components['brand'] = trim($words[0]);
                // Le reste comme nom de produit
                if (count($words) > 1) {
                    $components['product_name'] = implode(' ', array_slice($words, 1));
                }
            }
        }

        // Extraire le volume
        if (preg_match('/(\d+)\s*ml/i', $search, $volumeMatches)) {
            $components['volume'] = $volumeMatches[1];
        }

        // Nettoyer et normaliser
        foreach ($components as $key => $value) {
            if ($key !== 'volume') {
                $components[$key] = mb_strtolower(trim($value));
            }
        }

        return $components;
    }

    /**
     * Liste étendue des mots à ignorer
     */
    private function getStopWords(): array
    {
        return [
            // Français
            'de', 'le', 'la', 'les', 'un', 'une', 'des', 'du', 'et', 'ou', 'pour', 
            'avec', 'sans', 'dans', 'sur', 'par', 'au', 'aux', 'en', 'à',
            
            // Anglais
            'the', 'a', 'an', 'and', 'or', 'for', 'with', 'without', 'in', 'on', 'by',
            
            // Termes produits
            'eau', 'ml', 'edition', 'édition', 'coffret', 'parfum', 'vaporisateur',
            'spray', 'flacon', 'bottle', 'perfume', 'toilette', 'edt', 'edp'
        ];
    }

    /**
     * Extrait le nom principal du produit pour les recherches LIKE
     */
    private function extractMainProductName(string $search): string
    {
        $components = $this->extractSearchComponents($search);
        
        if (!empty($components['product_name'])) {
            return $components['product_name'];
        }
        
        if (!empty($components['brand'])) {
            return $components['brand'];
        }
        
        $words = explode(' ', $search);
        $stopWords = $this->getStopWords();
        
        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) > 2 && !in_array($word, $stopWords)) {
                return $word;
            }
        }
        
        return $search;
    }

    /**
     * Calcule la similarité améliorée entre la recherche et chaque produit
     */
    private function calculateEnhancedSimilarity($products, $search)
    {
        $scoredProducts = [];
        $searchComponents = $this->extractSearchComponents($search);

        foreach ($products as $product) {
            $similarityScore = $this->computeEnhancedSimilarity($product, $search, $searchComponents);

            // Appliquer le seuil de similarité
            if ($similarityScore >= $this->similarityThreshold) {
                $product->similarity_score = $similarityScore;
                $product->match_level = $this->getAdvancedMatchLevel($similarityScore, $product, $searchComponents);
                $product->match_reasons = $this->getMatchReasons($product, $searchComponents);
                $product->component_scores = $this->getComponentScores($product, $searchComponents);
                $scoredProducts[] = $product;
            }
        }

        usort($scoredProducts, function ($a, $b) {
            return $b->similarity_score <=> $a->similarity_score;
        });

        return $scoredProducts;
    }

    /**
     * Calcule le score de similarité amélioré avec pondérations optimisées
     */
    private function computeEnhancedSimilarity($product, $search, $searchComponents)
    {
        // Poids optimisés pour une meilleure distribution des scores
        $weights = [
            'brand' => 0.15,       // Important mais secondaire
            'product_name' => 0.35, // Très important - CRITÈRE PRINCIPAL
            'product_type' => 0.25, // Important - CRITÈRE PRINCIPAL  
            'variation' => 0.10,    // Moins important
            'volume' => 0.30        // Très important - CRITÈRE PRINCIPAL
        ];

        $totalScore = 0;
        $componentScores = [];

        // Calcul des scores individuels
        $brandScore = $this->computeBrandSimilarity($product, $searchComponents);
        $componentScores['brand'] = $brandScore;
        $totalScore += $brandScore * $weights['brand'];

        $productNameScore = $this->computeProductNameSimilarity($product, $searchComponents);
        $componentScores['product_name'] = $productNameScore;
        $totalScore += $productNameScore * $weights['product_name'];

        $productTypeScore = $this->computeProductTypeSimilarity($product, $searchComponents);
        $componentScores['product_type'] = $productTypeScore;
        $totalScore += $productTypeScore * $weights['product_type'];

        $variationScore = $this->computeEnhancedVariationSimilarity($product, $searchComponents);
        $componentScores['variation'] = $variationScore;
        $totalScore += $variationScore * $weights['variation'];

        $volumeScore = $this->computeVolumeSimilarity($product);
        $componentScores['volume'] = $volumeScore;
        $totalScore += $volumeScore * $weights['volume'];

        // Bonus pour les correspondances exactes (plus modérés)
        $exactMatchBonus = $this->computeExactMatchBonus($product, $searchComponents);
        $totalScore += $exactMatchBonus;

        // Bonus pour les correspondances partielles
        $partialMatchBonus = $this->computePartialMatchBonus($componentScores, $product, $searchComponents);
        $totalScore += $partialMatchBonus;

        // BONUS SPÉCIAL: Pour les combinaisons critiques
        $comboBonus = $this->computeComboBonus($componentScores);
        $totalScore += $comboBonus;

        // Score final limité à 1.0
        $finalScore = min(1.0, $totalScore);

        \Log::info('Similarity calculation completed', [
            'component_scores' => $componentScores,
            'weights' => $weights,
            'bonuses' => [
                'exact' => $exactMatchBonus,
                'partial' => $partialMatchBonus,
                'combo' => $comboBonus
            ],
            'total_score' => $totalScore,
            'final_score' => $finalScore,
            'product_name' => $product->name
        ]);

        return $finalScore;
    }

    /**
     * Détermine le niveau de correspondance avancé basé sur les critères
     */
    private function getAdvancedMatchLevel($similarityScore, $product, $searchComponents)
    {
        $brandScore = $this->computeBrandSimilarity($product, $searchComponents);
        $nameScore = $this->computeProductNameSimilarity($product, $searchComponents);
        $volumeScore = $this->computeVolumeSimilarity($product);
        $variationScore = $this->computeEnhancedVariationSimilarity($product, $searchComponents);
        $typeScore = $this->computeProductTypeSimilarity($product, $searchComponents);

        // Seuils ajustés pour une meilleure distribution
        $excellentThreshold = 0.8;
        $goodThreshold = 0.7;
        $moderateThreshold = 0.5;

        // CRITÈRES PRINCIPAUX POUR EXCELLENT
        $hasExcellentName = $nameScore >= $excellentThreshold;
        $hasExcellentVolume = $volumeScore >= $excellentThreshold;
        $hasExcellentType = $typeScore >= $excellentThreshold;

        // CRITÈRES POUR BON
        $hasGoodName = $nameScore >= $goodThreshold;
        $hasGoodVolume = $volumeScore >= $goodThreshold;
        $hasGoodType = $typeScore >= $goodThreshold;
        $hasGoodBrand = $brandScore >= $goodThreshold;

        // CRITÈRES POUR MOYEN
        $hasModerateName = $nameScore >= $moderateThreshold;
        $hasModerateVolume = $volumeScore >= $moderateThreshold;
        $hasModerateType = $typeScore >= $moderateThreshold;
        $hasModerateBrand = $brandScore >= $moderateThreshold;

        // === RÈGLE 1: EXCELLENT - Nom + Volume + Type excellents ===
        if ($hasExcellentName && $hasExcellentVolume && $hasExcellentType) {
            \Log::info('EXCELLENT MATCH: Name + Volume + Type all excellent', [
                'name_score' => $nameScore,
                'volume_score' => $volumeScore,
                'type_score' => $typeScore,
                'product_name' => $product->name,
                'similarity_score' => $similarityScore
            ]);
            return 'excellent';
        }

        // === RÈGLE 2: BON - Nom + Type excellents OU Nom + Volume excellents ===
        if (($hasExcellentName && $hasExcellentType) || ($hasExcellentName && $hasExcellentVolume)) {
            \Log::info('GOOD MATCH: Name + Type/Volume excellent', [
                'name_score' => $nameScore,
                'type_score' => $typeScore,
                'volume_score' => $volumeScore,
                'product_name' => $product->name,
                'similarity_score' => $similarityScore
            ]);
            return 'bon';
        }

        // === RÈGLE 3: BON - Score global élevé avec bons critères ===
        if ($similarityScore >= 0.75 && ($hasGoodName && ($hasGoodType || $hasGoodVolume))) {
            \Log::info('GOOD MATCH: High similarity with good name + type/volume', [
                'similarity_score' => $similarityScore,
                'name_score' => $nameScore,
                'type_score' => $typeScore,
                'volume_score' => $volumeScore,
                'product_name' => $product->name
            ]);
            return 'bon';
        }

        // === RÈGLE 4: MOYEN - Critères modérés ou combinaisons partielles ===
        $goodCriteriaCount = 0;
        $goodCriteriaCount += $hasGoodName ? 1 : 0;
        $goodCriteriaCount += $hasGoodVolume ? 1 : 0;
        $goodCriteriaCount += $hasGoodType ? 1 : 0;
        $goodCriteriaCount += $hasGoodBrand ? 1 : 0;

        $moderateCriteriaCount = 0;
        $moderateCriteriaCount += $hasModerateName ? 1 : 0;
        $moderateCriteriaCount += $hasModerateVolume ? 1 : 0;
        $moderateCriteriaCount += $hasModerateType ? 1 : 0;
        $moderateCriteriaCount += $hasModerateBrand ? 1 : 0;

        // MOYEN: Au moins 2 critères bons OU 3 critères modérés
        if ($goodCriteriaCount >= 2 || $moderateCriteriaCount >= 3 || $similarityScore >= 0.65) {
            \Log::info('MODERATE MATCH: Multiple good/moderate criteria', [
                'good_criteria' => $goodCriteriaCount,
                'moderate_criteria' => $moderateCriteriaCount,
                'similarity_score' => $similarityScore,
                'product_name' => $product->name
            ]);
            return 'moyen';
        }

        // === RÈGLE 5: FAIBLE - Peu de critères correspondants ===
        \Log::info('WEAK MATCH: Few matching criteria', [
            'name_score' => $nameScore,
            'volume_score' => $volumeScore,
            'type_score' => $typeScore,
            'brand_score' => $brandScore,
            'similarity_score' => $similarityScore,
            'product_name' => $product->name
        ]);
        return 'faible';
    }

    /**
     * Bonus spécial pour les combinaisons critiques
     */
    private function computeComboBonus($componentScores)
    {
        $bonus = 0;

        $hasExcellentName = $componentScores['product_name'] >= 0.8;
        $hasExcellentVolume = $componentScores['volume'] >= 0.8;
        $hasExcellentType = $componentScores['product_type'] >= 0.8;

        $hasGoodName = $componentScores['product_name'] >= 0.7;
        $hasGoodVolume = $componentScores['volume'] >= 0.7;
        $hasGoodType = $componentScores['product_type'] >= 0.7;

        // BONUS MAXIMAL: Les trois critères pour "excellent"
        if ($hasExcellentName && $hasExcellentVolume && $hasExcellentType) {
            $bonus += 0.25;
            \Log::info('MAX COMBO BONUS: Name + Volume + Type all excellent');
        }
        // BONUS IMPORTANT: Pour "bon" - Nom + Type
        elseif ($hasExcellentName && $hasExcellentType) {
            $bonus += 0.15;
            \Log::info('GOOD COMBO BONUS: Name + Type excellent');
        }
        // BONUS: Pour "bon" - Nom + Volume
        elseif ($hasExcellentName && $hasExcellentVolume) {
            $bonus += 0.12;
            \Log::info('GOOD COMBO BONUS: Name + Volume excellent');
        }
        // BONUS LÉGER: Bonnes correspondances
        elseif ($hasGoodName && $hasGoodType) {
            $bonus += 0.08;
            \Log::info('MODERATE COMBO BONUS: Name + Type good');
        }

        return $bonus;
    }

    /**
     * Bonus pour les correspondances exactes (réduit pour éviter les scores trop élevés)
     */
    private function computeExactMatchBonus($product, $searchComponents)
    {
        $bonus = 0;

        // Bonus marque exacte
        if (!empty($searchComponents['brand']) && 
            !empty($product->vendor) &&
            stripos($product->vendor, $searchComponents['brand']) !== false) {
            $bonus += 0.08;
        }

        // Bonus volume exact
        if (!empty($this->searchVolumes)) {
            $productVolumes = $this->extractVolumesFromText($product->name . ' ' . $product->variation);
            $volumeMatches = array_intersect($this->searchVolumes, $productVolumes);
            if (!empty($volumeMatches)) {
                $bonus += 0.10;
            }
        }

        // Bonus type exact
        if (!empty($searchComponents['product_type']) && 
            !empty($product->type) &&
            stripos($product->type, $searchComponents['product_type']) !== false) {
            $bonus += 0.06;
        }

        return $bonus;
    }

    /**
     * Bonus pour les correspondances partielles
     */
    private function computePartialMatchBonus($componentScores, $product, $searchComponents)
    {
        $bonus = 0;

        $hasGoodNameMatch = $componentScores['product_name'] >= 0.7;
        $hasGoodBrandMatch = $componentScores['brand'] >= 0.7;
        $hasVolumeMatch = $componentScores['volume'] >= 0.7;

        // Bonus si bon nom/marque mais volume différent
        if (($hasGoodNameMatch || $hasGoodBrandMatch) && !$hasVolumeMatch) {
            $bonus += 0.05;
        }

        // Bonus si bon volume mais nom différent
        if ($hasVolumeMatch && !$hasGoodNameMatch && !$hasGoodBrandMatch) {
            $bonus += 0.04;
        }

        return $bonus;
    }

    /**
     * Similarité de la marque
     */
    private function computeBrandSimilarity($product, $searchComponents)
    {
        $productBrand = $product->vendor ?? '';
        $searchBrand = $searchComponents['brand'] ?? '';

        if (empty($productBrand) || empty($searchBrand)) {
            return 0;
        }

        $score = $this->computeStringSimilarity($searchBrand, $productBrand);

        if (stripos($product->name ?? '', $searchBrand) === 0) {
            $score = min(1.0, $score + 0.2);
        }

        return $score;
    }

    /**
     * Amélioration de la similarité du nom du produit
     */
    private function computeProductNameSimilarity($product, $searchComponents)
    {
        $productName = $product->name ?? '';
        $searchProductName = $searchComponents['product_name'] ?? '';

        if (empty($searchProductName)) {
            return 0;
        }

        $baseScore = $this->computeStringSimilarity($searchProductName, $productName);

        // Bonus pour inclusion exacte
        $containsBonus = 0;
        if (stripos($productName, $searchProductName) !== false) {
            $containsBonus = 0.20;
        }

        // Bonus pour mots individuels
        $searchWords = $this->splitIntoWords($searchProductName);
        $productWords = $this->splitIntoWords($productName);
        
        $wordMatches = 0;
        foreach ($searchWords as $searchWord) {
            foreach ($productWords as $productWord) {
                if (strlen($searchWord) > 2 && stripos($productWord, $searchWord) !== false) {
                    $wordMatches++;
                    break;
                }
            }
        }
        
        $wordBonus = ($wordMatches / max(1, count($searchWords))) * 0.10;

        $totalScore = min(1.0, $baseScore + $containsBonus + $wordBonus);

        return $totalScore;
    }

    /**
     * Similarité du type de produit
     */
    private function computeProductTypeSimilarity($product, $searchComponents)
    {
        $productType = $product->type ?? '';
        $searchProductType = $searchComponents['product_type'] ?? '';

        if (empty($productType) || empty($searchProductType)) {
            return 0;
        }

        return $this->computeStringSimilarity($searchProductType, $productType);
    }

    /**
     * Similarité améliorée de la variation
     */
    private function computeEnhancedVariationSimilarity($product, $searchComponents)
    {
        $productVariation = $product->variation ?? '';
        $searchVariation = $searchComponents['variation'] ?? '';

        if (empty($productVariation)) {
            return 0;
        }

        $baseScore = $this->computeStringSimilarity($searchVariation, $productVariation);

        $keywordMatches = 0;
        foreach ($this->searchVariationKeywords as $keyword) {
            if (stripos($productVariation, $keyword) !== false) {
                $keywordMatches++;
            }
        }

        $keywordBonus = $keywordMatches / max(1, count($this->searchVariationKeywords)) * 0.3;

        return min(1.0, $baseScore + $keywordBonus);
    }

    /**
     * Amélioration de la similarité du volume
     */
    private function computeVolumeSimilarity($product)
    {
        if (empty($this->searchVolumes)) {
            return 0;
        }

        $productVolumes = $this->extractVolumesFromText($product->name . ' ' . ($product->variation ?? ''));

        if (empty($productVolumes)) {
            return 0;
        }

        $matches = array_intersect($this->searchVolumes, $productVolumes);
        $matchRatio = count($matches) / count($this->searchVolumes);

        // Bonus pour correspondance exacte de volume (réduit)
        $exactBonus = 0;
        foreach ($this->searchVolumes as $searchedVolume) {
            if (in_array($searchedVolume, $productVolumes)) {
                $exactBonus += 0.15;
                break; // Un seul bonus même si plusieurs volumes correspondent
            }
        }

        $baseScore = $matchRatio * 0.8; // Base score augmenté
        $totalScore = min(1.0, $baseScore + $exactBonus);

        return $totalScore;
    }

    /**
     * Retourne les scores des composants
     */
    private function getComponentScores($product, $searchComponents)
    {
        return [
            'brand' => $this->computeBrandSimilarity($product, $searchComponents),
            'product_name' => $this->computeProductNameSimilarity($product, $searchComponents),
            'product_type' => $this->computeProductTypeSimilarity($product, $searchComponents),
            'variation' => $this->computeEnhancedVariationSimilarity($product, $searchComponents),
            'volume' => $this->computeVolumeSimilarity($product)
        ];
    }

    /**
     * Retourne les raisons de la correspondance améliorées
     */
    private function getMatchReasons($product, $searchComponents)
    {
        $reasons = [];
        $scores = $this->getComponentScores($product, $searchComponents);

        // Seuils pour les raisons
        $excellentThreshold = 0.8;
        $goodThreshold = 0.7;
        $moderateThreshold = 0.5;

        // Marque
        if ($scores['brand'] >= $excellentThreshold) {
            $reasons[] = 'Marque correspondante';
        } elseif ($scores['brand'] >= $goodThreshold) {
            $reasons[] = 'Marque similaire';
        }

        // Nom du produit
        if ($scores['product_name'] >= $excellentThreshold) {
            $reasons[] = 'Nom produit correspondant';
        } elseif ($scores['product_name'] >= $goodThreshold) {
            $reasons[] = 'Nom produit similaire';
        }

        // Volume
        if ($scores['volume'] >= $excellentThreshold) {
            $reasons[] = 'Volume correspondant';
        } elseif ($scores['volume'] >= $goodThreshold) {
            $reasons[] = 'Volume similaire';
        }

        // Type de produit
        if ($scores['product_type'] >= $excellentThreshold) {
            $reasons[] = 'Type produit correspondant';
        } elseif ($scores['product_type'] >= $goodThreshold) {
            $reasons[] = 'Type produit similaire';
        }

        // Variation
        if ($scores['variation'] >= $excellentThreshold) {
            $reasons[] = 'Variation correspondante';
        } elseif ($scores['variation'] >= $goodThreshold) {
            $reasons[] = 'Variation similaire';
        }

        // Raisons spéciales basées sur les combinaisons
        if ($scores['product_name'] >= $excellentThreshold && 
            $scores['volume'] >= $excellentThreshold && 
            $scores['product_type'] >= $excellentThreshold) {
            $reasons[] = 'Nom+Volume+Type excellents ★';
        } elseif ($scores['product_name'] >= $excellentThreshold && $scores['product_type'] >= $excellentThreshold) {
            $reasons[] = 'Nom+Type excellents';
        } elseif ($scores['product_name'] >= $excellentThreshold && $scores['volume'] >= $excellentThreshold) {
            $reasons[] = 'Nom+Volume excellents';
        }

        return array_slice($reasons, 0, 4);
    }

    /**
     * Similarité de chaîne (algorithme de Jaro-Winkler amélioré)
     */
    private function computeStringSimilarity($str1, $str2)
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
     * Extrait les volumes (ml) de la recherche
     */
    private function extractSearchVolumes(string $search): void
    {
        $this->searchVolumes = [];

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
     */
    private function extractSearchVariationKeywords(string $search): void
    {
        $this->searchVariationKeywords = [];

        $pattern = '/^[^-]+\s*-\s*[^-]+\s*-\s*/i';
        $variation = preg_replace($pattern, '', $search);

        $variationClean = preg_replace('/[^a-zA-ZÀ-ÿ0-9\s]/', ' ', $variation);
        $variationClean = trim(preg_replace('/\s+/', ' ', $variationClean));
        $variationClean = mb_strtolower($variationClean);

        $words = $this->splitIntoWords($variationClean);

        $stopWords = $this->getStopWords();

        foreach ($words as $word) {
            $word = trim($word);
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
     * Ajuste le seuil de similarité - CORRIGÉ
     */
    public function adjustSimilarityThreshold($threshold)
    {
        $this->isLoading = true;
        $this->similarityThreshold = $threshold;
        
        // Recalculer la similarité avec le nouveau seuil
        if (!empty($this->originalProducts)) {
            $this->matchedProducts = $this->calculateEnhancedSimilarity($this->originalProducts, $this->search ?? '');
        }
        
        $this->isLoading = false;
    }

    /**
     * Formate le prix pour l'affichage
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
        if (empty($url)) {
            return 'N/A';
        }

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

        return $hasMatchingVolume && $hasMatchingVariationKeyword;
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
     * Extrait la variation de la recherche complète
     */
    public function extractSearchVariation()
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
     * Vérifie si le produit a le même volume ET la même variation exacte que la recherche
     */
    public function hasSameVolumeAndExactVariation($product)
    {
        $hasMatchingVolume = $this->hasMatchingVolume($product);
        $hasExactVariation = $this->hasExactVariationMatch($product);

        return $hasMatchingVolume && $hasExactVariation;
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

        if (!empty($this->searchVolumes)) {
            foreach ($this->searchVolumes as $volume) {
                $patterns[] = '\b' . preg_quote($volume, '/') . '\s*ml\b';
            }
        }

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

        $pattern = '/(' . implode('|', $patterns) . ')/iu';

        $text = preg_replace_callback($pattern, function ($matches) {
            return '<span class="bg-green-100 text-green-800 font-semibold px-1 py-0.5 rounded">'
                . $matches[0]
                . '</span>';
        }, $text);

        return $text;
    }

    /**
     * Calcule la différence de prix par rapport au prix du concurrent
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
     * Calcule le pourcentage de différence par rapport au concurrent
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
     * Détermine le statut de compétitivité de notre prix
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
     * Calcule la différence de prix Cosmashop par rapport au concurrent
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
     * Calcule le pourcentage de différence Cosmashop par rapport au concurrent
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
     * Formate la différence de prix avec le bon symbole
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
     * Formate le pourcentage de différence avec le bon symbole
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
     * Analyse globale des prix des concurrents
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
            <!-- Indicateur de similarité amélioré -->
            <div class="mb-4 p-4 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg border">
                <div class="flex flex-col space-y-3">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 text-blue-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                            </svg>
                            <span class="text-sm font-medium text-blue-800">
                                Algorithme de similarité avancé - 
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

                    <!-- Nouvelle légende avec les règles de scoring -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs text-blue-600">
                        <div class="space-y-2">
                            <div class="font-semibold">Niveaux de correspondance :</div>
                            <div class="flex items-center">
                                <span class="w-3 h-3 bg-green-500 rounded-full mr-2"></span>
                                <span class="font-medium">EXCELLENT :</span>
                                <span class="ml-1 text-xs">Nom + Volume + Type excellents</span>
                            </div>
                            <div class="flex items-center">
                                <span class="w-3 h-3 bg-blue-500 rounded-full mr-2"></span>
                                <span class="font-medium">BON :</span>
                                <span class="ml-1 text-xs">Nom + Type OU Nom + Volume excellents</span>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <div class="flex items-center">
                                <span class="w-3 h-3 bg-yellow-500 rounded-full mr-2"></span>
                                <span class="font-medium">MOYEN :</span>
                                <span class="ml-1 text-xs">Au moins 1 critère fort ou 2 critères modérés</span>
                            </div>
                            <div class="flex items-center">
                                <span class="w-3 h-3 bg-gray-500 rounded-full mr-2"></span>
                                <span class="font-medium">FAIBLE :</span>
                                <span class="ml-1 text-xs">Peu de critères correspondants</span>
                            </div>
                        </div>
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

            <!-- Tableau des résultats amélioré -->
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
                                    <!-- Colonne Score améliorée -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-16 bg-gray-200 rounded-full h-2 mr-3">
                                                <div class="h-2 rounded-full 
                                                    @if($product->match_level === 'excellent') bg-green-500
                                                    @elseif($product->match_level === 'bon') bg-blue-500
                                                    @elseif($product->match_level === 'moyen') bg-yellow-500
                                                    @else bg-gray-500 @endif"
                                                    style="width: {{ ($product->similarity_score ?? 0) * 100 }}%">
                                                </div>
                                            </div>
                                            <span class="text-sm font-mono font-semibold 
                                                @if($product->match_level === 'excellent') text-green-600
                                                @elseif($product->match_level === 'bon') text-blue-600
                                                @elseif($product->match_level === 'moyen') text-yellow-600
                                                @else text-gray-600 @endif">
                                                {{ number_format(($product->similarity_score ?? 0) * 100, 0) }}%
                                            </span>
                                        </div>
                                    </td>

                                    <!-- Colonne Correspondance améliorée -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex flex-col space-y-2">
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium border {{ $matchClass }}">
                                                @if($product->match_level === 'excellent')
                                                    <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                                    </svg>
                                                @elseif($product->match_level === 'bon')
                                                    <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                                    </svg>
                                                @endif
                                                {{ ucfirst($product->match_level) }}
                                            </span>
                                            
                                            <!-- Afficher les raisons du matching -->
                                            @if(!empty($product->match_reasons))
                                                <div class="flex flex-wrap gap-1">
                                                    @foreach($product->match_reasons as $reason)
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs 
                                                            @if(str_contains($reason, '★')) bg-green-50 text-green-700 border border-green-200
                                                            @elseif(str_contains($reason, 'excellents')) bg-blue-50 text-blue-700 border border-blue-200
                                                            @elseif(str_contains($reason, 'correspondant')) bg-green-50 text-green-700 border border-green-200
                                                            @elseif(str_contains($reason, 'similaire')) bg-blue-50 text-blue-700 border border-blue-200
                                                            @else bg-gray-50 text-gray-700 border border-gray-200 @endif">
                                                            {{ $reason }}
                                                        </span>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    </td>

                                    <!-- Colonne Image -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        @if(!empty($product->image))
                                            <img src="{{ $product->image }}" 
                                                 alt="{{ $product->name ?? 'Produit' }}" 
                                                 class="h-20 w-20 object-cover rounded-lg shadow-md border border-gray-200"
                                                 onerror="this.src='https://placehold.co/600x400'">
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
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Colonne Prix HT -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-semibold text-green-600">
                                            {{ $this->formatPrice($product->price_ht ?? $product->prix_ht) }}
                                        </div>
                                    </td>

                                    <!-- Colonne Date MAJ Prix -->
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