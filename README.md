# balancer
Load balancer that was presented at HighLoad++ 2015 Conference in Moscow.

The load balancer is intended to fine-tune weights for upstream servers that you already have so that you get even balancing.
In order to use it, you must implement all abstract methods and call run() method.
If application exits with non-zero code then it means that something went wrong and you should see log for details.

You must implement the following methods:

**getCurrentServerWeights:**

Parse your config file (see NginxBalancer class as an example) and return all current weights that you have in config.

**updateServerWeights($new_weights, $to_delete):**

Update weights for existing hosts in a config file and add new hosts into config as well (this information is provided by $new_weights variable).
Also delete hosts that are present in $to_delete section.

**needCheckHTTP:**

Whether or not you should check the HTTP heartbeat for hosts that you already have in you config file. If you return false then only new hosts will be checked with a heartbeat.

**outputIsOk($hostname, $output):**

Function to check that HTTP output of a heartbeat request that was sent to $hostname is a correct one.
If you always return false here then no new hosts can be added.

**getAliveHosts:**

Get hosts that are alive using any central database you have (it is different from getCurrentServerWeights in a way that you actually provide the list of hosts from zabbix or something similar rather that hosts that are present in config file).

**getCpuIdleStats($hostname):**

Return information about the host CPU usage, number of cores and also provide information on how long ago you received the information. The latter is necessary so that you do not try to make weights tuning based on stale data.

**canDisableHosts($host_weights, $hosts_to_disable, &$reason = ''):**

Whether or not you can disable the provided number of hosts from balancing (useful only when needCheckHTTP returns true). It is suggested that you check peak CPU usage of your cluster without the provided hosts. An example implementation is provided in BaseBalancer class in comments.

# Testing
You can see test for Base Balancer that can be seen at BaseBalancerTest.php. You run it as "phpunit BaseBalancerTest.php".
The test provides minimal implementation for an actual balancer and checks that basic functionality works using default settings.
