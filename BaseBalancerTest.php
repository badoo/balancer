<?php

require_once("BaseBalancer.php");

class BaseBalancer_TestClass extends BaseBalancer
{
    private $my_alive_hosts;
    private $cpu_idle_stats;
    private $current_server_weights;

    private $new_weights;
    private $to_delete;

    private $fatal_msgs = [];

    public function __construct($alive_hosts, $cpu_idle_stats, $current_server_weights)
    {
        $this->my_alive_hosts = $alive_hosts;
        $this->cpu_idle_stats = $cpu_idle_stats;
        $this->current_server_weights = $current_server_weights;
    }

    protected function getCurrentServerWeights() { return $this->current_server_weights; }
    protected function needCheckHTTP() { return false; }
    protected function outputIsOk($hostname, $output) { return true; }
    protected function getAliveHosts() { return $this->my_alive_hosts; }
    protected function getCpuIdleStats($hostname) { return $this->cpu_idle_stats[$hostname]; }
    protected function canDisableHosts($host_weights, $hosts_to_disable, &$reason = '') { return true; }

    protected function sendRequest($servers, $timeout) { return true; }

    protected function updateServerWeights($new_weights, $to_delete)
    {
        $this->new_weights = $new_weights;
        $this->to_delete = $to_delete;
    }

    public function getNewServerWeights() { return $this->new_weights; }
    public function getToDelete() { return $this->to_delete; }

    protected function exitWithFatal($msg) { $this->fatal_msgs[] = $msg; }
    public function getFatalMsgs() { return $this->fatal_msgs; }

    protected function info($msg) {}
    protected function error($msg) {}
}

class BaseBalancerTest extends PHPUnit_Framework_TestCase
{
    /** @var BaseBalancer */
    private $instance;

    public function testRun()
    {
        $this->instance = new BaseBalancer_TestClass(
            ['wwwbma1', 'wwwbma2', 'wwwbma3', 'wwwbma4', 'wwwbma5'],
            [
                'wwwbma1' => ['lag' => 700, 'cpu_cores' => 10, 'idle' => 30], // host disappeared, lag > 300
                'wwwbma2' => ['lag' => 0, 'cpu_cores' => 20, 'idle' => 30],
                'wwwbma3' => ['lag' => 0, 'cpu_cores' => 40, 'idle' => 20],
                'wwwbma4' => ['lag' => 0, 'cpu_cores' => 20, 'idle' => 30],
                'wwwbma5' => ['lag' => 0, 'cpu_cores' => 40, 'idle' => 100], // new host, completely idle
            ],
            [
                'wwwbma1' => 40,
                'wwwbma2' => 80,
                'wwwbma3' => 160,
                'wwwbma4' => 80,
            ]
        );

        $Rm = new ReflectionMethod($this->instance, 'run');
        $this->assertTrue($Rm->invoke($this->instance));

        $expected_new_weights = [
            'wwwbma2' => 86,
            'wwwbma3' => 150,
            'wwwbma4' => 86,
            'wwwbma5' => 160, // new weight consists of number of cores * avg weight per core in config
        ];

        $actual_new_weights = $this->instance->getNewServerWeights();

        foreach ($expected_new_weights as $host => $weight) {
            $this->assertEquals($weight, $actual_new_weights[$host], "Weights do not match for $host", 2);
            unset($actual_new_weights[$host]);
        }

        $this->assertCount(0, $actual_new_weights, "Extra hosts in update server weights");
        $this->assertEquals(["Not enough time has passed for wwwbma1 stats"], $this->instance->getFatalMsgs());
    }
}
