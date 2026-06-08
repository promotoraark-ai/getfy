<?php

namespace App\Support;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Throwable;

/**
 * Executa comandos Artisan em processo — hospedagem compartilhada sem proc_open/SSH.
 */
class SharedHostingArtisan
{
    public const DEFAULT_MIGRATE_CHUNK = 8;

    public static function clearCaches(string $basePath): void
    {
        $cacheDir = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'cache';
        if (! is_dir($cacheDir)) {
            return;
        }
        foreach (glob($cacheDir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        foreach ([
            rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'hot',
            rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'vite.hot',
        ] as $hot) {
            if (is_file($hot)) {
                @unlink($hot);
            }
        }
    }

    /**
     * @return array{ok: bool, output: string, error: string, ran: int, pending: int, done: bool, failed_migration: string|null}
     */
    public static function runMigrateChunk(string $basePath, int $limit = self::DEFAULT_MIGRATE_CHUNK): array
    {
        @set_time_limit(120);
        @ini_set('max_execution_time', '120');

        $output = [];
        $ran = 0;
        $failedMigration = null;

        try {
            $app = self::bootstrap($basePath);
            $migrator = $app->make('migrator');

            $paths = self::migrationPaths($app);
            $files = $migrator->getMigrationFiles($paths);
            if (! is_array($files)) {
                $files = [];
            }

            $repository = $migrator->getRepository();
            if (method_exists($migrator, 'repositoryExists') && ! $migrator->repositoryExists()) {
                $repository->createRepository();
                $output[] = 'Tabela migrations criada.';
            }

            $ranNames = $repository->getRan();
            if (! is_array($ranNames)) {
                $ranNames = [];
            }

            $pending = [];
            foreach ($files as $name => $path) {
                if (! in_array($name, $ranNames, true)) {
                    $pending[$name] = $path;
                }
            }

            $pendingCount = count($pending);
            if ($pendingCount === 0) {
                return [
                    'ok' => true,
                    'output' => implode("\n", $output) ?: 'Nenhuma migration pendente.',
                    'error' => '',
                    'ran' => 0,
                    'pending' => 0,
                    'done' => true,
                    'failed_migration' => null,
                ];
            }

            $batch = array_slice($pending, 0, max(1, $limit), true);
            foreach ($batch as $name => $path) {
                @set_time_limit(60);
                try {
                    $migrator->run([$path]);
                    $ran++;
                    $output[] = 'OK: ' . $name;
                } catch (Throwable $e) {
                    $failedMigration = $name;
                    $hint = self::humanizeMigrationError($e);

                    return [
                        'ok' => false,
                        'output' => implode("\n", $output),
                        'error' => $hint,
                        'ran' => $ran,
                        'pending' => max(0, $pendingCount - $ran),
                        'done' => false,
                        'failed_migration' => $failedMigration,
                    ];
                }
            }

            $remaining = max(0, $pendingCount - $ran);

            return [
                'ok' => true,
                'output' => implode("\n", $output),
                'error' => '',
                'ran' => $ran,
                'pending' => $remaining,
                'done' => $remaining === 0,
                'failed_migration' => null,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'output' => implode("\n", $output),
                'error' => self::humanizeMigrationError($e),
                'ran' => $ran,
                'pending' => -1,
                'done' => false,
                'failed_migration' => $failedMigration,
            ];
        }
    }

    /**
     * Roda todas as migrations pendentes em chunks até concluir ou estourar o tempo.
     *
     * @return array{ok: bool, output: string, error: string, ran: int, pending: int, done: bool, partial: bool}
     */
    public static function runMigrateAll(string $basePath, int $maxSeconds = 90, int $chunkSize = self::DEFAULT_MIGRATE_CHUNK): array
    {
        @set_time_limit(max(120, $maxSeconds + 30));
        $started = time();
        $allOutput = [];
        $totalRan = 0;
        $lastError = '';

        while (true) {
            if ((time() - $started) >= $maxSeconds) {
                $last = self::countPending($basePath);

                return [
                    'ok' => $totalRan > 0,
                    'output' => implode("\n", $allOutput),
                    'error' => $last['pending'] > 0
                        ? "Tempo limite atingido. Restam {$last['pending']} migrations — clique em \"Rodar migrations\" novamente para continuar."
                        : '',
                    'ran' => $totalRan,
                    'pending' => $last['pending'],
                    'done' => $last['pending'] === 0,
                    'partial' => $last['pending'] > 0,
                ];
            }

            $result = self::runMigrateChunk($basePath, $chunkSize);
            if ($result['output'] !== '') {
                $allOutput[] = $result['output'];
            }
            $totalRan += (int) ($result['ran'] ?? 0);

            if (! ($result['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'output' => implode("\n", $allOutput),
                    'error' => (string) ($result['error'] ?? 'Falha nas migrations.'),
                    'ran' => $totalRan,
                    'pending' => max(0, (int) ($result['pending'] ?? 0)),
                    'done' => false,
                    'partial' => $totalRan > 0,
                ];
            }

            if ($result['done'] ?? false) {
                return [
                    'ok' => true,
                    'output' => implode("\n", $allOutput) ?: 'Migrations concluídas.',
                    'error' => '',
                    'ran' => $totalRan,
                    'pending' => 0,
                    'done' => true,
                    'partial' => false,
                ];
            }
        }
    }

    /**
     * @return array{pending: int, total: int}
     */
    public static function countPending(string $basePath): array
    {
        try {
            $app = self::bootstrap($basePath);
            $migrator = $app->make('migrator');
            $paths = self::migrationPaths($app);
            $files = $migrator->getMigrationFiles($paths);
            if (! is_array($files)) {
                return ['pending' => 0, 'total' => 0];
            }
            $ran = $migrator->getRepository()->getRan();
            if (! is_array($ran)) {
                $ran = [];
            }
            $pending = 0;
            foreach ($files as $name => $_path) {
                if (! in_array($name, $ran, true)) {
                    $pending++;
                }
            }

            return ['pending' => $pending, 'total' => count($files)];
        } catch (Throwable) {
            return ['pending' => -1, 'total' => 0];
        }
    }

    public static function humanizeMigrationError(Throwable $e): string
    {
        $msg = $e->getMessage();
        $previous = $e->getPrevious();
        if ($previous instanceof Throwable) {
            $msg .= ' | ' . $previous->getMessage();
        }

        if ($e instanceof QueryException || str_contains($msg, 'SQLSTATE')) {
            if (str_contains($msg, '42S01') || stripos($msg, 'already exists') !== false) {
                return 'Tabela ou índice já existe no banco. Isso costuma acontecer quando a instalação foi interrompida no meio. '
                    . 'Peça ao suporte para limpar o banco (DROP) e instale de novo, ou marque a migration como executada manualmente na tabela `migrations`. '
                    . 'Detalhe: ' . $msg;
            }
            if (str_contains($msg, '42S02') || stripos($msg, "doesn't exist") !== false) {
                return 'Tabela referenciada não existe — migration anterior pode não ter rodado. Rode "Rodar migrations" novamente ou verifique integridade. '
                    . 'Detalhe: ' . $msg;
            }
            if (str_contains($msg, '1045') || stripos($msg, 'Access denied') !== false) {
                return 'Acesso negado ao MySQL. Confira usuário, senha e host no .env (algumas hospedagens usam localhost em vez de 127.0.0.1). '
                    . 'Detalhe: ' . $msg;
            }
            if (str_contains($msg, '2002') || stripos($msg, 'Connection refused') !== false) {
                return 'Não foi possível conectar ao MySQL. Verifique DB_HOST e DB_PORT no .env. '
                    . 'Detalhe: ' . $msg;
            }
            if (str_contains($msg, '1060') || stripos($msg, 'Duplicate column') !== false) {
                return 'Coluna duplicada — migration parcialmente aplicada. Limpe o banco e reinstale, ou ajuste manualmente. '
                    . 'Detalhe: ' . $msg;
            }
            if (str_contains($msg, '1364') || stripos($msg, 'doesn\'t have a default value') !== false) {
                return 'Modo SQL strict da hospedagem bloqueou a migration. Peça ao suporte para desativar STRICT_TRANS_TABLES ou ajuste a migration. '
                    . 'Detalhe: ' . $msg;
            }
            if (str_contains($msg, '1071') || stripos($msg, 'Specified key was too long') !== false) {
                return 'Índice MySQL muito longo (utf8mb4). Normalmente resolvido nas migrations recentes — confirme que está na versão mais nova. '
                    . 'Detalhe: ' . $msg;
            }
        }

        if (str_contains($msg, 'vendor/autoload.php')) {
            return 'Pasta vendor não encontrada. Suba o projeto com vendor/ ou rode composer install antes de migrar.';
        }

        if (stripos($msg, 'no active transaction') !== false) {
            return 'Erro de transação MySQL durante migration (DDL faz commit automático). '
                . 'Se a instalação foi interrompida aqui, apague todas as tabelas do banco e rode o wizard de novo. '
                . 'Atualize para a versão mais recente do Getfy — essa migration já foi corrigida. '
                . 'Detalhe: ' . $msg;
        }

        return $msg;
    }

    /**
     * @return array{ok: bool, output: string, error: string}
     */
    public static function runArtisanCommand(string $basePath, string $command, array $parameters = []): array
    {
        try {
            $app = self::bootstrap($basePath);
            $kernel = $app->make(ConsoleKernel::class);
            $kernel->call($command, $parameters);
            $output = trim((string) $kernel->output());

            return ['ok' => true, 'output' => $output, 'error' => ''];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'output' => '',
                'error' => self::humanizeMigrationError($e),
            ];
        }
    }

    public static function bootstrap(string $basePath): Application
    {
        self::clearCaches($basePath);

        $autoload = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (! is_file($autoload)) {
            throw new \RuntimeException('vendor/autoload.php não encontrado.');
        }

        require $autoload;

        /** @var Application $app */
        $app = require rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
        $kernel = $app->make(ConsoleKernel::class);
        $kernel->bootstrap();

        return $app;
    }

    /**
     * @return array<int, string>
     */
    private static function migrationPaths(Application $app): array
    {
        $paths = [database_path('migrations')];
        $migrator = $app->make('migrator');
        if (method_exists($migrator, 'paths')) {
            $custom = $migrator->paths();
            if (is_array($custom)) {
                $paths = array_merge($paths, $custom);
            }
        }

        return array_values(array_unique(array_filter($paths, fn ($p) => is_string($p) && $p !== '' && is_dir($p))));
    }
}
