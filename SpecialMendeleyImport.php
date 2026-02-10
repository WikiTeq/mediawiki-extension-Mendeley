<?php

use MediaWiki\MediaWikiServices;

class SpecialMendeleyImport extends SpecialPage {
	public function __construct() {
		parent::__construct( 'MendeleyImport', 'mendeleyimport' );
	}

	/**
	 * @param string|null $par
	 */
	public function execute( $par ) {
		$this->setHeaders();
		$request = $this->getRequest();
		$out = $this->getOutput();

		$group_id = $request->getVal( "mendeley_group_id", $par );
		$dry = $request->getCheck( 'mendeley_dry' );

		$formOpts = [
			'id' => 'mendeley_import',
			'method' => 'post',
			'enctype' => 'multipart/form-data',
			'action' => $out->getTitle()->getFullUrl(),
		];

		$out->addHTML(
			Html::openElement( 'form', $formOpts ) . "<br>" .
			Html::label( $this->msg( 'mendeleyimport-group-label' )->text(), '', [ 'for' => 'mendeley_group_id' ] ) . "<br>" .
			Html::element( 'input', [
				'id' => 'mendeley_group_id',
				'name' => 'mendeley_group_id',
				'type' => 'text',
				'value' => $group_id,
				'size' => 100
			] ) . "<br>" .
			Html::rawElement(
				'p',
				[],
				Html::element( 'input', [
					'id' => 'mendeley_dry',
					'name' => 'mendeley_dry',
					'type' => 'checkbox',
					'value' => '1'
				] ) .
				Html::element( 'label', [ 'for' => 'mendeley_dry' ], $this->msg( 'mendeleyimport-dry-run' )->text() )
			) . "<br><br>"
		);

		$out->addHTML(
			Html::submitButton( $this->msg( 'mendeleyimport-submit' )->text(), [] ) .
			Html::closeElement( 'form' )
		);

		if ( $group_id ) {
			$this->handleImport( $group_id, $dry );
		}
	}

	public function handleImport( $group_id, $dry = false ) {
		global $wgMendeleyUseJobs;
		$out = $this->getOutput();
		try {
			$pages = Mendeley::getInstance()->importGroup( $group_id, $this->getUser()->getId(), $dry );
		} catch ( Exception $e ) {
			$out->addHTML( Html::errorBox( $this->msg( 'mendeleyimport-api-error', $e->getMessage() )->escaped() ) );
			return;
		}
		$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();
		if ( count( $pages ) > 0 ) {
			$out->addHTML( Html::openElement( 'ul' ) );
			foreach ( $pages as $pl ) {
				$out->addHTML( Html::rawElement( 'li', [], $linkRenderer->makeLink( $pl ) ) );
			}
			$out->addHTML( Html::closeElement( 'ul' ) );
			if ( $dry ) {
				$out->addHTML( '<p>' . $this->msg( 'mendeleyimport-dry-run-note' )->escaped() . '</p>' );
			} else {
				if ( $wgMendeleyUseJobs ) {
					$out->addHTML(
						$this->msg( 'mendeleyimport-scheduled', count( $pages ) )->escaped()
					);
				} else {
					$out->addHTML(
						$this->msg( 'mendeleyimport-success', count( $pages ) )->escaped()
					);
				}
			}
		} else {
			$out->addHTML( '<p class="error">' . $this->msg( 'mendeleyimport-invalid' )->escaped() . '</p>' );
		}
	}

}
