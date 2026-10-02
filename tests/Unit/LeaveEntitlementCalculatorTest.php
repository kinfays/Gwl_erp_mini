<?php

namespace Tests\Unit;

use App\Models\Employee;
use App\Services\Leave\LeaveEntitlementCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The entitlement rules, with no database: employees are built in memory and the year's compulsory days are passed in.
 */
class LeaveEntitlementCalculatorTest extends TestCase
{
    protected LeaveEntitlementCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new LeaveEntitlementCalculator;
    }

    protected function staff(?string $grade, ?string $joined = '2015-03-01', string $location = 'District', ?string $category = null): Employee
    {
        return (new Employee)->forceFill([
            'grade' => $grade,
            'date_joined' => $joined,
            'location_type' => $location,
            'category' => $category ?? 'Senior Staff',
        ]);
    }

    #[DataProvider('grossMatrix')]
    public function test_gross_entitlement_by_grade_and_tenure(string $grade, ?string $joined, int $year, int $expected): void
    {
        $this->assertSame($expected, $this->calculator->grossEntitlement($this->staff($grade, $joined), $year));
    }

    public static function grossMatrix(): array
    {
        return [
            // Junior Gd. Level 1-3: 26 days under ten years' service at 1 January, 31 from ten years.
            'junior L1, 9 years 11 months' => ['Junior Gd. Level 1', '2016-02-01', 2026, 26],
            'junior L2, one day short of ten years' => ['Junior Gd. Level 2', '2016-01-02', 2026, 26],
            'junior L3, exactly ten years' => ['Junior Gd. Level 3', '2016-01-01', 2026, 31],
            'junior L1, long serving' => ['Junior Gd. Level 1', '2001-05-10', 2026, 31],
            'junior L1, joined this year' => ['Junior Gd. Level 1', '2026-03-01', 2026, 26],
            'junior L1, joined after 1 January of a later year' => ['Junior Gd. Level 1', '2027-03-01', 2026, 26],
            'junior L1, no hire date' => ['Junior Gd. Level 1', null, 2026, 31],
            'junior L2, tenure crosses ten years next year' => ['Junior Gd. Level 2', '2016-06-15', 2027, 31],
            'junior L2, leap-day hire, nine years at 1 Jan 2026' => ['Junior Gd. Level 2', '2016-02-29', 2026, 26],
            // Junior Gd. Level 4-6: 31 whatever the tenure.
            'junior L4, new joiner' => ['Junior Gd. Level 4', '2026-02-01', 2026, 31],
            'junior L5' => ['Junior Gd. Level 5', '2020-02-01', 2026, 31],
            'junior L6, long serving' => ['Junior Gd. Level 6', '1999-02-01', 2026, 31],
            // Senior and Management: 36 at every level.
            'senior L1, new joiner' => ['Snr. Gd. Level 1', '2026-01-10', 2026, 36],
            'senior L4' => ['Snr. Gd. Level 4', '2010-01-10', 2026, 36],
            'management L1' => ['Mgt. Gd. Level 1', '2018-01-10', 2026, 36],
            'management L4, no hire date' => ['Mgt. Gd. Level 4', null, 2026, 36],
            // Contract workers are not eligible.
            'charwoman' => ['Charwoman', '2015-01-01', 2026, 0],
        ];
    }

    public function test_tenure_is_whole_years_to_the_first_of_january(): void
    {
        $this->assertSame(9, $this->calculator->tenureYears($this->staff('Snr. Gd. Level 1', '2016-02-01'), 2026));
        $this->assertSame(10, $this->calculator->tenureYears($this->staff('Snr. Gd. Level 1', '2016-01-01'), 2026));
        $this->assertSame(0, $this->calculator->tenureYears($this->staff('Snr. Gd. Level 1', '2026-01-01'), 2026));
        $this->assertSame(0, $this->calculator->tenureYears($this->staff('Snr. Gd. Level 1', '2030-01-01'), 2026));
        $this->assertNull($this->calculator->tenureYears($this->staff('Snr. Gd. Level 1', null), 2026));
    }

    public function test_the_numbers_and_threshold_come_from_config_not_the_code(): void
    {
        $calculator = new LeaveEntitlementCalculator([
            'leave_annual_days' => ['junior_lower_short_tenure' => 20, 'junior_lower_long_tenure' => 25, 'junior_upper' => 28, 'senior_and_management' => 30, 'ungraded' => 22],
            'leave_junior_lower_tenure_years' => 5,
        ]);

        $this->assertSame(20, $calculator->grossEntitlement($this->staff('Junior Gd. Level 1', '2022-01-02'), 2026));
        $this->assertSame(25, $calculator->grossEntitlement($this->staff('Junior Gd. Level 1', '2021-01-01'), 2026));
        $this->assertSame(28, $calculator->grossEntitlement($this->staff('Junior Gd. Level 5'), 2026));
        $this->assertSame(30, $calculator->grossEntitlement($this->staff('Snr. Gd. Level 2'), 2026));
        $this->assertSame(22, $calculator->grossEntitlement($this->staff(null), 2026));
    }

    public function test_senior_staff_at_head_office_have_the_compulsory_days_taken_from_the_gross(): void
    {
        $senior = $this->staff('Snr. Gd. Level 2', location: 'HeadOffice');

        $this->assertSame(36, $this->calculator->grossEntitlement($senior, 2026));
        $this->assertSame(11, $this->calculator->compulsoryDeduction($senior, 2026, 11));
        $this->assertSame(25, $this->calculator->netEntitlement($senior, 2026, 11));
    }

    public function test_regional_office_staff_are_covered_too(): void
    {
        $this->assertSame(20, $this->calculator->netEntitlement($this->staff('Junior Gd. Level 4', location: 'Region'), 2026, 11));
    }

    public function test_district_staff_are_exempt(): void
    {
        $district = $this->staff('Snr. Gd. Level 2', location: 'District');

        $this->assertSame(0, $this->calculator->compulsoryDeduction($district, 2026, 11));
        $this->assertSame(36, $this->calculator->netEntitlement($district, 2026, 11));
    }

    public function test_charwoman_is_never_deducted_and_has_nothing_to_deduct_from(): void
    {
        $charwoman = $this->staff('Charwoman', location: 'HeadOffice');

        $this->assertFalse($this->calculator->isEligible($charwoman));
        $this->assertSame(0, $this->calculator->compulsoryDeduction($charwoman, 2026, 11));
        $this->assertSame(0, $this->calculator->netEntitlement($charwoman, 2026, 11));
    }

    public function test_staff_with_no_grade_keep_the_flat_entitlement_and_no_automatic_deduction(): void
    {
        $ungraded = $this->staff(null, location: 'HeadOffice');

        $this->assertSame(31, $this->calculator->grossEntitlement($ungraded, 2026));
        $this->assertSame(0, $this->calculator->compulsoryDeduction($ungraded, 2026, 11));
        $this->assertSame(31, $this->calculator->netEntitlement($ungraded, 2026, 11));
    }

    public function test_an_ungraded_contract_employee_is_not_eligible_either(): void
    {
        $this->assertFalse($this->calculator->isEligible($this->staff(null, category: 'Contract')));
        $this->assertFalse($this->calculator->isEligible($this->staff(null, category: 'Charwoman')));
        $this->assertTrue($this->calculator->isEligible($this->staff(null, category: 'Junior Staff')));
    }

    public function test_the_compulsory_days_never_exceed_the_gross_entitlement(): void
    {
        $junior = $this->staff('Junior Gd. Level 1', '2025-01-01', 'HeadOffice');

        $this->assertSame(26, $this->calculator->compulsoryDeduction($junior, 2026, 40));
        $this->assertSame(0, $this->calculator->netEntitlement($junior, 2026, 40));
        $this->assertSame(0, $this->calculator->compulsoryDeduction($junior, 2026, 0));
    }

    public function test_the_breakdown_reports_gross_compulsory_and_net_together(): void
    {
        $this->assertSame(
            ['gross' => 36, 'compulsory' => 11, 'net' => 25, 'tenure_years' => 10, 'grade' => 'Mgt. Gd. Level 3', 'eligible' => true],
            $this->calculator->breakdown($this->staff('Mgt. Gd. Level 3', '2015-03-01', 'Region'), 2026, 11)
        );
    }
}
