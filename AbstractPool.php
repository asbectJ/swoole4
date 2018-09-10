<?php
/**
 * 连接池封装.
 * @author tanjiajun
 * Date: 2018/9/1
 * Time: 13:36
 */

use Swoole\Coroutine\Channel;

abstract class AbstractPool
{
    private $min;//最少连接数
    private $max;//最大连接数
    private $count;//当前连接数
    private $connections;//连接池组
    protected $spareTime;//用于空闲连接回收判断
    //数据库配置
    protected $dbConfig = array(
        'host' => '10.0.2.2',
        'port' => 3306,
        'user' => 'root',
        'password' => 'root',
        'database' => 'test',
        'charset' => 'utf8',
        'timeout' => 2,
    );

    private $inited = false;

    protected abstract function createDb();

    public function __construct()
    {
        $this->min = 10;
        $this->max = 100;
        $this->spareTime = 10 * 3600;
        $this->connections = new Channel($this->max + 1);
    }

    protected function createObject()
    {
        $obj = null;
        $db = $this->createDb();
        if ($db) {
            $obj = [
                'last_used_time' => time(),
                'db' => $db,
            ];
        }
        return $obj;
    }

    /**
     * 初始换最小数量连接池
     * @return $this|null
     */
    public function init()
    {
        if ($this->inited) {
            return null;
        }
        for ($i = 0; $i < $this->min; $i++) {
            $obj = $this->createObject();
            $this->count++;
            $this->connections->push($obj);
        }
        return $this;
    }

    public function getConnection($timeOut = 3)
    {
        $obj = null;
        if ($this->connections->isEmpty()) {
            if ($this->count < $this->max) {//连接数没达到最大，新建连接入池
                $this->count++;
                $obj = $this->createObject();#1
            } else {
                $obj = $this->connections->pop($timeOut);#2
            }
        } else {
            $obj = $this->connections->pop($timeOut);#3
        }
        return $obj;
    }

    public function free($obj)
    {
        if ($obj) {
            $this->connections->push($obj);
        }
    }

    /**
     * 处理空闲连接
     */
    public function gcSpareObject()
    {
        //大约2分钟检测一次连接
        swoole_timer_tick(120000, function () {
            $list = [];
            /*echo "开始检测回收空闲链接" . $this->connections->length() . PHP_EOL;*/
            if ($this->connections->length() < intval($this->max * 0.5)) {
                echo "请求连接数还比较多，暂不回收空闲连接\n";
            }#1
            while (true) {
                if (!$this->connections->isEmpty()) {
                    $obj = $this->connections->pop(0.001);
                    $last_used_time = $obj['last_used_time'];
                    if ($this->count > $this->min && (time() - $last_used_time > $this->spareTime)) {//回收
                        $this->count--;
                    } else {
                        array_push($list, $obj);
                    }
                } else {
                    break;
                }
            }
            foreach ($list as $item) {
                $this->connections->push($item);
            }
            unset($list);
        });
    }
}

