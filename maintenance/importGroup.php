<?php

if ( getenv( 'MW_INSTALL_PATH' ) ) {
	require_once getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php';
} else {
	require_once __DIR__ . '/../../../maintenance/Maintenance.php';
}

/**
 * @ingroup Maintenance
 */
class MendeleyImportGroupMaintenance extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Imports group from Mendeley' );
		$this->addOption( 'group_id', 'Group id', true, true, 'g' );
	}

	public function execute() {
		global $wgMendeleyUseJobs;
		// save jobs configuration, we do not want to use jobs in the maintenance script
		// regardless of the setting
		$oldGlobal = $wgMendeleyUseJobs;
		$wgMendeleyUseJobs = false;
		$this->output( 'Starting import..' );
		/** @phan-suppress-next-line PhanUndeclaredClassMethod */
		$mendeley = Mendeley::getInstance();
		$result = $mendeley->importGroup( $this->getOption( 'group_id' ) );
		foreach ( $result as $page ) {
			$this->output( "\nImported '" . $page->getFullText() . "' -> " . $page->getFullURL() );
		}
		$this->output( 'Done!' );
		// restore
		$wgMendeleyUseJobs = $oldGlobal;
	}
}

$maintClass = MendeleyImportGroupMaintenance::class;
require_once RUN_MAINTENANCE_IF_MAIN;
