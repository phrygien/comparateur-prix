<?php

use Livewire\Volt\Component;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;

new class extends Component {
    public $vendor = '';
    public $name = '';
    public $type = '';
    public $variation = '';
    public $site_ids = []; // Changé de site_id à site_ids (array)
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
        // Récupérer tous les résultats filtrés (sans pagination)
        $query = DB::table('last_price_scraped_product')
            ->select('*');
        
        $query->where('variation', '!=', 'Standard');

        if (!empty($this->vendor)) {
            $query->where('vendor', 'like', '%' . $this->vendor . '%');
        }
        
        if (!empty($this->name)) {
            $query->where('name', 'like', '%' . $this->name . '%');
        }
        
        if (!empty($this->type)) {
            $query->where('type', 'like', '%' . $this->type . '%');
        }
        
        if (!empty($this->variation)) {
            $query->where('variation', 'like', '%' . $this->variation . '%');
        }
        
        if (!empty($this->site_ids) && count($this->site_ids) > 0) {
            $query->whereIn('web_site_id', $this->site_ids);
        }
        
        $products = $query->orderBy('vendor', 'asc')->get();
        
        // Créer un fichier Excel avec PhpSpreadsheet
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Définir le titre de la feuille
        $sheet->setTitle('Produits Concurrents');
        
        // En-têtes
        $headers = ['Vendeur', 'Nom du produit', 'Type', 'Variation', 'Prix HT', 'Devise', 'Site web', 'URL', 'Date de scraping', 'Image URL'];
        $sheet->fromArray($headers, null, 'A1');
        
        // Style de l'en-tête - Fond bleu avec texte blanc
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
        
        // Augmenter la hauteur de la ligne d'en-tête
        $sheet->getRowDimension(1)->setRowHeight(25);
        
        // Données
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
            
            // URL cliquable
            if ($product->url) {
                $sheet->setCellValue('H' . $row, $product->url);
                $sheet->getCell('H' . $row)->getHyperlink()->setUrl($product->url);
                $sheet->getStyle('H' . $row)->getFont()->getColor()->setRGB('0563C1');
                $sheet->getStyle('H' . $row)->getFont()->setUnderline(true);
            }
            
            $sheet->setCellValue('I' . $row, $product->created_at ? \Carbon\Carbon::parse($product->created_at)->format('d/m/Y H:i:s') : '');
            
            // Image URL cliquable
            if ($product->image_url) {
                $sheet->setCellValue('J' . $row, $product->image_url);
                $sheet->getCell('J' . $row)->getHyperlink()->setUrl($product->image_url);
                $sheet->getStyle('J' . $row)->getFont()->getColor()->setRGB('0563C1');
                $sheet->getStyle('J' . $row)->getFont()->setUnderline(true);
            }
            
            // Alterner les couleurs de lignes
            if ($row % 2 == 0) {
                $sheet->getStyle('A' . $row . ':J' . $row)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F9FAFB');
            }
            
            $row++;
        }
        
        // Bordures pour toutes les cellules de données
        $lastRow = $row - 1;
        $sheet->getStyle('A1:J' . $lastRow)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['rgb' => 'E5E7EB']
                ]
            ]
        ]);
        
        // Largeurs de colonnes
        $sheet->getColumnDimension('A')->setWidth(20);  // Vendeur
        $sheet->getColumnDimension('B')->setWidth(50);  // Nom
        $sheet->getColumnDimension('C')->setWidth(20);  // Type
        $sheet->getColumnDimension('D')->setWidth(20);  // Variation
        $sheet->getColumnDimension('E')->setWidth(12);  // Prix
        $sheet->getColumnDimension('F')->setWidth(8);   // Devise
        $sheet->getColumnDimension('G')->setWidth(25);  // Site
        $sheet->getColumnDimension('H')->setWidth(60);  // URL
        $sheet->getColumnDimension('I')->setWidth(20);  // Date
        $sheet->getColumnDimension('J')->setWidth(60);  // Image URL
        
        // Appliquer l'auto-filtre sur les en-têtes
        $sheet->setAutoFilter('A1:J1');
        
        // Figer la première ligne (en-têtes)
        $sheet->freezePane('A2');
        
        // Créer le writer Excel
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        
        // Nom du fichier
        $filename = 'produits_concurrents_' . date('Y-m-d_His') . '.xlsx';
        
        // Créer un fichier temporaire
        $temp_file = tempnam(sys_get_temp_dir(), 'excel_');
        $writer->save($temp_file);
        
        // Retourner le fichier en téléchargement
        return response()->download($temp_file, $filename)->deleteFileAfterSend(true);
    }
    
    private function escapeCsv($value)
    {
        // Méthode gardée pour compatibilité mais non utilisée
        if (strpos($value, ';') !== false || strpos($value, '"') !== false || strpos($value, "\n") !== false) {
            return '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
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
        
        $query = DB::table('last_price_scraped_product')
            ->select('*');
        
        $query->where('variation', '!=', 'Standard');

        if (!empty($this->vendor)) {
            $query->where('vendor', 'like', '%' . $this->vendor . '%');
        }
        
        if (!empty($this->name)) {
            $query->where('name', 'like', '%' . $this->name . '%');
        }
        
        if (!empty($this->type)) {
            $query->where('type', 'like', '%' . $this->type . '%');
        }
        
        if (!empty($this->variation)) {
            $query->where('variation', 'like', '%' . $this->variation . '%');
        }
        
        // Filtre multi-sites
        if (!empty($this->site_ids) && count($this->site_ids) > 0) {
            $query->whereIn('web_site_id', $this->site_ids);
        }
        
        $totalResults = $query->count();
        
        $products = $query->orderBy('vendor', 'asc')
            ->skip(($this->currentPage - 1) * $this->perPage)
            ->take($this->perPage)
            ->get();
        
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
        .excel-table {
            border-spacing: 0;
        }
        
        .excel-table th, .excel-table td {
            border-right: 1px solid #e5e7eb;
            border-bottom: 1px solid #e5e7eb;
            padding: 8px 12px;
        }
        
        .excel-table th:last-child, .excel-table td:last-child {
            border-right: none;
        }
        
        .excel-table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .excel-table tbody tr:hover {
            background-color: #f9fafb;
        }
        
        .excel-truncate {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .excel-badge-cell {
            max-width: 200px;
        }

        /* Styles pour le select multi-sites */
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
    
    <x-header title="Produits de concurent" subtitle="Tous les prix des produits sur le concurent" separator>
        @if($showResults && $products->count() > 0)
            <x-slot:actions>
                <x-button 
                    wire:click="exportCsv" 
                    icon="o-arrow-down-tray"
                    label="Exporter CSV"
                    class="btn-success btn-sm"
                    spinner
                />
            </x-slot:actions>
        @endif
    </x-header>
    
    <!-- Filtres -->
    <div class="card bg-base-100 shadow-sm mb-4">
        <div class="card-body p-3">
            <h3 class="card-title text-base font-medium mb-2">Filtres de recherche</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-2">
                <!-- Vendeur -->
                <div>
                    <x-input 
                        wire:model="vendor"
                        label="Vendeur" 
                        placeholder="Filtrer par vendeur..."
                        icon="o-building-storefront"
                        class="input-sm"
                        hint="Exemple: Dior, Prada, etc..."
                        input-class="border border-gray-300 rounded-lg focus:border-primary focus:ring-2 focus:ring-primary/20 transition-colors"
                    />
                </div>
                
                <!-- Nom du produit -->
                <div>
                    <x-input 
                        wire:model="name"
                        label="Nom du produit" 
                        placeholder="Filtrer par nom..."
                        icon="o-tag"
                        class="input-sm"
                        input-class="border border-gray-300 rounded-lg focus:border-primary focus:ring-2 focus:ring-primary/20 transition-colors"
                    />
                </div>
                
                <!-- Type -->
                <div>
                    <x-input 
                        wire:model="type"
                        label="Type" 
                        placeholder="Filtrer par type..."
                        icon="o-cube"
                        class="input-sm"
                        input-class="border border-gray-300 rounded-lg focus:border-primary focus:ring-2 focus:ring-primary/20 transition-colors"
                    />
                </div>
                
                <!-- Variation -->
                <div>
                    <x-input 
                        wire:model="variation"
                        label="Variation" 
                        placeholder="Filtrer par variation..."
                        icon="o-arrows-pointing-out"
                        class="input-sm"
                        input-class="border border-gray-300 rounded-lg focus:border-primary focus:ring-2 focus:ring-primary/20 transition-colors"
                    />
                </div>
                
                <!-- Sites web (Multi-select) -->
                <div x-data="{ open: false }" @click.away="open = false" class="sites-select-wrapper">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <x-icon name="o-globe-alt" class="w-4 h-4 inline" />
                        Sites web
                    </label>
                    
                    <div @click="open = !open" class="selected-sites-display">
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
                                            <button 
                                                type="button"
                                                wire:click.stop="$set('site_ids', {{ json_encode(array_values(array_diff($site_ids, [$siteId]))) }})"
                                            >
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
                            <button 
                                type="button"
                                wire:click="$set('site_ids', {{ json_encode($sites->pluck('id')->toArray()) }})"
                                class="text-xs text-blue-600 hover:text-blue-800 mr-2"
                            >
                                Tout sélectionner
                            </button>
                            <button 
                                type="button"
                                wire:click="$set('site_ids', [])"
                                class="text-xs text-gray-600 hover:text-gray-800"
                            >
                                Tout désélectionner
                            </button>
                        </div>
                        
                        @foreach($sites as $site)
                            <label class="site-option">
                                <input 
                                    type="checkbox" 
                                    value="{{ $site->id }}"
                                    wire:model.live="site_ids"
                                    class="checkbox checkbox-sm checkbox-primary"
                                >
                                <span class="text-sm">{{ $site->name }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>
            
            <!-- Boutons d'action -->
            <div class="flex justify-between items-center mt-3">
                <div class="text-sm text-gray-600">
                    @if(!empty($site_ids))
                        <span class="font-medium">{{ count($site_ids) }}</span> site(s) sélectionné(s)
                    @endif
                </div>
                <div class="flex gap-2">
                    @if($showResults)
                        <x-button 
                            wire:click="resetFilter" 
                            icon="o-x-mark"
                            label="Réinitialiser"
                            class="btn-ghost btn-sm"
                        />
                    @endif
                    <x-button 
                        wire:click="applyFilter" 
                        icon="o-funnel"
                        label="Appliquer les filtres"
                        class="btn-primary btn-sm"
                        spinner
                    />
                    @if($showResults && $totalResults > 0)
                        <x-button 
                            wire:click="exportCsv" 
                            icon="o-arrow-down-tray"
                            label="Exporter CSV ({{ $totalResults }})"
                            class="btn-success btn-sm"
                            spinner
                        />
                    @endif
                </div>
            </div>
        </div>
    </div>
    
    <!-- Reste du code identique... -->
    @if($showResults)
        <div class="overflow-x-auto rounded-none border border-gray-300 bg-white shadow-sm mb-6">
            @if($products->count() > 0)
                <table class="excel-table table-auto border-collapse w-full">
                    <thead>
                        <tr class="bg-gray-100 border-b border-gray-300">
                            <th class="font-bold text-gray-700 text-sm px-3 py-2 border-r border-gray-300 text-center w-16 hidden sm:table-cell">Image</th>
                            <th class="font-bold text-gray-700 text-sm px-3 py-2 border-r border-gray-300 min-w-32 lg:min-w-40">Vendeur</th>
                            <th class="font-bold text-gray-700 text-sm px-3 py-2 border-r border-gray-300 min-w-48 lg:min-w-56 xl:min-w-64">Nom</th>
                            <th class="font-bold text-gray-700 text-sm px-3 py-2 border-r border-gray-300 min-w-32 max-w-48 excel-truncate hidden md:table-cell">Type</th>
                            <th class="font-bold text-gray-700 text-sm px-3 py-2 border-r border-gray-300 min-w-32 max-w-48 excel-truncate hidden lg:table-cell">Variation</th>
                            <th class="font-bold text-gray-700 text-sm px-3 py-2 border-r border-gray-300 text-center w-32">Prix HT</th>
                            <th class="font-bold text-gray-700 text-sm px-3 py-2 border-r border-gray-300 min-w-32 hidden xl:table-cell">Site</th>
                            <th class="font-bold text-gray-700 text-sm px-3 py-2 border-r border-gray-300 text-center w-32 hidden lg:table-cell">Date</th>
                            <th class="font-bold text-gray-700 text-sm px-3 py-2 text-center w-24">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($products as $index => $product)
                            @php
                                $rowNumber = (($currentPage - 1) * $perPage) + $index + 1;
                                $site = \App\Models\Site::find($product->web_site_id);
                            @endphp
                            <tr class="border-b border-gray-200 hover:bg-gray-50 even:bg-gray-50/50">
                                <td class="px-3 py-2 border-r border-gray-300 text-center hidden sm:table-cell">
                                    @if($product->image_url)
                                        <div class="avatar mx-auto">
                                            <div class="mask mask-squircle w-10 h-10">
                                                <img src="{{ $product->image_url }}" 
                                                     alt="{{ $product->name }}"
                                                     class="object-cover"
                                                     onerror="this.src='https://via.placeholder.com/40x40?text=No+Image'">
                                            </div>
                                        </div>
                                    @else
                                        <div class="text-gray-300 mx-auto">
                                            <x-icon name="o-photo" class="w-5 h-5" />
                                        </div>
                                    @endif
                                </td>
                                
                                <td class="text-gray-800 text-sm px-3 py-2 border-r border-gray-300 excel-truncate" 
                                    title="{{ $product->vendor }}">
                                    <div class="block lg:hidden font-semibold">Marque:</div>
                                    {{ $product->vendor }}
                                </td>
                                
                                <td class="text-gray-800 text-sm px-3 py-2 border-r border-gray-300 excel-truncate" 
                                    title="{{ $product->name }}">
                                    <div class="block lg:hidden font-semibold">Produit:</div>
                                    {{ $product->name }}
                                </td>
                                
                                <td class="text-gray-800 text-sm px-3 py-2 border-r border-gray-300 hidden md:table-cell" 
                                    title="{{ $product->type ?? 'N/A' }}">
                                    <div class="inline-flex items-center px-2 py-1 rounded bg-blue-50 text-blue-700 border border-blue-200 text-xs excel-truncate">
                                        {{ $product->type ?? 'N/A' }}
                                    </div>
                                </td>
                                
                                <td class="text-gray-800 text-sm px-3 py-2 border-r border-gray-300 hidden lg:table-cell" 
                                    title="{{ $product->variation ?? 'N/A' }}">
                                    <div class="inline-flex items-center px-2 py-1 rounded bg-gray-50 text-gray-700 border border-gray-200 text-xs excel-truncate">
                                        {{ $product->variation ?? 'N/A' }}
                                    </div>
                                </td>
                                
                                <td class="text-gray-800 text-sm px-3 py-2 border-r border-gray-300 text-center font-semibold text-green-600">
                                    <div class="block lg:hidden font-semibold text-gray-600">Prix:</div>
                                    {{ $product->prix_ht }} {{ $product->currency }}
                                </td>
                                
                                <td class="text-gray-800 text-sm px-3 py-2 border-r border-gray-300 hidden xl:table-cell">
                                    @if($site)
                                        <div class="flex items-center gap-1 excel-truncate" title="{{ $site->name }}">
                                            <x-icon name="o-globe-alt" class="w-3 h-3 text-gray-400 flex-shrink-0" />
                                            <span class="excel-truncate">{{ $site->name }}</span>
                                        </div>
                                    @else
                                        <span class="text-gray-400 italic">N/A</span>
                                    @endif
                                </td>
                                
                                <td class="text-gray-600 text-sm px-3 py-2 border-r border-gray-300 hidden lg:table-cell">
                                    <div class="whitespace-nowrap">
                                        {{ \Carbon\Carbon::parse($product->created_at)->format('d/m/Y') }}
                                    </div>
                                    <div class="text-xs text-gray-400">
                                        {{ \Carbon\Carbon::parse($product->created_at)->format('H:i') }}
                                    </div>
                                </td>
                                
                                <td class="px-3 py-2 text-center">
                                    <div class="flex justify-center gap-1">
                                        @if($product->url)
                                            <a href="{{ $product->url }}" 
                                               target="_blank" 
                                               class="btn btn-xs btn-outline btn-square border-gray-300 hover:bg-blue-50 hover:border-blue-300"
                                               title="Voir sur le site">
                                                <x-icon name="o-arrow-top-right-on-square" class="w-3 h-3" />
                                            </a>
                                        @endif
                                        <button class="btn btn-xs btn-ghost btn-square border border-gray-300 hover:bg-gray-100" title="Voir détails">
                                            <x-icon name="o-eye" class="w-3 h-3" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="p-8 text-center">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-yellow-50 border border-yellow-200 mb-4">
                        <x-icon name="o-magnifying-glass" class="w-8 h-8 text-yellow-600" />
                    </div>
                    <h3 class="text-lg font-semibold mb-2">Aucun produit trouvé</h3>
                    <p class="text-gray-600 mb-4">
                        Aucun produit ne correspond à vos critères de filtrage.
                    </p>
                    <x-button 
                        wire:click="resetFilter" 
                        icon="o-arrow-path"
                        label="Réinitialiser les filtres"
                        class="btn-outline border-gray-300"
                    />
                </div>
            @endif
        </div>
        
        <!-- Pagination -->
        @if($products->count() > 0 && $paginator)
            <div class="card bg-white border border-gray-300 shadow-sm">
                <div class="card-body">
                    <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                        <div class="text-sm text-gray-600">
                            Affichage de 
                            <span class="font-semibold">{{ (($currentPage - 1) * $perPage) + 1 }}</span>
                            à 
                            <span class="font-semibold">{{ min($currentPage * $perPage, $totalResults) }}</span>
                            sur 
                            <span class="font-semibold">{{ $totalResults }}</span>
                            produit(s) - Page 
                            <span class="font-semibold">{{ $currentPage }}</span>
                            sur 
                            <span class="font-semibold">{{ $totalPages }}</span>
                        </div>
                        
                        <div class="join border border-gray-300 rounded">
                            <button 
                                wire:click="previousPage" 
                                class="join-item btn btn-sm bg-white border-gray-300 hover:bg-gray-50 {{ $currentPage <= 1 ? 'btn-disabled opacity-50' : '' }}"
                                {{ $currentPage <= 1 ? 'disabled' : '' }}
                            >
                                <x-icon name="o-chevron-left" class="w-3 h-3" />
                            </button>
                            
                            @php
                                $startPage = max(1, $currentPage - 2);
                                $endPage = min($totalPages, $currentPage + 2);
                            @endphp
                            
                            @if($startPage > 1)
                                <button 
                                    wire:click="goToPage(1)" 
                                    class="join-item btn btn-sm bg-white border-gray-300 hover:bg-gray-50 {{ $currentPage == 1 ? 'bg-blue-50 text-blue-600 border-blue-300' : '' }}"
                                >
                                    1
                                </button>
                                @if($startPage > 2)
                                    <button class="join-item btn btn-sm bg-white border-gray-300 btn-disabled" disabled>...</button>
                                @endif
                            @endif
                            
                            @for($page = $startPage; $page <= $endPage; $page++)
                                <button 
                                    wire:click="goToPage({{ $page }})" 
                                    class="join-item btn btn-sm bg-white border-gray-300 hover:bg-gray-50 {{ $currentPage == $page ? 'bg-blue-50 text-blue-600 border-blue-300' : '' }}"
                                >
                                    {{ $page }}
                                </button>
                            @endfor
                            
                            @if($endPage < $totalPages)
                                @if($endPage < $totalPages - 1)
                                    <button class="join-item btn btn-sm bg-white border-gray-300 btn-disabled" disabled>...</button>
                                @endif
                                <button 
                                    wire:click="goToPage({{ $totalPages }})" 
                                    class="join-item btn btn-sm bg-white border-gray-300 hover:bg-gray-50 {{ $currentPage == $totalPages ? 'bg-blue-50 text-blue-600 border-blue-300' : '' }}"
                                >
                                    {{ $totalPages }}
                                </button>
                            @endif
                            
                            <button 
                                wire:click="nextPage" 
                                class="join-item btn btn-sm bg-white border-gray-300 hover:bg-gray-50 {{ $currentPage >= $totalPages ? 'btn-disabled opacity-50' : '' }}"
                                {{ $currentPage >= $totalPages ? 'disabled' : '' }}
                            >
                                <x-icon name="o-chevron-right" class="w-3 h-3" />
                            </button>
                        </div>
                        
                        <div class="flex items-center gap-2">
                            <span class="text-sm text-gray-600">Résultats par page:</span>
                            <select class="select select-bordered select-sm border-gray-300 bg-white" wire:model.live="perPage">
                                <option value="20">20</option>
                                <option value="50" selected>50</option>
                                <option value="100">100</option>
                                <option value="200">200</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @else
        <div class="card bg-white border border-gray-300 shadow-sm">
            <div class="card-body text-center py-12">
                <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-blue-50 border border-blue-200 mb-4">
                    <x-icon name="o-funnel" class="w-10 h-10 text-blue-600" />
                </div>
                <h3 class="text-xl font-semibold mb-2 text-gray-800">Aucun filtre appliqué</h3>
                <p class="text-gray-600 max-w-md mx-auto mb-6">
                    Remplissez les champs de filtrage et cliquez sur "Appliquer les filtres" pour voir les résultats.
                    <br>
                    <span class="text-sm text-gray-500">Seuls les produits les plus récents pour chaque combinaison unique sont affichés.</span>
                </p>
                <div class="stats shadow border border-gray-300">
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
        </div>
    @endif
</div>