<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\FlyerDateAudit;
use App\Models\Production;
use App\Services\FlyerDateAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class AnalyzeFlyerDateConsistency implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $uniqueFor = 600;

    public function __construct(public readonly int $auditId)
    {
        $this->onQueue((string) config('queue.flyer_date_queue', 'default'));
    }

    public function uniqueId(): string { return 'flyer-date-audit:'.$this->auditId; }

    public function handle(FlyerDateAnalysisService $analysis): void
    {
        $audit = FlyerDateAudit::query()->find($this->auditId);
        if (! $audit || in_array($audit->status, ['consistent', 'mismatch', 'no_date', 'ambiguous'], true)) return;

        $audit->update(['status' => 'analyzing']);
        try {
            $path = $this->imagePath($audit);
            if (! $path || ! Storage::disk('public')->exists($path)) throw new \RuntimeException('Flyer armazenado não encontrado.');
            $bytes = Storage::disk('public')->get($path);
            if ($bytes === '' || strlen($bytes) > 5 * 1024 * 1024) throw new \RuntimeException('Flyer inválido ou acima de 5 MB.');
            $mime = (string) (Storage::disk('public')->mimeType($path) ?: '');
            if (! preg_match('#^image/(?:png|jpe?g|webp)$#i', $mime)) throw new \RuntimeException('Formato de flyer não suportado.');

            $result = $analysis->analyze([
                'image_data_url' => 'data:'.strtolower($mime).';base64,'.base64_encode($bytes),
                'expected_start_at' => $audit->expected_start_at?->toIso8601String(),
                'timezone' => $audit->timezone,
                'locale' => $audit->locale,
                'recurring' => $audit->recurring,
                'day_of_week' => $audit->day_of_week,
            ]);
            $audit->update(['status' => $result['status'], 'result' => $result]);
        } catch (Throwable $exception) {
            $audit->update(['status' => $this->attempts() >= $this->tries ? 'failed' : 'queued', 'result' => ['reason' => mb_substr($exception->getMessage(), 0, 220)]]);
            throw $exception;
        }
    }

    private function imagePath(FlyerDateAudit $audit): ?string
    {
        $image = $audit->entity_type === 'event'
            ? Event::query()->where('app_id', $audit->app_id)->whereKey($audit->entity_id)->value('image')
            : Production::query()->where('app_id', $audit->app_id)->whereKey($audit->entity_id)->value('background');
        $image = trim((string) $image);
        if ($image === '' || preg_match('#^https?://#i', $image)) return null;
        return preg_replace('#^/?storage/#', '', ltrim($image, '/')) ?: null;
    }
}
