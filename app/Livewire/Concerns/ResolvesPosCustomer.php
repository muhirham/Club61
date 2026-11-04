<?php

namespace App\Livewire\Concerns;

use App\Models\User;
use App\Services\Padel\PadelBookingService;
use App\Support\PersonName;
use Filament\Notifications\Notification;

/**
 * Customer transaksi kasir POS Walk-In. Butuh properti Livewire: customerMode, selectedCustomerId,
 * walkInName, walkInPhone, walkInEmail.
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

        return $service->findOrCreateWalkInCustomer(
            name: $name,
            phone: $phone,
            email: ! empty($this->walkInEmail) ? trim($this->walkInEmail) : null
        );
    }
}
