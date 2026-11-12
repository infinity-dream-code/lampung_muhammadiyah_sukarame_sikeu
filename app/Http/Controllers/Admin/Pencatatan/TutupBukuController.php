<?php

namespace App\Http\Controllers\Admin\Pencatatan;

use App\Http\Controllers\Controller;
use App\Models\ValidationMessage;
use App\Support\PencatatanPeriode;
use App\Support\PencatatanPeriodeLock;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class TutupBukuController extends Controller
{
    public string $title = 'Pencatatan Sederhana';
    public string $mainTitle = 'Simpan Jurnal Bulanan';
    public string $dataTitle = 'Simpan Jurnal Per Bulan';

    public function index()
    {
        $now = Carbon::now();
        $tahunList = range((int) $now->year - 2, (int) $now->year + 1);
        rsort($tahunList);

        return view('admin.pencatatan.tutup_buku.index', [
            'title' => $this->title,
            'mainTitle' => $this->mainTitle,
            'dataTitle' => $this->dataTitle,
            'tahunList' => $tahunList,
            'bulanList' => PencatatanPeriode::BULAN,
            'tahunDefault' => (int) $now->year,
            'bulanDefault' => (int) $now->month,
            'lockedPeriods' => PencatatanPeriodeLock::listLocked(),
            'tutupUrl' => route('admin.pencatatan-sederhana.tutup-buku.tutup'),
            'bukaUrl' => route('admin.pencatatan-sederhana.tutup-buku.buka'),
        ]);
    }

    public function tutup(Request $request)
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

        $tahun = (string) $request->input('tahun');
        $periode = (string) ((int) $request->input('periode'));

        try {
            if (PencatatanPeriodeLock::isLocked($tahun, $periode)) {
                return response()->json([
                    'message' => 'Periode '.PencatatanPeriode::label($periode, $tahun).' sudah disimpan/ditutup.',
                ], 422);
            }

            PencatatanPeriodeLock::lock($tahun, $periode);

            return response()->json([
                'message' => 'Jurnal periode '.PencatatanPeriode::label($periode, $tahun).' berhasil disimpan. Kas masuk/keluar periode ini tidak bisa ditambah/dihapus.',
                'lockedPeriods' => PencatatanPeriodeLock::listLocked(),
            ]);
        } catch (\Throwable $e) {
            Log::error('TutupBuku tutup failed', ['message' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function buka(Request $request)
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

        $tahun = (string) $request->input('tahun');
        $periode = (string) ((int) $request->input('periode'));

        try {
            if (!PencatatanPeriodeLock::isLocked($tahun, $periode)) {
                return response()->json([
                    'message' => 'Periode '.PencatatanPeriode::label($periode, $tahun).' belum disimpan/ditutup.',
                ], 422);
            }

            PencatatanPeriodeLock::unlock($tahun, $periode);

            return response()->json([
                'message' => 'Periode '.PencatatanPeriode::label($periode, $tahun).' dibuka kembali.',
                'lockedPeriods' => PencatatanPeriodeLock::listLocked(),
            ]);
        } catch (\Throwable $e) {
            Log::error('TutupBuku buka failed', ['message' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
