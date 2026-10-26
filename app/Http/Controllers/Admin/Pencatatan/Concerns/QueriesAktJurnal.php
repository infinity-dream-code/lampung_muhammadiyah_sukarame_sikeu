<?php

namespace App\Http\Controllers\Admin\Pencatatan\Concerns;

use App\Models\akt_jurnal;
use App\Support\PencatatanAkunLookup;
use App\Support\PencatatanJurnalTable;
use App\Support\PencatatanPeriode;
use Illuminate\Database\Eloquent\Builder;

trait QueriesAktJurnal
{
    protected function jurnalBaseQuery(): Builder
    {
        PencatatanJurnalTable::ensureLedgerColumns();

        $select = [
            'urut',
            'tanggal',
            'no_bukti',
            'keterangan',
            'debet',
            'kredit',
            'tahun',
            'periode',
        ];

        if (PencatatanJurnalTable::hasBuktiUrlColumn()) {
            $select[] = 'buktiurl';
        }
        if (PencatatanJurnalTable::hasAkunMasukColumn()) {
            $select[] = 'NamaAkunMasuk';
        }
        if (PencatatanJurnalTable::hasAkunKeluarColumn()) {
            $select[] = 'NamaAkunKeluar';
        }
        if (PencatatanJurnalTable::hasNoRefColumn()) {
            $select[] = 'no_ref';
        }
        if (PencatatanJurnalTable::hasKodePerkiraanColumn()) {
            $select[] = 'kode_perkiraan';
        }

        return akt_jurnal::query()->select($select);
    }

    /**
     * @param  array<string, string>  $filters
     */
    protected function applyJurnalFilters(Builder $query, array $filters, string $searchValue = ''): void
    {
        if ($searchValue !== '') {
            $query->where(function ($q) use ($searchValue) {
                $q->where('no_bukti', 'like', '%' . $searchValue . '%')
                    ->orWhere('keterangan', 'like', '%' . $searchValue . '%')
                    ->orWhere('periode', 'like', '%' . $searchValue . '%')
                    ->orWhere('tahun', 'like', '%' . $searchValue . '%');

                if (PencatatanJurnalTable::hasAkunMasukColumn()) {
                    $q->orWhere('NamaAkunMasuk', 'like', '%' . $searchValue . '%');
                }
                if (PencatatanJurnalTable::hasAkunKeluarColumn()) {
                    $q->orWhere('NamaAkunKeluar', 'like', '%' . $searchValue . '%');
                }
                if (PencatatanJurnalTable::hasNoRefColumn()) {
                    $q->orWhere('no_ref', 'like', '%' . $searchValue . '%');
                }
            });
        }

        $filterPeriode = trim((string) ($filters['periode'] ?? ''));
        $filterNoBukti = trim((string) ($filters['no_bukti'] ?? ''));
        $filterKeterangan = trim((string) ($filters['keterangan'] ?? ''));
        $filterTanggalDari = trim((string) ($filters['tanggal_dari'] ?? ''));
        $filterTanggalSampai = trim((string) ($filters['tanggal_sampai'] ?? ''));
        $filterAkunMasuk = trim((string) ($filters['akun_masuk'] ?? ''));
        $filterAkunKeluar = trim((string) ($filters['akun_keluar'] ?? ''));
        $filterSumber = trim((string) ($filters['sumber_kas'] ?? ''));

        if ($filterPeriode !== '' && strtolower($filterPeriode) !== 'all') {
            $parsed = PencatatanPeriode::parse($filterPeriode);
            $matches = PencatatanPeriode::matchValues($filterPeriode);

            $query->where(function ($q) use ($parsed, $matches) {
                $q->whereIn('periode', $matches);
                if ($parsed['tahun'] && $parsed['bulan']) {
                    $q->orWhere(function ($inner) use ($parsed) {
                        $inner->where('tahun', $parsed['tahun'])
                            ->whereIn('periode', [
                                (string) $parsed['bulan'],
                                sprintf('%02d', $parsed['bulan']),
                            ]);
                    });
                }
            });
        }

        if ($filterNoBukti !== '') {
            $query->where('no_bukti', 'like', '%' . $filterNoBukti . '%');
        }

        if ($filterKeterangan !== '') {
            $query->where('keterangan', 'like', '%' . $filterKeterangan . '%');
        }

        if ($filterTanggalDari !== '' && $filterTanggalDari !== '0000-00-00') {
            $query->whereDate('tanggal', '>=', $filterTanggalDari);
        }

        if ($filterTanggalSampai !== '' && $filterTanggalSampai !== '0000-00-00') {
            $query->whereDate('tanggal', '<=', $filterTanggalSampai);
        }

        if ($filterAkunMasuk !== '' && strtolower($filterAkunMasuk) !== 'all') {
            $this->applyAkunFilter($query, 'masuk', $filterAkunMasuk);
        }

        if ($filterAkunKeluar !== '' && strtolower($filterAkunKeluar) !== 'all') {
            $this->applyAkunFilter($query, 'keluar', $filterAkunKeluar);
        }

        if ($filterSumber !== '' && strtolower($filterSumber) !== 'all' && PencatatanJurnalTable::hasNoRefColumn()) {
            $query->where('no_ref', $filterSumber);
        }
    }

    /**
     * @param  array<string, string>  $filters
     */
    protected function calculateJurnalSaldo(array $filters, string $searchValue = ''): int
    {
        $query = akt_jurnal::query();
        $this->applyJurnalFilters($query, $filters, $searchValue);
        $aggregate = $query
            ->selectRaw('COALESCE(SUM(kredit), 0) as total_masuk, COALESCE(SUM(debet), 0) as total_keluar')
            ->first();

        return (int) ($aggregate->total_masuk ?? 0) - (int) ($aggregate->total_keluar ?? 0);
    }

    protected function mapJurnalRow(object $item, ?int $saldo = null): array
    {
        $bukti = trim((string) ($item->buktiurl ?? ''));
        if ($bukti === '' || $bukti === '-') {
            $bukti = '-';
        } elseif (str_starts_with($bukti, '/uploads/')) {
            $bukti = url($bukti);
        }

        $noBukti = trim((string) ($item->no_bukti ?? ''));
        $kode = trim((string) ($item->kode_perkiraan ?? ''));
        $namaMasuk = PencatatanAkunLookup::namaMasuk($item->NamaAkunMasuk ?? null, $kode, $noBukti);
        $namaKeluar = PencatatanAkunLookup::namaKeluar($item->NamaAkunKeluar ?? null, $kode, $noBukti);

        $row = [
            'item_id' => $item->urut,
            'urut' => $item->urut,
            'tanggal' => $item->tanggal,
            'no_bukti' => $noBukti,
            'nominal_keluar' => (int) ($item->debet ?? 0),
            'nominal_masuk' => (int) ($item->kredit ?? 0),
            'keterangan' => $item->keterangan ?? '',
            'foto' => $bukti,
            'buktiurl' => $bukti,
            'periode' => PencatatanPeriode::label($item->periode ?? '', $item->tahun ?? ''),
            'periode_raw' => $item->periode ?? '',
            'tahun' => $item->tahun ?? '',
            'sumber_kas' => trim((string) ($item->no_ref ?? '')) ?: '-',
            'akun_kas_keluar' => $namaKeluar,
            'akun_kas_masuk' => $namaMasuk,
        ];

        if ($saldo !== null) {
            $row['saldo'] = $saldo;
        }

        return $row;
    }

    protected function applyAkunFilter(Builder $query, string $jenis, string $filter): void
    {
        $match = $jenis === 'keluar'
            ? PencatatanAkunLookup::matchKeluar($filter)
            : PencatatanAkunLookup::matchMasuk($filter);

        $namaCol = $jenis === 'keluar' ? 'NamaAkunKeluar' : 'NamaAkunMasuk';
        $hasNama = $jenis === 'keluar'
            ? PencatatanJurnalTable::hasAkunKeluarColumn()
            : PencatatanJurnalTable::hasAkunMasukColumn();

        $query->where(function ($q) use ($match, $namaCol, $hasNama) {
            $hasCondition = false;

            if ($hasNama && $match['nama']) {
                $q->orWhere($namaCol, $match['nama']);
                $hasCondition = true;
            }

            if ($match['kode']) {
                if (PencatatanJurnalTable::hasKodePerkiraanColumn()) {
                    $q->orWhere('kode_perkiraan', $match['kode']);
                    $hasCondition = true;
                }
                $q->orWhere('no_bukti', $match['kode']);
                $hasCondition = true;
            }

            if (!$hasCondition) {
                $q->whereRaw('1 = 0');
            }
        });

        if ($jenis === 'keluar') {
            $query->where('debet', '>', 0);
        } else {
            $query->where('kredit', '>', 0);
        }
    }

    protected function runningSaldoBefore(Builder $filteredQuery, string $columnName, string $columnSortOrder, int $start): int
    {
        if ($start <= 0) {
            return 0;
        }

        $prior = (clone $filteredQuery)
            ->reorder()
            ->orderBy($columnName, $columnSortOrder)
            ->orderBy('urut', 'asc')
            ->skip(0)
            ->take($start)
            ->get(['debet', 'kredit']);

        return (int) $prior->sum('kredit') - (int) $prior->sum('debet');
    }

    protected function jurnalSortMap(): array
    {
        $map = [
            'tanggal' => 'tanggal',
            'no_bukti' => 'no_bukti',
            'nominal_keluar' => 'debet',
            'nominal_masuk' => 'kredit',
            'keterangan' => 'keterangan',
            'periode' => 'periode',
            'tahun' => 'tahun',
        ];

        if (PencatatanJurnalTable::hasNoRefColumn()) {
            $map['sumber_kas'] = 'no_ref';
        }

        if (PencatatanJurnalTable::hasAkunKeluarColumn()) {
            $map['akun_kas_keluar'] = 'NamaAkunKeluar';
        }
        if (PencatatanJurnalTable::hasAkunMasukColumn()) {
            $map['akun_kas_masuk'] = 'NamaAkunMasuk';
        }

        return $map;
    }
}
