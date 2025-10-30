<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{{ $establishment->name }} - {{ $tipo }}</title>
    <style>
        @page { margin: 0; }
        body {
            margin: 0;
            font-family: 'DejaVu Sans', sans-serif;
            background: #0a0a0a;
            color: #fff;
        }
        * { box-sizing: border-box; }

        /* ===== CAPA ===== */
        .cover {
            position: relative;
            width: 100%;
            height: 100vh;
            page-break-after: always;
            overflow: hidden;
            background: linear-gradient(160deg, #000 20%, #111 90%);
        }
        .cover-bg {
            position: absolute;
            inset: 0;
            background-size: cover;
            background-position: center;
            filter: brightness(0.25) blur(10px);
            z-index: 0;
        }
        .cover-overlay {
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at center, rgba(255,255,255,0.05), rgba(0,0,0,0.85) 90%);
            z-index: 1;
        }
        .cover-content {
            position: relative;
            z-index: 2;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 80px 70px 50px 70px;
        }
        .cover-header {
            display: flex;
            align-items: center;
            gap: 28px;
        }
        .cover-logo {
            width: 140px;
            height: 140px;
            border-radius: 999px;
            border: 3px solid rgba(255,255,255,0.25);
            object-fit: cover;
            background: rgba(255,255,255,0.08);
        }
        .cover-info {
            flex: 1;
        }
        .cover-info .app {
            text-transform: uppercase;
            letter-spacing: 3px;
            font-size: 12px;
            opacity: 0.8;
        }
        .cover-info .title {
            font-size: 34px;
            font-weight: 800;
            margin-top: 8px;
            line-height: 1.1;
        }
        .cover-info .desc {
            margin-top: 6px;
            font-size: 15px;
            opacity: 0.9;
        }
        .cover-footer {
            border-top: 1px solid rgba(255,255,255,0.1);
            padding-top: 22px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }
        .pill {
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 99px;
            padding: 7px 16px;
            font-size: 11px;
            background: rgba(255,255,255,0.05);
        }

        /* ===== PÁGINAS DE ITENS ===== */
        .page {
            padding: 60px 55px 55px 55px;
            background: #0a0a0a;
            min-height: 100vh;
        }
        .category-title {
            text-transform: uppercase;
            text-align: center;
            font-weight: 700;
            letter-spacing: 2.5px;
            font-size: 14px;
            background: linear-gradient(90deg, #191919, #111);
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 18px;
            border: 1px solid rgba(255,255,255,0.05);
        }
        .item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(255,255,255,0.02);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 10px;
            padding: 10px 14px;
            margin-bottom: 10px;
            min-height: 72px;
        }
        .item-left {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
        }
        .thumb {
            width: 65px;
            height: 65px;
            border-radius: 8px;
            object-fit: cover;
            background: rgba(255,255,255,0.05);
        }
        .thumb.empty {
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            color: rgba(255,255,255,0.3);
        }
        .item-info {
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .item-title {
            font-weight: 700;
            font-size: 13px;
            margin-bottom: 2px;
        }
        .item-desc {
            font-size: 10.5px;
            opacity: 0.8;
            max-width: 400px;
        }
        .item-price {
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
            background: linear-gradient(90deg, #FFD700, #CDA434);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .avoid-break { page-break-inside: avoid; }
    </style>
</head>
<body>
@php
    $bg = $establishment->background && file_exists(public_path($establishment->background))
        ? public_path($establishment->background)
        : $logoPath;
    $hasLogo = file_exists($logoPath);
@endphp

<!-- CAPA -->
<div class="cover">
    <div class="cover-bg" style="background-image: url('{{ $bg }}');"></div>
    <div class="cover-overlay"></div>
    <div class="cover-content">
        <div class="cover-header">
            @if($hasLogo)
                <img src="{{ $logoPath }}" alt="Logo" class="cover-logo">
            @endif
            <div class="cover-info">
                <div class="app">{{ strtoupper($tipo) }}</div>
                <div class="title">{{ $establishment->name }}</div>
                @if($establishment->description)
                    <div class="desc">{{ $establishment->description }}</div>
                @endif
            </div>
        </div>

        <div class="cover-footer">
            @if($establishment->address)
                <div class="pill">📍 {{ $establishment->address }}{{ $establishment->city ? ' - '.$establishment->city : '' }}</div>
            @endif
            @if($establishment->phone)
                <div class="pill">📱 {{ $establishment->phone }}</div>
            @endif
            @if($establishment->instagram_url)
                <div class="pill">📸 {{ $establishment->instagram_url }}</div>
            @endif
            @php
                $segments = [];
                if (is_array($establishment->segments)) $segments = $establishment->segments;
                elseif ($establishment->segments) $segments = json_decode($establishment->segments, true) ?: [];
            @endphp
            @foreach($segments as $seg)
                <div class="pill">{{ $seg }}</div>
            @endforeach
        </div>
    </div>
</div>

<!-- CATEGORIAS E ITENS -->
@foreach($grouped as $categoryName => $items)
    <div class="page">
        <div class="category-title">{{ strtoupper($categoryName) }}</div>
        @foreach($items as $item)
            <div class="item avoid-break">
                <div class="item-left">
                    @if($item->image && file_exists(public_path($item->image)))
                        <img src="{{ public_path($item->image) }}" alt="img" class="thumb">
                    @else
                        <div class="thumb empty">SEM IMAGEM</div>
                    @endif
                    <div class="item-info">
                        <div class="item-title">{{ $item->name }}</div>
                        @if($item->description)
                            <div class="item-desc">{{ $item->description }}</div>
                        @endif
                    </div>
                </div>
                <div class="item-price">R$ {{ number_format($item->price, 2, ',', '.') }}</div>
            </div>
        @endforeach
    </div>
@endforeach

</body>
</html>
