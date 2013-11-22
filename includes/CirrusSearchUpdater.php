<?php
/**
 * Performs updates and deletes on the Elasticsearch index.  Called by
 * CirrusSearch.body.php (our SearchEngine implementation), forceSearchIndex
 * (for bulk updates), and hooked into LinksUpdate (for implied updates).
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */
class CirrusSearchUpdater {
	/**
	 * Full title text of pages updated in this process.  Used for deduplication
	 * of updates.
	 * @var array(String)
	 */
	private static $updated = array();

	/**
	 * Headings to ignore.  Lazily initialized.
	 * @var array(String)|null
	 */
	private static $ignoredHeadings = null;

	/**
	 * Update a single page.
	 * @param Title $title
	 */
	public static function updateFromTitle( $title ) {
		global $wgCirrusSearchShardTimeout, $wgCirrusSearchClientSideUpdateTimeout;

		// Loop through redirects until we get to the ultimate target
		while ( true ) {
			$titleText = $title->getFullText();
			if ( in_array( $titleText, self::$updated ) ) {
				// Already indexed this article in this process.  This is mostly useful
				// to catch self redirects but has a storied history of catching strange
				// behavior.
				return;
			}

			$page = WikiPage::factory( $title );
			if ( !$page ) {
				wfDebugLog( 'CirrusSearch', "Ignoring an update for a non-existant page: $titleText" );
				return;
			}
			$content = $page->getContent();
			if ( is_string( $content ) ) {
				$content = new TextContent( $content );
			}

			// Add the page to the list of updated pages before we start trying to update to catch redirect loops.
			self::$updated[] = $titleText;
			if ( $content->isRedirect() ) {
				$target = $content->getUltimateRedirectTarget();
				if ( $target->equals( $page->getTitle() ) ) {
					// This doesn't warn about redirect loops longer than one but we'll catch those anyway.
					wfDebugLog( 'CirrusSearch', "Title redirecting to itself. Skip indexing" );
					return;
				}
				wfDebugLog( 'CirrusSearch', "Updating search index for $title which is a redirect to " . $target->getText() );
				$title = $target;
				continue;
			} else {
				self::updatePages( array( array(
					'page' => $page,
				) ), false, $wgCirrusSearchShardTimeout, $wgCirrusSearchClientSideUpdateTimeout );
				return;
			}
		}
	}

	/**
	 * Hooked to update the search index when pages change directly or when templates that
	 * they include change.
	 * @param $linksUpdate LinksUpdate source of all links update information
	 */
	public static function linksUpdateCompletedHook( $linksUpdate ) {
		$job = new CirrusSearchLinksUpdateJob( $linksUpdate->getTitle(), array(
			'addedLinks' => $linksUpdate->getAddedLinks(),
			'removedLinks' => $linksUpdate->getRemovedLinks(),
		) );
		JobQueueGroup::singleton()->push( $job );
	}

	/**
	 * This updates pages in elasticsearch.
	 *
	 * @param array $pageData An array of pages. The format is as follows:
	 *   array(
	 *     array(
	 *       'page' => page,
	 *       'skip-parse' => true, # Don't parse the page, just update link counts.  Optional.
	 *     )
	 *   )
	 * @param boolean $checkFreshness Should we check if Elasticsearch already has
	 *   up to date copy of the document before sending it?
	 * @param null|string $shardTimeout How long should elaticsearch wait for an offline
	 *   shard.  Defaults to null, meaning don't wait.  Null is more efficient when sending
	 *   multiple pages because Cirrus will use Elasticsearch's bulk API.  Timeout is in
	 *   Elasticsearch's time format.
	 * @param null|int $clientSideTimeout timeout in seconds to update pages or null if using
	 *   the Elastica default which is 300 seconds.
	 */
	public static function updatePages( $pageData, $checkFreshness = false, $shardTimeout = null,
			$clientSideTimeout = null) {
		wfProfileIn( __METHOD__ );

		if ( $clientSideTimeout !== null ) {
			CirrusSearchConnection::setTimeout( $clientSideTimeout );
		}
		$contentDocuments = array();
		$generalDocuments = array();
		foreach ( $pageData as $page ) {
			if ( $checkFreshness && self::isFresh( $page ) ) {
				continue;
			}
			$document = self::buildDocumentforRevision( $page );
			if ( $document === null ) {
				continue;
			}
			if ( MWNamespace::isContent( $document->get( 'namespace' ) ) ) {
				$contentDocuments[] = $document;
			} else {
				$generalDocuments[] = $document;
			}
		}
		self::sendDocuments( CirrusSearchConnection::CONTENT_INDEX_TYPE, $contentDocuments, $shardTimeout );
		self::sendDocuments( CirrusSearchConnection::GENERAL_INDEX_TYPE, $generalDocuments, $shardTimeout );

		$count = count( $contentDocuments ) + count( $generalDocuments );
		wfProfileOut( __METHOD__ );
		return $count;
	}

	/**
	 * Delete pages from the elasticsearch index
	 *
	 * @param array $pages An array of ids to delete
	 * @param null|int $clientSideTimeout timeout in seconds to update pages or null if using
	 *   the Elastica default which is 300 seconds.
	 */
	public static function deletePages( $pages, $clientSideTimeout = null ) {
		wfProfileIn( __METHOD__ );

		if ( $clientSideTimeout !== null ) {
			CirrusSearchConnection::setTimeout( $clientSideTimeout );
		}
		self::sendDeletes( $pages );

		wfProfileOut( __METHOD__ );
	}

	private static function isFresh( $page ) {
		$page = $page[ 'page' ];
		$searcher = new CirrusSearchSearcher( 0, 0, array( $page->getTitle()->getNamespace() ) );
		$get = $searcher->get( $page->getTitle()->getArticleId(), array( 'timestamp ') );
		if ( !$get->isOk() ) {
			return false;
		}
		$get = $get->getValue();
		if ( $get === null ) {
			return false;
		}
		$found = new MWTimestamp( $get->timestamp );
		$diff = $found->diff( new MWTimestamp( $page->getTimestamp() ) );
		if ( $diff === false ) {
			return false;
		}
		return !$diff->invert;
	}

	/**
	 * @param string $indexType type of index to which to send $documents
	 * @param array $documents documents to send
	 * @param null|string $shardTimeout How long should elaticsearch wait for an offline
	 *   shard.  Defaults to null, meaning don't wait.  Null is more efficient when sending
	 *   multiple pages because Cirrus will use Elasticsearch's bulk API.  Timeout is in
	 *   Elasticsearch's time format.
	 */
	private static function sendDocuments( $indexType, $documents, $shardTimeout = null ) {
		wfProfileIn( __METHOD__ );

		$documentCount = count( $documents );
		if ( $documentCount === 0 ) {
			return;
		}
		$work = new PoolCounterWorkViaCallback( 'CirrusSearch-Update', "_elasticsearch", array(
			'doWork' => function() use ( $indexType, $documents, $documentCount, $shardTimeout ) {
				try {
					$pageType = CirrusSearchConnection::getPageType( $indexType );
					// The bulk api doesn't support shardTimeout so don't use it if one is set
					if ( $shardTimeout === null ) {
						$start = microtime( true );
						wfDebugLog( 'CirrusSearch', "Sending $documentCount documents to the $indexType index via bulk api." );
						// addDocuments (notice plural) is the bulk api
						$pageType->addDocuments( $documents );
						$took = round( ( microtime( true ) - $start ) * 1000 );
						wfDebugLog( 'CirrusSearch', "Update completed in $took millis" );
					} else {
						foreach ( $documents as $document ) {
							$start = microtime( true );
							wfDebugLog( 'CirrusSearch', 'Sending id=' . $document->getId() . " to the $indexType index." );
							$document->setTimeout( $shardTimeout );
							// The bulk api automatically performs an update if the opType is update but the index api
							// doesn't so we have to make the switch ourselves
							if ( $document->getOpType() === 'update' ) {
								$pageType->updateDocument( $document );
							} else {
								// addDocument (notice singular) is the non-bulk index api
								$pageType->addDocument( $document );
							}
							$took = round( ( microtime( true ) - $start ) * 1000 );
							wfDebugLog( 'CirrusSearch', "Update completed in $took millis" );
						}
					}
				} catch ( \Elastica\Exception\ExceptionInterface $e ) {
					wfLogWarning( 'CirrusSearch update failed caused by:  ' . $e->getMessage() );
					foreach ( $documents as $document ) {
						wfDebugLog( 'CirrusSearchChangeFailed', 'Update: ' . $document->getId() );
					}
				}
			},
			'error' => function( $status ) use ( $documents ) {
				$status = $status->getErrorsArray();
				wfLogWarning( 'Pool error sending documents to Elasticsearch:  ' . $status[ 0 ][ 0 ] );
				foreach ( $documents as $document ) {
					wfDebugLog( 'CirrusSearchChangeFailed', 'Update: ' . $document->getId() );
				}
				return false;
			}
		) );
		$work->execute();
		wfProfileOut( __METHOD__ );
	}

	private static function buildDocumentforRevision( $page ) {
		global $wgCirrusSearchIndexedRedirects;
		wfProfileIn( __METHOD__ );

		$skipParse = isset( $page[ 'skip-parse' ] ) && $page[ 'skip-parse' ];
		$page = $page[ 'page' ];
		$title = $page->getTitle();
		if ( !$page->exists() ) {
			wfLogWarning( 'Attempted to build a document for a page that doesn\'t exist.  This should be caught ' .
				"earlier but wasn't.  Page: $title" );
			return null;
		}

		$doc = new \Elastica\Document( $page->getId(), array(
			'namespace' => $title->getNamespace(),
			'title' => $title->getText(),
			'timestamp' => wfTimestamp( TS_ISO_8601, $page->getTimestamp() ),
		) );

		if ( $skipParse ) {
			// These are sent as updates so if the document isn't already in the index this is
			// ignored.  This is prferable to sending regular index requests because those ignore
			// doc_as_upsert.  Without that this feature just trashes the search index by removing
			// the text from entries.
			$doc->setDocAsUpsert( true );
			$doc->setOpType( 'update' );
		} else {
			$doc->setOpType( 'index' );
			$parserOutput = $page->getParserOutput( new ParserOptions(), $page->getRevision()->getId() );
			$text = self::buildTextToIndex( $page->getContent(), $parserOutput );
			$doc->add( 'text', $text );
			$doc->add( 'text_bytes', strlen( $text ) );
			$doc->add( 'text_words', str_word_count( $text ) ); // It would be better if we could let ES calculate it

			$categories = array();
			foreach ( $parserOutput->getCategories() as $key => $value ) {
				$category = Category::newFromName( $key );
				$categories[] = $category->getTitle()->getText();
			}
			$doc->add( 'category', $categories );

			$headings = array();
			$ignoredHeadings = self::getIgnoredHeadings();
			foreach ( $parserOutput->getSections() as $heading ) {
				$heading = $heading[ 'line' ];
				// Strip tags from the heading or else we'll display them (escaped) in search results
				$heading = Sanitizer::stripAllTags( $heading );
				// Note that we don't take the level of the heading into account - all headings are equal.
				// Except the ones we ignore.
				if ( !in_array( $heading, $ignoredHeadings ) ) {
					$headings[] = $heading;
				}
			}
			$doc->add( 'heading', $headings );

			$outgoingLinks = array();
			foreach ( $parserOutput->getLinks() as $linkedNamespace => $namespaceLinks ) {
				foreach ( $namespaceLinks as $linkedDbKey => $ignored ) {
					$linked = Title::makeTitle( $linkedNamespace, $linkedDbKey );
					$outgoingLinks[] = $linked->getPrefixedDBKey();
				}
			}
			$doc->add( 'outgoing_link', $outgoingLinks );
		}

		$incomingLinks = self::countLinksToTitle( $title );
		$doc->add( 'links', $incomingLinks );                    #Deprecated
		$doc->add( 'incoming_links', $incomingLinks );

		// Handle redirects to this page
		$redirectTitles = $title->getLinksTo( array( 'limit' => $wgCirrusSearchIndexedRedirects ), 'redirect', 'rd' );
		$redirects = array();
		$redirectLinks = 0;
		foreach ( $redirectTitles as $redirect ) {
			// If the redirect is in main or the same namespace as the article the index it
			if ( $redirect->getNamespace() === NS_MAIN && $redirect->getNamespace() === $title->getNamespace()) {
				$redirects[] = array(
					'namespace' => $redirect->getNamespace(),
					'title' => $redirect->getText()
				);
			}
			// Count links to redirects
			// Note that we don't count redirect to redirects here because that seems a bit much.
			$redirectLinks += self::countLinksToTitle( $redirect );
		}
		$doc->add( 'redirect', $redirects );
		$doc->add( 'redirect_links', $redirectLinks );           #Deprecated
		$doc->add( 'incoming_redirect_links', $redirectLinks );

		wfProfileOut( __METHOD__ );
		return $doc;
	}

	/**
	 * Fetch text to index.  If $content is wikitext then render and clean it.  Otherwise delegate
	 * to the $content itself and then to SearchUpdate::updateText to clean the result.
	 * @param $content Content of page
	 * @param $parserOutput ParserOutput from page
	 */
	public static function buildTextToIndex( $content, $parserOutput ) {
		switch ( $content->getModel() ) {
		case CONTENT_MODEL_WIKITEXT:
			return CirrusSearchTextFormatter::formatWikitext( $parserOutput );
		default:
			return SearchUpdate::updateText( $content->getTextForSearchIndex() );
		}
	}

	private static function getIgnoredHeadings() {
		if ( self::$ignoredHeadings === null ) {
			$source = wfMessage( 'cirrussearch-ignored-headings' )->inContentLanguage();
			if( $source->isDisabled() ) {
				self::$ignoredHeadings = array();
			} else {
				$lines = explode( "\n", $source->plain() );
				$lines = preg_replace( '/#.*$/', '', $lines ); // Remove comments
				$lines = array_map( 'trim', $lines );          // Remove extra spaces
				$lines = array_filter( $lines );               // Remove empty lines
				self::$ignoredHeadings = $lines;               // Now we just have headings!
			}
		}
		return self::$ignoredHeadings;
	}

	/**
	 * Count the links to $title directly in the slave db.
	 * @param $title a title
	 * @return an integer count
	 */
	private static function countLinksToTitle( $title ) {
		global $wgMemc, $wgCirrusSearchLinkCountCacheTime;
		$key = wfMemcKey( 'cirrus', 'linkcounts', $title->getPrefixedDBKey() );
		$count = $wgMemc->get( $key );
		if ( !is_int( $count ) ) {
			$dbr = wfGetDB( DB_SLAVE );
			$count = $dbr->selectField(
				array( 'pagelinks' ),
				'COUNT(*)',
				array(
					"pl_namespace" => $title->getNamespace(),
					"pl_title" => $title->getDBkey() ),
				__METHOD__
			);
			// Looks like $count can come back as a string....
			if ( is_string( $count ) ) {
				$count = (int)$count;
			}
			if ( is_int( $count ) && $wgCirrusSearchLinkCountCacheTime > 0 ) {
				$wgMemc->set( $key, $count, $wgCirrusSearchLinkCountCacheTime );
			}
		}

		return $count ? $count : 0;
	}

	/**
	 * Update the search index for articles linked from this article.  Just updates link counts.
	 * @param $addedLinks array of Titles added to the page
	 * @param $removedLinks array of Titles removed from the page
	 */
	public static function updateLinkedArticles( $addedLinks, $removedLinks ) {
		global $wgCirrusSearchLinkedArticlesToUpdate, $wgCirrusSearchUnlinkedArticlesToUpdate;
		global $wgCirrusSearchShardTimeout, $wgCirrusSearchClientSideUpdateTimeout;

		$titles = array_merge(
			self::pickFromArray( $addedLinks, $wgCirrusSearchLinkedArticlesToUpdate ),
			self::pickFromArray( $removedLinks, $wgCirrusSearchUnlinkedArticlesToUpdate )
		);
		$pages = array();
		foreach ( $titles as $title ) {
			wfDebugLog( 'CirrusSearch', "Updating link counts for $title" );
			$page = WikiPage::factory( $title );
			if ( $page === null || !$page->exists() ) {
				// Skip link to non-existant page.
				continue;
			}
			// Resolve one level of redirects because only one level of redirects is scored.
			if ( $page->isRedirect() ) {
				$target = $page->getRedirectTarget();
				$page = new WikiPage( $target );
				if ( !$page->exists() ) {
					// Skip redirects to non-existant pages
					continue;
				}
			}
			if ( $page->isRedirect() ) {
				// This is a redirect to a redirect which doesn't count in the search score any way.
				continue;
			}
			if ( in_array( $page->getId(), self::$updated ) ) {
				// We've already updated this page in this proces so there is no need to update it again.
				continue;
			}
			// Note that we don't add this page to the list of updated pages because this update isn't
			// a full update (just link counts.)
			$pages[] = array(
				'page' => $page,
				'skip-parse' => true,  // Just update link counts
			);
		}
		self::updatePages( $pages, false, $wgCirrusSearchShardTimeout, $wgCirrusSearchClientSideUpdateTimeout );
	}

	/**
	 * Pick $n random entries from $array.
	 * @var $array array array to pick from
	 * @var $n int number of entries to pick
	 * @return array of entries from $array
	 */
	private static function pickFromArray( $array, $n ) {
		if ( $n > count( $array ) ) {
			return $array;
		}
		if ( $n < 1 ) {
			return array();
		}
		$chosen = array_rand( $array, $n );
		// If $n === 1 then array_rand will return a key rather than an array of keys.
		if ( !is_array( $chosen ) ) {
			return array( $array[ $chosen ] );
		}
		$result = array();
		foreach ( $chosen as $key ) {
			$result[] = $array[ $key ];
		}
		return $result;
	}

	/**
	 * @param $ids array
	 */
	private static function sendDeletes( $ids ) {
		wfProfileIn( __METHOD__ );

		$idCount = count( $ids );
		if ( $idCount === 0 ) {
			return;
		}
		wfDebugLog( 'CirrusSearch', "Sending $idCount deletes to the index." );
		$work = new PoolCounterWorkViaCallback( 'CirrusSearch-Update', "_elasticsearch", array(
			'doWork' => function() use ( $ids ) {
				try {
					$start = microtime( true );
					$response = CirrusSearchConnection::getPageType( CirrusSearchConnection::CONTENT_INDEX_TYPE )
						->deleteIds( $ids );
					CirrusSearchConnection::getPageType( CirrusSearchConnection::GENERAL_INDEX_TYPE )
						->deleteIds( $ids );
					$took = round( ( microtime( true ) - $start ) * 1000 );
					wfDebugLog( 'CirrusSearch', "Delete completed in $took millis" );
				} catch ( \Elastica\Exception\ExceptionInterface $e ) {
					wfLogWarning( "CirrusSearch delete failed caused by:  " . $e->getMessage() );
					foreach ( $ids as $id ) {
						wfDebugLog( 'CirrusSearchChangeFailed', "Delete: $id" );
					}
				}
			},
			'error' => function( $status ) use ( $ids ) {
				$status = $status->getErrorsArray();
				wfLogWarning( 'Pool error sending deletes to Elasticsearch:  ' . $status[ 0 ][ 0 ] );
				foreach ( $ids as $id ) {
					wfDebugLog( 'CirrusSearchChangeFailed', "Delete: $id" );
				}
				return false;
			}
		) );
		$work->execute();
		wfProfileOut( __METHOD__ );
	}
}
