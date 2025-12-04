<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/StorageService.php';

echo "=== CLEAR OLD DRIP SEQUENCES ===\n\n";

$queue = StorageService::load(DRIP_QUEUE_FILE, []);
$before = count($queue);

echo "Total sequences in queue: $before\n";

// Show what's in the queue
foreach ($queue as $item) {
    $age = time() - $item['subscribed_at'];
    $ageHours = round($age / 3600, 1);
    echo "  - {$item['email']} (Status: {$item['status']}, Age: {$ageHours}h)\n";
}

echo "\nWhat would you like to do?\n";
echo "1. Clear ALL sequences (start fresh)\n";
echo "2. Clear only completed sequences\n";
echo "3. Clear sequences older than 1 hour\n";
echo "4. Cancel\n\n";

$choice = readline("Enter choice (1-4): ");

switch ($choice) {
    case '1':
        // Clear everything
        StorageService::save(DRIP_QUEUE_FILE, []);
        echo "\n✓ Cleared all sequences\n";
        break;
        
    case '2':
        // Keep only active
        $active = array_filter($queue, fn($item) => $item['status'] === 'active');
        StorageService::save(DRIP_QUEUE_FILE, array_values($active));
        echo "\n✓ Kept " . count($active) . " active sequences\n";
        break;
        
    case '3':
        // Keep only recent (< 1 hour old)
        $oneHourAgo = time() - 3600;
        $recent = array_filter($queue, fn($item) => $item['subscribed_at'] > $oneHourAgo);
        StorageService::save(DRIP_QUEUE_FILE, array_values($recent));
        echo "\n✓ Kept " . count($recent) . " recent sequences\n";
        break;
        
    default:
        echo "\nCancelled\n";
        exit;
}

$after = StorageService::load(DRIP_QUEUE_FILE, []);
echo "Remaining sequences: " . count($after) . "\n";