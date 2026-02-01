<?php
declare(strict_types=1);

namespace Tests\Api;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../config/security_config.php';

abstract class ApiTestCase extends TestCase
{
    protected const SESSION_ID_PREFIX = 'phpunit-api';

    protected string $currentSessionId = '';
    protected string $sessionRole = 'admin';
    protected static ?\mysqli $testConn = null;
    protected static array $insertedRollIds = [];
    protected array $insertedTestStandardIds = [];
    protected array $insertedQcTestOrderIds = [];
    protected array $insertedRollQcReportIds = [];

    public static function setUpBeforeClass(): void
    {
        static::ensureTestConnection();
        static::truncateTestTables();
    }

    public static function tearDownAfterClass(): void
    {
        if (static::$testConn !== null) {
            static::$testConn->close();
            static::applyConnectionToSecurityConfig(null);
            static::$testConn = null;
        }
    }

    protected function setUp(): void
    {
        $this->prepareSessionData();
    }

    protected function tearDown(): void
    {
        $this->cleanupInsertedEntries();
        $this->cleanupTestData();
    }

    protected static function ensureTestConnection(): \mysqli
    {
        if (static::$testConn === null) {
            return static::injectTestConnection();
        }

        if (@static::$testConn->ping()) {
            return static::$testConn;
        }

        static::$testConn->close();
        return static::injectTestConnection();
    }

    protected static function injectTestConnection(): \mysqli
    {
        $conn = new \mysqli('127.0.0.1', 'root', '', 'geobagg_test', 3307);
        if ($conn->connect_error) {
            throw new \RuntimeException('Unable to connect to test database: ' . $conn->connect_error);
        }
        $conn->set_charset('utf8mb4');
        static::applyConnectionToSecurityConfig($conn);
        static::$testConn = $conn;
        return $conn;
    }

    protected static function applyConnectionToSecurityConfig(?\mysqli $connection): void
    {
        $reflection = new \ReflectionClass(\SecurityConfig::class);
        $property = $reflection->getProperty('conn');
        $property->setAccessible(true);
        $property->setValue(null, $connection);
    }

    protected function prepareSessionData(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $this->currentSessionId = static::generateSessionId();
        session_id($this->currentSessionId);
        session_start();
        $_SESSION['user_id'] = 1;
        $_SESSION['username'] = 'phpunit';
        $_SESSION['full_name'] = 'PHPUnit Tester';
        $_SESSION['role'] = $this->sessionRole;
        session_write_close();
    }

    protected static function truncateTestTables(): void
    {
        $conn = static::ensureTestConnection();

        $tables = [
            'roll_entry',
            'routed',
            'weathering_exposure_reports'
        ];

        foreach ($tables as $table) {
            if (!static::tableExists($conn, $table)) {
                continue;
            }

            $conn->query("TRUNCATE TABLE `$table`");
        }
    }

    protected static function tableExists(\mysqli $conn, string $table): bool
    {
        $escapedTable = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$escapedTable}'");

        return ($result && $result->num_rows > 0);
    }

    protected function runApi(string $scriptName): array
    {
        return $this->runJsonScript('forms/api/' . $scriptName);
    }

    protected function runJsonScript(string $relativePath, array $getParams = [], array $postParams = []): array
    {
        $output = $this->runScript($relativePath, $getParams, $postParams);

        if (!is_string($output)) {
            throw new \RuntimeException('API did not return a usable response');
        }

        $decoded = json_decode($output, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Expected valid JSON but received: ' . $output);
        }

        return $decoded;
    }

    protected function runScript(string $relativePath, array $getParams = [], array $postParams = []): string
    {
        static::ensureTestConnection();

        $scriptPath = realpath(__DIR__ . '/../../' . $relativePath);
        if ($scriptPath === false) {
            throw new \RuntimeException(sprintf('Unable to resolve script path: %s', $relativePath));
        }

        $this->prepareSessionData();

        $previousGet = $_GET;
        $previousPost = $_POST;
        $_GET = $getParams;
        $_POST = $postParams;

        $previousDirectory = getcwd();
        $scriptDirectory = dirname($scriptPath);
        if ($scriptDirectory !== false) {
            chdir($scriptDirectory);
        }

        ob_start();
        try {
            require $scriptPath;
        } finally {
            $output = ob_get_clean();
            $_GET = $previousGet;
            $_POST = $previousPost;
            if ($previousDirectory !== false) {
                chdir($previousDirectory);
            }

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            static::$testConn = null;
            static::applyConnectionToSecurityConfig(null);
        }

        return $output;
    }

    protected static function generateSessionId(): string
    {
        try {
            $randomSuffix = bin2hex(random_bytes(5));
        } catch (\Throwable $e) {
            $randomSuffix = bin2hex(openssl_random_pseudo_bytes(5));
        }

        return static::SESSION_ID_PREFIX . '-' . $randomSuffix;
    }

    protected function setSessionRole(string $role): void
    {
        $this->sessionRole = $role;
    }

    protected function insertRollEntry(string $reference): void
    {
        $conn = static::ensureTestConnection();
        $stmt = $conn->prepare("
            INSERT INTO roll_entry (
                date_time,
                operator_id,
                reference_number,
                project_id,
                gsm,
                line_no,
                fiber_type,
                roll_number,
                total_weight,
                batch_number
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $dateTime = (new \DateTime('now', new \DateTimeZone('Asia/Dhaka')))->format('Y-m-d H:i:s');
        $operatorId = 1;
        $projectId = 1;
        $gsm = 120;
        $lineNumber = 'L1';
        $fiberType = 'Test Fiber';
        $rollNumber = 1;
        $totalWeight = 10.5;
        $batchNumber = 1;

        $stmt->bind_param(
            'sisiissidi',
            $dateTime,
            $operatorId,
            $reference,
            $projectId,
            $gsm,
            $lineNumber,
            $fiberType,
            $rollNumber,
            $totalWeight,
            $batchNumber
        );
        $stmt->execute();

        static::$insertedRollIds[] = $stmt->insert_id;
        $stmt->close();
    }

    protected function cleanupInsertedEntries(): void
    {
        if (empty(static::$insertedRollIds)) {
            return;
        }

        $conn = static::ensureTestConnection();
        $ids = implode(',', array_map('intval', static::$insertedRollIds));
        $conn->query("DELETE FROM roll_entry WHERE id IN ($ids)");
        static::$insertedRollIds = [];
    }

    protected function cleanupTestData(): void
    {
        $conn = static::ensureTestConnection();

        if (!empty($this->insertedQcTestOrderIds)) {
            $ids = implode(',', array_map('intval', $this->insertedQcTestOrderIds));
            $conn->query("DELETE FROM qc_test_orders WHERE id IN ($ids)");
            $this->insertedQcTestOrderIds = [];
        }

        if (!empty($this->insertedRollQcReportIds)) {
            $ids = implode(',', array_map('intval', $this->insertedRollQcReportIds));
            $conn->query("DELETE FROM roll_qc_reports WHERE id IN ($ids)");
            $this->insertedRollQcReportIds = [];
        }

        if (!empty($this->insertedTestStandardIds)) {
            $ids = implode(',', array_map('intval', $this->insertedTestStandardIds));
            $conn->query("DELETE FROM test_standards WHERE id IN ($ids)");
            $this->insertedTestStandardIds = [];
        }
    }

    protected function insertTestStandard(string $testName, string $standardCode, string $product = ''): int
    {
        $conn = static::ensureTestConnection();
        $nextId = $this->getNextId($conn, 'test_standards');
        $stmt = $conn->prepare("
            INSERT INTO test_standards (id, product, test_name, standard_code, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param('isss', $nextId, $product, $testName, $standardCode);
        $stmt->execute();
        $stmt->close();

        $this->insertedTestStandardIds[] = $nextId;
        return $nextId;
    }

    protected function insertQcTestOrder(string $reportNumber, string $reference, int $testStandardId, string $chosenMethod, string $status = 'pending'): int
    {
        $conn = static::ensureTestConnection();
        $nextId = $this->getNextId($conn, 'qc_test_orders');
        $stmt = $conn->prepare("
            INSERT INTO qc_test_orders (id, report_number, status, sample_reference_id, test_standard_id, chosen_method, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->bind_param('isssis', $nextId, $reportNumber, $status, $reference, $testStandardId, $chosenMethod);
        $stmt->execute();
        $stmt->close();

        $this->insertedQcTestOrderIds[] = $nextId;
        return $nextId;
    }

    protected function insertRollQcReport(string $reference, string $rollNo, string $lineNo, string $overallStatus = 'pending'): int
    {
        $conn = static::ensureTestConnection();
        $nextId = $this->getNextId($conn, 'roll_qc_reports');
        $stmt = $conn->prepare("
            INSERT INTO roll_qc_reports (id, reference_number, roll_no, line_number, line_no, product_amount, gsm_check_status, length_calibration_status, overall_status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $productAmount = 0.00;
        $gsmStatus = 'pending';
        $lengthStatus = 'pending';
        $stmt->bind_param('issssdsss', $nextId, $reference, $rollNo, $lineNo, $lineNo, $productAmount, $gsmStatus, $lengthStatus, $overallStatus);
        $stmt->execute();
        $stmt->close();

        $this->insertedRollQcReportIds[] = $nextId;
        return $nextId;
    }

    protected function getNextId(\mysqli $conn, string $table): int
    {
        $result = $conn->query("SELECT IFNULL(MAX(id), 0) + 1 AS next_id FROM `$table`");
        if (!$result) {
            throw new \RuntimeException("Unable to determine next id for $table");
        }
        $row = $result->fetch_assoc();
        return (int)($row['next_id'] ?? 1);
    }
}
