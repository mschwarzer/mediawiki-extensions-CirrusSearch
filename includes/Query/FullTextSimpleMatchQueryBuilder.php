<?php

namespace CirrusSearch\Query;

use CirrusSearch\Search\Escaper;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\SearchConfig;

/**
 * Simple Match query builder, currently based on
 * FullTextQueryStringQueryBuilder to reuse its parsing logic.
 * It will only support queries that do not use the lucene QueryString syntax
 * and fallbacks to FullTextQueryStringQueryBuilder in such cases.
 * It generates only simple match/multi_match queries. It supports merging
 * multiple clauses into a dismax query with 'in_dismax'.
 */
class FullTextSimpleMatchQueryBuilder extends FullTextQueryStringQueryBuilder {
	/**
	 * @var bool true is the main used the experimental query
	 */
	private $usedExpQuery = false;

	/**
	 * @var float[]|array[] mixed array of field settings used for the main query
	 */
	private $fields;

	/**
	 * @var float[]|array[] mixed array of field settings used for the phrase rescore query
	 */
	private $phraseFields;

	/**
	 * @var float default weight to use for stems
	 */
	private $defaultStemWeight;

	/**
	 * @var string default multimatch query type
	 */
	private $defaultQueryType;

	/**
	 * @var string default multimatch min should match
	 */
	private $defaultMinShouldMatch;

	public function __construct( SearchConfig $config, Escaper $escaper, array $feature, array $settings ) {
		parent::__construct( $config, $escaper, $feature );
		$this->fields = $settings['fields'];
		$this->phraseFields = $settings['phrase_rescore_fields'];
		$this->defaultStemWeight = $settings['default_stem_weight'];
		$this->defaultQueryType = $settings['default_query_type'];
		$this->defaultMinShouldMatch = $settings['default_min_should_match'];
	}

	/**
	 * Build the primary query used for full text search.
	 * If query_string syntax is not used the experimental query is built.
	 * We fallback to parent implementation otherwize.
	 *
	 * @param SearchContext $context
	 * @param string[] $fields
	 * @param string[] $nearMatchFields
	 * @param string $queryString
	 * @param string $nearMatchQuery
	 * @return \Elastica\Query\AbstractQuery
	 */
	protected function buildSearchTextQuery( SearchContext $context, array $fields, array $nearMatchFields, $queryString, $nearMatchQuery ) {
		if ( $context->isSyntaxUsed( 'query_string' ) ) {
			return parent::buildSearchTextQuery( $context, $fields,
				$nearMatchFields, $queryString, $nearMatchQuery );
		}
		$this->usedExpQuery = true;
		$queryForMostFields = $this->buildExpQuery( $queryString );
		if ( !$nearMatchQuery ) {
			return $queryForMostFields;
		}

		// Build one query for the full text fields and one for the near match fields so that
		// the near match can run unescaped.
		$bool = new \Elastica\Query\BoolQuery();
		$bool->setMinimumNumberShouldMatch( 1 );
		$bool->addShould( $queryForMostFields );
		$nearMatch = new \Elastica\Query\MultiMatch();
		$nearMatch->setFields( $nearMatchFields );
		$nearMatch->setQuery( $nearMatchQuery );
		$bool->addShould( $nearMatch );

		return $bool;
	}

	/**
	 * Builds the highlight query
	 * @param SearchContext $context
	 * @param string[] $fields
	 * @param string $queryText
	 * @param int $slop
	 * @return \Elastica\Query\AbstractQuery
	 */
	protected function buildHighlightQuery( SearchContext $context, array $fields, $queryText, $slop ) {
		$query = parent::buildHighlightQuery( $context, $fields, $queryText, $slop );
		if ( $this->usedExpQuery && $query instanceof \Elastica\Query\QueryString ) {
			// the exp query accepts more docs (stopwords in query are not required)
			/** @suppress PhanUndeclaredMethod $query is a QueryString */
			$query->setDefaultOperator( 'OR' );
		}
		return $query;
	}

	/**
	 * Builds the phrase rescore query
	 * @param SearchContext $context
	 * @param string[] $fields
	 * @param string $queryText
	 * @param int $slop
	 * @return \Elastica\Query\AbstractQuery
	 */
	protected function buildPhraseRescoreQuery( SearchContext $context, array $fields, $queryText, $slop ) {
		if ( $this->usedExpQuery ) {
			$phrase = new \Elastica\Query\MultiMatch();
			$phrase->setParam( 'type', 'phrase' );
			$phrase->setParam( 'slop', $slop );
			$fields = array();
			foreach( $this->phraseFields as $f => $b ) {
				$fields[] = "$f^$b";
			}
			$phrase->setFields( $fields );
			$phrase->setQuery( $queryText );
			return $phrase;
		} else {
			return parent::buildPhraseRescoreQuery( $context, $fields, $queryText, $slop );
		}
	}

	/**
	 * Generate an elasticsearch query by reading profile settings
	 * @param string $queryString the query text
	 * @return \Elastica\Query\AbstractQuery
	 */
	private function buildExpQuery( $queryString ) {
		$query = new \Elastica\Query\BoolQuery();

		$all_filter = new \Elastica\Query\BoolQuery();
		// FIXME: We can't use solely the stem field here
		// - Depending on langauges it may lack stopwords,
		// - Diacritics are sometimes (english) strangely (T141216)
		// A dedicated field used for filtering would be nice
		$match = new \Elastica\Query\Match();
		$match->setField( 'all', array( "query" => $queryString ) );
		$match->setFieldOperator( 'all', 'AND' );
		$all_filter->addShould( $match );
		$match = new \Elastica\Query\Match();
		$match->setField( 'all.plain', array( "query" => $queryString ) );
		$match->setFieldOperator( 'all.plain', 'AND' );
		$all_filter->addShould( $match );
		$query->addFilter( $all_filter );
		$dismaxQueries = array();

		foreach( $this->fields as $f => $settings ) {
			$mmatch = new \Elastica\Query\MultiMatch();
			$mmatch->setQuery( $queryString );
			$queryType = $this->defaultQueryType;
			$minShouldMatch = $this->defaultMinShouldMatch;
			$stemWeight = $this->defaultStemWeight;
			$boost = 1;
			$fields = array( "$f.plain^1", "$f^$stemWeight" );
			$in_dismax = null;

			if ( is_array( $settings ) ) {
				$boost = isset( $settings['boost'] ) ? $settings['boost'] : $boost;
				$queryType = isset( $settings['query_type'] ) ? $settings['query_type'] : $queryType;
				$minShouldMatch = isset( $settings['min_should_match'] ) ? $settings['min_should_match'] : $minShouldMatch;
				if( isset( $settings['is_plain'] ) && $settings['is_plain'] ) {
					$fields = array( $f );
				} else {
					$fields = array( "$f.plain^1", "$f^$stemWeight" );
				}
				$in_dismax = isset( $settings['in_dismax'] ) ? $settings['in_dismax'] : null;
			} else {
				$boost = $settings;
			}

			if ( $boost === 0 ) {
				continue;
			}

			$mmatch->setParam( 'boost', $boost );
			$mmatch->setMinimumShouldMatch( $minShouldMatch );
			$mmatch->setType( $queryType );
			$mmatch->setFields( $fields );
			$mmatch->setParam( 'boost', $boost );
			$mmatch->setQuery( $queryString );
			if ( $in_dismax ) {
				$dismaxQueries[$in_dismax][] = $mmatch;
			} else {
				$query->addShould( $mmatch );
			}
		}
		foreach ( $dismaxQueries as $queries ) {
			$dismax = new \Elastica\Query\DisMax();
			foreach( $queries as $q ) {
				$dismax->addQuery( $q );
			}
			$query->addShould( $dismax );
		}
		// Removed in future lucene version https://issues.apache.org/jira/browse/LUCENE-7347
		$query->setParam( 'disable_coord', true );
		return $query;
	}
}
