<?php

namespace Tests\Unit;

use App\Enums\StaffGrade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StaffGradeTest extends TestCase
{
    public function test_the_allowed_grades_are_defined_once_and_in_full(): void
    {
        $this->assertCount(15, StaffGrade::cases());
        $this->assertSame('Junior Gd. Level 1', StaffGrade::values()[0]);
        $this->assertContains('Charwoman', StaffGrade::values());
        $this->assertContains('Mgt. Gd. Level 4', StaffGrade::values());
    }

    #[DataProvider('categories')]
    public function test_a_grade_fixes_its_category_and_level(string $grade, string $category, ?int $level): void
    {
        $enum = StaffGrade::from($grade);

        $this->assertSame($category, $enum->category());
        $this->assertSame($level, $enum->level());
    }

    public static function categories(): array
    {
        $rows = [];

        foreach (range(1, 6) as $level) {
            $rows["junior $level"] = ["Junior Gd. Level $level", 'Junior Staff', $level];
        }

        foreach (range(1, 4) as $level) {
            $rows["senior $level"] = ["Snr. Gd. Level $level", 'Senior Staff', $level];
            $rows["management $level"] = ["Mgt. Gd. Level $level", 'Management', $level];
        }

        $rows['charwoman'] = ['Charwoman', 'Contract', null];

        return $rows;
    }

    public function test_only_junior_levels_one_to_three_are_the_lower_junior_grades(): void
    {
        $lower = array_filter(StaffGrade::cases(), fn (StaffGrade $grade) => $grade->isJuniorLower());

        $this->assertSame(
            ['Junior Gd. Level 1', 'Junior Gd. Level 2', 'Junior Gd. Level 3'],
            array_map(fn (StaffGrade $grade) => $grade->value, array_values($lower))
        );
    }

    public function test_only_charwoman_is_not_eligible_for_leave(): void
    {
        $this->assertFalse(StaffGrade::Charwoman->isLeaveEligible());
        $this->assertTrue(StaffGrade::SeniorLevel1->isLeaveEligible());
        $this->assertTrue(StaffGrade::JuniorLevel6->isLeaveEligible());
    }

    #[DataProvider('spellings')]
    public function test_grades_are_read_whatever_the_spelling_or_case(string $input, ?StaffGrade $expected): void
    {
        $this->assertSame($expected, StaffGrade::fromInput($input));
    }

    public static function spellings(): array
    {
        return [
            'exact' => ['Snr. Gd. Level 2', StaffGrade::SeniorLevel2],
            'lower case' => ['snr. gd. level 2', StaffGrade::SeniorLevel2],
            'no dots' => ['SNR GD LEVEL 2', StaffGrade::SeniorLevel2],
            'senior spelt out' => ['Senior Grade 2', StaffGrade::SeniorLevel2],
            'short level' => ['Snr Gd L2', StaffGrade::SeniorLevel2],
            'junior' => ['Junior Gd. Level 3', StaffGrade::JuniorLevel3],
            'jnr' => ['jnr gd lvl 6', StaffGrade::JuniorLevel6],
            'management' => ['Management Grade Level 4', StaffGrade::ManagementLevel4],
            'mgt' => ['MGT. GD. LEVEL 1', StaffGrade::ManagementLevel1],
            'padded' => ['  Mgt.   Gd.  Level 3 ', StaffGrade::ManagementLevel3],
            'charwoman' => ['charwoman', StaffGrade::Charwoman],
            'a level that does not exist' => ['Snr. Gd. Level 5', null],
            'junior level 7' => ['Junior Gd. Level 7', null],
            'management level 0' => ['Mgt. Gd. Level 0', null],
            'rubbish' => ['Director', null],
            'blank' => ['', null],
            'spaces' => ['   ', null],
        ];
    }

    public function test_legacy_categories_are_reported_under_the_category_they_became(): void
    {
        $this->assertSame('Management', StaffGrade::reportCategory('Senior Management'));
        $this->assertSame('Contract', StaffGrade::reportCategory('Charwoman'));
        $this->assertSame('Junior Staff', StaffGrade::reportCategory('Junior Staff'));
        $this->assertNull(StaffGrade::reportCategory(null));
    }
}
