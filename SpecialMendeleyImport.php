<?php

class SpecialMendeleyImport extends SpecialPage {

	public function __construct() {
		parent::__construct( 'MendeleyImport', 'mendeleyimport' );
	}

	/**
	 */
	public function execute( $par ) {
		$this->setHeaders();
		$this->checkReadOnly();
		$request = $this->getRequest();
		$out = $this->getOutput();

		$group_id = $request->getVal( "mendeley_group_id", $par );
		$dry = $request->getCheck( 'mendeley_dry' );

		$formOpts = [
			'id' => 'menedeley_import',
			'method' => 'post',
			"enctype" => "multipart/form-data",
			'action' => $out->getTitle()->getFullUrl(),
		];

		$out->addHTML(
			Html::openElement( 'form', $formOpts ) . "<br>" .
			Html::label( "Enter Mendeley Group ID","", array( "for" => "mendeley_group_id" ) ) . "<br>" .
			Html::element( 'input', array( "id" => "mendeley_group_id", "name" => "mendeley_group_id", "type" => "text", "value" => $group_id, "size" => 100 ) ) . "<br>" .
			Html::rawElement(
				'p',
				array(),
				Html::element( 'input', array( "id" => "mendeley_dry", "name" => "mendeley_dry", "type" => "checkbox", "value" => "1", "checked" => "checked" ) ) .
				Html::element( 'label', array( "for" => "mendeley_dry" ), "Dry run" )
			) . "<br><br>"
		);

		$out->addHTML(
			Html::hidden( 'wptoken', $this->getContext()->getCsrfTokenSet()->getToken()->toString() ) .
			Html::submitButton( "Submit", array() ) .
			Html::closeElement( 'form' )
		);

		if ( $group_id && $request->wasPosted() ) {
			if ( !$this->getContext()->getCsrfTokenSet()->matchesToken(
				$request->getVal( 'wptoken' )
			) ) {
				throw new PermissionsError( 'mendeleyimport' );
			}
			$this->handleImport( $group_id, $dry );
		}
	}

	public function handleImport( $group_id, $dry = false ) {
		global $wgMendeleyUseJobs;
		$pages = Mendeley::getInstance()->importGroup( $group_id, $this->getUser()->getId(), $dry );
		$out = $this->getOutput();
		if ( count($pages) > 0 ) {
			$out->addHTML( Html::openElement('ul') );
			foreach ($pages as $pl) {
				$out->addHTML( Html::rawElement( 'li', array(), Linker::link($pl) ) );
			}
			$out->addHTML( Html::closeElement('ul') );
			if ( $dry ) {
				$out->addHTML( "This was a dry run, nothing will be imported" );
			} else {
				if ( $wgMendeleyUseJobs ) {
					$out->addHTML(
						"Successfully scheduled " . count( $pages ) . " pages for import. " .
						"Please wait for the jobs to be processed or run runJobs.php maintenance " . "script by hand."
					);
				} else {
					$out->addHTML( "Successfully created/updated " . count( $pages ) . " pages" );
				}
			}
		} else {
			$out->addHTML( "Invalid result" );
		}
	}

}
