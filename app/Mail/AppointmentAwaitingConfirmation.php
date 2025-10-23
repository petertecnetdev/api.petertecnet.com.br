<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AppointmentAwaitingConfirmation extends Mailable
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
        // Nome do cliente
        $customerName =
            $this->order->customer_name
            ?? optional($this->order->client)->first_name
            ?? 'Cliente';

        // Nome do colaborador
        $attendantName =
            optional(optional($this->order->attendant)->user)->first_name
            ?? 'Colaborador';

        // Nome do estabelecimento
        $establishmentName =
            optional($this->order->entity)->name
            ?? optional($this->order->establishment)->name
            ?? 'Estabelecimento';

        return $this->subject('⏳ Seu agendamento está aguardando confirmação')
            ->view('emails.appointments.awaiting_confirmation')
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
