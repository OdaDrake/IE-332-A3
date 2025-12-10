<?php
session_start();
date_default_timezone_set('America/Indiana/Indianapolis');

// DB connection
$servername = "mydb.itap.purdue.edu";
$username = "g1151918";
$password = "group8ie332";
$database = $username;

$conn = new mysqli($servername, $username, $password, $database);
$conn->set_charset('utf8mb4');

if ($conn->connect_error) {
    die("Connection failed: " . htmlspecialchars($conn->connect_error));
}

// If not logged in kick back to login page
if (
    !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['role'])      || $_SESSION['role'] !== 'supply'
) {
    header('Location: index.php');
    exit;
}

$activeTab = isset($_GET['active_tab']) ? $_GET['active_tab'] : 'tab-company';

// POST and stay on Companies tab
$companyUpdateMessage = '';
$companyUpdateError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['company_edit_submit'])) {
    $activeTab = 'tab-company';

    $editId = isset($_POST['edit_company_id']) ? (int)$_POST['edit_company_id'] : 0;
    $type = isset($_POST['edit_company_type']) ? trim($_POST['edit_company_type']) : '';
    $tier = isset($_POST['edit_company_tier']) ? trim($_POST['edit_company_tier']) : '';
    $country = isset($_POST['edit_company_country']) ? trim($_POST['edit_company_country']) : '';
    $city = isset($_POST['edit_company_city']) ? trim($_POST['edit_company_city']) : '';
    $continent = isset($_POST['edit_company_continent']) ? trim($_POST['edit_company_continent']) : '';

    if ($editId <= 0 || $type === '' || $tier === '' || $country === '' || $city === '' || $continent === '') {
        $companyUpdateError = "Please select a company and make sure all fields are filled.";
    } else {
        $countryEsc = $conn->real_escape_string($country);
        $cityEsc = $conn->real_escape_string($city);
        $continentEsc = $conn->real_escape_string($continent);

        $locSql = "
            SELECT LocationID
            FROM Location
            WHERE CountryName = '$countryEsc'
              AND City = '$cityEsc'
              AND ContinentName = '$continentEsc'
            LIMIT 1
        ";

        $locRes = $conn->query($locSql);

        if (!$locRes) {
            $companyUpdateError = "Location lookup failed: " . $conn->error;
        } elseif ($locRes->num_rows === 0) {
            $companyUpdateError = "No existing Location matches the selected Country, City, and Continent.";
        } else {
            $locRow = $locRes->fetch_assoc();
            $newLocId = (int)$locRow['LocationID'];

            $typeEsc = $conn->real_escape_string($type);
            $tierEsc = $conn->real_escape_string($tier);

            $updateSql = "
                UPDATE Company
                SET Type = '$typeEsc',
                    TierLevel = '$tierEsc',
                    LocationID = $newLocId
                WHERE CompanyID = $editId
            ";

            if ($conn->query($updateSql)) {
                $companyUpdateMessage = "Company info updated successfully.";
            } else {
                $companyUpdateError = "Update failed: " . $conn->error;
            }
        }
    }
}

// Company popup date filters (90 day default)
if (isset($_GET['c_start']) && $_GET['c_start'] !== '') {
    $cStart = $_GET['c_start'];
} else {
    $cStart = date('Y-m-d', strtotime('-90 days'));
}

if (isset($_GET['c_end']) && $_GET['c_end'] !== '') {
    $cEnd = $_GET['c_end'];
} else {
    $cEnd = date('Y-m-d');
}

$focusCompanyId = isset($_GET['focus_company']) ? (int)$_GET['focus_company'] : 0;

$focusCompanyName = '';
$dependsOn = array();
$dependedBy = array();
$capacityInfo = null;
$uniqueRouteCount = null;
$productList = array();
$shipmentsOut = array();
$shipmentsIn = array();

if ($focusCompanyId > 0) {

    $nameSql = "
        SELECT CompanyName, Type
        FROM Company
        WHERE CompanyID = $focusCompanyId
    ";
    if ($nameRes = $conn->query($nameSql)) {
        if ($row = $nameRes->fetch_assoc()) {
            $focusCompanyName = $row['CompanyName'];
            $focusCompanyType = $row['Type'];
        }
    }

    $popupStart = $conn->real_escape_string($cStart);
    $popupEnd   = $conn->real_escape_string($cEnd);

    $upSql = "
        SELECT DISTINCT c.CompanyID, c.CompanyName
        FROM Shipping s
        JOIN Company c ON c.CompanyID = s.SourceCompanyID
        WHERE s.DestinationCompanyID = $focusCompanyId
          AND s.PromisedDate BETWEEN '$popupStart' AND '$popupEnd'
        ORDER BY c.CompanyName
    ";
    if ($upRes = $conn->query($upSql)) {
        while ($r = $upRes->fetch_assoc()) {
            $dependsOn[] = $r;
        }
    }

    $downSql = "
        SELECT DISTINCT c.CompanyID, c.CompanyName
        FROM Shipping s
        JOIN Company c ON c.CompanyID = s.DestinationCompanyID
        WHERE s.SourceCompanyID = $focusCompanyId
          AND s.PromisedDate BETWEEN '$popupStart' AND '$popupEnd'
        ORDER BY c.CompanyName
    ";
    if ($downRes = $conn->query($downSql)) {
        while ($r = $downRes->fetch_assoc()) {
            $dependedBy[] = $r;
        }
    }

    $capSql = "
        SELECT
            SUM(s.Quantity) AS TotalQuantity,
            COUNT(DISTINCT s.ShipmentID) AS ShipmentCount
        FROM Shipping s
        WHERE s.SourceCompanyID = $focusCompanyId
          AND s.PromisedDate BETWEEN '$popupStart' AND '$popupEnd'
    ";
    if ($capRes = $conn->query($capSql)) {
        $capacityInfo = $capRes->fetch_assoc();
    }

    $routeSql = "
        SELECT COUNT(DISTINCT CONCAT(s.SourceCompanyID, '-', s.DestinationCompanyID)) AS RouteCount
        FROM Shipping s
        WHERE s.DistributorID = $focusCompanyId
          AND s.PromisedDate BETWEEN '$popupStart' AND '$popupEnd'
    ";
    if ($routeRes = $conn->query($routeSql)) {
        $row = $routeRes->fetch_assoc();
        $uniqueRouteCount = $row ? (int)$row['RouteCount'] : 0;
    }

    $prodSql = "
        SELECT
            p.ProductName,
            COUNT(DISTINCT s.ShipmentID) AS ShipmentCount
        FROM Shipping s
        JOIN Product p ON p.ProductID = s.ProductID
        WHERE s.SourceCompanyID = $focusCompanyId
          AND s.PromisedDate BETWEEN '$popupStart' AND '$popupEnd'
        GROUP BY p.ProductID, p.ProductName
        ORDER BY ShipmentCount DESC
    ";
    if ($prodRes = $conn->query($prodSql)) {
        while ($r = $prodRes->fetch_assoc()) {
            $productList[] = $r;
        }
    }

    $outSql = "
        SELECT
            s.ShipmentID,
            src.CompanyName AS SourceName,
            dst.CompanyName AS DestName,
            p.ProductName,
            s.Quantity,
            s.PromisedDate,
            s.ActualDate,
            CASE
                WHEN s.ActualDate IS NULL THEN 'In Transit'
                WHEN s.ActualDate <= s.PromisedDate THEN 'On Time'
                ELSE 'Late'
            END AS StatusLabel
        FROM Shipping s
        JOIN Company src ON src.CompanyID = s.SourceCompanyID
        JOIN Company dst ON dst.CompanyID = s.DestinationCompanyID
        LEFT JOIN Product p ON p.ProductID = s.ProductID
        WHERE s.SourceCompanyID = $focusCompanyId
          AND s.PromisedDate BETWEEN '$popupStart' AND '$popupEnd'
        ORDER BY s.PromisedDate DESC
    ";
    if ($outRes = $conn->query($outSql)) {
        while ($r = $outRes->fetch_assoc()) {
            $shipmentsOut[] = $r;
        }
    }

    $inSql = "
        SELECT
            s.ShipmentID,
            src.CompanyName AS SourceName,
            dst.CompanyName AS DestName,
            p.ProductName,
            s.Quantity,
            s.PromisedDate,
            s.ActualDate,
            CASE
                WHEN s.ActualDate IS NULL THEN 'In Transit'
                WHEN s.ActualDate <= s.PromisedDate THEN 'On Time'
                ELSE 'Late'
            END AS StatusLabel
        FROM Shipping s
        JOIN Company src ON src.CompanyID = s.SourceCompanyID
        JOIN Company dst ON dst.CompanyID = s.DestinationCompanyID
        LEFT JOIN Product p ON p.ProductID = s.ProductID
        WHERE s.DestinationCompanyID = $focusCompanyId
          AND s.PromisedDate BETWEEN '$popupStart' AND '$popupEnd'
        ORDER BY s.PromisedDate DESC
    ";
    if ($inRes = $conn->query($inSql)) {
        while ($r = $inRes->fetch_assoc()) {
            $shipmentsIn[] = $r;
        }
    }
}

// MODULE 1: Company info table
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

usort($companyRows, function ($a, $b) {
    return strcasecmp($a['CompanyName'], $b['CompanyName']);
});

// Type options 
$typeOptions = array();
$typeRes = $conn->query("SELECT DISTINCT Type FROM Company ORDER BY Type");
if ($typeRes) {
    while ($row = $typeRes->fetch_assoc()) {
        $typeOptions[] = $row['Type'];
    }
}

// Tier options
$tierOptions = array();
$tierRes = $conn->query("SELECT DISTINCT TierLevel FROM Company ORDER BY TierLevel");
if ($tierRes) {
    while ($row = $tierRes->fetch_assoc()) {
        $tierOptions[] = $row['TierLevel'];
    }
}

// Location options
$locationOptions = array();
$locRes = $conn->query("
    SELECT LocationID, CountryName, ContinentName, City
    FROM Location
    ORDER BY CountryName, City
");
if ($locRes) {
    while ($row = $locRes->fetch_assoc()) {
        $locationOptions[] = $row;
    }
}

// Distinct countries
$countryOptionsEdit = array();
$cityOptionsEdit = array();
$continentOptionsEdit = array();

foreach ($locationOptions as $loc) {
    $country = $loc['CountryName'];
    $city = $loc['City'];
    $cont = $loc['ContinentName'];

    if (!in_array($country, $countryOptionsEdit, true)) {
        $countryOptionsEdit[] = $country;
    }
    if (!in_array($city, $cityOptionsEdit, true)) {
        $cityOptionsEdit[] = $city;
    }
    if (!in_array($cont, $continentOptionsEdit, true)) {
        $continentOptionsEdit[] = $cont;
    }
}

sort($countryOptionsEdit);
sort($cityOptionsEdit);
sort($continentOptionsEdit);


// MODULE 2: Disruption Events 
// Disruption Event date filters (90 day default)
$dfStart = isset($_GET['df_start']) ? trim($_GET['df_start']) : '';
$dfEnd = isset($_GET['df_end'])   ? trim($_GET['df_end'])   : '';

if ($dfStart === '' && $dfEnd === '') {
    $dfEnd = date('Y-m-d');
    $dfStart = date('Y-m-d', strtotime('-90 days'));
}

$T_days = 1;
$tsStart = strtotime($dfStart);
$tsEnd = strtotime($dfEnd);
if ($tsStart !== false && $tsEnd !== false && $tsEnd >= $tsStart) {
    $T_days = max(1, floor(($tsEnd - $tsStart) / 86400) + 1);
}

// Disruption Frequency 
$dfLabels = [];
$dfValues = [];

if ($dfStart !== '' && $dfEnd !== '') {
    $dfStartEsc = $conn->real_escape_string($dfStart);
    $dfEndEsc = $conn->real_escape_string($dfEnd);

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
            $dfValues[] = $row['num_disruptions'] / $T_days; 
        }
        $dfRes->free();
    }
}

// Average Recovery Time
$artBins = [
    '0-2 days'=> 0,
    '3-5 days'=> 0,
    '6-10 days'=> 0,
    '11-20 days'=> 0,
    '21+ days'=> 0
];

$totalRecoveryDays = 0;
$recoveryCount = 0;

if ($dfStart !== '' && $dfEnd !== '') {
    $dfStartEsc = $conn->real_escape_string($dfStart);
    $dfEndEsc = $conn->real_escape_string($dfEnd);

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

// High-Impact Disruption Rate 
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

$HDRpercent = null;
$hdrColor   = '#f5f5f5'; 

$hdrRow = $HDRresult->fetch_assoc();
if ($hdrRow && $hdrRow['HDR_percent'] !== null) {
    $HDRpercent = (float)$hdrRow['HDR_percent'];

    if ($HDRpercent > 10) {
        $hdrColor = '#f56565';   
    } elseif ($HDRpercent > 5) {
        $hdrColor = '#f6ad55'; 
    } else {
        $hdrColor = '#8fc78a';   
    }
}

// Total Downtime
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

while ($row = $TDresult->fetch_assoc()) {
    if ($row['downtime_days'] === null) {
        continue;
    }
    $d = (int)$row['downtime_days'];
    if ($d < 0) {
        continue; 
    }
    $downtimeData[] = $d;
    $totalDowntimeDays += $d;
}

$tdBins = array();
foreach ($downtimeData as $d) {
    if (!isset($tdBins[$d])) {
        $tdBins[$d] = 0;
    }
    $tdBins[$d]++;
}

ksort($tdBins);

// Regional Risk Concentration
$RRCsql = "
    SELECT
        per.Region,
        COUNT(*) AS DisruptionCount,
        CASE
            WHEN total.total_count = 0 THEN NULL
            ELSE COUNT(*) * 1.0 / total.total_count
        END AS RRC_fraction
    FROM
        (
            SELECT
                de.EventID,
                MIN(l.CountryName) AS Region
            FROM DisruptionEvent de
            JOIN ImpactsCompany ic
                ON ic.EventID = de.EventID
            JOIN Company c
                ON c.CompanyID = ic.AffectedCompanyID
            JOIN Location l
                ON l.LocationID = c.LocationID
            WHERE de.EventDate BETWEEN '$dfStart' AND '$dfEnd'
            GROUP BY de.EventID
        ) AS per
    JOIN
        (
            SELECT
                COUNT(*) AS total_count
            FROM
                (
                    SELECT de2.EventID
                    FROM DisruptionEvent de2
                    WHERE de2.EventDate BETWEEN '$dfStart' AND '$dfEnd'
                    GROUP BY de2.EventID
                ) AS t
        ) AS total
    GROUP BY per.Region
    ORDER BY RRC_fraction DESC
";

$RRCresult = $conn->query($RRCsql);
if (!$RRCresult) {
    die('RRC query failed: ' . $conn->error);
}

$rrcRegions = array();   
$rrcValues = array();  

while ($row = $RRCresult->fetch_assoc()) {
    if ($row['RRC_fraction'] === null) {
        continue;
    }
    $rrcRegions[] = $row['Region'];
    $rrcValues[] = (float)$row['RRC_fraction'];
}

// Disruption Severity Distribution (DSD)
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

$dsdLabel = 'All Disruptions';
$dsdLow = $dsdCounts['Low'];
$dsdMedium = $dsdCounts['Medium'];
$dsdHigh = $dsdCounts['High'];

// MODULE 3: Transaction Analysis 
// Mod 3 Filters
$tCompany = isset($_GET['t_company']) ? (int)$_GET['t_company'] : 0;
$tDirection = isset($_GET['t_direction']) ? $_GET['t_direction'] : 'any';
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

if (isset($_GET['t_start']) || isset($_GET['t_end'])) {
    $tStart = isset($_GET['t_start']) ? $_GET['t_start'] : '';
    $tEnd = isset($_GET['t_end']) ? $_GET['t_end']   : '';
} else {
    $tEnd = date('Y-m-d');
    $tStart = date('Y-m-d', strtotime($tEnd . ' -90 days'));
}

$conditions = array();

if ($tStart !== '' && $tEnd !== '') {
    $conditions[] =
        "s.PromisedDate BETWEEN '" .
        $conn->real_escape_string($tStart) .
        "' AND '" .
        $conn->real_escape_string($tEnd) .
        "'";
}

if ($tCompany > 0) {
    if ($tDirection === 'leaving') {
        $conditions[] = "s.SourceCompanyID = " . $tCompany;
    } elseif ($tDirection === 'arriving') {
        $conditions[] = "s.DestinationCompanyID = " . $tCompany;
    } else { // any
        $conditions[] = "(s.SourceCompanyID = " . $tCompany . " OR s.DestinationCompanyID = " . $tCompany . ")";
    }
}

if ($tCountry !== '') {
    $safeCountry = $conn->real_escape_string($tCountry);
    $conditions[] = "(srcLoc.CountryName = '" . $safeCountry . "' OR destLoc.CountryName = '" . $safeCountry . "')";
}

$whereClause = '';
if (count($conditions) > 0) {
    $whereClause = "WHERE " . implode(" AND ", $conditions);
}


// Transactions Query
$txSql = "
    SELECT
        s.ShipmentID,
        s.PromisedDate,
        s.ActualDate,
        s.Quantity,
        p.ProductName,

        src.CompanyName AS SourceCompany,
        srcLoc.City AS SourceCity,
        srcLoc.CountryName AS SourceCountry,

        dest.CompanyName AS DestCompany,
        destLoc.City AS DestCity,
        destLoc.CountryName AS DestCountry,

        dist.CompanyName AS DistributorName,

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

// Distributor Table
$distWhere = $whereClause; 

if ($distWhere === '') {
    $distWhere = "WHERE dist.Type = 'Distributor'";
} else {
    $distWhere .= " AND dist.Type = 'Distributor'";
}

$expConds = array();

// Date filter
if ($tStart !== '' && $tEnd !== '') {
    $expConds[] = "de.EventDate BETWEEN '" . $conn->real_escape_string($tStart) .
                  "' AND '" . $conn->real_escape_string($tEnd) . "'";
}

// Country filter
if ($tCountry !== '') {
    $safeCountryExp = $conn->real_escape_string($tCountry);
    $expConds[] = "l.CountryName = '" . $safeCountryExp . "'";
}

if (count($expConds) === 0) {
    $expWhere = "1 = 1"; 
} else {
    $expWhere = implode(" AND ", $expConds);
}

// Distributor query
$distSql = "
    SELECT
        dist.CompanyID AS DistributorID,
        dist.CompanyName AS DistributorName,
        distLoc.City AS DistributorCity,
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
    JOIN Company src ON s.SourceCompanyID = src.CompanyID
    JOIN Location srcLoc ON src.LocationID = srcLoc.LocationID
    JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID
    JOIN Location destLoc ON dest.LocationID = destLoc.LocationID

    JOIN Company dist ON s.DistributorID = dist.CompanyID
    JOIN Location distLoc ON dist.LocationID = distLoc.LocationID

    LEFT JOIN (
        SELECT
            ic.AffectedCompanyID AS DistributorID,
            COUNT(*) AS TotalEvents,
            SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) AS HighEvents
        FROM DisruptionEvent de
        JOIN ImpactsCompany ic ON ic.EventID = de.EventID
        JOIN Company c ON c.CompanyID = ic.AffectedCompanyID
        JOIN Location l ON l.LocationID = c.LocationID
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
    $delivered = (int)$row['OnTimeCount'] + (int)$row['LateCount'];
    if ($delivered > 0) {
        $row['OnTimeRate'] = 100.0 * (int)$row['OnTimeCount'] / $delivered;
    } else {
        $row['OnTimeRate'] = null;
    }
    $distributors[] = $row;
}

// Module 3: data for plots
$distNames = array();
$distShipVol = array();
$distOnTimeRate = array();
$distExposure = array();
$totalInTransit = 0;
$totalOnTime = 0;
$totalLate = 0;

foreach ($distributors as $d) {
    $distNames[] = $d['DistributorName'];
    $distShipVol[] = (int)$d['ShipmentVolume'];
    $distOnTimeRate[] = $d['OnTimeRate'] !== null ? (float)$d['OnTimeRate'] : null;
    $distExposure[] = (int)$d['ExposureScore'];
    $totalInTransit += (int)$d['InTransitCount'];
    $totalOnTime += (int)$d['OnTimeCount'];
    $totalLate += (int)$d['LateCount'];
}

// Global disruption alert
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
$newCount = (int)$alertRow['NewCount'];

// Encode vars for JS
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
    'On Time' => $totalOnTime,
    'Late' => $totalLate
));
?>

<?php
$companyIds = array();
if (!empty($companyRows)) {
    foreach ($companyRows as $row) {
        if (isset($row['CompanyID'])) {
            $companyIds[] = (int)$row['CompanyID'];
        }
    }
}

// Company table KPI arrays 
$deliveryKpi   = array(); 
$financialKpi  = array(); 
$disruptionKpi = array(); 

if (!empty($companyIds)) {
    foreach ($companyIds as $k => $cid) {
        $companyIds[$k] = (int)$cid;
    }
    $idList = implode(',', $companyIds);

    $sqlShip = "
        SELECT
            r.ReceiverCompanyID AS CompanyID,
            SUM(CASE WHEN r.ReceivedDate <= s.PromisedDate THEN 1 ELSE 0 END) AS OnTimeCount,
            COUNT(*) AS TotalShipments,
            AVG(DATEDIFF(r.ReceivedDate, s.PromisedDate)) AS AvgDelayDays,
            STDDEV_POP(DATEDIFF(r.ReceivedDate, s.PromisedDate)) AS StdDelayDays
        FROM Receiving r
        JOIN Shipping s ON r.ShipmentID = s.ShipmentID
        WHERE r.ReceivedDate >= CURDATE() - INTERVAL 1 YEAR
          AND r.ReceiverCompanyID IN ($idList)
        GROUP BY r.ReceiverCompanyID
    ";

    $result = $conn->query($sqlShip);
    if ($result) {
        while ($r = $result->fetch_assoc()) {
            $cid = (int)$r['CompanyID'];

            $onTimeRate = null;
            if (!empty($r['TotalShipments'])) {
                $onTimeRate = $r['OnTimeCount'] / $r['TotalShipments']; // 0–1
            }

            $deliveryKpi[$cid] = array(
                'on_time_rate' => $onTimeRate,
                'avg_delay' => isset($r['AvgDelayDays']) ? (float)$r['AvgDelayDays'] : null,
                'std_delay' => isset($r['StdDelayDays']) ? (float)$r['StdDelayDays'] : null,
            );
        }
        $result->free();
    } else {
        echo "Shipment KPI query error: " . htmlspecialchars($conn->error);
    }

    $sqlFin = "
        SELECT
            fr.CompanyID,
            AVG(fr.HealthScore) AS AvgHealthScoreLastYear
        FROM FinancialReport fr
        WHERE
            (
                CASE fr.Quarter
                    WHEN 'Q1' THEN STR_TO_DATE(CONCAT(fr.RepYear, '-01-01'), '%Y-%m-%d')
                    WHEN 'Q2' THEN STR_TO_DATE(CONCAT(fr.RepYear, '-04-01'), '%Y-%m-%d')
                    WHEN 'Q3' THEN STR_TO_DATE(CONCAT(fr.RepYear, '-07-01'), '%Y-%m-%d')
                    WHEN 'Q4' THEN STR_TO_DATE(CONCAT(fr.RepYear, '-10-01'), '%Y-%m-%d')
                END
            ) >= (CURDATE() - INTERVAL 1 YEAR)
          AND fr.CompanyID IN ($idList)
        GROUP BY fr.CompanyID
    ";

    $result = $conn->query($sqlFin);
    if ($result) {
        while ($r = $result->fetch_assoc()) {
            $cid = (int)$r['CompanyID'];
            $financialKpi[$cid] = array(
                'avg_health' => isset($r['AvgHealthScoreLastYear']) ? (float)$r['AvgHealthScoreLastYear'] : null
            );
        }
        $result->free();
    } else {
        echo "Financial KPI query error: " . htmlspecialchars($conn->error);
    }

    foreach ($companyIds as $cid) {
        if (!isset($financialKpi[$cid])) {
            $financialKpi[$cid] = array('avg_health' => null);
        }
    }

    $sqlDisrupt = "
        SELECT
            ic.AffectedCompanyID AS CompanyID,
            COUNT(*) AS DisruptionCountLastYear
        FROM ImpactsCompany ic
        JOIN DisruptionEvent de ON ic.EventID = de.EventID
        WHERE de.EventDate >= CURDATE() - INTERVAL 1 YEAR
          AND ic.AffectedCompanyID IN ($idList)
        GROUP BY ic.AffectedCompanyID
    ";

    $result = $conn->query($sqlDisrupt);
    if ($result) {
        while ($r = $result->fetch_assoc()) {
            $cid = (int)$r['CompanyID'];
            $disruptionKpi[$cid] = (int)$r['DisruptionCountLastYear'];
        }
        $result->free();
    } else {
        echo "Disruption KPI query error: " . htmlspecialchars($conn->error);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Supply Chain Manager</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Leaflet CSS & JS (for the world RRC map) -->
    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
        crossorigin=""
    />
    <script
        src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""
    ></script>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        #rrcMap {
            width: 100%;
            height: 420px;
            border-radius: 12px;
            overflow: hidden;
            background: #ffffff;
        }

        .rrc-legend {
            position: absolute;
            bottom: 18px;
            left: 18px;
            z-index: 999;
            background: rgba(255,255,255,0.9);
            padding: 8px 10px;
            border-radius: 6px;
            font-size: 11px;
            color: #111827;
            box-shadow: 0 2px 6px rgba(0,0,0,0.25);
        }

        .rrc-legend-row {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 2px;
        }

        .rrc-legend-color {
            width: 14px;
            height: 10px;
            border-radius: 2px;
        }

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
            padding: 0.5rem 12px;
            z-index: 1000;
            box-shadow: 0 2px 4px rgba(0,0,0,0.5);

            display: flex;
            align-items: center;
        }

        .top-header-title {
            flex: 1;
            text-align: center;
            font-size: 1.2rem;
            font-weight: 500;
        }

        .top-header-left {
            flex: 1;
            display: flex;
            align-items: center;
        }

        .top-header-right {
            flex: 1;
            display: flex;
            justify-content: flex-end;
        }

        .logout-btn {
            background-color: #cfb991;
            color: #111;
            padding: 6px 14px;
            border-radius: 999px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: bold;
            border: 1px solid #bfa678;
            transition: background-color 0.15s ease, transform 0.05s ease;
        }

        .logout-btn:hover {
            background-color: #e0c27f;
            transform: translateY(-1px);
        }

        .page {
            min-height: 100vh;
            padding-top: 80px;
            padding-left: 8px;
            padding-right: 8px;
            padding-bottom: 60px;
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
            grid-template-columns: repeat(2, minmax(0, 1fr));  
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

        .plot-canvas-wrapper {
            position: relative;
            width: 100%;
            height: 350px; 
            margin-top: 6px;
        }

        .plot-canvas-wrapper canvas {
            position: absolute;
            inset: 0;
            width: 100% !important;
            height: 100% !important;
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
            background-color: #064e3b;      
            border-color: #6ee7b7;         
            color: #d1fae5;                 
        }

        .alert-dot-ok {
            background-color: #22c55e;      
            box-shadow: 0 0 0 4px rgba(34,197,94,0.3);
        }

        .tab-buttons {
            display: flex;
            gap: 6px;
        }

        .tab-btn {
            padding: 4px 10px;
            border-radius: 999px;
            border: 1px solid #444;
            background-color: #1f2933;
            color: #f9fafb;
            font-size: 0.8rem;
            cursor: pointer;
        }

        .tab-btn:hover {
            background-color: #374151;
            border-color: #9ca3af;
        }

        .tab-btn-active {
            background-color: #cfb991;
            color: #000;
            border-color: #e0c27f;
        }

        .tab-panel {
            display: none;
        }

        .tab-panel-active {
            display: block;
        }

        .link-button {
            background: none;
            border: none;
            padding: 0;
            color: #93c5fd;
            text-decoration: underline;
            cursor: pointer;
            font: inherit;
        }
        .link-button:hover {
            color: #bfdbfe;
        }

        
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.85);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding-top: 40px;
            padding-bottom: 40px;
            z-index: 2000;
            animation: fadeIn 0.2s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-card {
            background: linear-gradient(135deg, #2d2d2d 0%, #262626 100%);
            color: var(--text);
            border-radius: 20px;
            border: 1px solid #444;
            box-shadow: 0 20px 60px rgba(0,0,0,0.9), 0 0 0 1px rgba(255,255,255,0.05);
            max-width: 1100px;
            width: 95%;
            max-height: 85vh;
            overflow-y: auto;
            padding: 0;
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                transform: translateY(30px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-card::-webkit-scrollbar {
            width: 8px;
        }

        .modal-card::-webkit-scrollbar-track {
            background: #1a1a1a;
            border-radius: 0 20px 20px 0;
        }

        .modal-card::-webkit-scrollbar-thumb {
            background: #555;
            border-radius: 4px;
        }

        .modal-card::-webkit-scrollbar-thumb:hover {
            background: #666;
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 24px 28px;
            background: linear-gradient(135deg, #1a1a1a 0%, #222 100%);
            border-bottom: 2px solid var(--accent);
            border-radius: 20px 20px 0 0;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--accent);
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        .modal-close {
            font-size: 2rem;
            color: #ccc;
            text-decoration: none;
            padding: 0 8px;
            line-height: 1;
            transition: all 0.2s ease;
            border-radius: 8px;
        }
        .modal-close:hover {
            color: #fff;
            background: rgba(255,255,255,0.1);
            transform: rotate(90deg);
        }

        .modal-section {
            margin: 0;
            padding: 24px 28px;
            border-bottom: 1px solid #333;
        }

        .modal-section:last-child {
            border-bottom: none;
            border-radius: 0 0 20px 20px;
        }

        .modal-section h4 {
            margin: 0 0 12px 0;
            font-size: 1.15rem;
            color: #cfb991;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .modal-section h4::before {
            content: '';
            width: 4px;
            height: 20px;
            background: var(--accent);
            border-radius: 2px;
        }

        .modal-columns {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 16px;
        }

        .modal-columns > div {
            background: rgba(0,0,0,0.2);
            border: 1px solid #3a3a3a;
            border-radius: 12px;
            padding: 16px;
        }

        .modal-columns > div strong {
            display: block;
            color: var(--accent);
            margin-bottom: 10px;
            font-size: 0.95rem;
        }

        .modal-columns ul {
            margin: 8px 0 0 0;
            padding-left: 20px;
            list-style: none;
        }

        .modal-columns ul li {
            padding: 6px 0;
            color: #ddd;
            position: relative;
        }

        .modal-columns ul li::before {
            content: '→';
            position: absolute;
            left: -20px;
            color: var(--accent);
        }

        .modal-subtable {
            background: rgba(0,0,0,0.2);
            border: 1px solid #3a3a3a;
            border-radius: 12px;
            padding: 16px;
            margin-top: 16px;
        }

        .modal-subtable h5 {
            margin: 0 0 12px 0;
            font-size: 1rem;
            color: #cfb991;
            font-weight: 600;
        }

        .modal-subtable table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .modal-subtable th,
        .modal-subtable td {
            border-bottom: 1px solid #3a3a3a;
            padding: 10px 8px;
            text-align: left;
        }

        .modal-subtable thead {
            background: rgba(207,185,145,0.1);
        }

        .modal-subtable th {
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-size: 0.75rem;
            color: var(--accent);
            font-weight: 600;
        }

        .modal-subtable tbody tr {
            transition: background 0.15s ease;
        }

        .modal-subtable tbody tr:hover {
            background: rgba(207,185,145,0.05);
        }

        .modal-subtable tbody tr:last-child td {
            border-bottom: none;
        }

        .modal-section ul {
            margin: 12px 0;
            padding-left: 24px;
        }

        .modal-section ul li {
            padding: 6px 0;
            color: #ddd;
            line-height: 1.5;
        }

        .kpi-card {
            background: linear-gradient(145deg, #3a3a3a, #2a2a2a);
            border: 1px solid #000;
            border-radius: 14px;
            padding: 16px 20px;
            width: 260px;
            color: var(--text);
            box-shadow: 0px 3px 6px rgba(0,0,0,0.6);
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin: 6px 0 18px 0;
        }

        .kpi-icon {
            font-size: 1.4rem;
        }

        .kpi-label {
            font-size: 0.8rem;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .kpi-value {
            font-size: 1.9rem;
            font-weight: 700;
        }

        .kpi-subtext {
            font-size: 0.75rem;
            color: var(--muted);
        }

        .global-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            padding: 8px 0;
            background: #1a1a1a;
            color: var(--text);
            border-top: 1px solid #333;
            display: flex;
            justify-content: center;
            gap: 40px;
            font-size: 0.9rem;
            z-index: 999; 
        }


        .footer-timezone {
            color: var(--muted);
        }

        .footer-timezone strong {
            color: var(--accent);
            margin-right: 4px;
        }
    </style>
</head>
<body>

<div class="top-header">
    <div class="top-header-left">
        <div class="tab-buttons">
            <button class="tab-btn tab-btn-active" data-tab-target="tab-company">Companies</button>
            <button class="tab-btn" data-tab-target="tab-disruptions">Disruptions</button>
            <button class="tab-btn" data-tab-target="tab-transactions">Transactions</button>
        </div>
    </div>
    <div class="top-header-title">
        Supply Chain Manager
    </div>
    <div class="top-header-right">
        <a href="index.php" class="logout-btn">Log Out</a>
    </div>
</div>

<!-- Entire SCM Page -->
<div class="page">
    <!-- MODULE 1: Company table -->
    <div class="tab-panel <?php if ($activeTab === 'tab-company') echo 'tab-panel-active'; ?>" id="tab-company">
        <div class="card">
            <div class="module-header">
                <h2 class="module-header-title">Company Info Table</h2>
            </div>

            <?php if ($CompTableResult->num_rows > 0): ?>
                <div class="search-bar">
                    <input 
                        type="text" 
                        id="companySearch" 
                        placeholder="Search by any category..."
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
                            <th> STD Dev. Delay </th>
                            <th> Fin Health </th>
                            <th> Disruption Dist.</th>
                        </tr>
                        </thead>
                            <tbody>
                                <?php foreach ($companyRows as $row): ?>
                                    <?php
                                    $cid = isset($row['CompanyID']) ? (int)$row['CompanyID'] : 0;
                                    $ship = isset($deliveryKpi[$cid])   ? $deliveryKpi[$cid]   : null;
                                    $fin  = isset($financialKpi[$cid])  ? $financialKpi[$cid]  : null;
                                    $disc = isset($disruptionKpi[$cid]) ? $disruptionKpi[$cid] : 0;

                                    // Delivery Rate
                                    if ($ship && isset($ship['on_time_rate']) && $ship['on_time_rate'] !== null) {
                                        $deliveryRate = round($ship['on_time_rate'] * 100, 1) . '%';
                                    } else {
                                        $deliveryRate = 'N/A';
                                    }

                                    // Avg Delay
                                    if ($ship && isset($ship['avg_delay']) && $ship['avg_delay'] !== null) {
                                        $avgDelay = round($ship['avg_delay'], 2) . ' days';
                                    } else {
                                        $avgDelay = 'N/A';
                                    }

                                    // Std dev Delay
                                    if ($ship && isset($ship['std_delay']) && $ship['std_delay'] !== null) {
                                        $stdDelay = round($ship['std_delay'], 2) . ' days';
                                    } else {
                                        $stdDelay = 'N/A';
                                    }

                                    // Fin Health
                                    if ($fin && isset($fin['avg_health']) && $fin['avg_health'] !== null) {
                                        $finHealth = round($fin['avg_health'], 1);
                                    } else {
                                        $finHealth = 'N/A';
                                    }
                                    ?>
                                <tr>
                                    <td>
                                        <form method="get" style="display:inline;">
                                            <input type="hidden" name="active_tab" value="tab-company">
                                            <input type="hidden" name="focus_company" value="<?php echo (int)$row['CompanyID']; ?>">
                                            <input type="hidden" name="c_start" value="<?php echo htmlspecialchars($cStart); ?>">
                                            <input type="hidden" name="c_end"   value="<?php echo htmlspecialchars($cEnd); ?>">
                                            <button type="submit" class="link-button">
                                                <?php echo htmlspecialchars($row['CompanyName']); ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td><span class="pill"><?php echo htmlspecialchars($row['Type']); ?></span></td>
                                    <td><span class="pill tier-pill"><?php echo htmlspecialchars($row['TierLevel']); ?></span></td>
                                    <td><?php echo htmlspecialchars($row['City']); ?></td>
                                    <td><?php echo htmlspecialchars($row['CountryName']); ?></td>
                                    <td><?php echo htmlspecialchars($deliveryRate); ?></td>
                                    <td><?php echo htmlspecialchars($avgDelay); ?></td>
                                    <td><?php echo htmlspecialchars($stdDelay); ?></td>
                                    <td><?php echo htmlspecialchars($finHealth); ?></td>
                                    <td><?php echo htmlspecialchars($disc); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($focusCompanyId > 0 && $focusCompanyName !== ''): ?>
                    <div id="companyModal" class="modal-backdrop">
                        <div class="modal-card">
                            <div class="modal-header">
                                <div>
                                    <h3><?php echo htmlspecialchars($focusCompanyName); ?></h3>
                                    <p class="chart-subtitle">
                                        Company details for:
                                        <?php echo htmlspecialchars($cStart); ?> to <?php echo htmlspecialchars($cEnd); ?>
                                    </p>
                                </div>
                                <form method="get" class="df-form" style="margin:0; gap:8px; align-items:flex-end;">
                                    <input type="hidden" name="active_tab" value="tab-company">
                                    <input type="hidden" name="focus_company" value="<?php echo (int)$focusCompanyId; ?>">

                                    <div class="df-field">
                                        <label for="c_start">Start</label>
                                        <input type="date" id="c_start" name="c_start"
                                            value="<?php echo htmlspecialchars($cStart); ?>">
                                    </div>

                                    <div class="df-field">
                                        <label for="c_end">End</label>
                                        <input type="date" id="c_end" name="c_end"
                                            value="<?php echo htmlspecialchars($cEnd); ?>">
                                    </div>

                                    <button type="submit">Update</button>
                                </form>

                                <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>?active_tab=tab-company"
                                class="modal-close">&times;</a>
                            </div>

                            <div class="modal-section">
                                <h4>Network Dependencies</h4>
                                <div class="modal-columns">
                                    <div>
                                        <strong>Depends on (upstream):</strong>
                                        <?php if (count($dependsOn)): ?>
                                            <ul>
                                                <?php foreach ($dependsOn as $co): ?>
                                                    <li><?php echo htmlspecialchars($co['CompanyName']); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php else: ?>
                                            <p class="no-data">No upstream partners in this date range.</p>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <strong>Depended on by (downstream):</strong>
                                        <?php if (count($dependedBy)): ?>
                                            <ul>
                                                <?php foreach ($dependedBy as $co): ?>
                                                    <li><?php echo htmlspecialchars($co['CompanyName']); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php else: ?>
                                            <p class="no-data">No downstream partners in this date range.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="modal-section">
                                <h4>Capacity & Routes</h4>
                                <p class="chart-subtitle">
                                    Metrics based on shipments in the current transaction date range.
                                </p>
                                <ul>
                                    <li>
                                        <strong>Proxy capacity (shipped out):</strong>
                                        <?php
                                        if ($capacityInfo && $capacityInfo['TotalQuantity'] !== null) {
                                            echo (int)$capacityInfo['TotalQuantity'] . " units across " .
                                                (int)$capacityInfo['ShipmentCount'] . " shipments.";
                                        } else {
                                            echo "No outbound shipments in this period.";
                                        }
                                        ?>
                                    </li>
                                    <li>
                                        <strong>Unique routes operated (as distributor):</strong>
                                        <?php
                                        if ($uniqueRouteCount !== null && $uniqueRouteCount > 0) {
                                            echo (int)$uniqueRouteCount . " distinct source–destination routes.";
                                        } else {
                                            echo "No distributor routes in this period.";
                                        }
                                        ?>
                                    </li>
                                </ul>
                            </div>

                            <div class="modal-section">
                                <h4>Products Supplied</h4>
                                <?php if (count($productList)): ?>
                                    <p class="chart-subtitle">
                                        Diversity: <?php echo count($productList); ?> distinct products supplied in this period.
                                    </p>
                                    <ul>
                                        <?php foreach ($productList as $p): ?>
                                            <li>
                                                <?php echo htmlspecialchars($p['ProductName']); ?>
                                                — <?php echo (int)$p['ShipmentCount']; ?> shipments
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="no-data">No outbound product shipments in this period.</p>
                                <?php endif; ?>
                            </div>

                            <div class="modal-section">
                                <h4>Transactions (within date range)</h4>

                                <div class="modal-subtable">
                                    <h5>Shipping (outbound)</h5>
                                    <?php if (count($shipmentsOut)): ?>
                                        <table>
                                            <thead>
                                                <tr>
                                                    <th>ID</th>
                                                    <th>To</th>
                                                    <th>Product</th>
                                                    <th>Qty</th>
                                                    <th>Promised</th>
                                                    <th>Delivered</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($shipmentsOut as $s): ?>
                                                    <tr>
                                                        <td><?php echo (int)$s['ShipmentID']; ?></td>
                                                        <td><?php echo htmlspecialchars($s['DestName']); ?></td>
                                                        <td><?php echo htmlspecialchars($s['ProductName']); ?></td>
                                                        <td><?php echo (int)$s['Quantity']; ?></td>
                                                        <td><?php echo htmlspecialchars($s['PromisedDate']); ?></td>
                                                        <td><?php echo htmlspecialchars($s['ActualDate']); ?></td>
                                                        <td><?php echo htmlspecialchars($s['StatusLabel']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php else: ?>
                                        <p class="no-data">No outbound shipments in this period.</p>
                                    <?php endif; ?>
                                </div>

                                <div class="modal-subtable" style="margin-top:20px;">
                                    <h5>Receiving (inbound)</h5>
                                    <?php if (count($shipmentsIn)): ?>
                                        <table>
                                            <thead>
                                                <tr>
                                                    <th>ID</th>
                                                    <th>From</th>
                                                    <th>Product</th>
                                                    <th>Qty</th>
                                                    <th>Promised</th>
                                                    <th>Delivered</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($shipmentsIn as $s): ?>
                                                    <tr>
                                                        <td><?php echo (int)$s['ShipmentID']; ?></td>
                                                        <td><?php echo htmlspecialchars($s['SourceName']); ?></td>
                                                        <td><?php echo htmlspecialchars($s['ProductName']); ?></td>
                                                        <td><?php echo (int)$s['Quantity']; ?></td>
                                                        <td><?php echo htmlspecialchars($s['PromisedDate']); ?></td>
                                                        <td><?php echo htmlspecialchars($s['ActualDate']); ?></td>
                                                        <td><?php echo htmlspecialchars($s['StatusLabel']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php else: ?>
                                        <p class="no-data">No inbound shipments in this period.</p>
                                    <?php endif; ?>
                                </div>

                            </div>

                        </div>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="no-data">No company records found.</div>
            <?php endif; ?>

            <div class="chart-header" style="margin-top: 16px;">
                <h3 class="chart-title">Edit Company Info</h3>
                <p class="chart-subtitle">
                    Select a company and update its type, tier, and location using existing values.
                </p>
            </div>

            <?php if ($companyUpdateMessage): ?>
                <div class="alert-banner alert-banner-ok">
                    <span class="alert-dot alert-dot-ok"></span>
                    <span class="alert-text">
                        <?php echo htmlspecialchars($companyUpdateMessage); ?>
                    </span>
                </div>
            <?php elseif ($companyUpdateError): ?>
                <div class="alert-banner">
                    <span class="alert-dot"></span>
                    <span class="alert-text">
                        <?php echo htmlspecialchars($companyUpdateError); ?>
                    </span>
                </div>
            <?php endif; ?>

            <form method="post" class="df-form">
                <input type="hidden" name="active_tab" value="tab-company">

                <!-- Company dropdown -->
                <div class="df-field">
                    <label for="edit_company_id">Company</label>
                    <select id="edit_company_id" name="edit_company_id" required>
                        <option value="">Select a company...</option>
                        <?php foreach ($companyRows as $row): ?>
                            <option value="<?php echo (int)$row['CompanyID']; ?>">
                                <?php echo htmlspecialchars($row['CompanyName']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Type dropdown -->
                <div class="df-field">
                    <label for="edit_company_type">Type</label>
                    <select id="edit_company_type" name="edit_company_type" required>
                        <option value="">Select type...</option>
                        <?php foreach ($typeOptions as $t): ?>
                            <option value="<?php echo htmlspecialchars($t); ?>">
                                <?php echo htmlspecialchars($t); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Tier dropdown -->
                <div class="df-field">
                    <label for="edit_company_tier">Tier</label>
                    <select id="edit_company_tier" name="edit_company_tier" required>
                        <option value="">Select tier...</option>
                        <?php foreach ($tierOptions as $t): ?>
                            <option value="<?php echo htmlspecialchars($t); ?>">
                                <?php echo htmlspecialchars($t); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Country dropdown -->
                <div class="df-field">
                    <label for="edit_company_country">Country</label>
                    <select id="edit_company_country" name="edit_company_country" required>
                        <option value="">Select country...</option>
                        <?php foreach ($countryOptionsEdit as $c): ?>
                            <option value="<?php echo htmlspecialchars($c); ?>">
                                <?php echo htmlspecialchars($c); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- City dropdown -->
                <div class="df-field">
                    <label for="edit_company_city">City</label>
                    <select id="edit_company_city" name="edit_company_city" required>
                        <option value="">Select city...</option>
                        <?php foreach ($cityOptionsEdit as $city): ?>
                            <option value="<?php echo htmlspecialchars($city); ?>">
                                <?php echo htmlspecialchars($city); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Continent dropdown -->
                <div class="df-field">
                    <label for="edit_company_continent">Continent</label>
                    <select id="edit_company_continent" name="edit_company_continent" required>
                        <option value="">Select continent...</option>
                        <?php foreach ($continentOptionsEdit as $cont): ?>
                            <option value="<?php echo htmlspecialchars($cont); ?>">
                                <?php echo htmlspecialchars($cont); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" name="company_edit_submit">
                    Save Company Changes
                </button>
            </form>

        </div> <!-- End module 1 card -->
    </div> <!-- End company tab -->
    
    <!-- MODULE 2: Disruption Events -->
    <div class="tab-panel <?php if ($activeTab === 'tab-disruptions') echo 'tab-panel-active'; ?>" id="tab-disruptions">
        <div class="card">
            <div class="module-header">
                <h2 class="module-header-title">Disruption Events Module</h2>
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

            <br><br>

            <!-- Shared date range for all charts -->
            <form method="get" class="df-form">
                <input type="hidden" name="active_tab" value="tab-disruptions">
                <div class="df-field">
                    <label for="df_start">Start date</label>
                    <input type="date" id="df_start" name="df_start" value="<?php echo htmlspecialchars($dfStart); ?>">
                </div>
                <div class="df-field">
                    <label for="df_end">End date</label>
                    <input type="date" id="df_end" name="df_end" value="<?php echo htmlspecialchars($dfEnd); ?>">
                </div>
                <button type="submit">Update Disruption Event(s) Date Range</button>
            </form>
            
            <br><br>
            
            <!-- DF chart -->
            <div class="chart-header">
                <h3 class="chart-title">Disruption Frequency by Company</h3>
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
            
            <br><br>

            <!-- ART chart -->
            <div class="chart-header">
                <h3 class="chart-title">Average Recovery Time</h3>
                <p class="chart-subtitle">
                    ART this period:
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

            <br><br>    

            <!-- Total Downtime (TD) -->
            <div class="chart-header">
                <h3 class="chart-title"> Total Downtime</h3>
                <p class="chart-subtitle">Aggregated Downtime:
                <strong><?php echo htmlspecialchars($totalDowntimeDays); ?></strong> days
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

            <br><br>

            <!-- Regional Risk Concentration -->
            <div class="chart-header">
                <h3 class="chart-title"> Regional Risk Concentration (RRC) </h3>
            </div>

            <div class="df-chart-wrapper">
                <?php if (!empty($rrcRegions)): ?>
                    <div style="position:relative;">
                        <div id="rrcMap"></div>
                        <div id="rrcLegend" class="rrc-legend"></div>
                    </div>
                <?php else: ?>
                    <div class="no-data">
                        No disruptions in the selected date range to compute regional risk.
                    </div>
                <?php endif; ?>
            </div>

            <br><br>

            <!-- HDR -->
            <div class="chart-header">
                <h3 class="chart-title">High-Impact Disruption Rate</h3>
            </div>
            <div class="kpi-card">
                <div class="kpi-value" style="color: <?php echo htmlspecialchars($hdrColor); ?>">
                    <?php
                    if ($HDRpercent !== null) {
                        echo htmlspecialchars(number_format($HDRpercent, 1)) . '%';
                    } else {
                        echo 'N/A';
                    }
                    ?>
                </div>

                <div class="kpi-subtext">
                    Share of disruption events classified as high impact
                </div>
            </div>
            
            <br><br>

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
    </div>

    <!-- Module 3: Transaction Analysis -->
    <div class="tab-panel <?php if ($activeTab === 'tab-transactions') echo 'tab-panel-active'; ?>" id="tab-transactions">
        <div class="card">
            <div class="module-header">
                <h2 class="module-header-title">Transaction Analysis</h2>
            </div>

            <!-- Filters module 3 -->
            <form method="get" class="df-form">
                <input type="hidden" name="active_tab" value="tab-transactions">
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

            <div class="search-bar">
                <input type="text" id="txSearch" placeholder="Search by any category...">
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
                <h3 class="chart-title">Distributors</h3>
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
    </div>
    
    <!-- Sticky Footer -->
   <footer class="global-footer">
        <div class="footer-timezone">
            <strong>New York:</strong> <span id="clock-ny"></span>
        </div>
        <div class="footer-timezone">
            <strong>London:</strong> <span id="clock-lon"></span>
        </div>
        <div class="footer-timezone">
            <strong>Shanghai:</strong> <span id="clock-sh"></span>
        </div>
    </footer>

</div> <!-- Ends Page -->

<!-- JS, graphs -->
<script>
var rrcMap = null;

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
                    backgroundColor: '#fed7aa',
                    borderColor: '#fb923c',
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
                            text: 'Company',
                            color: '#f5f5f5',
                            font: { size:18 }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { color: '#f5f5f5' },
                        grid: { color: 'rgba(255,255,255,0.1)' },
                        title: {
                            display: true,
                            text: 'Events per Day',
                            color: '#f5f5f5',
                            font: { size:18 }
                        }
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
                        grid: { display: false },
                        title: {
                            display: true,
                            text: 'Time Period (days)',
                            color: '#f5f5f5',
                            font: { size:18 }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { color: '#f5f5f5' },
                        grid: { color: 'rgba(255,255,255,0.1)' },
                        title: {
                            display: true,
                            text: 'Disruptions',
                            color: '#f5f5f5',
                            font: { size:18 }
                        }
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
                            font: { size:18 }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { color: '#f5f5f5' },
                        grid: { color: 'rgba(255,255,255,0.1)' },
                        title: {
                            display: true,
                            text: 'Number of Disruptions',
                            color: '#f5f5f5',
                            font: { size:18 }
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

    // Module 2: RRC World Map 
    var rrcRegions = <?php echo $rrcRegionsJson; ?>; 
    var rrcValues  = <?php echo $rrcValuesJson; ?>;   

    // Basic normalization: lowercase, remove accents and non-letters
    function normKey(s) {
        return (s || '')
            .toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z]/g, '');
    }

    // Map DB names -> GeoJSON names (only fix what you actually use)
    function canonicalKey(raw) {
        var k = normKey(raw);
        // DB has "United States", GeoJSON has "United States of America"
        if (k === "unitedstates") return "unitedstatesofamerica";
        return k;
    }

    if (rrcRegions.length && document.getElementById('rrcMap')) {
        // 1) Build lookup table name -> RRC & find max
        var rrcByName = {};
        var maxRRC = 0;
        for (var i = 0; i < rrcRegions.length; i++) {
            var name = rrcRegions[i] || "";
            var val  = rrcValues[i] || 0;
            var key  = canonicalKey(name);
            rrcByName[key] = val;
            if (val > maxRRC) maxRRC = val;
        }

        // 2) Map RRC -> color (green -> red)
        function rrcColor(rrc) {
            if (!rrc || !maxRRC) return '#e5e7eb'; 
            var t = rrc / maxRRC;                  
            var hue = (1 - t) * 120;               
            return 'hsl(' + hue + ', 80%, 50%)';
        }

        // 3) Build legend once, using same color scale
        function buildRRCLegend() {
            var legend = document.getElementById('rrcLegend');
            if (!legend) return;

            legend.innerHTML = `
                <div class="rrc-legend-row">
                    <span class="rrc-legend-color" style="background:#e5e7eb"></span>
                    No data
                </div>
                <div class="rrc-legend-row">
                    <span class="rrc-legend-color" style="background:${rrcColor(maxRRC * 0.25)}"></span>
                    Low
                </div>
                <div class="rrc-legend-row">
                    <span class="rrc-legend-color" style="background:${rrcColor(maxRRC * 0.50)}"></span>
                    Medium
                </div>
                <div class="rrc-legend-row">
                    <span class="rrc-legend-color" style="background:${rrcColor(maxRRC * 0.75)}"></span>
                    High
                </div>
                <div class="rrc-legend-row">
                    <span class="rrc-legend-color" style="background:${rrcColor(maxRRC)}"></span>
                    Very high
                </div>
            `;
        }

        buildRRCLegend();

        // 4) Init Leaflet map
        rrcMap = L.map('rrcMap', {
            center: [20, 0],
            zoom: 1,
            worldCopyJump: true,
            maxBounds: [[-85, -180], [85, 180]]
        });

        // Light OpenStreetMap basemap
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 5,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(rrcMap);

        // 5) Load world polygons (GeoJSON)
        fetch('https://d2ad6b4ur7yvpq.cloudfront.net/naturalearth-3.3.0/ne_110m_admin_0_countries.geojson')
          .then(function(res) { return res.json(); })
          .then(function(geojson) {
              var layer = L.geoJSON(geojson, {
                  style: function(feature) {
                      var name = feature.properties.name || feature.properties.ADMIN;
                      var key  = canonicalKey(name);
                      var rrc  = rrcByName[key] || 0;
                      return {
                          color: '#ffffff',
                          weight: 0.5,
                          fillColor: rrcColor(rrc),
                          fillOpacity: rrc ? 0.9 : 0.3
                      };
                  },
                  onEachFeature: function(feature, layer) {
                      var name = feature.properties.name || feature.properties.ADMIN;
                      var key  = canonicalKey(name);
                      var rrc  = rrcByName[key] || 0;
                      var pct  = (rrc * 100).toFixed(2);
                      var html = rrc
                          ? '<b>' + name + '</b><br>RRC: ' + pct + '%'
                          : '<b>' + name + '</b><br>No disruptions recorded';
                      layer.bindPopup(html);
                  }
              }).addTo(rrcMap);

              rrcMap.fitBounds(layer.getBounds());
          })
          .catch(function(err) {
              console.error('Failed to load world GeoJSON', err);
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
                            font: { size: 18 }
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

            for (var i = 1; i < rows.length; i++) { 
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
    var otrLabelsRaw = <?php echo $distNamesJson; ?>    
    var otrDataRaw = <?php echo $distOnTimeRateJson; ?> 
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
                    backgroundColor: '#bbf7d0',  
                    borderColor: '#22c55e',     
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
                        suggestedMax: 100, 
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

    var statusLabels = Object.keys(statusTotals);      
    var statusValues = Object.values(statusTotals);     

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
                        '#60a5fa',  
                        '#4ade80',  
                        '#f87171'   
                    ],
                    borderColor: '#1a1a1a',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',  
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
    var expLabels = <?php echo $distNamesJson; ?>;      
    var expData   = <?php echo $distExposureJson; ?>;   

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
                    backgroundColor: '#fed7aa',  
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
    
})();
</script>

<!-- JS, tab switching -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    var tabButtons = document.querySelectorAll('.tab-btn');
    var tabPanels  = document.querySelectorAll('.tab-panel');

    function activateTab(targetId) {
        tabPanels.forEach(function (panel) {
            if (panel.id === targetId) {
                panel.classList.add('tab-panel-active');
            } else {
                panel.classList.remove('tab-panel-active');
            }
        });

        tabButtons.forEach(function (btn) {
            if (btn.getAttribute('data-tab-target') === targetId) {
                btn.classList.add('tab-btn-active');
            } else {
                btn.classList.remove('tab-btn-active');
            }
        });

        if (targetId === 'tab-disruptions' && window.rrcMap) {
            setTimeout(function () {
                rrcMap.invalidateSize();
                rrcMap.setView([20,0],2);
            }, 200);
        }
    }

    tabButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetId = this.getAttribute('data-tab-target');
            activateTab(targetId);
        });
    });
});
</script>

<!-- JS, Edit companies -->
<script>
// Company data to preselect dropdowns
var companyData = <?php
    $companyMap = array();
    foreach ($companyRows as $r) {
        $companyMap[$r['CompanyID']] = array(
            'Type' => $r['Type'],
            'TierLevel' => $r['TierLevel'],
            'CountryName'=> $r['CountryName'],
            'City' => $r['City'],
            'ContinentName' => $r['ContinentName'],
        );
    }
    echo json_encode($companyMap);
?>;

document.addEventListener('DOMContentLoaded', function () {
    var selCompany = document.getElementById('edit_company_id');
    var selType = document.getElementById('edit_company_type');
    var selTier = document.getElementById('edit_company_tier');
    var selCountry = document.getElementById('edit_company_country');
    var selCity = document.getElementById('edit_company_city');
    var selContinent = document.getElementById('edit_company_continent');

    if (!selCompany) return;

    selCompany.addEventListener('change', function () {
        var id = this.value;
        if (!id || !companyData[id]) {
            selType.value = '';
            selTier.value = '';
            selCountry.value = '';
            selCity.value = '';
            selContinent.value = '';
            return;
        }

        var data = companyData[id];

        selType.value = data.Type || '';
        selTier.value = data.TierLevel || '';
        selCountry.value = data.CountryName || '';
        selCity.value = data.City || '';
        selContinent.value = data.ContinentName || '';
    });
});
</script>

<!-- JS, world clocks -->
<script>
function updateClocks() {
    const now = new Date();

    const fmt = {
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit"
    };

    document.getElementById("clock-ny").textContent =
        now.toLocaleTimeString("en-US", { ...fmt, timeZone: "America/New_York" });

    document.getElementById("clock-lon").textContent =
        now.toLocaleTimeString("en-US", { ...fmt, timeZone: "Europe/London" });

    document.getElementById("clock-sh").textContent =
        now.toLocaleTimeString("en-US", { ...fmt, timeZone: "Asia/Shanghai" });
}

updateClocks();
setInterval(updateClocks, 1000);
</script>
</body>
</html>
<?php
$conn->close();
?>