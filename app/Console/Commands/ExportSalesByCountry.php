<?php

namespace App\Console\Commands;

use App\Mail\SalesExportMail;
use App\Services\ExportSalesService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class ExportSalesByCountry extends Command
{
    /**
     * php artisan sales:export-by-country
     * php artisan sales:export-by-country --countries=FR,BE,NL
     * php artisan sales:export-by-country --date-from=2026-01-01 --date-to=2026-01-31
     * php artisan sales:export-by-country --sort=rank_ca
     * php artisan sales:export-by-country --email=autre@exemple.com
     * php artisan sales:export-by-country --countries=FR --dry-run
     */
    protected $signature = 'sales:export-by-country
                            {--countries=        : Codes pays séparés par virgule (défaut : tous)}
                            {--date-from=        : Date début YYYY-MM-DD (défaut : 1er janvier de l\'année courante)}
                            {--date-to=          : Date fin YYYY-MM-DD (défaut : 31 décembre de l\'année courante)}
                            {--sort=rank_qty     : Tri — rank_qty ou rank_ca}
                            {--email=            : Destinataire (défaut : mphrygien@astucom.com)}
                            {--dry-run           : Génère les fichiers sans envoyer l\'email}';

    protected $description = 'Exporte le top 100 des ventes par pays en XLSX et envoie le tout par email';

    private const DEFAULT_EMAIL     = 'mphrygien@astucom.com';
    private const ALL_COUNTRIES = [
        'FR' => 'France',
        'BE' => 'Belgique',
        'NL' => 'Pays-Bas',
        'DE' => 'Allemagne',
        'ES' => 'Espagne',
        'IT' => 'Italie',
    ];

    public function __construct(private readonly ExportSalesService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // --- Options ---
        $dateFrom  = $this->option('date-from') ?: date('Y-01-01');
        $dateTo    = $this->option('date-to')   ?: date('Y-12-31');
        $sortBy    = in_array($this->option('sort'), ['rank_qty', 'rank_ca']) ? $this->option('sort') : 'rank_qty';
        $email     = $this->option('email') ?: self::DEFAULT_EMAIL;
        $isDryRun  = $this->option('dry-run');

        // Pays à traiter
        $requestedCodes = $this->option('countries')
            ? array_map('trim', explode(',', strtoupper($this->option('countries'))))
            : array_keys(self::ALL_COUNTRIES);

        $countries = array_filter(
            self::ALL_COUNTRIES,
            fn($code) => in_array($code, $requestedCodes),
            ARRAY_FILTER_USE_KEY
        );

        if (empty($countries)) {
            $this->error('Aucun pays valide fourni. Pays disponibles : ' . implode(', ', array_keys(self::ALL_COUNTRIES)));
            return Command::FAILURE;
        }

        // --- Résumé ---
        $this->info('');
        $this->line('  <fg=cyan>📊 Export ventes par pays</>');
        $this->line('  ─────────────────────────────────────────────');
        $this->line("  Période    : <comment>{$dateFrom}</comment> → <comment>{$dateTo}</comment>");
        $this->line("  Tri        : <comment>{$sortBy}</comment>");
        $this->line("  Pays       : <comment>" . implode(', ', array_keys($countries)) . "</comment>");
        $this->line("  Email      : <comment>{$email}</comment>");
        $this->line("  Dry run    : <comment>" . ($isDryRun ? 'oui (aucun email envoyé)' : 'non') . "</comment>");
        $this->line('  ─────────────────────────────────────────────');
        $this->info('');

        // --- Génération des fichiers ---
        $filePaths          = [];
        $countriesGenerated = [];
        $errors             = [];

        foreach ($countries as $code => $label) {
            $this->line("  Génération <fg=yellow>{$label}</> ({$code})...");

            try {
                $filePath = $this->service->generateForCountry(
                    countryCode:   $code,
                    dateFrom:      $dateFrom,
                    dateTo:        $dateTo,
                    sortBy:        $sortBy,
                    groupeFilter:  []
                );

                $filePaths[]              = $filePath;
                $countriesGenerated[$code] = $label;

                $size = round(filesize($filePath) / 1024, 1);
                $this->line("  <fg=green>✓</> {$label} — {$size} Ko → <fg=gray>" . basename($filePath) . "</>");

            } catch (\Throwable $e) {
                $errors[$code] = $e->getMessage();
                $this->line("  <fg=red>✗</> {$label} — Erreur : {$e->getMessage()}");
                \Log::error("ExportSalesByCountry [{$code}] : " . $e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        if (empty($filePaths)) {
            $this->error('Aucun fichier généré. Abandon.');
            return Command::FAILURE;
        }

        // --- Envoi email ---
        if ($isDryRun) {
            $this->info('');
            $this->warn("  [Dry run] Email NON envoyé. " . count($filePaths) . " fichier(s) disponible(s) dans storage/app/public/exports/");
        } else {
            $this->info('');
            $this->line("  Envoi de l'email à <comment>{$email}</comment>...");

            try {
                Mail::to($email)->send(
                    new SalesExportMail(
                        filePaths:          $filePaths,
                        dateFrom:           $dateFrom,
                        dateTo:             $dateTo,
                        countriesGenerated: $countriesGenerated
                    )
                );

                $this->line("  <fg=green>✓</> Email envoyé avec " . count($filePaths) . " pièce(s) jointe(s).");

            } catch (\Throwable $e) {
                $this->error("  Erreur envoi email : " . $e->getMessage());
                \Log::error("ExportSalesByCountry — Email : " . $e->getMessage());
                // Les fichiers sont conservés même si l'email échoue
                return Command::FAILURE;
            }
        }

        // --- Nettoyage des fichiers (sauf dry-run) ---
        if (!$isDryRun) {
            foreach ($filePaths as $path) {
                if (file_exists($path)) {
                    @unlink($path);
                }
            }
            $this->line("  <fg=gray>Fichiers temporaires supprimés.</>");
        }

        // --- Rapport final ---
        $this->info('');
        $this->line('  ─────────────────────────────────────────────');

        if (!empty($errors)) {
            $this->warn("  ⚠  " . count($errors) . " pays en erreur : " . implode(', ', array_keys($errors)));
        }

        $this->info("  ✅  Export terminé — " . count($filePaths) . " pays traité(s).");
        $this->line('');

        return Command::SUCCESS;
    }
}