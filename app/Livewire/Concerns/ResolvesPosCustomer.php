<?php

namespace App\Livewire\Concerns;

use App\Models\User;
use App\Services\Padel\PadelBookingService;
use App\Support\PersonName;
use Filament\Notifications\Notification;

/**
 * Customer transaksi kasir POS Walk-In. Butuh properti Livewire: customerMode, selectedCustomerId,
 * selectedCustomerPhone, selectedCustomerNewPhone, walkInName, walkInPhone, walkInEmail.
 */
trait ResolvesPosCustomer
{
    /**
     * Customer transaksi kasir: akun terdaftar yang dipilih, atau akun walk-in dari nama + HP (dibuat / dipakai ulang
     * lewat nomor HP). Null = isian belum lengkap (notifikasi sudah dikirim).
     */
    protected function resolvePosCustomer(PadelBookingService $service): ?User
    {
        if ($this->customerMode === 'search') {
            if (empty($this->selectedCustomerId)) {
                Notification::make()
                    ->title('Customer Belum Dipilih')
                    ->body('Silakan cari dan pilih pelanggan terdaftar, atau beralih ke form Walk-In Cepat.')
                    ->warning()
                    ->send();
                return null;
            }
            $customer = User::find($this->selectedCustomerId);
            if (! $customer) {
                Notification::make()
                    ->title('Customer Tidak Ditemukan')
                    ->body('Data pelanggan terdaftar tidak valid atau telah dihapus.')
                    ->danger()
                    ->send();
                return null;
            }

            return $customer;
        }

        $name = trim($this->walkInName);
        $phone = trim($this->walkInPhone);

        if (empty($name)) {
            Notification::make()
                ->title('Nama Wajib Diisi')
                ->body('Silakan masukkan nama pelanggan walk-in.')
                ->warning()
                ->send();
            return null;
        }

        if (! PersonName::isValid($name)) {
            Notification::make()
                ->title('Nama Tidak Valid')
                ->body(PersonName::MESSAGE)
                ->warning()
                ->send();
            return null;
        }

        if (empty($phone) || strlen(preg_replace('/[^0-9]/', '', $phone)) < 8) {
            Notification::make()
                ->title('Nomor Telepon Tidak Valid')
                ->body('Silakan masukkan nomor WhatsApp / telepon aktif minimal 8 digit.')
                ->warning()
                ->send();
            return null;
        }

        if (! $this->walkInEmailAvailable($service)) {
            return null;
        }

        try {
            return $service->findOrCreateWalkInCustomer(
                name: $name,
                phone: $phone,
                email: ! empty($this->walkInEmail) ? trim($this->walkInEmail) : null,
                rejectTakenEmail: true,
            );
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            Notification::make()->title('Customer Tidak Bisa Dibuat')->body($e->getMessage())->danger()->send();

            return null;
        }
    }

    /**
     * Modul 33: email Walk-In Cepat yang sudah milik akun lain (nomor HP belum dikenal) → kasir diberi tahu & diarahkan ke
     * Cari Akun, bukan diam-diam dibuatkan akun kedua.
     */
    protected function walkInEmailAvailable(PadelBookingService $service): bool
    {
        $owner = $service->walkInEmailOwner((string) $this->walkInPhone, (string) $this->walkInEmail);
        if (! $owner) {
            return true;
        }

        Notification::make()
            ->title('Email Sudah Terdaftar')
            ->body(PadelBookingService::walkInEmailTakenMessage($owner))
            ->warning()
            ->persistent()
            ->send();

        return false;
    }

    /**
     * Modul 33 (Cari Akun): akun yang dipilih belum punya nomor HP (mis. daftar web lama) → kasir mengisinya supaya
     * kunjungan berikutnya dikenali lewat nomor HP. Butuh properti Livewire selectedCustomerNewPhone.
     */
    public function saveSelectedCustomerPhone(): void
    {
        if (empty($this->selectedCustomerId)) {
            return;
        }

        $customer = User::find($this->selectedCustomerId);
        if (! $customer) {
            Notification::make()->title('Customer Tidak Ditemukan')->danger()->send();

            return;
        }

        try {
            $customer = app(\App\Services\Customer\CustomerPhoneService::class)->attachToCustomer($customer, (string) $this->selectedCustomerNewPhone);
        } catch (\DomainException $e) {
            Notification::make()->title('Nomor HP Tidak Disimpan')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->selectedCustomerPhone = $customer->phone;
        $this->selectedCustomerNewPhone = '';
        Notification::make()->title('Nomor HP Disimpan')->body($customer->name.' sekarang dikenali lewat nomor '.$customer->phone.'.')->success()->send();
    }
}
