<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class SystemController extends Controller
{
    public function dashboard()
    {
        $now = now();
        return response()->json([
            'metrics' => [
                'users_total' => User::count(),
                'users_active' => Schema::hasColumn('users', 'status') ? User::where('status', 'active')->count() : User::count(),
                'users_blocked' => Schema::hasColumn('users', 'status') ? User::where('status', 'blocked')->count() : 0,
                'new_users_30d' => User::where('created_at', '>=', $now->copy()->subDays(30))->count(),
                'applications_total' => Application::count(),
                'applications_active' => Application::where('is_active', true)->count(),
                'profiles_total' => Profile::count(),
                'access_links' => DB::table('application_user')->count(),
            ],
            'applications' => Application::query()->withCount(['users', 'establishments', 'items'])->orderBy('name')->get(),
            'recent_users' => User::query()->with('profile:id,name')->latest()->limit(8)->get(['id','user_name','first_name','last_name','email','profile_id','created_at']),
            'recent_audit' => Schema::hasTable('admin_audit_logs') ? DB::table('admin_audit_logs')->latest()->limit(12)->get() : [],
            'api' => [
                'status' => 'online',
                'database' => DB::connection()->getPdo() ? 'online' : 'offline',
                'server_time' => $now->toIso8601String(),
                'environment' => app()->environment(),
                'laravel' => app()->version(),
            ],
        ]);
    }

    public function users(Request $request)
    {
        $query = User::query()->with(['profile:id,name', 'applications:id,name,slug']);
        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('user_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }
        if ($status = $request->query('status')) {
            if (Schema::hasColumn('users', 'status')) $query->where('status', $status);
        }
        return response()->json($query->latest()->paginate(min((int) $request->query('per_page', 25), 100)));
    }

    public function storeUser(Request $request)
    {
        $data = $request->validate([
            'first_name' => 'required|string|max:120',
            'last_name' => 'nullable|string|max:120',
            'user_name' => 'required|string|max:120|unique:users,user_name',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => ['required', Password::min(8)],
            'profile_id' => 'nullable|exists:profiles,id',
            'status' => 'nullable|in:active,blocked,inactive',
        ]);
        $data['password'] = Hash::make($data['password']);
        $user = User::create($data);
        $this->audit($request, 'user.created', 'user', $user->id, null, $user->only(['id','user_name','email','profile_id']));
        return response()->json(['user' => $user->load('profile:id,name')], 201);
    }

    public function updateUser(Request $request, User $user)
    {
        $before = $user->toArray();
        $data = $request->validate([
            'first_name' => 'sometimes|required|string|max:120',
            'last_name' => 'nullable|string|max:120',
            'user_name' => ['sometimes','required','string','max:120', Rule::unique('users','user_name')->ignore($user->id)],
            'email' => ['sometimes','required','email','max:255', Rule::unique('users','email')->ignore($user->id)],
            'profile_id' => 'nullable|exists:profiles,id',
            'password' => ['nullable', Password::min(8)],
        ]);
        if (! empty($data['password'])) $data['password'] = Hash::make($data['password']); else unset($data['password']);
        $user->update($data);
        $this->audit($request, 'user.updated', 'user', $user->id, $before, $user->fresh()->toArray());
        return response()->json(['user' => $user->fresh()->load('profile:id,name')]);
    }

    public function destroyUser(Request $request, User $user)
    {
        abort_if($user->id === auth('api')->id(), 422, 'Você não pode excluir o próprio usuário administrador.');
        $before = $user->toArray();
        DB::transaction(function () use ($user) {
            DB::table('application_user')->where('user_id', $user->id)->delete();
            $user->delete();
        });
        $this->audit($request, 'user.deleted', 'user', $user->id, $before, null);
        return response()->json(['message' => 'Usuário excluído.']);
    }

    public function setUserStatus(Request $request, User $user)
    {
        $data = $request->validate(['status' => 'required|in:active,blocked,inactive', 'reason' => 'nullable|string|max:500']);
        abort_if($user->id === auth('api')->id() && $data['status'] !== 'active', 422, 'Você não pode bloquear o próprio acesso.');
        $before = $user->toArray();
        $user->forceFill([
            'status' => $data['status'],
            'blocked_at' => $data['status'] === 'blocked' ? now() : null,
            'blocked_reason' => $data['status'] === 'blocked' ? ($data['reason'] ?? null) : null,
            'auth_version' => max((int) $user->auth_version, 1) + 1,
        ])->save();
        $this->audit($request, 'user.status_changed', 'user', $user->id, $before, $user->fresh()->toArray());
        return response()->json(['user' => $user->fresh()]);
    }

    public function profiles()
    {
        return response()->json(['profiles' => Profile::query()->withCount('users')->orderBy('name')->get()]);
    }

    public function applicationsAccess(User $user)
    {
        return response()->json([
            'user' => $user->only(['id','user_name','first_name','last_name','email']),
            'applications' => Application::query()->orderBy('name')->get()->map(function ($app) use ($user) {
                $pivot = DB::table('application_user')->where('application_id', $app->id)->where('user_id', $user->id)->first();
                return ['id'=>$app->id,'name'=>$app->name,'slug'=>$app->slug,'url'=>$app->url,'granted'=>(bool)$pivot,'status'=>$pivot->status ?? 'blocked','role'=>$pivot->role ?? 'user'];
            }),
        ]);
    }

    public function setApplicationAccess(Request $request, User $user, Application $application)
    {
        $data = $request->validate(['granted'=>'required|boolean','status'=>'nullable|in:active,blocked,suspended','role'=>'nullable|string|max:60']);
        $before = DB::table('application_user')->where('application_id',$application->id)->where('user_id',$user->id)->first();
        if ($data['granted']) {
            $user->applications()->syncWithoutDetaching([$application->id => [
                'role' => $data['role'] ?? ($before->role ?? 'user'),
                'status' => $data['status'] ?? 'active',
                'joined_at' => $before->joined_at ?? now(),
                'updated_at' => now(),
            ]]);
        } else {
            $user->applications()->detach($application->id);
        }
        $after = DB::table('application_user')->where('application_id',$application->id)->where('user_id',$user->id)->first();
        $this->audit($request, 'application.access_changed', 'application_user', $application->id, $before, $after);
        return response()->json(['message' => 'Acesso atualizado.']);
    }

    public function auditLogs(Request $request)
    {
        if (! Schema::hasTable('admin_audit_logs')) return response()->json(['data'=>[]]);
        $query = DB::table('admin_audit_logs')->orderByDesc('id');
        if ($action = $request->query('action')) $query->where('action','like',"%{$action}%");
        return response()->json($query->paginate(min((int)$request->query('per_page',50),100)));
    }

    private function audit(Request $request, string $action, ?string $type, ?int $id, $before, $after): void
    {
        if (! Schema::hasTable('admin_audit_logs')) return;
        DB::table('admin_audit_logs')->insert([
            'actor_user_id'=>auth('api')->id(),'action'=>$action,'entity_type'=>$type,'entity_id'=>$id,
            'before'=>$before ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
            'after'=>$after ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
            'ip'=>$request->ip(),'user_agent'=>$request->userAgent(),'created_at'=>now(),'updated_at'=>now(),
        ]);
    }
}
