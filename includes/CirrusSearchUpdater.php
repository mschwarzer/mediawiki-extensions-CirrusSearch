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
	 * Regex to remove text we don't want to search but that isn't already
	 * removed when stripping HTML or the toc.
	 */
	const SANITIZE = '/
		<video .*?<\/video>  # remove the sorry, not supported message
	/x';

	/**
	 * Article IDs updated in this process.  Used for deduplication of updates.
	 * @var array(Integer)
	 */
	private static $updated = array();

	/**
	 * Headings to ignore.  Lazily initialized.
	 * @var array(String)|null
	 */
	private static $ignoredHeadings = null;

	/**
	 * Update a single article using its Title and pre-sanitized text.
	 */
	public static function updateFromTitleAndText( $id, $title, $text ) {
		if ( in_array( $id, self::$updated ) ) {
			// Already indexed $id
			return;
		}
		$page = WikiPage::newFromID( $id, WikiPage::READ_LATEST );
		if ( !$page ) {
			wfDebugLog( 'CirrusSearch', "Ignoring an update for a non-existant page: $id" );
			return;
		}
		$content = $page->getContent();
		// Add the page to the list of updated pages before we start trying to update to catch redirect loops.
		self::$updated[] = $id;
		if ( $content->isRedirect() ) {
			$target = $content->getUltimateRedirectTarget();
			if ( $target->equals( $page->getTitle() ) ) {
				// This doesn't warn about redirect loops longer than one but that is ok.
				wfDebugLog( 'CirrusSearch', "Title redirecting to itself. Skip indexing" );
				return;
			}
			wfDebugLog( 'CirrusSearch', "Updating search index for $title which is a redirect to " . $target->getText() );
			self::updateFromTitle( $target );
		} else {
			self::updateRevisions( array( array(
				'page' => $page,
			) ) );
		}
	}

	/**
	 * Performs an update when the $text is not already available by firing the
	 * update back through the SearchEngine to get the sanitized text.
	 * @param Title $title
	 */
	public static function updateFromTitle( $title ) {
		$articleId = $title->getArticleID();
		$revision = Revision::loadFromPageId( wfGetDB( DB_SLAVE ), $articleId );

		// This usually happens on page creation when all the revision data hasn't
		// replicated out to the slaves
		if ( !$revision ) {
			$revision = Revision::loadFromPageId( wfGetDB( DB_MASTER ), $articleId );
			// This usually happens when building a redirect to a non-existant page
			if ( !$revision ) {
				return;
			}
		}

		$update = new SearchUpdate( $articleId, $title, $revision->getContent() );
		$update->doUpdate();
	}

	/**
	 * Hooked to update the search index for pages when templates that they include are changed
	 * and to kick off updating linked articles.
	 * @param $linksUpdate LinksUpdate
	 */
	public static function linksUpdateCompletedHook( $linksUpdate ) {
		self::updateFromTitle( $linksUpdate->getTitle() );
		self::updateLinkedArticles( $linksUpdate );
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
	 */
	public static function updateRevisions( $pageData ) {
		wfProfileIn( __METHOD__ );

		$contentDocuments = array();
		$generalDocuments = array();
		foreach ( $pageData as $page ) {
			$document = self::buildDocumentforRevision( $page );
			if ( MWNamespace::isContent( $document->get( 'namespace' ) ) ) {
				$contentDocuments[] = $document;
			} else {
				$generalDocuments[] = $document;
			}
		}
		self::sendDocuments( CirrusSearchConnection::CONTENT_INDEX_TYPE, $contentDocuments );
		self::sendDocuments( CirrusSearchConnection::GENERAL_INDEX_TYPE, $generalDocuments );

		wfProfileOut( __METHOD__ );
	}

	/**
	 * Delete pages from the elasticsearch index
	 *
	 * @param array $pages An array of ids to delete
	 */
	public static function deletePages( $pages ) {
		wfProfileIn( __METHOD__ );

		self::sendDeletes( CirrusSearchConnection::CONTENT_INDEX_TYPE, $pages );
		self::sendDeletes( CirrusSearchConnection::GENERAL_INDEX_TYPE, $pages );

		wfProfileOut( __METHOD__ );
	}

	/**
	 * @param $indexType
	 * @param $documents array
	 */
	private static function sendDocuments( $indexType, $documents ) {
		wfProfileIn( __METHOD__ );

		$documentCount = count( $documents );
		if ( $documentCount === 0 ) {
			return;
		}
		wfDebugLog( 'CirrusSearch', "Sending $documentCount documents to the $indexType index." );
		$work = new PoolCounterWorkViaCallback( 'CirrusSearch-Update', "_elasticsearch",
			array( 'doWork' => function() use ( $indexType, $documents ) {
				try {
					$result = CirrusSearchConnection::getPageType( $indexType )->addDocuments( $documents );
					wfDebugLog( 'CirrusSearch', 'Update completed in ' . $result->getEngineTime() . ' (engine) millis' );
				} catch ( \Elastica\Exception\ExceptionInterface $e ) {
					error_log( "CirrusSearch update failed caused by:  " . $e->getMessage() );
				}
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

		$doc = new \Elastica\Document( $page->getId(), array(
			'namespace' => $title->getNamespace(),
			'title' => $title->getText(),
			'timestamp' => wfTimestamp( TS_ISO_8601, $page->getTimestamp() ),
		) );

		if ( $skipParse ) {
			// Note that if the entry doesn't yet exist on the server this will create it lacking
			// text, headings, ceategories, and text length.  That _should_ be ok because the page
			// _should_ be on its way into the index if it isn't already.
			$doc->setDocAsUpsert( true );
		} else {
			$parserOutput = $page->getParserOutput( new ParserOptions(), $page->getRevision()->getId() );
			$doc->add( 'text', Sanitizer::stripAllTags( SearchEngine::create( 'CirrusSearch' )
				->getTextFromContent( $title, $page->getContent(), $parserOutput ) ) );

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

			$doc->add( 'textLen', $page->getContent()->getSize() );
		}

		$doc->add( 'links', self::countLinksToTitle( $title ) );

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
		$doc->add( 'redirect_links', $redirectLinks );

		wfProfileOut( __METHOD__ );
		return $doc;
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
	 * @param $linksUpdate LinksUpdate
	 */
	private static function updateLinkedArticles( $linksUpdate ) {
		// This could be made more efficient by having LinksUpdate return a list of articles who
		// have been newly linked or newly unlinked.  Those are the only articles that we need
		// to reindex any way.
		global $wgCirrusSearchLinkedArticlesToUpdate;

		// Build a big list of candidate pages who's links we should update
		$candidates = array();
		foreach ( $linksUpdate->getParserOutput()->getLinks() as $ns => $ids ) {
			foreach ( $ids as $id ) {
				$candidates[] = $id;
			}
		}

		// Pick up to $wgCirrusSearchLinkedArticlesToUpdate links to update
		$chosenCount = min( count( $candidates ), $wgCirrusSearchLinkedArticlesToUpdate );
		if ( $chosenCount < 1 ) {
			return;
		}
		$chosen = array_rand( $candidates, $chosenCount );
		// array_rand is $chosenCount === 1 then array_rand will return a key rather than an
		// array of keys so just wrap the key and move on with the rest of the request.
		if ( !is_array( $chosen ) ) {
			$chosen = array( $chosen );
		}
		$pages = array();
		foreach ( $chosen as $key ) {
			$page = WikiPage::newFromID( $candidates[ $key ] );
			if ( $page === null ) {
				// Skip link to non-existant page.
				continue;
			}
			// Resolve one level of redirects because only one level of redirects is scored.
			if ( $page->isRedirect() ) {
				$target = $page->getRedirectTarget();
				if ( $target === null ) {
					// Skip link to redirect to non-existant page
					continue;
				}
				$page = new WikiPage( $target );
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
		self::updateRevisions( $pages );
	}

	/**
	 * @param $indexType
	 * @param $ids array
	 */
	private static function sendDeletes( $indexType, $ids ) {
		wfProfileIn( __METHOD__ );

		$idCount = count( $ids );
		if ( $idCount === 0 ) {
			return;
		}
		wfDebugLog( 'CirrusSearch', "Sending $idCount deletes to the $indexType index." );
		$work = new PoolCounterWorkViaCallback( 'CirrusSearch-Update', "_elasticsearch",
			array( 'doWork' => function() use ( $indexType, $ids ) {
				try {
					$result = CirrusSearchConnection::getPageType( $indexType )->deleteIds( $ids );
					wfDebugLog( 'CirrusSearch', 'Delete completed in ' . $result->getEngineTime() . ' (engine) millis' );
				} catch ( \Elastica\Exception\ExceptionInterface $e ) {
					error_log( "CirrusSearch delete failed caused by:  " . $e->getMessage() );
				}
			}
		) );
		$work->execute();
		wfProfileOut( __METHOD__ );
	}
}