<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->index(
                ['app_id', 'owner_user_id', 'deleted_at', 'id'],
                'loc_props_owner_read_idx',
            );
        });

        Schema::table('leases', function (Blueprint $table) {
            $table->index(
                ['app_id', 'landlord_user_id', 'deleted_at', 'id'],
                'loc_leases_landlord_read_idx',
            );
            $table->index(
                ['app_id', 'tenant_user_id', 'deleted_at', 'id'],
                'loc_leases_tenant_read_idx',
            );
            $table->index(
                ['app_id', 'property_id', 'deleted_at', 'id'],
                'loc_leases_property_read_idx',
            );
            $table->index(
                ['app_id', 'tenant_email', 'deleted_at', 'id'],
                'loc_leases_email_read_idx',
            );
        });

        Schema::table('lease_charges', function (Blueprint $table) {
            $table->index(
                ['app_id', 'lease_id', 'status', 'paid_at'],
                'loc_charges_paid_read_idx',
            );
        });

        Schema::table('lease_operations', function (Blueprint $table) {
            $table->index(
                ['app_id', 'status', 'priority', 'due_at'],
                'loc_ops_queue_read_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('lease_operations', function (Blueprint $table) {
            $table->dropIndex('loc_ops_queue_read_idx');
        });

        Schema::table('lease_charges', function (Blueprint $table) {
            $table->dropIndex('loc_charges_paid_read_idx');
        });

        Schema::table('leases', function (Blueprint $table) {
            $table->dropIndex('loc_leases_email_read_idx');
            $table->dropIndex('loc_leases_property_read_idx');
            $table->dropIndex('loc_leases_tenant_read_idx');
            $table->dropIndex('loc_leases_landlord_read_idx');
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex('loc_props_owner_read_idx');
        });
    }
};
