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

    public function canonical(string $context): string
    {
        $normalized = $this->normalize($context);

        return $this->aliases()[$normalized] ?? $normalized;
    }

    public function isKnown(string $context): bool
    {
        return in_array($this->canonical($context), $this->known(), true);
    }

    /** @return array<string, string> */
    public function aliases(): array
    {
        return [
            'avatar' => self::PROFILE_AVATAR,
            'avatars' => self::PROFILE_AVATAR,
            'profile-avatar' => self::PROFILE_AVATAR,
            'profile-avatars' => self::PROFILE_AVATAR,
            'logo' => self::ESTABLISHMENT_LOGO,
            'logos' => self::ESTABLISHMENT_LOGO,
            'establishment-logo' => self::ESTABLISHMENT_LOGO,
            'cover' => self::ESTABLISHMENT_COVER,
            'covers' => self::ESTABLISHMENT_COVER,
            'establishment-cover' => self::ESTABLISHMENT_COVER,
            'item' => self::ITEM_IMAGE,
            'items' => self::ITEM_IMAGE,
            'item-image' => self::ITEM_IMAGE,
            'item-images' => self::ITEM_IMAGE,
            'event-banner' => self::EVENT_BANNER,
            'event-banners' => self::EVENT_BANNER,
            'post' => self::POST_MEDIA,
            'posts' => self::POST_MEDIA,
            'post-media' => self::POST_MEDIA,
            'message-attachment' => self::MESSAGE_ATTACHMENT,
            'message-attachments' => self::MESSAGE_ATTACHMENT,
            'support-attachment' => self::SUPPORT_ATTACHMENT,
            'support-attachments' => self::SUPPORT_ATTACHMENT,
            'documents' => self::DOCUMENT,
        ];
    }
}
