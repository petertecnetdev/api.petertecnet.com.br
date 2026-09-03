<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('ecosystem.admin', function ($user) {
    return $user->hasProfile('Administrador')
        || $user->hasPermission('ecosystem_manage')
        || $user->hasPermission('operations_view')
        || $user->hasPermission('security_view');
});
