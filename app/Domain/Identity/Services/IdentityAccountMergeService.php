<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\IdentityChallenge;
use App\Domain\Identity\Models\IdentityCredential;
use App\Domain\Identity\Models\IdentityIdentifier;
use App\Domain\Identity\Models\IdentitySecuritySetting;
use App\Domain\Identity\Models\IdentitySession;
use App\Domain\Identity\Models\IdentityStepUpGrant;
use App\Domain\Identity\Models\IdentityTrustedDevice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class IdentityAccountMergeService
{
    public function __construct(
        private readonly IdentityIdentifierService $identifiers,
        private readonly IdentitySessionService $sessions,
        private readonly IdentityGlobalSessionService $globalSessions,
        private readonly IdentityTrustedDeviceService $trustedDevices,
        private readonly IdentityStepUpService $stepUp,
    ) {
    }

    public function preview(User $target, User $source): array
    {
        abort_if((int) $target->id === (int) $source->id, 422, 'As contas precisam ser diferentes.');
        $this->identifiers->syncUser($target);
        $this->identifiers->syncUser($source);

        $blockers = $this->operationalBlockers($source);
        return [
            'target_user_id' => $target->id,
            'source_user_id' => $source->id,
            'source_hint' => $this->accountHint($source),
            'verified_overlap' => $this->verifiedOverlap($target, $source),
            'blockers' => $blockers,
            'can_merge_automatically' => empty($blockers),
            'policy' => empty($blockers)
                ? 'A conta de origem pode ser consolidada automaticamente após a prova de posse das duas contas.'
                : 'Há dados operacionais vinculados à conta de origem. A consolidação automática foi bloqueada para evitar perda de dados.',
        ];
    }

    public function merge(User $target, User $source): array
    {
        $preview = $this->preview($target, $source);
        abort_unless($preview['can_merge_automatically'], 409, 'A conta possui vínculos operacionais e requer revisão antes da consolidação.');

        return DB::transaction(function () use ($target, $source, $preview) {
            $this->sessions->revokeAll($source, 'account_merged');
            $this->globalSessions->revokeAll($source, 'account_merged');
            $this->trustedDevices->revokeAll($source, 'account_merged');
            $this->stepUp->revokeAll($source);

            $source->applications()->get()->each(function ($application) use ($target) {
                $pivot = $application->pivot;
                $target->applications()->syncWithoutDetaching([
                    $application->id => [
                        'role' => $pivot->role ?: 'member',
                        'status' => $pivot->status ?: 'active',
                        'metadata' => $pivot->metadata,
                        'joined_at' => $pivot->joined_at ?: now(),
                    ],
                ]);
            });

            IdentityIdentifier::query()->where('user_id', $source->id)->update(['user_id' => $target->id, 'updated_at' => now()]);
            IdentityCredential::query()->where('user_id', $source->id)->update(['user_id' => $target->id, 'updated_at' => now()]);

            if (Schema::hasTable('interactions') && Schema::hasColumn('interactions', 'user_id')) {
                DB::table('interactions')->where('user_id', $source->id)->update(['user_id' => $target->id]);
            }

            IdentityChallenge::query()->where('user_id', $source->id)->whereNull('consumed_at')->update(['consumed_at' => now(), 'updated_at' => now()]);
            IdentityTrustedDevice::query()->where('user_id', $source->id)->delete();
            IdentityStepUpGrant::query()->where('user_id', $source->id)->delete();
            IdentitySession::query()->where('user_id', $source->id)->delete();
            IdentitySecuritySetting::query()->where('user_id', $source->id)->delete();

            $extra = is_array($source->extra_info) ? $source->extra_info : [];
            $extra['identity_merge'] = ['merged_into_user_id' => $target->id, 'merged_at' => now()->toIso8601String()];
            $source->forceFill([
                'email' => 'merged+'.Str::uuid().'@invalid.petertecnet.local',
                'user_name' => 'merged-'.$source->id.'-'.Str::lower(Str::random(10)),
                'cpf' => null,
                'phone' => null,
                'google_id' => null,
                'password' => Hash::make(Str::random(80)),
                'auth_version' => max((int) ($source->auth_version ?? 1), 1) + 1,
                'email_verified_at' => null,
                'extra_info' => $extra,
            ])->save();

            $this->identifiers->syncUser($target->fresh());
            return $preview + ['merged' => true, 'merged_at' => now()->toIso8601String()];
        });
    }

    private function verifiedOverlap(User $target, User $source): array
    {
        $targetIds = IdentityIdentifier::query()->where('user_id', $target->id)->whereNotNull('verified_at')->whereNull('revoked_at')->get();
        $sourceIds = IdentityIdentifier::query()->where('user_id', $source->id)->whereNotNull('verified_at')->whereNull('revoked_at')->get();
        $sourceKeys = $sourceIds->map(fn ($id) => $id->type.':'.$id->fingerprint)->all();
        return $targetIds
            ->filter(fn ($id) => in_array($id->type.':'.$id->fingerprint, $sourceKeys, true))
            ->map(fn ($id) => ['type' => $id->type, 'hint' => $id->display_hint])
            ->values()->all();
    }

    private function operationalBlockers(User $source): array
    {
        $allowed = [
            'users', 'application_user', 'identity_identifiers', 'identity_credentials',
            'identity_security_settings', 'identity_sessions', 'identity_global_sessions',
            'identity_trusted_devices', 'identity_step_up_grants', 'identity_challenges', 'interactions',
        ];
        $blockers = [];

        try {
            foreach (Schema::getTables() as $tableInfo) {
                $table = is_array($tableInfo) ? ($tableInfo['name'] ?? null) : null;
                if (! $table || in_array($table, $allowed, true) || ! Schema::hasColumn($table, 'user_id')) {
                    continue;
                }
                $count = DB::table($table)->where('user_id', $source->id)->limit(1)->count();
                if ($count > 0) {
                    $blockers[] = ['table' => $table, 'reason' => 'operational_user_reference'];
                }
            }
        } catch (\Throwable) {
            $blockers[] = ['table' => null, 'reason' => 'schema_inventory_unavailable'];
        }

        return $blockers;
    }

    private function accountHint(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => trim((string) ($user->first_name.' '.$user->last_name)),
            'email' => $user->email ? preg_replace('/(^.).*(@.*$)/', '$1***$2', $user->email) : null,
            'username' => $user->user_name,
        ];
    }
}
