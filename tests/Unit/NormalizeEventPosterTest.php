<?php

namespace Tests\Unit;

use App\Domain\Events\Http\Middleware\NormalizeEventPoster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Facades\Image;
use Tests\TestCase;

final class NormalizeEventPosterTest extends TestCase
{
    public function test_it_normalizes_a_two_by_three_event_poster_to_the_official_resolution(): void
    {
        Storage::fake('public');

        $request = Request::create('/api/v1/apps/cutinapp/events', 'POST');
        $request->files->set('image', UploadedFile::fake()->image('poster.jpg', 1024, 1536));

        $path = 'images/apps/cutinapp/events/test.webp';
        $response = (new NormalizeEventPoster)->handle(
            $request,
            fn () => new JsonResponse(['event' => ['image' => $path]], 201)
        );

        $this->assertSame(201, $response->getStatusCode());
        Storage::disk('public')->assertExists($path);

        $stored = Image::make(Storage::disk('public')->get($path));
        $this->assertSame(1024, $stored->width());
        $this->assertSame(1536, $stored->height());
        $this->assertSame('image/webp', $stored->mime());
    }

    public function test_it_automatically_crops_landscape_art_to_the_official_portrait_size(): void
    {
        Storage::fake('public');

        $request = Request::create('/api/v1/apps/cutinapp/events', 'POST');
        $request->files->set('image', UploadedFile::fake()->image('landscape.jpg', 1600, 900));

        $path = 'images/apps/cutinapp/events/landscape.webp';
        $response = (new NormalizeEventPoster)->handle(
            $request,
            fn () => new JsonResponse(['event' => ['image' => $path]], 201)
        );

        $this->assertSame(201, $response->getStatusCode());
        Storage::disk('public')->assertExists($path);
        $saved = Image::make(Storage::disk('public')->get($path));
        $this->assertSame(1024, $saved->width());
        $this->assertSame(1536, $saved->height());
    }

    public function test_it_automatically_crops_square_art_for_legacy_clients(): void
    {
        Storage::fake('public');
        $request = Request::create('/api/v1/apps/cutinapp/events', 'POST');
        $request->files->set('image', UploadedFile::fake()->image('square.png', 800, 800));

        $path = 'images/apps/cutinapp/events/square.webp';
        (new NormalizeEventPoster)->handle(
            $request,
            fn () => new JsonResponse(['event' => ['image' => $path]], 201)
        );

        $saved = Image::make(Storage::disk('public')->get($path));
        $this->assertSame(1024, $saved->width());
        $this->assertSame(1536, $saved->height());
    }

    public function test_it_rejects_an_unreadable_upload_with_validation_instead_of_server_error(): void
    {
        Storage::fake('public');

        $request = Request::create('/api/v1/apps/cutinapp/events/265', 'PATCH');
        $request->files->set(
            'image',
            new UploadedFile('', 'poster.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true)
        );

        try {
            (new NormalizeEventPoster)->handle(
                $request,
                fn () => new JsonResponse(['event' => ['image' => 'images/apps/cutinapp/events/test.webp']], 200)
            );

            $this->fail('Expected an invalid upload to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'A imagem principal do evento excede o limite permitido. Envie um arquivo de até 5 MB.',
                $exception->errors()['image'][0] ?? null
            );
        }
    }
}
