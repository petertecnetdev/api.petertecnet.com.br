<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration {
    public function up(): void
    {
        $this->assertNoDuplicates('email');
        $this->assertNoDuplicates('google_id');
        $this->assertNoDuplicates('cpf');
        $this->assertNoDuplicates('phone');
        $this->assertNoDuplicates('user_name');

        Schema::table('users', function (Blueprint $table) {
            $table->unique('google_id', 'users_google_id_unique');
            $table->unique('cpf', 'users_cpf_unique');
            $table->unique('phone', 'users_phone_unique');
            $table->unique('user_name', 'users_user_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_google_id_unique');
            $table->dropUnique('users_cpf_unique');
            $table->dropUnique('users_phone_unique');
            $table->dropUnique('users_user_name_unique');
        });
    }

    private function assertNoDuplicates(string $column): void
    {
        $duplicate = DB::table('users')
            ->select($column, DB::raw('COUNT(*) as total'))
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate) {
            throw new RuntimeException(
                "Não foi possível criar o índice único de users.{$column}: existem valores duplicados. Corrija os registros duplicados antes de executar a migration novamente."
            );
        }
    }
};
