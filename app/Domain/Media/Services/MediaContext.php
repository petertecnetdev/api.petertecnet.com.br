<?php

namespace App\Domain\Media\Services;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class MediaContext
{
    public const PROFILE_AVATAR = 'profile/avatar';
    public const ESTABLISHMENT_LOGO = 'establishment/logo';
    public const ESTABLISHMENT_COVER = 'establishment/cover';
    public const ITEM_IMAGE = 'item/image';
    public const EVENT_BANNER = 'event/banner';
    public const POST_MEDIA = 'post/media';
    public const MESSAGE_ATTACHMENT = 'message/attachment';
    public const SUPPORT_ATTACHMENT = 'support/attachment';
    public const DOCUMENT = 'document';

    /** @return array<int, string> */
    public function known(): array
    {
        return [
            self::PROFILE_AVATAR,
            self::ESTABLISHMENT_LOGO,
            self::ESTABLISHMENT_COVER,
            self::ITEM_IMAGE,
            self::EVENT_BANNER,
            self::POST_MEDIA,
            self::MESSAGE_ATTACHMENT,
            self::SUPPORT_ATTACHMENT,
            self::DOCUMENT,
        ];
    }

    public function normalize(string $context): string
    {
        $segments = collect(explode('/', trim($context, '/')))
            ->filter(fn (string $segment): bool => trim($segment) !== '')
            ->map(function (string $segment): string {
                return Str::of($segment)
                    ->lower()
                    ->replaceMatches('/[^a-z0-9_-]+/', '-')
                    ->trim('-')
                    ->toString();
            })
            ->filter();

        $normalized = $segments->implode('/');

        if ($normalized === '') {
            throw new InvalidArgumentException('Media context cannot be empty.');
        }

        return $normalized;
    }
}
