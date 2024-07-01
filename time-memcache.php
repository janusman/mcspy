#!/usr/bin/env php
<?php

/**
 * Usage: php -f ./time-memcache.php [optional-memcache-hostname] [--slab=slab-number]
 *
 * Arguments:
 *   --slab=N              # Only test slab number N (usually 1-35). Default is test slabs 1-35.
 *   --num-items=N         # Limit to read/writing N memcache items.
 *                         # Default is "as many as can fit" on 1MB per slab.
 *   --key-prefix=[string] # Change prefix for item names. Default is "test_". Can be used
 *                         #   to force eviction by writing items with different names.
 *
 * Examples:
 *   php -f ./time-memcache.php                     # Will use localhost as the memcache endpoint
 *   php -f ./time-memcache.php 1.2.3.4             # Looks for memcache server at IP 1.2.3.4
 *   php -f ./time-memcache.php localhost --slab=5  # Runs tests only on memcache slab #5
 *
 */

define('PAGE_SIZE', 1024 * 1024);
define('ITEM_OVERHEAD', 80);

$slab_item_size = [
    1 => 96,
    2 => 120,
    3 => 152,
    4 => 192,
    5 => 240,
    6 => 304,
    7 => 384,
    8 => 480,
    9 => 600,
    10 => 752,
    11 => 944,
    12 => 1108, #1228
    13 => 1480,
    14 => 1856,
    15 => 2255, #2355
    16 => 2904,
    17 => 3632,
    18 => 4544,
    19 => 5680,
    20 => 7104,
    21 => 8800, #8908,
    22 => 11104,
    23 => 11426, #13926
    24 => 17352, #17352
    25 => 21608, #21708
    26 => 27120,
    27 => 33904,
    28 => 42384,
    29 => 52984,
    30 => 65252, #66252
    31 => 82792,
    32 => 103496,
    33 => 129376,
    34 => 161720,
    35 => 202152,
];

function show_help() {
    echo <<<HEREDOC

Usage: php -f ./time-memcache.php [optional-memcache-hostname] [--slab=slab-number]

 Arguments:
   --slab=N              # Only test slab number N (usually 1-35). Default is test slabs 1-35.
   --num-items=N         # Limit to read/writing N memcache items.
                         # Default is "as many as can fit" on 1MB per slab.
   --key-prefix=[string] # Change prefix for item names. Default is "test_". Can be used
                         #   to force eviction by writing items with different names.

 Examples:
   php -f ./time-memcache.php                     # Will use localhost as the memcache endpoint
   php -f ./time-memcache.php 1.2.3.4             # Looks for memcache server at IP 1.2.3.4
   php -f ./time-memcache.php localhost --slab=5  # Runs tests only on memcache slab #5

HEREDOC;
}

function get_key_prefix() {
    global $arg_key_prefix;
    return empty($arg_key_prefix) ? "test" : $arg_key_prefix;
}

function get_slab_item_size($slab) {
    global $slab_item_size;
    return $slab_item_size[$slab];
}
function get_slab_item_max($slab) {
    return intval(PAGE_SIZE / (get_slab_item_size($slab) + ITEM_OVERHEAD)) - 1;
}
function get_slab_item_value($slab) {
    return str_repeat("A", get_slab_item_size($slab) - ITEM_OVERHEAD);
}

function track_errors($memcache_op, $command) {
    static $errors = ['get' => 0, 'set' => 0];
    if ($memcache_op != "get" && $memcache_op != "set") {
        throw new Exception("track_errors(): Invalid memcache operation $memcache_op");
    }
    if ($command != "get" && $command != "reset" && $command != "track") {
        throw new Exception("track_errors(): Invalid command $command");
    }
    if ($command == "reset") {
        $errors[$memcache_op] = 0;
    }
    if ($command == "track") {
        $errors[$memcache_op]++;
    }
    #if ($command == "get") {
        return $errors[$memcache_op];
    #}
}

function do_get($key, $expected_value) {
    global $memcached;
    $value = $memcached->get($key);
    if ($value === false) {
        echo "  ERROR: Failed to get($key), the value should be " . strlen($expected_value) . " bytes\n";
        track_errors("get", "track");
    } else
    if ($value != $expected_value) {
        echo "  ERROR: Value does not match for key: $key with length " . strlen($expected_value) . " bytes\n";
    }
}

function do_set($key, $value) {
    global $memcached;
    $result = $memcached->set($key, $value);
    if ($result === false) {
        track_errors("set", "track");
    }
}

function do_slab($slab, $operation) {
    global $slab_item_size, $arg_num_items;
    $slab_elapsed = 0;
    $key_prefix = get_key_prefix();
    $item_size = get_slab_item_size($slab);
    echo "  $operation: SLAB=$slab, ITEM SIZE={$item_size} ...";

    $num_items = empty($arg_num_items) ? get_slab_item_max($slab) : $arg_num_items;
    $value = get_slab_item_value($slab);
    $setStartTime = microtime(true);
    track_errors($operation, "reset");
    for ($i=0; $i<$num_items; $i++) {
        $function = "do_{$operation}";
        $key = "{$key_prefix}_s{$slab}_n{$i}";
        $function($key, $value);
    }
    $error_count = track_errors($operation, "get");
    $setEndTime = microtime(true);
    $slab_elapsed = ($setEndTime - $setStartTime);
    echo "  $operation $num_items items";
    echo " (Error count: $error_count)";
    echo " (Avg time per $operation: " . round(1000 * $slab_elapsed/$num_items, 2) . " ms)\n";
    return [$slab_elapsed, $num_items];
}

function do_all_slabs($operation) {
    global $slab_item_size;
    $elapsed = 0;
    $total_items = 0;
    foreach ($slab_item_size as $slab => $item_size) {
        list($slab_elapsed, $this_slab_items) = do_slab($slab, $operation);
        $elapsed += $slab_elapsed;
        $total_items += $this_slab_items;
    }
    return [$elapsed, $total_items];
}


## MAIN ####################################

// Get arguments.
$arg_memcache_host = "localhost";
$arg_slab = false;
$arg_key_prefix = '';
$arg_num_items = false;

$arguments = $argv;
$dummy = array_shift($arguments);
while (sizeof($arguments)>0) {
    $arg = array_shift($arguments);
    // Help?
    if (preg_match('/^--help$/', $arg) || $arg == "help") {
        show_help();
        die();
    }
    // IP address
    if (preg_match('/^[1-9][0-9]*\.[0-9]+\.[0-9]+\.[0-9]+$/', $arg) || $arg == "localhost") {
        $arg_memcache_host = $arg;
        echo "Using $arg_memcache_host as the memcache hostname.\n";
        continue;
    }
    // --slab=N argument
    if (preg_match('/^--slab=[1-9][0-9]*$/', $arg)) {
        list( ,$arg_slab) = explode("=", $arg);
        echo "Using $arg_slab as the slab to check.\n";
        continue;
    }
    // --num-items=N argument
    if (preg_match('/^--(num|num[_-]items)=[1-9][0-9]*$/', $arg)) {
        list( ,$arg_num_items) = explode("=", $arg);
        echo "Using $arg_num_items as number of items to write per slab.\n";
        continue;
    }
    // --key-prefix="xyz" argument
    if (preg_match('/^--(prefix|key_prefix|key-prefix|item-prefix)=[a-zA-Z0-9]*$/', $arg)) {
        list( ,$arg_key_prefix) = explode("=", $arg);
        echo "Using '$arg_key_prefix' as the key prefix.\n";
        continue;
    }
    // What's left?
    die("Unknown argument: '{$arg}'");
}

// Connect to Memcached.
$memcached = new Memcached();
$memcached->setOption(Memcached::OPT_COMPRESSION, false);
$memcached->addServer($arg_memcache_host, 11211);

if ($memcached->getVersion() === false) {
    die("Could not connect to Memcached server. Please check your configuration.");
}

// Run tests.
foreach (['set', 'get'] as $op) {
    echo "Starting operation $op\n";
    if (!$arg_slab) {
        list($setDuration, $total_items) = do_all_slabs($op);
    } else {
        list($setDuration, $total_items) = do_slab($arg_slab,$op);
    }
    echo "           Operation: $op for " . $total_items ." items completed:\n";
    echo "  Total time for $op: " . round($setDuration, 4) . " seconds\n";
    echo "    Avg time per $op: " . round(1000 * $setDuration/$total_items, 2) . " ms\n";
    echo PHP_EOL;
}
