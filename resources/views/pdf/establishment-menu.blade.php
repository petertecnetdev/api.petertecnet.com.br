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
      height: 100vh;
      width: 100%;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      text-align: center;
      background: linear-gradient(180deg, #000 10%, #111 90%);
      position: relative;
      page-break-after: always;
    }

    .cover::before {
      content: "";
      position: absolute;
      inset: 0;
      background-image: url('{{ $establishment->background && file_exists(public_path($establishment->background)) ? public_path($establishment->background) : $logoPath }}');
      background-size: cover;
      background-position: center;
      filter: blur(14px) brightness(0.4);
      z-index: 0;
    }

    .cover-content {
      z-index: 1;
    }

    .cover-logo {
      width: 190px;
      height: 190px;
      border-radius: 50%;
      border: 5px solid #ffb703;
      object-fit: cover;
      box-shadow: 0 0 25px rgba(255,183,3,0.4);
      margin-bottom: 25px;
    }

    .cover-title {
      font-size: 44px;
      font-weight: 900;
      letter-spacing: 1.5px;
      text-transform: uppercase;
      color: #fff;
    }

    .cover-subtitle {
      color: #ffb703;
      font-size: 20px;
      margin-top: 8px;
      font-weight: 600;
      text-transform: uppercase;
    }

    .cover-info {
      margin-top: 6px;
      color: #ddd;
      font-size: 13px;
    }

    /* ==== CONTEÚDO ==== */
    .category-page {
      background: #000;
      min-height: 100vh;
      padding: 50px 60px 80px 60px;
      box-sizing: border-box;
      page-break-before: always;
    }

    .category-header {
      text-align: center;
      background: #111;
      color: #ffb703;
      font-size: 28px;
      font-weight: bold;
      padding: 14px 0;
      text-transform: uppercase;
      letter-spacing: 1px;
      border-top: 3px solid #ffb703;
      border-bottom: 3px solid #ffb703;
      margin-bottom: 40px;
    }

    .menu-table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0 14px;
    }

    .menu-row {
      background: #111;
      border: 1px solid rgba(255,255,255,0.1);
      border-radius: 10px;
    }

    .menu-img-cell {
      width: 95px;
      padding: 10px;
    }

    .menu-img {
      width: 85px;
      height: 85px;
      border-radius: 8px;
      object-fit: cover;
      border: 2px solid #ffb703;
      background: #222;
      display: block;
    }

    .menu-info-cell {
      padding: 10px 15px;
      vertical-align: top;
      width: 70%;
    }

    .menu-name {
      font-size: 15px;
      font-weight: bold;
      color: #ffb703;
      text-transform: uppercase;
    }

    .menu-desc {
      font-size: 12px;
      color: #ddd;
      margin-top: 4px;
      line-height: 1.4;
    }

    .menu-price-cell {
      text-align: right;
      vertical-align: middle;
      padding-right: 15px;
      font-weight: bold;
      font-size: 14px;
      color: #fff;
    }

    footer {
      position: fixed;
      bottom: 0;
      left: 0;
      right: 0;
      background: #111;
      color: #ffb703;
      font-size: 11px;
      text-align: center;
      padding: 10px 0;
      border-top: 1px solid rgba(255,255,255,0.1);
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
      <div class="cover-info">📍 {{ $establishment->address }}</div>
    @endif
    @if($establishment->phone)
      <div class="cover-info">📞 WhatsApp: {{ $establishment->phone }}</div>
    @endif
    @if($establishment->instagram_url)
      <div class="cover-info">📸 Instagram: {{ $establishment->instagram_url }}</div>
    @endif
  </div>
</div>

<!-- CONTEÚDO -->
@foreach($grouped as $category => $items)
  <div class="category-page">
    <div class="category-header">{{ strtoupper($category) }}</div>

    <table class="menu-table">
      @foreach($items as $item)
        <tr class="menu-row">
          <td class="menu-img-cell">
            @php
              $imgPath = $item->image && file_exists(public_path($item->image))
                  ? public_path($item->image)
                  : public_path('images/default-item.jpg');
            @endphp
            <img src="{{ $imgPath }}" alt="{{ $item->name }}" class="menu-img">
          </td>
          <td class="menu-info-cell">
            <div class="menu-name">{{ $item->name }}</div>
            @if($item->description)
              <div class="menu-desc">{{ $item->description }}</div>
            @endif
          </td>
          <td class="menu-price-cell">
            R$ {{ number_format($item->price, 2, ',', '.') }}
          </td>
        </tr>
      @endforeach
    </table>
  </div>
@endforeach

<footer>
  {{ $establishment->name }} — {{ $tipo }} • Gerado automaticamente por Rasoio
</footer>

</body>
</html>
