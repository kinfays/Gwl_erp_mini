# Transport: region scoping

Transport used to show every vehicle to everyone who held a Transport permission, and `transport_manager` bypassed the vehicle policy altogether. Since Phase 0 of the G-Water work (`docs/17-module-g-water.md`, section 5) a vehicle belongs to a place and people only reach the vehicles of theirs.

## The rule

| Who | Sees |
| --- | --- |
| `super_admin` | every vehicle, including ones that were never placed |
| Head Office staff (`employees.location_type = HeadOffice`) | only vehicles based in the Head Office district |
| any other member of staff, regional or district | the vehicles of their **region, excluding the Head Office district** |
| a login with no employee record or no region | nothing |
| anyone, for a vehicle with no region (unplaced) | nothing, except `super_admin` |

Head Office is a **district** whose staff and vehicles carry the Head Office district's `region_id`, so the rule never compares region alone: a vehicle is Head Office when its `district_id` is a district whose name says "head office" (the same test as `Employee::locationTypeFor()`), a person is Head Office by `location_type`.

Roles give permissions, not reach. A `transport_manager` in Ashanti manages Ashanti's fleet and nothing else. Anything outside a user's reach answers **404** (not 403), so another region's vehicles cannot be told apart from ones that do not exist.

## Where it lives

`App\Services\Transport\TransportScope` is the only place that decides. Everything else calls it:

- `vehicles($user)` / `Vehicle::visibleTo($user)` / `apply($query, $user)`: the query scope (columns are qualified, safe next to joins).
- `records($query, $user)` / the `ScopedByVehicle` trait (`visibleTo`) on `VehicleExpense`, `MileageLog`, `VehicleIssue`, `MaintenanceRecord` and `VehicleAssignmentHistory`: rows of vehicles the user may reach (soft-deleted vehicles keep their history visible).
- `contains()`, `findVehicle()` (a `ModelNotFoundException`, i.e. 404) and `authorizeVehicle()` (an `abort(404)`): the single-vehicle checks.
- `can($user, $permission)`: `super_admin` or the permission. There is no `transport_manager` bypass anywhere any more.
- `peopleAt()`, `wherePersonAt()`, `personFits()`: who may be put on a vehicle at a place. `managersFor($vehicle)`: who is told about it. `keyFor($user)`: the cache key of a user's reach.

What goes through it:

- `VehiclePolicy`: permission **and** `contains()`; only `super_admin` passes everything (`before()`).
- `VehicleRepository`: every method takes the acting user. `activeAssignmentsForUser()` is deliberately global (one person holds one vehicle, wherever it is).
- Every Livewire screen and its pickers (vehicles, drivers, assigned employees), the dashboard counts, `ReportsService` (including its cache keys), the PDF/Excel exports, `VehicleExpensesExport` (takes the actor), the driver mobile API (through `assignedTo()`).
- `TransportService`: every write checks the vehicle with `authorizeVehicle()`; the acting user defaults to the signed-in one and nobody acting writes nothing. Mileage and issues are written as the driver/reporter.
- Listeners and the `transport:check-expiries` schedule: the command reads every vehicle on purpose (no user), but `TransportNotificationService` tells only `super_admin` and the `transport_manager`s based where the vehicle is.

## The vehicle's location

`vehicles.region_id` and `vehicles.district_id` (both nullable, `restrictOnDelete`). The vehicle form requires a location:

- it is **prefilled with the actor's own** region and district and shown read-only;
- only `super_admin` gets the region/district pickers (a district decides its own region; a missing or inconsistent one is refused);
- for everyone else the service ignores any posted location (a new vehicle gets theirs, an edit keeps the vehicle's own);
- the Vehicles import (`district_name` / `region_name` columns) follows the same rule, and a plate that exists outside the importer's reach is reported as "already registered" without saying where.

Assigning an employee or driver who is not based at the vehicle's place (Head Office vs. the region without it) is refused, on save and on `assignVehicle()`. People already on the vehicle are re-checked only when the vehicle moves.

## Migration and backfill

`2026_10_17_000001_add_location_to_vehicles_table.php` adds the columns and copies the region and district from the vehicle's assigned user, else its assigned driver (through their employee record). A vehicle nobody is assigned to, or whose person has no region, stays NULL: only `super_admin` sees it until someone gives it a location. The migration logs the number placed and unplaced (counts only, no personal data); look for "Transport vehicle location backfill finished" in `storage/logs`. Seeded demo vehicles of `TransportSeeder` are unplaced for the same reason.

## Things that are easy to get wrong

- Never scope by `region_id` alone; Head Office shares a region id with its staff.
- Never fetch a vehicle with `Vehicle::find()` / `findOrFail()` from a client value (a Livewire property is a client value): resolve it through `TransportScope::findVehicle()`.
- A `where(...)->orWhereHas(...)` after the scope escapes it. Group the `or` in a closure (see `Mileage` / `Issues`).
- Cached report figures are keyed by `TransportScope::keyFor()`; a new cached figure must be too.
- The H&S equipment pickers (`PicksEquipmentLocation::vehicleMatches()`) still list vehicles by plate with their own H&S scope; they were not part of Phase 0. `EquipmentScope::canSeeVehicle()` goes through the policy, so following a link to a vehicle is scoped.

Tests: `tests/Feature/Transport/TransportScopeTest.php` (scope, policy, service rules, notifications, backfill) and `TransportScopedScreensTest.php` (screens, exports, API); fixtures in `TransportTestCase`.
