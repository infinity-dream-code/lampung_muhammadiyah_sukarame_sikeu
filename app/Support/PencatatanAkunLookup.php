<?php

namespace App\Support;

use App\Models\akun_kas_keluar;
use App\Models\akun_kas_masuk;
use Throwable;

class PencatatanAkunLookup
{
    /** @var array<string, string>|null */
    private static ?array $masukByKode = null;

    /** @var array<string, string>|null */
    private static ?array $keluarByKode = null;

    public static function namaMasuk(?string $stored, ?string $kode = null, ?string $noBukti = null): string
    {
        return self::resolve($stored, [$kode, $noBukti], self::masukByKode());
    }

    public static function namaKeluar(?string $stored, ?string $kode = null, ?string $noBukti = null): string
    {
        return self::resolve($stored, [$kode, $noBukti], self::keluarByKode());
    }

    /**
     * Filter dropdown bisa kirim kode atau nama akun.
     *
     * @return array{kode: ?string, nama: ?string}
     */
    public static function matchMasuk(string $filter): array
    {
        return self::match($filter, self::masukByKode());
    }

    /**
     * @return array{kode: ?string, nama: ?string}
     */
    public static function matchKeluar(string $filter): array
    {
        return self::match($filter, self::keluarByKode());
    }

    /** @return array<string, string> */
    public static function masukByKode(): array
    {
        if (self::$masukByKode !== null) {
            return self::$masukByKode;
        }

        try {
            self::$masukByKode = akun_kas_masuk::query()
                ->pluck('NamaAkunMasuk', 'KodeAkunMasuk')
                ->map(fn ($nama) => trim((string) $nama))
                ->filter()
                ->all();
        } catch (Throwable) {
            self::$masukByKode = [];
        }

        return self::$masukByKode;
    }

    /** @return array<string, string> */
    public static function keluarByKode(): array
    {
        if (self::$keluarByKode !== null) {
            return self::$keluarByKode;
        }

        try {
            self::$keluarByKode = akun_kas_keluar::query()
                ->pluck('NamaAkunKeluar', 'KodeAkunKeluar')
                ->map(fn ($nama) => trim((string) $nama))
                ->filter()
                ->all();
        } catch (Throwable) {
            self::$keluarByKode = [];
        }

        return self::$keluarByKode;
    }

    /**
     * @param  list<string|null>  $keys
     * @param  array<string, string>  $map
     */
    private static function resolve(?string $stored, array $keys, array $map): string
    {
        $stored = trim((string) $stored);
        if ($stored !== '' && $stored !== '-') {
            return $stored;
        }

        foreach ($keys as $key) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            if (isset($map[$key]) && $map[$key] !== '') {
                return $map[$key];
            }
        }

        return '-';
    }

    /**
     * @param  array<string, string>  $map
     * @return array{kode: ?string, nama: ?string}
     */
    private static function match(string $filter, array $map): array
    {
        $filter = trim($filter);
        if ($filter === '' || strtolower($filter) === 'all') {
            return ['kode' => null, 'nama' => null];
        }

        if (isset($map[$filter])) {
            return ['kode' => $filter, 'nama' => $map[$filter]];
        }

        foreach ($map as $kode => $nama) {
            if (strcasecmp($nama, $filter) === 0) {
                return ['kode' => (string) $kode, 'nama' => $nama];
            }
        }

        return ['kode' => $filter, 'nama' => $filter];
    }
}
