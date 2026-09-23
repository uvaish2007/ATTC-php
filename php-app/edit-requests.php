<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/models/EditRequest.php';

$user = require_role(['Admin', 'Dean', 'HoD']);
$canProcess = in_array($user['role'], ['Admin', 'Dean'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (!$canProcess) {
        flash('error', 'Unauthorized: Only Dean and Administrator can process edit requests.');
        redirect('/approvals.php?tab=edit_requests');
    }

    $action        = (string) input('process_action');
    $ticketId      = (int) input('ticket_id');
    $adminComments = trim((string) input('admin_comments'));

    [$ok, $msg] = edit_request_process($ticketId, $action, $adminComments, (int) $user['id'], (string) $user['role']);
    flash($ok ? 'success' : 'error', $msg);
    redirect('/approvals.php?tab=edit_requests');
}

$qs = $_SERVER['QUERY_STRING'] ?? '';
$target = 'approvals.php?tab=edit_requests' . ($qs ? '&' . $qs : '');
redirect('/' . $target);
