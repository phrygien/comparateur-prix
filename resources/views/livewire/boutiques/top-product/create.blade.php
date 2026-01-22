<?php

use Livewire\Volt\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Models\Comparaison;
use App\Models\DetailProduct;

new class extends Component {
    public $page = 1;
    public $perPage = 20;
    public $hasMore = true;
    public $loading = false;
    public $loadingMore = false;
    
    // Filtres
    public $search = '';
    public $filterName = '';
    public $filterMarque = '';
    public $filterType = '';
    public $filterEAN = '';
    
    // Sélection des produits
    public $selectedProducts = [];
    public $selectAll = false;
    public $selectedProductsDetails = [];
    
    // Produits sélectionnés temporairement (pour la sélection multiple)
    public $tempSelectedProducts = [];
    
    // Modal
    public $showModal = false;
    public $listName = '';
    
    // Cache
    protected $cacheTTL = 3600;
    
    // Table de résumé des produits sélectionnés
    public $showSummaryTable = true;
    
    // Chargement pour sélection/désélection
    public $loadingProduct = null;
    public $removingProduct = null;
    public $loadingAction = false;
    public $addingMultiple = false;
    
    public function mount($listId = null)
    {
        $this->loading = true;
        // Charger les produits déjà sélectionnés si on édite une liste existante
        if ($listId) {
            $this->loadSelectedProducts($listId);
        }
    }
    
    // Charger les produits déjà dans la liste
    protected function loadSelectedProducts($listId)
    {
        $existingProducts = DetailProduct::where('list_product_id', $listId)
            ->pluck('EAN')
            ->toArray();
        
        $this->selectedProducts = $existingProducts;
        $this->loadSelectedProductsDetails();
    }
    
    // Charger les détails des produits sélectionnés
    protected function loadSelectedProductsDetails()
    {
        if (empty($this->selectedProducts)) {
            $this->selectedProductsDetails = [];
            return;
        }
        
        // Prendre seulement les premiers 100 produits pour éviter les problèmes de performance
        $skusToLoad = array_slice($this->selectedProducts, 0, 100);
        $placeholders = implode(',', array_fill(0, count($skusToLoad), '?'));
        
        $query = "
            SELECT 
                produit.sku as sku,
                CAST(product_char.name AS CHAR CHARACTER SET utf8mb4) as title,
                product_char.thumbnail as thumbnail
            FROM catalog_product_entity as produit
            LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
            WHERE produit.sku IN ($placeholders)
            ORDER BY FIELD(produit.sku, " . implode(',', $skusToLoad) . ")
            LIMIT 100
        ";
        
        try {
            $products = DB::connection('mysqlMagento')->select($query, $skusToLoad);
            $this->selectedProductsDetails = array_map(fn($p) => (array) $p, $products);
        } catch (\Exception $e) {
            Log::error('Erreur chargement détails produits: ' . $e->getMessage());
            $this->selectedProductsDetails = [];
        }
    }
    
    public function loadMore()
    {
        if (!$this->hasMore || $this->loading || $this->loadingMore) {
            return;
        }
        
        Log::info('loadMore: Chargement page ' . ($this->page + 1));
        $this->loadingMore = true;
        $this->page++;
    }
    
    // Gestion de la sélection temporaire (pour sélection multiple)
    public function toggleTempSelect($sku)
    {
        if (in_array($sku, $this->tempSelectedProducts)) {
            // Retirer de la sélection temporaire
            $this->tempSelectedProducts = array_diff($this->tempSelectedProducts, [$sku]);
        } else {
            // Ajouter à la sélection temporaire
            $this->tempSelectedProducts[] = $sku;
        }
    }
    
    // Ajouter tous les produits sélectionnés temporairement
    public function addAllSelectedProducts()
    {
        if (empty($this->tempSelectedProducts)) {
            $this->dispatch('notify', [
                'type' => 'warning',
                'message' => 'Veuillez sélectionner au moins un produit.'
            ]);
            return;
        }
        
        $this->addingMultiple = true;
        $this->loadingAction = true;
        
        // Récupérer les détails des produits sélectionnés
        $productsData = $this->getProductsDetails($this->tempSelectedProducts);
        
        $addedCount = 0;
        foreach ($productsData as $product) {
            $sku = $product['sku'] ?? null;
            if ($sku && !in_array($sku, $this->selectedProducts)) {
                $this->selectedProducts[] = $sku;
                $addedCount++;
                
                // Ajouter aux détails
                $existing = array_filter($this->selectedProductsDetails, 
                    fn($p) => ($p['sku'] ?? '') === $sku
                );
                
                if (empty($existing)) {
                    $this->selectedProductsDetails[] = [
                        'sku' => $sku,
                        'title' => $product['title'] ?? '',
                        'thumbnail' => $product['thumbnail'] ?? ''
                    ];
                }
            }
        }
        
        // Vider la sélection temporaire
        $this->tempSelectedProducts = [];
        
        $this->addingMultiple = false;
        $this->dispatch('selection-updated');
        
        if ($addedCount > 0) {
            $this->dispatch('notify', [
                'type' => 'success',
                'message' => $addedCount . ' produit(s) ajouté(s) à la liste.'
            ]);
        } else {
            $this->dispatch('notify', [
                'type' => 'info',
                'message' => 'Tous les produits sélectionnés étaient déjà dans la liste.'
            ]);
        }
    }
    
    // Récupérer les détails des produits
    protected function getProductsDetails($skus)
    {
        if (empty($skus)) {
            return [];
        }
        
        $placeholders = implode(',', array_fill(0, count($skus), '?'));
        
        $query = "
            SELECT 
                produit.sku as sku,
                CAST(product_char.name AS CHAR CHARACTER SET utf8mb4) as title,
                product_char.thumbnail as thumbnail
            FROM catalog_product_entity as produit
            LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
            WHERE produit.sku IN ($placeholders)
            LIMIT 100
        ";
        
        try {
            $products = DB::connection('mysqlMagento')->select($query, $skus);
            return array_map(fn($p) => (array) $p, $products);
        } catch (\Exception $e) {
            Log::error('Erreur récupération détails produits: ' . $e->getMessage());
            return [];
        }
    }
    
    // Gestion de la sélection individuelle définitive
    public function toggleSelect($sku, $title = '', $thumbnail = '')
    {
        if (in_array($sku, $this->selectedProducts)) {
            // Désélection - suppression du produit
            $this->loadingAction = true;
            $this->removingProduct = $sku;
            
            $this->selectedProducts = array_diff($this->selectedProducts, [$sku]);
            $this->selectedProductsDetails = array_filter($this->selectedProductsDetails, 
                fn($product) => $product['sku'] !== $sku
            );
            
            // Réinitialiser les indicateurs
            $this->dispatch('selection-updated');
        } else {
            // Sélection - ajout du produit
            $this->loadingAction = true;
            $this->loadingProduct = $sku;
            
            $this->selectedProducts[] = $sku;
            
            // Ajouter aux détails seulement si pas déjà présent
            $existing = array_filter($this->selectedProductsDetails, 
                fn($p) => $p['sku'] === $sku
            );
            
            if (empty($existing)) {
                $this->selectedProductsDetails[] = [
                    'sku' => $sku,
                    'title' => $title,
                    'thumbnail' => $thumbnail
                ];
            }
            
            // Réinitialiser les indicateurs
            $this->dispatch('selection-updated');
        }
    }
    
    // Vider la sélection temporaire
    public function clearTempSelection()
    {
        $this->tempSelectedProducts = [];
        $this->dispatch('notify', [
            'type' => 'info',
            'message' => 'Sélection temporaire vidée.'
        ]);
    }
    
    #[On('selection-updated')]
    public function resetLoading()
    {
        $this->loadingAction = false;
        $this->loadingProduct = null;
        $this->removingProduct = null;
    }
    
    // Toggle pour afficher/masquer le résumé
    public function toggleSummaryTable()
    {
        $this->showSummaryTable = !$this->showSummaryTable;
    }
    
    // Supprimer un produit du résumé
    public function removeFromSummary($sku)
    {
        // Appeler la même méthode que pour le toggle du checkbox
        $this->toggleSelect($sku);
    }
    
    // Réinitialiser les produits
    protected function resetProducts()
    {
        $this->page = 1;
        $this->hasMore = true;
        $this->loadingMore = false;
        $this->selectAll = false;
    }
    
    // Mise à jour des filtres
    public function updatedSearch()
    {
        $this->resetProducts();
        $this->loading = true;
    }
    
    public function updatedFilterName()
    {
        $this->resetProducts();
        $this->loading = true;
    }
    
    public function updatedFilterMarque()
    {
        $this->resetProducts();
        $this->loading = true;
    }
    
    public function updatedFilterType()
    {
        $this->resetProducts();
        $this->loading = true;
    }
    
    public function updatedFilterEAN()
    {
        $this->resetProducts();
        $this->loading = true;
    }
    
    // Ouvrir le modal de confirmation
    public function openModal()
    {
        if (empty($this->selectedProducts)) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Veuillez sélectionner au moins un produit.'
            ]);
            return;
        }
        
        $this->listName = 'Liste du ' . date('d/m/Y H:i');
        $this->showModal = true;
    }
    
    // Sauvegarder la liste
    public function saveList()
    {
        try {
            // Validation
            if (empty($this->selectedProducts)) {
                $this->dispatch('notify', [
                    'type' => 'error',
                    'message' => 'Veuillez sélectionner au moins un produit.'
                ]);
                return;
            }
            
            if (empty($this->listName)) {
                $this->dispatch('notify', [
                    'type' => 'error',
                    'message' => 'Veuillez donner un nom à votre liste.'
                ]);
                return;
            }
            
            // Créer la liste principale
            $list = Comparaison::create([
                'libelle' => $this->listName,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            // Sauvegarder les produits sélectionnés
            $batchData = [];
            foreach ($this->selectedProducts as $ean) {
                $batchData[] = [
                    'list_product_id' => $list->id,
                    'EAN' => $ean,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            
            // Insertion par lots pour plus de performance
            DetailProduct::insert($batchData);
            
            // Fermer le modal
            $this->showModal = false;
            
            // Réinitialiser la sélection
            $this->selectedProducts = [];
            $this->selectedProductsDetails = [];
            $this->tempSelectedProducts = [];
            $this->listName = '';
            
            $this->dispatch('notify', [
                'type' => 'success',
                'message' => 'Liste sauvegardée avec ' . count($batchData) . ' produit(s).'
            ]);
            
            // Émettre un événement pour le parent
            $this->dispatch('list-created', ['listId' => $list->id]);
            
        } catch (\Exception $e) {
            Log::error('Erreur sauvegarde liste: ' . $e->getMessage());
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Erreur lors de la sauvegarde: ' . $e->getMessage()
            ]);
        }
    }
    
    public function cancel()
    {
        return redirect()->to('/previous-page');
    }
    
    public function with(): array
    {
        try {
            $allProducts = [];
            $totalItems = 0;
            
            for ($i = 1; $i <= $this->page; $i++) {
                $result = $this->fetchProductsFromDatabase($this->search, $i, $this->perPage);
                
                if (isset($result['error'])) {
                    Log::error('Erreur DB: ' . $result['error']);
                    break;
                }
                
                $totalItems = $result['total_item'] ?? 0;
                $newProducts = $result['data'] ?? [];
                $newProducts = array_map(fn($p) => (array) $p, $newProducts);
                
                $allProducts = array_merge($allProducts, $newProducts);
                
                if (count($newProducts) < $this->perPage) {
                    $this->hasMore = false;
                    break;
                }
            }
            
            if (count($allProducts) >= $totalItems) {
                $this->hasMore = false;
            }
            
            $this->loading = false;
            $this->loadingMore = false;
            
            return [
                'products' => $allProducts,
                'totalItems' => $totalItems,
            ];
            
        } catch (\Exception $e) {
            Log::error('Erreur with(): ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            $this->loading = false;
            $this->loadingMore = false;
            $this->hasMore = false;
            
            return [
                'products' => [],
                'totalItems' => 0,
            ];
        }
    }
    
    /**
     * Récupère les produits depuis la base de données
     */
    protected function fetchProductsFromDatabase($search = "", $page = 1, $perPage = null)
    {
        try {
            $offset = ($page - 1) * $perPage;

            $subQuery = "";
            $params = [];

            // Global search
            if (!empty($search)) {
                $searchClean = str_replace("'", "", $search);
                $words = explode(" ", $searchClean);

                $subQuery = " AND ( ";
                $and = "";

                foreach ($words as $word) {
                    $subQuery .= " $and CONCAT(product_char.name, ' ', COALESCE(options.attribute_value, '')) LIKE ? ";
                    $params[] = "%$word%";
                    $and = "AND";
                }

                $subQuery .= " OR produit.sku LIKE ? ) ";
                $params[] = "%$searchClean%";
            }

            // Filtres avancés
            if (!empty($this->filterName)) {
                $subQuery .= " AND product_char.name LIKE ? ";
                $params[] = "%{$this->filterName}%";
            }

            if (!empty($this->filterMarque)) {
                $subQuery .= " AND SUBSTRING_INDEX(product_char.name, ' - ', 1) LIKE ? ";
                $params[] = "%{$this->filterMarque}%";
            }

            if (!empty($this->filterType)) {
                $subQuery .= " AND SUBSTRING_INDEX(eas.attribute_set_name, '_', -1) LIKE ? ";
                $params[] = "%{$this->filterType}%";
            }

            if (!empty($this->filterEAN)) {
                $subQuery .= " AND produit.sku LIKE ? ";
                $params[] = "%{$this->filterEAN}%";
            }

            // Filtre pour prix > 0
            $subQuery .= " AND product_decimal.price > 0 ";

            // Total count (mis en cache séparément)
            $total = $this->getProductCount($subQuery, $params);
            $nbPage = ceil($total / $perPage);

            if ($page > $nbPage && $nbPage > 0) {
                $page = 1;
                $offset = 0;
            }

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
                WHERE product_int.status >= 0 $subQuery
                ORDER BY product_char.entity_id DESC
                LIMIT ? OFFSET ?
            ";

            $params[] = $perPage;
            $params[] = $offset;

            $result = DB::connection('mysqlMagento')->select($dataQuery, $params);

            return [
                "total_item" => $total,
                "per_page" => $perPage,
                "total_page" => $nbPage,
                "current_page" => $page,
                "data" => $result,
                "cached_at" => now()->toDateTimeString(),
                "cache_key" => $this->getCacheKey('products', $page, $perPage)
            ];

        } catch (\Throwable $e) {
            Log::error('Error fetching products: ' . $e->getMessage());
            
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
    
    protected function getProductCount($subQuery, $params)
    {
        $countCacheKey = $this->getCacheKey('count', md5($subQuery . serialize($params)));
        
        return Cache::remember($countCacheKey, $this->cacheTTL, function () use ($subQuery, $params) {
            $resultTotal = DB::connection('mysqlMagento')->selectOne("
                SELECT COUNT(*) as nb
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
                WHERE product_int.status >= 0 $subQuery
            ", $params);

            return $resultTotal->nb ?? 0;
        });
    }
    
    protected function getCacheKey($type, ...$params)
    {
        return "products_{$type}_" . md5(serialize($params));
    }
}; ?>


<div class="mx-auto w-full">
    <!-- Loading indicator Livewire -->
    <div wire:loading.class.remove="hidden" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-base-100/80 backdrop-blur-sm">
        <div class="flex flex-col items-center justify-center bg-base-100 rounded-2xl p-8 shadow-2xl border border-base-300 min-w-[200px]">
            @if($loadingProduct)
                <div class="loading loading-spinner loading-lg text-success mb-4"></div>
                <p class="text-lg font-semibold text-base-content">Ajout du produit</p>
                <p class="text-sm text-base-content/70 mt-1">Veuillez patienter...</p>
            @elseif($removingProduct)
                <div class="loading loading-spinner loading-lg text-error mb-4"></div>
                <p class="text-lg font-semibold text-base-content">Suppression du produit</p>
                <p class="text-sm text-base-content/70 mt-1">Veuillez patienter...</p>
            @elseif($addingMultiple)
                <div class="loading loading-spinner loading-lg text-primary mb-4"></div>
                <p class="text-lg font-semibold text-base-content">Ajout multiple</p>
                <p class="text-sm text-base-content/70 mt-1">Ajout de {{ count($tempSelectedProducts) }} produits...</p>
            @else
                <div class="loading loading-spinner loading-lg text-primary mb-4"></div>
                <p class="text-lg font-semibold text-base-content">Chargement</p>
                <p class="text-sm text-base-content/70 mt-1">Veuillez patienter...</p>
            @endif
        </div>
    </div>

    <x-header title="Créer la liste à comparer" separator progress-indicator>
        <x-slot:middle class="!justify-end">
            <div class="flex items-center gap-4">
                @if($loading || $loadingMore)
                    <span class="loading loading-spinner loading-sm text-primary"></span>
                    <span class="text-sm text-base-content/70">Chargement des produits...</span>
                @elseif($loadingAction && $loadingProduct)
                    <span class="loading loading-spinner loading-sm text-success"></span>
                    <span class="text-sm text-base-content/70">Ajout du produit...</span>
                @elseif($loadingAction && $removingProduct)
                    <span class="loading loading-spinner loading-sm text-error"></span>
                    <span class="text-sm text-base-content/70">Suppression du produit...</span>
                @elseif($addingMultiple)
                    <span class="loading loading-spinner loading-sm text-primary"></span>
                    <span class="text-sm text-base-content/70">
                        Ajout de {{ count($tempSelectedProducts) }} produits...
                    </span>
                @endif
                
                <div class="text-sm text-base-content/70">
                    <span class="font-semibold text-primary">{{ count($selectedProducts) }}</span> produits dans la liste
                </div>
                <div class="text-sm text-base-content/70">
                    {{ count($products) }} / {{ $totalItems }} produits affichés
                </div>
            </div>
        </x-slot:middle>
        <x-slot:actions>
            <x-button class="btn-error" label="Annuler" wire:click="cancel"
                wire:confirm="Êtes-vous sûr de vouloir annuler ?" />
            <x-button class="btn-primary" label="Valider" wire:click="openModal" />
        </x-slot:actions>
    </x-header>

    <!-- Filtres -->
    <div class="mb-4 grid grid-cols-1 md:grid-cols-3 gap-4">
        <x-input 
            label="Recherche globale" 
            wire:model.live.debounce.500ms="search" 
            placeholder="Nom, SKU..."
            icon="o-magnifying-glass"
        />
        
        <x-input 
            label="Nom du produit" 
            wire:model.live.debounce.500ms="filterName" 
            placeholder="Filtrer par nom"
        />
        
        <x-input 
            label="Marque" 
            wire:model.live.debounce.500ms="filterMarque" 
            placeholder="Filtrer par marque"
        />
        
        <x-input 
            label="Type" 
            wire:model.live.debounce.500ms="filterType" 
            placeholder="Filtrer par type"
        />
        
        <x-input 
            label="EAN/SKU" 
            wire:model.live.debounce.500ms="filterEAN" 
            placeholder="Filtrer par EAN"
        />
    </div>

    <!-- Barre d'action pour la sélection multiple -->
    @if(count($tempSelectedProducts) > 0)
        <div class="mb-4 p-4 bg-primary/10 border border-primary/20 rounded-box">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-2">
                        <span class="loading loading-spinner loading-xs text-primary"></span>
                        <span class="font-semibold text-primary">
                            {{ count($tempSelectedProducts) }} produit(s) sélectionné(s)
                        </span>
                    </div>
                    <div class="text-sm text-base-content/70">
                        Cliquez sur "Ajouter la sélection" pour les ajouter à votre liste
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button 
                        wire:click="clearTempSelection"
                        class="btn btn-sm btn-ghost"
                        wire:loading.attr="disabled"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                        </svg>
                        Annuler
                    </button>
                    <button 
                        wire:click="addAllSelectedProducts"
                        class="btn btn-sm btn-primary"
                        wire:loading.attr="disabled"
                        wire:target="addAllSelectedProducts"
                    >
                        @if($addingMultiple)
                            <span class="loading loading-spinner loading-xs"></span>
                        @else
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd" />
                            </svg>
                        @endif
                        Ajouter la sélection
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Section de résumé des produits sélectionnés -->
    @if(count($selectedProducts) > 0)
        <div class="mb-6 border rounded-box border-base-content/10 bg-base-100 overflow-hidden">
            <div class="flex items-center justify-between p-4 bg-base-200 border-b border-base-content/5">
                <div class="flex items-center gap-3">
                    <h3 class="font-semibold text-lg flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 2a4 4 0 00-4 4v1H5a1 1 0 00-.994.89l-1 9A1 1 0 004 18h12a1 1 0 00.994-1.11l-1-9A1 1 0 0015 7h-1V6a4 4 0 00-4-4zm2 5V6a2 2 0 10-4 0v1h4zm-6 3a1 1 0 112 0 1 1 0 01-2 0zm7-1a1 1 0 100 2 1 1 0 000-2z" clip-rule="evenodd" />
                        </svg>
                        Votre liste de produits
                        <span class="badge badge-primary">{{ count($selectedProducts) }}</span>
                    </h3>
                </div>
                <button 
                    wire:click="toggleSummaryTable"
                    class="btn btn-sm btn-ghost"
                    type="button"
                >
                    @if($showSummaryTable)
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                        </svg>
                        Masquer
                    @else
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M14.707 12.707a1 1 0 01-1.414 0L10 9.414l-3.293 3.293a1 1 0 01-1.414-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 010 1.414z" clip-rule="evenodd" />
                        </svg>
                        Afficher
                    @endif
                </button>
            </div>
            
            @if($showSummaryTable)
                <div class="p-4">
                    <!-- Le reste du résumé reste inchangé -->
                    <!-- ... [votre code existant pour afficher les produits sélectionnés] ... -->
                </div>
            @endif
        </div>
    @endif

    <div class="rounded-box border border-base-content/5 bg-base-100 overflow-hidden">
        <!-- En-tête de la table avec instructions -->
        <div class="p-4 border-b border-base-content/5 bg-base-200">
            <div class="flex items-center justify-between">
                <div>
                    <h4 class="font-semibold text-base-content">Sélectionnez les produits à ajouter</h4>
                    <p class="text-sm text-base-content/70 mt-1">
                        Cochez les produits que vous souhaitez ajouter, puis cliquez sur "Ajouter la sélection"
                    </p>
                </div>
                <div class="text-sm">
                    <span class="font-medium">{{ count($products) }}</span> produits affichés
                </div>
            </div>
        </div>
        
        <!-- Conteneur principal -->
        <div class="max-h-[600px] overflow-y-auto">
            <!-- Table -->
            <table class="table table-sm w-full">
                <thead class="sticky top-0 bg-base-200 z-10">
                    <tr>
                        <th class="w-12">
                            <span class="sr-only">Sélectionner</span>
                        </th>
                        <th>Image</th>
                        <th>SKU</th>
                        <th>Nom</th>
                        <th>Marque</th>
                        <th>Type</th>
                        <th>Prix</th>
                        <th>Stock</th>
                        <th>Statut</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($products as $index => $product)
                        @php
                            $isSelected = in_array($product['sku'], $selectedProducts);
                            $isTempSelected = in_array($product['sku'], $tempSelectedProducts);
                        @endphp
                        <tr wire:key="product-{{ $product['id'] ?? $index }}"
                            class="{{ $isSelected ? 'bg-success/5' : ($isTempSelected ? 'bg-primary/5' : '') }}">
                            <!-- Checkbox de sélection temporaire -->
                            <td>
                                <div class="relative">
                                    @if($loadingProduct === $product['sku'])
                                        <div class="absolute inset-0 flex items-center justify-center z-10">
                                            <span class="loading loading-spinner loading-xs text-success"></span>
                                        </div>
                                        <input 
                                            type="checkbox" 
                                            class="checkbox checkbox-primary checkbox-xs opacity-30" 
                                            checked
                                            disabled
                                        >
                                    @elseif($removingProduct === $product['sku'])
                                        <div class="absolute inset-0 flex items-center justify-center z-10">
                                            <span class="loading loading-spinner loading-xs text-error"></span>
                                        </div>
                                        <input 
                                            type="checkbox" 
                                            class="checkbox checkbox-primary checkbox-xs opacity-30" 
                                            checked
                                            disabled
                                        >
                                    @else
                                        <input 
                                            type="checkbox" 
                                            class="checkbox checkbox-primary checkbox-xs" 
                                            {{ $isTempSelected ? 'checked' : '' }}
                                            wire:click="toggleTempSelect('{{ $product['sku'] }}')"
                                            {{ $loadingAction || $addingMultiple ? 'disabled' : '' }}
                                            data-sku="{{ $product['sku'] }}"
                                        >
                                    @endif
                                </div>
                            </td>
                            
                            <!-- Colonnes de données -->
                            <td>
                                @if(!empty($product['thumbnail']))
                                    <div class="avatar">
                                        <div class="w-10 h-10 rounded">
                                            <img 
                                                src="https://www.cosma-parfumeries.com/media/catalog/product/{{ $product['thumbnail'] }}"
                                                alt="{{ $product['title'] ?? '' }}"
                                            >
                                        </div>
                                    </div>
                                @else
                                    <div class="w-10 h-10 bg-base-300 rounded flex items-center justify-center">
                                        <span class="text-xs">N/A</span>
                                    </div>
                                @endif
                            </td>
                            <td class="font-mono text-xs">{{ $product['sku'] ?? '' }}</td>
                            <td>
                                <div class="max-w-xs truncate" title="{{ $product['title'] ?? '' }}">
                                    {{ $product['title'] ?? '' }}
                                </div>
                            </td>
                            <td>{{ $product['vendor'] ?? '' }}</td>
                            <td>
                                <span class="badge badge-sm">{{ $product['type'] ?? '' }}</span>
                            </td>
                            <td>
                                @if(!empty($product['special_price']))
                                    <div class="flex flex-col">
                                        <span class="line-through text-xs text-base-content/50">
                                            {{ number_format($product['price'] ?? 0, 2) }} €
                                        </span>
                                        <span class="text-error font-semibold">
                                            {{ number_format($product['special_price'], 2) }} €
                                        </span>
                                    </div>
                                @else
                                    <span class="font-semibold">
                                        {{ number_format($product['price'] ?? 0, 2) }} €
                                    </span>
                                @endif
                            </td>
                            <td>
                                <span class="{{ ($product['quatity'] ?? 0) > 0 ? 'text-success' : 'text-error' }}">
                                    {{ $product['quatity'] ?? 0 }}
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-sm {{ ($product['quatity_status'] ?? 0) == 1 ? 'badge-success' : 'badge-error' }}">
                                    {{ ($product['quatity_status'] ?? 0) == 1 ? 'En stock' : 'Rupture' }}
                                </span>
                            </td>
                            
                            <!-- Bouton d'action individuel -->
                            <td>
                                @if($isSelected)
                                    <button 
                                        wire:click="toggleSelect('{{ $product['sku'] }}', '{{ addslashes($product['title'] ?? '') }}', '{{ $product['thumbnail'] ?? '' }}')"
                                        class="btn btn-xs btn-error"
                                        wire:loading.attr="disabled"
                                        wire:target="toggleSelect"
                                        title="Retirer de la liste"
                                    >
                                        @if($removingProduct === $product['sku'])
                                            <span class="loading loading-spinner loading-xs"></span>
                                        @else
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
                                            </svg>
                                        @endif
                                    </button>
                                @else
                                    <button 
                                        wire:click="toggleSelect('{{ $product['sku'] }}', '{{ addslashes($product['title'] ?? '') }}', '{{ $product['thumbnail'] ?? '' }}')"
                                        class="btn btn-xs btn-success"
                                        wire:loading.attr="disabled"
                                        wire:target="toggleSelect"
                                        title="Ajouter à la liste"
                                    >
                                        @if($loadingProduct === $product['sku'])
                                            <span class="loading loading-spinner loading-xs"></span>
                                        @else
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd" />
                                            </svg>
                                        @endif
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="text-center py-12 text-base-content/50">
                                @if($loading)
                                    <div class="flex flex-col items-center gap-3">
                                        <span class="loading loading-spinner loading-lg text-primary"></span>
                                        <span class="text-lg">Chargement des produits...</span>
                                    </div>
                                @else
                                    <div class="flex flex-col items-center gap-3">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 text-base-content/20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                        </svg>
                                        <span class="text-lg">Aucun produit trouvé</span>
                                        <span class="text-sm">Essayez de modifier vos filtres</span>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                    
                    <!-- Ligne de chargement pendant le chargement manuel -->
                    @if($loadingMore)
                        <tr>
                            <td colspan="10" class="text-center py-8 bg-base-100/80">
                                <div class="flex flex-col items-center gap-3">
                                    <span class="loading loading-spinner loading-md text-primary"></span>
                                    <span class="text-base-content/70 font-medium">
                                        Chargement de {{ $perPage }} produits supplémentaires...
                                    </span>
                                </div>
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
        
        <!-- Section avec le bouton "Afficher plus" -->
        <div class="border-t border-base-content/5 bg-base-100">
            @if($hasMore)
                <div class="flex flex-col items-center justify-center py-6 gap-4">
                    <!-- Bouton Afficher plus -->
                    <button 
                        wire:click="loadMore"
                        wire:loading.attr="disabled"
                        class="btn btn-primary btn-wide"
                        :disabled="$wire.loadingMore"
                    >
                        <span wire:loading.remove wire:target="loadMore">
                            Afficher {{ $perPage }} produits supplémentaires
                        </span>
                        <span wire:loading wire:target="loadMore" class="flex items-center gap-2">
                            <span class="loading loading-spinner loading-sm"></span>
                            Chargement...
                        </span>
                    </button>
                    
                    <!-- Information -->
                    <div class="text-sm text-base-content/60">
                        <span class="font-medium">{{ count($products) }}</span> produits affichés sur 
                        <span class="font-medium">{{ $totalItems }}</span> au total
                    </div>
                </div>
            @elseif(count($products) > 0)
                <!-- Message de fin -->
                <div class="text-center py-6 text-base-content/70 bg-base-100">
                    <div class="inline-flex items-center gap-2 bg-success/10 text-success px-6 py-3 rounded-full">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                        </svg>
                        <span class="font-medium">Tous les produits chargés ({{ $totalItems }} au total)</span>
                    </div>
                </div>
            @endif
        </div>
    </div>

    <!-- Modal de confirmation -->
    <x-modal wire:model="showModal" title="Confirmation de la liste" persistent separator>
        <div class="space-y-4">
            <!-- Nom de la liste -->
            <div>
                <x-input 
                    label="Nom de la liste" 
                    wire:model="listName" 
                    placeholder="Entrez un nom pour votre liste"
                    required
                    hint="Donnez un nom significatif à votre liste"
                />
            </div>
        
            <!-- Résumé de la sélection -->
            <div class="border rounded-box p-4">
                <h4 class="font-semibold mb-2">Résumé de la sélection</h4>
                <div class="space-y-2">
                    <div class="flex justify-between">
                        <span>Nombre de produits:</span>
                        <span class="font-semibold">{{ count($selectedProducts) }}</span>
                    </div>
                    <div class="flex items-center gap-2 text-sm text-base-content/70">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 2a4 4 0 00-4 4v1H5a1 1 0 00-.994.89l-1 9A1 1 0 004 18h12a1 1 0 00.994-1.11l-1-9A1 1 0 0015 7h-1V6a4 4 0 00-4-4zm2 5V6a2 2 0 10-4 0v1h4zm-6 3a1 1 0 112 0 1 1 0 01-2 0zm7-1a1 1 0 100 2 1 1 0 000-2z" clip-rule="evenodd" />
                        </svg>
                        <span>Les produits sont visibles dans le panneau de résumé</span>
                    </div>
                </div>
            </div>
            
            <!-- Avertissement -->
            <div class="alert alert-info">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="stroke-current shrink-0 w-6 h-6">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span>La liste sera sauvegardée dans la base de données et vous pourrez y accéder ultérieurement.</span>
            </div>
        </div>
        
        <x-slot:actions>
            <x-button label="Annuler" @click="$wire.showModal = false" />
            <x-button label="Sauvegarder" class="btn-primary" wire:click="saveList" wire:loading.attr="disabled">
                <span wire:loading.remove>Sauvegarder</span>
                <span wire:loading wire:target="saveList" class="flex items-center gap-2">
                    <span class="loading loading-spinner loading-sm"></span>
                    Sauvegarde...
                </span>
            </x-button>
        </x-slot:actions>
    </x-modal>
</div>

@push('scripts')
<script>
// Fonction pour faire défiler le résumé
function scrollSummary(direction) {
    const container = document.getElementById('summaryScrollContainer');
    if (!container) return;
    
    const scrollAmount = 300;
    
    if (direction === 'left') {
        container.scrollLeft -= scrollAmount;
    } else {
        container.scrollLeft += scrollAmount;
    }
}

// Initialisation
document.addEventListener('livewire:initialized', () => {
    Livewire.on('notify', (event) => {
        const toast = document.createElement('div');
        toast.className = `toast toast-top toast-end`;
        toast.innerHTML = `
            <div class="alert ${event.type === 'success' ? 'alert-success' : 'alert-error'}">
                <span>${event.message}</span>
            </div>
        `;
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.remove();
        }, 3000);
    });
    
    Livewire.on('list-created', (event) => {
        console.log('Liste créée avec ID:', event.listId);
    });
});
</script>

<style>
    /* Style personnalisé pour la scrollbar */
    .scrollbar-thin::-webkit-scrollbar {
        height: 8px;
    }
    
    .scrollbar-thin::-webkit-scrollbar-track {
        background: #f3f4f6;
        border-radius: 4px;
    }
    
    .scrollbar-thin::-webkit-scrollbar-thumb {
        background: #d1d5db;
        border-radius: 4px;
    }
    
    .scrollbar-thin::-webkit-scrollbar-thumb:hover {
        background: #9ca3af;
    }
</style>
@endpush