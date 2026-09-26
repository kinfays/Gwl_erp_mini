<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\IctAsset;
use App\Models\IctAssetIssueReport;
use App\Models\IctAssetMaintenance;
use App\Models\IctAssetManufacturer;
use App\Models\IctAssetModel;
use App\Models\IctIpRange;
use App\Models\JobTitle;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LetterNotification;
use App\Models\MailLetter;
use App\Models\Permission;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Models\Visitor;
use App\Services\Assets\AssetRecordService;
use App\Services\Assets\IpRangeService;
use App\Services\Leave\LeaveApprovalChainResolver;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeaveEntitlementService;
use App\Services\Leave\LeaveWorkflowService;
use App\Services\Leave\WorkingDaysCalculator;
use App\Services\Letters\LetterWorkflowService;
use App\Services\Visitors\VisitorService;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Explorable demo data scoped to the Accra West region and its districts.
 *
 * Not part of DatabaseSeeder — run it after the normal seed:
 *   php artisan db:seed
 *   php artisan db:seed --class=DemoDataSeeder
 *
 * Everything with derived state goes through the real services
 * (EmployeeObserver, LeaveWorkflowService/LeaveBalanceService, LetterWorkflowService,
 * VisitorService, AssetRecordService). History is back-dated by travelling the
 * clock (Carbon::setTestNow) to each action's moment, so serials, timestamps,
 * balances and notifications line up with when the action "happened".
 *
 * Idempotent: master data is keyed on natural fields (region/district/department/
 * job title names, staff_id, ref_no, serial_number); transactional blocks skip
 * requesters/hosts that already have demo records. Transport and Credit Union are
 * intentionally untouched.
 */
class DemoDataSeeder extends Seeder
{
    protected const REGION_NAME = 'Accra West';

    protected const REGIONAL_OFFICE_NAME = 'Accra West Regional Office';

    protected const DISTRICTS = [
        'darkuman' => 'Darkuman',
        'sowutuom' => 'Sowutuom',
        'amasaman' => 'Amasaman',
        'keneshie' => 'Keneshie',
        'odorkor' => 'Odorkor',
    ];

    protected const DEPARTMENTS = ['Administration', 'HRAS', 'Finance', 'Commercial', 'Operations', 'Distribution', 'ICT'];

    protected const JOB_TITLES = [
        'Regional Chief Manager',
        'District Manager',
        'Manager',
        'Accountant',
        'Assistant Human Resource Officer',
        'ICT Officer',
        'Commercial Officer',
        'Customer Service Officer',
        'Engineer',
        'Meter Reader',
        'Plumber',
        'Secretary',
    ];

    protected const DEMO_PASSWORD = 'Demo@2026';

    protected const EMAIL_DOMAIN = 'gwcl-demo.test';

    protected const RANDOM_SEED = 20260918;

    /** Fixed, administrative demo logins — not leave-workflow actors. */
    protected const DEMO_LOGINS = ['AW0001', 'AW0002'];

    /**
     * Roles the LeaveApprovalChainResolver looks up with ->first() inside a scope,
     * so only one active holder per scope is meaningful.
     */
    protected const CHAIN_ROLES = ['regional_chief_manager', 'district_manager', 'departmental_manager'];

    /**
     * staff_id, full name, gender, location key, department, job title, category, roles.
     * Every non-admin demo user also gets the base `employee` role.
     */
    protected const ROSTER = [
        // Regional office: fixed demo logins
        ['AW0001', 'Kwabena Adu-Gyamfi', 'Male', 'region', 'ICT', 'Manager', 'Management', ['super_admin']],
        ['AW0002', 'Esi Appiah-Kubi', 'Female', 'region', 'ICT', 'ICT Officer', 'Senior Staff', ['admin']],
        // Regional office staff (location_type = Region)
        ['AW1001', 'Kwame Owusu-Ansah', 'Male', 'region', 'Administration', 'Regional Chief Manager', 'Senior Management', ['regional_chief_manager']],
        ['AW1002', 'Abena Boateng', 'Female', 'region', 'Administration', 'Manager', 'Management', ['departmental_manager']],
        ['AW1003', 'Akosua Frimpong', 'Female', 'region', 'Administration', 'Secretary', 'Senior Staff', ['secretary']],
        ['AW1004', 'Priscilla Ankrah', 'Female', 'region', 'Administration', 'Customer Service Officer', 'Junior Staff', ['receptionist']],
        ['AW1005', 'Yaw Darko', 'Male', 'region', 'HRAS', 'Manager', 'Management', ['departmental_manager', 'hr_region']],
        ['AW1006', 'Gifty Amoah', 'Female', 'region', 'HRAS', 'Assistant Human Resource Officer', 'Senior Staff', ['hr_region']],
        ['AW1007', 'Samuel Kyei', 'Male', 'region', 'Finance', 'Manager', 'Management', ['departmental_manager']],
        ['AW1008', 'Mercy Asante', 'Female', 'region', 'Finance', 'Accountant', 'Senior Staff', []],
        ['AW1009', 'Kojo Nkansah', 'Male', 'region', 'Finance', 'Accountant', 'Senior Staff', []],
        ['AW1010', 'Comfort Laryea', 'Female', 'region', 'Commercial', 'Manager', 'Management', ['departmental_manager']],
        ['AW1011', 'Ebenezer Tetteh', 'Male', 'region', 'Commercial', 'Commercial Officer', 'Senior Staff', []],
        ['AW1012', 'Naa Adjeley Quaye', 'Female', 'region', 'Commercial', 'Customer Service Officer', 'Junior Staff', []],
        ['AW1013', 'Daniel Opoku', 'Male', 'region', 'Operations', 'Manager', 'Management', ['departmental_manager']],
        ['AW1014', 'Kwesi Mensah', 'Male', 'region', 'Operations', 'Engineer', 'Senior Staff', []],
        ['AW1015', 'Joyce Agyeman', 'Female', 'region', 'Distribution', 'Manager', 'Management', ['departmental_manager']],
        ['AW1016', 'Prince Sowah', 'Male', 'region', 'Distribution', 'Engineer', 'Senior Staff', []],
        ['AW1017', 'Isaac Bortey', 'Male', 'region', 'ICT', 'Manager', 'Management', ['departmental_manager']],
        ['AW1018', 'Elikem Agbeko', 'Male', 'region', 'ICT', 'ICT Officer', 'Senior Staff', ['ict_team']],
        // Darkuman
        ['AW2001', 'Emmanuel Addo', 'Male', 'darkuman', 'Administration', 'District Manager', 'Management', ['district_manager']],
        ['AW2002', 'Adwoa Sarpong', 'Female', 'darkuman', 'Administration', 'Secretary', 'Senior Staff', ['secretary']],
        ['AW2003', 'Kofi Acheampong', 'Male', 'darkuman', 'Finance', 'Accountant', 'Senior Staff', []],
        ['AW2004', 'Afua Bonsu', 'Female', 'darkuman', 'Commercial', 'Commercial Officer', 'Senior Staff', []],
        ['AW2005', 'Nii Armah Lamptey', 'Male', 'darkuman', 'Commercial', 'Meter Reader', 'Junior Staff', []],
        ['AW2006', 'Yaa Dankwa', 'Female', 'darkuman', 'Commercial', 'Customer Service Officer', 'Junior Staff', []],
        ['AW2007', 'Kwaku Badu', 'Male', 'darkuman', 'Distribution', 'Plumber', 'Junior Staff', []],
        ['AW2008', 'Fiifi Aidoo', 'Male', 'darkuman', 'Operations', 'Engineer', 'Senior Staff', []],
        // Sowutuom
        ['AW3001', 'Akua Ofori', 'Female', 'sowutuom', 'Administration', 'District Manager', 'Management', ['district_manager']],
        ['AW3002', 'Selasi Dogbe', 'Male', 'sowutuom', 'Finance', 'Accountant', 'Senior Staff', []],
        ['AW3003', 'Efua Gyamfi', 'Female', 'sowutuom', 'Commercial', 'Commercial Officer', 'Senior Staff', []],
        ['AW3004', 'Kobina Arthur', 'Male', 'sowutuom', 'Commercial', 'Meter Reader', 'Junior Staff', []],
        ['AW3005', 'Maame Serwaa Yeboah', 'Female', 'sowutuom', 'Commercial', 'Customer Service Officer', 'Junior Staff', []],
        ['AW3006', 'Tetteh Nortey', 'Male', 'sowutuom', 'Distribution', 'Plumber', 'Junior Staff', []],
        ['AW3007', 'Eyram Kpodo', 'Female', 'sowutuom', 'Operations', 'Engineer', 'Senior Staff', []],
        ['AW3008', 'Kwadwo Asamoah', 'Male', 'sowutuom', 'Distribution', 'Plumber', 'Junior Staff', []],
        // Amasaman
        ['AW4001', 'Ebo Quansah', 'Male', 'amasaman', 'Administration', 'District Manager', 'Management', ['district_manager']],
        ['AW4002', 'Dzifa Amegashie', 'Female', 'amasaman', 'Administration', 'Secretary', 'Senior Staff', ['secretary']],
        ['AW4003', 'Stephen Okine', 'Male', 'amasaman', 'ICT', 'ICT Officer', 'Senior Staff', ['ict_team']],
        ['AW4004', 'Abena Konadu', 'Female', 'amasaman', 'Finance', 'Accountant', 'Senior Staff', []],
        ['AW4005', 'Yaw Boakye', 'Male', 'amasaman', 'Commercial', 'Meter Reader', 'Junior Staff', []],
        ['AW4006', 'Ama Serwah Osei', 'Female', 'amasaman', 'Commercial', 'Commercial Officer', 'Senior Staff', []],
        ['AW4007', 'Kwabena Frimpong', 'Male', 'amasaman', 'Distribution', 'Plumber', 'Junior Staff', []],
        ['AW4008', 'Afia Agyei', 'Female', 'amasaman', 'Commercial', 'Customer Service Officer', 'Junior Staff', []],
        // Keneshie
        ['AW5001', 'Kofi Boateng', 'Male', 'keneshie', 'Administration', 'District Manager', 'Management', ['district_manager']],
        ['AW5002', 'Esi Nyarko', 'Female', 'keneshie', 'Finance', 'Accountant', 'Senior Staff', []],
        ['AW5003', 'Kwame Aryee', 'Male', 'keneshie', 'Commercial', 'Commercial Officer', 'Senior Staff', []],
        ['AW5004', 'Naa Dedei Tagoe', 'Female', 'keneshie', 'Commercial', 'Customer Service Officer', 'Junior Staff', []],
        ['AW5005', 'Kwesi Amponsah', 'Male', 'keneshie', 'Commercial', 'Meter Reader', 'Junior Staff', []],
        ['AW5006', 'Nana Yaw Kumi', 'Male', 'keneshie', 'Distribution', 'Plumber', 'Junior Staff', []],
        ['AW5007', 'Adjoa Mensah', 'Female', 'keneshie', 'Operations', 'Engineer', 'Senior Staff', []],
        ['AW5008', 'Theophilus Ashong', 'Male', 'keneshie', 'Distribution', 'Plumber', 'Junior Staff', []],
        // Odorkor
        ['AW6001', 'Mavis Owusu', 'Female', 'odorkor', 'Administration', 'District Manager', 'Management', ['district_manager']],
        ['AW6002', 'Evelyn Ampofo', 'Female', 'odorkor', 'Administration', 'Secretary', 'Senior Staff', ['secretary']],
        ['AW6003', 'Richard Osei', 'Male', 'odorkor', 'Finance', 'Accountant', 'Senior Staff', []],
        ['AW6004', 'Josephine Addai', 'Female', 'odorkor', 'Commercial', 'Commercial Officer', 'Senior Staff', []],
        ['AW6005', 'Michael Ansah', 'Male', 'odorkor', 'Commercial', 'Meter Reader', 'Junior Staff', []],
        ['AW6006', 'Grace Dadzie', 'Female', 'odorkor', 'Commercial', 'Customer Service Officer', 'Junior Staff', []],
        ['AW6007', 'Joseph Ocansey', 'Male', 'odorkor', 'Distribution', 'Plumber', 'Junior Staff', []],
        ['AW6008', 'Patience Aboagye', 'Female', 'odorkor', 'Operations', 'Engineer', 'Senior Staff', []],
    ];

    /** staff_id => [deactivation reason, days ago] */
    protected const INACTIVE = [
        'AW1009' => ['retired', 205],
        'AW2007' => ['left', 310],
        'AW3008' => ['dead', 165],
        'AW6005' => ['left', 80],
    ];

    /** staff_id => ['last'|'this' => plan] for the leave block. */
    protected const SPECIAL_LEAVE = [
        'AW1012' => ['last' => 'maternity'],
        'AW5007' => ['this' => 'maternity'],
        'AW2008' => ['last' => 'paternity'],
        'AW3002' => ['this' => 'paternity'],
        // Casual only passes LeaveWorkflowService::submit() once Annual is used up.
        'AW2005' => ['last' => 'exhaust_then_casual'],
        'AW4006' => ['last' => 'exhaust_then_casual'],
        'AW6004' => ['last' => 'exhaust_then_casual'],
        'AW3003' => ['this' => 'exhaust_then_casual'],
    ];

    /** Casual submissions attempted while Annual remains — the workflow must refuse them. */
    protected const CASUAL_BLOCKED_ATTEMPTS = ['AW1011', 'AW5003'];

    /** ref_no, subject, type, memo-sender key or external company, 'last'|'this' year. */
    protected const LETTERS = [
        ['GES/GW/ADM/118', 'Request for bulk water supply to Amasaman Senior High School', 'External', 'Ghana Education Service – Ga West Municipal', 'last'],
        ['GWL/AWR/DKM/024', 'Monthly revenue performance report – Darkuman District', 'Internal', 'district:darkuman', 'last'],
        ['ECG/AW/OPS/0457', 'Notice of planned power interruption affecting Sowutuom booster station', 'External', 'Electricity Company of Ghana – Accra West Region', 'last'],
        ['GWL/AWR/HR/031', 'Staff redeployment: Commercial Officers to Keneshie District', 'Internal', 'dm:HRAS', 'last'],
        ['PURC/REG/TA/209', 'Tariff adjustment implementation guidelines', 'External', 'Public Utilities Regulatory Commission', 'last'],
        ['GWL/AWR/ODK/017', 'Report on illegal connections detected in Odorkor', 'Internal', 'district:odorkor', 'last'],
        ['GRA/DTRD/PAYE/077', 'Reminder: quarterly PAYE returns submission', 'External', 'Ghana Revenue Authority', 'last'],
        ['GWL/AWR/COM/052', 'Approval for procurement of 200 prepaid meters', 'Internal', 'dm:Commercial', 'last'],
        ['EPA/GA/ENF/336', 'Environmental compliance audit – Darkuman pumping station', 'External', 'Environmental Protection Agency', 'last'],
        ['GPS/ODK/CID/092', 'Police report on vandalised valve chamber at Odorkor', 'External', 'Ghana Police Service – Odorkor Division', 'last'],
        ['GWL/AWR/RCM/009', 'Circular on year-end revenue mobilisation drive', 'Internal', 'rcm', 'last'],
        ['SSNIT/AC/REC/451', 'SSNIT contribution reconciliation for the third quarter', 'External', 'Social Security and National Insurance Trust', 'last'],
        ['GWL/AWR/HR/007', 'Annual leave roster for regional office staff', 'Internal', 'dm:HRAS', 'this'],
        ['GWMA/WKS/019', 'Invitation to stakeholder meeting on the Amasaman–Pokuase road expansion', 'External', 'Ga West Municipal Assembly', 'this'],
        ['GHA/AR/DEV/064', 'Request for relocation of transmission pipeline along the Amasaman–Pokuase road', 'External', 'Ghana Highway Authority', 'this'],
        ['GWL/AWR/SWT/005', 'Customer complaints on low pressure at Sowutuom and Santa Maria', 'Internal', 'district:sowutuom', 'this'],
        ['GNFS/GA/FS/211', 'Request for fire hydrant inspection schedule', 'External', 'Ghana National Fire Service – Greater Accra', 'this'],
        ['GWL/AWR/KNS/011', 'Incident report: burst main at Kaneshie First Light', 'Internal', 'district:keneshie', 'this'],
        ['ANMA/BD/PR/087', 'Demand notice: property rate arrears on Odorkor district office', 'External', 'Ablekuma North Municipal Assembly', 'this'],
        ['GWL/AWR/OPS/023', 'Fleet fuel allocation for district operations', 'Internal', 'dm:Operations', 'this'],
        ['SBG/KAN/CORP/5561', 'Bank confirmation of district collection accounts', 'External', 'Stanbic Bank Ghana – Kaneshie Branch', 'this'],
        ['ATU/IL/PL/042', 'Request for industrial attachment placement for engineering students', 'External', 'Accra Technical University', 'this'],
        ['GWL/AWR/ICT/014', 'ICT equipment audit schedule for district offices', 'Internal', 'dm:ICT', 'this'],
        ['SRA/ADM/003', 'Complaint on estimated billing – Sowutuom Residents Association', 'External', 'Sowutuom Residents Association', 'this'],
        ['MSWR/MIN/VIS/012', 'Notice of ministerial working visit to Accra West Region', 'External', 'Ministry of Sanitation and Water Resources', 'this'],
        ['GWL/AWR/AMS/008', 'Meter reading route realignment – Amasaman District', 'Internal', 'district:amasaman', 'this'],
        ['GWL/AWR/COM/071', 'Disconnection exercise for government institutions in arrears', 'Internal', 'dm:Commercial', 'this'],
        ['BJE/GWL/004', 'Request for extension of service lines to new estate at Busia Junction', 'External', 'Busia Junction Estate Developers Ltd', 'this'],
        ['GWL/AWR/HR/019', 'Health and safety refresher training for field staff', 'Internal', 'dm:HRAS', 'this'],
        ['GWMHD/ADM/033', 'Request for dedicated supply line to Ga West Municipal Hospital', 'External', 'Ga West Municipal Health Directorate', 'this'],
    ];

    protected const ASSET_MANUFACTURERS = ['HP', 'Dell', 'Lenovo', 'Canon', 'Samsung', 'PAX', 'Huawei', 'Cisco', 'Ubiquiti', 'MikroTik'];

    /** model name, category (asset_type key), manufacturer */
    protected const ASSET_MODELS = [
        ['HP ProDesk 400 G7', 'PC', 'HP'],
        ['Dell OptiPlex 7400 All-in-One', 'AIO', 'Dell'],
        ['HP ProBook 450 G9', 'Laptop', 'HP'],
        ['Lenovo ThinkPad E14 Gen 4', 'Laptop', 'Lenovo'],
        ['HP LaserJet Pro M404dn', 'PRT', 'HP'],
        ['Canon imageRUNNER 2425', 'PTC', 'Canon'],
        ['Samsung Galaxy A15', 'Ph', 'Samsung'],
        ['PAX A920 Pro', 'POS', 'PAX'],
        ['Huawei B535 4G Router', '4GRT', 'Huawei'],
        ['Cisco Catalyst 2960-X', 'SW', 'Cisco'],
        ['Ubiquiti UniFi U6 Lite', 'AP', 'Ubiquiti'],
        ['MikroTik hEX RB750Gr3', 'RT', 'MikroTik'],
    ];

    /** Third octet of each location's demo /24 (only created when a location has no range yet). */
    protected const IP_SUBNETS = ['region' => 10, 'darkuman' => 11, 'sowutuom' => 12, 'amasaman' => 13, 'keneshie' => 14, 'odorkor' => 15];

    /**
     * serial, category, type, model, asset name, assignee staff_id (null = unassigned),
     * location key (network devices), status, purchase date, extra fields.
     */
    protected const ASSETS = [
        ['5CD2AW0101', 'asset', 'Laptop', 'HP ProBook 450 G9', 'Laptop – Regional Finance Manager', 'AW1007', null, 'Active', '2023-03-14', []],
        ['PF3AW0102', 'asset', 'Laptop', 'Lenovo ThinkPad E14 Gen 4', 'Laptop – Regional HR Manager', 'AW1005', null, 'Active', '2023-06-02', []],
        ['PF3AW0103', 'asset', 'Laptop', 'Lenovo ThinkPad E14 Gen 4', 'Laptop – Regional Commercial Manager', 'AW1010', null, 'Active', '2023-06-02', []],
        ['5CD2AW0104', 'asset', 'Laptop', 'HP ProBook 450 G9', 'Laptop – Regional Operations Manager', 'AW1013', null, 'Active', '2022-11-21', []],
        ['PF3AW0105', 'asset', 'Laptop', 'Lenovo ThinkPad E14 Gen 4', 'Laptop – Regional ICT Officer', 'AW1018', null, 'Active', '2024-01-18', []],
        ['5CD2AW0106', 'asset', 'Laptop', 'HP ProBook 450 G9', 'Laptop – Regional Accounts', 'AW1009', null, 'Active', '2022-11-21', []],
        ['8CG1AW0107', 'asset', 'PC', 'HP ProDesk 400 G7', 'Desktop – Regional Front Desk', 'AW1004', null, 'Active', '2021-08-09', []],
        ['8CG1AW0108', 'asset', 'PC', 'HP ProDesk 400 G7', 'Desktop – Regional HR Office', 'AW1006', null, 'Active', '2021-08-09', []],
        ['8CG1AW0109', 'asset', 'PC', 'HP ProDesk 400 G7', 'Desktop – Regional Accounts', 'AW1008', null, IctAsset::STATUS_IN_REPAIR, '2021-08-09', []],
        ['CN0AW0110', 'asset', 'AIO', 'Dell OptiPlex 7400 All-in-One', 'All-in-One – Regional Secretariat', 'AW1003', null, 'Active', '2023-02-27', []],
        ['CN0AW0111', 'asset', 'AIO', 'Dell OptiPlex 7400 All-in-One', 'All-in-One – Customer Service Desk', 'AW1012', null, 'Active', '2023-02-27', []],
        ['8CG1AW0112', 'asset', 'PC', 'HP ProDesk 400 G7', 'Desktop – Commercial (old)', 'AW1011', null, IctAsset::STATUS_RETIRED, '2017-05-15', ['notes' => 'Replaced; awaiting disposal board.']],
        ['VNBAW0113', 'asset', 'PRT', 'HP LaserJet Pro M404dn', 'Printer – Regional Secretariat', 'AW1003', null, 'Active', '2022-04-11', []],
        ['CRAW0114', 'asset', 'PTC', 'Canon imageRUNNER 2425', 'Photocopier – Regional Registry', 'AW1003', null, 'Active', '2021-10-04', []],
        ['5CD2AW0201', 'asset', 'Laptop', 'HP ProBook 450 G9', 'Laptop – Darkuman District Manager', 'AW2001', null, 'Active', '2023-03-14', []],
        ['8CG1AW0202', 'asset', 'PC', 'HP ProDesk 400 G7', 'Desktop – Darkuman Accounts', 'AW2003', null, 'Active', '2021-08-09', []],
        ['VNBAW0203', 'asset', 'PRT', 'HP LaserJet Pro M404dn', 'Printer – Darkuman Office', 'AW2002', null, 'Active', '2022-04-11', []],
        ['PF3AW0301', 'asset', 'Laptop', 'Lenovo ThinkPad E14 Gen 4', 'Laptop – Sowutuom District Manager', 'AW3001', null, 'Active', '2023-06-02', []],
        ['8CG1AW0302', 'asset', 'PC', 'HP ProDesk 400 G7', 'Desktop – Sowutuom Accounts', 'AW3002', null, 'Active', '2021-08-09', []],
        ['5CD2AW0401', 'asset', 'Laptop', 'HP ProBook 450 G9', 'Laptop – Amasaman District Manager', 'AW4001', null, 'Active', '2023-03-14', []],
        ['8CG1AW0402', 'asset', 'PC', 'HP ProDesk 400 G7', 'Desktop – Amasaman Accounts', 'AW4004', null, 'Active', '2021-08-09', []],
        ['VNBAW0403', 'asset', 'PRT', 'HP LaserJet Pro M404dn', 'Printer – Amasaman Office', 'AW4002', null, 'Active', '2022-04-11', []],
        ['8CG1AW0501', 'asset', 'PC', 'HP ProDesk 400 G7', 'Desktop – Keneshie Accounts', 'AW5002', null, 'Active', '2021-08-09', []],
        ['PF3AW0601', 'asset', 'Laptop', 'Lenovo ThinkPad E14 Gen 4', 'Laptop – Odorkor District Manager', 'AW6001', null, 'Active', '2023-06-02', []],
        ['8CG1AW0602', 'asset', 'PC', 'HP ProDesk 400 G7', 'Desktop – Odorkor Accounts', 'AW6003', null, 'Active', '2021-08-09', []],
        ['VNBAW0603', 'asset', 'PRT', 'HP LaserJet Pro M404dn', 'Printer – Odorkor Office', 'AW6002', null, 'Active', '2022-04-11', []],
        ['PAXAW2101', 'phone', 'POS', 'PAX A920 Pro', 'POS – Darkuman meter reading', 'AW2005', null, 'Active', '2024-02-05', []],
        ['PAXAW3101', 'phone', 'POS', 'PAX A920 Pro', 'POS – Sowutuom meter reading', 'AW3004', null, 'Active', '2024-02-05', []],
        ['PAXAW4101', 'phone', 'POS', 'PAX A920 Pro', 'POS – Amasaman meter reading', 'AW4005', null, 'Active', '2024-02-05', []],
        ['PAXAW5101', 'phone', 'POS', 'PAX A920 Pro', 'POS – Keneshie meter reading', 'AW5005', null, 'Active', '2024-02-05', []],
        ['PAXAW6101', 'phone', 'POS', 'PAX A920 Pro', 'POS – Odorkor meter reading', 'AW6005', null, IctAsset::STATUS_LOST, '2024-02-05', []],
        ['SMAW2102', 'phone', 'Ph', 'Samsung Galaxy A15', 'Phone – Darkuman District Manager', 'AW2001', null, 'Active', '2024-07-22', []],
        ['SMAW4102', 'phone', 'Ph', 'Samsung Galaxy A15', 'Phone – Amasaman District Manager', 'AW4001', null, 'Active', '2024-07-22', []],
        ['MTAW0901', 'network', 'RT', 'MikroTik hEX RB750Gr3', 'Core Router – Regional Office', null, 'region', 'Active', '2022-01-17', ['ip_host' => 1, 'actual_location' => 'Server room, ground floor']],
        ['CSAW0902', 'network', 'SW', 'Cisco Catalyst 2960-X', 'Core Switch – Regional Office', null, 'region', 'Active', '2022-01-17', ['ip_host' => 2, 'actual_location' => 'Server room, ground floor']],
        ['UBAW0903', 'network', 'AP', 'Ubiquiti UniFi U6 Lite', 'Wi-Fi AP – Regional Conference Room', null, 'region', 'Active', '2023-09-11', ['ip_host' => 20, 'actual_location' => 'Conference room, first floor', 'ssid' => 'GWL-AW-Staff']],
        ['HWAW2901', 'network', '4GRT', 'Huawei B535 4G Router', '4G Router – Darkuman Office', null, 'darkuman', 'Active', '2023-04-03', ['ip_host' => 1, 'actual_location' => 'Accounts office']],
        ['HWAW3901', 'network', '4GRT', 'Huawei B535 4G Router', '4G Router – Sowutuom Office', null, 'sowutuom', 'Active', '2023-04-03', ['ip_host' => 1, 'actual_location' => 'District manager\'s office']],
        ['HWAW4901', 'network', '4GRT', 'Huawei B535 4G Router', '4G Router – Amasaman Office', null, 'amasaman', 'Active', '2023-04-03', ['ip_host' => 1, 'actual_location' => 'ICT cabinet, customer hall']],
        ['UBAW4902', 'network', 'AP', 'Ubiquiti UniFi U6 Lite', 'Wi-Fi AP – Amasaman Customer Hall', null, 'amasaman', 'Active', '2024-03-18', ['ip_host' => 20, 'actual_location' => 'Customer hall ceiling', 'ssid' => 'GWL-AMS-Staff']],
        ['HWAW5901', 'network', '4GRT', 'Huawei B535 4G Router', '4G Router – Keneshie Office', null, 'keneshie', 'Active', '2023-04-03', ['ip_host' => 1, 'actual_location' => 'Front office']],
        ['HWAW6901', 'network', '4GRT', 'Huawei B535 4G Router', '4G Router – Odorkor Office', null, 'odorkor', 'Active', '2023-04-03', ['ip_host' => 1, 'actual_location' => 'Accounts office']],
    ];

    /** Handed over after the original holder left (AssetRecordService records previous_assigned_to). */
    protected const ASSET_REASSIGNMENTS = ['5CD2AW0106' => 'AW1014'];

    /** serial, type, status, opened days ago, completed days ago, location, notes */
    protected const ASSET_MAINTENANCE = [
        ['8CG1AW0109', 'Hardware Repair', 'In Progress', 6, null, 'Regional ICT workshop', 'Desktop fails to boot; replacing failed SSD and reinstalling Windows.'],
        ['VNBAW0113', 'Toner Replacement', 'Completed', 40, 39, 'Regional Secretariat', 'Replaced toner cartridge and cleaned fuser area.'],
        ['5CD2AW0104', 'OS Reinstallation', 'Completed', 75, 73, 'Regional ICT workshop', 'Reimaged after a malware alert; user data restored from backup.'],
        ['CRAW0114', 'Preventive Maintenance', 'Open', 3, null, 'Regional Registry', 'Quarterly vendor service; paper feed rollers due for replacement.'],
        ['HWAW6901', 'Firmware Upgrade', 'Completed', 28, 28, 'Odorkor District Office', 'Upgraded firmware to fix intermittent LTE drops.'],
        ['VNBAW0403', 'Repair', 'Cancelled', 50, null, 'Amasaman District Office', 'Paper jam cleared on site; no repair needed.'],
    ];

    /** title, issue type, reason, status, location key, linked serial, reported days ago, solved days ago */
    protected const ASSET_ISSUES = [
        ['Odorkor office internet keeps dropping', 'Network', 'LTE link drops several times a day; billing uploads failing.', 'Resolved', 'odorkor', 'HWAW6901', 30, 28],
        ['Password reset – Darkuman accounts desktop', 'Password Reset', 'Accountant locked out after password expiry.', 'Closed', 'darkuman', '8CG1AW0202', 55, 55],
        ['BitLocker recovery prompt on Operations laptop', 'BitLocker', 'Laptop asks for the recovery key after a BIOS update.', 'Resolved', 'region', '5CD2AW0104', 76, 75],
        ['Accounts desktop not booting', 'Hardware Fault', 'Machine powers on but shows no display; suspected disk failure.', 'In Progress', 'region', '8CG1AW0109', 6, null],
        ['Billing application freezes on customer lookup', 'Software', 'Customer service desk app hangs when searching by account number.', 'Open', 'keneshie', null, 2, null],
        ['Projector request for district staff durbar', 'Other', 'District requests a projector for the monthly staff durbar.', 'Open', 'sowutuom', null, 4, null],
        ['Weak Wi-Fi in Amasaman customer hall', 'Network', 'Customers and staff report weak signal at the front hall.', 'In Progress', 'amasaman', 'UBAW4902', 12, null],
    ];

    protected const LEAVE_DETAILS = [
        'Annual' => [
            'Annual leave – family visit to Kumasi.',
            'Annual leave to attend a family funeral in Cape Coast.',
            'Rest and travel to Ho.',
            'Annual leave – wedding preparations.',
            'Annual leave for children\'s school resumption.',
            'Annual leave – personal time off.',
            'Travelling home to Tamale for the holidays.',
            'Annual leave to complete professional exams.',
        ],
        'Sick' => [
            'Malaria – excuse duty from Ga West Municipal Hospital.',
            'Recovering from minor surgery at Korle Bu.',
            'Typhoid treatment; medical report attached.',
            'Severe flu, doctor advised bed rest.',
        ],
        'Casual' => [
            'Casual leave to attend to an urgent family matter.',
            'Casual leave – child\'s hospital appointment.',
        ],
        'Maternity' => ['Maternity leave – expected delivery.'],
        'Paternity' => ['Paternity leave – birth of our child.'],
    ];

    protected const RECOMMEND_COMMENTS = [
        'Recommended. Handover arranged with a unit colleague.',
        'Recommended — duties covered for the period.',
        'Recommended.',
        'Recommended; please ensure pending reports are submitted before leaving.',
    ];

    protected const REJECT_COMMENTS = [
        'Not recommended — month-end revenue drive in progress.',
        'Please reschedule; two officers from the unit are already on leave.',
    ];

    protected const APPROVE_COMMENTS = ['Approved.', 'Approved. Enjoy your leave.', 'Approved as recommended.'];

    protected const DENY_COMMENTS = [
        'Denied — conflicts with the regional audit exercise.',
        'Denied; please reapply after the billing cycle closes.',
    ];

    protected const MANAGER_REMARKS = [
        'Noted. Commercial to follow up and report within one week.',
        'Please brief me before the stakeholder meeting.',
        'Approved. Proceed as proposed.',
        'Refer to Operations for technical assessment.',
        'Copy all district managers for their information.',
        'Treat as urgent — coordinate with the district office.',
        'Kindly prepare a response for my signature.',
    ];

    protected const SECRETARY_REMARKS = [
        'Filed, and a copy sent to the district office.',
        'Hardcopy forwarded to the manager\'s office.',
        'Response drafted, awaiting signature.',
        'Logged in the incoming correspondence register.',
    ];

    protected const VISITOR_FIRST_NAMES = [
        'Kwame', 'Kofi', 'Kwesi', 'Yaw', 'Kwabena', 'Kwaku', 'Kojo', 'Nii', 'Ebo', 'Fiifi', 'Selorm', 'Eric', 'Isaac', 'Samuel', 'Emmanuel',
        'Ama', 'Akosua', 'Abena', 'Afua', 'Yaa', 'Adwoa', 'Akua', 'Efua', 'Esi', 'Naa', 'Dzifa', 'Gifty', 'Comfort', 'Mercy', 'Linda',
    ];

    protected const VISITOR_SURNAMES = [
        'Mensah', 'Owusu', 'Boateng', 'Asante', 'Osei', 'Appiah', 'Addo', 'Tetteh', 'Quaye', 'Lamptey', 'Ankrah', 'Adjei', 'Amoah',
        'Darko', 'Nkansah', 'Ofori', 'Sarpong', 'Gyamfi', 'Acheampong', 'Badu', 'Danquah', 'Kyei', 'Yeboah', 'Opoku', 'Aryee', 'Okine',
        'Sowah', 'Laryea', 'Agbeko', 'Kpodo', 'Tagoe', 'Annan', 'Baah', 'Asamoah', 'Fosu', 'Wiredu',
    ];

    protected const VISITOR_PURPOSES = [
        'Bill payment enquiry',
        'Application for a new service connection',
        'Follow-up on meter replacement request',
        'Contractor meeting – pipeline works',
        'Job interview',
        'Delivery of office supplies',
        'Complaint about an estimated bill',
        'Official meeting',
        'Invoice submission',
        'Reconnection request after payment',
        'Internship enquiry',
        'Personal visit',
    ];

    protected const PHONE_PREFIXES = ['024', '054', '055', '059', '020', '050', '027', '057', '026', '053'];

    protected Randomizer $rng;

    protected Carbon $realNow;

    protected Carbon $onboardedAt;

    protected int $thisYear;

    protected int $lastYear;

    protected Region $region;

    /** @var array<string, District> location key => district */
    protected array $locations = [];

    /** @var array<string, Department> */
    protected array $departments = [];

    /** @var array<string, JobTitle> */
    protected array $jobTitles = [];

    /** @var array<string, Employee> staff_id => demo employee */
    protected array $staff = [];

    /** @var array<string, User> staff_id => demo user */
    protected array $users = [];

    /** @var array<string, bool> staff_id => created in this run */
    protected array $createdThisRun = [];

    /** @var array<string, Carbon> */
    protected array $deactivationMoments = [];

    /** @var array<int, array<int, array{0: Carbon, 1: Carbon}>> employee id => booked leave windows */
    protected array $booked = [];

    /** @var array<int, array<int, int>> employee id => year => annual days committed */
    protected array $annualCommitted = [];

    /** @var array<int, string> requesters whose leave history was created this run */
    protected array $leaveSeededFor = [];

    /** @var array<int, string> database notification ids sent during this run */
    protected array $notificationIds = [];

    protected array $locationReport = [];

    protected array $chainReuse = [];

    protected array $counts = [];

    protected LeaveWorkflowService $workflow;

    protected LeaveBalanceService $balances;

    protected LeaveApprovalChainResolver $resolver;

    protected WorkingDaysCalculator $calculator;

    protected LetterWorkflowService $letters;

    protected VisitorService $visitors;

    public function run(): void
    {
        if (! $this->prerequisitesMet()) {
            return;
        }

        $this->rng = new Randomizer(new Mt19937(self::RANDOM_SEED));
        $this->realNow = Carbon::now();
        $this->thisYear = $this->realNow->year;
        $this->lastYear = $this->thisYear - 1;
        $this->onboardedAt = Carbon::create($this->lastYear, 1, 6, 9, 0);

        $this->workflow = app(LeaveWorkflowService::class);
        $this->balances = app(LeaveBalanceService::class);
        $this->resolver = app(LeaveApprovalChainResolver::class);
        $this->calculator = app(WorkingDaysCalculator::class);
        $this->letters = app(LetterWorkflowService::class);
        $this->visitors = app(VisitorService::class);

        $auditBefore = AuditLog::query()->count();

        // Invites (EmployeeObserver) and leave mails are sent synchronously; keep them on this machine.
        $previousMailer = config('mail.default');
        config(['mail.default' => 'array']);

        Event::listen(NotificationSent::class, function (NotificationSent $event): void {
            if ($event->channel === 'database') {
                $this->notificationIds[] = $event->notification->id;
            }
        });

        try {
            $this->actAs($this->bootstrapActor());

            $this->seedLocations();
            $this->seedLookups();
            $this->seedStaff();
            $this->seedHolidays();
            $this->seedLeave();
            $this->seedLetters();
            $this->seedVisitors();
            $this->seedAssets();
            $this->settleNotifications();
        } finally {
            Carbon::setTestNow();
            Auth::guard()->forgetUser();
            config(['mail.default' => $previousMailer]);
        }

        $this->counts['audit_logs'] = AuditLog::query()->count() - $auditBefore;
        $this->counts['notifications'] = count($this->notificationIds);

        $this->printSummary();
    }

    // ---------------------------------------------------------------------
    // Prerequisites
    // ---------------------------------------------------------------------

    protected function prerequisitesMet(): bool
    {
        foreach (['regions', 'districts', 'departments', 'job_titles', 'employees', 'users', 'roles', 'user_roles', 'audit_logs'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->command?->error("Table [{$table}] is missing — run `php artisan migrate` first.");

                return false;
            }
        }

        $roles = collect(self::ROSTER)->pluck(7)->flatten()->push('employee')->unique();
        $missing = $roles->diff(Role::query()->pluck('name'));

        if ($missing->isNotEmpty()) {
            $this->command?->error('Missing roles ('.$missing->join(', ').') — run `php artisan db:seed` before DemoDataSeeder.');

            return false;
        }

        return true;
    }

    protected function bootstrapActor(): ?User
    {
        return User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', User::ROLE_SUPER_ADMIN))
            ->orderBy('id')
            ->first();
    }

    // ---------------------------------------------------------------------
    // Step 1 — region and districts
    // ---------------------------------------------------------------------

    protected function seedLocations(): void
    {
        $this->heading('Step 1 — Accra West region and districts');

        $region = Region::query()
            ->whereRaw('LOWER(TRIM(region_name)) = ?', [Str::lower(self::REGION_NAME)])
            ->orderBy('id')
            ->first();

        if ($region) {
            $this->locationReport[] = ['Region', self::REGION_NAME, 'found', "#{$region->id}"];
        } else {
            $region = $this->at($this->onboardedAt, function () {
                $region = Region::query()->create(['region_name' => self::REGION_NAME]);
                AuditLog::record('create_region', 'staff', 'regions', $region->id, null, $region->toArray());

                return $region;
            });
            $this->locationReport[] = ['Region', self::REGION_NAME, 'created', "#{$region->id}"];
        }

        $this->region = $region;
        $districts = District::query()->where('region_id', $region->id)->orderBy('id')->get();

        // Employee::boot() derives location_type from the district NAME ("... regional office" => Region),
        // so region-level staff sit in a regional-office location under Accra West.
        $office = $districts->first(fn (District $district) => Str::contains(Str::lower($district->district_name), 'regional office'));
        $this->locations['region'] = $office ?? $this->createDistrict(self::REGIONAL_OFFICE_NAME);
        $this->locationReport[] = [
            'Regional office',
            $this->locations['region']->district_name,
            $office ? 'found' : 'created',
            "#{$this->locations['region']->id}",
        ];

        foreach (self::DISTRICTS as $key => $name) {
            $match = $this->matchDistrict($districts, $name);

            if ($match) {
                $spelling = Str::lower($match->district_name) === Str::lower($name) ? '' : " (existing spelling \"{$match->district_name}\")";
                $this->locationReport[] = ['District', $name, 'found'.$spelling, "#{$match->id}"];
                $this->locations[$key] = $match;

                continue;
            }

            $this->locations[$key] = $this->createDistrict($name);
            $this->locationReport[] = ['District', $name, 'created', "#{$this->locations[$key]->id}"];
        }

        $this->command?->table(['Level', 'Name', 'Status', 'Record'], $this->locationReport);
    }

    /**
     * Exact match first, then a one-letter tolerance so e.g. "Keneshie" reuses an
     * existing "Kaneshie" row instead of creating a near-duplicate location.
     */
    protected function matchDistrict(Collection $districts, string $name): ?District
    {
        $target = $this->normalizeLocation($name);
        $candidates = $districts->reject(
            fn (District $district) => Str::contains(Str::lower($district->district_name), ['regional office', 'head office'])
        );

        return $candidates->first(fn (District $district) => $this->normalizeLocation($district->district_name) === $target)
            ?? $candidates->first(fn (District $district) => levenshtein($this->normalizeLocation($district->district_name), $target) <= 1);
    }

    protected function normalizeLocation(string $name): string
    {
        return (string) Str::of($name)->lower()->squish()->replaceMatches('/\s+district(\s+office)?$/', '');
    }

    protected function createDistrict(string $name): District
    {
        return $this->at($this->onboardedAt, function () use ($name) {
            $district = District::query()->create(['region_id' => $this->region->id, 'district_name' => $name]);
            AuditLog::record('create_location', 'staff', 'districts', $district->id, null, $district->toArray());

            return $district;
        });
    }

    // ---------------------------------------------------------------------
    // Departments and job titles (company-wide lookups)
    // ---------------------------------------------------------------------

    protected function seedLookups(): void
    {
        foreach (self::DEPARTMENTS as $name) {
            $this->departments[$name] = $this->lookup(Department::class, 'departments', 'department_name', $name, 'create_department');
        }

        foreach (self::JOB_TITLES as $name) {
            $this->jobTitles[$name] = $this->lookup(JobTitle::class, 'job_titles', 'job_title_name', $name, 'create_job_title');
        }
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return T
     */
    protected function lookup(string $class, string $table, string $column, string $name, string $auditAction): Model
    {
        $existing = $class::query()
            ->whereRaw("LOWER(TRIM({$column})) = ?", [Str::lower($name)])
            ->orderBy('id')
            ->first();

        if ($existing) {
            $this->bump("{$table}_existing");

            return $existing;
        }

        $this->bump("{$table}_created");

        return $this->at($this->onboardedAt, function () use ($class, $table, $column, $name, $auditAction) {
            $record = $class::query()->create([$column => $name]);
            AuditLog::record($auditAction, 'staff', $table, $record->id, null, $record->toArray());

            return $record;
        });
    }

    // ---------------------------------------------------------------------
    // Staff — employees -> users (EmployeeObserver) -> roles
    // ---------------------------------------------------------------------

    protected function seedStaff(): void
    {
        foreach (self::INACTIVE as $staffId => [, $daysAgo]) {
            $this->deactivationMoments[$staffId] = $this->workMoment($this->realNow->copy()->subDays($daysAgo));
        }

        $roster = [];

        foreach (self::ROSTER as $index => $row) {
            [$staffId, , , $locationKey, $departmentName, , , $roleNames] = $row;
            $district = $this->locations[$locationKey];
            $department = $this->departments[$departmentName];

            $chainRole = collect($roleNames)->first(fn (string $role) => in_array($role, self::CHAIN_ROLES, true));

            if ($chainRole && ($holder = $this->existingChainHolder($chainRole, $district, $department, $staffId))) {
                $this->chainReuse[] = [$chainRole, $this->scopeLabel($chainRole, $district, $department), "{$holder->full_name} ({$holder->staff_id})", "{$staffId} not created"];

                continue;
            }

            $employee = Employee::query()->where('staff_id', $staffId)->first();

            if ($employee && (int) $employee->region_id !== (int) $this->region->id) {
                throw new RuntimeException("Staff ID {$staffId} already belongs to an employee outside ".self::REGION_NAME.'; refusing to modify it.');
            }

            if ($employee) {
                $this->bump('employees_existing');
            } else {
                $employee = $this->at(
                    $this->onboardedAt->copy()->addMinutes($index * 4),
                    fn () => $this->createEmployee($row, $district, $department)
                );
                $this->createdThisRun[$staffId] = true;
                $this->bump('employees_created');
            }

            $this->staff[$staffId] = $employee;
            $this->users[$staffId] = $this->userFor($employee);
            $roster[$index] = $row;
        }

        $this->assignRoles($roster);
        $this->configureDemoLogins();
        $this->deactivateLeavers();
    }

    protected function existingChainHolder(string $role, District $district, Department $department, string $staffId): ?Employee
    {
        return Employee::query()
            ->where('is_active', true)
            ->where($this->chainScope($role, $district, $department))
            ->where('staff_id', '!=', $staffId)
            ->whereHas('user.roles', fn ($query) => $query->where('name', $role))
            ->first();
    }

    protected function chainScope(string $role, District $district, Department $department): array
    {
        return match ($role) {
            'regional_chief_manager' => ['region_id' => $this->region->id],
            'district_manager' => ['district_id' => $district->id],
            'departmental_manager' => ['department_id' => $department->id, 'region_id' => $this->region->id],
        };
    }

    protected function scopeLabel(string $role, District $district, Department $department): string
    {
        return match ($role) {
            'regional_chief_manager' => self::REGION_NAME,
            'district_manager' => $district->district_name,
            'departmental_manager' => $department->department_name.' @ '.self::REGION_NAME,
        };
    }

    protected function createEmployee(array $row, District $district, Department $department): Employee
    {
        [$staffId, $name, $gender, $locationKey, , $jobTitle, $category] = $row;
        [$born, $joined, $appointed] = $this->careerDates($staffId, $category);

        $attributes = [
            'staff_id' => $staffId,
            'full_name' => $name,
            'gender' => $gender,
            'category' => $category,
            'email' => $this->emailFor($staffId, $name),
            'job_title_id' => $this->jobTitles[$jobTitle]->id,
            'department_id' => $department->id,
            'region_id' => $district->region_id,
            'district_id' => $district->id,
            // Re-derived from the district name by Employee::boot(); set to the same value for clarity.
            'location_type' => $locationKey === 'region' ? 'Region' : 'District',
            'date_of_birth' => $born->toDateString(),
            'date_joined' => $joined->toDateString(),
            'present_appointment' => $appointed->toDateString(),
            'unit' => null,
            'is_active' => true,
        ];

        // EmployeeObserver::created() creates the user (default password, must_change_password, invite).
        $employee = Employee::query()->create($attributes);

        AuditLog::record('create_employee', 'staff', 'employees', $employee->id, null, Arr::only($employee->toArray(), array_keys($attributes)));

        return $employee;
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: Carbon} date of birth, date joined, present appointment
     */
    protected function careerDates(string $staffId, string $category): array
    {
        if ((self::INACTIVE[$staffId][0] ?? null) === 'retired') {
            $born = $this->deactivationMoments[$staffId]->copy()->startOfDay()
                ->subYears(Employee::RETIREMENT_AGE)
                ->subDays($this->int(3, 25));
        } else {
            [$from, $to] = match ($category) {
                'Senior Management' => [1968, 1973],
                'Management' => [1970, 1982],
                'Senior Staff' => [1978, 1995],
                default => [1982, 1999],
            };
            $born = Carbon::create($this->int($from, $to), $this->int(1, 12), $this->int(1, 28));
        }

        $joined = Carbon::create($born->year + $this->int(23, 29), $this->int(1, 12), $this->int(1, 28));
        $latestJoin = Carbon::create($this->lastYear - 1, 12, 1);

        if ($joined->gt($latestJoin)) {
            $joined = $latestJoin->copy()->subMonths($this->int(1, 30));
        }

        $appointed = $joined->copy()->addYears($this->int(0, max(0, $this->lastYear - 1 - $joined->year)));

        return [$born, $joined, $appointed->gt($latestJoin) ? $latestJoin->copy() : $appointed];
    }

    protected function emailFor(string $staffId, string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [$staffId];
        $local = Str::lower(Str::ascii(Arr::first($parts).'.'.Arr::last($parts)));
        $email = "{$local}@".self::EMAIL_DOMAIN;

        $taken = Employee::query()->where('email', $email)->exists() || User::query()->where('email', $email)->exists();

        return $taken ? Str::lower("{$local}.{$staffId}@".self::EMAIL_DOMAIN) : $email;
    }

    protected function userFor(Employee $employee): User
    {
        $find = fn () => User::query()
            ->where('employee_id', $employee->id)
            ->orWhere('staff_id', $employee->staff_id)
            ->first();

        $user = $find();

        if (! $user) {
            // Re-fires EmployeeObserver::updated(), the same repair path the app relies on.
            $employee->touch();
            $user = $find();
        }

        if (! $user) {
            throw new RuntimeException("No user could be synced for employee {$employee->staff_id}.");
        }

        return $user;
    }

    protected function assignRoles(array $roster): void
    {
        $roleIds = Role::query()->pluck('id', 'name');
        $this->actAs($this->users['AW0001'] ?? $this->bootstrapActor());

        foreach ($roster as $index => [$staffId, , , , , , , $roleNames]) {
            $user = $this->users[$staffId];
            $names = in_array($staffId, self::DEMO_LOGINS, true) ? $roleNames : [...$roleNames, User::ROLE_EMPLOYEE];

            $changes = $user->roles()->syncWithoutDetaching($roleIds->only($names)->values()->all());

            if (empty($changes['attached'])) {
                continue;
            }

            $this->bump('role_assignments', count($changes['attached']));

            // Same audit entry UacController::update() writes when roles are assigned.
            $this->at($this->onboardedAt->copy()->addDay()->addMinutes($index * 2), fn () => Audit::log(
                action: 'update_user',
                module: 'uac.users',
                targetType: 'users',
                targetId: $user->id,
                metadata: [
                    'email' => $user->email,
                    'roles' => $user->roles()->pluck('name')->all(),
                ]
            ));
        }
    }

    protected function configureDemoLogins(): void
    {
        foreach (self::DEMO_LOGINS as $staffId) {
            $user = $this->users[$staffId] ?? null;

            if (! $user) {
                continue;
            }

            $mustChange = Schema::hasColumn('users', 'must_change_password') && $user->must_change_password;

            if ($mustChange || ! Hash::check(self::DEMO_PASSWORD, $user->password)) {
                $payload = ['password' => Hash::make(self::DEMO_PASSWORD)];

                if (Schema::hasColumn('users', 'must_change_password')) {
                    $payload['must_change_password'] = false;
                }

                $user->forceFill($payload)->save();
            }
        }

        // Super admins are kept unlinked from employee records (SuperAdminSeeder, 2026_04_28 migration);
        // staff_id still resolves their region through employeeByStaffId.
        $superAdmin = $this->users['AW0001'] ?? null;

        if ($superAdmin && $superAdmin->employee_id !== null) {
            $superAdmin->forceFill(['employee_id' => null])->save();
        }
    }

    protected function deactivateLeavers(): void
    {
        $this->actAs($this->users['AW1006'] ?? $this->users['AW1005'] ?? $this->users['AW0001'] ?? null);

        foreach (self::INACTIVE as $staffId => [$reason]) {
            $employee = $this->staff[$staffId] ?? null;

            // Never flip records that existed before this run.
            if (! $employee || ! ($this->createdThisRun[$staffId] ?? false) || ! $employee->is_active) {
                continue;
            }

            // Mirrors StaffController::toggleStatus(); EmployeeObserver deactivates the user.
            $this->at($this->deactivationMoments[$staffId], function () use ($employee, $reason) {
                $old = $employee->toArray();
                $employee->update(['is_active' => false, 'deactivation_reason' => $reason]);

                AuditLog::record('deactivate_employee', 'staff', 'employees', $employee->id, $old, $employee->fresh()->toArray());
            });
        }
    }

    // ---------------------------------------------------------------------
    // Holidays
    // ---------------------------------------------------------------------

    protected function seedHolidays(): void
    {
        if (! Schema::hasTable('holidays')) {
            $this->skipped('Holidays', 'holidays table missing');

            return;
        }

        $calendar = new HolidaySeeder;

        foreach ([$this->lastYear, $this->thisYear] as $year) {
            foreach ($calendar->holidaysFor($year) as $holiday) {
                $exists = Holiday::query()
                    ->where('holiday_name', $holiday['holiday_name'])
                    ->whereYear('holiday_date', $year)
                    ->exists();

                if ($exists) {
                    $this->bump('holidays_existing');

                    continue;
                }

                Holiday::query()->create($holiday);
                $this->bump('holidays_created');
            }
        }
    }

    // ---------------------------------------------------------------------
    // Leave — balances, then requests through LeaveWorkflowService
    // ---------------------------------------------------------------------

    protected function seedLeave(): void
    {
        if (! Schema::hasTable('leave_requests') || ! Schema::hasTable('leave_balances')) {
            $this->skipped('Leave', 'leave tables missing');

            return;
        }

        $this->seedLeaveBalances();

        foreach ($this->staff as $staffId => $employee) {
            if (in_array($staffId, self::DEMO_LOGINS, true)) {
                continue;
            }

            $employee->refresh();

            if (LeaveRequest::query()->where('requester_id', $employee->id)->exists()) {
                $this->bump('leave_requesters_existing');

                continue;
            }

            try {
                [, $chief] = $this->resolver->resolve($employee);
            } catch (RuntimeException) {
                $this->bump('leave_requesters_without_chain');

                continue;
            }

            // The regional chief is the final approver of their own chain.
            if ($chief->is($employee)) {
                continue;
            }

            $this->leaveSeededFor[] = $staffId;
            $deactivatedAt = $employee->is_active ? null : ($this->deactivationMoments[$staffId] ?? $employee->updated_at);

            foreach ([$this->lastYear, $this->thisYear] as $year) {
                $this->seedLeaveYear($employee, $staffId, $year, $deactivatedAt);
            }
        }

        $this->attemptBlockedCasualLeave();
    }

    /**
     * Opened through LeaveBalanceService (entitlement + carry-over rules, evaluated
     * today) before any approval, so approvals deduct from these rows rather than
     * opening them part-way through the back-dated history.
     */
    protected function seedLeaveBalances(): void
    {
        foreach ($this->staff as $employee) {
            $types = ['Annual', 'Casual', 'Sick', $employee->gender === 'Female' ? 'Maternity' : 'Paternity'];

            foreach ([$this->lastYear, $this->thisYear] as $year) {
                foreach ($types as $type) {
                    $exists = LeaveBalance::query()
                        ->where('employee_id', $employee->id)
                        ->where('leave_type', $type)
                        ->where('current_year', $year)
                        ->exists();

                    $this->balances->getOrCreateForApproval($employee, $type, $year);
                    $this->bump($exists ? 'leave_balances_existing' : 'leave_balances_created');
                }
            }
        }
    }

    protected function seedLeaveYear(Employee $employee, string $staffId, int $year, ?Carbon $deactivatedAt): void
    {
        $isCurrent = $year === $this->thisYear;
        $special = self::SPECIAL_LEAVE[$staffId][$isCurrent ? 'this' : 'last'] ?? null;
        $from = $isCurrent ? Carbon::create($year, 1, 12) : Carbon::create($year, 2, 2);

        // Latest start for already-decided leave; leavers stop taking leave a few weeks before they go.
        $limits = [Carbon::create($year, 12, 5), $this->realNow->copy()->subDays(3)];

        if ($deactivatedAt) {
            $limits[] = $deactivatedAt->copy()->subDays(21);
        }

        $to = $this->earliest(...$limits);

        if ($special === 'exhaust_then_casual') {
            $this->exhaustAnnualThenCasual($employee, $year, $from, $to);
        } else {
            if ($special === 'maternity') {
                $this->filePastLeave($employee, 'Maternity', 60, $year, $from, $to->copy()->subDays(60), 'approved');
            } elseif ($special === 'paternity') {
                $this->filePastLeave($employee, 'Paternity', 5, $year, $from, $to, 'approved');
            }

            $annualRequests = $isCurrent ? ($this->chance(0.45) ? 1 : 0) : ($this->chance(0.12) ? 2 : 1);

            for ($i = 0; $i < $annualRequests; $i++) {
                $this->filePastLeave($employee, 'Annual', $this->int(5, 15), $year, $from, $to);
            }
        }

        if ($this->chance($isCurrent ? 0.10 : 0.12)) {
            $this->filePastLeave($employee, 'Sick', $this->int(1, 3), $year, $from, $to);
        }

        if ($isCurrent && $employee->is_active && $special !== 'exhaust_then_casual' && $this->chance(0.60)) {
            $this->fileUpcomingLeave($employee);
        }
    }

    protected function exhaustAnnualThenCasual(Employee $employee, int $year, Carbon $from, Carbon $to): void
    {
        $room = $this->annualRoom($employee, $year);
        $first = intdiv($room, 2) + 1;
        $second = $room - $first;

        $a = $this->filePastLeave($employee, 'Annual', $first, $year, $from, $from->copy()->addDays(90), 'approved');
        $b = $this->filePastLeave($employee, 'Annual', $second, $year, $from->copy()->addDays(115), $to->copy()->subDays(40), 'approved');

        if (! $a || ! $b) {
            return;
        }

        $casualFrom = $b->end_date->copy()->addDays(10);

        if ($year === $this->lastYear) {
            $this->filePastLeave($employee, 'Casual', 2, $year, $casualFrom, Carbon::create($year, 12, 5), 'approved');

            return;
        }

        $this->fileUpcomingLeave($employee, 'Casual', 2, 'pending');
    }

    protected function attemptBlockedCasualLeave(): void
    {
        foreach (self::CASUAL_BLOCKED_ATTEMPTS as $staffId) {
            $employee = $this->staff[$staffId] ?? null;

            if (! $employee || ! $employee->is_active || ! in_array($staffId, $this->leaveSeededFor, true)) {
                continue;
            }

            $start = $this->nextWorkingDay($this->realNow->copy()->addDays(10)->startOfDay());
            $end = $this->endAfterWorkingDays($start, 1);

            if ($end->year !== $this->thisYear) {
                continue;
            }

            $this->fileLeave($employee, 'Casual', $start, $end, 'pending', $this->workMoment($this->realNow->copy()->subDays(2)));
        }
    }

    protected function filePastLeave(Employee $employee, string $type, int $days, int $year, Carbon $from, Carbon $to, ?string $outcome = null): ?LeaveRequest
    {
        if ($type === 'Annual') {
            $days = min($days, $this->annualRoom($employee, $year));
        }

        if ($days < 1 || $to->lt($from)) {
            return null;
        }

        $window = $this->pickWindow($employee, $from, $to, $days, $year);

        if (! $window) {
            return null;
        }

        [$start, $end] = $window;
        $outcome ??= $this->pastOutcome();
        $submittedAt = $type === 'Sick'
            ? $this->workMoment($start, 7, 9)
            : $this->workMoment($start->copy()->subDays($this->int(7, 21)));

        return $this->fileLeave($employee, $type, $start, $end, $outcome, $submittedAt);
    }

    protected function fileUpcomingLeave(Employee $employee, string $type = 'Annual', ?int $days = null, ?string $outcome = null): ?LeaveRequest
    {
        if ($outcome === null) {
            $roll = $this->int(1, 100);
            $outcome = match (true) {
                $roll <= 35 => 'pending',
                $roll <= 55 => 'recommended',
                $roll <= 85 => 'planned',
                default => 'approved',
            };
        }

        [$fromDays, $toDays] = match ($outcome) {
            'planned' => [20, 80],
            'approved' => [5, 30],
            default => [7, 45],
        };

        $from = $this->realNow->copy()->addDays($fromDays)->startOfDay();
        $to = $this->earliest($this->realNow->copy()->addDays($toDays)->startOfDay(), Carbon::create($this->thisYear, 12, 5));
        $days ??= $this->int(3, 10);

        if ($type === 'Annual') {
            $days = min($days, $this->annualRoom($employee, $this->thisYear));
        }

        if ($days < 1 || $to->lt($from)) {
            return null;
        }

        $window = $this->pickWindow($employee, $from, $to, $days, $this->thisYear);

        if (! $window) {
            return null;
        }

        [$daysAgoMin, $daysAgoMax] = match ($outcome) {
            'planned' => [1, 20],
            'approved' => [9, 16],
            default => [1, 9],
        };

        $submittedAt = $this->workMoment($this->realNow->copy()->subDays($this->int($daysAgoMin, $daysAgoMax)));

        return $this->fileLeave($employee, $type, $window[0], $window[1], $outcome, $submittedAt);
    }

    /**
     * Runs one request through the real workflow, moving the clock to each step:
     * submit/save -> manager recommendation -> chief decision.
     *
     * Outcomes: planned, pending, recommended, approved, rejected (by manager), denied (by chief).
     */
    protected function fileLeave(Employee $requester, string $type, Carbon $start, Carbon $end, string $outcome, Carbon $submittedAt): ?LeaveRequest
    {
        $data = [
            'leave_type' => $type,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'leave_details' => $this->pick(self::LEAVE_DETAILS[$type]),
        ];

        try {
            $request = $this->at($submittedAt, fn () => $outcome === 'planned'
                ? $this->workflow->savePlanned($requester, $data)
                : $this->workflow->submit($requester, $data));
        } catch (RuntimeException) {
            // e.g. Casual while Annual remains — the workflow's own rule.
            $this->bump($type === 'Casual' ? 'leave_casual_blocked' : 'leave_failed');

            return null;
        }

        $this->booked[$requester->id][] = [$start->copy(), $end->copy()];

        if ($type === 'Annual' && ! in_array($outcome, ['rejected', 'denied'], true)) {
            $this->annualCommitted[$requester->id][$request->request_year] =
                ($this->annualCommitted[$requester->id][$request->request_year] ?? 0) + (int) $request->total_days_applied;
        }

        if (in_array($outcome, ['planned', 'pending'], true)) {
            return $request;
        }

        $moment = $submittedAt;

        if ($request->manager_recommendation === 'Pending') {
            $moment = $this->laterMoment($moment, 2, 30);
            $recommend = $outcome !== 'rejected';

            $this->at($moment, fn () => $this->workflow->recommend(
                $request->manager,
                $request->fresh(),
                $this->pick($recommend ? self::RECOMMEND_COMMENTS : self::REJECT_COMMENTS),
                $recommend
            ));

            if (! $recommend) {
                return $request->fresh();
            }
        } elseif ($outcome === 'rejected') {
            // Managers' own requests skip the recommendation step, so only the chief can refuse.
            $outcome = 'denied';
        }

        if ($outcome === 'recommended') {
            return $request->fresh();
        }

        [, $chief] = $this->resolver->resolve($requester);
        $approve = $outcome === 'approved';
        $moment = $this->laterMoment($moment, 2, 40);

        $this->at($moment, fn () => $this->workflow->finalDecision(
            $chief,
            $request->fresh(),
            $this->pick($approve ? self::APPROVE_COMMENTS : self::DENY_COMMENTS),
            $approve
        ));

        return $request->fresh();
    }

    protected function pastOutcome(): string
    {
        $roll = $this->int(1, 100);

        return match (true) {
            $roll <= 88 => 'approved',
            $roll <= 94 => 'rejected',
            default => 'denied',
        };
    }

    protected function annualRoom(Employee $employee, int $year): int
    {
        return max(0, app(LeaveEntitlementService::class)->entitlementDays('Annual') - ($this->annualCommitted[$employee->id][$year] ?? 0));
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    protected function pickWindow(Employee $employee, Carbon $from, Carbon $to, int $days, int $year): ?array
    {
        $span = (int) $from->diffInDays($to, false);

        if ($span < 0) {
            return null;
        }

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $start = $this->nextWorkingDay($from->copy()->addDays($this->int(0, $span))->startOfDay());
            $end = $this->endAfterWorkingDays($start, $days);

            if ($start->gt($to) || $start->year !== $year || $end->year !== $year) {
                continue;
            }

            $clash = collect($this->booked[$employee->id] ?? [])->contains(
                fn (array $window) => $start->lte($window[1]->copy()->addDays(3)) && $end->gte($window[0]->copy()->subDays(3))
            );

            if (! $clash) {
                return [$start, $end];
            }
        }

        return null;
    }

    /** Same exclusion set WorkingDaysCalculator applies (weekends + observed holidays). */
    protected function nextWorkingDay(Carbon $date): Carbon
    {
        $excluded = $this->calculator->excludedDatesBetween($date, $date->copy()->addDays(14));

        while ($date->isWeekend() || isset($excluded[$date->toDateString()])) {
            $date->addDay();
        }

        return $date;
    }

    protected function endAfterWorkingDays(Carbon $start, int $days): Carbon
    {
        $excluded = $this->calculator->excludedDatesBetween($start, $start->copy()->addDays($days * 2 + 30));
        $end = $start->copy();
        $counted = 0;

        while (true) {
            if (! $end->isWeekend() && ! isset($excluded[$end->toDateString()])) {
                $counted++;
            }

            if ($counted >= $days) {
                return $end;
            }

            $end->addDay();
        }
    }

    // ---------------------------------------------------------------------
    // Letters — LetterWorkflowService (serials, status logs, routing, remarks)
    // ---------------------------------------------------------------------

    protected function seedLetters(): void
    {
        foreach (['mail_letters', 'letter_status_logs', 'routing_histories', 'letter_remarks', 'letter_notifications'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->skipped('Letters', "{$table} table missing");

                return;
            }
        }

        $secretaries = collect(['AW1003', 'AW2002', 'AW4002', 'AW6002'])
            ->map(fn (string $staffId) => $this->staff[$staffId] ?? null)
            ->filter(fn (?Employee $employee) => $employee?->is_active)
            ->values();

        if ($secretaries->count() < 2) {
            $this->skipped('Letters', 'fewer than two demo secretaries available');

            return;
        }

        $hub = $secretaries->first();
        $plans = $this->letterPlans();

        foreach ($plans as $index => $plan) {
            [$ref, $subject, $type, $sender, $createdAt] = $plan;

            if (MailLetter::query()->where('region_id', $this->region->id)->where('ref_no', $ref)->exists()) {
                $this->bump('letters_existing');

                continue;
            }

            $creator = $index % 3 === 2 ? $secretaries[1 + intdiv($index, 3) % ($secretaries->count() - 1)] : $hub;
            $memoSender = $type === 'Internal' ? ($this->memoSender($sender) ?? $creator) : null;

            $this->actAs($this->userOf($creator));
            $letter = $this->at($createdAt, fn () => $this->letters->create($creator, [
                'subject' => $subject,
                'ref_no' => $ref,
                'type' => $type,
                'memo_sender_id' => $memoSender?->id,
                'company_sender' => $type === 'External' ? $sender : null,
                'date_on_letter' => $createdAt->copy()->subDays($this->int(1, 6))->toDateString(),
                'region_id' => $creator->region_id,
            ]));
            $this->bump('letters_created');

            $this->routeLetter($letter, $creator, $secretaries, $hub, $createdAt);
        }
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: string, 3: string, 4: Carbon}>
     */
    protected function letterPlans(): array
    {
        $last = array_values(array_filter(self::LETTERS, fn (array $letter) => $letter[4] === 'last'));
        $this_ = array_values(array_filter(self::LETTERS, fn (array $letter) => $letter[4] === 'this'));
        $plans = [];

        foreach ($last as $i => $letter) {
            $date = Carbon::create($this->lastYear, 2, 10)->addDays((int) round($i * 300 / count($last)) + $this->int(0, 6));
            $plans[] = [...array_slice($letter, 0, 4), $this->workMoment($date)];
        }

        $recentCount = 4;
        $older = count($this_) - $recentCount;
        $olderFrom = Carbon::create($this->thisYear, 1, 12);
        $olderSpan = max(0, (int) $olderFrom->diffInDays($this->realNow->copy()->subDays(9), false));

        foreach ($this_ as $i => $letter) {
            $date = $i < $older
                ? $olderFrom->copy()->addDays((int) round($i * $olderSpan / max(1, $older)))
                : $this->realNow->copy()->subDays([6, 4, 2, 0][$i - $older]);

            $plans[] = [...array_slice($letter, 0, 4), $this->workMoment($date)];
        }

        return $plans;
    }

    protected function memoSender(string $key): ?Employee
    {
        [$kind, $value] = array_pad(explode(':', $key, 2), 2, null);

        return match ($kind) {
            'rcm' => $this->chainHolder('regional_chief_manager', ['region_id' => $this->region->id]),
            'dm' => $this->chainHolder('departmental_manager', ['department_id' => $this->departments[$value]->id, 'region_id' => $this->region->id]),
            'district' => $this->chainHolder('district_manager', ['district_id' => $this->locations[$value]->id]),
            default => null,
        };
    }

    protected function chainHolder(string $role, array $scope): ?Employee
    {
        return Employee::query()
            ->where('is_active', true)
            ->where($scope)
            ->whereHas('user.roles', fn ($query) => $query->where('name', $role))
            ->first();
    }

    protected function routeLetter(MailLetter $letter, Employee $creator, Collection $secretaries, Employee $hub, Carbon $createdAt): void
    {
        $age = (int) $createdAt->diffInDays($this->realNow);
        $moment = $this->laterMoment($createdAt, 0, 2);

        // Opening the letter (ActiveLetters::select) moves the creator's log to "In Review".
        $this->at($moment, fn () => $this->letters->markInReview($letter, $creator));

        // A couple of recent letters are still sitting with their creator.
        if ($age >= 2 && $age <= 3 && $this->chance(0.5)) {
            return;
        }

        $hops = $age <= 1 ? 1 : ($this->chance(0.35) ? 2 : 1);
        $leaveLastUnconfirmed = $age <= 1 || ($age <= 7 && $hops === 2);
        $close = ! $leaveLastUnconfirmed && match (true) {
            $letter->created_at->year === $this->lastYear => $this->chance(0.85),
            $age > 30 => $this->chance(0.55),
            default => false,
        };

        $holder = $creator;
        $visited = [$creator->id];

        for ($hop = 1; $hop <= $hops; $hop++) {
            $options = $secretaries->reject(fn (Employee $secretary) => in_array($secretary->id, $visited, true))->values();

            if ($options->isEmpty()) {
                break;
            }

            $to = ! $holder->is($hub) && $options->contains(fn (Employee $e) => $e->is($hub)) && $this->chance(0.75)
                ? $hub
                : $options[$this->int(0, $options->count() - 1)];

            $moment = $this->laterMoment($moment, 1, 5);
            $this->actAs($this->userOf($holder));
            $this->at($moment, fn () => $this->letters->dispatch($letter, $holder, $to));
            $this->bump('letter_dispatches');
            $visited[] = $to->id;

            if ($leaveLastUnconfirmed && $hop === $hops) {
                return;
            }

            $moment = $this->laterMoment($moment, 2, 26);
            $this->actAs($this->userOf($to));
            $this->at($moment, function () use ($letter, $to) {
                $this->letters->confirmHardcopy($letter, $to);

                // The recipient has opened their letters inbox by now (Letters\Notifications marks it read).
                LetterNotification::query()
                    ->where('letter_id', $letter->id)
                    ->where('secretariat_id', $to->id)
                    ->update(['is_read' => true]);
            });

            $holder = $to;

            if ($this->chance(0.7)) {
                $moment = $this->laterMoment($moment, 1, 24);
                $this->addLetterRemark($letter, $holder, $moment);
            }
        }

        if ($close) {
            $moment = $this->laterMoment($moment, 24, 240);
            $this->actAs($this->userOf($creator));
            $this->at($moment, fn () => $this->letters->close($letter, $creator));
            $this->bump('letters_closed');
        }
    }

    /** Same reviewer rules as ActiveLetters::addRemark(): a manager OR a chief, from the actor's region. */
    protected function addLetterRemark(MailLetter $letter, Employee $secretary, Carbon $moment): void
    {
        $useChief = $this->chance(0.4);
        $reviewers = ($useChief
            ? $this->letters->regionalChiefManagersQuery($secretary)
            : $this->letters->regionalManagersQuery($secretary))->get();

        $demoReviewers = $reviewers->filter(fn (Employee $employee) => isset($this->staff[$employee->staff_id]));
        $pool = ($demoReviewers->isNotEmpty() ? $demoReviewers : $reviewers)->values();

        if ($pool->isEmpty()) {
            return;
        }

        $reviewer = $pool[$this->int(0, $pool->count() - 1)];

        $this->actAs($this->userOf($secretary));
        $this->at($moment, fn () => $this->letters->addRemark($letter, $secretary, [
            'manager_id' => $useChief ? null : $reviewer->id,
            'chief_manager_id' => $useChief ? $reviewer->id : null,
            'remark_content' => $this->pick(self::MANAGER_REMARKS),
            'secretary_remark_content' => $this->chance(0.5) ? $this->pick(self::SECRETARY_REMARKS) : null,
        ]));
        $this->bump('letter_remarks');
    }

    // ---------------------------------------------------------------------
    // Visitors — VisitorService (checkout codes unique among today's visitors inside)
    // ---------------------------------------------------------------------

    protected function seedVisitors(): void
    {
        if (! Schema::hasTable('visitors')) {
            $this->skipped('Visitors', 'visitors table missing');

            return;
        }

        $demoIds = collect($this->staff)->map(fn (Employee $employee) => $employee->id)->values();

        if (Visitor::query()->whereIn('staff_id', $demoIds)->exists()) {
            $this->bump('visitors_block_skipped');

            return;
        }

        // The kiosk sits at the regional office, so regional staff host most visits.
        $hosts = collect($this->staff)
            ->reject(fn (Employee $employee, string $staffId) => $staffId === 'AW0001' || ! $employee->is_active)
            ->flatMap(fn (Employee $employee) => array_fill(0, $employee->district_id === $this->locations['region']->id ? 3 : 1, $employee))
            ->values();

        if ($hosts->isEmpty()) {
            return;
        }

        $receptionist = $this->users['AW1004'] ?? null;
        [$autoHour, $autoMinute] = array_map('intval', explode(':', (string) config('gwl.auto_checkout_time', '18:00')) + [1 => 0]);

        foreach ($this->visitorDays() as $day) {
            $isToday = $day->isSameDay($this->realNow);
            $opening = $day->copy()->setTime(8, 0);
            $lastCheckIn = $isToday ? $this->realNow->copy()->subMinutes(10) : $day->copy()->setTime(15, 30);

            if ($lastCheckIn->lte($opening)) {
                continue;
            }

            $visits = [];
            $phones = [];
            $count = $isToday ? $this->int(5, 8) : $this->int(7, 11);
            $window = (int) $opening->diffInMinutes($lastCheckIn);

            for ($i = 0; $i < $count; $i++) {
                do {
                    $phone = $this->pick(self::PHONE_PREFIXES).str_pad((string) $this->int(0, 9999999), 7, '0', STR_PAD_LEFT);
                } while (isset($phones[$phone]));
                $phones[$phone] = true;

                $visits[] = [
                    'at' => $opening->copy()->addMinutes($this->int(0, $window)),
                    'data' => [
                        'visitor_name' => $this->pick(self::VISITOR_FIRST_NAMES).' '.$this->pick(self::VISITOR_SURNAMES),
                        'phone' => $phone,
                        'staff_id' => $hosts[$this->int(0, $hosts->count() - 1)]->id,
                        'purpose' => $this->pick(self::VISITOR_PURPOSES),
                        'signature' => $this->signature(),
                    ],
                ];
            }

            usort($visits, fn (array $a, array $b) => $a['at'] <=> $b['at']);

            $events = [];

            foreach ($visits as $i => $visit) {
                $events[] = ['at' => $visit['at'], 'kind' => 'in', 'visit' => $i];
                $roll = $this->int(1, 100);
                $mode = $roll <= 50 ? VisitorService::CHECKOUT_SELF : ($roll <= 85 ? VisitorService::CHECKOUT_RECEPTIONIST : VisitorService::CHECKOUT_AUTO);
                $out = $mode === VisitorService::CHECKOUT_AUTO
                    ? $day->copy()->setTime($autoHour, $autoMinute)
                    : $visit['at']->copy()->addMinutes($this->int(15, 150));

                // A few of today's visitors are still inside.
                $stillInside = $isToday && ($out->gte($this->realNow) || $i >= count($visits) - 3);

                if (! $stillInside) {
                    $events[] = ['at' => $out, 'kind' => $mode, 'visit' => $i];
                }
            }

            usort($events, fn (array $a, array $b) => [$a['at'], $a['kind'] === 'in' ? 0 : 1] <=> [$b['at'], $b['kind'] === 'in' ? 0 : 1]);
            $created = [];

            foreach ($events as $event) {
                $index = $event['visit'];

                if ($event['kind'] === 'in') {
                    $created[$index] = $this->at($event['at'], fn () => $this->visitors->checkIn($visits[$index]['data']));
                    $this->bump('visitors_created');

                    continue;
                }

                // Row-scoped rather than VisitorService::autoCheckOutToday(), so visitors that were
                // already in the database for these dates are never touched.
                $this->actAs($event['kind'] === VisitorService::CHECKOUT_RECEPTIONIST ? $receptionist : null);
                $this->at($event['at'], fn () => $this->visitors->checkOut(
                    $created[$index],
                    $event['kind'],
                    $event['kind'] === VisitorService::CHECKOUT_SELF ? $this->signature() : null
                ));
                $this->bump("visitors_checkout_{$event['kind']}");
            }
        }

        $this->counts['visitors_inside'] = Visitor::query()->whereIn('staff_id', $demoIds)->today()->inside()->count();
    }

    /**
     * @return array<int, Carbon> the last eight weekdays, oldest first (today included on a weekday)
     */
    protected function visitorDays(): array
    {
        $days = [];
        $day = $this->realNow->copy()->startOfDay();

        while (count($days) < 8) {
            if (! $day->isWeekend()) {
                $days[] = $day->copy();
            }

            $day->subDay();
        }

        return array_reverse($days);
    }

    /** A small hand-drawn-looking SVG, stored as a data URI like the kiosk's signature pad output. */
    protected function signature(): string
    {
        $y = fn () => $this->int(25, 75);
        $path = sprintf(
            'M%d %d C %d %d, %d %d, %d %d S %d %d, %d %d S %d %d, %d %d',
            $this->int(10, 30), $y(), $this->int(40, 60), $y(), $this->int(60, 90), $y(), $this->int(90, 120), $y(),
            $this->int(140, 170), $y(), $this->int(170, 200), $y(), $this->int(220, 250), $y(), $this->int(260, 290), $y()
        );
        $svg = "<svg xmlns='http://www.w3.org/2000/svg' width='300' height='100' viewBox='0 0 300 100'>"
            ."<path d='{$path}' fill='none' stroke='#1f2937' stroke-width='2.5' stroke-linecap='round'/></svg>";

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    // ---------------------------------------------------------------------
    // Assets — catalogue, IP ranges, devices via AssetRecordService
    // ---------------------------------------------------------------------

    protected function seedAssets(): void
    {
        foreach (['ict_assets', 'ict_asset_models', 'ict_asset_manufacturers'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->skipped('Assets', "{$table} table missing");

                return;
            }
        }

        // Settings > Manufacturers / Models / IP ranges are super_admin-only screens.
        $this->actAs($this->users['AW0001'] ?? $this->bootstrapActor());

        $manufacturers = [];

        foreach (self::ASSET_MANUFACTURERS as $name) {
            $manufacturers[$name] = IctAssetManufacturer::query()->whereRaw('LOWER(name) = ?', [Str::lower($name)])->first()
                ?? $this->at($this->onboardedAt->copy()->addDays(7), function () use ($name) {
                    $manufacturer = IctAssetManufacturer::query()->create(['name' => $name, 'is_active' => true]);
                    AuditLog::record('create_asset_manufacturer', Permission::MODULE_ASSETS, 'ict_asset_manufacturers', $manufacturer->id, null, $manufacturer->toArray());
                    $this->bump('asset_manufacturers_created');

                    return $manufacturer;
                });
        }

        $models = [];

        foreach (self::ASSET_MODELS as [$name, $category, $manufacturer]) {
            $models[$name] = IctAssetModel::query()->where('name', $name)->first()
                ?? $this->at($this->onboardedAt->copy()->addDays(7), function () use ($name, $category, $manufacturer, $manufacturers) {
                    $model = IctAssetModel::query()->create([
                        'name' => $name,
                        'category' => $category,
                        'ict_asset_manufacturer_id' => $manufacturers[$manufacturer]->id,
                        'is_active' => true,
                    ]);
                    AuditLog::record('create_asset_model', Permission::MODULE_ASSETS, 'ict_asset_models', $model->id, null, $model->toArray());
                    $this->bump('asset_models_created');

                    return $model;
                });
        }

        $ranges = Schema::hasTable('ict_ip_ranges') ? $this->seedIpRanges() : [];

        $this->seedAssetRecords($models, $ranges);
        $this->seedAssetMaintenance();
        $this->seedAssetIssues();
    }

    /**
     * @return array<string, IctIpRange> location key => range used for demo device IPs
     */
    protected function seedIpRanges(): array
    {
        $ranges = [];

        foreach (self::IP_SUBNETS as $key => $octet) {
            $district = $this->locations[$key];
            $existing = IctIpRange::query()->where('is_active', true)->where('district_id', $district->id)->orderBy('id')->first();

            if ($existing) {
                $ranges[$key] = $existing;

                continue;
            }

            $ranges[$key] = $this->at($this->onboardedAt->copy()->addDays(8), function () use ($district, $octet) {
                $range = IctIpRange::query()->create([
                    'label' => "{$district->district_name} LAN",
                    'region_id' => $this->region->id,
                    'district_id' => $district->id,
                    'start_ip' => "10.21.{$octet}.1",
                    'end_ip' => "10.21.{$octet}.254",
                    'cidr' => "10.21.{$octet}.0/24",
                    'notes' => 'Office LAN (demo data).',
                    'is_active' => true,
                ]);
                AuditLog::record('create_ip_range', Permission::MODULE_ASSETS, 'ict_ip_ranges', $range->id, null, $range->toArray());
                $this->bump('ip_ranges_created');

                return $range;
            });
        }

        return $ranges;
    }

    protected function seedAssetRecords(array $models, array $ranges): void
    {
        $service = app(AssetRecordService::class);

        foreach (self::ASSETS as $index => [$serial, $category, $type, $modelName, $assetName, $assigneeId, $locationKey, $status, $purchased, $extra]) {
            if (IctAsset::query()->where('serial_number', $serial)->exists()) {
                $this->bump('assets_existing');

                continue;
            }

            $assignee = $assigneeId ? ($this->staff[$assigneeId] ?? null) : null;

            if ($assigneeId && ! $assignee) {
                $this->bump('assets_skipped_no_assignee');

                continue;
            }

            $district = $assignee ? $assignee->district : $this->locations[$locationKey];
            $locationKey ??= array_search($district->id, array_map(fn (District $d) => $d->id, $this->locations), true);

            // Region is never user-selected on the asset forms: it is the recording actor's region.
            $data = [
                'asset_name' => $assetName,
                'serial_number' => $serial,
                'asset_type' => $type,
                'ict_asset_model_id' => $models[$modelName]->id,
                'status' => $status,
                'region_id' => $this->region->id,
                'district_id' => $district->id,
            ];

            $data += match ($category) {
                IctAsset::DEVICE_CATEGORY_ASSET => [
                    'assigned_to_employee_id' => $assignee->id,
                    'department_id' => $assignee->department_id,
                    'purchased_at' => $purchased,
                    'notes' => $extra['notes'] ?? null,
                ],
                IctAsset::DEVICE_CATEGORY_PHONE => [
                    'assigned_to_employee_id' => $assignee?->id,
                    'imei' => '35'.str_pad((string) $this->int(0, 999999999), 9, '0', STR_PAD_LEFT).str_pad((string) $this->int(0, 9999), 4, '0', STR_PAD_LEFT),
                    'device_phone_number' => $this->pick(self::PHONE_PREFIXES).str_pad((string) $this->int(0, 9999999), 7, '0', STR_PAD_LEFT),
                    'user_phone_number' => $this->pick(self::PHONE_PREFIXES).str_pad((string) $this->int(0, 9999999), 7, '0', STR_PAD_LEFT),
                ],
                IctAsset::DEVICE_CATEGORY_NETWORK => [
                    'device_ip' => $this->deviceIp($ranges[$locationKey] ?? null, (int) ($extra['ip_host'] ?? 1)),
                    'actual_location' => $extra['actual_location'] ?? null,
                    'device_username' => 'admin',
                    'login_password' => 'Demo-Only-'.Str::upper(Str::substr($serial, -4)),
                    'ssid' => $extra['ssid'] ?? null,
                    'ssid_password' => isset($extra['ssid']) ? 'demo-wifi-'.Str::lower(Str::substr($serial, -4)) : null,
                ],
            };

            // Devices are registered by the region-scoped ICT officer covering the location.
            $this->actAs($this->ictUserFor($locationKey));
            $this->at(
                $this->workMoment($this->onboardedAt->copy()->addDays(14 + $index)),
                fn () => $service->save(array_filter($data, fn ($value) => $value !== null), $category)
            );
            $this->bump("assets_created_{$category}");
        }

        foreach (self::ASSET_REASSIGNMENTS as $serial => $newStaffId) {
            $asset = IctAsset::query()->where('serial_number', $serial)->first();
            $previous = $asset?->assigned_to_employee_id ? Employee::query()->find($asset->assigned_to_employee_id) : null;
            $newHolder = $this->staff[$newStaffId] ?? null;

            if (! $asset || ! $previous || ! $newHolder || $previous->is($newHolder) || ! isset($this->deactivationMoments[$previous->staff_id])) {
                continue;
            }

            $this->actAs($this->ictUserFor('region'));
            $this->at(
                $this->laterMoment($this->deactivationMoments[$previous->staff_id], 48, 96),
                fn () => $service->save([
                    'assigned_to_employee_id' => $newHolder->id,
                    'department_id' => $newHolder->department_id,
                    'district_id' => $newHolder->district_id,
                    'region_id' => $this->region->id,
                    'notes' => "Handed over from {$previous->full_name} (".Str::lower((string) $previous->deactivation_reason_label).').',
                ], $asset->device_category, $asset)
            );
            $this->bump('assets_reassigned');
        }
    }

    protected function deviceIp(?IctIpRange $range, int $host): ?string
    {
        if (! $range) {
            return null;
        }

        $start = ip2long($range->start_ip);
        $ip = $start === false ? null : long2ip($start + $host - 1);

        return $ip && app(IpRangeService::class)->ipInRange($ip, $range) ? $ip : null;
    }

    protected function ictUserFor(?string $locationKey): ?User
    {
        $officer = $locationKey === 'amasaman' ? 'AW4003' : 'AW1018';

        return $this->users[$officer] ?? $this->users['AW1018'] ?? $this->users['AW4003'] ?? $this->users['AW0001'] ?? null;
    }

    /** Mirrors MaintenanceLog::save() — the maintenance screen writes the row directly. */
    protected function seedAssetMaintenance(): void
    {
        if (! Schema::hasTable('ict_asset_maintenances')) {
            return;
        }

        foreach (self::ASSET_MAINTENANCE as [$serial, $type, $status, $openedDaysAgo, $completedDaysAgo, $location, $notes]) {
            $asset = IctAsset::query()->where('serial_number', $serial)->first();

            if (! $asset || IctAssetMaintenance::query()->where('ict_asset_id', $asset->id)->where('maintenance_type', $type)->exists()) {
                continue;
            }

            $locationKey = $asset->district_id === $this->locations['amasaman']->id ? 'amasaman' : 'region';
            $technician = $this->ictUserFor($locationKey);

            $this->at($this->workMoment($this->realNow->copy()->subDays($openedDaysAgo)), fn () => IctAssetMaintenance::query()->create([
                'ict_asset_id' => $asset->id,
                'maintenance_type' => $type,
                'status' => $status,
                'completion_date' => $completedDaysAgo !== null ? $this->realNow->copy()->subDays($completedDaysAgo)->toDateString() : null,
                'technician' => $technician?->full_name,
                'location' => $location,
                'notes' => $notes,
                'performed_by_user_id' => $technician?->id,
            ]));
            $this->bump('asset_maintenance_created');
        }
    }

    /** Mirrors IssueReports::save() for a region-locked ICT actor. */
    protected function seedAssetIssues(): void
    {
        if (! Schema::hasTable('ict_asset_issue_reports')) {
            return;
        }

        foreach (self::ASSET_ISSUES as [$title, $type, $reason, $status, $locationKey, $serial, $reportedDaysAgo, $solvedDaysAgo]) {
            if (IctAssetIssueReport::query()->where('title', $title)->where('reporting_region_id', $this->region->id)->exists()) {
                continue;
            }

            $reporter = $this->ictUserFor($locationKey);

            $this->at($this->workMoment($this->realNow->copy()->subDays($reportedDaysAgo)), fn () => IctAssetIssueReport::query()->create([
                'title' => $title,
                'issue_type' => $type,
                'reason' => $reason,
                'status' => $status,
                'date_solved' => $solvedDaysAgo !== null ? $this->realNow->copy()->subDays($solvedDaysAgo)->toDateString() : null,
                'linked_asset_id' => $serial ? IctAsset::query()->where('serial_number', $serial)->value('id') : null,
                'reporting_region_id' => $this->region->id,
                'reporting_district_id' => $this->locations[$locationKey]->id,
                'reported_by_user_id' => $reporter?->id,
            ]));
            $this->bump('asset_issues_created');
        }
    }

    // ---------------------------------------------------------------------
    // Notifications
    // ---------------------------------------------------------------------

    /** Bell notifications older than ten days would realistically have been opened. */
    protected function settleNotifications(): void
    {
        if (! Schema::hasTable('notifications') || empty($this->notificationIds)) {
            return;
        }

        $cutoff = $this->realNow->copy()->subDays(10);

        DB::table('notifications')
            ->whereIn('id', $this->notificationIds)
            ->whereNull('read_at')
            ->where('created_at', '<', $cutoff)
            ->orderBy('created_at')
            ->get(['id', 'created_at'])
            ->each(fn ($row) => DB::table('notifications')->where('id', $row->id)->update([
                'read_at' => Carbon::parse($row->created_at)->addHours($this->int(1, 48)),
            ]));
    }

    // ---------------------------------------------------------------------
    // Summary
    // ---------------------------------------------------------------------

    protected function printSummary(): void
    {
        if (! $this->command) {
            return;
        }

        $c = fn (string $key) => $this->counts[$key] ?? 0;
        $demoEmployeeIds = collect($this->staff)->map(fn (Employee $employee) => $employee->id)->values();

        $this->heading('Summary');

        $this->command->table(['Level', 'Name', 'Status', 'Record'], $this->locationReport);

        if ($this->chainReuse) {
            $this->command->line('Existing approval-chain holders reused (LeaveApprovalChainResolver picks the first active holder per scope):');
            $this->command->table(['Role', 'Scope', 'Existing holder', 'Demo roster'], $this->chainReuse);
        }

        $leaveByStatus = Schema::hasTable('leave_requests')
            ? LeaveRequest::query()
                ->whereIn('requester_id', $demoEmployeeIds)
                ->selectRaw('request_year, leave_status, COUNT(*) as total')
                ->groupBy('request_year', 'leave_status')
                ->orderBy('request_year')
                ->get()
                ->map(fn ($row) => "{$row->request_year} {$row->leave_status}: {$row->total}")
                ->join(', ')
            : '—';

        $this->command->table(['Module', 'Result'], [
            ['Departments', "{$c('departments_created')} created, {$c('departments_existing')} reused"],
            ['Job titles', "{$c('job_titles_created')} created, {$c('job_titles_existing')} reused"],
            ['Employees', "{$c('employees_created')} created, {$c('employees_existing')} already present, "
                .collect($this->staff)->filter(fn (Employee $employee) => ! $employee->fresh()->is_active)->count().' inactive'],
            ['Users / roles', count($this->users)." users (via EmployeeObserver), {$c('role_assignments')} role grants"],
            ['Holidays', "{$c('holidays_created')} created, {$c('holidays_existing')} existing ({$this->lastYear}–{$this->thisYear})"],
            ['Leave balances', "{$c('leave_balances_created')} created, {$c('leave_balances_existing')} existing"],
            ['Leave requests', $leaveByStatus ?: 'none'],
            ['Leave notes', "{$c('leave_casual_blocked')} casual submission(s) refused by the annual-balance rule; "
                ."{$c('leave_requesters_existing')} requester(s) already had leave; {$c('leave_requesters_without_chain')} without an approval chain"],
            ['Letters', "{$c('letters_created')} created ({$c('letters_closed')} closed), {$c('letter_dispatches')} dispatches, "
                ."{$c('letter_remarks')} remarks, {$c('letters_existing')} already present"],
            ['Visitors', $c('visitors_block_skipped')
                ? 'skipped — demo visitors already present'
                : "{$c('visitors_created')} visits (self {$c('visitors_checkout_self')}, receptionist {$c('visitors_checkout_receptionist')}, "
                    ."auto {$c('visitors_checkout_auto')}), {$c('visitors_inside')} still inside today"],
            ['Assets', ($c('assets_created_asset') + $c('assets_created_phone') + $c('assets_created_network')).' devices '
                ."(assets {$c('assets_created_asset')}, phones {$c('assets_created_phone')}, network {$c('assets_created_network')}), "
                ."{$c('assets_reassigned')} reassigned, {$c('assets_existing')} already present; "
                ."{$c('asset_manufacturers_created')} manufacturers, {$c('asset_models_created')} models, {$c('ip_ranges_created')} IP ranges; "
                ."{$c('asset_maintenance_created')} maintenance logs, {$c('asset_issues_created')} issue reports"],
            ['Audit logs', "{$c('audit_logs')} written"],
            ['Notifications', "{$c('notifications')} general (bell) notifications from the leave workflow"],
            ['Skipped', 'Transport and Credit Union (not touched)'.(empty($this->counts['skipped']) ? '' : '; '.implode('; ', $this->counts['skipped']))],
        ]);

        $rows = [];

        foreach (self::ROSTER as [$staffId, $name, , $locationKey, , , , $roleNames]) {
            if (! isset($this->staff[$staffId]) || ($roleNames === [] && ! in_array($staffId, self::DEMO_LOGINS, true))) {
                continue;
            }

            $rows[] = [
                $staffId,
                $name,
                implode(', ', $roleNames),
                $this->locations[$locationKey]->district_name,
                in_array($staffId, self::DEMO_LOGINS, true) ? self::DEMO_PASSWORD : User::DEFAULT_PASSWORD.' (change on first login)',
            ];
        }

        $this->command->line('Demo role holders:');
        $this->command->table(['Staff ID', 'Name', 'Roles', 'Location', 'Password'], $rows);

        $this->command->info('Demo super_admin login  →  Staff ID: AW0001   Password: '.self::DEMO_PASSWORD);
        $this->command->info('Demo admin login        →  Staff ID: AW0002   Password: '.self::DEMO_PASSWORD);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** Run $callback with the clock set to $moment (timestamps, serial years, "today" scopes). */
    protected function at(Carbon $moment, callable $callback): mixed
    {
        $previous = Carbon::getTestNow();
        Carbon::setTestNow($moment);

        try {
            return $callback();
        } finally {
            Carbon::setTestNow($previous);
        }
    }

    /** The acting user recorded by AuditLog::record(). */
    protected function actAs(?User $user): void
    {
        $user ? Auth::guard()->setUser($user) : Auth::guard()->forgetUser();
    }

    protected function userOf(Employee $employee): ?User
    {
        return $this->users[$employee->staff_id]
            ?? User::query()->where('employee_id', $employee->id)->orWhere('staff_id', $employee->staff_id)->first();
    }

    /** A plausible office-hours moment on (or the Friday before) $date, never in the future. */
    protected function workMoment(Carbon $date, int $fromHour = 8, int $toHour = 16): Carbon
    {
        $moment = $date->copy();

        while ($moment->isWeekend()) {
            $moment->subDay();
        }

        $moment->setTime($fromHour, 0)->addMinutes($this->int(0, ($toHour - $fromHour) * 60));

        return $moment->gte($this->realNow) ? $this->realNow->copy()->subMinutes($this->int(5, 45)) : $moment;
    }

    /** A later office-hours moment, clamped to the past. */
    protected function laterMoment(Carbon $previous, int $minHours, int $maxHours): Carbon
    {
        $moment = $previous->copy()->addMinutes($this->int(max(10, $minHours * 60), max(10, $maxHours * 60)));

        if ($moment->hour >= 17) {
            $moment->addDay()->setTime(8, $this->int(0, 59));
        } elseif ($moment->hour < 8) {
            $moment->setTime(8, $this->int(0, 59));
        }

        while ($moment->isWeekend()) {
            $moment->addDay();
        }

        $ceiling = $this->realNow->copy()->subMinute();

        if ($moment->gt($ceiling)) {
            $moment = $previous->lt($ceiling) ? $ceiling : $previous->copy()->addSecond();
        }

        return $moment;
    }

    protected function earliest(Carbon ...$dates): Carbon
    {
        return collect($dates)->sort()->first()->copy();
    }

    protected function int(int $min, int $max): int
    {
        return $this->rng->getInt($min, $max);
    }

    protected function chance(float $probability): bool
    {
        return $this->rng->getInt(1, 10000) <= (int) round($probability * 10000);
    }

    protected function pick(array $items): mixed
    {
        $items = array_values($items);

        return $items[$this->rng->getInt(0, count($items) - 1)];
    }

    protected function bump(string $key, int $by = 1): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + $by;
    }

    protected function skipped(string $module, string $reason): void
    {
        $this->counts['skipped'][] = "{$module} ({$reason})";
        $this->command?->warn("Skipping {$module}: {$reason}.");
    }

    protected function heading(string $text): void
    {
        $this->command?->newLine();
        $this->command?->info($text);
    }
}
