<?php

class SpecialMendeleyAuth extends SpecialPage {

	private const SESSION_STATE_KEY = 'MendeleyOAuthState';

	public function __construct() {
		parent::__construct( 'MendeleyAuth', 'mendeleyoauth' );
	}

	/**
	 * @param string|null $par
	 */
	public function execute( $par ) {
		$this->setHeaders();
		$request = $this->getRequest();
		$out = $this->getOutput();

		if ( !$this->userCanExecute( $this->getUser() ) ) {
			$this->displayRestrictionError();
			return;
		}

		global $wgMendeleyConsumerKey, $wgMendeleyConsumerSecret;
		$missing = [];
		if ( !$wgMendeleyConsumerKey ) {
			$missing[] = '$wgMendeleyConsumerKey';
		}
		if ( !$wgMendeleyConsumerSecret ) {
			$missing[] = '$wgMendeleyConsumerSecret';
		}
		if ( $missing ) {
			$out->addHTML( '<p class="error">' . $this->msg( 'mendeleyauth-config-missing' )
				->params( $this->getLanguage()->listToText( $missing ) )->escaped() . '</p>' );
			$this->showStatusAndActions();
			return;
		}

		$code = $request->getVal( 'code' );
		$state = $request->getVal( 'state' );
		if ( $code !== null && $state !== null ) {
			$this->handleCallback( $code, $state );
			return;
		}

		$action = $request->getVal( 'action' );
		if ( $action && $request->wasPosted() && $this->getUser()->matchEditToken( $request->getVal( 'token' ) ) ) {
			if ( $action === 'delete' ) {
				$this->handleDelete();
				return;
			}
			if ( $action === 'refresh' ) {
				$this->handleRefresh();
				return;
			}
			if ( $action === 'check' ) {
				$this->handleCheck();
				return;
			}
		}

		$this->showStatusAndActions();
	}

	private function getRedirectUrl() {
		$title = SpecialPage::getTitleFor( 'MendeleyAuth' );
		return $title->getCanonicalURL();
	}

	private function handleCallback( $code, $state ) {
		$session = $this->getRequest()->getSession();
		$storedState = $session->get( self::SESSION_STATE_KEY );
		$session->remove( self::SESSION_STATE_KEY );
		if ( $storedState === null || $storedState !== $state ) {
			$this->getOutput()->addHTML( '<p class="error">' . $this->msg( 'mendeleyauth-state-mismatch' )->escaped() . '</p>' );
			$this->showStatusAndActions();
			return;
		}
		$mendeley = Mendeley::getInstance();
		try {
			$tokens = $mendeley->exchangeCodeForTokens( $code, $this->getRedirectUrl() );
			$mendeley->setStoredTokensInDB( $tokens['access_token'], $tokens['refresh_token'], 1 );
			$this->getOutput()->addHTML( '<p class="success">' . $this->msg( 'mendeleyauth-connected' )->escaped() . '</p>' );
		} catch ( Exception $e ) {
			$this->getOutput()->addHTML( '<p class="error">' . htmlspecialchars( $e->getMessage() ) . '</p>' );
		}
		$this->showStatusAndActions();
	}

	private function handleDelete() {
		Mendeley::getInstance()->deleteStoredTokensFromDB();
		$this->getOutput()->addHTML( '<p class="success">' . $this->msg( 'mendeleyauth-deleted' )->escaped() . '</p>' );
		$this->showStatusAndActions();
	}

	private function handleRefresh() {
		$mendeley = Mendeley::getInstance();
		$stored = $mendeley->getStoredTokensFromDB( true );
		if ( !$stored || !$stored['refresh_token'] ) {
			$this->getOutput()->addHTML( '<p class="error">' . $this->msg( 'mendeleyauth-no-tokens' )->escaped() . '</p>' );
			$this->showStatusAndActions();
			return;
		}
		$tokens = $mendeley->doRefreshWithRefreshToken( $stored['refresh_token'] );
		if ( $tokens ) {
			$mendeley->setStoredTokensInDB( $tokens['access_token'], $tokens['refresh_token'], 1 );
			$this->getOutput()->addHTML( '<p class="success">' . $this->msg( 'mendeleyauth-refreshed' )->escaped() . '</p>' );
		} else {
			$mendeley->markStoredTokensInvalid();
			$this->getOutput()->addHTML( '<p class="error">' . $this->msg( 'mendeleyauth-refresh-failed' )->escaped() . '</p>' );
		}
		$this->showStatusAndActions();
	}

	private function handleCheck() {
		$mendeley = Mendeley::getInstance();
		$stored = $mendeley->getStoredTokensFromDB( true );
		if ( !$stored ) {
			$this->getOutput()->addHTML( '<p class="error">' . $this->msg( 'mendeleyauth-no-tokens' )->escaped() . '</p>' );
			$this->showStatusAndActions();
			return;
		}
		$responseHeaders = [];
		$result = $mendeley->httpRequest(
			"https://api.mendeley.com/documents?limit=1&access_token=" . urlencode( $stored['access_token'] ),
			'',
			[],
			$responseHeaders
		);
		$status = $result ? FormatJson::parse( $result, FormatJson::FORCE_ASSOC ) : null;
		$decoded = ( $status && $status->isOK() ) ? $status->getValue() : null;
		$is401 = $decoded && isset( $decoded['errorId'] );
		if ( $is401 ) {
			$mendeley->markStoredTokensInvalid();
			$this->getOutput()->addHTML( '<p class="error">' . $this->msg( 'mendeleyauth-check-invalid' )->escaped() . '</p>' );
		} else {
			$this->getOutput()->addHTML( '<p class="success">' . $this->msg( 'mendeleyauth-check-ok' )->escaped() . '</p>' );
		}
		$this->showStatusAndActions();
	}

	private function showStatusAndActions() {
		$out = $this->getOutput();
		$mendeley = Mendeley::getInstance();
		$stored = $mendeley->getStoredTokensFromDB( true );

		$out->addHTML( '<h2>' . $this->msg( 'mendeleyauth-status' )->escaped() . '</h2>' );
		if ( $stored ) {
			$validMsg = $stored['valid'] ? 'mendeleyauth-valid' : 'mendeleyauth-invalid';
			$out->addHTML( '<p>' . $this->msg( 'mendeleyauth-tokens-stored' )->escaped() . ' ' .
				$this->msg( $validMsg )->escaped() . ' ' .
				$this->msg( 'mendeleyauth-updated', [ $this->getLanguage()->userTimeAndDate( wfTimestamp( TS_MW, $stored['updated'] ), $this->getUser() ) ] )->escaped() . '</p>' );
		} else {
			$out->addHTML( '<p>' . $this->msg( 'mendeleyauth-no-tokens' )->escaped() . '</p>' );
		}

		$redirectUrl = $this->getRedirectUrl();
		$out->addHTML( '<h3>' . $this->msg( 'mendeleyauth-redirect-url' )->escaped() . '</h3>' );
		$out->addHTML( '<p><code>' . htmlspecialchars( $redirectUrl ) . '</code></p>' );
		$out->addHTML( '<p>' . $this->msg( 'mendeleyauth-redirect-instruction' )->escaped() . '</p>' );
		$out->addHTML( '<p>' );
		$out->addWikiMsg( 'mendeleyauth-register-app' );
		$out->addHTML( '</p>' );

		global $wgMendeleyConsumerKey;
		$connectUrl = '';
		if ( $wgMendeleyConsumerKey ) {
			$state = bin2hex( random_bytes( 16 ) );
			$this->getRequest()->getSession()->set( self::SESSION_STATE_KEY, $state );
			$connectUrl = 'https://api.mendeley.com/oauth/authorize?' . http_build_query( [
				'client_id' => $wgMendeleyConsumerKey,
				'redirect_uri' => $redirectUrl,
				'response_type' => 'code',
				'scope' => 'all',
				'state' => $state,
			] );
		}

		$title = $this->getPageTitle();
		$out->addHTML( '<form method="post" action="' . htmlspecialchars( $title->getLocalURL() ) . '">' );
		$out->addHTML( Html::hidden( 'token', $this->getUser()->getEditToken() ) );
		$out->addHTML( '<p>' );
		if ( $connectUrl ) {
			$out->addHTML( '<a href="' . htmlspecialchars( $connectUrl ) . '" class="mw-ui-button mw-ui-progressive">' .
				$this->msg( 'mendeleyauth-connect' )->escaped() . '</a> ' );
		}
		$out->addHTML( '<button type="submit" name="action" value="refresh" class="mw-ui-button">' .
			$this->msg( 'mendeleyauth-refresh' )->escaped() . '</button> ' );
		$out->addHTML( '<button type="submit" name="action" value="check" class="mw-ui-button">' .
			$this->msg( 'mendeleyauth-check' )->escaped() . '</button> ' );
		$out->addHTML( '<button type="submit" name="action" value="delete" class="mw-ui-button mw-ui-destructive">' .
			$this->msg( 'mendeleyauth-delete' )->escaped() . '</button>' );
		$out->addHTML( '</p></form>' );
	}

}
