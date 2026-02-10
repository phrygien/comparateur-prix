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
            'headers' => [
                ['key' => 'id', 'label' => '#'],
                ['key' => 'nom_fichier', 'label' => 'Nom du fichier'],
                ['key' => 'top_products_count', 'label' => 'Nb produits'],
                ['key' => 'created_at', 'label' => 'Date d\'import'],
                ['key' => 'actions', 'label' => 'Actions'],
            ]
        ];
    }

    /**
     * Nettoie et convertit une valeur monétaire
     */
    private function cleanPrice($value)
    {
        if (empty($value)) {
            return 0;
        }
        
        // Enlever les espaces, € et convertir , en .
        $cleaned = str_replace([' ', '€', ' '], '', $value);
        $cleaned = str_replace(',', '.', $cleaned);
        
        return (float) $cleaned;
    }

    /**
     * Nettoie une valeur texte
     */
    private function cleanText($value)
    {
        return trim($value ?? '');
    }

    public function importer()
    {
        // Validation
        $this->validate([
            'file' => 'required|mimes:xlsx,xls|max:51200', // Max 50MB
        ]);

        $this->isImporting = true;
        $this->importProgress = 0;

        try {
            DB::beginTransaction();

            // Sauvegarder le fichier
            $fileName = time() . '_' . $this->file->getClientOriginalName();
            $filePath = $this->file->storeAs('top_products', $fileName, 'public');

            // Enregistrer dans histo_import_top_file
            $histoImport = HistoImportTopFile::create([
                'nom_fichier' => $fileName,
                'chemin_fichier' => $filePath,
            ]);

            // Charger le fichier Excel
            $fullPath = Storage::disk('public')->path($filePath);
            $spreadsheet = IOFactory::load($fullPath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            // Ignorer la première ligne (en-têtes)
            array_shift($rows);

            // Filtrer les lignes vides
            $rows = array_filter($rows, function($row) {
                return !empty(array_filter($row));
            });

            $this->totalRows = count($rows);
            $imported = 0;
            $errors = [];
            $batchSize = 100;

            // Traiter par lots de 100
            $chunks = array_chunk($rows, $batchSize);

            foreach ($chunks as $chunkIndex => $chunk) {
                $batchData = [];

                foreach ($chunk as $index => $row) {
                    try {
                        // Structure du fichier Excel:
                        // 0: Ranking Quantité
                        // 1: Ranking CA
                        // 2: Marque
                        // 3: Groupe
                        // 4: Désignation du produit
                        // 5: Gencode (EAN)
                        // 6: Stock
                        // 7: Export
                        // 8: Supprimé
                        // 9: Nouveauté
                        // 10: PGHT
                        // 11: PAMP
                        // 12: Prix de Vente Cosma

                        $batchData[] = [
                            'histo_import_top_file_id' => $histoImport->id,
                            'rank_qty' => (int) ($row[0] ?? 0),
                            'rank_chriffre_affaire' => (int) ($row[1] ?? 0),
                            'marque' => $this->cleanText($row[2]),
                            'groupe' => $this->cleanText($row[3]),
                            'designation' => $this->cleanText($row[4]),
                            'ean' => $this->cleanText($row[5]),
                            'freezed_stock' => (int) ($row[6] ?? 0),
                            'export' => $this->cleanText($row[7]),
                            'supprime' => $this->cleanText($row[8]),
                            'nouveaute' => $this->cleanText($row[9]),
                            'pght' => $this->cleanPrice($row[10]),
                            'pamp' => $this->cleanPrice($row[11]),
                            'prix_vente_cosma' => $this->cleanPrice($row[12]),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    } catch (\Exception $e) {
                        $globalIndex = ($chunkIndex * $batchSize) + $index + 2;
                        $errors[] = "Ligne $globalIndex: " . $e->getMessage();
                    }
                }

                // Insertion par lot
                if (!empty($batchData)) {
                    TopProduct::insert($batchData);
                    $imported += count($batchData);
                }

                // Mettre à jour la progression
                $this->importProgress = round(($imported / $this->totalRows) * 100);
                
                // Petit délai pour permettre au navigateur de se rafraîchir
                usleep(10000); // 10ms
            }

            DB::commit();

            // Message de succès
            $message = "$imported produit(s) importé(s) avec succès sur {$this->totalRows} ligne(s).";
            if (!empty($errors)) {
                $message .= " " . count($errors) . " erreur(s) détectée(s).";
                
                // Sauvegarder les erreurs dans un fichier log
                $errorLog = "Erreurs d'importation - " . now()->format('Y-m-d H:i:s') . "\n\n";
                $errorLog .= implode("\n", $errors);
                Storage::disk('public')->put(
                    'top_products/errors/' . time() . '_errors.txt',
                    $errorLog
                );
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
            
            // Supprimer les produits associés par lots pour éviter les timeouts
            $histo->topProducts()->chunkById(500, function ($products) {
                TopProduct::whereIn('id', $products->pluck('id'))->delete();
            });
            
            // Supprimer le fichier physique
            if (Storage::disk('public')->exists($histo->chemin_fichier)) {
                Storage::disk('public')->delete($histo->chemin_fichier);
            }
            
            // Supprimer l'enregistrement
            $histo->delete();

            DB::commit();

            session()->flash('success', 'Import supprimé avec succès.');
            
        } catch (\Exception $e) {
            DB::rollBack();
            session()->flash('error', 'Erreur lors de la suppression: ' . $e->getMessage());
        }
    }
}; ?>

<div class="max-w-7xl">
    <x-header title="Importer" subtitle="Importer le fichier de top product" separator />

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
            hint="Format xlsx/xls - Max 50MB (Colonnes: Ranking Quantité, Ranking CA, Marque, Groupe, Désignation, Gencode, Stock, Export, Supprimé, Nouveauté, PGHT, PAMP, Prix de Vente)" 
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

        <x-table :headers="$headers" :rows="$historiques" striped with-pagination>
            @scope('cell_created_at', $histo)
                {{ $histo->created_at->format('d/m/Y H:i') }}
            @endscope

            @scope('cell_actions', $histo)
                <div class="flex gap-2">
                    <x-button 
                        icon="o-arrow-down-tray" 
                        wire:click="telecharger({{ $histo->id }})" 
                        spinner 
                        class="btn-sm btn-ghost"
                        tooltip="Télécharger"
                    />
                    <x-button 
                        icon="o-trash" 
                        wire:click="supprimer({{ $histo->id }})" 
                        wire:confirm="Êtes-vous sûr de vouloir supprimer cet import ({{ $histo->top_products_count }} produits) ?" 
                        spinner 
                        class="btn-sm btn-ghost text-error"
                        tooltip="Supprimer"
                    />
                </div>
            @endscope

            @scope('empty')
                <x-alert icon="o-information-circle" class="alert-info">
                    Aucun historique d'import disponible.
                </x-alert>
            @endscope
        </x-table>
    </div>
</div>