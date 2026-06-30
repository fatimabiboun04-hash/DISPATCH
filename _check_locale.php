<?php
try {
    echo \Carbon\Carbon::now()->locale('fr')->dayName . "\n";
    echo "OK\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
