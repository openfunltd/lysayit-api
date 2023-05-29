<?php

include(__DIR__ . '/init.inc.php');
$uri = explode('?', $_SERVER['REQUEST_URI'])[0];
if ($uri == '/html') {
    $meet_id = $_GET['meet_id'];
    $content = file_get_contents('http://lydata.ronny-s3.click/publication-html/' . $meet_id);
    if (!$obj = json_decode($content)) {
        echo '404 not found';
        exit;
    }
    $content = base64_decode($obj->content);
    $content = preg_replace_callback('#<img src="([^"]*)"#', function($matches) use ($meet_id) {
        $id = explode('_html_', $matches[1])[1];
        return '<img src="https://lydata.ronny-s3.click/picfile/' . $meet_id . '-' . $id . '"';
    }, $content);
    echo $content;
    exit;

}
if (!preg_match('#/api/([^/]+)(.*)#', $uri, $matches)) {
    readfile(__DIR__ . '/notfound.html');
    exit;
}
$method = strtolower($matches[1]);
$matches[2] = ltrim($matches[2], '/');
$params = [];
if ($matches[2]) {
    $params = array_map('urldecode', explode('/', $matches[2]));
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
function json_output($obj) {
    echo json_encode($obj, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method == 'stat') {
    $cmd = [
        'query' => [
            'range' => ['date' => ['gte' => 19700001]],
        ],
        'aggs' => [
            'term_count' => [
                'terms' => ['field' => 'term'],
                'aggs' => [
                    'date_max' => ['max' => ['field' => 'date']],
                    'date_min' => ['min' => ['field' => 'date']],
                    'period_count' => [
                        'terms' => ['field' => 'sessionPeriod'],
                        'aggs' => [
                            'date_max' => ['max' => ['field' => 'date']],
                            'date_min' => ['min' => ['field' => 'date']],
                        ],
                    ],
                ],
            ],
            'date_max' => ['max' => ['field' => 'date']],
            'date_min' => ['min' => ['field' => 'date']],
        ],
        'size' => 0,
    ];
    $obj = API::query('/meet/_search', 'GET', json_encode($cmd));
    $ret = new StdClass;
    $ret->meet_total = $obj->hits->total;
    $ret->date_min = $obj->aggregations->date_min->value;
    $ret->date_max = $obj->aggregations->date_max->value;
    $ret->terms = [];

    foreach ($obj->aggregations->term_count->buckets as $bucket) {
        if (!array_key_exists($bucket->key, $ret->terms)) {
            $ret->terms[$bucket->key] = new StdClass;;
            $ret->terms[$bucket->key]->term = $bucket->key;
            $ret->terms[$bucket->key]->periods = [];
        }
        foreach ($bucket->period_count->buckets as $pbucket) {
            if (!array_key_exists($pbucket->key, $ret->terms[$bucket->key]->periods)) {
                $ret->terms[$bucket->key]->periods[$pbucket->key] = new StdClass;
                $ret->terms[$bucket->key]->periods[$pbucket->key]->period = $pbucket->key;
            }
            $ret->terms[$bucket->key]->periods[$pbucket->key]->date_min = $pbucket->date_min->value;
            $ret->terms[$bucket->key]->periods[$pbucket->key]->date_max = $pbucket->date_max->value;
            $ret->terms[$bucket->key]->periods[$pbucket->key]->meet_count = $pbucket->doc_count;
        }
        ksort($ret->terms[$bucket->key]->periods);
        $ret->terms[$bucket->key]->periods = array_values($ret->terms[$bucket->key]->periods);
        $ret->terms[$bucket->key]->date_min = $bucket->date_min->value;
        $ret->terms[$bucket->key]->date_max = $bucket->date_max->value;
        $ret->terms[$bucket->key]->meet_count = $bucket->doc_count;
    }
    $cmd = [
        'aggs' => [
            'term_agg' => [
                'terms' => ['field' => 'term'],
            ],
        ],
        'size' => 0,
    ];
    $obj = API::query('/vote/_search', 'GET', json_encode($cmd));
    $ret->vote_count = $obj->hits->total;
    foreach ($obj->aggregations->term_agg->buckets as $bucket) {
        if (!array_key_exists($bucket->key, $ret->terms)) {
            $ret->terms[$bucket->key] = new StdClass;;
            $ret->terms[$bucket->key]->term = $bucket->key;
        }
        $ret->terms[$bucket->key]->vote_count = $bucket->doc_count;
    }

    $cmd = [
        'aggs' => [
            'term_agg' => [
                'terms' => ['field' => 'term'],
                'aggs' => [
                    'speaker_count' => [
                        'cardinality' => ['field' => 'speaker'],
                    ]
                ],
            ],
        ],
        'size' => 0,
    ];
    $obj = API::query('/speech/_search', 'GET', json_encode($cmd));
    $ret->speech_count = $obj->hits->total;
    foreach ($obj->aggregations->term_agg->buckets as $bucket) {
        if (!array_key_exists($bucket->key, $ret->terms)) {
            $ret->terms[$bucket->key] = new StdClass;;
            $ret->terms[$bucket->key]->term = $bucket->key;
        }
        $ret->terms[$bucket->key]->speaker_count = $bucket->speaker_count->value;
        $ret->terms[$bucket->key]->speach_count = $bucket->doc_count;
    }

    ksort($ret->terms);
    $ret->terms = array_values($ret->terms);

    json_output($ret, JSON_UNESCAPED_UNICODE);
    exit;
} elseif ($method == 'meet') {
    $page = @max($_GET['page'], 1);
    $limit = @intval($_GET['limit']) ?: 100;
    $cmd = [
        'query' => [
            'bool' => [
                'must' => [],
                'filter' => [],
            ],
        ],
        'sort' => ['date' => 'desc'],
        'size' => $limit,
        'from' => $limit * $page - $limit,
    ];
    if (array_key_exists('term', $_GET)) {
        $cmd['query']['bool']['filter'][] = ['range' => ['term' => [
            'gte' => intval($_GET['term']),
            'lte' => intval($_GET['term']),
        ]]];
    }
    if (array_key_exists('sessionPeriod', $_GET)) {
        $cmd['query']['bool']['filter'][] = ['range' => ['sessionPeriod' => [
            'gte' => intval($_GET['sessionPeriod']),
            'lte' => intval($_GET['sessionPeriod']),
        ]]];
    }
    if (array_key_exists('dateStart', $_GET)) {
        $cmd['query']['bool']['filter'][] = ['range' => ['date' => [
            'gte' => intval($_GET['dateStart']),
        ]]];
    }
    if (array_key_exists('dateEnd', $_GET)) {
        $cmd['query']['bool']['filter'][] = ['range' => ['date' => [
            'lte' => intval($_GET['dateEnd']),
        ]]];
    }
    $obj = API::query('/meet/_search', 'GET', json_encode($cmd));

    $records = array();
    $ret = new StdClass;
    $ret->total = $obj->hits->total;
    $ret->limit = $limit;
    $ret->totalpage = ceil($ret->total / $ret->limit);
    $ret->page = $page;
    foreach ($obj->hits->hits as $hit) {
        $record = $hit->_source;
        $record->id = $hit->_id;
        $record->extra = json_decode($record->extra);
        $record->api_url = 'https://' . $_SERVER['HTTP_HOST'] . '/api/speech/' . $hit->_id;
        $records[] = $record;
    }
    $ret->data = $records;
    if (!array_key_exists('page', $_GET)) {
        $ret = $ret->data;
    }
    json_output($ret);
    exit;
} else if ($method == 'searchspeech') {
    $page = max($_GET['page'], 1);
    $cmd = [
        'query' => array(
            'query_string' => [
                'query' => strval($_GET['q']),
            ],
        ),
        'sort' => ['term' => 'desc', 'meet_id' => 'desc'],
        'size' => 100,
        'from' => 100 * $page - 100,
    ];
    $obj = API::query('/speech/_search', 'GET', json_encode($cmd));
    $records = new StdClass;
    $records->page = $page;
    $records->total = $obj->hits->total;
    $records->total_page = ceil($obj->hits->total / 100);
    $records->speeches = [];
    $records->meets = [];
    $meets = array();
    foreach ($obj->hits->hits as $hit) {
        $record = $hit->_source;
        $meets[$record->meet_id] = true;
        $records->speeches[] = $record;
    }
    $cmd = [
        'query' => array(
            'ids' => array('values' => array_keys($meets)),
        ),
        'size' => 10000,
    ];
    $obj = API::query('/meet/_search', 'GET', json_encode($cmd));
    foreach ($obj->hits->hits as $hit) {
        $record = $hit->_source;
        $record->id = $hit->_id;
        $record->extra = json_decode($record->extra);
        $records->meets[] = $record;
    }
    json_output($records, JSON_UNESCAPED_UNICODE);
    exit;
} else if ($method == 'speech' and $meet_id = $params[0]) {
    $meet_id = str_replace('.doc', '', $meet_id);
    $cmd = [
        'query' => array(
            'match' => array('meet_id' => $meet_id),
        ),
        'sort' => 'lineno',
        'size' => 10000,
    ];
    $obj = API::query('/speech/_search', 'GET', json_encode($cmd));
    $records = array();
    $speakers = [];
    foreach ($obj->hits->hits as $hit) {
        $record = $hit->_source;
        unset($record->meet_id);
        $speakers[intval($record->term) . '-' . $record->speaker] = true;
        $records[$record->lineno] = $record;
    }

    $cmd = [
        'query' => array(
            'match' => array('meet_id' => $meet_id),
        ),
        'size' => 10000,
    ];
    $obj = API::query('/vote/_search', 'GET', json_encode($cmd));
    foreach ($obj->hits->hits as $hit) {
        $record = $hit->_source;
        $records[$record->line_no]->vote_data = $record;
    }

    if (array_key_exists('full', $_GET) and $_GET['full']) {
        $ret = new StdClass;
        $ret->speech = array_values($records);
        $ret->persons = [];
        $obj = API::query('/meet/' . $meet_id, 'GET');
        if ($obj->found) {
            $ret->info = $obj->_source;
            $ret->info->extra = json_decode($ret->info->extra);
        }
        $cmd = [
            'query' => array(
                'ids' => array('values' => array_keys($speakers)),
            ),
            'size' => 10000,
        ];
        $obj = API::query('/person/_search', 'GET', json_encode($cmd));
        foreach ($obj->hits->hits as $hit) {
            $hit->_source->extra = json_decode($hit->_source->extra);
            $ret->persons[] = $hit->_source;
        }

        $records = $ret;
    } else {
        $records = array_values($records);
    }

    json_output($records, JSON_UNESCAPED_UNICODE);
    exit;
} elseif ($method == 'speaker' and $speaker = $params[0] and 'meet' == $params[1]) {
    $cmd = [
        'query' => array(
            'term' => array('speaker' => $speaker),
        ),
        'aggs' => [
            'term_agg' => [
                'terms' => [
                    'field' => 'meet_id',
                    'size' => 10000,
                ],
            ],
        ],
        'size' => 0,
    ];
    $obj1 = API::query('/speech/_search', 'GET', json_encode($cmd));
    $meet_counts = [];
    foreach ($obj1->aggregations->term_agg->buckets as $bucket) {
        $meet_counts[strtoupper($bucket->key)] = $bucket->doc_count;
    }

    $page = max($_GET['page'], 1);
    $limit = @intval($_GET['limit']) ?: 100;
    $cmd = [
        'query' => array(
            'bool' => [
                'must' => [
                    'ids' => array('values' => array_keys($meet_counts)),
                ],
                'filter' => [],
            ],
        ),
        'sort' => ['date' => 'desc'],
        'size' => $limit,
        'from' => $limit * $page - $limit,
    ];
    if (array_key_exists('term', $_GET)) {
        $cmd['query']['bool']['filter'][] = ['range' => ['term' => [
            'gte' => intval($_GET['term']),
            'lte' => intval($_GET['term']),
        ]]];
    }
    if (array_key_exists('sessionPeriod', $_GET)) {
        $cmd['query']['bool']['filter'][] = ['range' => ['sessionPeriod' => [
            'gte' => intval($_GET['sessionPeriod']),
            'lte' => intval($_GET['sessionPeriod']),
        ]]];
    }
    if (array_key_exists('dateStart', $_GET)) {
        $cmd['query']['bool']['filter'][] = ['range' => ['date' => [
            'gte' => intval($_GET['dateStart']),
        ]]];
    }
    if (array_key_exists('dateEnd', $_GET)) {
        $cmd['query']['bool']['filter'][] = ['range' => ['date' => [
            'lte' => intval($_GET['dateEnd']),
        ]]];
    }

    $obj = API::query('/meet/_search', 'GET', json_encode($cmd));
    $ret = new StdClass;
    $ret->total = $obj->hits->total;
    $ret->limit = $limit;
    $ret->totalpage = ceil($ret->total / $ret->limit);
    $ret->page = $page;
    $ret->meets = [];
    foreach ($obj->hits->hits as $hit) {
        $record = $hit->_source;
        $record->id = $hit->_id;
        $record->extra = json_decode($record->extra);
        $record->speech_count = $meet_counts[$hit->_id];
        $record->api_url = 'https://' . $_SERVER['HTTP_HOST'] . '/api/speech/' . $hit->_id;
        $ret->meets[] = $record;
    }
    if ($_GET['term']) {
        $obj = API::query('/person/_search/', 'GET', json_encode([
            'query' => [ 'terms' => ['_id' => [intval($_GET['term']) . '-' . $speaker]]],
        ]));
        if ($obj->hits->hits[0]) {
            $ret->person_data = $obj->hits->hits[0]->_source;
            $ret->person_data->extra = json_decode($ret->person_data->extra);
            $ret->person_data->meet_count = count($obj1->aggregations->term_agg->buckets);
            $ret->person_data->speech_count = $obj1->hits->total;
        }
    }

    json_output($ret, JSON_UNESCAPED_UNICODE);
    exit;

} elseif ($method == 'speaker' and $speaker  = $params[0]) {
    $limit = @max(intval($_GET['limit']), 100);
    $page = @max(intval($_GET['page']), 1);
    $cmd = [
        'query' => array(
            'match' => array('speaker' => $speaker),
        ),
        'size' => $limit,
        'from' => $limit * $page - $limit,
    ];
    $obj = API::query('/speech/_search', 'GET', json_encode($cmd));
    $ret = new StdClass;
    $ret->page = $page;
    $ret->limit = $limit;
    $ret->total = $obj->hits->total;
    $ret->totalpage = ceil($ret->total / $ret->limit);
    $ret->records = [];
    $meets = [];
    foreach ($obj->hits->hits as $hit) {
        $record = $hit->_source;
        $meets[$record->meet_id] = true;
        $ret->records[] = $record;
    }
    $cmd = [
        'query' => array(
            'ids' => array('values' => array_keys($meets)),
        ),
        'size' => 10000,
    ];
    $obj = API::query('/meet/_search', 'GET', json_encode($cmd));
    foreach ($obj->hits->hits as $hit) {
        $meets[$hit->_id] = $hit->_source;
    }
    foreach ($ret->records as $idx => $record) {
        $meet = $meets[$record->meet_id];
        foreach ($meet as $k => $v) {
            $ret->records[$idx]->{$k} = $v;
        }
    }
    if (!array_key_exists('page', $_GET)) {
        $ret = $ret->records;
    }
    json_output($ret, JSON_UNESCAPED_UNICODE);
    exit;
} elseif ($method == 'vote' and $speaker  = $params[0]) {
    $limit = @intval($_GET['limit']) ?: 100;
    $page = @max(intval($_GET['page']), 1);
    $cmd = [
        'query' => array(
            'multi_match' => array(
                'query' => $speaker,
                'fields' => ['贊成', '反對', '棄權'],
            ),
        ),
        'sort' => ['date' => 'desc'],
        'size' => $limit,
        'from' => $limit * $page - $limit,
    ];
    $obj = API::query('/vote/_search', 'GET', json_encode($cmd));
    $ret = new StdClass;
    $ret->page = $page;
    $ret->limit = $limit;
    $ret->total = $obj->hits->total;
    $ret->totalpage = ceil($ret->total / $ret->limit);
    $ret->records = [];
    $meets = [];
    foreach ($obj->hits->hits as $hit) {
        $record = $hit->_source;
        $meets[$record->meet_id] = true;
        $record->extra = json_decode($record->extra);
        $ret->records[] = $record;
    }
    $cmd = [
        'query' => array(
            'ids' => array('values' => array_keys($meets)),
        ),
        'size' => 10000,
    ];
    $obj = API::query('/meet/_search', 'GET', json_encode($cmd));
    foreach ($obj->hits->hits as $hit) {
        $meets[$hit->_id] = $hit->_source;
    }
    foreach ($ret->records as $idx => $record) {
        $meet = $meets[$record->meet_id];
        foreach ($meet as $k => $v) {
            if ($k == 'extra') {
                $v = json_decode($v);
            }
            if (property_exists($ret->records[$idx], $k)) {
                $k = 'meet_' . $k;
            }
            $ret->records[$idx]->{$k} = $v;
        }
    }
    json_output($ret, JSON_UNESCAPED_UNICODE);
    exit;
} elseif ($method == 'term' and count($params) == 3 and $params[1] == 'speaker') {
    $page = @intval(max($_GET['page'], 1));
    $limit = @intval($_GET['limit']) ?: 100;
    $cmd = [
        'query' => array(
            'bool' => [
                'must' => [
                    'term' => ['term' => intval($params[0])],
                ],
                'filter' => [
                    'term' => ['speaker_type' => intval($params[2])],
                ],
            ],
        ),
        'aggs' => [
            'speaker_agg' => [
                'terms' => [
                    'field' => 'speaker',
                    'size' => 10000,

                ],
                'aggs' => [
                    'meet_count' => [
                        'cardinality' => ['field' => 'meet_id'],
                    ],
                ],
            ],
        ],
        'size' => 0,
    ];
    $obj = API::query('/speech/_search', 'GET', json_encode($cmd));
    $records = new StdClass;
    $records->page = $page;
    $records->term = intval($params[0]);
    $records->speaker_type = intval($params[2]);
    $records->total = count($obj->aggregations->speaker_agg->buckets);
    $records->total_page = ceil($records->total / $limit);
    $records->limit = $limit;
    $records->persons = [];
    foreach (array_slice($obj->aggregations->speaker_agg->buckets, 100 * $page - 100, 100) as $bucket) {
        $key = $records->term . '-' . $bucket->key;
        if (array_key_exists($key, $records->persons)) {
            continue;
        }
        $records->persons[$key] = new StdClass;
        $records->persons[$key]->name = $bucket->key;
        $records->persons[$key]->type = $records->speaker_type;
        $records->persons[$key]->speech_count = $bucket->doc_count;
        $records->persons[$key]->meet_count = $bucket->meet_count->value;
    }

    $cmd = [
        'query' => array(
            'ids' => array('values' => array_keys($records->persons)),
        ),
        'size' => 10000,
    ];
    $obj = API::query('/person/_search', 'GET', json_encode($cmd));

    foreach ($obj->hits->hits as $hit) {
        $records->persons[$hit->_id]->extra = json_decode($hit->_source->extra);
    }

    $records->persons = array_values($records->persons);
    json_output($records, JSON_UNESCAPED_UNICODE);
    exit;
} else {
    readfile(__DIR__ . '/notfound.html');
    exit;
}
