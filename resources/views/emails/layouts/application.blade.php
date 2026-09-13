@php
    $brand = $mailBrand ?? [];
    $brandName = $brand['name'] ?? config('app.name', 'Peter Tecnet');
    $brandInitials = $brand['initials'] ?? 'PT';
    $logoUrl = $brand['logo_url'] ?? null;
    $primary = $brand['primary_color'] ?? '#6d28d9';
    $secondary = $brand['secondary_color'] ?? '#2563eb';
    $accent = $brand['accent_color'] ?? '#0891b2';
    $headerBackground = $brand['header_background_color'] ?? '#111827';
    $pageBackground = $brand['page_background_color'] ?? '#f5f5fb';
    $surface = $brand['surface_color'] ?? '#ffffff';
    $text = $brand['text_color'] ?? '#18162a';
    $muted = $brand['muted_color'] ?? '#6f6b7d';
    $border = $brand['border_color'] ?? '#e7e4ef';
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>@yield('title', $brandName)</title>
</head>
<body style="margin:0;padding:0;background:{{ $pageBackground }};font-family:Arial,Helvetica,sans-serif;color:{{ $text }};">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;mso-hide:all;">
        @yield('preheader')
    </div>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:{{ $pageBackground }};padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:640px;background:{{ $surface }};border:1px solid {{ $border }};border-radius:22px;overflow:hidden;box-shadow:0 12px 36px rgba(17,24,39,.08);">
                    <tr>
                        <td style="height:5px;background:{{ $primary }};font-size:0;line-height:0;">&nbsp;</td>
                    </tr>
                    <tr>
                        <td style="padding:24px 30px;background:{{ $headerBackground }};">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td width="84" valign="middle" style="width:84px;">
                                        @if($logoUrl)
                                            <div style="width:72px;height:72px;border-radius:999px;overflow:hidden;background:#ffffff;border:2px solid rgba(255,255,255,.18);box-shadow:0 8px 24px rgba(0,0,0,.22);">
                                                <img src="{{ $logoUrl }}" alt="{{ $brand['logo_alt'] ?? ('Logo '.$brandName) }}" width="72" height="72" style="display:block;width:72px;height:72px;border:0;border-radius:999px;object-fit:cover;">
                                            </div>
                                        @else
                                            <div style="width:72px;height:72px;line-height:72px;text-align:center;border-radius:999px;background:{{ $primary }};color:{{ $brand['button_text_color'] ?? '#ffffff' }};font-size:22px;font-weight:800;letter-spacing:.03em;">
                                                {{ $brandInitials }}
                                            </div>
                                        @endif
                                    </td>
                                    <td valign="middle" style="padding-left:14px;">
                                        <div style="font-size:12px;line-height:1.2;text-transform:uppercase;letter-spacing:.13em;color:#ffffff;opacity:.72;">Comunicação oficial</div>
                                        <div style="margin-top:5px;font-size:22px;line-height:1.2;font-weight:800;color:#ffffff;">{{ $brandName }}</div>
                                        <div style="margin-top:7px;width:72px;height:3px;border-radius:999px;background:{{ $accent }};font-size:0;line-height:0;">&nbsp;</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:30px;">
                            @yield('content')
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 30px 24px;border-top:1px solid {{ $border }};background:#fbfbfd;">
                            <p style="margin:0;font-size:12px;line-height:1.65;color:{{ $muted }};">
                                Este e-mail foi enviado por <strong style="color:{{ $text }};">{{ $brandName }}</strong>.
                                @if(!($brand['is_factory'] ?? false))
                                    {{ $brandName }} é um produto desenvolvido pela Peter Tecnet.
                                @endif
                            </p>
                            <p style="margin:7px 0 0;font-size:11px;line-height:1.55;color:{{ $muted }};">
                                © {{ date('Y') }} {{ $brandName }}. Nunca informe sua senha, código de verificação ou dados sensíveis em resposta a este e-mail.
                            </p>
                        </td>
                    </tr>
                </table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:640px;">
                    <tr>
                        <td align="center" style="padding:16px 8px 0;font-size:11px;line-height:1.5;color:{{ $muted }};">
                            Mensagem transacional gerada pela plataforma {{ $brandName }}.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
