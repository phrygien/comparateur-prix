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

<div class="w-full px-4 py-4 sm:px-6 lg:grid lg:grid-cols-2 lg:gap-x-10 lg:px-10">
    <!-- Product image -->
    <div class="lg:col-start-1 lg:row-span-2 lg:self-center">
        <div class="relative overflow-hidden rounded-xl group">
            <img src="{{ asset('https://www.cosma-parfumeries.com/media/catalog/product/' . $this->product->thumbnail) }}" 
                 alt="{{ utf8_encode($this->product->title) ?? 'Product image' }}" 
                 class="aspect-square w-full object-cover transition-transform duration-300 group-hover:scale-105">
        </div>
    </div>

    <!-- Product details -->
    <div class="lg:max-w-lg lg:self-center lg:col-start-2 mt-6 lg:mt-0">
        <nav aria-label="Breadcrumb" class="mb-4">
            <ol role="list" class="flex items-center space-x-2">
                <li>
                    <div class="flex items-center">
                        <a href="#" class="text-sm font-medium text-indigo-600 hover:text-indigo-800 transition-colors duration-200">
                           {{ utf8_encode($this->product->vendor) ?? 'N/A' }}
                        </a>
                        <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" class="ml-2 size-4 shrink-0 text-gray-400">
                            <path d="M5.555 17.776l8-16 .894.448-8 16-.894-.448z" />
                        </svg>
                    </div>
                </li>
                <li>
                    <div class="flex items-center">
                        <a href="#" class="text-sm font-medium text-gray-600 hover:text-gray-900 transition-colors duration-200">
                            {{ utf8_encode($this->product->type) ?? 'N/A' }}
                        </a>
                    </div>
                </li>
            </ol>
        </nav>

        <div class="mb-4">
            <h1 class="text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl leading-tight">
                {{ utf8_encode($this->product->title) ?? 'N/A' }}
            </h1>
        </div>

        <section aria-labelledby="information-heading" class="mb-6">
            <h2 id="information-heading" class="sr-only">Product information</h2>

            <div class="space-y-4">
                <p class="text-base leading-relaxed text-gray-700">
                    {{ strip_tags(utf8_encode($this->product->description)) ?? 'N/A' }}
                </p>
            </div>
        </section>
    </div>

    <!-- Product form -->
    {{-- <div class="mt-8 lg:col-start-2 lg:row-start-2 lg:max-w-lg lg:self-start">
        <section aria-labelledby="options-heading" class="p-4 rounded-lg border border-gray-200">
            <h2 id="options-heading" class="sr-only">Product options</h2>

            <form>
                <div class="sm:flex sm:justify-between">
                    <!-- Size selector -->
                    <fieldset>
                        <legend class="block text-sm font-semibold text-gray-900 mb-3">Variantes disponibles</legend>
                        <div class="mt-1 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div aria-label="18L" aria-description="Perfect for a reasonable amount of snacks." 
                                 class="relative block cursor-pointer rounded-lg border border-gray-200 p-3 focus:outline-hidden transition-all duration-200 hover:border-indigo-300">
                                <input type="radio" name="size-choice" value="18L" class="sr-only">
                                <div class="flex justify-between items-start">
                                    <p class="text-base font-medium text-gray-900">18 ML</p>
                                    <p class="text-base font-semibold text-indigo-600">65€</p>
                                </div>
                                <p class="mt-1 text-sm text-gray-600">Parfait pour une quantité raisonnable.</p>
                            </div>
                            
                            <div aria-label="20L" aria-description="Enough room for a serious amount of snacks." 
                                 class="relative block cursor-pointer rounded-lg border border-gray-200 p-3 focus:outline-hidden transition-all duration-200 hover:border-indigo-300">
                                <input type="radio" name="size-choice" value="20L" class="sr-only">
                                <div class="flex justify-between items-start">
                                    <p class="text-base font-medium text-gray-900">20 ML</p>
                                    <p class="text-base font-semibold text-indigo-600">85€</p>
                                </div>
                                <p class="mt-1 text-sm text-gray-600">Assez d'espace pour une quantité importante.</p>
                            </div>
                        </div>
                    </fieldset>
                </div>
                <div class="mt-4 pt-4 border-t border-gray-200">
                    <a href="#" class="group inline-flex items-center text-sm font-medium text-indigo-600 hover:text-indigo-800 transition-colors duration-200">
                        <span>Voir tous nos produits</span>
                        <svg class="ml-1 size-4 shrink-0 text-indigo-400 group-hover:text-indigo-600 transition-colors duration-200" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" data-slot="icon">
                            <path fill-rule="evenodd" d="M5.22 14.78a.75.75 0 001.06 0l7.22-7.22v5.69a.75.75 0 001.5 0v-7.5a.75.75 0 00-.75-.75h-7.5a.75.75 0 000 1.5h5.69l-7.22 7.22a.75.75 0 000 1.06z" clip-rule="evenodd" />
                        </svg>
                    </a>
                </div>
            </form>
        </section>
    </div> --}}
</div>