<?php
/**
 * Hybrid Drip Processor
 * Processes hybrid email/WhatsApp drip sequences
 * Run via cron every 15 minutes:
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/handlers/HybridDripHandler.php';

$stats = HybridDripHandler::processHybridQueue();

echo "Hybrid Drip Processor Results:\n";
echo "Processed: {$stats['processed']}\n";
echo "Sent: {$stats['sent']} (Email: {$stats['email_sent']}, WhatsApp: {$stats['whatsapp_sent']})\n";
echo "Failed: {$stats['failed']}\n";