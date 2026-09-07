<?php

namespace App\Domain\Creative\Http\Controllers;

use App\Domain\Creative\Models\CreativePromptTemplate;
use App\Domain\Creative\Services\CloudflareImageGenerator;
use App\Domain\Creative\Services\CreativePromptTemplateService;
use App\Http\Controllers\Controller;
use App\Models\EcosystemAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

final class AdminCreativePromptController extends Controller
{
    public function __construct(
        private readonly CreativePromptTemplateService $templates,
        private readonly CloudflareImageGenerator $generator,
    ) {}

    public function show(string $key)
    {
        abort_unless($this->templates->supports($key), 404, 'Prompt não encontrado.');

        return response()->json([
            'prompt' => $this->templates->definition($key),
        ]);
    }

    public function update(Request $request, string $key)
    {
        abort_unless($this->templates->supports($key), 404, 'Prompt não encontrado.');

        $data = $request->validate([
            'template' => 'required|string|min:120|max:1600',
        ]);

        $before = $this->templates->definition($key);
        $record = $this->templates->save($key, $data['template'], (int) $request->user()->id);
        $after = $this->templates->definition($key);

        $this->audit($request, 'creative.prompt.updated', $record, $before, $after);

        return response()->json([
            'message' => 'Prompt da IA atualizado. As próximas imagens já usarão esta versão.',
            'prompt' => $after,
        ]);
    }

    public function reset(Request $request, string $key)
    {
        abort_unless($this->templates->supports($key), 404, 'Prompt não encontrado.');

        $before = $this->templates->definition($key);
        $record = $this->templates->reset($key);
        $after = $this->templates->definition($key);

        $this->audit($request, 'creative.prompt.reset_to_default', $record, $before, $after);

        return response()->json([
            'message' => 'Prompt restaurado para o padrão seguro da Peter Tecnet.',
            'prompt' => $after,
        ]);
    }

    public function image(Request $request)
    {
        $data = $request->validate([
            'app_id' => 'required|integer|exists:applications,id',
            'purpose' => ['required', Rule::in([CreativePromptTemplateService::EVENT_FLYER_BACKGROUND])],
            'subject' => 'required|string|min:2|max:180',
            'description' => 'nullable|string|max:1200',
            'category' => 'nullable|string|max:180',
            'style' => ['nullable', Rule::in(['neon', 'premium', 'sunset', 'clean'])],
            'production_name' => 'nullable|string|max:180',
            'venue' => 'nullable|string|max:180',
            'city' => 'nullable|string|max:120',
            'uf' => 'nullable|string|max:2',
            'format' => ['nullable', Rule::in(['cover', 'post', 'story', 'square', 'portrait'])],
        ]);

        $prompt = $this->templates->renderEventFlyer($data);

        try {
            $result = $this->generator->generate(
                $prompt,
                (int) $request->user()->id,
                (int) $data['app_id'],
            );
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json([
                'message' => $exception->getMessage(),
                'fallback_available' => true,
            ], 503);
        }

        return response()->json([
            'image' => [
                'data_uri' => 'data:'.$result['mime_type'].';base64,'.$result['image'],
                'mime_type' => $result['mime_type'],
                'provider' => $result['provider'],
                'model' => $result['model'],
            ],
            'usage' => [
                'plan' => 'admin',
                'purpose' => $data['purpose'],
                'prompt_version' => $this->templates->definition(CreativePromptTemplateService::EVENT_FLYER_BACKGROUND)['version'],
            ],
        ]);
    }

    private function audit(
        Request $request,
        string $action,
        ?CreativePromptTemplate $record,
        array $before,
        array $after,
    ): void {
        EcosystemAuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'entity_type' => CreativePromptTemplate::class,
            'entity_id' => $record?->id,
            'before' => $before,
            'after' => $after,
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ]);
    }
}
