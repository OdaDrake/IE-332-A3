<?php
session_start();
date_default_timezone_set('America/Indiana/Indianapolis');

/* If you want to restrict to logged-in users again, uncomment this:
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}
*/

/* ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(EALL);
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
// MODULE 1: Company info table
// -------------------------------------------------
$sql = "
SELECT
    c.CompanyID,
    c.CompanyName,
    c.Type,
    c.TierLevel,
    l.City,
    l.CountryName,
    ROUND(
        100 * SUM(CASE WHEN s.ActualDate <= s.PromisedDate THEN 1 ELSE 0 END)
        / NULLIF(COUNT(s.ShipmentID), 0),
        2
    ) AS OnTimeRate,
    AVG(DATEDIFF(s.ActualDate, s.PromisedDate)) AS AvgDelay,
    STDDEV_SAMP(DATEDIFF(s.ActualDate, s.PromisedDate)) AS StdDelay,
    AVG(fr.HealthScore) AS AvgHealthScoreLastYear,
    COUNT(DISTINCT de.EventID) AS DisruptionCountLastYear
FROM Company c
JOIN Location l
    ON c.LocationID = l.LocationID
LEFT JOIN Shipping s
    ON s.SourceCompanyID = c.CompanyID
    AND s.ActualDate IS NOT NULL  -- ensure delivered shipments only
LEFT JOIN FinancialReport fr
    ON fr.CompanyID = c.CompanyID
    AND fr.RepYear >= YEAR(CURDATE()) - 1
LEFT JOIN ImpactsCompany ic
    ON ic.AffectedCompanyID = c.CompanyID
LEFT JOIN DisruptionEvent de
    ON de.EventID = ic.EventID
    AND de.EventDate >= CURDATE() - INTERVAL 1 YEAR
GROUP BY
    c.CompanyID, c.CompanyName, c.Type, c.TierLevel, l.City, l.CountryName
ORDER BY c.CompanyName;
";

$result = $conn->query($sql);
if (!$result) {
    die("Query failed: " . $conn->error);
}

// -------------------------------------------------
// MODULE 2: Disruption Events (DF + ART)
// -------------------------------------------------

// Date range
$dfStart = isset($_GET['df_start']) ? trim($_GET['df_start']) : '';
$dfEnd   = isset($_GET['df_end'])   ? trim($_GET['df_end'])   : '';

if ($dfStart === '' && $dfEnd === '') {
    $dfEnd   = date('Y-m-d');
    $dfStart = date('Y-m-d', strtotime('-90 days'));
}

// Observation period T (days) for DF
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
    die("Query failed: " . $conn->error);
}

// ---------- Total Downtime (TD) ----------
$TDsql = "
    
";

// Encode for JS
$dfLabelsJson  = json_encode($dfLabels);
$dfValuesJson  = json_encode($dfValues);
$artLabels     = array_keys($artBins);
$artValues     = array_values($artBins);
$artLabelsJson = json_encode($artLabels);
$artValuesJson = json_encode($artValues);
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
            <p class="subtitle">
                Live view of companies, locations, and tiers pulled directly from the MySQL
                <strong>Company</strong> and <strong>Location</strong> tables.
            </p>
        </div>

        <?php if ($result->num_rows > 0): ?>
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
                        <th> Distruption Dist.</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['CompanyName']); ?></td>
                            <td><span class="pill"><?php echo htmlspecialchars($row['Type']); ?></span></td>
                            <td><span class="pill tier-pill"><?php echo htmlspecialchars($row['TierLevel']); ?></span></td>
                            <td><?php echo htmlspecialchars($row['City']); ?></td>
                            <td><?php echo htmlspecialchars($row['CountryName']); ?></td>
                            <td><?php echo htmlspecialchars(isset($row['OnTimeRate']) ? $row['OnTimeRate'] : 'N/A') ?></td>
                            <td><?php echo htmlspecialchars(isset($row['AvgDelay']) ? $row['AvgDelay'] : 'N/A') ?></td>
                            <td><?php echo htmlspecialchars(isset($row['StdDelay']) ? $row['StdDelay'] : 'N/A') ?></td>
                            <td><?php echo htmlspecialchars(isset($row['AvgHealthScoreLastYear']) ? $row['AvgHealthScoreLastYear'] : 'N/A') ?></td>
                            <td><?php echo htmlspecialchars(isset($row['DisruptionCountLastYear']) ? $row['DisruptionCountLastYear'] : '0') ?></td>
                        </tr>
                    <?php endwhile; ?>
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
                <canvas id="dfChart" height="260"></canvas>
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
                <canvas id="artChart" height="260"></canvas>
            <?php else: ?>
                <div class="no-data">
                    No recovered disruption events in the selected period.
                </div>
            <?php endif; ?>
        </div>

        <!-- HDR -->
        <div class="chart-header">
            <h3 class="chart-title"> High-Impact Distruption Rate (HDR) </h3>
            <p><?php echo htmlspecialchars($HDRresult->fetch_assoc()['HDR_percent']); ?></p>
        </div>

        <!-- Total Downtime (TD) -->
        <div class="chart-header">
            <h3 class="chart-title"> Total Downtime (TD) </h3>
        </div>
    </div>
</div>

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
})();
</script>
</body>
</html>
<?php
$result->free();
$conn->close();
?>
