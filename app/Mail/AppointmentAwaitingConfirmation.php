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
    public $establishmentName;
    public $attendantName;
    public $appointmentDate;
    public $services;

    public function __construct(Order $order)
    {
        $this->order = $order;
        $this->establishmentName = $order->entity_name;
        $this->attendantName = optional($order->attendant)->first_name ?? 'Colaborador';
        $this->appointmentDate = $order->order_datetime ? $order->order_datetime->format('d/m/Y H:i') : null;
        $this->services = $order->items->map(fn($i) => $i->item->name)->implode(', ');
    }

    public function build()
    {
        return $this->subject('⏳ Seu agendamento está aguardando confirmação')
            ->view('emails.appointment_awaiting_confirmation')
            ->with([
                'establishmentName' => $this->establishmentName,
                'attendantName' => $this->attendantName,
                'appointmentDate' => $this->appointmentDate,
                'services' => $this->services,
            ]);
    }
}
