<?php

class Mendeley {

	/**
	 * @var self
	 */
	private static $instance;
	private $tokenFails = 0;
	/** @var array|null Last token endpoint response (for debug), access_token masked */
	public $lastTokenResponse = null;
	/** @var string Source of last token: 'cache', 'config', 'db', 'client_credentials' */
	public $mLastTokenSource = '';

	private const CACHE_TTL_SEC = 3600;
	private const OAUTH_TOKENS_TABLE = 'mendeley_oauth_tokens';

	/**
	 * @return self
	 */
	public static function getInstance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Imports a group from Mendeley
	 *
	 * @param string $group_id
	 * @param null $actorId
	 * @param bool $dryRun
	 *
	 * @return Title[]
	 * @throws MWContentSerializationException
	 * @throws MWException
	 */
	public function importGroup( $group_id, $actorId = null, $dryRun = false ) {
		global $wgMendeleyTemplate,
			   $wgMendeleyTemplateFields,
			   $wgMendeleyTemplateFieldsMapDelimiter,
			   $wgMendeleyPageFormula,
			   $wgMendeleyFieldValuesDelimiter,
			   $wgMendeleyOverwriteTemplateOnly,
			   $wgMendeleyImportPageLimit,
			   $wgMendeleyUseJobs;

		$pagesLinks = [];
		$access_token = $this->getAccessToken();
		$responseHeaders = [];
		$result = $this->httpRequest(
			"https://api.mendeley.com/documents" .
			"?access_token=$access_token" .
			"&group_id=$group_id" .
			"&view=all" .
			"&limit=$wgMendeleyImportPageLimit",
			'',
			[],
			$responseHeaders
		);

		$status = FormatJson::parse( $result, FormatJson::FORCE_ASSOC );
		if ( !$status->isOK() ) {
			throw new Exception( $status->getHTML() );
		}
		if ( !$status->isGood() ) {
			wfDebugLog( 'Mendeley', $status->getHTML() );
		}
		$result = $status->getValue();

		// Token has expired or invalid (e.g. 401)
		if ( isset( $result['errorId'] ) ) {
			wfDebugLog( 'Mendeley', $result['message'] ?? 'API error' );
			if ( $this->mLastTokenSource === 'db' ) {
				$this->markStoredTokensInvalid();
			} elseif ( $this->mLastTokenSource === 'config' ) {
				$this->refreshAccessToken();
			}
			$access_token = $this->getAccessToken();
			$responseHeaders = [];
			$result = $this->httpRequest(
				"https://api.mendeley.com/documents" .
				"?access_token=$access_token" .
				"&group_id=$group_id" .
				"&view=all" .
				"&limit=$wgMendeleyImportPageLimit",
				'',
				[],
				$responseHeaders
			);

			$status = FormatJson::parse( $result, FormatJson::FORCE_ASSOC );
			if ( !$status->isOK() ) {
				throw new Exception( $status->getHTML() );
			}
			if ( !$status->isGood() ) {
				wfDebugLog( 'Mendeley', $status->getHTML() );
			}
			$result = $status->getValue();
		}

		// Fail after first refresh try or if token is not refreshable
		if ( !empty( $result['errorId'] ) || !empty( $result['message'] ) ) {
			throw new Exception(
				'ErrorId: ' . ( $result['errorId'] ?? 'unknown' ) . ', message: ' . ( $result['message'] ?? 'empty' )
			);
		}

		if ( count( $result ) ) {
			while ( true ) {
				foreach ( $result as $result_row ) {

					$appendProps = [];

					$row = $this->array_flatten( $result_row );
					$text = '{{' . $wgMendeleyTemplate . "\n";
					foreach ( $wgMendeleyTemplateFields as $property => $field ) {
						if ( !isset( $row[$property] ) ) {
							wfDebugLog( 'Mendeley', 'field ' . $property . ' not found!' );
							continue;
						}
						if ( strpos( $field, '@' ) === 0 ) {
							$field = str_replace( '@', '', $field );
							// special case for deep arrays
							if ( is_array( $row[$property] ) ) {
								if ( count( $row[$property] ) && is_array( $row[$property][0] ) ) {
									$text .= '|' . $field . '=' . $this->processValue(
										$property,
										implode(
											$wgMendeleyTemplateFieldsMapDelimiter,
											array_map( static function ( $item ) use (
												$wgMendeleyFieldValuesDelimiter
											) {
													return implode( $wgMendeleyFieldValuesDelimiter, $item );
											},
											$row[$property] ) )
									) . "\n";
								} else {
									$text .= '|' . $field . '=' . $this->processValue(
										$property,
										implode( $wgMendeleyTemplateFieldsMapDelimiter, $row[$property] )
									) . "\n";
								}
							} else {
								// fallback to normal processing
								$text .= '|' . $field . '=' . $row[$property] . "\n";
							}
						} elseif ( strpos( $field, '+' ) === 0 ) {
							// append different properties to the same field
							$fieldName = substr( $field, 0, strpos( $field, '[' ) );
							$fieldName = str_replace( '+', '', $fieldName );
							$appendProps[$fieldName] = $field;
						} else {
							$text .= '|' . $field . '=' . $this->processValue( $property, $row[$property] ) . "\n";
						}
					}

					if ( count( $appendProps ) ) {
						foreach ( $appendProps as $k => $v ) {
							$pattern = substr( $v, strpos( $v, '[' ) + 1 );
							$pattern = substr( $pattern, 0, strpos( $pattern, ']' ) );
							$value = preg_replace_callback( '/<([a-z]+)>/', static function ( $m ) use ( $row ) {
								if ( isset( $row[$m[1]] ) ) {
									return $row[$m[1]];
								}
								return '';
							}, $pattern );
							$text .= '|' . $k . '=' . $this->processValue( $property, $value ) . "\n";
						}
					}

					// TODO: fixme
					$dateprop = '';
					if ( !empty( $row['year'] ) ) {
						$dateprop .= $row['year'];
						if ( !empty( $row['month'] ) ) {
							$dateprop .= '-' . $row['month'];
							if ( !empty( $row['day'] ) ) {
								$dateprop .= '-' . $row['day'];
							}
						}
					}

					$text .= '|Date=' . $dateprop . "\n";

					$text .= '}}';

					$pagename = $result_row['id'];

					// Replace tokens in page formula
					if ( $wgMendeleyPageFormula ) {
						$keys = array_map( static function ( $key ) {
							return '<' . $key . '>';
						}, array_keys( $row ) );
						$replacements = array_map(
							static function ( $r ) use ( $wgMendeleyTemplateFieldsMapDelimiter ) {
								if ( is_array( $r ) ) {
									if ( !count( $r ) ) {
										return '';
									}
									if ( is_array( $r[0] ) ) {
										if ( !count( $r[0] ) ) {
											return '';
										}
										return implode( ' ', $r[0] );
									}
									return $r[0];
								}
								return $r;
							},
							array_values( $row )
						);
						$pagename = str_ireplace( $keys, $replacements, $wgMendeleyPageFormula );
					}

					$title = Title::newFromText( $pagename );
					if ( !$title ) {
						wfDebugLog( 'Mendeley', 'Cannot create the Title object for sting "' . $pagename . '"!' );
						continue;
					}
					$wikiPage = new WikiPage( $title );

					if ( $wgMendeleyOverwriteTemplateOnly && $wgMendeleyTemplate &&
						$wikiPage->exists() && $wikiPage->getContent()
					) {
						$curContent = $wikiPage->getContent()->getWikitextForTransclusion();
						if ( strpos( $curContent, '{{' . $wgMendeleyTemplate ) !== false ) {
							// Replace only the template contents
							$text = $this->replaceTemplateBraces( $curContent, $text );
						}
					}

					// Only modify content if this is not a dry-run
					if ( !$dryRun ) {
						// Edit target page or push job into queue
						if ( $wgMendeleyUseJobs ) {
							$job = new MendeleyImportJob(
								$title, [
									'text' => $text,
									'id' => $result_row['id'],
									'actor_id' => $actorId
								]
							);
							JobQueueGroup::singleton()->push( $job );
						} else {
							$content = ContentHandler::makeContent( $text, $title );
							$user = $actorId ? User::newFromId( $actorId ) : null;
							$wikiPage->doEditContent( $content, "Importing document found in group", 0, false, $user );
						}
					}

					$pagesLinks[] = $title;
				}
				$nextLink = $this->getPaginationLink( $responseHeaders );
				// @TODO: remove me!
				if ( $nextLink ) {
					$result = $this->httpRequest( $nextLink, '', [], $responseHeaders );
					if ( !$result ) {
						break;
					}
					// Decode the result and loop
					$status = FormatJson::parse( $result, FormatJson::FORCE_ASSOC );
					if ( !$status->isGood() ) {
						wfDebugLog( 'Mendeley', $status->getHTML() );
					}
					$result = $status->getValue();
					if ( isset( $result['errorId'] ) || isset( $result['message'] ) ) {
						wfDebugLog(
							'Mendeley',
							'ErrorId: ' . ( $result['errorId'] ?? 'unknown' ) .
								', message: ' . ( $result['message'] ?? 'empty' )
						);
						break;
					}
				} else {
					break;
				}
			}
		}

		return $pagesLinks;
	}

	private function replaceTemplateBraces( $text, $replacement ) {
		global $wgMendeleyTemplate;
		return preg_replace_callback(
			"/\{\{(([^\{\}]*|(?R))*)\}\}/",
			static function ( $matches ) use ( $wgMendeleyTemplate, $replacement ) {
				if ( strpos( $matches[0], "{{" . $wgMendeleyTemplate . "\n" ) === 0 ) {
					return $replacement;
				}
				return $matches[0];
			},
			$text
		);
	}

	private function getPaginationLink( array $responseHeaders, $rel = 'next' ) {
		foreach ( $responseHeaders as $value ) {
			if ( strncmp( $value, 'Link:', 5 ) === 0 ) {
				if ( preg_match( '/Link: <([^>]*)>.*rel="([^"]*)"/', $value, $matches ) ) {
					if ( $matches[2] === $rel ) {
						return $matches[1];
					}
				}
			}
		}
		return null;
	}

	private function processValue( $property, $value ) {
		global $wgMendeleyReplaceUnderscoresFields;
		if ( is_array( $wgMendeleyReplaceUnderscoresFields ?? null )
			&& count( $wgMendeleyReplaceUnderscoresFields ) > 0
			&& in_array( $property, $wgMendeleyReplaceUnderscoresFields, true )
		) {
			$value = str_replace( '_', ' ', $value );
		}
		return $value;
	}

	/**
	 * Flattens the multi-dimensional array, folds the prop names recursively to a/b/c..
	 *
	 * @param $array
	 * @param string $prefix
	 *
	 * @return array|false
	 */
	private function array_flatten( $array, $prefix = '' ) {
		if ( !is_array( $array ) ) {
			return false;
		}
		$result = [];
		foreach ( $array as $key => $value ) {
			if ( is_array( $value ) && $this->is_assoc( $value ) ) {
				$result = array_merge( $result, $this->array_flatten( $value, $key ) );
			} else {
				$result = array_merge( $result, [ ( $prefix ? $prefix . '/' : '' ) . $key => $value	] );
			}
		}
		return $result;
	}

	/**
	 * Tests if the array is an associative array
	 *
	 * @param array $arr
	 *
	 * @return bool
	 */
	private function is_assoc( array $arr ) {
		if ( array() === $arr ) {
			return false;
		}
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}

	public function getAccessToken() {
		global $wgMendeleyConsumerKey, $wgMendeleyConsumerSecret,
			   $wgMendeleyToken, $wgMendeleyRefreshToken, $wgMemCachedServers, $wgObjectCaches;

		$cache = wfGetCache( CACHE_ANYTHING );
		$keyAccess = wfMemcKey( 'mendeley_token_access' );
		$keyTs = wfMemcKey( 'mendeley_token_ts_access' );
		$cachedAccess = $cache->get( $keyAccess );
		$cachedTs = $cache->get( $keyTs );
		if ( $cachedAccess && $cachedTs && ( time() - (int)$cachedTs < self::CACHE_TTL_SEC ) ) {
			$this->mLastTokenSource = 'cache';
			return $cachedAccess;
		}

		if ( !empty( $wgMendeleyToken ) && !empty( $wgMendeleyRefreshToken ) ) {
			if ( !count( $wgMemCachedServers ) && !isset( $wgObjectCaches['redis'] ) ) {
				throw new Exception(
					"The Mendeley extension is configured to use Authorization Code " .
					"flow but neither Memcached nor Redis cache is found!"
				);
			}
			$this->mLastTokenSource = 'config';
			return $this->getToken( 'access' );
		}

		$stored = $this->getStoredTokensFromDB( true );
		if ( $stored ) {
			$tokens = $this->doRefreshWithRefreshToken( $stored['refresh_token'] );
			if ( $tokens ) {
				$this->setStoredTokensInDB( $tokens['access_token'], $tokens['refresh_token'], 1 );
				$this->mLastTokenSource = 'db';
				return $tokens['access_token'];
			}
			$responseHeaders = [];
			$testResult = $this->httpRequest(
				"https://api.mendeley.com/documents?limit=1&access_token=" . urlencode( $stored['access_token'] ),
				'',
				[],
				$responseHeaders
			);
			$testStatus = $testResult ? FormatJson::parse( $testResult, FormatJson::FORCE_ASSOC ) : null;
			$testDecoded = ( $testStatus && $testStatus->isOK() ) ? $testStatus->getValue() : null;
			$is401 = ( $testDecoded && isset( $testDecoded['errorId'] ) );
			if ( !$is401 && $testResult !== null ) {
				$this->setStoredTokensInDB( $stored['access_token'], $stored['refresh_token'], 1 );
				$this->mLastTokenSource = 'db';
				return $stored['access_token'];
			}
			$this->markStoredTokensInvalid();
		}

		$this->mLastTokenSource = 'client_credentials';
		$postBody = "grant_type=client_credentials" .
			"&scope=all" .
			"&client_id=$wgMendeleyConsumerKey" .
			"&client_secret=$wgMendeleyConsumerSecret";
		$result = $this->httpRequest(
			"https://api.mendeley.com/oauth/token",
			$postBody
		);
		$status = FormatJson::parse( $result, FormatJson::FORCE_ASSOC );
		$decoded = $status->isOK() ? $status->getValue() : null;
		$this->lastTokenResponse = $decoded ?: [ '_raw' => $result ];
		$this->lastTokenResponse['request_debug'] = [
			'client_id_in_request' => (string)$wgMendeleyConsumerKey,
			'body_has_client_id' => strpos( $postBody, 'client_id=' ) !== false,
			'body_length' => strlen( $postBody ),
		];
		if ( is_array( $this->lastTokenResponse ) && isset( $this->lastTokenResponse['access_token'] ) ) {
			$this->lastTokenResponse['access_token'] = '***' . substr( $this->lastTokenResponse['access_token'], -4 );
		}
		if ( !$decoded || !isset( $decoded['access_token'] ) ) {
			$msg = $status->isOK() ? ( is_array( $decoded ) ? ( $decoded['message'] ?? $decoded['error'] ?? FormatJson::encode( $decoded ) ) : $result ) : $status->getHTML();
			throw new Exception( 'Mendeley token endpoint failed: ' . $msg );
		}
		return $decoded['access_token'];
	}

	/**
	 * Refreshes access token and accuires new refresh token
	 * @return bool
	 */
	public function refreshAccessToken() {
		global $wgMendeleyRefreshToken, $wgMendeleyConsumerKey, $wgMendeleyConsumerSecret;

		// check for refresh token setting presence to ensure it was initially set
		if ( !$wgMendeleyRefreshToken ) {
			return false;
		}

		$result = $this->httpRequest(
			"https://api.mendeley.com/oauth/token",
			"grant_type=refresh_token&refresh_token="
			. $this->getToken( 'refresh' )
			. '&client_id='
			. $wgMendeleyConsumerKey
			. '&client_secret=' . $wgMendeleyConsumerSecret
		);
		$status = FormatJson::parse( $result, FormatJson::FORCE_ASSOC );
		if ( !$status->isOK() ) {
			throw new Exception( $status->getHTML() );
		}
		if ( !$status->isGood() ) {
			wfDebugLog( 'Mendeley', $status->getHTML() );
		}
		$result = $status->getValue();
		if ( empty( $result ) || isset( $result['errorId'] ) || isset( $result['message'] ) ) {
			throw new Exception(
				'ErrorId: ' . ( $result['errorId'] ?? 'unknown' ) . ', message: ' . ( $result['message'] ?? 'empty' )
			);
		}
		$access_token = $result['access_token'] ?? null;
		if ( !$access_token ) {
			wfDebugLog( 'Mendeley', 'access_token is empty' );
		}
		$refresh_token = $result['refresh_token'] ?? null;
		if ( !$refresh_token ) {
			wfDebugLog( 'Mendeley', 'refresh_token is empty' );
		}

		$this->setToken( $access_token, 'access' );
		$this->setToken( $refresh_token, 'refresh' );

		return true;
	}

	public function getToken( $token = 'access' ) {
		global $wgMendeleyRefreshToken, $wgMendeleyToken;

		$cache = wfGetCache( CACHE_ANYTHING );
		$key = wfMemcKey( 'mendeley_token_' . $token );
		$keyTs = wfMemcKey( 'mendeley_token_ts_' . $token );
		$ts = $cache->get( $keyTs );
		$result = $cache->get( $key );
		if ( $result ) {
			if ( $token == 'access' && $ts && time() - $ts >= 3600 ) {
				$this->refreshAccessToken();
				return $this->getToken( $token );
			}
			return $result;
		}
		$token = $token == 'access' ? $wgMendeleyToken : $wgMendeleyRefreshToken;
		$cache->set( $key, $token );
		// We don't know initial token TS so not using setToken
		return $token;
	}

	public function setToken( $value, $token = 'access' ) {
		$cache = wfGetCache( CACHE_ANYTHING );
		$key = wfMemcKey( 'mendeley_token_' . $token );
		$keyTs = wfMemcKey( 'mendeley_token_ts_' . $token );
		$cache->set( $key, $value );
		$cache->set( $keyTs, time() );
	}

	/**
	 * Read stored OAuth tokens from DB (singleton row).
	 *
	 * @param bool $includeInvalid If true, return row even when moa_valid = 0
	 * @return array|null [ 'access_token', 'refresh_token', 'updated', 'valid' ] or null
	 */
	public function getStoredTokensFromDB( $includeInvalid = false ) {
		$db = wfGetDB( DB_REPLICA );
		$conds = [];
		if ( !$includeInvalid ) {
			$conds['moa_valid'] = 1;
		}
		$row = $db->selectRow(
			self::OAUTH_TOKENS_TABLE,
			[ 'moa_access_token', 'moa_refresh_token', 'moa_valid', 'moa_updated' ],
			$conds,
			__METHOD__
		);
		if ( !$row ) {
			return null;
		}
		return [
			'access_token' => $row->moa_access_token,
			'refresh_token' => $row->moa_refresh_token,
			'updated' => (int)$row->moa_updated,
			'valid' => (int)$row->moa_valid,
		];
	}

	/**
	 * Insert or update stored OAuth tokens in DB and optionally update access-token cache.
	 *
	 * @param string $access Access token
	 * @param string $refresh Refresh token
	 * @param int $valid 1 or 0
	 */
	public function setStoredTokensInDB( $access, $refresh, $valid = 1 ) {
		$db = wfGetDB( DB_MASTER );
		$row = $db->selectRow( self::OAUTH_TOKENS_TABLE, [ 'moa_id' ], [], __METHOD__ );
		$ts = (int)time();
		$rowData = [
			'moa_access_token' => $access,
			'moa_refresh_token' => $refresh,
			'moa_valid' => $valid ? 1 : 0,
			'moa_updated' => $ts,
		];
		if ( $row ) {
			$db->update( self::OAUTH_TOKENS_TABLE, $rowData, [ 'moa_id' => $row->moa_id ], __METHOD__ );
		} else {
			$db->insert( self::OAUTH_TOKENS_TABLE, array_merge( [ 'moa_id' => 1 ], $rowData ), __METHOD__ );
		}
		// Update access-token cache so next getAccessToken() can use cache
		$cache = wfGetCache( CACHE_ANYTHING );
		$cache->set( wfMemcKey( 'mendeley_token_access' ), $access, self::CACHE_TTL_SEC );
		$cache->set( wfMemcKey( 'mendeley_token_ts_access' ), $ts, self::CACHE_TTL_SEC );
	}

	/**
	 * Mark stored tokens as invalid (moa_valid = 0) and clear access-token cache.
	 * Does not delete the row; deletion is only via deleteStoredTokensFromDB() from Special page.
	 */
	public function markStoredTokensInvalid() {
		$db = wfGetDB( DB_MASTER );
		$db->update( self::OAUTH_TOKENS_TABLE, [ 'moa_valid' => 0 ], [ 'moa_id' => 1 ], __METHOD__ );
		$cache = wfGetCache( CACHE_ANYTHING );
		$cache->delete( wfMemcKey( 'mendeley_token_access' ) );
		$cache->delete( wfMemcKey( 'mendeley_token_ts_access' ) );
	}

	/**
	 * Delete the stored OAuth tokens row from DB. Only to be called from Special:MendeleyAuth "Delete".
	 */
	public function deleteStoredTokensFromDB() {
		$db = wfGetDB( DB_MASTER );
		$db->delete( self::OAUTH_TOKENS_TABLE, [ 'moa_id' => 1 ], __METHOD__ );
		$cache = wfGetCache( CACHE_ANYTHING );
		$cache->delete( wfMemcKey( 'mendeley_token_access' ) );
		$cache->delete( wfMemcKey( 'mendeley_token_ts_access' ) );
	}

	/**
	 * Refresh access token using a given refresh token (e.g. from DB).
	 *
	 * @param string $refreshToken
	 * @return array|null [ 'access_token', 'refresh_token' ] or null on failure
	 */
	public function doRefreshWithRefreshToken( $refreshToken ) {
		global $wgMendeleyConsumerKey, $wgMendeleyConsumerSecret;
		if ( !$refreshToken ) {
			return null;
		}
		$postBody = "grant_type=refresh_token&refresh_token=" . urlencode( $refreshToken )
			. "&client_id=" . urlencode( $wgMendeleyConsumerKey )
			. "&client_secret=" . urlencode( $wgMendeleyConsumerSecret );
		$result = $this->httpRequest( "https://api.mendeley.com/oauth/token", $postBody );
		$status = FormatJson::parse( $result, FormatJson::FORCE_ASSOC );
		$decoded = $status->isOK() ? $status->getValue() : null;
		if ( !$decoded || !isset( $decoded['access_token'] ) ) {
			return null;
		}
		return [
			'access_token' => $decoded['access_token'],
			'refresh_token' => isset( $decoded['refresh_token'] ) ? $decoded['refresh_token'] : $refreshToken,
		];
	}

	/**
	 * Exchange authorization code for access and refresh tokens.
	 *
	 * @param string $code Authorization code from Mendeley redirect
	 * @param string $redirectUri Redirect URI used in the authorize request
	 * @return array [ 'access_token' => ..., 'refresh_token' => ... ] or throw
	 */
	public function exchangeCodeForTokens( $code, $redirectUri ) {
		global $wgMendeleyConsumerKey, $wgMendeleyConsumerSecret;
		$postBody = http_build_query( [
			'grant_type' => 'authorization_code',
			'code' => $code,
			'redirect_uri' => $redirectUri,
			'client_id' => $wgMendeleyConsumerKey,
			'client_secret' => $wgMendeleyConsumerSecret,
		] );
		$result = $this->httpRequest( 'https://api.mendeley.com/oauth/token', $postBody );
		$status = FormatJson::parse( $result, FormatJson::FORCE_ASSOC );
		$decoded = $status->isOK() ? $status->getValue() : null;
		$this->lastTokenResponse = $decoded ?: [ '_raw' => $result ];
		if ( !$decoded || !isset( $decoded['access_token'] ) ) {
			$msg = $status->isOK() ? ( is_array( $decoded ) ? ( $decoded['message'] ?? $decoded['error'] ?? FormatJson::encode( $decoded ) ) : $result ) : $status->getHTML();
			throw new Exception( 'Mendeley token exchange failed: ' . $msg );
		}
		return [
			'access_token' => $decoded['access_token'],
			'refresh_token' => isset( $decoded['refresh_token'] ) ? $decoded['refresh_token'] : '',
		];
	}

	public function httpRequest( $url, $post = "", $headers = [], &$responseHeaders = [] ) {
		$ch = curl_init();
		$userAgent = 'Mendeley-MediaWiki/' . ( defined( 'MW_VERSION' ) ? MW_VERSION : '0.2' );
		curl_setopt( $ch, CURLOPT_USERAGENT, $userAgent );
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_ENCODING, "UTF-8" );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_COOKIESESSION, false );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 20 );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
		curl_setopt( $ch, CURLOPT_HEADER, 1 );

		if ( !empty( $post ) ) {
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $post );
			curl_setopt( $ch, CURLOPT_POST, 1 );
		}
		if ( !empty( $headers ) ) {
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		}
		$response = curl_exec( $ch );

		if ( $response === false ) {
			wfDebugLog( 'Mendeley', 'HTTP request failed: ' . curl_error( $ch ) );
			curl_close( $ch );
			return null;
		}
		$header_size = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
		$responseHeaders = explode( "\r\n", substr( $response, 0, $header_size ) );
		$body = substr( $response, $header_size );
		curl_close( $ch );
		return $body;
	}

}
