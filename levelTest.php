<?php
header('Content-Type: text/html; charset=utf-8');

// --- DATABASE CONFIGURATION ---
$host    = 'localhost';
$db      = 'fourhundred2';
$user    = 'root'; // Change to your username
$pass    = '';     // Change to your password
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// --- INITIALIZE VARIABLES ---
$text = '';
$results = null;
$xaiDrivers = []; // Tracks structural diagnostic metrics

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['chinese_text'])) {
    $text = $_POST['chinese_text'];
    
    // Extract individual Chinese characters (multibyte safe regex)
    preg_match_all('/\p{Han}/u', $text, $matches);
    $characters = $matches[0];
    
    if (!empty($characters)) {
        // Fetch matching character IDs from the database
        // Checking both simplified and traditional columns
        $placeholders = implode(',', array_fill(0, count($characters), '?'));
        $stmt = $pdo->prepare("
            SELECT MIN(id) as min_id, simplifiedChinese, traditionalChinese 
            FROM frequency2 
            WHERE simplifiedChinese IN ($placeholders) OR traditionalChinese IN ($placeholders)
            GROUP BY id
        ");
        
        // Execute with double the array to cover both columns in the IN clause
        $stmt->execute(array_merge($characters, $characters));
        $fetched = $stmt->fetchAll();
        
        // Map characters to their respective minimum frequency ID
        $charMap = [];
        foreach ($fetched as $row) {
            $charMap[$row['simplifiedChinese']] = (int)$row['min_id'];
            $charMap[$row['traditionalChinese']] = (int)$row['min_id'];
        }
        
        $validCount = 0;
        $totalWeight = 0;
        
        // Max theoretical difficulty weight per character using exponential scaling
        // Adjusted to new max ID of 5202
        $maxId = 5202;
        $maxWeightPerChar = pow($maxId, 1.5); 
        
        foreach ($characters as $char) {
            if (isset($charMap[$char])) {
                $id = $charMap[$char];
                if ($id >= 1 && $id <= $maxId) {
                    $validCount++;
                    // Exponential weighting: penalize rarer words progressively
                    $charWeight = pow($id, 1.5);
                    $totalWeight += $charWeight;
                    
                    // Determine single character difficulty classification dynamically based on frequency ID ranges
                    // Adjusted proportionally for the new 5202 max limit
                    if ($id <= 700) {
                        $charDifficulty = 'Easy';
                    } elseif ($id <= 1900) {
                        $charDifficulty = 'Intermediate';
                    } else {
                        $charDifficulty = 'Advanced';
                    }
                    
                    // Format object attributes on a single horizontal line using standard string keys
                    $xaiDrivers[] = ['character' => $char, 'id' => $id, 'weight' => $charWeight, 'difficulty' => $charDifficulty];
                }
            }
        }
        
        if ($validCount > 0) {
            // Calculate average weight normalized against the maximum possible weight
            $avgWeight = $totalWeight / $validCount;
            $percentage = ($avgWeight / $maxWeightPerChar) * 100;
            
            // Constrain percentage between 1 and 100
            $percentage = max(1, min(100, round($percentage, 2)));
            
            // Assign categories based on weighted percentage curves
            // These curve thresholds remain mathematically sound because the underlying max weight formula dynamically handles the $maxId change.
            if ($percentage <= 5) {
                $category = 'Very Easy';
            } elseif ($percentage <= 15) {
                $category = 'Easy';
            } elseif ($percentage <= 40) {
                $category = 'Moderately Difficult (Intermediate)';
            } elseif ($percentage <= 70) {
                $category = 'Difficult (Advanced)';
            } else {
                $category = 'Very Difficult';
            }
            
            $results = [
                'percentage' => $percentage,
                'category' => $category,
                'analyzed_chars' => $validCount,
                'total_chars' => count($characters)
            ];
            
            // Sort array metrics down to prioritize high-impact difficulty drivers
            usort($xaiDrivers, function($a, $b) {
                return $b['weight'] <=> $a['weight'];
            });
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hans">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CAFE Chinese Text Difficulty Analyzer</title>
    <style>
        :root {
            --bg-color: #f4f4f6;
            --container-bg: #ffffff;
            --text-color: #1a1a1a;
            --accent-color: #0288d1;
            --border-color: #cccccc;
        }
        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            margin: 0;
            padding: 40px 20px;
            display: flex;
            justify-content: center;
        }
        .container {
            max-width: 750px;
            width: 100%;
            background: var(--container-bg);
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border: 1px solid #e0e0e0;
        }
        h1 {
            font-size: 24px;
            font-weight: 400;
            margin-bottom: 20px;
            color: #000000;
        }
        textarea {
            width: 100%;
            height: 180px;
            background-color: #fafafa;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            color: #000000;
            padding: 15px;
            box-sizing: border-box;
            resize: vertical;
            font-family: "PingFang SC", "Lantinghei SC", "Heiti SC", "Microsoft YaHei Light", "Source Han Sans CN Light", sans-serif;
            font-size: 24px;
            font-weight: 200;
            line-height: 1.6;
        }
        textarea:focus {
            outline: none;
            border-color: var(--accent-color);
            background-color: #ffffff;
        }
        .btn-submit {
            background-color: var(--accent-color);
            color: #ffffff;
            border: none;
            padding: 12px 24px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 15px;
            transition: background 0.2s;
        }
        .btn-submit:hover {
            background-color: #01579b;
        }
        .result-box {
            margin-top: 30px;
            padding: 20px;
            background-color: #f8f9fa;
            border-left: 4px solid var(--accent-color);
            border-radius: 4px;
            border-top: 1px solid #e9ecef;
            border-right: 1px solid #e9ecef;
            border-bottom: 1px solid #e9ecef;
        }
        .metric {
            margin-bottom: 10px;
            font-size: 16px;
            color: #333333;
        }
        .metric span {
            font-weight: bold;
            color: #000000;
        }
        .rating-val {
            font-size: 22px;
            color: var(--accent-color) !important;
        }
        
        /* New styling configurations for XAI Diagnostic Elements */
        .xai-section {
            margin-top: 25px;
            border-top: 1px dashed #cccccc;
            padding-top: 20px;
        }
        .xai-title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 12px;
            color: #333333;
        }
        .xai-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        .xai-table th, .xai-table td {
            padding: 10px;
            border: 1px solid #e0e0e0;
            text-align: left;
        }
        .xai-table th {
            background-color: #f1f3f5;
            font-weight: 600;
        }
        .xai-char {
            font-size: 18px;
            font-weight: bold;
            color: #000000;
        }
    </style>
</head>
<body>

<div class="container">
    <h1>CAFE Chinese Difficulty Analyzer</h1>
    <form method="POST" action="">
        <textarea name="chinese_text" placeholder="在此粘贴中文文本..." required><?php echo htmlspecialchars($text); ?></textarea>
        <br />
        <button type="submit" class="btn-submit">Analyze Difficulty</button>
    </form>

    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
        <div class="result-box">
            <?php if ($results): ?>
                <div class="metric">Difficulty Rating: <span class="rating-val"><?php echo $results['percentage']; ?>%</span></div>
                <div class="metric">Classification: <span><?php echo $results['category']; ?></span></div>
                <div class="metric">Valid Core Characters Scanned: <span><?php echo $results['analyzed_chars']; ?> / <?php echo $results['total_chars']; ?></span></div>
                
                <div class="xai-section">
                    <div class="xai-title">Diagnostic Breakdown (Top Difficulty Drivers)</div>
                    <table class="xai-table">
                        <thead>
                            <tr>
                                <th>Character</th>
                                <th>Frequency ID</th>
                                <th>Exponential Weight ($ID^{1.5}$)</th>
                                <th>Difficulty Level</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // Output up to the top 300 most difficult diagnostic matches
                            $limit = min(300, count($xaiDrivers));
                            for ($i = 0; $i < $limit; $i++): 
                                $driver = $xaiDrivers[$i];
                            ?>
                                <tr>
                                    <td><span class="xai-char"><?php echo htmlspecialchars($driver['character']); ?></span></td>
                                    <td><?php echo $driver['id']; ?></td>
                                    <td><?php echo round($driver['weight'], 1); ?></td>
                                    <td><?php echo $driver['difficulty']; ?></td>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
                
            <?php else: ?>
                <div class="metric" style="color: #d32f2f;">No valid Chinese characters from the 5,202 frequency list were found in the provided text.</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
