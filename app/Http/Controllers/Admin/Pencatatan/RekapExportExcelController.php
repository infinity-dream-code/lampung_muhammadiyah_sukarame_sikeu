<?php

namespace App\Http\Controllers\Admin\Pencatatan;

use App\Exports\RekapPencatatanExcelExport;
use App\Http\Controllers\Admin\Pencatatan\Concerns\QueriesAktJurnal;
use App\Http\Controllers\Controller;
use App\Models\akun_kas_keluar;
use App\Models\akun_kas_masuk;
use App\Support\PencatatanAkunTable;
use App\Support\PencatatanJurnalTable;
use App\Support\PencatatanPeriode;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;

class RekapExportExcelController extends Controller
{
    use QueriesAktJurnal;

    public string $title = 'Pencatatan Sederhana';
    public string $mainTitle = 'Rekap Export Excel';
    public string $dataTitle = 'Rekap export excel Kas Masuk dan Kas Keluar';

    public function index()
    {
        $periodes = [];
        $akunMasuk = collect();
        $akunKeluar = collect();

        try {
            PencatatanJurnalTable::ensureLedgerColumns();
            PencatatanAkunTable::ensureMasuk();
            PencatatanAkunTable::ensureKeluar();

            if (Schema::connection('DATA_MYSQL')->hasTable(PencatatanJurnalTable::name())) {
                $periodes = PencatatanPeriode::options();
            }

            if (Schema::connection('DATA_MYSQL')->hasTable('akun_kas_masuk')) {
                $akunMasuk = akun_kas_masuk::query()
                    ->orderBy('KodeAkunMasuk')
                    ->get(['KodeAkunMasuk', 'NamaAkunMasuk']);
            }

            if (Schema::connection('DATA_MYSQL')->hasTable('akun_kas_keluar')) {
                $akunKeluar = akun_kas_keluar::query()
                    ->orderBy('KodeAkunKeluar')
                    ->get(['KodeAkunKeluar', 'NamaAkunKeluar']);
            }
        } catch (\Throwable $e) {
            Log::warning('RekapExportExcel index lookup failed', ['message' => $e->getMessage()]);
        }

        return view('admin.pencatatan.rekap_export_excel.index', [
            'title' => $this->title,
            'mainTitle' => $this->mainTitle,
            'dataTitle' => $this->dataTitle,
            'periodes' => $periodes,
            'defaultPeriode' => 'all',
            'sumberKasOptions' => ['Tunai', 'Transfer Bank', 'Virtual Account', 'Lainnya'],
            'akunMasuk' => $akunMasuk,
            'akunKeluar' => $akunKeluar,
            'columnsUrl' => route('admin.pencatatan-sederhana.rekap-export-excel.get-column'),
            'datasUrl' => route('admin.pencatatan-sederhana.rekap-export-excel.get-data'),
            'exportUrl' => route('admin.pencatatan-sederhana.rekap-export-excel.export'),
        ]);
    }

    public function getColumn()
    {
        return [
            ['data' => null, 'name' => 'no', 'className' => 'text-center', 'columnType' => 'row'],
            ['data' => 'tanggal', 'name' => 'Tanggal', 'searchable' => true, 'orderable' => true],
            ['data' => 'no_bukti', 'name' => 'No Bukti', 'searchable' => true, 'orderable' => true],
            [
                'data' => 'nominal_keluar',
                'name' => 'Nominal Kas Keluar',
                'searchable' => false,
                'orderable' => true,
                'columnType' => 'currency',
                'className' => 'text-end',
            ],
            [
                'data' => 'nominal_masuk',
                'name' => 'Nominal Kas Masuk',
                'searchable' => false,
                'orderable' => true,
                'columnType' => 'currency',
                'className' => 'text-end',
            ],
            ['data' => 'foto', 'name' => 'Foto', 'searchable' => false, 'orderable' => false],
            ['data' => 'periode', 'name' => 'Periode', 'searchable' => true, 'orderable' => true],
            ['data' => 'tahun', 'name' => 'Tahun', 'searchable' => true, 'orderable' => true],
            ['data' => 'keterangan', 'name' => 'Keterangan', 'searchable' => true, 'orderable' => true],
            ['data' => 'sumber_kas', 'name' => 'Sumber Kas', 'searchable' => true, 'orderable' => true],
            [
                'data' => 'saldo',
                'name' => 'Saldo',
                'searchable' => false,
                'orderable' => false,
                'columnType' => 'currency',
                'className' => 'text-end',
            ],
            ['data' => 'akun_kas_keluar', 'name' => 'Akun Kas Keluar', 'searchable' => true, 'orderable' => true],
            ['data' => 'akun_kas_masuk', 'name' => 'Akun Kas Masuk', 'searchable' => true, 'orderable' => true],
        ];
    }

    public function getData(Request $request)
    {
        try {
            $draw = (int) $request->get('draw', 0);
            $start = (int) $request->get('start', 0);
            $rowperpage = (int) $request->get('length', 25);

            [$filters, $searchValue] = $this->extractFilters($request);
            [$columnName, $columnSortOrder] = $this->resolveSort($request);

            $baseQuery = $this->jurnalBaseQuery();
            $totalRecords = (clone $baseQuery)->count();

            $filteredQuery = clone $baseQuery;
            $this->applyJurnalFilters($filteredQuery, $filters, $searchValue);
            $totalRecordswithFilter = (clone $filteredQuery)->count();
            $saldo = $this->calculateJurnalSaldo($filters, $searchValue);
            $running = $this->runningSaldoBefore($filteredQuery, $columnName, $columnSortOrder, $start);

            $records = $filteredQuery
                ->orderBy($columnName, $columnSortOrder)
                ->orderBy('urut', 'asc')
                ->skip($start)
                ->take($rowperpage)
                ->get()
                ->map(function ($item) use (&$running) {
                    $row = $this->mapJurnalRow($item);
                    $running += $row['nominal_masuk'] - $row['nominal_keluar'];
                    $row['saldo'] = $running;
                    return $row;
                })
                ->toArray();

            return response()->json([
                'draw' => $draw,
                'recordsTotal' => $totalRecords,
                'recordsFiltered' => $totalRecordswithFilter,
                'data' => $records,
                'saldo' => $saldo,
            ]);
        } catch (\Throwable $e) {
            Log::error('RekapExportExcel getData failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'draw' => (int) $request->get('draw', 0),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'saldo' => 0,
                'message' => 'Gagal memuat data rekap export excel',
            ], 500);
        }
    }

    public function export(Request $request): BinaryFileResponse|RedirectResponse
    {
        try {
            PencatatanJurnalTable::ensureLedgerColumns();

            [$filters, $searchValue] = $this->extractFilters($request);
            [$columnName, $columnSortOrder] = $this->resolveSort($request);

            $query = $this->jurnalBaseQuery();
            $this->applyJurnalFilters($query, $filters, $searchValue);

            $running = 0;
            $rows = $query
                ->orderBy($columnName, $columnSortOrder)
                ->orderBy('urut', 'asc')
                ->get()
                ->map(function ($item) use (&$running) {
                    $mapped = $this->mapJurnalRow($item);
                    $running += $mapped['nominal_masuk'] - $mapped['nominal_keluar'];

                    $tanggal = trim((string) ($mapped['tanggal'] ?? ''));
                    try {
                        $tanggal = $tanggal !== '' && !str_starts_with($tanggal, '0000-00-00')
                            ? Carbon::parse($tanggal)->format('d/m/Y H:i')
                            : '';
                    } catch (\Throwable) {
                        // keep raw
                    }

                    $foto = $mapped['foto'] ?? '';
                    if ($foto === '-') {
                        $foto = '';
                    }

                    return [
                        $tanggal,
                        $mapped['no_bukti'],
                        $mapped['nominal_keluar'],
                        $mapped['nominal_masuk'],
                        $foto,
                        $mapped['periode'],
                        $mapped['tahun'],
                        $mapped['keterangan'],
                        $mapped['sumber_kas'] ?? '-',
                        $running,
                        $mapped['akun_kas_keluar'],
                        $mapped['akun_kas_masuk'],
                    ];
                })
                ->all();

            $rows[] = ['', '', '', '', '', '', '', '', 'SALDO', $running, '', ''];

            $periode = $filters['periode'] !== '' && strtolower($filters['periode']) !== 'all'
                ? preg_replace('/[^A-Za-z0-9_-]/', '_', $filters['periode'])
                : 'semua';

            return Excel::download(
                new RekapPencatatanExcelExport($rows),
                'rekap_kas_masuk_keluar_' . $periode . '.xlsx'
            );
        } catch (\Throwable $e) {
            Log::error('RekapExportExcel export failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return redirect()
                ->route('admin.pencatatan-sederhana.rekap-export-excel.index')
                ->with('error', 'Gagal export Excel: ' . $e->getMessage());
        }
    }

    /**
     * @return array{0: array<string, string>, 1: string}
     */
    private function extractFilters(Request $request): array
    {
        $filter = $request->input('filter', []);
        if (!is_array($filter)) {
            $filter = [];
        }

        $searchArr = $request->get('search', []);
        $searchValue = trim((string) ($searchArr['value'] ?? ''));

        return [[
            'periode' => trim((string) ($filter['periode'] ?? '')),
            'no_bukti' => trim((string) ($filter['no_bukti'] ?? '')),
            'keterangan' => trim((string) ($filter['keterangan'] ?? '')),
            'tanggal_dari' => trim((string) ($filter['tanggal_dari'] ?? '')),
            'tanggal_sampai' => trim((string) ($filter['tanggal_sampai'] ?? '')),
            'akun_masuk' => trim((string) ($filter['akun_masuk'] ?? '')),
            'akun_keluar' => trim((string) ($filter['akun_keluar'] ?? '')),
            'sumber_kas' => trim((string) ($filter['sumber_kas'] ?? '')),
        ], $searchValue];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveSort(Request $request): array
    {
        $sortMap = $this->jurnalSortMap();

        $columnName = 'tanggal';
        $columnSortOrder = 'asc';
        $columnIndexArr = $request->get('order', []);
        $columnNameArr = $request->get('columns', []);

        if (!empty($columnIndexArr)) {
            $columnIndex = (int) ($columnIndexArr[0]['column'] ?? 0);
            $columnSortOrder = strtolower((string) ($columnIndexArr[0]['dir'] ?? 'asc'));
            $columnSortOrder = in_array($columnSortOrder, ['asc', 'desc'], true) ? $columnSortOrder : 'asc';
            $requested = (string) ($columnNameArr[$columnIndex]['data'] ?? 'tanggal');
            $columnName = $sortMap[$requested] ?? 'tanggal';
        }

        return [$columnName, $columnSortOrder];
    }
}
