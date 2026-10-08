<?php

/**
 * Identity Module - Reusable Partials
 * 
 * @package EduTrack
 * @subpackage Identity
 * @version 2.0
 * @filepath public/identity/includes/partials.php
 */

/**
 * Render a status badge
 */
function renderStatusBadge($status, $class = '')
{
    $labels = [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'pending' => 'Pending',
        'suspended' => 'Suspended',
        'archived' => 'Archived',
        'graduated' => 'Graduated',
        'verified' => 'Verified',
        'rejected' => 'Rejected',
        'expired' => 'Expired'
    ];

    $classes = [
        'active' => 'badge-status active',
        'inactive' => 'badge-status inactive',
        'pending' => 'badge-status pending',
        'suspended' => 'badge-status suspended',
        'archived' => 'badge-status archived',
        'graduated' => 'badge-status active',
        'verified' => 'badge-status active',
        'rejected' => 'badge-status expired',
        'expired' => 'badge-status expired'
    ];

    $label = $labels[$status] ?? ucfirst($status);
    $badgeClass = $classes[$status] ?? 'badge-status pending';

    return '<span class="' . $badgeClass . ' ' . $class . '">' . $label . '</span>';
}

/**
 * Render a person type badge
 */
function renderTypeBadge($type)
{
    $labels = [
        'student' => 'Student',
        'teacher' => 'Teacher',
        'staff' => 'Staff',
        'admin' => 'Admin',
        'parent' => 'Parent',
        'guardian' => 'Guardian',
        'alumni' => 'Alumni',
        'visitor' => 'Visitor',
        'contractor' => 'Contractor',
        'volunteer' => 'Volunteer'
    ];

    $classes = [
        'student' => 'badge-type student',
        'teacher' => 'badge-type teacher',
        'staff' => 'badge-type staff',
        'admin' => 'badge-type admin',
        'parent' => 'badge-type parent',
        'guardian' => 'badge-type guardian',
        'alumni' => 'badge-type student',
        'visitor' => 'badge-type staff',
        'contractor' => 'badge-type staff',
        'volunteer' => 'badge-type staff'
    ];

    $label = $labels[$type] ?? ucfirst($type);
    $badgeClass = $classes[$type] ?? 'badge-type staff';

    return '<span class="' . $badgeClass . '">' . $label . '</span>';
}

/**
 * Render a relationship badge
 */
function renderRelationshipBadge($type)
{
    $labels = [
        'parent' => 'Parent',
        'guardian' => 'Guardian',
        'sibling' => 'Sibling',
        'emergency_contact' => 'Emergency Contact',
        'spouse' => 'Spouse',
        'child' => 'Child',
        'other' => 'Other'
    ];

    $classes = [
        'parent' => 'relationship-badge parent',
        'guardian' => 'relationship-badge guardian',
        'sibling' => 'relationship-badge sibling',
        'emergency_contact' => 'relationship-badge emergency',
        'spouse' => 'relationship-badge other',
        'child' => 'relationship-badge other',
        'other' => 'relationship-badge other'
    ];

    $label = $labels[$type] ?? ucfirst($type);
    $badgeClass = $classes[$type] ?? 'relationship-badge other';

    return '<span class="' . $badgeClass . '">' . $label . '</span>';
}

/**
 * Render a person avatar
 */
function renderPersonAvatar($person, $size = 'md')
{
    $sizes = [
        'sm' => '36px',
        'md' => '48px',
        'lg' => '72px',
        'xl' => '96px'
    ];

    $fontSizes = [
        'sm' => '14px',
        'md' => '18px',
        'lg' => '28px',
        'xl' => '36px'
    ];

    $sizePx = $sizes[$size] ?? '48px';
    $fontSize = $fontSizes[$size] ?? '18px';

    $name = $person['first_name'] ?? '';
    if (!empty($person['last_name'])) {
        $name .= ' ' . $person['last_name'];
    }
    $name = trim($name);
    $initials = getInitials($name);

    $color = !empty($person['id']) ? getAvatarColor($person['id']) : '#4facfe';

    return '<div class="person-avatar" style="width:' . $sizePx . ';height:' . $sizePx . ';border-radius:50%;background:' . $color . ';color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:' . $fontSize . ';flex-shrink:0;">' . $initials . '</div>';
}

/**
 * Get initials from name
 */
function getInitials($name)
{
    if (empty($name)) return '?';
    $parts = explode(' ', trim($name));
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts) - 1], 0, 1));
    }
    return strtoupper(substr($name, 0, 1));
}

/**
 * Get avatar color based on ID
 */
function getAvatarColor($id)
{
    $colors = [
        '#4facfe',
        '#43e97b',
        '#fa709a',
        '#a18cd1',
        '#ff6b6b',
        '#fdcb6e',
        '#fd79a8',
        '#00cec9',
        '#0984e3',
        '#6c5ce7',
        '#e17055',
        '#00b894',
        '#fdcb6e',
        '#e84393',
        '#00a8ff'
    ];
    return $colors[$id % count($colors)];
}

/**
 * Format date
 */
function formatDate($dateStr, $format = 'M d, Y')
{
    if (empty($dateStr)) return 'N/A';
    $date = new DateTime($dateStr);
    return $date->format($format);
}

/**
 * Format datetime
 */
function formatDateTime($dateStr)
{
    if (empty($dateStr)) return 'N/A';
    $date = new DateTime($dateStr);
    return $date->format('M d, Y h:i A');
}

/**
 * Get full name from person array
 */
function getFullName($person)
{
    $name = $person['first_name'] ?? '';
    if (!empty($person['middle_name'])) {
        $name .= ' ' . $person['middle_name'];
    }
    if (!empty($person['last_name'])) {
        $name .= ' ' . $person['last_name'];
    }
    return trim($name) ?: 'Unknown';
}

/**
 * Get person type label
 */
function getPersonTypeLabel($type)
{
    $labels = [
        'student' => 'Student',
        'teacher' => 'Teacher',
        'staff' => 'Staff',
        'admin' => 'Administrator',
        'parent' => 'Parent',
        'guardian' => 'Guardian',
        'alumni' => 'Alumni',
        'visitor' => 'Visitor',
        'contractor' => 'Contractor',
        'volunteer' => 'Volunteer'
    ];
    return $labels[$type] ?? ucfirst($type);
}

/**
 * Render a person card (compact)
 */
function renderPersonCard($person)
{
    if (empty($person)) return '';

    $name = getFullName($person);
    $avatar = renderPersonAvatar($person, 'md');
    $badge = renderTypeBadge($person['person_type'] ?? '');
    $status = renderStatusBadge($person['status'] ?? 'pending');
    $details = [];

    if (!empty($person['person_number'])) {
        $details[] = '<i class="fas fa-hashtag"></i> ' . $person['person_number'];
    }
    if (!empty($person['primary_phone'])) {
        $details[] = '<i class="fas fa-phone"></i> ' . $person['primary_phone'];
    }
    if (!empty($person['primary_email'])) {
        $details[] = '<i class="fas fa-envelope"></i> ' . $person['primary_email'];
    }

    $detailsHtml = implode(' ', array_map(function ($detail) {
        return '<span class="detail-item">' . $detail . '</span>';
    }, $details));

    return '
        <div class="person-card d-flex align-items-center gap-3 p-3 bg-light rounded">
            ' . $avatar . '
            <div class="flex-grow-1">
                <div class="fw-semibold">' . $name . '</div>
                <div class="d-flex flex-wrap gap-2 align-items-center mt-1">
                    ' . $badge . '
                    ' . $status . '
                    ' . $detailsHtml . '
                </div>
            </div>
        </div>
    ';
}

/**
 * Render empty state
 */
function renderEmptyState($icon, $title, $message, $action = null)
{
    $actionHtml = '';
    if ($action) {
        $actionHtml = '<a href="' . ($action['url'] ?? '#') . '" class="btn btn-primary mt-2">
            <i class="fas ' . ($action['icon'] ?? 'fa-plus') . ' me-2"></i> ' . ($action['label'] ?? 'Add') . '
        </a>';
    }

    return '
        <div class="empty-state text-center py-5">
            <div class="empty-icon"><i class="fas ' . $icon . '"></i></div>
            <h5 class="fw-bold mt-3">' . $title . '</h5>
            <p class="text-muted">' . $message . '</p>
            ' . $actionHtml . '
        </div>
    ';
}

/**
 * Render a loading spinner
 */
function renderLoading($message = 'Loading...')
{
    return '
        <div class="text-center py-4 text-muted">
            <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
            ' . $message . '
        </div>
    ';
}

/**
 * Render a table with actions
 */
function renderActionButtons($actions)
{
    $html = '<div class="btn-group btn-group-sm" role="group">';
    foreach ($actions as $action) {
        $html .= '<a href="' . ($action['url'] ?? '#') . '" class="btn ' . ($action['class'] ?? 'btn-outline-secondary') . '" title="' . ($action['title'] ?? '') . '">
            <i class="fas ' . ($action['icon'] ?? 'fa-edit') . '"></i>
        </a>';
    }
    $html .= '</div>';
    return $html;
}
