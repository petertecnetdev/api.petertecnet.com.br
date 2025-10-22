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
    public $establishmentName;
    public $customerName;
    public $appointmentDate;
    public $attendantName;
    public $services;

    public function __construct(Order $order)
    {
        $this->order = $order;
        $this->establishmentName = $order->entity_name;
        $this->customerName = $order->customer_name;
        $this->appointmentDate = $order->order_datetime ? $order->order_datetime->format('d/m/Y H:i') : null;
        $this->attendantName = optional($order->attendant)->first_name ?? 'Não informado';
        $this->services = $order->items->map(fn($i) => $i->item->name)->implode(', ');
    }

    public function build()
    {
        return $this->subject('📢 Novo agendamento criado no seu estabelecimento')
            ->view('emails.owner_appointment_notification')
            ->with([
                'establishmentName' => $this->establishmentName,
                'customerName' => $this->customerName,
                'appointmentDate' => $this->appointmentDate,
                'attendantName' => $this->attendantName,
                'services' => $this->services,
            ]);
    }
}
