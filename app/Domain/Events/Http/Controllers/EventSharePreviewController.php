<?php

namespace App\Domain\Events\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class EventSharePreviewController extends Controller
{
    public function __invoke(Request $request, string $application, string $slug)
    {
        $app = Application::query()
            ->where('slug', $application)
            ->where('is_active', true)
            ->firstOrFail();

        $event = Event::query()
            ->where('app_id', $app->id)
            ->where('slug', $slug)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where(fn ($query) => $query
                ->where('is_private', false)
                ->orWhereNull('is_private'))
            ->with('production:id,app_id,name,slug')
            ->firstOrFail();

        $frontendOrigin = rtrim((string) $app->url, '/');
        abort_unless(filter_var($frontendOrigin, FILTER_VALIDATE_URL), 404);

        $canonicalUrl = $frontendOrigin.'/flyer/'.$event->slug;
        $imageUrl = $this->absoluteImageUrl($event->image);
        $productionName = $event->production?->name ?: 'Produção do evento';
        $eventDate = $event->start_date
            ? Carbon::parse($event->start_date)
                ->timezone(config('app.timezone', 'America/Sao_Paulo'))
                ->format('d/m/Y \à\s H:i')
            : 'Data a confirmar';

        $title = trim($event->title.' • '.$productionName);
        $description = $eventDate.' • Veja o flyer, o evento e os ingressos na '.$app->name.'.';

        return response()
            ->view('share.event', [
                'applicationName' => $app->name,
                'eventTitle' => $event->title,
                'productionName' => $productionName,
                'title' => $title,
                'description' => $description,
                'canonicalUrl' => $canonicalUrl,
                'imageUrl' => $imageUrl,
            ])
            ->header('Cache-Control', 'public, max-age=300, s-maxage=300')
            ->header('X-Robots-Tag', 'noindex, follow');
    }

    private function absoluteImageUrl(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        if (Str::startsWith($value, ['http://', 'https://'])) {
            return $value;
        }

        $path = ltrim($value, '/');
        if (Str::startsWith($path, 'storage/')) {
            $path = Str::after($path, 'storage/');
        }

        return url('/storage/'.$path);
    }
}
