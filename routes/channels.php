<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('ecosystem.admin', function ($user) {
    return $user->hasPermission('ecosystem_manage') || strtolower((string) $user->profile?->name) === 'administrador';
});

Broadcast::channel('laora.user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
