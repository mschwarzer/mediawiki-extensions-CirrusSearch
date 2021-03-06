<?php

// w sum with inc links alone (fallback with non content ns)
$wgCirrusSearchIncLinksAloneW = 1.3;
$wgCirrusSearchIncLinksAloneK = 30;
$wgCirrusSearchIncLinksAloneA = 0.7;

$wgCirrusSearchRescoreProfiles['relforge'] = array(
	'supported_namespaces' => 'all',
	'rescore' => array(
		array(
			'window' => 8192,
			'window_size_override' => 'CirrusSearchFunctionRescoreWindowSize',
			'query_weight' => 1.0,
			'rescore_query_weight' => 1.0,
			'score_mode' => 'total',
			'type' => 'function_score',
			'function_chain' => 'wsum_inclinks'
		),
		array(
			'window' => 8192,
			'window_size_override' => 'CirrusSearchFunctionRescoreWindowSize',
			'query_weight' => 1.0,
			'rescore_query_weight' => 1.0,
			'score_mode' => 'multiply',
			'type' => 'function_score',
			'function_chain' => 'optional_chain'
		),
	),
);

$wgCirrusSearchRescoreFunctionScoreChains['wsum_inclinks'] = array(
	'functions' => array(
		array(
			'type' => 'satu',
			'weight' => array(
				'value' => 1.2,
				'config_override' => 'CirrusSearchIncLinksAloneW',
				'uri_param_override' => 'cirrusIncLinksAloneW',
			),
			'params' => array(
				'field' => 'incoming_links',
				'k' => array(
					'value' => 10,
					'config_override' => 'CirrusSearchIncLinksAloneK',
					'uri_param_override' => 'cirrusIncLinksAloneK',
				),
				'a' => array(
					'value' => 1,
					'config_override' => 'CirrusSearchIncLinksAloneA',
					'uri_param_override' => 'cirrusIncLinksAloneA',
				),
			),
		),
	),
);
