<?php

namespace CirrusSearch\Search;

use CirrusSearch\InterwikiSearcher;
use CirrusSearch\Util;
use CirrusSearch\Searcher;
use MediaWiki\Logger\LoggerFactory;
use MWTimestamp;
use SearchResult;
use Title;

/**
 * An individual search result from Elasticsearch.
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
class Result extends SearchResult {
	/** @var int */
	private $namespace;
	/** @var string */
	private $titleSnippet = '';
	/** @var Title|null */
	private $redirectTitle = null;
	/** @var string */
	private $redirectSnippet = '';
	/** @var Title|null */
	private $sectionTitle = null;
	/** @var string */
	private $sectionSnippet = '';
	/** @var string */
	private $categorySnippet = '';
	/** @var string */
	private $textSnippet;
	/** @var bool */
	private $isFileMatch = false;
	/* @var string */
	private $interwiki = '';
	/** @var string */
	private $interwikiNamespace = '';
	/** @var int */
	private $wordCount;
	/** @var int */
	private $byteSize;
	/** @var string */
	private $timestamp;
	/** @var string */
	private $docId;
	/** @var float */
	private $score;
	/** @var array */
	private $explanation;

	/**
	 * Build the result.
	 *
	 * @param \Elastica\ResultSet $results containing all search results
	 * @param \Elastica\Result $result containing the given search result
	 * @param string $interwiki Interwiki prefix, if any
	 * @param \Elastica\Result $result containing information about the result this class should represent
	 */
	public function __construct( $results, $result, $interwiki = '' ) {
		if ( $interwiki ) {
			$this->setInterwiki( $result, $interwiki );
		}
		$this->docId = $result->getId();
		$this->namespace = $result->namespace;
		$this->mTitle = $this->makeTitle( $result->namespace, $result->title );
		if ( $this->getTitle()->getNamespace() == NS_FILE ) {
			$this->mImage = wfFindFile( $this->mTitle );
		}

		$fields = $result->getFields();
		// Not all results requested a word count. Just pretend we have none if so
		$this->wordCount = isset( $fields['text.word_count'] ) ? $fields['text.word_count'][ 0 ] : 0;
		$this->byteSize = $result->text_bytes;
		$this->timestamp = new MWTimestamp( $result->timestamp );
		$highlights = $result->getHighlights();
		if ( isset( $highlights[ 'title' ] ) ) {
			$nstext = $this->getTitle()->getNamespace() === 0 ? '' :
				Util::getNamespaceText( $this->getTitle() ) . ':';
			$this->titleSnippet = $nstext . $this->escapeHighlightedText( $highlights[ 'title' ][ 0 ] );
		} elseif ( $this->mTitle->isExternal() ) {
			// Interwiki searches are weird. They won't have title highlights by design, but
			// if we don't return a title snippet we'll get weird display results.
			$this->titleSnippet = $this->mTitle->getText();
		}

		if ( !isset( $highlights[ 'title' ] ) && isset( $highlights[ 'redirect.title' ] ) ) {
			// Make sure to find the redirect title before escaping because escaping breaks it....
			$redirects = $result->redirect;
			$this->redirectTitle = $this->findRedirectTitle( $highlights[ 'redirect.title' ][ 0 ], $redirects );
			$this->redirectSnippet = $this->escapeHighlightedText( $highlights[ 'redirect.title' ][ 0 ] );
		}

		$this->textSnippet = $this->escapeHighlightedText( $this->pickTextSnippet( $highlights ) );

		if ( isset( $highlights[ 'heading' ] ) ) {
			$this->sectionSnippet = $this->escapeHighlightedText( $highlights[ 'heading' ][ 0 ] );
			$this->sectionTitle = $this->findSectionTitle();
		}

		if ( isset( $highlights[ 'category' ] ) ) {
			$this->categorySnippet = $this->escapeHighlightedText( $highlights[ 'category' ][ 0 ] );
		}
		$this->score = $result->getScore();
		$this->explanation = $result->getExplanation();
	}

	/**
	 * @param string[] $highlights
	 * @return string
	 */
	private function pickTextSnippet( $highlights ) {
		// This can get skipped if there the page was sent to Elasticsearch without text.
		// This could be a bug or it could be that the page simply doesn't have any text.
		$mainSnippet = '';
		if ( isset( $highlights[ 'text' ] ) ) {
			$mainSnippet = $highlights[ 'text' ][ 0 ];
			if ( $this->containsMatches( $mainSnippet ) ) {
				return $mainSnippet;
			}
		}
		if ( isset( $highlights[ 'auxiliary_text' ] ) ) {
			$auxSnippet = $highlights[ 'auxiliary_text' ][ 0 ];
			if ( $this->containsMatches( $auxSnippet ) ) {
				return $auxSnippet;
			}
		}
		if ( isset( $highlights[ 'file_text' ] ) ) {
			$fileSnippet = $highlights[ 'file_text' ][ 0 ];
			if ( $this->containsMatches( $fileSnippet ) ) {
				$this->isFileMatch = true;
				return $fileSnippet;
			}
		}
		if ( isset( $highlights[ 'source_text.plain' ] ) ) {
			$sourceSnippet = $highlights[ 'source_text.plain' ][ 0 ];
			if ( $this->containsMatches( $sourceSnippet ) ) {
				return $sourceSnippet;
			}
		}
		return $mainSnippet;
	}

	/**
	 * Don't bother hitting the revision table and loading extra stuff like
	 * that into memory like the parent does, just return if we've got an idea
	 * about page existence.
	 *
	 * Protects against things like bug 61464, where a page clearly doesn't
	 * exist anymore but we've got something stuck in the index.
	 *
	 * @return bool
	 */
	public function isMissingRevision() {
		return !$this->mTitle->isKnown();
	}

	/**
	 * Escape highlighted text coming back from Elasticsearch.
	 *
	 * @param string $snippet highlighted snippet returned from elasticsearch
	 * @return string $snippet with html escaped _except_ highlighting pre and post tags
	 */
	private function escapeHighlightedText( $snippet ) {
		static $highlightPreEscaped = null, $highlightPostEscaped = null;
		if ( $highlightPreEscaped === null ) {
			$highlightPreEscaped = htmlspecialchars( Searcher::HIGHLIGHT_PRE );
			$highlightPostEscaped = htmlspecialchars( Searcher::HIGHLIGHT_POST );
		}
		return str_replace( array( $highlightPreEscaped, $highlightPostEscaped ),
			array( Searcher::HIGHLIGHT_PRE, Searcher::HIGHLIGHT_POST ),
			htmlspecialchars( $snippet ) );
	}

	/**
	 * Checks if a snippet contains matches by looking for HIGHLIGHT_PRE.
	 *
	 * @param string $snippet highlighted snippet returned from elasticsearch
	 * @return boolean true if $snippet contains matches, false otherwise
	 */
	private function containsMatches( $snippet ) {
		return strpos( $snippet, Searcher::HIGHLIGHT_PRE ) !== false;
	}

	/**
	 * Build the redirect title from the highlighted redirect snippet.
	 *
	 * @param string $snippet Highlighted redirect snippet
	 * @param array[]|null $redirects Array of redirects stored as arrays with 'title' and 'namespace' keys
	 * @return Title|null object representing the redirect
	 */
	private function findRedirectTitle( $snippet, $redirects ) {
		$title = $this->stripHighlighting( $snippet );
		// Grab the redirect that matches the highlighted title with the lowest namespace.
		// That is pretty arbitrary but it prioritizes 0 over others.
		$best = null;
		if ( $redirects !== null ) {
			foreach ( $redirects as $redirect ) {
				if ( $redirect[ 'title' ] === $title && ( $best === null || $best[ 'namespace' ] > $redirect ) ) {
					$best = $redirect;
				}
			}
		}
		if ( $best === null ) {
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				"Search backend highlighted a redirect ({title}) but didn't return it.",
				array( 'title' => $title )
			);
			return null;
		}
		return $this->makeTitle( $best[ 'namespace' ], $best[ 'title' ] );
	}

	/**
	 * @return Title
	 */
	private function findSectionTitle() {
		return $this->getTitle()->createFragmentTarget( Title::escapeFragmentForURL(
			$this->stripHighlighting( $this->sectionSnippet )
		) );
	}

	/**
	 * @param string $highlighted
	 * @return string
	 */
	private function stripHighlighting( $highlighted ) {
		$markers = array( Searcher::HIGHLIGHT_PRE, Searcher::HIGHLIGHT_POST );
		return str_replace( $markers, '', $highlighted );
	}

	/**
	 * Set interwiki and interwikiNamespace properties
	 *
	 * @param \Elastica\Result $result containing the given search result
	 * @param string $interwiki Interwiki prefix, if any
	 */
	private function setInterwiki( $result, $interwiki ) {
		$resultIndex = $result->getIndex();
		$indexBase = InterwikiSearcher::getIndexForInterwiki( $interwiki );
		$pos = strpos( $resultIndex, $indexBase );
		if ( $pos === 0 && $resultIndex[strlen( $indexBase )] == '_' ) {
			$this->interwiki = $interwiki;
			$this->interwikiNamespace = $result->namespace_text ? $result->namespace_text : '';
		}
	}

	/**
	 * @return string
	 */
	public function getTitleSnippet() {
		return $this->titleSnippet;
	}

	/**
	 * @return Title|null
	 */
	public function getRedirectTitle() {
		return $this->redirectTitle;
	}

	/**
	 * @return string
	 */
	public function getRedirectSnippet() {
		return $this->redirectSnippet;
	}

	/**
	 * @param array
	 * @return string|null
	 */
	public function getTextSnippet( $terms ) {
		return $this->textSnippet;
	}

	/**
	 * @return string
	 */
	public function getSectionSnippet() {
		return $this->sectionSnippet;
	}

	/**
	 * @return Title|null
	 */
	public function getSectionTitle() {
		return $this->sectionTitle;
	}

	/**
	 * @return string
	 */
	public function getCategorySnippet() {
		return $this->categorySnippet;
	}

	/**
	 * @return int
	 */
	public function getWordCount() {
		return $this->wordCount;
	}

	/**
	 * @return int
	 */
	public function getByteSize() {
		return $this->byteSize;
	}

	/**
	 * @return string
	 */
	public function getTimestamp() {
		return $this->timestamp->getTimestamp( TS_MW );
	}

	/**
	 * @return bool
	 */
	public function isFileMatch() {
		return $this->isFileMatch;
	}

	/**
	 * @return string
	 */
	public function getInterwikiPrefix() {
		return $this->interwiki;
	}

	/**
	 * @return string
	 */
	public function getInterwikiNamespaceText() {
		return $this->interwikiNamespace;
	}

	/**
	 * @return string
	 */
	public function getDocId() {
		return $this->docId;
	}

	/**
	 * @return float the score
	 */
	public function getScore() {
		return $this->score;
	}

	/**
	 * @return array lucene score explanation
	 */
	public function getExplanation() {
		return $this->explanation;
	}

	/**
	 * Create a title. When making interwiki titles we should be providing the
	 * namespace text as a portion of the text, rather than a namespace id,
	 * because namespace id's are not consistent across wiki's. This
	 * additionally prevents the local wiki from localizing the namespace text
	 * when it should be using the localized name of the remote wiki.
	 *
	 * Unfortunately we don't always have the remote namespace text, such as
	 * when handling redirects. Do the best we can in this case and take the
	 * less-than ideal results when we don't.
	 *
	 * @param int $namespace
	 * @param string $text
	 * @return Title
	 */
	private function makeTitle( $namespace, $text ) {
		if ( $this->interwikiNamespace && $namespace === $this->namespace ) {
			return Title::makeTitle( 0, $this->interwikiNamespace . ':' . $text, '', $this->interwiki );
		} else {
			return Title::makeTitle( $namespace, $text, '', $this->interwiki );
		}
	}
}
