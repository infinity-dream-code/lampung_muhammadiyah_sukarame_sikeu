<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PencatatanJurnalTable
{
    private static ?string $resolved = null;

    /** @var array<string, bool> */
    private static array $columnCache = [];

    /** @var list<string>|null */
    private static ?array $columns = null;

    public static function name(): string
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $connection = 'DATA_MYSQL';
        foreach (['akt_jurnal', 'akt_jurnal_in_out'] as $candidate) {
            try {
                if (Schema::connection($connection)->hasTable($candidate)) {
                    return self::$resolved = $candidate;
                }
            } catch (Throwable) {
                //
            }
        }

        return self::$resolved = 'akt_jurnal';
    }

    public static function hasAkunMasukColumn(): bool
    {
        return self::hasColumn('NamaAkunMasuk');
    }

    public static function hasAkunKeluarColumn(): bool
    {
        return self::hasColumn('NamaAkunKeluar');
    }

    public static function hasBuktiUrlColumn(): bool
    {
        return self::hasColumn('buktiurl');
    }

    public static function hasNoRefColumn(): bool
    {
        return self::hasColumn('no_ref');
    }

    public static function hasKodePerkiraanColumn(): bool
    {
        return self::hasColumn('kode_perkiraan');
    }

    /**
     * Pastikan kolom akun/sumber ada di tabel jurnal supaya nama akun bisa disimpan dan ditampil.
     */
    public static function ensureLedgerColumns(): void
    {
        $table = self::name();

        try {
            $schema = Schema::connection('DATA_MYSQL');
            if (!$schema->hasTable($table)) {
                return;
            }

            $definitions = [
                'NamaAkunMasuk' => 'VARCHAR(80) NULL',
                'NamaAkunKeluar' => 'VARCHAR(80) NULL',
                'kode_perkiraan' => 'VARCHAR(20) NULL',
                'no_ref' => 'VARCHAR(50) NULL',
                'buktiurl' => 'VARCHAR(255) NULL',
            ];

            $added = false;
            foreach ($definitions as $column => $definition) {
                if ($schema->hasColumn($table, $column)) {
                    continue;
                }

                DB::connection('DATA_MYSQL')->statement(
                    "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}"
                );
                $added = true;
            }

            if ($added) {
                self::forgetColumnCache();
            }
        } catch (Throwable) {
            self::forgetColumnCache();
        }
    }

    public static function forgetColumnCache(): void
    {
        self::$columnCache = [];
        self::$columns = null;
    }

    public static function hasColumn(string $column): bool
    {
        if (array_key_exists($column, self::$columnCache)) {
            return self::$columnCache[$column];
        }

        try {
            return self::$columnCache[$column] = in_array($column, self::columns(), true);
        } catch (Throwable) {
            return self::$columnCache[$column] = false;
        }
    }

    /** @return list<string> */
    public static function columns(): array
    {
        if (self::$columns !== null) {
            return self::$columns;
        }

        try {
            return self::$columns = Schema::connection('DATA_MYSQL')->getColumnListing(self::name());
        } catch (Throwable) {
            return self::$columns = [];
        }
    }
}
