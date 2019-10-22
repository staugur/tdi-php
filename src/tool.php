<?php

//严格模式
declare(strict_types=1);

/*
    //Web响应函数
    @param $msg string: 返回的消息
    @param $code int: 返回的消息标识状态
    @param $is_die bool: 是否中止访问
    @param $resp array: 直接返回的数据
*/
function make_response(string $msg=null, int $code=1, bool $is_die=true, array $resp=null)
{
    $resp = is_array($resp) ? json_encode($resp) : json_encode(array('code'=>$code, 'msg'=>$msg));
    if ($is_die === true) {
        die($resp);
    } else {
        echo $resp;
    }
}

//验证是否是有效的JSON串
function isValidJSON($str)
{
    json_decode($str);
    return json_last_error() == JSON_ERROR_NONE;
}

//Web路由中令牌验证
function signature_required(string $token)
{
    $signature = @$_GET['signature'];
    $timestamp = @$_GET['timestamp'];
    $nonce = @$_GET['nonce'];
    $args = [$token, $timestamp, $nonce];
    sort($args);
    $mysig = sha1(implode('', $args));
    if ($mysig !== $signature) {
        make_response('Invalid signature');
    }
}

class RedisDSNError extends Exception
{
    // 重定义构造器使 message 变为必须被指定的属性
    public function __construct($message, $code = 0)
    {
        // 自定义的代码
        // 确保所有变量都被正确赋值
        parent::__construct($message, $code);
    }
    // 自定义字符串输出的样式
    public function __toString()
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}

//解析redis连接串(DSN-style)
function parseRedisURI(string $url)
{
    $parsed_url = parse_url($url);
    if ($parsed_url) {
        if (isset($parsed_url['scheme'])) {
            if ($parsed_url['scheme'] != 'redis') {
                throw new RedisDSNError('The protocol of Redis URI look like redis://');
            }
        } else {
            throw new RedisDSNError('A protocol must be specified in the Redis URI connection scheme (e.g. redis:)');
        }
        if (!isset($parsed_url['host'])) {
            throw new RedisDSNError('A hostname must be specified in the Redis URI connection scheme (e.g. redis://localhost)');
        }
        return array(
            'auth' => isset($parsed_url['pass']) ? $parsed_url['pass'] : null,
            'host' => $parsed_url['host'],
            'port' => isset($parsed_url['port']) ? intval($parsed_url['port']) : 6379,
            'db' => isset($parsed_url['path']) ? intval(substr($parsed_url['path'], 1)) : 0
        );
    };
}

//建立redis连接实例，使用phpredis扩展
function getRedisConnect(string $url)
{
    $conf = parseRedisURI($url);
    $redis = new Redis();
    $redis->connect($conf['host'], $conf['port'], $conf['db'], '', 100); // 1 sec timeout, 100ms delay between reconnection attempts.
    if (isset($conf['auth']) && $conf['auth']) {
        $redis->auth($conf['auth']);
    }
    if ($conf['db'] != 0) {
        $redis->select($conf['db']);
    }
    return $redis;
}

//单位转换
function formatSize(int $bytes, int $decimals = 2)
{
    $quant = array(
        'T' => 1099511627776, // pow( 1024, 4)
        'G' => 1073741824, // pow( 1024, 3)
        'M' => 1048576, // pow( 1024, 2)
        'K' => 1024, // pow( 1024, 1)
        'B' => 1, // pow( 1024, 0)
    );

    foreach ($quant as $unit => $mag) {
        if (doubleval($bytes) >= $mag) {
            return number_format($bytes / $mag, $decimals) . '' . $unit;
        }
    }
    return false;
}

//内存使用率
function memRate()
{
    $res = array();
    // MEMORY
    if (false === ($str = @file('/proc/meminfo'))) {
        return false;
    }
    $str = implode('', $str);
    preg_match_all('/MemTotal\s{0,}\:+\s{0,}([\d\.]+).+?MemFree\s{0,}\:+\s{0,}([\d\.]+).+?Cached\s{0,}\:+\s{0,}([\d\.]+).+?SwapTotal\s{0,}\:+\s{0,}([\d\.]+).+?SwapFree\s{0,}\:+\s{0,}([\d\.]+)/s', $str, $buf);
    preg_match_all('/Buffers\s{0,}\:+\s{0,}([\d\.]+)/s', $str, $buffers);
    $res['memTotal'] = round($buf[1][0]*1024, 2);
    $res['memFree'] = round($buf[2][0]*1024, 2);
    $res['memBuffers'] = round($buffers[1][0]*1024, 2);
    $res['memCached'] = round($buf[3][0]*1024, 2);
    $res['memUsed'] = $res['memTotal']-$res['memFree']-$res['memBuffers']-$res['memCached'];
    $res['memPercent'] = (floatval($res['memTotal'])!=0)?round($res['memUsed']/$res['memTotal']*100, 2):0;
    return $res['memPercent'];
}

//五分钟负载
function loadStat()
{
    // LOAD AVG
    if (false === ($str = @file('/proc/loadavg'))) {
        return false;
    }
    $load = explode(' ', implode('', $str));
    return floatval($load[1]);
}

//磁盘使用率，返回字节
function diskRate(string $path=null, string $ret='percent')
{
    //硬盘
    $checkPath = $path ? $path : __DIR__;
    $dt = round(@disk_total_space($checkPath), 3); //总
    $df = round(@disk_free_space($checkPath), 3); //可用
    $du = $dt-$df; //已用
    $hdPercent = (floatval($dt)!=0)?round($du/$dt*100, 2):0;
    return $ret === 'percent' ? $hdPercent : array('total'=>$dt, 'available'=>$df, 'used'=>$du, 'percent'=>$hdPercent);
}

//用来统计一个目录的大小，返回字节
function getDirSize(string $dir, array $exclude=array())
{
    $sizeResult = 0;
    $handle = opendir($dir);
    while (false!==($FolderOrFile = readdir($handle))) {
        if ($FolderOrFile != "." && $FolderOrFile != "..") {
            $f = "$dir/$FolderOrFile";
            if (is_file($f) && !in_array(pathinfo($f, PATHINFO_EXTENSION), $exclude)) {
                $sizeResult += filesize($f);
            }
        }
    }
    closedir($handle);
    return $sizeResult;
}

//自定义函数递归的函数整个目录
function delDir($directory)
{
    if (file_exists($directory)) {//判断目录是否存在，如果不存在rmdir()函数会出错
        if ($dir_handle=@opendir($directory)) {//打开目录返回目录资源，并判断是否成功
            while ($filename=readdir($dir_handle)) {//遍历目录，读出目录中的文件或文件夹
                if ($filename!='.' && $filename!='..') {//一定要排除两个特殊的目录
                    $subFile=$directory."/".$filename;//将目录下的文件与当前目录相连
                    if (is_dir($subFile)) {//如果是目录条件则成了
                        delDir($subFile);//递归调用自己删除子目录
                    }
                    if (is_file($subFile)) {//如果是文件条件则成立
                        unlink($subFile);//直接删除这个文件
                    }
                }
            }
            closedir($dir_handle);//关闭目录资源
            rmdir($directory);//删除空目录
        }
    }
}

/*
    // 生成zip压缩文件的函数，参考：https://blog.csdn.net/zhao_teng/article/details/84941828
    @param $dir             string 需要压缩的文件夹名
    @param $filename     string 压缩后的zip文件名  包括zip后缀
    @param $exclude      array   不需要压缩的文件后缀，后缀不包含点
    @return 成功时返回压缩文件的绝对路径，否则返回false
*/
class MakeZip
{
    public function zip(string $dir, string $filename, array $exclude=array())
    {
        if (!is_dir($dir)) {
            die('can not exists dir '.$dir);
        }

        //判断是否为zip后缀
        if (pathinfo($filename, PATHINFO_EXTENSION) != 'zip') {
            die('only Support zip files');
        }
    
        $dir = str_replace('\\', '/', $dir);
        $filename = str_replace('\\', '/', $filename);
        $filename = iconv('utf-8', 'gb2312', $filename);
        if (is_file($filename)) {
            die('the zip file '.$filename.' has exists !');
        }

        //目录中的所有文件
        $files = array();
        getfiles($dir, $files);
        $files = $this->array_iconv($files);
        if (empty($files)) {
            die(' the dir is empty');
        }

        //执行压缩
        $zip = new ZipArchive();
        $res = $zip->open($filename, ZipArchive::CREATE);
        if ($res === true) {
            foreach ($files as $v) {
                // 设定在压缩包内文件名
                $_va = explode('/', $v);
                $_in_zip_filename = end($_va);
                // 依据文件后缀判断是否排除(即不压缩)
                if (is_file($v) && !in_array(pathinfo($_in_zip_filename, PATHINFO_EXTENSION), $exclude)) {
                    $zip->addFile($v, $_in_zip_filename);
                    $to_be_unlinked[] = $v;
                }
            }
            $zip->close();
            //由于zip需要close后才执行压缩操作，所以只能在这里删除压缩的文件
            foreach ($to_be_unlinked as $v) {
                unlink($v);
            }
            return realpath($filename);
        } else {
            return false;
        }
    }

    //定义图片字符集
    protected function array_iconv($data, $in_charset='GBK', $out_charset='UTF-8')
    {
        if (!is_array($data)) {
            $output = iconv($in_charset, $out_charset, $data);
        } elseif (count($data) === count($data, 1)) {//判断是否是二维数组
            foreach ($data as $key => $value) {
                $output[$key] = iconv($in_charset, $out_charset, $value);
            }
        } else {
            eval_r('$output = ' . iconv($in_charset, $out_charset, var_export($data, true)) . ';');
        }
        return $output;
    }
}

    //获取目录中文件赋值给$files
function getfiles($dir, &$files=array())
{
    if (!is_dir($dir)) {
        return false;
    }
    if (substr($dir, -1)=='/') {
        $dir = substr($dir, 0, strlen($dir)-1);
    }
    $_files = scandir($dir);
    foreach ($_files as $v) {
        if ($v != '.' && $v!='..') {
            if (is_file($dir.'/'.$v)) {
                $files[] = $dir.'/'.$v;
            }
        }
    }
    return $files;
}

function make_tarfile(string $tar_filename, string $tar_path, array $exclude=[])
{
    if (!is_dir($tar_path)) {
        die('Not exists dir '.$tar_path);
    }

    //判断是否为zip后缀
    if (pathinfo($tar_filename, PATHINFO_EXTENSION) != 'tar') {
        die('only Support tar files');
    }

    $tar_path = str_replace('\\', '/', $tar_path);
    $tar_filename = str_replace('\\', '/', $tar_filename);
    $files = array();
    getfiles($tar_path, $files);
    $tar = new PharData($tar_filename);

    // ADD FILES TO tar FILE
    foreach ($files as $v) {
        // 设定在压缩包内文件名
        $_va = explode('/', $v);
        $_in_zip_filename = end($_va);
        // 依据文件后缀判断是否排除(即不压缩)
        if (is_file($v) && !in_array(pathinfo($_in_zip_filename, PATHINFO_EXTENSION), $exclude)) {
            $tar->addFile($v, $_in_zip_filename);
            $to_be_unlinked[] = $v;
        }
    }

    // COMPRESS archive.tar FILE. COMPRESSED FILE WILL BE archive.tar.gz
    //$tar->compress(Phar::NONE);

    //由于zip需要close后才执行压缩操作，所以只能在这里删除压缩的文件
    foreach ($to_be_unlinked as $v) {
        unlink($v);
    }
    return realpath($tar_filename);
}

/*
    * 日志类
    * 当文件超过指定大小则备份日志文件并重新生成新的日志文件
*/
class Log
{
    private static $filename = __DIR__.'/logs/sys.log';
    private static $maxsize = 10240000; //最大文件大小10M

    //写入日志
    protected function _log($msg, $level='INFO')
    {
        if (!is_dir(__DIR__.'/logs')) {
            mkdir(__DIR__.'/logs');
        }
        $filename = self::$filename;
        // 来源文件与行号，回溯2条
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit=2);
        $file = $trace[1]['file'];
        $line = $trace[1]['line'];
        // 格式化消息
        if (is_array($msg)) {
            $msg = json_encode($msg);
        }
        $content = sprintf("[ %s ] %s %s:%s %s\n", $level, date('Y-m-d H:i:s', time()), str_replace(__DIR__.'/', '', $file), $line, $msg);

        //如果日志文件超过了指定大小则备份日志文件
        if (file_exists($filename) && (abs(filesize($filename)) > self::$maxsize)) {
            $newfilename = dirname($filename).'/'.time().'-'.basename($filename);
            rename($filename, $newfilename);
        }

        //往日志文件内容后面追加日志内容
        file_put_contents($filename, $content, FILE_APPEND);
    }

    public static function debug($msg)
    {
        $log = new self();
        $log->_log($msg, 'DEBUG');
    }

    public static function info($msg)
    {
        $log = new self();
        $log->_log($msg, 'INFO');
    }

    public static function error($msg)
    {
        $log = new self();
        $log->_log($msg, 'ERROR');
    }
}

/*
    //使用curl扩展发起http请求，支持https，可以发起get、post请求
    @param $url  string: 请求地址
    @param $data array:  如果存在，则为post请求
    @param $timeout int: 超时秒数
    @param $parse_json boolean: 是否将响应进行json解码
    @return array
*/
function request(string $url, array $data=null, int $timeout=10, bool $parse_json=true)
{
    $useragent = 'Tdi-PHP/v1';
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_HEADER, 1); //设置返回响应头
    curl_setopt($curl, CURLOPT_USERAGENT, $useragent);
    curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    if (!empty($data)) {
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    }
    $output = curl_exec($curl);
    if (curl_getinfo($curl, CURLINFO_HTTP_CODE) === 200) {
        list($header, $body) = explode("\r\n\r\n", $output, 2);
    }
    curl_close($curl);
    return $parse_json ? json_decode($body, true) : $body;
}

/*
    //批量下载图片
    @param pics array: 格式要求是二维数组，嵌套的数组格式要求有imgUrl和imgName
*/
function batchDownloadImages(array $pics, string $download_dir=null, int $diskLimit=80)
{
    $download_dir = $download_dir ? $download_dir : __DIR__;
    if (!is_array($pics)) {
        return;
    }
    if (diskRate($download_dir) > $diskLimit) {
        return;
    }
    // 计数器
    $count = 0;
    // 开启curl多线程
    $mh = curl_multi_init();
    // 循环添加curl句柄
    $ch = array();
    foreach ($pics as $k => $v) {
        if (isset($v['imgName']) && isset($v['imgUrl']) && !is_file($v['imgName'])) {
            $ch[$k] = curl_init();                           // 初始化curl句柄
            curl_setopt($ch[$k], CURLOPT_URL, $v['imgUrl']); // 设置要下载的图片
            curl_setopt($ch[$k], CURLOPT_RETURNTRANSFER, 1); // 设置获取图片内容而不直接在浏览器输出
            curl_setopt($ch[$k], CURLOPT_HEADER, 0);         // 设置只获取图片内容，不返回header头信息
            curl_multi_add_handle($mh, $ch[$k]);             // 添加curl多线程句柄
        }
    }
    // 开启curl多线程下载图片
    do {
        $status = curl_multi_exec($mh, $active);
        $result = curl_multi_info_read($mh);
        if ($result !== false) {
            $content = curl_multi_getcontent($result['handle']);// 获取图片内容
            $picName = $pics[$count]['imgName'];                // 获取图片名称
            Log::debug('Downloading '.$picName);
            $fp = @fopen($download_dir ? $download_dir.'/'.$picName : $picName, 'w');
            fwrite($fp, $content);                              // 把获取到的图片内容写入到新图片
            fclose($fp);                                        // 关闭文件句柄
            ++$count;                                           // 计数器+1
        }
        //暂停10毫秒，避免cpu使用率过高
        usleep(10000);
    } while ($status == CURLM_CALL_MULTI_PERFORM || $active);
    // 关闭curl句柄
    foreach ($ch as $handle) {
        curl_multi_remove_handle($mh, $handle);
    }
    // 关闭curl多线程句柄
    curl_multi_close($mh);
}
