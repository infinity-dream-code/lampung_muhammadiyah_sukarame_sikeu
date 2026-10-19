<?php

namespace App\Models;

use App\Support\SchoolScope;
use Illuminate\Database\Eloquent\Model;

class mst_kelas extends Model
{
    protected $connection = "DATA_MYSQL";

    protected $table = "mst_kelas";

    protected $primaryKey = "id";

    public $timestamps = false;

    public $incrementing = false;

    protected $fillable = [
        "kelas",
        "jenjang",
        "unit",
        "kelompok",
    ];

    public static function getMstKelasAttributes(?string $schoolCode = null): array|object
    {
        return static::dropdownQuery($schoolCode)
            ->select(["id", "kelas", "jenjang", "unit"])
            ->orderByRaw("
                    CASE
                        WHEN unit LIKE '%SD%' THEN 1
                        WHEN unit LIKE '%SMP%' THEN 2
                        WHEN unit LIKE '%SMA%' THEN 3
                        ELSE 4
                    END
            ")
            ->orderByRaw("CASE WHEN jenjang REGEXP '^[0-9]+$' THEN 0 ELSE 1 END, jenjang")
            ->orderBy("kelas")
            ->get();
    }

    /**
     * Query kelas untuk dropdown form, scoped per fid admin (mst_kelas.kelompok = CODE01).
     */
    public static function dropdownQuery(?string $schoolCode = null)
    {
        return static::query()
            ->tap(fn ($q) => SchoolScope::applyKelas($q, $schoolCode));
    }

    /**
     * Cocokkan baris import Excel ke Master Kelas (by nama/teks, bukan id).
     * Excel UNIT   = mst_kelas.unit
     * Excel KELAS  = mst_kelas.jenjang (7 / VII)
     * Excel KELOMPOK = mst_kelas.kelas (A / B / A1)
     * Sekolah (mst_sekolah) dipilih terpisah saat Simpan Data (filter mst_kelas.kelompok = CODE01).
     */
    public static function findForImport(?string $unit, mixed $jenjang, ?string $kelompok, ?string $schoolCode = null): ?self
    {
        $query = self::query();
        $schoolCode = trim((string) $schoolCode);
        if ($schoolCode !== '') {
            $query->where('kelompok', $schoolCode);
        }

        return self::matchFromCollection($query->get(), $unit, $jenjang, $kelompok);
    }

    /**
     * @param \Illuminate\Support\Collection<int, self>|iterable<int, self> $collection
     */
    public static function matchFromCollection(iterable $collection, ?string $unit, mixed $jenjang, ?string $kelompok): ?self
    {
        $unit = trim((string) $unit);
        $kelompok = trim((string) $kelompok);
        $jenjangText = trim((string) $jenjang);

        if ($unit === '' || $kelompok === '' || $jenjangText === '') {
            return null;
        }

        $jenjangCandidates = array_map('strtoupper', self::jenjangCandidates($jenjangText));
        $unitUpper = strtoupper($unit);
        $kelompokUpper = strtoupper($kelompok);

        foreach ($collection as $item) {
            $itemUnit = strtoupper(trim((string) ($item->unit ?? '')));
            if ($itemUnit !== $unitUpper && !str_contains($itemUnit, $unitUpper)) {
                continue;
            }

            $itemJenjang = strtoupper(trim((string) ($item->jenjang ?? '')));
            if (!in_array($itemJenjang, $jenjangCandidates, true)) {
                continue;
            }

            $itemKelas = strtoupper(trim((string) ($item->kelas ?? '')));
            if ($itemKelas !== $kelompokUpper) {
                continue;
            }

            return $item;
        }

        return null;
    }

    /** @return list<string> */
    public static function jenjangCandidates(mixed $jenjang): array
    {
        $value = trim((string) $jenjang);
        if ($value !== '' && is_numeric($value)) {
            $value = (string) (int) $value;
        }

        $numericToRoman = [
            '1' => 'I', '2' => 'II', '3' => 'III', '4' => 'IV', '5' => 'V', '6' => 'VI',
            '7' => 'VII', '8' => 'VIII', '9' => 'IX', '10' => 'X', '11' => 'XI', '12' => 'XII',
        ];

        $candidates = array_values(array_filter([$value]));
        if (isset($numericToRoman[$value])) {
            $candidates[] = $numericToRoman[$value];
        }

        $romanToNumeric = array_flip($numericToRoman);
        if (isset($romanToNumeric[$value])) {
            $candidates[] = $romanToNumeric[$value];
        }

        return array_values(array_unique($candidates));
    }
}
