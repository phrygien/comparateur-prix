<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;

new class extends Component {

    public $productId = null;

    public function mount($id)
    {
        $this->productId = $id;
    }

    #[Computed]
    public function product()
    {
        try {
            $result = DB::connection('mysqlMagento')
                ->table('catalog_product_entity as produit')
                ->select([
                    'produit.entity_id as id',
                    'produit.sku as sku',
                    'product_char.reference as parkode',
                    DB::raw('CAST(product_char.name AS CHAR CHARACTER SET utf8mb4) as title'),
                    DB::raw('CAST(product_parent_char.name AS CHAR CHARACTER SET utf8mb4) as parent_title'),
                    DB::raw("SUBSTRING_INDEX(product_char.name, ' - ', 1) as vendor"),
                    DB::raw("SUBSTRING_INDEX(eas.attribute_set_name, '_', -1) as type"),
                    'product_char.thumbnail as thumbnail',
                    'product_char.swatch_image as swatch_image',
                    'product_char.reference_us as reference_us',
                    DB::raw('CAST(product_text.description AS CHAR CHARACTER SET utf8mb4) as description'),
                    DB::raw('CAST(product_text.short_description AS CHAR CHARACTER SET utf8mb4) as short_description'),
                    DB::raw('CAST(product_parent_text.description AS CHAR CHARACTER SET utf8mb4) as parent_description'),
                    DB::raw('CAST(product_parent_text.short_description AS CHAR CHARACTER SET utf8mb4) as parent_short_description'),
                    DB::raw('CAST(product_text.composition AS CHAR CHARACTER SET utf8mb4) as composition'),
                    DB::raw('CAST(product_text.olfactive_families AS CHAR CHARACTER SET utf8mb4) as olfactive_families'),
                    DB::raw('CAST(product_text.product_benefit AS CHAR CHARACTER SET utf8mb4) as product_benefit'),
                    DB::raw('ROUND(product_decimal.price, 2) as price'),
                    DB::raw('ROUND(product_decimal.special_price, 2) as special_price'),
                    DB::raw('ROUND(product_decimal.cost, 2) as cost'),
                    DB::raw('ROUND(product_decimal.pvc, 2) as pvc'),
                    DB::raw('ROUND(product_decimal.prix_achat_ht, 2) as prix_achat_ht'),
                    DB::raw('ROUND(product_decimal.prix_us, 2) as prix_us'),
                    'product_int.status as status',
                    'product_int.color as color',
                    'product_int.capacity as capacity',
                    'product_int.product_type as product_type',
                    'product_media.media_gallery as media_gallery',
                    DB::raw('CAST(product_categorie.name AS CHAR CHARACTER SET utf8mb4) as categorie'),
                    DB::raw("REPLACE(product_categorie.name, ' > ', ',') as tags"),
                    'stock_item.qty as quatity',
                    'stock_status.stock_status as quatity_status',
                    'options.configurable_product_id as configurable_product_id',
                    'parent_child_table.parent_id as parent_id',
                    'options.attribute_code as option_name',
                    'options.attribute_value as option_value'
                ])
                ->leftJoin('catalog_product_relation as parent_child_table', 'parent_child_table.child_id', '=', 'produit.entity_id')
                ->leftJoin('catalog_product_super_link as cpsl', 'cpsl.product_id', '=', 'produit.entity_id')
                ->leftJoin('product_char', 'product_char.entity_id', '=', 'produit.entity_id')
                ->leftJoin('product_text', 'product_text.entity_id', '=', 'produit.entity_id')
                ->leftJoin('product_decimal', 'product_decimal.entity_id', '=', 'produit.entity_id')
                ->leftJoin('product_int', 'product_int.entity_id', '=', 'produit.entity_id')
                ->leftJoin('product_media', 'product_media.entity_id', '=', 'produit.entity_id')
                ->leftJoin('product_categorie', 'product_categorie.entity_id', '=', 'produit.entity_id')
                ->leftJoin('cataloginventory_stock_item as stock_item', 'stock_item.product_id', '=', 'produit.entity_id')
                ->leftJoin('cataloginventory_stock_status as stock_status', 'stock_item.product_id', '=', 'stock_status.product_id')
                ->leftJoin('option_super_attribut as options', 'options.simple_product_id', '=', 'produit.entity_id')
                ->leftJoin('eav_attribute_set as eas', 'produit.attribute_set_id', '=', 'eas.attribute_set_id')
                ->leftJoin('catalog_product_entity as produit_parent', 'parent_child_table.parent_id', '=', 'produit_parent.entity_id')
                ->leftJoin('product_char as product_parent_char', 'product_parent_char.entity_id', '=', 'produit_parent.entity_id')
                ->leftJoin('product_text as product_parent_text', 'product_parent_text.entity_id', '=', 'produit_parent.entity_id')
                ->where('produit.entity_id', $this->productId)
                ->where(function($query) {
                    $query->whereNull('product_int.status')
                          ->orWhere('product_int.status', '>=', 0);
                })
                ->orderBy('product_char.entity_id', 'DESC')
                ->first();

            return $result;

        } catch (\Throwable $e) {
            \Log::error('Error loading product:', [
                'message' => $e->getMessage(),
                'entity_id' => $this->productId ?? null,
                'trace' => $e->getTraceAsString()
            ]);
            
            return null;
        }
    }

}; ?>

<div class="w-full px-4 py-6 sm:px-6 lg:grid lg:grid-cols-2 lg:gap-x-10 lg:px-10">
    <!-- Product image - Left column -->
    <div class="lg:col-start-1">
        <div class="relative overflow-hidden rounded-lg">
            <img src="{{ asset('https://www.cosma-parfumeries.com/media/catalog/product/' . $this->product->thumbnail) }}" 
                 alt="{{ utf8_encode($this->product->title) ?? 'Product image' }}" 
                 class="aspect-[4/3] w-full object-cover">
        </div>
    </div>

    <!-- Product details - Right column -->
    <div class="lg:col-start-2 mt-6 lg:mt-0">
        <!-- Vendor -->
        <p class="text-sm text-gray-600 mb-2">
            {{ utf8_encode($this->product->vendor) ?? 'N/A' }}
        </p>

        <!-- Product Name -->
        <h1 class="text-3xl font-bold text-gray-900 mb-4">
            {{ utf8_encode($this->product->title) ?? 'N/A' }}
        </h1>

        <!-- Price -->
        <div class="mb-6">
            @if($this->product->special_price)
                <p class="text-2xl font-bold text-red-600">
                    {{ number_format($this->product->special_price, 2) }} €
                </p>
                <p class="text-lg text-gray-500 line-through">
                    {{ number_format($this->product->price, 2) }} €
                </p>
            @else
                <p class="text-2xl font-bold text-gray-900">
                    {{ $this->product->price ? number_format($this->product->price, 2) . ' €' : 'N/A' }}
                </p>
            @endif
        </div>

        <!-- Price Details - Collapsible -->
        <details class="mb-6 border border-gray-200 rounded-lg">
            <summary class="cursor-pointer p-4 font-semibold text-gray-900 hover:bg-gray-50 transition-colors rounded-lg flex items-center justify-between">
                <span>Détails des prix</span>
                <svg class="w-5 h-5 text-gray-500 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
            </summary>
            <div class="px-4 pb-4 space-y-3 text-sm border-t border-gray-100 pt-3">
                @if($this->product->price)
                <div class="flex justify-between">
                    <span class="text-gray-600">Prix de vente</span>
                    <span class="font-semibold text-gray-900">{{ number_format($this->product->price, 2) }} €</span>
                </div>
                @endif
                @if($this->product->special_price)
                <div class="flex justify-between">
                    <span class="text-gray-600">Prix promotionnel</span>
                    <span class="font-semibold text-red-600">{{ number_format($this->product->special_price, 2) }} €</span>
                </div>
                @endif
                @if($this->product->prix_achat_ht)
                <div class="flex justify-between">
                    <span class="text-gray-600">Coût d'achat HT</span>
                    <span class="font-semibold text-blue-600">{{ number_format($this->product->prix_achat_ht, 2) }} €</span>
                </div>
                @endif
                @if($this->product->pvc)
                <div class="flex justify-between">
                    <span class="text-gray-600">Prix PVC</span>
                    <span class="font-semibold text-purple-600">{{ number_format($this->product->pvc, 2) }} €</span>
                </div>
                @endif
                @if($this->product->prix_us)
                <div class="flex justify-between">
                    <span class="text-gray-600">Prix US</span>
                    <span class="font-semibold text-orange-600">${{ number_format($this->product->prix_us, 2) }}</span>
                </div>
                @endif
            </div>
        </details>

        <!-- Description - Collapsible -->
        @if($this->product->description || $this->product->short_description)
        <details class="border border-gray-200 rounded-lg">
            <summary class="cursor-pointer p-4 font-semibold text-gray-900 hover:bg-gray-50 transition-colors rounded-lg flex items-center justify-between">
                <span>Description</span>
                <svg class="w-5 h-5 text-gray-500 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
            </summary>
            <div class="px-4 pb-4 border-t border-gray-100 pt-3">
                @if($this->product->description)
                <p class="text-gray-700 leading-relaxed">
                    {{ strip_tags(utf8_encode($this->product->description)) }}
                </p>
                @elseif($this->product->short_description)
                <p class="text-gray-700 leading-relaxed">
                    {{ strip_tags(utf8_encode($this->product->short_description)) }}
                </p>
                @endif
            </div>
        </details>
        @endif
    </div>
</div>