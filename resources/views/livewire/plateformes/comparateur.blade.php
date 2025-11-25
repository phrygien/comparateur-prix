<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;

new class extends Component {
    public $products = [];
    public $hasData = false;
    
    public function mount($name)
    {
        dd($this->getCompetitorPrice($name));
    }

    public function getCompetitorPrice($search)
    {
        try {
            if (!empty($search)) {
                // Préparer les termes pour la recherche boolean mode
                $searchClean = str_replace("'", "", $search);
                $searchClean = str_replace(" - ", "", $searchClean);
                
                $words = explode(" ", $searchClean);
                $booleanTerms = [];
                
                foreach ($words as $word) {
                    if (strlen($word) > 2) { // Éviter les mots trop courts
                        $booleanTerms[] = '+' . $word . '*';
                    }
                }
                
                $searchQuery = implode(' ', $booleanTerms);
                
                $dataQuery = "SELECT *
                FROM last_price_scraped_product
                WHERE MATCH (name, vendor, type, variation)
                AGAINST ('+guerlain +shalimar +coffret +50ml*' IN BOOLEAN MODE)
                ORDER BY created_at DESC";
                //dd($dataQuery);
                $result = DB::connection('mysql')->select($dataQuery);
                
                $this->products = $result;
                $this->hasData = !empty($result);
                
            } else {
                $this->products = [];
                $this->hasData = false;
            }
            
        } catch (\Throwable $e) {
            \Log::error('Error loading products: ' . $e->getMessage());
            $this->products = [];
            $this->hasData = false;
        }
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

    <!-- Section des résultats -->
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        @if($hasData)
            <!-- Tableau des résultats -->
            <div class="bg-white shadow-sm rounded-lg overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Résultats de la recherche</h3>
                    <p class="mt-1 text-sm text-gray-500">Termes recherchés : {{ $search ?? 'N/A' }}</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nom</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vendor</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Variation</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach($products as $product)
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $product->name ?? 'N/A' }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $product->vendor ?? 'N/A' }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $product->type ?? 'N/A' }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $product->variation ?? 'N/A' }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $product->created_at ?? 'N/A' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @else
            <!-- Empty State -->
            <div class="text-center py-12">
                <svg class="mx-auto h-24 w-24 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <h3 class="mt-4 text-lg font-medium text-gray-900">Aucun résultat trouvé</h3>
                <p class="mt-2 text-sm text-gray-500">Aucun produit ne correspond à la recherche : {{ $search ?? 'N/A' }}</p>
            </div>
        @endif
    </div>
</div>