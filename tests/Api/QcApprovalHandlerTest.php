<?php
declare(strict_types=1);

namespace Tests\Api;

final class QcApprovalHandlerTest extends ApiTestCase
{
    protected const SESSION_ID_PREFIX = 'qc-approval';

    public function testApprovesSingleQcTestOrder(): void
    {
        $standardId = $this->insertTestStandard('QC Approval Test', 'APPROVE-METHOD', 'QC Product');
        $reportNumber = 'QC-APP-001';
        $reference = 'QC-APP-REF-001';

        $this->insertQcTestOrder($reportNumber, $reference, $standardId, 'APPROVE-METHOD');
        $this->insertRollQcReport($reference, '1', 'L1');
        $this->setSessionRole('admin');

        $this->runScript('handlers/approve_qc_test.php', [], [
            'action' => 'approve',
            'report_number' => $reportNumber,
            'test_type' => 'qc_test_order',
            'admin_comment' => 'Approved via automated test',
            'roll_destination' => 'fg_production',
            'is_external' => '0'
        ]);

        $conn = static::ensureTestConnection();
        $stmt = $conn->prepare("SELECT status, approved_by, roll_destination FROM qc_test_orders WHERE report_number = ? LIMIT 1");
        $stmt->bind_param('s', $reportNumber);
        $stmt->execute();
        $result = $stmt->get_result();
        $this->assertNotEmpty($result);
        $row = $result->fetch_assoc();
        $stmt->close();

        $this->assertSame('approved', $row['status']);
        $this->assertSame('PHPUnit Tester', $row['approved_by']);
        $this->assertSame('fg_production', $row['roll_destination']);

        $reportStmt = $conn->prepare("SELECT approved, approved_by, overall_status FROM roll_qc_reports WHERE reference_number = ? LIMIT 1");
        $reportStmt->bind_param('s', $reference);
        $reportStmt->execute();
        $reportResult = $reportStmt->get_result();
        $reportRow = $reportResult->fetch_assoc();
        $reportStmt->close();

        $this->assertSame('approved', $reportRow['overall_status']);
        $this->assertSame('PHPUnit Tester', $reportRow['approved_by']);
        $this->assertTrue((int)$reportRow['approved'] === 1);
    }

    public function testApprovesMultipleReportsInBulk(): void
    {
        $standardId = $this->insertTestStandard('QC Approval Bulk Test', 'APPROVE-ALL', 'QC Product');
        $referenceA = 'QC-BULK-REF-01';
        $referenceB = 'QC-BULK-REF-02';
        $reportA = 'QC-BULK-001';
        $reportB = 'QC-BULK-002';

        $this->insertQcTestOrder($reportA, $referenceA, $standardId, 'APPROVE-ALL');
        $this->insertQcTestOrder($reportB, $referenceB, $standardId, 'APPROVE-ALL');
        $this->insertRollQcReport($referenceA, '1', 'L1');
        $this->insertRollQcReport($referenceB, '2', 'L1');

        $this->runScript('handlers/approve_qc_test.php', [], [
            'action' => 'approve',
            'report_number' => "$reportA, $reportB",
            'test_type' => 'qc_test_order',
            'admin_comment' => 'Bulk approve',
            'roll_destination' => 'bag_production',
            'is_external' => '0'
        ]);

        $conn = static::ensureTestConnection();
        foreach ([$reportA, $reportB] as $reportNumber) {
            $stmt = $conn->prepare("SELECT status FROM qc_test_orders WHERE report_number = ? LIMIT 1");
            $stmt->bind_param('s', $reportNumber);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            $this->assertSame('approved', $row['status']);
        }
    }
}
