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
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      text-align: center;
      position: relative;
      overflow: hidden;
    }

    .cover::before {
      content: "";
      position: absolute;
      inset: 0;
      background-image: url('{{ $establishment->background && file_exists(public_path($establishment->background)) ? public_path($establishment->background) : $logoPath }}');
      background-size: cover;
      background-position: center;
      filter: blur(16px) brightness(0.35);
      z-index: 0;
    }

    .cover-content {
      position: relative;
      z-index: 1;
      padding: 20px;
    }

    .cover-logo {
      width: 190px;
      height: 190px;
      border-radius: 50%;
      border: 6px solid #ffb703;
      object-fit: cover;
      box-shadow: 0 0 25px rgba(255,183,3,0.5);
      margin-bottom: 25px;
    }

    .cover-title {
      font-size: 44px;
      font-weight: 900;
      text-transform: uppercase;
      letter-spacing: 1.5px;
      color: #fff;
    }

    .cover-subtitle {
      font-size: 20px;
      color: #ffb703;
      font-weight: 600;
      text-transform: uppercase;
      margin-top: 8px;
    }

    .cover-info {
      font-size: 13px;
      color: #ddd;
      margin-top: 6px;
    }

    /* ===== CONTEÚDO ===== */
    .page {
      background: #000;
      color: #fff;
      min-height: 100vh;
      padding: 40px 60px 60px 60px;
      box-sizing: border-box;
    }

    .category-header {
      width: 100%;
      text-align: center;
      background: linear-gradient(90deg, #ffb703, #ff9500);
      color: #000;
      font-weight: 900;
      font-size: 26px;
      padding: 12px 0;
      text-transform: uppercase;
      letter-spacing: 1px;
      border-radius: 3px;
      margin: 0 auto 30px auto;
    }

    .category-block {
      page-break-inside: avoid;
      margin-top: 40px;
    }

    table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0 12px;
    }

    tr {
      background: #111;
      border-radius: 10px;
      overflow: hidden;
    }

    td {
      vertical-align: top;
      padding: 10px 14px;
    }

    .img-col {
      width: 90px;
    }

    .item-img {
      width: 80px;
      height: 80px;
      border-radius: 8px;
      object-fit: cover;
      border: 2px solid #ffb703;
      display: block;
      background: #222;
    }

    .item-name {
      font-size: 15px;
      font-weight: bold;
      color: #ffb703;
      text-transform: uppercase;
    }

    .item-desc {
      font-size: 12px;
      color: #ccc;
      line-height: 1.4;
      margin-top: 4px;
    }

    .item-price {
      text-align: right;
      font-weight: bold;
      color: #fff;
      font-size: 14px;
      white-space: nowrap;
      vertical-align: middle;
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
      padding: 8px 0;
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

<!-- CATEGORIAS -->
@php $first = true; @endphp
@foreach($grouped as $category => $items)
  <div class="page" style="{{ $first ? 'page-break-before: always;' : '' }}">
    <div class="category-header">{{ strtoupper($category) }}</div>
    <div class="category-block">
      <table>
        @foreach($items as $item)
          <tr>
            <td class="img-col">
              @php
                $img = $item->image && file_exists(public_path($item->image))
                    ? public_path($item->image)
                    : public_path('images/default-item.jpg');
              @endphp
              <img src="{{ $img }}" alt="{{ $item->name }}" class="item-img">
            </td>
            <td>
              <div class="item-name">{{ $item->name }}</div>
              @if($item->description)
                <div class="item-desc">{{ $item->description }}</div>
              @endif
            </td>
            <td class="item-price">
              R$ {{ number_format($item->price, 2, ',', '.') }}
            </td>
          </tr>
        @endforeach
      </table>
    </div>
  </div>
  @php $first = false; @endphp
@endforeach

<footer>
  {{ $establishment->name }} — {{ $tipo }}
</footer>

</body>
</html>
