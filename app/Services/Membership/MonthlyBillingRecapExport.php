<?php

namespace App\Services\Membership;

use App\Models\Membership\UserMembershipBalance;
use App\Services\Finance\LedgerReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Modul 34 — Rekap Jam Main Tagihan Bulanan untuk Finance (Excel & PDF). Isinya sama dengan layar halaman Tagihan
 * Bulanan (MonthlyBilling::recap), tanpa harga. Teks dinetralkan dari formula injection (Excel).
 */
class MonthlyBillingRecapExport
{
    /** @return array{title: string, company: string, card: ?string, pic: ?string, month: string, recap: array, payment: ?array} */
    public function data(UserMembershipBalance $balance, string $month): array
    {
        $membership = $balance->membership()->with(['sponsorOrganization.sponsorAdmin:id,name', 'user:id,name'])->first();
        $payment = MonthlyBilling::paymentFor($balance->id, $month);

        return [
            'title' => 'Rekap Jam Main Tagihan Bulanan',
            'company' => $membership ? MonthlyBilling::billedName($membership) : '-',
            'card' => $membership?->membership_code,
            'pic' => $membership?->sponsorOrganization?->sponsorAdmin?->name ?? $membership?->user?->name,
            'month' => MonthlyBilling::monthLabel(Carbon::parse($month.'-01')),
            'recap' => MonthlyBilling::recap($balance, $month),
            'payment' => $payment ? [
                'amount' => (float) $payment->amount,
                'date' => \App\Services\Sponsor\CorporateTransfer::of($payment)['transfer_date'] ?? null,
                'reference' => \App\Services\Sponsor\CorporateTransfer::of($payment)['reference'] ?? null,
            ] : null,
        ];
    }

    public function excel(UserMembershipBalance $balance, string $month, string $path): string
    {
        $d = $this->data($balance, $month);
        $t = $d['recap']['totals'];
        $h = fn (float $v) => (float) $v;

        $writer = new Writer();
        $writer->openToFile($path);
        $bold = (new Style())->setFontBold();
        $header = (new Style())->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('662721');
        $row = fn (array $values, ?Style $style = null) => new Row(array_map(
            fn ($v) => Cell::fromValue(is_string($v) ? LedgerReport::cellSafe($v) : $v, $style),
            $values,
        ));

        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Rekap');
        foreach ([22, 18, 14, 18, 10, 10, 10, 14, 20] as $i => $w) {
            $sheet->setColumnWidth($w, $i + 1);
        }
        $writer->addRow($row([$d['title'].' — Club 61'], $bold));
        $writer->addRow($row(['Perusahaan', $d['company']]));
        $writer->addRow($row(['Nomor Kartu', (string) $d['card']]));
        $writer->addRow($row(['PIC', (string) $d['pic']]));
        $writer->addRow($row(['Periode', $d['month']]));
        $writer->addRow($row(['Dicetak', now(LedgerReport::TIMEZONE)->format('d/m/Y H:i').' WIB']));
        $writer->addRow($row([]));
        $writer->addRow($row(['RINGKASAN', 'Jam'], $header));
        foreach ([
            ['Jumlah booking', $t['bookings']],
            ['Total jam', $h($t['hours'])],
            ['Jam reguler (di luar jam peak)', $h($t['regular_hours'])],
            ['Jam peak', $h($t['peak_hours'])],
            ['Sudah main', $h($t['played_hours'])],
            ['Tidak hadir (tetap dihitung)', $h($t['no_show_hours'])],
            ['Belum main', $h($t['scheduled_hours'])],
        ] as [$label, $value]) {
            $writer->addRow($row([$label, $value]));
        }
        $writer->addRow($row([]));
        $writer->addRow($row(['Kode Booking', 'Tanggal', 'Jam', 'Lapangan', 'Durasi', 'Reguler', 'Peak', 'Status', 'Sumber'], $header));
        foreach ($d['recap']['rows'] as $r) {
            $writer->addRow($row([$r['code'], $r['date'], $r['time'], $r['court'], $h($r['hours']), $h($r['regular_hours']), $h($r['peak_hours']), $r['status_label'], $r['source']]));
        }
        $writer->addRow($row(['Total', '', '', '', $h($t['hours']), $h($t['regular_hours']), $h($t['peak_hours']), '', ''], $bold));

        $writer->close();

        return $path;
    }

    public function pdf(UserMembershipBalance $balance, string $month, ?string $printedBy): string
    {
        return Pdf::loadView('exports.rekap-tagihan-bulanan-pdf', $this->data($balance, $month) + [
            'printedBy' => $printedBy,
            'printedAt' => now(LedgerReport::TIMEZONE)->format('d/m/Y H:i').' WIB',
        ])->setPaper('a4', 'portrait')->output();
    }
}
