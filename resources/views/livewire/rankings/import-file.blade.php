<?php

use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Models\TopProduct;
use App\Models\HistoImportTopFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\WithPagination;

new class extends Component {
    use WithFileUploads, WithPagination;

    public $file;
    public $importProgress = 0;
    public $totalRows = 0;
    public $isImporting = false;

    public function with(): array
    {
        return [
            'historiques' => HistoImportTopFile::withCount('topProducts')
                ->latest()
                ->paginate(10),
        ];
    }

    /**
     * Récupère une valeur de tableau en toute sécurité
     */
    private function getValue($row, $index, $default = '')
    {
        return isset($row[$index]) ? $row[$index] : $default;
    }

    /**
     * Nettoie et convertit une valeur monétaire
     */
    private function cleanPrice($value)
    {
        if (empty($value) || !is_scalar($value)) {
            return 0;
        }
        
        $cleaned = str_replace([' ', '€', ' '], '', (string)$value);
        $cleaned = str_replace(',', '.', $cleaned);
        
        return is_numeric($cleaned) ? (float)$cleaned : 0;
    }

    /**
     * Nettoie une valeur texte
     */
    private function cleanText($value)
    {
        if ($value === null || $value === '') {
            return '';
        }
        return trim((string)$value);
    }

    /**
     * Nettoie une valeur entière
     */
    private function cleanInt($value)
    {
        if (empty($value) || !is_scalar($value)) {
            return 0;
        }
        return (int)$value;
    }

    /**
     * Génère un hash unique pour le fichier
     */
    private function generateFileHash($filePath)
    {
        return hash_file('sha256', $filePath);
    }

    /**
     * Vérifie si le fichier a déjà été importé
     */
    private function isFileAlreadyImported($originalFileName, $fileHash)
    {
        return HistoImportTopFile::where('nom_fichier', $originalFileName)
            ->orWhere('file_hash', $fileHash)
            ->exists();
    }

    public function importer()
    {
        // Validation
        $this->validate([
            'file' => 'required|mimes:xlsx,xls|max:51200', // Max 50MB
        ]);

        try {
            // Vérifier si le fichier existe déjà AVANT de le sauvegarder
            $tempPath = $this->file->getRealPath();
            $fileHash = $this->generateFileHash($tempPath);
            $originalFileName = $this->file->getClientOriginalName();

            // Vérifier si le fichier a déjà été importé
            $existingImport = HistoImportTopFile::where('file_hash', $fileHash)
                ->orWhere('nom_fichier', $originalFileName)
                ->first();

            if ($existingImport) {
                session()->flash('error', 
                    "Ce fichier a déjà été importé le " . 
                    $existingImport->created_at->format('d/m/Y à H:i') . 
                    " (" . $existingImport->top_products_count . " produits)."
                );
                $this->reset('file');
                return;
            }

            $this->isImporting = true;
            $this->importProgress = 0;

            DB::beginTransaction();

            // Sauvegarder le fichier
            $fileName = time() . '_' . $originalFileName;
            $filePath = $this->file->storeAs('top_products', $fileName, 'public');

            // Enregistrer dans histo_import_top_file avec le hash
            $histoImport = HistoImportTopFile::create([
                'nom_fichier' => $originalFileName,
                'chemin_fichier' => $filePath,
                'file_hash' => $fileHash,
            ]);

            // Charger le fichier Excel
            $fullPath = Storage::disk('public')->path($filePath);
            $spreadsheet = IOFactory::load($fullPath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            // Ignorer la première ligne (en-têtes)
            $headers = array_shift($rows);

            // Filtrer les lignes vides
            $rows = array_filter($rows, function($row) {
                return !empty(array_filter($row, function($cell) {
                    return $cell !== null && $cell !== '';
                }));
            });

            $this->totalRows = count($rows);
            $imported = 0;
            $errors = [];
            $skipped = 0;
            $batchSize = 100;

            // Traiter par lots de 100
            $chunks = array_chunk($rows, $batchSize, true);

            foreach ($chunks as $chunkIndex => $chunk) {
                $batchData = [];

                foreach ($chunk as $rowIndex => $row) {
                    try {
                        if (count($row) < 6) {
                            $skipped++;
                            $errors[] = "Ligne " . ($rowIndex + 2) . ": Ligne incomplète";
                            continue;
                        }

                        $data = [
                            'histo_import_top_file_id' => $histoImport->id,
                            'rank_qty' => $this->cleanInt($this->getValue($row, 0, 0)),
                            'rank_chriffre_affaire' => $this->cleanInt($this->getValue($row, 1, 0)),
                            'marque' => $this->cleanText($this->getValue($row, 2)),
                            'groupe' => $this->cleanText($this->getValue($row, 3)),
                            'designation' => $this->cleanText($this->getValue($row, 4)),
                            'ean' => $this->cleanText($this->getValue($row, 5)),
                            'freezed_stock' => $this->cleanInt($this->getValue($row, 6, 0)),
                            'export' => $this->cleanText($this->getValue($row, 7)),
                            'supprime' => $this->cleanText($this->getValue($row, 8)),
                            'nouveaute' => $this->cleanText($this->getValue($row, 9)),
                            'pght' => $this->cleanPrice($this->getValue($row, 10, 0)),
                            'pamp' => $this->cleanPrice($this->getValue($row, 11, 0)),
                            'prix_vente_cosma' => $this->cleanPrice($this->getValue($row, 12, 0)),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];

                        if (empty($data['ean'])) {
                            $skipped++;
                            $errors[] = "Ligne " . ($rowIndex + 2) . ": EAN manquant";
                            continue;
                        }

                        $batchData[] = $data;
                        
                    } catch (\Exception $e) {
                        $skipped++;
                        $errors[] = "Ligne " . ($rowIndex + 2) . ": " . $e->getMessage();
                    }
                }

                if (!empty($batchData)) {
                    try {
                        TopProduct::insert($batchData);
                        $imported += count($batchData);
                    } catch (\Exception $e) {
                        $errors[] = "Erreur insertion lot " . ($chunkIndex + 1) . ": " . $e->getMessage();
                    }
                }

                $processedRows = $imported + $skipped;
                $this->importProgress = $this->totalRows > 0 
                    ? round(($processedRows / $this->totalRows) * 100) 
                    : 0;
                
                usleep(10000);
            }

            DB::commit();

            $message = "$imported produit(s) importé(s) avec succès sur {$this->totalRows} ligne(s).";
            if ($skipped > 0) {
                $message .= " $skipped ligne(s) ignorée(s).";
            }
            if (!empty($errors)) {
                $message .= " " . count($errors) . " erreur(s) détectée(s).";
                
                $errorLog = "Erreurs d'importation - " . now()->format('Y-m-d H:i:s') . "\n\n";
                $errorLog .= "Fichier: $originalFileName\n";
                $errorLog .= "Total lignes: {$this->totalRows}\n";
                $errorLog .= "Importées: $imported\n";
                $errorLog .= "Ignorées: $skipped\n\n";
                $errorLog .= implode("\n", $errors);
                
                Storage::disk('public')->put('top_products/errors/' . time() . '_errors.txt', $errorLog);
            }

            $this->reset(['file', 'importProgress', 'totalRows', 'isImporting']);
            $this->resetPage();
            
            session()->flash('success', $message);

        } catch (\Exception $e) {
            DB::rollBack();
            
            $this->reset(['importProgress', 'totalRows', 'isImporting']);
            session()->flash('error', 'Erreur lors de l\'importation: ' . $e->getMessage());
        }
    }

    /**
     * Télécharge le fichier modèle d'importation
     */
    public function getModel()
    {
        $modelPath = public_path('Model_importation_ranking.xlsx');
        
        if (file_exists($modelPath)) {
            return response()->download($modelPath, 'Model_importation_ranking.xlsx');
        }
        
        session()->flash('error', 'Fichier modèle introuvable dans le dossier public.');
    }

    public function telecharger($id)
    {
        $histo = HistoImportTopFile::findOrFail($id);
        
        if (Storage::disk('public')->exists($histo->chemin_fichier)) {
            return Storage::disk('public')->download($histo->chemin_fichier, $histo->nom_fichier);
        }
        
        session()->flash('error', 'Fichier introuvable.');
    }

    public function supprimer($id)
    {
        try {
            DB::beginTransaction();

            $histo = HistoImportTopFile::findOrFail($id);
            
            $histo->topProducts()->chunkById(500, function ($products) {
                TopProduct::whereIn('id', $products->pluck('id'))->delete();
            });
            
            if (Storage::disk('public')->exists($histo->chemin_fichier)) {
                Storage::disk('public')->delete($histo->chemin_fichier);
            }
            
            $histo->delete();

            DB::commit();

            session()->flash('success', 'Import supprimé avec succès.');
            
        } catch (\Exception $e) {
            DB::rollBack();
            session()->flash('error', 'Erreur lors de la suppression: ' . $e->getMessage());
        }
    }
}; ?>

<div class="max-w-7xl mx-auto">
    <x-header title="Importer" subtitle="Importer le fichier de top product" separator>
        <x-slot:middle class="!justify-end">
        </x-slot:middle>
        <x-slot:actions>
            <x-button label="Telecharger le Model fichier ici" wire:click="getModel()" icon="o-arrow-down-tray" class="btn-primary" />
        </x-slot:actions>
    </x-header>

    {{-- Messages flash --}}
    @if (session()->has('success'))
        <x-alert icon="o-check-circle" class="alert-success mb-4">
            {{ session('success') }}
        </x-alert>
    @endif

    @if (session()->has('error'))
        <x-alert icon="o-exclamation-triangle" class="alert-error mb-4">
            {{ session('error') }}
        </x-alert>
    @endif

    {{-- Formulaire d'import --}}
    <x-form wire:submit="importer">
        <x-file 
            wire:model="file" 
            label="Ranking File" 
            hint="Format xlsx/xls - Max 50MB" 
            accept="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel"
            :disabled="$isImporting"
        />
        
        @error('file') 
            <span class="text-error text-sm mt-1">{{ $message }}</span> 
        @enderror

        <x-slot:actions>
            <x-button 
                label="Importer" 
                class="btn-primary" 
                type="submit" 
                spinner="importer" 
                :disabled="$isImporting"
            />
        </x-slot:actions>
    </x-form>

    {{-- Indicateur de chargement du fichier --}}
    <div wire:loading wire:target="file" class="mt-4">
        <x-alert icon="o-arrow-path" class="alert-info">
            Chargement du fichier...
        </x-alert>
    </div>

    {{-- Barre de progression de l'import --}}
    @if ($isImporting || $importProgress > 0)
        <div class="mt-4" wire:poll.500ms>
            <x-alert icon="o-arrow-path" class="alert-info">
                <div class="w-full">
                    <div class="flex justify-between mb-2">
                        <span class="font-medium">Importation en cours...</span>
                        <span class="font-bold text-lg">{{ $importProgress }}%</span>
                    </div>
                    <progress 
                        class="progress progress-primary w-full h-4" 
                        value="{{ $importProgress }}" 
                        max="100"
                    ></progress>
                    @if ($totalRows > 0)
                        <div class="text-sm mt-2 opacity-80">
                            <strong>{{ round(($importProgress / 100) * $totalRows) }}</strong> / 
                            <strong>{{ $totalRows }}</strong> lignes traitées
                        </div>
                    @endif
                </div>
            </x-alert>
        </div>
    @endif

    {{-- Historique des imports --}}
    <div class="mt-8">
        <x-header title="Historique des imports" separator />

        <div class="overflow-x-auto rounded-box border border-base-content/5 bg-base-100">
            <table class="table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Nom du fichier</th>
                        <th>Nb produits</th>
                        <th>Date d'import</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($historiques as $histo)
                        <tr class="hover">
                            <th>{{ $histo->id }}</th>
                            <td>
                                <div class="font-medium">{{ $histo->nom_fichier }}</div>
                            </td>
                            <td>
                                <span class="badge badge-primary">{{ $histo->top_products_count }}</span>
                            </td>
                            <td>{{ $histo->created_at->format('d/m/Y H:i') }}</td>
                            <td>
                                <div class="flex gap-2">
                                    <x-button icon="o-eye" class="btn-primary btn-sm btn-soft" link="/ranking-result/{{ $histo->id }}" />
                                    <button 
                                        wire:click="telecharger({{ $histo->id }})"
                                        class="btn btn-sm btn-ghost"
                                        title="Télécharger"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                        </svg>
                                    </button>
                                    <button 
                                        wire:click="supprimer({{ $histo->id }})"
                                        wire:confirm="Êtes-vous sûr de vouloir supprimer cet import ({{ $histo->top_products_count }} produits) ?"
                                        class="btn btn-sm btn-ghost text-error"
                                        title="Supprimer"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-8">
                                <div class="flex flex-col items-center gap-2 text-base-content/60">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-12 h-12">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                                    </svg>
                                    <span class="font-medium">Aucun historique d'import disponible</span>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        @if ($historiques->hasPages())
            <div class="mt-4">
                {{ $historiques->links() }}
            </div>
        @endif
    </div>
</div>
