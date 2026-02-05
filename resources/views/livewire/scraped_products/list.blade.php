<?php

use Livewire\Volt\Component;
use App\Models\Site;
use App\Models\Product;
use Illuminate\Pagination\LengthAwarePaginator;

new class extends Component {
    public $vendor = '';
    public $name = '';
    public $type = '';
    public $variation = '';
    public $site_ids = [];
    public $showResults = false;
    public $currentPage = 1;
    public $perPage = 50;

    public function applyFilter()
    {
        $this->showResults = true;
        $this->currentPage = 1;
    }

    public function resetFilter()
    {
        $this->vendor = '';
        $this->name = '';
        $this->type = '';
        $this->variation = '';
        $this->site_ids = [];
        $this->showResults = false;
        $this->currentPage = 1;
    }

    public function previousPage()
    {
        if ($this->currentPage > 1) {
            $this->currentPage--;
        }
    }

    public function nextPage()
    {
        $this->currentPage++;
    }

    public function goToPage($page)
    {
        if ($page >= 1) {
            $this->currentPage = $page;
        }
    }

    public function exportCsv()
    {
        set_time_limit(700);
        ini_set('memory_limit', '512M');

        // Utiliser Typesense pour récupérer tous les résultats
        $searchQuery = $this->buildSearchQuery();
        
        // Pour l'export, on récupère tout (pas de pagination)
        $results = Product::search($searchQuery, function ($typesenseSearch, $query, $options) {
            $filters = $this->buildFilters();
            if (!empty($filters)) {
                $options['filter_by'] = $filters;
            }
            
            // Récupérer jusqu'à 250 résultats par page (limite Typesense)
            // Pour plus, il faudra faire plusieurs requêtes
            $options['per_page'] = 250;
            $options['page'] = 1;
            
            return $options;
        })->get();

        // Si vous avez potentiellement plus de 250 résultats, utilisez cette approche :
        $allProducts = collect();
        $page = 1;
        do {
            $pageResults = Product::search($searchQuery, function ($typesenseSearch, $query, $options) use ($page) {
                $filters = $this->buildFilters();
                if (!empty($filters)) {
                    $options['filter_by'] = $filters;
                }
                $options['per_page'] = 250;
                $options['page'] = $page;
                return $options;
            })->get();
            
            $allProducts = $allProducts->merge($pageResults);
            $page++;
        } while ($pageResults->count() === 250);

        $products = $allProducts;

        // Le reste du code Excel reste identique
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Produits Concurrents');

        $headers = ['Vendeur', 'Nom du produit', 'Type', 'Variation', 'Prix HT', 'Devise', 'Site web', 'URL Produit', 'Date de scraping', 'Image'];
        $sheet->fromArray($headers, null, 'A1');

        $headerStyle = [
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 12
            ],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4F46E5']
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['rgb' => '000000']
                ]
            ]
        ];
        $sheet->getStyle('A1:J1')->applyFromArray($headerStyle);
        $sheet->getRowDimension(1)->setRowHeight(25);

        $row = 2;
        foreach ($products as $product) {
            $site = Site::find($product->web_site_id);

            $sheet->setCellValue('A' . $row, $product->vendor ?? '');
            $sheet->setCellValue('B' . $row, $product->name ?? '');
            $sheet->setCellValue('C' . $row, $product->type ?? '');
            $sheet->setCellValue('D' . $row, $product->variation ?? '');
            $sheet->setCellValue('E' . $row, $product->prix_ht ?? '');
            $sheet->setCellValue('F' . $row, $product->currency ?? '');
            $sheet->setCellValue('G' . $row, $site ? $site->name : '');

            if (!empty($product->url) && filter_var($product->url, FILTER_VALIDATE_URL)) {
                $sheet->setCellValue('H' . $row, 'Voir le produit');
                $sheet->getCell('H' . $row)->getHyperlink()->setUrl($product->url);
                $sheet->getStyle('H' . $row)->applyFromArray([
                    'font' => [
                        'color' => ['rgb' => '0563C1'],
                        'underline' => \PhpOffice\PhpSpreadsheet\Style\Font::UNDERLINE_SINGLE
                    ]
                ]);
            } else {
                $sheet->setCellValue('H' . $row, 'Pas d\'URL');
            }

            $sheet->setCellValue('I' . $row, $product->created_at ? \Carbon\Carbon::parse($product->created_at)->format('d/m/Y H:i:s') : '');

            if (!empty($product->image_url) && filter_var($product->image_url, FILTER_VALIDATE_URL)) {
                $sheet->setCellValue('J' . $row, 'Voir image');
                $sheet->getCell('J' . $row)->getHyperlink()->setUrl($product->image_url);
                $sheet->getStyle('J' . $row)->applyFromArray([
                    'font' => [
                        'color' => ['rgb' => '0563C1'],
                        'underline' => \PhpOffice\PhpSpreadsheet\Style\Font::UNDERLINE_SINGLE
                    ]
                ]);
            } else {
                $sheet->setCellValue('J' . $row, 'Pas d\'image');
            }

            if ($row % 2 == 0) {
                $sheet->getStyle('A' . $row . ':J' . $row)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F9FAFB');
            }

            $row++;
        }

        $lastRow = $row - 1;

        $sheet->getStyle('A1:J' . $lastRow)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['rgb' => 'E5E7EB']
                ]
            ]
        ]);

        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(50);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->getColumnDimension('D')->setWidth(20);
        $sheet->getColumnDimension('E')->setWidth(12);
        $sheet->getColumnDimension('F')->setWidth(8);
        $sheet->getColumnDimension('G')->setWidth(25);
        $sheet->getColumnDimension('H')->setWidth(18);
        $sheet->getColumnDimension('I')->setWidth(20);
        $sheet->getColumnDimension('J')->setWidth(15);

        $sheet->setAutoFilter('A1:J' . $lastRow);
        $sheet->freezePane('A2');

        $infoRow = $lastRow + 3;
        $sheet->setCellValue('A' . $infoRow, '💡 Conseil : Utilisez les filtres dans les en-têtes pour filtrer par Vendeur, Variation ou Site web');
        $sheet->getStyle('A' . $infoRow)->applyFromArray([
            'font' => [
                'italic' => true,
                'color' => ['rgb' => '0070C0']
            ]
        ]);
        $sheet->mergeCells('A' . $infoRow . ':J' . $infoRow);

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $filename = 'produits_concurrents_' . date('Y-m-d_His') . '.xlsx';
        $temp_file = tempnam(sys_get_temp_dir(), 'excel_');
        $writer->save($temp_file);

        return response()->download($temp_file, $filename)->deleteFileAfterSend(true);
    }

    /**
     * Construit la requête de recherche pour Typesense
     */
    private function buildSearchQuery()
    {
        // Si tous les champs sont vides, on fait une recherche globale
        $searchTerms = array_filter([
            $this->vendor,
            $this->name,
            $this->type,
            $this->variation
        ]);

        // Si on n'a aucun terme de recherche, on utilise '*' pour tout récupérer
        return empty($searchTerms) ? '*' : implode(' ', $searchTerms);
    }

    /**
     * Construit les filtres Typesense
     */
    private function buildFilters()
    {
        $filters = [];

        // Filtre : exclure les variations "Standard"
        $filters[] = 'variation:!=Standard';

        // Filtre multi-sites
        if (!empty($this->site_ids) && count($this->site_ids) > 0) {
            $siteFilter = 'web_site_id:[' . implode(',', $this->site_ids) . ']';
            $filters[] = $siteFilter;
        }

        return implode(' && ', $filters);
    }

    public function with()
    {
        if (!$this->showResults) {
            return [
                'products' => collect(),
                'sites' => Site::orderBy('name')->get(),
                'paginator' => null,
            ];
        }

        $searchQuery = $this->buildSearchQuery();
        
        // Recherche avec Typesense
        $results = Product::search($searchQuery, function ($typesenseSearch, $query, $options) {
            // Appliquer les filtres
            $filters = $this->buildFilters();
            if (!empty($filters)) {
                $options['filter_by'] = $filters;
            }

            // Configuration de la recherche
            $options['per_page'] = $this->perPage;
            $options['page'] = $this->currentPage;
            
            // Tri par vendor (alphabétique)
            $options['sort_by'] = 'vendor:asc';

            return $options;
        });

        $products = $results->get();
        $totalResults = $results->total();

        $paginator = new LengthAwarePaginator(
            $products,
            $totalResults,
            $this->perPage,
            $this->currentPage,
            ['path' => request()->url()]
        );

        return [
            'products' => $products,
            'sites' => Site::orderBy('name')->get(),
            'paginator' => $paginator,
            'totalResults' => $totalResults,
            'totalPages' => ceil($totalResults / $this->perPage),
        ];
    }
}; ?>

<div>
    <style>
        .gradient-border-card {
            position: relative;
            background: white;
            border-radius: 0.5rem;
        }

        .gradient-border-card::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 0.5rem;
            padding: 2px;
            background: linear-gradient(135deg, #a855f7, #ec4899);
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            opacity: 0.6;
            transition: opacity 0.3s;
        }

        .gradient-border-card:hover::before {
            opacity: 1;
        }

        .sites-select-wrapper {
            position: relative;
        }

        .sites-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            margin-top: 0.25rem;
            max-height: 300px;
            overflow-y: auto;
            z-index: 50;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
        }

        .site-option {
            padding: 0.5rem 0.75rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: background-color 0.15s;
        }

        .site-option:hover {
            background-color: #f3f4f6;
        }

        .site-option input[type="checkbox"] {
            cursor: pointer;
        }

        .selected-sites-display {
            min-height: 2.5rem;
            padding: 0.5rem;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .selected-sites-display:hover {
            border-color: #3b82f6;
        }

        .selected-sites-display:focus-within {
            border-color: #3b82f6;
            ring: 2px;
            ring-color: rgba(59, 130, 246, 0.2);
        }

        .site-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.5rem;
            background-color: #dbeafe;
            color: #1e40af;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            margin: 0.125rem;
        }

        .site-badge button {
            margin-left: 0.25rem;
            color: #1e40af;
            font-weight: bold;
            background: none;
            border: none;
            cursor: pointer;
            padding: 0;
            line-height: 1;
        }

        .site-badge button:hover {
            color: #1e3a8a;
        }
    </style>

    <x-header title="Produits de concurrent" subtitle="Tous les prix des produits sur le concurrent" separator />

    <!-- Filtres -->
    <div class="card bg-base-100 shadow-sm mb-4">
        <div class="card-body p-3">
            <h3 class="card-title text-base font-medium mb-2">Filtres de recherche</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
                <!-- Vendeur -->
                <div
                    class="rounded-md bg-white px-3 pt-2.5 pb-1.5 outline outline-1 -outline-offset-1 outline-gray-300 focus-within:outline-2 focus-within:-outline-offset-2 focus-within:outline-indigo-600">
                    <label for="vendor" class="block text-xs font-medium text-gray-900">Vendeur</label>
                    <input type="text" wire:model="vendor" id="vendor"
                        class="block w-full border-0 p-0 text-gray-900 placeholder:text-gray-400 focus:outline-none focus:ring-0 sm:text-sm/6"
                        placeholder="Dior, Prada, etc...">
                </div>

                <!-- Nom du produit -->
                <div
                    class="rounded-md bg-white px-3 pt-2.5 pb-1.5 outline outline-1 -outline-offset-1 outline-gray-300 focus-within:outline-2 focus-within:-outline-offset-2 focus-within:outline-indigo-600">
                    <label for="name" class="block text-xs font-medium text-gray-900">Nom du produit</label>
                    <input type="text" wire:model="name" id="name"
                        class="block w-full border-0 p-0 text-gray-900 placeholder:text-gray-400 focus:outline-none focus:ring-0 sm:text-sm/6"
                        placeholder="Filtrer par nom...">
                </div>

                <!-- Type -->
                <div
                    class="rounded-md bg-white px-3 pt-2.5 pb-1.5 outline outline-1 -outline-offset-1 outline-gray-300 focus-within:outline-2 focus-within:-outline-offset-2 focus-within:outline-indigo-600">
                    <label for="type" class="block text-xs font-medium text-gray-900">Type</label>
                    <input type="text" wire:model="type" id="type"
                        class="block w-full border-0 p-0 text-gray-900 placeholder:text-gray-400 focus:outline-none focus:ring-0 sm:text-sm/6"
                        placeholder="Filtrer par type...">
                </div>

                <!-- Variation -->
                <div
                    class="rounded-md bg-white px-3 pt-2.5 pb-1.5 outline outline-1 -outline-offset-1 outline-gray-300 focus-within:outline-2 focus-within:-outline-offset-2 focus-within:outline-indigo-600">
                    <label for="variation" class="block text-xs font-medium text-gray-900">Variation</label>
                    <input type="text" wire:model="variation" id="variation"
                        class="block w-full border-0 p-0 text-gray-900 placeholder:text-gray-400 focus:outline-none focus:ring-0 sm:text-sm/6"
                        placeholder="Filtrer par variation...">
                </div>
            </div>

            <!-- Boutons d'action -->
            <div class="flex justify-end items-center mt-3 gap-2">
                @if($showResults)
                    <x-button wire:click="resetFilter" label="Réinitialiser" class="focus:outline-none text-white bg-red-700 hover:bg-red-800 focus:ring-4 focus:ring-red-300 font-medium rounded-lg text-sm px-5 py-2.5 me-2 mb-2 dark:bg-red-600 dark:hover:bg-red-700 dark:focus:ring-red-900" />
                @endif
                @if($showResults && $totalResults > 0)
                    <x-button wire:click="exportCsv" icon="o-arrow-down-tray" label="Exporter Excel ({{ $totalResults }})"
                        class="focus:outline-none text-white bg-green-700 hover:bg-green-800 focus:ring-4 focus:ring-green-300 font-medium rounded-lg text-sm px-5 py-2.5 me-2 mb-2 dark:bg-green-600 dark:hover:bg-green-700 dark:focus:ring-green-800" spinner />
                @endif
                    <x-button wire:click="applyFilter" icon="o-magnifying-glass" label="Appliquer les filtres"
                        class="text-white bg-blue-700 hover:bg-blue-800 focus:ring-4 focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5 me-2 mb-2 dark:bg-blue-600 dark:hover:bg-blue-700 focus:outline-none dark:focus:ring-blue-800"
                        spinner />
            </div>
        </div>
    </div>

    <!-- Stacked List Results -->
    @if($showResults)
        <!-- Site Filter (shown only when results are displayed) -->
        <div class="mb-4">
            <div x-data="{ open: false }" @click.away="open = false" class="sites-select-wrapper">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Filtrer par sites web
                </label>

                <div @click="open = !open" class="selected-sites-display bg-white">
                    @if(empty($site_ids))
                        <span class="text-gray-400 text-sm">Sélectionner des sites...</span>
                    @else
                        <div class="flex flex-wrap gap-1">
                            @foreach($site_ids as $siteId)
                                @php
            $site = $sites->firstWhere('id', $siteId);
                                @endphp
                                @if($site)
                                    <span class="site-badge">
                                        {{ $site->name }}
                                        <button type="button"
                                            wire:click.stop="$set('site_ids', {{ json_encode(array_values(array_diff($site_ids, [$siteId]))) }})">
                                            ×
                                        </button>
                                    </span>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>

                <div x-show="open" x-transition class="sites-dropdown">
                    <div class="p-2 border-b border-gray-200 bg-gray-50">
                        <button type="button"
                            wire:click="$set('site_ids', {{ json_encode($sites->pluck('id')->toArray()) }})"
                            class="text-xs text-blue-600 hover:text-blue-800 mr-2">
                            Tout sélectionner
                        </button>
                        <button type="button" wire:click="$set('site_ids', [])"
                            class="text-xs text-gray-600 hover:text-gray-800">
                            Tout désélectionner
                        </button>
                    </div>

                    @foreach($sites as $site)
                        <label class="site-option">
                            <input type="checkbox" value="{{ $site->id }}" wire:model.live="site_ids"
                                class="checkbox checkbox-sm checkbox-primary">
                            <span class="text-sm">{{ $site->name }}</span>
                        </label>
                    @endforeach
                </div>

                @if(!empty($site_ids))
                    <p class="mt-2 text-sm text-gray-600">
                        <span class="font-medium">{{ count($site_ids) }}</span> site(s) sélectionné(s)
                    </p>
                @endif
            </div>
        </div>

        @if($products->count() > 0)
            <div class="space-y-3">
                @foreach($products as $index => $product)
                    @php
            $rowNumber = (($currentPage - 1) * $perPage) + $index + 1;
            $site = \App\Models\Site::find($product->web_site_id);
                    @endphp
                    <div class="gradient-border-card shadow-sm overflow-hidden hover:shadow-md transition-shadow">
                        <div class="relative flex justify-between py-5 px-4 sm:px-6 bg-white">
                            <!-- Left Section: Image + Product Info -->
                            <div class="flex gap-x-4 pr-6 sm:w-1/2 sm:flex-none">
                                <!-- Product Image -->
                                @if($product->image_url)
                                    <img class="size-12 flex-none rounded-full bg-gray-50 object-cover" src="{{ $product->image_url }}"
                                        alt="{{ $product->name }}" onerror="this.src='https://via.placeholder.com/48x48?text=No+Image'">
                                @else
                                    <div class="size-12 flex-none rounded-full bg-gray-100 flex items-center justify-center">
                                        <x-icon name="o-photo" class="w-6 h-6 text-gray-400" />
                                    </div>
                                @endif

                                <!-- Product Details -->
                                <div class="min-w-0 flex-auto">
                                    <p class="text-sm/6 font-semibold text-gray-900">
                                        <a href="{{ $product->url }}" target="_blank" class="hover:underline">
                                            <span class="absolute inset-x-0 -top-px bottom-0"></span>
                                            {{ $product->name }}
                                        </a>
                                    </p>
                                    <p class="mt-1 text-xs/5 text-gray-600 font-medium">
                                        {{ $product->vendor }}
                                    </p>
                                    <p class="mt-1 flex items-center gap-2 text-xs/5 text-gray-500">
                                        @if($product->type)
                                            <span
                                                class="inline-flex items-center px-2 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-200">
                                                {{ $product->type }}
                                            </span>
                                        @endif
                                        @if($product->variation)
                                            <span
                                                class="inline-flex items-center px-2 py-0.5 rounded bg-gray-50 text-gray-700 border border-gray-200">
                                                {{ $product->variation }}
                                            </span>
                                        @endif
                                    </p>
                                </div>
                            </div>

                            <!-- Right Section: Price + Site -->
                            <div class="flex items-center justify-between gap-x-4 sm:w-1/2 sm:flex-none">
                                <div class="hidden sm:block">
                                    <!-- Price -->
                                    <p class="text-sm/6 font-bold text-green-600">
                                        {{ $product->prix_ht }} {{ $product->currency }}
                                    </p>

                                    <!-- Site and Date -->
                                    <p class="mt-1 text-xs/5 text-gray-500">
                                        @if($site)
                                            <span class="inline-flex items-center gap-1">
                                                <x-icon name="o-globe-alt" class="w-3 h-3" />
                                                {{ $site->name }}
                                            </span>
                                            <span class="mx-1">•</span>
                                        @endif
                                        <time datetime="{{ $product->updated_at }}">
                                            {{ \Carbon\Carbon::parse($product->updated_at)->translatedFormat('j M Y') }}
                                        </time>
                                    </p>
                                </div>

                                <!-- Chevron Icon -->
                                <svg class="size-5 flex-none text-gray-400" viewBox="0 0 20 20" fill="currentColor"
                                    aria-hidden="true">
                                    <path fill-rule="evenodd"
                                        d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z"
                                        clip-rule="evenodd" />
                                </svg>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="p-8 text-center bg-gray-50 rounded-lg border border-gray-200">
                <div
                    class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-yellow-50 border border-yellow-200 mb-4">
                    <x-icon name="o-magnifying-glass" class="w-8 h-8 text-yellow-600" />
                </div>
                <h3 class="text-lg font-semibold mb-2">Aucun produit trouvé</h3>
                <p class="text-gray-600 mb-4">
                    Aucun produit ne correspond à vos critères de filtrage.
                </p>
                <x-button wire:click="resetFilter" icon="o-arrow-path" label="Réinitialiser les filtres"
                    class="btn-outline border-gray-300" />
            </div>
        @endif

        <!-- Pagination -->
        @if($products->count() > 0 && $paginator)
            <nav
                class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-3 sm:px-6 rounded-lg shadow-sm mt-3">
                <!-- Previous Button -->
                <div class="-mt-px flex w-0 flex-1">
                    <button wire:click="previousPage"
                        class="inline-flex items-center border-t-2 border-transparent pt-4 pr-1 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700 {{ $currentPage <= 1 ? 'opacity-50 cursor-not-allowed' : '' }}"
                        {{ $currentPage <= 1 ? 'disabled' : '' }}>
                        <svg class="mr-3 size-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd"
                                d="M18 10a.75.75 0 0 1-.75.75H4.66l2.1 1.95a.75.75 0 1 1-1.02 1.1l-3.5-3.25a.75.75 0 0 1 0-1.1l3.5-3.25a.75.75 0 1 1 1.02 1.1l-2.1 1.95h12.59A.75.75 0 0 1 18 10Z"
                                clip-rule="evenodd" />
                        </svg>
                        Previous
                    </button>
                </div>

                <!-- Page Numbers -->
                <div class="hidden md:-mt-px md:flex">
                    @php
        $startPage = max(1, $currentPage - 2);
        $endPage = min($totalPages, $currentPage + 2);
                    @endphp

                    @if($startPage > 1)
                        <button wire:click="goToPage(1)"
                            class="inline-flex items-center border-t-2 {{ $currentPage == 1 ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }} px-4 pt-4 text-sm font-medium">
                            1
                        </button>
                        @if($startPage > 2)
                            <span
                                class="inline-flex items-center border-t-2 border-transparent px-4 pt-4 text-sm font-medium text-gray-500">...</span>
                        @endif
                    @endif

                    @for($page = $startPage; $page <= $endPage; $page++)
                        <button wire:click="goToPage({{ $page }})"
                            class="inline-flex items-center border-t-2 {{ $currentPage == $page ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }} px-4 pt-4 text-sm font-medium"
                            @if($currentPage == $page) aria-current="page" @endif>
                            {{ $page }}
                        </button>
                    @endfor

                    @if($endPage < $totalPages)
                        @if($endPage < $totalPages - 1)
                            <span
                                class="inline-flex items-center border-t-2 border-transparent px-4 pt-4 text-sm font-medium text-gray-500">...</span>
                        @endif
                        <button wire:click="goToPage({{ $totalPages }})"
                            class="inline-flex items-center border-t-2 {{ $currentPage == $totalPages ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }} px-4 pt-4 text-sm font-medium">
                            {{ $totalPages }}
                        </button>
                    @endif
                </div>

                <!-- Next Button -->
                <div class="-mt-px flex w-0 flex-1 justify-end">
                    <button wire:click="nextPage"
                        class="inline-flex items-center border-t-2 border-transparent pt-4 pl-1 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700 {{ $currentPage >= $totalPages ? 'opacity-50 cursor-not-allowed' : '' }}"
                        {{ $currentPage >= $totalPages ? 'disabled' : '' }}>
                        Next
                        <svg class="ml-3 size-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd"
                                d="M2 10a.75.75 0 0 1 .75-.75h12.59l-2.1-1.95a.75.75 0 1 1 1.02-1.1l3.5 3.25a.75.75 0 0 1 0 1.1l-3.5 3.25a.75.75 0 1 1-1.02-1.1l2.1-1.95H2.75A.75.75 0 0 1 2 10Z"
                                clip-rule="evenodd" />
                        </svg>
                    </button>
                </div>
            </nav>

            <!-- Results info and per page selector -->
            <div class="mt-4 flex flex-col sm:flex-row justify-between items-center gap-4 px-4 sm:px-6">
                <div class="text-sm text-gray-600">
                    Affichage de
                    <span class="font-semibold">{{ (($currentPage - 1) * $perPage) + 1 }}</span>
                    à
                    <span class="font-semibold">{{ min($currentPage * $perPage, $totalResults) }}</span>
                    sur
                    <span class="font-semibold">{{ $totalResults }}</span>
                    produit(s)
                </div>

                <div class="flex items-center gap-2">
                    <span class="text-sm text-gray-600">Résultats par page:</span>
                    <select class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                        wire:model.live="perPage">
                        <option value="20">20</option>
                        <option value="50" selected>50</option>
                        <option value="100">100</option>
                        <option value="200">200</option>
                    </select>
                </div>
            </div>
        @endif
    @else
        <div class="text-center py-12">
            <div
                class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-blue-50 border border-blue-200 mb-4">
                <x-icon name="o-funnel" class="w-10 h-10 text-blue-600" />
            </div>
            <h3 class="text-xl font-semibold mb-2 text-gray-800">Aucun filtre appliqué</h3>
            <p class="text-gray-600 max-w-md mx-auto mb-6">
                Remplissez les champs de filtrage et cliquez sur "Appliquer les filtres" pour voir les résultats.
                <br>
                <span class="text-sm text-gray-500">Seuls les produits les plus récents pour chaque combinaison unique sont
                    affichés.</span>
            </p>
            <div class="stats shadow border border-gray-300 bg-white">
                <div class="stat border-r border-gray-300">
                    <div class="stat-figure text-blue-600">
                        <x-icon name="o-cube" class="w-8 h-8" />
                    </div>
                    <div class="stat-title text-gray-600">Produits uniques</div>
                    <div class="stat-value text-blue-600 text-2xl">?</div>
                    <div class="stat-desc text-gray-500">Cliquez pour voir</div>
                </div>

                <div class="stat border-r border-gray-300">
                    <div class="stat-figure text-gray-600">
                        <x-icon name="o-building-storefront" class="w-8 h-8" />
                    </div>
                    <div class="stat-title text-gray-600">Vendeurs</div>
                    <div class="stat-value text-gray-700 text-2xl">?</div>
                    <div class="stat-desc text-gray-500">En attente de filtre</div>
                </div>

                <div class="stat">
                    <div class="stat-figure text-green-600">
                        <x-icon name="o-globe-alt" class="w-8 h-8" />
                    </div>
                    <div class="stat-title text-gray-600">Sites web</div>
                    <div class="stat-value text-green-600 text-2xl">{{ \App\Models\Site::count() }}</div>
                    <div class="stat-desc text-gray-500">Sites disponibles</div>
                </div>
            </div>
        </div>
    @endif
</div>