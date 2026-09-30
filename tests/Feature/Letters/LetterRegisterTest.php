<?php

namespace Tests\Feature\Letters;

use App\Exceptions\Letters\RegisterTooLargeException;
use App\Exports\Letters\LetterRegisterExport;
use App\Livewire\Letters\Register;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ModuleAccess;
use App\Models\Role;
use App\Models\RoutingHistory;
use App\Services\Letters\LetterRegisterService;
use App\Support\ErpNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Letters\Concerns\BuildsLettersOrg;
use Tests\TestCase;

class LetterRegisterTest extends TestCase
{
    use BuildsLettersOrg;
    use RefreshDatabase;

    protected Employee $hrSec;

    protected Employee $cmSec;

    protected Employee $matSec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildLettersOrg();
        $this->hrSec = $this->letterStaff('HR001', $this->accraOffice);
        $this->cmSec = $this->letterStaff('CM001', $this->accraOffice);
        $this->matSec = $this->letterStaff('MAT01', $this->accraOffice);
    }

    private function register(): LetterRegisterService
    {
        return app(LetterRegisterService::class);
    }

    private function rows(Employee $holder, string $from = '2026-09-01', string $to = '2026-09-30', string $scope = 'all')
    {
        return $this->register()->rows($holder, Carbon::parse($from), Carbon::parse($to), $scope);
    }

    private function at(string $moment): void
    {
        $this->travelTo(Carbon::parse($moment));
    }

    /** hr records a letter on 1 Sep, sends it on 2 Sep, cm confirms on 5 Sep. */
    private function receivedByCm(string $subject = 'Laptop request', array $overrides = [])
    {
        $this->at('2026-09-01 09:00');
        $letter = $this->createLetter($this->hrSec, ['subject' => $subject, ...$overrides]);
        $this->at('2026-09-02 10:00');
        $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $this->cmSec);
        $this->at('2026-09-05 11:00');
        $this->lettersWorkflow()->confirmHardcopy($letter, $this->cmSec);

        return $letter;
    }

    private function exporter(array $roles = ['secretary'], string $staffId = 'EXP01'): Employee
    {
        return $this->letterStaff($staffId, $this->accraOffice, $roles);
    }

    private function grantSuperAdminLetters(): void
    {
        ModuleAccess::query()->create(['role_id' => Role::query()->firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super admin', 'is_system' => true])->id, 'module' => 'letters', 'can_access' => true]);
    }

    // ---- the rows -----------------------------------------------------------------------------------------

    public function test_the_creators_own_intake_is_a_row_dated_by_the_recording_date(): void
    {
        $this->at('2026-09-03 08:30');
        $letter = $this->createLetter($this->hrSec, [
            'subject' => 'Request for a laptop',
            'ref_no' => 'REF/77',
            'type' => 'External',
            'company_sender' => 'Acme Supplies Ltd',
            'date_on_letter' => '2026-09-01',
        ]);

        $row = $this->rows($this->hrSec)->sole();

        $this->assertSame(1, $row['no']);
        $this->assertSame('2026-09-03', $row['date_received']->toDateString());
        $this->assertSame($letter->sn_number, $row['sn_number']);
        $this->assertSame('REF/77', $row['ref_no']);
        $this->assertSame('External', $row['type']);
        $this->assertSame('2026-09-01', $row['date_on_letter']->toDateString());
        $this->assertSame('Acme Supplies Ltd', $row['sender']);
        $this->assertSame('Acme Supplies Ltd', $row['received_from'], 'intake: it came from the sender');
        $this->assertSame('Request for a laptop', $row['subject']);
        $this->assertNull($row['date_out']);
        $this->assertSame('', $row['sent_to']);
        $this->assertSame('', $row['transmittal_no']);
        $this->assertSame('With me', $row['status']);
        $this->assertSame('', $row['remarks']);
    }

    public function test_an_internal_letters_sender_is_the_memo_sender(): void
    {
        $this->at('2026-09-03 08:30');
        $this->createLetter($this->hrSec, ['type' => 'Internal', 'memo_sender_id' => $this->cmSec->id, 'company_sender' => null]);

        $this->assertSame('Employee CM001', $this->rows($this->hrSec)->sole()['sender']);
    }

    public function test_a_confirmed_hand_over_is_dated_by_its_confirmation_and_shows_who_it_came_from(): void
    {
        $this->receivedByCm();

        $row = $this->rows($this->cmSec)->sole();

        $this->assertSame('2026-09-05', $row['date_received']->toDateString(), 'the confirmation, not the dispatch on 2 Sep');
        $this->assertSame('Employee HR001', $row['received_from']);
        $this->assertSame('With me', $row['status']);
        $this->assertSame('Employee CM001', 'Employee '.$this->cmSec->staff_id);
    }

    public function test_only_the_holders_own_stays_are_rows(): void
    {
        $this->receivedByCm('Held by cm');
        $this->at('2026-09-06 09:00');
        $this->createLetter($this->matSec, ['subject' => 'Mat only']);

        $this->assertSame(['Held by cm'], $this->rows($this->cmSec)->pluck('subject')->all());
        $this->assertSame(['Mat only'], $this->rows($this->matSec)->pluck('subject')->all());
        $this->assertSame(['Held by cm'], $this->rows($this->hrSec)->pluck('subject')->all(), 'the creator has the one letter they recorded');
    }

    public function test_the_date_range_applies_to_the_date_received(): void
    {
        $this->receivedByCm('Received 5 Sep'); // created 1 Sep, received by cm on 5 Sep

        $this->assertCount(1, $this->rows($this->cmSec, '2026-09-05', '2026-09-05'), 'both ends are inclusive');
        $this->assertCount(0, $this->rows($this->cmSec, '2026-09-01', '2026-09-04'), 'dispatched on 2 Sep but not received until the 5th');
        $this->assertCount(0, $this->rows($this->cmSec, '2026-09-06', '2026-09-30'));
        $this->assertCount(1, $this->rows($this->hrSec, '2026-09-01', '2026-09-01'), 'the creator received it on the 1st');
    }

    public function test_a_dispatched_row_shows_the_recipient_date_out_and_transmittal_no(): void
    {
        $letters = collect();
        $this->at('2026-09-01 09:00');
        foreach (['First', 'Second'] as $subject) {
            $letters->push($this->createLetter($this->hrSec, ['subject' => $subject]));
        }
        $single = $this->createLetter($this->hrSec, ['subject' => 'Single']);
        $this->at('2026-09-04 10:00');
        $batch = $this->lettersWorkflow()->dispatchBatch($this->hrSec, $this->cmSec, $letters->pluck('id')->all());
        $this->lettersWorkflow()->dispatch($single, $this->hrSec, $this->matSec);

        $rows = $this->rows($this->hrSec)->keyBy('subject');

        foreach (['First', 'Second'] as $subject) {
            $this->assertSame('Dispatched', $rows[$subject]['status']);
            $this->assertSame('2026-09-04', $rows[$subject]['date_out']->toDateString());
            $this->assertSame('Employee CM001', $rows[$subject]['sent_to']);
            $this->assertSame($batch->batch_no, $rows[$subject]['transmittal_no']);
        }

        $this->assertSame('Employee MAT01', $rows['Single']['sent_to']);
        $this->assertSame('', $rows['Single']['transmittal_no'], 'a single dispatch has no transmittal');
    }

    public function test_hand_overs_that_were_never_received_are_not_rows(): void
    {
        $this->at('2026-09-01 09:00');
        $unconfirmed = $this->createLetter($this->hrSec, ['subject' => 'Unconfirmed']);
        $recalled = $this->createLetter($this->hrSec, ['subject' => 'Recalled']);
        $rejected = $this->createLetter($this->hrSec, ['subject' => 'Rejected']);
        $this->at('2026-09-02 09:00');
        foreach ([$unconfirmed, $recalled, $rejected] as $letter) {
            $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $this->cmSec);
        }
        $this->lettersWorkflow()->recall(RoutingHistory::query()->where('letter_id', $recalled->id)->sole(), $this->hrSec);
        $this->lettersWorkflow()->reject(RoutingHistory::query()->where('letter_id', $rejected->id)->sole(), $this->cmSec, 'Not meant for us');

        $this->assertCount(0, $this->rows($this->cmSec), 'cm never received any of them');

        // The sender's register: the recalled and rejected ones are back with her (With me), the other is out.
        $byStatus = $this->rows($this->hrSec)->pluck('status', 'subject')->all();
        $this->assertSame(['Unconfirmed' => 'Dispatched', 'Recalled' => 'With me', 'Rejected' => 'With me'], $byStatus);
    }

    public function test_scope_chips_narrow_by_status(): void
    {
        $this->receivedByCm('To be sent on');                     // with cm
        $this->at('2026-09-06 09:00');
        $sentOn = $this->createLetter($this->cmSec, ['subject' => 'Sent on']);
        $this->lettersWorkflow()->dispatch($sentOn, $this->cmSec, $this->matSec);
        $done = $this->createLetter($this->cmSec, ['subject' => 'Filed']);
        $this->lettersWorkflow()->close($done, $this->cmSec);

        $this->assertEqualsCanonicalizing(['To be sent on', 'Sent on', 'Filed'], $this->rows($this->cmSec, scope: 'all')->pluck('subject')->all());
        $this->assertSame(['To be sent on'], $this->rows($this->cmSec, scope: 'with_me')->pluck('subject')->all());
        $this->assertSame(['Sent on'], $this->rows($this->cmSec, scope: 'dispatched')->pluck('subject')->all());
        $this->assertSame(['Filed'], $this->rows($this->cmSec, scope: 'closed')->pluck('subject')->all());
        $this->assertCount(3, $this->rows($this->cmSec, scope: 'nonsense'), 'an unknown scope means all');
    }

    public function test_a_delivered_letter_shows_who_received_it(): void
    {
        $manager = $this->letterStaff('MGR01', $this->accraOffice, ['manager']);
        $addressee = $this->letterStaff('ADR01', $this->accraOffice, ['leave_applicant']);
        $this->at('2026-09-01 09:00');
        $letter = $this->createLetter($this->hrSec, ['subject' => 'Hand delivered']);
        $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $manager);
        $this->lettersWorkflow()->confirmHardcopy($letter, $manager);
        $this->at('2026-09-08 09:00');
        $this->lettersWorkflow()->deliver($letter, $manager, ['delivered_to_employee_id' => $addressee->id]);

        $row = $this->rows($manager)->sole();
        $this->assertSame('Delivered to Employee ADR01', $row['status']);
        $this->assertSame('closed', $row['status_key']);
        $this->assertSame(['Hand delivered'], $this->rows($manager, scope: 'closed')->pluck('subject')->all());

        // The creator dispatched it onward, so for them it is simply Dispatched.
        $this->assertSame('Dispatched', $this->rows($this->hrSec)->sole()['status']);
    }

    public function test_remarks_i_recorded_are_joined_and_only_mine(): void
    {
        $letter = $this->receivedByCm();
        $this->at('2026-09-05 12:00');
        $this->lettersWorkflow()->addRemark($letter, $this->cmSec, ['remark_content' => 'Refer to Materials', 'secretary_remark_content' => 'Noted by secretary']);
        $this->lettersWorkflow()->addRemark($letter, $this->cmSec, ['remark_content' => 'Second thought']);

        $this->assertSame('Refer to Materials | Secretary: Noted by secretary / Second thought', $this->rows($this->cmSec)->sole()['remarks']);
        $this->assertSame('', $this->rows($this->hrSec)->sole()['remarks'], "someone else's remarks are not mine");
    }

    public function test_a_letter_that_comes_back_makes_a_second_row_with_its_own_remarks_and_dates(): void
    {
        $this->at('2026-09-01 09:00');
        $letter = $this->createLetter($this->hrSec, ['subject' => 'Round trip']);
        $this->lettersWorkflow()->addRemark($letter, $this->hrSec, ['remark_content' => 'First stay note']);
        $this->at('2026-09-02 09:00');
        $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $this->cmSec);
        $this->at('2026-09-03 09:00');
        $this->lettersWorkflow()->confirmHardcopy($letter, $this->cmSec);
        $this->lettersWorkflow()->dispatch($letter, $this->cmSec, $this->hrSec);
        $this->at('2026-09-08 09:00');
        $this->lettersWorkflow()->confirmHardcopy($letter, $this->hrSec);
        $this->lettersWorkflow()->addRemark($letter, $this->hrSec, ['remark_content' => 'Second stay note']);
        $this->at('2026-09-09 09:00');
        $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $this->matSec);

        $rows = $this->rows($this->hrSec);

        $this->assertCount(2, $rows);
        $this->assertSame(['2026-09-01', '2026-09-08'], $rows->map(fn ($row) => $row['date_received']->toDateString())->all());
        $this->assertSame(['First stay note', 'Second stay note'], $rows->pluck('remarks')->all());
        $this->assertSame(['Employee CM001', 'Employee MAT01'], $rows->pluck('sent_to')->all());
        $this->assertSame(['2026-09-02', '2026-09-09'], $rows->map(fn ($row) => $row['date_out']->toDateString())->all());
        $this->assertSame(['Employee CM001'], $rows->pluck('received_from')->filter(fn ($from) => $from === 'Employee CM001')->values()->all(), 'the second stay came back from cm');
        $this->assertSame([1, 2], $rows->pluck('no')->all());
    }

    public function test_hand_overs_confirmed_before_the_log_link_existed_still_appear(): void
    {
        $this->receivedByCm('Legacy hand-over');
        DB::table('letter_status_logs')->update(['routing_history_id' => null]); // as rows from before the link were

        $row = $this->rows($this->cmSec)->sole();

        $this->assertSame('2026-09-05', $row['date_received']->toDateString());
        $this->assertSame('Employee HR001', $row['received_from']);
    }

    public function test_a_log_added_by_an_old_reopen_is_not_a_receipt(): void
    {
        $this->at('2026-09-01 09:00');
        $letter = $this->createLetter($this->hrSec);
        $this->at('2026-09-02 09:00');
        DB::table('letter_status_logs')->insert([
            'letter_id' => $letter->id, 'secretariat_id' => $this->hrSec->id, 'status' => 'In Review',
            'created_at' => '2026-09-02 09:00:00', 'updated_at' => '2026-09-02 09:00:00',
        ]);

        $this->assertCount(1, $this->rows($this->hrSec), 'only the real intake');
    }

    public function test_rows_are_in_date_received_order_and_numbered(): void
    {
        $this->at('2026-09-10 09:00');
        $this->createLetter($this->hrSec, ['subject' => 'Later']);
        $this->at('2026-09-02 09:00');
        $this->createLetter($this->hrSec, ['subject' => 'Earlier']);

        $rows = $this->rows($this->hrSec);

        $this->assertSame(['Earlier', 'Later'], $rows->pluck('subject')->all());
        $this->assertSame([1, 2], $rows->pluck('no')->all());
    }

    public function test_the_number_of_queries_does_not_grow_with_the_rows(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->rows($this->cmSec);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->receivedByCm('One');
        $one = $count();

        foreach (range(1, 12) as $i) {
            $this->at('2026-09-10 09:00');
            $letter = $this->createLetter($this->hrSec, ['subject' => "More {$i}"]);
            $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $this->cmSec);
            $this->lettersWorkflow()->confirmHardcopy($letter, $this->cmSec);
            $this->lettersWorkflow()->addRemark($letter, $this->cmSec, ['remark_content' => "Remark {$i}"]);
            $this->lettersWorkflow()->dispatch($letter, $this->cmSec, $this->matSec);
        }

        $this->assertCount(13, $this->rows($this->cmSec));
        $this->assertSame($one, $count());
    }

    public function test_more_rows_than_the_cap_asks_for_a_narrower_range(): void
    {
        config(['gwl.letters_register_max_rows' => 3]);
        $this->at('2026-09-01 09:00');
        $this->createLetters($this->hrSec, 3);

        $this->assertCount(3, $this->rows($this->hrSec), 'exactly at the limit is fine');

        $this->at('2026-09-02 09:00');
        $this->createLetter($this->hrSec, ['subject' => 'One too many']);

        try {
            $this->rows($this->hrSec);
            $this->fail('A register over the cap must be refused.');
        } catch (RegisterTooLargeException $e) {
            $this->assertStringContainsString('more than the limit of 3', $e->getMessage());
            $this->assertStringContainsString('Narrow the date range', $e->getMessage());
        }

        $this->assertCount(3, $this->rows($this->hrSec, '2026-09-01', '2026-09-01', 'all'), 'the cap counts rows after the filters');
    }

    public function test_the_cap_default_is_five_thousand(): void
    {
        $this->assertSame(5000, config('gwl.letters_register_max_rows'));
    }

    // ---- the Excel export -----------------------------------------------------------------------------------

    public function test_the_excel_export_downloads_the_holders_own_rows_for_the_chosen_range(): void
    {
        Excel::fake();
        $this->receivedByCm('Exported subject');
        $this->at('2026-10-20 09:00');
        $this->createLetter($this->cmSec, ['subject' => 'Outside the range']);

        $this->actingAs($this->letterUserOf($this->cmSec))
            ->get(route('letters.register.excel', ['from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertOk();

        Excel::assertDownloaded('letter_register_CM001_2026_09_01_to_2026_09_30.xlsx', function (LetterRegisterExport $export) {
            $this->assertSame(array_values(LetterRegisterService::COLUMNS), $export->headings());
            $rows = $export->collection();
            $this->assertCount(1, $rows);
            $this->assertSame(1, $rows[0][0]);
            $this->assertSame('05 Sep 2026', $rows[0][1]);
            $this->assertSame('Exported subject', $rows[0][8]);
            $this->assertSame('Employee HR001', $rows[0][7]);
            $this->assertSame('With me', $rows[0][12]);

            return true;
        });
    }

    public function test_the_excel_export_really_writes_an_xlsx_with_headings(): void
    {
        $this->receivedByCm('Real file');
        $bytes = Excel::raw(new LetterRegisterExport($this->rows($this->cmSec)), ExcelWriter::XLSX);

        $path = tempnam(sys_get_temp_dir(), 'register').'.xlsx';
        file_put_contents($path, $bytes);

        try {
            $sheet = IOFactory::load($path)->getSheet(0);
            $this->assertSame('No.', $sheet->getCell('A1')->getValue());
            $this->assertSame('Remarks I recorded', $sheet->getCell('N1')->getValue());
            $this->assertSame('Real file', $sheet->getCell('I2')->getValue());
            $this->assertSame(1, $sheet->getCell('A2')->getValue(), 'the row number stays a number');
        } finally {
            @unlink($path);
        }
    }

    public function test_user_text_that_looks_like_a_formula_is_stored_as_text(): void
    {
        $this->at('2026-09-01 09:00');
        $evil = [
            '=1+1',
            "=cmd|'/c calc'!A1",
            '=HYPERLINK("http://evil.example","click")',
            '+1+1',
            '-2+3',
            '@SUM(1,1)',
        ];
        foreach ($evil as $i => $text) {
            $letter = $this->createLetter($this->hrSec, ['subject' => $text, 'company_sender' => $text]);
            $this->lettersWorkflow()->addRemark($letter, $this->hrSec, ['remark_content' => $text]);
        }

        $bytes = Excel::raw(new LetterRegisterExport($this->rows($this->hrSec)), ExcelWriter::XLSX);
        $path = tempnam(sys_get_temp_dir(), 'register').'.xlsx';
        file_put_contents($path, $bytes);

        try {
            $sheet = IOFactory::load($path)->getSheet(0);
            $found = [];

            for ($row = 2; $row <= 1 + count($evil); $row++) {
                foreach (['G' => 'sender', 'I' => 'subject', 'N' => 'remarks'] as $column => $label) {
                    $cell = $sheet->getCell($column.$row);
                    $this->assertSame(DataType::TYPE_STRING, $cell->getDataType(), "{$label} in {$column}{$row} must be a string, got {$cell->getDataType()}");
                    $this->assertFalse($cell->isFormula(), "{$label} in {$column}{$row} must not be a formula");
                    $found[$label][] = $cell->getValue();
                }
            }

            $this->assertEqualsCanonicalizing($evil, $found['subject']);
            $this->assertContains('=1+1', $found['subject'], 'the text is kept exactly, not evaluated to 2');
        } finally {
            @unlink($path);
        }
    }

    public function test_an_empty_register_is_still_a_valid_file(): void
    {
        $bytes = Excel::raw(new LetterRegisterExport(collect()), ExcelWriter::XLSX);

        $this->assertStringStartsWith('PK', $bytes);
    }

    // ---- the PDF --------------------------------------------------------------------------------------------

    public function test_the_pdf_is_an_attachment_that_starts_with_pdf(): void
    {
        $this->receivedByCm('On paper');

        $response = $this->actingAs($this->letterUserOf($this->cmSec))
            ->get(route('letters.register.pdf', ['from' => '2026-09-01', 'to' => '2026-09-30']));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="letter_register_CM001_2026_09_01_to_2026_09_30.pdf"', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_the_pdf_page_has_the_holder_period_count_signature_lines_and_shortened_remarks(): void
    {
        $letter = $this->receivedByCm('On paper');
        $this->lettersWorkflow()->addRemark($letter, $this->cmSec, ['remark_content' => str_repeat('long remark ', 30)]);
        $this->cmSec->load(['department', 'district', 'region']);
        $register = $this->register();
        $rows = $this->rows($this->cmSec)->map(fn ($row) => $register->cells($row, 120));

        $html = view('letters.exports.register-pdf', [
            'holder' => $this->cmSec,
            'rows' => $rows,
            'periodLabel' => '01 September 2026 - 30 September 2026',
            'scopeLabel' => 'All',
            'generatedAt' => Carbon::parse('2026-10-01 08:15'),
        ])->render();

        $this->assertStringContainsString('Letter register', $html);
        $this->assertStringContainsString('Employee CM001 (CM001)', $html);
        $this->assertStringContainsString('Accra Regional Office', $html);
        $this->assertStringContainsString('01 September 2026 - 30 September 2026', $html);
        $this->assertStringContainsString('01 Oct 2026, 08:15', $html);
        $this->assertStringContainsString('Prepared by', $html);
        $this->assertStringContainsString('Checked by', $html);
        $this->assertStringContainsString('On paper', $html);
        $this->assertStringNotContainsString(str_repeat('long remark ', 30), $html);
        $this->assertLessThanOrEqual(120, mb_strlen($rows->first()['remarks']));
        $this->assertStringEndsWith('...', $rows->first()['remarks']);
    }

    // ---- who may export ---------------------------------------------------------------------------------------

    public function test_exports_need_the_letters_export_permission(): void
    {
        $manager = $this->exporter(['manager'], 'MGR01'); // managers do not hold letters.export by default

        foreach (['letters.register', 'letters.register.excel', 'letters.register.pdf'] as $route) {
            $this->actingAs($this->letterUserOf($manager))->get(route($route))->assertForbidden();
        }

        foreach (['letters.register', 'letters.register.excel', 'letters.register.pdf'] as $route) {
            $this->actingAs($this->letterUserOf($this->cmSec))->get(route($route))->assertOk();
        }
    }

    public function test_guests_are_sent_to_login(): void
    {
        foreach (['letters.register', 'letters.register.excel', 'letters.register.pdf'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
    }

    public function test_employee_id_is_honoured_for_super_admin_only(): void
    {
        Excel::fake();
        $this->receivedByCm('Someone elses register');
        $this->grantSuperAdminLetters();
        $admin = $this->letterStaff('ADM01', $this->accraOffice, ['super_admin']);

        $this->actingAs($this->letterUserOf($admin))
            ->get(route('letters.register.excel', ['employee_id' => $this->cmSec->id, 'from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertOk();

        Excel::assertDownloaded('letter_register_CM001_2026_09_01_to_2026_09_30.xlsx', fn (LetterRegisterExport $export) => $export->collection()->count() === 1);

        // Everyone else, even for their own id, even with letters.export: 403.
        foreach (['letters.register.excel', 'letters.register.pdf'] as $route) {
            $this->actingAs($this->letterUserOf($this->hrSec))
                ->get(route($route, ['employee_id' => $this->cmSec->id]))
                ->assertForbidden();
            $this->actingAs($this->letterUserOf($this->cmSec))
                ->get(route($route, ['employee_id' => $this->cmSec->id]))
                ->assertForbidden();
        }

        $this->assertSame(1, AuditLog::query()->where('action', 'export_letter_register_excel')->count(), 'the refused requests exported and audited nothing');
    }

    public function test_the_export_defaults_to_the_current_month_and_swaps_a_reversed_range(): void
    {
        Excel::fake();
        $this->at('2026-09-15 10:00');

        $this->actingAs($this->letterUserOf($this->hrSec))->get(route('letters.register.excel'))->assertOk();
        Excel::assertDownloaded('letter_register_HR001_2026_09_01_to_2026_09_30.xlsx');

        $this->actingAs($this->letterUserOf($this->hrSec))->get(route('letters.register.excel', ['from' => '2026-09-20', 'to' => '2026-09-10', 'scope' => 'closed']))->assertOk();
        Excel::assertDownloaded('letter_register_HR001_2026_09_10_to_2026_09_20.xlsx');
    }

    // ---- audit and the row cap in the controllers --------------------------------------------------------------

    public function test_each_export_is_audited_with_its_filters_and_row_count(): void
    {
        Excel::fake();
        $this->receivedByCm();
        $user = $this->letterUserOf($this->cmSec);

        $this->actingAs($user)->get(route('letters.register.excel', ['from' => '2026-09-01', 'to' => '2026-09-30', 'scope' => 'with_me']))->assertOk();
        $this->actingAs($user)->get(route('letters.register.pdf', ['from' => '2026-09-01', 'to' => '2026-09-30']))->assertOk();

        $excel = AuditLog::query()->where('action', 'export_letter_register_excel')->sole();
        $this->assertSame('letters', $excel->module);
        $this->assertSame('letter_status_logs', $excel->target_type);
        $this->assertNull($excel->target_id);
        $this->assertSame(['employee_id' => $this->cmSec->id, 'from' => '2026-09-01', 'to' => '2026-09-30', 'scope' => 'with_me', 'rows' => 1], $excel->new_values);

        $pdf = AuditLog::query()->where('action', 'export_letter_register_pdf')->sole();
        $this->assertSame(['employee_id' => $this->cmSec->id, 'from' => '2026-09-01', 'to' => '2026-09-30', 'scope' => 'all', 'rows' => 1], $pdf->new_values);
    }

    public function test_an_export_over_the_cap_sends_the_user_back_to_narrow_the_range_and_audits_nothing(): void
    {
        config(['gwl.letters_register_max_rows' => 2]);
        $this->at('2026-09-01 09:00');
        $this->createLetters($this->hrSec, 3);

        foreach (['letters.register.excel', 'letters.register.pdf'] as $route) {
            $this->actingAs($this->letterUserOf($this->hrSec))
                ->get(route($route, ['from' => '2026-09-01', 'to' => '2026-09-30', 'scope' => 'all']))
                ->assertRedirect(route('letters.register', ['from' => '2026-09-01', 'to' => '2026-09-30', 'scope' => 'all']))
                ->assertSessionHas('error');
        }

        $this->assertSame(0, AuditLog::query()->where('action', 'like', 'export_letter_register%')->count());
    }

    // ---- the page -----------------------------------------------------------------------------------------------

    private function page(Employee $employee)
    {
        $this->actingAs($this->letterUserOf($employee));

        return Livewire::test(Register::class);
    }

    public function test_the_page_defaults_to_the_current_month_and_all(): void
    {
        $this->at('2026-09-15 10:00');

        $this->page($this->hrSec)
            ->assertSet('from', '2026-09-01')
            ->assertSet('to', '2026-09-30')
            ->assertSet('scope', 'all')
            ->assertSee('My register')
            ->assertSee('No letters received in this period.');
    }

    public function test_the_page_previews_25_rows_a_page_from_the_same_service(): void
    {
        $this->at('2026-09-10 09:00');
        foreach (range(1, 30) as $i) {
            $this->createLetter($this->hrSec, ['subject' => sprintf('Letter %02d', $i)]);
            $this->travel(1)->minutes();
        }

        $page = $this->page($this->hrSec)
            ->assertSee('Letter 01')
            ->assertSee('Letter 25')
            ->assertDontSee('Letter 26')
            ->assertSee('Showing 1 - 25 of 30 letters');

        $page->call('gotoPage', 2)
            ->assertSee('Letter 26')
            ->assertSee('Letter 30')
            ->assertDontSee('Letter 01')
            ->assertSee('Showing 26 - 30 of 30 letters');

        $this->assertSame($this->rows($this->hrSec)->pluck('subject')->all(), $this->rows($this->hrSec)->pluck('subject')->all());
    }

    public function test_the_scope_chips_and_dates_filter_the_preview_and_the_export_links_carry_them(): void
    {
        $this->receivedByCm('Stays with cm');
        $this->at('2026-09-06 09:00');
        $sentOn = $this->createLetter($this->cmSec, ['subject' => 'Sent on by cm']);
        $this->lettersWorkflow()->dispatch($sentOn, $this->cmSec, $this->matSec);

        $this->page($this->cmSec)
            ->assertSee('Stays with cm')->assertSee('Sent on by cm')
            ->call('setScope', 'dispatched')
            ->assertSet('scope', 'dispatched')
            ->assertDontSee('Stays with cm')->assertSee('Sent on by cm')
            ->assertSeeHtml(e(route('letters.register.excel', ['from' => '2026-09-01', 'to' => '2026-09-30', 'scope' => 'dispatched'])))
            ->assertSeeHtml(e(route('letters.register.pdf', ['from' => '2026-09-01', 'to' => '2026-09-30', 'scope' => 'dispatched'])))
            ->set('from', '2026-09-07')
            ->assertSee('No letters received in this period.')
            ->assertSeeHtml(e(route('letters.register.excel', ['from' => '2026-09-07', 'to' => '2026-09-30', 'scope' => 'dispatched'])));
    }

    public function test_the_page_shows_the_narrow_the_range_message_over_the_cap(): void
    {
        config(['gwl.letters_register_max_rows' => 2]);
        $this->at('2026-09-01 09:00');
        $this->createLetters($this->hrSec, 3);

        $this->page($this->hrSec)
            ->assertSee('more than the limit of 2')
            ->assertSee('Narrow the date range')
            ->assertDontSee('Export Excel');
    }

    public function test_the_page_needs_letters_export_and_a_linked_employee(): void
    {
        $manager = $this->exporter(['manager'], 'MGR01');
        $this->actingAs($this->letterUserOf($manager));
        Livewire::test(Register::class)->assertForbidden();

        $this->actingAs($this->letterUserOf($this->hrSec));
        $this->get(route('letters.register'))->assertOk()->assertSee('My register');
    }

    public function test_the_sidebar_entry_shows_only_with_letters_export(): void
    {
        $labels = fn (Employee $employee) => collect(app(ErpNavigation::class)->build($this->letterUserOf($employee)->fresh(), 'letters')['sidebar'])->pluck('label')->all();

        $this->assertContains('My register', $labels($this->hrSec));
        $this->assertNotContains('My register', $labels($this->exporter(['manager'], 'MGR01')));
    }

    public function test_letters_export_stays_secretary_only_in_the_seeds(): void
    {
        $seeder = file_get_contents(database_path('seeders/LettersRolePermissionSeeder.php'));

        $this->assertSame(1, substr_count($seeder, 'letters.export'), 'only the secretary line carries it');
        $this->assertMatchesRegularExpression("/'secretary' => \\[[^\\]]*'letters\\.export'/", $seeder);
    }
}
