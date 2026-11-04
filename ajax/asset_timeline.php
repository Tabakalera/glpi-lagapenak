<?php

/**
 * Retired in 1.6.1.
 *
 * No page of the plugin used this endpoint (since 1.1.0), and it returned every loan of the month —
 * loan names and requester names, all entities — to any logged-in user. The file is kept (instead
 * of deleted) so that an upgrade copied over an existing install replaces the old version.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

http_response_code(410);
header('Content-Type: application/json');
echo json_encode(['error' => 'Gone']);
