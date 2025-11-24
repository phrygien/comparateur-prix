<?php

use Livewire\Volt\Component;

new class extends Component {
  
  public function mount($name): void
  {
    //dd($this->getCompetitorPrice($name));
  }

  public function getCompetitorPrice($search){
    try {
      if (empty(trim($search))) {
        return ["data" => []];
      }

      // Nettoyage et normalisation
      $cleanSearch = $this->cleanSearchString($search);
      $keywords = $this->extractKeywords($cleanSearch);
      
      if (empty($keywords)) {
        return ["data" => [], "keywords" => []];
      }
      
      // Recherche avec algorithme type YouTube
      $results = $this->youtubeStyleSearch($keywords, $cleanSearch);

      return [
        "data" => $results,
        "keywords" => $keywords,
        "total_results" => count($results),
        "original_search" => $search
      ];

    } catch (\Throwable $e) {
      \Log::error('Error loading products: ' . $e->getMessage(), [
        'search' => $search,
        'trace' => $e->getTraceAsString()
      ]);
      
      return [
        "data" => [],
        "error" => $e->getMessage()
      ];
    }
  }

  /**
   * Algorithme de recherche type YouTube
   * Combine plusieurs facteurs de scoring comme YouTube le fait
   */
  private function youtubeStyleSearch(array $keywords, string $fullSearch): array
  {
    $params = [];
    $scoringClauses = [];
    $whereConditions = [];
    
    // 1. EXACT MATCH SCORE (poids le plus élevé)
    // Si le nom contient la recherche complète exacte = bonus énorme
    $scoringClauses[] = "CASE WHEN LOWER(name) LIKE ? THEN 1000 ELSE 0 END";
    $params[] = "%" . $fullSearch . "%";
    
    // 2. STARTS WITH SCORE (commence par la recherche)
    $scoringClauses[] = "CASE WHEN LOWER(name) LIKE ? THEN 500 ELSE 0 END";
    $params[] = $fullSearch . "%";
    
    // 3. WORD ORDER SCORE (mots dans le bon ordre)
    // Simplifié : bonus si les mots sont proches les uns des autres
    for ($i = 0; $i < count($keywords) - 1; $i++) {
      $pattern = "%" . $keywords[$i] . "%" . $keywords[$i + 1] . "%";
      $scoringClauses[] = "CASE WHEN LOWER(name) LIKE ? THEN " . (40 - ($i * 5)) . " ELSE 0 END";
      $params[] = $pattern;
    }
    
    // 4. INDIVIDUAL KEYWORD SCORE (chaque mot-clé)
    // Pondération décroissante : premiers mots = plus importants
    foreach ($keywords as $index => $keyword) {
      $weight = (count($keywords) - $index) * 10;
      
      // Bonus si le mot est au début du nom
      $scoringClauses[] = "CASE WHEN LOWER(name) LIKE ? THEN " . ($weight * 2) . " ELSE 0 END";
      $params[] = $keyword . "%";
      
      // Score normal si le mot est présent n'importe où
      $scoringClauses[] = "CASE WHEN LOWER(name) LIKE ? THEN $weight ELSE 0 END";
      $params[] = "%" . $keyword . "%";
      
      // Condition WHERE : au moins un mot doit correspondre
      $whereConditions[] = "LOWER(name) LIKE ?";
      $params[] = "%" . $keyword . "%";
    }
    
    // 5. WORD DENSITY SCORE (densité de mots)
    // Plus il y a de mots qui matchent, mieux c'est
    $matchCount = [];
    $matchCountParams = [];
    foreach ($keywords as $keyword) {
      $matchCount[] = "CASE WHEN LOWER(name) LIKE ? THEN 1 ELSE 0 END";
      $matchCountParams[] = "%" . $keyword . "%";
    }
    $densityScore = "(" . implode(" + ", $matchCount) . ") * 15";
    $scoringClauses[] = $densityScore;
    
    // Ajouter les paramètres pour le match count
    $params = array_merge($params, $matchCountParams);
    
    // 6. LENGTH PENALTY (pénalité pour noms trop longs)
    // Favorise les noms courts et précis comme YouTube
    $scoringClauses[] = "CASE 
      WHEN LENGTH(name) <= 50 THEN 20
      WHEN LENGTH(name) <= 100 THEN 10
      WHEN LENGTH(name) <= 150 THEN 5
      ELSE 0 
    END";
    
    // 7. RECENCY SCORE (bonus pour les produits récents)
    $scoringClauses[] = "CASE 
      WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 30
      WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 15
      WHEN created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN 5
      ELSE 0 
    END";
    
    // Construction du score total
    $totalScore = "(" . implode(" + ", $scoringClauses) . ")";
    
    // Construction de la requête
    $whereClause = "(" . implode(" OR ", $whereConditions) . ")";
    
    // Dupliquer les paramètres matchCountParams pour la clause SELECT matched_words_count
    $finalParams = array_merge($params, $matchCountParams);
    
    $query = "
      SELECT 
        *,
        $totalScore AS relevance_score,
        (" . implode(" + ", $matchCount) . ") AS matched_words_count
      FROM scraped_product 
      WHERE $whereClause
      HAVING relevance_score > 0
      ORDER BY relevance_score DESC, matched_words_count DESC, created_at DESC 
      LIMIT 100
    ";
    
    $results = DB::connection('mysql')->select($query, $finalParams);
    
    // Filtrer uniquement les résultats avec un score proche du meilleur
    return $this->filterTopResults($results, $keywords);
  }

  /**
   * Nettoie la chaîne de recherche
   */
  private function cleanSearchString(string $search): string
  {
    // Supprime caractères spéciaux
    $clean = preg_replace("/[^\p{L}\p{N}\s]/u", " ", $search);
    $clean = preg_replace("/\s+/", " ", $clean);
    return mb_strtolower(trim($clean), 'UTF-8');
  }

  /**
   * Extrait les mots-clés significatifs
   */
  private function extractKeywords(string $cleanSearch): array
  {
    $stopWords = [
      'le', 'la', 'les', 'de', 'du', 'des', 'un', 'une', 'et', 'ou', 'en', 
      'pour', 'avec', 'sans', 'sur', 'au', 'aux', 'ce', 'ces', 'son', 'sa'
    ];
    
    $words = explode(" ", $cleanSearch);
    
    $keywords = array_filter($words, function($word) use ($stopWords) {
      return mb_strlen($word, 'UTF-8') >= 2 && !in_array($word, $stopWords);
    });
    
    return array_values($keywords);
  }

  /**
   * Filtre pour ne garder que les résultats avec les meilleurs scores
   */
  private function filterTopResults(array $results, array $keywords): array
  {
    if (empty($results)) {
      return [];
    }
    
    // Récupérer le meilleur score
    $bestScore = $results[0]->relevance_score ?? 0;
    
    if ($bestScore == 0) {
      return [];
    }
    
    // Définir les seuils de filtrage intelligents
    $totalKeywords = count($keywords);
    
    // Stratégie adaptative basée sur le meilleur score
    if ($bestScore >= 1000) {
      // Exact match trouvé : très strict (80% du meilleur)
      $threshold = $bestScore * 0.80;
    } elseif ($bestScore >= 500) {
      // Très bon match : strict (70% du meilleur)
      $threshold = $bestScore * 0.70;
    } elseif ($bestScore >= 200) {
      // Bon match : moyennement strict (60% du meilleur)
      $threshold = $bestScore * 0.60;
    } else {
      // Match faible : plus permissif (50% du meilleur)
      $threshold = $bestScore * 0.50;
    }
    
    // Filtrer les résultats
    $filtered = array_filter($results, function($result) use ($threshold, $totalKeywords) {
      // Doit avoir au moins le seuil de score
      $scoreOk = $result->relevance_score >= $threshold;
      
      // ET doit avoir au moins 50% des mots-clés
      $minWords = max(1, (int)ceil($totalKeywords * 0.5));
      $wordsOk = ($result->matched_words_count ?? 0) >= $minWords;
      
      return $scoreOk && $wordsOk;
    });
    
    // Limiter aux 20 meilleurs résultats maximum
    return array_slice(array_values($filtered), 0, 50);
  }

}; ?>
<div>

  <div class="mx-auto max-w-2xl px-4 py-2 sm:px-2 sm:py-4 lg:grid lg:max-w-7xl lg:grid-cols-2 lg:gap-x-8 lg:px-8">
    <!-- Product details -->
    <div class="lg:max-w-lg lg:self-end">
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

    <!-- Product image -->
    <div class="mt-10 lg:col-start-2 lg:row-span-2 lg:mt-0 lg:self-center">
      <img src="https://images.unsplash.com/photo-1556228720-195a672e8a03?w=800&q=80" alt="Model wearing light green backpack with black canvas straps and front zipper pouch." class="aspect-square w-full rounded-lg object-cover">
    </div>

    <!-- Product form -->
    <div class="mt-10 lg:col-start-1 lg:row-start-2 lg:max-w-lg lg:self-start">
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
                    <!--
                    Active: "border", Not Active: "border-2"
                    Checked: "border-indigo-500", Not Checked: "border-transparent"
                    -->
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
                    <!--
                    Active: "border", Not Active: "border-2"
                    Checked: "border-indigo-500", Not Checked: "border-transparent"
                    -->
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
          {{-- <div class="mt-10">
            <button type="submit" class="flex w-full items-center justify-center rounded-md border border-transparent bg-indigo-600 px-8 py-3 text-base font-medium text-white hover:bg-indigo-700 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-gray-50 focus:outline-hidden">Add to bag</button>
          </div> --}}
        </form>
      </section>
    </div>
  </div>


<livewire:plateformes.comparateur.product />

</div>
