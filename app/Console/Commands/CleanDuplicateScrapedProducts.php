<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanDuplicateScrapedProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scraped-products:clean-duplicates 
                            {--dry-run : Afficher le nombre de doublons sans les supprimer}
                            {--keep=2 : Nombre d\'enregistrements à conserver par groupe}';

    /**
     * The description of the console command.
     *
     * @var string
     */
    protected $description = 'Supprime les doublons de scraped_product en gardant les 2 plus récents par groupe (url, vendor, name, type, variation)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $keep = (int) $this->option('keep');
        $isDryRun = $this->option('dry-run');

        $this->info('Recherche des doublons dans scraped_product...');

        try {
            // Compter les doublons
            $duplicatesCount = $this->countDuplicates($keep);

            if ($duplicatesCount === 0) {
                $this->info('Aucun doublon trouvé !');
                return Command::SUCCESS;
            }

            $this->warn("{$duplicatesCount} enregistrements en doublon détectés");

            if ($isDryRun) {
                $this->info('Mode dry-run activé - Aucune suppression effectuée');
                return Command::SUCCESS;
            }

            // Demander confirmation
            if (!$this->confirm("Voulez-vous supprimer {$duplicatesCount} doublons ?")) {
                $this->info('Opération annulée');
                return Command::SUCCESS;
            }

            // Exécuter la suppression
            $deletedCount = $this->deleteDuplicates($keep);

            $this->info("{$deletedCount} doublons supprimés avec succès !");

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Erreur : ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Compte le nombre de doublons
     */
    private function countDuplicates(int $keep): int
    {
        $query = "
            SELECT COUNT(*) as count
            FROM (
                SELECT
                    id,
                    ROW_NUMBER() OVER (
                        PARTITION BY url, vendor, name, type, variation
                        ORDER BY created_at DESC
                    ) AS rn
                FROM scraped_product
            ) AS t
            WHERE rn > ?
        ";

        $result = DB::selectOne($query, [$keep]);
        return $result->count;
    }

    /**
     * Supprime les doublons
     */
    private function deleteDuplicates(int $keep): int
    {
        $query = "
            DELETE FROM scraped_product
            WHERE id IN (
                SELECT id FROM (
                    SELECT
                        id,
                        ROW_NUMBER() OVER (
                            PARTITION BY url, vendor, name, type, variation
                            ORDER BY created_at DESC
                        ) AS rn
                    FROM scraped_product
                ) AS t
                WHERE rn > ?
            )
        ";

        return DB::delete($query, [$keep]);
    }
}