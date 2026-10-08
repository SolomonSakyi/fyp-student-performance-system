<?php
/**
 * ChartOfAccounts.php
 *
 * Enterprise Chart of Accounts
 * Standard COA for Basic and Secondary Schools
 *
 * @package EduTrack
 * @subpackage Helpers\Finance
 * @version 1.0
 */

class ChartOfAccounts
{
    /**
     * Get standard chart of accounts for schools
     */
    public static function getStandardCOA(): array
    {
        return [
            // ========================================
            // ASSETS (1000-1999)
            // ========================================
            [
                'account_code' => '1000',
                'account_name' => 'Current Assets',
                'account_type' => 'asset',
                'parent_code' => null,
                'is_control' => 1,
                'is_active' => 1
            ],
            [
                'account_code' => '1100',
                'account_name' => 'Cash in Hand',
                'account_type' => 'asset',
                'parent_code' => '1000',
                'is_control' => 0,
                'is_active' => 1
            ],
            [
                'account_code' => '1200',
                'account_name' => 'Cash at Bank',
                'account_type' => 'asset',
                'parent_code' => '1000',
                'is_control' => 0,
                'is_active' => 1
            ],
            [
                'account_code' => '1300',
                'account_name' => 'Accounts Receivable',
                'account_type' => 'asset',
                'parent_code' => '1000',
                'is_control' => 0,
                'is_active' => 1
            ],
            [
                'account_code' => '1400',
                'account_name' => 'Prepaid Expenses',
                'account_type' => 'asset',
                'parent_code' => '1000',
                'is_control' => 0,
                'is_active' => 1
            ],
            [
                'account_code' => '1500',
                'account_name' => 'Fixed Assets',
                'account_type' => 'asset',
                'parent_code' => null,
                'is_control' => 1,
                'is_active' => 1
            ],
            [
                'account_code' => '1510',
                'account_name' => 'Land and Buildings',
                'account_type' => 'asset',
                'parent_code' => '1500',
                'is_control' => 0,
                'is_active' => 1
            ],
            [
                'account_code' => '1520',
                'account_name' => 'Furniture and Equipment',
                'account_type' => 'asset',
                'parent_code' => '1500',
                'is_control' => 0,
                'is_active' => 1
            ],
            [
                'account_code' => '1530',
                'account_name' => 'Vehicles',
                'account_type' => 'asset',
                'parent_code' => '1500',
                'is_control' => 0,
                'is_active' => 1
            ],
            [
                'account_code' => '1540',
                'account_name' => 'Accumulated Depreciation',
                'account_type' => 'asset',
                'parent_code' => '1500',
                'is_control' => 0,
                'is_active' => 1
            ],

            // ========================================
            // LIABILITIES (2000-2999)
            // ========================================
            [
                'account_code' => '2000',
                'account_name' => 'Current Liabilities',
                'account_type' => 'liability',
                'parent_code' => null,
                'is_control' => 1,
                'is_active' => 1
            ],
            [
                'account_code' => '2100',
                'account_name' => 'Accounts Payable',
                'account_type' => 'liability',
                'parent_code' => '2000',
                'is_control' => 0,
                'is_active' => 1
            ],
            [
                'account_code' => '2200',
                'account_name' => 'Accrued Expenses',
                'account_type' => 'liability',
                'parent_code' => '2000',
                'is_control' => 0,
                'is_active' => 1
            ],
            [
                'account_code' => '2300',
                'account_name' => 'Unearned Revenue',
                'account_type' => 'liability',
                'parent_code' => '2000',
                'is_control' => 0,
                'is_active' => 1
            ],
            [
                'account_code' => '2400',
                'account_name' => 'Long Term Liabilities',
                'account_type' => 'liability',
                'parent_code' => null,
                'is_control' => 1,
                'is_active' => 1
            ],
            [
                'account_code' => '2410',
                'account_name' => 'Bank Loans',
                'account_type' => 'liability',
                'parent_code' => '2400',
                'is_control' => 0,
                'is_active' => 1
            ],

            // ========================================
            // EQUITY (3000-3999)
            // ========================================
            [
                'account_code' => '3000',
                'account_name' => 'Equity',
                'account_type' => 'equity',
                'parent_code' => null,
                'is_control' => 1,
                'is_active' => 1
            ],
            [
                'account_code' => '3100',
                'account_name' => 'Capital',
                'account_type' => 'equity',
                'parent_code' => '3000',
                'is_control' => 0,
                'is_active' => 1
            ],
            [
                'account_code' => '3200',
                'account_name' => 'Retained Earnings',
                'account_type' => 'equity',
                'parent_code' => '3000',
                'is_control' => 0,
                'is_active' => 1
            ],

            // ========================================
            // REVENUE (4000-4999)
            // ========================================
            [
                'account_code' => '4000',
                'account_name' => 'Revenue',
                'account_type' => 'revenue',
                'parent_code' => null,
                'is_control' => 1,
                'is_active' => 1
            ],
            [
                'account_code' => '4100',
                'account_name' => 'Tuition Fees',
                'account_type' => 'revenue',
                'parent_code' => '4000',
                'is_control' => 0,
                'is_active' => 1
            ],
            [
                'account_code' => '4200',
                'account_name' => 'Levy Fees',
                'account_type' => 'revenue',
                'parent_code' => '4000',
                'is_control' => 0,
                'is_active' => 1
            ],
            [
                'account_code' => '4300',
                'account_name' => 'Sports Fees',
                'account_type' => 'revenue',
                'parent_code' => '4000',
                'is_control' => 0,
                'is_active' => 1
            ],
            [
                'account_code' => '4400',
                'account_name' => 'PTA Fees',
                'account_type' => 'revenue',
                'parent_code' => '4000',
                'is_control' => 0,
                'is_active' => 1
            ],
            [
                'account_code' => '4500',
                'account_name' => 'Donations',
                'account_type' => 'revenue',
                'parent_code' => '4000',
                'is_control' => 0,
                'is_active' => 1
            ],
            [
                'account_code' => '4600',
                'account_name' => 'Grants',
                'account_type' => 'revenue',
                'parent_code' => '4000',
                'is_control' => 0,
                'is_active' => 1
            ],
            [
                'account_code' => '4700',
                'account_name' => 'Miscellaneous Income',
                'account_type' => 'revenue',
                'parent_code' => '4000',
                'is_control' => 0,
                'is_active' => 1
            ],

            // ========================================
            // EXPENSES (5000-5999)
            // ========================================
            [
                'account_code' => '5000',
                'account_name' => 'Expenses',
                'account_type' => 'expense',
                'parent_code' => null,
                'is_control' => 1,
                'is_active' => 1
            ],
            [
                'account_code' => '5100',
                'account_name' => 'Salaries and Wages',
                'account_type' => 'expense',
                'parent_code' => '5000',
                'is_control' => 0,
                'is_active' => 1
            ],
            [
                'account_code' => '5200',
                'account_name' => 'Utilities',
                'account_type' => 'expense',
                'parent_code' => '5000',
                'is_control' => 0,
                'is_active' => 1
            ],
            [
                'account_code' => '5300',
                'account_name' => 'Teaching Materials',
                'account_type' => 'expense',
                'parent_code' => '5000',
                'is_control' => 0,
                'is_active' => 1
            ],
            [
                'account_code' => '5400',
                'account_name' => 'Maintenance and Repairs',
                'account_type' => 'expense',
                'parent_code' => '5000',
                'is_control' => 0,
                'is_active' => 1
            ],
            [
                'account_code' => '5500',
                'account_name' => 'Transport',
                'account_type' => 'expense',
                'parent_code' => '5000',
                'is_control' => 0,
                'is_active' => 1
            ],
            [
                'account_code' => '5600',
                'account_name' => 'Marketing',
                'account_type' => 'expense',
                'parent_code' => '5000',
                'is_control' => 0,
                'is_active' => 1
            ],
            [
                'account_code' => '5700',
                'account_name' => 'Depreciation',
                'account_type' => 'expense',
                'parent_code' => '5000',
                'is_control' => 0,
                'is_active' => 1
            ],
            [
                'account_code' => '5800',
                'account_name' => 'Miscellaneous Expenses',
                'account_type' => 'expense',
                'parent_code' => '5000',
                'is_control' => 0,
                'is_active' => 1
            ]
        ];
    }
}