<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{{ $establishment->name }} - {{ $tipo }}</title>
    <style>
        @page {
            margin: 0;
        }
        * {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            font-family: 'DejaVu Sans', sans-serif;
            color: #fff;
            font-size: 12px;
        }

        /* ====== CAPA ====== */
        .page-cover {
            position: relative;
            width: 100%;
            min-height: 100vh;
            height: 100vh;
            overflow: hidden;
        }
        .page-bg {
            position: absolute;
            inset: 0;
            background-size: cover;
            background-position: center;
            filter: blur(18px) brightness(0.35);
            z-index: 0;
        }
        .page-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(160deg, rgba(0,0,0,0.85) 10%, rgba(0,0,0,0.35) 50%, rgba(0,0,0,0.9) 95%);
            z-index: 1;
        }
        .cover-content {
            position: relative;
            z-index: 2;
            height: 100vh;
            padding: 80px 52px 45px 52px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .cover-top {
            display: flex;
            align-items: center;
            gap: 22px;
        }
        .cover-logo {
            width: 120px;
            height: 120px;
            border-radius: 999px;
            background: rgba(0,0,0,0.25);
            border: 3px solid rgba(255,255,255,0.25);
            object-fit: cover;
        }
        .cover-title-block {
            flex: 1;
        }
        .app-name {
            font-size: 12px;
            letter-spacing: 2px;
            text-transform: uppercase;
            opacity: .8;
        }
        .estab-name {
            font-size: 30px;
            font-weight: 700;
            line-height: 1.1;
        }
        .estab-subtitle {
            font-size: 15px;
            margin-top: 4px;
            opacity: .9;
        }
        .cover-bottom {
            border-top: 1px solid rgba(255,255,255,0.12);
            padding-top: 20px;
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
        }
        .info-pill {
            background: rgba(0,0,0,0.45);
            padding: 7px 14px;
            border-radius: 99px;
            font-size: 11px;
            border: 1px solid rgba(255,255,255,0.08);
        }

        /* ====== PÁGINAS DE ITENS ====== */
        .page-items {
            page-break-before: always;
            background: #000;
            min-height: 100vh;
            padding: 58px 45px 45px 45px;
        }

        .category-header {
            width: 100%;
            background: #0f0f0f;
            color: #fff;
            text-align: center;
            padding: 10px 12px 9px 12px;
            margin: 0 0 14px 0;
            font-size: 14px;
            letter-spacing: 2.5px;
            text-transform: uppercase;
            border: 1px solid rgba(255,255,255,0.06);
        }

        .item-row {
            display: flex;
            gap: 12px;
            align-items: stretch;
            background: rgba(255,255,255,0.02);
            border: 1px solid rgba(255,255,255,0.035);
            border-radius: 8px;
            padding: 8px 10px 8px 8px;
            margin-bottom: 8px;
            min-height: 66px;
        }
        .item-thumb {
            width: 60px;
            height: 53px;
            border-radius: 6px;
            background: rgba(255,255,255,0.05);
            object-fit: cover;
        }
        .item-body {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .item-title {
            font-size: 12.5px;
            font-weight: 700;
            line-height: 1.1;
        }
        .item-desc {
            font-size: 10.5px;
            opacity: .85;
            margin-top: 2px;
        }
        .item-price {
            font-size: 13px;
            font-weight: 700;
            align-self: center;
            white-space: nowrap;
            margin-left: 12px;
        }

        /* ====== RODAPÉ ====== */
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 5px 35px 7px 35px;
            font-size: 10px;
            background: rgba(0,0,0,0.7);
            color: rgba(255,255,255,0.6);
            text-align: right;
        }

        /* ====== AJUSTES DOMPDF ====== */
        .avoid-break {
            page-break-inside: avoid;
        }
    </style>
</head>
<body>

@php
    $bg = $establishment->background && file_exists(public_path($establishment->background))
        ? public_path($establishment->background)
        : $logoPath;

    $hasLogo = file_exists($logoPath);
@endphp

{{-- CAPA --}}
<div class="page-cover">
    <div class="page-bg" style="background-image: url('{{ $bg }}');"></div>
    <div class="page-overlay"></div>
    <div class="cover-content">
        <div class="cover-top">
            @if($hasLogo)
                <img src="{{ $logoPath }}" class="cover-logo" alt="Logo">
            @endif
            <div class="cover-title-block">
                <div class="app-name">{{ strtoupper($tipo) }}</div>
                <div class="estab-name">{{ $establishment->name }}</div>
                @if($establishment->description)
                    <div class="estab-subtitle">{{ $establishment->description }}</div>
                @endif
            </div>
        </div>

        <div class="cover-bottom">
            @if($establishment->address)
                <div class="info-pill">📍 {{ $establishment->address }}{{ $establishment->city ? ' - '.$establishment->city : '' }}</div>
            @endif
            @if($establishment->phone)
                <div class="info-pill">📱 WhatsApp: {{ $establishment->phone }}</div>
            @endif
            @if($establishment->instagram_url)
                <div class="info-pill">📸 Instagram: {{ $establishment->instagram_url }}</div>
            @endif

            @php
                $segments = [];
                if (is_array($establishment->segments)) {
                    $segments = $establishment->segments;
                } elseif ($establishment->segments) {
                    $segments = json_decode($establishment->segments, true) ?: [];
                }
            @endphp
            @foreach($segments as $seg)
                <div class="info-pill">{{ $seg }}</div>
            @endforeach
        </div>
    </div>
</div>

{{-- PÁGINAS DE ITENS --}}
@php
    $firstCategory = true;
@endphp
@foreach($grouped as $categoryName => $items)
    <div class="page-items">
        <div class="category-header">{{ strtoupper($categoryName) }}</div>

        @foreach($items as $item)
            <div class="item-row avoid-break">
                @if($item->image && file_exists(public_path($item->image)))
                    <img src="{{ public_path($item->image) }}" alt="img" class="item-thumb">
                @else
                    <div class="item-thumb" style="display:flex;align-items:center;justify-content:center;font-size:9px;opacity:.4;">IMG</div>
                @endif
                <div class="item-body">
                    <div class="item-title">{{ $item->name }}</div>
                    @if($item->description)
                        <div class="item-desc">{{ $item->description }}</div>
                    @endif
                </div>
                <div class="item-price">
                    R$ {{ number_format($item->price, 2, ',', '.') }}
                </div>
            </div>
        @endforeach
    </div>
@endforeach

<div class="footer">
    {{ $establishment->name }} — {{ $tipo }}
</div>

</body>
</html>
