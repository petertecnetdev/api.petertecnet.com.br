<?php

namespace Tests\Feature;

use Tests\TestCase;

class GenericDomainArchitectureRegressionTest extends TestCase
{
    public function test_documents_workflow_is_transport_neutral_and_app_scoped_entrypoints_exist(): void
    {
        $source = $this->source('Domain/Documents/Services/DocumentWorkflowService.php');

        $this->assertStringNotContainsString('Illuminate\\Http\\Request', $source);
        $this->assertStringContainsString('DocumentAuditContext', $source);
        $this->assertStringContainsString('payloadForApplication', $source);
        $this->assertStringContainsString('timelineForApplication', $source);
        $this->assertStringContainsString('sendForApplication', $source);
        $this->assertStringContainsString('signForApplication', $source);
    }

    public function test_document_http_controllers_do_not_own_persistence_workflows(): void
    {
        $catalog = $this->source('Domain/Documents/Http/Controllers/DocumentCatalogController.php');
        $publicSignature = $this->source('Domain/Documents/Http/Controllers/PublicSignatureController.php');

        $this->assertStringNotContainsString('Support\\Facades\\DB', $catalog);
        $this->assertStringNotContainsString('DB::table', $catalog);
        $this->assertStringNotContainsString('Support\\Facades\\DB', $publicSignature);
        $this->assertStringNotContainsString('DB::table', $publicSignature);
    }

    public function test_leasing_lifecycle_controller_delegates_reusable_logic(): void
    {
        $source = $this->source('Domain/Leasing/Http/Controllers/LeaseLifecycleController.php');

        $this->assertStringContainsString('LeaseLifecycleReadService', $source);
        $this->assertStringContainsString('LeaseLifecycleCommandService', $source);
        $this->assertStringNotContainsString('CarbonImmutable', $source);
        $this->assertStringNotContainsString('Str::uuid', $source);
    }

    public function test_leasing_document_and_termination_controllers_do_not_own_email_transport(): void
    {
        $document = $this->source('Domain/Leasing/Http/Controllers/LeaseDocumentWorkflowController.php');
        $termination = $this->source('Domain/Leasing/Http/Controllers/LeaseTerminationController.php');

        $this->assertStringContainsString('NotificationDispatcher', $document);
        $this->assertStringNotContainsString('Support\\Facades\\Mail', $document);
        $this->assertStringNotContainsString('Mail::raw', $document);
        $this->assertStringContainsString('LeaseTerminationService', $termination);
        $this->assertStringNotContainsString('Support\\Facades\\Mail', $termination);
        $this->assertStringNotContainsString('DocumentWorkflowService', $termination);
    }

    public function test_contextual_role_controller_delegates_domain_queries(): void
    {
        $source = $this->source('Domain/Leasing/Http/Controllers/LeaseContextController.php');

        $this->assertStringContainsString('LeaseContextService', $source);
        $this->assertStringNotContainsString('Support\\Facades\\DB', $source);
        $this->assertStringNotContainsString('DB::table', $source);
    }

    public function test_account_documents_reuse_generic_media_storage(): void
    {
        $source = $this->source('Http/Controllers/AccountDocumentController.php');

        $this->assertStringContainsString('ManagedFileStorageService', $source);
        $this->assertStringNotContainsString('Support\\Facades\\Storage', $source);
        $this->assertStringNotContainsString('storeAs(', $source);
    }

    private function source(string $relativePath): string
    {
        $source = file_get_contents(app_path($relativePath));
        $this->assertIsString($source);

        return $source;
    }
}
