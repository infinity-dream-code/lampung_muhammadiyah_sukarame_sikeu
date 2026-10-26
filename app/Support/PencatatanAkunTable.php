<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PencatatanAkunTable
{
    public static function ensureMasuk(): bool
    {
        return self::ensure('akun_kas_masuk', '
            KodeAkunMasuk VARCHAR(10) NOT NULL,
            NamaAkunMasuk VARCHAR(80) NULL,
            NoRekMasuk VARCHAR(25) NULL,
            PRIMARY KEY (KodeAkunMasuk)
        ');
    }

    public static function ensureKeluar(): bool
    {
        return self::ensure('akun_kas_keluar', '
            KodeAkunKeluar VARCHAR(10) NOT NULL,
            NamaAkunKeluar VARCHAR(80) NULL,
            NoRekKeluar VARCHAR(25) NULL,
            PRIMARY KEY (KodeAkunKeluar)
        ');
    }

    private static function ensure(string $table, string $columnsSql): bool
    {
        try {
            if (Schema::connection('DATA_MYSQL')->hasTable($table)) {
                return true;
            }

            DB::connection('DATA_MYSQL')->statement(
                "CREATE TABLE IF NOT EXISTS {$table} ({$columnsSql}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            return Schema::connection('DATA_MYSQL')->hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }
}
