<?php

namespace Tests\Feature\Mail;

use App\Mail\OrderInvoiceMail;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Pos\Order;
use App\Models\User;
use App\Services\Mail\OrderInvoiceMailer;
use App\Services\Payment\PaymentOrchestratorService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Invoice lunas dikirim ke email customer dari akun billing@, sekali per order, PDF terlampir. */
class OrderInvoiceEmailTest extends TestCase
{
    use RefreshDatabase;

    protected User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::create([
            'name' => 'Andi Wijaya',
            'email' => 'andi@club61.test',
            'phone' => '08123456789',
            'password' => bcrypt('password123'),
            'role' => 'CUSTOMER',
            'is_active' => true,
        ]);
    }

    private function onlineBookingOrder(string $number = 'ORD-PAD-MAIL1'): Order
    {
        $court = PadelCourt::create([
            'name' => 'Court 1 - Panoramic Indoor', 'court_type' => 'INDOOR', 'surface_type' => 'MONDO_SUPERCOURT',
            'hourly_rate_regular' => 200000, 'hourly_rate_prime' => 300000, 'is_active' => true,
        ]);
        $order = Order::create([
            'order_number' => $number, 'user_id' => $this->customer->id, 'order_type' => 'ONLINE_BOOKING',
            'subtotal' => 400000, 'service_charge' => 12000, 'grand_total' => 412000, 'payment_status' => 'UNPAID',
        ]);
        $date = now()->addDays(3)->format('Y-m-d');
        $booking = PadelBooking::create([
            'booking_code' => 'BK-MAIL-1', 'order_id' => $order->id, 'user_id' => $this->customer->id, 'court_id' => $court->id,
            'booking_date' => $date, 'start_time' => Carbon::parse("{$date} 18:00:00"), 'end_time' => Carbon::parse("{$date} 20:00:00"),
            'court_fee' => 400000, 'total_amount' => 400000, 'status' => 'PENDING_PAYMENT',
        ]);
        $order->items()->create([
            'item_type' => 'PADEL', 'reference_id' => $booking->id, 'item_name' => 'Sewa Court 1 - Panoramic Indoor',
            'quantity' => 1, 'unit_price' => 400000, 'subtotal' => 400000,
        ]);

        return $order;
    }

    private function pay(Order $order, string $trx = 'MID-MAIL-1'): void
    {
        app(PaymentOrchestratorService::class)->markOrderAsPaid($order, [
            'payment_gateway' => 'MIDTRANS', 'transaction_id' => $trx, 'payment_method' => 'bank_transfer',
            'amount' => (float) $order->grand_total,
        ]);
    }

    public function test_paid_online_booking_emails_one_invoice_from_billing_with_the_pdf(): void
    {
        Mail::fake();
        $order = $this->onlineBookingOrder();

        $this->pay($order);
        // Notifikasi Midtrans yang sama datang lagi → tidak kirim ulang.
        $this->pay($order);

        Mail::assertSent(OrderInvoiceMail::class, 1);
        Mail::assertSent(OrderInvoiceMail::class, function (OrderInvoiceMail $mail) {
            return $mail->hasTo('andi@club61.test')
                && $mail->mailer === 'billing'
                && $mail->invoice['number'] === 'ORD-PAD-MAIL1'
                && $mail->invoice['grand_total'] === 412000.0
                && $mail->invoice['bookings'][0]['time'] === '18:00 – 20:00 WIB'
                && count($mail->attachments()) === 1;
        });
        $this->assertNotNull($order->fresh()->invoice_emailed_at);
    }

    public function test_invoice_email_and_pdf_render(): void
    {
        $order = $this->onlineBookingOrder();
        $this->pay($order);
        $invoice = app(OrderInvoiceMailer::class)->invoiceData($order->fresh(['user', 'items', 'payments']));

        $html = (new OrderInvoiceMail($invoice))->render();
        $this->assertStringContainsString('ORD-PAD-MAIL1', $html);
        $this->assertStringContainsString('Rp 412.000', $html);
        $this->assertStringContainsString('Court 1 - Panoramic Indoor', $html);

        $pdf = Pdf::loadView('emails.invoice.pdf', ['invoice' => $invoice])->output();
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_paid_membership_order_also_emails_the_invoice(): void
    {
        Mail::fake();
        $order = Order::create([
            'order_number' => 'ORD-MBR-MAIL1', 'user_id' => $this->customer->id, 'order_type' => 'MEMBERSHIP',
            'subtotal' => 1900000, 'grand_total' => 1900000, 'payment_status' => 'UNPAID',
        ]);

        $this->pay($order, 'MID-MBR-1');

        Mail::assertSent(OrderInvoiceMail::class, fn (OrderInvoiceMail $mail) => $mail->invoice['type'] === 'Membership');
    }

    public function test_walk_in_and_fnb_orders_do_not_email_an_invoice(): void
    {
        Mail::fake();
        $order = Order::create([
            'order_number' => 'ORD-WALKIN-MAIL', 'user_id' => $this->customer->id, 'order_type' => 'WALK_IN',
            'subtotal' => 200000, 'grand_total' => 200000, 'payment_status' => 'UNPAID',
        ]);

        $this->pay($order, 'POS-MAIL-1');

        Mail::assertNothingSent();
        $this->assertNull($order->fresh()->invoice_emailed_at);
    }

    public function test_smtp_failure_never_blocks_the_payment_and_can_be_retried(): void
    {
        Mail::shouldReceive('mailer')->with('billing')->andThrow(new \RuntimeException('SMTP down'));
        $order = $this->onlineBookingOrder();

        $this->pay($order);

        $this->assertSame('PAID', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->invoice_emailed_at);
    }

    public function test_invoice_send_command_explains_why_and_resends_a_failed_invoice(): void
    {
        $order = $this->onlineBookingOrder();
        $order->update(['payment_status' => 'PAID']);

        // Akun customer tanpa email valid → alasan ditampilkan, tidak dikirim.
        $this->customer->forceFill(['email' => 'bukan-email'])->save();
        $this->artisan('invoice:send', ['order' => $order->order_number, '--check' => true])
            ->expectsOutputToContain('tidak punya email yang valid')
            ->assertSuccessful();

        $this->customer->forceFill(['email' => 'andi@club61.test'])->save();
        Mail::fake();
        $this->artisan('invoice:send', ['order' => $order->order_number])
            ->expectsOutputToContain('Invoice terkirim ke andi@club61.test')
            ->assertSuccessful();
        Mail::assertSent(OrderInvoiceMail::class, 1);

        // Sudah terkirim → butuh --force.
        $this->artisan('invoice:send', ['order' => $order->order_number])->expectsOutputToContain('--force')->assertSuccessful();
        $this->artisan('invoice:send', ['order' => $order->order_number, '--force' => true])->assertSuccessful();
        Mail::assertSent(OrderInvoiceMail::class, 2);
    }

    public function test_logo_file_is_embedded_in_the_email_and_pdf_when_present(): void
    {
        $logo = public_path(OrderInvoiceMailer::LOGO_FILE);
        $existed = is_file($logo);
        $dirExisted = is_dir(dirname($logo));
        if (! $existed) {
            @mkdir(dirname($logo), 0777, true);
            copy(public_path('images/club61-logo.png'), $logo);
        }

        try {
            $order = $this->onlineBookingOrder();
            $this->pay($order);
            $invoice = app(OrderInvoiceMailer::class)->invoiceData($order->fresh(['user', 'items', 'payments']));

            Mail::mailer('billing')->to('andi@club61.test')->send(new OrderInvoiceMail($invoice));
            $message = Mail::mailer('billing')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
            $this->assertStringContainsString('cid:', $message->getHtmlBody());
            $this->assertStringContainsString('data:image/png;base64,', view('emails.invoice.pdf', ['invoice' => $invoice])->render());
        } finally {
            if (! $existed) {
                @unlink($logo);
            }
            if (! $dirExisted) {
                @rmdir(dirname($logo));
            }
        }
    }

    public function test_billing_mailer_follows_the_local_transport_and_replies_go_to_info(): void
    {
        // Test & lokal: MAIL_MAILER bukan smtp → billing tidak pernah mengirim email sungguhan.
        $this->assertSame('array', config('mail.mailers.billing.transport'));
        $this->assertArrayHasKey('reply_to', config('mail'));
    }
}
