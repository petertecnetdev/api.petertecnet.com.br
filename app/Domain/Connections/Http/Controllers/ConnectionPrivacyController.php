<?php

namespace App\Domain\Connections\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class ConnectionPrivacyController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function export(Request $request)
    {
        $userId = (int) $request->user()->id;
        $appId = $this->context->id();
        $profile = DB::table('connection_profiles')->where('app_id', $appId)->where('user_id', $userId)->first();

        $data = [
            'generated_at' => now()->toIso8601String(),
            'profile' => $profile,
            'photos' => $profile
                ? DB::table('connection_profile_photos')->where('app_id', $appId)->where('profile_id', $profile->id)->get()
                : [],
            'decisions' => DB::table('connection_decisions')->where('app_id', $appId)->where('actor_user_id', $userId)->get(),
            'connections' => DB::table('connections')->where('app_id', $appId)
                ->where(fn ($query) => $query->where('user_one_id', $userId)->orWhere('user_two_id', $userId))->get(),
            'messages' => DB::table('connection_messages')->where('app_id', $appId)->where('sender_user_id', $userId)->get(),
            'blocks' => DB::table('connection_blocks')->where('app_id', $appId)->where('blocker_user_id', $userId)->get(),
            'reports_submitted' => DB::table('connection_reports')->where('app_id', $appId)->where('reporter_user_id', $userId)->get(),
            'moderation_actions' => DB::table('connection_moderation_actions')->where('app_id', $appId)->where('target_user_id', $userId)->get(),
        ];

        return response()->json(['data' => $data]);
    }

    public function destroyProfile(Request $request)
    {
        $userId = (int) $request->user()->id;
        $appId = $this->context->id();
        $request->validate(['confirmation' => ['required', 'in:EXCLUIR']]);

        $profile = DB::table('connection_profiles')->where('app_id', $appId)->where('user_id', $userId)->first();
        if (! $profile) {
            return response()->json(['message' => 'Seu perfil já não existe.']);
        }

        $paths = DB::table('connection_profile_photos')
            ->where('app_id', $appId)
            ->where('profile_id', $profile->id)
            ->pluck('path')
            ->all();

        DB::transaction(function () use ($appId, $userId, $profile) {
            DB::table('connection_messages')
                ->where('app_id', $appId)
                ->where('sender_user_id', $userId)
                ->update([
                    'body' => '[mensagem removida pelo titular]',
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('connections')->where('app_id', $appId)
                ->where(fn ($query) => $query->where('user_one_id', $userId)->orWhere('user_two_id', $userId))
                ->delete();
            DB::table('connection_decisions')->where('app_id', $appId)
                ->where(fn ($query) => $query->where('actor_user_id', $userId)->orWhere('target_user_id', $userId))
                ->delete();
            DB::table('connection_blocks')->where('app_id', $appId)
                ->where(fn ($query) => $query->where('blocker_user_id', $userId)->orWhere('blocked_user_id', $userId))
                ->delete();
            DB::table('connection_profiles')->where('app_id', $appId)->where('id', $profile->id)->delete();
        });

        foreach ($paths as $path) {
            Storage::disk('public')->delete($path);
        }

        return response()->json([
            'message' => 'Seu perfil e dados de relacionamento foram removidos. Registros mínimos de segurança e moderação podem ser preservados quando houver obrigação ou interesse legítimo aplicável.',
        ]);
    }
}
