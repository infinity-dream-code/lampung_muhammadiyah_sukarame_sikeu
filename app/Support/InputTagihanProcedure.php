<?php

namespace App\Support;

use App\Models\scctcust;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InputTagihanProcedure
{
    /**
     * Memanggil stored procedure InputTagihan di DATA_MYSQL.
     *
     * p_isNYICIL di SP mengisi scctbill.INSTALLMENT. INSTALLMENT = jumlah cicil,
     * jadi ke SP selalu dikirim 0. Flag boleh cicil disimpan di isINSTALLABLE.
     *
     * @see InputTagihan(p_NOCUST, p_NOMINAL, p_NMTagihan, p_BILLPERIOD, p_BTA, p_isNYICIL)
     */
    public static function call(
        string $nocust,
        int $nominal,
        string $nmTagihan,
        string $billPeriod,
        string $bta,
        string $isNyicil = '0',
    ): void {
        $isInstallable = ((int) $isNyicil === 1) ? 1 : 0;

        $pdo = DB::connection('DATA_MYSQL')->getPdo();
        $stmt = $pdo->prepare('CALL InputTagihan(?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $nocust,
            $nominal,
            $nmTagihan,
            $billPeriod,
            $bta,
            '0',
        ]);

        do {
            $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } while ($stmt->nextRowset());

        $custId = (int) (scctcust::query()->where('NOCUST', $nocust)->value('CUSTID') ?? 0);
        if ($custId > 0) {
            NewBillFlags::normalize($custId, $nmTagihan, $billPeriod !== '' ? $billPeriod : $bta, $isInstallable);
        }

        Log::info('input-tagihan.procedure.ok', [
            'nocust' => $nocust,
            'nominal' => $nominal,
            'nmtagihan' => $nmTagihan,
            'billperiod' => $billPeriod,
            'bta' => $bta,
            'isnyicil_sent' => '0',
            'isinstallable' => $isInstallable,
        ]);
    }
}
