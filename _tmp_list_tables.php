<?php
$tables = DB::select('SHOW TABLES');
foreach ($tables as $t) {
    echo reset($t) . "\n";
}
