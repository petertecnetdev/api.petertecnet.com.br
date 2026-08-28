<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('productions')) {
            Schema::create('productions', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->nullable()->unique();
                $table->string('type', 100)->nullable();
                $table->string('phone', 30)->nullable();
                $table->string('establishment_type', 100)->nullable();
                $table->text('description')->nullable();
                $table->string('city', 120)->nullable();
                $table->string('location')->nullable();
                $table->string('cep', 20)->nullable();
                $table->string('address')->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->boolean('is_featured')->default(false);
                $table->boolean('is_published')->default(false);
                $table->boolean('is_approved')->default(false);
                $table->boolean('is_cancelled')->default(false);
                $table->text('additional_info')->nullable();
                $table->string('facebook_url', 2048)->nullable();
                $table->string('twitter_url', 2048)->nullable();
                $table->string('instagram_url', 2048)->nullable();
                $table->string('youtube_url', 2048)->nullable();
                $table->string('website_url', 2048)->nullable();
                $table->text('other_information')->nullable();
                $table->decimal('ticket_price_min', 12, 2)->nullable();
                $table->decimal('ticket_price_max', 12, 2)->nullable();
                $table->unsignedInteger('total_tickets_sold')->default(0);
                $table->unsignedInteger('total_tickets_available')->default(0);
                $table->string('logo')->nullable();
                $table->string('background')->nullable();
                $table->json('segments')->nullable();
                $table->string('cnpj', 18)->nullable()->index();
                $table->string('fantasy')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('events')) {
            Schema::create('events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('production_id')->nullable()->constrained('productions')->cascadeOnDelete();
                $table->string('title');
                $table->text('description')->nullable();
                $table->string('image')->nullable();
                $table->string('address')->nullable();
                $table->dateTime('start_date')->nullable()->index();
                $table->dateTime('end_date')->nullable();
                $table->string('venue')->nullable();
                $table->string('uf', 2)->nullable();
                $table->string('establishment_type', 100)->nullable();
                $table->string('slug')->nullable()->unique();
                $table->string('city', 120)->nullable();
                $table->string('state', 120)->nullable();
                $table->string('country', 120)->nullable();
                $table->string('location')->nullable();
                $table->string('cep', 20)->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->boolean('is_featured')->default(false);
                $table->boolean('is_published')->default(false);
                $table->boolean('is_approved')->default(false);
                $table->boolean('is_cancelled')->default(false);
                $table->unsignedInteger('max_attendees')->nullable();
                $table->unsignedInteger('remaining_tickets')->nullable();
                $table->json('extra_info')->nullable();
                $table->json('agenda')->nullable();
                $table->json('menu')->nullable();
                $table->json('additional_info')->nullable();
                $table->string('facebook_url', 2048)->nullable();
                $table->string('twitter_url', 2048)->nullable();
                $table->string('instagram_url', 2048)->nullable();
                $table->string('youtube_url', 2048)->nullable();
                $table->string('contact_email')->nullable();
                $table->string('contact_phone', 30)->nullable();
                $table->string('website', 2048)->nullable();
                $table->string('registration_link', 2048)->nullable();
                $table->string('organizer_name')->nullable();
                $table->string('organizer_email')->nullable();
                $table->string('organizer_phone', 30)->nullable();
                $table->text('organizer_description')->nullable();
                $table->json('speaker_list')->nullable();
                $table->json('sponsor_list')->nullable();
                $table->json('partners')->nullable();
                $table->json('reviews')->nullable();
                $table->decimal('rating', 3, 2)->nullable();
                $table->boolean('is_private')->default(false);
                $table->boolean('requires_approval')->default(false);
                $table->text('approval_message')->nullable();
                $table->json('segments')->nullable();
                $table->string('establishment_name')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('tickets')) {
            Schema::create('tickets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
                $table->string('name');
                $table->string('type')->nullable();
                $table->string('ticket_type')->nullable();
                $table->decimal('price', 12, 2)->default(0);
                $table->unsignedInteger('quantity')->default(0);
                $table->dateTime('limit_date')->nullable();
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('service_records')) {
            Schema::create('service_records', function (Blueprint $table) {
                $table->id();
                $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
                $table->string('entity_name', 100);
                $table->unsignedBigInteger('entity_id');
                $table->json('service_ids');
                $table->foreignId('provider_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
                $table->decimal('discount', 12, 2)->default(0);
                $table->string('payment_method', 50);
                $table->decimal('total_price', 12, 2)->default(0);
                $table->string('status', 40)->default('pending');
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['app_id', 'entity_name', 'entity_id']);
                $table->index(['provider_id', 'created_at']);
                $table->index(['client_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('news')) {
            Schema::create('news', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->longText('content');
                $table->string('image')->nullable();
                $table->timestamp('published_at')->nullable()->index();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('menu')) {
            Schema::create('menu', function (Blueprint $table) {
                $table->id();
                $table->foreignId('establishment_id')->constrained('establishments')->cascadeOnDelete();
                $table->string('name');
                $table->string('slug')->nullable()->index();
                $table->text('description')->nullable();
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->decimal('price_modifier_percent', 8, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->string('cover_image')->nullable();
                $table->string('pdf_path')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('menu_itens')) {
            Schema::create('menu_itens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('menu_id')->constrained('menu')->cascadeOnDelete();
                $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
                $table->unsignedInteger('display_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->decimal('price_override', 12, 2)->nullable();
                $table->timestamps();

                $table->unique(['menu_id', 'item_id']);
            });
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive. This migration bootstraps legacy tables only
        // when they are absent and may run against installations where some tables
        // predate Laravel's migration history.
    }
};
