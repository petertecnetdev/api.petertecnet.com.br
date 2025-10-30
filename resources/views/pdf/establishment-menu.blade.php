<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>{{ $establishment->name }} - {{ $tipo }}</title>
  <style>
    @page { margin: 0; }
    body { margin: 0; padding: 0; font-family: 'DejaVu Sans', sans-serif; color: #fff; font-size: 13px; }

    .page { position: relative; width: 100%; height: 100vh; overflow: hidden; }

    .cover-bg {
      position: absolute; inset: 0;
      background-size: cover;
      background-position: center;
      filter: blur(25px) brightness(0.4);
      z-index: 0;
    }

    .cover-content {
      position: relative;
      z-index: 1;
      text-align: center;
      padding-top: 160px;
      color: #fff;
    }

    .cover-logo {
      width: 200px;
      height: 200px;
      border-radius: 16px;
      background: rgba(255,255,255,0.1);
      padding: 12px;
      object-fit: contain;
      box-shadow: 0 8px 30px rgba(0,0,0,0.6);
    }

    .title { font-size: 40px; font-weight: bold; margin-top: 25px; text-transform: uppercase; letter-spacing: 2px; }
    .subtitle { font-size: 20px; margin-top: 10px; opacity: 0.9; text-transform: uppercase; }
    .info { font-size: 13px; opacity: 0.9; margin-top: 5px; }

    .content { background: #000; color: #fff; padding: 80px 60px 60px 60px; height: 100vh; box-sizing: border-box; }

    .category {
      background: #000;
      text-align: center;
      font-weight: bold;
      text-transform: uppercase;
      font-size: 18px;
      letter-spacing: 1.5px;
      padding: 16px 0;
      margin-bottom: 20px;
      border-bottom: 1px solid #444;
    }

    .item { margin-bottom: 25px; page-break-inside: avoid; }
    .item-name { font-size: 15px; font-weight: bold; margin-bottom: 6px; color: #fff; }
    .item-desc { font-size: 12px; color: #ccc; line-height: 1.4; margin-bottom: 6px; }
    .item-price { text-align: right; font-size: 14px; font-weight: bold; color: #fff; }

    footer {
      position: fixed;
      bottom: 10px;
      left: 0;
      right: 0;
      text-align: center;
      font-size: 11px;
      color: #aaa;
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
  <div class="cover-bg" style="background-image: url('{{ $background }}');"></div>
  <div class="cover-content">
    @if(file_exists($logoPath))
      <img src="{{ $logoPath }}" class="cover-logo" alt="Logo">
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

<!-- CONTEÚDO -->
@foreach($grouped as $category => $items)
<div class="page">
  <div class="content">
    <div class="category">{{ strtoupper($category) }}</div>
    @foreach($items as $item)
      <div class="item">
        <div class="item-name">{{ $item->name }}</div>
        @if($item->description)
          <div class="item-desc">{{ $item->description }}</div>
        @endif
        <div class="item-price">R$ {{ number_format($item->price, 2, ',', '.') }}</div>
      </div>
    @endforeach
  </div>
</div>
@endforeach

<footer>{{ $establishment->name }} — {{ $tipo }} • Gerado automaticamente por Rasoio</footer>
</body>
</html>
