<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewAppointmentNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $order;
    public $appUrl;

    public function __construct(Order $order, $appUrl = null)
    {
        $this->order = $order;
        $this->appUrl = $appUrl;
    }

    public function build()
    {
        return $this->subject('📅 Novo agendamento recebido')
            ->view('emails.appointments.new_appointment')
            ->with([
                'order' => $this->order,
                'appUrl' => $this->appUrl,

                // Nome do cliente (preferência: usuário logado > nome informado > fallback)
                'customerName' => $this->order->client->first_name
                    ?? $this->order->customer_name
                    ?? 'Cliente',

                // Nome do colaborador (Employer → User)
                'attendantName' => optional($this->order->attendant)->user->first_name
                    ?? 'Colaborador',

                // Data formatada
                'date' => $this->order->order_datetime
                    ? $this->order->order_datetime->format('d/m/Y H:i')
                    : 'Data não informada',

                // Lista de serviços do pedido
                'services' => $this->order->items()
                    ->with('item')
                    ->get()
                    ->map(fn($i) => $i->item->name)
                    ->implode(', '),

                // Nome do estabelecimento (morph compatível com entity() ou establishment())
                'establishment' => optional($this->order->entity ?? $this->order->establishment)->name
                    ?? 'Estabelecimento',
            ]);
    }
}
