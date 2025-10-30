<!-- resources/views/pdf/establishment-menu.blade.php -->
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>{{ $establishment->name }} - {{ $tipo }}</title>
<style>
@page { margin: 0; }
* { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'DejaVu Sans', sans-serif;
    color: #fff;
    background: #000;
    line-height: 1.4;
}

/* ===== CAPA ===== */
.cover {
    width: 100%;
    height: 100vh;
    position: relative;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    text-align: center;
    background-color: #000;
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
    overflow: hidden;
}
.cover::before {
    content: "";
    position: absolute;
    inset: 0;
    background: inherit;
    background-size: cover;
    background-position: center;
    filter: blur(60px) brightness(0.6);
    transform: scale(1.1);
    z-index: 0;
}
.cover-overlay {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,0.55);
    z-index: 1;
}
.cover-content {
    position: relative;
    z-index: 2;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    height: 100vh;
    text-align: center;
}
.cover-logo {
    width: 240px;
    height: 240px;
    border-radius: 999px;
    object-fit: cover;
    border: 4px solid rgba(255,255,255,0.25);
    margin-bottom: 28px;
    box-shadow: 0 0 35px rgba(255,255,255,0.2);
}
.cover-title {
    font-size: 42px;
    font-weight: 800;
    background: rgba(0,0,0,0.85);
    padding: 14px 30px;
    border-radius: 10px;
    margin-bottom: 12px;
}
.cover-info {
    font-size: 16px;
    background: rgba(0,0,0,0.85);
    padding: 10px 22px;
    border-radius: 10px;
    margin-top: 10px;
}

/* ===== ITENS ===== */
.page {
    padding: 60px;
    background: #0b0b0b;
}
.category {
    page-break-after: always;
    margin-bottom: 40px;
}
.category:last-child {
    page-break-after: auto;
}
.category-title {
    text-transform: uppercase;
    text-align: center;
    font-weight: 700;
    letter-spacing: 2px;
    font-size: 16px;
    background: linear-gradient(90deg, #1b1b1b, #111);
    padding: 10px;
    border-radius: 8px;
    border: 1px solid rgba(255,255,255,0.1);
    margin-bottom: 20px;
}

/* ===== ITEM ===== */
.item-wrapper {
    display: block;
    margin-bottom: 20px;
    page-break-inside: avoid;
}
.item {
    width: 100%;
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 10px;
    background: rgba(255,255,255,0.03);
    overflow: hidden;
}
.item-table {
    width: 100%;
    border-collapse: collapse;
}
.item-table td {
    vertical-align: top;
}
.thumb {
    width: 120px;
    height: 110px;
    object-fit: cover;
    background: rgba(255,255,255,0.05);
    border-right: 2px solid rgba(255,255,255,0.1);
}
.item-body {
    padding: 14px 20px;
}
.item-title {
    font-size: 18px;
    font-weight: 700;
    margin-bottom: 4px;
    color: #fff;
}
.item-desc {
    font-size: 13px;
    color: #ccc;
    margin-bottom: 6px;
}
.item-price {
    font-size: 18px;
    font-weight: 700;
    color: #ffd700;
    text-align: right;
}

/* ===== AJUSTE ANTI-GRUDADO ===== */
.item-wrapper:first-child {
    margin-top: 40px;
}
</style>
</head>

<body>
@php
    $hasLogo = file_exists($logoPath);
    $bg = $hasLogo ? $logoPath : ($establishment->background && file_exists(public_path($establishment->background)) ? public_path($establishment->background) : $logoPath);
@endphp

<!-- CAPA -->
<div class="cover" style="background-image: url('{{ $bg }}');">
    <div class="cover-overlay"></div>
    <div class="cover-content">
        @if($hasLogo)
            <img src="{{ $logoPath }}" alt="Logo" class="cover-logo">
        @endif
        <div class="cover-title">{{ $establishment->name }}</div>
        <div class="cover-info">
            @if($establishment->address)
                <div>{{ $establishment->address }}{{ $establishment->city ? ' - '.$establishment->city : '' }}</div>
            @endif
            @if($establishment->phone)
                <div>Whats: {{ $establishment->phone }}</div>
            @endif
        </div>
    </div>
</div>

<!-- ITENS -->
@foreach($grouped as $categoryName => $items)
@if($items->count() > 0)
<div class="page">
    <div class="category">
        <div class="category-title">{{ strtoupper($categoryName) }}</div>

        @foreach($items as $item)
        @php
            $thumb = null;
            if (!empty($item->image)) {
                $abs = public_path($item->image);
                if (file_exists($abs)) {
                    $mime = mime_content_type($abs);
                    $thumb = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($abs));
                }
            }
        @endphp

        <div class="item-wrapper">
            <div class="item">
                <table class="item-table">
                    <tr>
                        @if($thumb)
                        <td width="120">
                            <img src="{{ $thumb }}" class="thumb" alt="{{ $item->name }}">
                        </td>
                        @endif
                        <td class="item-body">
                            <div class="item-title">{{ $item->name }}</div>
                            @if($item->description)
                                <div class="item-desc">{{ $item->description }}</div>
                            @endif
                            <div class="item-price">R$ {{ number_format($item->price, 2, ',', '.') }}</div>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        @endforeach
    </div>
</div>
@endif
@endforeach
</body>
</html>
