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

<div class="bg-white">
    <!-- Header avec le bouton de recherche -->
    <div class="px-6 py-4 border-b border-gray-200">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-bold text-gray-900">Recherche de produit</h2>
            <div class="flex gap-2">
                <button wire:click="toggleManualSearch"
                    class="px-4 py-2 {{ $manualSearchMode ? 'bg-gray-600' : 'bg-green-600' }} text-white rounded-lg hover:opacity-90 font-medium shadow-sm">
                    {{ $manualSearchMode ? 'Mode Auto' : 'Recherche Manuelle' }}
                </button>
                <button wire:click="extractSearchTerme" wire:loading.attr="disabled"
                    class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 font-medium shadow-sm">
                    <span wire:loading.remove>Rechercher à nouveau</span>
                    <span wire:loading>Extraction en cours...</span>
                </button>
            </div>
        </div>
    </div>

    <livewire:plateformes.detail :id="$productId" />

    <!-- Formulaire de recherche manuelle -->
    @if($manualSearchMode)
        <div class="px-6 py-4 bg-blue-50 border-b border-blue-200">
            <h3 class="font-semibold text-gray-900 mb-3">🔍 Recherche Manuelle</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Vendor (readonly) -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Marque (Vendor)</label>
                    <input type="text" wire:model="manualVendor" readonly
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-100 text-gray-600 cursor-not-allowed">
                </div>

                <!-- Name -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nom de la gamme</label>
                    <input type="text" wire:model="manualName"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                        placeholder="Ex: J'adore, N°5, Vital Perfection">
                </div>

                <!-- Type -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Type de produit</label>
                    <input type="text" wire:model="manualType"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                        placeholder="Ex: Eau de Parfum, Crème visage">
                </div>

                <!-- Variation -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Contenance</label>
                    <input type="text" wire:model="manualVariation"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                        placeholder="Ex: 50 ml, 200 ml">
                </div>
            </div>

            <div class="mt-4 flex justify-end">
                <button wire:click="manualSearch" wire:loading.attr="disabled"
                    class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 font-medium shadow-sm">
                    <span wire:loading.remove>🔎 Lancer la recherche</span>
                    <span wire:loading>Recherche en cours...</span>
                </button>
            </div>
        </div>
    @endif

    <!-- Filtres par site -->
    @if(!empty($availableSites))
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-semibold text-gray-700">Filtrer par site</h3>
                <button wire:click="toggleAllSites" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">
                    {{ count($selectedSites) === count($availableSites) ? 'Tout désélectionner' : 'Tout sélectionner' }}
                </button>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2">
                @foreach($availableSites as $site)
                    <label class="flex items-center space-x-2 cursor-pointer hover:bg-gray-100 p-2 rounded">
                        <input type="checkbox" wire:model.live="selectedSites" value="{{ $site['id'] }}"
                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        <span class="text-sm">{{ $site['name'] }}</span>
                    </label>
                @endforeach
            </div>
            <p class="text-xs text-gray-500 mt-2">
                {{ count($selectedSites) }} site(s) sélectionné(s)
            </p>
        </div>
    @endif

    @if(session('error'))
        <div class="mx-6 mt-4 p-4 bg-red-100 text-red-700 rounded-lg">
            {{ session('error') }}
        </div>
    @endif

    @if(session('success'))
        <div class="mx-6 mt-4 p-4 bg-green-100 text-green-700 rounded-lg">
            {{ session('success') }}
        </div>
    @endif

    <!-- Contenu principal -->
    <div class="p-6">
        <!-- Indicateur de chargement -->
        @if($isLoading)
            <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <div class="flex items-center space-x-3">
                    <div class="animate-spin rounded-full h-5 w-5 border-b-2 border-indigo-600"></div>
                    <p class="text-sm text-blue-800">
                        Extraction et recherche en cours pour "<span class="font-semibold">{{ $productName }}</span>"...
                    </p>
                </div>
            </div>
        @endif

        <!-- Statistiques (quand la recherche est terminée) -->
        @if(!empty($groupedResults) && !$isLoading)
            <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <p class="text-sm text-blue-800">
                    <span class="font-semibold">{{ count($matchingProducts) }}</span> produit(s) unique(s) trouvé(s)
                    @if(isset($groupedResults['_site_stats']))
                        (après déduplication)
                    @endif
                </p>
                @if(isset($groupedResults['_site_stats']))
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach($groupedResults['_site_stats'] as $siteId => $stats)
                            @php
                                $siteInfo = collect($availableSites)->firstWhere('id', $siteId);
                            @endphp
                            @if($siteInfo)
                                <span class="px-2 py-1 bg-white border border-blue-300 rounded text-xs">
                                    <span class="font-semibold">{{ $siteInfo['name'] }}</span>: 
                                    <span class="text-blue-700 font-bold">{{ $stats['total_products'] }}</span>
                                </span>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        <!-- Section des produits -->
        @if(!empty($matchingProducts) && !$isLoading)
            <div class="mx-auto max-w-7xl overflow-hidden sm:px-6 lg:px-8">
                <h2 class="sr-only">Produits</h2>

                <div class="-mx-px grid grid-cols-2 border-l border-gray-200 sm:mx-0 md:grid-cols-3 lg:grid-cols-4">
                    @foreach($matchingProducts as $product)
                        @php
                            $hasUrl = !empty($product['url']);
                            $isBestMatch = $bestMatch && $bestMatch['id'] === $product['id'];
                            $cardClass = "group relative border-r border-b border-gray-200 p-4 sm:p-6 cursor-pointer transition hover:bg-gray-50";
                            if ($isBestMatch) {
                                $cardClass .= " ring-2 ring-indigo-500 bg-indigo-50";
                            }
                        @endphp

                        @if($hasUrl)
                            <a href="{{ $product['url'] }}" target="_blank" rel="noopener noreferrer" 
                               class="{{ $cardClass }}">
                        @else
                            <div class="{{ $cardClass }}">
                        @endif

                            <!-- Image du produit -->
                            @if(!empty($product['image_url']))
                                <img src="{{ $product['image_url'] }}" 
                                     alt="{{ $product['name'] }}"
                                     class="aspect-square rounded-lg bg-gray-200 object-cover group-hover:opacity-75"
                                     onerror="this.src='https://placehold.co/600x400/e5e7eb/9ca3af?text=No+Image'">
                            @else
                                <img src="https://placehold.co/600x400/e5e7eb/9ca3af?text=No+Image" 
                                     alt="Image non disponible"
                                     class="aspect-square rounded-lg bg-gray-200 object-cover group-hover:opacity-75">
                            @endif

                            <div class="pt-4 pb-4 text-center">
                                <!-- Badges de matching -->
                                <div class="mb-2 flex justify-center gap-1">
                                    @php
                                        // Vérifier si le name matche
                                        $nameMatches = false;
                                        if (!empty($extractedData['name'])) {
                                            $searchNameLower = mb_strtolower($extractedData['name']);
                                            $productNameLower = mb_strtolower($product['name'] ?? '');
                                            $nameMatches = str_contains($productNameLower, $searchNameLower);
                                        }

                                        // Vérifier si le type matche
                                        $typeMatches = false;
                                        if (!empty($extractedData['type'])) {
                                            $searchTypeLower = mb_strtolower($extractedData['type']);
                                            $productTypeLower = mb_strtolower($product['type'] ?? '');
                                            $typeMatches = str_contains($productTypeLower, $searchTypeLower);
                                        }
                                    @endphp

                                    @if($nameMatches)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                            ✓ Name
                                        </span>
                                    @endif

                                    @if($typeMatches)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                            ✓ Type
                                        </span>
                                    @endif
                                </div>

                                <!-- Badge coffret -->
                                @if($product['name'] && (str_contains(strtolower($product['name']), 'coffret') || str_contains(strtolower($product['name']), 'set') || str_contains(strtolower($product['type'] ?? ''), 'coffret')))
                                    <div class="mb-2">
                                        <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-purple-100 text-purple-800">🎁 Coffret</span>
                                    </div>
                                @endif

                                <!-- Nom du produit -->
                                <h3 class="text-sm font-medium text-gray-900">
                                    {{ $product['vendor'] }}
                                </h3>
                                <p class="text-xs text-gray-600 mt-1 truncate">{{ $product['name'] }}</p>

                                <!-- Type avec badge coloré -->
                                @php
                                    $productTypeLower = strtolower($product['type'] ?? '');
                                    $badgeColor = 'bg-gray-100 text-gray-800';

                                    if (str_contains($productTypeLower, 'eau de toilette') || str_contains($productTypeLower, 'eau de parfum')) {
                                        $badgeColor = 'bg-purple-100 text-purple-800';
                                    } elseif (str_contains($productTypeLower, 'déodorant') || str_contains($productTypeLower, 'deodorant')) {
                                        $badgeColor = 'bg-green-100 text-green-800';
                                    } elseif (str_contains($productTypeLower, 'crème') || str_contains($productTypeLower, 'creme')) {
                                        $badgeColor = 'bg-pink-100 text-pink-800';
                                    } elseif (str_contains($productTypeLower, 'huile')) {
                                        $badgeColor = 'bg-yellow-100 text-yellow-800';
                                    }
                                @endphp

                                <div class="mt-1">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $badgeColor }}">
                                        {{ $product['type'] }}
                                    </span>
                                </div>

                                <p class="text-xs text-gray-400 mt-1">{{ $product['variation'] }}</p>

                                <!-- Site -->
                                @php
                                    $siteInfo = collect($availableSites)->firstWhere('id', $product['web_site_id']);
                                @endphp
                                @if($siteInfo)
                                    <div class="mt-2">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                            {{ $siteInfo['name'] }}
                                        </span>
                                    </div>
                                @endif

                                <!-- Prix -->
                                <p class="mt-4 text-base font-medium text-gray-900">
                                    {{ number_format((float) ($product['prix_ht'] ?? 0), 2) }} €
                                </p>

                                <!-- Bouton voir produit -->
                                @if($hasUrl)
                                    <div class="mt-2">
                                        <span class="inline-flex items-center text-xs font-medium text-indigo-600">
                                            Ouvrir dans un nouvel onglet
                                            <svg class="ml-1 h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                            </svg>
                                        </span>
                                    </div>
                                @else
                                    <div class="mt-2">
                                        <span class="inline-flex items-center text-xs font-medium text-gray-400">
                                            URL non disponible
                                        </span>
                                    </div>
                                @endif

                                <!-- ID scrape -->
                                @if(isset($product['scrape_reference_id']))
                                    <p class="text-xs text-gray-400 mt-2">Scrape ID: {{ $product['scrape_reference_id'] }}</p>
                                @endif
                            </div>

                        @if($hasUrl)
                            </a>
                        @else
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        @elseif($isLoading)
            <!-- État de chargement -->
            <div class="text-center py-12 bg-gray-50 rounded-lg border-2 border-dashed border-gray-300">
                <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-indigo-600 mx-auto"></div>
                <h3 class="mt-4 text-sm font-medium text-gray-900">Extraction en cours</h3>
                <p class="mt-1 text-sm text-gray-500">Analyse du produit et recherche des correspondances...</p>
            </div>
        @elseif($extractedData && empty($matchingProducts))
            <!-- Aucun résultat -->
            <div class="text-center py-12 bg-gray-50 rounded-lg border-2 border-dashed border-gray-300">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <h3 class="mt-2 text-sm font-medium text-gray-900">Aucun produit trouvé</h3>
                <p class="mt-1 text-sm text-gray-500">Essayez de modifier les filtres par site ou utilisez la recherche manuelle</p>
            </div>
        @else
            <!-- État initial (avant chargement) -->
            <div class="text-center py-12 bg-gray-50 rounded-lg border-2 border-dashed border-gray-300">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <h3 class="mt-2 text-sm font-medium text-gray-900">Prêt à rechercher</h3>
                <p class="mt-1 text-sm text-gray-500">L'extraction démarre automatiquement...</p>
            </div>
        @endif
    </div>
</div>
