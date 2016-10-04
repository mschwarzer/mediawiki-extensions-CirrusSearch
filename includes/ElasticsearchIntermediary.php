<?php

namespace CirrusSearch;

use DeferredUpdates;
use Elastica\Client;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use RequestContext;
use SearchResultSet;
use Status;
use Title;
use User;

/**
 * Base class with useful functions for communicating with Elasticsearch.
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
class ElasticsearchIntermediary {
	/**
	 * @const int max number of results to store in CirrusSearchRequestSet logs (per request)
	 */
	const LOG_MAX_RESULTS = 50;

	/**
	 * @var Connection
	 */
	protected $connection;
	/**
	 * @var User|null user for which we're performing this search or null in the case of
	 * requests kicked off by jobs
	 */
	protected $user;
	/**
	 * @var float|null start time of current request or null if none is running
	 */
	private $requestStart = null;
	/**
	 * @var string|null description of the next request to be sent to Elasticsearch or null if not yet decided
	 */
	private $description = null;
	/**
	 * @var array map of search request stats to log about the current search request
	 */
	protected $logContext = [];

	/**
	 * @var int how many millis a request through this intermediary needs to take before it counts as slow.
	 * 0 means none count as slow.
	 */
	private $slowMillis;

	/**
	 * @var array Metrics about a completed search
	 */
	private $searchMetrics = [];

	/**
	 * @var array[] Result of self::getLogContext for each request in this process
	 */
	static private $logContexts = [];

	/**
	 * @var array[string] Result page ids that were returned to user
	 */
	static private $resultTitleStrings = [];

	/**
	 * @var int artificial extra backend latency in micro seconds
	 */
	private $extraBackendLatency;

	/**
	 * Constructor.
	 *
	 * @param Connection $connection
	 * @param User|null $user user for which this search is being performed.  Attached to slow request logs.  Note that
	 * null isn't for anonymous users - those are still User objects and should be provided if possible.  Null is for
	 * when the action is being performed in some context where the user that caused it isn't available.  Like when an
	 * action is being performed during a job.
	 * @param float $slowSeconds how many seconds a request through this intermediary needs to take before it counts as
	 * slow.  0 means none count as slow.
	 * @param float $extraBackendLatency artificial backend latency.
	 */
	protected function __construct( Connection $connection, User $user = null, $slowSeconds, $extraBackendLatency = 0 ) {
		$this->connection = $connection;
		if ( is_null( $user ) ) {
			$user = RequestContext::getMain()->getUser();
		}
		$this->user = $user;
		$this->slowMillis = (int) ( 1000 * $slowSeconds );
		$this->extraBackendLatency = $extraBackendLatency;

		// This isn't explicitly used, but we need to make sure it is
		// instantiated so it has the opportunity to override global
		// configuration for test buckets.
		UserTesting::getInstance();
	}

	/**
	 * Summarizes all the requests made in this process and reports
	 * them along with the test they belong to.
	 */
	private static function reportLogContexts() {
		if ( !self::$logContexts ) {
			return;
		}
		self::buildRequestSetLog();
		self::$logContexts = [];
	}

	/**
	 * Builds and ships a log context that is serialized to an avro
	 * schema. Avro is very specific that all fields must be defined,
	 * even if they have a default, and that types must match exactly.
	 * "5" is not an int as much as php would like it to be.
	 *
	 * Avro will happily ignore fields that are present but not used. To
	 * add new fields to the schema they must first be added here and
	 * deployed. Then the schema can be updated. Removing goes in reverse,
	 * adjust the schema to ignore the column, then deploy code no longer
	 * providing it.
	 */
	private static function buildRequestSetLog() {
		global $wgRequest;

		// for the moment these are still created in the old format to serve
		// the old log formats, so here we transform the context into the new
		// request format. At some point the context should just be created in
		// the correct format.
		$requests = [];
		$allCached = true;
		$allHits = [];
		foreach ( self::$logContexts as $context ) {
			$request = [
				'query' => isset( $context['query'] ) ? (string) $context['query'] : '',
				'queryType' => isset( $context['queryType'] ) ? (string) $context['queryType'] : '',
				// populated below
				'indices' => [],
				'tookMs' => isset( $context['tookMs'] ) ? (int) $context['tookMs'] : -1,
				'elasticTookMs' => isset( $context['elasticTookMs'] ) ? (int) $context['elasticTookMs'] : -1,
				'limit' => isset( $context['limit'] ) ? (int) $context['limit'] : -1,
				'hitsTotal' => isset( $context['hitsTotal'] ) ? (int) $context['hitsTotal'] : -1,
				'hitsReturned' => isset( $context['hitsReturned'] ) ? (int) $context['hitsReturned'] : -1,
				'hitsOffset' => isset( $context['hitsOffset'] ) ? (int) $context['hitsOffset'] : -1,
				// populated below
				'namespaces' => [],
				'suggestion' => isset( $context['suggestion'] ) ? (string) $context['suggestion'] : '',
				'suggestionRequested' => isset( $context['suggestion'] ),
				'maxScore' => isset( $context['maxScore'] ) ? $context['maxScore'] : -1,
				'payload' => [],
				'hits' => isset( $context['hits'] ) ? array_slice( $context['hits'], 0, self::LOG_MAX_RESULTS ) : [],
			];
			if ( isset( $context['hits'] ) ) {
				$allHits = array_merge( $allHits, $context['hits'] );
			}
			if ( isset( $context['index'] ) ) {
				$request['indices'][] = $context['index'];
			}
			if ( isset( $context['namespaces'] ) ) {
				foreach ( $context['namespaces'] as $nsId ) {
					$request['namespaces'][] = (int) $nsId;
				}
			}
			if ( !empty( $context['langdetect' ] ) ) {
				$request['payload']['langdetect'] = (string) $context['langdetect'];
			}
			if ( isset( $context['cached'] ) && $context['cached'] ) {
				$request['payload']['cached'] = 'true';
			} else {
				$allCached = false;
			}

			if ( isset( $context['timing'] ) ) {
				$start = 0;
				if ( isset( $context['timing']['start'] ) ) {
					$start = $context['timing']['start'];
					unset( $context['timing']['start'] );
				}
				foreach ( $context['timing'] as $name => $time ) {
					$request['payload']["timing-$name"] = (string) intval(( $time - $start ) * 1000);
				}
			}

			$requests[] = $request;
		}

		// Note that this is only accurate for hhvm and php-fpm
		// since they close the request to the user before running
		// deferred updates.
		$timing = \RequestContext::getMain()->getTiming();
		$startMark = $timing->getEntryByName( 'requestStart' );
		$endMark  = $timing->getEntryByName( 'requestShutdown' );
		if ( $startMark && $endMark ) {
			// should always work, but Timing can return null so
			// fallbacks are provided.
			$tookS = $endMark['startTime'] - $startMark['startTime'];
		} elseif( isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
			// php >= 5.4
			$tookS = microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'];
		} else {
			// php 5.3
			$tookS = microtime( true ) - $_SERVER['REQUEST_TIME'];
		}

		// Reindex allHits by page title's. It's maybe not perfect, but it's
		// hopefully a "close enough" representation of where our final result
		// set came from. maybe :(
		$allHitsByTitle = [];
		foreach ( $allHits as $hit ) {
			$allHitsByTitle[$hit['title']] = $hit;
		}
		$resultHits = [];
		// FIXME: temporary hack to investigate why SpecialSearch can display results
		// that do not come from cirrus.
		$bogusResult = null;
		foreach ( self::$resultTitleStrings as $titleString ) {
			// Track only the first missing title.
			if ( $bogusResult === null && !isset( $allHitsByTitle[$titleString] ) ) {
				$bogusResult = $titleString;
			}

			$hit = isset( $allHitsByTitle[$titleString] ) ? $allHitsByTitle[$titleString] : [];
			// Apply defaults to ensure all properties are accounted for.
			$resultHits[] = $hit + [
				'title' => $titleString,
				'index' => "",
				'pageId' => -1,
				'score' => -1,
				'profileName' => ""
			];
		}

		$requestSet = [
			'id' => Util::getRequestSetToken(),
			'ts' => time(),
			'wikiId' => wfWikiID(),
			'source' => Util::getExecutionContext(),
			'identity' => Util::generateIdentToken(),
			'ip' => $wgRequest->getIP() ?: '',
			'userAgent' => $wgRequest->getHeader( 'User-Agent') ?: '',
			'backendUserTests' => UserTesting::getInstance()->getActiveTestNamesWithBucket(),
			'tookMs' => 1000 * $tookS,
			'hits' => array_slice( $resultHits, 0, self::LOG_MAX_RESULTS ),
			'payload' => [
				// useful while we are testing accept-lang based interwiki
				'acceptLang' => (string) ($wgRequest->getHeader( 'Accept-Language' ) ?: ''),
				// Helps to track down what actually caused the request. Will promote to full
				// param if it proves useful
				'queryString' => http_build_query( $_GET ),
				// When tracking down performance issues it is useful to know if they are localized
				// to a particular set of instances
				'host' => gethostname(),
			],
			'requests' => $requests,
		];

		if ( $bogusResult !== null ) {
			if ( is_string( $bogusResult ) ) {
				$requestSet['payload']['bogusResult'] = $bogusResult;
			} else {
				$requestSet['payload']['bogusResult'] = 'NOT_A_STRING?: ' . gettype( $bogusResult );
			}
		}

		if ( $allCached ) {
			$requestSet['payload']['cached'] = 'true';
		}

		LoggerFactory::getInstance( 'CirrusSearchRequestSet' )->debug( '', $requestSet );
	}

	/**
	 * This is set externally because we don't have complete control, from the
	 * SearchEngine interface, of what is actually sent to the user. Instead hooks
	 * receive the final results that will be sent to the user and set them here.
	 *
	 * Accepts two result sets because some places (Special:Search) perform multiple
	 * searches. This can be called multiple times, but only that last call wins. For
	 * API's that is correct, for Special:Search a hook catches the final results and
	 * sets them here.
	 *
	 * @param array[Search\ResultSet|null] $matches
	 */
	public static function setResultPages( array $matches ) {
		$titleStrings = [];
		foreach ( $matches as $resultSet ) {
			if ( $resultSet !== null ) {
				$titleStrings = array_merge( $titleStrings, self::extractTitleStrings( $resultSet ) );
			}
		}
		self::$resultTitleStrings = $titleStrings;
	}

	private static function extractTitleStrings( SearchResultSet $matches ) {
		$strings = [];
		$result = $matches->next();
		while ( $result ) {
			$strings[] = (string) $result->getTitle();
			$result = $matches->next();
		}
		$matches->rewind();
		return $strings;
	}

	/**
	 * Report the types of queries that were issued
	 * within the current request.
	 *
	 * @return string[]
	 */
	public static function getQueryTypesUsed() {
		$types = [];
		foreach ( self::$logContexts as $context ) {
			if ( isset( $context['queryType'] ) ) {
				$types[] = $context['queryType'];
			}
		}
		return array_unique( $types );
	}

	/**
	 * Mark the start of a request to Elasticsearch.  Public so it can be called from pool counter methods.
	 *
	 * @param string $description name of the action being started
	 * @param array $logContext Contextual variables for generating log messages
	 */
	public function start( $description, array $logContext = [] ) {
		$this->description = $description;
		$this->logContext = $logContext;
		$this->requestStart = microtime( true );
		if ( $this->extraBackendLatency ) {
			usleep( $this->extraBackendLatency );
		}
	}

	/**
	 * Log a successful request and return the provided result in a good Status.  If you don't need the status
	 * just ignore the return.  Public so it can be called from pool counter methods.
	 *
	 * @param mixed $result result of the request.  defaults to null in case the request doesn't have a result
	 * @return Status wrapping $result
	 */
	public function success( $result = null ) {
		$this->finishRequest();
		return Status::newGood( $result );
	}

	/**
	 * Log a successful request when the response comes from a cache outside elasticsearch.
	 * @param string $description name of the action being started
	 * @param array $logContext Contextual variables for generating log messages
	 */
	public function successViaCache( $description, array $logContext = [] ) {
		global $wgCirrusSearchLogElasticRequests;

		$this->description = $description;
		$logContext['cached'] = true;
		$this->logContext = $logContext;

		$logContext = $this->buildLogContext( -1, null );
		if ( $wgCirrusSearchLogElasticRequests ) {
			$logMessage = $this->buildLogMessage( $logContext );
			LoggerFactory::getInstance( 'CirrusSearchRequests' )->debug( $logMessage, $logContext );
		}
		$this->requestStart = null;
	}

	/**
	 * Log a failure and return an appropriate status.  Public so it can be called from pool counter methods.
	 *
	 * @param \Elastica\Exception\ExceptionInterface|null $exception if the request failed
	 * @return Status representing a backend failure
	 */
	public function failure( \Elastica\Exception\ExceptionInterface $exception = null ) {
		$context = $this->logContext;
		$context['took'] = $this->finishRequest();
		list( $status, $message ) = ElasticaErrorHandler::extractMessageAndStatus( $exception );
		$context['message'] = $message;

		$stats = MediaWikiServices::getInstance()->getStatsdDataFactory();
		$type = ElasticaErrorHandler::classifyError( $exception );
		$clusterName = $this->connection->getClusterName();
		$stats->increment( "CirrusSearch.$clusterName.backend_failure.$type" );

		LoggerFactory::getInstance( 'CirrusSearch' )->warning(
			"Search backend error during {$this->description} after {took}: {message}",
			$context
		);
		return $status;
	}

	/**
	 * Get the search metrics we have
	 * @return array
	 */
	public function getSearchMetrics() {
		return $this->searchMetrics;
	}

	/**
	 * Log the completion of a request to Elasticsearch.
	 * @return int|null number of milliseconds it took to complete the request
	 */
	private function finishRequest() {
		global $wgCirrusSearchLogElasticRequests;

		if ( !$this->requestStart ) {
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				'finishRequest called without staring a request'
			);
			return null;
		}
		$endTime = microtime( true );
		$took = (int) ( ( $endTime - $this->requestStart ) * 1000 );
		$clusterName = $this->connection->getClusterName();
		$stats = MediaWikiServices::getInstance()->getStatsdDataFactory();
		$stats->timing( "CirrusSearch.$clusterName.requestTime", $took );
		$this->searchMetrics['wgCirrusStartTime'] = $this->requestStart;
		$this->searchMetrics['wgCirrusEndTime'] = $endTime;
		$logContext = $this->buildLogContext( $took, $this->connection->getClient() );
		$type = isset( $logContext['queryType'] ) ? $logContext['queryType'] : 'unknown';
		$stats->timing( "CirrusSearch.$clusterName.requestTimeMs.$type", $took );
		if ( isset( $logContext['elasticTookMs'] ) ) {
			$this->searchMetrics['wgCirrusElasticTime'] = $logContext['elasticTookMs'];
		}
		if ( $wgCirrusSearchLogElasticRequests ) {
			$logMessage = $this->buildLogMessage( $logContext );
			LoggerFactory::getInstance( 'CirrusSearchRequests' )->debug( $logMessage, $logContext );
			if ( $this->slowMillis && $took >= $this->slowMillis ) {
				if ( $this->user ) {
					$logContext['user'] = $this->user->getName();
					$logMessage .= ' for {user}';
				}
				LoggerFactory::getInstance( 'CirrusSearchSlowRequests' )->info( $logMessage, $logContext );
			}
		}
		$this->requestStart = null;
		return $took;
	}

	/**
	 * @param array $context Request specific log variables from self::buildLogContext()
	 * @return string a PSR-3 compliant message describing $context
	 */
	private function buildLogMessage( array $context ) {
		// No need to check description because it must be set by $this->start.
		$message = $this->description;
		$message .= " against {index} took {tookMs} millis";
		if ( isset( $context['elasticTookMs'] ) ) {
			$message .= " and {elasticTookMs} Elasticsearch millis";
			if ( isset( $context['elasticTook2PassMs'] ) ) {
				$message .= " (with 2nd pass: {elasticTook2PassMs} ms)";
			}
		}
		if ( isset( $context['hitsTotal'] ) ){
			$message .= ". Found {hitsTotal} total results";
			$message .= " and returned {hitsReturned} of them starting at {hitsOffset}";
		}
		if ( isset( $context['namespaces'] ) ) {
			$namespaces = implode( ', ', $context['namespaces'] );
			$message .= " within these namespaces: $namespaces";
		}
		if ( isset( $context['suggestion'] ) && strlen( $context['suggestion'] ) > 0 ) {
			$message .= " and suggested '{suggestion}'";
		}
		$message .= ". Requested via {source} for {identity} by executor {executor}";

		return $message;
	}

	/**
	 * These values end up serialized into Avro which has strict typing
	 * requirements. float !== int !== string.
	 *
	 * Note that this really only handles the "standard" search response
	 * format from elasticsearch. The completion suggester is a bit of a
	 * special snowflake in that it has a completely different response
	 * format than other searches. The CirrusSearch\CompletionSuggester
	 * class is responsible for providing any useful logging data by adding
	 * directly to $this->logContext.
	 *
	 * @param float $took Number of milliseconds the request took
	 * @param Client|null $client
	 * @return array
	 */
	private function buildLogContext( $took, Client $client = null ) {
		global $wgCirrusSearchLogElasticRequests;

		if ( $client ) {
			$query = $client->getLastRequest();
			$result = $client->getLastResponse();
		} else {
			$query = null;
			$result = null;
		}

		$params = $this->logContext;
		$this->logContext = [];

		$params += [
			'tookMs' => intval( $took ),
			'source' => Util::getExecutionContext(),
			'executor' => Util::getExecutionId(),
			'identity' => Util::generateIdentToken(),
		];

		if ( $result ) {
			$queryData = $query->getData();
			$resultData = $result->getData();

			$index = explode( '/', $query->getPath() );
			$params['index'] = $index[0];
			if ( isset( $resultData[ 'took' ] ) ) {
				$elasticTook = $resultData[ 'took' ];
				$params['elasticTookMs'] = intval( $elasticTook );
			}
			if ( isset( $resultData['hits']['total'] ) ) {
				$params['hitsTotal'] = intval( $resultData['hits']['total'] );
			}
			if ( isset( $resultData['hits']['max_score'] ) ) {
				$params['maxScore'] = $resultData['hits']['max_score'];
			}
			if ( isset( $resultData['hits']['hits'] ) ) {
				$num = count( $resultData['hits']['hits'] );
				$offset = isset( $queryData['from'] ) ? $queryData['from'] : 0;
				$params['hitsReturned'] = $num;
				$params['hitsOffset'] = intval( $offset );
				$params['hits'] = [];
				foreach ( $resultData['hits']['hits'] as $hit ) {
					if ( !isset( $hit['_source']['namespace'] )
						|| !isset( $hit['_source']['title'] )
					) {
						// This is probably a query that does not return pages
						// like geo or namespace queries
						continue;
					}
					// duplication of work ... this happens in the transformation
					// stage but we can't see that here...Perhaps we instead attach
					// this data at a later stage like CompletionSuggester?
					$title = Title::makeTitle( $hit['_source']['namespace'], $hit['_source']['title'] );
					$params['hits'][] = [
						// This *must* match the names and types of the CirrusSearchHit
						// record in the CirrusSearchRequestSet logging channel avro schema.
						'title' => (string) $title,
						'index' => isset( $hit['_index'] ) ? $hit['_index'] : "",
						'pageId' => isset( $hit['_id'] ) ? (int) $hit['_id'] : -1,
						'score' => isset( $hit['_score'] ) ? (float) $hit['_score'] : -1,
						// only comp_suggest has profileName, and that is handled
						// elsewhere
						'profileName' => "",
					];
				}
			}
			if ( isset( $queryData['query']['filtered']['filter']['terms']['namespace'] ) ) {
				$namespaces = $queryData['query']['filtered']['filter']['terms']['namespace'];
				$params['namespaces'] = array_map( 'intval', $namespaces );
			}
			if ( isset( $resultData['suggest']['suggest'][0]['options'][0]['text'] ) ) {
				$params['suggestion'] = $resultData['suggest']['suggest'][0]['options'][0]['text'];
			}
		}

		if ( $wgCirrusSearchLogElasticRequests ) {
			if ( count( self::$logContexts ) === 0 ) {
				DeferredUpdates::addCallableUpdate( function () {
					ElasticsearchIntermediary::reportLogContexts();
				} );
			}
			self::$logContexts[] = $params;
		}

		return $params;
	}

	/**
	 * @param array $values
	 */
	static public function appendLastLogContext( array $values ) {
		$idx = count( self::$logContexts ) - 1;
		if ( $idx >= 0 ) {
			self::$logContexts[$idx] += $values;
		}
	}
}
