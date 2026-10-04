<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Media Library storage
    |--------------------------------------------------------------------------
    |
    | Use "public" for local storage or "s3" for S3-compatible providers.
    | Cloudflare R2 is supported through the existing s3 disk by configuring
    | AWS_ENDPOINT, AWS_BUCKET, AWS_URL and the provider credentials.
    |
    */
    'disk' => env('MEDIA_LIBRARY_DISK', 'public'),
    'max_upload_kb' => (int) env('MEDIA_LIBRARY_MAX_UPLOAD_KB', 153600),
    'max_image_upload_kb' => (int) env('MEDIA_LIBRARY_MAX_IMAGE_UPLOAD_KB', 20480),
    'max_video_upload_kb' => (int) env('MEDIA_LIBRARY_MAX_VIDEO_UPLOAD_KB', 153600),
    'max_document_upload_kb' => (int) env('MEDIA_LIBRARY_MAX_DOCUMENT_UPLOAD_KB', 30720),
    'max_image_pixels' => (int) env('MEDIA_LIBRARY_MAX_IMAGE_PIXELS', 40000000),

    'allowed_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'video/mp4',
        'video/webm',
        'video/quicktime',
        'application/pdf',
    ],

    'variant_background' => env('MEDIA_LIBRARY_VARIANT_BACKGROUND', '#101010'),
    'variant_quality' => (int) env('MEDIA_LIBRARY_VARIANT_QUALITY', 86),

    // Social variants always contain the full source. Flyers are never
    // destructively cropped simply to fit a social aspect ratio.
    'variants' => [
        'thumbnail' => ['width' => 720, 'height' => 720],
        'feed_square' => ['width' => 1080, 'height' => 1080],
        'feed_portrait' => ['width' => 1080, 'height' => 1350],
        'story' => ['width' => 1080, 'height' => 1920],
        'og' => ['width' => 1200, 'height' => 630],
    ],
];
