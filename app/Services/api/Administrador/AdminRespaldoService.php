<?php

namespace App\Services\api\Administrador;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class AdminRespaldoService
{
    public function metadata(): array
    {
        $connection = DB::connection();

        return [
            'database' => [
                'driver' => $connection->getDriverName(),
                'name' => (string) $connection->getDatabaseName(),
                'serverVersion' => (string) ($connection->selectOne('SHOW server_version')->server_version ?? ''),
                'format' => 'CreaFolio JSON',
            ],
            'ready' => true,
            'tables' => $this->availableTables(),
            'supportsFilePicker' => true,
        ];
    }

    public function generate(string $mode, array $requestedTables = []): array
    {
        $availableTables = collect($this->availableTables())->pluck('name')->all();
        $tables = $mode === 'full'
            ? $availableTables
            : array_values(array_unique($requestedTables));

        $invalidTables = array_values(array_diff($tables, $availableTables));
        if ($invalidTables !== []) {
            throw new \InvalidArgumentException('Se seleccionaron tablas no disponibles para respaldo.');
        }

        $directory = storage_path('app/private/admin-backups-temp');
        File::ensureDirectoryExists($directory);
        $this->deleteExpiredTemporaryFiles($directory);

        $suffix = now()->format('Ymd-His').'-'.Str::lower(Str::random(6));
        $filename = sprintf('creafolio-%s-%s.json', $mode === 'full' ? 'completo' : 'tablas', $suffix);
        $path = $directory.DIRECTORY_SEPARATOR.$filename;
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new \RuntimeException('No se pudo crear el archivo temporal del respaldo.');
        }

        try {
            $this->writeBackup($handle, $mode, $tables);
        } catch (\Throwable $exception) {
            fclose($handle);
            File::delete($path);
            throw new \RuntimeException('No se pudo exportar la informacion del respaldo.', 0, $exception);
        }

        fclose($handle);

        return [
            'path' => $path,
            'filename' => $filename,
            'mode' => $mode,
            'tables' => $tables,
        ];
    }

    private function writeBackup($handle, string $mode, array $tables): void
    {
        $database = DB::connection();
        $metadata = [
            'format' => 'creafolio-backup',
            'version' => 1,
            'generatedAt' => now()->toIso8601String(),
            'mode' => $mode,
            'database' => [
                'driver' => $database->getDriverName(),
                'name' => (string) $database->getDatabaseName(),
            ],
            'tableCount' => count($tables),
        ];

        fwrite($handle, "{\n  \"metadata\": ".$this->json($metadata).",\n  \"tables\": {");

        foreach ($tables as $tableIndex => $table) {
            fwrite($handle, ($tableIndex === 0 ? "\n" : ",\n").'    '.$this->json($table).": {\n");
            fwrite($handle, '      "columns": '.$this->json($this->tableColumns($table)).",\n");
            fwrite($handle, "      \"rows\": [");

            $rowIndex = 0;
            DB::table($table)->orderBy($this->orderColumn($table))->chunk(500, function ($rows) use ($handle, &$rowIndex): void {
                foreach ($rows as $row) {
                    fwrite($handle, ($rowIndex === 0 ? "\n" : ",\n").'        '.$this->json((array) $row));
                    $rowIndex++;
                }
            });

            fwrite($handle, ($rowIndex > 0 ? "\n" : '')."      ]\n    }");
        }

        fwrite($handle, "\n  }\n}\n");
    }

    private function availableTables(): array
    {
        return collect(DB::select(
            <<<'SQL'
                SELECT
                    tables.tablename AS name,
                    pg_total_relation_size(format('%I.%I', tables.schemaname, tables.tablename)) AS size_bytes,
                    COALESCE(stats.n_live_tup, 0) AS row_count
                FROM pg_catalog.pg_tables AS tables
                LEFT JOIN pg_catalog.pg_stat_user_tables AS stats
                    ON stats.schemaname = tables.schemaname
                    AND stats.relname = tables.tablename
                WHERE tables.schemaname = 'public'
                ORDER BY tables.tablename
            SQL
        ))->map(fn (object $table): array => [
            'name' => $table->name,
            'sizeBytes' => (int) $table->size_bytes,
            'rowCount' => (int) $table->row_count,
        ])->all();
    }

    private function tableColumns(string $table): array
    {
        return collect(DB::select(
            <<<'SQL'
                SELECT column_name, data_type, is_nullable, column_default
                FROM information_schema.columns
                WHERE table_schema = 'public' AND table_name = ?
                ORDER BY ordinal_position
            SQL,
            [$table],
        ))->map(fn (object $column): array => [
            'name' => $column->column_name,
            'type' => $column->data_type,
            'nullable' => $column->is_nullable === 'YES',
            'default' => $column->column_default,
        ])->all();
    }

    private function orderColumn(string $table): string
    {
        $column = DB::selectOne(
            <<<'SQL'
                SELECT column_name
                FROM information_schema.columns
                WHERE table_schema = 'public' AND table_name = ?
                ORDER BY ordinal_position
                LIMIT 1
            SQL,
            [$table],
        );

        return $column?->column_name ?? throw new \RuntimeException("La tabla {$table} no tiene columnas.");
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function deleteExpiredTemporaryFiles(string $directory): void
    {
        $expiration = now()->subDay()->getTimestamp();

        foreach (File::files($directory) as $file) {
            if ($file->getMTime() < $expiration) {
                File::delete($file->getPathname());
            }
        }
    }
}
