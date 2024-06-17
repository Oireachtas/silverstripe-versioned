<?php

namespace SilverStripe\Versioned\Tests;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Versioned\Tests\VersionedTest\TestObject;

/**
 * @internal Only test the right values are returned, not that the cache is actually used.
 */
class VersionedNumberCacheTest extends SapphireTest
{

    public static $extra_dataobjects = [
        VersionedTest\TestObject::class
    ];

    /**
     * @var int
     */
    private static $publishedID;

    /**
     * @var int
     */
    private static $draftOnlyID;

    private static $expectedVersions = [ ];


    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Initialise our dummy object
        $obj = TestObject::create(['Title' => 'Initial version']);
        $obj->write();
        VersionedNumberCacheTest::$publishedID = $obj->ID;

        // Create our live version
        $obj->Title = 'This will be our live version';
        $obj->write();
        $obj->publishSingle();
        $liveVersion = $obj->Version;

        // Create our draft version
        $obj->Title = 'This will be our draft version';
        $obj->write();
        $draftVersion = $obj->Version;

        // This object won't ne publish
        $draftOnly = TestObject::create(['Title' => 'Draft Only object']);
        $draftOnly->write();
        VersionedNumberCacheTest::$draftOnlyID = $draftOnly->ID;

        VersionedNumberCacheTest::$expectedVersions = [
            'liveVersion' => $liveVersion,
            'draftVersion' => $draftVersion,
            'null' => null
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        TestObject::singleton()->flushCache();
    }

    public function cacheDataProvider()
    {
        return [
            [Versioned::DRAFT, 'publishedID', false, 'draftVersion'],
            [Versioned::DRAFT, 'publishedID', true, 'draftVersion'],
            [Versioned::LIVE, 'publishedID', false, 'liveVersion'],
            [Versioned::LIVE, 'publishedID', true, 'liveVersion'],
            [Versioned::LIVE, 'draftOnlyID', false, 'null'],
            [Versioned::LIVE, 'draftOnlyID', true, 'null'],
        ];
    }


    /**
     * @dataProvider cacheDataProvider
     */
    public function testVersionNumberCache($stage, $ID, $cache, $expected)
    {
        $actual = Versioned::get_versionnumber_by_stage(TestObject::class, $stage, VersionedNumberCacheTest::${$ID}, $cache);
        $this->assertEquals(VersionedNumberCacheTest::$expectedVersions[$expected], $actual);

        if ($cache) {
            // When cahing is eanbled, try re-accessing version number to make sure the cache returns the same value
            $actual = Versioned::get_versionnumber_by_stage(TestObject::class, $stage, VersionedNumberCacheTest::${$ID}, $cache);
            $this->assertEquals(VersionedNumberCacheTest::$expectedVersions[$expected], $actual);
        }
    }

    /**
     * @dataProvider cacheDataProvider
     */
    public function testPrepopulatedVersionNumberCache($stage, $ID, $cache, $expected)
    {
        TestObject::singleton()->onPrepopulateTreeDataCache();
        $actual = Versioned::get_versionnumber_by_stage(TestObject::class, $stage, VersionedNumberCacheTest::${$ID}, $cache);
        $this->assertEquals(VersionedNumberCacheTest::$expectedVersions[$expected], $actual);
    }
}
