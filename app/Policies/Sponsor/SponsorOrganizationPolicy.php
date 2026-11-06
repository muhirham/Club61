<?php

namespace App\Policies\Sponsor;

use App\Models\Sponsor\SponsorOrganization;
use App\Models\User;
use App\Support\SiteLocale;
use Illuminate\Auth\Access\Response;

/**
 * Corporate PIC (Person In Charge) authorization — scoped strictly to their own
 * organization. This governs the CUSTOMER PORTAL (/corporate) only, via the routes in
 * SponsorOrganizationController — it does NOT gate the staff Filament panel.
 *
 * App\Filament\Resources\Sponsor\SponsorOrganizationResource has its own explicit
 * canViewAny()/canCreate()/canEdit()/canDelete() overrides checking staff permission
 * slugs (view_sponsor_organizations / manage_sponsor_organizations) instead of this
 * policy, precisely so a staff account is never accidentally granted or denied access
 * based on this PIC-ownership check (sponsor_admin_user_id === user->id), which would
 * be meaningless for a staff member.
 */
class SponsorOrganizationPolicy
{
    public function view(User $user, SponsorOrganization $organization): bool
    {
        return $organization->sponsor_admin_user_id === $user->id;
    }

    public function update(User $user, SponsorOrganization $organization): bool
    {
        return $organization->sponsor_admin_user_id === $user->id;
    }

    public function manageMembers(User $user, SponsorOrganization $organization): bool
    {
        return $organization->sponsor_admin_user_id === $user->id;
    }

    /**
     * Hak Akses PIC: aksi tertentu bisa dimatikan admin per perusahaan (SponsorOrganization::PIC_ACTIONS). Selalu
     * dipakai BERSAMA manageMembers — tetap hanya PIC perusahaan itu sendiri.
     */
    public function performPicAction(User $user, SponsorOrganization $organization, string $action): Response
    {
        return $organization->sponsor_admin_user_id === $user->id && $organization->picAllows($action)
            ? Response::allow()
            : Response::deny(SiteLocale::text('corporate.pic_action_not_allowed'));
    }
}
