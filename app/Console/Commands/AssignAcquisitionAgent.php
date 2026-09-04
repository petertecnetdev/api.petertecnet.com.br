<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AssignAcquisitionAgent extends Command
{
    protected $signature = 'acquisition:assign-agent {application : Slug da aplicação} {email : E-mail do usuário}';
    protected $description = 'Adiciona o papel contextual acquisition_agent a um usuário sem conceder acesso administrativo global.';

    public function handle(): int
    {
        $application = Application::query()->where('slug', strtolower(trim((string) $this->argument('application'))))->first();
        if (! $application) {
            $this->error('Aplicação não encontrada.');
            return self::FAILURE;
        }

        $user = User::query()->where('email', strtolower(trim((string) $this->argument('email'))))->first();
        if (! $user) {
            $this->error('Usuário não encontrado.');
            return self::FAILURE;
        }

        $membership = DB::table('application_user')
            ->where('application_id', $application->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $membership) {
            $user->applications()->attach($application->id, [
                'role' => 'acquisition_agent',
                'status' => 'active',
                'metadata' => json_encode(['roles' => ['acquisition_agent']], JSON_UNESCAPED_UNICODE),
                'joined_at' => now(),
            ]);
        } else {
            $metadata = $membership->metadata ? json_decode((string) $membership->metadata, true) : [];
            if (! is_array($metadata)) $metadata = [];
            $roles = array_values(array_unique(array_merge((array) ($metadata['roles'] ?? []), ['acquisition_agent'])));
            $metadata['roles'] = $roles;

            $updates = [
                'status' => 'active',
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ];
            if (! $membership->role) $updates['role'] = 'acquisition_agent';
            if (! $membership->joined_at) $updates['joined_at'] = now();

            DB::table('application_user')
                ->where('application_id', $application->id)
                ->where('user_id', $user->id)
                ->update($updates);
        }

        $this->info("{$user->email} agora é agente de aquisição em {$application->name}. Nenhum perfil administrativo global foi alterado.");
        return self::SUCCESS;
    }
}
