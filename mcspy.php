#!/usr/bin/env php
<?php
/**
 * McSpy - Memcache Data Analyzer (PHP Port)
 * Inspects memcache usage by Drupal and handles McRouter.
 */

ini_set('memory_limit', '1G');

class McSpy {
    // Colors
    const C_RESET = "\033[0m";
    const C_RED = "\033[0;31m";
    const C_GREEN = "\033[0;32m";
    const C_YELLOW = "\033[0;33m";
    const C_GRAY = "\033[1;30m";
    const C_BOLD = "\033[1m";

    private $args = [];
    private $flags = [
        'verbose' => false,
        'quiet' => false,
        'refresh' => true,
        'raw' => false,
        'slab' => 0,
        'yes' => false,
        'watch' => false,
        'mcrouter' => false,
    ];

    private $dumpFolder = '/tmp/mcspy-dump';
    private $memcacheServers = [];
    private $command = '';
    private $extraArg = '';
    private $keyGrep = '.';
    private $uri = '';

    public function __construct($argv) {
        $this->parseArguments($argv);
        $this->init();
    }

    /**
     * Output a message to STDERR or STDOUT with optional color.
     * @param string $msg
     * @param string $color
     * @param bool $stderr
     */
    private function output($msg, $color = self::C_GRAY, $stderr = true) {
        if ($this->flags['quiet']) return;
        $stream = $stderr ? STDERR : STDOUT;
        fwrite($stream, $color . $msg . self::C_RESET . PHP_EOL);
    }

    /**
     * Print a formatted section header to STDOUT.
     * @param string $msg
     */
    private function header($msg) {
        if ($this->flags['quiet']) return;
        echo PHP_EOL;
        echo self::C_GRAY . "._____________________________________________________________________________" . self::C_RESET . PHP_EOL;
        echo "|" . self::C_GREEN . "  $msg" . self::C_RESET . PHP_EOL;
    }

    /**
     * Parse CLI arguments and set command, flags, and options.
     * @param array $argv
     */
    private function parseArguments($argv) {
        // Shift off script name
        array_shift($argv);

        $residual = [];
        foreach ($argv as $arg) {
            if (strpos($arg, '--') === 0) {
                $parts = explode('=', substr($arg, 2), 2);
                $key = $parts[0];
                $val = isset($parts[1]) ? $parts[1] : true;

                switch ($key) {
                    case 'help': $this->showHelp(); exit;
                    case 'verbose': $this->flags['verbose'] = true; break;
                    case 'quiet': $this->flags['quiet'] = true; break;
                    case 'raw': $this->flags['raw'] = true; break;
                    case 'yes': $this->flags['yes'] = true; break;
                    case 'watch': $this->flags['watch'] = true; break;
                    case 'refresh': $this->flags['refresh'] = true; break;
                    case 'no-refresh':
                    case 'cached': $this->flags['refresh'] = false; break;
                    case 'dump-folder': $this->dumpFolder = $val; break;
                    case 'slab': $this->flags['slab'] = $val; break;
                    case 'key-grep':
                    case 'key-search': $this->keyGrep = $val; break;
                    case 'servers':
                    case 'server': $this->memcacheServers = explode(',', $val); break;
                    case 'uri': $this->uri = $val; break;
                }
            } elseif (strpos($arg, '-') === 0) {
                // Short flags
                $chars = str_split(substr($arg, 1));
                foreach ($chars as $char) {
                    if ($char === 'v') $this->flags['verbose'] = true;
                    if ($char === 'h') { $this->showHelp(); exit; }
                    if ($char === 'q') $this->flags['quiet'] = true;
                    if ($char === 'y') $this->flags['yes'] = true;
                    if ($char === 'w') $this->flags['watch'] = true;
                }
            } else {
                $residual[] = $arg;
            }
        }

        if (empty($residual)) {
            $this->output("No command given.", self::C_YELLOW);
            $this->showHelp();
            exit(1);
        }

        $cmdMap = [
            'deep-search' => 'deepSearch', 'deep' => 'deepSearch',
            'config:server' => 'configServer', 'server-config' => 'configServer',
            'config:drupal' => 'configDrupal', 'drupal-config' => 'configDrupal',
            'report:drupal' => 'reportDrupal', 'report' => 'reportDrupal',
            'report:stats' => 'reportStats', 'stats' => 'reportStats',
            'report:realtime' => 'reportRealtime', 'realtime' => 'reportRealtime', 'rt' => 'reportRealtime',
            'dump:files' => 'dumpFiles', 'backup' => 'dumpFiles',
            'dump:keys' => 'dumpKeys', 'keys' => 'dumpKeys',
            'item:get' => 'itemGet', 'item' => 'itemGet', 'get' => 'itemGet',
            'item:delete' => 'itemDelete', 'delete' => 'itemDelete', 'del' => 'itemDelete'
        ];

        $cmdInput = array_shift($residual);
        if (isset($cmdMap[$cmdInput])) {
            $this->command = $cmdMap[$cmdInput];
        } else {
            $this->output("Unknown command: $cmdInput", self::C_RED);
            exit(1);
        }

        if (!empty($residual)) {
            $this->extraArg = $residual[0];
        }
    }

    /**
     * Initialize memcache server list, handle mcrouter, and ensure dump folder exists.
     */
    private function init() {
        // Detect Servers if not provided
        if (empty($this->memcacheServers)) {
            $ahSiteName = getenv("AH_SITE_NAME");
            $configFile = "/var/www/site-php/{$ahSiteName}/config.json";

            if (file_exists($configFile)) {
                $data = json_decode(file_get_contents($configFile), true);
                if (isset($data['memcached_servers'])) {
                    $this->memcacheServers = array_values($data['memcached_servers']);
                    $this->output("Detected memcache servers from config.json");
                }
            }

            if (empty($this->memcacheServers)) {
                $this->memcacheServers = ['localhost:11211'];
            }
        }

        // Handle McRouter
        // Simple check: if list is localhost and we can query mcrouter route
        if (count($this->memcacheServers) === 1 && strpos($this->memcacheServers[0], 'localhost') !== false) {
             $routerConfig = $this->sendRawCommand('localhost', 11211, 'get __mcrouter__.preprocessed_config');
             if ($routerConfig) {
                 $this->flags['mcrouter'] = true;
                 // Parse IPs out of config (Rough regex based on bash script logic)
                 preg_match_all('/([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+):11211/', $routerConfig, $matches);
                 if (!empty($matches[0])) {
                    $realServers = array_unique($matches[0]);
                    $this->output("Detected mcrouter. Real servers: " . implode(", ", $realServers));
                    $this->memcacheServers = $realServers;
                 }
             }
        }

        if (!is_dir($this->dumpFolder)) {
            if (!mkdir($this->dumpFolder, 0777, true)) {
                $this->output("ERROR: Can't create {$this->dumpFolder}", self::C_RED);
                exit(1);
            }
        }
    }

    /**
     * Run the selected command method.
     */
    public function run() {
        $method = $this->command;
        $this->$method();
    }

    // --- Networking Utility ---

    /**
     * Send a raw command to a memcache server and return the response.
     * @param string $host
     * @param int $port
     * @param string $cmd
     * @return string|null
     */
    private function sendRawCommand($host, $port, $cmd) {
        $fp = @fsockopen($host, $port, $errno, $errstr, 2);
        if (!$fp) return null;

        fwrite($fp, "$cmd\r\n");

        $response = "";
        while (!feof($fp)) {
            $line = fgets($fp, 1024);
            if (trim($line) == "END" || trim($line) == "DELETED" || trim($line) == "NOT_FOUND" || trim($line) == "OK") {
                break;
            }
            $response .= $line;
        }
        fclose($fp);
        return $response;
    }

    // --- Core Data Gathering ---

    /**
     * Get the path to the raw dump file.
     * @return string
     */
    private function getDumpFile() {
        return $this->dumpFolder . "/memcache-key-dump-raw.txt";
    }

    /**
     * Get the path to the parsed dump file.
     * @return string
     */
    private function getParsedFile() {
        return $this->dumpFolder . "/memcache-key-dump-parsed.txt";
    }

    /**
     * Refresh the memcache key dump if needed, and parse it.
     */
    private function refreshDumpIfNeeded() {
        $dumpFile = $this->getDumpFile();

        // If not refreshing and file exists, return
        if (!$this->flags['refresh'] && file_exists($dumpFile) && filesize($dumpFile) > 0) {
            $this->output("Using existing key dump $dumpFile");
            return;
        }

        $this->output("Dumping list of all memcache keys to file $dumpFile");
        $handle = fopen($dumpFile, 'w');

        foreach ($this->memcacheServers as $server) {
            $parts = explode(':', $server);
            $host = $parts[0];
            $port = $parts[1] ?? 11211;

            $this->output("  ... server $host port $port");

            $slabs = [];
            if ($this->flags['slab'] > 0) {
                $slabs[] = $this->flags['slab'];
            } else {
                $slabs = range(1, 42);
            }

            foreach ($slabs as $i) {
                $fp = @fsockopen($host, $port, $errno, $errstr, 1);
                if (!$fp) continue;

                fwrite($fp, "stats cachedump $i 0\r\n");
                while (!feof($fp)) {
                    $line = fgets($fp);
                    if (trim($line) === 'END') break;
                    // Format: ITEM key [size b; timestamp s]
                    if (strpos($line, 'ITEM') === 0) {
                        fwrite($handle, "SLAB=$i " . $line);
                    }
                }
                fclose($fp);
            }
        }
        fclose($handle);
        $this->output("  Done.");

        $this->parseDumpFile();
    }

    /**
     * Parse the raw dump file into a structured format for analysis.
     */
    private function parseDumpFile() {
        $rawFile = $this->getDumpFile();
        $parsedFile = $this->getParsedFile();
        $grep = $this->keyGrep;

        $in = fopen($rawFile, 'r');
        $out = fopen($parsedFile, 'w');

        // Regex to parse: SLAB=10 ITEM keyname [123 b; 123123 s]
        while ($line = fgets($in)) {
            // Grep filter
            if ($grep !== '.' && strpos($line, $grep) === false) continue;

            if (preg_match('/^SLAB=(\d+) ITEM (\S+) /', $line, $matches)) {
                $slab = $matches[1];
                $fullKey = $matches[2];

                // Drupal Parsing Logic from Bash script
                // Check if it has urlencoded colon or standard
                $prefix = '';
                $bin = '';
                $item = '';

                $keyDecoded = urldecode($fullKey); // Optional helper, though Bash script did manual string checks

                // Logic replication:
                // Check for colon or %3A
                $posColon = strpos($fullKey, ':');
                $pos3A = strpos($fullKey, '%3A');

                if ($posColon === false && $pos3A !== false) {
                    $prefixEnd = $pos3A;
                    $prefix = substr($fullKey, 0, $prefixEnd);
                    $remainder = substr($fullKey, $prefixEnd + 3);
                    $binEnd = strpos($remainder, '%3A');
                    $bin = substr($remainder, 0, $binEnd);
                    $item = substr($remainder, $binEnd + 3);
                } else {
                    $posDash = strpos($fullKey, '-');
                    if ($posDash !== false) {
                        $prefix = substr($fullKey, 0, $posDash);
                        $remainder = substr($fullKey, $posDash + 1);
                        $posDash2 = strpos($remainder, '-');
                        $bin = substr($remainder, 0, $posDash2);
                        $item = substr($remainder, $posDash2 + 1);
                    }
                }

                if ($prefix && $bin) {
                    fwrite($out, "$slab\t$prefix\t$bin\t$item\n");
                }
            }
        }
        fclose($in);
        fclose($out);
        $this->output("Parsed file is: $parsedFile");
    }

    // --- Commands ---

    /**
     * Fetch and print a single memcache item from all servers.
     */
    private function itemGet() {
        if (!$this->extraArg) { $this->output("Missing key argument", self::C_RED); return; }

        $servers = $this->flags['mcrouter'] ? ['localhost:11211'] : $this->memcacheServers;

        foreach ($servers as $server) {
            $parts = explode(':', $server);
            $this->output("-- Item from server $server --");
            $mc = new Memcached();
            $mc->addServer($parts[0], $parts[1] ?? 11211);
            $val = $mc->get($this->extraArg);
            if ($val) {
                print_r($val);
                echo PHP_EOL;
            } else {
                echo "Not found on this server.\n";
            }
        }
    }

    /**
     * Delete a single memcache item from all servers.
     */
    private function itemDelete() {
        if (!$this->extraArg) { $this->output("Missing key argument", self::C_RED); return; }

        $servers = $this->flags['mcrouter'] ? ['localhost:11211'] : $this->memcacheServers;
        foreach ($servers as $server) {
            $parts = explode(':', $server);
            $this->output("-- Deleting from server $server --");
            $mc = new Memcached();
            $mc->addServer($parts[0], $parts[1] ?? 11211);
            $mc->delete($this->extraArg);
        }
    }

    /**
     * Search all memcache items for a string pattern.
     */
    private function deepSearch() {
        if (!$this->extraArg || strlen($this->extraArg) < 4) {
            $this->output("Search string must be > 3 chars", self::C_RED); return;
        }

        if (!$this->flags['yes']) {
            $this->output("NOTE: This operation traverses ALL memcache items. Continue? (Enter/Ctrl-C)", self::C_YELLOW);
            fgets(STDIN);
        }

        $searchStr = $this->extraArg;
        $regex = "/" . preg_quote($searchStr, '/') . "/si";

        foreach ($this->memcacheServers as $server) {
            $parts = explode(':', $server);
            $host = $parts[0]; $port = $parts[1] ?? 11211;

            $mc = new Memcached();
            $mc->addServer($host, $port);

            $slabs = ($this->flags['slab'] > 0) ? [$this->flags['slab']] : range(1, 42);

            foreach ($slabs as $slab) {
                // Get keys via raw command
                $resp = $this->sendRawCommand($host, $port, "stats cachedump $slab 0");
                if (!$resp) continue;

                $lines = explode("\n", $resp);
                foreach ($lines as $line) {
                    if (preg_match('/^ITEM (\S+)/', $line, $match)) {
                        $key = $match[1];
                        // Apply key grep if set
                        if ($this->keyGrep !== '.' && strpos($key, $this->keyGrep) === false) continue;

                        $val = $mc->get($key);
                        if ($val !== false) {
                            $text = print_r($val, true);
                            if (preg_match($regex, $text)) {
                                echo self::C_GREEN . "MATCH: SERVER=$server SLAB=$slab $line" . self::C_RESET . PHP_EOL;
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Dump all memcache items to individual files in the dump folder.
     */
    private function dumpFiles() {
        $this->refreshDumpIfNeeded();
        $this->output("Dumping content to files...");

        $destDir = $this->dumpFolder . "/content-dump";
        if (!is_dir($destDir)) mkdir($destDir, 0777, true);

        // Load Parsed keys to get valid keys, or Raw keys
        $keys = [];
        $handle = fopen($this->getDumpFile(), "r");
        while ($line = fgets($handle)) {
             if (preg_match('/^SLAB=\d+ ITEM (\S+)/', $line, $m)) {
                 if ($this->keyGrep === '.' || strpos($m[1], $this->keyGrep) !== false) {
                    $keys[] = $m[1];
                 }
             }
        }
        fclose($handle);

        $mc = new Memcached();
        foreach ($this->memcacheServers as $s) {
            $p = explode(':', $s);
            $mc->addServer($p[0], $p[1]??11211);
        }

        $count = 0;
        foreach ($keys as $key) {
            $val = $mc->get($key);
            if ($val !== false) {
                file_put_contents("$destDir/" . urlencode($key), print_r($val, true));
                $count++;
                if ($count % 100 == 0) echo ".";
            }
        }
        echo PHP_EOL . "$count items written to $destDir" . PHP_EOL;
    }

    /**
     * List all memcache keys, optionally parsed, in a table format.
     */
    private function dumpKeys() {
        $this->refreshDumpIfNeeded();
        $file = $this->flags['raw'] ? $this->getDumpFile() : $this->getParsedFile();

        $header = $this->flags['raw']
            ? ["Slab", "--", "Item", "Size/Age"]
            : ["Slab", "Prefix", "Bin", "Id"];

        $rows = [];
        $handle = fopen($file, "r");
        while ($line = fgets($handle)) {
            $line = trim($line);
            if (!$line) continue;
            // Filter
            if ($this->keyGrep !== '.' && strpos($line, $this->keyGrep) === false) continue;

            if ($this->flags['raw']) {
                 // SLAB=10 ITEM x [y]
                 $parts = explode(' ', $line);
                 $rows[] = [$parts[0], 'ITEM', $parts[2] ?? '', implode(' ', array_slice($parts, 3))];
            } else {
                 $rows[] = preg_split('/\t/', $line);
            }
        }
        fclose($handle);

        $this->printTable($rows, $header);
    }

    /**
     * Analyze and report on memcache usage by Drupal, including crosstabs and patterns.
     */
    private function reportDrupal() {
        $this->refreshDumpIfNeeded();
        $parsedFile = $this->getParsedFile();

        // Read file into memory arrays for processing
        $data = [];
        $handle = fopen($parsedFile, "r");
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $cols = explode("\t", trim($line));
                if (count($cols) == 4) {
                    $data[] = [
                        'slab' => $cols[0],
                        'prefix' => $cols[1],
                        'bin' => $cols[2],
                        'item' => $cols[3]
                    ];
                }
            }
            fclose($handle);
        }

        $this->header("Count by memcache_key_prefix");
        $this->printFrequency($data, 'prefix');

        $this->header("Count by Bin");
        $this->printFrequency($data, 'bin');

        $this->showCrosstab($data, 'prefix', 'bin', "Prefix", "Bin");
        $this->showCrosstab($data, 'prefix', 'slab', "Prefix", "Slab");

        // Analyze prefixes
        $counts = array_count_values(array_column($data, 'prefix'));
        arsort($counts);

        foreach (array_keys($counts) as $prefix) {
            if ($counts[$prefix] < 5) continue; // Skip insignificant ones

            $this->header("Analysing prefix = $prefix");

            $subset = array_filter($data, function($row) use ($prefix) {
                return $row['prefix'] === $prefix;
            });

            $this->showCrosstab($subset, 'bin', 'slab', "Cache_Bins", "Slab");

            echo "Top patterns observed:\n";
            $patterns = [];
            foreach ($subset as $row) {
                $key = urldecode($row['item']);
                // Sanitize patterns
                $key = preg_replace('/\.html_[a-zA-Z0-9_-]{6,100}/', '.html_{hash}', $key);
                $key = preg_replace('/\.html\.twig_[a-zA-Z0-9_-]{6,100}/', '.html.twig_{hash}', $key);
                $key = preg_replace('/[0-9a-f]{6,100}/', '{hex-hash}', $key);
                $key = preg_replace('/:\d+/', ':{num}', $key);
                $patterns[] = $row['bin'] . " => " . $key;
            }
            $pCounts = array_count_values($patterns);
            arsort($pCounts);
            $pData = [];
            foreach (array_slice($pCounts, 0, 20) as $p => $c) {
                $pData[] = [$c, $p];
            }
            $this->printTable($pData, ["Count", "Pattern"]);
        }

        $this->cleanup();
    }

    /**
     * Show memcache server configuration for all servers.
     */
    private function configServer() {
        $this->header("Memcache server configuration");
        foreach ($this->memcacheServers as $server) {
            $p = explode(':', $server);
            echo "-- Server {$p[0]} port " . ($p[1]??11211) . PHP_EOL;
            echo $this->sendRawCommand($p[0], $p[1]??11211, "stats settings");
            echo PHP_EOL;
        }
    }

    /**
     * Show Drupal Memcached PECL library runtime configuration (simplified).
     */
    private function configDrupal() {
        $this->header("Drupal Memcached PECL library runtime configuration");
        // We defer to the original bash script's PHP logic for this,
        // as we can't easily bootstrap Drupal from within a class unless we are in the root.
        // We will output a small script to run in a separate process.

        $script = <<<'EOD'
        $options = [
          "OPT_COMPRESSION" => Memcached::OPT_COMPRESSION,
          "OPT_BINARY_PROTOCOL" => Memcached::OPT_BINARY_PROTOCOL,
          "OPT_TCP_NODELAY" => Memcached::OPT_TCP_NODELAY,
          "OPT_DISTRIBUTION" => Memcached::OPT_DISTRIBUTION,
          "OPT_LIBKETAMA_HASH" => Memcached::OPT_LIBKETAMA_HASH,
        ];
        if (!class_exists("Memcached")) die("No Memcached PECL\n");
        $m = new Memcached("default");
        foreach ($options as $k => $v) {
            echo "$k: " . var_export($m->getOption($v), true) . "\n";
        }
EOD;
        // In a real scenario, we might want to `drush eval` the logic.
        // For this port, we run a simplified check.
        eval($script);
    }

    /**
     * Show runtime and slab statistics for all memcache servers.
     */
    private function reportStats() {
        $this->header("Runtime Statistics");
        foreach ($this->memcacheServers as $server) {
            $p = explode(':', $server);
            echo "-- Server $server" . PHP_EOL;
            echo $this->sendRawCommand($p[0], $p[1]??11211, "stats") . PHP_EOL;
        }

        $this->header("Slab Statistics");
        // In the bash script, it parses `stats slabs` into a temp file then runs crosstab.
        // We will fetch and parse in memory.
        foreach ($this->memcacheServers as $server) {
            $p = explode(':', $server);
            $raw = $this->sendRawCommand($p[0], $p[1]??11211, "stats slabs");
            $lines = explode("\n", $raw);
            $data = [];
            foreach ($lines as $line) {
                if (preg_match('/STAT (\d+):(\w+)\s+(\S+)/', $line, $m)) {
                    $slab = $m[1];
                    $key = $m[2];
                    $val = $m[3];
                    // Filter keys like the bash script
                    if (in_array($key, ['chunk_size','chunks_per_page','cmd_set','delete_hits','get_hits','used_chunks','total_chunks'])) {
                        $data[] = ['slab' => $slab, 'key' => $key, 'val' => $val];
                    }
                }
            }
            // Crosstab: Rows=Key, Cols=Slab
            $this->showCrosstab($data, 'key', 'slab', 'Metric', 'Slab', 'val');
        }
    }

    /**
     * Show real-time memcache statistics using memcached-tool-ng.
     */
    private function reportRealtime() {
        $script = "/tmp/memcached-tool-ng";
        if (!file_exists($script)) {
            $url = "https://raw.githubusercontent.com/davidfoliveira/memcached-tool-ng/master/memcached-tool-ng";
            file_put_contents($script, file_get_contents($url));
            chmod($script, 0755);
        }

        $server = $this->memcacheServers[0];
        $cmd = "$script $server";

        if ($this->flags['watch']) {
            $this->output("Hit Ctrl-C to stop");
            passthru("watch -n1 -d --color '$cmd'");
        } else {
            passthru($cmd);
        }
    }

    /**
     * Clean up temporary files in the dump folder.
     */
    private function cleanup() {
        $this->output("Cleaning up temporary files");
        array_map('unlink', glob("$this->dumpFolder/*.*"));
    }

    // --- Helpers ---

    /**
     * Print a frequency table for a given column in the data.
     * @param array $data
     * @param string $col
     */
    private function printFrequency($data, $col) {
        $counts = array_count_values(array_column($data, $col));
        arsort($counts);
        $table = [];
        foreach (array_slice($counts, 0, 10) as $k => $v) {
            $table[] = [$v, $k];
        }
        $this->printTable($table, ["Count", ucfirst($col)]);
    }

    /**
     * Show a crosstab (pivot table) of the data by two fields.
     * @param array $data
     * @param string $colField
     * @param string $rowField
     * @param string $headerCol
     * @param string $headerRow
     * @param string|null $valField
     */
    private function showCrosstab($data, $colField, $rowField, $headerCol, $headerRow, $valField = null) {
        $this->header("Crosstab: $headerCol / $headerRow");

        $matrix = [];
        $cols = [];
        $rows = [];

        // Aggregate
        foreach ($data as $d) {
            $c = $d[$colField];
            $r = $d[$rowField];
            $val = $valField ? $d[$valField] : 1;

            if (!isset($matrix[$r])) $matrix[$r] = [];
            if (!isset($matrix[$r][$c])) $matrix[$r][$c] = 0;

            $matrix[$r][$c] += $val;
            $cols[$c] = true;
            $rows[$r] = true;
        }

        ksort($cols);
        ksort($rows);
        $colKeys = array_keys($cols);

        // Build Header
        $tableHeader = array_merge([$headerRow, "TOTAL"], $colKeys);
        $tableRows = [];

        // Build Rows
        $grandTotal = 0;
        $colTotals = array_fill_keys($colKeys, 0);

        foreach (array_keys($rows) as $rKey) {
            $rowTotal = 0;
            $rowCells = [];

            foreach ($colKeys as $cKey) {
                $val = isset($matrix[$rKey][$cKey]) ? $matrix[$rKey][$cKey] : 0;
                $rowCells[] = $val > 0 ? $val : '-';
                $rowTotal += $val;
                $colTotals[$cKey] += $val;
            }
            $grandTotal += $rowTotal;

            $tableRows[] = array_merge([$rKey, $rowTotal], $rowCells);
        }

        // Totals Row
        $totalsRow = ["TOTAL", $grandTotal];
        foreach ($colKeys as $cKey) {
            $totalsRow[] = $colTotals[$cKey];
        }

        // Prepend Totals Row
        array_unshift($tableRows, $totalsRow);

        $this->printTable($tableRows, $tableHeader);
    }

    /**
     * Print a table to STDOUT with optional headers.
     * @param array $rows
     * @param array $headers
     */
    private function printTable($rows, $headers = []) {
        if (empty($rows)) return;

        // Calculate widths
        $widths = [];
        foreach ($headers as $i => $h) $widths[$i] = mb_strlen($h);

        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $cell) {
                $len = mb_strlen((string)$cell);
                if (!isset($widths[$i]) || $len > $widths[$i]) {
                    $widths[$i] = $len;
                }
            }
        }

        // Separator
        $sep = "+";
        foreach ($widths as $w) $sep .= str_repeat("-", $w + 2) . "+";

        // Print Header
        if (!empty($headers)) {
            echo $sep . PHP_EOL;
            echo "|";
            foreach ($headers as $i => $h) {
                echo " " . str_pad($h, $widths[$i]) . " |";
            }
            echo PHP_EOL;
        }

        echo $sep . PHP_EOL;

        // Print Rows
        foreach ($rows as $row) {
            echo "|";
            $i = 0;
            foreach ($row as $cell) {
                echo " " . str_pad((string)$cell, $widths[$i]) . " |";
                $i++;
            }
            echo PHP_EOL;
        }
        echo $sep . PHP_EOL;
    }

    /**
     * Print the help/usage message for the CLI tool.
     */
    private function showHelp() {
        echo <<<EOT
Usage: php mcspy.php command [arguments]

Commands:
 report:drupal              Analyze usage (alias: report)
 report:stats               Slab/Item stats (alias: stats)
 report:realtime            Realtime monitor (alias: rt)
 dump:keys                  List keys (alias: keys)
 dump:files                 Save items to disk
 item:get {key}             Get item
 item:delete {key}          Delete item
 deep-search {string}       Scan contents for string
 config:server              Show server config

Arguments:
 --servers=host:port        Specify servers
 --dump-folder=path         Path to temp files
 --key-grep=pattern         Filter keys
 --slab=N                   Only specific slab
 --no-refresh               Use cached dump
 --watch                    Realtime watch
EOT;
        echo PHP_EOL;
    }
}

// Run
if (php_sapi_name() === 'cli') {
    $app = new McSpy($argv);
    $app->run();
}