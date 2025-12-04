<?php

namespace App\Livewire\Boutiques;

use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

class Boutique extends Component
{
    use WithPagination;

    public $search = "";
    
    // Filtres avancés
    public $filterName = "";
    public $filterMarque = "";
    public $filterType = "";
    public $filterCapacity = "";

    // Nombre d'éléments par page
    public $perPage = 12;

    protected $queryString = [
        'search' => ['except' => ''],
        'filterName' => ['except' => ''],
        'filterMarque' => ['except' => ''],
        'filterType' => ['except' => ''],
        'filterCapacity' => ['except' => ''],
        'perPage' => ['except' => 12],
    ];

    public function updated($property)
    {
        // Reset à la première page quand un filtre change
        if (in_array($property, ['search', 'filterName', 'filterMarque', 'filterType', 'filterCapacity', 'perPage'])) {
            $this->resetPage();
        }
    }

    public function applyFilters()
    {
        $this->resetPage();
        $this->dispatch('close-drawer');
    }

    public function resetFilters()
    {
        $this->filterName = "";
        $this->filterMarque = "";
        $this->filterType = "";
        $this->filterCapacity = "";
        $this->search = "";
        $this->resetPage();
        $this->dispatch('close-drawer');
    }

    // Méthodes de pagination personnalisées
    public function goToPage($page)
    {
        $this->setPage($page);
    }

    public function previousPage()
    {
        $this->setPage(max(1, $this->getPage() - 1));
    }

    public function nextPage()
    {
        $currentPage = $this->getPage();
        $productsData = $this->getListProduct($this->search, $currentPage, $this->perPage);
        $totalPages = $productsData['total_page'];
        
        $this->setPage(min($totalPages, $currentPage + 1));
    }

    public function getPage()
    {
        return $this->paginators['page'] ?? 1;
    }

    public function setPage($page)
    {
        $this->paginators['page'] = $page;
    }

    public function getListProduct($search = "", $page = 1, $perPage = null)
    {
        $perPage = $perPage ?: $this->perPage;

        try {
            $offset = ($page - 1) * $perPage;

            // Construction des conditions WHERE
            $whereConditions = ["product_int.status >= 0"];
            $params = [];

            // Global search - optimisé
            if (!empty($search)) {
                $searchClean = str_replace("'", "", $search);
                $words = explode(" ", $searchClean);

                $searchConditions = [];
                foreach ($words as $word) {
                    $searchConditions[] = "CONCAT(product_char.name, ' ', COALESCE(options.attribute_value, '')) LIKE ?";
                    $params[] = "%$word%";
                }

                $whereConditions[] = "(" . implode(" AND ", $searchConditions) . " OR produit.sku LIKE ?)";
                $params[] = "%$searchClean%";
            }

            // Filtres avancés
            if (!empty($this->filterName)) {
                $whereConditions[] = "product_char.name LIKE ?";
                $params[] = "%{$this->filterName}%";
            }

            if (!empty($this->filterMarque)) {
                $whereConditions[] = "SUBSTRING_INDEX(product_char.name, ' - ', 1) = ?";
                $params[] = $this->filterMarque;
            }

            if (!empty($this->filterType)) {
                $whereConditions[] = "SUBSTRING_INDEX(eas.attribute_set_name, '_', -1) = ?";
                $params[] = $this->filterType;
            }

            if (!empty($this->filterCapacity)) {
                $whereConditions[] = "product_int.capacity = ?";
                $params[] = $this->filterCapacity;
            }

            // Filtre prix > 0
            $whereConditions[] = "product_decimal.price > 0";

            $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

            // **OPTIMISATION 1: Création d'une VIEW ou CTE virtuelle pour les données principales**
            $mainQuery = "
            WITH product_base AS (
                SELECT 
                    p.entity_id,
                    p.sku,
                    p.attribute_set_id,
                    -- Joins optimisés avec EXISTS au lieu de LEFT JOIN quand possible
                    COALESCE(pc.name, '') as name,
                    COALESCE(pc.reference, '') as reference,
                    COALESCE(pc.reference_us, '') as reference_us,
                    COALESCE(pc.thumbnail, '') as thumbnail,
                    COALESCE(pc.swatch_image, '') as swatch_image,
                    COALESCE(pt.description, '') as description,
                    COALESCE(pt.short_description, '') as short_description,
                    COALESCE(pt.composition, '') as composition,
                    COALESCE(pt.olfactive_families, '') as olfactive_families,
                    COALESCE(pt.product_benefit, '') as product_benefit,
                    COALESCE(pd.price, 0) as price,
                    COALESCE(pd.special_price, 0) as special_price,
                    COALESCE(pd.cost, 0) as cost,
                    COALESCE(pd.pvc, 0) as pvc,
                    COALESCE(pd.prix_achat_ht, 0) as prix_achat_ht,
                    COALESCE(pd.prix_us, 0) as prix_us,
                    COALESCE(pi.status, 0) as status,
                    COALESCE(pi.color, 0) as color,
                    COALESCE(pi.capacity, 0) as capacity,
                    COALESCE(pi.product_type, 0) as product_type,
                    COALESCE(pm.media_gallery, '') as media_gallery,
                    COALESCE(pcat.name, '') as categorie_name,
                    -- Récupération du parent ID une seule fois
                    (
                        SELECT parent_id 
                        FROM catalog_product_relation cpr 
                        WHERE cpr.child_id = p.entity_id 
                        LIMIT 1
                    ) as parent_id
                FROM catalog_product_entity p
                INNER JOIN product_int pi ON p.entity_id = pi.entity_id AND pi.status >= 0
                LEFT JOIN product_char pc ON p.entity_id = pc.entity_id
                LEFT JOIN product_text pt ON p.entity_id = pt.entity_id
                LEFT JOIN product_decimal pd ON p.entity_id = pd.entity_id AND pd.price > 0
                LEFT JOIN product_media pm ON p.entity_id = pm.entity_id
                LEFT JOIN product_categorie pcat ON p.entity_id = pcat.entity_id
            )
        ";

            // **OPTIMISATION 2: Requête de comptage séparée et simplifiée**
            $countQuery = "
            SELECT COUNT(*) as nb
            FROM product_base pb
            LEFT JOIN eav_attribute_set eas ON pb.attribute_set_id = eas.attribute_set_id 
            LEFT JOIN option_super_attribut osa ON pb.entity_id = osa.simple_product_id
            $whereClause
        ";

            $resultTotal = DB::connection('mysqlMagento')->selectOne($countQuery, $params);
            $total = $resultTotal->nb ?? 0;

            if ($total === 0) {
                return [
                    "total_item" => 0,
                    "per_page" => $perPage,
                    "total_page" => 0,
                    "current_page" => $page,
                    "data" => []
                ];
            }

            $nbPage = ceil($total / $perPage);

            if ($page > $nbPage && $nbPage > 0) {
                $page = 1;
                $offset = 0;
            }

            // **OPTIMISATION 3: Requête principale avec JOIN optimisés**
            $dataQuery = "
            $mainQuery
            SELECT 
                pb.entity_id as id,
                pb.sku as sku,
                pb.reference as parkode,
                pb.name as title,
                -- Données parent récupérées seulement si nécessaire
                (
                    SELECT name 
                    FROM product_char 
                    WHERE entity_id = pb.parent_id 
                    LIMIT 1
                ) as parent_title,
                SUBSTRING_INDEX(pb.name, ' - ', 1) as vendor,
                SUBSTRING_INDEX(eas.attribute_set_name, '_', -1) as type,
                pb.thumbnail,
                pb.swatch_image,
                pb.reference_us,
                pb.description,
                pb.short_description,
                -- Descriptions parent
                (
                    SELECT description 
                    FROM product_text 
                    WHERE entity_id = pb.parent_id 
                    LIMIT 1
                ) as parent_description,
                (
                    SELECT short_description 
                    FROM product_text 
                    WHERE entity_id = pb.parent_id 
                    LIMIT 1
                ) as parent_short_description,
                pb.composition,
                pb.olfactive_families,
                pb.product_benefit,
                ROUND(pb.price, 2) as price,
                ROUND(pb.special_price, 2) as special_price,
                ROUND(pb.cost, 2) as cost,
                ROUND(pb.pvc, 2) as pvc,
                ROUND(pb.prix_achat_ht, 2) as prix_achat_ht,
                ROUND(pb.prix_us, 2) as prix_us,
                pb.status,
                pb.color,
                pb.capacity,
                pb.product_type,
                pb.media_gallery,
                pb.categorie_name as categorie,
                REPLACE(pb.categorie_name, ' > ', ',') as tags,
                -- Stock info
                COALESCE(csi.qty, 0) as quantity,
                COALESCE(css.stock_status, 0) as quantity_status,
                -- Options
                osa.configurable_product_id,
                pb.parent_id,
                osa.attribute_code as option_name,
                osa.attribute_value as option_value
            FROM product_base pb
            LEFT JOIN eav_attribute_set eas ON pb.attribute_set_id = eas.attribute_set_id 
            LEFT JOIN cataloginventory_stock_item csi ON csi.product_id = pb.entity_id 
            LEFT JOIN cataloginventory_stock_status css ON css.product_id = pb.entity_id 
            LEFT JOIN option_super_attribut osa ON pb.entity_id = osa.simple_product_id
            $whereClause
            ORDER BY pb.entity_id DESC
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
                "data" => $result
            ];

        } catch (\Throwable $e) {
            return [
                "total_item" => 0,
                "per_page" => $perPage,
                "total_page" => 0,
                "current_page" => 1,
                "data" => []
            ];
        }
    }

    public function render()
    {
        $productsData = $this->getListProduct($this->search, $this->getPage(), $this->perPage);
        
        return view('livewire.boutiques.boutique', [
            'products' => $productsData['data'],
            'totalItems' => $productsData['total_item'],
            'totalPages' => $productsData['total_page'],
            'currentPage' => $productsData['current_page']
        ]);
    }
}