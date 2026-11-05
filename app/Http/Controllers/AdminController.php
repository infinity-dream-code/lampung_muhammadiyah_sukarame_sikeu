<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\scctbill;
use App\Models\scctcust;
use App\Models\sccttran;
use App\Support\SchoolScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

        $taighanDibayar = $this->scopedBillQuery($schoolCode)->select(
            [
                DB::raw('DATE(PAIDDT) as date'),
                DB::raw('COUNT(*) as count')
            ])
            ->where('PAIDST', 1)
            ->where('FSTSBolehBayar', 1)
            ->whereNotNull('PAIDDT')
            ->where('PAIDDT', '>=', Carbon::now()->subDays(7)->startOfDay())
            ->where('PAIDDT', '!=', '0000-00-00 00:00:00')
            ->groupBy(DB::raw('DATE(PAIDDT)'))
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

        $tahunTersedia = $this->scopedBillQuery($schoolCode)->where('PAIDST', 1)
            ->where('FSTSBolehBayar', 1)
            ->whereNotNull('PAIDDT')
            ->selectRaw('YEAR(PAIDDT) as tahun')
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

        $tagihanDibayarBulanan = $this->scopedBillQuery($schoolCode)->select([
                DB::raw('MONTH(PAIDDT) as bulan'),
                DB::raw('COUNT(*) as count'),
            ])
            ->whereYear('PAIDDT', $tahunDipilih)
            ->where('PAIDST', 1)
            ->where('FSTSBolehBayar', 1)
            ->whereNotNull('PAIDDT')
            ->groupBy(DB::raw('MONTH(PAIDDT)'))
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
        $recentPaid = scctbill::leftJoin('scctcust', 'scctcust.CUSTID', 'scctbill.CUSTID')
            ->select([
                'scctbill.AA',
                'scctbill.CUSTID',
                'scctbill.BILLNM',
                'scctbill.BILLAM',
                'scctbill.PAIDST',
                'scctbill.PAIDDT',
                'scctbill.TRANSNO',
                'scctbill.BTA',
                'scctbill.FIDBANK',
                'scctbill.FUrutan',
                'scctcust.nmcust as nama',
                'scctcust.nocust',
                'scctcust.CODE02',
                'scctcust.DESC02',
                'scctcust.DESC04',
            ])->where('scctbill.PAIDST', 1)
            ->where('scctbill.FSTSBolehBayar', 1)
            ->where('scctcust.STCUST', 1)
            ->when($schoolCode, fn ($q) => $q->where('scctcust.CODE01', $schoolCode))
            ->orderByRaw('CASE WHEN scctbill.PAIDDT IS NULL OR scctbill.PAIDDT = \'0000-00-00 00:00:00\' THEN 1 ELSE 0 END')
            ->orderByDesc('scctbill.PAIDDT')
            ->orderByDesc('scctbill.AA')
            ->take(20)
            ->get();

        $data['tagihan_baru_dibayar'] = $this->hydrateRecentPaidDates($recentPaid)->take(5)->values();

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

    private function hydrateRecentPaidDates(Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return $rows;
        }

        $missingAa = $rows
            ->filter(fn ($row) => $this->isBlankPaidDate($row->PAIDDT ?? null))
            ->pluck('AA')
            ->filter()
            ->map(fn ($aa) => (string) $aa)
            ->unique()
            ->values()
            ->all();

        $trxDates = [];
        if ($missingAa !== []) {
            $trxDates = sccttran::query()
                ->whereIn('BILLID', $missingAa)
                ->where(function ($q) {
                    $q->whereNull('isreversal')
                        ->orWhere('isreversal', 0)
                        ->orWhere('isreversal', '0');
                })
                ->where(function ($q) {
                    $q->whereRaw('CAST(COALESCE(DEBET, 0) AS SIGNED) > 0')
                        ->orWhereRaw('CAST(COALESCE(KREDIT, 0) AS SIGNED) > 0');
                })
                ->selectRaw('BILLID, MAX(TRXDATE) as last_trx')
                ->groupBy('BILLID')
                ->pluck('last_trx', 'BILLID')
                ->mapWithKeys(fn ($date, $billId) => [(string) $billId => $date])
                ->all();
        }

        foreach ($rows as $row) {
            $aa = (string) ($row->AA ?? '');
            $paidDt = $row->PAIDDT ?? null;

            if ($this->isBlankPaidDate($paidDt) && isset($trxDates[$aa])) {
                $paidDt = $trxDates[$aa];
                $row->PAIDDT = $paidDt;

                scctbill::query()
                    ->where('AA', $row->AA)
                    ->where(function ($q) {
                        $q->whereNull('PAIDDT')
                            ->orWhere('PAIDDT', '0000-00-00 00:00:00');
                    })
                    ->update(['PAIDDT' => $paidDt]);
            }

            $row->PAIDDT_DISPLAY = $this->formatPaidDateDisplay($paidDt);
            $row->PAIDDT_SORT = $this->isBlankPaidDate($paidDt)
                ? null
                : Carbon::parse($paidDt)->timestamp;
        }

        return $rows
            ->sortByDesc(fn ($row) => $row->PAIDDT_SORT ?? 0)
            ->values();
    }

    private function isBlankPaidDate(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        $normalized = trim((string) $value);

        return $normalized === ''
            || $normalized === '-'
            || $normalized === '0'
            || preg_match('/^0{4}-0{2}-0{2}/', $normalized) === 1;
    }

    private function formatPaidDateDisplay(mixed $value): ?string
    {
        if ($this->isBlankPaidDate($value)) {
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
