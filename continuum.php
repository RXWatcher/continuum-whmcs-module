<?php

/**
 * WHMCS Provisioning Module: continuum
 *
 * Translates WHMCS service-lifecycle hooks into calls against
 * Continuum's admin HTTP API.
 */

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/autoload.php';

use Continuum\WhmcsModule\Hooks;

/**
 * Module metadata.
 *
 * @return array<string, mixed>
 */
function continuum_MetaData(): array
{
    return [
        'DisplayName' => 'Continuum',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultNonSSLPort' => '80',
        'DefaultSSLPort' => '443',
    ];
}

/**
 * Per-product config fields.
 *
 * @return array<string, array<string, string>>
 */
function continuum_ConfigOptions(): array
{
    return [
        'role' => [
            'FriendlyName' => 'Role',
            'Type' => 'dropdown',
            'Options' => 'user,admin',
            'Default' => 'user',
        ],
        'library_ids' => [
            'FriendlyName' => 'Library IDs',
            'Type' => 'text',
            'Description' => 'Comma-separated continuum library IDs, e.g. 1,3,5.'
                . ' Leave blank to grant access to ALL libraries (including any added later).',
        ],
        'max_streams' => [
            'FriendlyName' => 'Max concurrent streams',
            'Type' => 'text', 'Default' => '6',
        ],
        'max_transcodes' => [
            'FriendlyName' => 'Max concurrent transcodes',
            'Type' => 'text', 'Default' => '2',
        ],
        'max_profiles' => [
            'FriendlyName' => 'Max profiles',
            'Type' => 'text', 'Default' => '5',
        ],
        'download_allowed' => [
            'FriendlyName' => 'Downloads allowed',
            'Type' => 'yesno', 'Default' => 'yes',
        ],
        'download_transcode_allowed' => [
            'FriendlyName' => 'Download transcode allowed',
            // No Default => WHMCS renders the checkbox unchecked, i.e.
            // defaults to No. ('Default' => 'no' is non-standard for a
            // yesno checkbox and does not reliably mean off.)
            'Type' => 'yesno',
            'Description' => 'Default: No. Has no effect unless Downloads allowed is enabled.',
        ],
        'max_playback_quality' => [
            'FriendlyName' => 'Max playback quality',
            'Type' => 'dropdown',
            'Options' => ',1080p,4k',
            'Description' => 'Leave blank for unrestricted. Continuum only'
                . ' enforces 1080p or 4k; older 720p/480p settings behave as 1080p.',
        ],
        'create_default_profile' => [
            'FriendlyName' => 'Create default profile on CreateAccount',
            'Type' => 'yesno', 'Default' => 'yes',
        ],
        'allow_user_chosen_username' => [
            'FriendlyName' => 'Allow customer-chosen username',
            'Type' => 'yesno', 'Default' => 'no',
            'Description' => 'If yes, customers can pick their handle via the order-form "desired_username"'
                . ' custom field. If no, every account gets a generated abcd232-style username.',
        ],
    ];
}

// === Server connection test (WHMCS Servers page) ===

/**
 * Backs the "Test Connection" button on System Settings -> Servers.
 *
 * @param array<string, mixed> $params
 * @return array{success: bool, error: string}
 */
function continuum_TestConnection(array $params): array
{
    return (new Hooks())->testConnection($params);
}

// === Lifecycle hooks (Phase 6 onwards) ===

function continuum_CreateAccount(array $params): string
{
    return (new Hooks())->createAccount($params);
}

function continuum_SuspendAccount(array $params): string
{
    return (new Hooks())->suspendAccount($params);
}

function continuum_UnsuspendAccount(array $params): string
{
    return (new Hooks())->unsuspendAccount($params);
}

function continuum_TerminateAccount(array $params): string
{
    return (new Hooks())->terminateAccount($params);
}

function continuum_ChangePassword(array $params): string
{
    return (new Hooks())->changePassword($params);
}

function continuum_ChangePackage(array $params): string
{
    return (new Hooks())->changePackage($params);
}

// === Admin custom buttons (Phase 9) ===

function continuum_AdminCustomButtonArray(): array
{
    return [
        'Reconcile from WHMCS' => 'admin_reconcile',
        'Reset Password' => 'admin_reset_password',
        'Scaffold Configurable Options' => 'admin_scaffold_options',
    ];
}

function continuum_admin_reconcile(array $params): string
{
    return (new Hooks())->adminReconcile($params);
}

function continuum_admin_scaffold_options(array $params): string
{
    return (new Hooks())->adminScaffoldOptions($params);
}

function continuum_admin_reset_password(array $params): string
{
    return (new Hooks())->adminResetPassword($params);
}

// === Admin tab (Phase 10) ===

function continuum_AdminServicesTabFields(array $params): array
{
    return (new Hooks())->adminServicesTabFields($params);
}

// === Client area (Phase 11) ===

function continuum_ClientAreaCustomButtonArray(): array
{
    return [];   // login button is rendered inside the ClientArea template
}

function continuum_ClientArea(array $params): array
{
    return (new Hooks())->clientArea($params);
}
