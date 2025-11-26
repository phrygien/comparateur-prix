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

<div class="w-full px-4 py-4 sm:px-6 lg:grid lg:grid-cols-3 lg:gap-x-8 lg:px-10">
    <!-- Product image - Takes up more space -->
    <div class="lg:col-span-2 lg:self-start sticky top-4">
        <div class="relative overflow-hidden rounded-xl group bg-gray-50">
            <img src="{{ asset('https://www.cosma-parfumeries.com/media/catalog/product/' . $this->product->thumbnail) }}" 
                 alt="{{ utf8_encode($this->product->title) ?? 'Product image' }}" 
                 class="aspect-[4/3] w-full object-contain transition-transform duration-300 group-hover:scale-105">
        </div>
    </div>

    <!-- Product details sidebar -->
    <div class="lg:col-span-1 mt-6 lg:mt-0 space-y-6">
        <!-- Breadcrumb -->
        <nav aria-label="Breadcrumb">
            <ol role="list" class="flex items-center space-x-2 text-sm">
                <li>
                    <a href="#" class="font-medium text-indigo-600 hover:text-indigo-800 transition-colors">
                        {{ utf8_encode($this->product->vendor) ?? 'N/A' }}
                    </a>
                </li>
                <li class="text-gray-400">/</li>
                <li>
                    <a href="#" class="text-gray-600 hover:text-gray-900 transition-colors">
                        {{ utf8_encode($this->product->type) ?? 'N/A' }}
                    </a>
                </li>
            </ol>
        </nav>

        <!-- Product Title -->
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-gray-900 leading-tight">
                {{ utf8_encode($this->product->title) ?? 'N/A' }}
            </h1>
            @if($this->product->sku)
            <p class="mt-1 text-sm text-gray-500">SKU: {{ $this->product->sku }}</p>
            @endif
        </div>

        <!-- Price Section - More prominent -->
        <div class="bg-gradient-to-br from-indigo-50 to-purple-50 p-5 rounded-xl border border-indigo-100 shadow-sm">
            <div class="space-y-3">
                @if($this->product->special_price)
                <div>
                    <p class="text-sm text-gray-600 line-through">{{ number_format($this->product->price, 2) }} €</p>
                    <p class="text-3xl font-bold text-red-600">
                        {{ number_format($this->product->special_price, 2) }} €
                    </p>
                    <span class="inline-block mt-1 px-2 py-1 text-xs font-semibold text-red-700 bg-red-100 rounded">
                        -{{ round((($this->product->price - $this->product->special_price) / $this->product->price) * 100) }}%
                    </span>
                </div>
                @else
                <div>
                    <p class="text-sm text-gray-600 mb-1">Prix de vente</p>
                    <p class="text-3xl font-bold text-gray-900">
                        {{ $this->product->price ? number_format($this->product->price, 2) . ' €' : 'N/A' }}
                    </p>
                </div>
                @endif
            </div>
        </div>

        <!-- Stock Status -->
        <div class="flex items-center space-x-3 p-4 rounded-lg {{ $this->product->quatity_status ? 'bg-green-50 border border-green-200' : 'bg-red-50 border border-red-200' }}">
            <svg class="w-5 h-5 {{ $this->product->quatity_status ? 'text-green-600' : 'text-red-600' }}" fill="currentColor" viewBox="0 0 20 20">
                @if($this->product->quatity_status)
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                @else
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                @endif
            </svg>
            <div>
                <p class="font-semibold {{ $this->product->quatity_status ? 'text-green-800' : 'text-red-800' }}">
                    {{ $this->product->quatity_status ? 'En stock' : 'Rupture de stock' }}
                </p>
                <p class="text-sm {{ $this->product->quatity_status ? 'text-green-600' : 'text-red-600' }}">
                    {{ $this->product->quatity ?? 0 }} unités disponibles
                </p>
            </div>
        </div>

        <!-- Quick Info -->
        <div class="bg-white p-4 rounded-lg border border-gray-200 space-y-3">
            @if($this->product->capacity)
            <div class="flex justify-between items-center">
                <span class="text-sm text-gray-600">Capacité</span>
                <span class="font-semibold text-gray-900">{{ $this->product->capacity }} ml</span>
            </div>
            @endif
            @if($this->product->color)
            <div class="flex justify-between items-center">
                <span class="text-sm text-gray-600">Couleur</span>
                <span class="font-semibold text-gray-900">{{ $this->product->color }}</span>
            </div>
            @endif
            @if($this->product->parkode)
            <div class="flex justify-between items-center">
                <span class="text-sm text-gray-600">Référence</span>
                <span class="font-mono text-sm font-semibold text-gray-900">{{ $this->product->parkode }}</span>
            </div>
            @endif
        </div>

        <!-- Pricing Details - Collapsible -->
        <details class="bg-gray-50 rounded-lg border border-gray-200">
            <summary class="cursor-pointer p-4 font-semibold text-gray-900 hover:bg-gray-100 transition-colors rounded-lg">
                Détails des prix
            </summary>
            <div class="p-4 pt-0 space-y-3 text-sm">
                <div class="flex justify-between">
                    <span class="text-gray-600">Coût d'achat HT</span>
                    <span class="font-semibold text-blue-600">
                        {{ $this->product->prix_achat_ht ? number_format($this->product->prix_achat_ht, 2) . ' €' : 'N/A' }}
                    </span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-600">Prix PVC</span>
                    <span class="font-semibold text-purple-600">
                        {{ $this->product->pvc ? number_format($this->product->pvc, 2) . ' €' : 'N/A' }}
                    </span>
                </div>
                @if($this->product->prix_us)
                <div class="flex justify-between">
                    <span class="text-gray-600">Prix US</span>
                    <span class="font-semibold text-orange-600">
                        ${{ number_format($this->product->prix_us, 2) }}
                    </span>
                </div>
                @endif
            </div>
        </details>
    </div>

    <!-- Full-width descriptions below -->
    <div class="lg:col-span-3 mt-8 pt-8 border-t border-gray-200">
        <div class="grid lg:grid-cols-2 gap-8">
            <!-- Description Section -->
            <div class="space-y-6">
                <h2 class="text-2xl font-bold text-gray-900">Description du produit</h2>
                
                @if($this->product->short_description)
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 mb-2 uppercase tracking-wide">Aperçu</h3>
                    <p class="text-base leading-relaxed text-gray-700">
                        {{ strip_tags(utf8_encode($this->product->short_description)) }}
                    </p>
                </div>
                @endif

                @if($this->product->description)
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 mb-2 uppercase tracking-wide">Détails</h3>
                    <p class="text-base leading-relaxed text-gray-700">
                        {{ strip_tags(utf8_encode($this->product->description)) }}
                    </p>
                </div>
                @endif
            </div>

            <!-- Product Attributes -->
            <div class="space-y-6">
                <h2 class="text-2xl font-bold text-gray-900">Caractéristiques</h2>
                
                @if($this->product->composition)
                <div class="bg-blue-50 p-4 rounded-lg border border-blue-200">
                    <h3 class="text-sm font-semibold text-blue-900 mb-2 uppercase tracking-wide">Composition</h3>
                    <p class="text-base leading-relaxed text-gray-700">
                        {{ strip_tags(utf8_encode($this->product->composition)) }}
                    </p>
                </div>
                @endif

                @if($this->product->olfactive_families)
                <div class="bg-purple-50 p-4 rounded-lg border border-purple-200">
                    <h3 class="text-sm font-semibold text-purple-900 mb-2 uppercase tracking-wide">Familles olfactives</h3>
                    <p class="text-base leading-relaxed text-gray-700">
                        {{ strip_tags(utf8_encode($this->product->olfactive_families)) }}
                    </p>
                </div>
                @endif

                @if($this->product->product_benefit)
                <div class="bg-green-50 p-4 rounded-lg border border-green-200">
                    <h3 class="text-sm font-semibold text-green-900 mb-2 uppercase tracking-wide">Bénéfices</h3>
                    <p class="text-base leading-relaxed text-gray-700">
                        {{ strip_tags(utf8_encode($this->product->product_benefit)) }}
                    </p>
                </div>
                @endif

                @if($this->product->categorie)
                <div class="bg-gray-100 p-4 rounded-lg">
                    <h3 class="text-sm font-semibold text-gray-900 mb-2 uppercase tracking-wide">Catégorie</h3>
                    <p class="text-base text-gray-700">
                        {{ utf8_encode($this->product->categorie) }}
                    </p>
                </div>
                @endif
            </div>
        </div>
    </div>
</div>