<?php
/**
 * Class Helper
 * Handles class operations and terminology synchronization
 */
class ClassHelper
{
    private $db;
    
    public function __construct($db)
    {
        $this->db = $db;
    }
    
    /**
     * Sync class names with current terminology
     */
    public function syncClassNamesWithTerminology($terminology = null)
    {
        // Get current terminology if not provided
        if ($terminology === null) {
            $config = $this->db->fetchOne("SELECT terminology FROM school_grade_config WHERE school_id = 1");
            $terminology = $config['terminology'] ?? 'Basic';
        }
        
        // Get all active grade levels
        $gradeLevels = $this->db->fetchAll("
            SELECT id, level_name, promotion_order 
            FROM grade_levels 
            WHERE school_id = 1 AND is_active = 1 
            ORDER BY promotion_order ASC
        ");
        
        // Get current academic year
        $currentYear = $this->db->fetchOne("SELECT id FROM academic_years WHERE is_current = 1 AND school_id = 1");
        $yearId = $currentYear ? $currentYear['id'] : 1;
        
        $updated = 0;
        $created = 0;
        
        foreach ($gradeLevels as $level) {
            // Generate section name based on terminology
            $sectionName = $level['level_name'] . 'A';
            
            // Check if class exists
            $existing = $this->db->fetchOne("
                SELECT id, section_name FROM class_sections 
                WHERE grade_level_id = ? AND academic_year_id = ? AND school_id = 1
            ", [$level['id'], $yearId]);
            
            if ($existing) {
                // Update existing class name if different
                if ($existing['section_name'] !== $sectionName) {
                    $this->db->query("
                        UPDATE class_sections 
                        SET section_name = ? 
                        WHERE id = ?
                    ", [$sectionName, $existing['id']]);
                    $updated++;
                }
            } else {
                // Create default class
                $this->db->query("
                    INSERT INTO class_sections (
                        uuid, school_id, grade_level_id, academic_year_id,
                        section_code, section_name, is_active
                    ) VALUES (UUID(), 1, ?, ?, 'A', ?, 1)
                ", [$level['id'], $yearId, $sectionName]);
                $created++;
            }
        }
        
        return [
            'updated' => $updated,
            'created' => $created,
            'terminology' => $terminology,
            'message' => "Class names synchronized with '{$terminology}' terminology. Updated: {$updated}, Created: {$created}"
        ];
    }
    
    /**
     * Get class name suggestion based on terminology
     */
    public function suggestClassName($gradeLevelId, $sectionCode = 'A')
    {
        $level = $this->db->fetchOne("
            SELECT level_name FROM grade_levels 
            WHERE id = ? AND school_id = 1
        ", [$gradeLevelId]);
        
        if (!$level) {
            return null;
        }
        
        return $level['level_name'] . $sectionCode;
    }
    
    /**
     * Get all grade levels with their class names
     */
    public function getGradeLevelsWithClasses($academicYearId = null)
    {
        if ($academicYearId === null) {
            $currentYear = $this->db->fetchOne("SELECT id FROM academic_years WHERE is_current = 1 AND school_id = 1");
            $academicYearId = $currentYear ? $currentYear['id'] : 1;
        }
        
        return $this->db->fetchAll("
            SELECT 
                gl.*,
                cs.id as class_id,
                cs.section_name,
                cs.section_code,
                cs.class_teacher_id,
                CONCAT(p.first_name, ' ', p.last_name) AS teacher_name
            FROM grade_levels gl
            LEFT JOIN class_sections cs ON cs.grade_level_id = gl.id AND cs.academic_year_id = ?
            LEFT JOIN staff st ON cs.class_teacher_id = st.id
            LEFT JOIN people p ON st.person_id = p.id
            WHERE gl.school_id = 1 AND gl.is_active = 1
            ORDER BY gl.promotion_order ASC
        ", [$academicYearId]);
    }
}
?>