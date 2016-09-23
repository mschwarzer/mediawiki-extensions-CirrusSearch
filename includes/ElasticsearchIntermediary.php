<?php

namespace CirrusSearch;

use DeferredUpdates;
use Elastica\Client;
use Elastica\Exception\PartialShardFailureException;
use Elastica\Exception\ResponseException;
use FormatJson;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use RequestContext;
use SearchResultSet;
use Status;
use Title;
use User;
use UIDGenerator;

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
	 * @var UserTesting Reports on this requests participation in tests
	 */
	protected $ut;
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
	 * @var string Id identifying this php execution
	 */
	static private $executionId;

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
		$this->ut = UserTesting::getInstance();
	}

	/**
	 * Identifies a specific execution of php.  That might be one web
	 * request, or multiple jobs run in the same executor. An execution id
	 * is valid over a brief timespan, perhaps a minute or two for some jobs.
	 *
	 * @return string unique identifier
	 */
	private static function getExecutionId() {
		if ( self::$executionId === null ) {
			self::$executionId = mt_rand();
		}
		return self::$executionId;
	}

	/**
	 * Unit tests only
	 */
	public static function resetExecutionId() {
		self::$executionId = null;
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
		self::buildUserTestingLog();
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
			'id' => self::getRequestSetToken(),
			'ts' => time(),
			'wikiId' => wfWikiID(),
			'source' => self::getExecutionContext(),
			'identity' => self::generateIdentToken(),
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
	 * Get a token that (hopefully) uniquely identifies this search. It will be
	 * added to the search result page js config vars, and put into the url with
	 * history.replaceState(). This means click through's from supported browsers
	 * will record this token as part of the referrer.
	 *
	 * @return string
	 */
	public static function getRequestSetToken() {
		static $token;
		if ( $token === null ) {
			// random UID, 70B tokens have a collision probability of 4*10^-16
			// so should work for marking unique queries.
			$uuid = UIDGenerator::newUUIDv4();
			// make it a little shorter by using straight base36
			$hex = substr( $uuid, 0, 8 ) . substr( $uuid, 9, 4 ) .
				   substr( $uuid, 14, 4 ) . substr( $uuid, 19, 4) .
				   substr( $uuid, 24 );
			$token = \Wikimedia\base_convert( $hex, 16, 36 );
		}
		return $token;
	}

	private static function buildUserTestingLog() {
		global $wgRequest;

		$ut = UserTesting::getInstance();
		if ( !$ut->getActiveTestNames() ) {
			return;
		}
		$queries = [];
		$parameters = [
			'index' => [],
			'queryType' => [],
			'acceptLang' => $wgRequest->getHeader( 'Accept-Language' ),
		];
		$elasticTook = 0;
		$hits = 0;
		foreach ( self::$logContexts as $context ) {
			$hits += isset( $context['hitsTotal'] ) ? $context['hitsTotal'] : 0;
			if ( isset( $context['query'] ) ) {
				$queries[] = $context['query'];
			}
			if ( isset( $context['elasticTookMs'] ) ) {
				$elasticTook += $context['elasticTookMs'];
			}
			if ( isset( $context['index'] ) ) {
				$parameters['index'][] = $context['index'];
			}
			if ( isset( $context['queryType'] ) ) {
				$parameters['queryType'][] = $context['queryType'];
			}
			if ( !empty( $context['langdetect' ] ) ) {
				$parameters['langdetect'] = $context['langdetect'];
			}
		}

		foreach ( [ 'index', 'queryType' ] as $key ) {
			$parameters[$key] = array_values( array_unique( $parameters[$key] ) );
		}

		$message = [
			wfWikiID(),
			'',
			FormatJson::encode( $queries ),
			$hits,
			self::getExecutionContext(),
			$elasticTook,
			$wgRequest->getIP(),
			preg_replace( "/[\t\"']/", "", $wgRequest->getHeader( 'User-Agent') ),
			FormatJson::encode( $parameters ),
			self::generateIdentToken(),
		];

		$logger = LoggerFactory::getInstance( 'CirrusSearchUserTesting' );
		foreach ( $ut->getActiveTestNames() as $test ) {
			$bucket = $ut->getBucket( $test );
			$message[1] = "{$test}-{$bucket}";
			$logger->debug( implode( "\t", $message ) );
		}
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
		list( $status, $message ) = $this->extractMessageAndStatus( $exception );
		$context['message'] = $message;

		$stats = MediaWikiServices::getInstance()->getStatsdDataFactory();
		$type = self::classifyError( $exception );
		$clusterName = $this->connection->getClusterName();
		$stats->increment( "CirrusSearch.$clusterName.backend_failure.$type" );

		LoggerFactory::getInstance( 'CirrusSearch' )->warning(
			"Search backend error during {$this->description} after {took}: {message}",
			$context
		);
		return $status;
	}

	/**
	 * Broadly classify the error message into failures where
	 * we decided to not serve the query, and failures where
	 * we just failed to answer
	 *
	 * @param \Elastica\Exception\ExceptionInterface|null $exception
	 * @return string Either 'rejected', 'failed' or 'unknown'
	 */
	static public function classifyError( \Elastica\Exception\ExceptionInterface $exception = null ) {
		if ( $exception === null ) {
			return 'unknown';
		}
		$error = self::extractFullError( $exception );
		if ( isset( $error['root_cause'][0]['type'] ) ) {
			$error = reset( $error['root_cause'] );
		} else if ( ! ( isset( $error['type'] ) && isset( $error['reason'] ) ) ) {
			return 'unknown';
		}

		$heuristics = [
			'rejected' => [
				'type_regexes' => [
					'(^|_)regex_',
					'^too_complex_to_determinize_exception$',
					'^elasticsearch_parse_exception$',
					'^search_parse_exception$',
					'^query_parsing_exception$',
					'^illegal_argument_exception$',
					'^too_many_clauses$'
				],
				'msg_regexes' => [],
			],
			'failed' => [
				'type_regexes' => [
					'^es_rejected_execution_exception$',
					'^remote_transport_exception$',
					'^search_context_missing_exception$',
					'^null_pointer_exception$',
					'^elasticsearch_timeout_exception$'
				],
				// These are exceptions thrown by elastica itself
				'msg_regexes' => [
					'^Couldn\'t connect to host',
					'^No enabled connection',
					'^Operation timed out',
				],
			],
		];
		foreach( $heuristics as $type => $heuristic ) {
			$regex = implode( '|', $heuristic['type_regexes'] );
			if ( $regex && preg_match( "/$regex/", $error['type'] ) ) {
				return $type;
			}
			$regex = implode( '|', $heuristic['msg_regexes'] );
			if ( $regex && preg_match( "/$regex/", $error['reason'] ) ) {
				return $type;
			}
		}
		return "unknown";
	}

	/**
	 * Get the search metrics we have
	 * @return array
	 */
	public function getSearchMetrics() {
		return $this->searchMetrics;
	}

	/**
	 * Extract an error message from an exception thrown by Elastica.
	 * @param \Elastica\Exception\ExceptionInterface $exception exception from which to extract a message
	 * @return array structuerd error from the exception
	 * @suppress PhanUndeclaredMethod ExceptionInterface doesn't declare any methods
	 *  so we have to suppress those warnings.
	 */
	public static function extractFullError( \Elastica\Exception\ExceptionInterface $exception ) {
		if ( !( $exception instanceof ResponseException ) ) {
			// simulate the basic full error structure
			return [
				'type' => 'unknown',
				'reason' => $exception->getMessage()
			];
		}
		if ( $exception instanceof PartialShardFailureException ) {
			// @todo still needs to be fixed, need a way to trigger this
			// failure
			$shardStats = $exception->getResponse()->getShardsStatistics();
			$message = [];
			$type = null;
			foreach ( $shardStats[ 'failures' ] as $failure ) {
				$message[] = $failure['reason']['reason'];
				if ( $type === null ) {
					$type = $failure['reason']['type'];
				}
			}

			return [
				'type' => $type,
				'reason' => 'Partial failure:  ' . implode( ',', $message ),
				'partial' => true
			];
		}

		return $exception->getResponse()->getFullError();
	}

	/**
	 * @param \Elastica\Exception\ExceptionInterface $exception
	 * @return string
	 */
	public static function extractMessage( \Elastica\Exception\ExceptionInterface $exception ) {
		$error = self::extractFullError( $exception );

		return $error['type'] . ': ' .$error['reason'];
	}

	/**
	 * Does this status represent an Elasticsearch parse error?
	 * @param Status $status Status to check
	 * @return boolean is this a parse error?
	 */
	protected function isParseError( $status ) {
		/** @suppress PhanDeprecatedFunction No good replacements for getErrorsArray */
		foreach ( $status->getErrorsArray() as $errorMessage ) {
			if ( $errorMessage[ 0 ] === 'cirrussearch-parse-error' ) {
				return true;
			}
		}
		return false;
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
			'source' => self::getExecutionContext(),
			'executor' => self::getExecutionId(),
			'identity' => self::generateIdentToken(),
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
			if ( $this->_isset( $queryData, [ 'query', 'filtered', 'filter', 'terms', 'namespace' ] ) ) {
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

	/**
	 * @return string The context the request is in. Either cli, api or web.
	 */
	static public function getExecutionContext() {
		if ( php_sapi_name() === 'cli' ) {
			return 'cli';
		} elseif ( defined( 'MW_API' ) ) {
			return 'api';
		} else {
			return 'web';
		}
	}

	/**
	 * @param \Elastica\Exception\ExceptionInterface|null $exception
	 * @return array Two elements, first is Status object, second is string.
	 */
	private function extractMessageAndStatus( \Elastica\Exception\ExceptionInterface $exception = null ) {
		if ( !$exception ) {
			return [ Status::newFatal( 'cirrussearch-backend-error' ), '' ];
		}

		// Lots of times these are the same as getFullError(), but sometimes
		// they're not. I'm looking at you PartialShardFailureException.
		$error = self::extractFullError( $exception );

		// These can be top level errors, or exceptions that don't extend from
		// ResponseException like PartialShardFailureException or errors
		// contacting the cluster.
		if ( !isset( $error['root_cause'][0]['type'] ) ) {
			return [
				Status::newFatal( 'cirrussearch-backend-error' ),
				$error['type'] . ': ' . $error['reason']
			];
		}

		// We can have multiple root causes if the error is not the
		// same on different shards. Errors will be deduplicated based
		// on their type. Currently we display only the first one if
		// it happens.
		$cause = reset( $error['root_cause'] );

		if ( $cause['type'] === 'query_parsing_exception' ) {
			// The important part of the parse error message is embedded a few levels down
			// and comes before the next new line so lets slurp it up and log it rather than
			// the huge clump of error.
			$shardFailure = reset( $error['failed_shards'] );
			$message = $shardFailure['reason']['caused_by']['reason'];
			$end = strpos( $message, "\n", 0 );
			$parseError = substr( $message, 0, $end );

			return [
				Status::newFatal( 'cirrussearch-parse-error' ),
				'Parse error on ' . $parseError
			];
		}

		if ( $cause['type'] === 'too_complex_to_determinize_exception' ) {
			return [ Status::newFatal(
				'cirrussearch-regex-too-complex-error' ),
				$cause['reason']
			];
		}

		if ( preg_match( '/(^|_)regex_/', $cause['type'] ) ) {
			$syntaxError = $cause['reason'];
			$errorMessage = 'unknown';
			$position = 'unknown';
			// Note: we support only error coming from the extra plugin
			// In the case Cirrus is installed without the plugin and
			// is using the Groovy script to do regex then a generic backend error
			// will be displayed.

			$matches = [];
			// In some cases elastic will serialize the exception by adding
			// an extra message prefix with the exception type.
			// If the exception is serialized through Transport:
			//   invalid_regex_exception: expected ']' at position 2
			// Or if the exception is thrown locally by the node receiving the query:
			//   expected ']' at position 2
			if ( preg_match( '/(?:[a-z_]+: )?(.+) at position (\d+)/', $syntaxError, $matches ) ) {
				$errorMessage = $matches[ 1 ];
				$position = $matches[ 2 ];
			} else if ( $syntaxError === 'unexpected end-of-string' ) {
				$errorMessage = 'regex too short to be correct';
			}
			$status = Status::newFatal( 'cirrussearch-regex-syntax-error', $errorMessage, $position );

			return [ $status, 'Regex syntax error:  ' . $syntaxError ];
		}

		return [
			Status::newFatal( 'cirrussearch-backend-error' ),
			$cause['type'] . ': ' . $cause['reason']
		];
	}

	/**
	 * @param string $extraData Extra information to mix into the hash
	 * @return string A token that identifies the source of the request
	 */
	public static function generateIdentToken( $extraData = '' ) {
		$request = \RequestContext::getMain()->getRequest();
		return md5( implode( ':', [
			$extraData,
			$request->getIP(),
			$request->getHeader( 'X-Forwarded-For' ),
			$request->getHeader( 'User-Agent' ),
		] ) );
	}

	/**
	 * Like isset, but wont fatal when one of the expected array keys in a
	 * multi-dimensional array is a string.
	 *
	 * Temporary hack required only for php 5.3. Can be removed when 5.4 is no
	 * longer a requirement.  See T99871 for more details.
	 *
	 * @param array $array
	 * @param array $path
	 * @return bool
	 */
	private function _isset( $array, $path ) {
		while( true ) {
			$step = array_shift( $path );
			if ( !isset( $array[$step] ) ) {
				// next step of the path is non-existent
				return false;
			} elseif( !$path ) {
				// reached the end of our path
				return true;
			} elseif ( !is_array( $array[$step] ) ) {
				// more steps exist in the path, but we don't have an array
				return false;
			} else {
				// keep looking
				$array = $array[$step];
			}
		}
	}
}
