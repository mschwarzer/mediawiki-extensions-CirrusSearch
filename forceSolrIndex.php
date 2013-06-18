<?php

require_once( "maintenance/Maintenance.php" );

/**
 * Force reindexing change to the wiki.
 */
class BuildSolrConfig extends Maintenance {
	var $from = null;
	var $to = null;
	var $toId = null;
	var $indexUpdates;
	var $limit;

	public function __construct() {
		parent::__construct();
		$this->mDescription = "Force indexing some pages.  Setting neither from nor to will get you a more efficient "
			. "query at the cost of having to reindex by page id rather than time.\n\n"
			. "Note: All froms are _exclusive_ and all tos are _inclusive_.\n"
			. "Note 2: Setting fromId and toId use the efficient query so those are ok.";
		$this->mBatchSize = 500;
		$this->addOption( 'from', 'Start date of reindex in YYYY-mm-ddTHH:mm:ssZ (exc.  Defaults to 0 epoch.', false, true );
		$this->addOption( 'to', 'Stop date of reindex in YYYY-mm-ddTHH:mm:ssZ.  Defaults to now.', false, true );
		$this->addOption( 'fromId', 'Start indexing at a specific page_id.  Not useful with --deletes.', false, true );
		$this->addOption( 'toId', 'Stop indexing at a specific page_id.  Note useful with --deletes or --from or --to.', false, true );
		$this->addOption( 'deletes', 'If this is set then just index deletes, not updates or creates.', false );
		$this->addOption( 'limit', 'Maximum number of pages to process before exiting the script. Default to unlimited.', false, true );
	}

	public function execute() {
		wfProfileIn( __METHOD__ );
		if ( !is_null( $this->getOption( 'from' ) ) || !is_null( $this->getOption( 'to' ) ) ) {
			// 0 is falsy so MWTimestamp makes that `now`.  '00' is epoch 0.
			$this->from = new MWTimestamp( $this->getOption( 'from', '00' )  );
			$this->to = new MWTimestamp( $this->getOption( 'to', false ) );
		}
		$this->toId = $this->getOption( 'toId' );
		$this->indexUpdates = !$this->getOption( 'deletes', false );
		$this->limit = $this->getOption( 'limit' );

		if ( $this->indexUpdates ) {
			$operationName = 'Indexed';	
		} else {
			$operationName = 'Deleted';
		}
		$operationStartTime = microtime( true );
		$completed = 0;

		$minUpdate = $this->from;
		if ( $this->indexUpdates ) {
			$minId = $this->getOption( 'fromId', -1 );
		} else {
			$minNamespace = -100000000;
			$minTitle = '';
		}
		while ( is_null( $this->limit ) || $this->limit > $completed ) {
			if ( $this->indexUpdates ) {
				$revisions = $this->findUpdates( $minUpdate, $minId, $this->to );
				$size = count( $revisions );
			} else {
				$titles = $this->findDeletes( $minUpdate, $minNamespace, $minTitle, $this->to );
				$size = count( $titles );
			}
			
			if ( $size == 0 ) {
				break;
			}
			if ( $this->indexUpdates ) {
				$lastRevision = $revisions[$size - 1];
				$minUpdate = new MWTimestamp( $lastRevision->getTimestamp() );
				$minId = $lastRevision->getTitle()->getArticleID();
				CirrusSearchUpdater::updateRevisions( $revisions );
			} else {
				$lastTitle = $titles[$size - 1];
				$minUpdate = $lastTitle['timestamp'];
				$minNamespace = $lastTitle['namespace'];
				$minTitle = $lastTitle['title'];
				CirrusSearchUpdater::deleteTitles( $titles );
			}
			$completed += $size;
			$rate = round( $completed / ( microtime( true ) - $operationStartTime ) );
			if ( is_null( $this->to ) ) {
				$endingAt = $minId;
			} else {
				$endingAt = $minUpdate->getTimestamp( TS_ISO_8601 );
			}
			$this->output( "$operationName $size pages ending at $endingAt at $rate/second\n" );
		}
		$this->output( "$operationName a total of $completed pages at $rate/second\n" );
		wfProfileOut( __METHOD__ );
	}

	/**
	 * Find $this->mBatchSize revisions who are the latest for a page and were
	 * made after (minUpdate,minId) and before maxUpdate.
	 *
	 * @return an array of the last update timestamp and id that were found
	 */
	private function findUpdates( $minUpdate, $minId, $maxUpdate ) {
		wfProfileIn( __METHOD__ );
		$dbr = $this->getDB( DB_SLAVE );
		$minId = $dbr->addQuotes( $minId );
		if ( is_null( $maxUpdate ) ) {
			$toIdPart = '';
			if ( !is_null( $this->toId ) ) {
				$toId = $dbr->addQuotes( $this->toId );
				$toIdPart = " AND page_id <= $toId";
			}
			$res = $dbr->select(
				array( 'revision', 'text', 'page' ),
				array_merge( Revision::selectFields(), Revision::selectTextFields(), Revision::selectPageFields() ),
					"$minId < page_id"
					. $toIdPart
					. ' AND rev_text_id = old_id'
					. ' AND rev_id = page_latest',
				__METHOD__,
				array( 'ORDER BY' => 'page_id',
				       'LIMIT' => $this->mBatchSize )
			);
		} else {
			$minUpdate = $dbr->addQuotes( $dbr->timestamp( $minUpdate ) );
			$maxUpdate = $dbr->addQuotes( $dbr->timestamp( $maxUpdate ) );
			$res = $dbr->select(
				array( 'revision', 'text', 'page' ),
				array_merge( Revision::selectFields(), Revision::selectTextFields(), Revision::selectPageFields() ),
					'page_id = rev_page'
					. ' AND rev_text_id = old_id'
					. ' AND rev_id = page_latest'
					. " AND ( ( $minUpdate = rev_timestamp AND $minId < page_id ) OR $minUpdate < rev_timestamp )"
					. " AND rev_timestamp <= $maxUpdate",
				__METHOD__,
				array( 'ORDER BY' => 'rev_timestamp, rev_page',
				       'LIMIT' => $this->mBatchSize )
			);
		}
		$result = array();
		foreach ( $res as $row ) {
			$result[] = Revision::newFromRow( $row );
		}
		wfProfileOut( __METHOD__ );
		return $result;
	}

	/**
	 * Find $this->mBatchSize deletes who were deleted after (minUpdate,minNamespace,minTitle) and before maxUpdate.
	 *
	 * @return an array of the last update timestamp and id that were found
	 */
	private function findDeletes( $minUpdate, $minNamespace, $minTitle, $maxUpdate ) {
		wfProfileIn( __METHOD__ );
		$dbr = $this->getDB( DB_SLAVE );
		$logType = $dbr->addQuotes( 'delete' );
		$minUpdate = $dbr->addQuotes( $dbr->timestamp( $minUpdate ) );
		$minNamespace = $dbr->addQuotes( $minNamespace );
		$minTitle = $dbr->addQuotes( $minTitle );
		$maxUpdate = $dbr->addQuotes( $dbr->timestamp( $maxUpdate ) );
		$res = $dbr->select(
			'logging',
			array( 'log_timestamp', 'log_namespace', 'log_title' ),
				"log_type = $logType"
				. " AND ( ( $minUpdate = log_timestamp AND $minNamespace < log_namespace AND $minTitle < log_title )"
				. "    OR $minUpdate < log_timestamp )"
				. " AND log_timestamp <= $maxUpdate",
			__METHOD__,
			array( 'ORDER BY' => 'log_timestamp, log_namespace, log_title',
			       'LIMIT' => $this->mBatchSize )
		);
		$result = array();
		foreach ( $res as $row ) {
			$result[] = array(
				'timestamp' => new MWTimestamp( $row->log_timestamp ),  // This feels funky
				'namespace' => $row->log_namespace,
				'title' => $row->log_title
			);
		}
		wfProfileOut( __METHOD__ );
		return $result;
	}
}

$maintClass = "BuildSolrConfig";
require_once RUN_MAINTENANCE_IF_MAIN;