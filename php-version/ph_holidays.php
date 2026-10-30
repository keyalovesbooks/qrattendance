<?php
/**
 * Nationwide Philippine non-working holidays taken from annual proclamations.
 * Keep future years synchronized with Official Gazette proclamations; Eid dates
 * are included only once an annual proclamation actually declares them.
 * Sources: Proc. 1006 s. 2025 (2026) and Proc. 1427 s. 2026 (2027).
 */
function philippine_national_holidays(): array
{
    return [
        '2026-01-01' => "New Year's Day",
        '2026-02-17' => 'Chinese New Year',
        '2026-03-20' => "Eid'l Fitr (Feast of Ramadhan)",
        '2026-04-02' => 'Maundy Thursday',
        '2026-04-03' => 'Good Friday',
        '2026-04-04' => 'Black Saturday',
        '2026-04-09' => 'Araw ng Kagitingan',
        '2026-05-01' => 'Labor Day',
        '2026-05-27' => "Eid'l Adha (Feast of Sacrifice)",
        '2026-06-12' => 'Independence Day',
        '2026-08-21' => 'Ninoy Aquino Day',
        '2026-08-31' => 'National Heroes Day',
        '2026-11-01' => "All Saints' Day",
        '2026-11-02' => "All Souls' Day",
        '2026-11-30' => 'Bonifacio Day',
        '2026-12-08' => 'Feast of the Immaculate Conception of Mary',
        '2026-12-24' => 'Christmas Eve',
        '2026-12-25' => 'Christmas Day',
        '2026-12-30' => 'Rizal Day',
        '2026-12-31' => 'Last Day of the Year',

        '2027-01-01' => "New Year's Day",
        '2027-02-06' => 'Chinese New Year',
        '2027-03-25' => 'Maundy Thursday',
        '2027-03-26' => 'Good Friday',
        '2027-03-27' => 'Black Saturday',
        '2027-04-09' => 'Araw ng Kagitingan',
        '2027-05-01' => 'Labor Day',
        '2027-06-12' => 'Independence Day',
        '2027-08-21' => 'Ninoy Aquino Day',
        '2027-08-30' => 'National Heroes Day',
        '2027-11-01' => "All Saints' Day",
        '2027-11-02' => "All Souls' Day",
        '2027-11-30' => 'Bonifacio Day',
        '2027-12-08' => 'Feast of the Immaculate Conception of Mary',
        '2027-12-24' => 'Christmas Eve',
        '2027-12-25' => 'Christmas Day',
        '2027-12-30' => 'Rizal Day',
        '2027-12-31' => 'Last Day of the Year',
    ];
}
