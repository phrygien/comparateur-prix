<?php

use Livewire\Volt\Component;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Session;

new class extends Component {
    public $vendor = '';
    public $name = '';
    public $type = '';
    public $variation = '';
    public $site_ids = [];
    public $showResults = false;
    public $currentPage = 1;
    public $perPage = 50;
    
    // Variables pour l'export
    public $exportProgress = 0;
    public $isExporting = false;
    public $exportTotal = 0;
    public $exportCurrent = 0;
    public $exportStatus = '';
    public $exportFileName = '';
    public $exportError = '';
    
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
        try {
            // Initialiser l'état d'export via Livewire
            $this->isExporting = true;
            $this->exportProgress = 0;
            $this->exportCurrent = 0;
            $this->exportTotal = 0;
            $this->exportStatus = 'initialisation';
            $this->exportError = '';
            $this->exportFileName = 'produits_concurrents_' . date('Y-m-d_His') . '.xlsx';
            
            // Dispatcher un événement pour Alpine.js
            $this->dispatch('export-started', [
                'total' => 0,
                'fileName' => $this->exportFileName
            ]);
            
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
            
            if ($totalProducts === 0) {
                $this->isExporting = false;
                $this->exportError = 'Aucun produit à exporter avec les filtres actuels.';
                $this->dispatch('export-error', ['message' => $this->exportError]);
                return;
            }
            
            $this->exportTotal = $totalProducts;
            $this->exportStatus = 'preparation';
            $this->dispatch('export-progress', [
                'progress' => 5,
                'current' => 0,
                'total' => $totalProducts,
                'status' => 'Comptage terminé'
            ]);
            
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
            
            $this->exportStatus = 'en-têtes';
            $this->dispatch('export-progress', [
                'progress' => 10,
                'current' => 0,
                'total' => $totalProducts,
                'status' => 'En-têtes créés'
            ]);
            
            // Récupérer les produits par lots
            $batchSize = 500;
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
                    
                    // Mettre à jour la progression tous les 50 produits
                    if ($processed % 50 === 0) {
                        $progress = min(90, 10 + round(($processed / $totalProducts) * 80));
                        $this->exportCurrent = $processed;
                        $this->exportProgress = $progress;
                        $this->exportStatus = 'traitement';
                        
                        $this->dispatch('export-progress', [
                            'progress' => $progress,
                            'current' => $processed,
                            'total' => $totalProducts,
                            'status' => 'Traitement des produits'
                        ]);
                    }
                }
                
                $offset += $batchSize;
                
            } while ($products->count() === $batchSize);
            
            $this->exportStatus = 'finalisation';
            $this->dispatch('export-progress', [
                'progress' => 95,
                'current' => $totalProducts,
                'total' => $totalProducts,
                'status' => 'Finalisation du fichier'
            ]);
            
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
            
            $this->dispatch('export-progress', [
                'progress' => 98,
                'current' => $totalProducts,
                'total' => $totalProducts,
                'status' => 'Sauvegarde du fichier'
            ]);
            
            // Sauvegarder le fichier
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $filename = $this->exportFileName;
            $temp_file = tempnam(sys_get_temp_dir(), 'excel_');
            $writer->save($temp_file);
            
            $this->dispatch('export-completed', [
                'progress' => 100,
                'fileName' => $filename,
                'filePath' => $temp_file
            ]);
            
            // Réinitialiser l'état
            $this->isExporting = false;
            $this->exportProgress = 0;
            $this->exportCurrent = 0;
            $this->exportTotal = 0;
            $this->exportStatus = '';
            
            // Retourner le fichier pour téléchargement
            return response()->download($temp_file, $filename)->deleteFileAfterSend(true);
            
        } catch (\Exception $e) {
            $this->isExporting = false;
            $this->exportError = 'Erreur lors de l\'export : ' . $e->getMessage();
            $this->dispatch('export-error', ['message' => $this->exportError]);
            
            // Log l'erreur
            \Log::error('Erreur export CSV: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    public function cancelExport()
    {
        $this->isExporting = false;
        $this->exportProgress = 0;
        $this->exportCurrent = 0;
        $this->exportTotal = 0;
        $this->exportStatus = 'annulé';
        $this->dispatch('export-cancelled');
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

        /* Styles pour la barre de progression Alpine.js */
        .progress-ring {
            transform: rotate(-90deg);
        }
        
        .progress-ring__circle {
            transition: stroke-dashoffset 0.3s;
            transform: rotate(90deg);
            transform-origin: 50% 50%;
        }
        
        .export-modal-overlay {
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
        }
        
        .export-modal-content {
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .animate-spin {
            animation: spin 1s linear infinite;
        }
        
        .animate-pulse {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .progress-bar-transition {
            transition: all 0.3s ease-in-out;
        }
    </style>
    
    <x-header title="Produits de concurent" subtitle="Tous les prix des produits sur le concurent" separator>
    </x-header>

    <!-- Composant Alpine.js pour la barre de progression -->
    <div x-data="exportProgress()" x-cloak>
        <!-- Modal de progression -->
        <template x-if="isExporting">
            <div class="fixed inset-0 z-[9999] flex items-center justify-center p-4">
                <!-- Overlay -->
                <div class="absolute inset-0 bg-black bg-opacity-50 export-modal-overlay"></div>
                
                <!-- Modal -->
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md export-modal-content">
                    <!-- En-tête -->
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-xl font-bold text-gray-900" x-text="title"></h3>
                                <p class="text-gray-600 mt-1" x-text="statusMessage"></p>
                            </div>
                            <button 
                                @click="cancelExport()"
                                class="p-2 hover:bg-gray-100 rounded-lg transition-colors"
                                :disabled="isCompleting"
                            >
                                <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Contenu -->
                    <div class="p-6">
                        <!-- Cercle de progression -->
                        <div class="flex justify-center mb-6">
                            <div class="relative">
                                <!-- Cercle de fond -->
                                <svg class="w-32 h-32" viewBox="0 0 36 36">
                                    <!-- Cercle de fond -->
                                    <path
                                        d="M18 2.0845
                                          a 15.9155 15.9155 0 0 1 0 31.831
                                          a 15.9155 15.9155 0 0 1 0 -31.831"
                                        fill="none"
                                        stroke="#E5E7EB"
                                        stroke-width="3"
                                    />
                                    <!-- Cercle de progression -->
                                    <path
                                        d="M18 2.0845
                                          a 15.9155 15.9155 0 0 1 0 31.831
                                          a 15.9155 15.9155 0 0 1 0 -31.831"
                                        fill="none"
                                        :stroke="progressColor"
                                        stroke-width="3"
                                        stroke-dasharray="100, 100"
                                        :stroke-dashoffset="100 - progress"
                                        class="progress-ring__circle transition-all duration-300"
                                    />
                                </svg>
                                
                                <!-- Pourcentage au centre -->
                                <div class="absolute inset-0 flex items-center justify-center">
                                    <div class="text-center">
                                        <span class="text-3xl font-bold text-gray-900" x-text="`${progress}%`"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Détails de progression -->
                        <div class="space-y-4">
                            <!-- Barre de progression linéaire -->
                            <div>
                                <div class="flex justify-between text-sm text-gray-600 mb-1">
                                    <span x-text="`${current} / ${total} produits`"></span>
                                    <span x-text="`${progress}%`"></span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                                    <div 
                                        class="h-2 rounded-full progress-bar-transition"
                                        :class="progressBarClass"
                                        :style="`width: ${progress}%`"
                                    ></div>
                                </div>
                            </div>
                            
                            <!-- Statistiques -->
                            <div class="grid grid-cols-2 gap-4 text-center">
                                <div class="p-3 bg-gray-50 rounded-lg">
                                    <div class="text-sm text-gray-600">Vitesse</div>
                                    <div class="text-lg font-semibold text-gray-900" x-text="`${speed}/sec`"></div>
                                </div>
                                <div class="p-3 bg-gray-50 rounded-lg">
                                    <div class="text-sm text-gray-600">Temps restant</div>
                                    <div class="text-lg font-semibold text-gray-900" x-text="remainingTime"></div>
                                </div>
                            </div>
                            
                            <!-- Fichier -->
                            <div class="p-3 bg-blue-50 rounded-lg">
                                <div class="flex items-center">
                                    <svg class="w-5 h-5 text-blue-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-medium text-blue-900 truncate" x-text="fileName"></div>
                                        <div class="text-xs text-blue-700" x-text="`${fileSize} - ${processedCount} produits`"></div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Étape actuelle -->
                            <div class="flex items-center p-3 bg-gray-50 rounded-lg">
                                <div class="flex-shrink-0 w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center mr-3">
                                    <template x-if="!isCompleting">
                                        <svg class="w-4 h-4 text-blue-600 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                        </svg>
                                    </template>
                                    <template x-if="isCompleting">
                                        <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                        </svg>
                                    </template>
                                </div>
                                <div class="flex-1">
                                    <div class="text-sm font-medium text-gray-900" x-text="currentStep"></div>
                                    <div class="text-xs text-gray-600" x-text="stepDescription"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Pied de page -->
                    <div class="p-6 border-t border-gray-200">
                        <div class="flex justify-between items-center">
                            <div class="text-sm text-gray-500">
                                Débuté à <span x-text="startTime"></span>
                            </div>
                            <div class="flex space-x-3">
                                <button
                                    @click="cancelExport()"
                                    :disabled="isCompleting"
                                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    Annuler
                                </button>
                                <button
                                    x-show="isCompleting"
                                    @click="closeModal()"
                                    class="px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors"
                                >
                                    Fermer
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
        
        <!-- Notification de succès -->
        <template x-if="showSuccess">
            <div class="fixed bottom-4 right-4 z-[9999]">
                <div class="bg-green-50 border border-green-200 rounded-xl shadow-lg p-4 max-w-sm">
                    <div class="flex items-start">
                        <div class="flex-shrink-0">
                            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-green-800">Export terminé !</h3>
                            <div class="mt-1 text-sm text-green-700">
                                <p>Le fichier <span x-text="fileName" class="font-semibold"></span> a été généré avec succès.</p>
                                <p class="mt-1 text-xs" x-text="`${processedCount} produits exportés - ${fileSize}`"></p>
                            </div>
                            <div class="mt-3">
                                <button
                                    @click="downloadFile()"
                                    class="inline-flex items-center px-3 py-1.5 text-xs font-medium text-white bg-green-600 hover:bg-green-700 rounded-lg transition-colors"
                                >
                                    <svg class="w-3 h-3 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                    Télécharger
                                </button>
                                <button
                                    @click="showSuccess = false"
                                    class="ml-2 inline-flex items-center px-3 py-1.5 text-xs font-medium text-gray-700 hover:text-gray-900"
                                >
                                    Fermer
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
        
        <!-- Notification d'erreur -->
        <template x-if="showError">
            <div class="fixed bottom-4 right-4 z-[9999]">
                <div class="bg-red-50 border border-red-200 rounded-xl shadow-lg p-4 max-w-sm">
                    <div class="flex items-start">
                        <div class="flex-shrink-0">
                            <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-red-800">Erreur lors de l'export</h3>
                            <div class="mt-1 text-sm text-red-700">
                                <p x-text="errorMessage"></p>
                            </div>
                            <div class="mt-3">
                                <button
                                    @click="showError = false"
                                    class="inline-flex items-center px-3 py-1.5 text-xs font-medium text-red-700 hover:text-red-900"
                                >
                                    Fermer
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
    
    <!-- Script Alpine.js -->
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('exportProgress', () => ({
                // État
                isExporting: false,
                isCompleting: false,
                showSuccess: false,
                showError: false,
                
                // Données de progression
                progress: 0,
                current: 0,
                total: 0,
                speed: 0,
                remainingTime: '--:--',
                startTime: '',
                processedCount: 0,
                
                // Informations du fichier
                fileName: '',
                filePath: '',
                fileSize: 'Calcul...',
                
                // Messages
                title: 'Export en cours',
                statusMessage: 'Préparation du fichier Excel...',
                currentStep: 'Initialisation',
                stepDescription: 'Préparation des données',
                errorMessage: '',
                
                // Couleurs dynamiques
                get progressColor() {
                    if (this.progress < 30) return '#EF4444';
                    if (this.progress < 60) return '#F59E0B';
                    if (this.progress < 90) return '#3B82F6';
                    return '#10B981';
                },
                
                get progressBarClass() {
                    if (this.progress < 30) return 'bg-red-500';
                    if (this.progress < 60) return 'bg-yellow-500';
                    if (this.progress < 90) return 'bg-blue-500';
                    return 'bg-green-500';
                },
                
                // Initialisation
                init() {
                    this.setupEventListeners();
                    this.startTime = this.formatTime(new Date());
                },
                
                // Configuration des écouteurs d'événements Livewire
                setupEventListeners() {
                    // Événement de démarrage d'export
                    Livewire.on('export-started', (data) => {
                        this.isExporting = true;
                        this.isCompleting = false;
                        this.showSuccess = false;
                        this.showError = false;
                        this.progress = 0;
                        this.current = 0;
                        this.total = data.total || 0;
                        this.fileName = data.fileName || 'export.xlsx';
                        this.title = 'Export en cours';
                        this.statusMessage = 'Préparation du fichier Excel...';
                        this.currentStep = 'Initialisation';
                        this.stepDescription = 'Préparation des données';
                        this.startTime = this.formatTime(new Date());
                        
                        // Démarrer le calcul de la vitesse
                        this.startSpeedCalculation();
                    });
                    
                    // Événement de progression
                    Livewire.on('export-progress', (data) => {
                        this.progress = data.progress;
                        this.current = data.current;
                        this.total = data.total;
                        this.statusMessage = data.status || 'Traitement en cours...';
                        
                        // Mettre à jour l'étape en fonction de la progression
                        if (data.progress < 20) {
                            this.currentStep = 'Initialisation';
                            this.stepDescription = 'Préparation des données';
                        } else if (data.progress < 40) {
                            this.currentStep = 'Récupération';
                            this.stepDescription = 'Récupération des produits depuis la base de données';
                        } else if (data.progress < 70) {
                            this.currentStep = 'Traitement';
                            this.stepDescription = 'Formatage et organisation des données';
                        } else if (data.progress < 95) {
                            this.currentStep = 'Génération';
                            this.stepDescription = 'Création du fichier Excel';
                        } else {
                            this.currentStep = 'Finalisation';
                            this.stepDescription = 'Préparation du téléchargement';
                        }
                        
                        // Mettre à jour le compteur de produits traités
                        this.processedCount = data.current;
                        
                        // Calculer la vitesse et le temps restant
                        this.calculateRemainingTime();
                    });
                    
                    // Événement de complétion
                    Livewire.on('export-completed', (data) => {
                        this.isCompleting = true;
                        this.progress = 100;
                        this.current = data.total || this.total;
                        this.total = data.total || this.total;
                        this.fileName = data.fileName;
                        this.filePath = data.filePath;
                        this.processedCount = this.current;
                        
                        this.title = 'Export terminé !';
                        this.statusMessage = 'Fichier prêt au téléchargement';
                        this.currentStep = 'Terminé';
                        this.stepDescription = 'Export complété avec succès';
                        
                        // Calculer la taille du fichier
                        this.calculateFileSize();
                        
                        // Arrêter le calcul de la vitesse
                        clearInterval(this.speedInterval);
                        
                        // Afficher le message de succès après un délai
                        setTimeout(() => {
                            this.isExporting = false;
                            this.showSuccess = true;
                        }, 1500);
                    });
                    
                    // Événement d'erreur
                    Livewire.on('export-error', (data) => {
                        this.isExporting = false;
                        this.showError = true;
                        this.errorMessage = data.message || 'Une erreur est survenue lors de l\'export';
                        
                        // Arrêter le calcul de la vitesse
                        clearInterval(this.speedInterval);
                    });
                    
                    // Événement d'annulation
                    Livewire.on('export-cancelled', () => {
                        this.isExporting = false;
                        
                        // Arrêter le calcul de la vitesse
                        clearInterval(this.speedInterval);
                    });
                },
                
                // Calcul de la vitesse et du temps restant
                startSpeedCalculation() {
                    let lastUpdate = Date.now();
                    let lastCount = 0;
                    
                    this.speedInterval = setInterval(() => {
                        const now = Date.now();
                        const elapsedSeconds = (now - lastUpdate) / 1000;
                        
                        if (elapsedSeconds > 0 && this.current > lastCount) {
                            this.speed = Math.round((this.current - lastCount) / elapsedSeconds);
                            this.calculateRemainingTime();
                        }
                        
                        lastUpdate = now;
                        lastCount = this.current;
                    }, 1000);
                },
                
                calculateRemainingTime() {
                    if (this.speed > 0 && this.total > this.current) {
                        const remaining = this.total - this.current;
                        const secondsRemaining = Math.ceil(remaining / this.speed);
                        
                        if (secondsRemaining < 60) {
                            this.remainingTime = `${secondsRemaining}s`;
                        } else if (secondsRemaining < 3600) {
                            const minutes = Math.floor(secondsRemaining / 60);
                            const seconds = secondsRemaining % 60;
                            this.remainingTime = `${minutes}m ${seconds}s`;
                        } else {
                            const hours = Math.floor(secondsRemaining / 3600);
                            const minutes = Math.floor((secondsRemaining % 3600) / 60);
                            this.remainingTime = `${hours}h ${minutes}m`;
                        }
                    } else {
                        this.remainingTime = '--:--';
                    }
                },
                
                // Calcul de la taille du fichier
                calculateFileSize() {
                    // Estimation basée sur le nombre de produits
                    const estimatedSize = this.processedCount * 1024; // 1KB par produit en moyenne
                    
                    if (estimatedSize < 1024) {
                        this.fileSize = `${estimatedSize} octets`;
                    } else if (estimatedSize < 1024 * 1024) {
                        this.fileSize = `${(estimatedSize / 1024).toFixed(1)} KB`;
                    } else {
                        this.fileSize = `${(estimatedSize / (1024 * 1024)).toFixed(1)} MB`;
                    }
                },
                
                // Formatage du temps
                formatTime(date) {
                    return date.toLocaleTimeString('fr-FR', {
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                },
                
                // Téléchargement du fichier
                downloadFile() {
                    if (this.filePath) {
                        // Créer un lien de téléchargement temporaire
                        const link = document.createElement('a');
                        link.href = this.filePath;
                        link.download = this.fileName;
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                    }
                },
                
                // Annulation de l'export
                cancelExport() {
                    if (!this.isCompleting) {
                        Livewire.dispatch('cancel-export');
                        this.isExporting = false;
                        clearInterval(this.speedInterval);
                    }
                },
                
                // Fermeture du modal
                closeModal() {
                    this.isExporting = false;
                    this.isCompleting = false;
                }
            }));
        });
        
        // Ajouter le listener pour l'annulation d'export
        document.addEventListener('livewire:initialized', () => {
            Livewire.on('cancel-export', () => {
                @this.cancelExport();
            });
        });
    </script>
    
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