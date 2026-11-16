<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class PencatatanHapusLog
{
    public const TABLE = 'log_hapus_akt_jurnal';

    public static function ensureTable(): bool
    {
        try {
            if (Schema::connection('DATA_MYSQL')->hasTable(self::TABLE)) {
                return true;
            }

            DB::connection('DATA_MYSQL')->statement(
                'CREATE TABLE IF NOT EXISTS '.self::TABLE.' (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    urut_asal INT NULL,
                    jenis VARCHAR(10) NOT NULL,
                    no_bukti VARCHAR(50) NULL,
                    tanggal_jurnal DATETIME NULL,
                    keterangan VARCHAR(255) NULL,
                    debet BIGINT NOT NULL DEFAULT 0,
                    kredit BIGINT NOT NULL DEFAULT 0,
                    nominal BIGINT NOT NULL DEFAULT 0,
                    tahun VARCHAR(4) NULL,
                    periode VARCHAR(10) NULL,
                    kode_akun VARCHAR(20) NULL,
                    nama_akun VARCHAR(80) NULL,
                    sumber_kas VARCHAR(50) NULL,
                    dihapus_oleh VARCHAR(80) NULL,
                    dihapus_oleh_id VARCHAR(50) NULL,
                    dihapus_pada DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    KEY idx_dihapus_pada (dihapus_pada),
                    KEY idx_no_bukti (no_bukti),
                    KEY idx_jenis (jenis)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );

            return Schema::connection('DATA_MYSQL')->hasTable(self::TABLE);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  'masuk'|'keluar'  $jenis
     * @param  object|array<string, mixed>  $row
     */
    public static function write(string $jenis, object|array $row): void
    {
        if (!self::ensureTable()) {
            throw new RuntimeException(
                'Tabel log_hapus_akt_jurnal belum ada. Jalankan CREATE TABLE di Navicat terlebih dahulu.'
            );
        }

        $data = self::rowToArray($row);
        $debet = (int) self::pick($data, ['debet', 'DEBET'], 0);
        $kredit = (int) self::pick($data, ['kredit', 'KREDIT'], 0);
        $nominal = $jenis === 'masuk' ? $kredit : $debet;
        if ($nominal <= 0) {
            $nominal = max($debet, $kredit);
        }

        $namaAkun = trim((string) (
            $jenis === 'masuk'
                ? self::pick($data, ['NamaAkunMasuk', 'namaakunmasuk', 'nama_akun_masuk'], '')
                : self::pick($data, ['NamaAkunKeluar', 'namaakunkeluar', 'nama_akun_keluar'], '')
        ));
        if ($namaAkun === '') {
            $namaAkun = trim((string) self::pick(
                $data,
                ['NamaAkunMasuk', 'NamaAkunKeluar', 'namaakunmasuk', 'namaakunkeluar'],
                ''
            ));
        }

        $user = Auth::user();
        $dihapusOleh = trim((string) ($user->username ?? $user->name ?? Auth::id() ?? 'system'));
        $dihapusOlehId = trim((string) ($user->urut ?? $user->id ?? Auth::id() ?? ''));

        DB::connection('DATA_MYSQL')->table(self::TABLE)->insert([
            'urut_asal' => ($urut = self::pick($data, ['urut', 'URUT'], null)) !== null && $urut !== ''
                ? (int) $urut
                : null,
            'jenis' => $jenis,
            'no_bukti' => trim((string) self::pick($data, ['no_bukti', 'NO_BUKTI'], '')) ?: null,
            'tanggal_jurnal' => self::normalizeDatetime(self::pick($data, ['tanggal', 'TANGGAL'], null)),
            'keterangan' => trim((string) self::pick($data, ['keterangan', 'KETERANGAN'], '')) ?: null,
            'debet' => $debet,
            'kredit' => $kredit,
            'nominal' => $nominal,
            'tahun' => trim((string) self::pick($data, ['tahun', 'TAHUN'], '')) ?: null,
            'periode' => trim((string) self::pick($data, ['periode', 'PERIODE'], '')) ?: null,
            'kode_akun' => trim((string) self::pick($data, ['kode_perkiraan', 'KODE_PERKIRAAN'], '')) ?: null,
            'nama_akun' => $namaAkun !== '' ? $namaAkun : null,
            'sumber_kas' => trim((string) self::pick($data, ['no_ref', 'NO_REF'], '')) ?: null,
            'dihapus_oleh' => $dihapusOleh !== '' ? $dihapusOleh : 'system',
            'dihapus_oleh_id' => $dihapusOlehId !== '' ? $dihapusOlehId : null,
            'dihapus_pada' => Carbon::now()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param  object|array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function rowToArray(object|array $row): array
    {
        if (is_array($row)) {
            return $row;
        }

        if ($row instanceof Model) {
            return $row->getAttributes();
        }

        if (method_exists($row, 'toArray')) {
            /** @var array<string, mixed> $arr */
            $arr = $row->toArray();

            return $arr;
        }

        // Jangan pakai (array) $object — Eloquent jadi key \0*\0attributes.
        $arr = [];
        foreach (get_object_vars($row) as $key => $value) {
            $arr[$key] = $value;
        }

        return $arr;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private static function pick(array $data, array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                return $data[$key];
            }
        }

        $lowerMap = [];
        foreach ($data as $k => $v) {
            $lowerMap[strtolower((string) $k)] = $v;
        }

        foreach ($keys as $key) {
            $lk = strtolower($key);
            if (array_key_exists($lk, $lowerMap) && $lowerMap[$lk] !== null && $lowerMap[$lk] !== '') {
                return $lowerMap[$lk];
            }
        }

        return $default;
    }

    private static function normalizeDatetime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '' || preg_match('/^0{4}-0{2}-0{2}/', $raw) === 1) {
            return null;
        }

        try {
            return Carbon::parse($raw)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }
}
