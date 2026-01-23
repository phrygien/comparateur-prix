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
    
    // Modal
    public $showModal = false;
    public $listName = '';
    
    // Cache
    protected $cacheTTL = 3600;
    
    // Table de résumé des produits sélectionnés
    public $showSummaryTable = true;
    
    // ID de la liste à mettre à jour
    public $listId = null;
    public $list = null;
    
    // Produits déjà dans la liste
    public $existingProducts = [];
    public $existingProductsDetails = [];
    
    public function mount($listId = null)
    {
        $this->loading = true;
        // Uniquement pour la mise à jour de liste
        if ($listId) {
            $this->listId = $listId;
            $this->loadList();
            $this->loadExistingProductsFromDatabase();
        } else {
            // Redirection si pas d'ID de liste
            return redirect()->route('comparison.lists');
        }
    }
    
    // Charger les informations de la liste
    protected function loadList()
    {
        $this->list = Comparaison::find($this->listId);
        if ($this->list) {
            $this->listName = $this->list->libelle;
        } else {
            // Redirection si liste non trouvée
            return redirect()->route('comparison.lists');
        }
    }
    
    // Charger les produits déjà dans la liste (depuis la base de données)
    protected function loadExistingProductsFromDatabase()
    {
        // Récupérer les SKU des produits existants
        $this->existingProducts = DetailProduct::where('list_product_id', $this->listId)
            ->pluck('EAN')
            ->toArray();
        
        // Charger les détails de ces produits
        $this->loadExistingProductsDetails();
        
        // Initialiser les produits sélectionnés avec les produits existants
        $this->selectedProducts = $this->existingProducts;
        
        // Fusionner les détails dans selectedProductsDetails
        $this->selectedProductsDetails = $this->existingProductsDetails;
    }
    
    // Charger les détails des produits existants
    protected function loadExistingProductsDetails()
    {
        if (empty($this->existingProducts)) {
            $this->existingProductsDetails = [];
            return;
        }
        
        $skus = array_values($this->existingProducts);
        $placeholders = implode(',', array_fill(0, count($skus), '?'));
        
        $query = "
            SELECT 
                produit.sku as sku,
                CAST(product_char.name AS CHAR CHARACTER SET utf8mb4) as title,
                product_char.thumbnail as thumbnail
            FROM catalog_product_entity as produit
            LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
            WHERE produit.sku IN ($placeholders)
            ORDER BY FIELD(produit.sku, " . implode(',', $skus) . ")
            LIMIT 100
        ";
        
        try {
            $products = DB::connection('mysqlMagento')->select($query, $skus);
            $this->existingProductsDetails = array_map(fn($p) => (array) $p, $products);
        } catch (\Exception $e) {
            Log::error('Erreur chargement détails produits existants: ' . $e->getMessage());
            $this->existingProductsDetails = [];
        }
    }
    
    // Charger les détails des produits sélectionnés
    protected function loadSelectedProductsDetails()
    {
        if (empty($this->selectedProducts)) {
            $this->selectedProductsDetails = [];
            return;
        }
        
        // Filtrer les produits dont on n'a pas encore les détails
        $existingSkus = array_column($this->selectedProductsDetails, 'sku');
        $skusToLoad = array_diff($this->selectedProducts, $existingSkus);
        
        if (empty($skusToLoad)) {
            return;
        }
        
        $skus = array_values($skusToLoad);
        $placeholders = implode(',', array_fill(0, count($skus), '?'));
        
        $query = "
            SELECT 
                produit.sku as sku,
                CAST(product_char.name AS CHAR CHARACTER SET utf8mb4) as title,
                product_char.thumbnail as thumbnail
            FROM catalog_product_entity as produit
            LEFT JOIN product_char ON product_char.entity_id = produit.entity_id
            WHERE produit.sku IN ($placeholders)
            ORDER BY FIELD(produit.sku, " . implode(',', $skus) . ")
            LIMIT 100
        ";
        
        try {
            $products = DB::connection('mysqlMagento')->select($query, $skus);
            $newProducts = array_map(fn($p) => (array) $p, $products);
            
            // Fusionner avec les détails existants
            $this->selectedProductsDetails = array_merge($this->selectedProductsDetails, $newProducts);
            
            // Trier pour garder l'ordre des SKU
            usort($this->selectedProductsDetails, function($a, $b) {
                $indexA = array_search($a['sku'], $this->selectedProducts);
                $indexB = array_search($b['sku'], $this->selectedProducts);
                return $indexA - $indexB;
            });
            
        } catch (\Exception $e) {
            Log::error('Erreur chargement détails produits: ' . $e->getMessage());
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
    
    // Gestion de la sélection - Empêche l'ajout de doublons
    public function toggleSelect($sku, $title = '', $thumbnail = '')
    {
        // Vérifier si le produit existe déjà dans la liste (base de données)
        $existsInDatabase = in_array($sku, $this->existingProducts);
        
        if ($existsInDatabase) {
            // Si déjà dans la liste, on le retire
            $this->removeFromDatabase($sku);
            return;
        }
        
        // Vérifier si le produit est déjà sélectionné (temporairement)
        if (in_array($sku, $this->selectedProducts)) {
            // Retirer de la sélection
            $this->selectedProducts = array_diff($this->selectedProducts, [$sku]);
            // Retirer du résumé
            $this->selectedProductsDetails = array_filter($this->selectedProductsDetails, 
                fn($product) => $product['sku'] !== $sku
            );
        } else {
            // Ajouter à la sélection
            $this->selectedProducts[] = $sku;
            
            // Charger les détails si pas déjà chargés
            $existsInDetails = array_filter($this->selectedProductsDetails, 
                fn($product) => $product['sku'] === $sku
            );
            
            if (empty($existsInDetails) && !empty($title)) {
                // Ajouter au résumé immédiatement
                $this->selectedProductsDetails[] = [
                    'sku' => $sku,
                    'title' => $title,
                    'thumbnail' => $thumbnail
                ];
            } else {
                // Sinon, charger les détails
                $this->loadSelectedProductsDetails();
            }
        }
    }
    
    public function updatedSelectAll($value)
    {
        // Cette logique sera gérée directement dans le template
    }
    
    // Toggle pour afficher/masquer le résumé
    public function toggleSummaryTable()
    {
        $this->showSummaryTable = !$this->showSummaryTable;
    }
    
    // Supprimer un produit du résumé
    public function removeFromSummary($sku)
    {
        // Vérifier si le produit est dans la base de données
        $existsInDatabase = in_array($sku, $this->existingProducts);
        
        if ($existsInDatabase) {
            // Demander confirmation pour supprimer un produit déjà dans la liste
            $this->dispatch('confirm-remove', [
                'sku' => $sku,
                'title' => 'Supprimer le produit de la liste ?',
                'message' => 'Ce produit est déjà dans la liste. Voulez-vous le supprimer définitivement ?',
                'method' => 'removeFromDatabase'
            ]);
            return;
        }
        
        // Sinon, simplement retirer de la sélection temporaire
        if (in_array($sku, $this->selectedProducts)) {
            $this->selectedProducts = array_diff($this->selectedProducts, [$sku]);
            $this->selectedProductsDetails = array_filter($this->selectedProductsDetails, 
                fn($product) => $product['sku'] !== $sku
            );
        }
    }
    
    // Supprimer un produit de la base de données
    public function removeFromDatabase($sku)
    {
        try {
            // Supprimer de la base de données
            $deleted = DetailProduct::where('list_product_id', $this->listId)
                ->where('EAN', $sku)
                ->delete();
            
            if ($deleted) {
                // Retirer des tableaux locaux
                if (in_array($sku, $this->existingProducts)) {
                    $this->existingProducts = array_diff($this->existingProducts, [$sku]);
                }
                
                if (in_array($sku, $this->selectedProducts)) {
                    $this->selectedProducts = array_diff($this->selectedProducts, [$sku]);
                }
                
                // Retirer du résumé
                $this->selectedProductsDetails = array_filter($this->selectedProductsDetails, 
                    fn($product) => $product['sku'] !== $sku
                );
                
                // Retirer des détails existants
                $this->existingProductsDetails = array_filter($this->existingProductsDetails, 
                    fn($product) => $product['sku'] !== $sku
                );
                
                $this->dispatch('notify', [
                    'type' => 'success',
                    'message' => 'Produit retiré de la liste avec succès.'
                ]);
                
                // Nettoyer le cache
                Cache::forget("list_skus_{$this->listId}");
            }
        } catch (\Exception $e) {
            Log::error('Erreur suppression produit: ' . $e->getMessage());
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Erreur lors de la suppression: ' . $e->getMessage()
            ]);
        }
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
                'type' => 'warning',
                'message' => 'La liste ne peut pas être vide. Ajoutez au moins un produit.'
            ]);
            return;
        }
        
        $this->showModal = true;
    }
    
    // Mettre à jour la liste existante
    public function updateList()
    {
        try {
            // Validation
            if (empty($this->selectedProducts)) {
                $this->dispatch('notify', [
                    'type' => 'error',
                    'message' => 'La liste ne peut pas être vide.'
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
            
            // Vérifier que la liste existe
            if (!$this->listId) {
                $this->dispatch('notify', [
                    'type' => 'error',
                    'message' => 'Liste non trouvée.'
                ]);
                return;
            }
            
            $list = Comparaison::find($this->listId);
            if (!$list) {
                $this->dispatch('notify', [
                    'type' => 'error',
                    'message' => 'Liste non trouvée.'
                ]);
                return;
            }
            
            // Mettre à jour le nom de la liste
            $list->update([
                'libelle' => $this->listName,
                'updated_at' => now(),
            ]);
            
            // Identifier les produits à conserver, ajouter et supprimer
            $productsToKeep = array_intersect($this->existingProducts, $this->selectedProducts);
            $productsToAdd = array_diff($this->selectedProducts, $this->existingProducts);
            $productsToRemove = array_diff($this->existingProducts, $this->selectedProducts);
            
            // Supprimer les produits retirés
            if (!empty($productsToRemove)) {
                DetailProduct::where('list_product_id', $this->listId)
                    ->whereIn('EAN', $productsToRemove)
                    ->delete();
            }
            
            // Ajouter les nouveaux produits
            if (!empty($productsToAdd)) {
                $batchData = [];
                foreach ($productsToAdd as $ean) {
                    $batchData[] = [
                        'list_product_id' => $this->listId,
                        'EAN' => $ean,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                
                // Insertion par lots
                DetailProduct::insert($batchData);
            }
            
            // Fermer le modal
            $this->showModal = false;
            
            // Mettre à jour les données locales
            $this->existingProducts = $this->selectedProducts;
            $this->existingProductsDetails = $this->selectedProductsDetails;
            
            // Message de succès
            $message = 'Liste mise à jour avec succès.';
            if (!empty($productsToAdd)) {
                $message .= ' ' . count($productsToAdd) . ' nouveau(x) produit(s) ajouté(s).';
            }
            if (!empty($productsToRemove)) {
                $message .= ' ' . count($productsToRemove) . ' produit(s) supprimé(s).';
            }
            
            $this->dispatch('notify', [
                'type' => 'success',
                'message' => $message
            ]);
            
            // Émettre un événement pour le parent
            $this->dispatch('list-updated', ['listId' => $this->listId]);
            
        } catch (\Exception $e) {
            Log::error('Erreur mise à jour liste: ' . $e->getMessage());
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Erreur lors de la mise à jour: ' . $e->getMessage()
            ]);
        }
    }
    
    // Vérifier si un produit est déjà dans la liste (base de données)
    public function isInDatabase($sku)
    {
        return in_array($sku, $this->existingProducts);
    }
    
    // Vérifier si un produit est temporairement sélectionné (pas encore en base)
    public function isTemporarilySelected($sku)
    {
        $existsInDatabase = in_array($sku, $this->existingProducts);
        return in_array($sku, $this->selectedProducts) && !$existsInDatabase;
    }
    
    // Obtenir les nouveaux produits sélectionnés
    public function getNewProducts()
    {
        return array_diff($this->selectedProducts, $this->existingProducts);
    }
    
    // Obtenir les produits supprimés
    public function getRemovedProducts()
    {
        return array_diff($this->existingProducts, $this->selectedProducts);
    }
    
    public function cancel()
    {
        return redirect()->route('comparison.lists');
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
    <x-header 
        title="Mettre à jour la liste : {{ $listName }}" 
        separator progress-indicator
    >
        <x-slot:middle class="!justify-end">
            <div class="flex items-center gap-4">
                @if($loading || $loadingMore)
                    <span class="loading loading-spinner loading-sm text-primary"></span>
                @endif
                <div class="text-sm text-base-content/70">
                    @php
                        $existingCount = count($existingProducts);
                        $newCount = count($this->getNewProducts());
                        $removedCount = count($this->getRemovedProducts());
                    @endphp
                    <span class="font-semibold text-primary">{{ count($selectedProducts) }}</span> 
                    produits 
                    @if($newCount > 0 || $removedCount > 0)
                        ({{ $existingCount - $removedCount }} existants 
                        @if($newCount > 0)
                            <span class="text-warning">+{{ $newCount }} nouveaux</span>
                        @endif
                        @if($removedCount > 0)
                            <span class="text-error">-{{ $removedCount }} supprimés</span>
                        @endif
                        )
                    @endif
                </div>
                <div class="text-sm text-base-content/70">
                    {{ count($products) }} / {{ $totalItems }} produits affichés
                </div>
            </div>
        </x-slot:middle>
        <x-slot:actions>
            <x-button class="btn-error" label="Annuler" wire:click="cancel"
                wire:confirm="Êtes-vous sûr de vouloir annuler ? Les modifications seront perdues." />
            <x-button class="btn-primary" label="Mettre à jour" wire:click="openModal" />
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

    <!-- Section de résumé des produits sélectionnés -->
    @if(count($selectedProducts) > 0)
        <div class="mb-6 border rounded-box border-base-content/10 bg-base-100 overflow-hidden">
            <div class="flex items-center justify-between p-4 bg-base-200 border-b border-base-content/5">
                <div class="flex items-center gap-3">
                    <h3 class="font-semibold text-lg flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 2a4 4 0 00-4 4v1H5a1 1 0 00-.994.89l-1 9A1 1 0 004 18h12a1 1 0 00.994-1.11l-1-9A1 1 0 0015 7h-1V6a4 4 0 00-4-4zm2 5V6a2 2 0 10-4 0v1h4zm-6 3a1 1 0 112 0 1 1 0 01-2 0zm7-1a1 1 0 100 2 1 1 0 000-2z" clip-rule="evenodd" />
                        </svg>
                        Produits dans la liste
                        <span class="badge badge-primary">{{ count($selectedProducts) }}</span>
                    </h3>
                    <div class="flex gap-2">
                        <div class="badge badge-sm badge-success">
                            {{ $existingCount - $removedCount }} existants
                        </div>
                        @if($newCount > 0)
                            <div class="badge badge-sm badge-warning">
                                +{{ $newCount }} nouveaux
                            </div>
                        @endif
                        @if($removedCount > 0)
                            <div class="badge badge-sm badge-error">
                                -{{ $removedCount }} supprimés
                            </div>
                        @endif
                    </div>
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
                    <!-- Conteneur avec scroll horizontal -->
                    <div class="relative">
                        <!-- Bouton gauche -->
                        <button 
                            type="button"
                            class="absolute left-0 top-1/2 transform -translate-y-1/2 z-10 bg-base-200 hover:bg-base-300 rounded-full p-2 shadow-lg"
                            onclick="scrollSummary('left')"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                            </svg>
                        </button>
                        
                        <!-- Bouton droit -->
                        <button 
                            type="button"
                            class="absolute right-0 top-1/2 transform -translate-y-1/2 z-10 bg-base-200 hover:bg-base-300 rounded-full p-2 shadow-lg"
                            onclick="scrollSummary('right')"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                            </svg>
                        </button>
                        
                        <!-- Conteneur avec scroll horizontal -->
                        <div 
                            id="summaryScrollContainer"
                            class="flex flex-col gap-4 overflow-x-auto pb-4 scrollbar-thin scrollbar-thumb-base-300 scrollbar-track-base-100"
                            style="max-height: 300px;"
                        >
                            <!-- Première ligne -->
                            <div class="flex gap-3 min-w-max">
                                @foreach($selectedProductsDetails as $index => $product)
                                    @if($index % 2 == 0)
                                        @php
                                            $isInDatabase = $this->isInDatabase($product['sku']);
                                            $isNew = $this->isTemporarilySelected($product['sku']);
                                            $isRemoved = in_array($product['sku'], $this->getRemovedProducts());
                                        @endphp
                                        <div class="relative group border rounded-lg p-3 
                                            {{ $isRemoved ? 'bg-error/10 border-error line-through' : 
                                              ($isNew ? 'bg-warning/10 border-warning' : 
                                              'bg-success/10 border-success') }} 
                                            transition-colors flex-shrink-0"
                                            style="width: 300px;">
                                            <!-- Badge d'état -->
                                            <div class="absolute top-2 right-2">
                                                @if($isRemoved)
                                                    <span class="badge badge-sm badge-error" title="À supprimer">
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                                            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                                                        </svg>
                                                    </span>
                                                @elseif($isNew)
                                                    <span class="badge badge-sm badge-warning" title="Nouveau - À ajouter">
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                                            <path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd" />
                                                        </svg>
                                                    </span>
                                                @else
                                                    <span class="badge badge-sm badge-success" title="Déjà dans la liste">
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                                        </svg>
                                                    </span>
                                                @endif
                                            </div>
                                            
                                            <div class="flex items-start gap-3">
                                                <!-- Image -->
                                                <div class="flex-shrink-0">
                                                    @if(!empty($product['thumbnail']))
                                                        <div class="avatar">
                                                            <div class="w-12 h-12 rounded border">
                                                                <img 
                                                                    src="https://www.cosma-parfumeries.com/media/catalog/product/{{ $product['thumbnail'] }}"
                                                                    alt="{{ $product['title'] ?? '' }}"
                                                                    class="object-cover"
                                                                >
                                                            </div>
                                                        </div>
                                                    @else
                                                        <div class="w-12 h-12 bg-base-300 rounded border flex items-center justify-center">
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-base-content/40" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                            </svg>
                                                        </div>
                                                    @endif
                                                </div>
                                                
                                                <!-- Info produit -->
                                                <div class="flex-grow min-w-0">
                                                    <div class="font-mono text-xs text-base-content/60 mb-1">
                                                        {{ $product['sku'] ?? '' }}
                                                    </div>
                                                    <div class="font-medium text-sm truncate" title="{{ $product['title'] ?? '' }}">
                                                        {{ $product['title'] ?? 'Chargement...' }}
                                                    </div>
                                                    <div class="flex items-center justify-between mt-2">
                                                        <span class="badge badge-sm badge-neutral">{{ $index + 1 }}</span>
                                                        <button 
                                                            wire:click="removeFromSummary('{{ $product['sku'] }}')"
                                                            class="btn btn-xs btn-error"
                                                            title="Retirer de la liste"
                                                        >
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                                                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                                                            </svg>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                            
                            <!-- Deuxième ligne -->
                            <div class="flex gap-3 min-w-max">
                                @foreach($selectedProductsDetails as $index => $product)
                                    @if($index % 2 == 1)
                                        @php
                                            $isInDatabase = $this->isInDatabase($product['sku']);
                                            $isNew = $this->isTemporarilySelected($product['sku']);
                                            $isRemoved = in_array($product['sku'], $this->getRemovedProducts());
                                        @endphp
                                        <div class="relative group border rounded-lg p-3 
                                            {{ $isRemoved ? 'bg-error/10 border-error line-through' : 
                                              ($isNew ? 'bg-warning/10 border-warning' : 
                                              'bg-success/10 border-success') }} 
                                            transition-colors flex-shrink-0"
                                            style="width: 300px;">
                                            <!-- Badge d'état -->
                                            <div class="absolute top-2 right-2">
                                                @if($isRemoved)
                                                    <span class="badge badge-sm badge-error" title="À supprimer">
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                                            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                                                        </svg>
                                                    </span>
                                                @elseif($isNew)
                                                    <span class="badge badge-sm badge-warning" title="Nouveau - À ajouter">
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                                            <path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd" />
                                                        </svg>
                                                    </span>
                                                @else
                                                    <span class="badge badge-sm badge-success" title="Déjà dans la liste">
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                                        </svg>
                                                    </span>
                                                @endif
                                            </div>
                                            
                                            <div class="flex items-start gap-3">
                                                <!-- Image -->
                                                <div class="flex-shrink-0">
                                                    @if(!empty($product['thumbnail']))
                                                        <div class="avatar">
                                                            <div class="w-12 h-12 rounded border">
                                                                <img 
                                                                    src="https://www.cosma-parfumeries.com/media/catalog/product/{{ $product['thumbnail'] }}"
                                                                    alt="{{ $product['title'] ?? '' }}"
                                                                    class="object-cover"
                                                                >
                                                            </div>
                                                        </div>
                                                    @else
                                                        <div class="w-12 h-12 bg-base-300 rounded border flex items-center justify-center">
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-base-content/40" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                            </svg>
                                                        </div>
                                                    @endif
                                                </div>
                                                
                                                <!-- Info produit -->
                                                <div class="flex-grow min-w-0">
                                                    <div class="font-mono text-xs text-base-content/60 mb-1">
                                                        {{ $product['sku'] ?? '' }}
                                                    </div>
                                                    <div class="font-medium text-sm truncate" title="{{ $product['title'] ?? '' }}">
                                                        {{ $product['title'] ?? 'Chargement...' }}
                                                    </div>
                                                    <div class="flex items-center justify-between mt-2">
                                                        <span class="badge badge-sm badge-neutral">{{ $index + 1 }}</span>
                                                        <button 
                                                            wire:click="removeFromSummary('{{ $product['sku'] }}')"
                                                            class="btn btn-xs btn-error"
                                                            title="Retirer de la liste"
                                                        >
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                                                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                                                            </svg>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    </div>
                    
                    <!-- Message si chargement incomplet -->
                    @if(count($selectedProductsDetails) < count($selectedProducts))
                        <div class="mt-4 text-center text-sm text-warning">
                            <span class="loading loading-spinner loading-xs"></span>
                            Chargement des détails des produits...
                            ({{ count($selectedProductsDetails) }}/{{ count($selectedProducts) }})
                        </div>
                    @endif
                </div>
            @endif
        </div>
    @endif

    <div class="rounded-box border border-base-content/5 bg-base-100 overflow-hidden">
        <!-- En-tête de la table -->
        <div class="bg-base-200 p-4 border-b border-base-content/10">
            <div class="flex items-center justify-between">
                <div class="font-semibold">Sélectionner des produits à ajouter à la liste</div>
                <div class="text-sm text-base-content/70">
                    Cochez les produits que vous souhaitez ajouter à votre liste
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
                    </tr>
                </thead>
                <tbody>
                    @forelse($products as $index => $product)
                        @php
                            $isSelected = in_array($product['sku'], $selectedProducts);
                            $isInDatabase = $this->isInDatabase($product['sku']);
                        @endphp
                        <tr wire:key="product-{{ $product['id'] ?? $index }}"
                            class="{{ $isSelected ? ($isInDatabase ? 'bg-success/5' : 'bg-warning/5') : '' }}">
                            <td>
                                @if($isInDatabase)
                                    <div class="tooltip" data-tip="Déjà dans la liste - Cliquez pour retirer">
                                        <button 
                                            type="button"
                                            wire:click="toggleSelect('{{ $product['sku'] }}')"
                                            class="text-success hover:text-error transition-colors"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                            </svg>
                                        </button>
                                    </div>
                                @else
                                    <input 
                                        type="checkbox" 
                                        class="checkbox checkbox-primary checkbox-xs" 
                                        {{ $isSelected ? 'checked' : '' }}
                                        wire:click="toggleSelect('{{ $product['sku'] }}', '{{ addslashes($product['title'] ?? '') }}', '{{ $product['thumbnail'] ?? '' }}')"
                                    />
                                @endif
                            </td>
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
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center py-12 text-base-content/50">
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
                            <td colspan="9" class="text-center py-8 bg-base-100/80">
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

    <!-- Modal de confirmation de mise à jour -->
    <x-modal wire:model="showModal" title="Mettre à jour la liste" persistent separator>
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
        
            <!-- Résumé des modifications -->
            <div class="border rounded-box p-4">
                <h4 class="font-semibold mb-2">Résumé des modifications</h4>
                <div class="space-y-2">
                    <div class="flex justify-between">
                        <span>Produits dans la liste actuelle:</span>
                        <span class="font-semibold">{{ count($existingProducts) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Nouvelle configuration:</span>
                        <span class="font-semibold text-primary">{{ count($selectedProducts) }} produits</span>
                    </div>
                    
                    <div class="divider my-1"></div>
                    
                    @if($newCount > 0)
                        <div class="flex justify-between">
                            <span>Nouveaux produits à ajouter:</span>
                            <span class="font-semibold text-warning">{{ $newCount }}</span>
                        </div>
                    @endif
                    
                    @if($removedCount > 0)
                        <div class="flex justify-between">
                            <span>Produits à supprimer:</span>
                            <span class="font-semibold text-error">{{ $removedCount }}</span>
                        </div>
                    @endif
                    
                    @if($newCount == 0 && $removedCount == 0)
                        <div class="flex justify-between">
                            <span>Modifications:</span>
                            <span class="font-semibold text-info">Aucune</span>
                        </div>
                    @endif
                </div>
            </div>
            
            <!-- Avertissement -->
            <div class="alert alert-warning">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="stroke-current shrink-0 w-6 h-6">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <div>
                    <div class="font-semibold">Attention</div>
                    <div class="text-sm">Cette action mettra à jour la liste existante. Les modifications seront permanentes.</div>
                </div>
            </div>
        </div>
        
        <x-slot:actions>
            <x-button label="Annuler" @click="$wire.showModal = false" />
            <x-button label="Mettre à jour" class="btn-primary" 
                wire:click="updateList" 
                wire:loading.attr="disabled">
                <span wire:loading.remove>Mettre à jour</span>
                <span wire:loading wire:target="updateList" class="flex items-center gap-2">
                    <span class="loading loading-spinner loading-sm"></span>
                    Mise à jour en cours...
                </span>
            </x-button>
        </x-slot:actions>
    </x-modal>
</div>

@push('scripts')
<script>
    document.addEventListener('livewire:initialized', () => {
        Livewire.on('notify', (event) => {
            const toast = document.createElement('div');
            toast.className = `toast toast-top toast-end`;
            toast.innerHTML = `
                <div class="alert ${event.type === 'success' ? 'alert-success' : 
                                  event.type === 'error' ? 'alert-error' : 
                                  event.type === 'info' ? 'alert-info' : 
                                  'alert-warning'}">
                    <span>${event.message}</span>
                </div>
            `;
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.remove();
            }, 3000);
        });
        
        Livewire.on('confirm-remove', (event) => {
            if (confirm(`${event.title}\n\n${event.message}`)) {
                Livewire.dispatch(event.method, { sku: event.sku });
            }
        });
        
        Livewire.on('list-updated', (event) => {
            // Redirection vers la page de détail de la liste après mise à jour
            setTimeout(() => {
                window.location.href = `/comparison/lists/${event.listId}`;
            }, 1500);
        });
    });
    
    // Fonction pour faire défiler le résumé
    function scrollSummary(direction) {
        const container = document.getElementById('summaryScrollContainer');
        const scrollAmount = 300; // Montant de défilement en pixels
        
        if (direction === 'left') {
            container.scrollLeft -= scrollAmount;
        } else {
            container.scrollLeft += scrollAmount;
        }
    }
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
    
    /* Tooltip styles */
    .tooltip {
        position: relative;
        display: inline-block;
    }
    
    .tooltip::before {
        content: attr(data-tip);
        position: absolute;
        bottom: 100%;
        left: 50%;
        transform: translateX(-50%);
        background: #374151;
        color: white;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 12px;
        white-space: nowrap;
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.2s, visibility 0.2s;
        z-index: 10;
        margin-bottom: 5px;
    }
    
    .tooltip:hover::before {
        opacity: 1;
        visibility: visible;
    }
</style>
@endpush