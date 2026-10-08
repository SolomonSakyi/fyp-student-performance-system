<?php
/**
 * Audience Service
 * Manages login audience selection and validation
 *
 * @package EduTrack
 * @subpackage Services\Auth
 * @version 1.0
 * @filepath app/services/Auth/AudienceService.php
 */

class AudienceService
{
    private $db;
    private $tenantContext;
    private $validAudiences = ['staff', 'student', 'admin'];

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->tenantContext = TenantContext::getInstance();
    }

    /**
     * Get all valid audiences
     */
    public function getValidAudiences(): array
    {
        return $this->validAudiences;
    }

    /**
     * Get available audiences for current tenant
     */
    public function getAvailableAudiences(): array
    {
        $audiences = $this->validAudiences;

        // Check tenant settings for disabled audiences
        $tenantId = $this->tenantContext->getTenantId();
        if ($tenantId) {
            try {
                $disabled = $this->db->getValue(
                    "SELECT setting_value FROM tenant_settings 
                     WHERE tenant_id = ? AND setting_key = 'disabled_login_audiences'",
                    [$tenantId]
                );

                if ($disabled) {
                    $disabledArray = array_map('trim', explode(',', $disabled));
                    $audiences = array_diff($audiences, $disabledArray);
                }
            } catch (Exception $e) {
                error_log('Error getting available audiences: ' . $e->getMessage());
            }
        }

        return array_values($audiences);
    }

    /**
     * Validate audience is valid
     */
    public function isValidAudience(string $audience): bool
    {
        return in_array($audience, $this->validAudiences);
    }

    /**
     * Validate audience is available for current tenant
     */
    public function isAudienceAvailable(string $audience): bool
    {
        return in_array($audience, $this->getAvailableAudiences());
    }

    /**
     * Get audience display name
     */
    public function getAudienceDisplayName(string $audience): string
    {
        $names = [
            'staff' => 'Staff',
            'student' => 'Student',
            'admin' => 'Administrator'
        ];

        return $names[$audience] ?? ucfirst($audience);
    }

    /**
     * Get audience icon
     */
    public function getAudienceIcon(string $audience): string
    {
        $icons = [
            'staff' => 'fa-user-tie',
            'student' => 'fa-user-graduate',
            'admin' => 'fa-user-cog'
        ];

        return $icons[$audience] ?? 'fa-user';
    }

    /**
     * Get audience login form fields
     */
    public function getAudienceFields(string $audience): array
    {
        $fields = [
            'staff' => [
                'identifier' => [
                    'label' => 'Staff Number or Email',
                    'type' => 'text',
                    'placeholder' => 'Enter your staff number or email',
                    'required' => true
                ],
                'password' => [
                    'label' => 'Password',
                    'type' => 'password',
                    'placeholder' => 'Enter your password',
                    'required' => true
                ]
            ],
            'student' => [
                'identifier' => [
                    'label' => 'Student Number',
                    'type' => 'text',
                    'placeholder' => 'Enter your student number',
                    'required' => true
                ],
                'password' => [
                    'label' => 'Password',
                    'type' => 'password',
                    'placeholder' => 'Enter your password',
                    'required' => true
                ]
            ],
            'admin' => [
                'identifier' => [
                    'label' => 'Username or Email',
                    'type' => 'text',
                    'placeholder' => 'Enter your username or email',
                    'required' => true
                ],
                'password' => [
                    'label' => 'Password',
                    'type' => 'password',
                    'placeholder' => 'Enter your password',
                    'required' => true
                ]
            ]
        ];

        return $fields[$audience] ?? $fields['staff'];
    }

    /**
     * Get audience dashboard URL
     */
    public function getAudienceDashboard(string $audience): string
    {
        $dashboards = [
            'staff' => '/tenant/staff/dashboard.php',
            'student' => '/tenant/student/dashboard.php',
            'admin' => '/tenant/admin/dashboard.php'
        ];

        return $dashboards[$audience] ?? '/tenant/dashboard.php';
    }

    /**
     * Get audience authentication handler
     */
    public function getAudienceHandler(string $audience): string
    {
        $handlers = [
            'staff' => '/platform/auth/tenant-login.php',
            'student' => '/platform/auth/tenant-login.php',
            'admin' => '/platform/auth/tenant-login.php'
        ];

        return $handlers[$audience] ?? '/platform/auth/tenant-login.php';
    }

    /**
     * Get audience-specific error messages
     */
    public function getAudienceErrorMessages(string $audience): array
    {
        $messages = [
            'staff' => [
                'invalid_credentials' => 'Invalid staff number or password.',
                'not_staff' => 'You are not registered as staff.',
                'inactive' => 'Your staff account is inactive.',
                'wrong_tenant' => 'You are not authorized for this school.',
            ],
            'student' => [
                'invalid_credentials' => 'Invalid student number or password.',
                'not_student' => 'You are not registered as a student.',
                'inactive' => 'Your student account is inactive.',
                'wrong_tenant' => 'You are not enrolled in this school.',
            ],
            'admin' => [
                'invalid_credentials' => 'Invalid username or password.',
                'not_admin' => 'You do not have admin privileges.',
                'inactive' => 'Your admin account is inactive.',
                'wrong_tenant' => 'You are not authorized for this school.',
            ]
        ];

        return $messages[$audience] ?? [
            'invalid_credentials' => 'Invalid credentials.',
            'not_authorized' => 'You are not authorized for this audience.',
            'inactive' => 'Your account is inactive.',
            'wrong_tenant' => 'You are not authorized for this tenant.',
        ];
    }
}<?php
/**
 * Audience Service
 * Manages login audience selection and validation
 *
 * @package EduTrack
 * @subpackage Services\Auth
 * @version 1.0
 * @filepath app/services/Auth/AudienceService.php
 */

class AudienceService
{
    private $db;
    private $tenantContext;
    private $validAudiences = ['staff', 'student', 'admin'];

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->tenantContext = TenantContext::getInstance();
    }

    /**
     * Get all valid audiences
     */
    public function getValidAudiences(): array
    {
        return $this->validAudiences;
    }

    /**
     * Get available audiences for current tenant
     */
    public function getAvailableAudiences(): array
    {
        $audiences = $this->validAudiences;

        // Check tenant settings for disabled audiences
        $tenantId = $this->tenantContext->getTenantId();
        if ($tenantId) {
            try {
                $disabled = $this->db->getValue(
                    "SELECT setting_value FROM tenant_settings 
                     WHERE tenant_id = ? AND setting_key = 'disabled_login_audiences'",
                    [$tenantId]
                );

                if ($disabled) {
                    $disabledArray = array_map('trim', explode(',', $disabled));
                    $audiences = array_diff($audiences, $disabledArray);
                }
            } catch (Exception $e) {
                error_log('Error getting available audiences: ' . $e->getMessage());
            }
        }

        return array_values($audiences);
    }

    /**
     * Validate audience is valid
     */
    public function isValidAudience(string $audience): bool
    {
        return in_array($audience, $this->validAudiences);
    }

    /**
     * Validate audience is available for current tenant
     */
    public function isAudienceAvailable(string $audience): bool
    {
        return in_array($audience, $this->getAvailableAudiences());
    }

    /**
     * Get audience display name
     */
    public function getAudienceDisplayName(string $audience): string
    {
        $names = [
            'staff' => 'Staff',
            'student' => 'Student',
            'admin' => 'Administrator'
        ];

        return $names[$audience] ?? ucfirst($audience);
    }

    /**
     * Get audience icon
     */
    public function getAudienceIcon(string $audience): string
    {
        $icons = [
            'staff' => 'fa-user-tie',
            'student' => 'fa-user-graduate',
            'admin' => 'fa-user-cog'
        ];

        return $icons[$audience] ?? 'fa-user';
    }

    /**
     * Get audience login form fields
     */
    public function getAudienceFields(string $audience): array
    {
        $fields = [
            'staff' => [
                'identifier' => [
                    'label' => 'Staff Number or Email',
                    'type' => 'text',
                    'placeholder' => 'Enter your staff number or email',
                    'required' => true
                ],
                'password' => [
                    'label' => 'Password',
                    'type' => 'password',
                    'placeholder' => 'Enter your password',
                    'required' => true
                ]
            ],
            'student' => [
                'identifier' => [
                    'label' => 'Student Number',
                    'type' => 'text',
                    'placeholder' => 'Enter your student number',
                    'required' => true
                ],
                'password' => [
                    'label' => 'Password',
                    'type' => 'password',
                    'placeholder' => 'Enter your password',
                    'required' => true
                ]
            ],
            'admin' => [
                'identifier' => [
                    'label' => 'Username or Email',
                    'type' => 'text',
                    'placeholder' => 'Enter your username or email',
                    'required' => true
                ],
                'password' => [
                    'label' => 'Password',
                    'type' => 'password',
                    'placeholder' => 'Enter your password',
                    'required' => true
                ]
            ]
        ];

        return $fields[$audience] ?? $fields['staff'];
    }

    /**
     * Get audience dashboard URL
     */
    public function getAudienceDashboard(string $audience): string
    {
        $dashboards = [
            'staff' => '/tenant/staff/dashboard.php',
            'student' => '/tenant/student/dashboard.php',
            'admin' => '/tenant/admin/dashboard.php'
        ];

        return $dashboards[$audience] ?? '/tenant/dashboard.php';
    }

    /**
     * Get audience authentication handler
     */
    public function getAudienceHandler(string $audience): string
    {
        $handlers = [
            'staff' => '/platform/auth/tenant-login.php',
            'student' => '/platform/auth/tenant-login.php',
            'admin' => '/platform/auth/tenant-login.php'
        ];

        return $handlers[$audience] ?? '/platform/auth/tenant-login.php';
    }

    /**
     * Get audience-specific error messages
     */
    public function getAudienceErrorMessages(string $audience): array
    {
        $messages = [
            'staff' => [
                'invalid_credentials' => 'Invalid staff number or password.',
                'not_staff' => 'You are not registered as staff.',
                'inactive' => 'Your staff account is inactive.',
                'wrong_tenant' => 'You are not authorized for this school.',
            ],
            'student' => [
                'invalid_credentials' => 'Invalid student number or password.',
                'not_student' => 'You are not registered as a student.',
                'inactive' => 'Your student account is inactive.',
                'wrong_tenant' => 'You are not enrolled in this school.',
            ],
            'admin' => [
                'invalid_credentials' => 'Invalid username or password.',
                'not_admin' => 'You do not have admin privileges.',
                'inactive' => 'Your admin account is inactive.',
                'wrong_tenant' => 'You are not authorized for this school.',
            ]
        ];

        return $messages[$audience] ?? [
            'invalid_credentials' => 'Invalid credentials.',
            'not_authorized' => 'You are not authorized for this audience.',
            'inactive' => 'Your account is inactive.',
            'wrong_tenant' => 'You are not authorized for this tenant.',
        ];
    }
}