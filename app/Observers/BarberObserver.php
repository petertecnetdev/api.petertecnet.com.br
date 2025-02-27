<?php 

namespace App\Observers;

use App\Models\Barber;

class BarberObserver
{
    /**
     * Handle the Barber "updated" event.
     *
     * @param  \App\Models\Barber  $barber
     * @return void
     */
    public function updated(Barber $barber)
    {
        // Lista de campos comuns a serem sincronizados
        $commonFields = [
            'first_name', 'last_name', 'email', 'phone', 'avatar'
        ];

        // Verificar se algum dos campos comuns foi alterado
        $changes = $barber->only(array_keys($barber->getChanges()));
        $fieldsToSync = array_intersect_key($changes, array_flip($commonFields));

        if (!empty($fieldsToSync)) {
            // Atualizar os campos no modelo User correspondente
            $barber->user->update($fieldsToSync);
        }
    }
}
