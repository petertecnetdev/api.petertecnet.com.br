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
      background: linear-gradient(180deg, #000 10%, #111 90%);
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      text-align: center;
      position: relative;
      page-break-after: always;
    }

    .cover-logo {
      width: 200px;
      height: 200px;
      border-radius: 50%;
      object-fit: cover;
      border: 6px solid #ff6a00;
      box-shadow: 0 0 30px rgba(255,106,0,0.4);
      margin-bottom: 25px;
    }

    .cover-title {
      font-size: 46px;
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
      margin-top: 6px;
    }

    /* ===== CONTEÚDO ===== */
    .category-page {
      width: 100%;
      min-height: 100vh;
      background: #000;
      box-sizing: border-box;
      padding: 70px 50px 80px 50px;
      position: relative;
      page-break-before: always;
    }

    .category-header {
      background: #ff6a00;
      color: #fff;
      text-align: center;
      font-size: 28px;
      font-weight: 900;
      text-transform: uppercase;
      padding: 15px 0;
      letter-spacing: 1.5px;
      border-radius: 0;
      margin-bottom: 40px;
    }

    .menu-grid {
      display: flex;
      flex-wrap: wrap;
      justify-content: space-between;
    }

    .menu-item {
      width: 48%;
      background: rgba(255,255,255,0.05);
      border: 1px solid rgba(255,255,255,0.1);
      border-radius: 10px;
      padding: 15px;
      margin-bottom: 25px;
      display: flex;
      flex-direction: row;
      align-items: center;
    }

    .item-image {
      width: 85px;
      height: 85px;
      border-radius: 10px;
      object-fit: cover;
      margin-right: 15px;
      border: 2px solid #ff6a00;
      background: #111;
    }

    .item-details {
      flex: 1;
    }

    .item-name {
      font-size: 15px;
      font-weight: bold;
      color: #ff6a00;
      text-transform: uppercase;
      margin-bottom: 4px;
    }

    .item-desc {
      font-size: 12px;
      color: #ddd;
      line-height: 1.4;
      margin-bottom: 6px;
    }

    .item-price {
      font-weight: bold;
      color: #fff;
      background: #ff6a00;
      border-radius: 20px;
      padding: 4px 10px;
      font-size: 13px;
      display: inline-block;
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
  <div class="category-page">
    <div class="category-header">{{ strtoupper($category) }}</div>

    <div class="menu-grid">
      @foreach($items as $item)
        <div class="menu-item">
          @if($item->image && file_exists(public_path($item->image)))
            <img src="{{ public_path($item->image) }}" alt="{{ $item->name }}" class="item-image">
          @else
            <img src="{{ public_path('images/default-item.jpg') }}" alt="Item" class="item-image">
          @endif

          <div class="item-details">
            <div class="item-name">{{ $item->name }}</div>
            @if($item->description)
              <div class="item-desc">{{ $item->description }}</div>
            @endif
            <div class="item-price">R$ {{ number_format($item->price, 2, ',', '.') }}</div>
          </div>
        </div>
      @endforeach
    </div>
  </div>
@endforeach

<footer>
  {{ $establishment->name }} — {{ $tipo }} 
</footer>

</body>
</html>
