<?php

class MendeleyHooks {

	/**
	 * Register database schema updates for mendeley_oauth_tokens table.
	 *
	 * @param DatabaseUpdater $updater
	 */
	public static function onLoadExtensionSchemaUpdates( $updater ) {
		$updater->addExtensionTable( 'mendeley_oauth_tokens', __DIR__ . '/sql/mendeley_oauth_tokens.sql' );
	}

	/**
	 * Register PHPUnit test paths for the extension.
	 *
	 * @param array &$paths List of test paths (files or directories)
	 */
	public static function onUnitTestsList( &$paths ) {
		$paths[] = __DIR__ . '/tests/phpunit';
	}

	/**
	 * Sets up the parser function
	 *
	 * @param Parser $parser
	 */
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setFunctionHook(
			'mendeley',
			'MendeleyHooks::mendeley'
		);
	}

	/**
	 * @param PFFormPrinter $pfFormPrinter
	 * @return void
	 */
	public static function onFormPrinterSetup( PFFormPrinter $pfFormPrinter ) {
		$pfFormPrinter->registerInputType( 'PFMendeleyInput' );
		$pfFormPrinter->registerInputType( 'PFMendeleyInputDOI' );
	}

	/**
	 * Handles the mendeley parser function
	 *
	 * @param Parser $parser Unused
	 * @return string
	 */
	public static function mendeley( Parser $parser ) {
		$options = self::extractOptions( array_slice( func_get_args(), 1 ) );

		$parameter = $options['parameter'];

		$mendeley = Mendeley::getInstance();

		$document_key = $options['doi'] ?? $options['id'];

		// CACHE_DB is slow but we can cache more items - which is likely what we want
		$cache_object = ObjectCache::getInstance( CACHE_DB );

		// Check cache first (use JSON instead of serialize for security)
		$cached = $cache_object->get( $document_key );
		$cacheProp = null;
		if ( $cached !== false ) {
			$status = FormatJson::parse( $cached, FormatJson::FORCE_ASSOC );
			$cacheProp = $status->isOK() ? $status->getValue() : null;
		}

		if ( $cacheProp && is_array( $cacheProp ) && !isset( $cacheProp['errorId'] ) ) {
			return self::getArrayElementFromPath( $cacheProp, $parameter );
		}
		$access_token = $mendeley->getAccessToken();

		$result = [];
		if ( isset( $options['doi'] ) ) {
			$raw = $mendeley->httpRequest( "https://api.mendeley.com/catalog?doi=" . urlencode( $options['doi'] ) . "&access_token=$access_token&view=all" );
			$status = FormatJson::parse( $raw, FormatJson::FORCE_ASSOC );
			if ( !$status->isGood() ) {
				wfDebugLog( 'Mendeley', $status->getHTML() );
			}
			$decoded = $status->isOK() ? $status->getValue() : null;
			$result = ( is_array( $decoded ) && isset( $decoded[0] ) ) ? $decoded[0] : ( $decoded ?: [] );
		} else {
			$raw = $mendeley->httpRequest( "https://api.mendeley.com/catalog/" . urlencode( $options['id'] ) . "?access_token=$access_token&view=all" );
			$status = FormatJson::parse( $raw, FormatJson::FORCE_ASSOC );
			if ( !$status->isGood() ) {
				wfDebugLog( 'Mendeley', $status->getHTML() );
			}
			$decoded = $status->isOK() ? $status->getValue() : null;
			$result = $decoded ?: [];
		}

		if ( empty( $result ) || isset( $result['errorId'] ) || isset( $result['message'] ) ) {
			wfDebugLog(
				'Mendeley',
				'ErrorId: ' . ( $result['errorId'] ?? 'unknown' ) . ', message: ' . ( $result['message'] ?? 'empty' )
			);
			return '';
		}

		// Store in Cache (use JSON instead of serialize for security)
		$cache_object->set( $document_key, FormatJson::encode( $result ), 5 * 24 * 60 * 60 );

		return self::getArrayElementFromPath( $result, $parameter );
	}

	/**
	 * Get an array element from a (potentially) muti-dimensional array based on a string path,
	 * with each array element separated by a delimiter
	 *
	 * Example: To access $array['stuff']['vehicles']['car'], the path would be 'stuff;vehicles;car'
	 *  (assuming the default delimiter)
	 *
	 * @param array $array
	 * @param string $path
	 * @param string $delimiter
	 * @return string
	 */
	private static function getArrayElementFromPath( array $array, $path, $delimiter = ';' ) {
		# http://stackoverflow.com/a/2951721
		$paths = explode( $delimiter, $path );
		foreach ( $paths as $index ) {
			if ( array_keys( $array ) === range( 0, count( $array ) - 1 ) ) {
				// if we have reached a numeric key just take the values from each array item,
				// concatenate and return it.
				$output = [];
				foreach ( $array as $array_item ) {
					if ( isset( $array_item[$index] ) ) {
						$output[] = $array_item[$index];
					}
				}
				return strip_tags( implode( ',', $output ) );
			} else {
				if ( isset( $array[$index] ) ) {
					$array = $array[$index];
				} else {
					return '';
				}
			}
		}
		return strip_tags( implode( ',', (array)$array ) );
	}

	/**
	 * @param array $options
	 * @return array
	 */
	public static function extractOptions( array $options ) {
		$results = [];

		foreach ( $options as $option ) {
			$pair = explode( '=', $option, 2 );
			if ( count( $pair ) === 2 ) {
				$name = trim( $pair[0] );
				$value = trim( $pair[1] );
				$results[$name] = $value;
			}

			if ( count( $pair ) === 1 ) {
				$name = trim( $pair[0] );
				$results[$name] = true;
			}
		}
		return $results;
	}

}
