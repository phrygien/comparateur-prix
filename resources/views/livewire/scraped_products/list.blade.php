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
    public $site_ids = [];
    public $showResults = false;
    public $currentPage = 1;
    public $perPage = 50;
    public $exportProgress = 0;
    public $isExporting = false;
    public $exportTotal = 0;
    public $exportCurrent = 0;
    
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
        // Initialiser l'état d'export
        $this->isExporting = true;
        $this->exportProgress = 5; // 5% pour initialisation
        $this->exportCurrent = 0;
        
        // Étape 1: Compter le total des produits
        $query = DB::table('last_price_scraped_product')
            ->select('*')
            ->where('variation', '!=', 'Standard');
        
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
        
        $totalProducts = $query->count();
        $this->exportTotal = $totalProducts;
        
        if ($totalProducts === 0) {
            $this->isExporting = false;
            session()->flash('error', 'Aucun produit à exporter.');
            return;
        }
        
        $this->exportProgress = 10; // 10% pour comptage terminé
        
        // Augmenter les limites pour les exports volumineux
        set_time_limit(300);
        ini_set('memory_limit', '512M');
        
        // Créer le fichier Excel
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Produits Concurrents');
        
        // En-têtes
        $headers = ['Vendeur', 'Nom du produit', 'Type', 'Variation', 'Prix HT', 'Devise', 'Site web', 'URL Produit', 'Date de scraping', 'Image'];
        $sheet->fromArray($headers, null, 'A1');
        
        // Style de l'en-tête
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
        
        $this->exportProgress = 15; // 15% pour en-têtes créés
        
        // Récupérer les produits par lots
        $batchSize = 1000;
        $offset = 0;
        $row = 2;
        $processed = 0;
        
        do {
            // Récupérer un lot de produits
            $batchQuery = DB::table('last_price_scraped_product')
                ->select('*')
                ->where('variation', '!=', 'Standard');
            
            if (!empty($this->vendor)) {
                $batchQuery->where('vendor', 'like', '%' . $this->vendor . '%');
            }
            
            if (!empty($this->name)) {
                $batchQuery->where('name', 'like', '%' . $this->name . '%');
            }
            
            if (!empty($this->type)) {
                $batchQuery->where('type', 'like', '%' . $this->type . '%');
            }
            
            if (!empty($this->variation)) {
                $batchQuery->where('variation', 'like', '%' . $this->variation . '%');
            }
            
            if (!empty($this->site_ids) && count($this->site_ids) > 0) {
                $batchQuery->whereIn('web_site_id', $this->site_ids);
            }
            
            $products = $batchQuery->orderBy('vendor', 'asc')
                ->offset($offset)
                ->limit($batchSize)
                ->get();
            
            foreach ($products as $product) {
                $site = Site::find($product->web_site_id);
                
                $sheet->setCellValue('A' . $row, $product->vendor ?? '');
                $sheet->setCellValue('B' . $row, $product->name ?? '');
                $sheet->setCellValue('C' . $row, $product->type ?? '');
                $sheet->setCellValue('D' . $row, $product->variation ?? '');
                $sheet->setCellValue('E' . $row, $product->prix_ht ?? '');
                $sheet->setCellValue('F' . $row, $product->currency ?? '');
                $sheet->setCellValue('G' . $row, $site ? $site->name : '');
                
                // URL Produit
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
                
                // Image URL
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
                
                // Alterner les couleurs
                if ($row % 2 == 0) {
                    $sheet->getStyle('A' . $row . ':J' . $row)->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setRGB('F9FAFB');
                }
                
                $row++;
                $processed++;
                $this->exportCurrent = $processed;
                
                // Mettre à jour la progression
                if ($processed % 100 === 0 || $processed === $totalProducts) {
                    $this->exportProgress = min(95, 15 + round(($processed / $totalProducts) * 75));
                }
            }
            
            $offset += $batchSize;
            
        } while ($products->count() === $batchSize);
        
        $this->exportProgress = 95; // 95% pour données ajoutées
        
        // Appliquer le style final
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
        
        // Note d'information
        $infoRow = $lastRow + 3;
        $sheet->setCellValue('A' . $infoRow, '💡 Conseil : Utilisez les filtres dans les en-têtes pour filtrer par Vendeur, Variation ou Site web');
        $sheet->getStyle('A' . $infoRow)->applyFromArray([
            'font' => [
                'italic' => true,
                'color' => ['rgb' => '0070C0']
            ]
        ]);
        $sheet->mergeCells('A' . $infoRow . ':J' . $infoRow);
        
        $this->exportProgress = 98; // 98% pour styles appliqués
        
        // Sauvegarder le fichier
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $filename = 'produits_concurrents_' . date('Y-m-d_His') . '.xlsx';
        $temp_file = tempnam(sys_get_temp_dir(), 'excel_');
        $writer->save($temp_file);
        
        $this->exportProgress = 100; // 100% pour fichier créé
        
        // Petite pause pour montrer le 100%
        usleep(500000); // 0.5 seconde
        
        // Télécharger le fichier
        $this->isExporting = false;
        
        return response()->download($temp_file, $filename)->deleteFileAfterSend(true);
    }
    
    public function cancelExport()
    {
        $this->isExporting = false;
        $this->exportProgress = 0;
        $this->exportCurrent = 0;
        $this->exportTotal = 0;
    }
    
    private function escapeCsv($value)
    {
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

        /* Styles pour la barre de progression */
        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .animate-spin {
            animation: spin 1s linear infinite;
        }

        .animate-pulse {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }

        .progress-bar {
            transition: width 0.3s ease-in-out;
        }
    </style>
    
    <x-header title="Produits de concurent" subtitle="Tous les prix des produits sur le concurent" separator>
        {{-- @if($showResults && $products->count() > 0)
            <x-slot:actions>
                <x-button 
                    wire:click="exportCsv" 
                    icon="o-arrow-down-tray"
                    label="Exporter CSV"
                    class="btn-success btn-sm"
                    spinner
                />
            </x-slot:actions>
        @endif --}}
    </x-header>

    <!-- Modal de progression d'export -->
    @if($isExporting)
        <div class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
            <div class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-md">
                <div class="flex flex-col items-center">
                    <!-- Icône de chargement -->
                    <div class="relative mb-6">
                        <div class="w-20 h-20">
                            <div class="animate-spin rounded-full h-20 w-20 border-t-4 border-b-4 border-blue-500"></div>
                        </div>
                        <div class="absolute inset-0 flex items-center justify-center">
                            <span class="text-blue-600 font-bold text-lg">{{ $exportProgress }}%</span>
                        </div>
                    </div>
                    
                    <!-- Titre -->
                    <h3 class="text-xl font-semibold mb-2 text-gray-800">Export en cours...</h3>
                    <p class="text-gray-600 text-center mb-6">
                        Préparation du fichier Excel. Veuillez patienter...
                    </p>
                    
                    <!-- Informations de progression -->
                    <div class="w-full mb-4">
                        <div class="flex justify-between text-sm text-gray-600 mb-2">
                            <span class="font-medium">{{ $exportCurrent }} / {{ $exportTotal }} produits</span>
                            <span class="font-semibold">{{ $exportProgress }}%</span>
                        </div>
                        
                        <!-- Barre de progression -->
                        <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                            <div 
                                class="bg-gradient-to-r from-blue-500 to-blue-600 h-3 rounded-full progress-bar"
                                style="width: {{ $exportProgress }}%"
                            ></div>
                        </div>
                    </div>
                    
                    <!-- Indicateur de statut -->
                    <div class="mt-4">
                        <div class="flex items-center justify-center text-sm text-gray-600">
                            <div class="animate-pulse flex items-center space-x-2">
                                <div class="w-2 h-2 bg-blue-500 rounded-full"></div>
                                <span class="font-medium">
                                    @if($exportProgress < 20)
                                        Initialisation de l'export...
                                    @elseif($exportProgress < 40)
                                        Récupération des données...
                                    @elseif($exportProgress < 60)
                                        Traitement des produits...
                                    @elseif($exportProgress < 80)
                                        Formatage des données...
                                    @elseif($exportProgress < 95)
                                        Génération du fichier Excel...
                                    @else
                                        Finalisation du téléchargement...
                                    @endif
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Temps estimé (optionnel) -->
                    <div class="mt-4 text-xs text-gray-500 text-center">
                        <div class="flex items-center justify-center space-x-1">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span>Temps estimé: {{ ceil($exportTotal / 500) }} secondes</span>
                        </div>
                    </div>
                    
                    <!-- Bouton annuler -->
                    <button 
                        wire:click="cancelExport"
                        class="mt-8 px-6 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg font-medium transition-all duration-200 hover:shadow-sm"
                    >
                        <div class="flex items-center space-x-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                            <span>Annuler l'export</span>
                        </div>
                    </button>
                </div>
            </div>
        </div>
    @endif
    
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
                            label="Exporter Excel ({{ $totalResults }})"
                            class="btn-success btn-sm"
                            spinner
                            :disabled="$isExporting"
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