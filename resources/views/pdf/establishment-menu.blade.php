<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>{{ $establishment->name }} - {{ $tipo }}</title>
  <style>
    @page { margin: 0; }
    body {
      font-family: 'DejaVu Sans', sans-serif;
      margin: 0;
      padding: 0;
      color: #fff;
    }

    /* === CAPA === */
    .cover {
      position: relative;
      height: 100vh;
      overflow: hidden;
      color: #fff;
      text-align: center;
    }

    .cover::before {
      content: "";
      position: absolute;
      inset: 0;
      background: url('{{ public_path($establishment->background ?? $logoPath) }}') center/cover no-repeat;
      filter: blur(20px) brightness(0.4);
      z-index: 0;
    }

    .cover-content {
      position: relative;
      z-index: 1;
      top: 35%;
      transform: translateY(-35%);
    }

    .cover-logo {
      width: 180px;
      height: 180px;
      object-fit: contain;
      border-radius: 15px;
      background: rgba(255,255,255,0.1);
      padding: 15px;
      margin-bottom: 20px;
    }

    .cover-title {
      font-size: 40px;
      font-weight: bold;
      letter-spacing: 2px;
      text-transform: uppercase;
    }

    .cover-subtitle {
      font-size: 20px;
      margin-top: 8px;
      opacity: 0.85;
      text-transform: uppercase;
    }

    .cover-info {
      font-size: 13px;
      margin-top: 6px;
      opacity: 0.85;
    }

    /* === PÁGINAS DE ITENS === */
    .page {
      background: #000;
      min-height: 100vh;
      padding: 60px 60px 80px;
      box-sizing: border-box;
      page-break-before: always;
    }

    .category-bar {
      background: #111;
      color: #fff;
      text-align: center;
      text-transform: uppercase;
      font-size: 22px;
      font-weight: bold;
      letter-spacing: 1.5px;
      padding: 16px 0;
      border-bottom: 2px solid #e0b100;
      margin-bottom: 25px;
      box-shadow: 0 3px 10px rgba(255,255,255,0.1);
    }

    .item {
      display: flex;
      align-items: flex-start;
      margin-bottom: 22px;
      page-break-inside: avoid;
      border-bottom: 1px solid rgba(255,255,255,0.1);
      padding-bottom: 10px;
    }

    .item img {
      width: 80px;
      height: 80px;
      border-radius: 8px;
      object-fit: cover;
      margin-right: 15px;
      box-shadow: 0 0 10px rgba(255,255,255,0.08);
    }

    .item-info {
      flex: 1;
    }

    .item-name {
      font-size: 15px;
      font-weight: bold;
      color: #fff;
      margin-bottom: 3px;
    }

    .item-desc {
      font-size: 12px;
      color: #bbb;
      line-height: 1.4;
    }

    .item-price {
      font-size: 14px;
      font-weight: bold;
      color: #ffcc00;
      white-space: nowrap;
      margin-left: 10px;
    }

    footer {
      position: fixed;
      bottom: 0;
      left: 0;
      right: 0;
      background: #111;
      text-align: center;
      color: #aaa;
      font-size: 11px;
      padding: 6px 0;
    }
  </style>
</head>
<body>

<!-- CAPA -->
<div class="cover">
  <div class="cover-content">
    @if(file_exists($logoPath))
      <img src="{{ $logoPath }}" alt="Logo" class="cover-logo">
    @endif
    <div class="cover-title">{{ strtoupper($establishment->name) }}</div>
    <div class="cover-subtitle">{{ strtoupper($tipo) }}</div>
    @if($establishment->address)
      <div class="cover-info">{{ $establishment->address }}</div>
    @endif
    @if($establishment->city)
      <div class="cover-info">{{ $establishment->city }}</div>
    @endif
    @if($establishment->phone)
      <div class="cover-info">WhatsApp: {{ $establishment->phone }}</div>
    @endif
    @if($establishment->instagram_url)
      <div class="cover-info">Instagram: {{ $establishment->instagram_url }}</div>
    @endif
  </div>
</div>

<!-- CONTEÚDO -->
@foreach($grouped as $category => $items)
<div class="page">
  <div class="category-bar">{{ strtoupper($category) }}</div>

  @foreach($items as $item)
  <div class="item">
    @if($item->image && file_exists(public_path($item->image)))
      <img src="{{ public_path($item->image) }}" alt="{{ $item->name }}">
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
@endforeach

<footer>
  {{ $establishment->name }} — {{ $tipo }} • Gerado automaticamente por Rasoio
</footer>

</body>
</html>
