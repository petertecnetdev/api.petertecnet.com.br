<?php

use App\Models\Profile;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Profile::query()->updateOrCreate(
            ['name' => 'Marketing'],
            ['permissions' => [
                'marketing_dashboard',
                'marketing_activity_view',
                'marketing_user_view',
                'marketing_user_invite',
            ]]
        );
    }

    public function down(): void
    {
        Profile::query()->where('name', 'Marketing')->whereDoesntHave('users')->delete();
    }
};
