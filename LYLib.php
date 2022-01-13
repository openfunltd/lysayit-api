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
        $content = curl_exec($curl);
    }
}
