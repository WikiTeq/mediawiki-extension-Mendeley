<?php

class MendeleyHooks {

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

		$cache = wfGetCache( CACHE_ANYTHING );
		$key = wfMemcKey( 'mendeley_document_' . $document_key );
		$cacheProp = unserialize( $cache->get( $key ) );

		if ( $cacheProp && !isset( $cacheProp['errorId'] ) ) {
			return self::getArrayElementFromPath( $cacheProp, $parameter );
		}
		$access_token = $mendeley->getAccessToken();

		if ( isset( $options['doi'] ) ) {
			$result = $mendeley->httpRequest(
				"https://api.mendeley.com/catalog?doi={$options['doi']}&access_token=$access_token&view=all"
			);
			$status = FormatJson::parse( $result, FormatJson::FORCE_ASSOC );
			if ( !$status->isGood() ) {
				wfDebugLog( 'Mendeley', $status->getHTML() );
			}
			$result = $status->getValue()[0] ?? $status->getValue();
		} else {
			$result = $mendeley->httpRequest(
				"https://api.mendeley.com/catalog/{$options['id']}?access_token=$access_token&view=all"
			);
			$status = FormatJson::parse( $result, FormatJson::FORCE_ASSOC );
			if ( !$status->isGood() ) {
				wfDebugLog( 'Mendeley', $status->getHTML() );
			}
			$result = $status->getValue();
		}

		if ( empty( $result ) || isset( $result['errorId'] ) || isset( $result['message'] ) ) {
			wfDebugLog(
				'Mendeley',
				'ErrorId: ' . ( $result['errorId'] ?? 'unknown' ) . ', message: ' . ( $result['message'] ?? 'empty' )
			);
			return '';
		}

		// Store in Cache
		$serialized = serialize( $result );
		$cache->set( $key, $serialized, 5 * 24 * 60 * 60 );

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
