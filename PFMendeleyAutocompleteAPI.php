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
		$term = urlencode( $this->getMain()->getVal( 'term' ) );
		$mendeley = Mendeley::getInstance();
		$access_token = $mendeley->getAccessToken();

		$result = $mendeley->httpRequest(
			"https://api.mendeley.com/search/catalog?query==$term&access_token=$access_token&view=all&limit=20"
		);
		$status = FormatJson::parse( $result, FormatJson::FORCE_ASSOC );
		if ( !$status->isGood() ) {
			wfDebugLog( 'Mendeley', $status->getHTML() );
		}
		$result = $status->getValue();

		if ( empty( $result ) ) {
			$result = $mendeley->httpRequest(
				"https://api.mendeley.com/catalog?doi=$term&access_token=$access_token&view=all"
			);
			$status = FormatJson::parse( $result, FormatJson::FORCE_ASSOC );
			if ( !$status->isGood() ) {
				wfDebugLog( 'Mendeley', $status->getHTML() );
			}
			$result = $status->getValue() ?: [];
		}

		$return_arr = [];
		foreach ( $result as $row ) {
			$row_arr = [];
			$row_arr['id'] = $row['id'];
			$row_arr['label'] = strlen( $row['title'] ) > 50
				? substr( $row['title'], 0, 50 ) . "..."
				: $row['title'];
			$row_arr['value'] = $row['title'];
			$row_arr['type'] = $row['type'];
			$row_arr['year'] = $row['year'];
			$row_arr['source'] = $row['source'];
			$row_arr['issn'] = $row['identifiers']['issn'];
			$row_arr['sgr'] = $row['identifiers']['sgr'];
			$row_arr['doi'] = $row['identifiers']['doi'];
			$row_arr['isbn'] = $row['identifiers']['isbn'];
			$row_arr['pmid'] = $row['identifiers']['pmid'];
			$row_arr['arxiv'] = $row['identifiers']['arxiv'];
			$row_arr['scopus'] = $row['identifiers']['scopus'];
			$row_arr['pui'] = $row['identifiers']['pui'];
			$row_arr['abstract'] = $row['abstract'];
			$row_arr['mendeley_link'] = $row['link'];
			$row_arr['month'] = $row['month'];
			$row_arr['day'] = $row['day'];
			$row_arr['revision'] = $row['revision'];
			$row_arr['pages'] = $row['pages'];
			$row_arr['volume'] = $row['volume'];
			$row_arr['issue'] = $row['issue'];
			$row_arr['websites'] = $row['websites'];
			$row_arr['publisher'] = $row['publisher'];
			$row_arr['city'] = $row['city'];
			$row_arr['edition'] = $row['edition'];
			$row_arr['institution'] = $row['institution'];
			$row_arr['series'] = $row['series'];
			$row_arr['chapter'] = $row['chapter'];
			$row_arr['language'] = $row['language'];
			$row_arr['genre'] = $row['genre'];
			$row_arr['country'] = $row['country'];
			$row_arr['department'] = $row['department'];
			$authors = [];
			foreach ( $row['authors'] as $author ) {
				$authors[] = $author['first_name'] . ' ' . $author['last_name'];
			}
			$row_arr['authors'] = implode( ', ', $authors );
			$return_arr[] = $row_arr;
		}
		$this->getResult()->addValue( 'result', "autocomplete_results", $return_arr );
	}
}
