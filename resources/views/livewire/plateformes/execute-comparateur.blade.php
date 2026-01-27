<?php

use Livewire\Volt\Component;
use App\Models\Product;
use App\Models\Site;
use Illuminate\Support\Facades\Http;

new class extends Component {

    public string $productName = '';
    public $productPrice;
    public $productId;
    public $extractedData = null;
    public $isLoading = false;
    public $matchingProducts = [];
    public $bestMatch = null;
    public $aiValidation = null;
    public $availableSites = [];
    public $selectedSites = [];
    public $groupedResults = [];
    public $allFoundProducts = [];
    public $debugInfo = null; // Pour le débogage

    public function mount($name, $id, $price): void
    {
        $this->productName = $name;
        $this->productId = $id;
        $this->productPrice = $price;

        $this->availableSites = Site::orderBy('name')->get()->toArray();
        $this->selectedSites = collect($this->availableSites)->pluck('id')->toArray();
    }

    public function extractSearchTerme()
    {
        $this->isLoading = true;
        $this->extractedData = null;
        $this->matchingProducts = [];
        $this->allFoundProducts = [];
        $this->bestMatch = null;
        $this->aiValidation = null;
        $this->groupedResults = [];
        $this->debugInfo = null;

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            ])->post('https://api.openai.com/v1/chat/completions', [
                        'model' => 'gpt-4o-mini',
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => 'Tu es un expert en extraction de données de produits cosmétiques. EXTRACTION DU VENDOR : Prends TOUJOURS la version OFFICIELLE de la marque. Ex: "Yves Saint Laurent" devient "YVES SAINT LAURENT", "Estée Lauder" reste "ESTÉE LAUDER". Réponds UNIQUEMENT avec un objet JSON.'
                            ],
                            [
                                'role' => 'user',
                                'content' => "Analyse ce nom de produit cosmétique et extrait les informations :

Produit : {$this->productName}

RÈGLES D'EXTRACTION :
1. **vendor** : MARQUE en MAJUSCULES (ex: YVES SAINT LAURENT, LANCÔME, DIOR)
2. **name** : Nom du produit/collection (ex: MON PARIS, COFFRET MON PARIS)
3. **variation** : Taille ou détails techniques
4. **type** : Catégorie (ex: COFFRET, EAU DE PARFUM, CRÈME)
5. **is_coffret** : true si coffret/set/kit

Retourne JSON :"
                            ]
                        ],
                        'temperature' => 0.1, // Très bas pour la cohérence
                        'max_tokens' => 500
                    ]);

            if ($response->successful()) {
                $data = $response->json();
                $content = $data['choices'][0]['message']['content'];
                $content = preg_replace('/```json\s*|\s*```/', '', $content);
                $content = trim($content);

                $decodedData = json_decode($content, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('Erreur parsing JSON: ' . json_last_error_msg());
                }

                // NORMALISATION du vendor en MAJUSCULES
                if (isset($decodedData['vendor'])) {
                    $decodedData['vendor'] = mb_strtoupper(trim($decodedData['vendor']));
                }

                $this->extractedData = array_merge([
                    'vendor' => '',
                    'name' => '',
                    'variation' => '',
                    'type' => '',
                    'is_coffret' => false
                ], $decodedData);

                // DEBUG
                $this->debugInfo = [
                    'produit_source' => $this->productName,
                    'extracted' => $this->extractedData,
                    'timestamp' => now()->toDateTimeString()
                ];

                \Log::info('Extraction IA', $this->debugInfo);

                // Recherche
                $this->searchMatchingProducts();

            } else {
                throw new \Exception('Erreur API OpenAI: ' . $response->status());
            }

        } catch (\Exception $e) {
            \Log::error('Erreur extraction', ['message' => $e->getMessage()]);
            session()->flash('error', 'Erreur: ' . $e->getMessage());
        } finally {
            $this->isLoading = false;
        }
    }

    /**
     * Normalise un nom de vendor pour la recherche
     */
    private function normalizeVendor(string $vendor): string
    {
        $vendor = trim($vendor);
        
        // Convertir en majuscules
        $vendor = mb_strtoupper($vendor);
        
        // Normalisations spécifiques
        $normalizations = [
            'YVES SAINT LAURENT' => 'YVES SAINT LAURENT',
            'YSL' => 'YVES SAINT LAURENT',
            'ESTEE LAUDER' => 'ESTÉE LAUDER',
            'MAC COSMETICS' => 'M·A·C',
            'MAC' => 'M·A·C',
            // Ajoutez d'autres normalisations ici
        ];
        
        return $normalizations[$vendor] ?? $vendor;
    }

    private function searchMatchingProducts()
    {
        if (empty($this->extractedData) || !is_array($this->extractedData)) {
            return;
        }

        $extractedData = array_merge([
            'vendor' => '',
            'name' => '',
            'variation' => '',
            'type' => '',
            'is_coffret' => false
        ], $this->extractedData);

        $vendor = $extractedData['vendor'] ?? '';
        $name = $extractedData['name'] ?? '';
        $type = $extractedData['type'] ?? '';
        $isCoffretSource = $extractedData['is_coffret'] ?? false;

        // Normaliser le vendor
        $vendorNormalized = $this->normalizeVendor($vendor);
        $vendorSearch = mb_strtoupper(trim($vendorNormalized));

        \Log::info('Recherche démarrée', [
            'vendor_original' => $vendor,
            'vendor_normalized' => $vendorNormalized,
            'vendor_search' => $vendorSearch,
            'name' => $name,
            'type' => $type
        ]);

        // STRATÉGIE DE RECHERCHE AMÉLIORÉE
        $allFound = collect();

        // 1. Recherche par VENDOR normalisé (le plus important)
        $baseQuery = Product::query()
            ->when(!empty($vendorSearch), function ($q) use ($vendorSearch) {
                // Recherche FLEXIBLE par vendor
                $q->where(function ($subQuery) use ($vendorSearch) {
                    // Recherche exacte
                    $subQuery->whereRaw('UPPER(TRIM(vendor)) = ?', [$vendorSearch]);
                    
                    // Recherche par similarité (pour les variantes)
                    $vendorWords = explode(' ', $vendorSearch);
                    foreach ($vendorWords as $word) {
                        if (mb_strlen($word) > 2) {
                            $subQuery->orWhereRaw('UPPER(vendor) LIKE ?', ['%' . $word . '%']);
                        }
                    }
                    
                    // Cas spéciaux
                    if ($vendorSearch === 'YVES SAINT LAURENT') {
                        $subQuery->orWhereRaw('UPPER(vendor) LIKE ?', ['%YSL%']);
                    }
                });
            })
            ->when(!empty($this->selectedSites), function ($q) {
                $q->whereIn('web_site_id', $this->selectedSites);
            });

        // DEBUG: Compter tous les produits de ce vendor
        $totalVendorProducts = (clone $baseQuery)->count();
        \Log::info('Produits du vendor trouvés', [
            'vendor' => $vendorSearch,
            'total' => $totalVendorProducts,
            'sites_selectionnes' => $this->selectedSites
        ]);

        // 2. Recherche par NAME (si disponible)
        if (!empty($name)) {
            $nameNormalized = mb_strtoupper(trim($name));
            
            // A. Recherche exacte
            $exactNameMatch = (clone $baseQuery)
                ->whereRaw('UPPER(TRIM(name)) = ?', [$nameNormalized])
                ->get();
            
            if ($exactNameMatch->isNotEmpty()) {
                $filtered = $this->filterByCoffretStatus($exactNameMatch, $isCoffretSource);
                $allFound = $allFound->merge($filtered);
            }
            
            // B. Recherche par mots du name
            $nameWords = explode(' ', $nameNormalized);
            $nameWords = array_filter($nameWords, function($word) {
                return mb_strlen($word) >= 3;
            });
            
            if (!empty($nameWords)) {
                $similarNameMatch = (clone $baseQuery);
                foreach ($nameWords as $word) {
                    $similarNameMatch->whereRaw('UPPER(name) LIKE ?', ['%' . $word . '%']);
                }
                $similarNameMatch = $similarNameMatch->get();
                
                if ($similarNameMatch->isNotEmpty()) {
                    $filtered = $this->filterByCoffretStatus($similarNameMatch, $isCoffretSource);
                    $allFound = $allFound->merge($filtered);
                }
            }
        }

        // 3. Si peu de résultats, recherche par TYPE
        if ($allFound->count() < 5 && !empty($type)) {
            $typeNormalized = mb_strtoupper(trim($type));
            $typeWords = explode(' ', $typeNormalized);
            
            $typeMatch = (clone $baseQuery)
                ->where(function ($q) use ($typeWords) {
                    foreach ($typeWords as $word) {
                        if (mb_strlen($word) >= 3) {
                            $q->orWhereRaw('UPPER(type) LIKE ?', ['%' . $word . '%']);
                        }
                    }
                })
                ->limit(50)
                ->get();
            
            if ($typeMatch->isNotEmpty()) {
                $filtered = $this->filterByCoffretStatus($typeMatch, $isCoffretSource);
                $allFound = $allFound->merge($filtered);
            }
        }

        // 4. Si toujours peu, prendre TOUS les produits du vendor
        if ($allFound->isEmpty() && !empty($vendorSearch)) {
            $allVendorProducts = (clone $baseQuery)
                ->orderByDesc('scrap_reference_id')
                ->orderByDesc('id')
                ->limit(100)
                ->get();
            
            if ($allVendorProducts->isNotEmpty()) {
                $filtered = $this->filterByCoffretStatus($allVendorProducts, $isCoffretSource);
                $allFound = $allFound->merge($filtered);
            }
        }

        // 5. Traitement des résultats
        if ($allFound->isNotEmpty()) {
            // Éliminer les doublons par ID
            $allFound = $allFound->unique('id');
            
            // Stocker TOUS les résultats
            $this->allFoundProducts = $allFound->toArray();
            
            \Log::info('Résultats trouvés', [
                'total' => $allFound->count(),
                'premiers_produits' => $allFound->take(3)->pluck('name', 'web_site_id')
            ]);
            
            // Grouper pour affichage (1 par site)
            $this->groupResultsBySiteAndProduct($this->allFoundProducts);
            
            // Validation IA
            $this->validateBestMatchWithAI();
        } else {
            \Log::warning('Aucun résultat trouvé', [
                'vendor' => $vendorSearch,
                'name' => $name,
                'sites_selectionnes' => count($this->selectedSites)
            ]);
        }
    }

    /**
     * Groupe les résultats par site
     */
    private function groupResultsBySiteAndProduct(array $products)
    {
        if (empty($products)) {
            $this->matchingProducts = [];
            $this->groupedResults = [];
            return;
        }

        $productsCollection = collect($products);

        // Grouper par site
        $groupedBySite = $productsCollection->groupBy('web_site_id');

        // Pour chaque site, garder le produit avec le scrap_reference_id le plus élevé
        $uniqueProductsBySite = $groupedBySite->map(function ($siteProducts, $siteId) {
            return $siteProducts->sortByDesc('scrap_reference_id')
                ->sortByDesc('id')
                ->first();
        })->filter()->values();

        $this->matchingProducts = $uniqueProductsBySite->take(50)->toArray();

        // Statistiques
        $this->groupedResults = $groupedBySite->map(function ($siteProducts, $siteId) {
            return [
                'site_id' => $siteId,
                'total_products' => $siteProducts->count(),
                'max_scrap_reference_id' => $siteProducts->max('scrap_reference_id'),
                'latest_product' => $siteProducts->sortByDesc('scrap_reference_id')->first(),
                'product_ids' => $siteProducts->pluck('id')->toArray()
            ];
        })->toArray();
    }

    // ... [Les autres méthodes restent similaires: isCoffret, extractKeywords, filterByCoffretStatus, validateBestMatchWithAI, etc.] ...

}; ?>

<div class="p-6 bg-white rounded-lg shadow">
    <div class="mb-4">
        <h2 class="text-xl font-bold mb-2">Extraction et recherche de produit</h2>
        <p class="text-gray-600">Produit: {{ $productName }}</p>
        
        <!-- Debug info -->
        @if($debugInfo)
            <div class="mt-2 p-3 bg-gray-100 rounded text-xs">
                <p class="font-semibold">Debug IA :</p>
                <p>Vendor extrait: <span class="font-bold">{{ $extractedData['vendor'] ?? 'N/A' }}</span></p>
                <p>Name extrait: {{ $extractedData['name'] ?? 'N/A' }}</p>
                <p>Type extrait: {{ $extractedData['type'] ?? 'N/A' }}</p>
            </div>
        @endif
    </div>

    <!-- Filtres par site -->
    @if(!empty($availableSites))
        <div class="mb-6 p-4 bg-gray-50 rounded-lg border border-gray-200">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-semibold text-gray-700">Filtrer par site ({{ count($selectedSites) }}/{{ count($availableSites) }})</h3>
                <button wire:click="toggleAllSites" class="text-sm text-blue-600 hover:text-blue-800 font-medium">
                    {{ count($selectedSites) === count($availableSites) ? 'Tout désélectionner' : 'Tout sélectionner' }}
                </button>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2">
                @foreach($availableSites as $site)
                    <label class="flex items-center space-x-2 cursor-pointer hover:bg-gray-100 p-2 rounded">
                        <input type="checkbox" wire:model.live="selectedSites" value="{{ $site['id'] ?? '' }}"
                            class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span class="text-sm">{{ $site['name'] ?? 'Site inconnu' }}</span>
                    </label>
                @endforeach
            </div>
        </div>
    @endif

    <button wire:click="extractSearchTerme" wire:loading.attr="disabled"
        class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600 disabled:opacity-50 mb-4">
        <span wire:loading.remove>Extraire et rechercher</span>
        <span wire:loading>Extraction en cours...</span>
    </button>

    <!-- Messages d'erreur/succès -->
    @if(session('error'))
        <div class="mt-4 p-4 bg-red-100 text-red-700 rounded">
            {{ session('error') }}
        </div>
    @endif

    @if(session('success'))
        <div class="mt-4 p-4 bg-green-100 text-green-700 rounded">
            {{ session('success') }}
        </div>
    @endif

    <!-- Données extraites -->
    @if(!empty($extractedData) && is_array($extractedData))
        <div class="mt-6 p-4 bg-gray-50 rounded">
            <h3 class="font-bold mb-3">Critères extraits par IA :</h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <span class="font-semibold">Vendor:</span> 
                    <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-sm font-bold">
                        {{ $extractedData['vendor'] ?? 'N/A' }}
                    </span>
                </div>
                <div>
                    <span class="font-semibold">Name:</span> 
                    <span class="bg-green-100 text-green-800 px-2 py-1 rounded text-sm">
                        {{ $extractedData['name'] ?? 'N/A' }}
                    </span>
                </div>
                <div>
                    <span class="font-semibold">Type:</span> {{ $extractedData['type'] ?? 'N/A' }}
                </div>
                <div>
                    <span class="font-semibold">Variation:</span> {{ $extractedData['variation'] ?? 'N/A' }}
                </div>
                <div class="col-span-2">
                    <span class="font-semibold">Est un coffret:</span>
                    <span class="px-2 py-1 rounded text-sm {{ ($extractedData['is_coffret'] ?? false) ? 'bg-purple-100 text-purple-800' : 'bg-gray-100 text-gray-800' }}">
                        {{ ($extractedData['is_coffret'] ?? false) ? 'Oui' : 'Non' }}
                    </span>
                </div>
            </div>
        </div>
    @endif

    <!-- Statistiques résultats -->
    @if(!empty($allFoundProducts))
        <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded">
            <p class="text-sm text-blue-800">
                <span class="font-semibold">{{ count($allFoundProducts) }}</span> produit(s) trouvé(s) pour 
                <span class="font-bold">{{ $extractedData['vendor'] ?? 'N/A' }}</span>
            </p>
            <p class="text-xs text-blue-600 mt-1">
                Affichés: {{ count($matchingProducts) }} produit(s) unique(s) (1 par site) | 
                Sites: {{ count($groupedResults) }}
            </p>
            
            <!-- Liste des sites avec résultats -->
            @if(!empty($groupedResults))
                <div class="mt-2">
                    <p class="text-xs font-semibold text-blue-700">Sites avec résultats:</p>
                    <div class="flex flex-wrap gap-1 mt-1">
                        @foreach($groupedResults as $siteId => $siteData)
                            @php
                                $siteInfo = collect($availableSites)->firstWhere('id', $siteId);
                                $productCount = $siteData['total_products'] ?? 0;
                            @endphp
                            @if($siteInfo)
                                <span class="text-xs px-2 py-1 bg-white border border-blue-200 rounded">
                                    {{ $siteInfo['name'] }} ({{ $productCount }})
                                </span>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif

    <!-- Validation IA -->
    @if(!empty($aiValidation) && is_array($aiValidation))
        <div class="mt-4 p-4 bg-blue-50 border border-blue-300 rounded">
            <h3 class="font-bold text-blue-700 mb-2">🤖 Validation IA :</h3>
            <p class="text-sm mb-1">
                <span class="font-semibold">Score de confiance:</span>
                <span class="text-lg font-bold {{ ($aiValidation['confidence_score'] ?? 0) >= 0.8 ? 'text-green-600' : (($aiValidation['confidence_score'] ?? 0) >= 0.6 ? 'text-yellow-600' : 'text-red-600') }}">
                    {{ number_format(($aiValidation['confidence_score'] ?? 0) * 100, 0) }}%
                </span>
            </p>
            <p class="text-sm text-gray-700">
                <span class="font-semibold">Analyse:</span> {{ $aiValidation['reasoning'] ?? 'N/A' }}
            </p>
        </div>
    @endif

    <!-- Meilleur résultat -->
    @if(!empty($bestMatch) && is_array($bestMatch))
        <div class="mt-6 p-4 bg-green-50 border-2 border-green-500 rounded">
            <h3 class="font-bold text-green-700 mb-3">✓ Meilleur résultat :</h3>
            <div class="flex items-start gap-4">
                @if(!empty($bestMatch['image_url']))
                    <img src="{{ $bestMatch['image_url'] }}" alt="{{ $bestMatch['name'] ?? '' }}"
                        class="w-20 h-20 object-cover rounded">
                @endif
                <div class="flex-1">
                    <p class="font-semibold">{{ $bestMatch['vendor'] ?? '' }} - {{ $bestMatch['name'] ?? '' }}</p>
                    <p class="text-sm text-gray-600">{{ $bestMatch['type'] ?? '' }} | {{ $bestMatch['variation'] ?? '' }}</p>
                    
                    @php
                        $siteInfo = collect($availableSites)->firstWhere('id', $bestMatch['web_site_id'] ?? 0);
                    @endphp
                    @if(!empty($siteInfo))
                        <div class="mt-2">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                {{ $siteInfo['name'] ?? '' }}
                            </span>
                        </div>
                    @endif
                    
                    <p class="text-sm font-bold text-green-600 mt-2">{{ $bestMatch['prix_ht'] ?? 0 }}
                        {{ $bestMatch['currency'] ?? '' }}</p>
                    @if(!empty($bestMatch['url']))
                        <a href="{{ $bestMatch['url'] }}" target="_blank" class="text-xs text-blue-500 hover:underline">Voir le produit</a>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <!-- Produits uniques par site -->
    @if(!empty($matchingProducts) && count($matchingProducts) > 0)
        <div class="mt-6">
            <h3 class="font-bold mb-3">
                Produits par site ({{ count($matchingProducts) }}) :
                <span class="text-sm font-normal text-gray-600">(Dernier scrap par site)</span>
            </h3>
            <div class="space-y-3 max-h-96 overflow-y-auto">
                @foreach($matchingProducts as $product)
                    @php
                        $product = is_array($product) ? $product : [];
                        $siteInfo = collect($availableSites)->firstWhere('id', $product['web_site_id'] ?? 0);
                        $siteId = $product['web_site_id'] ?? 0;
                        $siteProductCount = $groupedResults[$siteId]['total_products'] ?? 0;
                    @endphp
                    <div wire:click="selectProduct({{ $product['id'] ?? 0 }})"
                        class="p-3 border rounded hover:bg-blue-50 cursor-pointer transition {{ !empty($bestMatch['id']) && $bestMatch['id'] === ($product['id'] ?? 0) ? 'bg-blue-100 border-blue-500' : 'bg-white' }}">
                        <div class="flex items-start gap-3">
                            @if(!empty($product['image_url']))
                                <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] ?? '' }}"
                                    class="w-12 h-12 object-cover rounded">
                            @endif
                            <div class="flex-1">
                                <div class="flex justify-between">
                                    <div>
                                        <p class="font-medium text-sm">{{ $product['vendor'] ?? '' }} - {{ $product['name'] ?? '' }}</p>
                                        <p class="text-xs text-gray-500">{{ $product['type'] ?? '' }} | {{ $product['variation'] ?? '' }}</p>
                                    </div>
                                    <p class="font-bold text-sm">{{ $product['prix_ht'] ?? 0 }} {{ $product['currency'] ?? '' }}</p>
                                </div>
                                
                                <div class="flex items-center justify-between mt-2">
                                    <div class="flex items-center gap-2">
                                        @if(!empty($siteInfo))
                                            <span class="text-xs px-2 py-1 rounded bg-gray-100 text-gray-800">
                                                {{ $siteInfo['name'] ?? '' }}
                                                @if($siteProductCount > 1)
                                                    <span class="ml-1">({{ $siteProductCount }} versions)</span>
                                                @endif
                                            </span>
                                        @endif
                                    </div>
                                    
                                    <div class="text-right">
                                        <span class="text-xs text-gray-500">ID: {{ $product['id'] ?? 0 }}</span>
                                        @if(!empty($product['url']))
                                            <a href="{{ $product['url'] }}" target="_blank" 
                                                class="ml-2 text-xs text-blue-500 hover:text-blue-700 hover:underline"
                                                onclick="event.stopPropagation();">
                                                Voir
                                            </a>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <!-- Tous les produits -->
    @if(!empty($allFoundProducts) && count($allFoundProducts) > count($matchingProducts))
        <div class="mt-6">
            <h3 class="font-bold mb-3">
                Tous les produits ({{ count($allFoundProducts) }}) :
                <span class="text-sm font-normal text-gray-400">(Toutes versions, tous sites)</span>
            </h3>
            <div class="space-y-2 max-h-96 overflow-y-auto">
                @foreach($allFoundProducts as $product)
                    @php
                        $product = is_array($product) ? $product : [];
                        $siteInfo = collect($availableSites)->firstWhere('id', $product['web_site_id'] ?? 0);
                    @endphp
                    <div class="p-3 border rounded bg-gray-50">
                        <div class="flex items-start gap-3">
                            <div class="flex-1">
                                <div class="flex justify-between">
                                    <div>
                                        <p class="font-medium text-sm">{{ $product['vendor'] ?? '' }} - {{ $product['name'] ?? '' }}</p>
                                        <p class="text-xs text-gray-500">{{ $product['type'] ?? '' }} | {{ $product['variation'] ?? '' }}</p>
                                    </div>
                                    <p class="font-bold text-sm">{{ $product['prix_ht'] ?? 0 }} {{ $product['currency'] ?? '' }}</p>
                                </div>
                                
                                <div class="flex items-center justify-between mt-1">
                                    <div class="flex items-center gap-2">
                                        @if(!empty($siteInfo))
                                            <span class="text-xs px-2 py-1 bg-gray-200 text-gray-700 rounded">
                                                {{ $siteInfo['name'] ?? '' }}
                                            </span>
                                        @endif
                                        <span class="text-xs text-gray-500">
                                            Ref ID: {{ $product['scrap_reference_id'] ?? 0 }}
                                        </span>
                                    </div>
                                    <span class="text-xs text-gray-500">ID: {{ $product['id'] ?? 0 }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <!-- Aucun résultat -->
    @if(!empty($extractedData) && empty($allFoundProducts))
        <div class="mt-6 p-4 bg-yellow-50 border border-yellow-300 rounded">
            <p class="text-yellow-800">
                ❌ Aucun produit trouvé pour la marque "{{ $extractedData['vendor'] ?? 'N/A' }}"
            </p>
            <p class="text-sm text-yellow-700 mt-1">
                Vérifiez que le vendor extrait correspond exactement à celui dans la base de données.
            </p>
        </div>
    @endif
</div>