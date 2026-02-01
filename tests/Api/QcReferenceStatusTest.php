<?php
declare(strict_types=1);

namespace Tests\Api;

final class QcReferenceStatusTest extends ApiTestCase
{
    protected const SESSION_ID_PREFIX = 'qc-ref-status';

    public function testSingleReferenceSwitchesFromPendingToSubmitted(): void
    {
        $standardId = $this->insertTestStandard('QC Reference Status', 'REF-METH', 'Bundle Sample');

        $payload = $this->runScript('forms/qc_test_order.php', [
            'action' => 'check_reference_test_status',
            'from_reference' => 'QC-REF-01',
            'to_reference' => 'QC-REF-01',
            'test_name' => 'QC Reference Status',
            'method' => 'REF-METH'
        ]);

        $this->assertTrue($payload['success']);
        $this->assertCount(1, $payload['references']);
        $first = $payload['references'][0];
        $this->assertSame('pending', $first['status']);
        $this->assertNull($first['report_number']);
        $this->assertTrue($first['can_submit']);

        $this->insertQcTestOrder('REP-REF-01', 'QC-REF-01', $standardId, 'REF-METH', 'pending');

        $payload = $this->runScript('forms/qc_test_order.php', [
            'action' => 'check_reference_test_status',
            'from_reference' => 'QC-REF-01',
            'to_reference' => 'QC-REF-01',
            'test_name' => 'QC Reference Status',
            'method' => 'REF-METH'
        ]);

        $this->assertTrue($payload['success']);
        $this->assertCount(1, $payload['references']);
        $submitted = $payload['references'][0];
        $this->assertSame('submitted', $submitted['status']);
        $this->assertSame('REP-REF-01', $submitted['report_number']);
        $this->assertFalse($submitted['can_submit']);
    }

    public function testBundleRangeGeneratesExpectedEntries(): void
    {
        $standardId = $this->insertTestStandard('QC Reference Status Bundle', 'REF-BUNDLE', 'Bundle Sample');

        $this->insertQcTestOrder('REP-BUND-01', 'BUNDLE-R01-GT', $standardId, 'REF-BUNDLE');
        $this->insertQcTestOrder('REP-BUND-03', 'BUNDLE-R03-GT', $standardId, 'REF-BUNDLE');

        $payload = $this->runScript('forms/qc_test_order.php', [
            'action' => 'check_reference_test_status',
            'from_reference' => 'BUNDLE-R01-GT',
            'to_reference' => 'BUNDLE-R03-GT',
            'test_name' => 'QC Reference Status Bundle',
            'method' => 'REF-BUNDLE'
        ]);

        $this->assertTrue($payload['success']);
        $debugRefs = $payload['debug']['generated_refs'] ?? [];
        $this->assertSame(['BUNDLE-R01-GT', 'BUNDLE-R02-GT', 'BUNDLE-R03-GT'], $debugRefs);

        $referenceMap = [];
        foreach ($payload['references'] as $entry) {
            $referenceMap[$entry['reference']] = $entry;
        }

        $this->assertSame('submitted', $referenceMap['BUNDLE-R01-GT']['status']);
        $this->assertTrue($referenceMap['BUNDLE-R01-GT']['can_submit'] === false);
        $this->assertSame('REP-BUND-01', $referenceMap['BUNDLE-R01-GT']['report_number']);

        $this->assertSame('pending', $referenceMap['BUNDLE-R02-GT']['status']);
        $this->assertTrue($referenceMap['BUNDLE-R02-GT']['can_submit']);
        $this->assertNull($referenceMap['BUNDLE-R02-GT']['report_number']);

        $this->assertSame('submitted', $referenceMap['BUNDLE-R03-GT']['status']);
        $this->assertSame('REP-BUND-03', $referenceMap['BUNDLE-R03-GT']['report_number']);
    }
}
