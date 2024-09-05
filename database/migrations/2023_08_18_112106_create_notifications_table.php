<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateNotificationsTable extends Migration
{
    public function up()
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id'); // ID do usuário associado à notificação
            $table->string('type'); // Tipo de notificação (pode ser 'participant', 'producer', 'etc.')
            $table->text('message'); // Mensagem da notificação
            $table->boolean('read')->default(false); // Indicador se a notificação foi lida
            $table->boolean('archived')->default(false); // Indicador se a notificação foi arquivada
            $table->unsignedBigInteger('related_id')->nullable(); // ID relacionado (por exemplo, ID do evento)
            $table->string('related_type')->nullable(); // Tipo relacionado (por exemplo, 'event')
            $table->timestamps(); // Created_at e updated_at
            $table->softDeletes(); // Campo para remoção lógica            
            $table->json('extra_info')->nullable();

            // Índices
            $table->index(['user_id', 'type', 'read', 'archived']);
            $table->index(['related_id', 'related_type']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('notifications');
    }
}
