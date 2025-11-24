<?php

use Livewire\Volt\Component;

new class extends Component {
  

  public function mount($name): void
  {
    //dd($name);
     dd($this->getCompetitorPrice($name));
  }

  public function getCompetitorPrice($search){
    try{

      $subQuery = "";
      $params = [];

      // Global search
      if (!empty($search)) {
        
          $searchClean = str_replace("'", "", $search);
          $searchClean = str_replace(" - ", " ", $searchClean);

          $words = explode(" ", $searchClean);

          //dd($words);

          $subQuery = " AND ( ";
          $and = "";

          foreach ($words as $word) {
              $subQuery .= " $and LOWER( CONCAT(vendor, ' ', name, ' ', COALESCE(type,''), ' ', COALESCE(variation, '')) ) LIKE %".mb_strtolower($word, 'UTF-8')."%";
              $params[] = "%".mb_strtolower($word, 'UTF-8')."%";
              $and = "AND";
          }

          $subQuery .= " ) ";
      }

      // Paginated data
      $dataQuery = "
        SELECT * 
        FROM scraped_product 
        WHERE 1=1 $subQuery
        ORDER BY scrap_reference_id DESC, created_at DESC 
      ";

      dd($dataQuery);
      $result = DB::connection('mysql')->select($dataQuery, $params);

       return [
          "data" => $result
        ];

    } 
    catch (\Throwable $e) {
      // Log l'erreur et retourne un tableau vide
      \Log::error('Error loading products: ' . $e->getMessage());
      return [
        "data" => []
      ];
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
                    <!--
                    Active: "border", Not Active: "border-2"
                    Checked: "border-indigo-500", Not Checked: "border-transparent"
                    -->
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
                    <!--
                    Active: "border", Not Active: "border-2"
                    Checked: "border-indigo-500", Not Checked: "border-transparent"
                    -->
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
          {{-- <div class="mt-10">
            <button type="submit" class="flex w-full items-center justify-center rounded-md border border-transparent bg-indigo-600 px-8 py-3 text-base font-medium text-white hover:bg-indigo-700 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-gray-50 focus:outline-hidden">Add to bag</button>
          </div> --}}
        </form>
      </section>
    </div>
  </div>


<livewire:plateformes.comparateur.product />

</div>
