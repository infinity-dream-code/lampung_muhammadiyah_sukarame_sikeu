<?php

namespace App\Support;

use App\Models\akt_jurnal;
use Illuminate\Support\Carbon;

class PencatatanPeriode
{
    /** @var array<int, string> */
    public const BULAN = [
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

    public static function fromDate(string $tanggal): array
    {
        try {
            $dt = Carbon::parse(str_replace('T', ' ', $tanggal));
        } catch (\Throwable) {
            $dt = Carbon::now();
        }

        return [
            'tahun' => $dt->format('Y'),
            'periode' => (string) $dt->month,
            'key' => $dt->format('Y') . '-' . $dt->month,
            'label' => self::BULAN[$dt->month] . ' ' . $dt->format('Y'),
        ];
    }

    /**
     * @return array{tahun: ?string, bulan: ?int, raw: string}
     */
    public static function parse(string $value): array
    {
        $raw = trim($value);

        if (preg_match('/^(\d{4})-(\d{1,2})$/', $raw, $m)) {
            return ['tahun' => $m[1], 'bulan' => (int) $m[2], 'raw' => $raw];
        }

        if (preg_match('/^(\d{4})(\d{2})$/', $raw, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            return ['tahun' => $m[1], 'bulan' => (int) $m[2], 'raw' => $raw];
        }

        if (preg_match('/^(\d{1,2})$/', $raw) && (int) $raw >= 1 && (int) $raw <= 12) {
            return ['tahun' => null, 'bulan' => (int) $raw, 'raw' => $raw];
        }

        return ['tahun' => null, 'bulan' => null, 'raw' => $raw];
    }

    public static function label(mixed $periode, mixed $tahun = null): string
    {
        $parsed = self::parse((string) $periode);
        $bulan = $parsed['bulan'];
        $tahunStr = $parsed['tahun'] ?: (string) $tahun;

        if ($bulan && isset(self::BULAN[$bulan])) {
            return trim(self::BULAN[$bulan] . ' ' . $tahunStr);
        }

        return trim((string) $tahun . ' ' . (string) $periode);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        $now = self::fromDate(now()->toDateString());
        $map = [$now['key'] => $now['label']];

        try {
            $rows = akt_jurnal::query()
                ->select(['periode', 'tahun'])
                ->whereNotNull('periode')
                ->where('periode', '!=', '')
                ->distinct()
                ->get();
        } catch (\Throwable) {
            $rows = collect();
        }

        foreach ($rows as $row) {
            $parsed = self::parse((string) $row->periode);
            $tahun = $parsed['tahun'] ?: (string) ($row->tahun ?? '');
            $bulan = $parsed['bulan'];

            if ($bulan && $tahun !== '') {
                $key = $tahun . '-' . $bulan;
                $map[$key] = self::BULAN[$bulan] . ' ' . $tahun;
                continue;
            }

            $fallback = trim($tahun . ' ' . (string) $row->periode);
            if ($fallback !== '') {
                $map[(string) $row->periode] = $fallback;
            }
        }

        krsort($map, SORT_NATURAL);

        $options = [];
        foreach ($map as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => $label];
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public static function matchValues(string $filter): array
    {
        $parsed = self::parse($filter);
        $values = array_filter([$parsed['raw'], $filter]);

        if ($parsed['bulan']) {
            $bulan = $parsed['bulan'];
            $values[] = (string) $bulan;
            $values[] = sprintf('%02d', $bulan);
            if ($parsed['tahun']) {
                $values[] = $parsed['tahun'] . sprintf('%02d', $bulan);
                $values[] = $parsed['tahun'] . '-' . $bulan;
                $values[] = $parsed['tahun'] . '-' . sprintf('%02d', $bulan);
            }
        }

        return array_values(array_unique($values));
    }
}
