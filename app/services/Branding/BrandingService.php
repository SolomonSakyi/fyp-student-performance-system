<?php

/**
 * Branding Service
 * Manages tenant and school branding for login pages
 *
 * @package EduTrack
 * @subpackage Services\Branding
 * @version 1.0
 * @filepath app/services/Branding/BrandingService.php
 */

class BrandingService
{
    private $db;
    private $tenantContext;
    private $cache = [];

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->tenantContext = TenantContext::getInstance();
    }

    /**
     * Get login branding for current tenant/school context
     *
     * @return array
     */
    public function getLoginBranding(): array
    {
        $tenantId = $this->tenantContext->getTenantId();
        $schoolId = $this->tenantContext->getSchoolId();
        $domainName = $this->tenantContext->getDomainName();

        // Check cache
        $cacheKey = "branding_{$tenantId}_{$schoolId}";
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        // Get tenant branding
        $tenantBranding = $this->getTenantBranding($tenantId);

        // Get school branding (overrides tenant)
        $schoolBranding = $this->getSchoolBranding($schoolId);

        // Merge (school overrides tenant)
        $branding = array_merge($tenantBranding, $schoolBranding);

        // Add computed values
        $branding['tenant_name'] = $this->tenantContext->getTenantName();
        $branding['school_name'] = $this->tenantContext->getSchoolName() ?? $branding['tenant_name'];
        $branding['domain_name'] = $domainName;
        $branding['login_audiences'] = $this->getAvailableAudiences();

        // Cache the result
        $this->cache[$cacheKey] = $branding;

        return $branding;
    }

    /**
     * Get tenant branding
     */
    private function getTenantBranding(int $tenantId): array
    {
        $default = [
            'tenant_logo' => null,
            'tenant_logo_url' => null,
            'tenant_primary_color' => '#4facfe',
            'tenant_secondary_color' => '#00f2fe',
            'tenant_background_color' => '#f0f2f5',
            'tenant_login_background' => null,
            'tenant_motto' => null,
            'tenant_welcome_message' => 'Welcome to EduTrack',
            'tenant_support_email' => null,
            'tenant_support_phone' => null,
            'tenant_favicon' => null,
        ];

        try {
            $settings = $this->db->fetchAll(
                "SELECT setting_key, setting_value 
                 FROM tenant_settings 
                 WHERE tenant_id = ? AND setting_key LIKE 'branding_%'",
                [$tenantId]
            );

            foreach ($settings as $setting) {
                $key = str_replace('branding_', 'tenant_', $setting['setting_key']);
                $default[$key] = $setting['setting_value'];
            }

            // If logo is stored as file path, generate URL
            if (!empty($default['tenant_logo'])) {
                $default['tenant_logo_url'] = $this->getLogoUrl($default['tenant_logo'], 'tenant');
            }
        } catch (Exception $e) {
            error_log('Error loading tenant branding: ' . $e->getMessage());
        }

        return $default;
    }

    /**
     * Get school branding
     */
    private function getSchoolBranding(?int $schoolId): array
    {
        $default = [
            'school_logo' => null,
            'school_logo_url' => null,
            'school_primary_color' => null,
            'school_secondary_color' => null,
            'school_background_color' => null,
            'school_login_background' => null,
            'school_motto' => null,
            'school_welcome_message' => null,
            'school_support_email' => null,
            'school_support_phone' => null,
            'school_favicon' => null,
        ];

        if (!$schoolId) {
            return $default;
        }

        try {
            $settings = $this->db->fetchAll(
                "SELECT setting_key, setting_value 
                 FROM school_settings 
                 WHERE school_id = ? AND setting_key LIKE 'branding_%'",
                [$schoolId]
            );

            foreach ($settings as $setting) {
                $key = str_replace('branding_', 'school_', $setting['setting_key']);
                $default[$key] = $setting['setting_value'];
            }

            // If logo is stored as file path, generate URL
            if (!empty($default['school_logo'])) {
                $default['school_logo_url'] = $this->getLogoUrl($default['school_logo'], 'school');
            }
        } catch (Exception $e) {
            error_log('Error loading school branding: ' . $e->getMessage());
        }

        return $default;
    }

    /**
     * Get logo URL
     */
    private function getLogoUrl(string $logoPath, string $type): string
    {
        // If already a full URL, return as-is
        if (filter_var($logoPath, FILTER_VALIDATE_URL)) {
            return $logoPath;
        }

        // If path starts with /, it's a public path
        if (strpos($logoPath, '/') === 0) {
            return $logoPath;
        }

        // Otherwise, assume it's in storage
        $baseUrl = $_ENV['APP_URL'] ?? 'http://localhost';
        return $baseUrl . '/storage/' . $type . '/logos/' . $logoPath;
    }

    /**
     * Get available login audiences
     */
    private function getAvailableAudiences(): array
    {
        $audiences = ['staff', 'student', 'admin'];

        // Check if tenant has disabled any audiences
        $tenantId = $this->tenantContext->getTenantId();
        if ($tenantId) {
            try {
                $disabled = $this->db->getValue(
                    "SELECT setting_value FROM tenant_settings 
                     WHERE tenant_id = ? AND setting_key = 'disabled_login_audiences'",
                    [$tenantId]
                );

                if ($disabled) {
                    $disabledArray = explode(',', $disabled);
                    $audiences = array_diff($audiences, $disabledArray);
                }
            } catch (Exception $e) {
                // Ignore
            }
        }

        return array_values($audiences);
    }

    /**
     * Get login page CSS based on branding
     */
    public function getLoginCSS(array $branding): string
    {
        $primaryColor = $branding['school_primary_color'] ?? $branding['tenant_primary_color'] ?? '#4facfe';
        $secondaryColor = $branding['school_secondary_color'] ?? $branding['tenant_secondary_color'] ?? '#00f2fe';
        $backgroundColor = $branding['school_background_color'] ?? $branding['tenant_background_color'] ?? '#f0f2f5';

        return "
            :root {
                --brand-primary: {$primaryColor};
                --brand-secondary: {$secondaryColor};
                --brand-background: {$backgroundColor};
            }
            
            body {
                background: var(--brand-background);
            }
            
            .btn-login {
                background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-secondary) 100%);
            }
            
            .btn-login:hover {
                box-shadow: 0 6px 20px rgba({$this->hexToRgb($primaryColor)}, 0.4);
            }
            
            .login-container .school-logo .logo-placeholder {
                background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-secondary) 100%);
            }
            
            .login-container .audience-selector .audience-btn.active {
                color: var(--brand-primary);
            }
            
            .login-container .form-control:focus {
                border-color: var(--brand-primary);
                box-shadow: 0 0 0 4px rgba({$this->hexToRgb($primaryColor)}, 0.1);
            }
            
            .login-container .tenant-badge i {
                color: var(--brand-primary);
            }
        ";
    }

    /**
     * Convert hex color to RGB
     */
    private function hexToRgb(string $hex): string
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return "$r, $g, $b";
    }

    /**
     * Get login page HTML head content (meta tags, favicon, etc.)
     */
    public function getLoginHead(array $branding): string
    {
        $html = '';

        // Favicon
        $favicon = $branding['school_favicon'] ?? $branding['tenant_favicon'] ?? null;
        if ($favicon) {
            $faviconUrl = $this->getLogoUrl($favicon, 'favicon');
            $html .= '<link rel="icon" href="' . htmlspecialchars($faviconUrl) . '">' . "\n";
        }

        // Additional meta tags
        $html .= '<meta name="brand-primary" content="' . htmlspecialchars($branding['school_primary_color'] ?? $branding['tenant_primary_color'] ?? '#4facfe') . '">' . "\n";

        return $html;
    }

    /**
     * Get login page welcome message
     */
    public function getWelcomeMessage(array $branding): string
    {
        $message = $branding['school_welcome_message'] ?? $branding['tenant_welcome_message'] ?? 'Welcome to EduTrack';
        return htmlspecialchars($message);
    }

    /**
     * Get login page motto/tagline
     */
    public function getMotto(array $branding): string
    {
        $motto = $branding['school_motto'] ?? $branding['tenant_motto'] ?? null;
        return $motto ? htmlspecialchars($motto) : '';
    }

    /**
     * Get support contact information
     */
    public function getSupportInfo(array $branding): array
    {
        return [
            'email' => $branding['school_support_email'] ?? $branding['tenant_support_email'] ?? null,
            'phone' => $branding['school_support_phone'] ?? $branding['tenant_support_phone'] ?? null,
        ];
    }
}
