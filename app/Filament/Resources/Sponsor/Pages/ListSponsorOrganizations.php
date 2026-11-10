<?php

namespace App\Filament\Resources\Sponsor\Pages;

use App\Filament\Resources\Sponsor\SponsorOrganizationResource;
use App\Models\Membership\MembershipPlan;
use App\Models\User;
use App\Services\Sponsor\CorporateTransfer;
use App\Services\Sponsor\SponsorOrganizationService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;

class ListSponsorOrganizations extends ListRecords
{
    protected static string $resource = SponsorOrganizationResource::class;

    public const PAY_TRANSFER = 'TRANSFER';

    public const PAY_NONE = 'NONE';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('grantCorporateMembership')
                ->label('Berikan Membership Corporate')
                ->icon('heroicon-o-gift')
                ->color('warning')
                ->visible(fn (): bool => (bool) auth()->user()?->can('grant_corporate_membership'))
                ->modalHeading('Berikan Paket Membership Corporate')
                ->modalDescription('Paket langsung aktif. Pilih Transfer ke Rekening Perusahaan apabila perusahaan sudah membayar; pembayaran tercatat di laporan pendapatan beserta bukti transfernya.')
                ->schema([
                    TextInput::make('company_name')
                        ->label('Nama Perusahaan / Sponsor')
                        ->required()
                        ->maxLength(150),
                    Select::make('pic_user_id')
                        ->label('PIC (Person In Charge)')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => User::query()
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->limit(20)
                            ->pluck('name', 'id'))
                        ->getOptionLabelUsing(fn ($value) => User::find($value)?->name)
                        ->required(),
                    Select::make('plan_id')
                        ->label('Paket Corporate')
                        ->options(fn () => MembershipPlan::query()
                            ->where('ownership_type', 'ORGANIZATIONAL')
                            ->where('is_active', true)
                            ->pluck('name', 'id'))
                        ->live()
                        ->required(),
                    Radio::make('payment')
                        ->label('Cara Pembayaran')
                        ->options(fn () => array_filter([
                            self::PAY_TRANSFER => auth()->user()?->can(CorporateTransfer::PERMISSION) ? 'Transfer ke Rekening Perusahaan' : null,
                            self::PAY_NONE => 'Tanpa Pembayaran',
                        ]))
                        ->default(fn () => auth()->user()?->can(CorporateTransfer::PERMISSION) ? self::PAY_TRANSFER : self::PAY_NONE)
                        ->live()
                        ->required(),
                    Group::make([
                        Text::make(fn (Get $get) => self::nominalLabel($get('plan_id'))),
                        ...CorporateTransfer::formFields(),
                    ])->visible(fn (Get $get) => $get('payment') === self::PAY_TRANSFER),
                    Textarea::make('reason')
                        ->label('Alasan Pemberian Tanpa Pembayaran')
                        ->placeholder('Contoh: Kompensasi kerja sama event.')
                        ->required(fn (Get $get) => $get('payment') === self::PAY_NONE)
                        ->visible(fn (Get $get) => $get('payment') === self::PAY_NONE)
                        ->maxLength(500),
                ])
                ->action(function (array $data, SponsorOrganizationService $service): void {
                    abort_unless(auth()->user()?->can('grant_corporate_membership'), 403);
                    $paid = ($data['payment'] ?? null) === self::PAY_TRANSFER;

                    try {
                        $organization = $paid
                            ? $service->grantCorporateMembershipPaid(
                                pic: User::findOrFail($data['pic_user_id']),
                                plan: MembershipPlan::findOrFail($data['plan_id']),
                                companyName: $data['company_name'],
                                grantedBy: auth()->user(),
                                transferData: $data,
                            )
                            : $service->grantCorporateMembership(
                                pic: User::findOrFail($data['pic_user_id']),
                                plan: MembershipPlan::findOrFail($data['plan_id']),
                                companyName: $data['company_name'],
                                grantedBy: auth()->user(),
                                reason: (string) ($data['reason'] ?? ''),
                            );
                    } catch (\DomainException|\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                        if (! $paid) {
                            CorporateTransfer::discardProof($data['proof_path'] ?? null);
                        }
                        Notification::make()->title('Gagal Memberikan Membership')->body($e->getMessage())->danger()->send();

                        return;
                    }
                    if (! $paid) {
                        CorporateTransfer::discardProof($data['proof_path'] ?? null);
                    }

                    Notification::make()
                        ->title('Membership Corporate Aktif')
                        ->body("{$organization->name} siap dipakai. PIC bisa langsung membuka Dashboard Sponsor Team."
                            .($paid ? ' Pembayaran transfer sudah tercatat.' : ''))
                        ->success()
                        ->send();
                }),

            CreateAction::make()
                ->label('Tambah Sponsor Manual'),
        ];
    }

    /** Nominal yang dicatat: harga paket + pajak & biaya sesuai Master Data (sama dengan POS Jual Membership). */
    private static function nominalLabel(?string $planId): string
    {
        $plan = $planId ? MembershipPlan::find($planId) : null;
        if (! $plan) {
            return 'Pilih paket untuk melihat nominal pembayaran.';
        }
        $calc = app(\App\Services\Finance\TaxAndFeeService::class)->calculate(subtotal: (float) $plan->price, discountAmount: 0, channel: 'POS_WALKIN', module: 'MEMBERSHIP');

        return 'Nominal pembayaran: Rp '.number_format((float) $calc['grand_total'], 0, ',', '.')
            .' (harga paket'.((float) $calc['tax_amount'] + (float) $calc['admin_fee_amount'] > 0 ? ' + pajak & biaya sesuai Master Data' : '').').';
    }
}
