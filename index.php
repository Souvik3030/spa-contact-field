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

function writeSection($title)
{
    writeLog("");
    writeLog("========== $title ==========");
}

function sanitizeForLog($value)
{
    if (!is_array($value)) {
        return $value;
    }

    $sensitive_keys = ['access_token', 'refresh_token', 'application_token', 'auth', 'password', 'token'];

    foreach ($value as $key => $item) {
        $lower_key = strtolower((string) $key);
        if (in_array($lower_key, $sensitive_keys, true) || strpos($lower_key, 'token') !== false) {
            $value[$key] = '*** hidden ***';
            continue;
        }

        $value[$key] = sanitizeForLog($item);
    }

    return $value;
}

function writeValue($label, $value)
{
    $value = sanitizeForLog($value);
    writeLog($label . ': ' . (is_array($value) ? print_r($value, true) : $value));
}

function getRequestHeadersForLog()
{
    if (function_exists('getallheaders')) {
        return getallheaders();
    }

    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $header = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$header] = $value;
        }
    }

    return $headers;
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
    writeSection("BITRIX API CALL: $method");
    writeValue("Request params", $params);

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
        writeLog("Bitrix response: HTTP_REQUEST_FAILED");
        return ['error' => 'HTTP_REQUEST_FAILED'];
    }

    $decoded_result = json_decode($result, true);
    writeValue("Bitrix raw response", $result);
    writeValue("Bitrix decoded response", $decoded_result);

    return $decoded_result;
}

// 4. Processing Incoming Webhook
writeSection("WEBHOOK HIT");
writeValue("Request time", date("Y-m-d H:i:s"));
writeValue("Request method", $_SERVER['REQUEST_METHOD'] ?? '');
writeValue("Remote IP", $_SERVER['REMOTE_ADDR'] ?? '');
writeValue("User agent", $_SERVER['HTTP_USER_AGENT'] ?? '');

writeSection("INCOMING EVENT DATA");
writeValue("Event name", $_POST['event'] ?? $_REQUEST['event'] ?? 'NO_EVENT_NAME_RECEIVED');
writeValue("POST data", $_POST);
writeValue("GET data", $_GET);
writeValue("REQUEST data", $_REQUEST);
writeValue("Raw body", file_get_contents('php://input'));
writeValue("Headers", getRequestHeadersForLog());

// Resolve webhook source entity type
writeSection("RESOLVE EVENT TYPE");
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

writeValue("Raw TYPE", $raw_type);
writeValue("Raw ENTITY_TYPE_ID", $raw_entity_id);
writeValue("Resolved type", $resolved_type);
writeValue("Expected type", $expected_type);
writeValue("Item ID", $item_id);

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
writeSection("FETCH SPA ITEM");
$item_response = callBitrix('crm.item.get', [
    'entityTypeId' => $spa_entity_type,
    'id' => $item_id,
], $rest_url);

$item = $item_response['result']['item'] ?? null;

if (!$item) {
    writeLog("ERROR: Could not fetch SPA item #$item_id - " . print_r($item_response, true));
    finishWebhook();
}

writeSection("SPA ITEM DATA");
writeValue("Item data", $item);

// 6. Extracted fields with flexible mappings
writeSection("EXTRACT CONTACT FIELDS");
$name  = trim($item['ufCrm8Name']  ?? $item['ufCrm26LandlordName']  ?? '');
$email = trim($item['ufCrm8Email'] ?? $item['ufCrm26LandlordEmail'] ?? '');
$phone = trim($item['ufCrm8Phone'] ?? $item['ufCrm26LandlordContact'] ?? '');

writeValue("Name", $name);
writeValue("Email", $email);
writeValue("Phone", $phone);

if ($name === '' || $email === '' || $phone === '') {
    writeLog("WARNING: SPA item #$item_id missing required fields (name/email/phone) - skipping contact creation.");
    finishWebhook();
}

writeLog("INFO: Required contact fields found.");

// 7. Structure Contact Fields
writeSection("BUILD CONTACT PAYLOAD");
$contact_fields = ['NAME' => $name];

if ($email !== '') {
    $contact_fields['EMAIL'] = [['VALUE' => $email, 'VALUE_TYPE' => 'WORK']];
}

if ($phone !== '') {
    $contact_fields['PHONE'] = [['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']];
}

writeValue("Contact payload", $contact_fields);

// 8. Create the New Contact
writeSection("CREATE CONTACT");
$contact_response = callBitrix('crm.contact.add', ['fields' => $contact_fields], $rest_url);
$new_contact_id = $contact_response['result'] ?? null;

writeValue("New contact ID", $new_contact_id);

if (!$new_contact_id) {
    writeLog("FAILED: Could not create contact for SPA item #$item_id - " . print_r($contact_response, true));
    finishWebhook();
}

writeLog("SUCCESS: Contact #$new_contact_id created.");

// 9. Prepare Associated Contact Array
writeSection("CHECK EXISTING CONTACT LINKS");
$existing_contact_ids = $item['contactIds'] ?? [];
if (!is_array($existing_contact_ids)) {
    $existing_contact_ids = [];
}
$existing_contact_ids = array_map('intval', $existing_contact_ids);

writeValue("Existing contact IDs", $existing_contact_ids);

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

writeValue("Updated contact IDs", $updated_contact_ids);

// 10. Link Contact back to SPA Item
writeSection("UPDATE SPA CONTACT LINKS");
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
