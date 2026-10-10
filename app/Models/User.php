<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, HasRoles, HasUlids, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role',
        'avatar_url',
        'is_active',
        'registration_source',
    ];

    protected $appends = [
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected ?string $pendingRole = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function setRoleAttribute($value): void
    {
        if ($value) {
            $this->pendingRole = strtolower($value);
        }
    }

    public function getRoleAttribute(): string
    {
        $firstRole = $this->roles->first()?->name;
        return $firstRole ? strtoupper($firstRole) : 'CUSTOMER';
    }

    protected static function booted(): void
    {
        // Dinonaktifkan → cabut semua token API & sesi web di database saat itu juga (middleware
        // EnsureUserIsActive tetap menjaga sesi yang tersimpan di tempat lain).
        static::updated(function (User $user) {
            if (! $user->wasChanged('is_active') || $user->is_active !== false) {
                return;
            }

            $user->tokens()->delete();

            if (config('session.driver') === 'database') {
                \Illuminate\Support\Facades\DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();
            }
        });

        static::saved(function (User $user) {
            if ($user->pendingRole) {
                $role = Role::findOrCreate($user->pendingRole, 'web');
                if ($role->wasRecentlyCreated) {
                    \App\Services\Permission\Club61PermissionMatrix::applyDefaultPermissionsTo($role);
                }
                $user->syncRoles([$role]);
                $user->pendingRole = null;
                $user->unsetRelation('roles');
            }
        });
    }

    /** Email placeholder (akun walk-in tanpa email) tidak punya kotak masuk — tidak dikirimi apa pun. */
    public function sendPasswordResetNotification($token): void
    {
        if (\App\Support\PlaceholderEmail::is($this->email)) {
            return;
        }

        $this->notify(new \App\Notifications\Auth\ResetPasswordNotification($token));
    }

    public function canCancelBooking(): bool
    {
        return $this->can('cancel_padel_booking') || $this->can('cancel_refund_padel');
    }

    public function isCustomer(): bool
    {
        $roleNames = $this->getRoleNames()->map(fn ($r) => strtolower($r));
        if ($roleNames->isEmpty()) {
            return true;
        }

        return $roleNames->count() === 1 && $roleNames->first() === 'customer';
    }

    public function isStaff(): bool
    {
        return $this->hasRole('cashier') || $this->isAdmin();
    }

    public function isAdmin(): bool
    {
        if ($this->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        // isAdmin() = "staf backoffice" untuk routing (/pos, /kitchen), BUKAN izin aksi —
        // aksi sensitif wajib dicek lewat can(). Role frontline di sini tidak dianggap backoffice.
        $nonAdminRoles = ['customer', 'cashier', 'kitchen', 'receptionist'];
        return $this->roles->contains(fn ($role) => ! in_array(strtolower($role->name), $nonAdminRoles, true));
    }

    public function isCashier(): bool
    {
        return $this->hasRole('cashier');
    }

    public function isKitchen(): bool
    {
        return $this->hasRole('kitchen');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->is_active === false) {
            return false;
        }

        return ! $this->isCustomer() || $this->can('access_admin_panel');
    }

    public function staffProfile()
    {
        return $this->hasOne(\App\Models\Staff\StaffProfile::class, 'user_id');
    }
}
