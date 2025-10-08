#!/usr/bin/env python3
"""Update sessions REST integration for the Professional Development plugin.

This script is designed to keep multi-file edits consistent and repeatable:
- creates/updates the sessions REST controller
- adjusts the main plugin bootstrap to load it and localize JS configs
- rewrites the sessions admin JS to consume the REST API
- rewrites the session profile JS to consume the REST API

Usage (dry-run by default):
    python3 tools/update_sessions_api.py
Apply changes:
    python3 tools/update_sessions_api.py --apply
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path
from textwrap import dedent

ROOT = Path(__file__).resolve().parents[1]


REST_SESSIONS_PHP = dedent(
    """
    <?php
    if (!defined('ABSPATH')) {
        exit;
    }

    add_action('rest_api_init', function () {
        register_rest_route('profdev/v1', '/sessions', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'pd_sessions_list',
            'permission_callback' => 'pd_sessions_permission',
        ]);

        register_rest_route('profdev/v1', '/sessions/(?P<id>\\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'pd_sessions_get_item',
            'permission_callback' => 'pd_sessions_permission',
            'args'                => [
                'id' => [
                    'type'     => 'integer',
                    'required' => true,
                ],
            ],
        ]);

        register_rest_route('profdev/v1', '/sessions', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'pd_sessions_create',
            'permission_callback' => 'pd_sessions_permission',
            'args'                => pd_sessions_args_schema(),
        ]);

        register_rest_route('profdev/v1', '/sessions/(?P<id>\\d+)', [
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => 'pd_sessions_update',
            'permission_callback' => 'pd_sessions_permission',
            'args'                => array_merge(
                [
                    'id' => [
                        'type'     => 'integer',
                        'required' => true,
                    ],
                ],
                pd_sessions_args_schema()
            ),
        ]);
    });

    function pd_sessions_permission( WP_REST_Request $request ) {
        $nonce = $request->get_header('X-WP-Nonce');
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_Error('rest_forbidden', __( 'Bad or missing nonce.', 'professional-development' ), ['status' => 403]);
        }
        if ( ! current_user_can('manage_options') ) {
            return new WP_Error('rest_forbidden', __( 'Insufficient permissions.', 'professional-development' ), ['status' => 403]);
        }
        return true;
    }

    function pd_sessions_args_schema() {
        $schema = [
            'date' => [
                'type'     => 'string',
                'required' => true,
            ],
            'title' => [
                'type'     => 'string',
                'required' => true,
            ],
            'length' => [
                'type'     => 'integer',
                'required' => true,
            ],
            'stype' => [
                'type'     => 'string',
                'required' => true,
            ],
            'ceuWeight' => [
                'type'     => 'string',
                'required' => false,
            ],
            'ceuConsiderations' => [
                'type'     => 'string',
                'required' => false,
            ],
            'qualifyForCeus' => [
                'type'     => 'string',
                'required' => true,
            ],
            'eventType' => [
                'type'     => 'string',
                'required' => true,
            ],
            'presenters' => [
                'type'     => 'string',
                'required' => true,
            ],
        ];
        return apply_filters('pd_sessions_args_schema', $schema);
    }

    function pd_sessions_db_options() {
        $defaults = [
            'host' => get_option('ProfessionalDevelopment_db_host', ''),
            'name' => get_option('ProfessionalDevelopment_db_name', ''),
            'user' => get_option('ProfessionalDevelopment_db_user', ''),
            'pass' => get_option('ProfessionalDevelopment_db_pass', ''),
        ];
        return apply_filters('pd_sessions_db_options', $defaults);
    }

    function pd_sessions_connect() {
        $options = pd_sessions_db_options();

        $host = isset($options['host']) ? ProfessionalDevelopment_decrypt($options['host']) : '';
        $name = isset($options['name']) ? ProfessionalDevelopment_decrypt($options['name']) : '';
        $user = isset($options['user']) ? ProfessionalDevelopment_decrypt($options['user']) : '';
        $pass = isset($options['pass']) ? ProfessionalDevelopment_decrypt($options['pass']) : '';

        if (! $host || ! $name || ! $user || $pass === false) {
            return new WP_Error('pd_sessions_credentials', __( 'Database credentials are missing or could not be decrypted.', 'professional-development' ), ['status' => 500]);
        }

        $mysqli = @new mysqli($host, $user, $pass, $name);
        if ($mysqli->connect_error) {
            return new WP_Error('db_connect_error', sprintf(__( 'Database connection failed: %s', 'professional-development' ), $mysqli->connect_error), ['status' => 500]);
        }
        $mysqli->set_charset('utf8mb4');
        return $mysqli;
    }

    function pd_sessions_drain_results(mysqli $conn) {
        while ($conn->more_results() && $conn->next_result()) {
            if ($result = $conn->use_result()) {
                $result->free();
            }
        }
    }

    function pd_sessions_wp_error(mysqli $conn, $default_message, $code = 'db_query_error', $status = 500) {
        $errno  = $conn->errno;
        $detail = $conn->error;

        if ($errno === 1305) { // ER_SP_DOES_NOT_EXIST
            $hint = __( 'The expected stored procedure is missing. Create it in the database or override the filter (e.g. pd_sessions_fetch_proc).', 'professional-development' );
            return new WP_Error('db_missing_procedure', $hint, ['status' => 500]);
        }
        if ($detail) {
            $default_message .= ' ' . $detail;
        }
        return new WP_Error($code, $default_message, ['status' => $status]);
    }

    function pd_sessions_normalize_row(array $row) {
        $id = null;
        foreach (['id', 'session_id', 'ID'] as $key) {
            if (isset($row[$key])) {
                $id = (int) $row[$key];
                break;
            }
        }

        $raw_date = '';
        foreach (['date', 'session_date', 'sessionDate'] as $key) {
            if (!empty($row[$key])) {
                $raw_date = (string) $row[$key];
                break;
            }
        }
        $iso_date = pd_sessions_to_iso_date($raw_date);
        $display_date = $iso_date ? pd_sessions_format_date($iso_date) : $raw_date;

        $length = null;
        foreach (['length', 'length_minutes', 'duration'] as $key) {
            if (isset($row[$key])) {
                $length = (int) $row[$key];
                break;
            }
        }

        $stype = '';
        foreach (['stype', 'session_type', 'type'] as $key) {
            if (isset($row[$key])) {
                $stype = (string) $row[$key];
                break;
            }
        }

        $event_type = '';
        foreach (['eventType', 'event_type'] as $key) {
            if (isset($row[$key])) {
                $event_type = (string) $row[$key];
                break;
            }
        }

        $ceu_weight = '';
        foreach (['ceuWeight', 'ceu_weight'] as $key) {
            if (isset($row[$key])) {
                $ceu_weight = (string) $row[$key];
                break;
            }
        }

        $ceu_considerations = '';
        foreach (['ceuConsiderations', 'ceu_considerations'] as $key) {
            if (isset($row[$key])) {
                $ceu_considerations = (string) $row[$key];
                break;
            }
        }

        $qualify = '';
        foreach (['qualifyForCeus', 'qualify_for_ceus', 'qualify'] as $key) {
            if (isset($row[$key])) {
                $qualify = (string) $row[$key];
                break;
            }
        }

        $presenters = '';
        foreach (['presenters', 'presenter_names'] as $key) {
            if (isset($row[$key])) {
                $presenters = (string) $row[$key];
                break;
            }
        }

        $members = [];
        if (!empty($row['members']) && is_array($row['members'])) {
            $members = $row['members'];
        } elseif (!empty($row['members_json'])) {
            $decoded = json_decode($row['members_json'], true);
            if (is_array($decoded)) {
                $members = $decoded;
            }
        }

        $normalized = [
            'id'                => $id,
            'date'              => $display_date,
            'isoDate'           => $iso_date,
            'title'             => isset($row['title']) ? (string) $row['title'] : ((isset($row['session_title'])) ? (string) $row['session_title'] : ''),
            'length'            => $length,
            'stype'             => $stype,
            'eventType'         => $event_type,
            'ceuWeight'         => $ceu_weight,
            'ceuConsiderations' => $ceu_considerations,
            'qualifyForCeus'    => $qualify,
            'presenters'        => $presenters,
            'members'           => $members,
        ];

        return apply_filters('pd_sessions_normalize_row', $normalized, $row);
    }

    function pd_sessions_to_iso_date($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value)) {
            return $value;
        }
        if (preg_match('/^\\d{1,2}\\/\\d{1,2}\\/\\d{4}$/', $value)) {
            [$month, $day, $year] = array_map('intval', explode('/', $value));
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
        $timestamp = strtotime($value);
        if ($timestamp) {
            return date('Y-m-d', $timestamp);
        }
        return '';
    }

    function pd_sessions_format_date($iso) {
        if (! $iso) {
            return '';
        }
        $timestamp = strtotime($iso);
        if (! $timestamp) {
            return $iso;
        }
        return date('n/j/Y', $timestamp);
    }

    function pd_sessions_list( WP_REST_Request $request ) {
        $conn = pd_sessions_connect();
        if (is_wp_error($conn)) {
            return $conn;
        }

        $sql = apply_filters('pd_sessions_fetch_proc', 'CALL sessions_table_view()');
        $result = $conn->query($sql);
        if (! $result) {
            $error = pd_sessions_wp_error($conn, __( 'Failed to fetch sessions.', 'professional-development' ));
            $conn->close();
            return $error;
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = pd_sessions_normalize_row($row);
        }
        $result->free();
        pd_sessions_drain_results($conn);
        $conn->close();

        return rest_ensure_response($rows);
    }

    function pd_sessions_get_item( WP_REST_Request $request ) {
        $id = (int) $request['id'];

        $conn = pd_sessions_connect();
        if (is_wp_error($conn)) {
            return $conn;
        }

        $sql_pattern = apply_filters('pd_sessions_detail_proc', 'CALL session_profile_view(%d)');
        $sql = sprintf($sql_pattern, $id);
        $result = $conn->query($sql);
        if (! $result) {
            $error = pd_sessions_wp_error($conn, __( 'Failed to fetch the session.', 'professional-development' ));
            $conn->close();
            return $error;
        }

        $row = $result->fetch_assoc();
        $result->free();
        pd_sessions_drain_results($conn);
        $conn->close();

        if (! $row) {
            return new WP_Error('rest_not_found', __( 'Session not found.', 'professional-development' ), ['status' => 404]);
        }

        return rest_ensure_response(pd_sessions_normalize_row($row));
    }

    function pd_sessions_collect_payload( WP_REST_Request $request ) {
        $date_raw = trim((string) $request->get_param('date'));
        $iso_date = pd_sessions_to_iso_date($date_raw);
        if (! $iso_date) {
            return new WP_Error('rest_invalid_param', __( 'Invalid session date.', 'professional-development' ), ['status' => 400]);
        }

        $length = (int) $request->get_param('length');
        if ($length < 0) {
            $length = abs($length);
        }

        $qualify = strtoupper(substr(trim((string) $request->get_param('qualifyForCeus')), 0, 3));
        $qualify = ($qualify === 'YES') ? 'Yes' : 'No';

        $payload = [
            'date'              => $iso_date,
            'title'             => sanitize_text_field((string) $request->get_param('title')),
            'length'            => $length,
            'stype'             => sanitize_text_field((string) $request->get_param('stype')),
            'ceuWeight'         => sanitize_text_field((string) $request->get_param('ceuWeight')),
            'ceuConsiderations' => sanitize_textarea_field((string) $request->get_param('ceuConsiderations')),
            'qualifyForCeus'    => $qualify,
            'eventType'         => sanitize_text_field((string) $request->get_param('eventType')),
            'presenters'        => sanitize_text_field((string) $request->get_param('presenters')),
        ];

        if ($payload['title'] === '') {
            return new WP_Error('rest_invalid_param', __( 'Session title is required.', 'professional-development' ), ['status' => 400]);
        }
        if ($payload['stype'] === '') {
            return new WP_Error('rest_invalid_param', __( 'Session type is required.', 'professional-development' ), ['status' => 400]);
        }
        if ($payload['eventType'] === '') {
            return new WP_Error('rest_invalid_param', __( 'Event type is required.', 'professional-development' ), ['status' => 400]);
        }
        if ($payload['presenters'] === '') {
            return new WP_Error('rest_invalid_param', __( 'Presenter information is required.', 'professional-development' ), ['status' => 400]);
        }

        return $payload;
    }

    function pd_sessions_after_write(mysqli $conn) {
        $status = $conn->query('SELECT @ok AS success, LAST_INSERT_ID() AS id');
        if (! $status) {
            return new WP_Error('db_exec_error', __( 'Unable to determine stored procedure status.', 'professional-development' ), ['status' => 500]);
        }
        $row = $status->fetch_assoc();
        $status->free();
        pd_sessions_drain_results($conn);

        $success = isset($row['success']) ? (int) $row['success'] : 0;
        $new_id  = isset($row['id']) ? (int) $row['id'] : 0;

        if ($success !== 1) {
            return new WP_Error('db_exec_error', __( 'The database reported a failure when saving the session.', 'professional-development' ), ['status' => 500]);
        }

        return ['id' => $new_id];
    }

    function pd_sessions_fetch_single(mysqli $conn, $id) {
        $sql_pattern = apply_filters('pd_sessions_detail_proc', 'CALL session_profile_view(%d)');
        $sql = sprintf($sql_pattern, (int) $id);
        $result = $conn->query($sql);
        if (! $result) {
            return pd_sessions_wp_error($conn, __( 'Failed to fetch the saved session.', 'professional-development' ));
        }
        $row = $result->fetch_assoc();
        $result->free();
        pd_sessions_drain_results($conn);
        if (! $row) {
            return new WP_Error('rest_not_found', __( 'Session not found after saving.', 'professional-development' ), ['status' => 404]);
        }
        return $row;
    }

    function pd_sessions_create( WP_REST_Request $request ) {
        $payload = pd_sessions_collect_payload($request);
        if (is_wp_error($payload)) {
            return $payload;
        }

        $conn = pd_sessions_connect();
        if (is_wp_error($conn)) {
            return $conn;
        }

        $sql = apply_filters('pd_sessions_insert_proc', 'CALL add_session(?, ?, ?, ?, ?, ?, ?, ?, ?, @ok)');
        $stmt = $conn->prepare($sql);
        if (! $stmt) {
            $error = pd_sessions_wp_error($conn, __( 'Unable to prepare the session insert statement.', 'professional-development' ));
            $conn->close();
            return $error;
        }

        $length = isset($payload['length']) ? (int) $payload['length'] : 0;
        $stmt->bind_param(
            'ssissssss',
            $payload['date'],
            $payload['title'],
            $length,
            $payload['stype'],
            $payload['ceuWeight'],
            $payload['ceuConsiderations'],
            $payload['qualifyForCeus'],
            $payload['eventType'],
            $payload['presenters']
        );

        if (! $stmt->execute()) {
            $stmt->close();
            $error = pd_sessions_wp_error($conn, __( 'Failed to insert the session.', 'professional-development' ));
            $conn->close();
            return $error;
        }
        $stmt->close();

        $status = pd_sessions_after_write($conn);
        if (is_wp_error($status)) {
            $conn->close();
            return $status;
        }

        $row = pd_sessions_fetch_single($conn, $status['id']);
        $conn->close();
        if (is_wp_error($row)) {
            return $row;
        }

        return new WP_REST_Response(pd_sessions_normalize_row($row), 201);
    }

    function pd_sessions_update( WP_REST_Request $request ) {
        $payload = pd_sessions_collect_payload($request);
        if (is_wp_error($payload)) {
            return $payload;
        }
        $payload['id'] = (int) $request['id'];
        if ($payload['id'] <= 0) {
            return new WP_Error('rest_invalid_param', __( 'Invalid session identifier.', 'professional-development' ), ['status' => 400]);
        }

        $conn = pd_sessions_connect();
        if (is_wp_error($conn)) {
            return $conn;
        }

        $sql = apply_filters('pd_sessions_update_proc', 'CALL update_session(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, @ok)');
        $stmt = $conn->prepare($sql);
        if (! $stmt) {
            $error = pd_sessions_wp_error($conn, __( 'Unable to prepare the session update statement.', 'professional-development' ));
            $conn->close();
            return $error;
        }

        $length = isset($payload['length']) ? (int) $payload['length'] : 0;
        $stmt->bind_param(
            'ississssss',
            $payload['id'],
            $payload['date'],
            $payload['title'],
            $length,
            $payload['stype'],
            $payload['ceuWeight'],
            $payload['ceuConsiderations'],
            $payload['qualifyForCeus'],
            $payload['eventType'],
            $payload['presenters']
        );

        if (! $stmt->execute()) {
            $stmt->close();
            $error = pd_sessions_wp_error($conn, __( 'Failed to update the session.', 'professional-development' ));
            $conn->close();
            return $error;
        }
        $stmt->close();

        $status = pd_sessions_after_write($conn);
        if (is_wp_error($status)) {
            $conn->close();
            return $status;
        }

        $row = pd_sessions_fetch_single($conn, $payload['id']);
        $conn->close();
        if (is_wp_error($row)) {
            return $row;
        }

        return rest_ensure_response(pd_sessions_normalize_row($row));
    }
    """
).strip() + "\n"

PD_SESSIONS_TABLE_JS = dedent(
    """
    let sessionSortKey = null;
    let sessionSortAsc = true;

    const PDSessionsConfig = window.PDSessions || {};
    const PD_SESSIONS_ENDPOINT = (() => {
        const root = (PDSessionsConfig.restRoot || '').replace(/\/?$/, '/');
        const route = (PDSessionsConfig.sessionsRoute || 'sessions').replace(/^\/?/, '');
        return root + route;
    })();
    const PD_SESSIONS_DETAIL_BASE = PDSessionsConfig.detailPageBase || '';
    const PD_SESSIONS_NONCE = PDSessionsConfig.nonce || '';

    const sessionsState = {
        list: [],
        filtered: []
    };

    function normalizeSession(raw, index) {
        const normalized = { ...raw };

        const possibleId = raw.id ?? raw.session_id ?? raw.ID;
        if (typeof possibleId === 'number') {
            normalized.id = possibleId;
        } else if (typeof possibleId === 'string' && possibleId.trim() !== '' && !Number.isNaN(parseInt(possibleId, 10))) {
            normalized.id = parseInt(possibleId, 10);
        } else {
            normalized.id = null;
        }

        const isoDate = normalizeToISO(raw.isoDate || raw.date || raw.session_date || '');
        normalized.isoDate = isoDate;
        normalized.date = isoDate ? formatDateForDisplay(isoDate) : (raw.date || raw.session_date || '');

        const lengthValue = raw.length ?? raw.length_minutes ?? raw.duration;
        normalized.length = Number.isFinite(lengthValue) ? Number(lengthValue) : parseInt(lengthValue, 10) || 0;

        normalized.title = (raw.title || raw.session_title || '').toString();
        normalized.stype = (raw.stype || raw.session_type || raw.type || '').toString();
        normalized.eventType = (raw.eventType || raw.event_type || '').toString();
        normalized.ceuWeight = (raw.ceuWeight || raw.ceu_weight || '').toString();
        normalized.ceuConsiderations = (raw.ceuConsiderations || raw.ceu_considerations || '').toString();
        normalized.qualifyForCeus = (raw.qualifyForCeus || raw.qualify_for_ceus || raw.qualify || '').toString() || 'No';
        normalized.presenters = (raw.presenters || raw.presenter_names || '').toString();

        if (Array.isArray(raw.members)) {
            normalized.members = raw.members;
        } else if (typeof raw.members === 'string' && raw.members.trim() !== '') {
            try {
                const parsed = JSON.parse(raw.members);
                normalized.members = Array.isArray(parsed) ? parsed : [];
            } catch (error) {
                normalized.members = [];
            }
        } else if (typeof raw.members_json === 'string' && raw.members_json.trim() !== '') {
            try {
                const parsed = JSON.parse(raw.members_json);
                normalized.members = Array.isArray(parsed) ? parsed : [];
            } catch (error) {
                normalized.members = [];
            }
        } else {
            normalized.members = [];
        }

        normalized.rowKey = normalized.id !== null && normalized.id !== undefined ? `id-${normalized.id}` : `idx-${index}`;

        return normalized;
    }

    function normalizeToISO(value) {
        const trimmed = (value || '').toString().trim();
        if (trimmed === '') {
            return '';
        }
        if (/^\d{4}-\d{2}-\d{2}$/.test(trimmed)) {
            return trimmed;
        }
        if (/^\d{1,2}\/\d{1,2}\/\d{4}$/.test(trimmed)) {
            const [month, day, year] = trimmed.split('/').map((part) => parseInt(part, 10));
            return `${year.toString().padStart(4, '0')}-${month.toString().padStart(2, '0')}-${day.toString().padStart(2, '0')}`;
        }
        const parsed = new Date(trimmed);
        if (!Number.isNaN(parsed.getTime())) {
            const month = (parsed.getMonth() + 1).toString().padStart(2, '0');
            const day = parsed.getDate().toString().padStart(2, '0');
            return `${parsed.getFullYear()}-${month}-${day}`;
        }
        return '';
    }

    function formatDateForDisplay(isoDate) {
        if (!isoDate) {
            return '';
        }
        const parts = isoDate.split('-');
        if (parts.length === 3) {
            const [year, month, day] = parts;
            return `${parseInt(month, 10)}/${parseInt(day, 10)}/${year}`;
        }
        const parsed = new Date(isoDate);
        if (!Number.isNaN(parsed.getTime())) {
            return `${parsed.getMonth() + 1}/${parsed.getDate()}/${parsed.getFullYear()}`;
        }
        return isoDate;
    }

    function updateSessionSortArrows() {
        const keys = ['date', 'title', 'length', 'stype', 'ceuWeight', 'ceuConsiderations', 'qualifyForCeus', 'eventType', 'presenters'];
        keys.forEach((key) => {
            const el = document.getElementById(`sort-arrow-${key}`);
            if (!el) {
                return;
            }
            if (sessionSortKey === key) {
                el.textContent = sessionSortAsc ? '▲' : '▼';
                el.style.color = '#e11d48';
                el.style.fontSize = '1em';
                el.style.marginLeft = '0.2em';
            } else {
                el.textContent = '';
            }
        });
    }

    async function fetchSessions() {
        setTableMessage('Loading sessions…');
        try {
            const response = await fetch(PD_SESSIONS_ENDPOINT, {
                headers: buildHeaders(),
                credentials: 'same-origin'
            });
            if (!response.ok) {
                throw await toFetchError(response);
            }
            const data = await response.json();
            const list = Array.isArray(data) ? data : [];
            sessionsState.list = list.map((item, index) => normalizeSession(item, index));
            sessionsState.filtered = [...sessionsState.list];
            if (sessionsState.list.length === 0) {
                setTableMessage('No sessions found.');
            } else {
                updateSessionSortArrows();
                renderSessions();
            }
        } catch (error) {
            console.error('Failed to load sessions', error);
            setTableMessage('Unable to load sessions. Check the database connection.');
        }
    }

    function buildHeaders() {
        const headers = {
            'X-WP-Nonce': PD_SESSIONS_NONCE
        };
        return headers;
    }

    async function toFetchError(response) {
        let detail = '';
        try {
            const data = await response.json();
            if (data && data.message) {
                detail = data.message;
            }
        } catch (error) {
            detail = response.statusText;
        }
        const err = new Error(detail || 'Request failed');
        err.status = response.status;
        return err;
    }

    function escapeHtml(value) {
        if (value === null || value === undefined) {
            return '';
        }
        const map = {
            "&": "&amp;",
            "<": "&lt;",
            ">": "&gt;",
            '"': "&quot;",
            "'": "&#39;"
        };
        return value.toString().replace(/[&<"']/g, (char) => map[char] || char);
    }


    function setTableMessage(message) {
        const tbody = document.getElementById('sessionsTableBody');
        if (!tbody) {
            return;
        }
        const safeMessage = escapeHtml(message || '');
        tbody.innerHTML = `
            <tr>
                <td colspan="10" style="text-align:center; padding:2rem; color:#6b7280;">${safeMessage}</td>
            </tr>
        `;
    }



    function renderSessions() {
        const tbody = document.getElementById('sessionsTableBody');
        if (!tbody) {
            return;
        }
        if (sessionsState.filtered.length === 0) {
            setTableMessage('No sessions found matching your criteria.');
            return;
        }

        const rows = sessionsState.filtered.map((session) => {
            const displayLength = session.length ? `${session.length}m` : '';
            const safeValues = {
                date: escapeHtml(session.date || ''),
                title: escapeHtml(session.title || ''),
                length: escapeHtml(displayLength),
                stype: escapeHtml(session.stype || ''),
                ceuWeight: escapeHtml(session.ceuWeight || ''),
                ceuConsiderations: escapeHtml(session.ceuConsiderations || ''),
                qualifyForCeus: escapeHtml(session.qualifyForCeus || ''),
                eventType: escapeHtml(session.eventType || ''),
                presenters: escapeHtml(session.presenters || '')
            };

            const members = Array.isArray(session.members) && session.members.length > 0
                ? session.members.map((member) => {
                    const name = escapeHtml(member.name ? member.name.toString() : '');
                    const email = escapeHtml(member.email ? member.email.toString() : '');
                    return `<li><span class="member-name">${name}</span><span class="member-email">${email}</span></li>`;
                }).join('')
                : '<li class="no-members">No members yet.</li>';

            const memberRowId = `member-row-${session.rowKey}`;
            const rowClick = session.id ? `goToSessionProfile(${session.id})` : '';
            const cellAttr = session.id ? `onclick="${rowClick}"` : '';

            return `
            <tr class="session-row" style="cursor:pointer;">
                <td ${cellAttr}>${safeValues.date}</td>
                <td ${cellAttr} style="font-weight:600;">${safeValues.title}</td>
                <td ${cellAttr}>${safeValues.length}</td>
                <td ${cellAttr}>${safeValues.stype}</td>
                <td ${cellAttr}>${safeValues.ceuWeight}</td>
                <td ${cellAttr}>${safeValues.ceuConsiderations}</td>
                <td ${cellAttr}>${safeValues.qualifyForCeus}</td>
                <td ${cellAttr}>${safeValues.eventType}</td>
                <td ${cellAttr}>${safeValues.presenters}</td>
                <td>
                    <span class="details-dropdown" data-row-key="${session.rowKey}" onclick="toggleMemberDropdown(event, '${session.rowKey}')">
                        <svg class="dropdown-icon" width="18" height="18" fill="none" stroke="#e11d48" stroke-width="2" viewBox="0 0 24 24" style="vertical-align:middle; margin-right:4px;"><path d="M6 9l6 6 6-6"/></svg>
                        Details
                    </span>
                </td>
            </tr>
            <tr class="member-row" id="${memberRowId}" style="display:none;">
                <td colspan="10" style="background:#fef2f2; padding:0; border-top:1px solid #fecaca;">
                    <div class="member-list-block">
                        <ul>${members}</ul>
                    </div>
                </td>
            </tr>
            `;
        }).join('');

        tbody.innerHTML = rows;
    }




    function sortSessions(key) {
        if (sessionSortKey === key) {
            sessionSortAsc = !sessionSortAsc;
        } else {
            sessionSortKey = key;
            sessionSortAsc = true;
        }

        sessionsState.filtered.sort((a, b) => compareSessions(a, b, key, sessionSortAsc));
        updateSessionSortArrows();
        renderSessions();
    }

    function compareSessions(a, b, key, asc) {
        const direction = asc ? 1 : -1;
        if (key === 'date') {
            const valA = a.isoDate ? new Date(a.isoDate).getTime() : 0;
            const valB = b.isoDate ? new Date(b.isoDate).getTime() : 0;
            return direction * (valA - valB);
        }
        if (key === 'length') {
            return direction * ((a.length || 0) - (b.length || 0));
        }
        const valueA = (a[key] || '').toString().toLowerCase();
        const valueB = (b[key] || '').toString().toLowerCase();
        if (valueA < valueB) {
            return -1 * direction;
        }
        if (valueA > valueB) {
            return 1 * direction;
        }
        return 0;
    }

    function filterSessions() {
        const searchInput = document.getElementById('searchInput');
        const term = searchInput ? searchInput.value.toLowerCase() : '';
        if (!term) {
            sessionsState.filtered = [...sessionsState.list];
            renderSessions();
            return;
        }
        sessionsState.filtered = sessionsState.list.filter((session) => {
            return [
                session.date,
                session.title,
                session.stype,
                session.presenters,
                session.eventType
            ].some((value) => (value || '').toString().toLowerCase().includes(term));
        });
        renderSessions();
    }

    function openAddSessionModal() {
        const modal = document.getElementById('addSessionModal');
        if (!modal) {
            return;
        }
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeAddSessionModal() {
        const modal = document.getElementById('addSessionModal');
        const form = document.getElementById('addSessionForm');
        if (modal) {
            modal.classList.remove('active');
        }
        document.body.style.overflow = 'auto';
        if (form) {
            form.reset();
            clearFormNotice(form);
        }
    }

    async function handleAddSession(event) {
        event.preventDefault();
        const form = event.target;
        const submitBtn = form.querySelector('.btn-save');
        const originalHtml = submitBtn ? submitBtn.innerHTML : '';
        if (submitBtn) {
            submitBtn.innerHTML = '<span class="loading"></span> Saving...';
            submitBtn.disabled = true;
        }
        clearFormNotice(form);

        const payload = collectSessionPayload(form);

        try {
            const response = await fetch(PD_SESSIONS_ENDPOINT, {
                method: 'POST',
                headers: {
                    ...buildHeaders(),
                    'Content-Type': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            });
            if (!response.ok) {
                throw await toFetchError(response);
            }
            const data = await response.json();
            const normalized = normalizeSession(data, -1);
            sessionsState.list.unshift(normalized);
            sessionsState.filtered = [...sessionsState.list];
            renderSessions();
            closeAddSessionModal();
            showFormNotice(form, 'Session added successfully.');
        } catch (error) {
            console.error('Failed to add session', error);
            showFormNotice(form, error.message || 'Unable to save the session.', true);
        } finally {
            if (submitBtn) {
                submitBtn.innerHTML = originalHtml;
                submitBtn.disabled = false;
            }
        }
    }

    function collectSessionPayload(form) {
        const getValue = (selector) => {
            const field = form.querySelector(selector);
            return field ? field.value : '';
        };
        const dateValue = getValue('#sessionDate');
        return {
            date: dateValue,
            title: getValue('#sessionTitle'),
            length: parseInt(getValue('#sessionLength'), 10) || 0,
            stype: getValue('#sessionType'),
            ceuWeight: getValue('#ceuWeight'),
            ceuConsiderations: getValue('#ceuConsiderations'),
            qualifyForCeus: getValue('#qualifyForCeus'),
            eventType: getValue('#eventType'),
            presenters: getValue('#presenters')
        };
    }

    function showFormNotice(form, message, isError = false) {
        if (!form) {
            return;
        }
        let note = form.querySelector('.session-form-notice');
        if (!note) {
            note = document.createElement('div');
            note.className = 'session-form-notice';
            form.insertBefore(note, form.firstChild);
        }
        note.textContent = message;
        note.style.color = isError ? '#b91c1c' : '#047857';
        note.style.marginBottom = '1rem';
    }

    function clearFormNotice(form) {
        if (!form) {
            return;
        }
        const note = form.querySelector('.session-form-notice');
        if (note) {
            note.remove();
        }
    }

    function toggleMemberDropdown(event, rowKey) {
        event.stopPropagation();
        document.querySelectorAll('.member-row').forEach((row) => {
            if (!row.id.endsWith(rowKey)) {
                row.style.display = 'none';
            }
        });
        const row = document.getElementById(`member-row-${rowKey}`);
        if (row) {
            row.style.display = row.style.display === 'table-row' ? 'none' : 'table-row';
        }
    }

    function goToSessionProfile(sessionId) {
        if (!sessionId || !PD_SESSIONS_DETAIL_BASE) {
            return;
        }
        const url = `${PD_SESSIONS_DETAIL_BASE}&session=${encodeURIComponent(sessionId)}`;
        window.location.href = url;
    }

    document.addEventListener('DOMContentLoaded', () => {
        updateSessionSortArrows();
        fetchSessions();
        const form = document.getElementById('addSessionForm');
        if (form) {
            form.addEventListener('submit', handleAddSession);
        }
        const modalOverlay = document.getElementById('addSessionModal');
        if (modalOverlay) {
            modalOverlay.addEventListener('click', (event) => {
                if (event.target === modalOverlay) {
                    closeAddSessionModal();
                }
            });
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeAddSessionModal();
        }
        if (event.ctrlKey && (event.key === 'n' || event.key === 'N')) {
            event.preventDefault();
            openAddSessionModal();
        }
    });

    document.addEventListener('click', (event) => {
        const isDropdown = event.target.classList && (event.target.classList.contains('details-dropdown') || event.target.closest('.member-list-block'));
        if (!isDropdown) {
            document.querySelectorAll('.member-row').forEach((row) => {
                row.style.display = 'none';
            });
        }
    });

    window.sortSessions = sortSessions;
    window.filterSessions = filterSessions;
    window.openAddSessionModal = openAddSessionModal;
    window.closeAddSessionModal = closeAddSessionModal;
    window.toggleMemberDropdown = toggleMemberDropdown;
    window.goToSessionProfile = goToSessionProfile;
    """
).strip() + "\n"
PD_SESSION_PAGE_JS = dedent(
    """
    const PDSessionConfig = window.PDSessionpage || {};
    const PD_SESSION_ENDPOINT = (() => {
        const root = (PDSessionConfig.restRoot || '').replace(/\/?$/, '/');
        const route = (PDSessionConfig.sessionsRoute || 'sessions').replace(/^\/?/, '');
        return root + route;
    })();
    const PD_SESSION_NONCE = PDSessionConfig.nonce || '';
    const PD_SESSION_LIST_BASE = PDSessionConfig.listPageBase || '';

    let currentSessionId = null;
    let currentSession = null;

    document.addEventListener('DOMContentLoaded', () => {
        const params = new URLSearchParams(window.location.search);
        const idParam = params.get('session');
        const parsedId = parseInt(idParam, 10);
        if (!Number.isInteger(parsedId) || parsedId <= 0) {
            renderSessionNotFound('Invalid session reference.');
            return;
        }
        currentSessionId = parsedId;
        bindModalControls();
        loadSession(parsedId);
    });

    function bindModalControls() {
        const editButton = document.getElementById('editSessionBtn');
        if (editButton) {
            editButton.addEventListener('click', () => {
                if (!currentSession) {
                    return;
                }
                populateEditForm(currentSession);
                openEditSessionModal();
            });
        }
        const modalOverlay = document.getElementById('editSessionModal');
        if (modalOverlay) {
            modalOverlay.addEventListener('click', (event) => {
                if (event.target === modalOverlay) {
                    closeEditSessionModal();
                }
            });
        }
        const form = document.getElementById('editSessionForm');
        if (form) {
            form.addEventListener('submit', handleEditSubmit);
        }
    }

    async function loadSession(id) {
        setSessionMessage('Loading session…');
        try {
            const response = await fetch(`${PD_SESSION_ENDPOINT}/${id}`, {
                headers: buildHeaders(),
                credentials: 'same-origin'
            });
            if (!response.ok) {
                throw await toFetchError(response);
            }
            const data = await response.json();
            currentSession = normalizeSession(data);
            renderSession(currentSession);
        } catch (error) {
            console.error('Failed to load session', error);
            renderSessionNotFound(error.message || 'Unable to load the requested session.');
        }
    }

    function buildHeaders() {
        return {
            'X-WP-Nonce': PD_SESSION_NONCE
        };
    }

    async function toFetchError(response) {
        let detail = '';
        try {
            const data = await response.json();
            if (data && data.message) {
                detail = data.message;
            }
        } catch (error) {
            detail = response.statusText;
        }
        const err = new Error(detail || 'Request failed');
        err.status = response.status;
        return err;
    }

    function normalizeSession(raw) {
        const isoDate = normalizeToISO(raw.isoDate || raw.date || raw.session_date || '');
        return {
            id: raw.id ?? raw.session_id ?? raw.ID ?? null,
            isoDate,
            date: isoDate ? formatDateForDisplay(isoDate) : (raw.date || raw.session_date || ''),
            title: (raw.title || raw.session_title || '').toString(),
            length: Number.isFinite(raw.length) ? Number(raw.length) : parseInt(raw.length || raw.length_minutes || raw.duration || '0', 10) || 0,
            stype: (raw.stype || raw.session_type || raw.type || '').toString(),
            eventType: (raw.eventType || raw.event_type || '').toString(),
            ceuWeight: (raw.ceuWeight || raw.ceu_weight || '').toString(),
            ceuConsiderations: (raw.ceuConsiderations || raw.ceu_considerations || '').toString(),
            qualifyForCeus: (raw.qualifyForCeus || raw.qualify_for_ceus || raw.qualify || '').toString() || 'No',
            presenters: (raw.presenters || raw.presenter_names || '').toString(),
            members: Array.isArray(raw.members) ? raw.members : []
        };
    }

    function renderSessionNotFound(message) {
        const title = document.querySelector('.main-title');
        if (title) {
            title.textContent = 'Session Not Found';
        }
        const tableContainer = document.querySelector('.table-container');
        if (tableContainer) {
            tableContainer.innerHTML = `<p style="color:#e11d48;">${message}</p>`;
        }
    }

    function setSessionMessage(message) {
        const title = document.querySelector('.session-title');
        if (title) {
            title.textContent = message;
        }
    }

    function renderSession(session) {
        const mainTitle = document.querySelector('.main-title');
        if (mainTitle) {
            mainTitle.textContent = 'Session Profile';
        }
        setText('.session-title', session.title || '');
        setText('.session-date', session.date || '');
        setText('.session-length', session.length ? `${session.length} minutes` : '');
        setText('.session-type', session.stype || '');
        setText('.event-type', session.eventType || '');
        setText('.ceu-weight', session.ceuWeight || '');
        setText('.qualify-ceus', session.qualifyForCeus || '');
        setText('.ceu-considerations', session.ceuConsiderations || '');
        setText('.presenters', session.presenters || '');
    }

    function setText(selector, value) {
        const el = document.querySelector(selector);
        if (el) {
            el.textContent = value;
        }
    }

    function openEditSessionModal() {
        const modal = document.getElementById('editSessionModal');
        if (modal) {
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    }

    function closeEditSessionModal() {
        const modal = document.getElementById('editSessionModal');
        if (modal) {
            modal.classList.remove('active');
        }
        document.body.style.overflow = 'auto';
    }

    function populateEditForm(session) {
        setInputValue('#editSessionDate', session.isoDate ? formatDateForInput(session.isoDate) : '');
        setInputValue('#editSessionLength', session.length || 0);
        setInputValue('#editSessionTitle', session.title || '');
        setInputValue('#editSessionType', session.stype || '');
        setInputValue('#editEventType', session.eventType || '');
        setInputValue('#editCeuWeight', session.ceuWeight || '');
        setSelectValue('#editQualifyForCeus', session.qualifyForCeus || 'No');
        setInputValue('#editCeuConsiderations', session.ceuConsiderations || '');
        setInputValue('#editPresenters', session.presenters || '');
        clearFormNotice(document.getElementById('editSessionForm'));
    }

    function setInputValue(selector, value) {
        const field = document.querySelector(selector);
        if (field) {
            field.value = value;
        }
    }

    function setSelectValue(selector, value) {
        const field = document.querySelector(selector);
        if (field) {
            field.value = value;
        }
    }

    function formatDateForInput(iso) {
        if (!iso) {
            return '';
        }
        const parts = iso.split('-');
        if (parts.length === 3) {
            const [year, month, day] = parts;
            return `${year}-${month.padStart(2, '0')}-${day.padStart(2, '0')}`;
        }
        const parsed = new Date(iso);
        if (!Number.isNaN(parsed.getTime())) {
            const month = (parsed.getMonth() + 1).toString().padStart(2, '0');
            const day = parsed.getDate().toString().padStart(2, '0');
            return `${parsed.getFullYear()}-${month}-${day}`;
        }
        return '';
    }

    function normalizeToISO(value) {
        const trimmed = (value || '').toString().trim();
        if (trimmed === '') {
            return '';
        }
        if (/^\d{4}-\d{2}-\d{2}$/.test(trimmed)) {
            return trimmed;
        }
        if (/^\d{1,2}\/\d{1,2}\/\d{4}$/.test(trimmed)) {
            const [month, day, year] = trimmed.split('/').map((part) => parseInt(part, 10));
            return `${year.toString().padStart(4, '0')}-${month.toString().padStart(2, '0')}-${day.toString().padStart(2, '0')}`;
        }
        const parsed = new Date(trimmed);
        if (!Number.isNaN(parsed.getTime())) {
            const month = (parsed.getMonth() + 1).toString().padStart(2, '0');
            const day = parsed.getDate().toString().padStart(2, '0');
            return `${parsed.getFullYear()}-${month}-${day}`;
        }
        return '';
    }

    function formatDateForDisplay(isoDate) {
        if (!isoDate) {
            return '';
        }
        const parts = isoDate.split('-');
        if (parts.length === 3) {
            const [year, month, day] = parts;
            return `${parseInt(month, 10)}/${parseInt(day, 10)}/${year}`;
        }
        const parsed = new Date(isoDate);
        if (!Number.isNaN(parsed.getTime())) {
            return `${parsed.getMonth() + 1}/${parsed.getDate()}/${parsed.getFullYear()}`;
        }
        return isoDate;
    }

    async function handleEditSubmit(event) {
        event.preventDefault();
        if (!currentSessionId) {
            return;
        }
        const form = event.target;
        clearFormNotice(form);
        const submitBtn = form.querySelector('.btn-save');
        const originalText = submitBtn ? submitBtn.textContent : '';
        if (submitBtn) {
            submitBtn.textContent = 'Saving...';
            submitBtn.disabled = true;
        }

        const payload = collectEditPayload(form);

        try {
            const response = await fetch(`${PD_SESSION_ENDPOINT}/${currentSessionId}`, {
                method: 'PATCH',
                headers: {
                    ...buildHeaders(),
                    'Content-Type': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            });
            if (!response.ok) {
                throw await toFetchError(response);
            }
            const data = await response.json();
            currentSession = normalizeSession(data);
            renderSession(currentSession);
            closeEditSessionModal();
            showFormNotice(form, 'Session updated successfully.');
        } catch (error) {
            console.error('Failed to update session', error);
            showFormNotice(form, error.message || 'Unable to update the session.', true);
        } finally {
            if (submitBtn) {
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            }
        }
    }

    function collectEditPayload(form) {
        const getValue = (selector) => {
            const field = form.querySelector(selector);
            return field ? field.value : '';
        };
        return {
            date: getValue('#editSessionDate'),
            length: parseInt(getValue('#editSessionLength'), 10) || 0,
            title: getValue('#editSessionTitle'),
            stype: getValue('#editSessionType'),
            eventType: getValue('#editEventType'),
            ceuWeight: getValue('#editCeuWeight'),
            qualifyForCeus: getValue('#editQualifyForCeus'),
            ceuConsiderations: getValue('#editCeuConsiderations'),
            presenters: getValue('#editPresenters')
        };
    }

    function showFormNotice(form, message, isError = false) {
        if (!form) {
            return;
        }
        let note = form.querySelector('.session-form-notice');
        if (!note) {
            note = document.createElement('div');
            note.className = 'session-form-notice';
            form.insertBefore(note, form.firstChild);
        }
        note.textContent = message;
        note.style.color = isError ? '#b91c1c' : '#047857';
        note.style.marginBottom = '1rem';
    }

    function clearFormNotice(form) {
        if (!form) {
            return;
        }
        const note = form.querySelector('.session-form-notice');
        if (note) {
            note.remove();
        }
    }

    window.closeEditSessionModal = closeEditSessionModal;
    """
).strip() + "\n"
NEW_SESSIONS_LOCALIZE = (
    "        wp_localize_script(\n"
    "            'PD-admin-sessions-table-js',\n"
    "            'PDSessions',\n"
    "            array(\n"
    "                'restRoot'       => esc_url_raw( rest_url( 'profdev/v1/' ) ),\n"
    "                'sessionsRoute'  => 'sessions',\n"
    "                'nonce'          => wp_create_nonce( 'wp_rest' ),\n"
    "                'detailPageBase' => admin_url( 'admin.php?page=profdef_session_page' )\n"
    "            )\n"
    "        );"
)

NEW_SESSION_PAGE_LOCALIZE = (
    "        wp_localize_script(\n"
    "            'PD-admin-session-page-js',\n"
    "            'PDSessionpage',\n"
    "            array(\n"
    "                'restRoot'      => esc_url_raw( rest_url( 'profdev/v1/' ) ),\n"
    "                'sessionsRoute' => 'sessions',\n"
    "                'nonce'         => wp_create_nonce( 'wp_rest' ),\n"
    "                'listPageBase'  => admin_url( 'admin.php?page=profdef_sessions_table' )\n"
    "            )\n"
    "        );"
)

def write_file(path: Path, content: str, apply: bool) -> bool:
    existing = path.read_text(encoding='utf-8') if path.exists() else None
    if existing == content:
        print(f"UNCHANGED {path.relative_to(ROOT)}")
        return False

    if not apply:
        action = "would create" if existing is None else "would update"
        print(f"DRY-RUN {action} {path.relative_to(ROOT)}")
        return True

    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding='utf-8')
    print(f"UPDATED {path.relative_to(ROOT)}")
    return True


def update_rest_sessions_php(apply: bool) -> bool:
    path = ROOT / 'includes' / 'rest-sessions.php'
    return write_file(path, REST_SESSIONS_PHP, apply)


def update_sessions_js(apply: bool) -> bool:
    path = ROOT / 'js' / 'PD-sessions-table.js'
    return write_file(path, PD_SESSIONS_TABLE_JS, apply)


def update_session_page_js(apply: bool) -> bool:
    path = ROOT / 'js' / 'PD-session-page.js'
    return write_file(path, PD_SESSION_PAGE_JS, apply)


def replace_localize_block(text: str, pattern: str, replacement: str) -> str:
    regex = re.compile(pattern)
    return regex.sub(replacement, text)


def update_main_plugin_php(apply: bool) -> bool:
    path = ROOT / 'Professional_Development.php'
    original = path.read_text(encoding='utf-8')
    updated = original

    if "includes/rest-sessions.php" not in updated:
        updated = updated.replace(
            "require_once plugin_dir_path( __FILE__ ) . 'includes/rest-presenters.php';\n",
            "require_once plugin_dir_path( __FILE__ ) . 'includes/rest-presenters.php';\n"
            "require_once plugin_dir_path( __FILE__ ) . 'includes/rest-sessions.php';\n"
        )

    updated = replace_localize_block(
        updated,
        pattern=r"wp_localize_script\(\s*'PD-admin-sessions-table-js',[\s\S]+?\);",
        replacement=NEW_SESSIONS_LOCALIZE
    )

    updated = replace_localize_block(
        updated,
        pattern=r"wp_localize_script\(\s*'PD-admin-session-page-js',[\s\S]+?\);",
        replacement=NEW_SESSION_PAGE_LOCALIZE
    )

    return write_file(path, updated, apply)


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description='Update sessions REST wiring.')
    parser.add_argument('--apply', action='store_true', help='Write changes to disk')
    return parser.parse_args(argv)


def main(argv: list[str]) -> int:
    args = parse_args(argv)

    touched = False
    touched = update_rest_sessions_php(args.apply) or touched
    touched = update_sessions_js(args.apply) or touched
    touched = update_session_page_js(args.apply) or touched
    touched = update_main_plugin_php(args.apply) or touched

    if not touched:
        print('No changes necessary.')
    elif not args.apply:
        print('Re-run with --apply to write these changes.')

    return 0


if __name__ == '__main__':
    raise SystemExit(main(sys.argv[1:]))
