<?php

namespace App\Livewire;

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use App\Models\Site as WebSite;

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

    // Pour stocker la requête de recherche
    public $searchQuery = '';

    // prix a comparer
    public $price;
    public $referencePrice;

    // price cosmashop
    public $cosmashopPrice;

    // Filtres
    public $filters = [
        'vendor' => '',
        'name' => '',
        'variation' => '',
        'type' => '',
        'site_source' => ''
    ];

    public $sites = []; // Pour stocker la liste des sites
    public $showTable = false; // Pour suivre si on doit montrer le tableau même sans résultats
    public $isAutomaticSearch = true; // Pour distinguer recherche automatique vs manuelle
    
    // Pour stocker les résultats originaux de la recherche automatique
    public $originalAutomaticResults = [];
    
    // Pour stocker si un filtre a été appliqué après la recherche automatique
    public $hasAppliedFilters = false;

    // Mapping des abréviations des marques
    private array $brandAbbreviations = [
        'YSL' => 'Yves Saint Laurent',
        'D&G' => 'Dolce & Gabbana',
        'CK' => 'Calvin Klein',
        'JPG' => 'Jean Paul Gaultier',
        'PR' => 'Paco Rabanne',
        'CH' => 'Carolina Herrera',
        'V&R' => 'Viktor & Rolf',
        'BVLGARI' => 'Bvlgari',
        'HERMES' => 'Hermès',
        'GUERLAIN' => 'Guerlain',
        'LANCOME' => 'Lancôme',
        'DIOR' => 'Dior',
        'CHANEL' => 'Chanel',
        'ARMANI' => 'Armani',
        'PRADA' => 'Prada',
        'VERSACE' => 'Versace',
        'GIVENCHY' => 'Givenchy',
        'BURBERRY' => 'Burberry',
        'MUGLER' => 'Mugler',
        'NR' => 'Narciso Rodriguez',
        'MB' => 'Montblanc',
        'CARTIER' => 'Cartier',
        // Ajoutez d'autres mappings selon vos besoins
    ];

    public function mount($name, $id, $price)
    {
        $this->getCompetitorPrice($name);
        $this->id = $id;
        $this->price = $this->cleanPrice($price);
        $this->referencePrice = $this->cleanPrice($price);
        $this->cosmashopPrice = $this->cleanPrice($price) * 1.05;

        // STOCKEZ LA REQUÊTE DE RECHERCHE
        $this->searchQuery = $name;

        // Extraire le vendor par défaut depuis la recherche
        $this->extractDefaultVendor($name);

        // Charger la liste des sites
        $this->loadSites();

        // Toujours afficher le tableau pour permettre le filtrage manuel
        $this->showTable = true;
    }

    /**
     * Extrait le vendor par défaut depuis la recherche avec gestion des abréviations
     */
    private function extractDefaultVendor(string $search): void
    {
        $vendor = '';

        // Pattern pour extraire le vendor (marque) de la recherche
        if (preg_match('/^([^-]+)/', $search, $matches)) {
            $vendor = trim($matches[1]);

            // Nettoyer les chiffres et caractères spéciaux
            $vendor = preg_replace('/[0-9]+ml/i', '', $vendor);
            $vendor = trim($vendor);
            
            // Vérifier si c'est une abréviation connue
            $vendor = $this->normalizeVendor($vendor);
        }

        // Si on n'a pas trouvé de vendor, essayer d'autres méthodes
        if (empty($vendor)) {
            $vendor = $this->guessVendorFromSearch($search);
        }

        // Définir le vendor comme filtre par défaut
        if (!empty($vendor)) {
            $this->filters['vendor'] = $vendor;
            \Log::info('Default vendor extracted:', ['vendor' => $vendor, 'search' => $search]);
        }
    }

    /**
     * Normalise un nom de vendor (convertit les abréviations en noms complets)
     */
    private function normalizeVendor(string $vendor): string
    {
        if (empty(trim($vendor))) {
            return '';
        }
        
        $vendorUpper = strtoupper(trim($vendor));
        
        // Si le vendor est une abréviation connue, retourner le nom complet
        if (array_key_exists($vendorUpper, $this->brandAbbreviations)) {
            return $this->brandAbbreviations[$vendorUpper];
        }
        
        // Vérifier également les correspondances partielles
        foreach ($this->brandAbbreviations as $abbreviation => $fullName) {
            if (str_contains($vendorUpper, $abbreviation) || 
                str_contains(strtoupper($fullName), $vendorUpper)) {
                return $fullName;
            }
        }
        
        // Vérifier aussi dans l'autre sens (nom complet vers abréviation)
        foreach ($this->brandAbbreviations as $abbreviation => $fullName) {
            if (strcasecmp(trim($vendor), $fullName) === 0) {
                return $fullName;
            }
        }
        
        return trim($vendor);
    }

    /**
     * Devine le vendor à partir de la recherche avec gestion des abréviations
     */
    private function guessVendorFromSearch(string $search): string
    {
        $commonVendors = [
            'Dior',
            'Chanel',
            'Yves Saint Laurent',
            'Guerlain',
            'Lancôme',
            'Hermès',
            'Prada',
            'Armani',
            'Versace',
            'Dolce & Gabbana',
            'Givenchy',
            'Jean Paul Gaultier',
            'Bvlgari',
            'Cartier',
            'Montblanc',
            'Burberry',
            'Calvin Klein',
            'Paco Rabanne',
            'Carolina Herrera',
            'Viktor & Rolf',
            'Mugler',
            'Narciso Rodriguez'
        ];

        $searchLower = strtolower($search);
        
        // Vérifier d'abord les abréviations
        foreach ($this->brandAbbreviations as $abbreviation => $fullName) {
            if (stripos($searchLower, strtolower($abbreviation)) !== false) {
                return $fullName;
            }
        }

        // Vérifier les noms complets
        foreach ($commonVendors as $vendor) {
            if (stripos($searchLower, strtolower($vendor)) !== false) {
                return $vendor;
            }
        }

        return '';
    }

    /**
     * Récupère toutes les variations d'une marque (nom complet, abréviation)
     */
    private function getVendorVariations(string $vendor): array
    {
        $variations = [trim($vendor)];
        
        // Normaliser d'abord
        $normalized = $this->normalizeVendor($vendor);
        if ($normalized !== $vendor && !in_array($normalized, $variations)) {
            $variations[] = $normalized;
        }
        
        // Trouver l'abréviation correspondante
        foreach ($this->brandAbbreviations as $abbreviation => $fullName) {
            if (strcasecmp($normalized, $fullName) === 0) {
                $variations[] = $abbreviation;
                $variations[] = strtoupper($abbreviation);
                $variations[] = strtolower($abbreviation);
            }
        }
        
        // Ajouter aussi les versions en majuscule/minuscule
        $variations[] = strtoupper($vendor);
        $variations[] = strtolower($vendor);
        $variations[] = ucwords(strtolower($vendor));
        
        return array_unique(array_filter($variations));
    }

    /**
     * Charge la liste des sites
     */
    public function loadSites()
    {
        try {
            // Récupérer tous les sites web
            $this->sites = WebSite::orderBy('name')->get();

            \Log::info('Sites loaded:', ['count' => count($this->sites)]);
        } catch (\Throwable $e) {
            \Log::error('Error loading sites:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->sites = [];
        }
    }

/**
 * Recherche manuelle sans FULLTEXT avec gestion des abréviations
 * Retourne uniquement le dernier produit inséré par site
 */
public function searchManual()
{
    try {
        // Réinitialiser le flag de données
        $this->hasData = false;
        $this->matchedProducts = [];
        $this->products = [];

        // ÉTAPE 1: Trouver les derniers scrap_reference_id par site web
        $latestScrapReferences = DB::connection('mysql')->select("
            SELECT 
                web_site_id,
                MAX(id) as latest_scrap_reference_id
            FROM scrap_reference
            GROUP BY web_site_id
        ");

        // Convertir en tableau pour utilisation facile
        $latestRefsBySite = [];
        foreach ($latestScrapReferences as $ref) {
            $latestRefsBySite[$ref->web_site_id] = $ref->latest_scrap_reference_id;
        }

        // Si aucun scrap_reference trouvé, retourner vide
        if (empty($latestRefsBySite)) {
            $this->products = [];
            $this->hasData = false;
            return;
        }

        // ÉTAPE 2: Pour chaque site, trouver le dernier produit inséré
        $sql = "SELECT 
                    sp.*, 
                    ws.name as site_name, 
                    sp.url as product_url,
                    sp.image_url as image
                FROM scraped_product sp
                LEFT JOIN web_site ws ON sp.web_site_id = ws.id
                WHERE (sp.web_site_id, sp.scrap_reference_id, sp.created_at) IN (
                    SELECT 
                        web_site_id,
                        scrap_reference_id,
                        MAX(created_at) as max_created_at
                    FROM scraped_product
                    WHERE scrap_reference_id IN (" . implode(',', array_values($latestRefsBySite)) . ")
                    GROUP BY web_site_id, scrap_reference_id
                )";

        $params = [];

        // AJOUTER LE FILTRE VENDOR AVEC GESTION DES ABRÉVIATIONS
        if (!empty($this->filters['vendor'])) {
            $vendorVariations = $this->getVendorVariations($this->filters['vendor']);
            
            if (!empty($vendorVariations)) {
                $sql .= " AND (";
                $conditions = [];
                
                foreach ($vendorVariations as $variation) {
                    $conditions[] = "sp.vendor LIKE ?";
                    $params[] = '%' . $variation . '%';
                }
                
                $sql .= implode(' OR ', $conditions) . ")";
            }
        }

        // Ajouter les autres filtres si spécifiés
        if (!empty($this->filters['name'])) {
            $sql .= " AND sp.name LIKE ?";
            $params[] = '%' . $this->filters['name'] . '%';
        }

        if (!empty($this->filters['variation'])) {
            $sql .= " AND sp.variation LIKE ?";
            $params[] = '%' . $this->filters['variation'] . '%';
        }

        if (!empty($this->filters['type'])) {
            $sql .= " AND sp.type LIKE ?";
            $params[] = '%' . $this->filters['type'] . '%';
        }

        // Ajouter le filtre site_source si spécifié
        if (!empty($this->filters['site_source'])) {
            $sql .= " AND ws.id = ?";
            $params[] = $this->filters['site_source'];
        }

        $sql .= " ORDER BY sp.prix_ht DESC LIMIT 20";

        \Log::info('Manual search SQL with latest products by site:', [
            'filters' => $this->filters,
            'vendor_variations' => $vendorVariations ?? [],
            'latest_scrap_references' => $latestRefsBySite,
            'sql' => $sql,
            'params' => $params
        ]);

        $result = DB::connection('mysql')->select($sql, $params);

        // Nettoyer les prix et ajouter les propriétés manquantes
        foreach ($result as $product) {
            if (isset($product->prix_ht)) {
                $product->prix_ht = $this->cleanPrice($product->prix_ht);
            }

            // S'assurer que product_url est défini
            if (!isset($product->product_url) && isset($product->url)) {
                $product->product_url = $product->url;
            }

            // S'assurer que image est défini
            if (!isset($product->image) && isset($product->image_url)) {
                $product->image = $product->image_url;
            }

            // AJOUTER LES PROPRIÉTÉS POUR LE TABLEAU UNIFIÉ
            $product->similarity_score = null;
            $product->match_level = null;
            $product->is_manual_search = true;
        }

        $this->products = $result;
        $this->matchedProducts = $result;
        $this->hasData = !empty($result);
        $this->isAutomaticSearch = false;
        $this->hasAppliedFilters = true; // Marquer que des filtres ont été appliqués

        \Log::info('Manual search results with latest products by site:', [
            'count' => count($result),
            'has_data' => $this->hasData,
            'unique_sites' => array_unique(array_column($result, 'web_site_id')),
            'latest_scrap_references_used' => $latestRefsBySite
        ]);

    } catch (\Throwable $e) {
        \Log::error('Error in manual search:', [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->products = [];
        $this->hasData = false;
    }
}

    /**
     * Méthode pour appliquer les filtres
     */
    public function applyFilters()
    {
        // Si on est en recherche automatique et qu'on applique un filtre,
        // on passe en mode recherche manuelle
        if ($this->isAutomaticSearch && $this->hasData) {
            $this->searchManual();
        } else {
            // Si déjà en recherche manuelle, on fait une nouvelle recherche avec les filtres
            $this->searchManual();
        }
    }

    /**
     * Méthode pour réinitialiser les filtres
     */
    public function resetFilters()
    {
        // Réinitialiser tous les filtres sauf le vendor qui garde sa valeur par défaut
        $this->filters = [
            'vendor' => $this->filters['vendor'], // Garder le vendor actuel
            'name' => '',
            'variation' => '',
            'type' => '',
            'site_source' => ''
        ];

        // Réinitialiser le flag de filtres appliqués
        $this->hasAppliedFilters = false;

        // Si on avait des résultats automatiques stockés, on les restaure
        if (!empty($this->originalAutomaticResults) && !$this->hasAppliedFilters) {
            $this->matchedProducts = $this->originalAutomaticResults;
            $this->products = $this->matchedProducts;
            $this->hasData = !empty($this->matchedProducts);
            $this->isAutomaticSearch = true;
            
            \Log::info('Reset filters - restored original automatic results:', [
                'original_count' => count($this->originalAutomaticResults)
            ]);
        } else {
            // Sinon, on recharge la recherche automatique
            if (!empty($this->searchQuery)) {
                $this->getCompetitorPrice($this->searchQuery);
            }
        }
    }

    /**
     * Méthode appelée quand un filtre change
     */
    public function updatedFilters($value, $key)
    {
        // Vérifier si le filtre n'est pas vide
        if (!empty($value)) {
            // Si on était en recherche automatique, on passe en manuelle
            if ($this->isAutomaticSearch && $this->hasData) {
                $this->hasAppliedFilters = true;
            }
        }
        
        // Débouncer pour éviter trop d'appels
        $this->applyFilters();
    }

    /**
     * Nettoie et convertit un prix en nombre décimal
     */
    private function cleanPrice($price)
    {
        // Si null ou vide, retourner null
        if ($price === null || $price === '') {
            return null;
        }

        // Si déjà numérique, retourner tel quel
        if (is_numeric($price)) {
            return (float) $price;
        }

        // Si string, nettoyer
        if (is_string($price)) {
            // Enlever symboles de devise et espaces
            $cleanPrice = preg_replace('/[^\d,.-]/', '', $price);

            // Remplacer virgule par point pour conversion
            $cleanPrice = str_replace(',', '.', $cleanPrice);

            // Vérifier si numérique après nettoyage
            if (is_numeric($cleanPrice)) {
                return (float) $cleanPrice;
            }
        }

        return null;
    }

    /**
     * Récupère les détails d'un produit depuis Magento
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

            $this->products = [];
            $this->hasData = false;

            return [
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Récupère les prix des concurrents (recherche automatique) avec gestion des abréviations
     */
    public function getCompetitorPrice($search)
    {
        try {
            if (empty($search)) {
                $this->products = [];
                $this->hasData = false;
                $this->originalAutomaticResults = [];
                $this->hasAppliedFilters = false;
                return null;
            }

            $this->extractSearchVolumes($search);
            $this->extractSearchVariationKeywords($search);

            $searchQuery = $this->prepareSearchTerms($search);

            if (empty($searchQuery)) {
                $this->products = [];
                $this->hasData = false;
                $this->originalAutomaticResults = [];
                $this->hasAppliedFilters = false;
                return null;
            }

            // MODIFIEZ CETTE REQUÊTE POUR INCLURE LA GESTION DES ABRÉVIATIONS
            $sql = "SELECT lp.*, ws.name as site_name, lp.url as product_url, lp.image_url as image
                    FROM last_price_scraped_product lp
                    LEFT JOIN web_site ws ON lp.web_site_id = ws.id
                    WHERE MATCH (lp.name, lp.vendor, lp.type, lp.variation) 
                    AGAINST (? IN BOOLEAN MODE)
                    ORDER BY lp.prix_ht DESC LIMIT 50";

            \Log::info('SQL Query:', [
                'original_search' => $search,
                'search_query' => $searchQuery,
                'search_volumes' => $this->searchVolumes,
                'search_variation_keywords' => $this->searchVariationKeywords
            ]);

            $result = DB::connection('mysql')->select($sql, [$searchQuery]);

            // NETTOYER LE PRIX_HT DÈS LA RÉCUPÉRATION
            foreach ($result as $product) {
                if (isset($product->prix_ht)) {
                    $originalPrice = $product->prix_ht;
                    $cleanedPrice = $this->cleanPrice($product->prix_ht);
                    $product->prix_ht = $cleanedPrice;

                    \Log::info('Prix nettoyé:', [
                        'original' => $originalPrice,
                        'cleaned' => $cleanedPrice
                    ]);
                }

                // Normaliser le vendor pour une meilleure comparaison
                if (isset($product->vendor)) {
                    $originalVendor = $product->vendor;
                    $product->vendor = $this->normalizeVendor($originalVendor);
                    
                    if ($originalVendor !== $product->vendor) {
                        \Log::info('Vendor normalisé:', [
                            'original' => $originalVendor,
                            'normalized' => $product->vendor
                        ]);
                    }
                }

                // S'assurer que product_url est défini
                if (!isset($product->product_url) && isset($product->url)) {
                    $product->product_url = $product->url;
                }

                // S'assurer que image est défini
                if (!isset($product->image) && isset($product->image_url)) {
                    $product->image = $product->image_url;
                }

                // AJOUTER LA PROPRIÉTÉ POUR DISTINGUER LA RECHERCHE
                $product->is_manual_search = false;
            }

            \Log::info('Query result:', [
                'count' => count($result)
            ]);

            $this->matchedProducts = $this->calculateSimilarity($result, $search);
            $this->products = $this->matchedProducts;
            
            // STOCKER LES RÉSULTATS ORIGINAUX DE LA RECHERCHE AUTOMATIQUE
            $this->originalAutomaticResults = $this->matchedProducts;
            
            // Réinitialiser le flag de filtres appliqués
            $this->hasAppliedFilters = false;
            
            $this->hasData = !empty($result);
            $this->isAutomaticSearch = true;

            // Si pas de résultats automatiques, on affiche le tableau quand même
            if (!$this->hasData) {
                $this->showTable = true;
            }

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
            $this->originalAutomaticResults = [];
            $this->hasAppliedFilters = false;
            $this->showTable = true;

            return [
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Calcule la similarité entre la recherche et chaque produit
     */
    private function calculateSimilarity($products, $search)
    {
        $scoredProducts = [];

        foreach ($products as $product) {
            $similarityScore = $this->computeOverallSimilarity($product, $search);

            if ($similarityScore >= $this->similarityThreshold) {
                $product->similarity_score = $similarityScore;
                $product->match_level = $this->getMatchLevel($similarityScore);
                $scoredProducts[] = $product;
            }
        }

        usort($scoredProducts, function ($a, $b) {
            return $b->similarity_score <=> $a->similarity_score;
        });

        return $scoredProducts;
    }

    /**
     * Calcule le score de similarité global
     */
    private function computeOverallSimilarity($product, $search)
    {
        $weights = [
            'name' => 0.3,
            'vendor' => 0.2,
            'variation' => 0.25,
            'volumes' => 0.15,
            'type' => 0.1
        ];

        $totalScore = 0;

        $nameScore = $this->computeStringSimilarity($search, $product->name ?? '');
        $totalScore += $nameScore * $weights['name'];

        $vendorScore = $this->computeVendorSimilarity($product, $search);
        $totalScore += $vendorScore * $weights['vendor'];

        $variationScore = $this->computeVariationSimilarity($product, $search);
        $totalScore += $variationScore * $weights['variation'];

        $volumeScore = $this->computeVolumeSimilarity($product);
        $totalScore += $volumeScore * $weights['volumes'];

        $typeScore = $this->computeTypeSimilarity($product, $search);
        $totalScore += $typeScore * $weights['type'];

        return min(1.0, $totalScore);
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
     * Similarité du vendeur avec gestion des abréviations
     */
    private function computeVendorSimilarity($product, $search)
    {
        $vendor = $product->vendor ?? '';
        if (empty($vendor)) {
            return 0;
        }

        $searchVendor = $this->extractVendorFromSearch($search);

        if (empty($searchVendor)) {
            return 0;
        }

        // Normaliser les deux vendors
        $normalizedProductVendor = $this->normalizeVendor($vendor);
        $normalizedSearchVendor = $this->normalizeVendor($searchVendor);

        // Calculer la similarité entre les noms normalisés
        $similarity = $this->computeStringSimilarity($normalizedSearchVendor, $normalizedProductVendor);
        
        // Bonus si c'est une correspondance exacte (même après normalisation)
        if (strcasecmp($normalizedProductVendor, $normalizedSearchVendor) === 0) {
            $similarity = min(1.0, $similarity + 0.2);
        }
        
        // Bonus si l'abréviation correspond
        $productVendorUpper = strtoupper($vendor);
        $searchVendorUpper = strtoupper($searchVendor);
        foreach ($this->brandAbbreviations as $abbreviation => $fullName) {
            if (($productVendorUpper === $abbreviation && strtoupper($normalizedSearchVendor) === strtoupper($fullName)) ||
                ($searchVendorUpper === $abbreviation && strtoupper($normalizedProductVendor) === strtoupper($fullName))) {
                $similarity = min(1.0, $similarity + 0.15);
                break;
            }
        }

        return $similarity;
    }

    /**
     * Similarité de la variation
     */
    private function computeVariationSimilarity($product, $search)
    {
        $productVariation = $product->variation ?? '';
        $searchVariation = $this->extractSearchVariationFromSearch($search);

        if (empty($productVariation) || empty($searchVariation)) {
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
     * Similarité des volumes
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

        if ($matchRatio === 1.0) {
            $matchRatio = 1.0;
        }

        return $matchRatio;
    }

    /**
     * Similarité du type de produit
     */
    private function computeTypeSimilarity($product, $search)
    {
        $productType = $product->type ?? '';
        if (empty($productType)) {
            return 0;
        }

        $searchType = $this->extractProductTypeFromSearch($search);

        if (empty($searchType)) {
            return 0;
        }

        return $this->computeStringSimilarity($searchType, $productType);
    }

    /**
     * Extrait la marque de la recherche avec gestion des abréviations
     */
    private function extractVendorFromSearch($search)
    {
        if (preg_match('/^([^-]+)/', $search, $matches)) {
            $vendor = trim($matches[1]);
            // Normaliser pour convertir les abréviations
            return $this->normalizeVendor($vendor);
        }

        return '';
    }

    /**
     * Extrait la variation de la recherche
     */
    private function extractSearchVariationFromSearch($search)
    {
        $pattern = '/^[^-]+\s*-\s*[^-]+\s*-\s*/i';
        $variation = preg_replace($pattern, '', $search);

        return trim($variation);
    }

    /**
     * Extrait le type de produit de la recherche
     */
    private function extractProductTypeFromSearch($search)
    {
        $types = ['parfum', 'eau de parfum', 'eau de toilette', 'coffret', 'gel douche', 'lotion'];

        foreach ($types as $type) {
            if (stripos($search, $type) !== false) {
                return $type;
            }
        }

        return '';
    }

    /**
     * Détermine le niveau de correspondance
     */
    private function getMatchLevel($similarityScore)
    {
        if ($similarityScore >= 0.9)
            return 'excellent';
        if ($similarityScore >= 0.7)
            return 'bon';
        if ($similarityScore >= 0.6)
            return 'moyen';
        return 'faible';
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

        $words = explode(" ", $variationClean);

        $stopWords = [
            'de',
            'le',
            'la',
            'les',
            'un',
            'une',
            'des',
            'du',
            'et',
            'ou',
            'pour',
            'avec',
            'the',
            'a',
            'an',
            'and',
            'or',
            'ml',
            'edition',
            'édition'
        ];

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
     * Prépare les termes de recherche pour le mode BOOLEAN FULLTEXT
     */
    private function prepareSearchTerms(string $search): string
    {
        $searchClean = preg_replace('/[^a-zA-ZÀ-ÿ\s]/', ' ', $search);
        $searchClean = trim(preg_replace('/\s+/', ' ', $searchClean));
        $searchClean = mb_strtolower($searchClean);

        $words = explode(" ", $searchClean);

        $stopWords = [
            'de',
            'le',
            'la',
            'les',
            'un',
            'une',
            'des',
            'du',
            'et',
            'ou',
            'pour',
            'avec',
            'the',
            'a',
            'an',
            'and',
            'or',
            'eau',
            'ml',
            'edition',
            'édition',
            'coffret'
        ];

        $significantWords = [];

        foreach ($words as $word) {
            $word = trim($word);

            if (strlen($word) > 2 && !in_array($word, $stopWords)) {
                $significantWords[] = $word;
            }

            if (count($significantWords) >= 3) {
                break;
            }
        }

        $booleanTerms = array_map(function ($word) {
            return '+' . $word . '*';
        }, $significantWords);

        return implode(' ', $booleanTerms);
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
     * Retourne le libellé pour le statut de prix (Cosmaparfumerie)
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

    /**
     * Récupère l'URL du produit de manière sécurisée
     */
    public function getProductUrl($product)
    {
        if (isset($product->product_url)) {
            return $product->product_url;
        }

        if (isset($product->url)) {
            return $product->url;
        }

        return null;
    }

    /**
     * Méthode pour calculer la similarité pour la recherche manuelle si nécessaire
     */
    public function calculateManualSimilarity($product)
    {
        if (isset($product->similarity_score) && isset($product->match_level)) {
            return [
                'similarity_score' => $product->similarity_score,
                'match_level' => $product->match_level
            ];
        }

        if (!empty($this->searchQuery)) {
            $similarityScore = $this->computeOverallSimilarity($product, $this->searchQuery);
            $matchLevel = $this->getMatchLevel($similarityScore);

            return [
                'similarity_score' => $similarityScore,
                'match_level' => $matchLevel
            ];
        }

        return [
            'similarity_score' => null,
            'match_level' => null
        ];
    }

    /**
     * Récupère l'image du produit de manière sécurisée avec URL par défaut
     */
    public function getProductImage($product)
    {
        if (isset($product->image) && !empty($product->image)) {
            return $product->image;
        }

        if (isset($product->image_url) && !empty($product->image_url)) {
            return $product->image_url;
        }

        if (isset($product->thumbnail) && !empty($product->thumbnail)) {
            return $product->thumbnail;
        }

        if (isset($product->swatch_image) && !empty($product->swatch_image)) {
            return $product->swatch_image;
        }

        if (isset($product->media_gallery) && !empty($product->media_gallery)) {
            $images = json_decode($product->media_gallery, true);
            if (is_array($images) && !empty($images)) {
                return $images[0] ?? null;
            }
        }

        return 'https://placehold.co/400x400/cccccc/999999?text=No+Image';
    }

    /**
     * Vérifie si une URL d'image est valide
     */
    public function isValidImageUrl($url)
    {
        if (empty($url)) {
            return false;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        $path = parse_url($url, PHP_URL_PATH);
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return in_array(strtolower($extension), $imageExtensions);
    }
}; ?>

<div>
    <!-- Overlay de chargement global - Uniquement visible lors d'une action Livewire -->
    <div wire:loading.delay.flex class="hidden fixed inset-0 z-50 items-center justify-center bg-transparent">
        <div class="flex flex-col items-center justify-center bg-white/90 backdrop-blur-sm rounded-2xl p-8 shadow-2xl border border-white/20 min-w-[200px]">
            <!-- Spinner -->
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mb-4"></div>
            
            <!-- Texte de chargement -->
            <p class="text-lg font-semibold text-gray-800">Chargement</p>
            <p class="text-sm text-gray-600 mt-1">Veuillez patienter...</p>
        </div>
    </div>

    <!-- Indicateur de chargement pour les filtres - Uniquement lors du filtrage -->
    <div wire:loading.delay.flex wire:target="filters.vendor, filters.name, filters.variation, filters.type, filters.site_source" class="hidden fixed top-4 right-4 z-40 items-center justify-center">
        <div class="bg-blue-500/90 backdrop-blur-sm text-white px-4 py-2 rounded-lg shadow-lg flex items-center space-x-2">
            <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-white"></div>
            <span class="text-sm">Filtrage en cours...</span>
        </div>
    </div>

    <livewire:plateformes.detail :id="$id"/>

    <!-- Section d'analyse des prix (uniquement si on a des données) -->
    @if($hasData && $referencePrice && count($matchedProducts) > 0)
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

    <!-- Section des résultats - TOUJOURS AFFICHÉE -->
    <div class="mx-auto w-full px-4 py-6 sm:px-6 lg:px-8">
        <!-- Message d'information si pas de résultats automatiques -->
        @if(!$hasData && $showTable)
            <div class="mb-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-yellow-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span class="text-sm font-medium text-yellow-800">
                        Aucun résultat trouvé automatiquement. Utilisez les filtres ci-dessous pour rechercher manuellement.
                    </span>
                </div>
                <p class="mt-2 text-sm text-yellow-700">
                    Le vendor a été pré-rempli à partir de votre recherche. Vous pouvez ajuster les autres filtres pour trouver des produits.
                </p>
            </div>
        @endif

        @if($hasData && $isAutomaticSearch)
            <!-- Indicateur de similarité (uniquement si on a des résultats automatiques) -->
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
                            <!-- Boutons avec indicateurs de chargement -->
                            <button wire:click="adjustSimilarityThreshold(0.5)" 
                                    class="px-2 py-1 text-xs {{ $similarityThreshold == 0.5 ? 'bg-blue-600 text-white' : 'bg-blue-100 text-blue-800' }} rounded transition-colors flex items-center justify-center min-w-[50px]"
                                    wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="adjustSimilarityThreshold(0.5)">50%</span>
                                <span wire:loading wire:target="adjustSimilarityThreshold(0.5)">
                                    <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-blue-600"></div>
                                </span>
                            </button>
                            <button wire:click="adjustSimilarityThreshold(0.6)" 
                                    class="px-2 py-1 text-xs {{ $similarityThreshold == 0.6 ? 'bg-blue-600 text-white' : 'bg-blue-100 text-blue-800' }} rounded transition-colors flex items-center justify-center min-w-[50px]"
                                    wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="adjustSimilarityThreshold(0.6)">60%</span>
                                <span wire:loading wire:target="adjustSimilarityThreshold(0.6)">
                                    <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-blue-600"></div>
                                </span>
                            </button>
                            <button wire:click="adjustSimilarityThreshold(0.7)" 
                                    class="px-2 py-1 text-xs {{ $similarityThreshold == 0.7 ? 'bg-blue-600 text-white' : 'bg-blue-100 text-blue-800' }} rounded transition-colors flex items-center justify-center min-w-[50px]"
                                    wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="adjustSimilarityThreshold(0.7)">70%</span>
                                <span wire:loading wire:target="adjustSimilarityThreshold(0.7)">
                                    <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-blue-600"></div>
                                </span>
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
        @endif

        <!-- Indicateur des filtres actifs -->
        @if(array_filter($filters))
            <div class="mb-4 p-3 bg-blue-50 rounded-lg border border-blue-200">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 text-blue-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path>
                        </svg>
                        <span class="text-sm font-medium text-blue-800">Filtres actifs :</span>
                    </div>
                    <!-- Bouton Réinitialiser avec indicateur de chargement -->
                    <button wire:click="resetFilters" 
                            class="px-3 py-1.5 text-sm bg-red-50 text-red-700 hover:bg-red-100 rounded-md transition-colors duration-200 flex items-center border border-red-200"
                            wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="resetFilters">
                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                            Réinitialiser les filtres
                        </span>
                        <span wire:loading wire:target="resetFilters">
                            <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-red-600 mr-1"></div>
                            Réinitialisation...
                        </span>
                    </button>
                </div>

                <div class="mt-2 flex flex-wrap gap-2">
                    <!-- FILTRE VENDOR AJOUTÉ -->
                    @if($filters['vendor'])
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800 border border-blue-200">
                            Marque: {{ $filters['vendor'] }}
                            <button wire:click="$set('filters.vendor', '')" 
                                    class="ml-2 text-blue-600 hover:text-blue-800 flex items-center"
                                    wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="$set('filters.vendor', '')">×</span>
                                <span wire:loading wire:target="$set('filters.vendor', '')">
                                    <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-blue-600"></div>
                                </span>
                            </button>
                        </span>
                    @endif

                    @if($filters['name'])
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800 border border-green-200">
                            Nom: {{ $filters['name'] }}
                            <button wire:click="$set('filters.name', '')" 
                                    class="ml-2 text-green-600 hover:text-green-800 flex items-center"
                                    wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="$set('filters.name', '')">×</span>
                                <span wire:loading wire:target="$set('filters.name', '')">
                                    <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-green-600"></div>
                                </span>
                            </button>
                        </span>
                    @endif

                    @if($filters['variation'])
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-800 border border-purple-200">
                            Variation: {{ $filters['variation'] }}
                            <button wire:click="$set('filters.variation', '')" 
                                    class="ml-2 text-purple-600 hover:text-purple-800 flex items-center"
                                    wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="$set('filters.variation', '')">×</span>
                                <span wire:loading wire:target="$set('filters.variation', '')">
                                    <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-purple-600"></div>
                                </span>
                            </button>
                        </span>
                    @endif

                    @if($filters['type'])
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-orange-100 text-orange-800 border border-orange-200">
                            Type: {{ $filters['type'] }}
                            <button wire:click="$set('filters.type', '')" 
                                    class="ml-2 text-orange-600 hover:text-orange-800 flex items-center"
                                    wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="$set('filters.type', '')">×</span>
                                <span wire:loading wire:target="$set('filters.type', '')">
                                    <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-orange-600"></div>
                                </span>
                            </button>
                        </span>
                    @endif

                    @if($filters['site_source'])
                        @php
                            $selectedSite = $sites->firstWhere('id', $filters['site_source']);
                            $siteName = $selectedSite ? $selectedSite->name : 'Site ID: ' . $filters['site_source'];
                        @endphp
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800 border border-indigo-200">
                            Site: {{ $siteName }}
                            <button wire:click="$set('filters.site_source', '')" 
                                    class="ml-2 text-indigo-600 hover:text-indigo-800 flex items-center"
                                    wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="$set('filters.site_source', '')">×</span>
                                <span wire:loading wire:target="$set('filters.site_source', '')">
                                    <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-indigo-600"></div>
                                </span>
                            </button>
                        </span>
                    @endif
                </div>
            </div>
        @endif

<!-- Tableau des résultats - TOUJOURS AFFICHÉ -->
@if($showTable)
    <div class="bg-white shadow-sm rounded-lg overflow-hidden" wire:loading.class="opacity-50" wire:target="adjustSimilarityThreshold, resetFilters, updatedFilters">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">
                @if($hasData)
                    @if($isAutomaticSearch)
                        Résultats de la recherche automatique ({{ count($matchedProducts) }} produit(s))
                    @else
                        Résultats de la recherche manuelle ({{ count($matchedProducts) }} produit(s))
                    @endif
                @else
                    Recherche manuelle - Utilisez les filtres
                @endif
            </h3>
            <p class="mt-1 text-sm text-gray-500">
                @if($hasData)
                    <span wire:loading.remove wire:target="adjustSimilarityThreshold, resetFilters, updatedFilters">
                        {{ count($matchedProducts) }} produit(s) trouvé(s)
                    </span>
                @else
                    Aucun résultat automatique. Utilisez les filtres pour rechercher manuellement.
                @endif
            </p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <!-- NOUVELLE COLONNE : Image (TOUJOURS VISIBLE) -->
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <div class="flex flex-col">
                                <span>Image</span>
                            </div>
                        </th>
                        
                        @if($hasData && $isAutomaticSearch)
                        <!-- Colonne Score (uniquement si résultats automatiques) -->
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <div class="flex flex-col">
                                <span>Score</span>
                            </div>
                        </th>
                        
                        <!-- Colonne Correspondance (uniquement si résultats automatiques) -->
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <div class="flex flex-col">
                                <span>Correspondance</span>
                            </div>
                        </th>
                        @endif
                        
                        <!-- Colonne Vendor avec filtre AJOUTÉE -->
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-48">
                            <div class="flex flex-col space-y-2">
                                <span class="whitespace-nowrap">Marque/Vendor</span>
                                <div class="relative">
                                    <input type="text" 
                                           disabled
                                           wire:model.live.debounce.800ms="filters.vendor"
                                           placeholder="Filtrer par marque..."
                                           class="px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 w-full"
                                           wire:loading.attr="disabled">
                                    <div wire:loading wire:target="filters.vendor" class="absolute right-3 top-1/2 transform -translate-y-1/2">
                                        <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-blue-600"></div>
                                    </div>
                                </div>
                            </div>
                        </th>
                        
                        <!-- Colonne Nom avec filtre -->
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-64" style="width: 30%;">
                            <!-- Largeur ajustée -->
                            <div class="flex flex-col space-y-2">
                                <span class="whitespace-nowrap">Nom</span>
                                <div class="relative">
                                    <input type="text" 
                                        wire:model.live.debounce.800ms="filters.name"
                                        placeholder="Filtrer..."
                                        class="px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 w-full"
                                        wire:loading.attr="disabled">
                                    <div wire:loading wire:target="filters.name" class="absolute right-3 top-1/2 transform -translate-y-1/2">
                                        <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-blue-600"></div>
                                    </div>
                                </div>
                            </div>
                        </th>
                        
                        <!-- Colonne Variation avec filtre -->
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <div class="flex flex-col space-y-1">
                                <span>Variation</span>
                                <div class="relative">
                                    <input type="text" 
                                           wire:model.live.debounce.800ms="filters.variation"
                                           placeholder="Filtrer..."
                                           class="px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 w-full"
                                           wire:loading.attr="disabled">
                                    <div wire:loading wire:target="filters.variation" class="absolute right-2 top-1/2 transform -translate-y-1/2">
                                        <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-blue-600"></div>
                                    </div>
                                </div>
                            </div>
                        </th>
                        
                        <!-- Colonne Site Source avec filtre -->
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <div class="flex flex-col space-y-1">
                                <span>Site Source</span>
                                <div class="relative">
                                    <select wire:model.live="filters.site_source"
                                            class="px-2 py-1 text-xs border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 w-full"
                                            wire:loading.attr="disabled">
                                        <option value="">Tous</option>
                                        @foreach($sites as $site)
                                            <option value="{{ $site->id }}">{{ $site->name }}</option>
                                        @endforeach
                                    </select>
                                    <div wire:loading wire:target="filters.site_source" class="absolute right-2 top-1/2 transform -translate-y-1/2">
                                        <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-blue-600"></div>
                                    </div>
                                </div>
                            </div>
                        </th>
                        
                        <!-- Colonne Prix HT -->
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <div class="flex flex-col">
                                <span>Prix HT</span>
                            </div>
                        </th>
                        
                        <!-- Colonne Date MAJ Prix -->
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <div class="flex flex-col">
                                <span>Date MAJ Prix</span>
                            </div>
                        </th>
                        
                        @if($hasData && $referencePrice)
                        <!-- Colonne Vs Cosmaparfumerie (uniquement si on a un prix de référence) -->
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <div class="flex flex-col">
                                <span>Vs Cosmaparfumerie</span>
                            </div>
                        </th>
                        
                        <!-- Colonne Vs Cosmashop (uniquement si on a un prix de référence) -->
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <div class="flex flex-col">
                                <span>Vs Cosmashop</span>
                            </div>
                        </th>
                        @endif
                        
                        <!-- Colonne Type avec filtre -->
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <div class="flex flex-col space-y-1">
                                <span>Type</span>
                                <div class="relative">
                                    <input type="text" 
                                           wire:model.live.debounce.800ms="filters.type"
                                           placeholder="Filtrer..."
                                           class="px-2 py-1 text-xs border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 w-full"
                                           wire:loading.attr="disabled">
                                    <div wire:loading wire:target="filters.type" class="absolute right-2 top-1/2 transform -translate-y-1/2">
                                        <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-blue-600"></div>
                                    </div>
                                </div>
                            </div>
                        </th>
                        
                        <!-- Colonne Actions -->
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <div class="flex flex-col">
                                <span>Actions</span>
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @if(count($matchedProducts) > 0)
                        @foreach($matchedProducts as $product)
                            @php
                                // Pour la recherche manuelle, on calcule la similarité à la volée si nécessaire
                                if ($isAutomaticSearch) {
                                    $similarityScore = $product->similarity_score ?? null;
                                    $matchLevel = $product->match_level ?? null;
                                } else {
                                    // Pour la recherche manuelle, on calcule la similarité
                                    $similarityData = $this->calculateManualSimilarity($product);
                                    $similarityScore = $similarityData['similarity_score'];
                                    $matchLevel = $similarityData['match_level'];
                                }
                                
                                // Définir la classe de match si disponible
                                if ($matchLevel) {
                                    $matchClass = [
                                        'excellent' => 'bg-green-100 text-green-800 border-green-300',
                                        'bon' => 'bg-blue-100 text-blue-800 border-blue-300',
                                        'moyen' => 'bg-yellow-100 text-yellow-800 border-yellow-300',
                                        'faible' => 'bg-gray-100 text-gray-800 border-gray-300'
                                    ][$matchLevel];
                                }

                                $productVolumes = $this->extractVolumesFromText($product->name . ' ' . $product->variation);
                                $hasMatchingVolume = $this->hasMatchingVolume($product);
                                $hasExactVariation = $this->hasExactVariationMatch($product);

                                // Données pour la comparaison de prix (uniquement si référencePrice)
                                if ($referencePrice) {
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
                                }
                            @endphp
                            <tr class="hover:bg-gray-50 transition-colors duration-150">
                                <!-- NOUVELLE COLONNE : Image (TOUJOURS VISIBLE) -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @php
                                        $productImage = $this->getProductImage($product);
                                        $productName = $product->name ?? 'Produit sans nom';
                                    @endphp
                                    <div class="relative group">
                                        <img src="{{ $productImage }}" 
                                             alt="{{ $productName }}" 
                                             class="h-20 w-20 object-cover rounded-lg shadow-sm border border-gray-200 hover:shadow-md transition-shadow duration-200"
                                             loading="lazy"
                                             onerror="this.onerror=null; this.src='https://placehold.co/400x400/cccccc/999999?text=No+Image'">
                                        
                                        <!-- Overlay au survol pour agrandir -->
                                        <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-10 rounded-lg transition-all duration-200 flex items-center justify-center opacity-0 group-hover:opacity-100">
                                            <svg class="w-6 h-6 text-white opacity-0 group-hover:opacity-70 transition-opacity duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7"></path>
                                            </svg>
                                        </div>
                                    </div>
                                    
                                    <!-- Indicateur si pas d'image -->
                                    @if(!$this->isValidImageUrl($productImage) || str_contains($productImage, 'placehold.co'))
                                        <div class="mt-1 text-center">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800">
                                                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                                </svg>
                                                Sans image
                                            </span>
                                        </div>
                                    @endif
                                </td>
                                
                                @if($hasData && $isAutomaticSearch)
                                <!-- Colonne Score (uniquement si résultats automatiques) -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="w-16 bg-gray-200 rounded-full h-2 mr-3">
                                            <div class="h-2 rounded-full 
                                                @if($similarityScore >= 0.9) bg-green-500
                                                @elseif($similarityScore >= 0.7) bg-blue-500
                                                @elseif($similarityScore >= 0.6) bg-yellow-500
                                                @else bg-gray-500 @endif"
                                                style="width: {{ ($similarityScore ?? 0) * 100 }}%">
                                            </div>
                                        </div>
                                        <span class="text-sm font-mono font-semibold 
                                            @if($similarityScore >= 0.9) text-green-600
                                            @elseif($similarityScore >= 0.7) text-blue-600
                                            @elseif($similarityScore >= 0.6) text-yellow-600
                                            @else text-gray-600 @endif">
                                            {{ $similarityScore ? number_format($similarityScore * 100, 0) : 'N/A' }}%
                                        </span>
                                    </div>
                                </td>

                                <!-- Colonne Correspondance (uniquement si résultats automatiques) -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if($matchLevel)
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium border {{ $matchClass ?? '' }}">
                                            @if($matchLevel === 'excellent')
                                                <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                                </svg>
                                            @endif
                                            {{ ucfirst($matchLevel) }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium border border-gray-300 bg-gray-100 text-gray-800">
                                            N/A
                                        </span>
                                    @endif
                                </td>
                                @endif

                                <!-- Colonne Vendor AJOUTÉE -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">
                                        {{ $product->vendor ?? 'N/A' }}
                                    </div>
                                </td>

                                <!-- Colonne Nom -->
                                <td class="px-6 py-4">
                                    <div class="text-sm font-medium text-gray-900 max-w-xs" title="{{ $product->name ?? 'N/A' }}">
                                        @if($isAutomaticSearch && !empty($searchVolumes))
                                            {!! $this->highlightMatchingTerms($product->name) !!}
                                        @else
                                            {{ $product->name ?? 'N/A' }}
                                        @endif
                                    </div>
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
                                        @if($isAutomaticSearch && !empty($searchVariationKeywords))
                                            {!! $this->highlightMatchingTerms($product->variation ?? 'Standard') !!}
                                        @else
                                            {{ $product->variation ?? 'Standard' }}
                                        @endif
                                    </div>
                                    @if($hasData && $hasExactVariation)
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
                                                @php
                                                    $productUrl = $this->getProductUrl($product);
                                                    $domain = $this->extractDomain($productUrl ?? '');
                                                    echo strtoupper(substr($domain, 0, 2));
                                                @endphp
                                            </span>
                                        </div>
                                        <div>
                                            <div class="text-sm font-medium text-gray-900">
                                                {{ $product->site_name ?? $this->extractDomain($productUrl ?? '') }}
                                            </div>
                                            @if(isset($product->web_site_id))
                                                <div class="text-xs text-gray-500">
                                                    ID: {{ $product->web_site_id }}
                                                </div>
                                            @endif
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

                                @if($referencePrice)
                                <!-- Colonne Vs Cosmaparfumerie (uniquement si référencePrice) -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if(is_numeric($competitorPrice) && is_numeric($referencePrice))
                                        <div class="space-y-1">
                                            <div class="text-xs text-gray-500">
                                                prix cosma-parfumerie: {{ number_format($referencePrice, 2, ',', ' ') }} €
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

                                <!-- Colonne Vs Cosmashop (uniquement si référencePrice) -->
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
                                        @php
                                            $productUrl = $this->getProductUrl($product);
                                        @endphp
                                        @if(!empty($productUrl))
                                            <a href="{{ $productUrl }}" target="_blank" rel="noopener noreferrer"
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
                    @else
                        <!-- Aucun résultat avec les filtres appliqués -->
                        <tr>
                            <td colspan="{{ ($hasData && $isAutomaticSearch ? 15 : 13) }}" class="px-6 py-12 text-center">
                                <svg class="mx-auto h-16 w-16 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <h3 class="mt-4 text-lg font-medium text-gray-900">
                                    @if(array_filter($filters))
                                        Aucun résultat avec les filtres actuels
                                    @else
                                        Aucun produit trouvé
                                    @endif
                                </h3>
                                <p class="mt-2 text-sm text-gray-500">
                                    @if(array_filter($filters))
                                        Aucun produit ne correspond à vos critères de recherche. Essayez de modifier les filtres.
                                    @else
                                        Ajustez les filtres pour trouver des produits.
                                    @endif
                                </p>
                                @if(array_filter($filters))
                                    <div class="mt-4 flex justify-center space-x-3">
                                        <!-- Bouton Réinitialiser avec loading -->
                                        <button wire:click="resetFilters" 
                                                class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-red-600 hover:bg-red-700 focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-colors duration-200"
                                                wire:loading.attr="disabled">
                                            <span wire:loading.remove wire:target="resetFilters">
                                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                                </svg>
                                                Réinitialiser les filtres
                                            </span>
                                            <span wire:loading wire:target="resetFilters">
                                                <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2"></div>
                                                Réinitialisation...
                                            </span>
                                        </button>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>
@endif
    </div>
</div>

@push('styles')
    <style>
        /* Style pour les filtres dans le thead */
        th .flex-col {
            min-height: 70px;
            justify-content: space-between;
        }

        /* Style pour les inputs de filtres */
        input[type="text"], select {
            transition: all 0.2s ease;
        }

        input[type="text"]:focus, select:focus {
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
            border-color: #3b82f6;
        }

        /* Style pour les filtres actifs */
        .filter-active {
            background-color: rgba(59, 130, 246, 0.1);
            border-left: 3px solid #3b82f6;
        }

        /* Style pour les badges de filtres */
        .filter-badge {
            transition: all 0.2s ease;
        }

        .filter-badge:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        /* Animation pour les boutons */
        button {
            transition: all 0.2s ease;
        }

        button:hover {
            transform: translateY(-1px);
        }

        button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        /* Style pour les colonnes avec filtres */
        th.with-filter {
            background-color: #f9fafb;
        }

        /* Animation de spin pour les loaders */
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .animate-spin {
            animation: spin 1s linear infinite;
        }

        /* Style pour les indicateurs de chargement dans les inputs */
        .relative .animate-spin {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
        }

        /* Style pour l'overlay de chargement global - Transparent */
        .fixed.inset-0 {
            z-index: 9999;
            background-color: transparent !important;
        }

        .fixed.inset-0 > div {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(8px);
            box-shadow: 
                0 20px 40px rgba(0, 0, 0, 0.1),
                0 0 0 1px rgba(255, 255, 255, 0.1);
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        /* Style pour le loader des filtres */
        .fixed.top-4.right-4 {
            z-index: 9998;
            animation: slideInRight 0.3s ease-out;
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        /* Transition pour l'opacité du tableau */
        .opacity-50 {
            transition: opacity 0.3s ease;
        }
    </style>
@endpush

@push('scripts')
    <script>
        // Script pour gérer les indicateurs de chargement
        document.addEventListener('livewire:init', () => {
            // Désactiver les inputs pendant le chargement
            Livewire.hook('request', ({ fail }) => {
                // Ajouter un indicateur visuel
                document.body.style.cursor = 'wait';

                fail(() => {
                    document.body.style.cursor = 'default';
                });
            });

            Livewire.hook('response', ({ component }) => {
                document.body.style.cursor = 'default';
            });
        });
    </script>
@endpush>