<?php

/**
 * Provides autocomplete for Mendeley documents.
 *
 *
 * @author Nischay Nahata
 */
class PFMendeleyAutocompleteAPI extends ApiBase {

	public function __construct( $query, $moduleName ) {
		parent::__construct( $query, $moduleName );
	}

	public function execute() {
		$this->checkUserRightsAny( 'read' );
		$term = urlencode( $this->getMain()->getVal( 'term' ) );

		$mendeley = Mendeley::getInstance();

		try {
			$access_token = $mendeley->getAccessToken();
		} catch ( Exception $e ) {
			$debugInfo = [
				'token_error' => $e->getMessage(),
				'last_token_response' => $mendeley->lastTokenResponse,
			];
			wfDebugLog( 'Mendeley', 'Autocomplete getAccessToken failed: ' . FormatJson::encode( $debugInfo ) );
			throw $e;
		}

		$rawSearch = $mendeley->httpRequest( "https://api.mendeley.com/search/catalog?query==$term&access_token=$access_token&view=all&limit=20" );
		$searchStatus = FormatJson::parse( $rawSearch, FormatJson::FORCE_ASSOC );
		$result = $searchStatus->isOK() ? $searchStatus->getValue() : null;
		if ( empty( $result ) ) {
			if ( !$searchStatus->isOK() ) {
				wfDebugLog( 'Mendeley', 'Autocomplete search parse failed: ' . $searchStatus->getHTML() . ' raw=' . substr( $rawSearch ?? '', 0, 500 ) );
			}
			$rawCatalog = $mendeley->httpRequest( "https://api.mendeley.com/catalog?doi=". $term ."&access_token=$access_token&view=all" );
			$catalogStatus = FormatJson::parse( $rawCatalog, FormatJson::FORCE_ASSOC );
			$result = $catalogStatus->isOK() ? $catalogStatus->getValue() : null;
			wfDebugLog( 'Mendeley', 'Autocomplete search empty, catalog fallback: term=' . $term .
				' catalog_ok=' . ( $catalogStatus->isOK() ? '1' : '0' ) . ' raw=' . substr( $rawCatalog ?? '', 0, 500 ) );
		}

		// Catalog by DOI returns a single document; search returns an array. Normalize to array.
		if ( $result !== null && isset( $result['id'] ) && isset( $result['title'] ) ) {
			$result = [ $result ];
		}
		// Skip Mendeley API error response (e.g. message, errorId) or null
		if ( !is_array( $result ) || isset( $result['message'] ) || isset( $result['errorId'] ) ) {
			wfDebugLog( 'Mendeley', 'Autocomplete Mendeley API error: ' . FormatJson::encode( $result ) );
			$result = [];
		}

		$return_arr = [];
		$identifiers = function ( $row, $key ) {
			$ids = $row['identifiers'] ?? [];
			return $ids[$key] ?? '';
		};
		foreach ( $result as $row ) {
			$title = $row['title'] ?? '';
			$authors = [];
			foreach ( $row['authors'] ?? [] as $author ) {
				$authors[] = ( $author['first_name'] ?? '' ) . ' ' . ( $author['last_name'] ?? '' );
			}
			$row_arr = [
				'id' => $row['id'] ?? '',
				'label' => strlen( $title ) > 50 ? substr( $title, 0, 50 ) . '...' : $title,
				'value' => $title,
				'type' => $row['type'] ?? '',
				'year' => $row['year'] ?? '',
				'source' => $row['source'] ?? '',
				'issn' => $identifiers( $row, 'issn' ),
				'sgr' => $identifiers( $row, 'sgr' ),
				'doi' => $identifiers( $row, 'doi' ),
				'isbn' => $identifiers( $row, 'isbn' ),
				'pmid' => $identifiers( $row, 'pmid' ),
				'arxiv' => $identifiers( $row, 'arxiv' ),
				'scopus' => $identifiers( $row, 'scopus' ),
				'pui' => $identifiers( $row, 'pui' ),
				'abstract' => $row['abstract'] ?? '',
				'mendeley_link' => $row['link'] ?? '',
				'month' => $row['month'] ?? '',
				'day' => $row['day'] ?? '',
				'revision' => $row['revision'] ?? '',
				'pages' => $row['pages'] ?? '',
				'volume' => $row['volume'] ?? '',
				'issue' => $row['issue'] ?? '',
				'websites' => $row['websites'] ?? '',
				'publisher' => $row['publisher'] ?? '',
				'city' => $row['city'] ?? '',
				'edition' => $row['edition'] ?? '',
				'institution' => $row['institution'] ?? '',
				'series' => $row['series'] ?? '',
				'chapter' => $row['chapter'] ?? '',
				'language' => $row['language'] ?? '',
				'genre' => $row['genre'] ?? '',
				'country' => $row['country'] ?? '',
				'department' => $row['department'] ?? '',
				'authors' => implode( ', ', $authors ),
			];
			$return_arr[] = $row_arr;
		}
		$this->getResult()->addValue( 'result', "autocomplete_results", $return_arr );
	}
}
