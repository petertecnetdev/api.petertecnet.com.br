<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('ecosystem.admin', function ($user) {
    return $user->hasProfile('Administrador')
        || $user->hasPermission('ecosystem_manage')
        || $user->hasPermission('operations_view')
        || $user->hasPermission('security_view');
});

Broadcast::channel('messaging.{conversationId}', function ($user, $conversationId) {
    return DB::table('conversation_participants as cp')
        ->join('conversations as c', 'c.id', '=', 'cp.conversation_id')
        ->where('cp.conversation_id', (int) $conversationId)
        ->where('cp.user_id', (int) $user->id)
        ->whereNull('c.deleted_at')
        ->exists();
});
