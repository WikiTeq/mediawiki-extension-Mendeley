<?php
/**
 * Integration tests for Mendeley extension (DB token storage, cache, invalidation).
 *
 * Requires the mendeley_oauth_tokens table to exist. Run `php maintenance/update.php` once before running tests.
 *
 * @group Database
 * @group medium
 */
class MendeleyIntegrationTest extends MediaWikiIntegrationTestCase {

	protected $tablesUsed = [ 'mendeley_oauth_tokens' ];

	protected function setUp() : void {
		parent::setUp();
		// Start with no stored tokens and clean cache
		$mendeley = Mendeley::getInstance();
		$mendeley->deleteStoredTokensFromDB();
		ObjectCache::getInstance( CACHE_ANYTHING )->delete( wfMemcKey( 'mendeley_token_access' ) );
		ObjectCache::getInstance( CACHE_ANYTHING )->delete( wfMemcKey( 'mendeley_token_ts_access' ) );
	}

	/**
	 * @covers Mendeley::getStoredTokensFromDB
	 */
	public function testGetStoredTokensFromDBEmpty() {
		$mendeley = Mendeley::getInstance();
		$this->assertNull( $mendeley->getStoredTokensFromDB( false ) );
		$this->assertNull( $mendeley->getStoredTokensFromDB( true ) );
	}

	/**
	 * @covers Mendeley::setStoredTokensInDB
	 * @covers Mendeley::getStoredTokensFromDB
	 */
	public function testSetAndGetStoredTokensInDB() {
		$mendeley = Mendeley::getInstance();
		$mendeley->setStoredTokensInDB( 'access1', 'refresh1', 1 );

		$stored = $mendeley->getStoredTokensFromDB( false );
		$this->assertNotNull( $stored );
		$this->assertSame( 'access1', $stored['access_token'] );
		$this->assertSame( 'refresh1', $stored['refresh_token'] );
		$this->assertSame( 1, $stored['valid'] );
		$this->assertGreaterThan( 0, $stored['updated'] );

		$storedIncludeInvalid = $mendeley->getStoredTokensFromDB( true );
		$this->assertNotNull( $storedIncludeInvalid );
		$this->assertSame( 'access1', $storedIncludeInvalid['access_token'] );
	}

	/**
	 * @covers Mendeley::setStoredTokensInDB
	 * @covers Mendeley::getStoredTokensFromDB
	 */
	public function testSetStoredTokensInDBUpdate() {
		$mendeley = Mendeley::getInstance();
		$mendeley->setStoredTokensInDB( 'access1', 'refresh1', 1 );
		$first = $mendeley->getStoredTokensFromDB( true );
		$firstUpdated = $first['updated'];

		$mendeley->setStoredTokensInDB( 'access2', 'refresh2', 1 );
		$second = $mendeley->getStoredTokensFromDB( true );
		$this->assertSame( 'access2', $second['access_token'] );
		$this->assertSame( 'refresh2', $second['refresh_token'] );
		$this->assertGreaterThanOrEqual( $firstUpdated, $second['updated'] );
	}

	/**
	 * @covers Mendeley::markStoredTokensInvalid
	 * @covers Mendeley::getStoredTokensFromDB
	 */
	public function testMarkStoredTokensInvalid() {
		$mendeley = Mendeley::getInstance();
		$mendeley->setStoredTokensInDB( 'access1', 'refresh1', 1 );

		$this->assertNotNull( $mendeley->getStoredTokensFromDB( false ) );
		$mendeley->markStoredTokensInvalid();

		$this->assertNull( $mendeley->getStoredTokensFromDB( false ) );
		$stored = $mendeley->getStoredTokensFromDB( true );
		$this->assertNotNull( $stored );
		$this->assertSame( 0, $stored['valid'] );
		$this->assertSame( 'access1', $stored['access_token'] );
	}

	/**
	 * @covers Mendeley::deleteStoredTokensFromDB
	 * @covers Mendeley::getStoredTokensFromDB
	 */
	public function testDeleteStoredTokensFromDB() {
		$mendeley = Mendeley::getInstance();
		$mendeley->setStoredTokensInDB( 'access1', 'refresh1', 1 );
		$this->assertNotNull( $mendeley->getStoredTokensFromDB( true ) );

		$mendeley->deleteStoredTokensFromDB();
		$this->assertNull( $mendeley->getStoredTokensFromDB( false ) );
		$this->assertNull( $mendeley->getStoredTokensFromDB( true ) );
	}

	/**
	 * @covers Mendeley::setStoredTokensInDB
	 * @covers Mendeley::markStoredTokensInvalid
	 */
	public function testMarkStoredTokensInvalidClearsCache() {
		$mendeley = Mendeley::getInstance();
		$mendeley->setStoredTokensInDB( 'access1', 'refresh1', 1 );
		$cache = ObjectCache::getInstance( CACHE_ANYTHING );
		$this->assertSame( 'access1', $cache->get( wfMemcKey( 'mendeley_token_access' ) ) );

		$mendeley->markStoredTokensInvalid();
		$this->assertFalse( $cache->get( wfMemcKey( 'mendeley_token_access' ) ) );
	}

	/**
	 * @covers Mendeley::deleteStoredTokensFromDB
	 */
	public function testDeleteStoredTokensFromDBClearsCache() {
		$mendeley = Mendeley::getInstance();
		$mendeley->setStoredTokensInDB( 'access1', 'refresh1', 1 );
		$cache = ObjectCache::getInstance( CACHE_ANYTHING );
		$this->assertSame( 'access1', $cache->get( wfMemcKey( 'mendeley_token_access' ) ) );

		$mendeley->deleteStoredTokensFromDB();
		$this->assertFalse( $cache->get( wfMemcKey( 'mendeley_token_access' ) ) );
	}
}
