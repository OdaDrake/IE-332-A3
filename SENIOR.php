<?php
session_start();
date_default_timezone_set('America/Indiana/Indianapolis');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// DB connection
$servername = "mydb.itap.purdue.edu";
$username   = "g1151918";
$password   = "group8ie332";
$database   = $username;

$conn = new mysqli($servername, $username, $password, $database);
$conn->set_charset('utf8mb4');

if ($conn->connect_error) {
    die("Connection failed: " . htmlspecialchars($conn->connect_error));
}

// Active tab tracking
$activeTab = isset($_GET['active_tab']) ? $_GET['active_tab'] : 'financial';

// -------------------------------------------------
// MODULE 1: Financial Health Analysis
// -------------------------------------------------

// Date range filters
$fhStart = isset($_GET['fh_start']) ? trim($_GET['fh_start']) : '';
$fhEnd   = isset($_GET['fh_end'])   ? trim($_GET['fh_end'])   : '';

if ($fhStart === '' && $fhEnd === '') {
    $fhEnd   = date('Y-m-d');
    $fhStart = date('Y-m-d', strtotime('-365 days'));
}

// Extract years for SQL queries
$fhStartYear = (int)date('Y', strtotime($fhStart));
$fhEndYear = (int)date('Y', strtotime($fhEnd));

// ---------- Average Financial Health by Company ----------
$fhByCompanyLabels = array();
$fhByCompanyValues = array();

if ($fhStart !== '' && $fhEnd !== '') {
    $fhCompanySql = "
        SELECT 
            c.CompanyName,
            AVG(fr.HealthScore) AS AvgHealthScore
        FROM Company c
        JOIN FinancialReport fr 
            ON c.CompanyID = fr.CompanyID
        WHERE fr.RepYear >= $fhStartYear
          AND fr.RepYear <= $fhEndYear
        GROUP BY c.CompanyID, c.CompanyName
        HAVING AVG(fr.HealthScore) IS NOT NULL
        ORDER BY AvgHealthScore DESC
    ";

    $fhCompanyRes = $conn->query($fhCompanySql);
    if (!$fhCompanyRes) {
        die("Financial health by company query failed: " . $conn->error);
    }
    while ($row = $fhCompanyRes->fetch_assoc()) {
        $fhByCompanyLabels[] = $row['CompanyName'];
        $fhByCompanyValues[] = round($row['AvgHealthScore'], 2);
    }
    $fhCompanyRes->free();
}

// ---------- Average Financial Health by Company Type ----------
$fhByTypeLabels = array();
$fhByTypeValues = array();

if ($fhStart !== '' && $fhEnd !== '') {
    $fhTypeSql = "
        SELECT 
            c.Type,
            AVG(fr.HealthScore) AS AvgHealthScore
        FROM Company c
        JOIN FinancialReport fr 
            ON c.CompanyID = fr.CompanyID
        WHERE fr.RepYear >= $fhStartYear
          AND fr.RepYear <= $fhEndYear
        GROUP BY c.Type
        HAVING AVG(fr.HealthScore) IS NOT NULL
        ORDER BY AvgHealthScore DESC
    ";

    $fhTypeRes = $conn->query($fhTypeSql);
    if (!$fhTypeRes) {
        die("Financial health by type query failed: " . $conn->error);
    }
    while ($row = $fhTypeRes->fetch_assoc()) {
        $fhByTypeLabels[] = $row['Type'];
        $fhByTypeValues[] = round($row['AvgHealthScore'], 2);
    }
    $fhTypeRes->free();
}

// -------------------------------------------------
// MODULE 2: Regional Disruption Overview
// -------------------------------------------------

// Date range filters for disruptions
$rdStart = isset($_GET['rd_start']) ? trim($_GET['rd_start']) : '';
$rdEnd   = isset($_GET['rd_end'])   ? trim($_GET['rd_end'])   : '';

if ($rdStart === '' && $rdEnd === '') {
    $rdEnd   = date('Y-m-d');
    $rdStart = date('Y-m-d', strtotime('-365 days'));
}

// ---------- Regional Disruption Data ----------
$rdRegions = array();
$rdTotalDisruptions = array();
$rdLowImpactDisruptions = array();
$rdMediumImpactDisruptions = array();
$rdHighImpactDisruptions = array();

if ($rdStart !== '' && $rdEnd !== '') {
    $rdStartEsc = $conn->real_escape_string($rdStart);
    $rdEndEsc   = $conn->real_escape_string($rdEnd);

    $rdSql = "
        SELECT 
            l.ContinentName AS Region,
            COUNT(DISTINCT de.EventID) AS TotalDisruptions,
            SUM(CASE WHEN ic.ImpactLevel = 'Low' THEN 1 ELSE 0 END) AS LowImpactDisruptions,
            SUM(CASE WHEN ic.ImpactLevel = 'Medium' THEN 1 ELSE 0 END) AS MediumImpactDisruptions,
            SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) AS HighImpactDisruptions
        FROM DisruptionEvent de
        JOIN ImpactsCompany ic 
            ON de.EventID = ic.EventID
        JOIN Company c 
            ON ic.AffectedCompanyID = c.CompanyID
        JOIN Location l 
            ON c.LocationID = l.LocationID
        WHERE de.EventDate >= '$rdStartEsc'
          AND de.EventDate <= '$rdEndEsc'
        GROUP BY l.ContinentName
        ORDER BY TotalDisruptions DESC
    ";

    $rdRes = $conn->query($rdSql);
    if (!$rdRes) {
        die("Regional disruption query failed: " . $conn->error);
    }
    while ($row = $rdRes->fetch_assoc()) {
        $rdRegions[] = $row['Region'];
        $rdTotalDisruptions[] = (int)$row['TotalDisruptions'];
        $rdLowImpactDisruptions[] = (int)$row['LowImpactDisruptions'];
        $rdMediumImpactDisruptions[] = (int)$row['MediumImpactDisruptions'];
        $rdHighImpactDisruptions[] = (int)$row['HighImpactDisruptions'];
    }
    $rdRes->free();
}

// ---------- Most Critical Companies ----------
$criticalCompanies = array();
$criticalityScores = array();

if ($rdStart !== '' && $rdEnd !== '') {
    $rdStartEsc = $conn->real_escape_string($rdStart);
    $rdEndEsc   = $conn->real_escape_string($rdEnd);

    $criticalSql = "
        SELECT 
            c.CompanyName,
            COUNT(DISTINCT d.DownstreamCompanyID) AS DownstreamCount,
            SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) AS HighImpactCount,
            COUNT(DISTINCT d.DownstreamCompanyID) * SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) AS Criticality
        FROM Company c
        LEFT JOIN DependsOn d 
            ON c.CompanyID = d.UpstreamCompanyID
        LEFT JOIN ImpactsCompany ic 
            ON c.CompanyID = ic.AffectedCompanyID
        LEFT JOIN DisruptionEvent de 
            ON ic.EventID = de.EventID
            AND de.EventDate >= '$rdStartEsc'
            AND de.EventDate <= '$rdEndEsc'
        GROUP BY c.CompanyID, c.CompanyName
        HAVING Criticality > 0
        ORDER BY Criticality DESC
        LIMIT 20
    ";

    $criticalRes = $conn->query($criticalSql);
    if (!$criticalRes) {
        die("Critical companies query failed: " . $conn->error);
    }
    while ($row = $criticalRes->fetch_assoc()) {
        $criticalCompanies[] = $row['CompanyName'];
        $criticalityScores[] = (int)$row['Criticality'];
    }
    $criticalRes->free();
}

// ---------- Disruption Frequency Over Time ----------
$disruptionDates = array();
$disruptionCounts = array();

if ($rdStart !== '' && $rdEnd !== '') {
    $rdStartEsc = $conn->real_escape_string($rdStart);
    $rdEndEsc   = $conn->real_escape_string($rdEnd);

    $freqTimeSql = "
        SELECT 
            DATE_FORMAT(de.EventDate, '%Y-%m') AS MonthYear,
            COUNT(DISTINCT de.EventID) AS DisruptionCount
        FROM DisruptionEvent de
        WHERE de.EventDate >= '$rdStartEsc'
          AND de.EventDate <= '$rdEndEsc'
        GROUP BY DATE_FORMAT(de.EventDate, '%Y-%m')
        ORDER BY MonthYear ASC
    ";

    $freqTimeRes = $conn->query($freqTimeSql);
    if (!$freqTimeRes) {
        die("Disruption frequency over time query failed: " . $conn->error);
    }
    while ($row = $freqTimeRes->fetch_assoc()) {
        $disruptionDates[] = $row['MonthYear'];
        $disruptionCounts[] = (int)$row['DisruptionCount'];
    }
    $freqTimeRes->free();
}

// ---------- Supply Chain Vulnerability Score ----------
$vulnerableCompanies = array();
$vulnerabilityScores = array();

if ($rdStart !== '' && $rdEnd !== '') {
    $rdStartEsc = $conn->real_escape_string($rdStart);
    $rdEndEsc   = $conn->real_escape_string($rdEnd);

    $vulnerabilitySql = "
        SELECT 
            c.CompanyID,
            c.CompanyName,
            COUNT(DISTINCT d.DownstreamCompanyID) AS DownstreamCount,
            COUNT(DISTINCT ic.EventID) AS DisruptionCount,
            AVG(DATEDIFF(de.EventRecoveryDate, de.EventDate)) AS AvgRecoveryDays,
            AVG(fr.HealthScore) AS AvgHealthScore,
            (
                COUNT(DISTINCT d.DownstreamCompanyID) * 
                COUNT(DISTINCT ic.EventID) * 
                (1 + COALESCE(AVG(DATEDIFF(de.EventRecoveryDate, de.EventDate)), 0) / 30) /
                GREATEST(AVG(fr.HealthScore) / 100, 0.1)
            ) AS VulnerabilityScore
        FROM Company c
        LEFT JOIN DependsOn d 
            ON c.CompanyID = d.UpstreamCompanyID
        LEFT JOIN ImpactsCompany ic 
            ON c.CompanyID = ic.AffectedCompanyID
        LEFT JOIN DisruptionEvent de 
            ON ic.EventID = de.EventID
            AND de.EventDate >= '$rdStartEsc'
            AND de.EventDate <= '$rdEndEsc'
        LEFT JOIN FinancialReport fr 
            ON c.CompanyID = fr.CompanyID
        GROUP BY c.CompanyID, c.CompanyName
        HAVING COUNT(DISTINCT d.DownstreamCompanyID) > 0 
           AND COUNT(DISTINCT ic.EventID) > 0
           AND AVG(fr.HealthScore) IS NOT NULL
        ORDER BY VulnerabilityScore DESC
        LIMIT 20
    ";

    $vulnerabilityRes = $conn->query($vulnerabilitySql);
    if (!$vulnerabilityRes) {
        die("Vulnerability score query failed: " . $conn->error);
    }
    while ($row = $vulnerabilityRes->fetch_assoc()) {
        $vulnerableCompanies[] = $row['CompanyName'];
        $vulnerabilityScores[] = round((float)$row['VulnerabilityScore'], 2);
    }
    $vulnerabilityRes->free();
}

// -------------------------------------------------
// MODULE 3: Company Financials by Region
// -------------------------------------------------

// Company selector
$cfCompanyID = isset($_GET['cf_company']) ? (int)$_GET['cf_company'] : 0;

// Get list of all companies for dropdown (with region)
$companyListOptions = array();
$companyListSql = "
    SELECT 
        c.CompanyID, 
        c.CompanyName,
        l.CountryName,
        l.ContinentName
    FROM Company c
    JOIN Location l ON c.LocationID = l.LocationID
    ORDER BY c.CompanyName
";
$companyListRes = $conn->query($companyListSql);
if ($companyListRes) {
    while ($row = $companyListRes->fetch_assoc()) {
        $companyListOptions[] = $row;
    }
    $companyListRes->free();
}

// Selected company data
$selectedCompanyName = '';
$selectedCompanyRegion = '';
$cfQuarters = array();
$cfHealthScores = array();

if ($cfCompanyID > 0) {
    // Get company info
    $companyInfoSql = "
        SELECT 
            c.CompanyName,
            l.ContinentName,
            l.CountryName
        FROM Company c
        JOIN Location l ON c.LocationID = l.LocationID
        WHERE c.CompanyID = $cfCompanyID
    ";
    $companyInfoRes = $conn->query($companyInfoSql);
    if ($companyInfoRes && $row = $companyInfoRes->fetch_assoc()) {
        $selectedCompanyName = $row['CompanyName'];
        $selectedCompanyRegion = $row['ContinentName'] . ' - ' . $row['CountryName'];
        $companyInfoRes->free();
    }

    // Get financial data
    $cfDataSql = "
        SELECT 
            CONCAT(RepYear, '-', Quarter) AS Period,
            HealthScore
        FROM FinancialReport
        WHERE CompanyID = $cfCompanyID
        ORDER BY RepYear ASC, 
                 FIELD(Quarter, 'Q1', 'Q2', 'Q3', 'Q4')
    ";
    $cfDataRes = $conn->query($cfDataSql);
    if ($cfDataRes) {
        while ($row = $cfDataRes->fetch_assoc()) {
            $cfQuarters[] = $row['Period'];
            $cfHealthScores[] = (float)$row['HealthScore'];
        }
        $cfDataRes->free();
    }
}

// -------------------------------------------------
// MODULE 4: Top Distributors by Shipment Volume
// -------------------------------------------------

// Distributor selector
$tdDistributorID = isset($_GET['td_distributor']) ? (int)$_GET['td_distributor'] : 0;

// Date range filters for distributor volume
$tdStart = isset($_GET['td_start']) ? trim($_GET['td_start']) : '';
$tdEnd   = isset($_GET['td_end'])   ? trim($_GET['td_end'])   : '';

if ($tdStart === '' && $tdEnd === '') {
    $tdEnd   = date('Y-m-d');
    $tdStart = date('Y-m-d', strtotime('-365 days'));
}

// Get list of all distributors
$distributorListOptions = array();
$distributorListSql = "
    SELECT DISTINCT
        c.CompanyID,
        c.CompanyName,
        l.ContinentName
    FROM Company c
    JOIN Location l ON c.LocationID = l.LocationID
    WHERE c.Type = 'Distributor'
    ORDER BY c.CompanyName
";
$distributorListRes = $conn->query($distributorListSql);
if ($distributorListRes) {
    while ($row = $distributorListRes->fetch_assoc()) {
        $distributorListOptions[] = $row;
    }
    $distributorListRes->free();
}

// Top distributors overall
$topDistributors = array();
$topDistributorVolumes = array();

$topDistSql = "
    SELECT 
        c.CompanyName,
        SUM(s.Quantity) AS TotalVolume
    FROM Shipping s
    JOIN Company c ON s.DistributorID = c.CompanyID
    GROUP BY c.CompanyID, c.CompanyName
    ORDER BY TotalVolume DESC
    LIMIT 15
";
$topDistRes = $conn->query($topDistSql);
if ($topDistRes) {
    while ($row = $topDistRes->fetch_assoc()) {
        $topDistributors[] = $row['CompanyName'];
        $topDistributorVolumes[] = (int)$row['TotalVolume'];
    }
    $topDistRes->free();
}

// Selected distributor data
$selectedDistributorName = '';
$tdMonths = array();
$tdVolumes = array();

if ($tdDistributorID > 0) {
    // Get distributor name
    $distNameSql = "SELECT CompanyName FROM Company WHERE CompanyID = $tdDistributorID";
    $distNameRes = $conn->query($distNameSql);
    if ($distNameRes && $row = $distNameRes->fetch_assoc()) {
        $selectedDistributorName = $row['CompanyName'];
        $distNameRes->free();
    }

    // Get monthly shipment volumes with date filtering
    $tdStartEsc = $conn->real_escape_string($tdStart);
    $tdEndEsc   = $conn->real_escape_string($tdEnd);
    
    $tdVolumeSql = "
        SELECT 
            DATE_FORMAT(s.PromisedDate, '%Y-%m') AS Month,
            SUM(s.Quantity) AS Volume
        FROM Shipping s
        WHERE s.DistributorID = $tdDistributorID
          AND s.PromisedDate >= '$tdStartEsc'
          AND s.PromisedDate <= '$tdEndEsc'
        GROUP BY DATE_FORMAT(s.PromisedDate, '%Y-%m')
        ORDER BY Month ASC
    ";
    $tdVolumeRes = $conn->query($tdVolumeSql);
    if ($tdVolumeRes) {
        while ($row = $tdVolumeRes->fetch_assoc()) {
            $tdMonths[] = $row['Month'];
            $tdVolumes[] = (int)$row['Volume'];
        }
        $tdVolumeRes->free();
    }
}

// -------------------------------------------------
// MODULE 5: Companies Affected by Disruption Event
// -------------------------------------------------

// Event selector
$deEventID = isset($_GET['de_event']) ? (int)$_GET['de_event'] : 0;

// Get list of disruption events (filtered by date range)
$eventListOptions = array();
$eventListSql = "
    SELECT 
        de.EventID,
        de.EventDate,
        dc.CategoryName
    FROM DisruptionEvent de
    JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
    WHERE de.EventDate >= '$rdStartEsc'
      AND de.EventDate <= '$rdEndEsc'
    ORDER BY de.EventDate DESC 
    LIMIT 100
";

$eventListRes = $conn->query($eventListSql);
if ($eventListRes) {
    while ($row = $eventListRes->fetch_assoc()) {
        $eventListOptions[] = $row;
    }
    $eventListRes->free();
}

// Selected event data
$selectedEventDate = '';
$selectedEventCategory = '';
$selectedEventRecovery = '';
$affectedCompanies = array();

if ($deEventID > 0) {
    // Get event info
    $eventInfoSql = "
        SELECT 
            de.EventDate,
            de.EventRecoveryDate,
            dc.CategoryName
        FROM DisruptionEvent de
        JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
        WHERE de.EventID = $deEventID
    ";
    $eventInfoRes = $conn->query($eventInfoSql);
    if ($eventInfoRes && $row = $eventInfoRes->fetch_assoc()) {
        $selectedEventDate = $row['EventDate'];
        $selectedEventCategory = $row['CategoryName'];
        $selectedEventRecovery = $row['EventRecoveryDate'];
        $eventInfoRes->free();
    }

    // Get affected companies
    $affectedSql = "
        SELECT 
            c.CompanyName,
            c.Type,
            l.CountryName,
            l.ContinentName,
            ic.ImpactLevel
        FROM ImpactsCompany ic
        JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
        JOIN Location l ON c.LocationID = l.LocationID
        WHERE ic.EventID = $deEventID
        ORDER BY 
            FIELD(ic.ImpactLevel, 'High', 'Medium', 'Low'),
            c.CompanyName
    ";
    $affectedRes = $conn->query($affectedSql);
    if ($affectedRes) {
        while ($row = $affectedRes->fetch_assoc()) {
            $affectedCompanies[] = $row;
        }
        $affectedRes->free();
    }
}

// -------------------------------------------------
// MODULE 6: All Disruptions for a Specific Company
// -------------------------------------------------

// Company selector for disruptions
$dcCompanyID = isset($_GET['dc_company']) ? (int)$_GET['dc_company'] : 0;

// Selected company disruptions
$selectedDisruptionCompanyName = '';
$companyDisruptions = array();

if ($dcCompanyID > 0) {
    // Get company name
    $compNameSql = "SELECT CompanyName FROM Company WHERE CompanyID = $dcCompanyID";
    $compNameRes = $conn->query($compNameSql);
    if ($compNameRes && $row = $compNameRes->fetch_assoc()) {
        $selectedDisruptionCompanyName = $row['CompanyName'];
        $compNameRes->free();
    }

    // Get all disruptions affecting this company (filtered by date range)
    $rdStartEsc = $conn->real_escape_string($rdStart);
    $rdEndEsc   = $conn->real_escape_string($rdEnd);
    
    $disruptionsSql = "
        SELECT 
            de.EventID,
            de.EventDate,
            de.EventRecoveryDate,
            dc.CategoryName,
            ic.ImpactLevel,
            DATEDIFF(de.EventRecoveryDate, de.EventDate) AS RecoveryDays
        FROM ImpactsCompany ic
        JOIN DisruptionEvent de ON ic.EventID = de.EventID
        JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
        WHERE ic.AffectedCompanyID = $dcCompanyID
          AND de.EventDate >= '$rdStartEsc'
          AND de.EventDate <= '$rdEndEsc'
        ORDER BY de.EventDate DESC
    ";
    $disruptionsRes = $conn->query($disruptionsSql);
    if ($disruptionsRes) {
        while ($row = $disruptionsRes->fetch_assoc()) {
            $companyDisruptions[] = $row;
        }
        $disruptionsRes->free();
    }
}

// -------------------------------------------------
// MODULE 7: Distributors Sorted by Average Delay
// -------------------------------------------------

$distributorsByDelay = array();
$avgDelays = array();

$delayDistSql = "
    SELECT 
        c.CompanyName,
        AVG(DATEDIFF(s.ActualDate, s.PromisedDate)) AS AvgDelay,
        COUNT(s.ShipmentID) AS ShipmentCount
    FROM Company c
    JOIN Shipping s ON c.CompanyID = s.DistributorID
    WHERE s.ActualDate IS NOT NULL
      AND c.Type = 'Distributor'
    GROUP BY c.CompanyID, c.CompanyName
    HAVING COUNT(s.ShipmentID) >= 5
    ORDER BY AvgDelay DESC
    LIMIT 20
";
$delayDistRes = $conn->query($delayDistSql);
if ($delayDistRes) {
    while ($row = $delayDistRes->fetch_assoc()) {
        $distributorsByDelay[] = $row['CompanyName'];
        $avgDelays[] = round((float)$row['AvgDelay'], 2);
    }
    $delayDistRes->free();
}

// -------------------------------------------------
// MODULE 8: Add New Company
// -------------------------------------------------

$addCompanyMessage = '';
$addCompanySuccess = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_company'])) {
    $newCompanyName = isset($_POST['new_company_name']) ? trim($_POST['new_company_name']) : '';
    $newCompanyType = isset($_POST['new_company_type']) ? trim($_POST['new_company_type']) : '';
    $newCompanyTier = isset($_POST['new_company_tier']) ? (int)$_POST['new_company_tier'] : 0;
    $newCity = isset($_POST['new_city']) ? trim($_POST['new_city']) : '';
    $newCountry = isset($_POST['new_country']) ? trim($_POST['new_country']) : '';
    $newContinent = isset($_POST['new_continent']) ? trim($_POST['new_continent']) : '';

    if ($newCompanyName && $newCompanyType && $newCompanyTier > 0 && $newCity && $newCountry && $newContinent) {
        // First, check if location exists or create it
        $escapedCity = $conn->real_escape_string($newCity);
        $escapedCountry = $conn->real_escape_string($newCountry);
        $escapedContinent = $conn->real_escape_string($newContinent);
        
        $locationCheckSql = "
            SELECT LocationID 
            FROM Location 
            WHERE City = '$escapedCity' 
              AND CountryName = '$escapedCountry' 
              AND ContinentName = '$escapedContinent'
        ";
        
        $locationCheckRes = $conn->query($locationCheckSql);
        $locationID = 0;
        
        if ($locationCheckRes && $locationCheckRes->num_rows > 0) {
            $row = $locationCheckRes->fetch_assoc();
            $locationID = (int)$row['LocationID'];
            $locationCheckRes->free();
        } else {
            // Insert new location
            $insertLocationSql = "
                INSERT INTO Location (City, CountryName, ContinentName)
                VALUES ('$escapedCity', '$escapedCountry', '$escapedContinent')
            ";
            
            if ($conn->query($insertLocationSql)) {
                $locationID = $conn->insert_id;
            } else {
                $addCompanyMessage = "Error adding location: " . $conn->error;
            }
        }
        
        // Now insert the company if we have a valid location
        if ($locationID > 0) {
            $escapedName = $conn->real_escape_string($newCompanyName);
            $escapedType = $conn->real_escape_string($newCompanyType);

            $insertSql = "
                INSERT INTO Company (CompanyName, Type, TierLevel, LocationID)
                VALUES ('$escapedName', '$escapedType', $newCompanyTier, $locationID)
            ";

            if ($conn->query($insertSql)) {
                $addCompanySuccess = true;
                $addCompanyMessage = "Company '$newCompanyName' added successfully with Tier $newCompanyTier!";
                $activeTab = 'management'; // Stay on management tab
            } else {
                $addCompanyMessage = "Error adding company: " . $conn->error;
            }
        }
    } else {
        $addCompanyMessage = "Please fill in all required fields. Tier must be 1, 2, or 3.";
    }
}

// -------------------------------------------------
// MODULE 9: Risk vs Financial Health Scatter Plot
// -------------------------------------------------

$scatterData = array();

$scatterSql = "
    SELECT 
        c.CompanyID,
        c.CompanyName,
        c.Type,
        c.TierLevel,
        AVG(fr.HealthScore) AS AvgHealthScore,
        COUNT(DISTINCT ic.EventID) AS DisruptionCount,
        COALESCE(SUM(DATEDIFF(de.EventRecoveryDate, de.EventDate)), 0) AS TotalDowntime
    FROM Company c
    LEFT JOIN FinancialReport fr 
        ON c.CompanyID = fr.CompanyID
    LEFT JOIN ImpactsCompany ic 
        ON c.CompanyID = ic.AffectedCompanyID
    LEFT JOIN DisruptionEvent de 
        ON ic.EventID = de.EventID
        AND de.EventRecoveryDate IS NOT NULL
    GROUP BY c.CompanyID, c.CompanyName, c.Type, c.TierLevel
    HAVING AVG(fr.HealthScore) IS NOT NULL
    ORDER BY c.CompanyName
";

$scatterRes = $conn->query($scatterSql);
if ($scatterRes) {
    while ($row = $scatterRes->fetch_assoc()) {
        $scatterData[] = array(
            'companyName' => $row['CompanyName'],
            'type' => $row['Type'],
            'tier' => $row['TierLevel'],
            'healthScore' => round((float)$row['AvgHealthScore'], 2),
            'disruptionCount' => (int)$row['DisruptionCount'],
            'totalDowntime' => (int)$row['TotalDowntime']
        );
    }
    $scatterRes->free();
}

// Encode data for JavaScript
$fhByCompanyLabelsJson = json_encode($fhByCompanyLabels);
$fhByCompanyValuesJson = json_encode($fhByCompanyValues);
$fhByTypeLabelsJson = json_encode($fhByTypeLabels);
$fhByTypeValuesJson = json_encode($fhByTypeValues);
$rdRegionsJson = json_encode($rdRegions);
$rdTotalDisruptionsJson = json_encode($rdTotalDisruptions);
$rdLowImpactDisruptionsJson = json_encode($rdLowImpactDisruptions);
$rdMediumImpactDisruptionsJson = json_encode($rdMediumImpactDisruptions);
$rdHighImpactDisruptionsJson = json_encode($rdHighImpactDisruptions);
$criticalCompaniesJson = json_encode($criticalCompanies);
$criticalityScoresJson = json_encode($criticalityScores);
$disruptionDatesJson = json_encode($disruptionDates);
$disruptionCountsJson = json_encode($disruptionCounts);
$vulnerableCompaniesJson = json_encode($vulnerableCompanies);
$vulnerabilityScoresJson = json_encode($vulnerabilityScores);
$cfQuartersJson = json_encode($cfQuarters);
$cfHealthScoresJson = json_encode($cfHealthScores);
$topDistributorsJson = json_encode($topDistributors);
$topDistributorVolumesJson = json_encode($topDistributorVolumes);
$tdMonthsJson = json_encode($tdMonths);
$tdVolumesJson = json_encode($tdVolumes);
$distributorsByDelayJson = json_encode($distributorsByDelay);
$avgDelaysJson = json_encode($avgDelays);
$scatterDataJson = json_encode($scatterData);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Senior Manager Dashboard - Analytics</title>
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

        .df-field input[type="date"],
        .df-field input[type="text"],
        .df-field input[type="number"],
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

        .df-field input[type="date"]:focus,
        .df-field input[type="text"]:focus,
        .df-field input[type="number"]:focus,
        .df-field select:focus {
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

        .df-form button:hover { 
            background-color: var(--accent-hover); 
        }

        .df-form button:active { 
            transform: scale(0.97); 
        }

        .df-chart-wrapper {
            background-color: #2d2d2d;
            border-radius: 12px;
            border: 1px solid #1a1a1a;
            padding: 12px;
            margin-bottom: 16px;
        }

        .no-data {
            margin-top: 10px;
            font-size: 0.9rem;
            color: #ddd;
        }

        .nav-links {
            text-align: center;
            margin-bottom: 20px;
        }

        .nav-links a {
            display: inline-block;
            padding: 0.5rem 1rem;
            margin: 0 0.5rem;
            background-color: var(--accent);
            color: #141414;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: background-color 0.15s ease;
        }

        .nav-links a:hover {
            background-color: var(--accent-hover);
        }

        /* Tab Navigation Styles */
        .tab-navigation {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .tab-button {
            padding: 0.75rem 1.5rem;
            border: none;
            background-color: #2d2d2d;
            color: var(--muted);
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 2px solid transparent;
        }

        .tab-button:hover {
            background-color: #3a3a3a;
            color: var(--text);
        }

        .tab-button.active {
            background-color: var(--accent);
            color: #141414;
            border-color: var(--accent-hover);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Table styles */
        .table-container {
            overflow-x: auto;
            margin-top: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background-color: #2d2d2d;
            border-radius: 8px;
            overflow: hidden;
        }

        thead {
            background-color: #1a1a1a;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #3a3a3a;
        }

        th {
            font-weight: 600;
            color: var(--accent);
            font-size: 0.9rem;
        }

        td {
            color: var(--text);
            font-size: 0.85rem;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tbody tr:hover {
            background-color: #3a3a3a;
        }

        .pill {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            border: 1px solid;
        }
    </style>
</head>
<body>

<div class="top-header">
    Supply Chain Analytics Module
</div>

<div class="page">

    <!-- Navigation Links -->
    <div class="nav-links">
        <a href="dashboard.php">← Back to Main Dashboard</a>
    </div>

    <!-- Tab Navigation -->
    <div class="tab-navigation">
        <button class="tab-button <?php echo ($activeTab === 'financial') ? 'active' : ''; ?>" onclick="switchTab('financial')">Financial Analytics</button>
        <button class="tab-button <?php echo ($activeTab === 'disruptions') ? 'active' : ''; ?>" onclick="switchTab('disruptions')">Disruption Analytics</button>
        <button class="tab-button <?php echo ($activeTab === 'logistics') ? 'active' : ''; ?>" onclick="switchTab('logistics')">Logistics Performance</button>
        <button class="tab-button <?php echo ($activeTab === 'management') ? 'active' : ''; ?>" onclick="switchTab('management')">Data Management</button>
    </div>

    <!-- TAB 1: Financial Analytics -->
    <div id="tab-financial" class="tab-content <?php echo ($activeTab === 'financial') ? 'active' : ''; ?>">

    <!-- MODULE 1: Financial Health Analysis -->
    <div class="card">
        <div class="module-header">
            <h2 class="module-header-title">Financial Health Analysis</h2>
            <p class="subtitle">
                Analyze average financial health scores across companies and company types.
            </p>
        </div>

        <!-- Date range filter -->
        <form method="get" class="df-form">
            <input type="hidden" name="active_tab" value="financial">
            
            <div class="df-field">
                <label for="fh_start">Start date</label>
                <input type="date" id="fh_start" name="fh_start" value="<?php echo htmlspecialchars($fhStart); ?>">
            </div>
            <div class="df-field">
                <label for="fh_end">End date</label>
                <input type="date" id="fh_end" name="fh_end" value="<?php echo htmlspecialchars($fhEnd); ?>">
            </div>
            <button type="submit">Update Financial Health View</button>
        </form>

        <!-- Financial Health by Company -->
        <div class="chart-header">
            <h3 class="chart-title">All Companies by Average Financial Health</h3>
            <p class="chart-subtitle">Companies sorted from highest to lowest health scores. Scroll horizontally to see more.</p>
        </div>
        <div class="df-chart-wrapper">
            <?php if (!empty($fhByCompanyLabels)): ?>
                <div style="overflow-x: auto; overflow-y: hidden; padding-bottom: 10px;">
                    <div style="min-width: <?php echo max(1000, count($fhByCompanyLabels) * 50); ?>px; height: 450px;">
                        <canvas id="fhCompanyChart"></canvas>
                    </div>
                </div>
            <?php else: ?>
                <div class="no-data">
                    No financial health data found for the selected period. Try expanding the date range.
                </div>
            <?php endif; ?>
        </div>

        <!-- Financial Health by Company Type -->
        <div class="chart-header">
            <h3 class="chart-title">Average Financial Health by Company Type</h3>
            <p class="chart-subtitle">Comparison of health scores across different company types.</p>
        </div>
        <div class="df-chart-wrapper">
            <?php if (!empty($fhByTypeLabels)): ?>
                <canvas id="fhTypeChart" height="400"></canvas>
            <?php else: ?>
                <div class="no-data">
                    No financial health data found for the selected period.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- MODULE 3: Company Financials by Region -->
    <div class="card">
        <div class="module-header">
            <h2 class="module-header-title">Company Financials by Region</h2>
            <p class="subtitle">
                Search for any company and view their financial health trends over time.
            </p>
        </div>

        <!-- Company Selector -->
        <form method="get" class="df-form">
            <input type="hidden" name="active_tab" value="financial">
            <input type="hidden" name="fh_start" value="<?php echo htmlspecialchars($fhStart); ?>">
            <input type="hidden" name="fh_end" value="<?php echo htmlspecialchars($fhEnd); ?>">
            <input type="hidden" name="rd_start" value="<?php echo htmlspecialchars($rdStart); ?>">
            <input type="hidden" name="rd_end" value="<?php echo htmlspecialchars($rdEnd); ?>">
            
            <div class="df-field" style="min-width: 300px;">
                <label for="cf_company">Select Company</label>
                <select id="cf_company" name="cf_company">
                    <option value="0">-- Choose a company --</option>
                    <?php foreach ($companyListOptions as $co): ?>
                        <option value="<?php echo (int)$co['CompanyID']; ?>"
                            <?php if ($cfCompanyID === (int)$co['CompanyID']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($co['CompanyName'] . ' (' . $co['ContinentName'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit">View Company Financials</button>
        </form>

        <?php if ($cfCompanyID > 0): ?>
            <!-- Company Info Display -->
            <div style="margin-bottom: 16px; padding: 12px; background-color: #2d2d2d; border-radius: 8px;">
                <p style="margin: 4px 0; color: var(--text); font-size: 1rem;">
                    <strong>Company:</strong> <?php echo htmlspecialchars($selectedCompanyName); ?>
                </p>
                <p style="margin: 4px 0; color: var(--muted); font-size: 0.9rem;">
                    <strong>Region:</strong> <?php echo htmlspecialchars($selectedCompanyRegion); ?>
                </p>
            </div>

            <!-- Financial Health Chart -->
            <div class="chart-header">
                <h3 class="chart-title">Financial Health Score Over Time</h3>
                <p class="chart-subtitle">Quarterly health scores from financial reports.</p>
            </div>
            <div class="df-chart-wrapper">
                <?php if (!empty($cfQuarters)): ?>
                    <canvas id="companyFinChart" height="400"></canvas>
                <?php else: ?>
                    <div class="no-data">
                        No financial data available for this company.
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="no-data">
                Please select a company from the dropdown above to view their financial data.
            </div>
        <?php endif; ?>
    </div>

    <!-- MODULE 9: Risk vs Financial Health Scatter Plot -->
    <div class="card">
        <div class="module-header">
            <h2 class="module-header-title">Risk vs Financial Health Analysis</h2>
            <p class="subtitle">
                Identify companies that appear healthy but are vulnerable to disruptions.
            </p>
        </div>

        <div class="chart-header">
            <h3 class="chart-title">Financial Health vs Disruption Risk</h3>
            <p class="chart-subtitle">
                Each point represents a company. X-axis = Average Financial Health (0-100), 
                Y-axis = Total Disruption Frequency. Point size = Total downtime days.
            </p>
        </div>

        <!-- Quadrant Legend -->
        <div style="display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 16px; padding: 12px; background-color: #2d2d2d; border-radius: 8px;">
            <div style="flex: 1; min-width: 200px;">
                <p style="margin: 4px 0; color: var(--muted); font-size: 0.85rem;">
                    <strong style="color: #10b981;">Lower Right (Ideal):</strong> High health, low risk
                </p>
            </div>
            <div style="flex: 1; min-width: 200px;">
                <p style="margin: 4px 0; color: var(--muted); font-size: 0.85rem;">
                    <strong style="color: #f59e0b;">Upper Right (Fragile):</strong> High health, high risk - vulnerable despite good financials
                </p>
            </div>
            <div style="flex: 1; min-width: 200px;">
                <p style="margin: 4px 0; color: var(--muted); font-size: 0.85rem;">
                    <strong style="color: #6b7280;">Lower Left (Stable):</strong> Low health, low risk - struggling but stable
                </p>
            </div>
            <div style="flex: 1; min-width: 200px;">
                <p style="margin: 4px 0; color: var(--muted); font-size: 0.85rem;">
                    <strong style="color: #ef4444;">Upper Left (Critical):</strong> Low health, high risk - immediate concern
                </p>
            </div>
        </div>

        <div class="df-chart-wrapper">
            <?php if (!empty($scatterData)): ?>
                <canvas id="riskHealthScatter" height="550"></canvas>
            <?php else: ?>
                <div class="no-data">
                    No data available for risk vs health analysis.
                </div>
            <?php endif; ?>
        </div>
    </div>

    </div>
    <!-- END TAB: Financial Analytics -->

    <!-- TAB 2: Disruption Analytics -->
    <div id="tab-disruptions" class="tab-content <?php echo ($activeTab === 'disruptions') ? 'active' : ''; ?>">

    <!-- MODULE 2: Regional Disruption Overview -->
    <div class="card">
        <div class="module-header">
            <h2 class="module-header-title">Regional Disruption Overview</h2>
            <p class="subtitle">
                Analyze total disruptions and impact levels by continent.
            </p>
        </div>

        <!-- Date range filter -->
        <form method="get" class="df-form">
            <input type="hidden" name="active_tab" value="disruptions">
            <input type="hidden" name="fh_start" value="<?php echo htmlspecialchars($fhStart); ?>">
            <input type="hidden" name="fh_end" value="<?php echo htmlspecialchars($fhEnd); ?>">
            
            <div class="df-field">
                <label for="rd_start">Start date</label>
                <input type="date" id="rd_start" name="rd_start" value="<?php echo htmlspecialchars($rdStart); ?>">
            </div>
            <div class="df-field">
                <label for="rd_end">End date</label>
                <input type="date" id="rd_end" name="rd_end" value="<?php echo htmlspecialchars($rdEnd); ?>">
            </div>
            <button type="submit">Update Regional View</button>
        </form>

        <!-- Regional Disruption Chart -->
        <div class="chart-header">
            <h3 class="chart-title">Total and High-Impact Disruptions by Continent</h3>
            <p class="chart-subtitle">Stacked bar chart showing disruption distribution across continents.</p>
        </div>
        <div class="df-chart-wrapper">
            <?php if (!empty($rdRegions)): ?>
                <canvas id="regionalChart" height="500"></canvas>
            <?php else: ?>
                <div class="no-data">
                    No disruption data found for the selected period. Try expanding the date range.
                </div>
            <?php endif; ?>
        </div>

        <!-- Most Critical Companies Chart -->
        <div class="chart-header">
            <h3 class="chart-title">Most Critical Companies</h3>
            <p class="chart-subtitle">Criticality Score = Downstream Dependencies × High-Impact Disruptions</p>
        </div>
        <div class="df-chart-wrapper">
            <?php if (!empty($criticalCompanies)): ?>
                <canvas id="criticalityChart" height="600"></canvas>
            <?php else: ?>
                <div class="no-data">
                    No critical companies found in the selected period.
                </div>
            <?php endif; ?>
        </div>

        <!-- Disruption Frequency Over Time Chart -->
        <div class="chart-header">
            <h3 class="chart-title">Disruption Frequency Over Time</h3>
            <p class="chart-subtitle">Number of disruptions per month in the selected period.</p>
        </div>
        <div class="df-chart-wrapper">
            <?php if (!empty($disruptionDates)): ?>
                <canvas id="freqTimeChart" height="400"></canvas>
            <?php else: ?>
                <div class="no-data">
                    No disruption data found for the selected period.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Supply Chain Vulnerability Score -->
    <div class="card">
        <div class="module-header">
            <h2 class="module-header-title">Supply Chain Vulnerability Score</h2>
            <p class="subtitle">
                Identifies critical bottleneck companies by combining downstream dependencies, disruption frequency, recovery time, and financial health.
            </p>
        </div>

        <div style="margin-bottom: 16px; padding: 12px; background-color: #2d2d2d; border-radius: 8px;">
            <p style="margin: 4px 0; color: var(--muted); font-size: 0.85rem;">
                <strong>Vulnerability Score Formula:</strong> (Downstream Companies × Disruptions × Recovery Risk) / Financial Health
            </p>
            <p style="margin: 4px 0; color: var(--muted); font-size: 0.85rem;">
                Higher scores = Greater vulnerability. These companies are critical dependencies that frequently experience disruptions and/or have weak recovery capabilities.
            </p>
        </div>

        <div class="chart-header">
            <h3 class="chart-title">Top 20 Most Vulnerable Companies</h3>
            <p class="chart-subtitle">Companies ranked by supply chain vulnerability in the selected period.</p>
        </div>
        <div class="df-chart-wrapper">
            <?php if (!empty($vulnerableCompanies)): ?>
                <canvas id="vulnerabilityChart" height="600"></canvas>
            <?php else: ?>
                <div class="no-data">
                    No vulnerability data available for the selected period. Companies need downstream dependencies, disruptions, and financial data.
                </div>
            <?php endif; ?>
        </div>

        <!-- Risk Level Legend -->
        <div style="display: flex; flex-wrap: wrap; gap: 20px; margin-top: 16px; padding: 12px; background-color: #2d2d2d; border-radius: 8px;">
            <div style="flex: 1; min-width: 200px;">
                <p style="margin: 4px 0; color: var(--muted); font-size: 0.85rem;">
                    <strong style="color: #ef4444;">🔴 Critical Risk:</strong> Immediate attention required - high dependency with poor resilience
                </p>
            </div>
            <div style="flex: 1; min-width: 200px;">
                <p style="margin: 4px 0; color: var(--muted); font-size: 0.85rem;">
                    <strong style="color: #f59e0b;">🟡 Elevated Risk:</strong> Monitor closely - significant impact potential
                </p>
            </div>
            <div style="flex: 1; min-width: 200px;">
                <p style="margin: 4px 0; color: var(--muted); font-size: 0.85rem;">
                    <strong style="color: #10b981;">🟢 Moderate Risk:</strong> Lower priority but worth tracking
                </p>
            </div>
        </div>
    </div>

    <!-- MODULE 5: Companies Affected by Disruption Event -->
    <div class="card">
        <div class="module-header">
            <h2 class="module-header-title">Companies Affected by Disruption Event</h2>
            <p class="subtitle">
                Select a disruption event to see all affected companies and impact levels. Events are filtered by the date range selected above.
            </p>
        </div>

        <!-- Event Selector -->
        <form method="get" class="df-form">
            <input type="hidden" name="active_tab" value="disruptions">
            <input type="hidden" name="fh_start" value="<?php echo htmlspecialchars($fhStart); ?>">
            <input type="hidden" name="fh_end" value="<?php echo htmlspecialchars($fhEnd); ?>">
            <input type="hidden" name="rd_start" value="<?php echo htmlspecialchars($rdStart); ?>">
            <input type="hidden" name="rd_end" value="<?php echo htmlspecialchars($rdEnd); ?>">
            <input type="hidden" name="cf_company" value="<?php echo (int)$cfCompanyID; ?>">
            <input type="hidden" name="td_distributor" value="<?php echo (int)$tdDistributorID; ?>">
            <input type="hidden" name="td_start" value="<?php echo htmlspecialchars($tdStart); ?>">
            <input type="hidden" name="td_end" value="<?php echo htmlspecialchars($tdEnd); ?>">
            
            <div class="df-field" style="min-width: 300px;">
                <label for="de_event">Select Disruption Event</label>
                <select id="de_event" name="de_event">
                    <option value="0">-- Choose an event --</option>
                    <?php foreach ($eventListOptions as $evt): ?>
                        <option value="<?php echo (int)$evt['EventID']; ?>"
                            <?php if ($deEventID === (int)$evt['EventID']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars('Event #' . $evt['EventID'] . ' - ' . $evt['EventDate'] . ' (' . $evt['CategoryName'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit">View Affected Companies</button>
        </form>

        <?php if ($deEventID > 0): ?>
            <!-- Event Info Display -->
            <div style="margin: 16px 0; padding: 12px; background-color: #2d2d2d; border-radius: 8px;">
                <p style="margin: 4px 0; color: var(--text); font-size: 1rem;">
                    <strong>Event Date:</strong> <?php echo htmlspecialchars($selectedEventDate); ?>
                </p>
                <p style="margin: 4px 0; color: var(--muted); font-size: 0.9rem;">
                    <strong>Category:</strong> <?php echo htmlspecialchars($selectedEventCategory); ?>
                </p>
                <?php if (!empty($selectedEventRecovery)): ?>
                    <p style="margin: 4px 0; color: var(--muted); font-size: 0.9rem;">
                        <strong>Recovery Date:</strong> <?php echo htmlspecialchars($selectedEventRecovery); ?>
                    </p>
                <?php endif; ?>
            </div>

            <!-- Affected Companies Table -->
            <div class="chart-header">
                <h3 class="chart-title">Affected Companies</h3>
                <p class="chart-subtitle">
                    Total affected: <strong><?php echo count($affectedCompanies); ?></strong> companies
                </p>
            </div>

            <?php if (!empty($affectedCompanies)): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Company Name</th>
                                <th>Type</th>
                                <th>Location</th>
                                <th>Impact Level</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($affectedCompanies as $company): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($company['CompanyName']); ?></td>
                                    <td><span class="pill" style="background-color: #2d2d2d; border-color: var(--accent); color: var(--accent);">
                                        <?php echo htmlspecialchars($company['Type']); ?>
                                    </span></td>
                                    <td>
                                        <?php echo htmlspecialchars($company['CountryName'] . ', ' . $company['ContinentName']); ?>
                                    </td>
                                    <td>
                                        <span class="pill" style="
                                            <?php 
                                            if ($company['ImpactLevel'] === 'High') {
                                                echo 'background-color: #fee2e2; border-color: #ef4444; color: #991b1b;';
                                            } elseif ($company['ImpactLevel'] === 'Medium') {
                                                echo 'background-color: #fef3c7; border-color: #f59e0b; color: #92400e;';
                                            } else {
                                                echo 'background-color: #d1fae5; border-color: #10b981; color: #065f46;';
                                            }
                                            ?>
                                        ">
                                            <?php echo htmlspecialchars($company['ImpactLevel']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="no-data">
                    No companies affected by this event.
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="no-data">
                Please select a disruption event from the dropdown to view affected companies.
            </div>
        <?php endif; ?>
    </div>

    <!-- MODULE 6: All Disruptions for a Specific Company -->
    <div class="card">
        <div class="module-header">
            <h2 class="module-header-title">All Disruptions for a Specific Company</h2>
            <p class="subtitle">
                Select a company to view disruption events within the selected date range above.
            </p>
        </div>

        <!-- Company Selector -->
        <form method="get" class="df-form">
            <input type="hidden" name="active_tab" value="disruptions">
            <input type="hidden" name="fh_start" value="<?php echo htmlspecialchars($fhStart); ?>">
            <input type="hidden" name="fh_end" value="<?php echo htmlspecialchars($fhEnd); ?>">
            <input type="hidden" name="rd_start" value="<?php echo htmlspecialchars($rdStart); ?>">
            <input type="hidden" name="rd_end" value="<?php echo htmlspecialchars($rdEnd); ?>">
            <input type="hidden" name="cf_company" value="<?php echo (int)$cfCompanyID; ?>">
            <input type="hidden" name="td_distributor" value="<?php echo (int)$tdDistributorID; ?>">
            <input type="hidden" name="td_start" value="<?php echo htmlspecialchars($tdStart); ?>">
            <input type="hidden" name="td_end" value="<?php echo htmlspecialchars($tdEnd); ?>">
            <input type="hidden" name="de_event" value="<?php echo (int)$deEventID; ?>">
            
            <div class="df-field" style="min-width: 300px;">
                <label for="dc_company">Select Company</label>
                <select id="dc_company" name="dc_company">
                    <option value="0">-- Choose a company --</option>
                    <?php foreach ($companyListOptions as $co): ?>
                        <option value="<?php echo (int)$co['CompanyID']; ?>"
                            <?php if ($dcCompanyID === (int)$co['CompanyID']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($co['CompanyName'] . ' (' . $co['ContinentName'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit">View Company Disruptions</button>
        </form>

        <?php if ($dcCompanyID > 0): ?>
            <!-- Company Info Display -->
            <div style="margin: 16px 0; padding: 12px; background-color: #2d2d2d; border-radius: 8px;">
                <p style="margin: 4px 0; color: var(--text); font-size: 1rem;">
                    <strong>Company:</strong> <?php echo htmlspecialchars($selectedDisruptionCompanyName); ?>
                </p>
                <p style="margin: 4px 0; color: var(--muted); font-size: 0.9rem;">
                    <strong>Total Disruptions:</strong> <?php echo count($companyDisruptions); ?>
                </p>
            </div>

            <!-- Disruptions Table -->
            <?php if (!empty($companyDisruptions)): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Event ID</th>
                                <th>Event Date</th>
                                <th>Category</th>
                                <th>Impact Level</th>
                                <th>Recovery Date</th>
                                <th>Recovery Days</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($companyDisruptions as $disruption): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($disruption['EventID']); ?></td>
                                    <td><?php echo htmlspecialchars($disruption['EventDate']); ?></td>
                                    <td><?php echo htmlspecialchars($disruption['CategoryName']); ?></td>
                                    <td>
                                        <span class="pill" style="
                                            <?php 
                                            if ($disruption['ImpactLevel'] === 'High') {
                                                echo 'background-color: #fee2e2; border-color: #ef4444; color: #991b1b;';
                                            } elseif ($disruption['ImpactLevel'] === 'Medium') {
                                                echo 'background-color: #fef3c7; border-color: #f59e0b; color: #92400e;';
                                            } else {
                                                echo 'background-color: #d1fae5; border-color: #10b981; color: #065f46;';
                                            }
                                            ?>
                                        ">
                                            <?php echo htmlspecialchars($disruption['ImpactLevel']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo $disruption['EventRecoveryDate'] ? htmlspecialchars($disruption['EventRecoveryDate']) : 'N/A'; ?>
                                    </td>
                                    <td>
                                        <?php echo $disruption['RecoveryDays'] !== null ? htmlspecialchars($disruption['RecoveryDays']) : 'N/A'; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="no-data">
                    No disruptions recorded for this company in the selected date range.
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="no-data">
                Please select a company to view their disruption history.
            </div>
        <?php endif; ?>
    </div>

    </div>
    <!-- END TAB: Disruption Analytics -->

    <!-- TAB 3: Logistics Performance -->
    <div id="tab-logistics" class="tab-content <?php echo ($activeTab === 'logistics') ? 'active' : ''; ?>">

    <!-- MODULE 4: Top Distributors by Shipment Volume -->
    <div class="card">
        <div class="module-header">
            <h2 class="module-header-title">Top Distributors by Shipment Volume</h2>
            <p class="subtitle">
                View top distributors and detailed shipment data for any specific distributor.
            </p>
        </div>

        <!-- Top Distributors Chart -->
        <div class="chart-header">
            <h3 class="chart-title">Top 15 Distributors by Total Volume</h3>
            <p class="chart-subtitle">Total shipment quantity across all time.</p>
        </div>
        <div class="df-chart-wrapper">
            <?php if (!empty($topDistributors)): ?>
                <canvas id="topDistChart" height="500"></canvas>
            <?php else: ?>
                <div class="no-data">
                    No distributor data available.
                </div>
            <?php endif; ?>
        </div>

        <!-- Distributor Selector -->
        <form method="get" class="df-form" style="margin-top: 24px;">
            <input type="hidden" name="active_tab" value="logistics">
            <input type="hidden" name="fh_start" value="<?php echo htmlspecialchars($fhStart); ?>">
            <input type="hidden" name="fh_end" value="<?php echo htmlspecialchars($fhEnd); ?>">
            <input type="hidden" name="rd_start" value="<?php echo htmlspecialchars($rdStart); ?>">
            <input type="hidden" name="rd_end" value="<?php echo htmlspecialchars($rdEnd); ?>">
            <input type="hidden" name="cf_company" value="<?php echo (int)$cfCompanyID; ?>">
            <input type="hidden" name="de_event" value="<?php echo (int)$deEventID; ?>">
            <input type="hidden" name="dc_company" value="<?php echo (int)$dcCompanyID; ?>">
            
            <div class="df-field" style="min-width: 300px;">
                <label for="td_distributor">Select Distributor</label>
                <select id="td_distributor" name="td_distributor">
                    <option value="0">-- Choose a distributor --</option>
                    <?php foreach ($distributorListOptions as $dist): ?>
                        <option value="<?php echo (int)$dist['CompanyID']; ?>"
                            <?php if ($tdDistributorID === (int)$dist['CompanyID']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($dist['CompanyName'] . ' (' . $dist['ContinentName'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="df-field">
                <label for="td_start">Start date</label>
                <input type="date" id="td_start" name="td_start" value="<?php echo htmlspecialchars($tdStart); ?>">
            </div>
            <div class="df-field">
                <label for="td_end">End date</label>
                <input type="date" id="td_end" name="td_end" value="<?php echo htmlspecialchars($tdEnd); ?>">
            </div>
            
            <button type="submit">View Distributor Details</button>
        </form>

        <?php if ($tdDistributorID > 0): ?>
            <!-- Distributor Info Display -->
            <div style="margin: 16px 0; padding: 12px; background-color: #2d2d2d; border-radius: 8px;">
                <p style="margin: 4px 0; color: var(--text); font-size: 1rem;">
                    <strong>Distributor:</strong> <?php echo htmlspecialchars($selectedDistributorName); ?>
                </p>
            </div>

            <!-- Distributor Volume Over Time Chart -->
            <div class="chart-header">
                <h3 class="chart-title">Shipment Volume Over Time</h3>
                <p class="chart-subtitle">Monthly shipment volumes for selected distributor in the chosen date range.</p>
            </div>
            <div class="df-chart-wrapper">
                <?php if (!empty($tdMonths)): ?>
                    <canvas id="distVolumeChart" height="400"></canvas>
                <?php else: ?>
                    <div class="no-data">
                        No shipment data available for this distributor.
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="no-data" style="margin-top: 16px;">
                Select a distributor to view their detailed shipment volume trends.
            </div>
        <?php endif; ?>
    </div>

    <!-- MODULE 7: Distributors Sorted by Average Delay -->
    <div class="card">
        <div class="module-header">
            <h2 class="module-header-title">Distributors Sorted by Average Delay</h2>
            <p class="subtitle">
                Top 20 distributors ranked by average delivery delay (highest to lowest).
            </p>
        </div>

        <div class="chart-header">
            <h3 class="chart-title">Average Delay by Distributor</h3>
            <p class="chart-subtitle">Average days late (or early if negative) for delivered shipments.</p>
        </div>
        <div class="df-chart-wrapper">
            <?php if (!empty($distributorsByDelay)): ?>
                <canvas id="delayDistChart" height="600"></canvas>
            <?php else: ?>
                <div class="no-data">
                    No distributor delay data available.
                </div>
            <?php endif; ?>
        </div>
    </div>

    </div>
    <!-- END TAB: Logistics Performance -->

    <!-- TAB 4: Data Management -->
    <div id="tab-management" class="tab-content <?php echo ($activeTab === 'management') ? 'active' : ''; ?>">

    <!-- MODULE 8: Add New Company -->
    <div class="card">
        <div class="module-header">
            <h2 class="module-header-title">Add New Company</h2>
            <p class="subtitle">
                Add a new company to the supply chain network.
            </p>
        </div>

        <?php if ($addCompanyMessage): ?>
            <div style="margin-bottom: 16px; padding: 12px; background-color: <?php echo $addCompanySuccess ? '#d1fae5' : '#fee2e2'; ?>; border-radius: 8px; border: 1px solid <?php echo $addCompanySuccess ? '#10b981' : '#ef4444'; ?>;">
                <p style="margin: 0; color: <?php echo $addCompanySuccess ? '#065f46' : '#991b1b'; ?>; font-weight: 600;">
                    <?php echo htmlspecialchars($addCompanyMessage); ?>
                </p>
            </div>
        <?php endif; ?>

        <form method="post" class="df-form">
            <input type="hidden" name="active_tab" value="management">
            
            <div class="df-field" style="min-width: 250px;">
                <label for="new_company_name">Company Name *</label>
                <input type="text" id="new_company_name" name="new_company_name" 
                       placeholder="Enter company name" required>
            </div>

            <div class="df-field" style="min-width: 200px;">
                <label for="new_company_type">Company Type *</label>
                <select id="new_company_type" name="new_company_type" required>
                    <option value="">-- Select Type --</option>
                    <option value="Retailer">Retailer</option>
                    <option value="Manufacturer">Manufacturer</option>
                    <option value="Distributor">Distributor</option>
                </select>
            </div>

            <div class="df-field" style="min-width: 150px;">
                <label for="new_company_tier">Tier Level (1-3) *</label>
                <input type="number" id="new_company_tier" name="new_company_tier" 
                       min="1" max="3" placeholder="1, 2, or 3" required>
            </div>

            <div class="df-field" style="min-width: 200px;">
                <label for="new_city">City *</label>
                <input type="text" id="new_city" name="new_city" 
                       placeholder="e.g., New York" required>
            </div>

            <div class="df-field" style="min-width: 200px;">
                <label for="new_country">Country *</label>
                <input type="text" id="new_country" name="new_country" 
                       placeholder="e.g., United States" required>
            </div>

            <div class="df-field" style="min-width: 200px;">
                <label for="new_continent">Continent *</label>
                <select id="new_continent" name="new_continent" required>
                    <option value="">-- Select Continent --</option>
                    <option value="Africa">Africa</option>
                    <option value="Asia">Asia</option>
                    <option value="Europe">Europe</option>
                    <option value="North America">North America</option>
                    <option value="South America">South America</option>
                    <option value="Oceania">Oceania</option>
                </select>
            </div>

            <button type="submit" name="add_company" value="1">Add Company</button>
        </form>

       <div style="margin-top: 20px; padding: 12px; background-color: #2d2d2d; border-radius: 8px;">
            <p style="margin: 4px 0; color: var(--muted); font-size: 0.85rem;">
                <strong>Note:</strong> All fields marked with * are required. Tier level must be 1, 2, or 3 (integer). If the location doesn't exist in the database, it will be created automatically.
            </p>
        </div>
    </div>

    </div>
    <!-- END TAB: Data Management -->

</div>

<script>
// Tab Switching Function
function switchTab(tabName) {
    // Hide all tab contents
    var tabContents = document.querySelectorAll('.tab-content');
    for (var i = 0; i < tabContents.length; i++) {
        tabContents[i].classList.remove('active');
    }
    
    // Remove active class from all buttons
    var tabButtons = document.querySelectorAll('.tab-button');
    for (var i = 0; i < tabButtons.length; i++) {
        tabButtons[i].classList.remove('active');
    }
    
    // Show selected tab content - match all tabs with this prefix
    var selectedTabs = document.querySelectorAll('[id^="tab-' + tabName + '"]');
    for (var i = 0; i < selectedTabs.length; i++) {
        selectedTabs[i].classList.add('active');
    }
    
    // Add active class to clicked button
    event.target.classList.add('active');
}

(function() {
    // MODULE 1: Financial Health by Company Chart
    var fhCompanyLabels = <?php echo $fhByCompanyLabelsJson; ?>;
    var fhCompanyValues = <?php echo $fhByCompanyValuesJson; ?>;

    if (fhCompanyLabels.length && document.getElementById('fhCompanyChart')) {
        var ctx1 = document.getElementById('fhCompanyChart').getContext('2d');
        new Chart(ctx1, {
            type: 'bar',
            data: {
                labels: fhCompanyLabels,
                datasets: [{
                    label: 'Average Health Score',
                    data: fhCompanyValues,
                    backgroundColor: '#60a5fa',
                    borderColor: '#3b82f6',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        ticks: { 
                            color: '#f5f5f5',
                            maxRotation: 90,
                            minRotation: 45,
                            font: { size: 10 }
                        },
                        grid: { display: false },
                        title: {
                            display: true,
                            text: 'Company Name',
                            color: '#f5f5f5',
                            font: { size: 13 }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: { 
                            color: '#f5f5f5'
                        },
                        grid: { color: 'rgba(255,255,255,0.1)' },
                        title: {
                            display: true,
                            text: 'Health Score',
                            color: '#f5f5f5',
                            font: { size: 13 }
                        }
                    }
                },
                plugins: {
                    legend: { 
                        display: true,
                        position: 'top',
                        align: 'start',
                        labels: { 
                            color: '#f5f5f5',
                            padding: 10,
                            font: { size: 12 }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Health Score: ' + context.raw.toFixed(2);
                            }
                        }
                    }
                }
            }
        });
    }

    // MODULE 1: Financial Health by Type Chart
    var fhTypeLabels = <?php echo $fhByTypeLabelsJson; ?>;
    var fhTypeValues = <?php echo $fhByTypeValuesJson; ?>;

    if (fhTypeLabels.length && document.getElementById('fhTypeChart')) {
        var ctx2 = document.getElementById('fhTypeChart').getContext('2d');
        
        // Generate colors for each type
        var typeColors = fhTypeLabels.map(function(label, index) {
            var colors = ['#10b981', '#8b5cf6', '#f59e0b', '#ef4444', '#06b6d4'];
            return colors[index % colors.length];
        });

        new Chart(ctx2, {
            type: 'bar',
            data: {
                labels: fhTypeLabels,
                datasets: [{
                    label: 'Average Health Score',
                    data: fhTypeValues,
                    backgroundColor: typeColors,
                    borderColor: typeColors.map(c => c),
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
                        max: 100,
                        ticks: { color: '#f5f5f5' },
                        grid: { color: 'rgba(255,255,255,0.1)' },
                        title: {
                            display: true,
                            text: 'Health Score',
                            color: '#f5f5f5',
                            font: { size: 13 }
                        }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Health Score: ' + context.raw.toFixed(2);
                            }
                        }
                    }
                }
            }
        });
    }

    // MODULE 2: Regional Disruption Chart (Stacked Bar)
    var rdRegions = <?php echo $rdRegionsJson; ?>;
    var rdLow = <?php echo $rdLowImpactDisruptionsJson; ?>;
    var rdMedium = <?php echo $rdMediumImpactDisruptionsJson; ?>;
    var rdHigh = <?php echo $rdHighImpactDisruptionsJson; ?>;

    if (rdRegions.length && document.getElementById('regionalChart')) {
        var ctx3 = document.getElementById('regionalChart').getContext('2d');
        new Chart(ctx3, {
            type: 'bar',
            data: {
                labels: rdRegions,
                datasets: [
                    {
                        label: 'Low Impact',
                        data: rdLow,
                        backgroundColor: '#22c55e',
                        borderColor: '#16a34a',
                        borderWidth: 1
                    },
                    {
                        label: 'Medium Impact',
                        data: rdMedium,
                        backgroundColor: '#fbbf24',
                        borderColor: '#f59e0b',
                        borderWidth: 1
                    },
                    {
                        label: 'High Impact',
                        data: rdHigh,
                        backgroundColor: '#ef4444',
                        borderColor: '#dc2626',
                        borderWidth: 1
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
                        grid: { display: false },
                        title: {
                            display: true,
                            text: 'Continent',
                            color: '#f5f5f5',
                            font: { size: 13 }
                        }
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
                            text: 'Number of Disruptions',
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
                                return context.dataset.label + ': ' + context.raw + ' disruptions';
                            },
                            footer: function(items) {
                                var sum = 0;
                                items.forEach(function(item) {
                                    sum += item.raw;
                                });
                                return 'Total: ' + sum + ' disruptions';
                            }
                        }
                    }
                }
            }
        });
    }

    // MODULE 2: Most Critical Companies Chart
    var criticalCompanies = <?php echo $criticalCompaniesJson; ?>;
    var criticalityScores = <?php echo $criticalityScoresJson; ?>;

    if (criticalCompanies.length && document.getElementById('criticalityChart')) {
        var ctx4 = document.getElementById('criticalityChart').getContext('2d');
        new Chart(ctx4, {
            type: 'bar',
            data: {
                labels: criticalCompanies,
                datasets: [{
                    label: 'Criticality Score',
                    data: criticalityScores,
                    backgroundColor: '#a855f7',
                    borderColor: '#9333ea',
                    borderWidth: 1
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: { 
                            color: '#f5f5f5',
                            precision: 0
                        },
                        grid: { color: 'rgba(255,255,255,0.1)' },
                        title: {
                            display: true,
                            text: 'Criticality Score',
                            color: '#f5f5f5',
                            font: { size: 13 }
                        }
                    },
                    y: {
                        ticks: { color: '#f5f5f5' },
                        grid: { display: false }
                    }
                },
                plugins: {
                    legend: { 
                        labels: { color: '#f5f5f5' }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Criticality Score: ' + context.raw;
                            }
                        }
                    }
                }
            }
        });
    }

    // MODULE 2: Disruption Frequency Over Time Chart
    var disruptionDates = <?php echo $disruptionDatesJson; ?>;
    var disruptionCounts = <?php echo $disruptionCountsJson; ?>;

    if (disruptionDates.length && document.getElementById('freqTimeChart')) {
        var ctx5 = document.getElementById('freqTimeChart').getContext('2d');
        new Chart(ctx5, {
            type: 'line',
            data: {
                labels: disruptionDates,
                datasets: [{
                    label: 'Number of Disruptions',
                    data: disruptionCounts,
                    backgroundColor: 'rgba(59, 130, 246, 0.2)',
                    borderColor: '#3b82f6',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#3b82f6',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        ticks: { 
                            color: '#f5f5f5',
                            maxRotation: 45,
                            minRotation: 45
                        },
                        grid: { color: 'rgba(255,255,255,0.1)' },
                        title: {
                            display: true,
                            text: 'Month',
                            color: '#f5f5f5',
                            font: { size: 13 }
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
                            text: 'Number of Disruptions',
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
                                return 'Disruptions: ' + context.raw;
                            }
                        }
                    }
                }
            }
        });
    }

    // Supply Chain Vulnerability Score Chart
    var vulnerableCompanies = <?php echo $vulnerableCompaniesJson; ?>;
    var vulnerabilityScores = <?php echo $vulnerabilityScoresJson; ?>;

    if (vulnerableCompanies.length && document.getElementById('vulnerabilityChart')) {
        var ctx5b = document.getElementById('vulnerabilityChart').getContext('2d');
        
        // Color code bars based on vulnerability level (gradient from green to red)
        var barColors = vulnerabilityScores.map(function(score, index) {
            var maxScore = Math.max.apply(null, vulnerabilityScores);
            var normalizedScore = score / maxScore;
            
            if (normalizedScore > 0.7) {
                return '#ef4444'; // Critical - Red
            } else if (normalizedScore > 0.4) {
                return '#f59e0b'; // Elevated - Orange
            } else {
                return '#10b981'; // Moderate - Green
            }
        });

        new Chart(ctx5b, {
            type: 'bar',
            data: {
                labels: vulnerableCompanies,
                datasets: [{
                    label: 'Vulnerability Score',
                    data: vulnerabilityScores,
                    backgroundColor: barColors,
                    borderColor: barColors,
                    borderWidth: 1
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: { 
                            color: '#f5f5f5',
                            precision: 1
                        },
                        grid: { color: 'rgba(255,255,255,0.1)' },
                        title: {
                            display: true,
                            text: 'Vulnerability Score',
                            color: '#f5f5f5',
                            font: { size: 13 }
                        }
                    },
                    y: {
                        ticks: { color: '#f5f5f5' },
                        grid: { display: false }
                    }
                },
                plugins: {
                    legend: { 
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                var score = context.raw;
                                var riskLevel = '';
                                var maxScore = Math.max.apply(null, vulnerabilityScores);
                                var normalizedScore = score / maxScore;
                                
                                if (normalizedScore > 0.7) {
                                    riskLevel = 'Critical Risk';
                                } else if (normalizedScore > 0.4) {
                                    riskLevel = 'Elevated Risk';
                                } else {
                                    riskLevel = 'Moderate Risk';
                                }
                                
                                return [
                                    'Vulnerability Score: ' + score.toFixed(2),
                                    'Risk Level: ' + riskLevel
                                ];
                            }
                        }
                    }
                }
            }
        });
    }

    // MODULE 3: Company Financials Chart
    var cfQuarters = <?php echo $cfQuartersJson; ?>;
    var cfHealthScores = <?php echo $cfHealthScoresJson; ?>;

    if (cfQuarters.length && document.getElementById('companyFinChart')) {
        var ctx6 = document.getElementById('companyFinChart').getContext('2d');
        new Chart(ctx6, {
            type: 'line',
            data: {
                labels: cfQuarters,
                datasets: [{
                    label: 'Health Score',
                    data: cfHealthScores,
                    backgroundColor: 'rgba(16, 185, 129, 0.2)',
                    borderColor: '#10b981',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#10b981',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        ticks: { 
                            color: '#f5f5f5',
                            maxRotation: 45,
                            minRotation: 45
                        },
                        grid: { color: 'rgba(255,255,255,0.1)' },
                        title: {
                            display: true,
                            text: 'Quarter',
                            color: '#f5f5f5',
                            font: { size: 13 }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: { 
                            color: '#f5f5f5',
                            callback: function(value) {
                                return value;
                            }
                        },
                        grid: { color: 'rgba(255,255,255,0.1)' },
                        title: {
                            display: true,
                            text: 'Health Score',
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
                                return 'Health Score: ' + context.raw.toFixed(2);
                            }
                        }
                    }
                }
            }
        });
    }

    // MODULE 4: Top Distributors Chart
    var topDistributors = <?php echo $topDistributorsJson; ?>;
    var topDistributorVolumes = <?php echo $topDistributorVolumesJson; ?>;

    if (topDistributors.length && document.getElementById('topDistChart')) {
        var ctx7 = document.getElementById('topDistChart').getContext('2d');
        new Chart(ctx7, {
            type: 'bar',
            data: {
                labels: topDistributors,
                datasets: [{
                    label: 'Total Shipment Volume',
                    data: topDistributorVolumes,
                    backgroundColor: '#f59e0b',
                    borderColor: '#d97706',
                    borderWidth: 1
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: { 
                            color: '#f5f5f5',
                            precision: 0
                        },
                        grid: { color: 'rgba(255,255,255,0.1)' },
                        title: {
                            display: true,
                            text: 'Total Volume (Units)',
                            color: '#f5f5f5',
                            font: { size: 13 }
                        }
                    },
                    y: {
                        ticks: { color: '#f5f5f5' },
                        grid: { display: false }
                    }
                },
                plugins: {
                    legend: { 
                        labels: { color: '#f5f5f5' }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Volume: ' + context.raw.toLocaleString() + ' units';
                            }
                        }
                    }
                }
            }
        });
    }

    // MODULE 4: Distributor Volume Over Time Chart
    var tdMonths = <?php echo $tdMonthsJson; ?>;
    var tdVolumes = <?php echo $tdVolumesJson; ?>;

    if (tdMonths.length && document.getElementById('distVolumeChart')) {
        var ctx8 = document.getElementById('distVolumeChart').getContext('2d');
        new Chart(ctx8, {
            type: 'line',
            data: {
                labels: tdMonths,
                datasets: [{
                    label: 'Shipment Volume',
                    data: tdVolumes,
                    backgroundColor: 'rgba(245, 158, 11, 0.2)',
                    borderColor: '#f59e0b',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#f59e0b',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        ticks: { 
                            color: '#f5f5f5',
                            maxRotation: 45,
                            minRotation: 45
                        },
                        grid: { color: 'rgba(255,255,255,0.1)' },
                        title: {
                            display: true,
                            text: 'Month',
                            color: '#f5f5f5',
                            font: { size: 13 }
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
                            text: 'Volume (Units)',
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
                                return 'Volume: ' + context.raw.toLocaleString() + ' units';
                            }
                        }
                    }
                }
            }
        });
    }

    // MODULE 7: Distributors Sorted by Average Delay Chart
    var distributorsByDelay = <?php echo $distributorsByDelayJson; ?>;
    var avgDelays = <?php echo $avgDelaysJson; ?>;

    if (distributorsByDelay.length && document.getElementById('delayDistChart')) {
        var ctx9 = document.getElementById('delayDistChart').getContext('2d');
        
        // Color code bars: red for positive delays, green for negative (early)
        var barColors = avgDelays.map(function(delay) {
            return delay > 0 ? '#ef4444' : '#10b981';
        });

        new Chart(ctx9, {
            type: 'bar',
            data: {
                labels: distributorsByDelay,
                datasets: [{
                    label: 'Average Delay (Days)',
                    data: avgDelays,
                    backgroundColor: barColors,
                    borderColor: barColors,
                    borderWidth: 1
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        ticks: { 
                            color: '#f5f5f5',
                            callback: function(value) {
                                return value + ' days';
                            }
                        },
                        grid: { color: 'rgba(255,255,255,0.1)' },
                        title: {
                            display: true,
                            text: 'Average Delay (Days)',
                            color: '#f5f5f5',
                            font: { size: 13 }
                        }
                    },
                    y: {
                        ticks: { color: '#f5f5f5' },
                        grid: { display: false }
                    }
                },
                plugins: {
                    legend: { 
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                var delay = context.raw;
                                if (delay > 0) {
                                    return 'Avg Delay: ' + delay.toFixed(2) + ' days late';
                                } else if (delay < 0) {
                                    return 'Avg Delay: ' + Math.abs(delay).toFixed(2) + ' days early';
                                } else {
                                    return 'Avg Delay: On time';
                                }
                            }
                        }
                    }
                }
            }
        });
    }

    // MODULE 9: Risk vs Financial Health Scatter Plot
    var scatterData = <?php echo $scatterDataJson; ?>;

    if (scatterData.length && document.getElementById('riskHealthScatter')) {
        // Define colors for each company type
        var typeColors = {
            'Supplier': '#3b82f6',
            'Manufacturer': '#8b5cf6',
            'Distributor': '#f59e0b',
            'Retailer': '#10b981'
        };

        // Group data by company type for separate datasets
        var datasetsByType = {};
        
        scatterData.forEach(function(company) {
            if (!datasetsByType[company.type]) {
                datasetsByType[company.type] = [];
            }
            
            // Calculate point size based on downtime (min 4, max 20)
            var pointSize = Math.min(20, Math.max(4, 4 + (company.totalDowntime / 10)));
            
            datasetsByType[company.type].push({
                x: company.healthScore,
                y: company.disruptionCount,
                label: company.companyName,
                tier: company.tier,
                downtime: company.totalDowntime,
                pointRadius: pointSize,
                pointHoverRadius: pointSize + 3
            });
        });

        // Create datasets array
        var datasets = [];
        Object.keys(datasetsByType).forEach(function(type) {
            datasets.push({
                label: type,
                data: datasetsByType[type],
                backgroundColor: typeColors[type] || '#6b7280',
                borderColor: typeColors[type] || '#6b7280',
                borderWidth: 2
            });
        });

        var ctx10 = document.getElementById('riskHealthScatter').getContext('2d');
        new Chart(ctx10, {
            type: 'scatter',
            data: {
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        type: 'linear',
                        position: 'bottom',
                        min: 0,
                        max: 100,
                        ticks: { 
                            color: '#f5f5f5',
                            stepSize: 10
                        },
                        grid: { 
                            color: 'rgba(255,255,255,0.1)',
                            drawBorder: true
                        },
                        title: {
                            display: true,
                            text: 'Average Financial Health Score',
                            color: '#f5f5f5',
                            font: { size: 14, weight: 'bold' }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { 
                            color: '#f5f5f5',
                            precision: 0
                        },
                        grid: { 
                            color: 'rgba(255,255,255,0.1)',
                            drawBorder: true
                        },
                        title: {
                            display: true,
                            text: 'Disruption Frequency (Total Events)',
                            color: '#f5f5f5',
                            font: { size: 14, weight: 'bold' }
                        }
                    }
                },
                plugins: {
                    legend: { 
                        labels: { 
                            color: '#f5f5f5',
                            font: { size: 12 },
                            usePointStyle: true,
                            padding: 15
                        }
                    },
                    tooltip: {
                        callbacks: {
                            title: function(context) {
                                return context[0].raw.label;
                            },
                            label: function(context) {
                                var point = context.raw;
                                return [
                                    'Type: ' + context.dataset.label,
                                    'Tier: ' + point.tier,
                                    'Health Score: ' + point.x.toFixed(2),
                                    'Disruptions: ' + point.y,
                                    'Total Downtime: ' + point.downtime + ' days'
                                ];
                            }
                        }
                    }
                }
            }
        });
    }

})();
</script>

</body>
</html>

<?php
$conn->close();
?>





