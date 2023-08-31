<?php

/**
 * @OA\Info(title="lysayit api", version="0.1")
 */
class APIDispatcher
{
    public static function json_output($obj)
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET');
        echo json_encode($obj, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 回傳公報的 HTML 內容
     *
     * @OA\Get(
     *   path="/html", summary="回傳公報的 HTML 內容",
     *   @OA\Parameter(
     *     name="meet_id", in="query", description="公報 ID", required=true,
     *     @OA\Schema( type="string", example="LCIDC01_1077502_00003.doc" )
     *   ),
     *   @OA\Response( response=200, description="回傳公報的 HTML 內容" ),
     *   @OA\Response( response=404, description="找不到公報" )
     * )
     */
    public static function html()
    {
        // Ex: meet_id LCIDC01_1077502_00003.doc
        $meet_id = $_GET['meet_id'];
        $content = file_get_contents('http://lydata.ronny-s3.click/publication-html/' . $meet_id);
        if (!$obj = json_decode($content)) {
            header('HTTP/1.1 404 Not Found');
            echo '404 not found';
            return;
        }
        $content = base64_decode($obj->content);
        $content = preg_replace_callback('#<img src="([^"]*)"#', function($matches) use ($meet_id) {
            $id = explode('_html_', $matches[1])[1];
            return '<img src="https://lydata.ronny-s3.click/picfile/' . $meet_id . '-' . $id . '"';
        }, $content);
        echo $content;
    }

    /**
     * 取得資料庫統計
     * @OA\Get(
     *   path="/api/stat",
     *   summary="取得資料庫統計",
     *   @OA\Response(
     *     response=200,
     *     description="取得資料庫統計"
     *   )
     * )
     */
    public static function stat()
    {
        $prefix = getenv('ELASTIC_PREFIX');
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
        $obj = LYLib::dbQuery("/{$prefix}meet/_search", 'GET', json_encode($cmd));
        $ret = new StdClass;
        $ret->meet_total = 0;
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
                $ret->meet_total += $pbucket->doc_count;
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
        $obj = LYLib::dbQuery("/{$prefix}vote/_search", 'GET', json_encode($cmd));
        $ret->vote_count = 0;
        foreach ($obj->aggregations->term_agg->buckets as $bucket) {
            if (!array_key_exists($bucket->key, $ret->terms)) {
                $ret->terms[$bucket->key] = new StdClass;;
                $ret->terms[$bucket->key]->term = $bucket->key;
            }
            $ret->terms[$bucket->key]->vote_count = $bucket->doc_count;
            $ret->vote_count += $bucket->doc_count;
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
        $obj = LYLib::dbQuery("/{$prefix}speech/_search", 'GET', json_encode($cmd));
        $ret->speech_count = 0;
        foreach ($obj->aggregations->term_agg->buckets as $bucket) {
            if (!array_key_exists($bucket->key, $ret->terms)) {
                $ret->terms[$bucket->key] = new StdClass;;
                $ret->terms[$bucket->key]->term = $bucket->key;
            }
            $ret->terms[$bucket->key]->speaker_count = $bucket->speaker_count->value;
            $ret->terms[$bucket->key]->speach_count = $bucket->doc_count;
            $ret->speech_count += $bucket->doc_count;
        }

        ksort($ret->terms);
        $ret->terms = array_values($ret->terms);

        self::json_output($ret);
    }

    /**
     * 列出所有的會議
     *
     * @OA\Get(
     *   path="/api/meet",
     *   summary="列出所有的會議",
     *   @OA\Parameter(
     *     name="term", in="query", description="屆別", required=false,
     *     @OA\Schema( type="integer", example="9" )
     *   ),
     *   @OA\Parameter(
     *     name="sessionPeriod", in="query", description="會期", required=false,
     *     @OA\Schema( type="integer", example="1" )
     *   ),
     *   @OA\Parameter(
     *     name="dateStart", in="query", description="開始日期", required=false,
     *     @OA\Schema( type="integer", example="20170101" )
     *   ),
     *   @OA\Parameter(
     *     name="dateEnd", in="query", description="結束日期", required=false,
     *     @OA\Schema( type="integer", example="20171231" )
     *   ),
     *   @OA\Parameter(
     *     name="page", in="query", description="頁數", required=false,
     *     @OA\Schema( type="integer", example="1" )
     *   ),
     *   @OA\Parameter(
     *     name="limit", in="query", description="每頁筆數", required=false,
     *     @OA\Schema( type="integer", example="100" )
     *   ),
     *   @OA\Response( response=200, description="列出所有的會議" )
     * )
     */
    public static function meet()
    {
        $prefix = getenv('ELASTIC_PREFIX');
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
        $obj = LYLib::dbQuery("/{$prefix}meet/_search", 'GET', json_encode($cmd));

        $records = array();
        $ret = new StdClass;
        $ret->total = $obj->hits->total->value;
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
        self::json_output($ret);
    }

    /**
     * 列出包含 keyword 的對話記錄
     *
     * @OA\Get(
     *   path="/api/searchspeech", summary="列出包含 keyword 的對話記錄",
     *   @OA\Parameter(
     *     name="q", in="query", description="關鍵字", required=true,
     *     @OA\Schema( type="string", example="蔡英文" )
     *   ),
     *   @OA\Parameter(
     *     name="page", in="query", description="頁數", required=false,
     *     @OA\Schema( type="integer", example="1" )
     *   ),
     *   @OA\Response( response=200, description="列出包含 keyword 的對話記錄" )
     * )
     */
    public static function searchspeech()
    {
        $prefix = getenv('ELASTIC_PREFIX');
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
        $obj = LYLib::dbQuery("/{$prefix}speech/_search", 'GET', json_encode($cmd));
        $records = new StdClass;
        $records->page = $page;
        $records->total = $obj->hits->total->value;
        $records->total_page = ceil($obj->hits->total->value / 100);
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
        $obj = LYLib::dbQuery("/{$prefix}meet/_search", 'GET', json_encode($cmd));
        foreach ($obj->hits->hits as $hit) {
            $record = $hit->_source;
            $record->id = $hit->_id;
            $record->extra = json_decode($record->extra);
            $records->meets[] = $record;
        }
        self::json_output($records, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 列出 meet_id 的所有會議記錄
     *
     * @OA\Get(
     *   path="/api/speech/{meet_id}", summary="列出 meet_id 的所有會議記錄",
     *   @OA\Parameter(
     *     name="meet_id", in="path", description="公報 ID", required=true,
     *     @OA\Schema( type="string", example="LCIDC01_1077502_00003.doc" )
     *   ),
     *   @OA\Parameter(
     *     name="full", in="query", description="是否要顯示完整資料", required=false,
     *     @OA\Schema( type="boolean", example="true" )
     *   ),
     *   @OA\Response( response=200, description="列出 meet_id 的所有會議記錄" ),
     *   @OA\Response( response=404, description="找不到公報" )
     * )
     *
     */
    public static function speechByMeet($meet_id)
    {
        $prefix = getenv('ELASTIC_PREFIX');

        $meet_id = str_replace('.doc', '', $meet_id);
        $cmd = [
            'query' => array(
                'match' => array('meet_id' => $meet_id),
            ),
            'sort' => 'lineno',
            'size' => 10000,
        ];
        $obj = LYLib::dbQuery("/{$prefix}speech/_search", 'GET', json_encode($cmd));
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
        $obj = LYLib::dbQuery("/{$prefix}vote/_search", 'GET', json_encode($cmd));
        foreach ($obj->hits->hits as $hit) {
            $record = $hit->_source;
            $records[$record->line_no]->vote_data = $record;
        }

        if (array_key_exists('full', $_GET) and $_GET['full']) {
            $ret = new StdClass;
            $ret->speech = array_values($records);
            $ret->persons = [];
            $obj = LYLib::dbQuery("/{$prefix}meet/_doc/" . $meet_id, 'GET');
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
            $obj = LYLib::dbQuery("/{$prefix}person/_search", 'GET', json_encode($cmd));
            foreach ($obj->hits->hits as $hit) {
                $hit->_source->extra = json_decode($hit->_source->extra);
                $ret->persons[] = $hit->_source;
            }

            $records = $ret;
        } else {
            $records = array_values($records);
        }

        self::json_output($records, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 列出 name 的參與會議記錄（依時間排序，新的在前面）
     * 
     * @OA\Get(
     *   path="/api/speaker/{speaker}/meet", summary="列出 name 的參與會議記錄（依時間排序，新的在前面）",
     *   @OA\Parameter(
     *     name="speaker", in="path", description="姓名", required=true,
     *     @OA\Schema( type="string", example="黃國昌" )
     *   ),
     *   @OA\Parameter(
     *     name="term", in="query", description="屆別", required=false,
     *     @OA\Schema( type="integer", example="9" )
     *   ),
     *   @OA\Parameter(
     *     name="sessionPeriod", in="query", description="會期", required=false,
     *     @OA\Schema( type="integer", example="1" )
     *   ),   
     *   @OA\Parameter(
     *     name="dateStart", in="query", description="開始日期", required=false,
     *     @OA\Schema( type="integer", example="20160101" )
     *   ),
     *   @OA\Parameter(
     *     name="dateEnd", in="query", description="結束日期", required=false,
     *     @OA\Schema( type="integer", example="20161231" )
     *   ),
     *   @OA\Parameter(
     *     name="page", in="query", description="頁數", required=false,
     *     @OA\Schema( type="integer", example="1" )
     *   ),
     *   @OA\Parameter(
     *     name="limit", in="query", description="每頁筆數", required=false,
     *     @OA\Schema( type="integer", example="100" )
     *   ),
     *   @OA\Response( response=200, description="列出 name 的參與會議記錄（依時間排序，新的在前面）" ),
     *   @OA\Response( response=404, description="找不到公報" )
     * )
     *
     */
    public static function speakerMeet($speaker)
    {
        $prefix = getenv('ELASTIC_PREFIX');

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
        $obj1 = LYLib::dbQuery("/{$prefix}speech/_search", 'GET', json_encode($cmd));
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

        $obj = LYLib::dbQuery("/{$prefix}meet/_search", 'GET', json_encode($cmd));
        $ret = new StdClass;
        $ret->total = $obj->hits->total->value;
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
            $obj = LYLib::dbQuery("/{$prefix}person/_search/", 'GET', json_encode([
                'query' => [ 'terms' => ['_id' => [intval($_GET['term']) . '-' . $speaker]]],
            ]));
            if ($obj->hits->hits[0]) {
                $ret->person_data = $obj->hits->hits[0]->_source;
                $ret->person_data->extra = json_decode($ret->person_data->extra);
                $ret->person_data->meet_count = count($obj1->aggregations->term_agg->buckets);
                $ret->person_data->speech_count = $obj1->hits->total->value;
            }
        }

        self::json_output($ret, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 列出 name 的所有對話記錄
     * 
     * @OA\Get(
     *   path="/api/speaker/{speaker}", summary="列出 name 的所有對話記錄",
     *   @OA\Parameter(
     *     name="speaker", in="path", description="姓名", required=true,
     *     @OA\Schema( type="string", example="黃國昌" )
     *   ),
     *   @OA\Parameter(
     *     name="term", in="query", description="屆別", required=false,
     *     @OA\Schema( type="integer", example="9" )
     *   ),
     *   @OA\Parameter(
     *     name="sessionPeriod", in="query", description="會期", required=false,
     *     @OA\Schema( type="integer", example="1" )
     *   ),
     *   @OA\Parameter(
     *     name="dateStart", in="query", description="開始日期", required=false,
     *     @OA\Schema( type="integer", example="20160101" )
     *   ),
     *   @OA\Parameter(
     *     name="dateEnd", in="query", description="結束日期", required=false,
     *     @OA\Schema( type="integer", example="20161231" )
     *   ),
     *   @OA\Parameter(
     *     name="page", in="query", description="頁數", required=false,
     *     @OA\Schema( type="integer", example="1" )
     *   ),
     *   @OA\Parameter(
     *     name="limit", in="query", description="每頁筆數", required=false,
     *     @OA\Schema( type="integer", example="100" )
     *   ),
     *   @OA\Response( response=200, description="列出 name 的所有對話記錄" ),
     *   @OA\Response( response=404, description="找不到公報" )
     * )
     */
    public static function speaker($speaker)
    {
        $prefix = getenv('ELASTIC_PREFIX');
        $limit = @max(intval($_GET['limit']), 100);
        $page = @max(intval($_GET['page']), 1);
        $cmd = [
            'query' => array(
                'match' => array('speaker' => $speaker),
            ),
            'size' => $limit,
            'from' => $limit * $page - $limit,
        ];
        $obj = LYLib::dbQuery("/{$prefix}speech/_search", 'GET', json_encode($cmd));
        $ret = new StdClass;
        $ret->page = $page;
        $ret->limit = $limit;
        $ret->total = $obj->hits->total->value;
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
        $obj = LYLib::dbQuery("/{$prefix}meet/_search", 'GET', json_encode($cmd));
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
        self::json_output($ret, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 列出 name 的所有投票記錄
     *
     * @OA\Get(
     *   path="/api/vote/{speaker}", summary="列出 name 的所有投票記錄",
     *   @OA\Parameter(
     *     name="speaker", in="path", description="姓名", required=true,
     *     @OA\Schema( type="string", example="黃國昌" )
     *   ),
     *   @OA\Parameter(
     *     name="page", in="query", description="頁數", required=false,
     *     @OA\Schema( type="integer", example="1" )
     *   ),
     *   @OA\Parameter(
     *     name="limit", in="query", description="每頁筆數", required=false,
     *     @OA\Schema( type="integer", example="100" )
     *   ),
     *   @OA\Response( response=200, description="列出 name 的所有投票記錄" ),
     *   @OA\Response( response=404, description="找不到公報" )
     * )
     */
    public static function voteBySpeaker($speaker)
    {
        $prefix = getenv('ELASTIC_PREFIX');
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
        $obj = LYLib::dbQuery("/{$prefix}vote/_search", 'GET', json_encode($cmd));
        $ret = new StdClass;
        $ret->page = $page;
        $ret->limit = $limit;
        $ret->total = $obj->hits->total->value;
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
        $obj = LYLib::dbQuery("/{$prefix}meet/_search", 'GET', json_encode($cmd));
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
        self::json_output($ret, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 列出第 term 屆的發言者
     *
     * @OA\Get(
     *   path="/api/term/{term}/speaker/{speaker_type}", summary="列出第 term 屆的發言者",
     *   @OA\Parameter(
     *     name="term", in="path", description="屆別", required=true,
     *     @OA\Schema( type="integer", example="9" )
     *   ),
     *   @OA\Parameter(
     *     name="speaker_type", in="path", description="發言者類型", required=true,
     *     @OA\Schema( type="integer", example="1" )
     *   ),
     *   @OA\Response( response=200, description="列出第 term 屆的發言者" ),
     *   @OA\Response( response=404, description="找不到公報" )
     * )
     *
     */
    public static function speakerByTerm($term, $speaker_type)
    {
        $prefix = getenv('ELASTIC_PREFIX');

        $page = @intval(max($_GET['page'], 1));
        $limit = @intval($_GET['limit']) ?: 100;
        $cmd = [
            'query' => array(
                'bool' => [
                    'must' => [
                        'term' => ['term' => intval($term)],
                    ],
                    'filter' => [
                        'term' => ['speaker_type' => intval($speaker_type)],
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
        $obj = LYLib::dbQuery("/{$prefix}speech/_search", 'GET', json_encode($cmd));
        $records = new StdClass;
        $records->page = $page;
        $records->term = intval($term);
        $records->speaker_type = intval($speaker_type);
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
        $obj = LYLib::dbQuery("/{$prefix}person/_search", 'GET', json_encode($cmd));

        foreach ($obj->hits->hits as $hit) {
            $records->persons[$hit->_id]->extra = json_decode($hit->_source->extra);
        }

        $records->persons = array_values($records->persons);
        self::json_output($records, JSON_UNESCAPED_UNICODE);
    }

    public static function dispatch($uri)
    {
        $uri = explode('?', $uri)[0];
        if ($uri == '/html') {
            return self::html();
        }

        if ($uri == '/swagger.yaml') {
            header('Content-Type: text/plain');
            header('Access-Control-Allow-Origin: *');
            readfile(__DIR__ . '/swagger.yaml');
            return;
        }

        if (!preg_match('#/api/([^/]+)(.*)#', $uri, $matches)) {
            readfile(__DIR__ . '/swagger.html');
            return;
        }
        $method = strtolower($matches[1]);
        $matches[2] = ltrim($matches[2], '/');
        $params = [];
        if ($matches[2]) {
            $params = array_map('urldecode', explode('/', $matches[2]));
        }

        if ($method == 'stat') {
            self::stat();
        } elseif ($method == 'meet') {
            self::meet();
        } elseif ($method == 'searchspeech') {
            self::searchspeech();
        } else if ($method == 'speech' and $meet_id = $params[0]) {
            self::speechByMeet($meet_id);
        } elseif ($method == 'speaker' and $speaker = $params[0] and 'meet' == $params[1]) {
            self::speakerMeet($speaker);
        } elseif ($method == 'speaker' and $speaker  = $params[0]) {
            self::speaker($speaker);
        } elseif ($method == 'vote' and $speaker  = $params[0]) {
            self::voteBySpeaker($speaker);
        } elseif ($method == 'term' and count($params) == 3 and $params[1] == 'speaker') {
            self::speakerByTerm($params[0], $params[2]);
        }
    }
}
