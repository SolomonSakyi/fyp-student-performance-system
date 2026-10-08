<?php
/**
 * Numbering Helper
 * Auto-generates student admission numbers and staff numbers
 */
class NumberingHelper
{
    private $db;
    private $config;
    
    public function __construct($db)
    {
        $this->db = $db;
        $this->loadConfig();
    }
    
    /**
     * Load numbering configuration
     */
    private function loadConfig()
    {
        $this->config = $this->db->fetchOne("
            SELECT * FROM numbering_config 
            WHERE school_id = 1 AND is_active = 1
        ");
        
        if (!$this->config) {
            // Default config if not found
            $this->config = [
                'student_prefix' => 'CCI',
                'student_year_format' => 'full',
                'student_include_year' => 1,
                'student_padding_length' => 4,
                'student_current_number' => 0,
                'student_separator' => '/',
                'staff_prefix' => 'STF',
                'staff_year_format' => 'full',
                'staff_include_year' => 1,
                'staff_padding_length' => 4,
                'staff_current_number' => 0,
                'staff_separator' => '/'
            ];
        }
    }
    
    /**
     * Generate next student admission number
     */
    public function generateStudentNumber()
    {
        $year = $this->getCurrentYear();
        $prefix = $this->config['student_prefix'];
        $separator = $this->config['student_separator'];
        $padding = $this->config['student_padding_length'];
        $includeYear = $this->config['student_include_year'];
        
        // Get next number
        $nextNumber = $this->getNextStudentNumber();
        
        // Format number with padding
        $formattedNumber = str_pad($nextNumber, $padding, '0', STR_PAD_LEFT);
        
        // Build the admission number
        if ($includeYear) {
            return $prefix . $separator . $year . $separator . $formattedNumber;
        } else {
            return $prefix . $separator . $formattedNumber;
        }
    }
    
    /**
     * Generate next staff number
     */
    public function generateStaffNumber()
    {
        $year = $this->getCurrentYear();
        $prefix = $this->config['staff_prefix'];
        $separator = $this->config['staff_separator'];
        $padding = $this->config['staff_padding_length'];
        $includeYear = $this->config['staff_include_year'];
        
        // Get next number
        $nextNumber = $this->getNextStaffNumber();
        
        // Format number with padding
        $formattedNumber = str_pad($nextNumber, $padding, '0', STR_PAD_LEFT);
        
        // Build the staff number
        if ($includeYear) {
            return $prefix . $separator . $year . $separator . $formattedNumber;
        } else {
            return $prefix . $separator . $formattedNumber;
        }
    }
    
    /**
     * Get next student number and increment
     */
    private function getNextStudentNumber()
    {
        // Get current number
        $current = $this->config['student_current_number'];
        $next = $current + 1;
        
        // Update the config
        $this->db->query("
            UPDATE numbering_config 
            SET student_current_number = ? 
            WHERE school_id = 1
        ", [$next]);
        
        // Reload config
        $this->loadConfig();
        
        return $next;
    }
    
    /**
     * Get next staff number and increment
     */
    private function getNextStaffNumber()
    {
        // Get current number
        $current = $this->config['staff_current_number'];
        $next = $current + 1;
        
        // Update the config
        $this->db->query("
            UPDATE numbering_config 
            SET staff_current_number = ? 
            WHERE school_id = 1
        ", [$next]);
        
        // Reload config
        $this->loadConfig();
        
        return $next;
    }
    
    /**
     * Get current year
     */
    private function getCurrentYear()
    {
        return date('Y');
    }
    
    /**
     * Update numbering configuration
     */
    public function updateConfig($data)
    {
        $fields = [];
        $params = [];
        
        foreach ($data as $key => $value) {
            if (in_array($key, ['student_prefix', 'staff_prefix', 'student_separator', 'staff_separator'])) {
                $fields[] = "$key = ?";
                $params[] = $value;
            }
            if (in_array($key, ['student_padding_length', 'staff_padding_length'])) {
                $fields[] = "$key = ?";
                $params[] = (int)$value;
            }
            if (in_array($key, ['student_include_year', 'staff_include_year'])) {
                $fields[] = "$key = ?";
                $params[] = $value ? 1 : 0;
            }
        }
        
        if (!empty($fields)) {
            $sql = "UPDATE numbering_config SET " . implode(', ', $fields) . " WHERE school_id = 1";
            $this->db->query($sql, $params);
            $this->loadConfig();
            return true;
        }
        
        return false;
    }
    
    /**
     * Get current configuration
     */
    public function getConfig()
    {
        return $this->config;
    }
    
    /**
     * Preview next numbers
     */
    public function previewNextNumbers()
    {
        $studentNumber = $this->generateStudentNumber();
        // Rollback the increment for preview
        $this->db->query("
            UPDATE numbering_config 
            SET student_current_number = student_current_number - 1 
            WHERE school_id = 1
        ");
        $this->loadConfig();
        
        $staffNumber = $this->generateStaffNumber();
        $this->db->query("
            UPDATE numbering_config 
            SET staff_current_number = staff_current_number - 1 
            WHERE school_id = 1
        ");
        $this->loadConfig();
        
        return [
            'student_next' => $studentNumber,
            'staff_next' => $staffNumber
        ];
    }
    
    /**
     * Get the next number without incrementing
     */
    public function peekNextStudentNumber()
    {
        $config = $this->config;
        $next = $config['student_current_number'] + 1;
        $year = $this->getCurrentYear();
        $prefix = $config['student_prefix'];
        $separator = $config['student_separator'];
        $padding = $config['student_padding_length'];
        $includeYear = $config['student_include_year'];
        
        $formattedNumber = str_pad($next, $padding, '0', STR_PAD_LEFT);
        
        if ($includeYear) {
            return $prefix . $separator . $year . $separator . $formattedNumber;
        } else {
            return $prefix . $separator . $formattedNumber;
        }
    }
    
    /**
     * Peek next staff number without incrementing
     */
    public function peekNextStaffNumber()
    {
        $config = $this->config;
        $next = $config['staff_current_number'] + 1;
        $year = $this->getCurrentYear();
        $prefix = $config['staff_prefix'];
        $separator = $config['staff_separator'];
        $padding = $config['staff_padding_length'];
        $includeYear = $config['staff_include_year'];
        
        $formattedNumber = str_pad($next, $padding, '0', STR_PAD_LEFT);
        
        if ($includeYear) {
            return $prefix . $separator . $year . $separator . $formattedNumber;
        } else {
            return $prefix . $separator . $formattedNumber;
        }
    }
}
?>