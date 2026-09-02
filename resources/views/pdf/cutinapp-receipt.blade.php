<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Recibo Cutinapp {{ $receipt['number'] }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #172033; font-size: 12px; margin: 28px; }
        .header { border-bottom: 2px solid #172033; padding-bottom: 14px; margin-bottom: 20px; }
        .brand { font-size: 24px; font-weight: 700; }
        .muted { color: #667085; }
        .grid { width: 100%; margin-bottom: 18px; }
        .grid td { vertical-align: top; width: 50%; padding: 4px 10px 4px 0; }
        .box { border: 1px solid #d8dee9; border-radius: 8px; padding: 12px; margin-bottom: 16px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.items th, table.items td { border-bottom: 1px solid #e4e7ec; padding: 8px 4px; text-align: left; }
        table.items th:last-child, table.items td:last-child { text-align: right; }
        .total { font-size: 16px; font-weight: 700; text-align: right; margin-top: 12px; }
        .status { font-weight: 700; text-transform: uppercase; }
        .footer { margin-top: 30px; font-size: 10px; color: #667085; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <div class="brand">Cutinapp</div>
        <div class="muted">Comprovante de compra</div>
    </div>

    <table class="grid">
        <tr>
            <td><strong>Pedido</strong><br>#{{ $receipt['number'] }}</td>
            <td><strong>Status</strong><br><span class="status">{{ $receipt['status'] }}</span></td>
        </tr>
        <tr>
            <td><strong>Comprador</strong><br>{{ $receipt['buyer']['name'] }}<br>{{ $receipt['buyer']['email'] }}</td>
            <td><strong>Evento</strong><br>{{ data_get($receipt, 'event.title', '-') }}<br><span class="muted">{{ data_get($receipt, 'production.name', '-') }}</span></td>
        </tr>
    </table>

    <div class="box">
        <strong>Itens da compra</strong>
        <table class="items">
            <thead>
                <tr><th>Item</th><th>Qtd.</th><th>Unitário</th><th>Total</th></tr>
            </thead>
            <tbody>
            @foreach($receipt['items'] as $item)
                <tr>
                    <td>{{ $item['name'] }}</td>
                    <td>{{ $item['quantity'] }}</td>
                    <td>R$ {{ number_format((float) $item['unit_price'], 2, ',', '.') }}</td>
                    <td>R$ {{ number_format((float) $item['subtotal'], 2, ',', '.') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="total">Total: R$ {{ number_format((float) $receipt['total'], 2, ',', '.') }}</div>
    </div>

    <div class="box">
        <strong>Pagamento</strong><br>
        Forma: {{ strtoupper((string) $receipt['payment_method']) }}<br>
        @if($receipt['payment'])
            Provedor: {{ ucfirst((string) $receipt['payment']['provider']) }}<br>
            Situação: {{ strtoupper((string) $receipt['payment']['status']) }}<br>
            Identificador: {{ $receipt['payment']['provider_payment_id'] ?: '-' }}<br>
        @else
            Situação: pagamento ainda não registrado<br>
        @endif
        @if($receipt['paid_at'])
            Pago em: {{ \Carbon\Carbon::parse($receipt['paid_at'])->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}
        @endif
    </div>

    <div class="muted">Este comprovante registra a compra e o pagamento. Ele não substitui o ingresso de acesso ao evento.</div>
    <div class="footer">Gerado pela Cutinapp em {{ \Carbon\Carbon::parse($receipt['generated_at'])->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}.</div>
</body>
</html>
