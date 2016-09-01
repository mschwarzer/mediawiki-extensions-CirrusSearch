<?php

namespace CirrusSearch\Query;

use CirrusSearch\OtherIndexes;
use CirrusSearch\SearchConfig;
use CirrusSearch\Searcher;
use CirrusSearch\Search\Escaper;
use CirrusSearch\Search\SearchContext;
use MediaWiki\Logger\LoggerFactory;

/**
 * Builds an Elastica query backed by an elasticsearch QueryString query
 * Has many warts and edge cases that are hardly desirable.
 */
class FullTextQueryStringQueryBuilder implements FullTextQueryBuilder {
	/**
	 * @var SearchConfig
	 */
	protected $config;

	/**
	 * @var Escaper
	 */
	private $escaper;

	/**
	 * @var KeywordFeature[]
	 */
	private $features;

	/**
	 * @var string
	 */
	private $queryStringQueryString = '';

	/**
	 * @param SearchConfig $config
	 * @param Escaper $escaper
	 * @param KeywordFeature[] $features
	 * @param array[] $settings currently ignored
	 */
	public function __construct( SearchConfig $config, Escaper $escaper, array $features, array $settings = [] ) {
		$this->config = $config;
		$this->escaper = $escaper;
		$this->features = $features;
	}

	/**
	 * Search articles with provided term.
	 *
	 * @param SearchContext $context
	 * @param string $term term to search
	 * @param boolean $showSuggestion should this search suggest alternative
	 * searches that might be better?
	 */
	public function build( SearchContext $searchContext, $term, $showSuggestion ) {
		// Transform Mediawiki specific syntax to filters and extra
		// (pre-escaped) query string
		$searchContext->setSearchType( 'full_text' );

		foreach ( $this->features as $feature ) {
			$term = $feature->apply( $searchContext, $term );
		}

		if ( !$searchContext->areResultsPossible() ) {
			return;
		}

		$term = $this->escaper->escapeQuotes( $term );
		$term = trim( $term );

		// Match quoted phrases including those containing escaped quotes.
		// Those phrases can optionally be followed by ~ then a number (this is
		// the phrase slop). That can optionally be followed by a ~ (this
		// matches stemmed words in phrases). The following all match:
		//   "a", "a boat", "a\"boat", "a boat"~, "a boat"~9,
		//   "a boat"~9~, -"a boat", -"a boat"~9~
		$slop = $this->config->get('CirrusSearchPhraseSlop');
		$query = self::replacePartsOfQuery(
			$term,
			'/(?<![\]])(?<negate>-|!)?(?<main>"((?:[^"]|(?<=\\\)")+)"(?<slop>~\d+)?)(?<fuzzy>~)?/',
			function ( $matches ) use ( $searchContext, $slop ) {
				$negate = $matches[ 'negate' ][ 0 ] ? 'NOT ' : '';
				$main = $this->escaper->fixupQueryStringPart( $matches[ 'main' ][ 0 ] );

				if ( !$negate && !isset( $matches[ 'fuzzy' ] ) && !isset( $matches[ 'slop' ] ) &&
						 preg_match( '/^"([^"*]+)[*]"/', $main, $matches ) ) {
					$phraseMatch = new \Elastica\Query\Match( );
					$phraseMatch->setFieldQuery( "all.plain", $matches[1] );
					$phraseMatch->setFieldType( "all.plain", "phrase_prefix" );
					$searchContext->addNonTextQuery( $phraseMatch );

					$phraseHighlightMatch = new \Elastica\Query\QueryString( );
					$phraseHighlightMatch->setQuery( $matches[1] . '*' );
					$phraseHighlightMatch->setFields( [ 'all.plain' ] );
					$searchContext->addNonTextHighlightQuery( $phraseHighlightMatch );

					return [];
				}

				if ( !isset( $matches[ 'fuzzy' ] ) ) {
					if ( !isset( $matches[ 'slop' ] ) ) {
						$main = $main . '~' . $slop[ 'precise' ];
					}
					// Got to collect phrases that don't use the all field so we can highlight them.
					// The highlighter locks phrases to the fields that specify them.  It doesn't do
					// that with terms.
					return [
						'escaped' => $negate . self::switchSearchToExact( $searchContext, $main, true ),
						'nonAll' => $negate . self::switchSearchToExact( $searchContext, $main, false ),
					];
				}
				return [ 'escaped' => $negate . $main ];
			} );
		// Find prefix matches and force them to only match against the plain analyzed fields.  This
		// prevents prefix matches from getting confused by stemming.  Users really don't expect stemming
		// in prefix queries.
		$query = self::replaceAllPartsOfQuery( $query, '/\w+\*(?:\w*\*?)*/u',
			function ( $matches ) use ( $searchContext ) {
				$term = $this->escaper->fixupQueryStringPart( $matches[ 0 ][ 0 ] );
				return [
					'escaped' => self::switchSearchToExactForWildcards( $searchContext, $term ),
					'nonAll' => self::switchSearchToExactForWildcards( $searchContext, $term )
				];
			} );

		$escapedQuery = [];
		$nonAllQuery = [];
		$nearMatchQuery = [];
		foreach ( $query as $queryPart ) {
			if ( isset( $queryPart[ 'escaped' ] ) ) {
				$escapedQuery[] = $queryPart[ 'escaped' ];
				if ( isset( $queryPart[ 'nonAll' ] ) ) {
					$nonAllQuery[] = $queryPart[ 'nonAll' ];
				} else {
					$nonAllQuery[] = $queryPart[ 'escaped' ];
				}
				continue;
			}
			if ( isset( $queryPart[ 'raw' ] ) ) {
				$fixed = $this->escaper->fixupQueryStringPart( $queryPart[ 'raw' ] );
				$escapedQuery[] = $fixed;
				$nonAllQuery[] = $fixed;
				$nearMatchQuery[] = $queryPart[ 'raw' ];
				continue;
			}
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				'Unknown query part: {queryPart}',
				[ 'queryPart' => serialize( $queryPart ) ]
			);
		}

		// Actual text query
		list( $this->queryStringQueryString, $fuzzyQuery ) =
			$this->escaper->fixupWholeQueryString( implode( ' ', $escapedQuery ) );
		$searchContext->setFuzzyQuery( $fuzzyQuery );

		if ( $this->queryStringQueryString === '' ) {
			return;
		}

		// Note that no escaping is required for near_match's match query.
		$nearMatchQuery = implode( ' ', $nearMatchQuery );

		if ( preg_match( '/(?<!\\\\)[?*+~"!|-]|AND|OR|NOT/', $this->queryStringQueryString ) ) {
			$searchContext->addSyntaxUsed( 'query_string' );
			// We're unlikely to make good suggestions for query string with special syntax in them....
			$showSuggestion = false;
		}
		$fields = array_merge(
			self::buildFullTextSearchFields( $searchContext, 1, '.plain', true ),
			self::buildFullTextSearchFields( $searchContext, $this->config->get( 'CirrusSearchStemmedWeight' ), '', true ) );
		$nearMatchFields = self::buildFullTextSearchFields( $searchContext,
			$this->config->get( 'CirrusSearchNearMatchWeight' ), '.near_match', true );
		$searchContext->setMainQuery( $this->buildSearchTextQuery( $searchContext, $fields, $nearMatchFields,
			$this->queryStringQueryString, $nearMatchQuery ) );

		// The highlighter doesn't know about the weighting from the all fields so we have to send
		// it a query without the all fields.  This swaps one in.
		if ( $this->config->getElement( 'CirrusSearchAllFields', 'use' ) ) {
			$nonAllFields = array_merge(
				self::buildFullTextSearchFields( $searchContext, 1, '.plain', false ),
				self::buildFullTextSearchFields( $searchContext, $this->config->get( 'CirrusSearchStemmedWeight' ), '', false ) );
			list( $nonAllQueryString, /*_*/ ) = $this->escaper->fixupWholeQueryString( implode( ' ', $nonAllQuery ) );
			$searchContext->setHighlightQuery(
				$this->buildHighlightQuery( $searchContext, $nonAllFields, $nonAllQueryString, 1 )
			);
		} else {
			$nonAllFields = $fields;
		}

		// Only do a phrase match rescore if the query doesn't include any quotes and has a space.
		// Queries without spaces are either single term or have a phrase query generated.
		// Queries with the quote already contain a phrase query and we can't build phrase queries
		// out of phrase queries at this point.
		if ( $this->config->get( 'CirrusSearchPhraseRescoreBoost' ) > 0.0 &&
				$this->config->get( 'CirrusSearchPhraseRescoreWindowSize' ) &&
				!$searchContext->isSyntaxUsed() &&
				strpos( $this->queryStringQueryString, '"' ) === false &&
				strpos( $this->queryStringQueryString, ' ' ) !== false ) {

			$rescoreFields = $fields;
			if ( !$this->config->get( 'CirrusSearchAllFieldsForRescore' ) ) {
				$rescoreFields = $nonAllFields;
			}

			$searchContext->addRescore( [
				'window_size' => $this->config->get( 'CirrusSearchPhraseRescoreWindowSize' ),
				'query' => [
					'rescore_query' => $this->buildPhraseRescoreQuery(
						$searchContext,
						$rescoreFields,
						$this->queryStringQueryString,
						$this->config->getElement( 'CirrusSearchPhraseSlop', 'boost' )
					),
					'query_weight' => 1.0,
					'rescore_query_weight' => $this->config->get( 'CirrusSearchPhraseRescoreBoost' ),
				]
			] );
		}

		if ( $showSuggestion ) {
			$searchContext->setSuggest( [
				'text' => $term,
				'suggest' => $this->buildSuggestConfig( 'suggest', $searchContext ),
			] );
		}
	}

	/**
	 * Attempt to build a degraded query from the query already built into $context. Must be
	 * called *after* self::build().
	 *
	 * @param SearchContext $context
	 * @return bool True if a degraded query was built
	 */
	public function buildDegraded( SearchContext $searchContext ) {
		if ( $this->queryStringQueryString === '' ) {
			return false;
		}

		$fields = array_merge(
			self::buildFullTextSearchFields( $searchContext, 1, '.plain', true ),
			self::buildFullTextSearchFields( $searchContext, $this->config->get( 'CirrusSearchStemmedWeight' ), '', true )
		);

		$searchContext->setSearchType( 'degraded_full_text' );
		$searchContext->setMainQuery( new \Elastica\Query\Simple( [ 'simple_query_string' => [
			'fields' => $fields,
			'query' => $this->queryStringQueryString,
			'default_operator' => 'AND',
		] ] ) );
		$searchContext->clearRescore();

		return true;
	}

	/**
	 * Build suggest config for $field.
	 *
	 * @param string $field field to suggest against
	 * @param SearchContext $searchContext
	 * @return array[] array of Elastica configuration
	 */
	private function buildSuggestConfig( $field, $searchContext ) {
		// check deprecated settings
		$suggestSettings = $this->config->get( 'CirrusSearchPhraseSuggestSettings' );
		$maxErrors = $this->config->get( 'CirrusSearchPhraseSuggestMaxErrors' );
		if ( isset( $maxErrors ) ) {
			$suggestSettings['max_errors'] = $maxErrors;
		}
		$confidence = $this->config->get( 'CirrusSearchPhraseSuggestMaxErrors' );
		if ( isset( $confidence ) ) {
			$suggestSettings['confidence'] = $confidence;
		}

		$settings = [
			'phrase' => [
				'field' => $field,
				'size' => 1,
				'max_errors' => $suggestSettings['max_errors'],
				'confidence' => $suggestSettings['confidence'],
				'real_word_error_likelihood' => $suggestSettings['real_word_error_likelihood'],
				'direct_generator' => [
					[
						'field' => $field,
						'suggest_mode' => $suggestSettings['mode'],
						'max_term_freq' => $suggestSettings['max_term_freq'],
						'min_doc_freq' => $suggestSettings['min_doc_freq'],
						'prefix_length' => $suggestSettings['prefix_length'],
					],
				],
				'highlight' => [
					'pre_tag' => Searcher::SUGGESTION_HIGHLIGHT_PRE,
					'post_tag' => Searcher::SUGGESTION_HIGHLIGHT_POST,
				],
			],
		];
		$extraIndexes = null;
		if ( $searchContext->getNamespaces() ) {
			$extraIndexes = OtherIndexes::getExtraIndexesForNamespaces(
				$searchContext->getNamespaces()
			);
		}
		// Add a second generator with the reverse field
		// Only do this for local queries, we don't know if it's activated
		// on other wikis.
		if ( empty( $extraIndexes )
			&& $this->config->getElement( 'CirrusSearchPhraseSuggestReverseField', 'use' )
		) {
			$settings['phrase']['direct_generator'][] = [
				'field' => $field . '.reverse',
				'suggest_mode' => $suggestSettings['mode'],
				'max_term_freq' => $suggestSettings['max_term_freq'],
				'min_doc_freq' => $suggestSettings['min_doc_freq'],
				'prefix_length' => $suggestSettings['prefix_length'],
				'pre_filter' => 'token_reverse',
				'post_filter' => 'token_reverse'
			];
		}
		if ( !empty( $suggestSettings['collate'] ) ) {
			$collateFields = ['title.plain', 'redirect.title.plain'];
			if ( $this->config->get( 'CirrusSearchPhraseSuggestUseText' )  ) {
				$collateFields[] = 'text.plain';
			}
			$settings['phrase']['collate'] = [
				'query' => [
					'inline' => [
						'multi_match' => [
							'query' => '{{suggestion}}',
							'operator' => 'or',
							'minimum_should_match' => $suggestSettings['collate_minimum_should_match'],
							'type' => 'cross_fields',
							'fields' => $collateFields
						],
					],
				],
			];
		}
		if( isset( $suggestSettings['smoothing_model'] ) ) {
			$settings['phrase']['smoothing'] = $suggestSettings['smoothing_model'];
		}

		return $settings;
	}

	/**
	 * Build the primary query used for full text search. This will be a
	 * QueryString query, and optionally a MultiMatch if a $nearMatchQuery
	 * is provided.
	 *
	 * @param SearchContext $searchContext
	 * @param string[] $fields
	 * @param string[] $nearMatchFields
	 * @param string $queryString
	 * @param string $nearMatchQuery
	 * @return \Elastica\Query\AbstractQuery
	 */
	protected function buildSearchTextQuery( SearchContext $context, array $fields, array $nearMatchFields, $queryString, $nearMatchQuery ) {
		$slop = $this->config->getElement( 'CirrusSearchPhraseSlop', 'default' );
		$queryForMostFields = $this->buildQueryString( $fields, $queryString, $slop );
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
	 * Builds the query using the QueryString, this is the default builder
	 * used by cirrus and uses a default AND between clause.
	 * The query 'the query' and the fields all and all.plain will be like
	 * (all:the OR all.plain:the) AND (all:query OR all.plain:query)
	 *
	 * @param string[] $fields the fields
	 * @param string $queryString the query
	 * @param integer $phraseSlop phrase slop
	 * @return \Elastica\Query\QueryString
	 */
	private function buildQueryString( array $fields, $queryString, $phraseSlop ) {
		$query = new \Elastica\Query\QueryString( $queryString );
		$query->setFields( $fields );
		$query->setAutoGeneratePhraseQueries( true );
		$query->setPhraseSlop( $phraseSlop );
		$query->setDefaultOperator( 'AND' );
		$query->setAllowLeadingWildcard( (bool) $this->config->get( 'CirrusSearchAllowLeadingWildcard' ) );
		$query->setFuzzyPrefixLength( 2 );
		$query->setRewrite( $this->getMultiTermRewriteMethod() );
		$states = $this->config->get( 'CirrusSearchQueryStringMaxDeterminizedStates' );
		if ( isset( $states ) ) {
			$query->setParam( 'max_determinized_states', $states );
		}
		return $query;
	}

	/**
	 * the rewrite method to use for multi term queries
	 * @return string
	 */
	protected function getMultiTermRewriteMethod() {
		return 'top_terms_boost_1024';
	}

	/**
	 * Expand wildcard queries to the all.plain and title.plain fields if
	 * wgCirrusSearchAllFields[ 'use' ] is set to true. Fallback to all
	 * the possible fields otherwise. This prevents applying and compiling
	 * costly wildcard queries too many times.
	 *
	 * @param string $term
	 * @return string
	 */
	private static function switchSearchToExactForWildcards( SearchContext $context, $term ) {
		// Try to limit the expansion of wildcards to all the subfields
		// We still need to add title.plain with a high boost otherwise
		// match in titles be poorly scored (actually it breaks some tests).
		if ( $context->getConfig()->getElement( 'CirrusSearchAllFields', 'use' ) ) {
			$titleWeight = $context->getConfig()->getElement( 'CirrusSearchWeights', 'title' );
			$fields = [];
			$fields[] = "title.plain:$term^${titleWeight}";
			$fields[] = "all.plain:$term";
			$exact = join( ' OR ', $fields );
			return "($exact)";
		} else {
			return self::switchSearchToExact( $context, $term, false );
		}
	}

	/**
	 * Build a QueryString query where all fields being searched are
	 * queried for $term, joined with an OR. This is primarily for the
	 * benefit of the highlighter, the primary search is typically against
	 * the special all field.
	 *
	 * @param SearchContext $context
	 * @param string $term
	 * @param boolean $allFieldAllowed
	 * @return string
	 */
	private static function switchSearchToExact( SearchContext $context, $term, $allFieldAllowed ) {
		$exact = join( ' OR ', self::buildFullTextSearchFields( $context, 1, ".plain:$term", $allFieldAllowed ) );
		return "($exact)";
	}

	/**
	 * Build fields searched by full text search.
	 *
	 * @param float $weight weight to multiply by all fields
	 * @param string $fieldSuffix suffix to add to field names
	 * @param boolean $allFieldAllowed can we use the all field?  False for
	 *  collecting phrases for the highlighter.
	 * @return string[] array of fields to query
	 */
	private static function buildFullTextSearchFields( SearchContext $context, $weight, $fieldSuffix, $allFieldAllowed ) {
		$searchWeights = $context->getConfig()->get( 'CirrusSearchWeights' );

		if ( $allFieldAllowed && $context->getConfig()->getElement( 'CirrusSearchAllFields', 'use' ) ) {
			if ( $fieldSuffix === '.near_match' ) {
				// The near match fields can't shard a root field because field fields need it -
				// thus no suffix all.
				return [ "all_near_match^${weight}" ];
			}
			return [ "all${fieldSuffix}^${weight}" ];
		}

		$fields = [];
		// Only title and redirect support near_match so skip it for everything else
		$titleWeight = $weight * $searchWeights[ 'title' ];
		$redirectWeight = $weight * $searchWeights[ 'redirect' ];
		if ( $fieldSuffix === '.near_match' ) {
			$fields[] = "title${fieldSuffix}^${titleWeight}";
			$fields[] = "redirect.title${fieldSuffix}^${redirectWeight}";
			return $fields;
		}
		$fields[] = "title${fieldSuffix}^${titleWeight}";
		$fields[] = "redirect.title${fieldSuffix}^${redirectWeight}";
		$categoryWeight = $weight * $searchWeights[ 'category' ];
		$headingWeight = $weight * $searchWeights[ 'heading' ];
		$openingTextWeight = $weight * $searchWeights[ 'opening_text' ];
		$textWeight = $weight * $searchWeights[ 'text' ];
		$auxiliaryTextWeight = $weight * $searchWeights[ 'auxiliary_text' ];
		$fields[] = "category${fieldSuffix}^${categoryWeight}";
		$fields[] = "heading${fieldSuffix}^${headingWeight}";
		$fields[] = "opening_text${fieldSuffix}^${openingTextWeight}";
		$fields[] = "text${fieldSuffix}^${textWeight}";
		$fields[] = "auxiliary_text${fieldSuffix}^${auxiliaryTextWeight}";
		$namespaces = $context->getNamespaces();
		if ( !$namespaces || in_array( NS_FILE, $namespaces ) ) {
			$fileTextWeight = $weight * $searchWeights[ 'file_text' ];
			$fields[] = "file_text${fieldSuffix}^${fileTextWeight}";
		}
		return $fields;
	}

	/**
	 * Walks through an array of query pieces, as built by
	 * self::replacePartsOfQuery, and replaecs all raw pieces by the result of
	 * self::replacePartsOfQuery when called with the provided regex and
	 * callable. One query piece may turn into one or more query pieces in the
	 * result.
	 *
	 * @param array[] $query The set of query pieces to apply against
	 * @param string $regex Pieces of $queryPart that match this regex will
	 *  be provided to $callable
	 * @param callable $callable A function accepting the $matches from preg_match
	 *  and returning either a raw or escaped query piece.
	 * @return array[] The set of query pieces after applying regex and callable
	 */
	private static function replaceAllPartsOfQuery( array $query, $regex, $callable ) {
		$result = [];
		foreach ( $query as $queryPart ) {
			if ( isset( $queryPart[ 'raw' ] ) ) {
				$result = array_merge( $result, self::replacePartsOfQuery( $queryPart[ 'raw' ], $regex, $callable ) );
			} else {
				$result[] = $queryPart;
			}
		}
		return $result;
	}

	/**
	 * Splits a query string into one or more sequential pieces. Each piece
	 * of the query can either be raw (['raw'=>'stuff']), or escaped
	 * (['escaped'=>'stuff']). escaped can also optionally include a nonAll
	 * query (['escaped'=>'stuff','nonAll'=>'stuff']). If nonAll is not set
	 * the escaped query will be used.
	 *
	 * Piees of $queryPart that do not match the provided $regex are tagged
	 * as 'raw' and may see further parsing. $callable receives pieces of
	 * the string that match the regex and must return either a raw or escaped
	 * query piece.
	 *
	 * @param string $queryPart Raw piece of a user supplied query string
	 * @param string $regex Pieces of $queryPart that match this regex will
	 *  be provided to $callable
	 * @param callable $callable A function accepting the $matches from preg_match
	 *  and returning either a raw or escaped query piece.
	 * @return array[] The sequential set of quer ypieces $queryPart was
	 *  converted into.
	 */
	private static function replacePartsOfQuery( $queryPart, $regex, $callable ) {
		$destination = [];
		$matches = [];
		$offset = 0;
		while ( preg_match( $regex, $queryPart, $matches, PREG_OFFSET_CAPTURE, $offset ) ) {
			$startOffset = $matches[0][1];
			if ( $startOffset > $offset ) {
				$destination[] = [
					'raw' => substr( $queryPart, $offset, $startOffset - $offset )
				];
			}

			$callableResult = call_user_func( $callable, $matches );
			if ( $callableResult ) {
				$destination[] = $callableResult;
			}

			$offset = $startOffset + strlen( $matches[0][0] );
		}

		if ( $offset < strlen( $queryPart ) ) {
			$destination[] = [
				'raw' => substr( $queryPart, $offset ),
			];
		}

		return $destination;
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
		return $this->buildQueryString( $fields, $queryText, $slop );
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
		return $this->buildQueryString( $fields, '"' . $queryText . '"', $slop );
	}
}
