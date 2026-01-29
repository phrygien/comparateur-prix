<?php

// CORRECTIFS PRINCIPAUX pour la recherche Guerlain

/**
 * 1. CORRECTION : filterByBaseType - Garder les produits SANS type
 */
private function filterByBaseType(array $products, string $searchType): array
{
    if (empty($searchType)) {
        return $products;
    }

    // Définir les catégories de types incompatibles
    $typeCategories = [
        'parfum' => ['eau de parfum', 'parfum', 'eau de toilette', 'eau de cologne', 'eau fraiche', 'extrait de parfum', 'extrait', 'cologne'],
        'déodorant' => ['déodorant', 'deodorant', 'deo', 'anti-transpirant', 'antitranspirant'],
        'crème' => ['crème', 'creme', 'baume', 'gel', 'lotion', 'fluide', 'soin'],
        'huile' => ['huile', 'oil'],
        'sérum' => ['sérum', 'serum', 'concentrate', 'concentré'],
        'masque' => ['masque', 'mask', 'patch'],
        'shampooing' => ['shampooing', 'shampoing', 'shampoo'],
        'après-shampooing' => ['après-shampooing', 'conditioner', 'après shampooing'],
        'savon' => ['savon', 'soap', 'gel douche', 'mousse'],
        'maquillage' => ['fond de teint', 'rouge à lèvres', 'mascara', 'eye-liner', 'fard', 'poudre'],
    ];

    $searchTypeLower = mb_strtolower(trim($searchType));

    // Trouver la catégorie du type recherché
    $searchCategory = null;
    foreach ($typeCategories as $category => $keywords) {
        foreach ($keywords as $keyword) {
            if (str_contains($searchTypeLower, $keyword)) {
                $searchCategory = $category;
                break 2;
            }
        }
    }

    // Si on n'a pas trouvé de catégorie, pas de filtrage
    if (!$searchCategory) {
        \Log::info('Type de recherche non catégorisé, pas de filtrage par type de base', [
            'type' => $searchType
        ]);
        return $products;
    }

    \Log::info('Filtrage par catégorie de type', [
        'type_recherché' => $searchType,
        'catégorie' => $searchCategory,
        'mots_clés_catégorie' => $typeCategories[$searchCategory]
    ]);

    // Filtrer les produits
    $filtered = collect($products)->filter(function ($product) use ($searchCategory, $typeCategories, $searchTypeLower) {
        $productType = mb_strtolower($product['type'] ?? '');

        // ⭐ CORRECTION : Si le produit n'a PAS de type, on le GARDE par défaut
        if (empty($productType)) {
            \Log::debug('Produit SANS type gardé par défaut', [
                'product_id' => $product['id'] ?? 0,
                'product_name' => $product['name'] ?? ''
            ]);
            return true; // ✅ Garder les produits sans type
        }

        // Vérifier si le produit appartient à la même catégorie
        $productCategory = null;
        foreach ($typeCategories as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($productType, $keyword)) {
                    $productCategory = $category;
                    break 2;
                }
            }
        }

        // Si le produit n'a pas de catégorie identifiée, on le garde par sécurité
        if (!$productCategory) {
            return true;
        }

        // Garder uniquement si même catégorie
        $match = ($productCategory === $searchCategory);

        if (!$match) {
            \Log::debug('Produit exclu par filtrage de type', [
                'product_id' => $product['id'] ?? 0,
                'product_name' => $product['name'] ?? '',
                'product_type' => $productType,
                'product_category' => $productCategory,
                'search_category' => $searchCategory
            ]);
        }

        return $match;
    })->values()->toArray();

    \Log::info('Résultat du filtrage par type de base', [
        'produits_avant' => count($products),
        'produits_après' => count($filtered),
        'produits_exclus' => count($products) - count($filtered)
    ]);

    return $filtered;
}

/**
 * 2. CORRECTION : Améliorer l'extraction des mots-clés pour Guerlain
 */
private function extractKeywords(string $text): array
{
    if (empty($text)) {
        return [];
    }

    // Mots à ignorer (stop words) - AJOUT de mots courants pour Guerlain
    $stopWords = [
        'de', 'la', 'le', 'les', 'des', 'du', 'un', 'une', 'et', 'ou', 'pour', 'avec', 'sans',
        'edition', 'limitée', 'limited', 'recharge', 'refill' // ⭐ AJOUT
    ];

    // Nettoyer et découper
    $text = mb_strtolower($text);
    
    // ⭐ CORRECTION : Gérer les numéros spéciaux (ex: "12 LE BRUN")
    // On garde les numéros AVEC le mot qui suit
    $text = preg_replace('/(\d+)\s+([a-z]+)/ui', '$1_$2', $text);
    
    $words = preg_split('/[\s\-]+/', $text, -1, PREG_SPLIT_NO_EMPTY);

    // Filtrer les mots courts et les stop words
    $keywords = array_filter($words, function ($word) use ($stopWords) {
        // ⭐ CORRECTION : Garder les mots avec underscore (ex: "12_le")
        if (str_contains($word, '_')) {
            return true;
        }
        return mb_strlen($word) >= 3 && !in_array($word, $stopWords);
    });

    // Replacer les underscores par des espaces
    $keywords = array_map(function($word) {
        return str_replace('_', ' ', $word);
    }, $keywords);

    return array_values($keywords);
}

/**
 * 3. CORRECTION : Améliorer le scoring pour les produits sans type
 */
private function scoreProduct($product, $typeParts, $type, $isCoffretSource, $nameWords)
{
    $score = 0;
    $productType = mb_strtolower($product['type'] ?? '');
    $productName = mb_strtolower($product['name'] ?? '');

    $matchedTypeParts = [];
    $typePartsCount = count($typeParts);

    // ==========================================
    // PRIORITÉ ABSOLUE : BONUS COFFRET
    // ==========================================
    $productIsCoffret = $this->isCoffret($product);

    if ($isCoffretSource && $productIsCoffret) {
        $score += 500;
    }

    // ==========================================
    // BONUS NAME : Compter combien de mots du name sont présents
    // ==========================================
    if (!empty($nameWords)) {
        $nameMatchCount = 0;
        foreach ($nameWords as $word) {
            if (str_contains($productName, $word)) {
                $nameMatchCount++;
            }
        }

        $nameMatchRatio = count($nameWords) > 0 ? ($nameMatchCount / count($nameWords)) : 0;
        $nameBonus = (int) ($nameMatchRatio * 300);
        $score += $nameBonus;

        if ($nameMatchCount === count($nameWords)) {
            $score += 200;
        }
    }

    // ==========================================
    // MATCHING HIÉRARCHIQUE SUR LE TYPE
    // ⭐ CORRECTION : Gérer les produits SANS type
    // ==========================================

    $typeMatched = false;

    // Si le produit n'a PAS de type
    if (empty($productType)) {
        // ⭐ On donne un bonus modéré si le NAME matche bien
        if (!empty($nameWords)) {
            $nameMatchCount = 0;
            foreach ($nameWords as $word) {
                if (str_contains($productName, $word)) {
                    $nameMatchCount++;
                }
            }
            
            // Si au moins 50% des mots du name matchent, on considère le type comme "potentiellement correct"
            if ($nameMatchCount >= (count($nameWords) * 0.5)) {
                $typeMatched = true;
                $score += 150; // Bonus pour compensation du type manquant
                
                \Log::debug('Produit SANS type mais NAME matche bien', [
                    'product_id' => $product['id'] ?? 0,
                    'name_match_count' => $nameMatchCount,
                    'name_words_total' => count($nameWords),
                    'bonus' => 150
                ]);
            }
        }
    } else {
        // Logique normale pour les produits AVEC type
        if (!empty($typeParts) && !empty($productType)) {
            // BONUS CRITIQUE : Vérifier que le TYPE DE BASE correspond
            if (!empty($typeParts[0])) {
                $baseTypeLower = mb_strtolower(trim($typeParts[0]));
                if (str_contains($productType, $baseTypeLower)) {
                    $score += 300;
                    $typeMatched = true;
                    \Log::debug('BONUS TYPE DE BASE correspondant', [
                        'product_id' => $product['id'] ?? 0,
                        'base_type_recherché' => $baseTypeLower,
                        'product_type' => $productType,
                        'bonus' => 300
                    ]);
                } else {
                    $score -= 200;
                    \Log::debug('MALUS TYPE DE BASE non correspondant', [
                        'product_id' => $product['id'] ?? 0,
                        'base_type_recherché' => $baseTypeLower,
                        'product_type' => $productType,
                        'malus' => -200
                    ]);
                }
            }

            // Vérifier chaque partie du type dans l'ordre
            foreach ($typeParts as $index => $part) {
                $partLower = mb_strtolower(trim($part));
                if (!empty($partLower)) {
                    if (str_contains($productType, $partLower)) {
                        $partBonus = 100 - ($index * 20);
                        $partBonus = max($partBonus, 20);

                        $score += $partBonus;
                        $matchedTypeParts[] = [
                            'part' => $part,
                            'bonus' => $partBonus,
                            'position' => $index + 1
                        ];

                        if ($index == 0 || $typeMatched) {
                            $typeMatched = true;
                        }
                    }
                }
            }

            // BONUS supplémentaire si TOUTES les parties du type correspondent
            if (count($matchedTypeParts) === $typePartsCount && $typePartsCount > 0) {
                $score += 150;
            }

            // BONUS MAXIMUM si le type complet est une sous-chaîne exacte
            $typeLower = mb_strtolower(trim($type));
            if (!empty($typeLower) && str_contains($productType, $typeLower)) {
                $score += 200;
                $typeMatched = true;
            }

            // BONUS supplémentaire si le type du produit commence par le type recherché
            if (!empty($typeLower) && str_starts_with($productType, $typeLower)) {
                $score += 100;
            }
        }
    }

    return [
        'product' => $product,
        'score' => $score,
        'matched_type_parts' => $matchedTypeParts,
        'all_type_parts_matched' => count($matchedTypeParts) === $typePartsCount,
        'type_parts_count' => $typePartsCount,
        'matched_count' => count($matchedTypeParts),
        'type_matched' => $typeMatched,
        'is_coffret' => $productIsCoffret,
        'coffret_bonus_applied' => ($isCoffretSource && $productIsCoffret),
        'name_match_count' => !empty($nameWords) ? array_reduce($nameWords, function ($count, $word) use ($productName) {
            return $count + (str_contains($productName, $word) ? 1 : 0);
        }, 0) : 0,
        'name_words_total' => count($nameWords),
        'has_type' => !empty($product['type'] ?? '') // ⭐ NOUVEAU : indicateur si le produit a un type
    ];
}

/**
 * 4. CORRECTION : Améliorer le prompt OpenAI pour mieux extraire les informations Guerlain
 */
private function getImprovedOpenAIPrompt($productName)
{
    return "Extrait les informations suivantes du nom de produit et retourne-les au format JSON strict :

RÈGLES IMPORTANTES POUR GUERLAIN :
- vendor : la marque du produit (ex: Guerlain, Dior, Chanel)
- name : le nom de la gamme/ligne de produit + NUMÉRO/CODE de teinte si présent
  * Pour Guerlain Rouge G : inclure \"Rouge G\" + le numéro et nom de teinte (ex: \"Rouge G 12 Le Brun Amarante\")
  * Pour les recharges : inclure \"La recharge\" dans le name
- type : UNIQUEMENT la catégorie/type du produit (ex: \"Le rouge à lèvres soin personnalisable\", \"Eau de Parfum\")
  * Si absent, laisser vide
- variation : la contenance/taille UNIQUEMENT (ex: \"200 ml\", \"50 ml\", \"3.5 g\")
  * Pour Guerlain Rouge G : mettre \"Satin\" ou \"Mat\" ou \"Velours\" si présent
  * Si \"Edition Limitée\" est mentionné, l'inclure dans variation
- is_coffret : true si c'est un coffret/set/kit, false sinon

Nom du produit : {$productName}

EXEMPLES SPÉCIFIQUES GUERLAIN :

Exemple 1 - \"Guerlain - Rouge G La recharge - Le rouge à lèvres soin personnalisable Edition Limitée 12 LE BRUN AMARANTE SATIN\"
{
  \"vendor\": \"Guerlain\",
  \"name\": \"Rouge G La recharge 12 Le Brun Amarante\",
  \"type\": \"Le rouge à lèvres soin personnalisable\",
  \"variation\": \"Satin - Edition Limitée\",
  \"is_coffret\": false
}

Exemple 2 - \"GUERLAIN Rouge G, Le rouge à lèvres soin personnalisable 03 Le Nude Intense 26.59 €\"
{
  \"vendor\": \"Guerlain\",
  \"name\": \"Rouge G 03 Le Nude Intense\",
  \"type\": \"Le rouge à lèvres soin personnalisable\",
  \"variation\": \"\",
  \"is_coffret\": false
}

Exemple 3 - \"Dior J'adore Les Adorables Huile Scintillante Huile pour le corps 200ml\"
{
  \"vendor\": \"Dior\",
  \"name\": \"J'adore Les Adorables\",
  \"type\": \"Huile pour le corps\",
  \"variation\": \"200 ml\",
  \"is_coffret\": false
}";
}

/**
 * 5. CORRECTION : Assouplir le filtrage NAME ET TYPE
 */
private function filterProductsByNameAndType($scoredProducts, $nameWords)
{
    // ⭐ CORRECTION : Assouplir les critères pour les produits sans type
    $filtered = $scoredProducts->filter(function ($item) use ($nameWords) {
        $hasNameMatch = !empty($nameWords) ? $item['name_match_count'] > 0 : true;
        $hasTypeMatch = $item['type_matched'];
        $hasType = $item['has_type'] ?? true; // Nouveau champ

        // ⭐ Si le produit n'a PAS de type, on assouplit les critères
        if (!$hasType) {
            // Pour les produits sans type, on exige uniquement un bon match sur le NAME
            $nameMatchRatio = count($nameWords) > 0 ? ($item['name_match_count'] / count($nameWords)) : 0;
            $keepProduct = $item['score'] > 0 && $nameMatchRatio >= 0.5; // Au moins 50% du name doit matcher
            
            if (!$keepProduct) {
                \Log::debug('Produit SANS type exclu car NAME insuffisant', [
                    'product_id' => $item['product']['id'] ?? 0,
                    'product_name' => $item['product']['name'] ?? '',
                    'score' => $item['score'],
                    'name_match_ratio' => round($nameMatchRatio * 100) . '%',
                    'name_match_count' => $item['name_match_count'],
                    'name_words_total' => $item['name_words_total']
                ]);
            }
            
            return $keepProduct;
        }

        // Pour les produits avec type, on garde les critères stricts
        $keepProduct = $item['score'] > 0 && $hasNameMatch && $hasTypeMatch;

        if (!$keepProduct) {
            \Log::debug('Produit exclu car ne match pas NAME ET TYPE', [
                'product_id' => $item['product']['id'] ?? 0,
                'product_name' => $item['product']['name'] ?? '',
                'product_type' => $item['product']['type'] ?? '',
                'score' => $item['score'],
                'name_match' => $hasNameMatch,
                'type_match' => $hasTypeMatch,
                'name_match_count' => $item['name_match_count'],
                'name_words_total' => $item['name_words_total']
            ]);
        }

        return $keepProduct;
    });

    return $filtered;
}

?>