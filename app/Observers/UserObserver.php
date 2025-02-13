<?php

namespace App\Observers;

use App\Models\User;

class UserObserver
{
    /**
     * Handle the User "updated" event.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    public function updated(User $user)
    {
        // Lista de campos comuns a serem sincronizados
        $commonFields = [
            'first_name', 'last_name', 'email', 'phone', 'avatar'
        ];

        // Verificar se algum dos campos comuns foi alterado
        $changes = $user->only(array_keys($user->getChanges()));
        $fieldsToSync = array_intersect_key($changes, array_flip($commonFields));

        if (!empty($fieldsToSync) && $user->is_barber) {
            // Atualizar os campos no modelo Barber correspondente
            $user->barber->update($fieldsToSync);
        }
    }
}
