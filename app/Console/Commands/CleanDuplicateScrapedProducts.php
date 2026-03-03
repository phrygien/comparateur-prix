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
                            {--dry-run : Afficher le nombre de doublons sans les supprimer}';

    /**
     * The description of the console command.
     *
     * @var string
     */
    protected $description = 'Supprime les doublons de scraped_product en gardant le plus récent par groupe (ean, web_site_id)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');

        $this->info('Recherche des doublons dans scraped_product...');

        try {
            // Compter les doublons
            $duplicatesCount = $this->countDuplicates();

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
            $deletedCount = $this->deleteDuplicates();

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
    private function countDuplicates(): int
    {
        $query = "
            SELECT COUNT(*) as count
            FROM (
                SELECT
                    id,
                    ROW_NUMBER() OVER (
                        PARTITION BY ean, web_site_id
                        ORDER BY created_at DESC
                    ) AS rn
                FROM scraped_product
            ) AS t
            WHERE rn > 1
        ";

        $result = DB::selectOne($query);
        return $result->count;
    }

    /**
     * Supprime les doublons
     */
    private function deleteDuplicates(): int
    {
        $query = "
            DELETE FROM scraped_product
            WHERE id IN (
                SELECT id FROM (
                    SELECT
                        id,
                        ROW_NUMBER() OVER (
                            PARTITION BY ean, web_site_id
                            ORDER BY created_at DESC
                        ) AS rn
                    FROM scraped_product
                ) AS t
                WHERE rn > 1
            )
        ";

        return DB::delete($query);
    }
}