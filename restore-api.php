<?php
// restore-api.php - Updated to use reports endpoint and separate billable/non-billable with weekly, monthly earnings, and top projects
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Load configuration
if (!file_exists('config.php')) {
    echo json_encode([
        'success' => false,
        'error' => 'Configuration file not found. Please copy config.php.example to config.php and update with your credentials.',
        'debug_info' => ['Missing config.php file']
    ]);
    exit;
}

$config = require 'config.php';
$apiKey = $config['breeze_api_key'];
$hourlyRate = $config['hourly_rate'] ?? 115;
$excludedUsers = $config['excluded_users'] ?? ['admin'];
$apiTimeout = $config['api_timeout'] ?? 10;

// Progress level configuration
$progressLevels = $config['progress_levels'] ?? [];
$dailyIncrement = $progressLevels['daily_increment'] ?? 92;
$maxLevel = $progressLevels['max_level'] ?? 20;
$rainbowThreshold = $progressLevels['rainbow_threshold'] ?? 1500;
$monthlyMultiplier = $progressLevels['monthly_multiplier'] ?? 5;
$levelDescriptions = $progressLevels['level_descriptions'] ?? [];

if (empty($apiKey) || $apiKey === 'YOUR_BREEZE_API_KEY_HERE') {
    echo json_encode([
        'success' => false,
        'error' => 'API key not configured. Please update config.php with your Breeze PM API key.',
        'debug_info' => ['API key not set in config.php']
    ]);
    exit;
}

function apiCall($endpoint, $apiKey, $postData = null, $timeout = 10) {
    $url = $endpoint . (strpos($endpoint, '?') ? '&' : '?') . 'api_token=' . $apiKey;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/json']);
    
    if ($postData !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        throw new Exception("cURL Error: $error");
    }
    
    if ($httpCode !== 200) {
        throw new Exception("HTTP $httpCode from $endpoint");
    }
    
    return json_decode($response, true);
}

try {
    $result = [
        'success' => true,
        'session_count' => 0,
        'billable_hours' => 0,
        'non_billable_hours' => 0,
        'daily_earnings' => 0,
        'weekly_earnings' => [],
        'monthly_earnings' => [],
        'champion_data' => [],
        'top_projects' => [],
        'data' => [],
        'debug_info' => [],
        'config' => [
            'daily_increment' => $dailyIncrement,
            'max_level' => $maxLevel,
            'rainbow_threshold' => $rainbowThreshold,
            'monthly_multiplier' => $monthlyMultiplier,
            'level_descriptions' => $levelDescriptions
        ]
    ];
    
    // 1. Get running timers (active sessions)
    try {
        $timers = apiCall('https://api.breeze.pm/running_timers.json', $apiKey, null, $apiTimeout);
        $result['data'] = $timers;
        $result['session_count'] = is_array($timers) ? count($timers) : 0;
        $result['debug_info'][] = "Found {$result['session_count']} running timers";
    } catch (Exception $e) {
        $result['debug_info'][] = "Running timers error: " . $e->getMessage();
    }
    
    // 2. Get daily time from reports endpoint (more accurate than activities)
    $billableHours = 0;
    $nonBillableHours = 0;
    $today = date('Y-m-d');
    
    try {
        $result['debug_info'][] = "Fetching time tracking report for today...";
        
        // Create report request for today's data
        $reportData = [
            'report_type' => 'timetracking',
            'start_date' => $today,
            'end_date' => $today
        ];
        
        $timeEntries = apiCall('https://api.breeze.pm/reports.json', $apiKey, $reportData, $apiTimeout);
        
        if (is_array($timeEntries)) {
            $billableCount = 0;
            $nonBillableCount = 0;
            
            foreach ($timeEntries as $entry) {
                $tracked = isset($entry['tracked']) ? intval($entry['tracked']) : 0; // tracked is in minutes
                $hours = $tracked / 60; // convert to hours
                $isNonBillable = isset($entry['notbillable']) ? $entry['notbillable'] : false;
                
                if ($isNonBillable) {
                    $nonBillableHours += $hours;
                    $nonBillableCount++;
                    $result['debug_info'][] = "Non-billable: {$hours}h from entry ID {$entry['id']}";
                } else {
                    $billableHours += $hours;
                    $billableCount++;
                    $result['debug_info'][] = "Billable: {$hours}h from entry ID {$entry['id']}";
                }
            }
            
            $totalEntries = count($timeEntries);
            $result['debug_info'][] = "Processed $totalEntries time entries: $billableCount billable, $nonBillableCount non-billable";
        } else {
            $result['debug_info'][] = "No time entries returned from reports endpoint";
        }
    } catch (Exception $e) {
        $result['debug_info'][] = "Reports error: " . $e->getMessage();
    }
    
    $result['billable_hours'] = round($billableHours, 2);
    $result['non_billable_hours'] = round($nonBillableHours, 2);
    $result['daily_earnings'] = round($billableHours * $hourlyRate, 2); // Only billable hours count for earnings
    $result['today_date'] = $today;
    
    // 3. Get champion data (user with most hours today)
    try {
        $result['debug_info'][] = "Fetching champion data...";
        
        // Get all users
        $users = apiCall('https://api.breeze.pm/users.json', $apiKey, null, $apiTimeout);
        $championData = [
            'champion_name' => 'No one yet',
            'champion_hours' => 0,
            'user_rank' => 1,
            'total_users' => 0,
            'leaderboard' => []
        ];
        
        if (is_array($users) && count($users) > 0) {
            $userHours = [];
            $currentUserEmail = null;
            
            // Try to get current user's email (you may need to adjust this)
            try {
                $currentUser = apiCall('https://api.breeze.pm/users/me.json', $apiKey, null, $apiTimeout);
                if (isset($currentUser['email'])) {
                    $currentUserEmail = $currentUser['email'];
                }
            } catch (Exception $e) {
                $result['debug_info'][] = "Could not get current user: " . $e->getMessage();
            }
            
            // Get time tracking data for each user today
            foreach ($users as $user) {
                if (!isset($user['id']) || !isset($user['name'])) continue;
                
                // Skip excluded users
                $skip = false;
                foreach ($excludedUsers as $excludedUser) {
                    if (stripos($user['name'], $excludedUser) !== false) {
                        $result['debug_info'][] = "Skipping user: {$user['name']}";
                        $skip = true;
                        break;
                    }
                }
                if ($skip) continue;
                
                try {
                    // Get time entries for this user today
                    $userReportData = [
                        'report_type' => 'timetracking',
                        'start_date' => $today,
                        'end_date' => $today,
                        'user_ids' => [$user['id']]
                    ];
                    
                    $userTimeEntries = apiCall('https://api.breeze.pm/reports.json', $apiKey, $userReportData, $apiTimeout);
                    
                    $userTotalHours = 0;
                    if (is_array($userTimeEntries)) {
                        foreach ($userTimeEntries as $entry) {
                            // Verify this entry belongs to this user
                            $entryUserId = isset($entry['user_id']) ? $entry['user_id'] : null;
                            if ($entryUserId && $entryUserId != $user['id']) {
                                $result['debug_info'][] = "Entry user_id {$entryUserId} doesn't match target user_id {$user['id']}, skipping";
                                continue;
                            }
                            
                            $tracked = isset($entry['tracked']) ? intval($entry['tracked']) : 0;
                            $userTotalHours += $tracked / 60; // convert minutes to hours
                        }
                    }
                    
                    $result['debug_info'][] = "User {$user['name']} (ID: {$user['id']}) has {$userTotalHours} hours";
                    
                    $userHours[] = [
                        'name' => $user['name'],
                        'email' => isset($user['email']) ? $user['email'] : '',
                        'hours' => round($userTotalHours, 2),
                        'is_current_user' => $currentUserEmail && isset($user['email']) && $user['email'] === $currentUserEmail
                    ];
                    
                } catch (Exception $e) {
                    $result['debug_info'][] = "Error getting data for user {$user['name']}: " . $e->getMessage();
                }
            }
            
            // Sort by hours (highest first)
            usort($userHours, function($a, $b) {
                return $b['hours'] <=> $a['hours'];
            });
            
            // Find champion and current user rank
            if (count($userHours) > 0) {
                $championData['champion_name'] = $userHours[0]['name'];
                $championData['champion_hours'] = $userHours[0]['hours'];
                $championData['total_users'] = count($userHours);
                
                // Find current user's rank
                for ($i = 0; $i < count($userHours); $i++) {
                    if ($userHours[$i]['is_current_user']) {
                        $championData['user_rank'] = $i + 1;
                        break;
                    }
                }
                
                // Create leaderboard (top 5)
                $championData['leaderboard'] = array_slice($userHours, 0, 5);
            }
            
            $result['debug_info'][] = "Champion analysis complete: {$championData['champion_name']} with {$championData['champion_hours']} hours";
        }
        
        $result['champion_data'] = $championData;
        
    } catch (Exception $e) {
        $result['debug_info'][] = "Champion data error: " . $e->getMessage();
        $result['champion_data'] = [
            'champion_name' => 'Error loading',
            'champion_hours' => 0,
            'user_rank' => 1,
            'total_users' => 0,
            'leaderboard' => []
        ];
    }

    // 4. Get weekly earnings data (last 7 days) - FIXED VERSION
    try {
        $result['debug_info'][] = "Fetching weekly earnings data...";
        
        // Calculate date range for last 7 days
        $endDate = new DateTime();
        $startDate = clone $endDate;
        $startDate->modify('-6 days'); // Last 7 days including today
        
        // Create weekly earnings array first with empty values
        $weeklyEarnings = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = clone $endDate;
            $date->modify("-{$i} days");
            $dateKey = $date->format('Y-m-d');
            
            $weeklyEarnings[] = [
                'date' => $dateKey,
                'hours' => 0,
                'earnings' => 0
            ];
        }
        
        // FOR TODAY SPECIFICALLY: Use the same data as daily calculation
        // This ensures consistency between daily and weekly displays
        $todayIndex = 6; // Today is the last item in the array
        $weeklyEarnings[$todayIndex]['hours'] = round($billableHours, 2);
        $weeklyEarnings[$todayIndex]['earnings'] = round($billableHours * $hourlyRate, 2);
        
        $result['debug_info'][] = "Using daily calculation data for today in weekly view: {$billableHours} hours = \$" . ($billableHours * $hourlyRate);
        
        // FOR OTHER DAYS: Get individual day data
        for ($i = 0; $i < 6; $i++) { // Skip today (index 6)
            $targetDate = $weeklyEarnings[$i]['date'];
            
            try {
                // Get data for this specific day only
                $dayReportData = [
                    'report_type' => 'timetracking',
                    'start_date' => $targetDate,
                    'end_date' => $targetDate
                ];
                
                $dayTimeEntries = apiCall('https://api.breeze.pm/reports.json', $apiKey, $dayReportData, $apiTimeout);
                
                $dayBillableHours = 0;
                if (is_array($dayTimeEntries)) {
                    foreach ($dayTimeEntries as $entry) {
                        $tracked = isset($entry['tracked']) ? intval($entry['tracked']) : 0;
                        $hours = $tracked / 60;
                        $isNonBillable = isset($entry['notbillable']) ? $entry['notbillable'] : false;
                        
                        if (!$isNonBillable) {
                            $dayBillableHours += $hours;
                        }
                    }
                }
                
                $weeklyEarnings[$i]['hours'] = round($dayBillableHours, 2);
                $weeklyEarnings[$i]['earnings'] = round($dayBillableHours * $hourlyRate, 2);
                
                if ($dayBillableHours > 0) {
                    $result['debug_info'][] = "Day $targetDate: {$dayBillableHours} hours = \$" . ($dayBillableHours * $hourlyRate);
                }
                
            } catch (Exception $e) {
                $result['debug_info'][] = "Error getting data for $targetDate: " . $e->getMessage();
                // Leave as 0 hours/earnings on error
            }
        }
        
        $result['weekly_earnings'] = $weeklyEarnings;
        $result['debug_info'][] = "Generated weekly earnings with consistent today calculation";
        
    } catch (Exception $e) {
        $result['debug_info'][] = "Weekly data error: " . $e->getMessage();
        $result['weekly_earnings'] = []; // Empty array on error
    }

    // 5. Get monthly earnings data (last 6 months)
    try {
        $result['debug_info'][] = "Fetching monthly earnings data...";
        
        $monthlyEarnings = [];
        $currentDate = new DateTime();
        
        // Generate 6 months of data
        for ($i = 5; $i >= 0; $i--) {
            // Calculate the target month
            $targetDate = clone $currentDate;
            $targetDate->modify("-{$i} months");
            $targetDate->modify('first day of this month');
            
            $monthKey = $targetDate->format('Y-m');
            $monthStart = $targetDate->format('Y-m-01');
            $monthEnd = $targetDate->format('Y-m-t'); // Last day of month
            
            try {
                // Get all time entries for this month
                $monthReportData = [
                    'report_type' => 'timetracking',
                    'start_date' => $monthStart,
                    'end_date' => $monthEnd
                ];
                
                $monthTimeEntries = apiCall('https://api.breeze.pm/reports.json', $apiKey, $monthReportData, $apiTimeout);
                
                $monthBillableHours = 0;
                if (is_array($monthTimeEntries)) {
                    foreach ($monthTimeEntries as $entry) {
                        $tracked = isset($entry['tracked']) ? intval($entry['tracked']) : 0;
                        $hours = $tracked / 60;
                        $isNonBillable = isset($entry['notbillable']) ? $entry['notbillable'] : false;
                        
                        if (!$isNonBillable) {
                            $monthBillableHours += $hours;
                        }
                    }
                }
                
                $monthlyEarnings[] = [
                    'month' => $monthKey,
                    'hours' => round($monthBillableHours, 2),
                    'earnings' => round($monthBillableHours * $hourlyRate, 2)
                ];
                
                if ($monthBillableHours > 0) {
                    $result['debug_info'][] = "Month $monthKey: {$monthBillableHours} hours = \$" . ($monthBillableHours * $hourlyRate);
                }
                
            } catch (Exception $e) {
                $result['debug_info'][] = "Error getting data for month $monthKey: " . $e->getMessage();
                // Add empty entry on error
                $monthlyEarnings[] = [
                    'month' => $monthKey,
                    'hours' => 0,
                    'earnings' => 0
                ];
            }
        }
        
        $result['monthly_earnings'] = $monthlyEarnings;
        $result['debug_info'][] = "Generated monthly earnings for 6 months";
        
    } catch (Exception $e) {
        $result['debug_info'][] = "Monthly data error: " . $e->getMessage();
        $result['monthly_earnings'] = []; // Empty array on error
    }

    // 6. Get top 5 projects with most billable time in last 30 days
    try {
        $result['debug_info'][] = "Fetching top projects data...";
        
        // Calculate date range for last 30 days
        $endDate = new DateTime();
        $startDate = clone $endDate;
        $startDate->modify('-30 days');
        
        $startDateStr = $startDate->format('Y-m-d');
        $endDateStr = $endDate->format('Y-m-d');
        
        // Get all projects first
        $projects = apiCall('https://api.breeze.pm/projects.json', $apiKey, null, $apiTimeout);
        $projectHours = [];
        
        if (is_array($projects)) {
            $result['debug_info'][] = "Found " . count($projects) . " projects, analyzing time entries...";
            
            // Get time entries for the last 30 days
            $projectReportData = [
                'report_type' => 'timetracking',
                'start_date' => $startDateStr,
                'end_date' => $endDateStr
            ];
            
            $timeEntries = apiCall('https://api.breeze.pm/reports.json', $apiKey, $projectReportData, $apiTimeout);
            
            if (is_array($timeEntries)) {
                // Group time entries by project
                foreach ($timeEntries as $entry) {
                    $projectId = isset($entry['project_id']) ? $entry['project_id'] : null;
                    $tracked = isset($entry['tracked']) ? intval($entry['tracked']) : 0;
                    $isNonBillable = isset($entry['notbillable']) ? $entry['notbillable'] : false;
                    
                    // Only count billable time
                    if ($projectId && !$isNonBillable && $tracked > 0) {
                        $hours = $tracked / 60; // convert minutes to hours
                        
                        if (!isset($projectHours[$projectId])) {
                            $projectHours[$projectId] = 0;
                        }
                        $projectHours[$projectId] += $hours;
                    }
                }
                
                $result['debug_info'][] = "Processed " . count($timeEntries) . " time entries";
            }
            
            // Build the top projects list
            $topProjects = [];
            foreach ($projectHours as $projectId => $hours) {
                // Find project details
                $project = null;
                foreach ($projects as $p) {
                    if (isset($p['id']) && $p['id'] == $projectId) {
                        $project = $p;
                        break;
                    }
                }
                
                if ($project) {
                    $topProjects[] = [
                        'id' => $projectId,
                        'name' => isset($project['name']) ? $project['name'] : 'Unknown Project',
                        'description' => isset($project['description']) ? $project['description'] : '',
                        'hours' => round($hours, 2),
                        'earnings' => round($hours * $hourlyRate, 2) // Configurable hourly rate
                    ];
                }
            }
            
            // Sort by hours (highest first) and take top 5
            usort($topProjects, function($a, $b) {
                return $b['hours'] <=> $a['hours'];
            });
            
            $result['top_projects'] = array_slice($topProjects, 0, 5);
            
            if (count($result['top_projects']) > 0) {
                $result['debug_info'][] = "Top project: {$result['top_projects'][0]['name']} with {$result['top_projects'][0]['hours']} hours";
            } else {
                $result['debug_info'][] = "No projects found with billable time in last 30 days";
            }
            
        } else {
            $result['debug_info'][] = "Failed to fetch projects list";
        }
        
    } catch (Exception $e) {
        $result['debug_info'][] = "Top projects error: " . $e->getMessage();
        $result['top_projects'] = []; // Empty array on error
    }
    
    if ($billableHours > 0 || $nonBillableHours > 0) {
        $result['debug_info'][] = "Billable time: {$result['billable_hours']} hours = \${$result['daily_earnings']}";
        $result['debug_info'][] = "Non-billable time: {$result['non_billable_hours']} hours";
    } else {
        $result['debug_info'][] = "No time entries found for today";
    }
    
    echo json_encode($result);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug_info' => ['Fatal error occurred']
    ]);
}
?>