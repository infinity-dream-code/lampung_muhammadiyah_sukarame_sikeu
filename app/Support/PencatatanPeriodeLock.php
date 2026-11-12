<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PencatatanPeriodeLock
{
    public const TABLE = 'akt_jurnal_periode_lock';

    public static function ensureTable(): bool
    {
        try {
            if (Schema::connection('DATA_MYSQL')->hasTable(self::TABLE)) {
                return true;
            }

            DB::connection('DATA_MYSQL')->statement(
                'CREATE TABLE IF NOT EXISTS '.self::TABLE.' (
                    tahun VARCHAR(4) NOT NULL,
                    periode VARCHAR(2) NOT NULL,
                    locked_at DATETIME NULL,
                    locked_by VARCHAR(80) NULL,
                    PRIMARY KEY (tahun, periode)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );

            return Schema::connection('DATA_MYSQL')->hasTable(self::TABLE);
        } catch (Throwable) {
            return false;
        }
    }

    public static function normalizePeriode(mixed $periode): string
    {
        $parsed = PencatatanPeriode::parse((string) $periode);
        $bulan = $parsed['bulan'] ?? null;
        if ($bulan === null || $bulan < 1 || $bulan > 12) {
            return trim((string) $periode);
        }

        return (string) $bulan;
    }

    public static function normalizeTahun(mixed $tahun, mixed $periode = null): string
    {
        $tahunStr = trim((string) $tahun);
        if ($tahunStr !== '' && preg_match('/^\d{4}$/', $tahunStr)) {
            return $tahunStr;
        }

        $parsed = PencatatanPeriode::parse((string) $periode);
        if (!empty($parsed['tahun'])) {
            return (string) $parsed['tahun'];
        }

        return Carbon::now()->format('Y');
    }

    public static function isLocked(mixed $tahun, mixed $periode): bool
    {
        if (!self::ensureTable()) {
            return false;
        }

        $tahunStr = self::normalizeTahun($tahun, $periode);
        $periodeStr = self::normalizePeriode($periode);
        if ($tahunStr === '' || $periodeStr === '') {
            return false;
        }

        return DB::connection('DATA_MYSQL')
            ->table(self::TABLE)
            ->where('tahun', $tahunStr)
            ->where('periode', $periodeStr)
            ->exists();
    }

    public static function assertOpen(mixed $tahun, mixed $periode): void
    {
        if (!self::isLocked($tahun, $periode)) {
            return;
        }

        $label = PencatatanPeriode::label($periode, $tahun);
        throw new \RuntimeException("Periode {$label} sudah disimpan/ditutup. Tidak bisa menambah/menghapus jurnal.");
    }

    public static function lock(string $tahun, string $periode): void
    {
        if (!self::ensureTable()) {
            throw new \RuntimeException(
                'Tabel akt_jurnal_periode_lock belum ada. Jalankan CREATE TABLE di Navicat terlebih dahulu.'
            );
        }

        $tahunStr = self::normalizeTahun($tahun, $periode);
        $periodeStr = self::normalizePeriode($periode);
        if ($tahunStr === '' || $periodeStr === '' || (int) $periodeStr < 1 || (int) $periodeStr > 12) {
            throw new \RuntimeException('Tahun/periode tidak valid.');
        }

        $user = Auth::user();
        $lockedBy = trim((string) ($user->username ?? $user->name ?? Auth::id() ?? 'system'));

        DB::connection('DATA_MYSQL')->table(self::TABLE)->updateOrInsert(
            ['tahun' => $tahunStr, 'periode' => $periodeStr],
            [
                'locked_at' => Carbon::now()->format('Y-m-d H:i:s'),
                'locked_by' => $lockedBy !== '' ? $lockedBy : 'system',
            ]
        );
    }

    public static function unlock(string $tahun, string $periode): void
    {
        if (!self::ensureTable()) {
            return;
        }

        DB::connection('DATA_MYSQL')
            ->table(self::TABLE)
            ->where('tahun', self::normalizeTahun($tahun, $periode))
            ->where('periode', self::normalizePeriode($periode))
            ->delete();
    }

    /**
     * @return list<object{tahun:string,periode:string,locked_at:?string,locked_by:?string,label:string}>
     */
    public static function listLocked(): array
    {
        if (!self::ensureTable()) {
            return [];
        }

        return DB::connection('DATA_MYSQL')
            ->table(self::TABLE)
            ->orderByDesc('tahun')
            ->orderByDesc('periode')
            ->get()
            ->map(function ($row) {
                $row->label = PencatatanPeriode::label($row->periode, $row->tahun);

                return $row;
            })
            ->all();
    }
}
