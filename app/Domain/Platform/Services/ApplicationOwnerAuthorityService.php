<?php

namespace App\Domain\Platform\Services;

use App\Models\ApplicationOwner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ApplicationOwnerAuthorityService
{
    public const BOOTSTRAP_OWNER_EMAIL = 'petertecnet@gmail.com';

    public function isOwner(User $user, int $applicationId): bool
    {
        if ($applicationId <= 0) {
            return false;
        }

        if (! Schema::hasTable('application_owners')) {
            return $this->matchesBootstrapEmail($user);
        }

        $binding = ApplicationOwner::query()
            ->where('application_id', $applicationId)
            ->first();

        if ($binding) {
            return (int) $binding->user_id === (int) $user->id;
        }

        if (! $this->matchesBootstrapEmail($user)) {
            return false;
        }

        return DB::transaction(function () use ($user, $applicationId): bool {
            $binding = ApplicationOwner::query()
                ->where('application_id', $applicationId)
                ->lockForUpdate()
                ->first();

            if (! $binding) {
                $binding = ApplicationOwner::query()->create([
                    'application_id' => $applicationId,
                    'user_id' => $user->id,
                    'bootstrap_email' => self::BOOTSTRAP_OWNER_EMAIL,
                    'bound_at' => now(),
                    'bound_by_user_id' => $user->id,
                ]);
            }

            return (int) $binding->user_id === (int) $user->id;
        });
    }

    public function ownerId(int $applicationId): ?int
    {
        if ($applicationId <= 0 || ! Schema::hasTable('application_owners')) {
            return null;
        }

        $value = ApplicationOwner::query()
            ->where('application_id', $applicationId)
            ->value('user_id');

        return $value ? (int) $value : null;
    }

    public function matchesBootstrapEmail(User $user): bool
    {
        return strtolower(trim((string) $user->email)) === self::BOOTSTRAP_OWNER_EMAIL;
    }
}
