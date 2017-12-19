<?php

namespace Crawler\Components\MultiProcess;

use Exception;

/**
 * 主进程
 * 负责启动，停止，回收子进程，守护进程化
 * 主要起到一个管理器的作用，并不做其它的逻辑处理
 *
 * @author LL
 */
class MainProcess extends BaseProcess
{
    /**
     * 保存子进程的进程id
     *
     * @var array
     */
    private $subProcessPidMap = [];

    /**
     * 子进程的最大数量
     *
     * @var int
     */
    private $subProcessMaxCount;

    /**
     * 子进程的执行事件
     *
     * @var \Closure
     */
    private $subProcessHandle;

    /**
     * 进程休眠时间
     *
     * @var float
     */
    private $sleepTime = 1;

    /**
     * 进程是否处于停止状态
     *
     * @var int
     */
    private $stopStatus = 0;

    /**
     * 进程是否处于重启状态
     *
     * @var int
     */
    private $restartStatus = 0;

    /**
     * 已经完成重启的子进程的数量
     *
     * @var int
     */
    private $restartSubProcessCount = 0;

    /**
     * 标准输出重定向位置
     *
     * @var string
     */
    private $stdoutFilePath = '/dev/null';

    /**
     * 主进程构造函数
     * 设置信号监听
     *
     * @param \Closure $handle          子进程的执行事件
     * @param int      $subProcessCount 子进程的启动数量
     */
    public function __construct(\Closure $handle, int $subProcessCount = 0)
    {
        $this->subProcessHandle = $handle;
        $this->subProcessMaxCount = $subProcessCount;

        $this->init();
    }

    /**
     * 进程的初始化
     *
     * @return void
     */
    private function init()
    {
        //注册信号监听
        $this->registerSignalHandler();
        //设置守护进程
        $this->daemonize();
        //重定向标准输出
        $this->resetStdout();
        //保存pid
        $this->savePid();
        //启动子进程
        $this->startSubProcess();
        //等待子进程
        $this->wait();
    }

    /**
     * 使进程变为守护进程
     *
     * @return void
     *
     * @throws Exception
     */
    private function daemonize()
    {
        //只有在cli模式下可以变为守护进程
        if (php_sapi_name() != 'cli') {
            return;
        }

        //文件掩码清0
        umask(0);

        //第一次生成子进程
        $pid = pcntl_fork();

        if ($pid == -1) {
            throw new Exception('fork fail in daemonize exe');
        } elseif ($pid > 0) {
            //父进程退出
            exit(0);
        }

        //设置为新会话组长，脱离终端
        if (posix_setsid() < 0) {
            throw new Exception('setsid fail in daemonize exe');
        }

        //第二次生成子进程
        $pid = pcntl_fork();

        if ($pid == -1) {
            throw new Exception('second time fork fail in daemonize exe');
        } elseif ($pid > 0) {
            //父进程退出
            exit(0);
        }
    }

    /**
     * 启动子进程
     *
     * @return void
     */
    private function startSubProcess()
    {
        for ($i=0; $i<$this->subProcessMaxCount; $i++) {
            $this->makeSubProcess();
        }
    }

    /**
     * 生成子进程
     *
     * @return void
     *
     * @throws Exception
     */
    private function makeSubProcess()
    {
        $pid = pcntl_fork();

        if ($pid == 0) {
            //子进程
            $subProcess = new SubProcess();
            $subProcess->handler($this->subProcessHandle);
        } elseif ($pid > 0) {
            //父进程
            $this->subProcessPidMap[$pid] = $pid;
        } else {
            //错误
            throw new Exception('fork fail in make sub process');
        }
    }

    /**
     * 父进程监听子进程
     * 等待子进程退出
     *
     * @return void
     */
    private function wait()
    {
        while (true) {
            $status = 0;

            pcntl_signal_dispatch();

            //等待子进程退出
            $pid = pcntl_wait($status, WNOHANG);

            if ($pid > 0) {
                //如果进程处于停止状态
                if ($this->stopStatus != 0) {
                    $this->stopHandler($pid);
                }
                //如果进程处于重启状态
                if ($this->restartStatus != 0) {
                    $this->restartHandler($pid);
                }
                //如果进程是异常退出
                if ($this->stopStatus == 0 && $this->restartStatus == 0) {
                    $this->exceptionProcessHandler($pid, $status);
                }
            }

            sleep($this->sleepTime);
        }
    }

    /**
     * 信号处理
     *
     * @param  int $signal
     * @return void
     */
    protected function signalHandler($signal)
    {
        switch ($signal) {
            //退出
            case SIGINT :
            case SIGTERM :
                $this->stop();
                break;
            //重启
            case SIGUSR1:
                $this->restart();
                break;
        }
    }

    /**
     * 进程退出
     * 等待并回收全部子进程后退出
     *
     * @return void
     */
    private function stop()
    {
        $this->stopStatus = 1;

        $this->killSubProcess();
    }

    /**
     * 重启子进程
     *
     * @return void
     */
    private function restart()
    {
        $this->restartStatus = 1;

        $this->killSubProcess();
    }

    /**
     * 结束子进程
     *
     * @return void
     */
    private function killSubProcess()
    {
        foreach ($this->subProcessPidMap as $pid) {
            //向所有子进程发送退出信号
            posix_kill($pid, SIGTERM);
        }
    }

    /**
     * 进程停止的操作
     *
     * @param  int $pid
     * @return void
     */
    private function stopHandler($pid)
    {
        if (isset($this->subProcessPidMap[$pid])) {
            unset($this->subProcessPidMap[$pid]);
        }

        if (count($this->subProcessPidMap) == 0) {
            exit(0);
        }
    }

    /**
     * 重启进程的操作
     *
     * @param  int $pid
     * @return void
     */
    private function restartHandler($pid)
    {
        if (isset($this->subProcessPidMap[$pid])) {
            try {
                $this->makeSubProcess();
                unset($this->subProcessPidMap[$pid]);
                $this->restartSubProcessCount++;
            } catch (Exception $e) {
                //TODO:记录日志
            }
        }

        //如果重启子进程的数量已经达到子进程最大的数量，则停止重启状态
        if ($this->restartSubProcessCount == $this->subProcessMaxCount) {
            $this->restartStatus = 0;
            $this->restartSubProcessCount = 0;
        }
    }

    /**
     * 异常退出的子进程
     *
     * @param  int $pid    子进程的id
     * @param  int $status 子进程的退出状态
     * @return void
     */
    private function exceptionProcessHandler($pid, $status)
    {
        if (isset($this->subProcessPidMap[$pid])) {
            //重启一个子进程
            unset($this->subProcessPidMap[$pid]);
            $this->makeSubProcess();

            //TODO:记录子进程的退出状态
        }
    }

    /**
     * 重定向标准输出和标准错误输出
     *
     * @return void
     */
    private function resetStdout()
    {
        global $STDOUT, $STDERR;

        //关闭标准输出和标准错误输出
        @fclose(STDOUT);
        @fclose(STDERR);

        $STDOUT = fopen('/data/dev/test.log', 'a');
        $STDERR = fopen('/data/dev/test.log', 'a');
    }
}