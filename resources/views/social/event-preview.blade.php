<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <meta name="robots" content="noindex, follow, max-image-preview:large">
    <link rel="canonical" href="{{ $canonicalUrl }}">

    <meta property="og:locale" content="pt_BR">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:title" content="{{ $eventTitle }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    <meta property="og:image" content="{{ $imageUrl }}">
    <meta property="og:image:secure_url" content="{{ $imageUrl }}">
    <meta property="og:image:type" content="image/jpeg">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{{ $imageAlt }}">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $eventTitle }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ $imageUrl }}">
    <meta name="twitter:image:alt" content="{{ $imageAlt }}">
</head>
<body>
    <main>
        <h1>{{ $eventTitle }}</h1>
        <p>{{ $description }}</p>
        <p><a href="{{ $canonicalUrl }}">Abrir evento</a></p>
    </main>
</body>
</html>
