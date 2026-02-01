<?php
declare(strict_types=1);

namespace Tests\Api;

require_once __DIR__ . '/ApiTestCase.php';

final class GetQcReferencesTest extends ApiTestCase
{
    protected const SESSION_ID_PREFIX = 'phpunit-get-qc-references';

    public function testReturnsRecentReferenceList(): void
    {
        $reference = 'QC-REF-' . uniqid();
        $this->insertRollEntry($reference);

        $payload = $this->runApi('get_qc_references.php');

        $this->assertTrue($payload['success']);
        $this->assertContains($reference, $payload['references']);
    }
}
