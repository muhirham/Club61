<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use Filament\Resources\Pages\CreateRecord;
use Spatie\Permission\Models\Permission;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected array $matrixPermissions = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $collected = [];
        foreach ($data as $key => $value) {
            if (str_starts_with($key, 'permissions_') && is_array($value)) {
                foreach ($value as $perm) {
                    if (! empty($perm)) {
                        $collected[] = $perm;
                    }
                }
            }
        }
        $this->matrixPermissions = array_values(array_unique($collected));

        return [
            'name' => $data['name'],
            'guard_name' => $data['guard_name'] ?? 'web',
            'description' => $data['description'] ?? null,
            'home_route' => ($data['home_route'] ?? null) ?: null, // kosong = otomatis dari permission
        ];
    }

    protected function afterCreate(): void
    {
        $guardName = $this->record->guard_name ?? 'web';

        foreach ($this->matrixPermissions as $permName) {
            Permission::firstOrCreate([
                'name' => $permName,
                'guard_name' => $guardName,
            ]);
        }

        $this->record->syncPermissions($this->matrixPermissions);

        \App\Services\Audit\ActivityLogger::rolePermissionsChanged($this->record, [], $this->matrixPermissions);

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        app('cache')
            ->store(config('permission.cache.store') !== 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }
}
