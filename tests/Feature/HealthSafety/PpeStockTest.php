<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\HsPpeReorderLevel;
use App\Models\HsPpeStockMovement;
use App\Models\HsPpeType;
use App\Services\HealthSafety\PpeSetupService;
use App\Services\HealthSafety\PpeStockService;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PpeStockTest extends HealthSafetyTestCase
{
    private function stock(): PpeStockService
    {
        return app(PpeStockService::class);
    }

    private function officerAtHeadOffice()
    {
        return $this->officer('200001', $this->accraWest, $this->headOffice);
    }

    // ---------------------------------------------------------------- the sign rules

    public function test_every_movement_type_carries_its_sign(): void
    {
        $officer = $this->officerAtHeadOffice();
        $store = $this->ppeStore();
        $other = $this->ppeStore('Second Store');
        $type = $this->ppeType(['name' => 'Hard hat']);

        $receipt = $this->stock()->receive($officer, $store, $type, null, 20, 'DN-1');
        $this->assertSame(['receipt', 20, 'DN-1'], [$receipt->movement_type, $receipt->quantity, $receipt->reference]);

        $writeOff = $this->stock()->writeOff($officer, $store, $type, null, 3, 'Water damage');
        $this->assertSame(['write_off', -3], [$writeOff->movement_type, $writeOff->quantity]);

        $up = $this->stock()->adjust($officer, $store, $type, null, 2, 'Found in a cupboard');
        $down = $this->stock()->adjust($officer, $store, $type, null, -1, 'Miscount');
        $this->assertSame([2, -1], [$up->quantity, $down->quantity]);
        $this->assertSame('adjustment', $up->movement_type);

        ['out' => $out, 'in' => $in] = $this->stock()->transfer($officer, $store, $other, $type, null, 5);
        $this->assertSame(['transfer_out', -5], [$out->movement_type, $out->quantity]);
        $this->assertSame(['transfer_in', 5], [$in->movement_type, $in->quantity]);

        // 20 - 3 + 2 - 1 - 5 = 13 here, 5 there.
        $this->assertSame(13, $this->stock()->balance($store, $type));
        $this->assertSame(5, $this->stock()->balance($other, $type));

        $this->assertSame([], HsPpeStockMovement::query()->where('movement_type', 'receipt')->where('quantity', '<=', 0)->get()->all());
        $this->assertSame(0, HsPpeStockMovement::query()->where('quantity', 0)->count());
    }

    public function test_the_issue_and_return_signs_are_enforced_by_the_ledger_itself(): void
    {
        $officer = $this->officerAtHeadOffice();
        $store = $this->ppeStore();
        $type = $this->ppeType();

        foreach ([
            [HsPpeStockMovement::RECEIPT, -1], [HsPpeStockMovement::RETURN, -1], [HsPpeStockMovement::TRANSFER_IN, -2],
            [HsPpeStockMovement::ISSUE, 1], [HsPpeStockMovement::WRITE_OFF, 4], [HsPpeStockMovement::TRANSFER_OUT, 1],
            [HsPpeStockMovement::ADJUSTMENT, 0],
        ] as [$movementType, $quantity]) {
            try {
                $this->stock()->post($officer, $store, $type, null, $movementType, $quantity);
                $this->fail("{$movementType} with {$quantity} must be refused");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('quantity', $exception->errors());
            }
        }

        $this->assertSame(0, HsPpeStockMovement::query()->count());
    }

    public function test_quantities_must_be_whole_numbers_of_at_least_one(): void
    {
        $officer = $this->officerAtHeadOffice();
        $store = $this->ppeStore();
        $type = $this->ppeType();

        foreach ([0, -4] as $bad) {
            foreach (['receive' => fn () => $this->stock()->receive($officer, $store, $type, null, $bad), 'writeOff' => fn () => $this->stock()->writeOff($officer, $store, $type, null, $bad, 'x')] as $name => $attempt) {
                try {
                    $attempt();
                    $this->fail("{$name} with {$bad} must be refused");
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('quantity', $exception->errors());
                }
            }
        }

        $this->expectException(ValidationException::class);
        $this->stock()->adjust($officer, $store, $type, null, 0, 'Nothing');
    }

    // ---------------------------------------------------------------- never negative

    public function test_a_balance_can_never_go_negative(): void
    {
        $officer = $this->officerAtHeadOffice();
        $store = $this->ppeStore();
        $other = $this->ppeStore('Second Store');
        $type = $this->ppeType(['name' => 'Hard hat']);
        $this->stock()->receive($officer, $store, $type, null, 3);

        $attempts = [
            'write off' => fn () => $this->stock()->writeOff($officer, $store, $type, null, 4, 'Lost in a fire'),
            'adjust' => fn () => $this->stock()->adjust($officer, $store, $type, null, -4, 'Recount'),
            'transfer out' => fn () => $this->stock()->transfer($officer, $store, $other, $type, null, 4),
            'issue' => fn () => $this->stock()->post($officer, $store, $type, null, HsPpeStockMovement::ISSUE, -4),
        ];

        foreach ($attempts as $name => $attempt) {
            try {
                $attempt();
                $this->fail("{$name} below zero must be refused");
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('below zero', $exception->errors()['quantity'][0], $name);
            }
        }

        // Nothing was posted by any of them, and the transfer left no half behind.
        $this->assertSame(3, $this->stock()->balance($store, $type));
        $this->assertSame(0, $this->stock()->balance($other, $type));
        $this->assertSame(1, HsPpeStockMovement::query()->count());

        // Taking exactly what is there is fine.
        $this->stock()->writeOff($officer, $store, $type, null, 3, 'All condemned');
        $this->assertSame(0, $this->stock()->balance($store, $type));
    }

    public function test_the_check_is_per_size_not_just_per_type(): void
    {
        $officer = $this->officerAtHeadOffice();
        $store = $this->ppeStore();
        $boots = $this->bootsType();
        $this->stock()->receive($officer, $store, $boots, '40', 2);
        $this->stock()->receive($officer, $store, $boots, '42', 10);

        // Twelve pairs in total, but only two in size 40.
        try {
            $this->stock()->writeOff($officer, $store, $boots, '40', 5, 'Mouldy');
            $this->fail('Size 40 only has two.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('(40)', $exception->errors()['quantity'][0]);
        }

        $this->assertSame(2, $this->stock()->balance($store, $boots, '40'));
    }

    // ---------------------------------------------------------------- reasons

    public function test_adjustment_and_write_off_need_a_reason_and_it_is_kept(): void
    {
        $officer = $this->officerAtHeadOffice();
        $store = $this->ppeStore();
        $type = $this->ppeType();
        $this->stock()->receive($officer, $store, $type, null, 10);

        foreach ([
            fn () => $this->stock()->adjust($officer, $store, $type, null, 1, ''),
            fn () => $this->stock()->adjust($officer, $store, $type, null, 1, '   '),
            fn () => $this->stock()->writeOff($officer, $store, $type, null, 1, ''),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A reason is required.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('reason', $exception->errors());
            }
        }

        $kept = $this->stock()->writeOff($officer, $store, $type, null, 1, 'Cracked in storage');
        $this->assertSame('Cracked in storage', $kept->notes);
        $this->assertSame(1, HsPpeStockMovement::query()->where('movement_type', 'write_off')->count());
    }

    // ---------------------------------------------------------------- balances after a mixed run

    public function test_balances_are_correct_per_store_type_and_size_after_a_mixed_sequence(): void
    {
        $officer = $this->officerAtHeadOffice();
        $a = $this->ppeStore('Store A');
        $b = $this->ppeStore('Store B');
        $boots = $this->bootsType();
        $hat = $this->ppeType(['name' => 'Hard hat']);

        $this->stock()->receive($officer, $a, $boots, '40', 10);
        $this->stock()->receive($officer, $a, $boots, '41', 6);
        $this->stock()->receive($officer, $a, $hat, null, 30);
        $this->stock()->receive($officer, $b, $boots, '40', 4);
        $this->stock()->writeOff($officer, $a, $boots, '40', 2, 'Damp');
        $this->stock()->transfer($officer, $a, $b, $boots, '41', 3);
        $this->stock()->adjust($officer, $a, $hat, null, -5, 'Recount');
        $this->stock()->transfer($officer, $b, $a, $boots, '40', 1);

        $this->assertSame(9, $this->stock()->balance($a, $boots, '40'));   // 10 - 2 + 1
        $this->assertSame(3, $this->stock()->balance($a, $boots, '41'));   // 6 - 3
        $this->assertSame(25, $this->stock()->balance($a, $hat));          // 30 - 5
        $this->assertSame(3, $this->stock()->balance($b, $boots, '40'));   // 4 - 1
        $this->assertSame(3, $this->stock()->balance($b, $boots, '41'));
        $this->assertSame(12, $this->stock()->total($a, $boots));
        $this->assertSame(6, $this->stock()->total($b, $boots));

        $matrix = $this->stock()->matrix($officer);
        $row = $matrix->first(fn ($r) => $r['store']->is($a) && $r['type']->is($boots));
        $this->assertSame(['40' => 9, '41' => 3, '42' => 0], $row['sizes']);
        $this->assertSame(12, $row['total']);
    }

    // ---------------------------------------------------------------- sizes

    public function test_size_is_required_exactly_when_the_type_has_sizes_and_must_be_listed(): void
    {
        $officer = $this->officerAtHeadOffice();
        $store = $this->ppeStore();
        $boots = $this->bootsType();
        $hat = $this->ppeType(['name' => 'Hard hat']);

        foreach ([null, '', '45'] as $bad) {
            try {
                $this->stock()->receive($officer, $store, $boots, $bad, 1);
                $this->fail('A sized type needs a listed size.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('size', $exception->errors());
            }
        }

        try {
            $this->stock()->receive($officer, $store, $hat, 'L', 1);
            $this->fail('A type without sizes takes none.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('size', $exception->errors());
        }

        $this->stock()->receive($officer, $store, $boots, '41', 1);
        $this->stock()->receive($officer, $store, $hat, null, 1);
        $this->assertSame(2, HsPpeStockMovement::query()->count());
    }

    // ---------------------------------------------------------------- transfers

    public function test_a_transfer_posts_both_rows_with_a_shared_reference(): void
    {
        $officer = $this->officerAtHeadOffice();
        $from = $this->ppeStore('From');
        $to = $this->ppeStore('To');
        $type = $this->ppeType(['name' => 'Hard hat']);
        $this->stock()->receive($officer, $from, $type, null, 10);

        $result = $this->stock()->transfer($officer, $from, $to, $type, null, 4, 'For the new depot');

        $this->assertNotNull($result['out']->reference);
        $this->assertSame($result['out']->reference, $result['in']->reference);
        $this->assertStringStartsWith('TRF-', $result['out']->reference);
        $this->assertSame([$from->id, $to->id], [$result['out']->site_id, $result['in']->site_id]);
        $this->assertSame(0, (int) HsPpeStockMovement::query()->where('reference', $result['out']->reference)->sum('quantity'), 'the two halves cancel');
        $this->assertSame(1, AuditLog::query()->where('action', 'health_safety.ppe_transferred')->count());

        // Two transfers do not share a reference.
        $second = $this->stock()->transfer($officer, $from, $to, $type, null, 1);
        $this->assertNotSame($result['out']->reference, $second['out']->reference);
    }

    public function test_a_transfer_to_a_non_store_an_inactive_store_or_the_same_store_is_refused_and_posts_nothing(): void
    {
        $officer = $this->officerAtHeadOffice();
        $from = $this->ppeStore('From');
        $plainSite = $this->site('Not a store');
        $closed = $this->ppeStore('Closed store');
        $closed->update(['is_active' => false]);
        $type = $this->ppeType(['name' => 'Hard hat']);
        $this->stock()->receive($officer, $from, $type, null, 10);

        foreach ([$plainSite, $closed, $from] as $destination) {
            try {
                $this->stock()->transfer($officer, $from, $destination, $type, null, 2);
                $this->fail('That destination must be refused.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }

        $this->assertSame(1, HsPpeStockMovement::query()->count(), 'only the opening receipt');
        $this->assertSame(10, $this->stock()->balance($from, $type));
    }

    public function test_stock_cannot_be_posted_to_a_site_that_is_not_a_store(): void
    {
        $officer = $this->officerAtHeadOffice();

        $this->expectException(ValidationException::class);

        $this->stock()->receive($officer, $this->site('Plain office'), $this->ppeType(), null, 5);
    }

    public function test_a_regional_officer_cannot_post_into_or_transfer_to_another_regions_store(): void
    {
        $accraOfficer = $this->officer('200001', $this->accraWest, $this->odorkor);
        $mine = $this->ppeStore('Accra Store', $this->odorkor);
        $theirs = $this->ppeStore('Kumasi Store', $this->kumasi);
        $type = $this->ppeType(['name' => 'Hard hat']);
        $this->stock()->receive($accraOfficer, $mine, $type, null, 5);

        foreach ([
            fn () => $this->stock()->receive($accraOfficer, $theirs, $type, null, 1),
            fn () => $this->stock()->writeOff($accraOfficer, $theirs, $type, null, 1, 'x'),
            fn () => $this->stock()->transfer($accraOfficer, $mine, $theirs, $type, null, 1),
            fn () => $this->stock()->transfer($accraOfficer, $theirs, $mine, $type, null, 1),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Another region must be refused.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }

        $this->assertSame(1, HsPpeStockMovement::query()->count());
        $this->assertSame(5, $this->stock()->balance($mine, $type));
    }

    public function test_only_manage_ppe_can_post(): void
    {
        $store = $this->ppeStore();
        $type = $this->ppeType();
        $manager = $this->districtManager('200003', $this->headOffice);   // view_equipment, record_checks: no manage_ppe

        $this->expectException(HttpException::class);

        $this->stock()->receive($manager, $store, $type, null, 1);
    }

    public function test_a_deactivated_type_cannot_be_received(): void
    {
        $officer = $this->officerAtHeadOffice();
        $store = $this->ppeStore();
        $type = $this->ppeType(['is_active' => false]);

        $this->expectException(ValidationException::class);

        $this->stock()->receive($officer, $store, $type, null, 1);
    }

    // ---------------------------------------------------------------- reorder levels and low stock

    public function test_a_reorder_level_flags_low_stock_on_the_total_across_sizes(): void
    {
        $officer = $this->officerAtHeadOffice();
        $store = $this->ppeStore();
        $boots = $this->bootsType();
        $this->stock()->receive($officer, $store, $boots, '40', 3);
        $this->stock()->receive($officer, $store, $boots, '41', 3);

        app(PpeSetupService::class)->saveReorderLevels($officer, [['site_id' => $store->id, 'ppe_type_id' => $boots->id, 'level' => 5]]);

        $row = fn () => $this->stock()->matrix($officer)->first(fn ($r) => $r['type']->is($boots));

        // Six in total, neither size is above five on its own, but the total is what counts: not low.
        $this->assertSame(6, $row()['total']);
        $this->assertFalse($row()['low']);

        $this->stock()->writeOff($officer, $store, $boots, '40', 1, 'Damp');
        $this->assertTrue($row()['low'], 'five in total is at the level');
        $this->assertSame(1, $this->stock()->lowStock($officer)->count());
    }

    public function test_no_reorder_level_means_no_flag_and_zero_stock_is_not_low_without_one(): void
    {
        $officer = $this->officerAtHeadOffice();
        $store = $this->ppeStore();
        $type = $this->ppeType(['name' => 'Hard hat']);

        $this->assertFalse($this->stock()->matrix($officer)->firstWhere(fn ($r) => $r['type']->is($type))['low']);
        $this->assertSame(0, $this->stock()->lowStock($officer)->count());

        app(PpeSetupService::class)->saveReorderLevels($officer, [['site_id' => $store->id, 'ppe_type_id' => $type->id, 'level' => 0]]);
        $this->assertTrue($this->stock()->matrix($officer)->firstWhere(fn ($r) => $r['type']->is($type))['low'], 'a level of 0 flags an empty shelf');

        // Clearing the level removes the flag and the row.
        app(PpeSetupService::class)->saveReorderLevels($officer, [['site_id' => $store->id, 'ppe_type_id' => $type->id, 'level' => '']]);
        $this->assertSame(0, HsPpeReorderLevel::query()->count());
        $this->assertSame(0, $this->stock()->lowStock($officer)->count());
    }

    // ---------------------------------------------------------------- audit and immutability

    public function test_every_movement_is_audited_and_the_ledger_has_no_edit_or_delete(): void
    {
        $officer = $this->officerAtHeadOffice();
        $store = $this->ppeStore();
        $other = $this->ppeStore('Second Store');
        $type = $this->ppeType(['name' => 'Hard hat']);

        $this->stock()->receive($officer, $store, $type, null, 10);
        $this->stock()->adjust($officer, $store, $type, null, 1, 'x');
        $this->stock()->writeOff($officer, $store, $type, null, 1, 'x');
        $this->stock()->transfer($officer, $store, $other, $type, null, 1);

        foreach (['health_safety.ppe_received', 'health_safety.ppe_adjusted', 'health_safety.ppe_written_off', 'health_safety.ppe_transferred'] as $action) {
            $this->assertTrue(AuditLog::query()->where('action', $action)->exists(), $action);
        }

        foreach (['update', 'edit', 'delete', 'remove', 'void', 'cancel'] as $method) {
            $this->assertFalse(method_exists($this->stock(), $method), "PpeStockService has no {$method}");
        }

        $this->assertSame(5, HsPpeStockMovement::query()->count());
    }

    public function test_nothing_is_seeded_into_ppe_types_by_migrate_or_seed(): void
    {
        $this->assertSame(0, HsPpeType::query()->count());
        $this->artisan('db:seed', ['--class' => \Database\Seeders\HealthSafetyRolePermissionSeeder::class]);
        $this->assertSame(0, HsPpeType::query()->count());
        $this->assertSame(0, \App\Models\HsPpeEntitlement::query()->count());
        $this->assertSame(0, \App\Models\HsSite::query()->where('is_ppe_store', true)->count());
    }

    public function test_the_ledger_serialises_on_the_type_row_so_a_double_issue_cannot_go_below_zero(): void
    {
        // SQLite has no row locks to race against, so this pins the mechanism: the post takes the lock inside a
        // transaction and re-reads the balance after writing its own line.
        $source = file_get_contents(app_path('Services/HealthSafety/PpeStockService.php'));

        $this->assertStringContainsString('lockForUpdate()', $source);
        $this->assertStringContainsString('DB::transaction', $source);
        $this->assertLessThan(strpos($source, 'HsPpeStockMovement::query()->create'), strpos($source, 'lockForUpdate()'), 'the lock is taken before the line is written');
    }
}
