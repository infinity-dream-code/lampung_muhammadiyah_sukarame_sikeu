<?php

namespace App\Http\Controllers\Admin\Pencatatan;

use App\Http\Controllers\Controller;
use App\Models\akun_kas_masuk;
use App\Models\ValidationMessage;
use App\Support\PencatatanAkunTable;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AkunKasMasukController extends Controller
{
    public string $title = 'Pencatatan Sederhana';
    public string $mainTitle = 'Akun Kas Masuk';
    public string $dataTitle = 'Akun Kas Masuk';

    public function index()
    {
        PencatatanAkunTable::ensureMasuk();

        return view('admin.pencatatan.akun_kas_masuk.index', [
            'title' => $this->title,
            'mainTitle' => $this->mainTitle,
            'dataTitle' => $this->dataTitle,
            'columnsUrl' => route('admin.pencatatan-sederhana.akun-kas-masuk.get-column'),
            'datasUrl' => route('admin.pencatatan-sederhana.akun-kas-masuk.get-data'),
            'storeUrl' => route('admin.pencatatan-sederhana.akun-kas-masuk.store'),
        ]);
    }

    public function getColumn()
    {
        return [
            ['data' => null, 'name' => 'no', 'className' => 'text-center', 'columnType' => 'row'],
            ['data' => 'KodeAkunMasuk', 'name' => 'Kode', 'searchable' => true, 'orderable' => true],
            ['data' => 'NamaAkunMasuk', 'name' => 'Nama Akun Kas Masuk', 'searchable' => true, 'orderable' => true],
            ['data' => 'NoRekMasuk', 'name' => 'Nomor Rekening', 'searchable' => true, 'orderable' => true],
        ];
    }

    public function getData(Request $request)
    {
        try {
            if (!PencatatanAkunTable::ensureMasuk()) {
                return response()->json([
                    'draw' => (int) $request->get('draw', 0),
                    'recordsTotal' => 0,
                    'recordsFiltered' => 0,
                    'data' => [],
                    'message' => 'Tabel akun_kas_masuk belum tersedia di database',
                ]);
            }

            $draw = (int) $request->get('draw', 0);
            $start = max(0, (int) $request->get('start', 0));
            $rowperpage = (int) $request->get('length', 25);
            if ($rowperpage <= 0) {
                $rowperpage = 25;
            }

            $columnIndexArr = $request->get('order', []);
            $columnNameArr = $request->get('columns', []);
            $searchArr = $request->get('search', []);
            $searchValue = is_array($searchArr) ? trim((string) ($searchArr['value'] ?? '')) : '';

            $columnName = 'KodeAkunMasuk';
            $columnSortOrder = 'asc';

            if (!empty($columnIndexArr)) {
                $columnIndex = $columnIndexArr[0]['column'] ?? null;
                if ($columnIndex !== null && !empty($columnNameArr[$columnIndex]['data']) && $columnNameArr[$columnIndex]['data'] !== 'no') {
                    $columnName = $columnNameArr[$columnIndex]['data'];
                    $columnSortOrder = $columnIndexArr[0]['dir'] ?? 'asc';
                }
            }

            $allowedSort = ['KodeAkunMasuk', 'NamaAkunMasuk', 'NoRekMasuk'];
            if (!in_array($columnName, $allowedSort, true)) {
                $columnName = 'KodeAkunMasuk';
            }

            $query = akun_kas_masuk::query();
            if ($searchValue !== '') {
                $like = '%' . $searchValue . '%';
                $query->where(function ($q) use ($like) {
                    $q->where('KodeAkunMasuk', 'like', $like)
                        ->orWhere('NamaAkunMasuk', 'like', $like)
                        ->orWhere('NoRekMasuk', 'like', $like);
                });
            }

            $totalRecords = akun_kas_masuk::query()->count();
            $totalRecordswithFilter = (clone $query)->count();

            $records = $query
                ->orderBy($columnName, in_array($columnSortOrder, ['asc', 'desc'], true) ? $columnSortOrder : 'asc')
                ->skip($start)
                ->take($rowperpage)
                ->get()
                ->map(function ($item) {
                    $item->NoRekMasuk = $item->NoRekMasuk ?? '';
                    return $item;
                })
                ->toArray();

            return response()->json([
                'draw' => $draw,
                'recordsTotal' => $totalRecords,
                'recordsFiltered' => $totalRecordswithFilter,
                'data' => $records,
            ]);
        } catch (\Throwable $e) {
            Log::error('AkunKasMasuk getData failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'draw' => (int) $request->get('draw', 0),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'message' => 'Gagal memuat data akun kas masuk',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $kode = trim((string) $request->kode_akun);
        $nama = trim((string) $request->nama_akun);
        $noRek = trim((string) ($request->no_rek ?? ''));

        $validator = Validator::make(
            [
                'kode_akun' => $kode,
                'nama_akun' => $nama,
                'no_rek' => $noRek,
            ],
            [
                'kode_akun' => ['required', 'max:5'],
                'nama_akun' => ['required', 'max:50'],
                'no_rek' => ['nullable', 'max:25'],
            ],
            ValidationMessage::messages(),
            ValidationMessage::attributes()
        );

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        if (akun_kas_masuk::where('KodeAkunMasuk', $kode)->exists()) {
            return response()->json(['message' => 'Kode akun kas masuk sudah ada'], 422);
        }

        if (akun_kas_masuk::where('NamaAkunMasuk', $nama)->exists()) {
            return response()->json(['message' => 'Nama akun kas masuk sudah ada'], 422);
        }

        try {
            if (!PencatatanAkunTable::ensureMasuk()) {
                return response()->json(['message' => 'Tabel akun kas masuk belum tersedia di database'], 422);
            }

            DB::connection('DATA_MYSQL')->beginTransaction();
            akun_kas_masuk::create([
                'KodeAkunMasuk' => $kode,
                'NamaAkunMasuk' => $nama,
                'NoRekMasuk' => $noRek === '' ? '0' : $noRek,
            ]);
            DB::connection('DATA_MYSQL')->commit();

            return response()->json(['message' => 'Data Akun Kas Masuk telah disimpan']);
        } catch (Exception $e) {
            DB::connection('DATA_MYSQL')->rollBack();
            return response()->json(['message' => 'Data Akun Kas Masuk gagal disimpan', 'error' => $e->getMessage()], 422);
        }
    }
}
