<?php
/**
 * An example implementation of NginxBalancer that does balance nginx upstream config that looks like this:
 *
 * upstream backend {
 *      server backend1:123 weight=345;
 *      server backend2:123 weight=456;
 *      # new servers go here
 *      server backend3:123 weight=567;
 * }
 */

require_once(__DIR__ . '/BaseBalancer.php');

abstract class NginxBalancer extends BaseBalancer
{
    const SOURCE_FILE = '/path/to/source/file.conf';
    const TARGET_FILE = '/path/to/target/file.conf';
    const SERVER_WEIGHT_REGEX = '/^\\s*server\\s+([^:]+)\\:(\\d+)\\s+weight=(\\d+)\\;\\s*$/s';

    /** @var array */
    protected $lines = false;

    /**
     * Get server weights
     *
     * @return array (hostname => weight)
     */
    protected function getCurrentServerWeights()
    {
        $tpl_file = static::SOURCE_FILE;

        $this->lines = file($tpl_file, FILE_IGNORE_NEW_LINES);
        if ($this->lines === false) {
            $this->exitWithFatal("Could not read $tpl_file");
        }

        $result = [];

        foreach ($this->lines as &$ln) {
            if (!preg_match(self::SERVER_WEIGHT_REGEX, $ln, $matches)) {
                continue;
            }

            list(, $hostname, $port, $weight) = $matches;
            $result[$hostname] = $weight;
        }
        unset($ln);

        return $result;
    }

    protected function updateServerWeights($new_weights, $to_delete)
    {
        $output_file = static::TARGET_FILE;

        if ($this->lines === false) {
            $this->exitWithFatal("Internal error: no lines in updateServerWeights");
        }

        $port = 0;

        foreach ($this->lines as &$ln) {
            if (!preg_match(self::SERVER_WEIGHT_REGEX, $ln, $matches)) {
                continue;
            }

            list(, $hostname, $port, $weight) = $matches;

            if (isset($to_delete[$hostname])) {
                $this->info("Deleting $hostname from config");
                $ln = false;
                continue;
            }

            if (!isset($new_weights[$hostname])) {
                $this->info("No new weight for $hostname");
                continue;
            }

            $ln = "server $hostname:$port weight=" . $new_weights[$hostname] . ";";
            unset($new_weights[$hostname]);
        }
        unset($ln);

        $contents = implode("\n", array_filter($this->lines, function ($a) { return $a !== false; })) . "\n";

        if (count($new_weights) > 0) {
            $new_server_lines = [];
            foreach ($new_weights as $hostname => $weight) {
                if (!$port) {
                    $this->exitWithFatal("Could not guess port for $hostname");
                }

                $new_server_lines[] = "server $hostname:$port weight=$weight;\n";
            }

            $contents = str_replace("# new servers go here\n", "# new servers go here\n" . implode("", $new_server_lines), $contents);
        }

        if (strlen($contents) !== file_put_contents($output_file, $contents)) {
            $this->exitWithFatal("Could not write contents into $output_file");
        }
    }

    public function needCheckHTTP()
    {
        return true;
    }
}
