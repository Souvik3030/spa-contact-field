<?php
/**
 * OUTBOUND webhook: CRM item add / dynamic item add (Scope: crm)
 *
 * Handler URL:
 *   https://yourserver.com/spa-contact-field/index.php
 *
 * This script fires when a new SPA item (entityTypeId = 1054) is created.
 * It reads the custom fields UF_CRM_34_NAME / UF_CRM_34_EMAIL / UF_CRM_34_PHONE, ADDRESS
 * creates a Bitrix Contact from that data, then links the contact back to the
 * SPA item via the contactIds field.
 */

$rest_url = "https://comma.bitrix24.ae/rest/8/pzou62xi93z8dnu3/";
$spa_entity_type = 1056;
$log_file = __DIR__ . '/webhook_log.txt';

function writeLog($message, $file)
{
    $timestamp = date("Y-m-d H:i:s");
    $entry = "[$timestamp] " . (is_array($message) ? print_r($message, true) : $message) . PHP_EOL;
    file_put_contents($file, $entry, FILE_APPEND);
}

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
    $result = file_get_contents($queryUrl, false, $context);

    return json_decode($result, true);
}

writeLog("=== INCOMING WEBHOOK POST DATA ===", $log_file);
writeLog($_POST, $log_file);

// Some Bitrix events send data[TYPE] = DYNAMIC_1054, while
// ONCRMDYNAMICITEMADD sends data[FIELDS][ENTITY_TYPE_ID] = 1054.
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

writeLog("INFO: Resolved webhook item type as '$resolved_type'.", $log_file);

if ($resolved_type !== $expected_type) {
    writeLog("INFO: Skipping - type '$resolved_type' is not our SPA ($expected_type).", $log_file);
    exit;
}

if (!$item_id) {
    writeLog("ERROR: No Item ID in webhook payload. Exiting.", $log_file);
    exit;
}

writeLog("INFO: Processing new SPA item #$item_id (entityTypeId=$spa_entity_type).", $log_file);

$item_response = callBitrix('crm.item.get', [
    'entityTypeId' => $spa_entity_type,
    'id' => $item_id,
], $rest_url);

$item = $item_response['result']['item'] ?? null;

if (!$item) {
    writeLog("ERROR: Could not fetch SPA item #$item_id - " . print_r($item_response, true), $log_file);
    exit;
}

writeLog("--- SPA ITEM DATA ---", $log_file);
writeLog($item, $log_file);

// ufCrm26LandlordName
// ufCrm26LandlordEmail
// ufCrm26LandlordContact
// ufCrm26_1774431022842


$name = trim($item['ufCrm26LandlordName'] ?? '');  // fields
$email = trim($item['ufCrm26LandlordEmail'] ?? '');
$phone = trim($item['ufCrm26LandlordContact'] ?? '');
$address = trim($item['ufCrm26_1774431022842'][0] ?? '');

if ($name === '' && $email === '' && $phone === '' && $address === '') {
    writeLog("WARNING: SPA item #$item_id has no name/email/phone/address - skipping contact creation.", $log_file);
    exit;
}

writeLog("INFO: Extracted -> Name: '$name' | Email: '$email' | Phone: '$phone' | Address: '$address' ", $log_file);

$contact_fields = ['NAME' => $name];

if ($email !== '') {
    $contact_fields['EMAIL'] = [['VALUE' => $email, 'VALUE_TYPE' => 'WORK']];
}

if ($phone !== '') {
    $contact_fields['PHONE'] = [['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']];
}

if ($address !== '') {
    $contact_fields['ADDRESS'] = $address;
}

$contact_response = callBitrix('crm.contact.add', ['fields' => $contact_fields], $rest_url);
$new_contact_id = $contact_response['result'] ?? null;

if (!$new_contact_id) {
    writeLog("FAILED: Could not create contact for SPA item #$item_id - " . print_r($contact_response, true), $log_file);
    exit;
}

writeLog("SUCCESS: Contact #$new_contact_id created.", $log_file);

$existing_contact_ids = $item['contactIds'] ?? [];
if (!is_array($existing_contact_ids)) {
    $existing_contact_ids = [];
}

$updated_contact_ids = array_values(array_unique(array_merge(
    array_map('intval', $existing_contact_ids),
    [(int) $new_contact_id]
)));

$update_response = callBitrix('crm.item.update', [
    'entityTypeId' => $spa_entity_type,
    'id' => $item_id,
    'fields' => [
        'contactIds' => $updated_contact_ids,
    ],
], $rest_url);

if (!empty($update_response['result']['item'])) {
    writeLog("SUCCESS: SPA item #$item_id contactIds updated to " . json_encode($updated_contact_ids) . ".", $log_file);
} else {
    writeLog("WARNING: Update response for SPA item #$item_id - " . print_r($update_response, true), $log_file);
}
