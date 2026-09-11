<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();

            if (! Schema::hasColumn('users', 'phone_normalized')) {
                $table->string('phone_normalized', 32)->nullable()->index()->after('phone');
            }

            if (! Schema::hasColumn('users', 'whatsapp_verified_at')) {
                $table->timestamp('whatsapp_verified_at')->nullable()->after('phone_normalized');
            }
        });

        DB::table('users')
            ->select(['id', 'phone'])
            ->whereNotNull('phone')
            ->orderBy('id')
            ->chunkById(500, function ($users) {
                foreach ($users as $user) {
                    $digits = preg_replace('/\D+/', '', (string) $user->phone) ?: '';
                    if ($digits === '') {
                        continue;
                    }

                    if (strlen($digits) === 10 || strlen($digits) === 11) {
                        $digits = '55'.$digits;
                    }

                    if (! str_starts_with($digits, '55') || strlen($digits) < 12 || strlen($digits) > 13) {
                        continue;
                    }

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['phone_normalized' => '+'.$digits]);
                }
            });

        Schema::table('user_invitations', function (Blueprint $table) {
            if (! Schema::hasColumn('user_invitations', 'channel')) {
                $table->string('channel', 32)->default('email')->after('invited_by')->index();
            }

            if (! Schema::hasColumn('user_invitations', 'destination')) {
                $table->string('destination', 255)->nullable()->after('channel')->index();
            }

            if (! Schema::hasColumn('user_invitations', 'code_expires_at')) {
                $table->timestamp('code_expires_at')->nullable()->after('verification_code_hash');
            }

            if (! Schema::hasColumn('user_invitations', 'verification_attempts')) {
                $table->unsignedTinyInteger('verification_attempts')->default(0)->after('code_expires_at');
            }

            if (! Schema::hasColumn('user_invitations', 'last_sent_at')) {
                $table->timestamp('last_sent_at')->nullable()->after('verification_attempts');
            }

            if (! Schema::hasColumn('user_invitations', 'delivery_status')) {
                $table->string('delivery_status', 32)->nullable()->after('last_sent_at');
            }

            if (! Schema::hasColumn('user_invitations', 'provider_message_id')) {
                $table->string('provider_message_id', 255)->nullable()->after('delivery_status')->index();
            }
        });

        DB::table('user_invitations')
            ->whereNull('destination')
            ->orderBy('id')
            ->chunkById(500, function ($invitations) {
                foreach ($invitations as $invitation) {
                    $user = DB::table('users')->where('id', $invitation->user_id)->first(['email']);

                    DB::table('user_invitations')
                        ->where('id', $invitation->id)
                        ->update([
                            'channel' => 'email',
                            'destination' => $user?->email,
                            'code_expires_at' => $invitation->expires_at,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('user_invitations', function (Blueprint $table) {
            foreach ([
                'provider_message_id',
                'delivery_status',
                'last_sent_at',
                'verification_attempts',
                'code_expires_at',
                'destination',
                'channel',
            ] as $column) {
                if (Schema::hasColumn('user_invitations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'whatsapp_verified_at')) {
                $table->dropColumn('whatsapp_verified_at');
            }

            if (Schema::hasColumn('users', 'phone_normalized')) {
                $table->dropColumn('phone_normalized');
            }
        });
    }
};
