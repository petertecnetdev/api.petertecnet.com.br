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
    public $ownerName;

    public function __construct(Order $order, $ownerName = null, $appUrl = null)
    {
        $this->order = $order;
        $this->ownerName = $ownerName;
        $this->appUrl = $appUrl;
    }

    public function build()
    {
        $customerName =
            optional($this->order->client)->first_name
            ?? $this->order->customer_name
            ?? 'Cliente';

        $attendantName =
            optional(optional($this->order->attendant)->user)->first_name
            ?? 'Colaborador';

        $establishmentName =
            optional($this->order->entity)->name
            ?? optional($this->order->establishment)->name
            ?? 'Estabelecimento';

        return $this->subject('📢 Novo agendamento em seu estabelecimento')
            ->view('emails.appointments.owner_appointment')
            ->with([
                'ownerName' => $this->ownerName ?? 'Proprietário',
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
                'appUrl' => $this->appUrl,
            ]);
    }
}
