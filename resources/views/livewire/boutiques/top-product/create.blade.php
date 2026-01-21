<?php

use Livewire\Volt\Component;
use Livewire\Attributes\On;

new class extends Component {
    public $products = [];
    public $page = 1;
    public $perPage = 20;
    public $hasMore = true;
    public $loading = false;
    
    // Paramètres de recherche/filtres
    public $subQuery = '';
    public $params = [];
    
    public function mount()
    {
        $this->loadProducts();
    }
    
    #[On('load-more')]
    public function loadMore()
    {
        if (!$this->hasMore || $this->loading) {
            return;
        }
        
        $this->page++;
        $this->loadProducts();
    }
    
    protected function loadProducts()
    {
        $this->loading = true;
        
        $offset = ($this->page - 1) * $this->perPage;
        
        $newProducts = $this->getProducts($this->subQuery, $this->params, $this->perPage, $offset);
        
        if (count($newProducts) < $this->perPage) {
            $this->hasMore = false;
        }
        
        $this->products = array_merge($this->products, $newProducts);
        $this->loading = false;
    }
    
    protected function getProducts($subQuery, $params, $limit, $offset)
    {
        $cacheKey = $this->getCacheKey('products', md5($subQuery . serialize($params) . $limit . $offset));
        
        return Cache::remember($cacheKey, $this->cacheTTL ?? 3600, function () use ($subQuery, $params, $limit, $offset) {
            $results = DB::connection('mysqlMagento')->select("
                SELECT 
                    produit.entity_id,
                    product_char.sku,
                    product_char.name,
                    product_text.description,
                    product_decimal.price,
                    product_int.status,
                    stock_item.qty,
                    stock_status.stock_status,
                    product_media.image,
                    eas.attribute_set_name
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
                LIMIT ? OFFSET ?
            ", array_merge($params, [$limit, $offset]));

            return $results;
        });
    }
    
    protected function getCacheKey($type, $identifier)
    {
        return "products_{$type}_{$identifier}";
    }
    
    public function save()
    {
        // Logique de sauvegarde
        $this->dispatch('saved');
    }
    
    public function cancel()
    {
        return redirect()->to('/previous-page');
    }
}; ?>

<div class="mx-auto max-w-5xl">
    <x-header title="Créer la liste à comparer" separator>
        <x-slot:middle class="!justify-end">
        </x-slot:middle>
        <x-slot:actions>
            <x-button class="btn-error" label="Annuler" wire:click="cancel"
                wire:confirm="Êtes-vous sûr de vouloir annuler ?" />
            <x-button class="btn-primary" label="Valider" wire:click="save" />
        </x-slot:actions>
    </x-header>

    <div class="overflow-x-auto rounded-box border border-base-content/5 bg-base-100">
        <div 
            x-data="infiniteScroll()" 
            x-init="init()"
            class="max-h-[600px] overflow-y-auto"
            @scroll="onScroll"
        >
            <table class="table">
                <thead class="sticky top-0 bg-base-200 z-10">
                    <tr>
                        <th>ID</th>
                        <th>SKU</th>
                        <th>Nom</th>
                        <th>Prix</th>
                        <th>Stock</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($products as $product)
                        <tr wire:key="product-{{ $product->entity_id }}">
                            <th>{{ $product->entity_id }}</th>
                            <td>{{ $product->sku }}</td>
                            <td>{{ $product->name }}</td>
                            <td>{{ number_format($product->price ?? 0, 2) }} €</td>
                            <td>{{ $product->qty ?? 0 }}</td>
                            <td>
                                <span class="badge {{ $product->stock_status == 1 ? 'badge-success' : 'badge-error' }}">
                                    {{ $product->stock_status == 1 ? 'En stock' : 'Rupture' }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-8 text-base-content/50">
                                Aucun produit trouvé
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @if($loading)
                <div class="flex justify-center py-4">
                    <span class="loading loading-spinner loading-md"></span>
                    <span class="ml-2">Chargement...</span>
                </div>
            @endif

            @if(!$hasMore && count($products) > 0)
                <div class="text-center py-4 text-base-content/50">
                    Tous les produits ont été chargés
                </div>
            @endif
        </div>
    </div>
</div>

<script>
function infiniteScroll() {
    return {
        init() {
            // Initialisation si nécessaire
        },
        onScroll(event) {
            const element = event.target;
            const threshold = 100; // pixels avant la fin
            
            if (element.scrollHeight - element.scrollTop - element.clientHeight < threshold) {
                this.$wire.dispatch('load-more');
            }
        }
    }
}
</script>