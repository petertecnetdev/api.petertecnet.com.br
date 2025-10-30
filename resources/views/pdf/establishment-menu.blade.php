<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{{ $establishment->name }} - {{ $tipo }}</title>
    <style>
        @page {
            margin: 0;
        }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            color: #fff;
            font-size: 13px;
            margin: 0;
            padding: 0;
        }
        .page {
            position: relative;
            width: 100%;
            min-height: 100vh;
            height: 100vh;
            box-sizing: border-box;
        }
        .page-cover {
            padding: 120px 60px 60px 60px;
        }
        .page-content {
            padding: 80px 40px 60px 40px;
        }
        .bg-layer {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100vh;
            background-size: cover;
            background-position: center center;
            background-repeat: no-repeat;
            filter: blur(32px) brightness(0.35);
            z-index: 0;
        }
        .bg-layer-page {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-size: cover;
            background-position: center center;
            background-repeat: no-repeat;
            filter: blur(30px) brightness(0.32);
            z-index: 0;
        }
        .cover-content,
        .category-content {
            position: relative;
            z-index: 2;
        }
        .cover-content {
            text-align: center;
            color: #fff;
        }
        .cover-logo {
            width: 200px;
            height: 200px;
            border-radius: 20px;
            background: rgba(255,255,255,0.1);
            object-fit: contain;
            padding: 14px;
            margin: 0 auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.4);
        }
        .title {
            font-size: 40px;
            font-weight: bold;
            margin-top: 28px;
            letter-spacing: 1.4px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.75);
        }
        .subtitle {
            font-size: 22px;
            opacity: 0.95;
            margin-top: 12px;
            text-transform: uppercase;
            font-weight: 600;
        }
        .info {
            font-size: 14px;
            margin-top: 7px;
            opacity: 0.9;
        }
        .category-banner {
            position: relative;
            z-index: 2;
            width: 100%;
            background: rgba(0,0,0,0.9);
            padding: 14px 0;
            text-align: center;
            text-transform: uppercase;
            font-weight: bold;
            font-size: 18px;
            letter-spacing: 1.8px;
            box-shadow: 0 10px 35px rgba(0,0,0,0.4);
        }
        .item {
            display: flex;
            flex-direction: row;
            width: 100%;
            margin-top: 12px;
            background: rgba(0,0,0,0.45);
            border: 1px solid rgba(255,255,255,0.04);
            border-radius: 8px;
            padding: 12px 16px;
            box-sizing: border-box;
            align-items: flex-start;
            gap: 12px;
        }
        .item-thumb {
            width: 78px;
            height: 78px;
            border-radius: 6px;
            background: rgba(255,255,255,0.1);
            object-fit: cover;
            flex: 0 0 78px;
        }
        .item-info {
            flex: 1;
            min-width: 0;
        }
        .item-name {
            font-size: 15px;
            font-weight: bold;
            color: #fff;
        }
        .item-desc {
            font-size: 12px;
            color: #ddd;
            margin-top: 3px;
        }
        .item-price {
            font-size: 15px;
            font-weight: bold;
            color: #fff;
            white-space: nowrap;
            margin-left: 8px;
        }
        footer {
            position: fixed;
            bottom: 10px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 10px;
            color: #ddd;
            z-index: 5;
        }
        .items-wrapper {
            margin-top: 18px;
        }
    </style>
</head>
<body>
@php
    $background = $establishment->background && file_exists(public_path($establishment->background))
        ? public_path($establishment->background)
        : $logoPath;
@endphp

<div class="page page-cover">
    <div class="bg-layer" style="background-image: url('{{ $background }}');"></div>
    <div class="cover-content">
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

@foreach($grouped as $category => $items)
    <div class="page page-content">
        <div class="bg-layer-page" style="background-image: url('{{ $background }}');"></div>
        <div class="category-content">
            <div class="category-banner">{{ strtoupper($category) }}</div>
            <div class="items-wrapper">
                @foreach($items as $item)
                    <div class="item">
                        @if($item->image && file_exists(public_path($item->image)))
                            <img src="{{ public_path($item->image) }}" alt="Item" class="item-thumb">
                        @else
                            <div class="item-thumb" style="display:flex;align-items:center;justify-content:center;font-size:10px;color:rgba(255,255,255,0.4);">
                                {{ strtoupper(substr($item->name,0,2)) }}
                            </div>
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
    </div>
@endforeach

<footer>
    {{ $establishment->name }} — {{ $tipo }} • Gerado automaticamente por Rasoio
</footer>
</body>
</html>
