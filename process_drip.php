<?php
/**
 * Drip Processor - Cron Job
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
    // Get stats before processing
    $initialStats = DripSequenceHandler::getStats();
    
    echo "Queue Status:\n";
    echo "- Total sequences: {$initialStats['total']}\n";
    echo "- Active sequences: {$initialStats['active']}\n"; 
    echo "- Completed sequences: {$initialStats['completed']}\n";
    echo "- Pending messages: {$initialStats['pending_messages']}\n\n";
    
    // Process the queue
    $result = DripSequenceHandler::processDripQueue();
    
    echo "Processing Results:\n";
    echo "- Sequences processed: {$result['processed']}\n";
    echo "- Messages sent: {$result['sent']}\n";
    echo "- Messages failed: {$result['failed']}\n";
    
    // Get stats after processing
    $finalStats = DripSequenceHandler::getStats();
    
    echo "\nFinal Queue Status:\n";
    echo "- Active sequences: {$finalStats['active']}\n";
    echo "- Pending messages: {$finalStats['pending_messages']}\n";
    
    if ($result['sent'] > 0) {
        echo "\n Successfully sent {$result['sent']} WhatsApp message(s)\n";
    }
    
    if ($result['failed'] > 0) {
        echo "\n {$result['failed']} message(s) failed (will retry next run)\n";
    }
    
    if ($result['processed'] === 0 && $initialStats['active'] > 0) {
        echo "\n No messages due for sending yet (waiting for scheduled time)\n";
    } else if ($initialStats['active'] === 0) {
        echo "\n No active drip sequences found\n";
    }
    
} catch (Exception $e) {
    echo " ERROR: " . $e->getMessage() . "\n";
    error_log("Drip processor fatal error: " . $e->getMessage());
    exit(1);
}

echo "\nFinished at: " . date('Y-m-d H:i:s') . "\n";
echo "================================\n";

exit(0);