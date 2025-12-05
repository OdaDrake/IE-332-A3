<?php
session_start();
date_default_timezone_set('America/Indiana/Indianapolis');

/* If you want to restrict to logged-in users again, uncomment this:
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}
*/

/* Uncomment to see errors:
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); */

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

// Extract years for SQL queries (FinancialReport uses RepYear, not RepDate)
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Supply Chain Dashboard - Analytics</title>
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
            <h3 class="chart-title">Average Financial Health by Company</h3>
            <p class="chart-subtitle">Sorted from highest to lowest health score in the selected period.</p>
        </div>
        <div class="df-chart-wrapper">
            <?php if (!empty($fhByCompanyLabels)): ?>
                <canvas id="fhCompanyChart" height="600"></canvas>
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
    </div>

</div>

<script>
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
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
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
                    },
                    y: {
                        ticks: { color: '#f5f5f5' },
                        grid: { display: false }
                    }
                },
                plugins: {
                    legend: { labels: { color: '#f5f5f5' } },
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

})();
</script>

</body>
</html>

<?php
$conn->close();
?>


