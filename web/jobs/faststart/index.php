<?php

require(__DIR__ . '/../../helpers/cloud-fs-operations.php'); 

$ops = new cloudFS\Operations();
$dest = '/data/privuma';


function getDirContents($dir, &$results = array())
{
    global $ops;
    $files = $ops->scandir($dir, true);

    foreach ($files as $obj) {
        $value = $obj['Name'];
        $path = $dir . DIRECTORY_SEPARATOR . $value;
        if (!$obj['IsDir']) {
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            $filename = basename($path, "." . $ext);
            $filenameParts = explode("---", $filename);

            if (count($filenameParts) > 1 && $ext === "mp4" && end($filenameParts) == "compressed") {
                // attempt fast start

                $tempFile = $ops->pull($path);

                $newFileTemp = tempnam(sys_get_temp_dir(), 'PVMA');
                rename($newFileTemp, $newFileTemp . '.mp4');
                $newFileTemp = $newFileTemp  . '.mp4';
                exec("/usr/bin/ffmpeg -threads 1 -hide_banner -loglevel error -y -i '" . $tempFile . "' -c copy -map 0 -movflags +faststart '" . $newFileTemp . "'", $void, $response);
                unset($void);
                if ($response == 0 ) {
                    echo PHP_EOL. "fast start successful for: " . $path;
                    $ops->copy($newFileTemp, $path, false);
                }
                unlink($newFileTemp);
                unlink($tempFile);
            }

        } else if ($value != "." && $value != ".." && $value != "@eaDir") {
            getDirContents($path);
        }
    }
}

    getDirContents($dest);

