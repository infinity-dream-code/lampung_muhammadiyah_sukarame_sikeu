<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\scctbill;
use App\Models\sccttran;
use App\Support\SchoolScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function index(Request $request)
    {
        $schoolCode = SchoolScope::codeFromUser();

        $monthsDaily = [
            'January' => 'Jan',
            'February' => 'Feb',
            'March' => 'Mar',
            'April' => 'Apr',
            'May' => 'Mei',
            'June' => 'Jun',
            'July' => 'Jul',
            'August' => 'Ags',
            'September' => 'Sep',
            'October' => 'Okt',
            'November' => 'Nov',
            'December' => 'Des'
        ];

        $today = Carbon::now();

        $taighanDibayar = $this->scopedPaymentTranQuery($schoolCode)->select(
            [
                DB::raw('DATE(sccttran.TRXDATE) as date'),
                DB::raw('COUNT(*) as count')
            ])
            ->where('sccttran.TRXDATE', '>=', Carbon::now()->subDays(7)->startOfDay())
            ->groupBy(DB::raw('DATE(sccttran.TRXDATE)'))
            ->orderBy('date', 'ASC')
            ->get();

        $hasilTagihanDibayar = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $today->copy()->subDays($i)->format('Y-m-d');
            $hasilTagihanDibayar[$date] = 0;
        }
        foreach ($taighanDibayar as $count) {
            $hasilTagihanDibayar[$count->date] = $count->count;
        }
        $chartTagihanDibayar = collect($hasilTagihanDibayar)->map(function ($count, $date) use ($monthsDaily) {
            return [
                'date' => $this->formatDateIndonesian($date, $monthsDaily),
                'count' => $count
            ];
        })->values();

        $months = [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];

        $tahunTersedia = $this->scopedPaymentTranQuery($schoolCode)
            ->selectRaw('YEAR(sccttran.TRXDATE) as tahun')
            ->distinct()
            ->orderBy('tahun', 'desc')
            ->pluck('tahun')
            ->filter()
            ->map(fn ($tahun) => (int) $tahun)
            ->values()
            ->toArray();

        $tahunDipilih = (int) $request->query('tahun', Carbon::now()->year);
        if (!in_array($tahunDipilih, $tahunTersedia, true)) {
            $tahunDipilih = $tahunTersedia[0] ?? Carbon::now()->year;
        }

        $tagihanDibayarBulanan = $this->scopedPaymentTranQuery($schoolCode)->select([
                DB::raw('MONTH(sccttran.TRXDATE) as bulan'),
                DB::raw('COUNT(*) as count'),
            ])
            ->whereYear('sccttran.TRXDATE', $tahunDipilih)
            ->groupBy(DB::raw('MONTH(sccttran.TRXDATE)'))
            ->orderBy('bulan', 'ASC')
            ->get()
            ->keyBy('bulan');

        $chartTagihanDibayarBulanan = collect($months)
            ->map(function ($namaBulan, $nomorBulan) use ($tagihanDibayarBulanan) {
                return [
                    'date' => $namaBulan,
                    'count' => (int) ($tagihanDibayarBulanan[$nomorBulan]->count ?? 0),
                ];
            })
            ->values();

        $data['chartTagihanDibayar'] = $chartTagihanDibayar;
        $data['chartTagihanDibayarBulanan'] = $chartTagihanDibayarBulanan;
        $data['tahun_tahun'] = $tahunTersedia;
        $data['tahun_dipilih'] = $tahunDipilih;

        $data['tagihan_baru_dibayar'] = $this->scopedPaymentTranQuery($schoolCode)
            ->select([
                'sccttran.urut',
                'sccttran.TRXDATE',
                'sccttran.DEBET',
                'sccttran.BILLTARGET',
                'sccttran.BILLID',
                'scctbill.AA',
                'scctbill.BILLNM',
                'scctbill.BILLAM',
                'scctbill.BTA',
                'scctbill.FIDBANK',
                'scctcust.nmcust as nama',
                'scctcust.nocust as nis',
                'scctcust.CODE02',
                'scctcust.DESC02',
                'scctcust.DESC04',
            ])
            ->orderByDesc('sccttran.TRXDATE')
            ->orderByDesc('sccttran.urut')
            ->take(5)
            ->get()
            ->map(function ($item) {
                $item->BILLNM = $item->BILLNM ?: $item->BILLTARGET;
                $item->BILLAM = (int) ($item->DEBET ?? 0) > 0
                    ? (int) $item->DEBET
                    : (int) ($item->BILLAM ?? 0);
                $item->PAIDDT_DISPLAY = $this->formatPaidDateDisplay($item->TRXDATE);

                return $item;
            });

        $data['jumlah_tagihan_belum_dibayar'] = $this->scopedBillQuery($schoolCode)->where('PAIDST', 0)->count('AA') ?: 0;
        $data['jumlah_tagihan_dibayar'] = $this->scopedBillQuery($schoolCode)->where('PAIDST', 1)->count('AA') ?: 0;

        return view('admin.index', $data);
    }

    private function scopedBillQuery(?string $schoolCode = null)
    {
        return scctbill::query()->when($schoolCode, function ($query) use ($schoolCode) {
            $query->whereExists(function ($exists) use ($schoolCode) {
                $exists->select(DB::raw(1))
                    ->from('scctcust')
                    ->whereColumn('scctcust.CUSTID', 'scctbill.CUSTID')
                    ->where('scctcust.CODE01', $schoolCode);
            });
        });
    }

    private function scopedPaymentTranQuery(?string $schoolCode = null)
    {
        return sccttran::query()
            ->leftJoin('scctcust', 'scctcust.CUSTID', '=', 'sccttran.CUSTID')
            ->leftJoin('scctbill', function ($join) {
                $join->on('scctbill.AA', '=', 'sccttran.BILLID')
                    ->on('scctbill.CUSTID', '=', 'sccttran.CUSTID');
            })
            ->where(function ($q) {
                $q->whereNull('sccttran.isreversal')
                    ->orWhere('sccttran.isreversal', 0)
                    ->orWhere('sccttran.isreversal', '0');
            })
            ->whereRaw('CAST(COALESCE(sccttran.DEBET, 0) AS SIGNED) > 0')
            ->whereNotNull('sccttran.TRXDATE')
            ->where('sccttran.TRXDATE', '!=', '0000-00-00 00:00:00')
            ->whereRaw('CAST(COALESCE(scctcust.STCUST, 0) AS SIGNED) = 1')
            ->when($schoolCode, fn ($q) => $q->where('scctcust.CODE01', $schoolCode));
    }

    private function formatPaidDateDisplay(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '' || $normalized === '-' || preg_match('/^0{4}-0{2}-0{2}/', $normalized) === 1) {
            return null;
        }

        try {
            $parsed = Carbon::parse($value);
            if ($parsed->year < 1971) {
                return null;
            }

            return $parsed->locale('id')->isoFormat('dddd, D MMMM YYYY');
        } catch (\Throwable) {
            return null;
        }
    }

    function formatDateIndonesian($date, $months)
    {
        // Create a Carbon instance from the date
        $carbonDate = Carbon::parse($date);

        // Get the day and month in English
        $day = $carbonDate->day;
        $monthName = $carbonDate->format('F');

        // Translate month to Indonesian
        $monthIndonesian = $months[$monthName] ?? $monthName;

        // Return formatted date
        return $monthIndonesian . ' ' . $day;
    }
}
