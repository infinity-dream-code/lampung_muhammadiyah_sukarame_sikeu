<?php

namespace App\Support;

use App\Models\scctbill;
use App\Models\sccttran;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TagihanPaymentReversal
{
    /** Manual Pembayaran teller/cash — sama dengan bank yang pakai FROM TELLER di sccttran. */
    private const TELLER_FIDBANKS = ['1140000', '1140001', '1140003', '1140004', '1140005', '1200001', '1200002'];

    private const SALDO_FIDBANK = '1140002';

    /** Batalkan pembayaran terakhir. Cash/teller di-reset manual; saldo/VA lewat procedure. */
    public function reverseLastPayment(scctbill $tagihan, Request $request): void
    {
        if (!$this->hasBillPayments($tagihan)) {
            return;
        }

        $username = (string) (Auth::user()->username ?? Auth::id() ?? 'system');

        if ($this->shouldCancelAsTellerPayment($tagihan)) {
            $this->cancelCashPayment($tagihan, $username);

            return;
        }

        try {
            $this->callCancelPaymentSaldo(
                (string) $tagihan->CUSTID,
                (string) $tagihan->AA,
                (string) ($tagihan->BILLCD ?? ''),
                $this->resolveCyberKeyUserId(),
                Str::limit((string) ($request->ip() ?? ''), 250, '')
            );

            $tagihan->refresh();

            if ($this->hasBillPayments($tagihan)) {
                Log::warning('tagihan-payment.cancel.procedure_noop', [
                    'aa' => $tagihan->AA,
                    'custid' => $tagihan->CUSTID,
                    'billpaid' => $tagihan->BILLPAID,
                ]);
                $this->reverseSaldoPaymentManually($tagihan, $username);
            }
        } catch (\Throwable $e) {
            Log::error('tagihan-payment.cancel.procedure_failed', [
                'aa' => $tagihan->AA,
                'custid' => $tagihan->CUSTID,
                'fidbank' => $tagihan->FIDBANK,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            if ($this->shouldCancelAsTellerPayment($tagihan)) {
                $this->cancelCashPayment($tagihan, $username);

                return;
            }

            $this->reverseSaldoPaymentManually($tagihan, $username);
        }
    }

    /** Hapus tagihan: batalkan semua pembayaran dari yang terakhir. */
    public function reverseAllPayments(scctbill $tagihan, Request $request): void
    {
        $guard = 0;

        while ($this->hasBillPayments($tagihan) && $guard < 50) {
            $this->reverseLastPayment($tagihan, $request);
            $tagihan->refresh();
            $guard++;
        }
    }

    public function deleteUnpaidTagihan(scctbill $tagihan, Request $request): void
    {
        if ($this->hasBillPayments($tagihan)) {
            $this->reverseAllPayments($tagihan, $request);
            $tagihan->refresh();
        }

        $tagihan->update([
            'FSTSBolehBayar' => 0,
        ]);
    }

    public function hasBillPayments(scctbill $tagihan): bool
    {
        if ((int) ($tagihan->BILLPAID ?? 0) > 0) {
            return true;
        }

        if ((int) ($tagihan->PAIDST ?? 0) === 1) {
            return true;
        }

        return $this->paymentTransactionQuery($tagihan)->exists();
    }

    private function shouldCancelAsTellerPayment(scctbill $tagihan): bool
    {
        $fidBank = $this->normalizeFidBank($tagihan->FIDBANK ?? '');

        if ($fidBank === self::SALDO_FIDBANK) {
            return false;
        }

        if (in_array($fidBank, self::TELLER_FIDBANKS, true)) {
            return true;
        }

        return sccttran::query()
            ->where('BILLID', $tagihan->AA)
            ->where('CUSTID', $tagihan->CUSTID)
            ->whereRaw('UPPER(TRIM(COALESCE(METODE, ""))) = ?', ['FROM TELLER'])
            ->exists();
    }

    private function normalizeFidBank(?string $fidBank): string
    {
        return (string) preg_replace('/\D/', '', (string) $fidBank);
    }

    private function paymentTransactionQuery(scctbill $tagihan)
    {
        return sccttran::query()
            ->where('BILLID', $tagihan->AA)
            ->where('CUSTID', $tagihan->CUSTID)
            ->where(function ($q) {
                $q->whereRaw('CAST(COALESCE(DEBET, 0) AS SIGNED) > 0')
                    ->orWhereRaw('CAST(COALESCE(KREDIT, 0) AS SIGNED) > 0');
            })
            ->where(function ($q) {
                $q->whereNull('isreversal')
                    ->orWhere('isreversal', 0)
                    ->orWhere('isreversal', '0');
            })
            ->whereRaw('UPPER(TRIM(COALESCE(METODE, ""))) NOT IN (?, ?)', ['REVERSAL', 'JURNAL SALDO']);
    }

    private function resolvePaidAmount(scctbill $tagihan): int
    {
        $billPaid = (int) ($tagihan->BILLPAID ?? 0);
        if ($billPaid > 0) {
            return $billPaid;
        }

        $fromTran = (int) $this->paymentTransactionQuery($tagihan)->sum('DEBET');
        if ($fromTran > 0) {
            return $fromTran;
        }

        return max(0, (int) ($tagihan->BILLAM ?? 0));
    }

    private function cancelCashPayment(scctbill $tagihan, string $username): void
    {
        $last = $this->latestActivePayment($tagihan);
        $nominalBayar = $last
            ? max((int) ($last->DEBET ?? 0), (int) ($last->KREDIT ?? 0))
            : $this->resolvePaidAmount($tagihan);

        if ($last) {
            $this->markTransactionReversed($last);
        }

        $this->applyBillAfterReverse($tagihan, $nominalBayar);
    }

    private function latestActivePayment(scctbill $tagihan): ?sccttran
    {
        return $this->paymentTransactionQuery($tagihan)
            ->orderByDesc('INSTALLMENT')
            ->orderByDesc('urut')
            ->first();
    }

    private function markTransactionReversed(sccttran $tran): void
    {
        try {
            $tran->update(['isreversal' => 1]);

            return;
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), 'isreversal') === false
                && stripos($e->getMessage(), 'Unknown column') === false) {
                throw $e;
            }
        }

        // Jangan kosongkan KREDIT saja — v_saldo_va = KREDIT - DEBET.
        // Tetap seimbangkan baris cash, lalu tandai REVERSAL.
        $debet = (int) ($tran->DEBET ?? 0);
        $kredit = (int) ($tran->KREDIT ?? 0);
        $tran->update([
            'METODE' => 'REVERSAL',
            'NOREFF' => 'REVERSAL',
            'DEBET' => $debet,
            'KREDIT' => max($kredit, $debet),
        ]);
    }

    private function applyBillAfterReverse(scctbill $tagihan, int $nominalBayar): void
    {
        $billAm = (int) ($tagihan->BILLAM ?? 0);
        $remainingPaid = max(0, (int) ($tagihan->BILLPAID ?? 0) - max(0, $nominalBayar));
        $remainingCicilan = $this->paymentTransactionQuery($tagihan)->count();

        $tagihan->update([
            'PAIDST' => ($remainingPaid > 0 && $remainingPaid >= $billAm) ? 1 : 0,
            'PAIDDT' => $remainingPaid > 0 ? $tagihan->PAIDDT : null,
            'PAIDDT_ACTUAL' => $remainingPaid > 0 ? $tagihan->PAIDDT_ACTUAL : null,
            'BILLPAID' => $remainingPaid,
            'PAYMENTLEFT' => max(0, $billAm - $remainingPaid),
            'INSTALLMENT' => $remainingCicilan,
            'FIDBANK' => $remainingPaid > 0 ? $tagihan->FIDBANK : null,
        ]);
    }

    private function reverseSaldoPaymentManually(scctbill $tagihan, string $username): void
    {
        $last = $this->latestActivePayment($tagihan);
        $nominalBayar = $last
            ? max((int) ($last->DEBET ?? 0), (int) ($last->KREDIT ?? 0))
            : $this->resolvePaidAmount($tagihan);

        if ($last) {
            $this->markTransactionReversed($last);
        }

        $this->applyBillAfterReverse($tagihan, $nominalBayar);
    }

    private function insertReversalTransaction(scctbill $tagihan, int $nominalBayar, string $metode, string $username): void
    {
        $lastInstallment = (int) sccttran::query()
            ->where('BILLID', $tagihan->AA)
            ->where('CUSTID', $tagihan->CUSTID)
            ->max('INSTALLMENT');

        $payload = [
            'CUSTID' => $tagihan->CUSTID,
            'METODE' => $metode,
            'TRXDATE' => now(),
            'NOREFF' => 'REVERSAL',
            'FIDBANK' => (string) ($tagihan->FIDBANK ?? ''),
            'DEBET' => 0,
            'KREDIT' => $nominalBayar,
            'BILLID' => $tagihan->AA,
            'BILLTARGET' => $tagihan->BILLNM,
            'INSTALLMENT' => $lastInstallment,
            'TRANSNO' => $tagihan->TRANSNO ?? $username,
            'isreversal' => 1,
        ];

        try {
            sccttran::create($payload);
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), 'isreversal') === false
                && stripos($e->getMessage(), 'Unknown column') === false) {
                throw $e;
            }

            unset($payload['isreversal']);
            sccttran::create($payload);
        }
    }

    private function callCancelPaymentSaldo(string $custId, string $aa, string $billCd, string $userId, string $hostname): void
    {
        Log::info('tagihan-payment.cancel.call_procedure', [
            'procedure' => 'CancelPaymentSaldo',
            'custid' => $custId,
            'aa' => $aa,
            'billcd' => $billCd,
            'users' => $userId,
            'hostname' => $hostname,
        ]);

        $pdo = DB::connection('DATA_MYSQL')->getPdo();
        $stmt = $pdo->prepare('CALL CancelPaymentSaldo(?, ?, ?, ?, ?)');
        $stmt->execute([$custId, $aa, $billCd, $userId, $hostname]);

        do {
            $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } while ($stmt->nextRowset());
    }

    private function resolveCyberKeyUserId(): string
    {
        $user = Auth::user();

        if ($user === null) {
            return '';
        }

        return (string) ($user->urut ?? Auth::id() ?? '');
    }
}
