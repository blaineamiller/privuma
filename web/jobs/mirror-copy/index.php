<?php

exec(__DIR__ . '/../../bin/rclone --config ' . __DIR__ . '/../../config/rclone.conf --exclude "#recycle/**" --exclude "@eaDir/**" --exclude "@eaDir/" --ignore-existing --size-only --transfers 2  --checkers 2 --s3-chunk-size 64M -v --log-file=' . realpath(__DIR__ . '/../../logs/mirror-copy-out.txt') .' copy ' . realpath(__DIR__ . '/../../') . ' privuma:privuma/');