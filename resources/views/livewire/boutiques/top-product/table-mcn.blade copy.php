<?php

namespace App\Livewire;

use Livewire\Volt\Component;
use App\Models\DetailProduct;
use App\Models\Comparaison;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

new class extends Component {

    public int $id;
    public string $listTitle = '';
    public bool $loading = true;
    public bool $loadingMore = false;
    public bool $hasMore = true;
    public int $page = 1;
    public int $perPage = 200;
    public int $totalPages = 1;
    
    // Nouvelles propriétés pour la recherche de concurrents
    public array $competitorResults = [];
    public bool $searchingCompetitors = false;
    public array $searchingProducts = []; // Track which products are being searched
    public array $expandedProducts = []; // Track which products are expanded
    
    // Cache
    protected $cacheTTL = 3600;

    public function mount($id): void
    {
        $this->id = $id;
        $this->loadListTitle();
    }

    public function loadListTitle(): void
    {
        try {
            $list = Comparaison::find($this->id);
            $this->listTitle = $list ? $list->libelle : 'Liste non trouvée';
        } catch (\Exception $e) {
            $this->listTitle = 'Erreur de chargement';
        }
    }

    /**
     * Nettoyer un prix (assure qu'il est numérique)
     */
    protected function cleanPrice($price): float
    {
        if ($price === null || $price === '' || $price === false) {
            return 0.0;
        }

        if (is_numeric($price)) {
            return (float) $price;
        }

        if (is_string($price)) {
            // Supprimer tous les caractères non numériques sauf les virgules, points et tirets
            $cleanPrice = preg_replace('/[^\d,.-]/', '', $price);
            // Remplacer les virgules par des points
            $cleanPrice = str_replace(',', '.', $cleanPrice);
            
            // Si le prix a plusieurs points (ex: 1.234.56), garder seulement le dernier
            $parts = explode('.', $cleanPrice);
            if (count($parts) > 2) {
                $cleanPrice = $parts[0] . '.' . end($parts);
            }

            if (is_numeric($cleanPrice)) {
                return (float) $cleanPrice;
            }
        }

        return 0.0;
    }

    /**
     * Formater un prix pour l'affichage
     */
    public function formatPrice($price): string
    {
        $cleanPrice = $this->cleanPrice($price);
        return number_format($cleanPrice, 2, ',', ' ') . ' €';
    }

    /**
     * Rechercher les concurrents pour un produit spécifique
     */
    public function searchCompetitorsForProduct(string $sku, string $productName, $price): void
    {
        $this->searchingProducts[$sku] = true;
        
        try {
            // Nettoyer le nom du produit
            $cleanedProductName = $this->normalizeAndCleanText($productName);

            // Nettoyer le prix
            $cleanPrice = $this->cleanPrice($price);
            
            // Utiliser l'algorithme de recherche amélioré
            $competitors = $this->findCompetitorsForProduct($cleanedProductName, $cleanPrice);
            
            if (!empty($competitors)) {
                $this->competitorResults[$sku] = [
                    'product_name' => $cleanedProductName,
                    'our_price' => $cleanPrice,
                    'competitors' => $competitors,
                    'count' => count($competitors)
                ];
            } else {
                $this->competitorResults[$sku] = [
                    'product_name' => $cleanedProductName,
                    'our_price' => $cleanPrice,
                    'competitors' => [],
                    'count' => 0
                ];
            }

        } catch (\Exception $e) {
            $this->competitorResults[$sku] = [
                'product_name' => $productName,
                'our_price' => $this->cleanPrice($price),
                'competitors' => [],
                'count' => 0,
                'error' => $e->getMessage()
            ];
        } finally {
            unset($this->searchingProducts[$sku]);
        }
    }

    /**
     * Rechercher les concurrents pour TOUS les produits de la page
     */
    public function searchAllCompetitorsOnPage(): void
    {
        $this->searchingCompetitors = true;
        
        try {
            $currentProducts = $this->getCurrentPageProducts();
            
            foreach ($currentProducts as $product) {
                $sku = $product['sku'] ?? '';
                $productName = $product['title'] ?? '';
                $price = $product['price'] ?? 0;
                
                if (!empty($sku) && !empty($productName)) {
                    $this->searchCompetitorsForProduct($sku, $productName, $price);
                }
            }

        } catch (\Exception $e) {
            // Erreur silencieuse
        } finally {
            $this->searchingCompetitors = false;
        }
    }

    /**
     * Basculer l'affichage des concurrents pour un produit
     */
    public function toggleCompetitors(string $sku): void
    {
        if (isset($this->expandedProducts[$sku])) {
            unset($this->expandedProducts[$sku]);
        } else {
            $this->expandedProducts[$sku] = true;
            
            // Si pas encore recherché, rechercher les concurrents
            if (!isset($this->competitorResults[$sku])) {
                $product = $this->findProductBySku($sku);
                if ($product) {
                    $this->searchCompetitorsForProduct($sku, $product['title'] ?? '', $product['price'] ?? 0);
                }
            }
        }
    }

    /**
     * Obtenir les produits de la page courante
     */
    protected function getCurrentPageProducts(): array
    {
        try {
            $allSkus = DetailProduct::where('list_product_id', $this->id)
                ->pluck('EAN')
                ->unique()
                ->values()
                ->toArray();

            $offset = ($this->page - 1) * $this->perPage;
            $pageSkus = array_slice($allSkus, $offset, $this->perPage);

            if (empty($pageSkus)) {
                return [];
            }

            $placeholders = implode(',', array_fill(0, count($pageSkus), '?'));

            $query = "
                SELECT 
                    produit.sku as sku,
                    CAST(product_char.name AS CHAR CHARACTER SET utf8mb4) as title,
                    ROUND(product_decimal.price, 2) as price
                FROM catalog_product_entity as produit
                LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
                LEFT JOIN product_decimal ON product_decimal.entity_id = produit.entity_id
                LEFT JOIN product_int ON product_int.entity_id = produit.entity_id
                WHERE produit.sku IN ($placeholders)
                AND product_int.status >= 0
                ORDER BY FIELD(produit.sku, " . implode(',', $pageSkus) . ")
            ";

            $result = DB::connection('mysqlMagento')->select($query, $pageSkus);
            return array_map(fn($p) => (array) $p, $result);

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Trouver un produit par son SKU
     */
    protected function findProductBySku(string $sku): ?array
    {
        try {
            $query = "
                SELECT 
                    produit.sku as sku,
                    CAST(product_char.name AS CHAR CHARACTER SET utf8mb4) as title,
                    ROUND(product_decimal.price, 2) as price
                FROM catalog_product_entity as produit
                LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
                LEFT JOIN product_decimal ON product_decimal.entity_id = produit.entity_id
                LEFT JOIN product_int ON product_int.entity_id = produit.entity_id
                WHERE produit.sku = ?
                AND product_int.status >= 0
            ";

            $result = DB::connection('mysqlMagento')->select($query, [$sku]);
            
            if (!empty($result)) {
                $product = (array) $result[0];
                // Nettoyer le prix
                $product['price'] = $this->cleanPrice($product['price']);
                return $product;
            }
            
            return null;

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Algorithme de recherche de concurrents AMÉLIORÉ
     * Utilise la même logique que le premier composant
     */
    protected function findCompetitorsForProduct(string $search, float $ourPrice): array
    {
        try {
            // Cache pour éviter les recherches répétées
            $cacheKey = 'competitor_search_' . md5($search . '_' . $ourPrice);
            $cached = Cache::get($cacheKey);
            
            if ($cached !== null) {
                return $cached;
            }
            
            // 1. Extraire le vendor de manière intelligente (comme le premier composant)
            $vendor = $this->extractVendorFromSearchImproved($search);
            
            // 2. Extraire les mots de recherche significatifs (méthode améliorée)
            $searchKeywords = $this->extractSearchKeywords($search, $vendor);
            
            // 3. Extraire les composants de la recherche
            $components = $this->extractSearchComponentsImproved($search, $vendor, $searchKeywords);
            
            // 4. Préparer les variations du vendor
            $vendorVariations = $this->getVendorVariationsImproved($vendor);
            
            // 5. Recherche avec plusieurs stratégies
            $competitors = $this->searchWithMultipleStrategies($search, $vendor, $vendorVariations, $searchKeywords, $components);
            
            // 6. Filtrer par similarité améliorée
            $filteredCompetitors = $this->filterBySimilarityImproved($competitors, $search, $components);
            
            // 7. Limiter le nombre de résultats et ajouter les comparaisons
            $limitedCompetitors = array_slice($filteredCompetitors, 0, length: 200); // Limiter à 20 résultats
            
            $competitorsWithComparison = $this->addPriceComparisons($limitedCompetitors, $ourPrice);
            
            Cache::put($cacheKey, $competitorsWithComparison, now()->addHours(1));
            
            return $competitorsWithComparison;

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Normaliser et nettoyer un texte (UTF-8)
     */
    protected function normalizeAndCleanText(string $text): string
    {
        // Vérifier si le texte est déjà en UTF-8
        if (!mb_check_encoding($text, 'UTF-8')) {
            // Essayer de convertir depuis différents encodages
            $encodings = ['ISO-8859-1', 'ISO-8859-15', 'Windows-1252', 'CP1252'];
            
            foreach ($encodings as $encoding) {
                $converted = mb_convert_encoding($text, 'UTF-8', $encoding);
                if (mb_check_encoding($converted, 'UTF-8') && !$this->hasInvalidUtf8($converted)) {
                    $text = $converted;
                    break;
                }
            }
        }
        
        // Normaliser les caractères Unicode (NFC normalization)
        if (function_exists('normalizer_normalize')) {
            $text = normalizer_normalize($text, \Normalizer::FORM_C);
        }
        
        // Remplacer les caractères mal encodés spécifiques
        $replacements = [
            // Caractères mal encodés courants
            '�' => 'é', '�' => 'è', '�' => 'ê', '�' => 'ë',
            '�' => 'à', '�' => 'â', '�' => 'ä',
            '�' => 'î', '�' => 'ï',
            '�' => 'ô', '�' => 'ö',
            '�' => 'ù', '�' => 'û', '�' => 'ü',
            '�' => 'ç',
            '�' => 'É', '�' => 'È', '�' => 'Ê', '�' => 'Ë',
            '�' => 'À', '�' => 'Â', '�' => 'Ä',
            '�' => 'Î', '�' => 'Ï',
            '�' => 'Ô', '�' => 'Ö',
            '�' => 'Ù', '�' => 'Û', '�' => 'Ü',
            '�' => 'Ç',
            // Caractères spéciaux
            '�' => "'", '�' => "'", // Guillemets simples
            '�' => '"', '�' => '"', // Guillemets doubles
            '�' => '€',
            '�' => '...',
            '[' => '', ']' => '', // Supprimer les crochets souvent mal formatés
        ];
        
        $text = strtr($text, $replacements);
        
        // Décoder les entités HTML
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Supprimer les caractères de contrôle et normaliser les espaces
        $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text);
        $text = trim(preg_replace('/\s+/', ' ', $text));
        
        return $text;
    }
    
    /**
     * Vérifier si une chaîne contient des caractères UTF-8 invalides
     */
    protected function hasInvalidUtf8(string $text): bool
    {
        return preg_match('//u', $text) !== 1;
    }

    /**
     * Extraire le vendor de manière plus intelligente
     * Similaire à la méthode du premier composant
     */
    protected function extractVendorFromSearchImproved(string $search): string
    {
        $search = $this->normalizeAndCleanText($search);
        $searchLower = mb_strtolower(trim($search));
        
        // Charger la liste des vendors connus
        $knownVendors = $this->loadKnownVendors();
        
        // Essayer plusieurs stratégies d'extraction
        $vendor = '';
        
        // 1. Recherche par motif "VENDOR - " au début
        if (preg_match('/^([^-]+)\s*-\s*/i', $search, $matches)) {
            $potentialVendor = trim($matches[1]);
            $vendor = $this->findMatchingVendor($potentialVendor, $knownVendors);
        }
        
        // 2. Si pas trouvé, chercher le vendor n'importe où dans la chaîne
        if (empty($vendor)) {
            foreach ($knownVendors as $knownVendor) {
                $knownLower = mb_strtolower($knownVendor);
                if (str_contains($searchLower, $knownLower)) {
                    // Vérifier qu'il n'est pas dans un mot plus long
                    $position = mb_strpos($searchLower, $knownLower);
                    $before = $position > 0 ? mb_substr($searchLower, $position - 1, 1) : ' ';
                    $afterCharPosition = $position + mb_strlen($knownLower);
                    $after = $afterCharPosition < mb_strlen($searchLower) 
                        ? mb_substr($searchLower, $afterCharPosition, 1) 
                        : ' ';
                    
                    // Le vendor doit être délimité par des espaces, tirets, etc.
                    $delimiters = [' ', '-', '(', '[', ',', '.', ';', ':', '/'];
                    if (in_array($before, $delimiters) && in_array($after, $delimiters)) {
                        $vendor = $knownVendor;
                        break;
                    }
                }
            }
        }
        
        // 3. Si toujours pas trouvé, utiliser la première partie avant le premier tiret
        if (empty($vendor)) {
            $parts = preg_split('/\s*-\s*/', $search, 2);
            $firstPart = trim($parts[0]);
            
            // Éviter les mots clés produits
            $productKeywords = $this->getProductKeywords();
            
            $hasProductKeyword = false;
            foreach ($productKeywords as $keyword) {
                if (stripos($firstPart, $keyword) !== false) {
                    $hasProductKeyword = true;
                    break;
                }
            }
            
            if (!$hasProductKeyword && !empty($firstPart)) {
                $vendor = $this->findMatchingVendor($firstPart, $knownVendors);
            }
        }
        
        return $vendor ?: '';
    }

    /**
     * Extraire les mots clés de recherche significatifs
     * Méthode générale qui fonctionne pour tous les types de produits
     */
    protected function extractSearchKeywords(string $search, ?string $vendor = null): array
    {
        $search = $this->normalizeAndCleanText($search);
        
        // Supprimer le vendor si présent
        if (!empty($vendor)) {
            $pattern = '/^' . preg_quote($vendor, '/') . '\s*-\s*/i';
            $searchWithoutVendor = preg_replace($pattern, '', $search);
            
            if ($searchWithoutVendor !== $search) {
                $search = $searchWithoutVendor;
            } else {
                $search = str_ireplace($vendor, '', $search);
            }
        }
        
        // Liste exhaustive de mots à exclure (stop words)
        $stopWords = array_merge(
            $this->getGeneralStopWords(),
            $this->getProductStopWords(),
            $this->getTechnicalTerms()
        );
        
        // Nettoyer et tokeniser
        $search = mb_strtolower($search);
        
        // Supprimer les ponctuations mais garder les tirets pour les mots composés
        $search = preg_replace('/[^\p{L}\p{N}\s-]/u', ' ', $search);
        
        // Tokeniser
        $tokens = preg_split('/[\s-]+/', $search);
        
        // Filtrer les tokens
        $keywords = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            
            // Exclure les stop words, nombres seuls, et tokens trop courts
            if (strlen($token) > 2 && 
                !in_array($token, $stopWords) && 
                !is_numeric($token) &&
                !preg_match('/^\d+ml$/i', $token) && // Exclure "50ml" etc.
                !preg_match('/^\d+g$/i', $token) &&  // Exclure "100g" etc.
                !preg_match('/^\d+\%$/i', $token)) { // Exclure "10%" etc.
                
                // Garder les tokens qui semblent être des mots significatifs
                if ($this->isSignificantWord($token)) {
                    $keywords[] = $token;
                }
            }
        }
        
        // Limiter le nombre de keywords et éviter les doublons
        $keywords = array_unique($keywords);
        $keywords = array_slice($keywords, 0, 10); // Maximum 10 keywords
        
        return $keywords;
    }
    
    /**
     * Vérifier si un mot est significatif
     */
    protected function isSignificantWord(string $word): bool
    {
        // Exclure les codes produits, références, etc.
        if (preg_match('/^[A-Z0-9]{2,}[A-Z0-9-]*$/i', $word) && strlen($word) >= 4) {
            // Probablement un code produit (ex: "HA", "SPF50", "N°5")
            return false;
        }
        
        // Exclure les mots qui sont des tailles/capacités
        $sizePatterns = ['\d+ml', '\d+l', '\d+g', '\d+kg', '\d+oz', '\d+fl'];
        foreach ($sizePatterns as $pattern) {
            if (preg_match('/^' . $pattern . '$/i', $word)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Obtenir les stop words généraux
     */
    protected function getGeneralStopWords(): array
    {
        return [
            'de', 'le', 'la', 'les', 'un', 'une', 'des', 'du', 'et', 'ou', 'pour', 'avec', 'sans',
            'the', 'a', 'an', 'and', 'or', 'in', 'on', 'at', 'by', 'to', 'of', 'for', 'with',
            'à', 'a', 'au', 'aux', 'dans', 'sur', 'sous', 'chez', 'par', 'entre',
            'ml', 'g', 'kg', 'l', 'oz', 'fl', 'cm', 'mm',
            'edition', 'édition', 'coffret', 'spray', 'vapo', 'vaporisateur',
            'limitée', 'limitee', 'spéciale', 'speciale', 'exclusive', 'exclusif'
        ];
    }
    
    /**
     * Obtenir les stop words spécifiques aux produits
     */
    protected function getProductStopWords(): array
    {
        return [
            'eau', 'parfum', 'cologne', 'toilette', 'fraiche',
            'crème', 'creme', 'lotion', 'gel', 'sérum', 'serum', 'baume', 'masque',
            'shampooing', 'après-shampooing', 'soin', 'traitement', 'nettoyant',
            'hydratant', 'hydratante', 'nourrissant', 'nourrissante', 'protecteur', 'protectrice',
            'anti', 'contre', 'pour', 'homme', 'femme', 'unisexe',
            'visage', 'corps', 'mains', 'pieds', 'cheveux', 'peau', 'levres', 'lèvres',
            'normales', 'normaux', 'sèches', 'seches', 'grasse', 'grasses', 'mixtes', 'sensibles',
            'jour', 'nuit', 'matin', 'soir'
        ];
    }
    
    /**
     * Obtenir les termes techniques
     */
    protected function getTechnicalTerms(): array
    {
        return [
            'spf', 'uv', 'uva', 'uvb', 'ha', 'na', 'ph', 'vitamine', 'vitamin',
            'q10', 'collagène', 'collagene', 'élastine', 'elastine', 'acide', 'hyaluronique',
            'rétinol', 'retinol', 'niacinamide', 'peptide', 'anti-oxydant', 'antioxydant'
        ];
    }
    
    /**
     * Obtenir les mots clés produits généraux
     */
    protected function getProductKeywords(): array
    {
        return array_merge(
            $this->getProductStopWords(),
            $this->getTechnicalTerms(),
            [
                'recharge', 'personnalisable', 'rouge', 'blanc', 'noir', 'bleu', 'vert', 'jaune',
                'rose', 'violet', 'orange', 'marron', 'gris', 'doré', 'dore', 'argenté', 'argente',
                'brun', 'amarante', 'satin', 'mat', 'brillant', 'nacré', 'nacre', 'perlé', 'perle'
            ]
        );
    }

    /**
     * Extraire tous les composants de la recherche (comme le premier composant)
     */
    protected function extractSearchComponentsImproved(string $search, ?string $vendor = null, array $keywords = []): array
    {
        $search = $this->normalizeAndCleanText($search);
        
        $components = [
            'vendor' => $vendor,
            'product_name' => '',
            'keywords' => $keywords,
            'variation' => '',
            'volumes' => [],
            'capacities' => [],
            'type' => '',
            'color' => '',
            'finish' => ''
        ];
        
        // Extraire les volumes (ml)
        if (preg_match_all('/(\d+)\s*ml/i', $search, $matches)) {
            $components['volumes'] = $matches[1];
        }
        
        // Extraire les capacités (g, kg, etc.)
        if (preg_match_all('/(\d+)\s*(g|kg|oz|l)/i', $search, $matches)) {
            $components['capacities'] = array_map(function($value, $unit) {
                return $value . strtolower($unit);
            }, $matches[1], $matches[2]);
        }
        
        // Extraire le nom du produit (reste de la recherche après nettoyage)
        $remainingSearch = $search;
        
        if (!empty($vendor)) {
            // Supprimer le vendor
            $pattern = '/^' . preg_quote($vendor, '/') . '\s*-\s*/i';
            $remainingSearch = preg_replace($pattern, '', $remainingSearch);
            
            if ($remainingSearch === $search) {
                $remainingSearch = str_ireplace($vendor, '', $remainingSearch);
            }
        }
        
        // Supprimer les parties techniques
        $technicalParts = array_merge(
            $this->getProductStopWords(),
            $this->getTechnicalTerms(),
            ['ml', 'g', 'kg', 'l', 'oz']
        );
        
        foreach ($technicalParts as $part) {
            $remainingSearch = preg_replace('/\b' . preg_quote($part, '/') . '\b/i', '', $remainingSearch);
        }
        
        // Supprimer les volumes et capacités
        $remainingSearch = preg_replace('/\d+\s*(ml|g|kg|oz|l)/i', '', $remainingSearch);
        
        // Supprimer les nombres isolés
        $remainingSearch = preg_replace('/\s\d+\s/', ' ', $remainingSearch);
        
        // Nettoyer
        $remainingSearch = trim(preg_replace('/\s+/', ' ', $remainingSearch));
        $remainingSearch = preg_replace('/^\s*-\s*|\s*-\s*$/i', '', $remainingSearch);
        
        $components['product_name'] = $remainingSearch;
        
        // Extraire le type
        $types = ['eau de parfum', 'eau de toilette', 'parfum', 'coffret', 'edp', 'edt', 
                 'crème', 'creme', 'lotion', 'gel', 'sérum', 'serum', 'baume', 'masque',
                 'shampooing', 'soin', 'traitement', 'nettoyant', 'hydratant', 'protecteur'];
        
        foreach ($types as $type) {
            if (stripos($search, $type) !== false) {
                $components['type'] = $type;
                break;
            }
        }
        
        // Extraire la couleur
        $colors = ['rouge', 'blanc', 'noir', 'bleu', 'vert', 'jaune', 'rose', 'violet', 
                  'orange', 'marron', 'gris', 'brun', 'amarante', 'doré', 'dore', 'argenté', 'argente'];
        
        foreach ($colors as $color) {
            if (stripos($search, $color) !== false) {
                $components['color'] = $color;
                break;
            }
        }
        
        // Extraire la finition
        $finishes = ['satin', 'mat', 'brillant', 'nacré', 'nacre', 'perlé', 'perle'];
        
        foreach ($finishes as $finish) {
            if (stripos($search, $finish) !== false) {
                $components['finish'] = $finish;
                break;
            }
        }
        
        // Extraire la variation (partie après le deuxième tiret)
        $parts = preg_split('/\s*-\s*/', $search, 3);
        if (count($parts) >= 3) {
            $components['variation'] = trim($parts[2]);
        } elseif (count($parts) == 2) {
            $components['variation'] = trim($parts[1]);
        }
        
        return $components;
    }

    /**
     * Recherche avec plusieurs stratégies
     */
    protected function searchWithMultipleStrategies(string $search, string $vendor, array $vendorVariations, array $keywords, array $components): array
    {
        $allCompetitors = [];
        $seenIds = [];
        
        try {
            // STRATÉGIE 1: Recherche par vendor + keywords
            if (!empty($vendorVariations) && !empty($keywords)) {
                $competitors1 = $this->searchByVendorAndKeywords($vendorVariations, $keywords);
                foreach ($competitors1 as $competitor) {
                    $id = $competitor->id ?? $competitor->url;
                    if (!in_array($id, $seenIds)) {
                        $allCompetitors[] = $competitor;
                        $seenIds[] = $id;
                    }
                }
            }
            
            // STRATÉGIE 2: Recherche FULLTEXT avec la recherche originale
            if (count($allCompetitors) < 10) {
                $searchQuery = $this->prepareSearchTermsForFulltext($search);
                if (!empty($searchQuery)) {
                    $competitors2 = $this->searchByFulltext($searchQuery);
                    foreach ($competitors2 as $competitor) {
                        $id = $competitor->id ?? $competitor->url;
                        if (!in_array($id, $seenIds)) {
                            $allCompetitors[] = $competitor;
                            $seenIds[] = $id;
                        }
                    }
                }
            }
            
            // STRATÉGIE 3: Recherche par vendor seul
            if (count($allCompetitors) < 5 && !empty($vendorVariations)) {
                $competitors3 = $this->searchByVendorOnly($vendorVariations);
                foreach ($competitors3 as $competitor) {
                    $id = $competitor->id ?? $competitor->url;
                    if (!in_array($id, $seenIds)) {
                        $allCompetitors[] = $competitor;
                        $seenIds[] = $id;
                    }
                }
            }
            
            // STRATÉGIE 4: Recherche par type et caractéristiques
            if (count($allCompetitors) < 5 && (!empty($components['type']) || !empty($components['color']))) {
                $competitors4 = $this->searchByTypeAndFeatures($components);
                foreach ($competitors4 as $competitor) {
                    $id = $competitor->id ?? $competitor->url;
                    if (!in_array($id, $seenIds)) {
                        $allCompetitors[] = $competitor;
                        $seenIds[] = $id;
                    }
                }
            }
            
            // Traiter les images
            foreach ($allCompetitors as $competitor) {
                $competitor->image = $this->getCompetitorImage($competitor);
            }
            
            return $allCompetitors;
            
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Recherche par vendor + keywords
     */
    protected function searchByVendorAndKeywords(array $vendorVariations, array $keywords): array
    {
        try {
            if (empty($keywords)) {
                return [];
            }
            
            $vendorConditions = [];
            $params = [];
            
            foreach ($vendorVariations as $variation) {
                $vendorConditions[] = "lp.vendor LIKE ?";
                $params[] = '%' . $variation . '%';
            }
            
            // Construire les conditions pour les keywords
            $keywordConditions = [];
            foreach (array_slice($keywords, 0, 5) as $keyword) { // Limiter à 5 keywords
                $keywordConditions[] = "(lp.name LIKE ? OR lp.variation LIKE ?)";
                $params[] = '%' . $keyword . '%';
                $params[] = '%' . $keyword . '%';
            }
            
            $query = "
                SELECT 
                    lp.*,
                    ws.name as site_name,
                    lp.image_url as image_url,
                    lp.url as product_url
                FROM last_price_scraped_product lp
                LEFT JOIN web_site ws ON lp.web_site_id = ws.id
                WHERE (lp.variation != 'Standard' OR lp.variation IS NULL OR lp.variation = '')
                AND (" . implode(' OR ', $vendorConditions) . ")
                AND (" . implode(' OR ', $keywordConditions) . ")
                ORDER BY lp.prix_ht ASC
                LIMIT 100
            ";
            
            return DB::connection('mysql')->select($query, $params);
            
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Recherche par type et caractéristiques
     */
    protected function searchByTypeAndFeatures(array $components): array
    {
        try {
            $conditions = [];
            $params = [];
            
            // Condition pour le type
            if (!empty($components['type'])) {
                $conditions[] = "lp.type LIKE ?";
                $params[] = '%' . $components['type'] . '%';
            }
            
            // Condition pour la couleur
            if (!empty($components['color'])) {
                $conditions[] = "(lp.name LIKE ? OR lp.variation LIKE ?)";
                $params[] = '%' . $components['color'] . '%';
                $params[] = '%' . $components['color'] . '%';
            }
            
            // Condition pour la finition
            if (!empty($components['finish'])) {
                $conditions[] = "(lp.name LIKE ? OR lp.variation LIKE ?)";
                $params[] = '%' . $components['finish'] . '%';
                $params[] = '%' . $components['finish'] . '%';
            }
            
            if (empty($conditions)) {
                return [];
            }
            
            $query = "
                SELECT 
                    lp.*,
                    ws.name as site_name,
                    lp.image_url as image_url,
                    lp.url as product_url
                FROM last_price_scraped_product lp
                LEFT JOIN web_site ws ON lp.web_site_id = ws.id
                WHERE (lp.variation != 'Standard' OR lp.variation IS NULL OR lp.variation = '')
                AND (" . implode(' OR ', $conditions) . ")
                ORDER BY lp.prix_ht ASC
                LIMIT 100
            ";
            
            return DB::connection('mysql')->select($query, $params);
            
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Préparer les termes de recherche pour FULLTEXT
     */
    protected function prepareSearchTermsForFulltext(string $search): string
    {
        $search = $this->normalizeAndCleanText($search);
        $searchClean = preg_replace('/[^a-zA-ZÀ-ÿ\s]/', ' ', $search);
        $searchClean = trim(preg_replace('/\s+/', ' ', $searchClean));
        $searchClean = mb_strtolower($searchClean);
        
        // Extraire les mots significatifs
        $words = explode(' ', $searchClean);
        $significantWords = [];
        
        $stopWords = array_merge(
            $this->getGeneralStopWords(),
            $this->getProductStopWords()
        );
        
        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) > 2 && !in_array($word, $stopWords) && !is_numeric($word)) {
                $significantWords[] = '+' . $word . '*';
            }
        }
        
        // Limiter à 5 mots maximum
        $significantWords = array_slice($significantWords, 0, 5);
        
        return implode(' ', $significantWords);
    }

    /**
     * Recherche FULLTEXT
     */
    protected function searchByFulltext(string $searchQuery): array
    {
        try {
            $query = "
                SELECT 
                    lp.*,
                    ws.name as site_name,
                    lp.image_url as image_url,
                    lp.url as product_url
                FROM last_price_scraped_product lp
                LEFT JOIN web_site ws ON lp.web_site_id = ws.id
                WHERE MATCH (lp.name, lp.vendor, lp.type, lp.variation) 
                    AGAINST (? IN BOOLEAN MODE)
                AND (lp.variation != 'Standard' OR lp.variation IS NULL OR lp.variation = '')
                ORDER BY lp.prix_ht ASC
                LIMIT 100
            ";
            
            return DB::connection('mysql')->select($query, [$searchQuery]);
            
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Recherche par vendor seul
     */
    protected function searchByVendorOnly(array $vendorVariations): array
    {
        try {
            $vendorConditions = [];
            $params = [];
            
            foreach ($vendorVariations as $variation) {
                $vendorConditions[] = "lp.vendor LIKE ?";
                $params[] = '%' . $variation . '%';
            }
            
            $query = "
                SELECT 
                    lp.*,
                    ws.name as site_name,
                    lp.image_url as image_url,
                    lp.url as product_url
                FROM last_price_scraped_product lp
                LEFT JOIN web_site ws ON lp.web_site_id = ws.id
                WHERE (lp.variation != 'Standard' OR lp.variation IS NULL OR lp.variation = '')
                AND (" . implode(' OR ', $vendorConditions) . ")
                ORDER BY lp.prix_ht ASC
                LIMIT 100
            ";
            
            return DB::connection('mysql')->select($query, $params);
            
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Filtrer par similarité améliorée
     */
    protected function filterBySimilarityImproved(array $competitors, string $search, array $components): array
    {
        $filtered = [];
        
        foreach ($competitors as $competitor) {
            $similarityScore = $this->computeSimilarityScoreImproved($competitor, $search, $components);
            
            // Seuil ajustable
            if ($similarityScore >= 0.3) { // Seuil plus bas pour plus de résultats
                $competitor->similarity_score = $similarityScore;
                $competitor->match_level = $this->getMatchLevel($similarityScore);
                $filtered[] = $competitor;
            }
        }
        
        // Trier par score décroissant
        usort($filtered, function($a, $b) {
            return $b->similarity_score <=> $a->similarity_score;
        });
        
        return $filtered;
    }

    /**
     * Calculer le score de similarité amélioré
     */
    protected function computeSimilarityScoreImproved($competitor, $search, $components): float
    {
        $weights = [
            'vendor' => 0.25,
            'keywords' => 0.20,
            'name' => 0.15,
            'type' => 0.15,
            'variation' => 0.10,
            'volumes' => 0.05,
            'color' => 0.05,
            'features' => 0.05
        ];
        
        $totalScore = 0;
        
        // 1. Score du vendor
        $vendorScore = $this->computeVendorSimilarity($competitor, $components['vendor']);
        $totalScore += $vendorScore * $weights['vendor'];
        
        // 2. Score des keywords
        $keywordsScore = $this->computeKeywordsSimilarity($competitor, $components['keywords']);
        $totalScore += $keywordsScore * $weights['keywords'];
        
        // 3. Score du nom
        $nameScore = $this->computeNameSimilarity($competitor->name ?? '', $components['product_name']);
        $totalScore += $nameScore * $weights['name'];
        
        // 4. Score du type
        $typeScore = $this->computeTypeSimilarity($competitor, $components['type']);
        $totalScore += $typeScore * $weights['type'];
        
        // 5. Score de la variation
        $variationScore = $this->computeVariationSimilarity($competitor, $components['variation']);
        $totalScore += $variationScore * $weights['variation'];
        
        // 6. Score des volumes
        $volumeScore = $this->computeVolumeSimilarity($competitor, $components['volumes']);
        $totalScore += $volumeScore * $weights['volumes'];
        
        // 7. Score de la couleur
        $colorScore = $this->computeColorSimilarity($competitor, $components['color']);
        $totalScore += $colorScore * $weights['color'];
        
        // 8. Score des caractéristiques (finition, etc.)
        $featuresScore = $this->computeFeaturesSimilarity($competitor, $components);
        $totalScore += $featuresScore * $weights['features'];
        
        return min(1.0, $totalScore);
    }

    /**
     * Similarité du vendor
     */
    protected function computeVendorSimilarity($competitor, $searchVendor): float
    {
        $productVendor = $competitor->vendor ?? '';
        
        if (empty($productVendor) || empty($searchVendor)) {
            return 0;
        }
        
        $productLower = mb_strtolower(trim($productVendor));
        $searchLower = mb_strtolower(trim($searchVendor));
        
        if ($productLower === $searchLower) {
            return 1.0;
        }
        
        if (str_starts_with($productLower, $searchLower) || str_starts_with($searchLower, $productLower)) {
            return 0.9;
        }
        
        if (str_contains($productLower, $searchLower) || str_contains($searchLower, $productLower)) {
            return 0.8;
        }
        
        // Similarité de Levenshtein
        $levenshtein = levenshtein($productLower, $searchLower);
        $maxLength = max(strlen($productLower), strlen($searchLower));
        
        if ($maxLength > 0) {
            $similarity = 1 - ($levenshtein / $maxLength);
            if ($similarity > 0.7) {
                return $similarity;
            }
        }
        
        return 0;
    }

    /**
     * Similarité des keywords
     */
    protected function computeKeywordsSimilarity($competitor, $keywords): float
    {
        if (empty($keywords)) {
            return 0.5; // Pas de pénalité si pas de keywords
        }
        
        $productName = $competitor->name ?? '';
        $productVariation = $competitor->variation ?? '';
        $productType = $competitor->type ?? '';
        
        $productText = mb_strtolower($productName . ' ' . $productVariation . ' ' . $productType);
        
        $matches = 0;
        foreach ($keywords as $keyword) {
            $keywordLower = mb_strtolower($keyword);
            if (str_contains($productText, $keywordLower)) {
                $matches++;
            }
        }
        
        return $matches / count($keywords);
    }

    /**
     * Similarité du nom
     */
    protected function computeNameSimilarity($productName, $searchProductName): float
    {
        if (empty($productName) || empty($searchProductName)) {
            return 0.3; // Score minimum si un des deux est vide
        }
        
        $productNameLower = mb_strtolower(trim($productName));
        $searchProductNameLower = mb_strtolower(trim($searchProductName));
        
        // Si le nom du produit contient le nom recherché (ou vice versa)
        if (str_contains($productNameLower, $searchProductNameLower) || 
            str_contains($searchProductNameLower, $productNameLower)) {
            return 0.9;
        }
        
        // Extraire les mots clés du nom recherché
        $searchWords = preg_split('/\s+/', $searchProductNameLower);
        $searchWords = array_filter($searchWords, function($word) {
            return strlen($word) > 2 && !$this->isStopWord($word);
        });
        
        if (empty($searchWords)) {
            return 0.3;
        }
        
        $matches = 0;
        foreach ($searchWords as $word) {
            if (str_contains($productNameLower, $word)) {
                $matches++;
            }
        }
        
        return $matches / count($searchWords);
    }

    /**
     * Similarité du type
     */
    protected function computeTypeSimilarity($competitor, $searchType): float
    {
        $productType = $competitor->type ?? '';
        
        if (empty($productType) || empty($searchType)) {
            return 0.3; // Score minimum si un des deux est vide
        }
        
        $productLower = mb_strtolower($productType);
        $searchLower = mb_strtolower($searchType);
        
        if ($productLower === $searchLower) {
            return 1.0;
        }
        
        if (str_contains($productLower, $searchLower) || str_contains($searchLower, $productLower)) {
            return 0.8;
        }
        
        // Vérifier les abréviations (ex: "edp" pour "eau de parfum")
        $typeMappings = [
            'edp' => 'eau de parfum',
            'edt' => 'eau de toilette',
            'edc' => 'eau de cologne',
            'creme' => 'crème',
            'serum' => 'sérum'
        ];
        
        foreach ($typeMappings as $abbr => $full) {
            if (($productLower === $abbr && str_contains($searchLower, $full)) ||
                ($searchLower === $abbr && str_contains($productLower, $full))) {
                return 0.7;
            }
        }
        
        return 0.2;
    }

    /**
     * Similarité de la variation
     */
    protected function computeVariationSimilarity($competitor, $searchVariation): float
    {
        $productVariation = $competitor->variation ?? '';
        
        if (empty($productVariation) || empty($searchVariation)) {
            return 0.3; // Score minimum si un des deux est vide
        }
        
        $productLower = mb_strtolower($productVariation);
        $searchLower = mb_strtolower($searchVariation);
        
        if ($productLower === $searchLower) {
            return 1.0;
        }
        
        if (str_contains($productLower, $searchLower) || str_contains($searchLower, $productLower)) {
            return 0.8;
        }
        
        return 0.2;
    }

    /**
     * Similarité des volumes
     */
    protected function computeVolumeSimilarity($competitor, $searchVolumes): float
    {
        if (empty($searchVolumes)) {
            return 0.5; // Pas de pénalité si pas de volumes recherchés
        }
        
        $productVolumes = $this->extractVolumesFromText(
            ($competitor->name ?? '') . ' ' . ($competitor->variation ?? '')
        );
        
        if (empty($productVolumes)) {
            return 0;
        }
        
        $matches = array_intersect($searchVolumes, $productVolumes);
        
        if (!empty($matches)) {
            return count($matches) / count($searchVolumes);
        }
        
        return 0;
    }

    /**
     * Similarité de la couleur
     */
    protected function computeColorSimilarity($competitor, $searchColor): float
    {
        if (empty($searchColor)) {
            return 0.5; // Pas de pénalité si pas de couleur recherchée
        }
        
        $productName = $competitor->name ?? '';
        $productVariation = $competitor->variation ?? '';
        $productText = mb_strtolower($productName . ' ' . $productVariation);
        
        $colorMappings = [
            'rouge' => ['red', 'rosso', 'rojo'],
            'blanc' => ['white', 'blanco', 'bianco'],
            'noir' => ['black', 'nero', 'negro'],
            'bleu' => ['blue', 'blu', 'azul'],
            'vert' => ['green', 'verde', 'verde'],
            'rose' => ['pink', 'rosa', 'rosado'],
            'brun' => ['brown', 'marrone', 'marrón'],
            'amarante' => ['amaranth', 'amaranto'],
            'doré' => ['gold', 'golden', 'dorado', 'dore'],
            'argenté' => ['silver', 'silvery', 'plateado', 'argente']
        ];
        
        $searchLower = mb_strtolower($searchColor);
        
        // Vérifier la couleur exacte
        if (str_contains($productText, $searchLower)) {
            return 1.0;
        }
        
        // Vérifier les équivalents dans d'autres langues
        if (isset($colorMappings[$searchLower])) {
            foreach ($colorMappings[$searchLower] as $equivalent) {
                if (str_contains($productText, $equivalent)) {
                    return 0.8;
                }
            }
        }
        
        return 0;
    }

    /**
     * Similarité des caractéristiques
     */
    protected function computeFeaturesSimilarity($competitor, $components): float
    {
        $score = 0.5; // Score de base
        
        $productName = $competitor->name ?? '';
        $productVariation = $competitor->variation ?? '';
        $productText = mb_strtolower($productName . ' ' . $productVariation);
        
        // Vérifier la finition
        if (!empty($components['finish'])) {
            $finishLower = mb_strtolower($components['finish']);
            $finishMappings = [
                'satin' => ['satiny', 'satiné', 'satinée'],
                'mat' => ['matte', 'mate', 'opaque'],
                'brillant' => ['shiny', 'glossy', 'brillante', 'lustré'],
                'nacré' => ['pearly', 'iridescent', 'nacre', 'nacrée'],
                'perlé' => ['pearlescent', 'pearlized', 'perle', 'perlée']
            ];
            
            if (str_contains($productText, $finishLower)) {
                $score += 0.1;
            } elseif (isset($finishMappings[$finishLower])) {
                foreach ($finishMappings[$finishLower] as $equivalent) {
                    if (str_contains($productText, $equivalent)) {
                        $score += 0.05;
                        break;
                    }
                }
            }
        }
        
        return min(1.0, $score);
    }

    /**
     * Vérifier si un mot est un stop word
     */
    protected function isStopWord(string $word): bool
    {
        $stopWords = array_merge(
            $this->getGeneralStopWords(),
            $this->getProductStopWords()
        );
        
        return in_array(mb_strtolower($word), $stopWords);
    }

    /**
     * Obtenir le niveau de correspondance
     */
    protected function getMatchLevel(float $similarityScore): string
    {
        if ($similarityScore >= 0.8) return 'excellent';
        if ($similarityScore >= 0.6) return 'bon';
        if ($similarityScore >= 0.4) return 'moyen';
        return 'faible';
    }

    /**
     * Charger la liste des vendors connus
     */
    protected function loadKnownVendors(): array
    {
        $cacheKey = 'all_vendors_list';
        $cachedVendors = Cache::get($cacheKey);
        
        if ($cachedVendors !== null) {
            return $cachedVendors;
        }
        
        try {
            $vendors = DB::connection('mysql')
                ->table('scraped_product')
                ->select('vendor')
                ->whereNotNull('vendor')
                ->where('vendor', '!=', '')
                ->distinct()
                ->get()
                ->pluck('vendor')
                ->toArray();
            
            $cleanVendors = [];
            foreach ($vendors as $vendor) {
                $clean = $this->normalizeAndCleanText($vendor);
                if (!empty($clean) && strlen($clean) > 1) {
                    $cleanVendors[] = $clean;
                }
            }
            
            $uniqueVendors = array_unique($cleanVendors);
            Cache::put($cacheKey, $uniqueVendors, now()->addHours(24));
            
            return $uniqueVendors;
            
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Trouver le vendor correspondant
     */
    protected function findMatchingVendor(string $searchVendor, array $knownVendors): string
    {
        if (empty($searchVendor) || empty($knownVendors)) {
            return '';
        }
        
        $searchVendor = $this->normalizeAndCleanText($searchVendor);
        $searchVendorLower = mb_strtolower(trim($searchVendor));
        $bestMatch = '';
        $bestScore = 0;
        
        foreach ($knownVendors as $knownVendor) {
            $knownVendor = $this->normalizeAndCleanText($knownVendor);
            $knownVendorLower = mb_strtolower($knownVendor);
            $score = 0;
            
            // 1. Correspondance exacte
            if ($searchVendorLower === $knownVendorLower) {
                return $knownVendor;
            }
            
            // 2. Le vendor recherché est contenu au début
            if (str_starts_with($knownVendorLower, $searchVendorLower)) {
                $score = 90;
            }
            
            // 3. Le vendor connu est contenu au début
            if (str_starts_with($searchVendorLower, $knownVendorLower)) {
                $score = 85;
            }
            
            // 4. Correspondance partielle
            if (str_contains($knownVendorLower, $searchVendorLower)) {
                $score = max($score, 70);
            }
            
            if (str_contains($searchVendorLower, $knownVendorLower)) {
                $score = max($score, 65);
            }
            
            // 5. Similarité de Levenshtein
            $levenshtein = levenshtein($searchVendorLower, $knownVendorLower);
            $maxLength = max(strlen($searchVendorLower), strlen($knownVendorLower));
            
            if ($maxLength > 0) {
                $similarity = (1 - ($levenshtein / $maxLength)) * 100;
                if ($similarity > 80) {
                    $score = max($score, $similarity);
                }
            }
            
            // 6. Correspondance sans caractères spéciaux
            $searchNoSpecial = preg_replace('/[^a-z0-9]/i', '', $searchVendorLower);
            $knownNoSpecial = preg_replace('/[^a-z0-9]/i', '', $knownVendorLower);
            
            if ($searchNoSpecial === $knownNoSpecial && !empty($searchNoSpecial)) {
                $score = max($score, 95);
            }
            
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $knownVendor;
            }
        }
        
        // Seuil minimum de confiance
        return $bestScore >= 60 ? $bestMatch : '';
    }

    /**
     * Générer les variations du vendor (améliorée)
     */
    protected function getVendorVariationsImproved(string $vendor): array
    {
        if (empty($vendor)) {
            return [];
        }
        
        $vendor = $this->normalizeAndCleanText($vendor);
        $variations = [trim($vendor)];
        
        // Variations de casse
        $variations[] = mb_strtoupper($vendor);
        $variations[] = mb_strtolower($vendor);
        $variations[] = mb_convert_case($vendor, MB_CASE_TITLE);
        $variations[] = ucfirst(mb_strtolower($vendor));
        
        // Variations sans espaces
        if (str_contains($vendor, ' ')) {
            $noSpace = str_replace(' ', '', $vendor);
            $variations[] = $noSpace;
            $variations[] = mb_strtoupper($noSpace);
            $variations[] = mb_strtolower($noSpace);
        }
        
        // Variations avec tirets
        if (str_contains($vendor, ' ')) {
            $withDash = str_replace(' ', '-', $vendor);
            $variations[] = $withDash;
            $variations[] = mb_strtoupper($withDash);
            $variations[] = mb_strtolower($withDash);
        }
        
        // Variations avec points
        if (str_contains($vendor, ' ')) {
            $withDot = str_replace(' ', '.', $vendor);
            $variations[] = $withDot;
            $variations[] = mb_strtoupper($withDot);
            $variations[] = mb_strtolower($withDot);
        }
        
        // Chercher des variations similaires dans la base de données
        $knownVendors = $this->loadKnownVendors();
        $vendorLower = mb_strtolower($vendor);
        
        foreach ($knownVendors as $knownVendor) {
            $knownLower = mb_strtolower($knownVendor);
            $distance = levenshtein($vendorLower, $knownLower);
            $maxLength = max(strlen($vendorLower), strlen($knownLower));
            
            if ($maxLength > 0 && ($distance / $maxLength) < 0.2) {
                $variations[] = $knownVendor;
            }
            
            if (str_contains($knownLower, $vendorLower) || str_contains($vendorLower, $knownLower)) {
                $variations[] = $knownVendor;
            }
        }
        
        return array_unique(array_filter($variations));
    }

    /**
     * Ajouter les comparaisons de prix
     */
    protected function addPriceComparisons(array $competitors, float $ourPrice): array
    {
        foreach ($competitors as $competitor) {
            // Nettoyer le prix du concurrent
            $competitorPrice = $this->cleanPrice($competitor->prix_ht ?? 0);
            
            // Différence de prix
            $competitor->price_difference = $ourPrice - $competitorPrice;
            $competitor->price_difference_percent = $ourPrice > 0 ? (($ourPrice - $competitorPrice) / $ourPrice) * 100 : 0;
            
            // Statut
            if ($competitorPrice < $ourPrice * 0.9) {
                $competitor->price_status = 'much_cheaper';
            } elseif ($competitorPrice < $ourPrice) {
                $competitor->price_status = 'cheaper';
            } elseif ($competitorPrice == $ourPrice) {
                $competitor->price_status = 'same';
            } elseif ($competitorPrice <= $ourPrice * 1.1) {
                $competitor->price_status = 'slightly_higher';
            } else {
                $competitor->price_status = 'much_higher';
            }
            
            // Ajouter le prix nettoyé
            $competitor->clean_price = $competitorPrice;
        }
        
        return $competitors;
    }

    /**
     * Obtenir l'image d'un concurrent
     */
    protected function getCompetitorImage($competitor): string
    {
        // Priorité 1: image_url direct
        if (!empty($competitor->image_url)) {
            $imageUrl = $this->normalizeAndCleanText($competitor->image_url);
            
            // Vérifier si c'est une URL complète ou un chemin relatif
            if (filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                return $imageUrl;
            }
            
            // Si c'est un chemin relatif, essayer de construire une URL complète
            if (strpos($imageUrl, 'http') !== 0) {
                // Essayer de deviner le domaine à partir de l'URL du produit
                $productUrl = $competitor->product_url ?? '';
                if (!empty($productUrl)) {
                    $parsed = parse_url($productUrl);
                    if (isset($parsed['scheme']) && isset($parsed['host'])) {
                        $baseUrl = $parsed['scheme'] . '://' . $parsed['host'];
                        
                        // Si l'image commence par /, ajouter au domaine
                        if (strpos($imageUrl, '/') === 0) {
                            return $baseUrl . $imageUrl;
                        }
                    }
                }
            }
            
            return $imageUrl;
        }
        
        // Priorité 2: extraire de l'URL du produit
        if (!empty($competitor->product_url)) {
            // Essayer d'extraire une image de l'URL
            $productUrl = $competitor->product_url;
            return $productUrl;
        }
        
        // Fallback: image par défaut
        return 'https://placehold.co/100x100/cccccc/999999?text=No+Image';
    }

    /**
     * Obtenir le nom du statut de prix
     */
    public function getPriceStatusLabel(string $status): string
    {
        $labels = [
            'much_cheaper' => 'Beaucoup moins cher',
            'cheaper' => 'Moins cher',
            'same' => 'Même prix',
            'slightly_higher' => 'Légèrement plus cher',
            'much_higher' => 'Beaucoup plus cher'
        ];
        
        return $labels[$status] ?? 'Inconnu';
    }

    /**
     * Obtenir la classe CSS du statut de prix
     */
    public function getPriceStatusClass(string $status): string
    {
        $classes = [
            'much_cheaper' => 'badge-success',
            'cheaper' => 'badge-success',
            'same' => 'badge-info',
            'slightly_higher' => 'badge-warning',
            'much_higher' => 'badge-error'
        ];
        
        return $classes[$status] ?? 'badge-neutral';
    }

    /**
     * Formater une différence de prix
     */
    public function formatPriceDifference($difference): string
    {
        $cleanDiff = $this->cleanPrice($difference);
        $sign = $cleanDiff > 0 ? '+' : ($cleanDiff < 0 ? '-' : '');
        $absDiff = abs($cleanDiff);
        return $sign . number_format($absDiff, 2, ',', ' ') . ' €';
    }

    /**
     * Formater un pourcentage
     */
    public function formatPercentage($percentage): string
    {
        $cleanPercentage = $this->cleanPrice($percentage);
        $sign = $cleanPercentage > 0 ? '+' : ($cleanPercentage < 0 ? '-' : '');
        $absPercentage = abs($cleanPercentage);
        return $sign . number_format($absPercentage, 1, ',', ' ') . '%';
    }

    /**
     * Valider une URL d'image
     */
    public function isValidImageUrl($url): bool
    {
        if (empty($url)) {
            return false;
        }
        
        $url = $this->normalizeAndCleanText($url);
        
        // Vérifier si c'est une URL valide
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        // Vérifier les extensions d'image courantes
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'];
        $path = parse_url($url, PHP_URL_PATH);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        
        // Si pas d'extension, on suppose que c'est OK (peut être une URL dynamique)
        if (empty($extension)) {
            return true;
        }
        
        return in_array(strtolower($extension), $imageExtensions);
    }

    /**
     * Obtenir l'image d'un concurrent pour l'affichage
     */
    public function getCompetitorImageUrl($competitor): string
    {
        // Si l'image est déjà définie dans l'objet
        if (isset($competitor->image) && !empty($competitor->image)) {
            return $this->normalizeAndCleanText($competitor->image);
        }
        
        // Sinon, utiliser la méthode de secours
        return $this->getCompetitorImage($competitor);
    }

    /**
     * Extraire les volumes d'un texte
     */
    protected function extractVolumesFromText(string $text): array
    {
        $volumes = [];
        if (preg_match_all('/(\d+)\s*ml/i', $text, $matches)) {
            $volumes = $matches[1];
        }
        return $volumes;
    }

    // Changer de page
    public function goToPage($page): void
    {
        if ($page < 1 || $page > $this->totalPages || $page === $this->page) {
            return;
        }

        $this->loading = true;
        $this->page = (int) $page;
        $this->expandedProducts = []; // Réinitialiser les produits étendus
    }

    // Page précédente
    public function previousPage(): void
    {
        if ($this->page > 1) {
            $this->goToPage($this->page - 1);
        }
    }

    // Page suivante
    public function nextPage(): void
    {
        if ($this->page < $this->totalPages) {
            $this->goToPage($this->page + 1);
        }
    }

    // Rafraîchir la liste
    public function refreshProducts(): void
    {
        $this->page = 1;
        $this->loading = true;
        $this->expandedProducts = [];
        $this->competitorResults = [];
        $this->loadListTitle();
    }

    public function with(): array
    {
        try {
            // Récupérer tous les EAN de la liste
            $allSkus = Cache::remember("list_skus_{$this->id}", 300, function () {
                return DetailProduct::where('list_product_id', $this->id)
                    ->pluck('EAN')
                    ->unique()
                    ->values()
                    ->toArray();
            });

            $totalItems = count($allSkus);

            if ($totalItems === 0) {
                $this->loading = false;
                $this->totalPages = 1;
                return [
                    'products' => [],
                    'totalItems' => 0,
                    'totalPages' => 1,
                    'allSkus' => [],
                ];
            }

            // Calculer le nombre total de pages
            $this->totalPages = max(1, ceil($totalItems / $this->perPage));

            // Charger uniquement la page courante
            $result = $this->fetchProductsFromDatabase($allSkus, $this->page, $this->perPage);

            if (isset($result['error'])) {
                $products = [];
            } else {
                $products = $result['data'] ?? [];
                $products = array_map(fn($p) => (array) $p, $products);
                
                // Nettoyer les prix et noms des produits
                foreach ($products as &$product) {
                    $product['price'] = $this->cleanPrice($product['price'] ?? 0);
                    $product['special_price'] = $this->cleanPrice($product['special_price'] ?? 0);
                    if (isset($product['title'])) {
                        $product['title'] = $this->normalizeAndCleanText($product['title']);
                    }
                }
            }

            $this->loading = false;
            $this->loadingMore = false;

            return [
                'products' => $products,
                'totalItems' => $totalItems,
                'totalPages' => $this->totalPages,
                'allSkus' => $allSkus,
            ];

        } catch (\Exception $e) {
            $this->loading = false;
            $this->loadingMore = false;
            $this->totalPages = 1;

            return [
                'products' => [],
                'totalItems' => 0,
                'totalPages' => 1,
                'allSkus' => [],
            ];
        }
    }

    /**
     * Récupère les produits depuis la base de données
     */
    protected function fetchProductsFromDatabase(array $allSkus, int $page = 1, int $perPage = null)
    {
        try {
            $offset = ($page - 1) * $perPage;
            $pageSkus = array_slice($allSkus, $offset, $perPage);

            if (empty($pageSkus)) {
                return [
                    "total_item" => count($allSkus),
                    "per_page" => $perPage,
                    "total_page" => ceil(count($allSkus) / $perPage),
                    "current_page" => $page,
                    "data" => [],
                    "cached_at" => now()->toDateTimeString(),
                ];
            }

            $placeholders = implode(',', array_fill(0, count($pageSkus), '?'));

            $query = "
                SELECT 
                    produit.sku as sku,
                    CAST(product_char.name AS CHAR CHARACTER SET utf8mb4) as title,
                    product_char.thumbnail as thumbnail,
                    SUBSTRING_INDEX(product_char.name, ' - ', 1) as vendor,
                    SUBSTRING_INDEX(eas.attribute_set_name, '_', -1) as type,
                    ROUND(product_decimal.price, 2) as price,
                    ROUND(product_decimal.special_price, 2) as special_price,
                    stock_item.qty as quatity,
                    stock_status.stock_status as quatity_status,
                    product_char.reference as reference,
                    product_char.reference_us as reference_us,
                    product_int.status as status,
                    CAST(product_text.description AS CHAR CHARACTER SET utf8mb4) as description,
                    product_char.swatch_image as swatch_image
                FROM catalog_product_entity as produit
                LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
                LEFT JOIN product_decimal ON product_decimal.entity_id = produit.entity_id
                LEFT JOIN product_int ON product_int.entity_id = produit.entity_id
                LEFT JOIN product_text ON product_text.entity_id = produit.entity_id
                LEFT JOIN cataloginventory_stock_item AS stock_item ON stock_item.product_id = produit.entity_id 
                LEFT JOIN cataloginventory_stock_status AS stock_status ON stock_status.product_id = stock_item.product_id 
                LEFT JOIN eav_attribute_set AS eas ON produit.attribute_set_id = eas.attribute_set_id 
                WHERE produit.sku IN ($placeholders)
                AND product_int.status >= 0
                ORDER BY FIELD(produit.sku, " . implode(',', $pageSkus) . ")
            ";

            $result = DB::connection('mysqlMagento')->select($query, $pageSkus);

            return [
                "total_item" => count($allSkus),
                "per_page" => $perPage,
                "total_page" => ceil(count($allSkus) / $perPage),
                "current_page" => $page,
                "data" => $result,
                "cached_at" => now()->toDateTimeString(),
                "cache_key" => $this->getCacheKey('list_products', $this->id, $page, $perPage)
            ];

        } catch (\Throwable $e) {
            return [
                "total_item" => 0,
                "per_page" => $perPage,
                "total_page" => 0,
                "current_page" => 1,
                "data" => [],
                "error" => $e->getMessage()
            ];
        }
    }

    // Générer les boutons de pagination
    public function getPaginationButtons(): array
    {
        $buttons = [];
        $current = $this->page;
        $total = $this->totalPages;

        // Toujours afficher la première page
        $buttons[] = [
            'page' => 1,
            'label' => '1',
            'active' => $current === 1,
        ];

        // Afficher les pages autour de la page courante
        $start = max(2, $current - 2);
        $end = min($total - 1, $current + 2);

        // Ajouter "..." après la première page si nécessaire
        if ($start > 2) {
            $buttons[] = [
                'page' => null,
                'label' => '...',
                'disabled' => true,
            ];
        }

        // Pages du milieu
        for ($i = $start; $i <= $end; $i++) {
            $buttons[] = [
                'page' => $i,
                'label' => (string) $i,
                'active' => $current === $i,
            ];
        }

        // Ajouter "..." avant la dernière page si nécessaire
        if ($end < $total - 1) {
            $buttons[] = [
                'page' => null,
                'label' => '...',
                'disabled' => true,
            ];
        }

        // Toujours afficher la dernière page si elle existe
        if ($total > 1) {
            $buttons[] = [
                'page' => $total,
                'label' => (string) $total,
                'active' => $current === $total,
            ];
        }

        return $buttons;
    }

    protected function getCacheKey($type, ...$params)
    {
        return "list_products_{$type}_" . md5(serialize($params));
    }
}; ?>
<div>
    <!-- En-tête avec information -->    
    <x-header title="{{ $listTitle }}" subtitle="Page {{ $page }} sur {{ $totalPages }} ({{ $totalItems }} produits)" separator>
        <x-slot:middle class="!justify-end">
            <x-input icon="o-bolt" placeholder="Search..." />
        </x-slot:middle>
        <x-slot:actions>
            <x-button 
                label="Rechercher les concurrents pour cette page" 
                class="btn-primary" 
                wire:click="searchAllCompetitorsOnPage"
                wire:loading.attr="disabled"
            />
            <x-button 
                label="Rafraîchir" 
                class="btn-outline" 
                wire:click="refreshProducts"
                wire:loading.attr="disabled"
            />
        </x-slot:actions>
    </x-header>

    <!-- Indicateur de chargement pour la recherche de concurrents -->
    @if($searchingCompetitors)
        <div class="alert alert-info mb-4">
            <div class="flex items-center">
                <span class="loading loading-spinner loading-sm mr-2"></span>
                <span>Recherche des prix concurrents pour tous les produits de la page...</span>
            </div>
        </div>
    @endif

    <!-- Table des produits -->
    <div class="overflow-x-auto rounded-box border border-base-content/5 bg-base-100 mb-6">
        <table class="table">
            <!-- head -->
            <thead>
                <tr>
                    <th>#</th>
                    <th>Image</th>
                    <th>EAN/SKU</th>
                    <th>Nom</th>
                    <th>Marque</th>
                    <th>Type</th>
                    <th>Prix</th>
                    <th>Stock</th>
                    <th>Statut</th>
                    <th>Concurrents</th>
                </tr>
            </thead>
            <tbody>
                @if($loading)
                    <!-- État de chargement initial -->
                    <tr>
                        <td colspan="10" class="text-center py-12">
                            <div class="flex flex-col items-center gap-3">
                                <span class="loading loading-spinner loading-lg text-primary"></span>
                                <span class="text-lg">Chargement des produits...</span>
                            </div>
                        </td>
                    </tr>
                @elseif(count($products) === 0 && !$loading)
                    <!-- Aucun produit -->
                    <tr>
                        <td colspan="10" class="text-center py-12 text-base-content/50">
                            <div class="flex flex-col items-center gap-3">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 text-base-content/20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2 2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                </svg>
                                <span class="text-lg">Aucun produit dans cette liste</span>
                            </div>
                        </td>
                    </tr>
                @else
                    <!-- Liste des produits -->
                    @foreach($products as $index => $product)
                        @php
                            $rowNumber = (($page - 1) * $perPage) + $index + 1;
                            $sku = $product['sku'] ?? '';
                            $isExpanded = isset($expandedProducts[$sku]);
                            $hasCompetitors = isset($competitorResults[$sku]);
                            $isSearching = isset($searchingProducts[$sku]);
                        @endphp
                        
                        <!-- Ligne du produit -->
                        <tr wire:key="product-{{ $sku }}-{{ $page }}-{{ $index }}">
                            <th>{{ $rowNumber }}</th>
                            <td>
                                @if(!empty($product['thumbnail']))
                                    <div class="avatar">
                                        <div class="w-12 h-12 rounded">
                                            <img 
                                                src="https://www.cosma-parfumeries.com/media/catalog/product/{{ $product['thumbnail'] }}"
                                                alt="{{ $product['title'] ?? '' }}"
                                                class="object-cover"
                                                loading="lazy"
                                                onerror="this.onerror=null; this.src='https://placehold.co/100x100/cccccc/999999?text=No+Image'"
                                            >
                                        </div>
                                    </div>
                                @elseif(!empty($product['swatch_image']))
                                    <div class="avatar">
                                        <div class="w-12 h-12 rounded">
                                            <img 
                                                src="https://www.cosma-parfumeries.com/media/catalog/product/{{ $product['swatch_image'] }}"
                                                alt="{{ $product['title'] ?? '' }}"
                                                class="object-cover"
                                                loading="lazy"
                                                onerror="this.onerror=null; this.src='https://placehold.co/100x100/cccccc/999999?text=No+Image'"
                                            >
                                        </div>
                                    </div>
                                @else
                                    <div class="w-12 h-12 bg-base-300 rounded flex items-center justify-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-base-content/40" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                    </div>
                                @endif
                            </td>
                            <td class="font-mono text-sm">
                                <div class="tooltip" data-tip="Cliquer pour copier">
                                    <button 
                                        onclick="copySku('{{ $sku }}')"
                                        class="hover:text-primary transition-colors"
                                    >
                                        {{ $sku }}
                                    </button>
                                </div>
                            </td>
                            <td>
                                <div class="max-w-xs" title="{{ utf8_encode($product['title'] ?? '' ) }}">
                                   {{ utf8_encode($product['title'] ?? '' ) }}
                                </div>
                            </td>
                            <td>{{ $product['vendor'] ?? 'N/A' }}</td>
                            <td>
                                <span class="badge">{{ $product['type'] ?? 'N/A' }}</span>
                            </td>
                            <td>
                                @if(!empty($product['special_price']) && $product['special_price'] > 0)
                                    <div class="flex flex-col">
                                        <span class="line-through text-xs text-base-content/50">
                                            {{ $this->formatPrice($product['price'] ?? 0) }}
                                        </span>
                                        <span class="text-error font-semibold">
                                            {{ $this->formatPrice($product['special_price']) }}
                                        </span>
                                    </div>
                                @else
                                    <span class="font-semibold">
                                        {{ $this->formatPrice($product['price'] ?? 0) }}
                                    </span>
                                @endif
                            </td>
                            <td>
                                <span class="{{ ($product['quatity'] ?? 0) > 0 ? 'text-success' : 'text-error' }}">
                                    {{ $product['quatity'] ?? 0 }}
                                </span>
                            </td>
                            <td>
                                @php
                                    $statusClass = ($product['status'] ?? 0) == 1 ? 'badge-success' : 'badge-error';
                                    $statusText = ($product['status'] ?? 0) == 1 ? 'Actif' : 'Inactif';
                                    $stockStatusClass = ($product['quatity_status'] ?? 0) == 1 ? 'badge-success' : 'badge-error';
                                    $stockStatusText = ($product['quatity_status'] ?? 0) == 1 ? 'En stock' : 'Rupture';
                                @endphp
                                <div class="flex flex-col gap-1">
                                    <span class="badge badge-sm {{ $statusClass }}">
                                        {{ $statusText }}
                                    </span>
                                    <span class="badge badge-sm {{ $stockStatusClass }}">
                                        {{ $stockStatusText }}
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="flex items-center space-x-2">
                                    @if($isSearching)
                                        <span class="loading loading-spinner loading-xs"></span>
                                        <span class="text-xs text-info">Recherche...</span>
                                    @else
                                        <button 
                                            wire:click="toggleCompetitors('{{ $sku }}')"
                                            class="btn btn-xs {{ $hasCompetitors && $competitorResults[$sku]['count'] > 0 ? 'btn-primary' : 'btn-outline' }}"
                                            wire:loading.attr="disabled"
                                        >
                                            @if($isExpanded)
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                                                </svg>
                                            @else
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                                </svg>
                                            @endif
                                            @if($hasCompetitors)
                                                <span class="ml-1">{{ $competitorResults[$sku]['count'] }}</span>
                                            @else
                                                <span class="ml-1">Voir</span>
                                            @endif
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        
                        <!-- Ligne des concurrents (expandable) -->
                        @if($isExpanded)
                            <tr class="bg-base-200">
                                <td colspan="10" class="p-0">
                                    <div class="p-4">
                                        @if($isSearching)
                                            <div class="flex justify-center items-center py-8">
                                                <span class="loading loading-spinner loading-lg mr-2"></span>
                                                <span>Recherche des concurrents en cours...</span>
                                            </div>
                                        @elseif($hasCompetitors)
                                            @php
                                                $productData = $competitorResults[$sku];
                                            @endphp
                                            <div class="mb-4">
                                                <div class="flex items-center justify-between mb-2">
                                                    <h4 class="font-bold text-lg">
                                                        Concurrents pour: {{ $productData['product_name'] }}
                                                    </h4>
                                                    <div class="flex items-center space-x-2">
                                                        <span class="badge badge-lg">
                                                            Notre prix: {{ $this->formatPrice($productData['our_price']) }}
                                                        </span>
                                                        <span class="badge badge-primary badge-lg">
                                                            {{ $productData['count'] }} concurrent(s) trouvé(s)
                                                        </span>
                                                    </div>
                                                </div>
                                                
                                                @if($productData['count'] > 0)
                                                    <!-- Table des concurrents avec images -->
                                                    <div class="overflow-x-auto mt-4">
                                                        <table class="table table-xs">
                                                            <thead>
                                                                <tr>
                                                                    <th class="bg-base-300">Image</th>
                                                                    <th class="bg-base-300">Site</th>
                                                                    <th class="bg-base-300">Vendor</th>
                                                                    <th class="bg-base-300">Nom</th>
                                                                    <th class="bg-base-300">Variation</th>
                                                                    <th class="bg-base-300">Type</th>
                                                                    <th class="bg-base-300">Prix HT</th>
                                                                    <th class="bg-base-300">Différence</th>
                                                                    <th class="bg-base-300">%</th>
                                                                    <th class="bg-base-300">Statut</th>
                                                                    <th class="bg-base-300">Similarité</th>
                                                                    <th class="bg-base-300">Action</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                @foreach($productData['competitors'] as $competitor)
                                                                    @php
                                                                        $priceDiff = $competitor->price_difference ?? 0;
                                                                        $priceDiffPercent = $competitor->price_difference_percent ?? 0;
                                                                        $priceStatus = $competitor->price_status ?? 'unknown';
                                                                        $similarityScore = $competitor->similarity_score ?? 0;
                                                                        $competitorPrice = $competitor->clean_price ?? $this->cleanPrice($competitor->prix_ht ?? 0);
                                                                        $competitorImage = $this->getCompetitorImageUrl($competitor);
                                                                        $hasValidImage = $this->isValidImageUrl($competitorImage);
                                                                    @endphp
                                                                    <tr>
                                                                        <td>
                                                                            <div class="avatar">
                                                                                <div class="w-16 h-16 rounded border border-base-300">
                                                                                    <img 
                                                                                        src="{{ $competitorImage }}"
                                                                                        alt="{{ $competitor->name ?? 'Produit concurrent' }}"
                                                                                        class="object-cover w-full h-full"
                                                                                        loading="lazy"
                                                                                        onerror="this.onerror=null; this.src='https://placehold.co/100x100/cccccc/999999?text=No+Image'"
                                                                                    >
                                                                                </div>
                                                                                @if(!$hasValidImage)
                                                                                    <div class="mt-1 text-center">
                                                                                        <span class="badge badge-xs badge-outline">
                                                                                            Pas d'image
                                                                                        </span>
                                                                                    </div>
                                                                                @endif
                                                                            </div>
                                                                        </td>
                                                                        <td>
                                                                            <div class="font-medium">{{ $competitor->site_name ?? 'Inconnu' }}</div>
                                                                            @if(!empty($competitor->product_url))
                                                                                <a href="{{ $competitor->product_url }}" 
                                                                                   target="_blank" 
                                                                                   rel="noopener noreferrer"
                                                                                   class="text-xs text-primary hover:underline"
                                                                                   title="Voir le produit">
                                                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 inline mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                                                                    </svg>
                                                                                    Voir
                                                                                </a>
                                                                            @endif
                                                                        </td>
                                                                        <td>{{ $competitor->vendor ?? 'N/A' }}</td>
                                                                        <td class="max-w-xs truncate" title="{{ $competitor->name ?? 'N/A' }}">
                                                                            {{ $competitor->name ?? 'N/A' }}
                                                                        </td>
                                                                        <td>{{ $competitor->variation ?? 'Standard' }}</td>
                                                                        <td>
                                                                            <span class="badge badge-outline badge-xs">
                                                                                {{ $competitor->type ?? 'N/A' }}
                                                                            </span>
                                                                        </td>
                                                                        <td class="font-semibold">
                                                                            {{ $this->formatPrice($competitorPrice) }}
                                                                        </td>
                                                                        <td>
                                                                            <span class="{{ $priceDiff > 0 ? 'text-green-600' : ($priceDiff < 0 ? 'text-red-600' : 'text-gray-600') }} font-semibold">
                                                                                {{ $this->formatPriceDifference($priceDiff) }}
                                                                            </span>
                                                                        </td>
                                                                        <td>
                                                                            <span class="text-sm {{ $priceDiffPercent > 0 ? 'text-green-600' : ($priceDiffPercent < 0 ? 'text-red-600' : 'text-gray-600') }}">
                                                                                {{ $this->formatPercentage($priceDiffPercent) }}
                                                                            </span>
                                                                        </td>
                                                                        <td>
                                                                            <span class="badge badge-sm {{ $this->getPriceStatusClass($priceStatus) }}">
                                                                                {{ $this->getPriceStatusLabel($priceStatus) }}
                                                                            </span>
                                                                        </td>
                                                                        <td>
                                                                            <div class="flex items-center space-x-2">
                                                                                <div class="w-16 bg-gray-300 rounded-full h-2">
                                                                                    <div class="h-2 rounded-full 
                                                                                        @if($similarityScore >= 0.7) bg-green-500
                                                                                        @elseif($similarityScore >= 0.5) bg-yellow-500
                                                                                        @else bg-red-500 @endif"
                                                                                        style="width: {{ $similarityScore * 100 }}%">
                                                                                    </div>
                                                                                </div>
                                                                                <span class="text-xs font-medium">
                                                                                    {{ number_format($similarityScore * 100, 0) }}%
                                                                                </span>
                                                                            </div>
                                                                        </td>
                                                                        <td>
                                                                            @if(!empty($competitor->product_url))
                                                                                <a href="{{ $competitor->product_url }}" 
                                                                                   target="_blank" 
                                                                                   rel="noopener noreferrer"
                                                                                   class="btn btn-xs btn-outline btn-primary"
                                                                                   title="Visiter le site">
                                                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                                                                    </svg>
                                                                                </a>
                                                                            @else
                                                                                <span class="text-xs text-gray-400">N/A</span>
                                                                            @endif
                                                                        </td>
                                                                    </tr>
                                                                @endforeach
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                    
                                                    <!-- Statistiques des concurrents -->
                                                    <div class="mt-4 p-4 bg-base-100 rounded-lg border border-base-300">
                                                        <h5 class="font-semibold mb-2">Statistiques des concurrents</h5>
                                                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                                            @php
                                                                $prices = array_map(fn($c) => $this->cleanPrice($c->prix_ht ?? 0), $productData['competitors']);
                                                                $minPrice = !empty($prices) ? min($prices) : 0;
                                                                $maxPrice = !empty($prices) ? max($prices) : 0;
                                                                $avgPrice = !empty($prices) ? array_sum($prices) / count($prices) : 0;
                                                                $cheaperCount = !empty($productData['competitors']) ? count(array_filter($productData['competitors'], fn($c) => $this->cleanPrice($c->prix_ht ?? 0) < $productData['our_price'])) : 0;
                                                                $expensiveCount = !empty($productData['competitors']) ? count(array_filter($productData['competitors'], fn($c) => $this->cleanPrice($c->prix_ht ?? 0) > $productData['our_price'])) : 0;
                                                                $samePriceCount = !empty($productData['competitors']) ? count(array_filter($productData['competitors'], fn($c) => $this->cleanPrice($c->prix_ht ?? 0) == $productData['our_price'])) : 0;
                                                            @endphp
                                                            <div class="text-center">
                                                                <div class="text-2xl font-bold text-green-600">{{ $this->formatPrice($minPrice) }}</div>
                                                                <div class="text-xs text-gray-500">Prix minimum</div>
                                                            </div>
                                                            <div class="text-center">
                                                                <div class="text-2xl font-bold text-red-600">{{ $this->formatPrice($maxPrice) }}</div>
                                                                <div class="text-xs text-gray-500">Prix maximum</div>
                                                            </div>
                                                            <div class="text-center">
                                                                <div class="text-2xl font-bold text-blue-600">{{ $this->formatPrice($avgPrice) }}</div>
                                                                <div class="text-xs text-gray-500">Prix moyen</div>
                                                            </div>
                                                            <div class="text-center">
                                                                <div class="text-2xl font-bold {{ $cheaperCount > $expensiveCount ? 'text-green-600' : ($cheaperCount < $expensiveCount ? 'text-red-600' : 'text-blue-600') }}">
                                                                    {{ $cheaperCount }}
                                                                </div>
                                                                <div class="text-xs text-gray-500">Moins chers que nous</div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                @else
                                                    <div class="alert alert-warning">
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.998-.833-2.732 0L4.338 16.5c-.77.833.192 2.5 1.732 2.5z" />
                                                        </svg>
                                                        Aucun concurrent trouvé pour ce produit.
                                                    </div>
                                                @endif
                                            </div>
                                        @else
                                            <div class="flex justify-center items-center py-8">
                                                <div class="text-center">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 text-gray-400 mx-auto mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                                                    </svg>
                                                    <p class="text-gray-500">Cliquez sur "Rechercher" pour trouver les concurrents</p>
                                                    <button 
                                                        wire:click="searchCompetitorsForProduct('{{ $sku }}', '{{ addslashes($product['title'] ?? '') }}', {{ $product['price'] ?? 0 }})"
                                                        class="btn btn-sm btn-primary mt-2"
                                                        wire:loading.attr="disabled"
                                                    >
                                                        <span wire:loading.remove wire:target="searchCompetitorsForProduct">
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                                            </svg>
                                                            Rechercher les concurrents
                                                        </span>
                                                        <span wire:loading wire:target="searchCompetitorsForProduct">
                                                            <span class="loading loading-spinner loading-xs"></span>
                                                            Recherche...
                                                        </span>
                                                    </button>
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                @endif
            </tbody>
            <tfoot>
                <tr>
                    <th>#</th>
                    <th>Image</th>
                    <th>EAN/SKU</th>
                    <th>Nom</th>
                    <th>Marque</th>
                    <th>Type</th>
                    <th>Prix</th>
                    <th>Stock</th>
                    <th>Statut</th>
                    <th>Concurrents</th>
                </tr>
            </tfoot>
        </table>
    </div>
    
    <!-- Pagination -->
    @if($totalPages > 1)
        <div class="flex flex-col sm:flex-row items-center justify-between gap-4 mt-6">
            <!-- Informations -->
            <div class="text-sm text-base-content/60">
                Affichage des produits 
                <span class="font-medium">{{ min((($page - 1) * $perPage) + 1, $totalItems) }}</span>
                à 
                <span class="font-medium">{{ min($page * $perPage, $totalItems) }}</span>
                sur 
                <span class="font-medium">{{ $totalItems }}</span> 
                au total
            </div>
            
            <!-- Boutons de pagination -->
            <div class="join">
                <!-- Bouton précédent -->
                <button 
                    class="join-item btn"
                    wire:click="previousPage"
                    wire:loading.attr="disabled"
                    @disabled($page <= 1)
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>
                
                <!-- Boutons de pages -->
                @foreach($this->getPaginationButtons() as $button)
                    @if($button['page'] === null)
                        <!-- Séparateur "..." -->
                        <button class="join-item btn btn-disabled" disabled>
                            {{ $button['label'] }}
                        </button>
                    @else
                        <!-- Bouton de page -->
                        <button 
                            class="join-item btn {{ $button['active'] ? 'btn-active' : '' }}"
                            wire:click="goToPage({{ $button['page'] }})"
                            wire:loading.attr="disabled"
                        >
                            {{ $button['label'] }}
                        </button>
                    @endif
                @endforeach
                
                <!-- Bouton suivant -->
                <button 
                    class="join-item btn"
                    wire:click="nextPage"
                    wire:loading.attr="disabled"
                    @disabled($page >= $totalPages)
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </button>
            </div>
        </div>
    @endif
    
    <!-- Statistiques globales -->
    @if(count($competitorResults) > 0)
        <div class="mt-8 card bg-base-100 shadow">
            <div class="card-body">
                <h3 class="card-title">Résumé des concurrents</h3>
                <div class="stats stats-vertical lg:stats-horizontal shadow">
                    <div class="stat">
                        <div class="stat-title">Produits analysés</div>
                        <div class="stat-value">{{ count($competitorResults) }}</div>
                    </div>
                    
                    @php
                        $totalCompetitors = array_sum(array_column($competitorResults, 'count'));
                        $productsWithCompetitors = count(array_filter($competitorResults, fn($r) => $r['count'] > 0));
                        $totalCheaper = 0;
                        $totalExpensive = 0;
                        
                        foreach($competitorResults as $result) {
                            foreach($result['competitors'] as $competitor) {
                                $compPrice = $competitor->clean_price ?? $this->cleanPrice($competitor->prix_ht ?? 0);
                                if ($compPrice < $result['our_price']) {
                                    $totalCheaper++;
                                } elseif ($compPrice > $result['our_price']) {
                                    $totalExpensive++;
                                }
                            }
                        }
                    @endphp
                    
                    <div class="stat">
                        <div class="stat-title">Concurrents trouvés</div>
                        <div class="stat-value">{{ $totalCompetitors }}</div>
                    </div>
                    
                    <div class="stat">
                        <div class="stat-title">Avec concurrents</div>
                        <div class="stat-value">{{ $productsWithCompetitors }}</div>
                        <div class="stat-desc">sur {{ count($competitorResults) }} produits</div>
                    </div>
                    
                    <div class="stat">
                        <div class="stat-title">Moins chers</div>
                        <div class="stat-value text-green-600">{{ $totalCheaper }}</div>
                        <div class="stat-desc">concurrent(s) moins cher(s)</div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

@push('script')
<script>
    // Fonction pour copier le SKU
    function copySku(sku) {
        navigator.clipboard.writeText(sku).then(() => {
            // Créer une notification simple
            const toast = document.createElement('div');
            toast.className = `toast toast-top toast-end`;
            toast.innerHTML = `
                <div class="alert alert-success">
                    <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span>SKU ${sku} copié !</span>
                </div>
            `;
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.remove();
            }, 2000);
        }).catch(err => {
            console.error('Erreur copie:', err);
        });
    }
    
    // Fonction pour afficher une image en grand
    function showImage(imageUrl, productName) {
        const modal = document.createElement('div');
        modal.className = 'modal modal-open';
        modal.innerHTML = `
            <div class="modal-box max-w-4xl">
                <h3 class="font-bold text-lg mb-4">${productName}</h3>
                <div class="flex justify-center">
                    <img src="${imageUrl}" 
                         alt="${productName}" 
                         class="max-w-full max-h-[70vh] object-contain"
                         onerror="this.onerror=null; this.src='https://placehold.co/600x400/cccccc/999999?text=Image+non+disponible'">
                </div>
                <div class="modal-action">
                    <button class="btn" onclick="this.closest('.modal').remove()">Fermer</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }
    
    // Fonction pour basculer l'affichage d'une image
    function toggleImage(imgElement) {
        const currentSrc = imgElement.src;
        const placeholder = 'https://placehold.co/100x100/cccccc/999999?text=No+Image';
        
        if (currentSrc.includes('placehold.co') || currentSrc === placeholder) {
            // Si c'est un placeholder, ne rien faire
            return;
        }
        
        // Sinon, ouvrir en grand
        const productName = imgElement.alt || 'Image produit';
        showImage(currentSrc, productName);
    }
    
    // Ajouter des événements de clic aux images
    document.addEventListener('DOMContentLoaded', function() {
        // Ajouter un événement de clic à toutes les images des concurrents
        document.querySelectorAll('.avatar img').forEach(img => {
            img.style.cursor = 'pointer';
            img.addEventListener('click', function() {
                toggleImage(this);
            });
        });
    });
</script>
    
@endpush

@push('style')

<style>
    /* Style pour les images cliquables */
    .avatar img {
        cursor: pointer;
        transition: transform 0.2s ease;
    }
    
    .avatar img:hover {
        transform: scale(1.05);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }
    
    /* Style pour le modal */
    .modal {
        background-color: rgba(0, 0, 0, 0.5);
        z-index: 9999;
    }
    
    .modal-box {
        background-color: white;
        max-width: 90vw;
        max-height: 90vh;
    }
    
    /* Style pour les lignes de concurrents */
    tr.bg-base-200 {
        transition: all 0.3s ease;
    }
    
    /* Style pour les badges de statut */
    .badge-success {
        background-color: #10b981 !important;
        color: white !important;
        border-color: #10b981;
    }
    
    .badge-warning {
        background-color: #f59e0b !important;
        color: white !important;
        border-color: #f59e0b;
    }
    
    .badge-error {
        background-color: #ef4444 !important;
        color: white !important;
        border-color: #ef4444;
    }
    
    .badge-info {
        background-color: #3b82f6 !important;
        color: white !important;
        border-color: #3b82f6;
    }
    
    .badge-primary {
        background-color: #3b82f6 !important;
        color: white !important;
        border-color: #3b82f6;
    }
    
    /* Style pour le tableau des concurrents */
    .table-xs th,
    .table-xs td {
        padding: 0.5rem;
        font-size: 0.75rem;
        vertical-align: middle;
    }
    
    .table-xs th.bg-base-300 {
        background-color: #f3f4f6;
        font-weight: 600;
    }
    
    /* Style pour les avatars */
    .avatar {
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    
    /* Style pour les barres de progression de similarité */
    .w-16.bg-gray-300 {
        background-color: #e5e7eb;
    }
    
    /* Style pour les statistiques */
    .stats {
        background: white;
    }
    
    .stat {
        border-right: 1px solid #e5e7eb;
    }
    
    .stat:last-child {
        border-right: none;
    }
    
    /* Responsive design */
    @media (max-width: 768px) {
        .table-xs {
            font-size: 0.7rem;
        }
        
        .avatar img {
            width: 40px;
            height: 40px;
        }
        
        .stats.stats-horizontal {
            display: grid;
            grid-template-columns: 1fr 1fr;
        }
        
        .stat {
            border-right: none;
            border-bottom: 1px solid #e5e7eb;
        }
    }
    
    /* Animation pour le chargement */
    .loading {
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    
    /* Style pour les tooltips */
    .tooltip {
        position: relative;
    }
    
    .tooltip:hover::before {
        content: attr(data-tip);
        position: absolute;
        bottom: 100%;
        left: 50%;
        transform: translateX(-50%);
        background: #333;
        color: white;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 12px;
        white-space: nowrap;
        z-index: 10;
    }
    
    /* Style pour les alertes */
    .alert {
        border-radius: 0.5rem;
        padding: 1rem;
    }
    
    .alert-info {
        background-color: #dbeafe;
        border-color: #93c5fd;
        color: #1e40af;
    }
    
    .alert-warning {
        background-color: #fef3c7;
        border-color: #fbbf24;
        color: #92400e;
    }
</style>    
@endpush