# HR Analytics

A workforce dashboard for HR under **Staff Management -> HR Tools -> HR Analytics** (`leave.hr-analytics`, Livewire `App\Livewire\Leave\HrAnalytics`, controller `LeaveHrAnalyticsController`). It is also linked from the HR dashboard. All the logic is in `App\Services\Hr\HrAnalyticsService`; the page only draws what the service returns.

## Who sees what

| Viewer | Sees |
|---|---|
| `super_admin`, Global Admin (`admin`), Head Office HR (`hr_headoffice`) | every region, and may narrow to one region and/or one department |
| Regional HR (`hr_region`) | their own region only (the region on their employee record, the same rule as the staff list and Staff Reports); the region filter is not offered and a region passed in is ignored |
| anyone else | refused (403 at the route, the controller, the Livewire component and the service) |

Staff holding the `super_admin` role are never counted, for any viewer. The scope is part of the cache key, so a regional HR user can never be served figures cached for the all-regions view. Note that Head Office is a *district* whose staff carry the Head Office district's `region_id`, so regional HR for that region also count Head Office staff, exactly as their staff list does.

## What it shows

**(a) Milestones** (active staff)

- Birthdays this month, and work anniversaries this month (staff who joined in an earlier year). A 29 February birth or hire date falls on the 28th in a common year.
- Staff completing exactly **5, 10, 15 or 20 years** this year, whatever the month (the anniversary date is shown).
- **Approaching retirement**: staff whose retirement date (date of birth + retirement age) is between today and the end of the window, soonest first, and how many active staff are already past it. The page states the age and window used. **Retirement age is `gwl.retirement_age` (default 60, env `GWL_RETIREMENT_AGE`); the window is `gwl.retirement_window_months` (default 12, env `GWL_RETIREMENT_WINDOW_MONTHS`).** `Employee::retirementAge()` reads the same setting, so the staff form's retirement date agrees with this card.

**(b) Headcount**

- Total active, new hires this month and this year (by hire date), exits this month and this year, **turnover**, average tenure, average age and age bands (under 25, 25-34, 35-44, 45-54, 55 and over).
- **Exit** = a deactivated employee with a deactivation date (`employees.deactivated_at`), for any reason **except Transfer**. A transfer is an internal move: it is shown in the exit-reasons chart but left out of the exit count and the turnover rate.
- **Turnover** = exits in the period / the average of the headcount at the start and at the end of the period, as a percentage (this month, and year to date). The headcount on a date is everyone hired by then (no hire date counts as before) and not yet deactivated. A deactivated employee with no deactivation date can't be placed in time, so they count in neither exits nor the headcount history.

**(c) Distribution** (active staff): by department, region, location type (Head Office / Regional Office / District), district (busiest 15), category, grade, age band, and permanent vs contract (Contract = the Contract category, i.e. the Charwoman grade). Category and grade counts link to the filtered staff list.

**(d) Exit reasons** so far this year: Retirement, Resignation, Contract ended, Transfer and Other (the older `left` / `dead` reasons and a missing reason are Other), as a share of all departures including transfers.

**(e) Grade and entitlement**: how many active staff have **no grade** (link to the staff list filtered to them), and this year's Annual entitlement by category: gross days, compulsory leave, available. It reads the stored `leave_entitlements` row where there is one and the calculator otherwise.

**Skipped**: full-time vs part-time. There is no employment-type field to base it on (the only such distinction in the data is permanent vs contract, which is shown); nothing was invented.

## Performance and caching

Every figure is a query-level aggregate (`GROUP BY` counts) or a column-only fetch of the people a list names; nothing queries inside a loop, so the query count does not grow with the number of staff (pinned by a test). The result is a plain array (cached values are unserialised without classes) kept for `gwl.hr_analytics_cache_seconds` (default 120, env `GWL_HR_ANALYTICS_CACHE_SECONDS`; `0` turns it off). The date arithmetic is all in the service, which takes an `$asOf` date (default today) so month and year boundaries can be tested exactly.

## Tests

`tests/Feature/Hr/HrAnalyticsTest.php`: scope per role and refusal of the rest, super_admin never counted, region/department filters, birthdays and anniversaries across month and year boundaries and leap days, the 5/10/15/20-year milestones, retirement edges (window start and end inclusive, leap-day births, configurable age), headcount/hires/exits on boundary days, turnover maths with transfers excluded, distribution, exit reasons, grade and entitlement summary, caching per scope, constant query count, and the page itself.
