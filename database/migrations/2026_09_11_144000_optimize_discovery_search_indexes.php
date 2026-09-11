<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->index('events', 'events_app_title_search_idx', ['app_id', 'title']);
        $this->index('events', 'events_app_city_date_search_idx', ['app_id', 'city', 'start_date']);
        $this->index('events', 'events_app_category_date_search_idx', ['app_id', 'category', 'start_date']);
        $this->index('establishments', 'establishments_app_name_search_idx', ['app_id', 'name']);
        $this->index('establishments', 'establishments_app_city_search_idx', ['app_id', 'city']);
        $this->index('artists', 'artists_app_stage_search_idx', ['app_id', 'stage_name']);
        $this->index('artists', 'artists_app_city_search_idx', ['app_id', 'city']);
        $this->index('users', 'users_username_search_idx', ['user_name']);
        $this->index('users', 'users_name_search_idx', ['first_name', 'last_name']);
        $this->index('items', 'items_app_name_search_idx', ['app_id', 'name']);
        $this->index('items', 'items_app_category_search_idx', ['app_id', 'category']);

        $this->fullText('events', 'events_search_fulltext', ['title', 'description', 'venue', 'city', 'category']);
        $this->fullText('establishments', 'establishments_search_fulltext', ['name', 'fantasy', 'description', 'city']);
        $this->fullText('artists', 'artists_search_fulltext', ['stage_name', 'bio', 'city']);
        $this->fullText('items', 'items_search_fulltext', ['name', 'description', 'category', 'subcategory', 'brand']);
    }

    public function down(): void
    {
        foreach ([
            ['events', 'events_app_title_search_idx'],
            ['events', 'events_app_city_date_search_idx'],
            ['events', 'events_app_category_date_search_idx'],
            ['establishments', 'establishments_app_name_search_idx'],
            ['establishments', 'establishments_app_city_search_idx'],
            ['artists', 'artists_app_stage_search_idx'],
            ['artists', 'artists_app_city_search_idx'],
            ['users', 'users_username_search_idx'],
            ['users', 'users_name_search_idx'],
            ['items', 'items_app_name_search_idx'],
            ['items', 'items_app_category_search_idx'],
            ['events', 'events_search_fulltext'],
            ['establishments', 'establishments_search_fulltext'],
            ['artists', 'artists_search_fulltext'],
            ['items', 'items_search_fulltext'],
        ] as [$table, $index]) {
            try {
                DB::statement(sprintf('ALTER TABLE %s DROP INDEX %s', $table, $index));
            } catch (\Throwable) {
            }
        }
    }

    private function index(string $table, string $name, array $columns): void
    {
        if (! Schema::hasTable($table)) return;

        try {
            $existing = collect(Schema::getIndexes($table))->pluck('name')->filter()->all();
            if (in_array($name, $existing, true)) return;
        } catch (\Throwable) {
        }

        try {
            $quoted = implode(',', $columns);
            DB::statement("CREATE INDEX {$name} ON {$table} ({$quoted})");
        } catch (\Throwable) {
        }
    }

    private function fullText(string $table, string $name, array $columns): void
    {
        if (! Schema::hasTable($table)) return;

        try {
            $existing = collect(Schema::getIndexes($table))->pluck('name')->filter()->all();
            if (in_array($name, $existing, true)) return;
        } catch (\Throwable) {
        }

        try {
            $quoted = implode(',', $columns);
            DB::statement("ALTER TABLE {$table} ADD FULLTEXT INDEX {$name} ({$quoted})");
        } catch (\Throwable) {
            // MySQL/MariaDB optimization only. Other engines keep the normal indexes.
        }
    }
};
