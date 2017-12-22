<?php

namespace Crawler\Components\Downloader;

/**
 * 下载器的接口
 * 所有下载器都必须实现这个接口
 *
 * @author LL
 */
interface DownloaderInterface
{
    /**
     * 对一个连接发起请求，并获得连接的内容
     *
     * @param  string $link   请求连接
     * @return mixed 请求后获得的内容
     */
    public function download($link);
}