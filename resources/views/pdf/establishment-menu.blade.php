<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{{ $establishment->name }} - {{ $tipo }}</title>
    <style>
        @page { margin: 0; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #fff; font-size: 13px; margin: 0; padding: 0; }

        .page {
            position: relative;
            min-height: 100vh;
            padding: 80px 60px 60px 60px;
            box-sizing: border-box;
            background-size: cover;
            background-position: center;
        }

        .blur-bg {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background-size: cover;
            background-position: center;
            filter: blur(20px) brightness(0.5);
            z-index: 0;
        }

        .cover-content, .content {
            position: relative;
            z-index: 1;
        }

        .cover-content {
            text-align: center;
            color: #fff;
            padding-top: 160px;
        }

        .cover-logo {
            width: 200px;
            height: 200px;
            border-radius: 20px;
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px);
            object-fit: contain;
            padding: 12px;
        }

        .title {
            font-size: 42px;
            font-weight: bold;
            margin-top: 30px;
            letter-spacing: 1px;
            text-shadow: 0 2px 8px rgba(0,0,0,0.5);
        }

        .subtitle {
            font-size: 22px;
            opacity: 0.9;
            margin-top: 12px;
            text-transform: uppercase;
        }

        .info { font-size: 14px; margin-top: 8px; opacity: 0.9; }

        .category-title {
            text-align: center;
            font-size: 26px;
            font-weight: bold;
            color: #fff;
            margin-top: 40px;
            margin-bottom: 25px;
            padding: 10px 20px;
            border-radius: 6px;
            display: inline-block;
            background: rgba(0,0,0,0.85);
            text-shadow: 0 2px 6px rgba(0,0,0,0.6);
        }

        .item {
            display: flex;
            flex-direction: row;
            width: 100%;
            margin-top: 14px;
            padding: 14px 16px;
            align-items: flex-start;
            background: rgba(255,255,255,0.07);
            border-radius: 8px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .item img {
            width: 80px;
            height: 80px;
            border-radius: 8px;
            object-fit: cover;
            margin-right: 14px;
        }

        .item-info { flex: 1; color: #fff; }
        .item-name { font-size: 17px; font-weight: bold; color: #fff; }
        .item-desc { font-size: 13px; color: #ddd; margin-top: 3px; }
        .item-price { font-weight: bold; font-size: 16px; color: #fff; text-align: right; white-space: nowrap; }

        footer {
            position: fixed;
            bottom: 15px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 11px;
            color: #eee;
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
<div class="page" style="background-image: url('{{ $background }}');">
    <div class="blur-bg" style="background-image: url('{{ $background }}'); filter: blur(22px) brightness(0.4);"></div>
    <div class="cover-content">
        @if(file_exists($logoPath))
            <img src="{{ $logoPath }}" alt="Logo" class="cover-logo">
        @endif
        <div class="title">{{ $establishment->name }}</div>
        <div class="subtitle">{{ $tipo }}</div>
        @if($establishment->address)
            <div class="info">{{ $establishment->address }}</div>
        @endif
        @if($establishment->phone)
            <div class="info">WhatsApp: {{ $establishment->phone }}</div>
        @endif
        @if($establishment->instagram_url)
            <div class="info">Instagram: {{ $establishment->instagram_url }}</div>
        @endif
    </div>
</div>

<!-- CONTEÚDO -->
@foreach($grouped as $category => $items)
<div class="page" style="background-image: url('{{ $background }}');">
    <div class="blur-bg" style="background-image: url('{{ $background }}'); filter: blur(25px) brightness(0.3);"></div>
    <div class="content" style="padding-top: 40px;">
        <div class="category-title">{{ $category }}</div>
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
                <div class="item-price">R$ {{ number_format($item->price, 2, ',', '.') }}</div>
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
