<?php

abstract class BaseBalancer
{
    const MAX_WEIGHT = 1000;

    const MIN_WEIGHT_PER_CORE = 2;
    const MAX_WEIGHT_PER_CORE = 6;
    const MIN_CORES = 1;
    const MAX_CORES = 500;

    const MAX_COEF = 1.05; // Maximum weight scale coef
    const MIN_COEF = 0.9; // Minimum weight scale coef
    const CHECK_URL = 'http://%s/is_alive.php';
    const UPDATE_INTERVAL_MAX_STATS_LAG = 300; // Max allowed stats lag for host (in seconds)
    const HTTP_TIMEOUT = 20; // Timeout for HTTP requests that do health check (in seconds)

    /** @var array (hostname1, ..., hostnameN) */
    protected $alive_hosts = [];

    /**
     * Read all servers from config file or database and return them as array(hostname => weight)
     */
    abstract protected function getCurrentServerWeights();

    /**
     * Update server weights, where $new_weights is array(hostname => new_weight)
     * There can be new hosts in $new_weights, which can either be ignored or added to config
     * Some hosts can be missing in $new_weights - it means that either host or stats for the host is not available
     * Hosts that are listed in $to_delete must be removed from config
     *
     * @param $new_weights  array(hostname => weight)
     * @param $to_delete    array(hostname => true)
     * @return
     */
    abstract protected function updateServerWeights($new_weights, $to_delete);

    /**
     * Whether or not we need to check web server liveness and try to delete servers if they are down.
     * If not sure, use false as default value.
     *
     * The CHECK_URL constant can be redefined to specify the exact URL to be used for health check
     *
     * @return bool
     */
    abstract protected function needCheckHTTP();

    /**
     * Is the $output of $hostname HTTP check a correct one or not. Required for automatic adding of new hosts
     *
     * @param $hostname
     * @param $output
     * @return bool
     */
    abstract protected function outputIsOk($hostname, $output);

    /**
     * @return string[]   List of hosts that are alive based on any criteria you need (e.g. select from zabbix)
     */
    abstract protected function getAliveHosts();

    /**
     * Get CPU idle statistics for a given host.
     *
     * You must return an array with the following fields:
     *
     *    'lag' => statistics lag in seconds (e.g. UNIX_TIMESTAMP() - UNIX_TIMESTAMP(MAX(ts))),
     *    'cpu_cores' => cores count (integer, must be greater than zero),
     *    'idle' => idle cpu% (float, ranging from 0 to 100),
     *
     * Example of SQL query:
     *

    SELECT
        UNIX_TIMESTAMP() - UNIX_TIMESTAMP(MAX(ts)) AS lag,
        AVG(cpu_idle) AS idle, -- cpu_idle is a percent of idle cpu, e.g. 30.5 (which means 30.5% idle, 69.5% used)
        cpu_cores
    FROM #table#
    WHERE
        ts >= NOW() - INTERVAL #interv# SECOND AND
        hostname = '#hostname#'

     *
     * @param string $hostname
     * @return array ('lag' => ..., 'cpu_cores' => ..., 'idle' => ...)
     */
    abstract protected function getCpuIdleStats($hostname);

    /**
     * Determine whether or not it is possible to safely disable specified hosts.
     * Method should check CPU load at peak and checks that the remaining hosts can handle load
     *
     * @param array    $host_weights       array(hostname => weight) - weights of all active servers
     * @param string[] $hosts_to_disable   List of hosts that you are going to disable
     * @param string   $reason             If false was returned, provides text reason why hosts cannot be disabled
     *
     * @return bool    true if yes, false if not
     */
    abstract protected function canDisableHosts($host_weights, $hosts_to_disable, &$reason = '');

    /*
     * An example implementation of canDisableHosts is provided below:

    const QUERY_FIND_LOAD_PEAK = "SELECT
            AVG(100 - cpu_idle) AS cpu_load,
            FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(created) / 600) * 600) AS interv
        FROM
            #table#
        WHERE
            hostname = '#hostname#' AND created >= NOW() - INTERVAL 1 DAY
        GROUP BY
            interv
        ORDER BY
            cpu_load DESC
        LIMIT 1";

    const QUERY_GET_AVG_CPU_LOAD = "SELECT AVG(100 - cpu_idle) AS cpu_load FROM #table#
        WHERE hostname = '#hostname#' AND created BETWEEN '#start#' AND '#finish#'";

    const MAX_FAIL_RATIO = 0.3;

    const MAX_AVG_LOAD = 0.80; // max load for cluster before it starts to degrade

    protected function getPeakLoads($hosts)
    {
        $result = [];
        if (!count($hosts)) return $result;

        // Find period of maximum load
        shuffle($hosts);
        $random_host = $hosts[0];

        $row = $this->getRow(
            self::QUERY_FIND_LOAD_PEAK,
            [
                'hostname' => \SQL::q($random_host),
                'table' => self::DB_NAME . "." . Common::getTableName(self::TABLE_SERVER_LOG, $random_host),
            ]
        );
        if ($row === false) return false;

        if ($row === [] || empty($row['interv'])) {
            trigger_error("Could not find load peak for random host ($random_host)");
            return false;
        }

        $start_unix = \cDateTime::dateTime2UnixTime($row['interv']);
        if (!$start_unix) {
            trigger_error("Invalid interval selected from table");
            return false;
        }

        $params = [
            'start' => \SQL::q(\cDateTime::unixTime2DateTime($start_unix)),
            'finish' => \SQL::q(\cDateTime::unixTime2DateTime($start_unix + 600)),
        ];

        $fail_cnt = 0;

        foreach ($hosts as $host) {
            $params['table'] = self::DB_NAME . "." . Common::getTableName(self::TABLE_SERVER_LOG, $host);
            $params['hostname'] = \SQL::q($host);

            $host_row = $this->getRow(self::QUERY_GET_AVG_CPU_LOAD, $params);
            if ($host_row === false) return false;

            $cpu_load = $host_row['cpu_load'];
            if (empty($cpu_load)) {
                $fail_cnt++;
            }

            $result[$host] = (float)$cpu_load;
        }

        $hosts_cnt = count($hosts);
        if ($fail_cnt / $hosts_cnt > self::MAX_FAIL_RATIO) {
            trigger_error("Too many failed hosts ($fail_cnt out of $hosts_cnt)");
            return false;
        }

        return $result;
    }

    protected function canDisableHosts($host_weights, $hosts_to_disable, &$reason = '')
    {
        if (count($hosts_to_disable) == 0) return true;

        $loads = $this->getPeakLoads(array_keys($host_weights));
        if ($loads === false) {
            $reason = "DB error, could not get peak loads";
            return false;
        }

        // The idea is the following:
        // 1. We suppose that the higher the host weight, the higher load it can handle (e.g. weight 1 = 1 kg)
        // 2. Each host can "lift" at most it's weight = capacity [kg]
        // 3. Current server load = cpu_load * capacity [kg]
        // 4. Total load [kg] / Total capacity [kg] = average cpu load
        // 5. We remove some capacity from cluster by removing nodes and thus increase average cpu load among others

        $total_capacity = $total_load = 0;
        foreach ($loads as $host => $load) {
            if (!isset($host_weights[$host])) {
                $reason = "Consistency error, no host weights for host '$host'";
                return false;
            }

            $total_load += $host_weights[$host] * $load / 100;
            if (!in_array($host, $hosts_to_disable)) {
                $total_capacity += $host_weights[$host];
            }
        }

        $avg_load = $total_load / $total_capacity;
        if ($avg_load > self::MAX_AVG_LOAD) {
            $reason = "Too high load expected (" . round($avg_load * 100, 1) . "%), maximum is " . round(self::MAX_AVG_LOAD * 100, 1) . "%";
            return false;
        }

        return true;
    }
     *
     */

    protected function sendRequest($servers, $timeout)
    {
        if (!extension_loaded('curl')) dl('curl.so');

        $headers = array('Host: 127.0.0.1');

        $curl_options = array(
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
        );

        $mh = curl_multi_init();
        $curl_array = array();
        foreach ($servers as $i => $url) {
            $curl_array[$i] = curl_init($url);
            curl_setopt_array($curl_array[$i], $curl_options);
            curl_multi_add_handle($mh, $curl_array[$i]);
        }
        $running = null;
        do {
            usleep(1000);
            curl_multi_exec($mh, $running);
        } while ($running > 0);

        $res = array();
        foreach ($servers as $i => $url) {
            $ch = $curl_array[$i];
            $res[$i] = curl_multi_getcontent($ch);
            if (!$res[$i] && curl_error($ch)) $res[$i] = 'cURL error: ' . curl_error($ch);
        }
        foreach ($servers as $i => $url) curl_multi_remove_handle($mh, $curl_array[$i]);
        curl_multi_close($mh);
        return $res;
    }

    public function run()
    {
        $this->alive_hosts = $alive_hosts = $this->getAliveHosts();

        $idles = $cores = $to_delete = [];

        foreach ($alive_hosts as $hostname) {
            if (!$row = $this->getCpuIdleStats($hostname)) {
                $this->exitWithFatal("Could not get cpu idle for $hostname");
            }

            if ($row['lag'] > static::UPDATE_INTERVAL_MAX_STATS_LAG) {
                $this->exitWithFatal("Not enough time has passed for $hostname stats");
                continue;
            }

            $cores[$hostname] = $row['cpu_cores'];
            $idles[$hostname] = $row['idle'];
        }

        if (!count($idles)) {
            $this->exitWithFatal("No hosts information, exiting");
        }

        $hostnames = $this->getCurrentServerWeights();

        $sum_cores = $sum_idle = 0;
        foreach ($hostnames as $hostname => $idle) {
            if (!isset($idles[$hostname])) continue;
            $sum_idle += $idles[$hostname] * $cores[$hostname];
            $sum_cores += $cores[$hostname];
        }

        if (!$sum_cores) {
            $this->error("No sum_cores - no alive hosts or no current hosts?");
            $sum_cores = 1;
            $sum_idle = 50;
        }

        $avg_idle = $sum_idle / $sum_cores;

        $new_weights = [];
        $total_parsed_cores = 0;
        $total_parsed_weight = 0;

        foreach ($hostnames as $hostname => $weight) {
            if (isset($idles[$hostname])) {
                $host_load = (100 - $idles[$hostname]);

                if ($host_load > 0) {
                    $coef = (100 - $avg_idle) / $host_load;
                    if ($coef > static::MAX_COEF) $coef = static::MAX_COEF;
                    else if ($coef < static::MIN_COEF) $coef = static::MIN_COEF;

                    $host_cores = $cores[$hostname];
                    if (!preg_match('/^\\d+$/s', $host_cores)) {
                        $this->error("Strange cores count for host '$hostname': '$host_cores'");
                        $to_delete[$hostname] = true;
                        continue;
                    }

                    if ($host_cores > static::MAX_CORES) {
                        $this->error("Host '$hostname' has more than " . static::MAX_CORES . " cores ($host_cores)");
                        $to_delete[$hostname] = true;
                        continue;
                    }

                    if ($host_cores < static::MIN_CORES) {
                        $this->error("Host '$hostname' has less than " . static::MIN_CORES . " cores ($host_cores)");
                        $to_delete[$hostname] = true;
                        continue;
                    }

                    if ($coef > 1) {
                        $coef_weight = ceil($weight * $coef);
                    } else {
                        $coef_weight = floor($weight * $coef);
                    }

                    $weight = min(
                        static::MAX_WEIGHT,
                        max(
                            $host_cores * static::MIN_WEIGHT_PER_CORE,
                            min($cores[$hostname] * static::MAX_WEIGHT_PER_CORE, $coef_weight)
                        )
                    );
                }
            } else {
                $this->error("Unknown hostname: $hostname");
                $to_delete[$hostname] = true;
                continue;
            }

            $new_weights[$hostname] = $weight;
            $total_parsed_cores += $cores[$hostname];
            $total_parsed_weight += $weight;
        }

        $new_servers = [];

        if ($this->needCheckHTTP()) {
            $urls = [];
            foreach ($new_weights as $srv => $_) $urls[$srv] = sprintf(static::CHECK_URL, $srv);
            $result = $this->sendRequest($urls, static::HTTP_TIMEOUT);
            foreach ($result as $hostname => $output) {
                if (!$this->outputIsOk($hostname, $output)) {
                    $to_delete[$hostname] = true;
                    unset($new_weights[$hostname]);
                }
            }
        }

        foreach ($cores as $hostname => $num_cores) {
            if (isset($new_weights[$hostname])) continue;
            $new_servers[] = $hostname;
        }

        if (count($new_servers)) {
            $this->info("New servers: " . implode(",", $new_servers));

            $avg_weight_per_core = $total_parsed_weight / $total_parsed_cores;

            if ($this->needCheckHTTP()) {
                $this->info("Determining whether or not web server is alive (avg weight per core: $avg_weight_per_core)...");
                $urls = [];
                foreach ($new_servers as $srv) $urls[$srv] = sprintf(static::CHECK_URL, $srv);
                $result = $this->sendRequest($urls, static::HTTP_TIMEOUT);
            } else {
                $result = [];
                foreach ($new_servers as $srv) $result[$srv] = 'OK';
            }

            foreach ($result as $hostname => $output) {
                if (!$this->outputIsOk($hostname, $output)) continue;

                $weight = round($cores[$hostname] * $avg_weight_per_core);

                $this->info("Adding $hostname with weight $weight");
                $new_weights[$hostname] = $weight;
            }
        }

        $reason = 'No reason';
        if (!$this->canDisableHosts($hostnames, array_keys($to_delete), $reason)) {
            $this->error("Tried to delete the following hosts: " . implode(",", array_keys($to_delete)));
            $this->exitWithFatal("Could not delete hosts: " . $reason);
        }

        $this->updateServerWeights($new_weights, $to_delete);

        return true;
    }

    protected function exitWithFatal($string)
    {
        fwrite(STDERR, "FATAL: " . $string . "\n");
        exit(1);
    }

    protected function error($string)
    {
        fwrite(STDERR, "ERROR: " . $string . "\n");
    }

    protected function info($string)
    {
        fwrite(STDOUT, "INFO: " . $string . "\n");
    }
}
