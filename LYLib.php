<?php

class LYLib
{
    /**
     * getTermPeriodByDate 取得現在的會期和屆次
     */
    public static function getTermPeriodByDate($timestamp)
    {
        list($year, $month) = explode('/', date('Y/m', $timestamp));
        if ($month < 2) { // 二月前的話，算前一年的第二會期
            $period = 1;
            $year --;
        } else if ($month < 9) { // 九月前的話，算當年的第一會期
            $period = 0;
        } else {
            $period = 1;
        }

        if ($year < 1993) { // 第一屆以前萬年國代
            $term = 1;
            $period = 1 + ($year - 1948) * 2 + $period;
        } else if ($year < 2008) { // 1993 ~ 2008 年以前三年一任
            $year -= 1993;
            $term = 2 + floor($year / 3);
            $period = 1 + ($year - 3 * floor($year / 3)) * 2 + $period;
        } else { // 2008 年以後四年一任
            $year -= 2008;
            $term = 7 + floor($year / 4);
            $period = 1 + ($year - 4 * floor($year / 4)) * 2 + $period;
        }

        return [$term, $period];
    }

    public static function getPersonList()
    {
        if (file_exists(__DIR__ . '/person.csv')) {
            return file_get_contents(__DIR__ . '/person.csv');
        }
        $url = sprintf("https://data.ly.gov.tw/odw/usageFile.action?id=16&type=CSV&fname=16_CSV.csv");
        error_log("fetching $url");
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_USERAGENT, 'Chrome');
        curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        $content = curl_exec($curl);
        file_put_contents(__DIR__ . '/person.csv', $content);
        return $content;
    }

    /**
     * getListFromTermPeriod 取得某屆次和會期的列表
     */
    public static function getListFromTermPeriod($term, $period)
    {
        $url = sprintf("https://data.ly.gov.tw/odw/usageFile.action?id=41&type=CSV&fname=41_%02d%02dCSV-1.csv", $term, $period);
        error_log("fetching $url");
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_USERAGENT, 'Chrome');
        curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        $content = curl_exec($curl);
        return $content;
    }

    public static function dbQuery($url, $method = 'GET', $data = null)
    {
        $curl = curl_init(getenv('ELASTIC_URL') . $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $_user = getenv('ELASTIC_USER');
        $_password = getenv('ELASTIC_PASSWORD');
        curl_setopt($curl, CURLOPT_USERPWD, $_user . ':' . $_password);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
        ]);
        if ($method != 'GET') {
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        }
        if (!is_null($data)) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        }
        $content = curl_exec($curl);
        $obj = json_decode($content);
        if (!$obj) {
            throw new Exception("error: " . $content);
        }
        if (property_exists($obj, 'error') and $obj->error) {
            file_put_contents('error.data', $data);
            throw new Exception("error: " . json_encode($obj, JSON_UNESCAPED_UNICODE));
        }
        curl_close($curl);
        return $obj;
    }

    public static $_db_bulk_pool = [];

    public static function dbBulkCommit($mapping = null)
    {
        if (is_null($mapping)) {
            $mappings = array_keys(self::$_db_bulk_pool);
        } else {
            $mappings = [$mapping];
        }
        $prefix = getenv('ELASTIC_PREFIX');
        foreach ($mappings as $mapping) {
            $ret = self::dbQuery("/{$prefix}{$mapping}/_bulk", 'PUT', self::$_db_bulk_pool[$mapping]);
            $ids = [];
            foreach (json_decode($ret)->items as $command) {
                foreach ($command as $action => $result) {
                    if ($result->status == 200 or $result->status == 201) {
                        $ids[] = $result->_id;
                        continue;
                    }
                    file_put_contents('error.data', self::$_db_bulk_pool[$mapping]);
                    print_r($result);
                    continue;
                }
            }

            error_log(sprintf("bulk commit, update (%d) %s", count($ids), mb_strimwidth(implode(',', $ids), 0, 200)));
            self::$_db_bulk_pool[$mapping] = '';
        }
    }

    public static function dbBulkInsert($mapping, $id, $data)
    {
        if (!array_key_exists($mapping, self::$_db_bulk_pool)) {
            self::$_db_bulk_pool[$mapping] = '';
        }
        $encdata = json_encode([
            'doc' => $data,
            'doc_as_upsert' => true,
        ]);
        if (!$encdata) {
            // TODO: LCIDC01_1124101_00003
            return;
        }
        self::$_db_bulk_pool[$mapping] .=
            json_encode(array(
                'update' => array('_id' => $id),
            )) . "\n"
            . $encdata . "\n";
        if (strlen(self::$_db_bulk_pool[$mapping]) > 1000000) {
            self::dbBulkCommit($mapping);
        }
    }

    public static function parseDoc($file)
    {
        error_log("parse doc $file");
        $basename = basename($file);
        if (file_exists(__DIR__ . "/htmlfile/{$basename}")) {
            $pics = json_decode(file_get_contents(__DIR__ . "/htmlfile/{$basename}"))->pics;
            if ($pics) {
            }
            return $pics;
        }
        $cmd = sprintf("curl -X POST -F %s -F \"output_type=html\" https://soffice.ronny.tw/", escapeshellarg('file=@' . $file));
        $fp = popen($cmd, 'r');
        $base = basename($file);
        $images = new StdClass;
        $ret = new StdClass;
        while ($line = fgets($fp)) {
            if (!$obj = json_decode($line)) {
                echo $line;
                echo 'error line';
                exit;
            }
            if ($obj[0] == 'attachments') {
                $attachment = $obj[1];
                $img_name = explode('_html_', $attachment->file_name)[1];
                file_put_contents(__DIR__ . '/picfile/' . $base . '-' . $img_name, base64_decode($attachment->content));
                //S3Lib::put(__DIR__ . "/picfile/{$basename}-{$img_name}", "data/picfile/{$basename}-{$img_name}");
                //unlink(__DIR__ . "/picfile/{$basename}-{$img_name}");

                $images->{$img_name} = true;
            } elseif ($obj[0] == 'content') {
                $ret->content = $obj[1];
                $content = base64_decode($obj[1]);
                preg_match_all('#<img src="([^"]+)"[^>]*"#', $content, $matches);
                $pics = [];
                foreach ($matches[1] as $idx => $file_name) {
                    $img_name = explode('_html_', $file_name)[1];
                    if (!preg_match('/width="(\d+)" height="(\d+)"/', $matches[0][$idx], $matches2)) {
                    }
                    $pics[] = [$img_name, $matches2[1], $matches2[2], $idx];
                }
                $ret->pics = $pics;
            } else {
                $ret->{$obj[0]} = $obj[1];
            }
        }

        file_put_contents(__DIR__ . "/htmlfile/{$basename}", json_encode($ret));
        return $pics;
    }

    public static function parseTxtFile($basename)
    {
        $docfile = __DIR__ . "/docfile/{$basename}";
        if (!file_exists("txtfile/" . $basename) or filesize("txtfile/{$basename}") == 0) {
            system(sprintf("antiword %s > %s", escapeshellarg($docfile), escapeshellarg("txtfile/{$basename}")));
        }

        if (file_exists("txtfile/" . $basename)) {
            // 檢查是否有圖片，有的話就解出來轉檔
            $cmd = sprintf("grep --quiet %s %s", escapeshellarg('\[pic\]'), escapeshellarg('txtfile/' . $basename));
            system($cmd, $ret);
            if ($ret) {
                return;
            }
            $pics = LYLib::parseDoc($docfile);
            $content = file_get_contents(__DIR__ . "/txtfile/{$basename}");
            $uploading_pics = [];
            $content = preg_replace_callback('/\[pic\]/', function($matches) use (&$pics, $basename, &$uploading_pics) {
                if (!$pics) {
                    print_r($pics);
                    throw new Exception("圖片數量不正確: {$basename}");
                }
                $pic = array_shift($pics);
                if ($pic[2] < 10) {
                    return '==========';
                }
                $uploading_pics[$pic[0]] = true;
                return "[pic:https://lydata.ronny-s3.click/picfile/{$basename}-{$pic[0]}]";
            }, $content);

            file_put_contents(__DIR__ . "/txtfile/{$basename}", $content);
        }
    }

    protected static $_person_data = null;
    public static function isLyerName($term, $speaker)
    {
        if (is_null(self::$_person_data)) {
            error_log("抓取歷屆委員名單");
            $fp = fopen('php://temp', 'rw');
            fputs($fp, LYLib::getPersonList());
            fseek($fp, 0, SEEK_SET);
            $columns = fgetcsv($fp);
            $columns[0] = 'term';

            $person_data = [];
            while ($rows = fgetcsv($fp)) {
                $values = array_combine($columns, $rows);
                $values['term'] = intval($values['term']);
                $person_data[$values['term'] . '-' . $values['name']] = 
                    [$values['term'], $values['name'], $values['picUrl'], $values['partyGroup']];
            }
            self::$_person_data = $person_data;
        }
        $k = intval($term) . '-' . str_replace('委員', '', $speaker);
        if (array_key_exists($k, self::$_person_data) and $d = self::$_person_data[$k]) {
            return $d[1];
        }
        return false;
    }

    public static function createIndex($name, $data)
    {
        $prefix = getenv('ELASTIC_PREFIX');
        return self::dbQuery("/{$prefix}{$name}", 'PUT', json_encode([
            'mappings' => $data,
        ]));
    }

    public static function dropIndex($name)
    {
        $prefix = getenv('ELASTIC_PREFIX');
        return self::dbQuery("/{$prefix}{$name}", 'DELETE');
    }
}
