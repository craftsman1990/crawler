<?php

namespace Crawler\Components\MultiProcess;

use Crawler\ComponentProvider;

/**
 * MultiProcess组件提供者
 *
 * @author LL
 */
class MultiProcessComponentProvider extends ComponentProvider
{
    public function register(): void
    {
        $this->container->bind('MainProcess', function($container, $params){
            return new MainProcess($params['handle'], $params['subProcessCount']);
        });

        //子进程组件非单例模式
        $this->container->bind('SubProcess', function(){
            return new SubProcess();
        }, true);
    }
}