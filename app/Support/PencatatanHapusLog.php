<?php

namespace App\Support;

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

        $data = is_array($row) ? $row : (array) $row;
        $debet = (int) ($data['debet'] ?? 0);
        $kredit = (int) ($data['kredit'] ?? 0);
        $nominal = $jenis === 'masuk' ? $kredit : $debet;
        if ($nominal <= 0) {
            $nominal = max($debet, $kredit);
        }

        $namaAkun = $jenis === 'masuk'
            ? trim((string) ($data['NamaAkunMasuk'] ?? ''))
            : trim((string) ($data['NamaAkunKeluar'] ?? ''));
        if ($namaAkun === '') {
            $namaAkun = trim((string) ($data['NamaAkunMasuk'] ?? $data['NamaAkunKeluar'] ?? ''));
        }

        $user = Auth::user();
        $dihapusOleh = trim((string) ($user->username ?? $user->name ?? Auth::id() ?? 'system'));
        $dihapusOlehId = trim((string) ($user->urut ?? $user->id ?? Auth::id() ?? ''));

        DB::connection('DATA_MYSQL')->table(self::TABLE)->insert([
            'urut_asal' => isset($data['urut']) ? (int) $data['urut'] : null,
            'jenis' => $jenis,
            'no_bukti' => trim((string) ($data['no_bukti'] ?? '')) ?: null,
            'tanggal_jurnal' => self::normalizeDatetime($data['tanggal'] ?? null),
            'keterangan' => trim((string) ($data['keterangan'] ?? '')) ?: null,
            'debet' => $debet,
            'kredit' => $kredit,
            'nominal' => $nominal,
            'tahun' => trim((string) ($data['tahun'] ?? '')) ?: null,
            'periode' => trim((string) ($data['periode'] ?? '')) ?: null,
            'kode_akun' => trim((string) ($data['kode_perkiraan'] ?? '')) ?: null,
            'nama_akun' => $namaAkun !== '' ? $namaAkun : null,
            'sumber_kas' => trim((string) ($data['no_ref'] ?? '')) ?: null,
            'dihapus_oleh' => $dihapusOleh !== '' ? $dihapusOleh : 'system',
            'dihapus_oleh_id' => $dihapusOlehId !== '' ? $dihapusOlehId : null,
            'dihapus_pada' => Carbon::now()->format('Y-m-d H:i:s'),
        ]);
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
