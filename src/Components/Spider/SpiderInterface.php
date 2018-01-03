<?php

namespace Crawler\Components\Spider;

use Crawler\Components\Parser\ParserInterface;

/**
 * 爬虫接口
 *
 * @author LL
 */
interface SpiderInterface
{
    /**
     * 获取抓取内容
     *
     * @param  mixed $link
     * @return ParserInterface
     */
    public function getContent($link): ParserInterface;

    /**
     * 清洗数据
     *
     * @param  ParserInterface $parser
     * @return mixed
     */
    public function filterData(ParserInterface $parser);

    /**
     * 准备下一次的抓取
     *
     * @return mixed
     */
    public function next();

    /**
     * 抓取结束
     *
     * @return mixed
     */
    public function end();
}