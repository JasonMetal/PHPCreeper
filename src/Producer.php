<?php
/**
 * @script   Producer.php
 * @brief    This file is part of PHPCreeper
 * @author   blogdaren<blogdaren@163.com>
 * @version  1.0.0
 * @modify   2019-09-04
 */

namespace PHPCreeper;

use PHPCreeper\Kernel\PHPCreeper;
use PHPCreeper\Kernel\Library\Helper\Benchmark;
use PHPCreeper\Kernel\Library\Helper\Tool;
use Configurator\Configurator;
use Logger\Logger;
use Workerman\Lib\Timer;
use Workerman\Worker;

class Producer extends PHPCreeper
{
    /**
     * producer timer id
     *
     * @var int
     */
    public $producerTimerId = 0;

    /**
     * timer interval
     *
     * @var float
     */
    public $interval = 0;

    /**
     * @brief    construct    
     *
     * @return   void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @brief   run worker instance
     *
     * @return  void
     */
    public function run()
    {
        $this->onWorkerStart  = array($this, 'onWorkerStart');
        $this->onWorkerStop   = array($this, 'onWorkerStop');
        $this->onWorkerReload = array($this, 'onWorkerReload');
        parent::run();
    }

    /**
     * @brief    onWorkerStart  
     *
     * @param    object  $worker
     *
     * @return   boolean | void
     */
    public function onWorkerStart($worker)
    {
        //global init 
        $this->initMiddleware()->initLogger();

        //trigger user callback
        $returning = $this->triggerUserCallback('onProducerStart', $this);
        if(false === $returning) return false;

        //trigger timer
        $this->installTimer();
    }

    /**
     * @brief    onWorkerStop   
     *
     * @return   void
     */
    public function onWorkerStop()
    {
        $this->removeTimer()->removeBucket();
    }

    /**
     * @brief    onWorkerReload     
     *
     * @param    object $worker
     *
     * @return   boolean | void
     */
    public function onWorkerReload($worker)
    {
        //trigger user callback
        $returning = $this->triggerUserCallback('onProducerReload', $this);
        if(false === $returning) return false;
    }

    /**
     * @brief   push initial task into queue
     *
     * @return  boolean
     */
    public function initTask()
    {
        $method     = Configurator::get('globalConfig/main/task/method');
        $context    = Configurator::get('globalConfig/main/task/context');
        $start_urls = Configurator::get('globalConfig/main/task/url');
        Configurator::remove('globalConfig/main/task/url');
        !is_array($start_urls) && $start_urls = [$start_urls];

        foreach($start_urls as $rule_name => $start_url) 
        {
            if(empty(Tool::checkUrl($start_url))) 
            {
                unset($start_urls[$rule_name]);
                continue;
            }

            !is_string($rule_name) && $rule_name = 'init' . $rule_name;
            $rule = self::getInitTaskRule($rule_name);

            $task_id = $this->newTaskMan()
                ->setUrl($start_url)
                ->setMethod($method)
                ->setContext($context)
                ->setRuleName($rule_name)
                ->setRule($rule)
                ->createTask();

            if(!empty($task_id))
            {
                Logger::info(Tool::replacePlaceHolder($this->langConfig['queue_push_task'], [
                    'task_url'  => $start_url,
                ]));
            }
        }

        if(empty($start_urls)) 
        {
            Logger::warn(Tool::replacePlaceHolder($this->langConfig['queue_start_url_invalid']));
            return false;
        }

        return true;
    }

    /**
     * @brief    get task rule set by user
     *
     * @param    string  $rule_name
     *
     * @return   array
     */
    static public function getInitTaskRule($rule_name)
    {
        if(empty($rule_name) || !is_string($rule_name)) return [];

        $rules = Configurator::get("globalConfig/main/task/rule");
        if(empty($rules)) return [];

        $rule = Configurator::get("globalConfig/main/task/rule/{$rule_name}");
        if(empty($rule)) return [];

        if(is_array($rule)) return $rule;

        if(is_string($rule) && array_key_exists($rule, $rules) && is_array($rules[$rule]))
        {
            $rule = $rules[$rule];
            return $rule;
        }

        return [];
    }

    /**
     * @brief    set time interval   
     *
     * @param    float  $interval
     *
     * @return   object
     */
    public function setInterval($interval = 1)
    {
        if(!$interval || Tool::bcCompareNumber($interval, '0.001', 3) < 0) 
        {
            $interval = 1;
        }

        $this->interval = $interval;

        return $this;
    }

    /**
     * @brief    get time interval   
     *
     * @return   float
     */
    public function getInterval()
    {
        $interval = $this->interval;

        if(Tool::bcCompareNumber($interval, '0.001', 3) < 0) 
        {
            $interval = $this->getAppWorkerConfig()['interval'] ?? 1;
        }

        if(!$interval || Tool::bcCompareNumber($interval, '0.001', 3) < 0) 
        {
            $interval = 1;
        }

        return $this->interval = $interval;
    }

    /**
     * @brief    install timer
     *
     * @return   object
     */
    public function installTimer()
    {
        $this->producerTimerId = Timer::add($this->getInterval(), [$this, 'initTask'], [], false);

        return $this;
    }

    /**
     * @brief    get timer id     
     *
     * @return   int
     */
    public function getTimerId()
    {
        return $this->producerTimerId;
    }

    /**
     * @brief    remove timer
     *
     * @param    int  $timer_id
     *
     * @return   void
     */
    public function removeTimer()
    {
        $this->getTimerId() > 0 && Timer::del($this->getTimerId());

        return $this;
    }

    /**
     * @brief    remove bucket   
     *
     * @return   object
     */
    public function removeBucket()
    {
        if(empty($this->dropDuplicateFilter) || !is_object($this->dropDuplicateFilter)) 
        {
            return $this;
        }

        //important: no sleep will lead to url repeated 
        usleep(100000);

        $this->dropDuplicateFilter->removeBucket();

        return $this;
    }

}


