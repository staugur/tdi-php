#!/usr/bin/env php
<?php

// 引入配置文件和工具
require_once './config.php';
require_once './tool.php';

function execute_cleanDownload(int $hours=12)
{
    if (!in_array($hours, [3, 6, 12, 24])) {
        $hours = 12;
    }
    // 下载目录
    $DOWNLOADPATH = __DIR__.'/downloads';
    if (!is_dir($DOWNLOADPATH)) {
        mkdir($DOWNLOADPATH);
    }
    if (is_dir($DOWNLOADPATH)) {
        // 连接redis
        $rc = getRedisConnect(REDIS);
        $files = scandir($DOWNLOADPATH);
        foreach ($files as $uifn) {
            $filepath = $DOWNLOADPATH.'/'.$uifn;
            if (is_file($filepath) && in_array(pathinfo($filepath, PATHINFO_EXTENSION), ['zip', 'tar'])) {
                list($aid, $mst) = explode('_', pathinfo($filepath, PATHINFO_FILENAME));
                if ($aid === 'hb') {
                    // 中心端接收到请求时的时间戳
                    $ctime = intval($mst / 1000);
                    // 实际生成压缩文件时的时间戳
                    $file_ctime = filectime($filepath);
                    if (($ctime + 3600 * $hours) <= time() && ($file_ctime + 3600 * $hours) <= time()) {
                        // 已过期，清理文件
                        unlink($filepath);
                        Log::info('Remove zip file: '.$filepath);
                        $data = $rc->hGetAll($uifn);
                        if (isset($data['CALLBACK_URL'])) {
                            $resp = request($data['CALLBACK_URL'].'?Action=SECOND_STATUS', array('uifn'=>$uifn));
                            Log::info($resp);
                            if (is_array($resp) && isset($resp['code'])) {
                                if ($resp['code'] === 0) {
                                    $rc->del($uifn);
                                }
                            }
                        }
                    }
                }
            }
        }
        $rc->close();
    }
}

// 仅支持命令行
if (php_sapi_name() === "cli") {
    //执行清理
    $hours = @intval($_SERVER['argv'][1]);
    execute_cleanDownload($hours);
} else {
    die('Only command-line mode access is allowed');
}
