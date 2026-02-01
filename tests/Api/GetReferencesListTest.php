<?php
declare(strict_types=1);

namespace Tests\Api;

require_once __DIR__ . '/ApiTestCase.php';

final class GetReferencesListTest extends ApiTestCase
{
    protected const SESSION_ID_PREFIX = 'phpunit-get-references-list';

    public function testReturnsSingleAndBundleReferences(): void
    {
        $singleReference = 'API-SINGLE-' . uniqid();
        $bundleBase = 'API-BUNDLE-' . uniqid();
        $bundleReferenceOne = $bundleBase . '-1';
        $bundleReferenceTwo = $bundleBase . '-2';

        $this->insertRollEntry($singleReference);
        $this->insertRollEntry($bundleReferenceOne);
        $this->insertRollEntry($bundleReferenceTwo);

        $payload = $this->runApi('get_references_list.php');

        $this->assertTrue($payload['success']);

        $availableSingleReferences = array_column($payload['references'], 'reference');
        $availableBundleReferences = array_column($payload['bundleReferences'], 'reference');

        $this->assertContains($singleReference, $availableSingleReferences);
        $this->assertContains($bundleReferenceOne, $availableBundleReferences);
        $this->assertContains($bundleReferenceTwo, $availableBundleReferences);
    }
}
