<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

header('Content-Type: application/json');

global $DB;

$entities_id = isset($_GET['entities_id']) ? (int)$_GET['entities_id'] : -1;
if ($entities_id < 0) {
    echo json_encode(['users' => []]);
    exit;
}

// Include ancestor entities so recursive profile assignments propagate to child entities
$ancestors = array_keys(getAncestorsOf('glpi_entities', $entities_id));
$ancestor_condition = '';
if (!empty($ancestors)) {
    $ancestor_list      = implode(',', array_map('intval', $ancestors));
    $ancestor_condition = "OR (pu.entities_id IN ({$ancestor_list}) AND pu.is_recursive = 1)";
}

// Find users with UPDATE right on plugin_lagapenak_loan in this entity
$sql = "
    SELECT DISTINCT pu.users_id, u.name, u.realname, u.firstname
    FROM glpi_profiles_users pu
    JOIN glpi_profilerights pr ON pr.profiles_id = pu.profiles_id
    JOIN glpi_users u ON u.id = pu.users_id
    WHERE pr.name = 'plugin_lagapenak_loan'
      AND (pr.rights & " . UPDATE . ") > 0
      AND u.is_deleted = 0
      AND u.is_active = 1
      AND (
          pu.entities_id = {$entities_id}
          {$ancestor_condition}
      )
    ORDER BY u.realname, u.firstname, u.name
";

$result = $DB->query($sql);
$users  = [];
while ($row = $DB->fetchAssoc($result)) {
    $name    = trim(($row['realname'] ?? '') . ' ' . ($row['firstname'] ?? ''));
    if (!$name) $name = $row['name'];
    $users[] = ['id' => (int)$row['users_id'], 'name' => $name];
}

echo json_encode(['users' => $users, 'entities_id' => $entities_id]);
