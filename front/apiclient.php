<?php

/**
 * Integration API (G1): settings, clients and recent calls. Super-Admin only (config UPDATE right).
 * A token is shown once, right after it is created or rotated; only its hash is stored.
 */

include('../../../inc/includes.php');

global $DB;

Session::checkRight('config', UPDATE);

$self = plugin_lagapenak_web_dir() . '/front/apiclient.php';

// GLPI 10 escapes request input; every field below is validated to plain characters afterwards
$in = class_exists('Glpi\Toolbox\Sanitizer') ? \Glpi\Toolbox\Sanitizer::unsanitize($_POST) : $_POST;

$flash_errors = [];

if (isset($in['save_config'])) {
    $proxies = PluginLagapenakApiClient::parseList($in['api_trusted_proxies'] ?? '');
    foreach ($proxies as $rule) {
        if (@inet_pton(explode('/', $rule, 2)[0]) === false) {
            $flash_errors[] = sprintf(__('Invalid IP address or range: %s', 'lagapenak'), $rule);
        }
    }
    if (!$flash_errors) {
        Config::setConfigurationValues('plugin:lagapenak', [
            'api_enabled'            => !empty($in['api_enabled']) ? 1 : 0,
            'api_require_https'      => !empty($in['api_require_https']) ? 1 : 0,
            'api_trusted_proxies'    => implode(' ', $proxies),
            'api_rate_per_minute'    => max(1, min(10000, (int) ($in['api_rate_per_minute'] ?? 120))),
            'api_log_retention_days' => max(30, min(3650, (int) ($in['api_log_retention_days'] ?? 365))),
        ]);
        Session::addMessageAfterRedirect(__('Settings saved.', 'lagapenak'));
        Html::redirect($self);
    }
}

if (isset($in['add_client']) || isset($in['update_client'])) {
    [$fields, $errors] = PluginLagapenakApiClient::validateInput($in);
    if ($errors) {
        $flash_errors = $errors;
    } elseif (isset($in['add_client'])) {
        $_SESSION['plugin_lagapenak_new_token'] = PluginLagapenakApiClient::create($fields);
        Html::redirect($self);
    } else {
        PluginLagapenakApiClient::update((int) $in['id'], $fields);
        Session::addMessageAfterRedirect(__('Client saved.', 'lagapenak'));
        Html::redirect($self);
    }
}

if (isset($in['rotate_client'])) {
    $token = PluginLagapenakApiClient::rotate((int) $in['id'], (int) ($in['grace_days'] ?? 0));
    if ($token !== null) {
        $_SESSION['plugin_lagapenak_new_token'] = $token;
    }
    Html::redirect($self);
}

if (isset($in['revoke_client'])) {
    PluginLagapenakApiClient::revoke((int) $in['id']);
    Session::addMessageAfterRedirect(__('Credential revoked: it stopped working immediately.', 'lagapenak'));
    Html::redirect($self);
}

Html::header(__('Lagapenak · Integration API', 'lagapenak'), $self, 'config', 'plugins');

$h = function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
$config  = PluginLagapenakApiClient::getConfig();
$clients = PluginLagapenakApiClient::getAll();
$edit    = isset($_GET['id']) ? PluginLagapenakApiClient::getById((int) $_GET['id']) : null;

echo '<div class="container-fluid" style="max-width:1100px">';
echo '<h2 class="mt-3">' . __('Integration API (Kide-Artean)', 'lagapenak') . '</h2>';
echo '<p class="text-muted">' . __('Read-only access to loans of the authorised entities and links with residencies and assignments. Contract: docs/api/openapi-g1.yaml in the plugin folder.', 'lagapenak') . '</p>';

foreach ($flash_errors as $e) {
    echo '<div class="alert alert-danger">' . $h($e) . '</div>';
}

if (!empty($_SESSION['plugin_lagapenak_new_token'])) {
    echo '<div class="alert alert-warning"><strong>' . __('Copy this credential now: it will not be shown again.', 'lagapenak') . '</strong>'
        . '<pre class="mt-2 mb-0 user-select-all">' . $h($_SESSION['plugin_lagapenak_new_token']) . '</pre></div>';
    unset($_SESSION['plugin_lagapenak_new_token']);
}

// ── Settings ─────────────────────────────────────────────────────────────────
echo '<div class="card mb-3"><div class="card-header"><strong>' . __('Settings', 'lagapenak') . '</strong></div><div class="card-body">';
echo '<form method="post" action="' . $h($self) . '">';
echo '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="api_enabled" id="api_enabled" value="1"' . ($config['enabled'] ? ' checked' : '') . '>'
    . '<label class="form-check-label" for="api_enabled">' . __('API enabled (when disabled, every call answers 404)', 'lagapenak') . '</label></div>';
echo '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="api_require_https" id="api_require_https" value="1"' . ($config['require_https'] ? ' checked' : '') . '>'
    . '<label class="form-check-label" for="api_require_https">' . __('Require HTTPS (only disable in test environments)', 'lagapenak') . '</label></div>';
echo '<div class="row g-2">';
echo '<div class="col-md-6"><label class="form-label">' . __('Trusted reverse proxies (IP or range; only these may set X-Forwarded-For / X-Forwarded-Proto)', 'lagapenak') . '</label>'
    . '<input class="form-control" name="api_trusted_proxies" value="' . $h(implode(' ', $config['trusted_proxies'])) . '"></div>';
echo '<div class="col-md-3"><label class="form-label">' . __('Requests per minute per client', 'lagapenak') . '</label>'
    . '<input class="form-control" type="number" min="1" max="10000" name="api_rate_per_minute" value="' . (int) $config['rate_per_minute'] . '"></div>';
echo '<div class="col-md-3"><label class="form-label">' . __('Keep API log (days)', 'lagapenak') . '</label>'
    . '<input class="form-control" type="number" min="30" max="3650" name="api_log_retention_days" value="' . (int) $config['log_retention_days'] . '"></div>';
echo '</div>';
echo '<button class="btn btn-primary mt-3" type="submit" name="save_config" value="1">' . __('Save') . '</button>';
Html::closeForm();
echo '</div></div>';

// ── Clients ──────────────────────────────────────────────────────────────────
$entity_names = [];
foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_entities', 'ORDER' => 'completename']) as $e) {
    $entity_names[(int) $e['id']] = $e['completename'];
}

echo '<div class="card mb-3"><div class="card-header"><strong>' . __('Clients', 'lagapenak') . '</strong></div><div class="card-body p-0">';
echo '<table class="table table-sm mb-0"><thead><tr><th>' . __('Name') . '</th><th>' . __('Source', 'lagapenak') . '</th><th>'
    . __('Scopes', 'lagapenak') . '</th><th>' . __('Entities') . '</th><th>' . __('Allowed IPs', 'lagapenak') . '</th><th>'
    . __('Expires', 'lagapenak') . '</th><th>' . __('Last used', 'lagapenak') . '</th><th>' . __('Status') . '</th><th></th></tr></thead><tbody>';
foreach ($clients as $c) {
    $names = array_map(function ($id) use ($entity_names) {
        return $entity_names[(int) $id] ?? ('#' . (int) $id);
    }, json_decode((string) $c['entities'], true) ?: []);
    $status = (int) $c['is_active'] && !empty($c['token_hash']) && strtotime((string) $c['expires_at']) > time()
        ? '<span class="badge bg-success">' . __('Active') . '</span>'
        : '<span class="badge bg-secondary">' . __('Inactive') . '</span>';
    if (!empty($c['prev_token_expires_at']) && strtotime($c['prev_token_expires_at']) > time()) {
        $status .= ' <span class="badge bg-warning text-dark">' . sprintf(__('previous credential valid until %s', 'lagapenak'), $h(Html::convDateTime($c['prev_token_expires_at']))) . '</span>';
    }
    echo '<tr><td>' . $h($c['name']) . '</td><td>' . $h($c['source']) . '</td><td>' . $h($c['scopes']) . '</td><td>'
        . $h(implode(', ', $names)) . ((int) $c['is_recursive'] ? ' <em>(+ ' . __('sub-entities', 'lagapenak') . ')</em>' : '') . '</td><td>'
        . nl2br($h($c['allowed_ips'])) . '</td><td>' . $h(Html::convDate($c['expires_at'])) . '</td><td>'
        . $h(Html::convDateTime($c['last_used_at'])) . '</td><td>' . $status . '</td><td class="text-nowrap">'
        . '<a class="btn btn-sm btn-outline-secondary" href="' . $h($self) . '?id=' . (int) $c['id'] . '">' . __('Edit') . '</a></td></tr>';
}
if (!$clients) {
    echo '<tr><td colspan="9" class="text-muted">' . __('No clients yet.', 'lagapenak') . '</td></tr>';
}
echo '</tbody></table></div></div>';

// ── Client form (new or edit) ────────────────────────────────────────────────
$f = $edit ?: [
    'name' => 'Kide-Artean', 'source' => 'kide-artean', 'scopes' => implode(' ', PluginLagapenakApiClient::SCOPES),
    'entities' => '[]', 'is_recursive' => 0, 'allowed_ips' => '', 'is_active' => 1,
    'expires_at' => date('Y-m-d', time() + 365 * DAY_TIMESTAMP),
];
$f_scopes   = explode(' ', (string) $f['scopes']);
$f_entities = array_map('intval', json_decode((string) $f['entities'], true) ?: []);

echo '<div class="card mb-3"><div class="card-header"><strong>'
    . ($edit ? sprintf(__('Edit client: %s', 'lagapenak'), $h($edit['name'])) : __('New client', 'lagapenak')) . '</strong></div><div class="card-body">';
echo '<form method="post" action="' . $h($self) . '">';
if ($edit) {
    echo '<input type="hidden" name="id" value="' . (int) $edit['id'] . '">';
}
echo '<div class="row g-2">';
echo '<div class="col-md-4"><label class="form-label">' . __('Name') . '</label><input class="form-control" name="name" maxlength="100" value="' . $h($f['name']) . '"></div>';
echo '<div class="col-md-4"><label class="form-label">' . __('Source', 'lagapenak') . '</label><input class="form-control" name="source" maxlength="32" value="' . $h($f['source']) . '"'
    . ($edit ? ' readonly' : '') . '></div>';
echo '<div class="col-md-4"><label class="form-label">' . __('Expires (12 months at most)', 'lagapenak') . '</label><input class="form-control" type="date" name="expires_at" value="'
    . $h(substr((string) $f['expires_at'], 0, 10)) . '"></div>';
echo '</div><div class="row g-2 mt-1"><div class="col-md-4"><label class="form-label">' . __('Scopes', 'lagapenak') . '</label>';
foreach (PluginLagapenakApiClient::SCOPES as $s) {
    echo '<div class="form-check"><input class="form-check-input" type="checkbox" name="scopes[]" value="' . $h($s) . '"'
        . (in_array($s, $f_scopes, true) ? ' checked' : '') . '><label class="form-check-label">' . $h($s) . '</label></div>';
}
echo '<div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="is_active" value="1"' . ((int) $f['is_active'] ? ' checked' : '') . '>'
    . '<label class="form-check-label">' . __('Active') . '</label></div>';
echo '</div><div class="col-md-4"><label class="form-label">' . __('Authorised entities', 'lagapenak') . '</label>'
    . '<div class="border rounded p-2" style="max-height:220px;overflow:auto">';
foreach ($entity_names as $id => $name) {
    echo '<div class="form-check"><input class="form-check-input" type="checkbox" name="entities[]" value="' . $id . '"'
        . (in_array($id, $f_entities, true) ? ' checked' : '') . '><label class="form-check-label">' . $h($name) . '</label></div>';
}
echo '</div><div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="is_recursive" value="1"' . ((int) $f['is_recursive'] ? ' checked' : '') . '>'
    . '<label class="form-check-label">' . __('Include sub-entities', 'lagapenak') . '</label></div></div>';
echo '<div class="col-md-4"><label class="form-label">' . __('Allowed source IPs (one per line; address or range)', 'lagapenak') . '</label>'
    . '<textarea class="form-control" name="allowed_ips" rows="6">' . $h($f['allowed_ips']) . '</textarea></div>';
echo '</div>';
echo '<button class="btn btn-primary mt-3" type="submit" name="' . ($edit ? 'update_client' : 'add_client') . '" value="1">'
    . ($edit ? __('Save') : __('Create client and show credential', 'lagapenak')) . '</button>';
if ($edit) {
    echo ' <a class="btn btn-outline-secondary mt-3" href="' . $h($self) . '">' . __('Cancel') . '</a>';
}
Html::closeForm();

if ($edit) {
    echo '<hr><form method="post" action="' . $h($self) . '" class="d-inline-flex align-items-end gap-2">';
    echo '<input type="hidden" name="id" value="' . (int) $edit['id'] . '">';
    echo '<div><label class="form-label">' . __('Days the current credential keeps working', 'lagapenak') . '</label>'
        . '<input class="form-control" type="number" min="0" max="30" name="grace_days" value="7" style="width:8em"></div>';
    echo '<button class="btn btn-warning" type="submit" name="rotate_client" value="1">' . __('Issue new credential', 'lagapenak') . '</button>';
    Html::closeForm();
    echo ' <form method="post" action="' . $h($self) . '" class="d-inline" onsubmit="return confirm(\''
        . $h(__('The client will stop working immediately. Continue?', 'lagapenak')) . '\')">';
    echo '<input type="hidden" name="id" value="' . (int) $edit['id'] . '">';
    echo '<button class="btn btn-danger" type="submit" name="revoke_client" value="1">' . __('Revoke now', 'lagapenak') . '</button>';
    Html::closeForm();
}
echo '</div></div>';

// ── Recent calls ─────────────────────────────────────────────────────────────
$client_names = array_column($clients, 'name', 'id');
echo '<div class="card mb-3"><div class="card-header"><strong>' . __('Last 50 calls', 'lagapenak') . '</strong></div><div class="card-body p-0">';
echo '<table class="table table-sm mb-0"><thead><tr><th>' . __('Date') . '</th><th>' . __('Client', 'lagapenak') . '</th><th>IP</th><th>'
    . __('Request', 'lagapenak') . '</th><th>' . __('Result', 'lagapenak') . '</th><th>' . __('Kide-Artean user', 'lagapenak') . '</th><th>X-Request-Id</th></tr></thead><tbody>';
foreach ($DB->request(['FROM' => 'glpi_plugin_lagapenak_apilogs', 'ORDER' => 'id DESC', 'LIMIT' => 50]) as $l) {
    echo '<tr><td class="text-nowrap">' . $h(Html::convDateTime($l['date'])) . '</td><td>' . $h($client_names[$l['apiclients_id']] ?? '—') . '</td><td>'
        . $h($l['ip']) . '</td><td><code>' . $h($l['method'] . ' ' . $l['route'] . ($l['query'] !== '' ? '?' . $l['query'] : '')) . '</code></td><td>'
        . (int) $l['status'] . ($l['code'] !== '' ? ' ' . $h($l['code']) : '') . ((int) $l['result_count'] ? ' · ' . (int) $l['result_count'] : '') . '</td><td><code>'
        . $h($l['acting_user']) . '</code></td><td><code>' . $h($l['request_id']) . '</code></td></tr>';
}
echo '</tbody></table></div></div>';
echo '</div>';

Html::footer();
