<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;

new class extends Component {

    // product details
    public $product = null;

    public function mount($id)
    {
        $this->getOneProductDetails($id);
    }

    public function getOneProductDetails($entity_id){
        try{

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
                WHERE product_int.status >= 0 AND produit.entity_id = ? 
                ORDER BY product_char.entity_id DESC
            ";

            $result = DB::connection('mysqlMagento')->select($dataQuery, [$entity_id]);

            // CORRECTION: Convertir l'objet stdClass en array pour Livewire
            if (!empty($result)) {
                // Méthode 1: Via json_decode/encode (recommandée)
                $this->product = json_decode(json_encode($result[0]), true);
                
                // OU Méthode 2: Via cast (alternative)
                // $this->product = (array) $result[0];
            } else {
                $this->product = null;
            }

        } catch (\Throwable $e) {
            \Log::error('Error loading product:', [
                'message' => $e->getMessage(),
                'entity_id' => $entity_id ?? null,
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->product = null;
            
            // Optionnel: afficher l'erreur à l'utilisateur
            session()->flash('error', 'Une erreur est survenue lors du chargement du produit.');
        }
    }

}; ?>

<div class="w-full px-4 py-2 sm:px-2 sm:py-4 lg:grid lg:grid-cols-2 lg:gap-x-8 lg:px-8">
    <!-- Product image -->
    <div class="mt-10 lg:col-start-1 lg:row-span-2 lg:mt-0 lg:self-center">
        <img src="https://images.unsplash.com/photo-1556228720-195a672e8a03?w=800&q=80" alt="Model wearing light green backpack with black canvas straps and front zipper pouch." class="aspect-square w-full rounded-lg object-cover">
    </div>

    <!-- Product details -->
    <div class="lg:max-w-lg lg:self-end lg:col-start-2">
        <nav aria-label="Breadcrumb">
            <ol role="list" class="flex items-center space-x-2">
                <li>
                    <div class="flex items-center text-sm">
                        <a href="#" class="font-medium text-gray-500 hover:text-gray-900">
                           {{ $product->vendor }}
                        </a>
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

    <!-- Product form -->
    <div class="mt-10 lg:col-start-2 lg:row-start-2 lg:max-w-lg lg:self-start">
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
            </form>
        </section>
    </div>
</div>