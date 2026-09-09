<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $canonicalUrl }}">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $applicationName }}">
    <meta property="og:locale" content="pt_BR">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    @if($imageUrl)
        <meta property="og:image" content="{{ $imageUrl }}">
        <meta property="og:image:secure_url" content="{{ $imageUrl }}">
        <meta property="og:image:alt" content="Flyer de {{ $eventTitle }} — {{ $productionName }}">
    @endif

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    @if($imageUrl)
        <meta name="twitter:image" content="{{ $imageUrl }}">
    @endif

    <meta http-equiv="refresh" content="0;url={{ $canonicalUrl }}">
    <script>window.location.replace(@json($canonicalUrl));</script>
</head>
<body>
    <main>
        <h1>{{ $eventTitle }}</h1>
        <p>{{ $productionName }} · {{ $description }}</p>
        <a href="{{ $canonicalUrl }}">Abrir evento na {{ $applicationName }}</a>
    </main>
</body>
</html>
