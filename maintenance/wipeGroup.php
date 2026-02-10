<?php

if ( getenv( 'MW_INSTALL_PATH' ) ) {
	require_once getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php';
} else {
	require_once __DIR__ . '/../../../maintenance/Maintenance.php';
}

/**
 * @ingroup Maintenance
 */
class MendeleyWipeGroupMaintenance extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Wipes pages that were imported from selected group' );
		$this->addOption( 'group_id', 'Group id', true, true, 'g' );
	}

	public function execute() {
		global $wgMendeleyUseJobs;
		// save jobs configuration, we do not want to use jobs in the maintenance script
		// regardless of the setting
		$oldGlobal = $wgMendeleyUseJobs;
		$wgMendeleyUseJobs = false;
		$this->output( 'Starting wiping..' );
		/** @phan-suppress-next-line PhanUndeclaredClassMethod */
		$mendeley = Mendeley::getInstance();
		$result = $mendeley->importGroup( $this->getOption( 'group_id' ), null, true );
		foreach ( $result as $page ) {
			$wp = WikiPage::factory( $page );
			if ( $wp->exists() ) {
				$e = [];
				$wp->doDeleteArticleReal(
					'Wiped by maintenance script',
					false,
					false,
					null,
					$e,
					null,
					[],
					'delete',
					true
				);
				$this->output( "\nWiped " . $page->getFullText() );
			}
		}
		$this->output( "\n" );
		$this->output( 'Done!' );
		// restore
		$wgMendeleyUseJobs = $oldGlobal;
	}
}

$maintClass = MendeleyWipeGroupMaintenance::class;
require_once RUN_MAINTENANCE_IF_MAIN;
