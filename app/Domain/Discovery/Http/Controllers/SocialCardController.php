<?php

namespace App\Domain\Discovery\Http\Controllers;

use App\Domain\Discovery\Services\DiscoveryService;
use App\Http\Controllers\Controller;
use App\Models\ContentEntry;
use App\Models\Establishment;
use App\Models\Item;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class SocialCardController extends Controller
{
    public function __construct(private readonly DiscoveryService $discovery)
    {
    }

    public function show(string $type, string $identifier): Response|RedirectResponse
    {
        [$title, $subtitle] = $this->copy($type, $identifier);

        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagepng')) {
            return redirect('https://petertecnet.com.br/thumbnail.jpg', 302);
        }

        $width = 1200;
        $height = 630;
        $image = imagecreatetruecolor($width, $height);
        if (! $image) return redirect('https://petertecnet.com.br/thumbnail.jpg', 302);

        $background = imagecolorallocate($image, 3, 12, 18);
        $panel = imagecolorallocate($image, 7, 29, 39);
        $cyan = imagecolorallocate($image, 64, 221, 241);
        $white = imagecolorallocate($image, 239, 252, 255);
        $muted = imagecolorallocate($image, 137, 170, 181);
        $grid = imagecolorallocate($image, 12, 55, 70);

        imagefilledrectangle($image, 0, 0, $width, $height, $background);
        for ($x = 0; $x < $width; $x += 60) imageline($image, $x, 0, $x, $height, $grid);
        for ($y = 0; $y < $height; $y += 60) imageline($image, 0, $y, $width, $y, $grid);
        imagefilledrectangle($image, 66, 66, 1134, 564, $panel);
        imagefilledrectangle($image, 66, 66, 75, 564, $cyan);

        imagestring($image, 5, 112, 112, 'PETER TECNET / DISCOVERY', $cyan);
        $lines = $this->wrap(Str::ascii($title), 42, 3);
        $y = 205;
        foreach ($lines as $line) {
            imagestring($image, 5, 112, $y, $line, $white);
            imagestring($image, 5, 112, $y + 18, $line, $white);
            $y += 58;
        }

        if ($subtitle) {
            foreach ($this->wrap(Str::ascii($subtitle), 72, 2) as $line) {
                imagestring($image, 4, 112, $y + 14, $line, $muted);
                $y += 26;
            }
        }

        imagestring($image, 4, 112, 510, 'petertecnet.com.br', $muted);
        imagestring($image, 4, 925, 510, strtoupper(Str::ascii($type)), $cyan);

        ob_start();
        imagepng($image, null, 7);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400, stale-while-revalidate=604800',
            'Content-Length' => (string) strlen($png),
        ]);
    }

    private function copy(string $type, string $identifier): array
    {
        if ($type === 'content') {
            $entry = ContentEntry::query()->published()->where('slug', $identifier)->firstOrFail();
            return [$entry->title, $entry->category ?: 'Conteudo e tecnologia aplicada'];
        }

        if ($type === 'establishment') {
            $establishment = $this->discovery->publicEstablishment($identifier);
            $location = trim(implode(' - ', array_filter([$establishment->city, $establishment->uf])));
            return [$establishment->fantasy ?: $establishment->name, trim(($establishment->category ?: $establishment->type ?: 'Empresa') . ($location ? " / {$location}" : ''))];
        }

        if ($type === 'item') {
            $item = $this->discovery->publicItem($identifier);
            $establishment = $item->establishment()->first();
            return [$item->name, trim(($item->category ?: $item->type ?: 'Catalogo') . ($establishment ? ' / ' . ($establishment->fantasy ?: $establishment->name) : ''))];
        }

        if ($type === 'category') {
            $category = Item::query()->whereNotNull('category')->distinct()->pluck('category')->first(fn ($value) => Str::slug($value) === Str::slug($identifier));
            abort_unless($category, 404);
            return [$category, 'Catalogo digital Peter Tecnet'];
        }

        abort(404);
    }

    private function wrap(string $text, int $length, int $maxLines): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $candidate = trim($line . ' ' . $word);
            if (strlen($candidate) <= $length) {
                $line = $candidate;
                continue;
            }
            if ($line !== '') $lines[] = $line;
            $line = $word;
            if (count($lines) >= $maxLines - 1) break;
        }
        if ($line !== '' && count($lines) < $maxLines) $lines[] = $line;
        return $lines ?: ['Peter Tecnet'];
    }
}
