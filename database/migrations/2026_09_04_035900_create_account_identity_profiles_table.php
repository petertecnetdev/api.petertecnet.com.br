<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_identity_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->text('nationality')->nullable();
            $table->text('birthplace')->nullable();
            $table->text('identity_document_type')->nullable();
            $table->text('identity_document_number')->nullable();
            $table->text('identity_document_issuer')->nullable();
            $table->text('parent_1')->nullable();
            $table->text('parent_2')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_identity_profiles');
    }
};
