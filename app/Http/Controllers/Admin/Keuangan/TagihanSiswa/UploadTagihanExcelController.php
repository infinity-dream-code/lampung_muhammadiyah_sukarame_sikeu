<?php

namespace App\Http\Controllers\Admin\Keuangan\TagihanSiswa;

use App\Http\Controllers\Controller;
use App\Imports\Keuangan\TagihanSiswa\ImportTagihanExcel;
use App\Models\mst_tagihan;
use App\Models\scctbill;
use App\Models\scctbill_detail;
use App\Models\scctcust;
use App\Models\ValidationMessage;
use App\Support\InputTagihanProcedure;
use App\Support\SchoolScope;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\HeadingRowImport;
use Maatwebsite\Excel\Validators\ValidationException;

class UploadTagihanExcelController extends Controller
{
    public string $title = 'Keuangan';
    public string $mainTitle = 'Tagihan Siswa';
    public string $dataTitle = 'Buat Tagihan Excel';
    public string $cacheKey = ImportTagihanExcel::CACHE_KEY;

    public ?string $sekolah = null;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if (Auth::check()) {
                $this->sekolah = Auth::user()->sekolah;
                $this->cacheKey = ImportTagihanExcel::CACHE_KEY.'_'.Auth::id();
            }

            return $next($request);
        });
    }

    public function index()
    {
        $currentYear = (int) date('Y');

        $data['title'] = $this->title;
        $data['mainTitle'] = $this->mainTitle;
        $data['dataTitle'] = $this->dataTitle;
        $data['columnsUrl'] = route('admin.keuangan.tagihan-siswa.upload-tagihan-excel.get-column');
        $data['datasUrl'] = route('admin.keuangan.tagihan-siswa.upload-tagihan-excel.get-data');
        $data['periode_tahun_list'] = range($currentYear - 2, $currentYear + 5);
        $data['periode_tahun_default'] = $currentYear;
        $data['periode_bulan_default'] = (int) date('m');
        $data['tagihan'] = mst_tagihan::orderBy('urut', 'asc')->get();

        return view('admin.keuangan.tagihan_siswa.upload_tagihan_excel.index', $data);
    }

    public function getColumn()
    {
        return [
            ['data' => null, 'name' => 'No', 'className' => 'text-center', 'columnType' => 'row'],
            ['data' => 'nis', 'name' => 'NIS', 'searchable' => true, 'orderable' => true],
            ['data' => 'name', 'name' => 'Nama', 'searchable' => true, 'orderable' => true],
            ['data' => 'status', 'name' => 'Status', 'searchable' => true, 'orderable' => true, 'columnType' => 'importstatus'],
            ['data' => 'keterangan', 'name' => 'Keterangan', 'searchable' => true, 'orderable' => true],
            ['data' => 'unit', 'name' => 'Unit', 'searchable' => true, 'orderable' => true],
            ['data' => 'kelas', 'name' => 'Kelas', 'searchable' => true, 'orderable' => true],
            ['data' => 'kelompok', 'name' => 'Kelompok', 'searchable' => true, 'orderable' => true],
            ['data' => 'angkatan', 'name' => 'Angkatan', 'searchable' => true, 'orderable' => true],
            ['data' => 'gender', 'name' => 'Jenis Kelamin', 'searchable' => true, 'orderable' => true],
            ['data' => 'ortu', 'name' => 'Ortu / Wali', 'searchable' => true, 'orderable' => true],
            ['data' => 'alamat', 'name' => 'Alamat', 'searchable' => true, 'orderable' => true],
            ['data' => 'nominal', 'name' => 'Nominal', 'searchable' => true, 'orderable' => true, 'columnType' => 'currency'],
        ];
    }

    public function getData(Request $request)
    {
        $draw = $request->get('draw');
        $cachedData = Cache::get($this->cacheKey, []);
        $nisCount = count($cachedData);

        $select = [
            'scctcust.NMCUST',
            'scctcust.NOCUST',
            'scctcust.CODE02',
            'scctcust.DESC02',
            'scctcust.DESC03',
            'scctcust.DESC04',
            'scctcust.CODE04',
            'scctcust.DESC05',
            'scctcust.GENUS',
        ];

        $records = collect($cachedData)->map(function ($item) use ($select) {
            $nis = (string) ($item['nocust'] ?? $item['nis'] ?? '');
            $siswa = scctcust::select($select)->where('scctcust.NOCUST', $nis);
            SchoolScope::apply($siswa, 'scctcust', $this->sekolah);
            $siswa = $siswa->first();

            return [
                'nis' => $nis,
                'name' => $item['nama'] ?: ($siswa->NMCUST ?? null),
                'unit' => $item['unit'] ?: ($siswa->CODE02 ?? null),
                'kelas' => $item['kelas'] ?: ($siswa->DESC02 ?? null),
                'kelompok' => $item['kelompok'] ?: ($siswa->DESC03 ?? null),
                'angkatan' => $item['angkatan'] ?: ($siswa->DESC04 ?? null),
                'gender' => $item['gender'] ?: ($siswa->CODE04 ?? null),
                'alamat' => $item['alamat'] ?: ($siswa->DESC05 ?? null),
                'ortu' => $item['ortu'] ?: ($siswa->GENUS ?? null),
                'nominal' => $item['nominal'] ?? null,
                'status' => $item['status'] ?? 0,
                'keterangan' => $item['keterangan'] ?? null,
            ];
        });

        return response()->json([
            'draw' => intval($draw),
            'recordsTotal' => $nisCount,
            'recordsFiltered' => $nisCount,
            'data' => $records,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate(
            [
                'fileImport' => [
                    'required',
                    'file',
                    'mimes:xls,xlsx',
                    'mimetypes:application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/octet-stream',
                    'max:1024',
                ],
            ],
            ValidationMessage::messages(),
            ValidationMessage::attributes()
        );

        $file = $request->file('fileImport');

        try {
            $headingsData = (new HeadingRowImport)->toArray($file);
            if (empty($headingsData) || !isset($headingsData[0][0])) {
                throw new \Exception('Tidak dapat membaca judul kolom dari file. Pastikan file memiliki header yang sesuai.');
            }

            $headings = array_map(
                static fn ($heading) => strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $heading) ?? (string) $heading),
                $headingsData[0][0]
            );

            $requiredColumns = ImportTagihanExcel::REQUIRED_COLUMNS;
            $missingColumns = [];
            $hasNis = in_array('nis', $headings, true) || in_array('nocust', $headings, true);
            if (!$hasNis) {
                $missingColumns[] = 'NIS';
            }
            foreach ($requiredColumns as $column) {
                if (!in_array($column, $headings, true)) {
                    $missingColumns[] = $column;
                }
            }

            if (!empty($missingColumns)) {
                $formattedMissingColumns = implode(', ', array_map([$this, 'displayColumn'], $missingColumns));
                throw new \Exception(
                    "Kolom {$formattedMissingColumns} tidak ditemukan.<br><hr>".
                    'Kolom wajib: <b>NIS dan Nominal</b>. Tagihan hanya memakai NIS, bukan nodaf.'
                );
            }

            Cache::forget($this->cacheKey);
            Excel::import(new ImportTagihanExcel($this->sekolah, $this->cacheKey), $file);

            $data = Cache::get($this->cacheKey, []);
            if (empty($data)) {
                throw new \Exception('File berhasil dibaca, tetapi tidak ada baris data yang dapat diproses. Pastikan file berisi NIS dan Nominal.');
            }

            Log::info('Upload tagihan excel berhasil', [
                'user_id' => auth()->id(),
                'file_name' => $file->getClientOriginalName(),
                'row_count' => count($data),
            ]);

            return response()->json(['message' => 'Sukses, data tagihan telah diimport, silahkan periksa kembali', 'data' => $data], 200);
        } catch (ValidationException $e) {
            $errorMessages = $e->errors();
            $errorMessage = $errorMessages['error'][0] ?? 'Terjadi kesalahan saat melakukan import data.';

            return response()->json(['message' => $errorMessage, 'error' => $errorMessages], 422);
        } catch (\Throwable $e) {
            Log::error('Upload tagihan excel gagal', [
                'user_id' => auth()->id(),
                'file_name' => $file?->getClientOriginalName(),
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return response()->json([
                'message' => "Gagal!<br> tidak dapat melakukan {$this->mainTitle}.<hr> {$e->getMessage()}",
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function validateExcel(Request $request)
    {
        $request->validate([
            'tagihan' => ['required'],
            'periode_tahun' => ['required', 'integer', 'digits:4', 'min:2000', 'max:2099'],
            'periode_bulan' => ['required', 'integer', 'min:1', 'max:12'],
        ], ValidationMessage::messages(), ValidationMessage::attributes());

        $data = Cache::get($this->cacheKey);
        if (empty($data)) {
            return response()->json(['message' => 'Silahkan import data tagihan terlebih dahulu'], 422);
        }

        $tagihan = mst_tagihan::where('urut', $request->tagihan)->first();
        if (!$tagihan) {
            return response()->json(['message' => 'Tagihan tidak ditemukan, silahkan muat ulang halaman!'], 422);
        }

        $nmTagihan = trim((string) $tagihan->tagihan);
        $isNyicil = (string) ((int) ($tagihan->isINSTALLMENT ?? 0));

        // Semua jenis tagihan: tahun + bulan (contoh 202609).
        $bta = sprintf('%04d%02d', (int) $request->periode_tahun, (int) $request->periode_bulan);

        try {
            $skippedInactive = [];
            $skippedInvalid = [];
            $insertedCount = 0;
            $failedRows = [];

            foreach ($data as $item) {
                if ((int) ($item['status'] ?? 0) !== 1) {
                    $skippedInvalid[] = trim(($item['nocust'] ?? $item['nis'] ?? '-').' - '.($item['keterangan'] ?? 'Data tidak valid'));
                    continue;
                }

                $nocust = trim((string) ($item['nocust'] ?? $item['nis'] ?? ''));
                $nominal = (int) ($item['nominal'] ?? 0);
                if ($nocust === '' || $nocust === '-' || $nominal <= 0) {
                    $skippedInvalid[] = trim($nocust.' - Data NIS/Nominal tidak valid');
                    continue;
                }

                $siswa = scctcust::query()
                    ->where(function ($q) use ($nocust) {
                        $q->where('NOCUST', $nocust)
                            ->orWhereRaw('TRIM(NOCUST) = ?', [$nocust]);
                    });
                SchoolScope::apply($siswa, 'scctcust', $this->sekolah);
                $siswa = $siswa->first();
                if (!$siswa) {
                    return response()->json(['message' => "Siswa dengan NIS {$nocust} tidak ditemukan."], 422);
                }

                if ((int) ($siswa->STCUST ?? 0) === 0) {
                    $skippedInactive[] = trim($nocust.' - '.($siswa->NMCUST ?? 'Tanpa Nama'));
                    continue;
                }

                try {
                    InputTagihanProcedure::call(
                        (string) $siswa->NOCUST,
                        $nominal,
                        $nmTagihan,
                        $bta,
                        $bta,
                        $isNyicil,
                    );
                } catch (\Throwable $e) {
                    Log::warning('input-tagihan.procedure.fail_fallback', [
                        'nocust' => $nocust,
                        'message' => $e->getMessage(),
                    ]);
                }

                // Pastikan baris benar-benar ada (SP kadang sukses tanpa insert)
                if (!$this->tagihanExistsForSiswa((int) $siswa->CUSTID, $nmTagihan, $bta)) {
                    $this->createTagihanDirect($siswa, $nominal, $nmTagihan, $bta, $isNyicil);
                }

                if (!$this->tagihanExistsForSiswa((int) $siswa->CUSTID, $nmTagihan, $bta)) {
                    $failedRows[] = $nocust;
                    continue;
                }

                $this->normalizeNewBillFlags(
                    (int) $siswa->CUSTID,
                    $nmTagihan,
                    $bta,
                    (int) $isNyicil === 1 ? 1 : 0
                );

                $this->ensureBillDetailForSiswa(
                    (int) $siswa->CUSTID,
                    $nmTagihan,
                    $bta,
                    $nominal,
                    $tagihan->kodeAkun()
                );

                $insertedCount++;
            }

            Cache::forget($this->cacheKey);
            $this->bumpDataTagihanCache();

            if ($insertedCount === 0 && empty($failedRows)) {
                return response()->json([
                    'message' => 'Tidak ada tagihan yang disimpan. Semua baris dilewati (invalid/nonaktif).',
                ], 422);
            }

            $message = "Data tagihan disimpan. Berhasil dibuat untuk {$insertedCount} siswa.";
            if (!empty($skippedInactive)) {
                $message .= '<hr>Tagihan tidak dibuat untuk siswa nonaktif: '.count($skippedInactive).' siswa.<br>'.
                    implode('<br>', $skippedInactive);
            }
            if (!empty($skippedInvalid)) {
                $message .= '<hr>Baris tidak diproses karena data tidak valid: '.count($skippedInvalid).' baris.';
            }
            if (!empty($failedRows)) {
                $message .= '<hr>Gagal menyimpan tagihan untuk NIS: '.implode(', ', $failedRows);
            }

            return response()->json([
                'message' => $message,
                'inserted' => $insertedCount,
                'bta' => $bta,
                'tagihan' => $nmTagihan,
            ], empty($failedRows) || $insertedCount > 0 ? 200 : 422);
        } catch (\Throwable $e) {
            Log::error('Simpan tagihan excel gagal', [
                'user_id' => auth()->id(),
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return response()->json([
                'message' => 'Terjadi kesalahan saat menyimpan data. Silakan coba lagi.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    private function tagihanExistsForSiswa(int $custId, string $billNm, string $billac): bool
    {
        return scctbill::query()
            ->where('CUSTID', $custId)
            ->where('BILLNM', $billNm)
            ->where('BILLAC', $billac)
            ->where('FSTSBolehBayar', 1)
            ->exists();
    }

    private function createTagihanDirect(scctcust $siswa, int $nominal, string $billNm, string $billac, string $isNyicil): void
    {
        $custId = (int) $siswa->CUSTID;
        $existing = scctbill::query()
            ->where('CUSTID', $custId)
            ->where('BILLNM', $billNm)
            ->where('BILLAC', $billac)
            ->where('FSTSBolehBayar', 1)
            ->first();

        if ($existing) {
            $existing->increment('BILLAM', $nominal);
            if ($existing->PAYMENTLEFT !== null) {
                $existing->increment('PAYMENTLEFT', $nominal);
            } else {
                $existing->update([
                    'PAYMENTLEFT' => max(0, (int) $existing->BILLAM - (int) ($existing->BILLPAID ?? 0)),
                ]);
            }

            return;
        }

        $maxUrut = (int) scctbill::where('CUSTID', $custId)->max('FUrutan');
        $newUrut = $maxUrut + 1;
        $billYear = substr($billac, 0, 4);
        $billMonth = strlen($billac) >= 6 ? substr($billac, 4, 2) : date('m');
        $newBillCd = "{$billYear}/{$billMonth}-{$newUrut}";
        $suffix = 0;
        while (scctbill::where('CUSTID', $custId)->where('BILLCD', $newBillCd)->exists()) {
            $suffix++;
            $newBillCd = "{$billYear}/{$billMonth}-{$newUrut}-{$suffix}";
        }

        scctbill::create([
            'CUSTID' => $custId,
            'BILLCD' => $newBillCd,
            'BILLAC' => $billac,
            'BILLNM' => $billNm,
            'BILLAM' => $nominal,
            'BILLPAID' => 0,
            'PAYMENTLEFT' => $nominal,
            'PAIDST' => 0,
            'PAIDDT' => null,
            'FSTSBolehBayar' => 1,
            'FUrutan' => $newUrut,
            'FTGLTagihan' => Carbon::now(),
            'BTA' => $billac,
            'INSTALLMENT' => 0,
            'FLPART' => null,
            'isINSTALLABLE' => ((int) $isNyicil === 1) ? 1 : 0,
        ]);

        $this->ensureBillDetailForSiswa($custId, $billNm, $billac, $nominal, mst_tagihan::kodePenerimaan());

        Log::info('input-tagihan.direct.ok', [
            'custid' => $custId,
            'nocust' => $siswa->NOCUST,
            'billnm' => $billNm,
            'billac' => $billac,
            'nominal' => $nominal,
        ]);
    }

    private function normalizeNewBillFlags(int $custId, string $billNm, string $billac, int $isInstallable): void
    {
        scctbill::query()
            ->where('CUSTID', $custId)
            ->where('BILLNM', $billNm)
            ->where('BILLAC', $billac)
            ->where('FSTSBolehBayar', 1)
            ->update([
                'INSTALLMENT' => 0,
                'FLPART' => null,
                'isINSTALLABLE' => $isInstallable,
            ]);
    }

    private function ensureBillDetailForSiswa(int $custId, string $billNm, string $billac, int $nominal, string $kodePost): void
    {
        $bill = scctbill::query()
            ->where('CUSTID', $custId)
            ->where('BILLNM', $billNm)
            ->where('BILLAC', $billac)
            ->where('FSTSBolehBayar', 1)
            ->orderByDesc('FUrutan')
            ->first();

        if (!$bill) {
            return;
        }

        $kodePost = trim($kodePost) !== '' ? trim($kodePost) : mst_tagihan::kodePenerimaan();
        $exists = scctbill_detail::query()
            ->where('CUSTID', $custId)
            ->where('BILLCD', $bill->BILLCD)
            ->where('KodePost', $kodePost)
            ->exists();

        if ($exists) {
            return;
        }

        $tahun = substr((string) $billac, 0, 4);
        $bulan = strlen((string) $billac) >= 6 ? substr((string) $billac, 4, 2) : date('m');

        scctbill_detail::create([
            'KodePost' => $kodePost,
            'CUSTID' => $custId,
            'BILLAM' => $nominal,
            'tahun' => $tahun,
            'periode' => $bulan,
            'BILLCD' => $bill->BILLCD,
        ]);
    }

    private function bumpDataTagihanCache(): void
    {
        $key = Str::slug('data_tagihan').'_cache_version';
        if (!Cache::has($key)) {
            Cache::forever($key, 1);
        }
        Cache::increment($key);
    }

    public function clear()
    {
        Cache::forget($this->cacheKey);
        Cache::forget(ImportTagihanExcel::CACHE_KEY);

        return response()->json(['message' => 'Data import telah dibersihkan']);
    }

    private function displayColumn(string $column): string
    {
        return ImportTagihanExcel::COLUMN_LABELS[$column]
            ?? match ($column) {
                'nocust' => 'NIS',
                default => ucwords(str_replace('_', ' ', $column)),
            };
    }
}
