<?php

namespace App\Services\Coaching;

use App\Models\Coaching\CoachingAcademy;
use App\Models\Padel\PadelBooking;
use App\Models\Pos\Order;
use App\Models\Pos\ClubFinanceSetting;
use App\Services\Mail\InvoicePdf;
use Carbon\Carbon;

/**
 * Modul 31 — invoice tagihan berkala academy (PDF A4) memakai Template Invoice yang sama dengan invoice customer
 * (InvoicePdf / emails.invoice.pdf), dengan status "Belum Lunas". Isinya sesi academy yang belum dilunasi pada periode
 * tanggal main yang dipilih; admin mengirim PDF ke academy secara manual.
 */
class CoachingInvoice
{
    public function __construct(private CoachingBillingService $billing) {}

    /**
     * @return array<string, mixed>|null null = tidak ada tagihan belum lunas pada periode itu
     */
    public function data(CoachingAcademy $academy, ?string $from, ?string $until): ?array
    {
        $sessions = $this->billing->unpaidSessions($academy->id)
            ->when($from, fn ($q) => $q->whereDate('booking_date', '>=', $from))
            ->when($until, fn ($q) => $q->whereDate('booking_date', '<=', $until))
            ->with(['court:id,name', 'coachingBooking.coaches'])
            ->orderBy('start_time')->get();
        if ($sessions->isEmpty()) {
            return null;
        }

        $orders = Order::with('items')->whereIn('id', $sessions->pluck('order_id')->unique())->get()->keyBy('id');
        $byBooking = $sessions->keyBy('id');
        $lines = [];
        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $session = $item->item_type === 'PADEL' ? $byBooking->get($item->reference_id) : null;
                $lines[] = [
                    'name' => $session
                        ? $item->item_name.' · '.$session->start_time->locale('id')->translatedFormat('D, d M Y').' '.$session->start_time->format('H:i').'–'.$session->end_time->format('H:i')
                            .' · Coach '.$session->coachingBooking->coachSummary()
                        : $item->item_name,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'subtotal' => (float) $item->subtotal,
                    'sort' => $session?->start_time?->timestamp ?? PHP_INT_MAX,
                ];
            }
        }
        usort($lines, fn ($a, $b) => $a['sort'] <=> $b['sort']);

        $finance = ClubFinanceSetting::getSettings();
        $dueDates = $sessions->map(fn (PadelBooking $b) => $b->coachingBooking->due_at)->filter();
        $period = $from || $until
            ? trim(($from ? Carbon::parse($from)->locale('id')->translatedFormat('d M Y') : '…').' – '.($until ? Carbon::parse($until)->locale('id')->translatedFormat('d M Y') : '…'))
            : $sessions->first()->start_time->locale('id')->translatedFormat('d M Y').' – '.$sessions->last()->start_time->locale('id')->translatedFormat('d M Y');

        return [
            'number' => 'TGH-'.strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $academy->name), 0, 6)).'-'
                .$sessions->first()->start_time->format('Ymd').'-'.$sessions->last()->start_time->format('Ymd'),
            'document_title' => 'Invoice Tagihan',
            'status_label' => 'Belum Lunas',
            'date_label' => 'Periode',
            'paid_at' => $period,
            'type' => 'Sewa Lapangan Coaching ('.$academy->billingLabel().')',
            'reference' => $sessions->count().' sesi',
            'customer' => ['name' => $academy->name.($academy->pic_name ? ' (PIC: '.$academy->pic_name.')' : ''), 'email' => null, 'phone' => $academy->phone],
            'lines' => array_map(fn ($l) => array_diff_key($l, ['sort' => true]), $lines),
            'bookings' => $sessions->map(fn (PadelBooking $b) => [
                'court' => $b->court?->name ?? '-',
                'date' => $b->start_time->locale('id')->translatedFormat('l, d F Y'),
                'time' => $b->start_time->format('H:i').' – '.$b->end_time->format('H:i').' WIB',
                'code' => $b->booking_code,
            ])->all(),
            'membership' => null,
            'subtotal' => (float) $orders->sum('subtotal'),
            'discount' => (float) $orders->sum('discount_amount'),
            'voucher_code' => null,
            'service_charge' => (float) $orders->sum('service_charge'),
            'service_charge_name' => $finance->admin_fee_name ?: 'Biaya Layanan',
            'tax' => (float) $orders->sum('tax_amount'),
            'tax_name' => $finance->tax_name ?: 'Pajak',
            'grand_total' => (float) $orders->sum('grand_total'),
            'method' => 'Kasir Club 61 (QRIS / EDC / Transfer Bank)',
            'va_number' => null,
            'total_label' => 'Total tagihan',
            'due_note' => 'Mohon melakukan pembayaran'.($dueDates->isNotEmpty() ? ' paling lambat '.$dueDates->max()->locale('id')->translatedFormat('d F Y') : ' sesuai periode tagihan')
                .' di kasir Club 61 (QRIS, kartu debit/kredit, atau transfer bank). Sertakan nomor invoice ini saat melakukan pembayaran.',
            'company_address' => '',
            'invoice_url' => null,
        ];
    }

    public function pdf(array $invoice): string
    {
        return InvoicePdf::output($invoice);
    }
}
