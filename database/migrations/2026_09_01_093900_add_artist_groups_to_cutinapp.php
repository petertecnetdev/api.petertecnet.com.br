<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cutinapp_artists', function (Blueprint $table) {
            $table->string('artist_type', 30)->default('solo')->after('slug')->index();
        });

        Schema::create('cutinapp_artist_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('artist_id')->constrained('cutinapp_artists')->cascadeOnDelete();
            $table->foreignId('member_artist_id')->nullable()->constrained('cutinapp_artists')->nullOnDelete();
            $table->string('display_name');
            $table->string('role', 160)->nullable();
            $table->string('photo', 2048)->nullable();
            $table->text('bio')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_current')->default(true);
            $table->date('joined_at')->nullable();
            $table->date('left_at')->nullable();
            $table->timestamps();

            $table->index(['app_id', 'artist_id', 'is_current'], 'cut_artist_members_current_idx');
            $table->unique(['artist_id', 'member_artist_id'], 'cut_artist_member_link_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cutinapp_artist_members');
        Schema::table('cutinapp_artists', function (Blueprint $table) {
            $table->dropIndex(['artist_type']);
            $table->dropColumn('artist_type');
        });
    }
};
