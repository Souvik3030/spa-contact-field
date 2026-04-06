<?php
/**
 * 1. CONFIGURATION
 * ─────────────────────────────────────────────────────────────────────────────
 * OUTBOUND webhook: ONCRM_ITEM_ADD  (Scope: crm)
 *
 * In Bitrix24 → Developer Resources → Outbound webhooks:
 *   - Event type : CRM Item Add  (onCrmItemAdd)
 *   - Handler URL: https://yourserver.com/spa-contact-field/index.php
 *
 * This script fires when a new SPA item (entityTypeId = 1054) is created.
 * It reads the custom fields UF_CRM_8_NAME / UF_CRM_8_EMAIL / UF_CRM_8_PHONE,
 * creates a Bitrix Contact from that data, then links the contact back to the
 * SPA item via the contactIds field.
 * ─────────────────────────────────────────────────────────────────────────────
 */
$rest_url          = "https://test.vortexwebre.com/rest/1/4xmt5rq9imvnzhv4/";
$spa_entity_type   = 1054;   // Your SPA entity type ID
$log_file          = __DIR__ . '/webhook_log.txt';

// ─────────────────────────────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────────────────────────────

function writeLog($message, $file)
{
    $timestamp = date("Y-m-d H:i:s");
    $entry     = "[$timestamp] " . (is_array($message) ? print_r($message, true) : $message) . PHP_EOL;
    file_put_contents($file, $entry, FILE_APPEND);
}

function callBitrix($method, $params, $url)
{
    $queryUrl  = $url . $method . ".json";
    $queryData = http_build_query($params);
    $options   = [
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-type: application/x-www-form-urlencoded',
            'content' => $queryData,
        ],
    ];
    $context = stream_context_create($options);
    $result  = file_get_contents($queryUrl, false, $context);
    return json_decode($result, true);
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. CAPTURE & VALIDATE INCOMING WEBHOOK
// ─────────────────────────────────────────────────────────────────────────────
writeLog("=== INCOMING WEBHOOK POST DATA ===", $log_file);
writeLog($_POST, $log_file);

// onCrmItemAdd sends:
//   data[TYPE]       → e.g. "DYNAMIC_1054" for SPA items
//   data[FIELDS][ID] → the new item's ID
$entity_type = strtoupper($_POST['data']['TYPE'] ?? '');
$item_id     = $_POST['data']['FIELDS']['ID'] ?? null;

$expected_type = 'DYNAMIC_' . $spa_entity_type;

if ($entity_type !== $expected_type) {
    writeLog("INFO: Skipping — type '$entity_type' is not our SPA ($expected_type).", $log_file);
    exit;
}

if (!$item_id) {
    writeLog("ERROR: No Item ID in webhook payload. Exiting.", $log_file);
    exit;
}

writeLog("INFO: Processing new SPA item #$item_id (entityTypeId=$spa_entity_type).", $log_file);

// ─────────────────────────────────────────────────────────────────────────────
// 3. FETCH FULL SPA ITEM DETAILS  →  crm.item.get
// ─────────────────────────────────────────────────────────────────────────────
$item_response = callBitrix('crm.item.get', [
    'entityTypeId' => $spa_entity_type,
    'id'           => $item_id,
], $rest_url);

$item = $item_response['result']['item'] ?? null;

if (!$item) {
    writeLog("ERROR: Could not fetch SPA item #$item_id — " . print_r($item_response, true), $log_file);
    exit;
}

writeLog("--- SPA ITEM DATA ---", $log_file);
writeLog($item, $log_file);

// ─────────────────────────────────────────────────────────────────────────────
// 4. EXTRACT CUSTOM FIELDS
//    ufCrm8Name  → UF_CRM_8_NAME  (contact name)
//    ufCrm8Email → UF_CRM_8_EMAIL (contact email)
//    ufCrm8Phone → UF_CRM_8_PHONE (contact phone)
// ─────────────────────────────────────────────────────────────────────────────
$name  = trim($item['ufCrm8Name']  ?? '');
$email = trim($item['ufCrm8Email'] ?? '');
$phone = trim($item['ufCrm8Phone'] ?? '');

if (empty($name) && empty($email) && empty($phone)) {
    writeLog("WARNING: SPA item #$item_id has no name/email/phone — skipping contact creation.", $log_file);
    exit;
}

writeLog("INFO: Extracted → Name: '$name' | Email: '$email' | Phone: '$phone'", $log_file);

// ─────────────────────────────────────────────────────────────────────────────
// 5. CREATE CONTACT  →  crm.contact.add
// ─────────────────────────────────────────────────────────────────────────────
$contact_fields = ['NAME' => $name];

if (!empty($email)) {
    $contact_fields['EMAIL'] = [['VALUE' => $email, 'VALUE_TYPE' => 'WORK']];
}
if (!empty($phone)) {
    $contact_fields['PHONE'] = [['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']];
}

$contact_response = callBitrix('crm.contact.add', ['fields' => $contact_fields], $rest_url);
$new_contact_id   = $contact_response['result'] ?? null;

if (!$new_contact_id) {
    writeLog("FAILED: Could not create contact for SPA item #$item_id — " . print_r($contact_response, true), $log_file);
    exit;
}

writeLog("SUCCESS: Contact #$new_contact_id created.", $log_file);

// ─────────────────────────────────────────────────────────────────────────────
// 6. LINK CONTACT BACK TO SPA ITEM  →  crm.item.update  (contactIds field)
// ─────────────────────────────────────────────────────────────────────────────
$update_response = callBitrix('crm.item.update', [
    'entityTypeId' => $spa_entity_type,
    'id'           => $item_id,
    'fields'       => [
        'contactIds' => [$new_contact_id],   // CONTACT_IDS — multiple, non-deprecated
    ],
], $rest_url);

if (!empty($update_response['result']['item'])) {
    writeLog("SUCCESS: SPA item #$item_id contactIds set to Contact #$new_contact_id.", $log_file);
} else {
    writeLog("WARNING: Update response for SPA item #$item_id — " . print_r($update_response, true), $log_file);
}
