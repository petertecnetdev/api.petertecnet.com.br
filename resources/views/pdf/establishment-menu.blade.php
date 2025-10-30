<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{{ $establishment->name }} - {{ $tipo }}</title>
    <style>
        @page { margin: 0; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #222; font-size: 13px; margin: 0; padding: 0; }

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
            filter: blur(14px) brightness(0.5);
            z-index: 0;
        }

        .cover-content, .content {
            position: relative;
            z-index: 1;
        }

        .cover-content {
            text-align: center;
            color: #fff;
            padding-top: 140px;
        }

        .cover-logo {
            width: 180px;
            height: 180px;
            border-radius: 15px;
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px);
            object-fit: contain;
            padding: 10px;
        }

        .title {
            font-size: 38px;
            font-weight: bold;
            margin-top: 25px;
            letter-spacing: 1px;
        }

        .subtitle {
            font-size: 20px;
            opacity: 0.9;
            margin-top: 10px;
            text-transform: uppercase;
        }

        .info { font-size: 13px; margin-top: 8px; opacity: 0.9; }

        .section {
            page-break-before: always;
            position: relative;
            min-height: 100vh;
        }

        .category {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 10px;
            background: rgba(0,0,0,0.8);
            color: #fff;
            padding: 8px 12px;
            border-radius: 4px;
            display: inline-block;
        }

        .items-grid {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
        }

        .item {
            width: 48%;
            display: flex;
            margin-top: 15px;
            border-bottom: 1px solid rgba(255,255,255,0.2);
            padding-bottom: 8px;
            align-items: center;
            background: rgba(255,255,255,0.08);
            border-radius: 8px;
            padding: 10px;
        }

        .item img {
            width: 70px;
            height: 70px;
            border-radius: 8px;
            object-fit: cover;
            margin-right: 10px;
        }

        .item-info { flex: 1; color: #fff; }
        .item-name { font-size: 15px; font-weight: bold; color: #fff; }
        .item-desc { font-size: 12px; color: #ddd; margin-top: 2px; }
        .item-price { font-weight: bold; font-size: 14px; color: #fff; text-align: right; }

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
    <div class="blur-bg" style="background-image: url('{{ $background }}'); filter: blur(20px) brightness(0.4);"></div>
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
    <div class="content">
        <div class="category">{{ $category }}</div>
        <div class="items-grid">
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
</div>
@endforeach

<footer>
    {{ $establishment->name }} — {{ $tipo }} • Gerado automaticamente por Rasoio
</footer>

</body>
</html>
