<?php

namespace App\Http\Controllers\Admin\Pencatatan\Concerns;

use App\Models\akt_jurnal;
use App\Support\CloudinaryUpload;
use App\Support\PencatatanJurnalTable;
use App\Support\PencatatanPeriode;
use App\Support\PencatatanPeriodeLock;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

trait StoresJurnalKas
{
    /** @var list<string> */
    protected array $sumberKasOptions = [
        'Tunai',
        'Transfer Bank',
        'Virtual Account',
        'Lainnya',
    ];

    protected function normalizeDatetime(string $tanggal): string
    {
        $tanggal = trim(str_replace('T', ' ', $tanggal));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            return $tanggal . ' ' . now()->format('H:i:s');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $tanggal)) {
            $tanggal .= ':00';
        }

        return $tanggal;
    }

    protected function periodeFromDate(string $tanggal): array
    {
        return PencatatanPeriode::fromDate($tanggal);
    }

    protected function assertJurnalPeriodOpen(string $tanggal): void
    {
        $period = $this->periodeFromDate($tanggal);
        PencatatanPeriodeLock::assertOpen($period['tahun'], $period['periode']);
    }

    protected function canDeleteJurnalRow(mixed $tahun, mixed $periode): bool
    {
        return !PencatatanPeriodeLock::isLocked($tahun, $periode);
    }

    protected function isReversalJurnalRow(object $row): bool
    {
        $keterangan = strtoupper(trim((string) ($row->keterangan ?? '')));
        $noBukti = strtoupper(trim((string) ($row->no_bukti ?? '')));

        return str_starts_with($keterangan, 'REVERSAL ')
            || str_starts_with($noBukti, 'RV-');
    }

    protected function hasJurnalReversal(string $noBukti): bool
    {
        $noBukti = trim($noBukti);
        if ($noBukti === '') {
            return false;
        }

        return akt_jurnal::query()
            ->where(function ($q) use ($noBukti) {
                $q->where('keterangan', 'like', 'REVERSAL '.$noBukti.':%')
                    ->orWhere('keterangan', 'like', 'REVERSAL '.$noBukti.' %')
                    ->orWhere('no_tran', $noBukti);
            })
            ->exists();
    }

    protected function canReverseJurnalRow(object $row): bool
    {
        if ($this->isReversalJurnalRow($row)) {
            return false;
        }

        $noBukti = trim((string) ($row->no_bukti ?? ''));
        if ($noBukti === '' || $this->hasJurnalReversal($noBukti)) {
            return false;
        }

        return $this->canDeleteJurnalRow($row->tahun ?? null, $row->periode ?? null);
    }

    /**
     * @param  'masuk'|'keluar'  $jenis
     */
    protected function destroyJurnalByUrut(int $urut, string $jenis): void
    {
        $row = akt_jurnal::query()->where('urut', $urut)->first();
        if (!$row) {
            throw new RuntimeException('Data jurnal tidak ditemukan.');
        }

        if ($jenis === 'masuk' && (int) ($row->kredit ?? 0) <= 0) {
            throw new RuntimeException('Data bukan kas masuk.');
        }
        if ($jenis === 'keluar' && (int) ($row->debet ?? 0) <= 0) {
            throw new RuntimeException('Data bukan kas keluar.');
        }

        PencatatanPeriodeLock::assertOpen($row->tahun ?? null, $row->periode ?? null);
        $row->delete();
    }

    /**
     * Buat jurnal lawan (baris asli tetap). Kas masuk → debet; kas keluar → kredit.
     *
     * @param  'masuk'|'keluar'  $jenis
     */
    protected function reverseJurnalByUrut(int $urut, string $jenis): akt_jurnal
    {
        $row = akt_jurnal::query()->where('urut', $urut)->first();
        if (!$row) {
            throw new RuntimeException('Data jurnal tidak ditemukan.');
        }

        if ($jenis === 'masuk' && (int) ($row->kredit ?? 0) <= 0) {
            throw new RuntimeException('Data bukan kas masuk.');
        }
        if ($jenis === 'keluar' && (int) ($row->debet ?? 0) <= 0) {
            throw new RuntimeException('Data bukan kas keluar.');
        }

        if ($this->isReversalJurnalRow($row)) {
            throw new RuntimeException('Baris ini sudah merupakan jurnal reversal.');
        }

        $noBuktiAsal = trim((string) ($row->no_bukti ?? ''));
        if ($noBuktiAsal === '') {
            throw new RuntimeException('No bukti tidak valid.');
        }
        if ($this->hasJurnalReversal($noBuktiAsal)) {
            throw new RuntimeException('Jurnal ini sudah pernah di-reversal.');
        }

        PencatatanPeriodeLock::assertOpen($row->tahun ?? null, $row->periode ?? null);

        $nominal = $jenis === 'masuk'
            ? (int) ($row->kredit ?? 0)
            : (int) ($row->debet ?? 0);
        if ($nominal <= 0) {
            throw new RuntimeException('Nominal jurnal tidak valid.');
        }

        $tanggal = Carbon::now()->format('Y-m-d H:i:s');
        $ketAsal = trim((string) ($row->keterangan ?? ''));
        $keterangan = 'REVERSAL '.$noBuktiAsal.': '.($ketAsal !== '' ? $ketAsal : '-');
        if (strlen($keterangan) > 255) {
            $keterangan = substr($keterangan, 0, 255);
        }

        return $this->insertJurnal([
            'tanggal' => $tanggal,
            'no_bukti' => $this->nextNoBukti('RV', $tanggal),
            'keterangan' => $keterangan,
            'debet' => $jenis === 'masuk' ? $nominal : 0,
            'kredit' => $jenis === 'keluar' ? $nominal : 0,
            'tahun' => $row->tahun,
            'periode' => $row->periode,
            'buktiurl' => $row->buktiurl ?? '-',
            'no_ref' => trim((string) ($row->no_ref ?? '')) ?: '-',
            'no_tran' => $noBuktiAsal,
            'kode_perkiraan' => $row->kode_perkiraan ?? null,
            'NamaAkunMasuk' => $jenis === 'keluar' ? ($row->NamaAkunKeluar ?? $row->NamaAkunMasuk ?? null) : ($row->NamaAkunMasuk ?? null),
            'NamaAkunKeluar' => $jenis === 'masuk' ? ($row->NamaAkunMasuk ?? $row->NamaAkunKeluar ?? null) : ($row->NamaAkunKeluar ?? null),
        ], false);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function insertJurnal(array $extra, bool $assertPeriod = true): akt_jurnal
    {
        PencatatanJurnalTable::ensureLedgerColumns();

        if ($assertPeriod && !empty($extra['tanggal'])) {
            $this->assertJurnalPeriodOpen((string) $extra['tanggal']);
        }

        $payload = array_merge([
            'no_tran' => null,
            'no_ref' => null,
            'kode_perkiraan' => null,
        ], $extra);

        if (!isset($payload['urut'])) {
            $payload['urut'] = ((int) akt_jurnal::query()->max('urut')) + 1;
        }

        $allowed = PencatatanJurnalTable::columns();
        if ($allowed !== []) {
            $payload = array_intersect_key($payload, array_flip($allowed));
        } else {
            if (!PencatatanJurnalTable::hasAkunMasukColumn()) {
                unset($payload['NamaAkunMasuk']);
            }
            if (!PencatatanJurnalTable::hasAkunKeluarColumn()) {
                unset($payload['NamaAkunKeluar']);
            }
            if (!PencatatanJurnalTable::hasBuktiUrlColumn()) {
                unset($payload['buktiurl']);
            }
        }

        return akt_jurnal::query()->create($payload);
    }

    protected function nextNoBukti(string $prefix, string $tanggal): string
    {
        $dateKey = Carbon::parse($this->normalizeDatetime($tanggal))->format('Ymd');
        $like = $prefix . '-' . $dateKey . '-%';
        $last = akt_jurnal::query()
            ->where('no_bukti', 'like', $like)
            ->orderByDesc('no_bukti')
            ->value('no_bukti');

        $seq = 1;
        if (is_string($last) && preg_match('/-(\d+)$/', $last, $m)) {
            $seq = ((int) $m[1]) + 1;
        }

        return $prefix . '-' . $dateKey . '-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }

    protected function resolveBuktiUrl(Request $request): string
    {
        if (!$request->hasFile('bukti_foto')) {
            $buktiurl = trim((string) $request->input('buktiurl', ''));

            return $buktiurl !== '' ? $buktiurl : '-';
        }

        $file = $request->file('bukti_foto');
        if (!$file || !$file->isValid()) {
            throw new RuntimeException('File foto bukti tidak valid.');
        }

        $cloudName = (string) config('services.cloudinary.cloud_name');
        $apiKey = (string) config('services.cloudinary.api_key');
        $apiSecret = (string) config('services.cloudinary.api_secret');

        if ($cloudName !== '' && $apiKey !== '' && $apiSecret !== '') {
            $uploaded = CloudinaryUpload::image($file);

            return $uploaded['secure_url'];
        }

        $dir = public_path('uploads/bukti_kas');
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Folder upload bukti foto tidak bisa dibuat.');
        }

        $name = now()->format('YmdHis') . '_' . Str::random(6) . '.' . $file->getClientOriginalExtension();
        $file->move($dir, $name);

        return '/uploads/bukti_kas/' . $name;
    }
}
