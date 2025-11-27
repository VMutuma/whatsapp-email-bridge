<?php
/**
 * Drip Processor - Cron Job
 * Run this file every 15 minutes via cron
 * 
 * Cron entry:
 * */15 * * * * /usr/bin/php /path/to/process_drip.php >> /path/to/drip.log 2>&1
 * 
 * High cohesion: Only processes drip queue
 * Loose coupling: Uses DripSequenceHandler
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/StorageService.php';
require_once __DIR__ . '/lib/BeemService.php';
require_once __DIR__ . '/lib/handlers/DripSequenceHandler.php';

if (php_sapi_name() !== 'cli' && !isset($_GET['manual_run'])) {
    die('This script must be run from command line or with ?manual_run=1 parameter');
}

echo "=== WhatsApp Drip Processor ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $result = DripSequenceHandler::processDripQueue();
    
    echo "Processing complete!\n";
    echo "- Subscribers processed: {$result['processed']}\n";
    echo "- Messages sent: {$result['sent']}\n";
    echo "- Messages failed: {$result['failed']}\n";
    
    if ($result['sent'] > 0) {
        echo "\n Successfully sent {$result['sent']} WhatsApp message(s)\n";
    }
    
    if ($result['failed'] > 0) {
        echo "\n {$result['failed']} message(s) failed (will retry next run)\n";
    }
    
    if ($result['processed'] === 0) {
        echo "\n No active drip sequences found\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    error_log("Drip processor fatal error: " . $e->getMessage());
    exit(1);
}

echo "\nFinished at: " . date('Y-m-d H:i:s') . "\n";
echo "================================\n";

exit(0);