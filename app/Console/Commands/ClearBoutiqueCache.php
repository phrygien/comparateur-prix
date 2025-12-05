<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ClearBoutiqueCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache:clear-boutique 
                            {--all : Vider tout le cache boutique}
                            {--stats : Afficher les statistiques du cache}
                            {--pattern= : Pattern personnalisé pour vider le cache}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Gère le cache Redis de la boutique';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->option('stats')) {
            $this->showStats();
            return 0;
        }

        if ($this->option('all')) {
            $this->clearAll();
            return 0;
        }

        if ($pattern = $this->option('pattern')) {
            $this->clearByPattern($pattern);
            return 0;
        }

        // Menu interactif si aucune option
        $this->interactiveMenu();
        return 0;
    }

    /**
     * Menu interactif
     */
    protected function interactiveMenu()
    {
        $choice = $this->choice(
            'Que voulez-vous faire ?',
            [
                'stats' => 'Voir les statistiques du cache',
                'clear_all' => 'Vider tout le cache boutique',
                'clear_pattern' => 'Vider par pattern',
                'quit' => 'Quitter'
            ],
            'stats'
        );

        switch ($choice) {
            case 'stats':
                $this->showStats();
                break;
            case 'clear_all':
                if ($this->confirm('Êtes-vous sûr de vouloir vider tout le cache boutique ?')) {
                    $this->clearAll();
                }
                break;
            case 'clear_pattern':
                $pattern = $this->ask('Entrez le pattern (ex: boutique:products:*)');
                $this->clearByPattern($pattern);
                break;
        }
    }

    /**
     * Affiche les statistiques du cache
     */
    protected function showStats()
    {
        try {
            if (config('cache.default') !== 'redis') {
                $this->error('Redis n\'est pas configuré comme driver de cache');
                return;
            }

            $redis = Cache::getRedis();
            
            // Statistiques générales
            $info = $redis->info();
            
            $this->info('=== Statistiques Redis ===');
            $this->line('Version: ' . ($info['redis_version'] ?? 'N/A'));
            $this->line('Mémoire utilisée: ' . $this->formatBytes($info['used_memory'] ?? 0));
            $this->line('Pics de mémoire: ' . $this->formatBytes($info['used_memory_peak'] ?? 0));
            $this->line('Clés totales: ' . ($info['db0']['keys'] ?? 0));
            
            // Statistiques spécifiques boutique
            $patterns = [
                'boutique:*' => 'Total boutique',
                'boutique:products:*' => 'Produits',
                'boutique:count:*' => 'Compteurs',
            ];

            $this->newLine();
            $this->info('=== Clés par catégorie ===');
            
            $table = [];
            foreach ($patterns as $pattern => $label) {
                $keys = $redis->keys($pattern);
                $count = count($keys);
                $size = 0;
                
                if ($count > 0) {
                    // Estimer la taille (limité aux 100 premières clés)
                    $sampleKeys = array_slice($keys, 0, min(100, $count));
                    foreach ($sampleKeys as $key) {
                        $size += strlen($redis->get($key) ?? '');
                    }
                    if ($count > 100) {
                        $size = ($size / 100) * $count; // Estimation
                    }
                }
                
                $table[] = [
                    $label,
                    $pattern,
                    $count,
                    $this->formatBytes($size)
                ];
            }
            
            $this->table(['Catégorie', 'Pattern', 'Nombre de clés', 'Taille estimée'], $table);
            
        } catch (\Exception $e) {
            $this->error('Erreur: ' . $e->getMessage());
            Log::error('Cache stats error: ' . $e->getMessage());
        }
    }

    /**
     * Vide tout le cache boutique
     */
    protected function clearAll()
    {
        try {
            $this->info('Vidage du cache boutique...');
            
            if (config('cache.default') !== 'redis') {
                Cache::flush();
                $this->info('Cache vidé (driver: ' . config('cache.default') . ')');
                return;
            }

            $redis = Cache::getRedis();
            $pattern = 'boutique:*';
            $keys = $redis->keys($pattern);
            $count = count($keys);
            
            if ($count === 0) {
                $this->info('Aucune clé à supprimer');
                return;
            }

            $bar = $this->output->createProgressBar($count);
            $bar->start();
            
            foreach ($keys as $key) {
                $redis->del($key);
                $bar->advance();
            }
            
            $bar->finish();
            $this->newLine();
            $this->info("✓ {$count} clés supprimées avec succès");
            
        } catch (\Exception $e) {
            $this->error('Erreur: ' . $e->getMessage());
            Log::error('Cache clear error: ' . $e->getMessage());
        }
    }

    /**
     * Vide le cache par pattern
     */
    protected function clearByPattern($pattern)
    {
        try {
            $this->info("Vidage du cache avec le pattern: {$pattern}");
            
            if (config('cache.default') !== 'redis') {
                $this->error('Cette fonctionnalité nécessite Redis');
                return;
            }

            $redis = Cache::getRedis();
            $keys = $redis->keys($pattern);
            $count = count($keys);
            
            if ($count === 0) {
                $this->info('Aucune clé trouvée pour ce pattern');
                return;
            }

            if (!$this->confirm("Voulez-vous supprimer {$count} clés ?")) {
                $this->info('Opération annulée');
                return;
            }

            $bar = $this->output->createProgressBar($count);
            $bar->start();
            
            foreach ($keys as $key) {
                $redis->del($key);
                $bar->advance();
            }
            
            $bar->finish();
            $this->newLine();
            $this->info("✓ {$count} clés supprimées avec succès");
            
        } catch (\Exception $e) {
            $this->error('Erreur: ' . $e->getMessage());
            Log::error('Cache clear by pattern error: ' . $e->getMessage());
        }
    }

    /**
     * Formate les bytes en format lisible
     */
    protected function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}