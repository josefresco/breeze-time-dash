// Netlify Function to handle Breeze PM API calls
exports.handler = async (event, context) => {
    // CORS headers for browser requests
    const headers = {
        'Access-Control-Allow-Origin': '*',
        'Access-Control-Allow-Headers': 'Content-Type',
        'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
        'Content-Type': 'application/json'
    };

    // Handle preflight requests
    if (event.httpMethod === 'OPTIONS') {
        return {
            statusCode: 200,
            headers,
            body: ''
        };
    }

    try {
        // Get configuration from environment variables
        const apiKey = process.env.BREEZE_API_KEY;
        const hourlyRate = parseFloat(process.env.HOURLY_RATE || '115');
        const excludedUsers = (process.env.EXCLUDED_USERS || 'admin').split(',').map(u => u.trim().toLowerCase());
        const apiTimeout = parseInt(process.env.API_TIMEOUT || '10') * 1000; // Convert to milliseconds

        // Progress level configuration
        const dailyIncrement = parseFloat(process.env.DAILY_INCREMENT || '92');
        const maxLevel = parseInt(process.env.MAX_LEVEL || '20');
        const rainbowThreshold = parseFloat(process.env.RAINBOW_THRESHOLD || '1500');
        const monthlyMultiplier = parseFloat(process.env.MONTHLY_MULTIPLIER || '5');

        if (!apiKey || apiKey === 'YOUR_BREEZE_API_KEY_HERE') {
            return {
                statusCode: 400,
                headers,
                body: JSON.stringify({
                    success: false,
                    error: 'API key not configured. Please set BREEZE_API_KEY environment variable.',
                    debug_info: ['API key not set in environment variables']
                })
            };
        }

        // API call helper function
        const apiCall = async (endpoint, postData = null) => {
            const url = `${endpoint}${endpoint.includes('?') ? '&' : '?'}api_token=${apiKey}`;
            
            const options = {
                method: postData ? 'POST' : 'GET',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                },
                timeout: apiTimeout
            };

            if (postData) {
                options.body = JSON.stringify(postData);
            }

            const response = await fetch(url, options);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status} from ${endpoint}`);
            }
            
            return await response.json();
        };

        // Initialize result object
        const result = {
            success: true,
            session_count: 0,
            billable_hours: 0,
            non_billable_hours: 0,
            daily_earnings: 0,
            weekly_earnings: [],
            monthly_earnings: [],
            champion_data: [],
            top_projects: [],
            data: [],
            debug_info: [],
            config: {
                daily_increment: dailyIncrement,
                max_level: maxLevel,
                rainbow_threshold: rainbowThreshold,
                monthly_multiplier: monthlyMultiplier,
                level_descriptions: {} // Can be expanded with env vars if needed
            }
        };

        // 1. Get running timers (active sessions)
        try {
            const timers = await apiCall('https://api.breeze.pm/running_timers.json');
            result.data = timers;
            result.session_count = Array.isArray(timers) ? timers.length : 0;
            result.debug_info.push(`Found ${result.session_count} running timers`);
        } catch (error) {
            result.debug_info.push(`Running timers error: ${error.message}`);
        }

        // 2. Get daily time from reports endpoint
        let billableHours = 0;
        let nonBillableHours = 0;
        const today = new Date().toISOString().split('T')[0]; // YYYY-MM-DD format

        try {
            result.debug_info.push("Fetching time tracking report for today...");
            
            const reportData = {
                report_type: 'timetracking',
                start_date: today,
                end_date: today
            };
            
            const timeEntries = await apiCall('https://api.breeze.pm/reports.json', reportData);
            
            if (Array.isArray(timeEntries)) {
                let billableCount = 0;
                let nonBillableCount = 0;
                
                timeEntries.forEach(entry => {
                    const tracked = parseInt(entry.tracked || 0); // tracked is in minutes
                    const hours = tracked / 60; // convert to hours
                    const isNonBillable = entry.notbillable || false;
                    
                    if (isNonBillable) {
                        nonBillableHours += hours;
                        nonBillableCount++;
                        result.debug_info.push(`Non-billable: ${hours}h from entry ID ${entry.id}`);
                    } else {
                        billableHours += hours;
                        billableCount++;
                        result.debug_info.push(`Billable: ${hours}h from entry ID ${entry.id}`);
                    }
                });
                
                const totalEntries = timeEntries.length;
                result.debug_info.push(`Processed ${totalEntries} time entries: ${billableCount} billable, ${nonBillableCount} non-billable`);
            } else {
                result.debug_info.push("No time entries returned from reports endpoint");
            }
        } catch (error) {
            result.debug_info.push(`Reports error: ${error.message}`);
        }

        result.billable_hours = Math.round(billableHours * 100) / 100;
        result.non_billable_hours = Math.round(nonBillableHours * 100) / 100;
        result.daily_earnings = Math.round(billableHours * hourlyRate * 100) / 100;
        result.today_date = today;

        // 3. Get champion data (user with most hours today)
        try {
            result.debug_info.push("Fetching champion data...");
            
            const users = await apiCall('https://api.breeze.pm/users.json');
            let championData = {
                champion_name: 'No one yet',
                champion_hours: 0,
                user_rank: 1,
                total_users: 0,
                leaderboard: []
            };
            
            if (Array.isArray(users) && users.length > 0) {
                const userHours = [];
                let currentUserEmail = null;
                
                // Try to get current user's email
                try {
                    const currentUser = await apiCall('https://api.breeze.pm/users/me.json');
                    if (currentUser.email) {
                        currentUserEmail = currentUser.email;
                    }
                } catch (error) {
                    result.debug_info.push(`Could not get current user: ${error.message}`);
                }
                
                // Get time tracking data for each user today
                for (const user of users) {
                    if (!user.id || !user.name) continue;
                    
                    // Skip excluded users
                    const shouldSkip = excludedUsers.some(excludedUser => 
                        user.name.toLowerCase().includes(excludedUser)
                    );
                    
                    if (shouldSkip) {
                        result.debug_info.push(`Skipping user: ${user.name}`);
                        continue;
                    }
                    
                    try {
                        const userReportData = {
                            report_type: 'timetracking',
                            start_date: today,
                            end_date: today,
                            user_ids: [user.id]
                        };
                        
                        const userTimeEntries = await apiCall('https://api.breeze.pm/reports.json', userReportData);
                        
                        let userTotalHours = 0;
                        if (Array.isArray(userTimeEntries)) {
                            userTimeEntries.forEach(entry => {
                                // Verify this entry belongs to this user
                                if (entry.user_id && entry.user_id != user.id) {
                                    result.debug_info.push(`Entry user_id ${entry.user_id} doesn't match target user_id ${user.id}, skipping`);
                                    return;
                                }
                                
                                const tracked = parseInt(entry.tracked || 0);
                                userTotalHours += tracked / 60; // convert minutes to hours
                            });
                        }
                        
                        result.debug_info.push(`User ${user.name} (ID: ${user.id}) has ${userTotalHours} hours`);
                        
                        userHours.push({
                            name: user.name,
                            email: user.email || '',
                            hours: Math.round(userTotalHours * 100) / 100,
                            is_current_user: currentUserEmail && user.email === currentUserEmail
                        });
                        
                    } catch (error) {
                        result.debug_info.push(`Error getting data for user ${user.name}: ${error.message}`);
                    }
                }
                
                // Sort by hours (highest first)
                userHours.sort((a, b) => b.hours - a.hours);
                
                // Find champion and current user rank
                if (userHours.length > 0) {
                    championData.champion_name = userHours[0].name;
                    championData.champion_hours = userHours[0].hours;
                    championData.total_users = userHours.length;
                    
                    // Find current user's rank
                    for (let i = 0; i < userHours.length; i++) {
                        if (userHours[i].is_current_user) {
                            championData.user_rank = i + 1;
                            break;
                        }
                    }
                    
                    // Create leaderboard (top 5)
                    championData.leaderboard = userHours.slice(0, 5);
                }
                
                result.debug_info.push(`Champion analysis complete: ${championData.champion_name} with ${championData.champion_hours} hours`);
            }
            
            result.champion_data = championData;
            
        } catch (error) {
            result.debug_info.push(`Champion data error: ${error.message}`);
            result.champion_data = {
                champion_name: 'Error loading',
                champion_hours: 0,
                user_rank: 1,
                total_users: 0,
                leaderboard: []
            };
        }

        // 4. Get weekly earnings data (last 7 days)
        try {
            result.debug_info.push("Fetching weekly earnings data...");
            
            const weeklyEarnings = [];
            const endDate = new Date();
            
            // Create weekly earnings array with empty values
            for (let i = 6; i >= 0; i--) {
                const date = new Date(endDate);
                date.setDate(endDate.getDate() - i);
                const dateKey = date.toISOString().split('T')[0];
                
                weeklyEarnings.push({
                    date: dateKey,
                    hours: 0,
                    earnings: 0
                });
            }
            
            // FOR TODAY SPECIFICALLY: Use the same data as daily calculation
            const todayIndex = 6; // Today is the last item in the array
            weeklyEarnings[todayIndex].hours = Math.round(billableHours * 100) / 100;
            weeklyEarnings[todayIndex].earnings = Math.round(billableHours * hourlyRate * 100) / 100;
            
            result.debug_info.push(`Using daily calculation data for today in weekly view: ${billableHours} hours = $${billableHours * hourlyRate}`);
            
            // FOR OTHER DAYS: Get individual day data
            for (let i = 0; i < 6; i++) { // Skip today (index 6)
                const targetDate = weeklyEarnings[i].date;
                
                try {
                    const dayReportData = {
                        report_type: 'timetracking',
                        start_date: targetDate,
                        end_date: targetDate
                    };
                    
                    const dayTimeEntries = await apiCall('https://api.breeze.pm/reports.json', dayReportData);
                    
                    let dayBillableHours = 0;
                    if (Array.isArray(dayTimeEntries)) {
                        dayTimeEntries.forEach(entry => {
                            const tracked = parseInt(entry.tracked || 0);
                            const hours = tracked / 60;
                            const isNonBillable = entry.notbillable || false;
                            
                            if (!isNonBillable) {
                                dayBillableHours += hours;
                            }
                        });
                    }
                    
                    weeklyEarnings[i].hours = Math.round(dayBillableHours * 100) / 100;
                    weeklyEarnings[i].earnings = Math.round(dayBillableHours * hourlyRate * 100) / 100;
                    
                    if (dayBillableHours > 0) {
                        result.debug_info.push(`Day ${targetDate}: ${dayBillableHours} hours = $${dayBillableHours * hourlyRate}`);
                    }
                    
                } catch (error) {
                    result.debug_info.push(`Error getting data for ${targetDate}: ${error.message}`);
                    // Leave as 0 hours/earnings on error
                }
            }
            
            result.weekly_earnings = weeklyEarnings;
            result.debug_info.push("Generated weekly earnings with consistent today calculation");
            
        } catch (error) {
            result.debug_info.push(`Weekly data error: ${error.message}`);
            result.weekly_earnings = [];
        }

        // 5. Get monthly earnings data (last 6 months)
        try {
            result.debug_info.push("Fetching monthly earnings data...");
            
            const monthlyEarnings = [];
            const currentDate = new Date();
            
            // Generate 6 months of data
            for (let i = 5; i >= 0; i--) {
                const targetDate = new Date(currentDate.getFullYear(), currentDate.getMonth() - i, 1);
                const monthKey = `${targetDate.getFullYear()}-${String(targetDate.getMonth() + 1).padStart(2, '0')}`;
                const monthStart = `${targetDate.getFullYear()}-${String(targetDate.getMonth() + 1).padStart(2, '0')}-01`;
                
                // Get last day of month
                const lastDay = new Date(targetDate.getFullYear(), targetDate.getMonth() + 1, 0).getDate();
                const monthEnd = `${targetDate.getFullYear()}-${String(targetDate.getMonth() + 1).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`;
                
                try {
                    const monthReportData = {
                        report_type: 'timetracking',
                        start_date: monthStart,
                        end_date: monthEnd
                    };
                    
                    const monthTimeEntries = await apiCall('https://api.breeze.pm/reports.json', monthReportData);
                    
                    let monthBillableHours = 0;
                    if (Array.isArray(monthTimeEntries)) {
                        monthTimeEntries.forEach(entry => {
                            const tracked = parseInt(entry.tracked || 0);
                            const hours = tracked / 60;
                            const isNonBillable = entry.notbillable || false;
                            
                            if (!isNonBillable) {
                                monthBillableHours += hours;
                            }
                        });
                    }
                    
                    monthlyEarnings.push({
                        month: monthKey,
                        hours: Math.round(monthBillableHours * 100) / 100,
                        earnings: Math.round(monthBillableHours * hourlyRate * 100) / 100
                    });
                    
                    if (monthBillableHours > 0) {
                        result.debug_info.push(`Month ${monthKey}: ${monthBillableHours} hours = $${monthBillableHours * hourlyRate}`);
                    }
                    
                } catch (error) {
                    result.debug_info.push(`Error getting data for month ${monthKey}: ${error.message}`);
                    // Add empty entry on error
                    monthlyEarnings.push({
                        month: monthKey,
                        hours: 0,
                        earnings: 0
                    });
                }
            }
            
            result.monthly_earnings = monthlyEarnings;
            result.debug_info.push("Generated monthly earnings for 6 months");
            
        } catch (error) {
            result.debug_info.push(`Monthly data error: ${error.message}`);
            result.monthly_earnings = [];
        }

        // 6. Get top 5 projects with most billable time in last 30 days
        try {
            result.debug_info.push("Fetching top projects data...");
            
            const endDate = new Date();
            const startDate = new Date(endDate);
            startDate.setDate(endDate.getDate() - 30);
            
            const startDateStr = startDate.toISOString().split('T')[0];
            const endDateStr = endDate.toISOString().split('T')[0];
            
            const projects = await apiCall('https://api.breeze.pm/projects.json');
            const projectHours = {};
            
            if (Array.isArray(projects)) {
                result.debug_info.push(`Found ${projects.length} projects, analyzing time entries...`);
                
                const projectReportData = {
                    report_type: 'timetracking',
                    start_date: startDateStr,
                    end_date: endDateStr
                };
                
                const timeEntries = await apiCall('https://api.breeze.pm/reports.json', projectReportData);
                
                if (Array.isArray(timeEntries)) {
                    // Group time entries by project
                    timeEntries.forEach(entry => {
                        const projectId = entry.project_id;
                        const tracked = parseInt(entry.tracked || 0);
                        const isNonBillable = entry.notbillable || false;
                        
                        // Only count billable time
                        if (projectId && !isNonBillable && tracked > 0) {
                            const hours = tracked / 60; // convert minutes to hours
                            
                            if (!projectHours[projectId]) {
                                projectHours[projectId] = 0;
                            }
                            projectHours[projectId] += hours;
                        }
                    });
                    
                    result.debug_info.push(`Processed ${timeEntries.length} time entries`);
                }
                
                // Build the top projects list
                const topProjects = [];
                Object.entries(projectHours).forEach(([projectId, hours]) => {
                    // Find project details
                    const project = projects.find(p => p.id == projectId);
                    
                    if (project) {
                        topProjects.push({
                            id: projectId,
                            name: project.name || 'Unknown Project',
                            description: project.description || '',
                            hours: Math.round(hours * 100) / 100,
                            earnings: Math.round(hours * hourlyRate * 100) / 100
                        });
                    }
                });
                
                // Sort by hours (highest first) and take top 5
                topProjects.sort((a, b) => b.hours - a.hours);
                result.top_projects = topProjects.slice(0, 5);
                
                if (result.top_projects.length > 0) {
                    result.debug_info.push(`Top project: ${result.top_projects[0].name} with ${result.top_projects[0].hours} hours`);
                } else {
                    result.debug_info.push("No projects found with billable time in last 30 days");
                }
                
            } else {
                result.debug_info.push("Failed to fetch projects list");
            }
            
        } catch (error) {
            result.debug_info.push(`Top projects error: ${error.message}`);
            result.top_projects = [];
        }

        // Final debug info
        if (billableHours > 0 || nonBillableHours > 0) {
            result.debug_info.push(`Billable time: ${result.billable_hours} hours = $${result.daily_earnings}`);
            result.debug_info.push(`Non-billable time: ${result.non_billable_hours} hours`);
        } else {
            result.debug_info.push("No time entries found for today");
        }

        return {
            statusCode: 200,
            headers,
            body: JSON.stringify(result)
        };

    } catch (error) {
        console.error('Function error:', error);
        return {
            statusCode: 500,
            headers,
            body: JSON.stringify({
                success: false,
                error: error.message,
                debug_info: ['Fatal error occurred in serverless function']
            })
        };
    }
};