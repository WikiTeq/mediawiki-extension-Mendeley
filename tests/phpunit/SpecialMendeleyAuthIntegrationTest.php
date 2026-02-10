<?php
/**
 * Integration tests for Special:MendeleyAuth.
 *
 * @group Database
 * @group medium
 */
class SpecialMendeleyAuthIntegrationTest extends SpecialPageTestBase {

	protected $tablesUsed = [ 'mendeley_oauth_tokens' ];

	protected function setUp() : void {
		parent::setUp();
		// Grant mendeleyoauth to users so we can access the special page
		$this->mergeMwGlobalArrayValue( 'wgGroupPermissions', [
			'user' => [ 'mendeleyoauth' => true ],
		] );
	}

	/**
	 * @return SpecialMendeleyAuth
	 */
	protected function newSpecialPage() {
		return new SpecialMendeleyAuth();
	}

	/**
	 * @covers SpecialMendeleyAuth::execute
	 */
	public function testSpecialPageRequiresPermission() {
		$this->setMwGlobals( 'wgGroupPermissions', [
			'*' => [ 'read' => true, 'edit' => true ],
			'user' => [ 'read' => true, 'edit' => true ],
		] );
		// Revoke mendeleyoauth from everyone
		$GLOBALS['wgGroupPermissions']['user']['mendeleyoauth'] = false;

		$user = $this->getTestUser()->getUser();
		$page = new SpecialMendeleyAuth();
		$request = new FauxRequest();
		$context = new RequestContext();
		$context->setRequest( $request );
		$context->setUser( $user );
		$context->setTitle( SpecialPage::getTitleFor( 'MendeleyAuth' ) );
		$page->setContext( $context );

		$this->expectException( PermissionsError::class );
		$page->execute( null );
	}

	/**
	 * @covers SpecialMendeleyAuth::execute
	 */
	public function testSpecialPageShowsConfigErrorWhenConfigMissing() {
		$this->setMwGlobals( [
			'wgMendeleyConsumerKey' => null,
			'wgMendeleyConsumerSecret' => null,
		] );

		list( $html, ) = $this->executeSpecialPage(
			'',
			new FauxRequest(),
			'en',
			$this->getTestUser()->getUser()
		);

		$this->assertStringContainsString( 'not configured', $html );
		$this->assertStringContainsString( '$wgMendeleyConsumerKey', $html );
		$this->assertStringContainsString( 'Redirect URL', $html );
	}

	/**
	 * @covers SpecialMendeleyAuth::execute
	 */
	public function testSpecialPageShowsRedirectUrl() {
		$this->setMwGlobals( [
			'wgMendeleyConsumerKey' => null,
			'wgMendeleyConsumerSecret' => null,
		] );

		list( $html, ) = $this->executeSpecialPage(
			'',
			new FauxRequest(),
			'en',
			$this->getTestUser()->getUser()
		);

		$this->assertStringContainsString( 'Special:MendeleyAuth', $html );
	}

	/**
	 * @covers SpecialMendeleyAuth::execute
	 * @covers Mendeley::deleteStoredTokensFromDB
	 */
	public function testDeleteActionRemovesTokensFromDB() {
		$this->setMwGlobals( [
			'wgMendeleyConsumerKey' => 'test_key',
			'wgMendeleyConsumerSecret' => 'test_secret',
		] );

		$mendeley = Mendeley::getInstance();
		$mendeley->setStoredTokensInDB( 'test_access', 'test_refresh', 1 );
		$this->assertNotNull( $mendeley->getStoredTokensFromDB( true ) );

		$user = $this->getTestUser()->getUser();
		$request = new FauxRequest(
			[
				'action' => 'delete',
				'token' => $user->getEditToken(),
			],
			true
		);

		list( $html, ) = $this->executeSpecialPage(
			'',
			$request,
			'en',
			$user
		);

		$this->assertStringContainsString( 'deleted', $html );
		$this->assertNull( $mendeley->getStoredTokensFromDB( true ) );
	}
}
