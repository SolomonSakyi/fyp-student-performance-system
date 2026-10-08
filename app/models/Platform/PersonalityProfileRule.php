<?php

/**
 * PersonalityProfileRule.php
 * Personality Profile Rule Model
 * 
 * @package EduTrack
 * @subpackage Models\Platform
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/models/Platform/BaseModel.php';

class PersonalityProfileRule extends BaseModel
{
    protected $table = 'personality_profile_rules';

    protected $fillable = [
        'profile_id',
        'rule_name',
        'factor_type',
        'condition',
        'template',
        'sort_order',
        'created_by',
        'updated_by'
    ];

    /**
     * Get active rules for a profile
     */
    public function getActiveRules(int $profileId): array
    {
        $sql = "SELECT * FROM {$this->table} 
                WHERE profile_id = ? AND deleted_at IS NULL 
                ORDER BY sort_order ASC";
        return $this->rawFetch($sql, [$profileId]);
    }

    /**
     * Get rules by factor type
     */
    public function getByFactorType(int $profileId, string $factorType): array
    {
        $sql = "SELECT * FROM {$this->table} 
                WHERE profile_id = ? AND factor_type = ? AND deleted_at IS NULL 
                ORDER BY sort_order ASC";
        return $this->rawFetch($sql, [$profileId, $factorType]);
    }

    /**
     * Get default personality profile rules
     */
    public function getDefaultRules(): array
    {
        return [
            [
                'rule_name' => 'Excellent Performance',
                'factor_type' => 'academic',
                'condition' => json_encode(['performance_level' => 'excellent']),
                'template' => '{name} is an outstanding student who consistently demonstrates excellence in {top_subject} and across all subject areas.',
                'sort_order' => 1
            ],
            [
                'rule_name' => 'Very Good Performance',
                'factor_type' => 'academic',
                'condition' => json_encode(['performance_level' => 'very_good']),
                'template' => '{name} is a very good student who performs well in {top_subject} and shows strong academic ability.',
                'sort_order' => 2
            ],
            [
                'rule_name' => 'Good Performance',
                'factor_type' => 'academic',
                'condition' => json_encode(['performance_level' => 'good']),
                'template' => '{name} is a good student who works hard and performs well, particularly in {top_subject}.',
                'sort_order' => 3
            ],
            [
                'rule_name' => 'Satisfactory Performance',
                'factor_type' => 'academic',
                'condition' => json_encode(['performance_level' => 'satisfactory']),
                'template' => '{name} shows satisfactory performance and has the potential to improve with consistent effort.',
                'sort_order' => 4
            ],
            [
                'rule_name' => 'Needs Improvement',
                'factor_type' => 'academic',
                'condition' => json_encode(['performance_level' => 'needs_improvement']),
                'template' => '{name} would benefit from focused attention on {bottom_subject} and developing stronger study habits.',
                'sort_order' => 5
            ],
            [
                'rule_name' => 'Strength in Subject',
                'factor_type' => 'strength',
                'condition' => json_encode(['strength_count' => ['min' => 1]]),
                'template' => 'Their strength lies in {top_subject}.',
                'sort_order' => 10
            ],
            [
                'rule_name' => 'Weakness in Subject',
                'factor_type' => 'weakness',
                'condition' => json_encode(['weakness_count' => ['min' => 1]]),
                'template' => 'They would benefit from additional support in {bottom_subject}.',
                'sort_order' => 11
            ],
            [
                'rule_name' => 'Good Attendance',
                'factor_type' => 'attendance',
                'condition' => json_encode(['attendance' => ['min' => 90]]),
                'template' => 'They have excellent attendance and show strong commitment to their education.',
                'sort_order' => 20
            ],
            [
                'rule_name' => 'Improving Performance',
                'factor_type' => 'trend',
                'condition' => json_encode(['improvement_trend' => 'improving']),
                'template' => 'They are showing positive improvement in their academic performance.',
                'sort_order' => 30
            ]
        ];
    }

    /**
     * Initialize default rules for a profile
     */
    public function initializeDefaults(int $profileId, ?int $createdBy = null): int
    {
        $defaults = $this->getDefaultRules();
        $count = 0;

        foreach ($defaults as $rule) {
            $sql = "INSERT INTO {$this->table} (
                        uuid, profile_id, rule_name, factor_type, 
                        condition, template, sort_order, created_by
                    ) VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?)";
            $result = $this->db->query($sql, [
                $profileId,
                $rule['rule_name'],
                $rule['factor_type'],
                $rule['condition'],
                $rule['template'],
                $rule['sort_order'],
                $createdBy
            ]);
            if ($result) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get valid factor types
     */
    public function getValidFactorTypes(): array
    {
        return [
            'academic' => 'Academic Performance',
            'strength' => 'Subject Strengths',
            'weakness' => 'Subject Weaknesses',
            'attendance' => 'Attendance',
            'consistency' => 'Consistency',
            'trend' => 'Improvement Trend',
            'behavior' => 'Behavior',
            'participation' => 'Participation'
        ];
    }
}
