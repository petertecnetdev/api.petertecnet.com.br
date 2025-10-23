<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OwnerAppointmentNotification extends Mailable
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
        // Nome do cliente — com fallback seguro
        $customerName =
            optional($this->order->client)->first_name
            ?? $this->order->customer_name
            ?? 'Cliente';

        // Nome do colaborador (Employer -> User)
        $attendantName =
            optional(optional($this->order->attendant)->user)->first_name
            ?? 'Colaborador';

        // Nome do estabelecimento (compatível com entity() e establishment)
        $establishmentName =
            optional($this->order->entity)->name
            ?? optional($this->order->establishment)->name
            ?? 'Estabelecimento';

        return $this->subject('📢 Novo agendamento em seu estabelecimento')
            ->view('emails.appointments.owner_appointment')
            ->with([
                'order' => $this->order,
                'appUrl' => $this->appUrl,
                'customerName' => $customerName,
                'attendantName' => $attendantName,
                'date' => $this->order->order_datetime
                    ? $this->order->order_datetime->format('d/m/Y H:i')
                    : 'Data não informada',
                'services' => $this->order->items()
                    ->with('item')
                    ->get()
                    ->map(fn($i) => $i->item->name)
                    ->implode(', '),
                'establishment' => $establishmentName,
            ]);
    }
}
