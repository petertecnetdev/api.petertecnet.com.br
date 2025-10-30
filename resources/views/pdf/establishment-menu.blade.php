<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{{ $establishment->name }} - {{ $tipo }}</title>
    <style>
        @page { margin: 0; }
        body {
            margin: 0;
            padding: 0;
            color: #fff;
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 13px;
        }

        .page {
            position: relative;
            width: 100%;
            height: 100vh;
            overflow: hidden;
        }

        .background {
            position: absolute;
            top: 0; left: 0;
            width: 100%;
            height: 100%;
            background-position: center;
            background-size: cover;
            background-repeat: no-repeat;
            filter: blur(35px) brightness(0.35);
            z-index: 0;
        }

        .content {
            position: relative;
            z-index: 2;
            padding: 70px 60px;
            box-sizing: border-box;
        }

        /* ===== CAPA ===== */
        .cover-content {
            text-align: center;
            padding-top: 160px;
        }
        .cover-logo {
            width: 220px;
            height: 220px;
            border-radius: 20px;
            background: rgba(255,255,255,0.12);
            object-fit: contain;
            padding: 14px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.5);
        }
        .title {
            font-size: 44px;
            font-weight: bold;
            margin-top: 30px;
            letter-spacing: 2px;
            text-shadow: 0 3px 10px rgba(0,0,0,0.6);
        }
        .subtitle {
            font-size: 20px;
            margin-top: 12px;
            text-transform: uppercase;
            opacity: 0.95;
        }
        .info { font-size: 14px; margin-top: 6px; opacity: 0.9; }

        /* ===== CATEGORIA ===== */
        .category-banner {
            background: rgba(0,0,0,0.9);
            text-align: center;
            color: #fff;
            text-transform: uppercase;
            font-weight: bold;
            font-size: 20px;
            padding: 15px 0;
            letter-spacing: 2px;
            width: 100%;
            position: relative;
            z-index: 2;
            box-shadow: 0 6px 25px rgba(0,0,0,0.5);
        }

        /* ===== ITENS ===== */
        .item {
            display: flex;
            flex-direction: row;
            align-items: flex-start;
            background: rgba(255,255,255,0.08);
            border-radius: 8px;
            margin-top: 14px;
            padding: 14px 16px;
            border: 1px solid rgba(255,255,255,0.05);
        }
        .item img {
            width: 75px;
            height: 75px;
            border-radius: 8px;
            object-fit: cover;
            margin-right: 12px;
        }
        .item-info { flex: 1; }
        .item-name { font-weight: bold; font-size: 15px; color: #fff; }
        .item-desc { font-size: 12px; color: #ddd; margin-top: 2px; }
        .item-price { font-weight: bold; font-size: 15px; color: #fff; text-align: right; white-space: nowrap; }

        footer {
            position: fixed;
            bottom: 10px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 11px;
            color: #ccc;
        }
    </style>
</head>
<body>

@php
    $background = $establishment->background && file_exists(public_path($establishment->background))
        ? public_path($establishment->background)
        : $logoPath;
@endphp

<!-- CAPA -->
<div class="page">
    <div class="background" style="background-image: url('{{ $background }}'); filter: blur(40px) brightness(0.3);"></div>
    <div class="content cover-content">
        @if(file_exists($logoPath))
            <img src="{{ $logoPath }}" alt="Logo" class="cover-logo">
        @endif
        <div class="title">{{ $establishment->name }}</div>
        <div class="subtitle">{{ $tipo }}</div>
        @if($establishment->address)
            <div class="info">{{ $establishment->address }}</div>
        @endif
        @if($establishment->city)
            <div class="info">{{ $establishment->city }}</div>
        @endif
        @if($establishment->phone)
            <div class="info">WhatsApp: {{ $establishment->phone }}</div>
        @endif
        @if($establishment->instagram_url)
            <div class="info">Instagram: {{ $establishment->instagram_url }}</div>
        @endif
    </div>
</div>

<!-- PÁGINAS DE CATEGORIA -->
@foreach($grouped as $category => $items)
<div class="page">
    <div class="background" style="background-image: url('{{ $background }}'); filter: blur(45px) brightness(0.28);"></div>
    <div class="category-banner">{{ strtoupper($category) }}</div>
    <div class="content">
        @foreach($items as $item)
            <div class="item">
                @if($item->image && file_exists(public_path($item->image)))
                    <img src="{{ public_path($item->image) }}" alt="Item">
                @endif
                <div class="item-info">
                    <div class="item-name">{{ $item->name }}</div>
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
</div>
@endforeach

<footer>
    {{ $establishment->name }} — {{ $tipo }} • Gerado automaticamente por Rasoio
</footer>

</body>
</html>
