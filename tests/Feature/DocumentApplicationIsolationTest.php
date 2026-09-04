<?php

namespace Tests\Feature;

use App\Domain\Documents\DTOs\DocumentAuditContext;
use App\Domain\Documents\Services\DocumentWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentApplicationIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_document_domain_is_reused_without_leaking_between_applications(): void
    {
        $appA = $this->applicationFixture('documents-a', ['name' => 'Documents A', 'is_active' => true]);
        $appB = $this->applicationFixture('documents-b', ['name' => 'Documents B', 'is_active' => true]);
        $workflow = app(DocumentWorkflowService::class);
        $audit = new DocumentAuditContext('127.0.0.1', 'phpunit', 'feature_test', 'document-isolation');

        $documentA = $workflow->createOrRevise(
            $appA->id,
            'generic_record',
            10,
            'agreement',
            'Contrato A',
            'Conteúdo do aplicativo A',
            ['source' => 'a'],
            [['role' => 'owner', 'name' => 'Owner A', 'must_sign' => true]],
            null,
            null,
            $audit,
        );

        $documentB = $workflow->createOrRevise(
            $appB->id,
            'generic_record',
            10,
            'agreement',
            'Contrato B',
            'Conteúdo do aplicativo B',
            ['source' => 'b'],
            [['role' => 'owner', 'name' => 'Owner B', 'must_sign' => true]],
            null,
            null,
            $audit,
        );

        $this->assertNotSame($documentA->id, $documentB->id);
        $this->assertSame($appA->id, $workflow->payloadForApplication($appA->id, $documentA->id)->app_id);
        $this->assertSame($appB->id, $workflow->payloadForApplication($appB->id, $documentB->id)->app_id);
        $this->assertSame('a', $workflow->latestForContext($appA->id, 'generic_record', 10, 'agreement')->payload['source']);
        $this->assertSame('b', $workflow->latestForContext($appB->id, 'generic_record', 10, 'agreement')->payload['source']);

        try {
            $workflow->payloadForApplication($appB->id, $documentA->id);
            $this->fail('A document from application A must never be readable in application B context.');
        } catch (\Throwable) {
            $this->assertTrue(true);
        }

        try {
            $workflow->timelineForApplication($appA->id, $documentB->id);
            $this->fail('Audit timeline must be isolated by application context.');
        } catch (\Throwable) {
            $this->assertTrue(true);
        }
    }

    public function test_workflow_service_has_no_http_request_dependency(): void
    {
        $source = file_get_contents(app_path('Domain/Documents/Services/DocumentWorkflowService.php'));

        $this->assertStringNotContainsString('Illuminate\\Http\\Request', $source);
        $this->assertStringContainsString('DocumentAuditContext', $source);
        $this->assertStringContainsString('payloadForApplication', $source);
        $this->assertStringContainsString('timelineForApplication', $source);
    }
}
