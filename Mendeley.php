<?php

class Mendeley {

	/**
	 * @var self
	 */
	private static $instance;

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

		// Token has expired: oauth/TOKEN_EXPIRED
		// This is necessary because we don't know initial token issue timestamp
		if ( isset( $result['errorId'] ) ) {
			// refresh token
			wfDebugLog( 'Mendeley', $result['message'] );
			$this->refreshAccessToken();
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
							$wikiPage->doEditContent( $content, "Importing document found in group" );
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
		if ( count( $wgMendeleyReplaceUnderscoresFields ) &&
			in_array( $property, $wgMendeleyReplaceUnderscoresFields )
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
			   $wgMendeleyToken, $wgMemCachedServers, $wgObjectCaches;

		// test against $wgMendeleyToken to ensure we want to use the auth code flow
		if ( !empty( $wgMendeleyToken ) ) {
			if ( !count( $wgMemCachedServers ) && !isset( $wgObjectCaches['redis'] ) ) {
				throw new Exception(
					"The Mendeley extension is configured to use Authorization Code " .
					"flow but neither Memcached nor Redis cache is found!"
				);
			}
			return $this->getToken( 'access' );
		}
		$result = $this->httpRequest(
			"https://api.mendeley.com/oauth/token",
			"grant_type=client_credentials" .
			"&scope=all" .
			"&client_id=$wgMendeleyConsumerKey" .
			"&client_secret=$wgMendeleyConsumerSecret"
		);
		$status = FormatJson::parse( $result, FormatJson::FORCE_ASSOC );
		if ( !$status->isGood() ) {
			wfDebugLog( 'Mendeley', $status->getHTML() );
		}
		$result = $status->getValue();
		if ( empty( $result ) || isset( $result['errorId'] ) || isset( $result['message'] ) ) {
			wfDebugLog(
				'Mendeley',
				'ErrorId: ' . ( $result['errorId'] ?? 'unknown' ) . ', message: ' . ( $result['message'] ?? 'empty' )
			);
		}
		$access_token = $result['access_token'] ?? '';
		if ( !$access_token ) {
			wfDebugLog( 'Mendeley', 'access_token is not defined' );
		}
		return $access_token;
	}

	/**
	 * Refreshes access token and accuires new refresh token
	 * @return bool
	 */
	public function refreshAccessToken() {
		global $wgMendeleyRefreshToken, $wgMendeleyRedirectUrl,
			   $wgMendeleyConsumerKey, $wgMendeleyConsumerSecret;

		// check for refresh token setting presence to ensure it was initially set
		if ( !$wgMendeleyRefreshToken || !$wgMendeleyRedirectUrl ) {
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

	public function httpRequest( $url, $post = "", $headers = [], &$responseHeaders = [] ) {
		try {
			$ch = curl_init();
			// Change the user agent below suitably
			curl_setopt(
				$ch,
				CURLOPT_USERAGENT,
				'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.9) Gecko/20071025 Firefox/2.0.0.9'
			);
			curl_setopt( $ch, CURLOPT_URL, ( $url ) );
			curl_setopt( $ch, CURLOPT_ENCODING, "UTF-8" );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_COOKIESESSION, false );
			curl_setopt( $ch, CURLOPT_TIMEOUT, 20 );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
			# curl_setopt($ch, CURLOPT_VERBOSE, 1);
			curl_setopt( $ch, CURLOPT_HEADER, 1 );

			if ( !empty( $post ) ) {
				curl_setopt( $ch, CURLOPT_POSTFIELDS, $post );
				curl_setopt( $ch, CURLOPT_POST, 1 );
			}
			if ( !empty( $headers ) ) {
				curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
			}
			$response = curl_exec( $ch );

			if ( !$response ) {
				throw new Exception( "Error getting data from server: " . curl_error( $ch ) );
			}
			$header_size = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
			$responseHeaders = explode( "\r\n", substr( $response, 0, $header_size ) );
			$body = substr( $response, $header_size );

			curl_close( $ch );
		} catch ( Exception $e ) {
			echo 'Caught exception: ', $e->getMessage(), "\n";
			return null;
		}
		return $body;
	}

}
