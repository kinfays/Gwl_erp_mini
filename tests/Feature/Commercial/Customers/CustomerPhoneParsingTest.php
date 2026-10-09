<?php

namespace Tests\Feature\Commercial\Customers;

use App\Services\Commercial\Customers\CustomerValues;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Phase 6b: reading a Mobile cell that holds one, two or several numbers. Pure parsing, no database. Every number here is
 * invented (the same few fake numbers are reused so the cases are easy to compare).
 */
class CustomerPhoneParsingTest extends TestCase
{
    private const A = '0241234567';

    private const B = '0207654321';

    private const C = '0501234567';

    /** @return array<string, array{0: mixed, 1: list<string>, 2: bool}> */
    public static function cells(): array
    {
        return [
            'two, the real export form (233 + nine digits, space slash space)' => ['233241234567 / 233207654321', [self::A, self::B], false],
            'two with a bare slash' => ['233241234567/233207654321', [self::A, self::B], false],
            'three' => ['0241234567 / 0207654321 / 0501234567', [self::A, self::B, self::C], false],
            'the same number twice' => ['0241234567 / 233241234567', [self::A], false],
            'a trailing slash' => ['0241234567 /', [self::A], false],
            'a leading slash' => ['/ 0241234567', [self::A], false],
            'an empty segment' => ['0241234567 // 0207654321', [self::A, self::B], false],
            'a comma, a semicolon, a bar and a line break' => ["0241234567,0207654321;0501234567|0241234567\n0207654321", [self::A, self::B, self::C], false],
            '233 form' => ['233241234567', [self::A], false],
            '+233 form' => ['+233241234567', [self::A], false],
            '00233 form' => ['00233241234567', [self::A], false],
            'local ten digits' => ['0241234567', [self::A], false],
            'a lost leading zero' => ['241234567', [self::A], false],
            'an Excel integer' => [233241234567, [self::A], false],
            'an Excel float' => [233241234567.0, [self::A], false],
            'an Excel number that lost its zero' => [241234567, [self::A], false],
            'two numbers with only a space between' => ['0241234567 0207654321', [self::A, self::B], false],
            'two numbers with a space between in the international form' => ['233241234567 233207654321', [self::A, self::B], false],
            'an ampersand' => ['0241234567 & 0207654321', [self::A, self::B], false],
            'the word and' => ['0241234567 and 0207654321', [self::A, self::B], false],
            'the word AND in capitals' => ['0241234567 AND 0207654321', [self::A, self::B], false],
            'the word or' => ['0241234567 or 0207654321', [self::A, self::B], false],
            'the word na' => ['0241234567 na 0207654321', [self::A, self::B], false],
            'spaces inside one number' => ['024 123 4567', [self::A], false],
            'spaces inside an international number' => ['+233 24 123 4567', [self::A], false],
            'an extension is ignored' => ['0241234567 ext 12', [self::A], false],
            'an x extension is ignored' => ['0241234567 x12 / 0207654321', [self::A, self::B], false],
            'one good and one short token' => ['0241234567 / 12345', [self::A], true],
            'one good and one letter token' => ['0241234567 / none', [self::A], false],
            'nothing readable' => ['12345', [], true],
            'blank' => ['', [], false],
            'null' => [null, [], false],
        ];
    }

    #[DataProvider('cells')]
    public function test_a_mobile_cell_is_read_into_its_numbers(mixed $cell, array $numbers, bool $invalid): void
    {
        [$read, $bad] = CustomerValues::phones($cell);

        $this->assertSame($numbers, $read);
        $this->assertSame($invalid, $bad);
    }

    public function test_an_ambiguous_run_of_digits_stays_invalid(): void
    {
        // 21 digits that can be cut as 12 + 9 or as 9 + 12: two ways, so neither is taken.
        $read = CustomerValues::readPhones('233456789233123456789');

        $this->assertSame([], $read['numbers']);
        $this->assertTrue($read['invalid']);
        $this->assertSame(0, $read['recovered']);

        // and a long string that no cutting uses up
        $this->assertSame([[], true], CustomerValues::phones('024123456789012'));
        $this->assertSame([[], true], CustomerValues::phones(str_repeat('2', 60)));
    }

    public function test_placeholders_are_invalid_and_never_stored(): void
    {
        foreach (['0000000000', '0111111111', '0121212121', '0123456789', '0987654321', '233000000000', '0222222222'] as $placeholder) {
            $read = CustomerValues::readPhones($placeholder);
            $this->assertSame([], $read['numbers'], $placeholder);
            $this->assertTrue($read['invalid'], $placeholder);
            $this->assertSame(1, $read['placeholders'], $placeholder);
        }

        // one real number next to a placeholder: the real one stays
        $read = CustomerValues::readPhones('0000000000 / 0241234567');
        $this->assertSame([self::A], $read['numbers']);
        $this->assertTrue($read['invalid']);
    }

    public function test_the_counts_say_how_a_cell_was_read(): void
    {
        $this->assertSame(2, CustomerValues::readPhones('02412345670207654321')['recovered'], 'two numbers cut out of one run');
        $this->assertSame(0, CustomerValues::readPhones('0241234567 / 0207654321')['recovered'], 'separated numbers are not "recovered"');

        $stray = CustomerValues::readPhones('2330241234567');
        $this->assertSame([self::A], $stray['numbers']);
        $this->assertSame(1, $stray['repaired'], 'a stray 0 after 233 was removed');
    }
}
