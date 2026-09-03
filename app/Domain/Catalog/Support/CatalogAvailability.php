<?php

namespace App\Domain\Catalog\Support;

use App\Models\Establishment;

final class CatalogAvailability
{
    public function evaluate(
        ?Establishment $establishment,
        ?int $viewerId = null,
        bool $approvalRequired = false,
        bool $previewRequested = false
    ): array {
        if (! $establishment) {
            return $this->contract(
                status: 'not_found',
                reason: 'not_found',
                httpStatus: 404,
                message: 'Recurso não encontrado.'
            );
        }

        $isOwner = $viewerId !== null && (int) $establishment->user_id === $viewerId;
        $ownerContext = [
            'is_owner' => $isOwner,
            'can_manage' => $isOwner && ! $establishment->is_cancelled,
            'can_preview' => $isOwner && ! $establishment->is_cancelled,
            'resource_id' => $isOwner ? (int) $establishment->id : null,
        ];

        if ($establishment->is_cancelled) {
            return $this->contract(
                status: 'unavailable',
                reason: 'disabled',
                httpStatus: 410,
                message: 'Este recurso não está mais disponível.',
                viewer: $ownerContext
            );
        }

        if (! $establishment->is_published) {
            if ($previewRequested && $isOwner) {
                return $this->contract(
                    status: 'preview',
                    reason: 'not_public',
                    httpStatus: 200,
                    message: 'Pré-visualização privada ativa.',
                    viewer: $ownerContext,
                    preview: true
                );
            }

            return $this->contract(
                status: 'restricted',
                reason: 'not_public',
                httpStatus: 404,
                message: 'Este recurso não está disponível publicamente.',
                viewer: $ownerContext
            );
        }

        if ($approvalRequired && ! $establishment->is_approved) {
            if ($previewRequested && $isOwner) {
                return $this->contract(
                    status: 'preview',
                    reason: 'pending_approval',
                    httpStatus: 200,
                    message: 'Pré-visualização privada ativa enquanto a publicação aguarda aprovação.',
                    viewer: $ownerContext,
                    preview: true
                );
            }

            return $this->contract(
                status: 'restricted',
                reason: 'pending_approval',
                httpStatus: 404,
                message: 'Este recurso ainda não está disponível publicamente.',
                viewer: $ownerContext
            );
        }

        return $this->contract(
            status: 'public',
            reason: null,
            httpStatus: 200,
            message: 'Recurso disponível publicamente.',
            viewer: $ownerContext,
            isPublic: true,
            indexable: true
        );
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
