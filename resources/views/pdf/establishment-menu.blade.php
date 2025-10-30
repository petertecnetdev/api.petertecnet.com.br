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

    .page {
      width: 100%;
      height: 100vh;
      padding: 60px;
      box-sizing: border-box;
      background: #000;
      display: flex;
      flex-direction: row;
      justify-content: space-between;
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

    .menu-title {
      font-size: 70px;
      font-weight: 900;
      color: #fff;
      text-transform: uppercase;
      line-height: 0.9;
    }

    .menu-subtitle {
      color: #ff6a00;
      font-size: 40px;
      font-weight: bold;
      text-transform: uppercase;
      letter-spacing: 2px;
      margin-bottom: 20px;
    }

    .category {
      font-size: 24px;
      font-weight: bold;
      color: #fff;
      text-transform: uppercase;
      margin-top: 30px;
      margin-bottom: 10px;
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

    .footer {
      width: 100%;
      position: absolute;
      bottom: 20px;
      left: 0;
      text-align: center;
      font-size: 12px;
      color: #ff6a00;
    }

    .contact {
      margin-top: 40px;
      font-size: 13px;
      color: #fff;
    }
  </style>
</head>
<body>

<div class="page">
  <div class="left-column">
    <div class="menu-title">MENU</div>
    <div class="menu-subtitle">{{ strtoupper($establishment->category ?? 'Cardápio') }}</div>

    @foreach($grouped as $category => $items)
      <div class="category">{{ strtoupper($category) }}</div>
      @foreach($items as $item)
        <div class="item">
          <span class="item-name">{{ $item->name }}</span>
          <span class="item-price">R$ {{ number_format($item->price, 2, ',', '.') }}</span>
          @if($item->description)
            <div class="item-desc">{{ $item->description }}</div>
          @endif
        </div>
      @endforeach
    @endforeach

    <div class="contact">
      📍 {{ $establishment->address }}  
      @if($establishment->phone)<br>📞 {{ $establishment->phone }}@endif
    </div>
  </div>

  <div class="right-column">
    @foreach($grouped->take(3) as $category => $items)
      @foreach($items->take(2) as $item)
        @if($item->image && file_exists(public_path($item->image)))
          <div class="image-card">
            <img src="{{ public_path($item->image) }}" alt="{{ $item->name }}">
          </div>
          <div class="image-label">{{ strtoupper($item->name) }}</div>
        @endif
      @endforeach
    @endforeach
  </div>
</div>

<div class="footer">
  {{ $establishment->name }} — {{ $tipo }} • Gerado automaticamente por Rasoio
</div>

</body>
</html>
