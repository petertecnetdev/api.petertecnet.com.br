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
        return $this->subject('📢 Novo agendamento em seu estabelecimento')
            ->view('emails.appointments.owner_appointment')
            ->with([
                'order' => $this->order,
                'appUrl' => $this->appUrl,
                'customerName' => $this->order->customer_name,
                'attendantName' => optional($this->order->attendant)->user->first_name ?? 'Colaborador',
                'date' => $this->order->order_datetime ? $this->order->order_datetime->format('d/m/Y H:i') : 'Data não informada',
                'services' => $this->order->items()->with('item')->get()->map(fn($i) => $i->item->name)->implode(', '),
                'establishment' => optional($this->order->entity)->name ?? 'Estabelecimento',
            ]);
    }
}
