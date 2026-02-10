<?php

if ( getenv( 'MW_INSTALL_PATH' ) ) {
	require_once getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php';
} else {
	require_once __DIR__ . '/../../../maintenance/Maintenance.php';
}

/**
 * @ingroup Maintenance
 */
class MendeleyRefreshTokenMaintenance extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Refreshes Mendeley token' );
	}

	public function execute() {
		$this->output( 'Starting..' );
		/** @phan-suppress-next-line PhanUndeclaredClassMethod */
		$mendeley = Mendeley::getInstance();
		$mendeley->refreshAccessToken();
		$this->output( 'Done!' );
	}
}

$maintClass = MendeleyRefreshTokenMaintenance::class;
require_once RUN_MAINTENANCE_IF_MAIN;
