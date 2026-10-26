<?php

namespace App\Support;

use App\Models\scctbill;
use Illuminate\Support\Facades\Log;
use Throwable;

class NewBillFlags
{
    /**
     * INSTALLMENT = jumlah cicilan yang sudah terjadi, bukan flag.
     * Tagihan baru (belum bayar) harus 0. isINSTALLABLE mengikuti master.
     */
    public static function normalize(int $custId, string $billNm, string $billac, int $isInstallable): void
    {
        $billNm = trim($billNm);
        $billac = trim($billac);
        if ($custId <= 0 || $billNm === '') {
            return;
        }

        $query = scctbill::query()
            ->where('CUSTID', $custId)
            ->where('FSTSBolehBayar', 1)
            ->where(function ($q) {
                $q->where('BILLPAID', 0)->orWhereNull('BILLPAID');
            })
            ->where(function ($q) use ($billNm) {
                $q->where('BILLNM', $billNm)
                    ->orWhere('BILLNM', 'like', $billNm.' %')
                    ->orWhere('BILLNM', 'like', $billNm.'%');
            });

        if ($billac !== '') {
            $query->where(function ($q) use ($billac) {
                $q->where('BILLAC', $billac)
                    ->orWhere('BTA', $billac);
            });
        }

        $payload = [
            'INSTALLMENT' => 0,
            'FLPART' => null,
            'isINSTALLABLE' => $isInstallable ? 1 : 0,
        ];

        $updated = $query->update($payload);

        if ($updated === 0) {
            scctbill::query()
                ->where('CUSTID', $custId)
                ->where('FSTSBolehBayar', 1)
                ->where(function ($q) {
                    $q->where('BILLPAID', 0)->orWhereNull('BILLPAID');
                })
                ->where(function ($q) use ($billNm) {
                    $q->where('BILLNM', $billNm)
                        ->orWhere('BILLNM', 'like', $billNm.' %');
                })
                ->orderByDesc('FUrutan')
                ->limit(3)
                ->update($payload);
        }
    }

    /**
     * Perbaiki tagihan belum bayar yang INSTALLMENT-nya salah terisi flag 1.
     */
    public static function resetUnpaidCicilCount(?string $billNm = null, ?string $billac = null): int
    {
        try {
            $query = scctbill::query()
                ->where(function ($q) {
                    $q->where('BILLPAID', 0)->orWhereNull('BILLPAID');
                })
                ->where(function ($q) {
                    $q->where('PAIDST', 0)->orWhereNull('PAIDST');
                })
                ->where('INSTALLMENT', '>', 0);

            if (is_string($billNm) && trim($billNm) !== '') {
                $name = trim($billNm);
                $query->where(function ($q) use ($name) {
                    $q->where('BILLNM', $name)
                        ->orWhere('BILLNM', 'like', $name.' %')
                        ->orWhere('BILLNM', 'like', $name.'%');
                });
            }

            if (is_string($billac) && trim($billac) !== '') {
                $period = trim($billac);
                $query->where(function ($q) use ($period) {
                    $q->where('BILLAC', $period)
                        ->orWhere('BTA', $period);
                });
            }

            return (int) $query->update([
                'INSTALLMENT' => 0,
                'FLPART' => null,
            ]);
        } catch (Throwable $e) {
            Log::warning('NewBillFlags reset unpaid failed', ['message' => $e->getMessage()]);

            return 0;
        }
    }
}
