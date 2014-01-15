<?php

namespace CirrusSearch;
use \MWTimestamp;
use \SearchResult;
use \Title;

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
	/**
	 * @var string|null lazy built escaped copy of Searcher::HIGHLIGHT_PRE
	 */
	private static $highlightPreEscaped = null;
	/**
	 * @var string|null lazy built escaped copy of Searcher::HIGHLIGHT_POST
	 */
	private static $highlightPostEscaped = null;

	private $titleSnippet;
	private $redirectTitle, $redirectSnipppet;
	private $sectionTitle, $sectionSnippet;
	private $textSnippet;
	private $wordCount;
	private $byteSize;
	private $score;
	private $timestamp;

	/**
	 * Build the result.
	 * @param $results \Elastica\ResultSet containing all search results
	 * @param $result \Elastic\Result containing information about the result this class should represent
	 */
	public function __construct( $results, $result ) {
		global $wgCirrusSearchShowScore;

		$title = Title::makeTitle( $result->namespace, $result->title );
		$this->initFromTitle( $title );
		$data = $result->getData();
		// TODO remove ternary once text.word_count is available everywhere
		$this->wordCount = isset( $data['text.word_count'] ) ? $data['text.word_count'] : $result->text_words;
		$this->byteSize = $result->text_bytes;
		$this->timestamp = new MWTimestamp( $result->timestamp );
		$highlights = $result->getHighlights();
		// TODO remove when Elasticsearch issue 3757 is fixed
		$highlights = $this->swapInPlainHighlighting( $highlights, 'redirect.title' );
		$highlights = $this->swapInPlainHighlighting( $highlights, 'heading' );
		if ( isset( $highlights[ 'title' ] ) ) {
			$nstext = '';
			if ( $title->getNamespace() !== 0 ) {
				$nstext = $title->getNsText() . ':';
			}
			$this->titleSnippet = $nstext . self::escapeHighlightedText( $highlights[ 'title' ][ 0 ] );
		} else {
			$this->titleSnippet = '';
		}
		if ( !isset( $highlights[ 'title' ] ) && isset( $highlights[ 'redirect.title' ] ) ) {
			// Make sure to find the redirect title before escaping because escaping breaks it....
			$this->redirectTitle = $this->findRedirectTitle( $highlights[ 'redirect.title' ][ 0 ], $result->redirect );
			$this->redirectSnipppet = self::escapeHighlightedText( $highlights[ 'redirect.title' ][ 0 ] );
		} else {
			$this->redirectSnipppet = '';
			$this->redirectTitle = null;
		}
		if ( isset( $highlights[ 'text' ] ) ) {
			$snippet = $highlights[ 'text' ][ 0 ];
			if ( isset( $highlights[ 'file_text' ] ) ) {
				$fileTextSnippet = $highlights[ 'file_text' ][ 0 ];
				if ( !self::containsMatches( $snippet ) && self::containsMatches( $fileTextSnippet ) ) {
					$snippet = wfMessage( 'cirrussearch-file-contents-match', $fileTextSnippet )->toString();
				}
			}
			$this->textSnippet = self::escapeHighlightedText( $snippet );
		} else {
			// This can happen if there the page was sent to Elasticsearch without text.  This could be
			// a bug or it could be that the page simply doesn't have any text.
			$this->textSnippet = '';
		}
		if ( isset( $highlights[ 'heading' ] ) ) {
			$this->sectionSnippet = self::escapeHighlightedText( $highlights[ 'heading' ][ 0 ] );
			$this->sectionTitle = $this->findSectionTitle();
		} else {
			$this->sectionSnippet = '';
			$this->sectionTitle = null;
		}
		if ( $wgCirrusSearchShowScore ) {
			$this->score = $result->getScore() / $results->getMaxScore();
		}
	}

	/**
	 * Swap plain highlighting into the highlighting field if there isn't any normal highlighting.
	 * TODO remove when Elasticsearch issue 3757 is fixed.
	 * @var $highlights array of highlighting results
	 * @var $name string normal field name
	 * @return $highlights with $name replaced with plain field results if $name isn't in $highlights
	 */
	private function swapInPlainHighlighting( $highlights, $name ) {
		if ( !isset( $highlights[ $name ] ) && isset( $highlights[ "$name.plain" ] ) ) {
			$highlights[ $name ] = $highlights[ "$name.plain" ];
		}
		return $highlights;
	}

	/**
	 * Escape highlighted text coming back from Elasticsearch.
	 * @param $snippet string highlighted snippet returned from elasticsearch
	 * @return string $snippet with html escaped _except_ highlighting pre and post tags
	 */
	private static function escapeHighlightedText( $snippet ) {
		if ( self::$highlightPreEscaped === null ) {
			self::$highlightPreEscaped = htmlspecialchars( Searcher::HIGHLIGHT_PRE );
			self::$highlightPostEscaped = htmlspecialchars( Searcher::HIGHLIGHT_POST );
		}
		return str_replace( array( self::$highlightPreEscaped, self::$highlightPostEscaped ),
			array( Searcher::HIGHLIGHT_PRE, Searcher::HIGHLIGHT_POST ),
			htmlspecialchars( $snippet ) );
	}

	/**
	 * Checks if a snippet contains matches by looking for HIGHLIGHT_PRE.
	 * @param string $snippet highlighted snippet returned from elasticsearch
	 * @return boolean true if $snippet contains matches, false otherwise
	 */
	private static function containsMatches( $snippet ) {
		return strpos( $snippet, Searcher::HIGHLIGHT_PRE ) !== false;
	}

	/**
	 * Build the redirect title from the highlighted redirect snippet.
	 * @param string highlighted redirect snippet
	 * @param array $redirects Array of redirects stored as arrays with 'title' and 'namespace' keys
	 * @return Title object representing the redirect
	 */
	private function findRedirectTitle( $snippet, $redirects ) {
		$title = $this->stripHighlighting( $snippet );
		// Grab the redirect that matches the highlighted title with the lowest namespace.
		// That is pretty arbitrary but it prioritizes 0 over others.
		$best = null;
		foreach ( $redirects as $redirect ) {
			if ( $redirect[ 'title' ] === $title && ( $best === null || $best[ 'namespace' ] > $redirect ) ) {
				$best = $redirect;
			}
		}
		if ( $best === null ) {
			wfLogWarning( "Search backend highlighted a redirect ($title) but didn't return it." );
			return null;
		}
		return Title::makeTitleSafe( $best[ 'namespace' ], $best[ 'title' ] );
	}

	private function findSectionTitle() {
		$heading = $this->stripHighlighting( $this->sectionSnippet );
		return Title::makeTitle(
			$this->getTitle()->getNamespace(),
			$this->getTitle()->getDBkey(),
			Title::escapeFragmentForURL( $heading )
		);
	}

	private function stripHighlighting( $highlighted ) {
		$markers = array( Searcher::HIGHLIGHT_PRE, Searcher::HIGHLIGHT_POST );
		return str_replace( $markers, '', $highlighted );
	}

	public function getTitleSnippet( $terms ) {
		return $this->titleSnippet;
	}

	public function getRedirectTitle() {
		return $this->redirectTitle;
	}

	public function getRedirectSnippet( $terms ) {
		return $this->redirectSnipppet;
	}

	public function getTextSnippet( $terms ) {
		return $this->textSnippet;
	}

	public function getSectionSnippet() {
		return $this->sectionSnippet;
	}

	public function getSectionTitle() {
		return $this->sectionTitle;
	}

	public function getWordCount() {
		return $this->wordCount;
	}

	public function getByteSize() {
		return $this->byteSize;
	}

	public function getScore() {
		return $this->score;
	}

	public function getTimestamp() {
		return $this->timestamp->getTimestamp( TS_MW );
	}
}