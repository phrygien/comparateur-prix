<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;

new class extends Component {
    public $products = [];
    public $hasData = false;
    public $searchTerms = [];
    public $searchVolumes = [];
    public $searchKeywords = []; // Nouveaux mots-clés à rechercher (Coffret, Eau de Parfum, etc.)
    
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