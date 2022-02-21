<?php

namespace privuma\output\format;

//uncomment to allow app to reauth
//echo "[]";
//die();
ini_set('session.cookie_lifetime', 60 * 60 * 24 * 30);
ini_set('session.gc_maxlifetime', 60 * 60 * 24 * 30);
date_default_timezone_set('America/Los_Angeles');

session_start();


use privuma\helpers\dotenv;
use privuma\privuma;
use privuma\helpers\cloudFS;
use privuma\helpers\mediaFile;
use mysqli;
use PDO;

$ops = privuma::getCloudFS();

$USE_MIRROR= privuma::getEnv('USE_MIRROR');
$RCLONE_MIRROR = privuma::getEnv('RCLONE_MIRROR');
$opsMirror = new cloudFS($RCLONE_MIRROR);

$SYNC_FOLDER = '/data/privuma';
$FALLBACK_ENDPOINT = privuma::getEnv('FALLBACK_ENDPOINT');
$ENDPOINT = privuma::getEnv('ENDPOINT');
$AUTHTOKEN = privuma::getEnv('AUTHTOKEN');
$RCLONE_DESTINATION = privuma::getEnv('RCLONE_DESTINATION');
$USE_X_Accel_Redirect = privuma::getEnv('USE_X_Accel_Redirect');

$rcloneConfig = parse_ini_file(privuma::getConfigDirectory() . DIRECTORY_SEPARATOR . 'rclone' . DIRECTORY_SEPARATOR . 'rclone.conf', true);



function RClone_S3_PresignedURL($AWSAccessKeyId, $AWSSecretAccessKey, $BucketName, $AWSRegion, $canonical_uri, $S3Endpoint = null, $expires = 86400)
{
    $encoded_uri = str_replace('%2F', '/', rawurlencode($canonical_uri));
    // Specify the hostname for the S3 endpoint
    if(!is_null($S3Endpoint)) {
        $hostname = trim(str_replace("https://", '', str_replace("http://", '', $S3Endpoint)));
        $encoded_uri = "/".$BucketName.$encoded_uri;
        $header_string = "host:" . $hostname . "\n";
        $signed_headers_string = "host";
    } else if ($AWSRegion == 'us-east-1') {
        $hostname = trim($BucketName . ".s3.amazonaws.com");
        $header_string = "host:" . $hostname . "\n";
        $signed_headers_string = "host";
    } else {
        $hostname =  trim($BucketName . ".s3-" . $AWSRegion . ".amazonaws.com");
        $header_string = "host:" . $hostname . "\n";
        $signed_headers_string = "host";
    }

    $currentTime = time();
    $date_text = gmdate('Ymd', $currentTime);

    $time_text = $date_text . 'T' . gmdate('His', $currentTime) . 'Z';
    $algorithm = 'AWS4-HMAC-SHA256';
    $scope = $date_text . "/" . $AWSRegion . "/s3/aws4_request";

    $x_amz_params = array(
        'X-Amz-Algorithm' => $algorithm,
        'X-Amz-Credential' => $AWSAccessKeyId . '/' . $scope,
        'X-Amz-Date' => $time_text,
        'X-Amz-SignedHeaders' => $signed_headers_string
    );

    // 'Expires' is the number of seconds until the request becomes invalid
    $x_amz_params['X-Amz-Expires'] = $expires + 30; // 30seocnds are less
    ksort($x_amz_params);

    $query_string = "";
    foreach ($x_amz_params as $key => $value) {
        $query_string .= rawurlencode($key) . '=' . rawurlencode($value) . "&";
    }

    $query_string = substr($query_string, 0, -1);

    $canonical_request = "GET\n" . $encoded_uri . "\n" . $query_string . "\n" . $header_string . "\n" . $signed_headers_string . "\nUNSIGNED-PAYLOAD";
    $string_to_sign = $algorithm . "\n" . $time_text . "\n" . $scope . "\n" . hash('sha256', $canonical_request, false);

    $signing_key = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', $AWSRegion, hash_hmac('sha256', $date_text, 'AWS4' . $AWSSecretAccessKey, true), true), true), true);

    $signature = hash_hmac('sha256', $string_to_sign, $signing_key);
    return 'https://' . $hostname . $encoded_uri . '?' . $query_string . '&X-Amz-Signature=' . $signature;
}


function redirectToMedia($path) {
    global $ops;
    global $USE_X_Accel_Redirect;
    $path = $ops->encode($path);

    if ($USE_X_Accel_Redirect){
        header('Content-Type: ' . mime_content_type(privuma::canonicalizePath(ltrim( $path, DIRECTORY_SEPARATOR))));
        header('X-Accel-Redirect: ' . DIRECTORY_SEPARATOR . $path);
        die();
    }

    global $USE_MIRROR;

    if($USE_MIRROR) {
        global $RCLONE_MIRROR;
        global $rcloneConfig;
        $mirror_parts = explode(':', $RCLONE_MIRROR);
        $rclone_config_key = $mirror_parts[0];
        $bucket = explode(DIRECTORY_SEPARATOR, trim($mirror_parts[1], DIRECTORY_SEPARATOR))[0];
        $rclone_config = $rcloneConfig[$rclone_config_key];
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $filenameSansExtension = basename($path, "." . $ext);
        $compressedPath = dirname($path) . DIRECTORY_SEPARATOR . $ops->encode($ops->decode($filenameSansExtension) . "---compressed." . $ext);

        if($rclone_config['type'] == 's3') {
            $key = $rclone_config['access_key_id'];
            $secret = $rclone_config['secret_access_key'];
            $endpoint = $rclone_config['endpoint'];

            $url = RClone_S3_PresignedURL($key, $secret, $bucket, isset($rclone_config['region']) ? $rclone_config['region'] : '', $path, $endpoint, $expires = 86400);
            $headers = get_headers($url, TRUE);
            $head = array_change_key_case($headers);
            if ( strpos($headers[0], '200') === FALSE || (strpos($head['content-type'], 'image') === FALSE && strpos($head['content-type'], 'video') === FALSE) ) {
                $url = RClone_S3_PresignedURL($key, $secret, $bucket, '', $compressedPath, $endpoint, $expires = 86400);
                $headers = get_headers($url, TRUE);
                $head = array_change_key_case($headers);
                if (strpos($headers[0], '200') === false || (strpos($head['content-type'], 'image') === false && strpos($head['content-type'], 'video') === false)) {
                    die('Mirror not operational for ' . $compressedPath);
                }
            }
        } else {
            $url = $ops->public_link($path);
            if($url == false) {
                $url = $ops->public_link($compressedPath);
                if ($url == false) {
                    die("Mirrored File not found: " . $compressedPath);
                }
            }
        }

        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Cache-Control: post-check=0, pre-check=0", false);
        header("Pragma: no-cache");
        header('Location: ' . $url);
        die();
    }


}


function connectToDB() {
    $host = privuma::getEnv('MYSQL_HOST');
    $db   = privuma::getEnv('MYSQL_DATABASE');
    $user = privuma::getEnv('MYSQL_USER');
    $pass =  privuma::getEnv('MYSQL_PASSWORD');

    $charset = 'utf8mb4';
    $port = 3306;

    $conn = new mysqli(
        $host,
        $user,
        $pass,
        $db,
        $port
    );

    if ($conn->connect_error) {
        die("DB Connection failed: " . $conn->connect_error);
    }

    $conn->set_charset("utf8");
    $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
    } catch (\PDOException $e) {
        throw new \PDOException($e->getMessage(), (int)$e->getCode());
        exit(1);
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS  `media` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `dupe` int(11) DEFAULT 0,
        `hash` varchar(512) DEFAULT NULL,
        `album` varchar(1000) DEFAULT NULL,
        `filename` varchar(1000) DEFAULT NULL,
        `time` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `media_id_IDX` (`id`) USING BTREE,
        KEY `media_hash_IDX` (`hash`) USING BTREE,
        KEY `media_album_IDX` (`album`(768)) USING BTREE,
        KEY `media_filename_IDX` (`filename`(768)) USING BTREE,
        KEY `media_time_IDX` (`time`) USING BTREE,
        KEY `media_idx_album_dupe_hash` (`album`(255),`dupe`,`hash`(255)),
        KEY `media_filename_time_IDX` (`filename`(512),`time`) USING BTREE,
        FULLTEXT KEY `media_filename_FULL_TEXT_IDX` (`filename`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    return [
        "pdo" => $pdo,
        "mysqli" => $conn,
    ];


}

function rollingTokens($seed) {
    $d1 = new \DateTime("yesterday");
    $d2 = new \DateTime("today");
    $d3 = new \DateTime("tomorrow");
    return [
        sha1(md5($d1->format('Y-m-d'))."-".$seed),
        sha1(md5($d2->format('Y-m-d'))."-".$seed),
        sha1(md5($d3->format('Y-m-d'))."-".$seed),
    ];
};

function checkToken($token, $seed) {
    return in_array($token, rollingTokens($seed));
}

function is_base64_encoded($data)
{
    if (preg_match('%^[a-zA-Z0-9/+]*={0,2}$%', $data)) {
       return TRUE;
    } else {
       return FALSE;
    }
};

if (session_status() == PHP_SESSION_ACTIVE && isset($_SESSION['SessionAuth']) && $_SESSION['SessionAuth'] === $AUTHTOKEN) {
    run();
} else if (isset($_GET['AuthToken']) && $_GET['AuthToken'] === $AUTHTOKEN) {
    $_SESSION['SessionAuth'] = $_GET['AuthToken'];
    run();
} else if(isset($_GET['token']) && checkToken($_GET['token'], $AUTHTOKEN)) {
    run();
} else {
    die("Malformed Request");
}

function normalizeString($str = '')
{
    //remove accents
    $str = strtr(utf8_decode($str), utf8_decode('àáâãäçèéêëìíîïñòóôõöùúûüýÿÀÁÂÃÄÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜÝ'), 'aaaaaceeeeiiiinooooouuuuyyAAAAACEEEEIIIINOOOOOUUUUY');
    //replace directory symbols
    $str = preg_replace('/[\/\\\\]+/', '-', $str);
    //replace symbols;
    $str = preg_replace('/[\:]+/', '_', $str);
    //replace foreign characters
    return preg_replace('/[^a-zA-Z0-9_\-\s\(\)~]+/', '', $str);
}

function realFilePath($filePath, $dirnamed_sync_folder = false)
{

    $mf = new mediaFile(basename($filePath), basename(dirname($filePath)));

    return $mf->realPath();

    global $SYNC_FOLDER;
    global $ops;

    $root = $dirnamed_sync_folder ? dirname($SYNC_FOLDER) : $SYNC_FOLDER;

    $ext = pathinfo($filePath, PATHINFO_EXTENSION);

    $filename = basename($filePath, "." . $ext);
    $album = normalizeString(basename(dirname($filePath)));

    $filePath = $root . DIRECTORY_SEPARATOR . $album . DIRECTORY_SEPARATOR . $filename . "." . $ext;
    $compressedFile = $root . DIRECTORY_SEPARATOR . $album  . DIRECTORY_SEPARATOR . $filename . "---compressed." . $ext;

    $dupe = $root . DIRECTORY_SEPARATOR . $album  . DIRECTORY_SEPARATOR . $filename . "---dupe." . $ext;

    $files = $ops->glob($root . DIRECTORY_SEPARATOR . $album . DIRECTORY_SEPARATOR . explode('---', $filename)[0]. "*.*");
    if($files === false) {
        $files = [];
    }
    if ($ops->is_file($filePath)) {
        return $filePath;
    } else if ($ops->is_file($compressedFile)) {
        return $compressedFile;
    } else if ($ops->is_file($dupe)) {
        return $dupe;
    } else if (count($files) > 0) {
        if (strtolower($ext) == "mp4" || strtolower($ext) == "webm") {
            foreach ($files as $file) {
                $iext = pathinfo($file, PATHINFO_EXTENSION);
                if (strtolower($ext) == strtolower($iext)) {
                    return $file;
                }
            }
        } else {
            foreach ($files as $file) {
                $iext = pathinfo($file, PATHINFO_EXTENSION);
                if (strtolower($iext) !== "mp4" && strtolower($iext) !== "webm") {
                    return $file;
                }
            }
        }
    }

    return false;
}

function getProtectedUrlForMediaPath($path, $use_fallback = false) {
    global $ENDPOINT;
    global $FALLBACK_ENDPOINT;
    global $AUTHTOKEN;
    $uri = "?token=" . rollingTokens($AUTHTOKEN)[1]  . "&media=" . urlencode(base64_encode($path));
    return $use_fallback ? $FALLBACK_ENDPOINT . $uri : $ENDPOINT . $uri;
}


function getProtectedUrlForMedia($media, $use_fallback = false) {
    global $ENDPOINT;
    global $FALLBACK_ENDPOINT;
    global $AUTHTOKEN;
    $uri = "?token=" . rollingTokens($AUTHTOKEN)[1]  . "&media=" . $media;
    return $use_fallback ? $FALLBACK_ENDPOINT . $uri : $ENDPOINT . $uri;
}

function getProtectedUrlForMediaHash($hash, $use_fallback = false) {
    global $ENDPOINT;
    global $FALLBACK_ENDPOINT;
    global $AUTHTOKEN;
    $uri = "?token=" . rollingTokens($AUTHTOKEN)[1]  . "&media=" . urlencode($hash);
    return $use_fallback ? $FALLBACK_ENDPOINT . $uri : $ENDPOINT . $uri;
}

function streamMedia($file, bool $useOps = false) {
    global $ops;
    header('Accept-Ranges: bytes');
    header('Content-Disposition: inline');
    if($useOps){
        header('Content-Type: ' . get_mime_by_filename($file));
        header('Content-Length:' . $ops->filesize($file));
        $ops->readfile($file);
    }else {
        $head = array_change_key_case(get_headers($file, TRUE));
        header('Content-Type: ' . $head['content-type']);
        header('Content-Length:' . $head['content-length']);
        readfile($file);
    }
    exit;
}

function isValidMd5($md5 ='') {
    return strlen($md5) == 32 && ctype_xdigit($md5);
  }


function run()
{
    global $conn;
    global $SYNC_FOLDER;
    global $ENDPOINT;
    global $AUTHTOKEN;
    global $USE_MIRROR;
    global $ops;
    global $opsMirror;
    global $RCLONE_MIRROR;
    global $USE_X_Accel_Redirect;


    $MAX_URL_CHARACTERS = 1600;

    if (isset($_GET['album']) || isset($_GET['amp;album'])) {
        $albumName = $_GET['album'] ?? $_GET['amp;album'];

        if(is_base64_encoded($albumName)) {
            $albumName = base64_decode($albumName);
        }

        $conn = connectToDB()["mysqli"];

        $stmt = $conn->prepare('select filename, album, time, hash
        from media
        where hash in
        (select hash from media where album = ? and dupe != 0 and hash != "compressed")
        and dupe = 0
        union ALL
        select filename, album, time, hash
        from media
        WHERE
        album = ? and dupe = 0 and hash != "compressed"
        group by filename
         order by
            CASE
                WHEN filename LIKE "%.gif" THEN 4
                WHEN filename LIKE "%.mp4" THEN 3
                WHEN filename LIKE "%.webm" THEN 2
                ELSE 1
            END DESC,
            time DESC
        ');

        $stmt->bind_param("ss", $albumName, $albumName);
        $stmt->execute();
        $result = $stmt->get_result();

        $media = [];
        while ($item = $result->fetch_assoc()) {
            if (!isset($item["filename"])) {
                continue;
            }

            $ext = pathinfo($item["filename"], PATHINFO_EXTENSION);
            $filename = basename($item["filename"], "." . $ext);
            $filePath = $SYNC_FOLDER . DIRECTORY_SEPARATOR . normalizeString($item['album']) . DIRECTORY_SEPARATOR . $item["filename"];
            $fileParts = explode('---', basename($filePath, "." . $ext));
            $hash = $fileParts[1] ?? $item['hash'];
            $relativePath = normalizeString($item['album']) . "-----" . basename($filePath);

            if (strtolower($ext) === "mp4") {


                $destt = $item['album'] . DIRECTORY_SEPARATOR . basename($filePath, ".".$ext) . ".jpg";
                $mediat = urlencode(base64_encode($destt));
                $dest = $item['album'] . DIRECTORY_SEPARATOR . basename($filePath, ".".$ext). ".".$ext;
                $mediaval = urlencode(base64_encode($dest));
                if(strlen($mediaval) > $MAX_URL_CHARACTERS) {
                    mediaFile::sanitize($hash, $dest);
                    $mediaval = $item['hash'];
                    $mediat = "t-".$item['hash'];
                }
                $videoPath = getProtectedUrlForMedia($mediaval);
                $photoPath = getProtectedUrlForMedia($mediat);
            } else {

                $dest = $item['album'] . DIRECTORY_SEPARATOR . basename($filePath, ".".$ext). ".".$ext;
                $mediaval = urlencode(base64_encode($dest));
                if(strlen($mediaval) > $MAX_URL_CHARACTERS) {
                    mediaFile::sanitize($hash, $dest);
                    $mediaval = $item['hash'];
                    $mediat = "t-".$item['hash'];
                }
                $photoPath = getProtectedUrlForMedia($mediaval);
            }

            $mime = (isset($videoPath)) ? "video/mp4": ((strtolower($ext) === "gif") ? "image/gif" :  ((strtolower($ext) === "png") ? "image/png" : "image/jpg")) ;
            if (!array_key_exists($hash, $media)) {
                $media[$hash] = array("img" => $photoPath ?? "", "updated" => strtotime($item["time"]), "video" => $videoPath ?? "", "id" => (string)$hash, "filename" => (string)$fileParts[0], "mime" => (string)$mime, "epoch" => strtotime($item["time"]));
            } elseif (isset($videoPath)) {
                $media[$hash]["video"] = $videoPath;
            } elseif (isset($photoPath)) {
                $media[$hash]["img"] = $photoPath;
            }

            unset($videoPath);
            unset($photoPath);
        }

        if(empty($media)) {
            $albumFSPath = str_replace('-----', DIRECTORY_SEPARATOR, $albumName);
            $scan = $ops->scandir(dirname($SYNC_FOLDER) . DIRECTORY_SEPARATOR . $albumFSPath, false, false, [...array_map(function($ext) {
                return '+ *.' . $ext;
            }, ['mp4','jpg','jpeg','gif','png','heif']), '-**']);
            if($scan === false) {
                $scan = [];
            }
            natcasesort($scan);
            foreach(array_diff($scan, ['.','..']) as $file) {
                $filePath = dirname($SYNC_FOLDER) . DIRECTORY_SEPARATOR . $albumFSPath . DIRECTORY_SEPARATOR . $file;

                $ext = pathinfo($file, PATHINFO_EXTENSION);
                $filename = basename($file, "." . $ext);

                $videoPathTest = dirname($SYNC_FOLDER) . DIRECTORY_SEPARATOR . $albumFSPath . DIRECTORY_SEPARATOR . $filename .".mp4";
                $thumbailPathTest = dirname($SYNC_FOLDER) . DIRECTORY_SEPARATOR . $albumFSPath . DIRECTORY_SEPARATOR . $filename .".jpg";
                $hash = md5(dirname($SYNC_FOLDER) . DIRECTORY_SEPARATOR . $albumFSPath . DIRECTORY_SEPARATOR . $filename);

                $fileParts = explode('---', basename($filePath, "." . $ext));
                $relativePath = str_replace(DIRECTORY_SEPARATOR, '-----', $filePath);

                if (strtolower($ext) === "mp4") {
                    $destt = dirname($SYNC_FOLDER) . DIRECTORY_SEPARATOR . $albumFSPath . basename($filePath, ".".$ext) . ".jpg";
                    $mediat = urlencode(base64_encode($destt));
                    $dest = dirname($SYNC_FOLDER) . DIRECTORY_SEPARATOR . $albumFSPath . DIRECTORY_SEPARATOR . basename($filePath, ".".$ext). ".".$ext;
                    $mediai = urlencode(base64_encode($dest));
                    if(strlen($mediai) > $MAX_URL_CHARACTERS) {
                        mediaFile::sanitize($hash, $dest);
                        $mediai = $hash;
                        $mediat = "t-".$hash;
                    }
                    $videoPath = getProtectedUrlForMedia($mediai);
                    $photoPath = getProtectedUrlForMedia($mediat);
                } else if($ops->is_file($videoPathTest)) {

                    $destt = dirname($SYNC_FOLDER) . DIRECTORY_SEPARATOR . $albumFSPath . basename($filePath, ".".$ext) . ".jpg";
                    $mediat = urlencode(base64_encode($destt));
                    $dest = $videoPathTest;
                    $mediai = urlencode(base64_encode($dest));
                    if(strlen($mediai) > $MAX_URL_CHARACTERS) {
                        mediaFile::sanitize($hash, $dest);
                        $mediai = $hash;
                        $mediat = "t-".$hash;
                    }
                    $videoPath = getProtectedUrlForMedia($mediai);
                    $photoPath = getProtectedUrlForMedia($mediat);
                } else {
                    unset($videoPath);
                    $dest = dirname($SYNC_FOLDER) . DIRECTORY_SEPARATOR . $albumFSPath . DIRECTORY_SEPARATOR . basename($filePath, ".".$ext). ".".$ext;
                    $mediai = urlencode(base64_encode($dest));
                    if(strlen($mediai) > $MAX_URL_CHARACTERS) {
                        mediaFile::sanitize($hash, $dest);
                        $mediai = $hash;
                        $mediat = "t-".$hash;
                    }
                    $photoPath = getProtectedUrlForMedia($mediai);
                }

                $mime = (isset($videoPath)) ? "video/mp4": ((strtolower($ext) === "gif") ? "image/gif" :  ((strtolower($ext) === "png") ? "image/png" : "image/jpg")) ;

                $media[$hash] = array("img" => $photoPath ?? "", "updated" => 1, "video" => $videoPath ?? "", "id" => (string)$hash, "filename" => (string)$fileParts[0], "mime" => (string)$mime, "epoch" => 1);
            }
        }

        $media = array_values($media);
        $photos = array("gtoken" => urlencode($ENDPOINT."...".$AUTHTOKEN), "gdata" => $media);
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Cache-Control: post-check=0, pre-check=0", false);
        header("Pragma: no-cache");
        header('Content-Type: application/json');
        print(json_encode($photos, JSON_UNESCAPED_SLASHES));

    } else if (isset($_GET['media'])) {
        set_time_limit(2);
        if(strpos($_GET['media'], 't-') === 0 ) {
            $hash = str_replace('t-','',$_GET['media']);
            $original = mediaFile::desanitize($hash);
            if($original !== $hash) {
                $file = ltrim($original, DIRECTORY_SEPARATOR);
            }else {
                $file = (str_replace(mediaFile::MEDIA_FOLDER.DIRECTORY_SEPARATOR, '', (new mediaFile('foo','bar',null, $hash))->original()));
                mediaFile::sanitize($hash, $file);
            }

            $ext = pathinfo($file, PATHINFO_EXTENSION);
            $filename = basename($file, "." . $ext);
            $_GET['media'] = str_Replace(DIRECTORY_SEPARATOR, '-----',dirname($file) . DIRECTORY_SEPARATOR . $filename . ".jpg");
        }else if(isValidMd5($_GET['media'])) {
            $hash = $_GET['media'];
            $original = mediaFile::desanitize($hash);
            if($original !== $hash) {
                $file = ltrim($original, DIRECTORY_SEPARATOR);
            }else {
                $file = (str_replace(mediaFile::MEDIA_FOLDER.DIRECTORY_SEPARATOR, '', (new mediaFile('foo','bar',null, $hash))->original()));
                mediaFile::sanitize($hash, $file);
            }
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            $filename = basename($file, "." . $ext);
            $_GET['media'] = str_Replace(DIRECTORY_SEPARATOR, '-----',dirname($file) . DIRECTORY_SEPARATOR . $filename . "." . $ext);
        }else if(is_base64_encoded($_GET['media'])) {
            $_GET['media'] = base64_decode($_GET['media']);
        }

        if ($_GET['media'] === "blank.gif") {
            header('Content-Type: image/gif');
            echo base64_decode('R0lGODlhAQABAJAAAP8AAAAAACH5BAUQAAAALAAAAAABAAEAAAICBAEAOw==');
            return;
        }

        $mediaPath = str_replace('..', '', str_replace(DIRECTORY_SEPARATOR.DIRECTORY_SEPARATOR,DIRECTORY_SEPARATOR,str_replace('-----', DIRECTORY_SEPARATOR, $_GET['media'])));

        $pos = strpos($mediaPath, 'data' . DIRECTORY_SEPARATOR);
        if ($pos !== false) {
            $mediaPath = substr_replace($mediaPath, '', $pos, strlen('data' . DIRECTORY_SEPARATOR));
        }

        if (count(explode(DIRECTORY_SEPARATOR, $mediaPath)) == 2) {
            $file =   DIRECTORY_SEPARATOR . privuma::getDataFolder() . DIRECTORY_SEPARATOR . mediaFile::MEDIA_FOLDER . DIRECTORY_SEPARATOR . ltrim($mediaPath, DIRECTORY_SEPARATOR);
        } else {
            $file = DIRECTORY_SEPARATOR . privuma::getDataFolder() . DIRECTORY_SEPARATOR . ltrim($mediaPath, DIRECTORY_SEPARATOR);
        }
        redirectToMedia($file);

        if (strpos($ENDPOINT, $_SERVER['HTTP_HOST']) == false ){
            header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
            header("Cache-Control: post-check=0, pre-check=0", false);
            header("Pragma: no-cache");
            header('Location: ' . $ENDPOINT . "?token=" . rollingTokens($_SESSION['SessionAuth'])[1]  . "&media=" . urlencode($_GET['media']));
            die();
        }

        if(is_base64_encoded($_GET['media'])) {
            $_GET['media'] = base64_decode($_GET['media']);
        }

        if ($_GET['media'] === "blank.gif") {
            header('Content-Type: image/gif');
            echo base64_decode('R0lGODlhAQABAJAAAP8AAAAAACH5BAUQAAAALAAAAAABAAEAAAICBAEAOw==');
            return;
        }
        if(!isset($hash)) {
            $file = realFilePath($SYNC_FOLDER . DIRECTORY_SEPARATOR . $mediaPath);
        }else {
            $file = privuma::getDataFolder() . DIRECTORY_SEPARATOR . $mediaPath;
        }


        if($file === false) {
            $file =  DIRECTORY_SEPARATOR . ltrim($ops->encode($mediaPath), DIRECTORY_SEPARATOR);
        }

        $ext = pathinfo($mediaPath, PATHINFO_EXTENSION);
        $album = explode(DIRECTORY_SEPARATOR, $mediaPath)[0];
        if (!$ops->is_file($file)) {
            die('Media file not found ' . $file);
        }

        $mediaPath = basename(dirname($file)) . DIRECTORY_SEPARATOR . basename($file);
        if (strpos($mediaPath, "---dupe") !== false && $ops->filesize($mediaPath) <= 512) {
            $mediaPath = $ops->file_get_contents($file);
            $file = realFilePath($SYNC_FOLDER . DIRECTORY_SEPARATOR . $mediaPath);
        }

        if (!$ops->is_file($file)) {
            die('Media file not found ' . $file);
        }

        if ($USE_X_Accel_Redirect){
            header('Content-Type: ' . mime_content_type(privuma::canonicalizePath(ltrim( $ops->encode($file), DIRECTORY_SEPARATOR))));
        	header('X-Accel-Redirect: ' . DIRECTORY_SEPARATOR . $ops->encode($file));
            die();
        }

        streamMedia($file, true);
    } else {
        $realbums = [];

        $conn = connectToDB()["mysqli"];
        foreach(json_decode(file_get_contents(privuma::getOutputDirectory() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . "mediadirs.json"), true) as $folderObj) {
            if(isset($folderObj['HasThumbnailJpg']) && $folderObj['HasThumbnailJpg']) {
                $ext = pathinfo($folderObj["Name"], PATHINFO_EXTENSION);
                $hash = md5(dirname($folderObj['Path']) . DIRECTORY_SEPARATOR . basename($folderObj['Path'], "." . $ext));

                $dest = dirname($SYNC_FOLDER) . DIRECTORY_SEPARATOR . $folderObj['Path'] . DIRECTORY_SEPARATOR . "1.jpg";
                $media = urlencode(base64_encode($dest));
                if(strlen($media) > $MAX_URL_CHARACTERS) {
                    mediaFile::sanitize($hash, $dest);
                    $media = "t-".$hash;
                }
                $photoPath = getProtectedUrlForMedia($media);

                //$photoPath = $ENDPOINT . "?token=" . rollingTokens($_SESSION['SessionAuth'])[1]  . "&media=".urlencode(base64_encode(str_replace(DIRECTORY_SEPARATOR, '-----', dirname($SYNC_FOLDER) . DIRECTORY_SEPARATOR . $folderObj['Path']) . "-----" . "1.jpg"));
            } else {
                $photoPath = $ENDPOINT . "?token=" . rollingTokens($_SESSION['SessionAuth'])[1]  . "&media=blank.gif";;
                $hash = "checkCache";
            }
            if(!in_array(explode(DIRECTORY_SEPARATOR, $folderObj['Path'])[0], ['SCRATCH'])) {

                $realbums[] = array("id" => (string)urlencode(base64_encode(implode('-----', explode(DIRECTORY_SEPARATOR, $folderObj['Path'])))), "updated" => (string)(strtotime(explode('.', $folderObj['ModTime'])[0])*1000), "title" => (string)implode('---', explode(DIRECTORY_SEPARATOR, $folderObj['Path'])), "img" => (string)$photoPath , "mediaId" => (string)$hash);

            }
        }
        $result = $conn->query('select filename, album, max(time) as time, hash FROM media where dupe = 0 GROUP by album order by time DESC;');
        while ($album = $result->fetch_assoc()) {
            $ext = pathinfo($album["filename"], PATHINFO_EXTENSION);
            $filename = basename($album["filename"], "." . $ext);
            $filePath = $album['album'] . DIRECTORY_SEPARATOR . $album["filename"];
            $relativePath = $album['album'] . "-----" . basename($filePath);
            if (strtolower($ext) === "mp4") {
                $dest = $album['album'] . DIRECTORY_SEPARATOR . basename($filePath, ".".$ext) . ".jpg";
                $media = urlencode(base64_encode($dest));
                if(strlen($media) > $MAX_URL_CHARACTERS) {
                    mediaFile::sanitize($hash, $dest);
                    $media = "t-".$album['hash'];
                }
                $photoPath = getProtectedUrlForMedia($media);
            } else {

                $dest = $album['album'] . DIRECTORY_SEPARATOR . basename($filePath, ".".$ext) . "." . $ext;
                $media = urlencode(base64_encode($dest));
                if(strlen($media) > $MAX_URL_CHARACTERS) {
                    mediaFile::sanitize($hash, $dest);
                    $media = $album['hash'];
                }
                $photoPath = getProtectedUrlForMedia($media);
            }

            if (empty($photoPath)) {
                $photoPath = $ENDPOINT . "?token=" . rollingTokens($_SESSION['SessionAuth'])[1]  . "&media=blank.gif";
            }

            $realbums[] = array("id" => (string)urlencode(base64_encode($album["album"])), "updated" => (string)(strtotime($album["time"])*1000), "title" => (string)$album["album"], "img" => (string)$photoPath , "mediaId" => (string)$album["hash"]);
        }

        usort($realbums, function ($a1, $a2) {
            return $a2['updated'] <=> $a1['updated'];
        });


        $realbums = array("gtoken" => urlencode($ENDPOINT."...".$AUTHTOKEN), "gdata" => $realbums);
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Cache-Control: post-check=0, pre-check=0", false);
        header("Pragma: no-cache");
        header('Content-Type: application/json');
        print(json_encode($realbums, JSON_UNESCAPED_SLASHES, 10));
        exit();
    }
}

function get_mime_by_filename($filename) {
    if (!is_file(privuma::getOutputDirectory() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR .'mimes.json')) {
       $db = json_decode(file_get_contents('https://cdn.jsdelivr.net/gh/jshttp/mime-db@master/db.json'), true);
       $mime_types = [];
       foreach ($db as $mime => $data) {
           if (!isset($data['extensions'])) {
               continue;
           }
           foreach ($data['extensions'] as $extension) {
               $mime_types[$extension] = $mime;
           }
       }

       file_put_contents(privuma::getOutputDirectory() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR .'mimes.json', json_encode($mime_types, JSON_PRETTY_PRINT));
    }

    $mime_types = json_decode(file_get_contents(privuma::getOutputDirectory() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR .'mimes.json'), true);
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (array_key_exists($ext, $mime_types)) {
        return $mime_types[$ext];
    }
    else {
        return 'application/octet-stream';
    }
}
