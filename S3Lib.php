<?php


class S3Lib
{
    protected static $_s3 = null;
    public static function getS3()
    {
        if (is_null(self::$_s3)) {
            include(__DIR__ . '/aws/aws-autoloader.php');
            self::$_s3 = new Aws\S3\S3Client([
                'region' => 'ap-northeast-1',
                'version' => 'latest',
            ]);
        }
        return self::$_s3;
    }

    public function put($local_file, $target_path)
    {
        $s3 = self::getS3();
        $ret = $s3->putObject([
            'Bucket' => 'twlydata',
            'Key' => $target_path,
            'Body' => fopen($local_file, 'r'),
            'ACL' => 'public-read',
            'ContentType' => mime_content_type($local_file),
            'CacheControl' => 'max-age=31536000,public'
        ]);
    }

    public static function get($target_path)
    {
        $s3 = self::getS3();
        $result = $s3->getObject([
            'Bucket' => 'twlydata',
            'Key' => $target_path,
        ]);
        return $result['Body'];
    }
}
