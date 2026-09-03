<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('establishments', function (Blueprint $table) {
            if (! Schema::hasColumn('establishments', 'tax_id')) {
                $table->string('tax_id', 64)->nullable()->after('cnpj');
            }
            if (! Schema::hasColumn('establishments', 'tax_id_type')) {
                $table->string('tax_id_type', 32)->nullable()->after('tax_id');
            }
            if (! Schema::hasColumn('establishments', 'country_code')) {
                $table->string('country_code', 2)->default('BR')->after('tax_id_type');
            }
        });

        DB::table('establishments')
            ->select(['id', 'cnpj'])
            ->orderBy('id')
            ->chunkById(250, function ($rows) {
                foreach ($rows as $row) {
                    $taxId = preg_replace('/\D+/', '', (string) $row->cnpj) ?: null;
                    DB::table('establishments')->where('id', $row->id)->update([
                        'tax_id' => $taxId,
                        'tax_id_type' => match (strlen((string) $taxId)) {
                            11 => 'cpf',
                            14 => 'cnpj',
                            default => $taxId ? 'tax_id' : null,
                        },
                        'country_code' => 'BR',
                        'cnpj' => $taxId,
                    ]);
                }
            });

        Schema::table('establishments', function (Blueprint $table) {
            $table->index(['country_code', 'tax_id'], 'establishments_country_tax_idx');
        });
    }

    public function down(): void
    {
        Schema::table('establishments', function (Blueprint $table) {
            $table->dropIndex('establishments_country_tax_idx');
            $table->dropColumn(['tax_id', 'tax_id_type', 'country_code']);
        });
    }
};
