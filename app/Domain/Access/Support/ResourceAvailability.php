<?php

namespace App\Domain\Access\Support;

final class ResourceAvailability
{
    public function evaluate(
        ?object $resource,
        ?int $viewerId = null,
        bool $approvalRequired = false,
        bool $previewRequested = false,
        array $mapping = []
    ): array {
        if (! $resource) {
            return $this->contract(
                status: 'not_found',
                reason: 'not_found',
                httpStatus: 404,
                message: 'Recurso não encontrado.'
            );
        }

        $ownerId = $this->value($resource, $mapping['owner_id'] ?? 'user_id');
        $resourceId = $this->value($resource, $mapping['resource_id'] ?? 'id');
        $isDisabled = (bool) $this->value($resource, $mapping['disabled'] ?? 'is_cancelled', false);
        $isPublic = (bool) $this->value($resource, $mapping['public'] ?? 'is_published', true);
        $isApproved = (bool) $this->value($resource, $mapping['approved'] ?? 'is_approved', true);
        $isOwner = $viewerId !== null && $ownerId !== null && (int) $ownerId === $viewerId;

        $viewer = [
            'is_owner' => $isOwner,
            'can_manage' => $isOwner && ! $isDisabled,
            'can_preview' => $isOwner && ! $isDisabled,
            'resource_id' => $isOwner && $resourceId !== null ? (int) $resourceId : null,
        ];

        if ($isDisabled) {
            return $this->contract(
                status: 'unavailable',
                reason: 'disabled',
                httpStatus: 410,
                message: 'Este recurso não está mais disponível.',
                viewer: $viewer
            );
        }

        if (! $isPublic) {
            if ($previewRequested && $isOwner) {
                return $this->contract(
                    status: 'preview',
                    reason: 'not_public',
                    httpStatus: 200,
                    message: 'Pré-visualização privada ativa.',
                    viewer: $viewer,
                    preview: true
                );
            }

            return $this->contract(
                status: 'restricted',
                reason: 'not_public',
                httpStatus: 404,
                message: 'Este recurso não está disponível publicamente.',
                viewer: $viewer
            );
        }

        if ($approvalRequired && ! $isApproved) {
            if ($previewRequested && $isOwner) {
                return $this->contract(
                    status: 'preview',
                    reason: 'pending_approval',
                    httpStatus: 200,
                    message: 'Pré-visualização privada ativa enquanto a publicação aguarda aprovação.',
                    viewer: $viewer,
                    preview: true
                );
            }

            return $this->contract(
                status: 'restricted',
                reason: 'pending_approval',
                httpStatus: 404,
                message: 'Este recurso ainda não está disponível publicamente.',
                viewer: $viewer
            );
        }

        return $this->contract(
            status: 'public',
            reason: null,
            httpStatus: 200,
            message: 'Recurso disponível publicamente.',
            viewer: $viewer,
            isPublic: true,
            indexable: true
        );
    }

    private function value(object $resource, string|callable $resolver, mixed $default = null): mixed
    {
        if (is_callable($resolver)) {
            return $resolver($resource);
        }

        return data_get($resource, $resolver, $default);
    }

    private function contract(
        string $status,
        ?string $reason,
        int $httpStatus,
        string $message,
        array $viewer = [],
        bool $isPublic = false,
        bool $indexable = false,
        bool $preview = false
    ): array {
        return [
            'status' => $status,
            'reason' => $reason,
            'is_public' => $isPublic,
            'indexable' => $indexable,
            'preview' => $preview,
            'http_status' => $httpStatus,
            'message' => $message,
            'viewer' => array_merge([
                'is_owner' => false,
                'can_manage' => false,
                'can_preview' => false,
                'resource_id' => null,
            ], $viewer),
        ];
    }
}
