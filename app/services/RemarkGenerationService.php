<?php

/**
 * Remark Generation Service
 * Auto-generates conduct, attitude, personality profile, and remarks
 * Uses trait-based approach from Module 06
 */

class RemarkGenerationService
{
    private $db;
    private $logger;

    public function __construct($db, $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Generate all remarks for a student
     */
    public function generateAllRemarks($average, $attendancePercentage)
    {
        // Get conduct and attitude levels based on attendance and score
        $conductResult = $this->generateConduct($average, $attendancePercentage);
        $attitudeResult = $this->generateAttitude($average, $attendancePercentage);
        
        return [
            'conduct_level' => $conductResult['level'],
            'conduct_remark' => $conductResult['remark'],
            'attitude_level' => $attitudeResult['level'],
            'attitude_remark' => $attitudeResult['remark'],
            'personality_profile' => $this->generatePersonalityProfile($average, $attendancePercentage, $conductResult['level'], $attitudeResult['level']),
            'teacher_remark' => $this->generateTeacherRemark($average, $attendancePercentage),
            'head_teacher_remark' => $this->generateHeadTeacherRemark($average, $attendancePercentage)
        ];
    }

    /**
     * Generate conduct level based on attendance and score
     */
    private function generateConduct($average, $attendancePercentage)
    {
        // Conduct logic based on attendance and score
        if ($attendancePercentage >= 90 && $average >= 85) {
            return [
                'level' => 'Excellent',
                'remark' => 'Demonstrates exemplary conduct, maintains excellent attendance, and consistently exhibits a strong commitment to learning.'
            ];
        } elseif ($attendancePercentage >= 80 && $average >= 75) {
            return [
                'level' => 'Very Good',
                'remark' => 'Displays very good behavior, attends school regularly, and participates positively in academic activities.'
            ];
        } elseif ($attendancePercentage >= 70 && $average >= 65) {
            return [
                'level' => 'Good',
                'remark' => 'Shows good conduct, follows school rules, and makes a commendable effort in academic work.'
            ];
        } elseif ($attendancePercentage >= 60 && $average >= 55) {
            return [
                'level' => 'Satisfactory',
                'remark' => 'Maintains satisfactory behavior and attendance, with room for greater consistency in effort and participation.'
            ];
        } elseif ($attendancePercentage >= 50 && $average >= 45) {
            return [
                'level' => 'Needs Improvement',
                'remark' => 'Needs to improve behavior, attendance, and academic engagement to achieve better overall performance.'
            ];
        } else {
            return [
                'level' => 'Unsatisfactory',
                'remark' => 'Attendance, conduct, and academic performance are of serious concern and require immediate attention and support.'
            ];
        }
    }

    /**
     * Generate attitude level based on attendance and score
     */
    private function generateAttitude($average, $attendancePercentage)
    {
        if ($attendancePercentage >= 90 && $average >= 85) {
            return [
                'level' => 'Excellent',
                'remark' => 'Demonstrates an outstanding attitude toward learning, consistently showing enthusiasm, responsibility, and self-motivation.'
            ];
        } elseif ($attendancePercentage >= 80 && $average >= 75) {
            return [
                'level' => 'Very Good',
                'remark' => 'Maintains a very positive attitude toward schoolwork and responds well to learning opportunities.'
            ];
        } elseif ($attendancePercentage >= 70 && $average >= 65) {
            return [
                'level' => 'Good',
                'remark' => 'Shows a positive attitude toward learning and generally approaches tasks with interest and commitment.'
            ];
        } elseif ($attendancePercentage >= 60 && $average >= 55) {
            return [
                'level' => 'Satisfactory',
                'remark' => 'Displays a satisfactory attitude toward learning and is capable of achieving more with greater consistency.'
            ];
        } elseif ($attendancePercentage >= 50 && $average >= 45) {
            return [
                'level' => 'Needs Improvement',
                'remark' => 'Needs to develop a more positive attitude toward learning and participate more actively in academic activities.'
            ];
        } else {
            return [
                'level' => 'Unsatisfactory',
                'remark' => 'Shows a highly concerning attitude toward learning and requires immediate intervention, guidance, and support.'
            ];
        }
    }

    /**
     * Generate personality profile based on conduct and attitude
     */
    private function generatePersonalityProfile($average, $attendancePercentage, $conductLevel, $attitudeLevel)
    {
        // Use the highest of conduct or attitude for personality
        $levels = ['Excellent' => 5, 'Very Good' => 4, 'Good' => 3, 'Satisfactory' => 2, 'Needs Improvement' => 1, 'Unsatisfactory' => 0];
        
        $conductScore = $levels[$conductLevel] ?? 2;
        $attitudeScore = $levels[$attitudeLevel] ?? 2;
        $combinedScore = ($conductScore + $attitudeScore) / 2;

        if ($combinedScore >= 4.5) {
            return 'An exemplary and well-rounded student who demonstrates outstanding character, responsibility, self-discipline, leadership qualities, and a positive influence on peers.';
        } elseif ($combinedScore >= 3.5) {
            return 'A highly responsible and cooperative student who displays strong character, positive relationships, and a commendable attitude toward learning and school life.';
        } elseif ($combinedScore >= 2.5) {
            return 'A pleasant and dependable student who generally exhibits good character, respect for others, and a willingness to learn and participate.';
        } elseif ($combinedScore >= 1.5) {
            return 'A respectful student with satisfactory personal qualities who can achieve greater success through increased consistency and self-motivation.';
        } else {
            return 'Shows potential but needs to strengthen personal responsibility, self-discipline, and positive participation in school life.';
        }
    }

    /**
     * Generate teacher remark
     */
    private function generateTeacherRemark($average, $attendancePercentage)
    {
        if ($average >= 85 && $attendancePercentage >= 90) {
            return 'An outstanding student who consistently demonstrates excellent academic performance, exemplary conduct, and a strong commitment to learning. Keep up the excellent work.';
        } elseif ($average >= 75 && $attendancePercentage >= 80) {
            return 'A very good student who performs well academically and exhibits commendable behavior. Continue striving for excellence.';
        } elseif ($average >= 65 && $attendancePercentage >= 70) {
            return 'A good student who is making steady progress. Continued effort and consistency will lead to even greater success.';
        } elseif ($average >= 55 && $attendancePercentage >= 60) {
            return 'Shows satisfactory progress in academic work and behavior. Greater commitment and participation will enhance performance.';
        } elseif ($average >= 45 && $attendancePercentage >= 50) {
            return 'Has the potential to perform better and should focus on improving attendance, participation, and academic effort.';
        } else {
            return 'Performance is of serious concern. Immediate intervention, regular attendance, and dedicated support are required to improve outcomes.';
        }
    }

    /**
     * Generate head teacher remark
     */
    private function generateHeadTeacherRemark($average, $attendancePercentage)
    {
        if ($average >= 85 && $attendancePercentage >= 90) {
            return 'An exceptional student whose academic excellence, conduct, and attitude are highly commendable. Keep aiming higher and continue to be a role model.';
        } elseif ($average >= 75 && $attendancePercentage >= 80) {
            return 'A commendable student who demonstrates strong academic ability and positive character. Continue working diligently.';
        } elseif ($average >= 65 && $attendancePercentage >= 70) {
            return 'A promising student with good potential. Maintain consistency and strive for further improvement.';
        } elseif ($average >= 55 && $attendancePercentage >= 60) {
            return 'Shows satisfactory progress and should continue working steadily to achieve higher academic and personal goals.';
        } else {
            return 'There is room for improvement in both academic performance and school participation. Greater dedication is encouraged.';
        }
    }
}