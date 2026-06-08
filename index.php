<?php
/**
 * OUTBOUND webhook: CRM item add / dynamic item add (Scope: crm)
 *
 * Handler URL:
 * https://yourserver.com/spa-contact-field/index.php
 *
 * This script fires when a new SPA item (entityTypeId = 1054) is created.
 * It reads the custom fields, creates a Bitrix Contact from that data, 
 * then links the contact back to the SPA item via the contactIds field.
 */

// 1. Configuration
$rest_url = "https://test.vortexwebre.com/rest/1/gng7u58v2pl8wpcf/";
$spa_entity_type = 1054;
$web_logs = [];
$log_file = __DIR__ . '/webhook_log.txt'; // Path to save your logs on the server

// 2. Logging Functions
function writeLog($message)
{
    global $web_logs;
    $timestamp = date("Y-m-d H:i:s");
    $web_logs[] = "[$timestamp] " . (is_array($message) ? print_r($message, true) : $message);
}

function finishWebhook()
{
    global $web_logs, $log_file;

    $log_content = "=== WEBHOOK EXECUTION START ===" . PHP_EOL;
    $log_content .= implode(PHP_EOL, $web_logs) . PHP_EOL;
    $log_content .= "=== WEBHOOK EXECUTION END ===" . PHP_EOL . PHP_EOL;
    
    // Save to file so you can check logs later
    @file_put_contents($log_file, $log_content, FILE_APPEND);

    // Also output to screen in case of manual browser testing
    header('Content-Type: text/plain; charset=utf-8');
    echo $log_content;
    exit;
}

// 3. API Communication Function
function callBitrix($method, $params, $url)
{
    $queryUrl = $url . $method . ".json";
    $queryData = http_build_query($params);
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-type: application/x-www-form-urlencoded',
            'content' => $queryData,
        ],
    ];

    $context = stream_context_create($options);
    $result = @file_get_contents($queryUrl, false, $context);

    if ($result === false) {
        return ['error' => 'HTTP_REQUEST_FAILED'];
    }

    return json_decode($result, true);
}

// 4. Processing Incoming Webhook
writeLog("=== INCOMING WEBHOOK POST DATA ===");
writeLog($_POST);

// Resolve webhook source entity type
$raw_type = $_POST['data']['TYPE'] ?? '';
$raw_entity_id = $_POST['data']['FIELDS']['ENTITY_TYPE_ID'] ?? null;
$entity_type = strtoupper((string) $raw_type);
$resolved_type = '';

if ($entity_type !== '') {
    $resolved_type = $entity_type;
} elseif ($raw_entity_id !== null && $raw_entity_id !== '') {
    $resolved_type = 'DYNAMIC_' . (int) $raw_entity_id;
}

$item_id = $_POST['data']['FIELDS']['ID'] ?? null;
$expected_type = 'DYNAMIC_' . $spa_entity_type;

writeLog("INFO: Resolved webhook item type as '$resolved_type'.");

if ($resolved_type !== $expected_type) {
    writeLog("INFO: Skipping - type '$resolved_type' is not our SPA ($expected_type).");
    finishWebhook();
}

if (!$item_id) {
    writeLog("ERROR: No Item ID in webhook payload. Exiting.");
    finishWebhook();
}

writeLog("INFO: Processing SPA item #$item_id (entityTypeId=$spa_entity_type).");

// 5. Fetch Original SPA Item Details
$item_response = callBitrix('crm.item.get', [
    'entityTypeId' => $spa_entity_type,
    'id' => $item_id,
], $rest_url);

$item = $item_response['result']['item'] ?? null;

if (!$item) {
    writeLog("ERROR: Could not fetch SPA item #$item_id - " . print_r($item_response, true));
    finishWebhook();
}

writeLog("--- SPA ITEM DATA ---");
writeLog($item);

// 6. Extracted fields with flexible mappings
$name  = trim($item['ufCrm8Name']  ?? $item['ufCrm26LandlordName']  ?? '');
$email = trim($item['ufCrm8Email'] ?? $item['ufCrm26LandlordEmail'] ?? '');
$phone = trim($item['ufCrm8Phone'] ?? $item['ufCrm26LandlordContact'] ?? '');

if ($name === '' || $email === '' || $phone === '') {
    writeLog("WARNING: SPA item #$item_id missing required fields (name/email/phone) - skipping contact creation.");
    finishWebhook();
}

writeLog("INFO: Extracted -> Name: '$name' | Email: '$email' | Phone: '$phone' ");

// 7. Structure Contact Fields
$contact_fields = ['NAME' => $name];

if ($email !== '') {
    $contact_fields['EMAIL'] = [['VALUE' => $email, 'VALUE_TYPE' => 'WORK']];
}

if ($phone !== '') {
    $contact_fields['PHONE'] = [['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']];
}

// 8. Create the New Contact
$contact_response = callBitrix('crm.contact.add', ['fields' => $contact_fields], $rest_url);
$new_contact_id = $contact_response['result'] ?? null;

if (!$new_contact_id) {
    writeLog("FAILED: Could not create contact for SPA item #$item_id - " . print_r($contact_response, true));
    finishWebhook();
}

writeLog("SUCCESS: Contact #$new_contact_id created.");

// 9. Prepare Associated Contact Array
$existing_contact_ids = $item['contactIds'] ?? [];
if (!is_array($existing_contact_ids)) {
    $existing_contact_ids = [];
}
$existing_contact_ids = array_map('intval', $existing_contact_ids);

// CRITICAL LOOP CONTROL: 
// If contact is already attached, exit to prevent an infinite webhook loop during updates.
if (in_array((int)$new_contact_id, $existing_contact_ids, true)) {
    writeLog("INFO: Contact #$new_contact_id is already linked to item #$item_id. Terminating to avoid recursion loops.");
    finishWebhook();
}

// Merge new contact cleanly
$updated_contact_ids = array_values(array_unique(array_merge(
    $existing_contact_ids,
    [(int) $new_contact_id]
)));

// 10. Link Contact back to SPA Item
$update_response = callBitrix('crm.item.update', [
    'entityTypeId' => $spa_entity_type,
    'id' => $item_id,
    'fields' => [
        'contactIds' => $updated_contact_ids,
    ],
], $rest_url);

if (!empty($update_response['result']['item'])) {
    writeLog("SUCCESS: SPA item #$item_id contactIds updated to " . json_encode($updated_contact_ids) . ".");
} else {
    writeLog("WARNING: Update response failed for SPA item #$item_id - " . print_r($update_response, true));
}

finishWebhook();