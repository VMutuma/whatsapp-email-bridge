<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/StorageService.php';
require_once __DIR__ . '/lib/BeemService.php';

echo "=== DEBUG DRIP PROCESSOR ===\n\n";

// Check if file exists
echo "Checking queue file: " . DRIP_QUEUE_FILE . "\n";
echo "File exists: " . (file_exists(DRIP_QUEUE_FILE) ? 'YES' : 'NO') . "\n";
echo "File readable: " . (is_readable(DRIP_QUEUE_FILE) ? 'YES' : 'NO') . "\n";
echo "File writable: " . (is_writable(DRIP_QUEUE_FILE) ? 'YES' : 'NO') . "\n\n";

// Load queue manually
$queue = StorageService::load(DRIP_QUEUE_FILE, []);
echo "Items in queue: " . count($queue) . "\n\n";

// Check each item
$currentTime = time();
echo "Current time: " . date('Y-m-d H:i:s', $currentTime) . " ($currentTime)\n\n";

foreach ($queue as $index => $item) {
    echo "Item #$index: {$item['email']}\n";
    echo "  Status: {$item['status']}\n";
    echo "  Next send: {$item['next_send_at']}\n";
    echo "  Next send time: " . date('Y-m-d H:i:s', $item['next_send_at'] ?? 0) . "\n";
    
    if ($item['status'] !== 'active') {
        echo "  → SKIPPED (status not active)\n";
    } elseif ($currentTime < $item['next_send_at']) {
        $wait = $item['next_send_at'] - $currentTime;
        echo "  → WAITING ($wait seconds until send)\n";
    } else {
        echo "  → READY TO SEND (overdue by " . ($currentTime - $item['next_send_at']) . " seconds)\n";
        
        // Check if it would process
        $step = $item['sequence'][$item['current_step']];
        echo "  → Would send template: {$step['template_id']} to {$item['phone']}\n";
    }
    echo "\n";
}

// Now actually try to process
echo "=== ATTEMPTING TO PROCESS ===\n";
require_once __DIR__ . '/lib/handlers/DripSequenceHandler.php';

$stats = DripSequenceHandler::processDripQueue();

echo "Results:\n";
echo "  Processed: {$stats['processed']}\n";
echo "  Sent: {$stats['sent']}\n";
echo "  Failed: {$stats['failed']}\n";