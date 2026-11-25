<?php

namespace App\Http\Controllers\Admin\Pencatatan;

use App\Http\Controllers\Admin\Pencatatan\Concerns\StoresJurnalKas;
use App\Http\Controllers\Controller;
use App\Models\akun_kas_masuk;
use App\Models\akt_jurnal;
use App\Models\sccttran;
use App\Models\ValidationMessage;
use App\Support\PencatatanAkunTable;
use App\Support\PencatatanJurnalTable;
use App\Support\PencatatanPeriode;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class InputKasTopUpController extends Controller
{
    use StoresJurnalKas;

    public string $title = 'Pencatatan Sederhana';

    public string $mainTitle = 'Input Kas Top Up';

    public string $dataTitle = 'Input Kas Top Up ke Jurnal';

    public function index()
    {
        PencatatanAkunTable::ensureMasuk();
        PencatatanJurnalTable::ensureLedgerColumns();

        $now = Carbon::now();
        $tahunList = range((int) $now->year - 2, (int) $now->year + 1);
        rsort($tahunList);

        $akunMasuk = collect();
        if (Schema::connection('DATA_MYSQL')->hasTable('akun_kas_masuk')) {
            $akunMasuk = akun_kas_masuk::query()
                ->where('KodeAkunMasuk', '!=', 'SA')
                ->orderBy('KodeAkunMasuk')
                ->get(['KodeAkunMasuk', 'NamaAkunMasuk']);
        }

        return view('admin.pencatatan.input_kas_top_up.index', [
            'title' => $this->title,
            'mainTitle' => $this->mainTitle,
            'dataTitle' => $this->dataTitle,
            'tahunList' => $tahunList,
            'bulanList' => PencatatanPeriode::BULAN,
            'tahunDefault' => (int) $now->year,
            'bulanDefault' => (int) $now->month,
            'akunMasuk' => $akunMasuk,
            'previewUrl' => route('admin.pencatatan-sederhana.input-kas-top-up.preview'),
            'storeUrl' => route('admin.pencatatan-sederhana.input-kas-top-up.store'),
        ]);
    }

    public function preview(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'tahun' => ['required', 'integer', 'digits:4', 'min:2000', 'max:2099'],
                'periode' => ['required', 'integer', 'min:1', 'max:12'],
            ],
            ValidationMessage::messages(),
            ValidationMessage::attributes()
        );

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        try {
            $tahun = (string) $request->input('tahun');
            $periode = (string) ((int) $request->input('periode'));
            $summary = $this->buildPeriodSummary($tahun, $periode);

            return response()->json([
                'message' => 'OK',
                'label' => PencatatanPeriode::label($periode, $tahun),
                'summary' => $summary,
            ]);
        } catch (\Throwable $e) {
            Log::error('InputKasTopUp preview failed', ['message' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'tahun' => ['required', 'integer', 'digits:4', 'min:2000', 'max:2099'],
                'periode' => ['required', 'integer', 'min:1', 'max:12'],
                'kode_akun' => ['required', 'max:20'],
            ],
            ValidationMessage::messages(),
            ValidationMessage::attributes()
        );

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $tahun = (string) $request->input('tahun');
        $periode = (string) ((int) $request->input('periode'));
        $kodeAkun = trim((string) $request->input('kode_akun', ''));
        $label = PencatatanPeriode::label($periode, $tahun);

        $akun = akun_kas_masuk::query()->where('KodeAkunMasuk', $kodeAkun)->first();
        if (!$akun) {
            return response()->json(['message' => 'Akun kas masuk tidak ditemukan'], 422);
        }

        try {
            $summary = $this->buildPeriodSummary($tahun, $periode);
            if ($summary['duplicate_in_source'] > 0) {
                return response()->json([
                    'message' => 'Ada NOREFF ganda di data Top Up periode '.$label.'. Perbaiki data scctran dulu.',
                    'summary' => $summary,
                ], 422);
            }

            if ($summary['empty_noreff'] > 0) {
                return response()->json([
                    'message' => 'Ada transaksi Top Up tanpa NOREFF di periode '.$label.'. Tidak bisa disimpan.',
                    'summary' => $summary,
                ], 422);
            }

            if ($summary['siap'] <= 0) {
                return response()->json([
                    'message' => $summary['total'] <= 0
                        ? 'Tidak ada Top Up di periode '.$label.'.'
                        : 'Semua Top Up periode '.$label.' sudah tercatat di jurnal (no_ref unik).',
                    'summary' => $summary,
                ], 422);
            }

            DB::connection('DATA_MYSQL')->beginTransaction();

            $inserted = 0;
            $nominalInserted = 0;
            $seen = [];

            foreach ($summary['rows'] as $row) {
                if (($row['status'] ?? '') !== 'siap') {
                    continue;
                }

                $noreff = trim((string) ($row['noreff'] ?? ''));
                if ($noreff === '' || isset($seen[$noreff])) {
                    continue;
                }
                $seen[$noreff] = true;

                if ($this->noRefExistsInPeriod($tahun, $periode, $noreff)) {
                    continue;
                }

                $tanggal = (string) ($row['tanggal'] ?? '');
                if ($tanggal === '') {
                    $tanggal = Carbon::create((int) $tahun, (int) $periode, 1)->format('Y-m-d H:i:s');
                }

                $kredit = (int) ($row['nominal'] ?? 0);
                if ($kredit <= 0) {
                    continue;
                }

                $keterangan = $this->buildKeterangan($row);
                $this->insertJurnal([
                    'tanggal' => $this->normalizeDatetime($tanggal),
                    'no_bukti' => $this->nextNoBukti('TU', $tanggal),
                    'keterangan' => $keterangan,
                    'debet' => 0,
                    'kredit' => $kredit,
                    'tahun' => $tahun,
                    'periode' => $periode,
                    'buktiurl' => '-',
                    'no_ref' => $noreff,
                    'kode_perkiraan' => $akun->KodeAkunMasuk,
                    'NamaAkunMasuk' => $akun->NamaAkunMasuk,
                    'NamaAkunKeluar' => null,
                ]);

                $inserted++;
                $nominalInserted += $kredit;
            }

            if ($inserted <= 0) {
                DB::connection('DATA_MYSQL')->rollBack();

                return response()->json([
                    'message' => 'Tidak ada baris baru yang disimpan. Cek apakah NOREFF sudah ada di periode yang sama.',
                    'summary' => $this->buildPeriodSummary($tahun, $periode),
                ], 422);
            }

            DB::connection('DATA_MYSQL')->commit();

            $fresh = $this->buildPeriodSummary($tahun, $periode);

            return response()->json([
                'message' => "Berhasil menyimpan {$inserted} Top Up ke jurnal periode {$label}.",
                'inserted' => $inserted,
                'nominal_inserted' => $nominalInserted,
                'summary' => $fresh,
            ]);
        } catch (\Throwable $e) {
            DB::connection('DATA_MYSQL')->rollBack();
            Log::error('InputKasTopUp store failed', ['message' => $e->getMessage()]);

            return response()->json([
                'message' => 'Gagal menyimpan Top Up ke jurnal: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * @return array{
     *   total:int,
     *   siap:int,
     *   sudah:int,
     *   empty_noreff:int,
     *   duplicate_in_source:int,
     *   nominal_total:int,
     *   nominal_siap:int,
     *   rows:list<array<string,mixed>>
     * }
     */
    private function buildPeriodSummary(string $tahun, string $periode): array
    {
        $rows = $this->fetchTopUpRows($tahun, $periode);
        $noreffCounts = [];
        foreach ($rows as $row) {
            $key = trim((string) ($row['NOREFF'] ?? ''));
            if ($key === '') {
                continue;
            }
            $noreffCounts[$key] = ($noreffCounts[$key] ?? 0) + 1;
        }

        $duplicateInSource = 0;
        foreach ($noreffCounts as $count) {
            if ($count > 1) {
                $duplicateInSource += $count - 1;
            }
        }

        $existingRefs = $this->existingNoRefsInPeriod($tahun, $periode);

        $mapped = [];
        $siap = 0;
        $sudah = 0;
        $empty = 0;
        $nominalTotal = 0;
        $nominalSiap = 0;
        $seenSiap = [];

        foreach ($rows as $row) {
            $noreff = trim((string) ($row['NOREFF'] ?? ''));
            $nominal = (int) ($row['KREDIT'] ?? 0);
            $nominalTotal += max(0, $nominal);

            $status = 'siap';
            if ($noreff === '') {
                $status = 'kosong';
                $empty++;
            } elseif (isset($existingRefs[$noreff])) {
                $status = 'sudah';
                $sudah++;
            } elseif (($noreffCounts[$noreff] ?? 0) > 1 && isset($seenSiap[$noreff])) {
                $status = 'ganda';
            } else {
                if ($noreff !== '') {
                    $seenSiap[$noreff] = true;
                }
                $siap++;
                $nominalSiap += max(0, $nominal);
            }

            $mapped[] = [
                'urut' => $row['urut'] ?? null,
                'tanggal' => $this->formatTrxDate($row['TRXDATE'] ?? null),
                'noreff' => $noreff !== '' ? $noreff : null,
                'nis' => trim((string) ($row['NOCUST'] ?? '')) ?: null,
                'nama' => trim((string) ($row['NMCUST'] ?? '')) ?: null,
                'nominal' => $nominal,
                'status' => $status,
            ];
        }

        return [
            'total' => count($mapped),
            'siap' => $siap,
            'sudah' => $sudah,
            'empty_noreff' => $empty,
            'duplicate_in_source' => $duplicateInSource,
            'nominal_total' => $nominalTotal,
            'nominal_siap' => $nominalSiap,
            'rows' => $mapped,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchTopUpRows(string $tahun, string $periode): array
    {
        $start = Carbon::create((int) $tahun, (int) $periode, 1)->startOfDay();
        $end = (clone $start)->endOfMonth();

        $query = $this->topUpVaScope(
            sccttran::query()
                ->leftJoin('scctcust', 'scctcust.CUSTID', '=', 'sccttran.CUSTID')
        )
            ->whereBetween('sccttran.TRXDATE', [
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
            ]);

        $schoolCode = trim((string) (Auth::user()->sekolah ?? ''));
        if ($schoolCode !== '') {
            $query->where('scctcust.CODE01', $schoolCode);
        }

        return $query
            ->orderBy('sccttran.TRXDATE')
            ->orderBy('sccttran.urut')
            ->get([
                'sccttran.urut',
                'sccttran.TRXDATE',
                'sccttran.NOREFF',
                'sccttran.KREDIT',
                'scctcust.NOCUST',
                'scctcust.NMCUST',
                'scctcust.NUM2ND',
            ])
            ->map(fn ($item) => $item->toArray())
            ->all();
    }

    private function topUpVaScope($query, string $tablePrefix = 'sccttran.')
    {
        $metodeColumn = $tablePrefix.'METODE';
        $isReversalColumn = $tablePrefix.'isreversal';

        return $query
            ->whereRaw("UPPER(TRIM(COALESCE({$metodeColumn}, ''))) = 'TOP UP'")
            ->where(function ($q) use ($isReversalColumn) {
                $q->whereNull($isReversalColumn)
                    ->orWhere($isReversalColumn, 0)
                    ->orWhere($isReversalColumn, '0');
            });
    }

    /**
     * @return array<string, true>
     */
    private function existingNoRefsInPeriod(string $tahun, string $periode): array
    {
        if (!PencatatanJurnalTable::hasNoRefColumn()) {
            throw new RuntimeException('Kolom no_ref belum tersedia di tabel jurnal.');
        }

        $periodeValues = PencatatanPeriode::matchValues($tahun.'-'.$periode);

        $refs = akt_jurnal::query()
            ->where('tahun', $tahun)
            ->where(function ($q) use ($periode, $periodeValues) {
                $q->where('periode', $periode)
                    ->orWhereIn('periode', $periodeValues);
            })
            ->whereNotNull('no_ref')
            ->where('no_ref', '!=', '')
            ->pluck('no_ref')
            ->all();

        $map = [];
        foreach ($refs as $ref) {
            $key = trim((string) $ref);
            if ($key !== '') {
                $map[$key] = true;
            }
        }

        return $map;
    }

    private function noRefExistsInPeriod(string $tahun, string $periode, string $noreff): bool
    {
        $noreff = trim($noreff);
        if ($noreff === '') {
            return false;
        }

        $periodeValues = PencatatanPeriode::matchValues($tahun.'-'.$periode);

        return akt_jurnal::query()
            ->where('tahun', $tahun)
            ->where(function ($q) use ($periode, $periodeValues) {
                $q->where('periode', $periode)
                    ->orWhereIn('periode', $periodeValues);
            })
            ->where('no_ref', $noreff)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function buildKeterangan(array $row): string
    {
        $nis = trim((string) ($row['nis'] ?? ''));
        $nama = trim((string) ($row['nama'] ?? ''));
        $noreff = trim((string) ($row['noreff'] ?? ''));

        $parts = ['Top Up VA'];
        if ($nis !== '') {
            $parts[] = $nis;
        }
        if ($nama !== '') {
            $parts[] = $nama;
        }
        if ($noreff !== '') {
            $parts[] = '('.$noreff.')';
        }

        $text = implode(' ', $parts);

        return strlen($text) > 255 ? substr($text, 0, 255) : $text;
    }

    private function formatTrxDate(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
