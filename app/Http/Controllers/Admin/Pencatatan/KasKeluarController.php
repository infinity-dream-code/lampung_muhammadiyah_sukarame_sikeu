<?php

namespace App\Http\Controllers\Admin\Pencatatan;

use App\Http\Controllers\Admin\Pencatatan\Concerns\StoresJurnalKas;
use App\Http\Controllers\Controller;
use App\Models\akun_kas_keluar;
use App\Models\akt_jurnal;
use App\Models\ValidationMessage;
use App\Support\PencatatanAkunLookup;
use App\Support\PencatatanAkunTable;
use App\Support\PencatatanJurnalTable;
use App\Support\PencatatanPeriode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class KasKeluarController extends Controller
{
    use StoresJurnalKas;

    public string $title = 'Pencatatan Sederhana';
    public string $mainTitle = 'Kas Keluar';
    public string $dataTitle = 'Kas Keluar';

    public function index()
    {
        $akunKeluar = collect();
        try {
            PencatatanJurnalTable::ensureLedgerColumns();
            PencatatanAkunTable::ensureKeluar();
            if (Schema::connection('DATA_MYSQL')->hasTable('akun_kas_keluar')) {
                $akunKeluar = akun_kas_keluar::query()
                    ->orderBy('KodeAkunKeluar')
                    ->get(['KodeAkunKeluar', 'NamaAkunKeluar']);
            }
        } catch (\Throwable $e) {
            Log::warning('KasKeluar index lookup failed', ['message' => $e->getMessage()]);
        }

        return view('admin.pencatatan.kas_keluar.index', [
            'title' => $this->title,
            'mainTitle' => $this->mainTitle,
            'dataTitle' => $this->dataTitle,
            'akunKeluar' => $akunKeluar,
            'sumberKasOptions' => $this->sumberKasOptions,
            'columnsUrl' => route('admin.pencatatan-sederhana.kas-keluar.get-column'),
            'datasUrl' => route('admin.pencatatan-sederhana.kas-keluar.get-data'),
            'storeUrl' => route('admin.pencatatan-sederhana.kas-keluar.store'),
        ]);
    }

    public function getColumn()
    {
        return [
            ['data' => null, 'name' => 'no', 'className' => 'text-center', 'columnType' => 'row'],
            ['data' => 'tanggal', 'name' => 'Tanggal', 'searchable' => true, 'orderable' => true],
            ['data' => 'no_bukti', 'name' => 'No Bukti', 'searchable' => true, 'orderable' => true],
            [
                'data' => 'debet',
                'name' => 'Nominal Kas Keluar',
                'searchable' => false,
                'orderable' => true,
                'columnType' => 'currency',
                'className' => 'text-end',
            ],
            ['data' => 'keterangan', 'name' => 'Keterangan', 'searchable' => true, 'orderable' => true],
            ['data' => 'NamaAkunKeluar', 'name' => 'Akun Kas Keluar', 'searchable' => true, 'orderable' => true],
            ['data' => 'sumber_kas', 'name' => 'Sumber Kas', 'searchable' => true, 'orderable' => true],
            ['data' => 'periode', 'name' => 'Periode', 'searchable' => true, 'orderable' => true],
        ];
    }

    public function getData(Request $request)
    {
        try {
            PencatatanJurnalTable::ensureLedgerColumns();
            $draw = (int) $request->get('draw', 0);
            $start = (int) $request->get('start', 0);
            $rowperpage = (int) $request->get('length', 10);
            $searchArr = $request->get('search', []);
            $searchValue = trim((string) ($searchArr['value'] ?? ''));

            $columnIndexArr = $request->get('order', []);
            $columnNameArr = $request->get('columns', []);
            $columnName = 'tanggal';
            $columnSortOrder = 'desc';
            if (!empty($columnIndexArr)) {
                $columnIndex = (int) ($columnIndexArr[0]['column'] ?? 0);
                $columnSortOrder = strtolower((string) ($columnIndexArr[0]['dir'] ?? 'desc'));
                $requested = (string) ($columnNameArr[$columnIndex]['data'] ?? 'tanggal');
                if ($requested !== 'no' && $requested !== '') {
                    $columnName = match ($requested) {
                        'debet' => 'debet',
                        'sumber_kas' => PencatatanJurnalTable::hasNoRefColumn() ? 'no_ref' : 'keterangan',
                        'NamaAkunKeluar' => PencatatanJurnalTable::hasAkunKeluarColumn() ? 'NamaAkunKeluar' : 'tanggal',
                        default => $requested,
                    };
                }
            }

            $baseQuery = akt_jurnal::query()->where('debet', '>', 0);
            $searchable = ['no_bukti', 'keterangan', 'periode', 'tahun'];
            if (PencatatanJurnalTable::hasAkunKeluarColumn()) {
                $searchable[] = 'NamaAkunKeluar';
            }

            $applySearch = function ($query) use ($searchValue, $searchable) {
                if ($searchValue === '') {
                    return;
                }
                $query->where(function ($q) use ($searchValue, $searchable) {
                    foreach ($searchable as $col) {
                        $q->orWhere($col, 'like', '%' . $searchValue . '%');
                    }
                });
            };

            $totalRecords = (clone $baseQuery)->count();
            $filteredQuery = clone $baseQuery;
            $applySearch($filteredQuery);
            $totalFiltered = (clone $filteredQuery)->count();

            $select = ['urut', 'tanggal', 'no_bukti', 'keterangan', 'debet', 'periode', 'tahun'];
            if (PencatatanJurnalTable::hasAkunKeluarColumn()) {
                $select[] = 'NamaAkunKeluar';
            }
            if (PencatatanJurnalTable::hasNoRefColumn()) {
                $select[] = 'no_ref';
            }
            if (PencatatanJurnalTable::hasKodePerkiraanColumn()) {
                $select[] = 'kode_perkiraan';
            }

            $records = $filteredQuery
                ->orderBy($columnName, in_array($columnSortOrder, ['asc', 'desc'], true) ? $columnSortOrder : 'desc')
                ->orderBy('urut', 'desc')
                ->skip($start)
                ->take($rowperpage)
                ->get($select)
                ->map(function ($item) {
                    $row = $item->toArray();
                    $row['NamaAkunKeluar'] = PencatatanAkunLookup::namaKeluar(
                        $row['NamaAkunKeluar'] ?? null,
                        $row['kode_perkiraan'] ?? null,
                        $row['no_bukti'] ?? null
                    );
                    $row['debet'] = (int) ($row['debet'] ?? 0);
                    $row['sumber_kas'] = trim((string) ($row['no_ref'] ?? '')) ?: '-';
                    $row['periode'] = PencatatanPeriode::label($row['periode'] ?? '', $row['tahun'] ?? '');
                    return $row;
                })
                ->toArray();

            return response()->json([
                'draw' => $draw,
                'recordsTotal' => $totalRecords,
                'recordsFiltered' => $totalFiltered,
                'data' => $records,
            ]);
        } catch (\Throwable $e) {
            Log::error('KasKeluar getData failed', ['message' => $e->getMessage()]);
            return response()->json([
                'draw' => (int) $request->get('draw', 0),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $tanggal = $this->normalizeDatetime(trim((string) $request->input('tanggal', '')));
        $kodeAkun = trim((string) $request->input('kode_akun', ''));
        $keterangan = trim((string) $request->input('keterangan', ''));
        $sumberKas = trim((string) $request->input('sumber_kas', 'Tunai'));
        $nominal = (int) preg_replace('/\D/', '', (string) $request->input('nominal', '0'));

        $validator = Validator::make(
            [
                'tanggal' => $tanggal,
                'kode_akun' => $kodeAkun,
                'keterangan' => $keterangan,
                'nominal' => $nominal,
                'sumber_kas' => $sumberKas,
                'bukti_foto' => $request->file('bukti_foto'),
            ],
            [
                'tanggal' => ['required', 'date'],
                'kode_akun' => ['required', 'max:20'],
                'keterangan' => ['required', 'max:255'],
                'nominal' => ['required', 'integer', 'min:1'],
                'sumber_kas' => ['required', 'max:50'],
                'bukti_foto' => ['nullable', 'image', 'max:5120'],
            ],
            ValidationMessage::messages(),
            ValidationMessage::attributes()
        );

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $akun = akun_kas_keluar::query()->where('KodeAkunKeluar', $kodeAkun)->first();
        if (!$akun) {
            return response()->json(['message' => 'Akun kas keluar tidak ditemukan. Buat dulu di menu Akun Kas Keluar.'], 422);
        }

        $period = $this->periodeFromDate($tanggal);

        try {
            $buktiurl = $this->resolveBuktiUrl($request);
            DB::connection('DATA_MYSQL')->beginTransaction();
            $this->insertJurnal([
                'tanggal' => $tanggal,
                'no_bukti' => $this->nextNoBukti('KK', $tanggal),
                'keterangan' => $keterangan,
                'debet' => $nominal,
                'kredit' => 0,
                'tahun' => $period['tahun'],
                'periode' => $period['periode'],
                'buktiurl' => $buktiurl,
                'no_ref' => $sumberKas,
                'kode_perkiraan' => $kodeAkun,
                'NamaAkunMasuk' => null,
                'NamaAkunKeluar' => $akun->NamaAkunKeluar,
            ]);
            DB::connection('DATA_MYSQL')->commit();

            return response()->json(['message' => 'Kas keluar berhasil disimpan']);
        } catch (\Throwable $e) {
            DB::connection('DATA_MYSQL')->rollBack();
            Log::error('KasKeluar store failed', ['message' => $e->getMessage()]);
            return response()->json(['message' => 'Kas keluar gagal disimpan: ' . $e->getMessage(), 'error' => $e->getMessage()], 422);
        }
    }
}
