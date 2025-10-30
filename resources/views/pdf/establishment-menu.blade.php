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
      background: #000;
      color: #fff;
    }

    /* ===== CAPA ===== */
    .cover {
      height: 100vh;
      width: 100%;
      background: linear-gradient(180deg, #000 20%, #111 100%);
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      text-align: center;
      position: relative;
      page-break-after: always;
    }

    .cover-logo {
      width: 180px;
      height: 180px;
      border-radius: 50%;
      object-fit: cover;
      border: 5px solid #ff6a00;
      margin-bottom: 25px;
      box-shadow: 0 0 25px rgba(255,106,0,0.4);
    }

    .cover-title {
      font-size: 42px;
      font-weight: 900;
      color: #fff;
      text-transform: uppercase;
      letter-spacing: 2px;
    }

    .cover-subtitle {
      font-size: 22px;
      color: #ff6a00;
      font-weight: bold;
      margin-top: 10px;
      text-transform: uppercase;
    }

    .cover-info {
      color: #ccc;
      font-size: 14px;
      margin-top: 8px;
    }

    /* ===== PÁGINAS DE CARDÁPIO ===== */
    .page {
      width: 100%;
      min-height: 100vh;
      padding: 60px;
      box-sizing: border-box;
      background: #000;
      display: flex;
      flex-direction: row;
      justify-content: space-between;
      position: relative;
      page-break-before: always;
    }

    .left-column {
      width: 55%;
    }

    .right-column {
      width: 40%;
      display: flex;
      flex-direction: column;
      justify-content: flex-start;
      align-items: center;
    }

    .menu-subtitle {
      color: #ff6a00;
      font-size: 36px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 1.5px;
      border-bottom: 3px solid #ff6a00;
      padding-bottom: 6px;
      margin-bottom: 20px;
    }

    .category {
      font-size: 22px;
      font-weight: bold;
      color: #fff;
      text-transform: uppercase;
      margin-top: 30px;
      margin-bottom: 12px;
      border-left: 5px solid #ff6a00;
      padding-left: 10px;
    }

    .item {
      margin-bottom: 14px;
    }

    .item-name {
      font-size: 16px;
      font-weight: bold;
      color: #ff6a00;
      text-transform: uppercase;
    }

    .item-desc {
      font-size: 12px;
      color: #ccc;
      margin-top: 2px;
      line-height: 1.3;
    }

    .item-price {
      float: right;
      background: #ff6a00;
      color: #fff;
      font-weight: bold;
      font-size: 13px;
      padding: 3px 8px;
      border-radius: 20px;
      margin-top: -20px;
    }

    .image-card {
      width: 280px;
      height: 280px;
      border-radius: 50%;
      border: 5px solid #ff6a00;
      overflow: hidden;
      margin-bottom: 20px;
      box-shadow: 0 0 25px rgba(255,106,0,0.3);
    }

    .image-card img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .image-label {
      background: #ff6a00;
      color: #fff;
      font-weight: bold;
      text-transform: uppercase;
      padding: 6px 15px;
      border-radius: 20px;
      margin-top: -10px;
    }

    footer {
      position: fixed;
      bottom: 0;
      left: 0;
      right: 0;
      background: #111;
      color: #ff6a00;
      text-align: center;
      font-size: 11px;
      padding: 10px 0;
    }

  </style>
</head>
<body>

<!-- CAPA -->
<div class="cover">
  @if(file_exists($logoPath))
    <img src="{{ $logoPath }}" alt="Logo" class="cover-logo">
  @endif
  <div class="cover-title">{{ strtoupper($establishment->name) }}</div>
  <div class="cover-subtitle">{{ strtoupper($tipo) }}</div>
  @if($establishment->address)
    <div class="cover-info">📍 {{ $establishment->address }}</div>
  @endif
  @if($establishment->phone)
    <div class="cover-info">📞 WhatsApp: {{ $establishment->phone }}</div>
  @endif
  @if($establishment->instagram_url)
    <div class="cover-info">📸 Instagram: {{ $establishment->instagram_url }}</div>
  @endif
</div>

<!-- PÁGINAS DE CATEGORIAS -->
@foreach($grouped as $category => $items)
<div class="page">
  <div class="left-column">
    <div class="menu-subtitle">{{ strtoupper($category) }}</div>

    @foreach($items as $item)
      <div class="item">
        <span class="item-name">{{ $item->name }}</span>
        <span class="item-price">R$ {{ number_format($item->price, 2, ',', '.') }}</span>
        @if($item->description)
          <div class="item-desc">{{ $item->description }}</div>
        @endif
      </div>
    @endforeach
  </div>

  <div class="right-column">
    @foreach($items->take(2) as $item)
      @if($item->image && file_exists(public_path($item->image)))
        <div class="image-card">
          <img src="{{ public_path($item->image) }}" alt="{{ $item->name }}">
        </div>
        <div class="image-label">{{ strtoupper($item->name) }}</div>
      @endif
    @endforeach
  </div>
</div>
@endforeach

<footer>
  {{ $establishment->name }} — {{ $tipo }} • Gerado automaticamente por Rasoio
</footer>

</body>
</html>
