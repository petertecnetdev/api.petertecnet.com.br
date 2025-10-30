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
      background: #000;
    }

    /* ==== CAPA ==== */
    .cover {
      position: relative;
      height: 100vh;
      width: 100%;
      overflow: hidden;
      text-align: center;
      color: #fff;
    }

    .cover::before {
      content: "";
      position: absolute;
      inset: 0;
      background: url('{{ public_path($establishment->background ?? $logoPath) }}') center/cover no-repeat;
      filter: blur(18px) brightness(0.45);
      z-index: 0;
    }

    .cover-content {
      position: relative;
      z-index: 1;
      top: 50%;
      transform: translateY(-50%);
    }

    .cover-logo {
      width: 200px;
      height: 200px;
      object-fit: contain;
      border-radius: 16px;
      background: rgba(255,255,255,0.08);
      padding: 15px;
      margin-bottom: 20px;
    }

    .cover-title {
      font-size: 42px;
      font-weight: 900;
      text-transform: uppercase;
      letter-spacing: 2px;
      color: #f8f8f8;
    }

    .cover-subtitle {
      font-size: 20px;
      margin-top: 10px;
      color: #d4af37;
      text-transform: uppercase;
    }

    .cover-info {
      font-size: 13px;
      margin-top: 8px;
      color: #ddd;
    }

    /* ==== CONTEÚDO ==== */
    .page {
      page-break-before: always;
      background: #000;
      padding: 70px 60px 90px 60px;
      box-sizing: border-box;
      min-height: 100vh;
    }

    .category-header {
      width: 100%;
      background: #111;
      color: #d4af37;
      text-transform: uppercase;
      text-align: center;
      font-size: 22px;
      font-weight: bold;
      padding: 18px 0;
      border-bottom: 2px solid #d4af37;
      margin-bottom: 30px;
      letter-spacing: 1px;
      box-shadow: 0 4px 15px rgba(212,175,55,0.25);
    }

    .item {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      margin-bottom: 22px;
      padding-bottom: 12px;
      border-bottom: 1px solid rgba(255,255,255,0.08);
    }

    .item-left {
      display: flex;
      align-items: flex-start;
      flex: 1;
    }

    .item img {
      width: 80px;
      height: 80px;
      border-radius: 8px;
      object-fit: cover;
      margin-right: 15px;
      box-shadow: 0 0 10px rgba(212,175,55,0.15);
    }

    .item-info {
      flex: 1;
      color: #fff;
    }

    .item-name {
      font-size: 16px;
      font-weight: bold;
      color: #fff;
      margin-bottom: 4px;
    }

    .item-desc {
      font-size: 12px;
      color: #ccc;
      line-height: 1.4;
    }

    .item-price {
      font-size: 15px;
      font-weight: bold;
      color: #d4af37;
      white-space: nowrap;
      margin-left: 20px;
    }

    footer {
      position: fixed;
      bottom: 0;
      left: 0;
      right: 0;
      text-align: center;
      background: #111;
      color: #999;
      font-size: 11px;
      padding: 8px 0;
      border-top: 1px solid #333;
    }
  </style>
</head>
<body>

<!-- CAPA -->
<div class="cover">
  <div class="cover-content">
    @if(file_exists($logoPath))
      <img src="{{ $logoPath }}" class="cover-logo" alt="Logo">
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

<!-- PÁGINAS DE CATEGORIAS -->
@foreach($grouped as $category => $items)
<div class="page">
  <div class="category-header">{{ strtoupper($category) }}</div>

  @foreach($items as $item)
    <div class="item">
      <div class="item-left">
        @if($item->image && file_exists(public_path($item->image)))
          <img src="{{ public_path($item->image) }}" alt="{{ $item->name }}">
        @endif
        <div class="item-info">
          <div class="item-name">{{ $item->name }}</div>
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

<footer>
  {{ $establishment->name }} — {{ $tipo }} • Gerado automaticamente por Rasoio
</footer>

</body>
</html>
