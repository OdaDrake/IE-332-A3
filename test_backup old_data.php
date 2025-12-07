<?php
session_start();
date_default_timezone_set('America/Indiana/Indianapolis');

/* If you want to restrict to logged-in users again, uncomment this:
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}
*/

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); 

// DB connection
$servername = "mydb.itap.purdue.edu";
$username   = "odrake";
$password   = "EagleScout#08!!";
$database   = $username;

$conn = new mysqli($servername, $username, $password, $database);
$conn->set_charset('utf8mb4');

if ($conn->connect_error) {
    die("Connection failed: " . htmlspecialchars($conn->connect_error));
}

// -------------------------------------------------
// MODULE 1: Company info table
// -------------------------------------------------
$CompTablesql = "
    SELECT
        c.CompanyID,
        c.CompanyName,
        c.Type,
        c.TierLevel,
        l.City,
        l.CountryName
    FROM Company c
    JOIN Location l
        ON c.LocationID = l.LocationID
";

$CompTableResult = $conn->query($CompTablesql);
if (!$CompTableResult) {
    die("Company table query failed: " . $conn->error);
}

$companyRows = array();
while ($row = $CompTableResult->fetch_assoc()) {
    $companyRows[] = $row;
}

// Sort by CompanyName in PHP
usort($companyRows, function ($a, $b) {
    return strcasecmp($a['CompanyName'], $b['CompanyName']);
});

// -------------------------------------------------
// MODULE 2: Disruption Events 
// -------------------------------------------------
// Date range
$dfStart = isset($_GET['df_start']) ? trim($_GET['df_start']) : '';
$dfEnd   = isset($_GET['df_end'])   ? trim($_GET['df_end'])   : '';

if ($dfStart === '' && $dfEnd === '') {
    $dfEnd   = date('Y-m-d');
    $dfStart = date('Y-m-d', strtotime('-90 days'));
}

// Observation period (days) for DF
$T_days = 1;
$tsStart = strtotime($dfStart);
$tsEnd   = strtotime($dfEnd);
if ($tsStart !== false && $tsEnd !== false && $tsEnd >= $tsStart) {
    $T_days = max(1, floor(($tsEnd - $tsStart) / 86400) + 1);
}

// ---------- Disruption Frequency (DF) by company ----------
$dfLabels = [];
$dfValues = [];

if ($dfStart !== '' && $dfEnd !== '') {
    $dfStartEsc = $conn->real_escape_string($dfStart);
    $dfEndEsc   = $conn->real_escape_string($dfEnd);

    $dfSql = "
        SELECT 
            c.CompanyName,
            COUNT(*) AS num_disruptions
        FROM DisruptionEvent de
        JOIN ImpactsCompany ic ON de.EventID = ic.EventID
        JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
        WHERE de.EventDate >= '$dfStartEsc'
          AND de.EventDate <= '$dfEndEsc'
        GROUP BY c.CompanyID, c.CompanyName
        ORDER BY num_disruptions DESC
    ";

    $dfRes = $conn->query($dfSql);
    if ($dfRes) {
        while ($row = $dfRes->fetch_assoc()) {
            $dfLabels[] = $row['CompanyName'];
            $dfValues[] = $row['num_disruptions'] / $T_days; // DF = N / T
        }
        $dfRes->free();
    }
}

// ---------- Average Recovery Time (ART) histogram ----------
$artBins = [
    '0-2 days'   => 0,
    '3-5 days'   => 0,
    '6-10 days'  => 0,
    '11-20 days' => 0,
    '21+ days'   => 0
];

$totalRecoveryDays = 0;
$recoveryCount     = 0;

if ($dfStart !== '' && $dfEnd !== '') {
    $dfStartEsc = $conn->real_escape_string($dfStart);
    $dfEndEsc   = $conn->real_escape_string($dfEnd);

    $artSql = "
        SELECT 
            DATEDIFF(de.EventRecoveryDate, de.EventDate) AS RecoveryDays
        FROM DisruptionEvent de
        JOIN ImpactsCompany ic ON de.EventID = ic.EventID
        WHERE de.EventDate >= '$dfStartEsc'
          AND de.EventDate <= '$dfEndEsc'
          AND de.EventRecoveryDate IS NOT NULL
    ";

    $artRes = $conn->query($artSql);
    if ($artRes) {
        while ($row = $artRes->fetch_assoc()) {
            $d = (int)$row['RecoveryDays'];
            if ($d < 0) {
                continue;
            }

            $totalRecoveryDays += $d;
            $recoveryCount++;

            if ($d <= 2) {
                $artBins['0-2 days']++;
            } elseif ($d <= 5) {
                $artBins['3-5 days']++;
            } elseif ($d <= 10) {
                $artBins['6-10 days']++;
            } elseif ($d <= 20) {
                $artBins['11-20 days']++;
            } else {
                $artBins['21+ days']++;
            }
        }
        $artRes->free();
    }
}

$overallART = null;
if ($recoveryCount > 0) {
    $overallART = $totalRecoveryDays / $recoveryCount;
}

// ---------- High-Impact Disruption Rate (HDR) Value ----------
$HDRsql = "
    SELECT
        CASE
            WHEN COUNT(*) = 0 THEN NULL
            ELSE 100.0 * SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) / COUNT(*)
        END AS HDR_percent
    FROM DisruptionEvent de
    JOIN ImpactsCompany ic
        ON ic.EventID = de.EventID
    WHERE de.EventDate BETWEEN '$dfStart' and '$dfEnd'    
    ";

$HDRresult = $conn->query($HDRsql);
if (!$HDRresult) {
    die("HDR query failed: " . $conn->error);
}

// ---------- Total Downtime (TD) ----------
$TDsql = "
    SELECT
        DATEDIFF(de.EventRecoveryDate, de.EventDate) AS downtime_days
    FROM DisruptionEvent de
    WHERE de.EventDate BETWEEN '$dfStart' AND '$dfEnd'
        AND de.EventRecoveryDate IS NOT NULL
";

$TDresult = $conn->query($TDsql);
if(!$TDresult) {
    die('Total downtime query failed: ' . $conn->error);
}

$downtimeData = array();
$totalDowntimeDays = 0;

// 1) Collect raw downtime values and total
while ($row = $TDresult->fetch_assoc()) {
    if ($row['downtime_days'] === null) {
        continue;
    }
    $d = (int)$row['downtime_days'];
    if ($d < 0) {
        continue; // safety guard
    }
    $downtimeData[]      = $d;
    $totalDowntimeDays  += $d;
}

// 2) Build histogram bins: downtime_days -> count
$tdBins = array();
foreach ($downtimeData as $d) {
    if (!isset($tdBins[$d])) {
        $tdBins[$d] = 0;
    }
    $tdBins[$d]++;
}

// 3) Sort bins by downtime (x-axis)
ksort($tdBins);

// ---------- Regional Risk Concentration (RRC) ----------
$RRCsql = "
    SELECT
        l.CountryName AS Region,
        COUNT(DISTINCT de.EventID) AS DisruptionCount,
        CASE
            WHEN total.total_count = 0 THEN NULL
            ELSE COUNT(DISTINCT de.EventID) / total.total_count
        END AS RRC_fraction
    FROM DisruptionEvent de
    JOIN ImpactsCompany ic
        ON ic.EventID = de.EventID
    JOIN Company c
        ON c.CompanyID = ic.AffectedCompanyID
    JOIN Location l
        ON l.LocationID = c.LocationID
    JOIN (
        SELECT COUNT(DISTINCT de2.EventID) AS total_count
        FROM DisruptionEvent de2
        JOIN ImpactsCompany ic2
            ON ic2.EventID = de2.EventID
        WHERE de2.EventDate BETWEEN '$dfStart' AND '$dfEnd'
    ) AS total
    WHERE de.EventDate BETWEEN '$dfStart' AND '$dfEnd'
    GROUP BY l.CountryName
    ORDER BY RRC_fraction DESC
";

$RRCresult = $conn->query($RRCsql);
if (!$RRCresult) {
    die('RRC query failed: ' . $conn->error);
}

$rrcRegions = array();   // Country names
$rrcValues  = array();   // Fractions 0–1

while ($row = $RRCresult->fetch_assoc()) {
    if ($row['RRC_fraction'] === null) {
        continue;
    }
    $rrcRegions[] = $row['Region'];
    $rrcValues[]  = (float)$row['RRC_fraction'];
}

// ---------- Disruption Severity Distribution (DSD) ----------
$DSDsql = "
    SELECT
        ic.ImpactLevel,
        COUNT(*) AS cnt
    FROM DisruptionEvent de
    JOIN ImpactsCompany ic
        ON ic.EventID = de.EventID
    WHERE de.EventDate BETWEEN '$dfStart' AND '$dfEnd'
    GROUP BY ic.ImpactLevel
";

$DSDresult = $conn->query($DSDsql);
if(!$DSDresult){
    die('DSD query failed: ' . $conn->error);
}

$dsdCounts = array(
    'Low' => 0,
    'Medium' => 0,
    'High' => 0
);

while ($row = $DSDresult->fetch_assoc()) {
    $level = $row['ImpactLevel'];
    if (isset($dsdCounts[$level])) {
        $dsdCounts[$level] = (int)$row['cnt'];
    }
}

/* Single stacked bar: "All disruptions" split into L / M / H */
$dsdLabel = 'All disruptions';
$dsdLow    = $dsdCounts['Low'];
$dsdMedium = $dsdCounts['Medium'];
$dsdHigh   = $dsdCounts['High'];

// -------------------------------------------------
// MODULE 3: Transaction Analysis 
// -------------------------------------------------
// Mod 3 filters
$tStart = isset($_GET['t_start']) ? $_GET['t_start'] : '';
$tEnd = isset($_GET['t_end'])   ? $_GET['t_end']   : '';
$tCompany = isset($_GET['t_company']) ? (int)$_GET['t_company'] : 0;
$tDirection = isset($_GET['t_direction']) ? $_GET['t_direction'] : 'any'; // 'any','leaving','arriving'
$tCountry = isset($_GET['t_country']) ? $_GET['t_country'] : '';

// Get list of companies for dropdown
$companyOptions = array();
$companySql = "SELECT CompanyID, CompanyName FROM Company ORDER BY CompanyName";
$companyRes = $conn->query($companySql);
if ($companyRes) {
    while ($row = $companyRes->fetch_assoc()) {
        $companyOptions[] = $row;
    }
}

// Get list of countries for dropdown
$countryOptions = array();
$countrySql = "SELECT DISTINCT CountryName FROM Location ORDER BY CountryName";
$countryRes = $conn->query($countrySql);
if ($countryRes) {
    while ($row = $countryRes->fetch_assoc()) {
        $countryOptions[] = $row['CountryName'];
    }
}

// ---- BUILD TRANSACTION QUERY CONDITIONS ----
$conditions = array();

// Date range: filter by PromisedDate so shipments without ActualDate (still in transit) are included
if ($tStart !== '' && $tEnd !== '') {
    $conditions[] = "s.PromisedDate BETWEEN '" . $conn->real_escape_string($tStart) .
                    "' AND '" . $conn->real_escape_string($tEnd) . "'";
}

// Company filter: leaving or arriving a specific company
if ($tCompany > 0) {
    if ($tDirection === 'leaving') {
        $conditions[] = "s.SourceCompanyID = " . $tCompany;
    } elseif ($tDirection === 'arriving') {
        $conditions[] = "s.DestinationCompanyID = " . $tCompany;
    } else { // any
        $conditions[] = "(s.SourceCompanyID = " . $tCompany . " OR s.DestinationCompanyID = " . $tCompany . ")";
    }
}

// Country filter: either source OR destination in that country
if ($tCountry !== '') {
    $safeCountry = $conn->real_escape_string($tCountry);
    $conditions[] = "(srcLoc.CountryName = '" . $safeCountry . "' OR destLoc.CountryName = '" . $safeCountry . "')";
}

$whereClause = '';
if (count($conditions) > 0) {
    $whereClause = "WHERE " . implode(" AND ", $conditions);
}

// ---- MAIN TRANSACTION QUERY ----
$txSql = "
    SELECT
        s.ShipmentID,
        s.PromisedDate,
        s.ActualDate,
        s.Quantity,
        p.ProductName,

        src.CompanyName      AS SourceCompany,
        srcLoc.City          AS SourceCity,
        srcLoc.CountryName   AS SourceCountry,

        dest.CompanyName     AS DestCompany,
        destLoc.City         AS DestCity,
        destLoc.CountryName  AS DestCountry,

        dist.CompanyName     AS DistributorName,

        DATEDIFF(s.ActualDate, s.PromisedDate) AS DelayDays,
        CASE
            WHEN s.ActualDate IS NULL THEN 'In Transit'
            WHEN s.ActualDate <= s.PromisedDate THEN 'On Time'
            ELSE 'Late'
        END AS StatusLabel
    FROM Shipping s
    JOIN Company src
        ON s.SourceCompanyID = src.CompanyID
    JOIN Location srcLoc
        ON src.LocationID = srcLoc.LocationID
    JOIN Company dest
        ON s.DestinationCompanyID = dest.CompanyID
    JOIN Location destLoc
        ON dest.LocationID = destLoc.LocationID
    JOIN Company dist
        ON s.DistributorID = dist.CompanyID
    JOIN Product p
        ON s.ProductID = p.ProductID
    $whereClause
    ORDER BY s.PromisedDate DESC, s.ShipmentID DESC
";

$txResult = $conn->query($txSql);
if (!$txResult) {
    die("Transaction query failed: " . $conn->error);
}

$transactions = array();
while ($row = $txResult->fetch_assoc()) {
    $transactions[] = $row;
}

// ---------- Distributor summary (per-distributor KPIs) ----------
// Reuse the transaction filters in $whereClause, but restrict to distributor companies.

$distWhere = $whereClause; // e.g. "WHERE s.PromisedDate BETWEEN ... AND ... AND (srcLoc.CountryName=... OR destLoc.CountryName=...)"

if ($distWhere === '') {
    $distWhere = "WHERE dist.Type = 'Distributor'";
} else {
    $distWhere .= " AND dist.Type = 'Distributor'";
}

// Build WHERE for disruption exposure (per distributor), reusing date + country filters
$expConds = array();

// Date filter
if ($tStart !== '' && $tEnd !== '') {
    $expConds[] = "de.EventDate BETWEEN '" . $conn->real_escape_string($tStart) .
                  "' AND '" . $conn->real_escape_string($tEnd) . "'";
}

// Country filter: distributor's own location
if ($tCountry !== '') {
    $safeCountryExp = $conn->real_escape_string($tCountry);
    $expConds[] = "l.CountryName = '" . $safeCountryExp . "'";
}

if (count($expConds) === 0) {
    $expWhere = "1 = 1"; // always true
} else {
    $expWhere = implode(" AND ", $expConds);
}

// Main distributor KPI query
$distSql = "
    SELECT
        dist.CompanyID      AS DistributorID,
        dist.CompanyName    AS DistributorName,
        distLoc.City        AS DistributorCity,
        distLoc.CountryName AS DistributorCountry,

        COUNT(*) AS ShipmentVolume,
        SUM(CASE WHEN s.ActualDate IS NULL THEN 1 ELSE 0 END) AS InTransitCount,
        SUM(CASE WHEN s.ActualDate IS NOT NULL AND s.ActualDate <= s.PromisedDate THEN 1 ELSE 0 END) AS OnTimeCount,
        SUM(CASE WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate THEN 1 ELSE 0 END) AS LateCount,
        COUNT(DISTINCT s.ProductID) AS ProductCount,

        COALESCE(exp.TotalEvents, 0) AS TotalEvents,
        COALESCE(exp.HighEvents, 0)  AS HighEvents,
        COALESCE(exp.TotalEvents, 0) + 2 * COALESCE(exp.HighEvents, 0) AS ExposureScore

    FROM Shipping s
    JOIN Company src      ON s.SourceCompanyID = src.CompanyID
    JOIN Location srcLoc  ON src.LocationID = srcLoc.LocationID
    JOIN Company dest     ON s.DestinationCompanyID = dest.CompanyID
    JOIN Location destLoc ON dest.LocationID = destLoc.LocationID

    JOIN Company dist     ON s.DistributorID = dist.CompanyID
    JOIN Location distLoc ON dist.LocationID = distLoc.LocationID

    LEFT JOIN (
        SELECT
            ic.AffectedCompanyID AS DistributorID,
            COUNT(*) AS TotalEvents,
            SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) AS HighEvents
        FROM DisruptionEvent de
        JOIN ImpactsCompany ic ON ic.EventID = de.EventID
        JOIN Company c         ON c.CompanyID = ic.AffectedCompanyID
        JOIN Location l        ON l.LocationID = c.LocationID
        WHERE $expWhere
        GROUP BY ic.AffectedCompanyID
    ) AS exp
        ON exp.DistributorID = dist.CompanyID

    $distWhere
    GROUP BY
        dist.CompanyID,
        dist.CompanyName,
        distLoc.City,
        distLoc.CountryName,
        exp.TotalEvents,
        exp.HighEvents
    ORDER BY ShipmentVolume DESC
";

$distResult = $conn->query($distSql);
if (!$distResult) {
    die("Distributor summary query failed: " . $conn->error);
}

$distributors = array();
while ($row = $distResult->fetch_assoc()) {
    // On-time rate based on delivered shipments (OnTime + Late)
    $delivered = (int)$row['OnTimeCount'] + (int)$row['LateCount'];
    if ($delivered > 0) {
        $row['OnTimeRate'] = 100.0 * (int)$row['OnTimeCount'] / $delivered;
    } else {
        $row['OnTimeRate'] = null;
    }
    $distributors[] = $row;
}

// Module 3: data for plots
$distNames      = array();
$distShipVol    = array();
$distOnTimeRate = array();
$distExposure   = array();

$totalInTransit = 0;
$totalOnTime    = 0;
$totalLate      = 0;

foreach ($distributors as $d) {
    $distNames[]      = $d['DistributorName'];
    $distShipVol[]    = (int)$d['ShipmentVolume'];
    $distOnTimeRate[] = $d['OnTimeRate'] !== null ? (float)$d['OnTimeRate'] : null;
    $distExposure[]   = (int)$d['ExposureScore'];

    $totalInTransit += (int)$d['InTransitCount'];
    $totalOnTime    += (int)$d['OnTimeCount'];
    $totalLate      += (int)$d['LateCount'];
}

// Global disruption alert (ongoing + new in last 7 days)
$alertSql = "
    SELECT
        COUNT(*) AS OngoingCount,
        SUM(
            CASE
                WHEN DATEDIFF(CURDATE(), de.EventDate) <= 7 THEN 1
                ELSE 0
            END
        ) AS NewCount
    FROM DisruptionEvent de
    WHERE de.EventRecoveryDate IS NULL
";

$alertRes = $conn->query($alertSql);
if (!$alertRes) {
    die('Alert query failed: ' . $conn->error);
}

$alertRow = $alertRes->fetch_assoc();
$ongoingCount = (int)$alertRow['OngoingCount'];
$newCount     = (int)$alertRow['NewCount'];


// Encode for JS
$dfLabelsJson  = json_encode($dfLabels);
$dfValuesJson  = json_encode($dfValues);
$artLabels = array_keys($artBins);
$artValues = array_values($artBins);
$artLabelsJson = json_encode($artLabels);
$artValuesJson = json_encode($artValues);
$tdLabels = array_keys($tdBins);
$tdValues = array_values($tdBins);
$tdLabelsJson = json_encode($tdLabels);
$tdValuesJson = json_encode($tdValues);
$rrcRegionsJson = json_encode($rrcRegions);
$rrcValuesJson  = json_encode($rrcValues);
$dsdLabelJson  = json_encode($dsdLabel);
$dsdLowJson = json_encode($dsdLow);
$dsdMediumJson = json_encode($dsdMedium);
$dsdHighJson = json_encode($dsdHigh);
$distNamesJson = json_encode($distNames);
$distShipVolJson = json_encode($distShipVol);
$distOnTimeRateJson = json_encode($distOnTimeRate);
$distExposureJson = json_encode($distExposure);
$statusTotalsJson = json_encode(array(
    'In Transit' => $totalInTransit,
    'On Time'    => $totalOnTime,
    'Late'       => $totalLate
));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>This is test_backup file</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root {
            --bg: whitesmoke;
            --card: #3f3f3f;
            --text: whitesmoke;
            --muted: #d8d8d8;
            --accent: #cfb991;
            --accent-hover: #daaa00;
            --border: #2a2a2a;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Verdana, Geneva, Tahoma, sans-serif;
            background-color: var(--bg);
            color: black;
        }

        .top-header {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            background-color: #000;
            color: #fff;
            text-align: center;
            padding: 0.75rem 0;
            font-size: 1.2rem;
            font-weight: normal;
            z-index: 1000;
            box-shadow: 0 2px 4px rgba(0,0,0,0.5);
        }

        .page {
            min-height: 100vh;
            padding-top: 80px;
            padding-left: 8px;
            padding-right: 8px;
            padding-bottom: 24px;
        }

        h1 {
            margin: 0 0 16px;
            font-size: 1.8rem;
            font-weight: normal;
            color: #141414;
        }

        .module-header {
            display: flex;
            flex-direction: column;
            gap: 4px;
            margin-bottom: 12px;
        }

        .module-header-title {
            font-size: 1.3rem;
            font-weight: normal;
            margin: 0;
        }

        .subtitle {
            font-size: 0.9rem;
            color: var(--muted);
            margin: 0;
        }

        .card {
            background-color: var(--card);
            color: var(--text);
            border-radius: 16px 8px 16px 8px;
            border: 1px solid #0a0a0a;
            box-shadow: 0px 4px 3px 1px #0a0a0a;
            padding: 20px;
            width: 98%;
            margin: 0 auto 24px auto;
        }

        /* Chart section headings inside a module */
        .chart-header {
            margin: 4px 0 8px 0;
        }
        .chart-title {
            margin: 0;
            font-size: 1.05rem;
            font-weight: normal;
        }
        .chart-subtitle {
            margin: 2px 0 0 0;
            font-size: 0.85rem;
            color: var(--muted);
        }
 
        .search-bar {
            margin-bottom: 12px;
            display: flex;
            justify-content: flex-end;
        }

        .search-bar input {
            padding: 0.6rem 1rem;
            border-radius: 8px;
            border: none;
            background-color: whitesmoke;
            color: black;
            font-size: 0.9rem;
            min-width: 260px;
            outline: none;
            transition: all 0.2s ease;
        }

        .search-bar input:focus {
            background-color: #141414;
            box-shadow: 0 0 0 2px var(--accent);
            color: white;
        }

        .search-bar input::placeholder { color: #555; }

        .table-container {
            max-height: 600px;
            overflow-y: auto;
            border-radius: 12px;
            background-color: #2d2d2d;
            border: 1px solid #0a0a0a;
        }

        table { width: 100%; border-collapse: collapse; }

        thead { background-color: #2b2b2b; }

        thead th {
            text-align: left;
            padding: 12px;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: var(--muted);
            border-bottom: 1px solid #444;
            position: sticky;
            top: 0;
            z-index: 2;
            background-color: #2b2b2b;
        }

        tbody tr { background-color: #3f3f3f; }
        tbody tr:nth-child(even) { background-color: #4a4a4a; }
        tbody tr:hover { background-color: #555; }

        tbody td {
            padding: 12px;
            font-size: 0.95rem;
            color: var(--text);
            border-bottom: 1px solid #2a2a2a;
        }

        .pill {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.85rem;
            background-color: #575757;
            border: 1px solid #888;
        }
        .tier-pill {
            background-color: #4b6043;
            border-color: #8fc78a;
        }

        .no-data {
            margin-top: 10px;
            font-size: 0.9rem;
            color: #ddd;
        }

        .df-form {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 16px;
            align-items: flex-end;
            margin-bottom: 16px;
        }

        .df-field {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 160px;
        }

        .df-field label {
            font-size: 0.8rem;
            color: var(--muted);
        }

        .df-field select {
            padding: 0.4rem 0.6rem;
            border-radius: 6px;
            border: none;
            background-color: whitesmoke;
            color: black;
            font-size: 0.85rem;
            outline: none;
            transition: all 0.2s ease;
        }

        .df-field select:focus {
            background-color: #141414;
            box-shadow: 0 0 0 2px var(--accent);
            color: white;
        }

        .df-field input[type="date"] {
            padding: 0.4rem 0.6rem;
            border-radius: 6px;
            border: none;
            background-color: whitesmoke;
            color: black;
            font-size: 0.85rem;
            outline: none;
            transition: all 0.2s ease;
        }

        .df-field input[type="date"]:focus {
            background-color: #141414;
            box-shadow: 0 0 0 2px var(--accent);
            color: white;
        }

        .df-form button {
            padding: 0.45rem 0.9rem;
            border-radius: 8px;
            border: none;
            background-color: var(--accent);
            color: #141414;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.15s ease, transform 0.05s ease;
            margin-top: 18px;
        }

        .df-form button:hover { background-color: var(--accent-hover); }
        .df-form button:active { transform: scale(0.97); }

        .df-chart-wrapper {
            background-color: #2d2d2d;
            border-radius: 12px;
            border: 1px solid #1a1a1a;
            padding: 12px;
            margin-bottom: 16px;
        }

        .plots-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));  /* force 2 columns on normal screens */
            gap: 12px;
            margin-top: 8px;
        }

        .plot-card {
            background-color: #2d2d2d;
            border-radius: 12px;
            border: 1px solid #1a1a1a;
            padding: 10px;
            display: flex;
            flex-direction: column;
        }

        /* Fixed height area for each chart */
        .plot-canvas-wrapper {
            position: relative;
            width: 100%;
            height: 350px; /* adjust if you want taller/shorter charts */
            margin-top: 6px;
        }

        .plot-canvas-wrapper canvas {
            position: absolute;
            inset: 0;
            width: 100% !important;
            height: 100% !important;
        }

        .footer {
            background-color: #000;         
            color: whitesmoke;              
            text-align: center;
            padding: 0.5rem 0;
            font-size: 0.9rem;
            position: fixed;                
            bottom: 0;
            left: 0;
            width: 100%;
        }

        .alert-banner {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background-color: #451a1a;
            border: 1px solid #f97373;
            color: #fee2e2;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 0.8rem;
            margin-bottom: 8px;
        }

        .alert-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background-color: #ef4444;
            box-shadow: 0 0 0 4px rgba(239,68,68,0.3);
        }

        .alert-text {
            white-space: nowrap;
        }

        .alert-banner-ok {
            background-color: #064e3b;      /* dark green */
            border-color: #6ee7b7;          /* mint border */
            color: #d1fae5;                 /* light mint text */
        }

        .alert-dot-ok {
            background-color: #22c55e;      /* green */
            box-shadow: 0 0 0 4px rgba(34,197,94,0.3);
        }

    </style>
</head>
<body>

<div class="top-header">
    Supply Chain Manager Module
</div>

<div class="page">

    <!-- MODULE 1: Company table -->
    <div class="card">
        <div class="module-header">
            <h2 class="module-header-title">Company Info Table</h2>
        </div>

        <?php if ($CompTableResult->num_rows > 0): ?>
            <div class="search-bar">
                <input 
                    type="text" 
                    id="companySearch" 
                    placeholder="Search by company, city, country, or type..."
                >
            </div>

            <div class="table-container">
                <table id="companyTable">
                    <thead>
                    <tr>
                        <th>Company</th>
                        <th>Type</th>
                        <th>Tier</th>
                        <th>City</th>
                        <th>Country</th>
                        <th> Delivery Rate </th>
                        <th> Average Delay </th>
                        <th> STD Delay </th>
                        <th> Fin Health </th>
                        <th> Disruption Dist.</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($companyRows as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['CompanyName']); ?></td>
                            <td><span class="pill"><?php echo htmlspecialchars($row['Type']); ?></span></td>
                            <td><span class="pill tier-pill"><?php echo htmlspecialchars($row['TierLevel']); ?></span></td>
                            <td><?php echo htmlspecialchars($row['City']); ?></td>
                            <td><?php echo htmlspecialchars($row['CountryName']); ?></td>

                            <!-- KPI Out of Commision -->
                            <td><?php echo htmlspecialchars(isset($row['OnTimeRate']) ? $row['OnTimeRate'] : 'N/A') ?></td>
                            <td><?php echo htmlspecialchars(isset($row['AvgDelay']) ? $row['AvgDelay'] : 'N/A') ?></td>
                            <td><?php echo htmlspecialchars(isset($row['StdDelay']) ? $row['StdDelay'] : 'N/A') ?></td>
                            <td><?php echo htmlspecialchars(isset($row['AvgHealthScoreLastYear']) ? $row['AvgHealthScoreLastYear'] : 'N/A') ?></td>
                            <td><?php echo htmlspecialchars(isset($row['DisruptionCountLastYear']) ? $row['DisruptionCountLastYear'] : '0') ?></td> 
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="no-data">No company records found.</div>
        <?php endif; ?>
    </div>

    <!-- MODULE 2: Disruption Events -->
    <div class="card">
        <div class="module-header">
            <h2 class="module-header-title">Disruption Events Module</h2>
            <p class="subtitle">
                Explore how often disruptions occur and how long it takes the network to recover.
            </p>
        </div>

        <?php if ($ongoingCount > 0): ?>
            <div class="alert-banner">
                <span class="alert-dot"></span>
                <span class="alert-text">
                    <?php echo htmlspecialchars($ongoingCount); ?> ongoing disruptions
                    <?php if ($newCount > 0): ?>
                        — <?php echo htmlspecialchars($newCount); ?> new in the last 7 days
                    <?php endif; ?>
                </span>
            </div>
        <?php else: ?>
            <div class="alert-banner alert-banner-ok">
                <span class="alert-dot alert-dot-ok"></span>
                <span class="alert-text">
                    No ongoing disruptions
                </span>
            </div>
        <?php endif; ?>

        <!-- Shared date range for all charts -->
        <form method="get" class="df-form">
            <div class="df-field">
                <label for="df_start">Start date</label>
                <input type="date" id="df_start" name="df_start" value="<?php echo htmlspecialchars($dfStart); ?>">
            </div>
            <div class="df-field">
                <label for="df_end">End date</label>
                <input type="date" id="df_end" name="df_end" value="<?php echo htmlspecialchars($dfEnd); ?>">
            </div>
            <button type="submit">Update Disruption View</button>
        </form>

        <!-- DF chart -->
        <div class="chart-header">
            <h3 class="chart-title">Disruption Frequency (DF) by Company</h3>
            <p class="chart-subtitle">Events per day for each affected company in the selected period.</p>
        </div>
        <div class="df-chart-wrapper">
            <?php if (!empty($dfLabels)): ?>
                <canvas id="dfChart" height="420"></canvas>
            <?php else: ?>
                <div class="no-data">
                    No disruption events found for the selected period. Try expanding the date range.
                </div>
            <?php endif; ?>
        </div>

        <!-- ART chart -->
        <div class="chart-header">
            <h3 class="chart-title">Average Recovery Time (ART)</h3>
            <p class="chart-subtitle">
                Histogram of disruption recovery times (days).
                Overall ART this period:
                <strong>
                    <?php
                    if ($overallART !== null) {
                        echo number_format($overallART, 2) . " days";
                    } else {
                        echo "N/A";
                    }
                    ?>
                </strong>
            </p>
        </div>
        <div class="df-chart-wrapper">
            <?php
            $totalArtCount = array_sum($artValues);
            if ($totalArtCount > 0): ?>
                <canvas id="artChart" height="420"></canvas>
            <?php else: ?>
                <div class="no-data">
                    No recovered disruption events in the selected period.
                </div>
            <?php endif; ?>
        </div>

        <!-- HDR -->
        <div class="chart-header">
            <h3 class="chart-title"> High-Impact Disruption Rate (HDR) </h3>
            <p><?php echo htmlspecialchars($HDRresult->fetch_assoc()['HDR_percent']); ?></p>
        </div>

        <!-- Total Downtime (TD) -->
        <div class="chart-header">
            <h3 class="chart-title"> Total Downtime (TD) </h3>
            <p class="chart-subtitle">Aggregated downtime across all disruptions with recovery dates in this period.
            <strong><?php echo htmlspecialchars($totalDowntimeDays); ?></strong> 
            </p>
        </div>
        <div class="df-chart-wrapper">
            <?php if (count($downtimeData) > 0): ?>
                <canvas id="tdChart" height="420"></canvas>
            <?php else: ?>
                <div class="no-data">
                    No disruptions with recorded recovery dates in the selected period.
                </div>
            <?php endif; ?>
        </div>

        <!-- Regional Risk Concentration -->
        <div class="chart-header">
            <h3 class="chart-title"> Regional Risk Concentration (RRC) </h3>
            <p class="chart-subtitle"> 
                N/A
            </p>
        </div>
        <div class="df-chart-wrapper">
            <?php if (!empty($rrcRegions)): ?>
                <canvas id="rrcChart" height="420"></canvas>
            <?php else: ?>
                <div class="no-data">
                    No disruptions found in the selected time period.
                </div>
            <?php endif; ?>
        </div>

        <!-- Disruption Severity Distribution (DSD) -->
        <div class="chart-header">
            <h3 class="chart-title">Disruption Severity Distribution (DSD)</h3>
        </div>
        <div class="df-chart-wrapper">
            <?php if (($dsdLow + $dsdMedium + $dsdHigh) > 0): ?>
                <canvas id="dsdChart" height="420"></canvas>
            <?php else: ?>
        <div class="no-data">
            No disruption events found in the selected period.
        </div>
        <?php endif; ?>
        </div>
    </div>

    <!-- Module 3: Transaction Analysis -->
    <div class="card">
        <div class="module-header">
            <h2 class="module-header-title">Transaction Analysis</h2>
        </div>

        <!-- Filters module 3 -->
        <form method="get" class="df-form">
            <div class="df-field">
                <label for="t_start">Start date</label>
                <input type="date" id="t_start" name="t_start"
                    value="<?php echo htmlspecialchars($tStart); ?>">
            </div>

            <div class="df-field">
                <label for="t_end">End date</label>
                <input type="date" id="t_end" name="t_end"
                    value="<?php echo htmlspecialchars($tEnd); ?>">
            </div>

            <div class="df-field">
                <label for="t_country">Country (source or destination)</label>
                <select id="t_country" name="t_country">
                    <option value="">All countries</option>
                    <?php foreach ($countryOptions as $country): ?>
                        <option value="<?php echo htmlspecialchars($country); ?>"
                            <?php if ($tCountry === $country) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($country); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="df-field">
                <label for="t_company">Company (leaving/arriving)</label>
                <select id="t_company" name="t_company">
                    <option value="0">All companies</option>
                    <?php foreach ($companyOptions as $co): ?>
                        <option value="<?php echo (int)$co['CompanyID']; ?>"
                            <?php if ($tCompany === (int)$co['CompanyID']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($co['CompanyName']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="df-field">
                <label for="t_direction">Direction</label>
                <select id="t_direction" name="t_direction">
                    <option value="any" <?php if ($tDirection === 'any') echo 'selected'; ?>>Leaving or Arriving</option>
                    <option value="leaving" <?php if ($tDirection === 'leaving') echo 'selected'; ?>>Leaving this company</option>
                    <option value="arriving" <?php if ($tDirection === 'arriving') echo 'selected'; ?>>Arriving at this company</option>
                </select>
            </div>

            <button type="submit">Update Transactions</button>
        </form>

        <!-- Optional: search bar for the table -->
        <div class="search-bar">
            <input type="text" id="txSearch" placeholder="Search shipments, companies, products...">
        </div>

        <div class="table-container">
            <table id="transactionTable">
                <thead>
                <tr>
                    <th>Shipment ID</th>
                    <th>Distributor</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Product</th>
                    <th>Quantity</th>
                    <th>Promised</th>
                    <th>Delivered</th>
                    <th>Delay (days)</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!empty($transactions)): ?>
                    <?php foreach ($transactions as $tx): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($tx['ShipmentID']); ?></td>
                            <td><?php echo htmlspecialchars($tx['DistributorName']); ?></td>
                            <td>
                                <?php echo htmlspecialchars($tx['SourceCompany']); ?><br>
                                <span class="pill">
                                    <?php echo htmlspecialchars($tx['SourceCity'] . ', ' . $tx['SourceCountry']); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($tx['DestCompany']); ?><br>
                                <span class="pill">
                                    <?php echo htmlspecialchars($tx['DestCity'] . ', ' . $tx['DestCountry']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($tx['ProductName']); ?></td>
                            <td><?php echo htmlspecialchars($tx['Quantity']); ?></td>
                            <td><?php echo htmlspecialchars($tx['PromisedDate']); ?></td>
                            <td><?php echo htmlspecialchars($tx['ActualDate']); ?></td>
                            <td><?php echo htmlspecialchars($tx['DelayDays']); ?></td>
                            <td><?php echo htmlspecialchars($tx['StatusLabel']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10" class="no-data">
                            No transactions match the selected filters.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <br><br>
        
        <!-- Distributor Table -->
        <div class="chart-header">
            <h3 class="chart-title">Distributor Summary</h3>
            <p class="chart-subtitle">
                Shipment performance and disruption exposure for distributor companies
                under the current Module 3 filters.
            </p>
        </div>
        
        <div class="table-container">
            <table id="distributorTable">
                <thead>
                <tr>
                    <th>Distributor</th>
                    <th>Location</th>
                    <th>Shipment Volume</th>
                    <th>On-Time Rate</th>
                    <th>In Transit</th>
                    <th>Late</th>
                    <th>Products Handled</th>
                    <th>Disruptions</th>
                    <th>High-Impact</th>
                    <th>Exposure Score</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!empty($distributors)): ?>
                    <?php foreach ($distributors as $d): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($d['DistributorName']); ?></td>
                            <td>
                                <?php echo htmlspecialchars($d['DistributorCity'] . ', ' . $d['DistributorCountry']); ?>
                            </td>
                            <td><?php echo (int)$d['ShipmentVolume']; ?></td>
                            <td>
                                <?php
                                if ($d['OnTimeRate'] !== null) {
                                    echo number_format($d['OnTimeRate'], 1) . '%';
                                } else {
                                    echo 'N/A';
                                }
                                ?>
                            </td>
                            <td><?php echo (int)$d['InTransitCount']; ?></td>
                            <td><?php echo (int)$d['LateCount']; ?></td>
                            <td><?php echo (int)$d['ProductCount']; ?></td>
                            <td><?php echo (int)$d['TotalEvents']; ?></td>
                            <td><?php echo (int)$d['HighEvents']; ?></td>
                            <td><?php echo (int)$d['ExposureScore']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10" class="no-data">
                            No distributors match the selected filters.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <br></br>

        <!-- Module 3 Plots -->
        <div class="plots-grid">
            <!-- Plot 1 -->
            <div class="plot-card">
                <div class="chart-header">
                    <h4 class="chart-title">Shipment Volume by Distributor</h4>
                </div>
                <div class="plot-canvas-wrapper">
                    <canvas id="m3_volByDist"></canvas>
                </div>
            </div>

            <!-- Plot 2 -->
            <div class="plot-card">
                <div class="chart-header">
                    <h4 class="chart-title">On-Time Rate by Distributor</h4>
                </div>
                <div class="plot-canvas-wrapper">
                    <canvas id="m3_otrByDist"></canvas>
                </div>
            </div>

            <!-- Plot 3 -->
            <div class="plot-card">
                <div class="chart-header">
                    <h4 class="chart-title">Shipment Status Distribution</h4>
                </div>
                <div class="plot-canvas-wrapper">
                    <canvas id="m3_statusDist"></canvas>
                </div>
            </div>

            <!-- Plot 4 -->
            <div class="plot-card">
                <div class="chart-header">
                    <h4 class="chart-title">Disruption Exposure by Distributor</h4>
                </div>
                <div class="plot-canvas-wrapper">
                    <canvas id="m3_exposureByDist"></canvas>
                </div>
            </div>
        </div>

    </div> <!-- Ends Module 3 card -->

    <footer class="footer">
        <?php echo date("F j, Y"); ?>
    </footer>

</div> <!-- Ends Page -->

<script>
(function() {
    // MODULE 1: Company search
    var searchInput = document.getElementById('companySearch');
    var table = document.getElementById('companyTable');
    if (searchInput && table) {
        var tbody = table.querySelector('tbody');
        var rows = tbody.getElementsByTagName('tr');

        searchInput.addEventListener('input', function() {
            var query = this.value.toLowerCase();
            for (var i = 0; i < rows.length; i++) {
                var rowText = rows[i].innerText.toLowerCase();
                rows[i].style.display = rowText.indexOf(query) !== -1 ? '' : 'none';
            }
        });
    }

    // MODULE 2: DF chart
    var dfLabels = <?php echo $dfLabelsJson; ?>;
    var dfValues = <?php echo $dfValuesJson; ?>;

    if (dfLabels.length && document.getElementById('dfChart')) {
        var ctx1 = document.getElementById('dfChart').getContext('2d');
        new Chart(ctx1, {
            type: 'bar',
            data: {
                labels: dfLabels,
                datasets: [{
                    label: 'Events per day',
                    data: dfValues,
                    backgroundColor: '#cfb991',
                    borderColor: '#daaa00',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        ticks: { color: '#f5f5f5' },
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { color: '#f5f5f5' },
                        grid: { color: 'rgba(255,255,255,0.1)' }
                    }
                },
                plugins: {
                    legend: { labels: { color: '#f5f5f5' } }
                }
            }
        });
    }

    // MODULE 2: ART histogram
    var artLabels = <?php echo $artLabelsJson; ?>;
    var artValues = <?php echo $artValuesJson; ?>;

    if (artLabels.length && document.getElementById('artChart')) {
        var ctx2 = document.getElementById('artChart').getContext('2d');
        new Chart(ctx2, {
            type: 'bar',
            data: {
                labels: artLabels,
                datasets: [{
                    label: 'Number of disruptions',
                    data: artValues,
                    backgroundColor: '#93c5fd',
                    borderColor: '#60a5fa',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        ticks: { color: '#f5f5f5' },
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { color: '#f5f5f5' },
                        grid: { color: 'rgba(255,255,255,0.1)' }
                    }
                },
                plugins: {
                    legend: { labels: { color: '#f5f5f5' } }
                }
            }
        });
    }

    // Module 2: TD Histogram
    var tdLabels = <?php echo $tdLabelsJson; ?>;
    var tdValues = <?php echo $tdValuesJson; ?>;

    if(tdLabels.length && document.getElementById('tdChart')){
        var ctx3 = document.getElementById('tdChart').getContext('2d');
        new Chart(ctx3, {
            type: 'bar',
            data: {
                labels: tdLabels,
                datasets: [{
                    label: 'Number of disruptions',
                    data: tdValues,
                    backgroundColor: '#f9a8d4',
                    borderColor: '#ec4899',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        ticks: { color: '#f5f5f5' },
                        grid: { display: false },
                        title: {
                            display: true,
                            text: 'Downtime (days)',
                            color: '#f5f5f5',
                            font: { size:12 }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { color: '#f5f5f5' },
                        grid: { color: 'rgba(255,255,255,0.1)' },
                        title: {
                            display: true,
                            text: 'Downtime (days)',
                            color: '#f5f5f5',
                            font: { size:12 }
                        }
                    }
                },
                plugins: {
                    legend: { 
                        labels: { color: '#f5f5f5' } 
                    }
                }
            }
        });
    } 

    // Module 2: RRC Heatmap
    var rrcRegions = <?php echo $rrcRegionsJson; ?>;
    var rrcValues  = <?php echo $rrcValuesJson; ?>;  // fractions 0–1

    if (rrcRegions.length && document.getElementById('rrcChart')) {
        var maxRRC = 0;
        for (var i = 0; i < rrcValues.length; i++) {
            if (rrcValues[i] > maxRRC) {
                maxRRC = rrcValues[i];
            }
        }

        // Map each value to a color from green (low) to red (high)
        var rrcColors = rrcValues.map(function(v) {
            if (maxRRC <= 0) {
                return 'rgba(148, 163, 184, 0.4)'; // fallback grey
            }
            var t = v / maxRRC;          // 0..1
            var hue = (1 - t) * 120;     // 120 = green, 0 = red
            return 'hsl(' + hue + ', 80%, 50%)';
        });

        var ctxRRC = document.getElementById('rrcChart').getContext('2d');

        new Chart(ctxRRC, {
            type: 'bar',
            data: {
                labels: rrcRegions,
                datasets: [{
                    data: rrcValues,
                    backgroundColor: rrcColors,
                    borderColor: rrcColors,
                    borderWidth: 1
                }]
            },
            options: {
                indexAxis: 'y',              // horizontal bars → heatmap feel
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            color: '#f5f5f5',
                            callback: function(value) {
                                return (value * 100).toFixed(0) + '%';
                            }
                        },
                        grid: {
                            color: 'rgba(255,255,255,0.1)'
                        }
                    },
                    y: {
                        ticks: { color: '#f5f5f5' },
                        grid: { display: false }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                var v = context.raw * 100;
                                return v.toFixed(1) + '% of disruptions';
                            }
                        }
                    }
                }
            }
        });
    }

    // Module 2: DSD Stacked Bar Chart
    var dsdLabel  = <?php echo $dsdLabelJson; ?>;   
    var dsdLow    = <?php echo $dsdLowJson; ?>;
    var dsdMedium = <?php echo $dsdMediumJson; ?>;
    var dsdHigh   = <?php echo $dsdHighJson; ?>;

    
    if (document.getElementById('dsdChart')) {
        var ctxDSD = document.getElementById('dsdChart').getContext('2d');

        new Chart(ctxDSD, {
            type: 'bar',
            data: {
                labels: [dsdLabel],
                datasets: [
                    {
                        label: 'Low',
                        data: [dsdLow],
                        backgroundColor: '#22c55e',
                        barThickness: 80
                    },
                    {
                        label: 'Medium',
                        data: [dsdMedium],
                        backgroundColor: '#eab308',
                        barThickness: 80
                    },
                    {
                        label: 'High',
                        data: [dsdHigh],
                        backgroundColor: '#ef4444',
                        barThickness: 80
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        stacked: true,
                        ticks: { color: '#f5f5f5' },
                        grid: { display: false }
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        ticks: { 
                            color: '#f5f5f5',
                            precision: 0 
                        },
                        grid: { color: 'rgba(255,255,255,0.1)' },

                        title: {
                            display: true,
                            text: 'Number of Disruption Events',
                            color: '#f5f5f5',
                            font: { size: 13 }
                        }
                    }
                },
                plugins: {
                    legend: {
                        labels: { color: '#f5f5f5' }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + context.raw + ' events';
                            }
                        }
                    }
                }
            }
        });
    }

    // Module 3: Filtering 
    var txSearchInput = document.getElementById('txSearch');
    var txTable = document.getElementById('transactionTable');

    if (txSearchInput && txTable) {
        txSearchInput.addEventListener('input', function () {
            var filter = txSearchInput.value.toLowerCase();
            var rows = txTable.getElementsByTagName('tr');

            for (var i = 1; i < rows.length; i++) { // skip header row (index 0)
                var cells = rows[i].getElementsByTagName('td');
                var match = false;

                for (var j = 0; j < cells.length; j++) {
                    var txt = cells[j].textContent || cells[j].innerText;
                    if (txt.toLowerCase().indexOf(filter) !== -1) {
                        match = true;
                        break;
                    }
                }
                rows[i].style.display = match ? '' : 'none';
            }
        });
    }

    // MODULE 3: Plot 1 - Shipment Volume by Distributor
    var volLabels = <?php echo $distNamesJson; ?>;
    var volData   = <?php echo $distShipVolJson; ?>;

    if (volLabels && volLabels.length && document.getElementById('m3_volByDist')) {
        var ctxVol = document.getElementById('m3_volByDist').getContext('2d');

        new Chart(ctxVol, {
            type: 'bar',
            data: {
                labels: volLabels,
                datasets: [{
                    label: 'Shipment Volume',
                    data: volData,
                    backgroundColor: '#93c5fd',
                    borderColor: '#60a5fa',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        ticks: { color: '#f5f5f5' },
                        grid: { display: false },
                        title: {
                            display: true,
                            text: 'Distributor',
                            color: '#f5f5f5',
                            font: { size: 12 }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            color: '#f5f5f5',
                            precision: 0
                        },
                        grid: { color: 'rgba(255,255,255,0.1)' },
                        title: {
                            display: true,
                            text: 'Shipment Volume',
                            color: '#f5f5f5',
                            font: { size: 12 }
                        }
                    }
                },
                plugins: {
                    legend: {
                        labels: { color: '#f5f5f5' }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Shipments: ' + context.raw;
                            }
                        }
                    }
                }
            }
        });
    }

    // Module 3: Plot 2 - On-Time Rate by Distributor
    var otrLabelsRaw = <?php echo $distNamesJson; ?>    // Dist. names
    var otrDataRaw = <?php echo $distOnTimeRateJson; ?> // percentage
    var otrLabels = [];
    var otrData = [];

    if (Array.isArray(otrLabelsRaw) && Array.isArray(otrDataRaw)) {
        for (var i = 0; i < otrLabelsRaw.length; i++) {
            if (otrDataRaw[i] !== null) {
                otrLabels.push(otrLabelsRaw[i]);
                otrData.push(otrDataRaw[i]);
            }
        }
    }

    if (otrLabels.length && document.getElementById('m3_otrByDist')) {
        var ctxOtr = document.getElementById('m3_otrByDist').getContext('2d');

        new Chart(ctxOtr, {
            type: 'bar',
            data: {
                labels: otrLabels,
                datasets: [{
                    label: 'On-Time Delivery Rate',
                    data: otrData,
                    backgroundColor: '#bbf7d0',  // light green
                    borderColor: '#22c55e',      // stronger green
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 300
                },
                scales: {
                    x: {
                        ticks: { color: '#f5f5f5' },
                        grid: { display: false },
                        title: {
                            display: true,
                            text: 'Distributor',
                            color: '#f5f5f5',
                            font: { size: 12 }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        suggestedMax: 100, // since it's a percentage
                        ticks: {
                            color: '#f5f5f5',
                            callback: function(value) {
                                return value + '%';
                            }
                        },
                        grid: { color: 'rgba(255,255,255,0.1)' },
                        title: {
                            display: true,
                            text: 'On-Time Delivery Rate (%)',
                            color: '#f5f5f5',
                            font: { size: 12 }
                        }
                    }
                },
                plugins: {
                    legend: {
                        labels: { color: '#f5f5f5' }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.raw.toFixed(1) + '% on-time';
                            }
                        }
                    }
                }
            }
        });
    }

    // Module 3: Plot 3 - Status Status Distribution
    var statusTotals = <?php echo $statusTotalsJson; ?>;

    var statusLabels = Object.keys(statusTotals);       // ["In Transit", "On Time", "Late"]
    var statusValues = Object.values(statusTotals);     // [#, #, #]

    // Only draw chart if the canvas exists AND there is at least one nonzero status count
    if (
        document.getElementById('m3_statusDist') &&
        statusValues.some(function(v) { return v > 0; })
    ) {
        var ctxStatus = document.getElementById('m3_statusDist').getContext('2d');

        new Chart(ctxStatus, {
            type: 'doughnut',
            data: {
                labels: statusLabels,
                datasets: [{
                    data: statusValues,
                    backgroundColor: [
                        '#60a5fa',  // In Transit (blue)
                        '#4ade80',  // On Time (green)
                        '#f87171'   // Late (red)
                    ],
                    borderColor: '#1a1a1a',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',  // donut shape
                plugins: {
                    legend: {
                        labels: { color: '#f5f5f5' }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.label + ': ' + context.raw + ' shipments';
                            }
                        }
                    }
                }
            }
        });
    }

    // MODULE 3: Plot 4 - Disruption Exposure by Distributor
    var expLabels = <?php echo $distNamesJson; ?>;      // distributor names
    var expData   = <?php echo $distExposureJson; ?>;   // exposure scores

    // Only draw chart if we have labels, data, and a canvas element
    if (
        Array.isArray(expLabels) &&
        expLabels.length &&
        Array.isArray(expData) &&
        expData.some(function(v) { return v > 0; }) &&
        document.getElementById('m3_exposureByDist')
    ) {
        var ctxExp = document.getElementById('m3_exposureByDist').getContext('2d');

        new Chart(ctxExp, {
            type: 'bar',
            data: {
                labels: expLabels,
                datasets: [{
                    label: 'Exposure Score',
                    data: expData,
                    backgroundColor: '#fed7aa',  // orange-ish
                    borderColor: '#fb923c',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 300
                },
                scales: {
                    x: {
                        ticks: { color: '#f5f5f5' },
                        grid: { display: false },
                        title: {
                            display: true,
                            text: 'Distributor',
                            color: '#f5f5f5',
                            font: { size: 12 }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            color: '#f5f5f5',
                            precision: 0
                        },
                        grid: { color: 'rgba(255,255,255,0.1)' },
                        title: {
                            display: true,
                            text: 'Exposure Score',
                            color: '#f5f5f5',
                            font: { size: 12 }
                        }
                    }
                },
                plugins: {
                    legend: {
                        labels: { color: '#f5f5f5' }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Exposure: ' + context.raw;
                            }
                        }
                    }
                }
            }
        });
    }
    
})(); // Ends entire JS section
</script>
</body>
</html>
<?php
// $CompTableResult->free();
//$artRes->free();
//$dfRes->free();
//$HDRresult->free();
//$TDresult->free();
//$RRCresult->free();
//$DSDresult->free();
//$txResult->free();
//$distResult->free();
//$alertRes->free();
$conn->close();
?>