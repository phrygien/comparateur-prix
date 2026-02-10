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

    public function importer()
    {
        // Validation
        $this->validate([
            'file' => 'required|mimes:xlsx,xls|max:10240', // Max 10MB
        ]);

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
            $headers = array_shift($rows);

            $imported = 0;
            $errors = [];

            foreach ($rows as $index => $row) {
                // Ignorer les lignes vides
                if (empty(array_filter($row))) {
                    continue;
                }

                try {
                    TopProduct::create([
                        'histo_import_top_file_id' => $histoImport->id,
                        'rank_qty' => $row[0] ?? 0,
                        'rank_chriffre_affaire' => $row[1] ?? 0,
                        'ean' => $row[2] ?? '',
                        'marque' => $row[3] ?? '',
                        'groupe' => $row[4] ?? '',
                        'designation' => $row[5] ?? '',
                        'freezed_stock' => $row[6] ?? 0,
                        'export' => $row[7] ?? null,
                        'supprime' => $row[8] ?? null,
                        'nouveaute' => $row[9] ?? null,
                        'pght' => $row[10] ?? 0,
                        'pamp' => $row[11] ?? 0,
                        'prix_vente_cosma' => $row[12] ?? 0,
                    ]);
                    $imported++;
                } catch (\Exception $e) {
                    $errors[] = "Ligne " . ($index + 2) . ": " . $e->getMessage();
                }
            }

            DB::commit();

            // Message de succès
            $message = "$imported produit(s) importé(s) avec succès.";
            if (!empty($errors)) {
                $message .= " " . count($errors) . " erreur(s) détectée(s).";
            }

            $this->reset('file');
            $this->resetPage();
            
            session()->flash('success', $message);

        } catch (\Exception $e) {
            DB::rollBack();
            
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
            
            // Supprimer les produits associés
            $histo->topProducts()->delete();
            
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
            hint="Format xlsx/xls - Max 10MB" 
            accept="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel" 
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
                wire:loading.attr="disabled"
            />
        </x-slot:actions>
    </x-form>

    {{-- Indicateur de chargement --}}
    <div wire:loading wire:target="file" class="mt-4">
        <x-alert icon="o-arrow-path" class="alert-info">
            Chargement du fichier...
        </x-alert>
    </div>

    <div wire:loading wire:target="importer" class="mt-4">
        <x-alert icon="o-arrow-path" class="alert-info">
            Importation en cours, veuillez patienter...
        </x-alert>
    </div>

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
                        wire:confirm="Êtes-vous sûr de vouloir supprimer cet import ?" 
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