<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;
use Throwable;

class PencatatanJurnalTable
{
    private static ?string $resolved = null;

    /** @var array<string, bool> */
    private static array $columnCache = [];

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

    private static function hasColumn(string $column): bool
    {
        if (array_key_exists($column, self::$columnCache)) {
            return self::$columnCache[$column];
        }

        try {
            return self::$columnCache[$column] = Schema::connection('DATA_MYSQL')
                ->hasColumn(self::name(), $column);
        } catch (Throwable) {
            return self::$columnCache[$column] = false;
        }
    }
}
