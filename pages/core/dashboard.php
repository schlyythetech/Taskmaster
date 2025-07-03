<?php
require_once '../../includes/functions.php';
require_once '../../includes/session_functions.php';
require_once '../../config/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    setMessage("You must be logged in to access the dashboard.", "danger");
    redirect('../auth/login.php');
}

// Fix any negative session durations
fixNegativeSessionDurations($conn);

// Get user data
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$first_name = explode(' ', $user_name)[0];

// Fetch user statistics from database
try {
    // Get total projects (both owner and member)
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total FROM (
            SELECT project_id FROM projects WHERE owner_id = ?
            UNION
            SELECT project_id FROM project_members 
            WHERE user_id = ? AND status = 'active'
        ) as user_projects
    ");
    $stmt->execute([$user_id, $user_id]);
    $result = $stmt->fetch();
    $total_projects = $result['total'];

    // Get ongoing projects
    $stmt = $conn->prepare("
        SELECT COUNT(*) as ongoing FROM (
            SELECT p.project_id FROM projects p WHERE p.owner_id = ? AND p.status = 'in_progress'
            UNION
            SELECT p.project_id FROM project_members pm 
            JOIN projects p ON pm.project_id = p.project_id
            WHERE pm.user_id = ? AND pm.status = 'active' AND p.status = 'in_progress'
        ) as ongoing_projects
    ");
    $stmt->execute([$user_id, $user_id]);
    $result = $stmt->fetch();
    $ongoing_projects = $result['ongoing'];

    // Get projects needing review
    $stmt = $conn->prepare("
        SELECT COUNT(*) as review FROM (
            SELECT p.project_id FROM projects p WHERE p.owner_id = ? AND p.status = 'review'
            UNION
            SELECT p.project_id FROM project_members pm 
            JOIN projects p ON pm.project_id = p.project_id
            WHERE pm.user_id = ? AND pm.status = 'active' AND p.status = 'review'
        ) as review_projects
    ");
    $stmt->execute([$user_id, $user_id]);
    $result = $stmt->fetch();
    $review_needed = $result['review'];

    // Get total tasks assigned to user
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total FROM tasks WHERE assigned_to = ?
    ");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch();
    $total_tasks = $result['total'];

    // Get ongoing tasks
    $stmt = $conn->prepare("
        SELECT COUNT(*) as ongoing FROM tasks 
        WHERE assigned_to = ? AND status IN ('backlog', 'to_do', 'in_progress')
    ");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch();
    $ongoing_tasks = $result['ongoing'];

    // Get tasks that need reviewing (created by current user, assigned to others, and status is 'review')
    $stmt = $conn->prepare("
        SELECT COUNT(*) as review_needed FROM tasks 
        WHERE created_by = ? AND assigned_to != ? AND assigned_to IS NOT NULL AND status = 'review'
    ");
    $stmt->execute([$user_id, $user_id]);
    $result = $stmt->fetch();
    $tasks_needing_review = $result['review_needed'];
    
    // Update review_needed to include tasks needing review
    $review_needed += $tasks_needing_review;

    // Get total hours spent from user sessions
    $hours_spent = getUserHoursSpent($conn, $user_id);
    
    // Get workload data - completed tasks assigned to user and tasks created by user
    $stmt = $conn->prepare("
        SELECT 
            COUNT(CASE WHEN assigned_to = ? AND status = 'completed' THEN 1 END) as completed_assigned,
            COUNT(CASE WHEN created_by = ? AND status = 'completed' THEN 1 END) as completed_created
        FROM tasks
    ");
    $stmt->execute([$user_id, $user_id]);
    $workload_data = $stmt->fetch();
    
    // Get completed tasks by month for the chart
    $stmt = $conn->prepare("
        SELECT 
            DATE_FORMAT(updated_at, '%Y-%m') as month,
            COUNT(CASE WHEN assigned_to = ? AND status = 'completed' THEN 1 END) as completed_assigned,
            COUNT(CASE WHEN created_by = ? AND status = 'completed' THEN 1 END) as completed_created
        FROM tasks
        WHERE (assigned_to = ? OR created_by = ?)
        AND status = 'completed'
        AND updated_at IS NOT NULL
        AND updated_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(updated_at, '%Y-%m')
        ORDER BY month
    ");
    $stmt->execute([$user_id, $user_id, $user_id, $user_id]);
    $monthly_completed_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Initialize completed tasks array with the last 6 months
    $monthly_tasks_chart = [];
    $current_month = new DateTime();
    
    for ($i = 5; $i >= 0; $i--) {
        $month_date = clone $current_month;
        $month_date->modify("-$i month");
        $month_key = $month_date->format('Y-m');
        $month_label = $month_date->format('M Y');
        
        $monthly_tasks_chart[$month_key] = [
            'label' => $month_label,
            'short_label' => $month_date->format('M Y'),
            'completed_assigned' => 0,
            'completed_created' => 0
        ];
    }
    
    // Fill in the actual data
    foreach ($monthly_completed_tasks as $item) {
        if (isset($monthly_tasks_chart[$item['month']])) {
            $monthly_tasks_chart[$item['month']]['completed_assigned'] = (int)$item['completed_assigned'];
            $monthly_tasks_chart[$item['month']]['completed_created'] = (int)$item['completed_created'];
        }
    }
    
    // Get completed tasks by day for the chart
    $stmt = $conn->prepare("
        SELECT 
            DATE_FORMAT(updated_at, '%Y-%m-%d') as day,
            COUNT(CASE WHEN assigned_to = ? AND status = 'completed' THEN 1 END) as completed_assigned,
            COUNT(CASE WHEN created_by = ? AND status = 'completed' THEN 1 END) as completed_created
        FROM tasks
        WHERE (assigned_to = ? OR created_by = ?)
        AND status = 'completed'
        AND updated_at IS NOT NULL
        AND updated_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DATE_FORMAT(updated_at, '%Y-%m-%d')
        ORDER BY day
    ");
    $stmt->execute([$user_id, $user_id, $user_id, $user_id]);
    $daily_completed_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Initialize completed tasks array with the last 7 days
    $daily_tasks_chart = [];
    $current_day = new DateTime();
    
    for ($i = 6; $i >= 0; $i--) {
        $day_date = clone $current_day;
        $day_date->modify("-$i day");
        $day_key = $day_date->format('Y-m-d');
        $day_label = $day_date->format('D, M j'); // Format: Mon, Jan 1
        
        $daily_tasks_chart[$day_key] = [
            'label' => $day_label,
            'short_label' => $day_date->format('M j'),
            'completed_assigned' => 0,
            'completed_created' => 0
        ];
    }
    
    // Fill in the actual data
    foreach ($daily_completed_tasks as $item) {
        if (isset($daily_tasks_chart[$item['day']])) {
            $daily_tasks_chart[$item['day']]['completed_assigned'] = (int)$item['completed_assigned'];
            $daily_tasks_chart[$item['day']]['completed_created'] = (int)$item['completed_created'];
        }
    }
    
    // Determine which dataset to show by default (monthly or daily)
    $default_view = 'monthly'; // Can be 'monthly' or 'daily'

} catch (PDOException $e) {
    // Log error and set default values
    error_log("Dashboard statistics error: " . $e->getMessage());
    $total_projects = 0;
    $ongoing_projects = 0;
    $review_needed = 0;
    $total_tasks = 0;
    $ongoing_tasks = 0;
    $hours_spent = [
        'hours' => 0,
        'minutes' => 0,
        'total_minutes' => 0,
        'formatted' => '0h 0m'
    ];
    $workload_data = [
        'completed_assigned' => 0,
        'completed_created' => 0
    ];
    
    // Initialize empty completed tasks chart data
    $monthly_tasks_chart = [];
    $current_month = new DateTime();
    
    for ($i = 5; $i >= 0; $i--) {
        $month_date = clone $current_month;
        $month_date->modify("-$i month");
        $month_key = $month_date->format('Y-m');
        $month_label = $month_date->format('M Y');
        
        $monthly_tasks_chart[$month_key] = [
            'label' => $month_label,
            'short_label' => $month_date->format('M Y'),
            'completed_assigned' => 0,
            'completed_created' => 0
        ];
    }
    
    // Initialize empty daily tasks chart data
    $daily_tasks_chart = [];
    $current_day = new DateTime();
    
    for ($i = 6; $i >= 0; $i--) {
        $day_date = clone $current_day;
        $day_date->modify("-$i day");
        $day_key = $day_date->format('Y-m-d');
        $day_label = $day_date->format('D, M j'); // Format: Mon, Jan 1
        
        $daily_tasks_chart[$day_key] = [
            'label' => $day_label,
            'short_label' => $day_date->format('M j'),
            'completed_assigned' => 0,
            'completed_created' => 0
        ];
    }
}

// Page title
$page_title = "Dashboard";
include '../../includes/header.php';
?>

<div class="d-flex">
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo-container">
            <img src="../../assets/images/logo.png" alt="TaskMaster Logo" class="logo">
            <h1>TaskMaster</h1>
        </div>
        <nav>
            <ul>
                <li class="active">
                    <a href="../core/dashboard.php"><i class="fas fa-home"></i> Home</a>
                </li>
                <li>
                    <a href="../projects/projects.php"><i class="fas fa-project-diagram"></i> Projects</a>
                </li>
                <li>
                    <a href="../tasks/tasks.php"><i class="fas fa-tasks"></i> Tasks</a>
                </li>
                <li>
                    <a href="../users/profile.php"><i class="fas fa-user"></i> Profile</a>
                </li>
                <li>
                    <a href="../users/connections.php"><i class="fas fa-users"></i> Connections</a>
                </li>
            </ul>
        </nav>
        <div class="sidebar-footer">
            <a href="../core/settings.php"><i class="fas fa-cog"></i> Settings</a>
            <a href="../auth/logout.php" id="logout-btn"><i class="fas fa-sign-out-alt"></i> Log Out</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navigation -->
        <div class="top-nav">
            <div></div> <!-- Empty div for flex spacing -->
            <!-- Notification system is included in header.php -->
            <div class="top-nav-right-placeholder"></div>
        </div>

        <!-- Dashboard Content -->
        <div class="dashboard-container">
            <div class="welcome-section">
                <div>
                    <h1>Hello <?php echo htmlspecialchars($first_name); ?>,</h1>
                    <p>Here is a summary of your activity.</p>
                </div>
                <div>
                    <a href="../projects/create_project.php" class="btn btn-success new-project-btn">Start a new project</a>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="stats-container">
                <div class="stats-row">
                    <!-- Projects Stats -->
                    <div class="stats-card">
                        <div class="stats-info">
                            <div>
                                <h3>Total Projects</h3>
                                <h2 class="stats-number"><?php echo $total_projects; ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="stats-card">
                        <div class="stats-info">
                            <div>
                                <h3>Ongoing Projects</h3>
                                <h2 class="stats-number"><?php echo $ongoing_projects; ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="stats-card">
                        <div class="stats-info">
                            <div>
                                <h3>Review Needed <i class="fas fa-info-circle" data-bs-toggle="tooltip" title="Projects in review status and tasks you created that are ready for your review"></i></h3>
                                <h2 class="stats-number"><?php echo $review_needed; ?></h2>
                            </div>
                        </div>
                        
                    </div>
                </div>

                <div class="stats-row">
                    <!-- Tasks Stats -->
                    <div class="stats-card">
                        <div class="stats-info">
                            <div>
                                <h3>Total Tasks</h3>
                                <h2 class="stats-number"><?php echo $total_tasks; ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="stats-card">
                        <div class="stats-info">
                            <div>
                                <h3>Ongoing Tasks</h3>
                                <h2 class="stats-number"><?php echo $ongoing_tasks; ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="stats-card">
                        <div class="stats-info">
                            <div>
                                <h3>Hours Spent</h3>
                                <h2 class="stats-number"><?php echo $hours_spent['formatted']; ?></h2>
                            </div>
                        </div>
                        
                    </div>
                </div>
            </div>

            <!-- Task Completion Section -->
            <div class="workload-section">
                <h2>Your Task Completion</h2>
                <div class="workload-card">
                    <div class="workload-header">
                        <h3>Completed Tasks Overview</h3>
                        <div class="view-toggle">
                            <button class="btn btn-sm btn-toggle active" data-view="monthly">Monthly</button>
                            <button class="btn btn-sm btn-toggle" data-view="daily">Daily</button>
                        </div>
                    </div>
                    <div class="workload-chart">
                        <div class="chart-container">
                            <canvas id="completedTasksChart"></canvas>
                        </div>
                        <div class="chart-legend">
                            <div class="legend-item clickable" data-dataset="completed">
                                <span class="legend-color completed"></span>
                                <span class="legend-label">Completed Tasks</span>
                            </div>
                            <div class="legend-item clickable" data-dataset="created">
                                <span class="legend-color created"></span>
                                <span class="legend-label">Created by You</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Debug Section (Remove in production) -->
            <?php if (isset($_GET['debug']) && $_GET['debug'] == 1): ?>
            <div class="debug-section mt-5">
                <h3>Debug Information</h3>
                <div class="card">
                    <div class="card-body">
                        <h4>Session Information</h4>
                        <?php
                        $stmt = $conn->prepare("SELECT * FROM user_sessions WHERE user_id = ? ORDER BY login_time DESC LIMIT 5");
                        $stmt->execute([$user_id]);
                        $sessions = $stmt->fetchAll();
                        
                        if (count($sessions) > 0):
                        ?>
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Session ID</th>
                                    <th>Login Time</th>
                                    <th>Logout Time</th>
                                    <th>Duration (min)</th>
                                    <th>Active</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sessions as $session): ?>
                                <tr>
                                    <td><?php echo $session['session_id']; ?></td>
                                    <td><?php echo $session['login_time']; ?></td>
                                    <td><?php echo $session['logout_time'] ? $session['logout_time'] : 'Still active'; ?></td>
                                    <td><?php echo $session['duration_minutes'] ? $session['duration_minutes'] : 'N/A'; ?></td>
                                    <td><?php echo $session['is_active'] ? 'Yes' : 'No'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <p>No sessions found for this user.</p>
                        <?php endif; ?>
                        
                        <h4>Hours Calculation</h4>
                        <p>Total time spent: <?php echo $hours_spent['formatted']; ?> (<?php echo $hours_spent['total_minutes']; ?> minutes)</p>
                        <p>
                            <a href="dashboard.php" class="btn btn-sm btn-secondary">Hide Debug Info</a>
                        </p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Logout Confirmation Modal -->
<div class="modal fade" id="logoutModal" tabindex="-1" aria-labelledby="logoutModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="logoutModalLabel">Confirm Logout</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                Are you sure you want to log out?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="../auth/logout.php" class="btn btn-primary">Logout</a>
            </div>
        </div>
    </div>
</div>

<!-- Add Chart.js library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
/* Chart legend styles */
.chart-legend {
    display: flex;
    justify-content: flex-start;
    margin-top: 15px;
    padding-left: 10px;
}

.legend-item {
    display: flex;
    align-items: center;
    margin-right: 20px;
    font-size: 14px;
}

.legend-item.clickable {
    cursor: pointer;
    padding: 5px 10px;
    border-radius: 4px;
    transition: background-color 0.2s;
}

.legend-item.clickable:hover {
    background-color: rgba(0, 0, 0, 0.05);
}

.legend-item.disabled {
    opacity: 0.5;
}

.legend-color {
    width: 12px;
    height: 12px;
    margin-right: 8px;
    border-radius: 2px;
}

.legend-color.completed {
    background-color: #28a745;
}

.legend-color.created {
    background-color: #007bff;
}

.legend-label {
    color: #495057;
}

.legend-value {
    margin-left: 8px;
    font-weight: 500;
}

/* Chart container styles */
.chart-container {
    height: 250px;
    position: relative;
}

/* Workload header styles */
.workload-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

/* View toggle styles */
.view-toggle {
    display: flex;
    gap: 5px;
}

.btn-toggle {
    background-color: #f8f9fa;
    border: 1px solid #dee2e6;
    color: #6c757d;
    font-size: 12px;
    padding: 4px 10px;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-toggle:hover {
    background-color: #e9ecef;
}

.btn-toggle.active {
    background-color: #28a745;
    border-color: #28a745;
    color: white;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Prepare data for completed tasks chart
    const monthlyLabels = [];
    const monthlyAssignedData = [];
    const monthlyCreatedData = [];
    
    <?php foreach ($monthly_tasks_chart as $month => $data): ?>
        monthlyLabels.push('<?php echo $data['short_label']; ?>');
        monthlyAssignedData.push(<?php echo $data['completed_assigned']; ?>);
        monthlyCreatedData.push(<?php echo $data['completed_created']; ?>);
    <?php endforeach; ?>
    
    const dailyLabels = [];
    const dailyAssignedData = [];
    const dailyCreatedData = [];
    
    <?php foreach ($daily_tasks_chart as $day => $data): ?>
        dailyLabels.push('<?php echo $data['short_label']; ?>');
        dailyAssignedData.push(<?php echo $data['completed_assigned']; ?>);
        dailyCreatedData.push(<?php echo $data['completed_created']; ?>);
    <?php endforeach; ?>
    
    // Track dataset visibility
    const datasetVisibility = {
        completed: true,
        created: true
    };
    
    // Completed Tasks Chart
    const ctx = document.getElementById('completedTasksChart').getContext('2d');
    let completedTasksChart;
    let currentLabels, currentAssignedData, currentCreatedData;
    
    function createChart(labels, assignedData, createdData) {
        // Save current data for toggling
        currentLabels = labels;
        currentAssignedData = assignedData;
        currentCreatedData = createdData;
        
        if (completedTasksChart) {
            completedTasksChart.destroy();
        }
        
        completedTasksChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Completed Tasks',
                        data: datasetVisibility.completed ? assignedData : [],
                        backgroundColor: 'rgba(40, 167, 69, 0.2)',
                        borderColor: '#28a745',
                        borderWidth: 2,
                        tension: 0.3,
                        fill: true,
                        pointBackgroundColor: '#28a745',
                        pointRadius: 4,
                        pointHoverRadius: 6
                    },
                    {
                        label: 'Created by You',
                        data: datasetVisibility.created ? createdData : [],
                        backgroundColor: 'rgba(0, 123, 255, 0.2)',
                        borderColor: '#007bff',
                        borderWidth: 2,
                        tension: 0.3,
                        fill: true,
                        pointBackgroundColor: '#007bff',
                        pointRadius: 4,
                        pointHoverRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#e5e5e5',
                            borderDash: [5, 5],
                        },
                        ticks: {
                            precision: 0,
                            stepSize: 1,
                            font: {
                                size: 11
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 11
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(255, 255, 255, 0.9)',
                        titleColor: '#333',
                        bodyColor: '#666',
                        borderColor: 'rgba(200, 200, 200, 0.5)',
                        borderWidth: 1,
                        cornerRadius: 8,
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                return label + ': ' + context.raw;
                            }
                        }
                    }
                }
            }
        });
    }
    
    // Initialize chart with default view
    const defaultView = '<?php echo $default_view; ?>';
    if (defaultView === 'monthly') {
        createChart(monthlyLabels, monthlyAssignedData, monthlyCreatedData);
    } else {
        createChart(dailyLabels, dailyAssignedData, dailyCreatedData);
    }
    
    // Handle view toggle
    const toggleButtons = document.querySelectorAll('.btn-toggle');
    toggleButtons.forEach(button => {
        button.addEventListener('click', function() {
            toggleButtons.forEach(btn => btn.classList.remove('active'));
            this.classList.add('active');
            
            const view = this.getAttribute('data-view');
            if (view === 'monthly') {
                createChart(monthlyLabels, monthlyAssignedData, monthlyCreatedData);
            } else {
                createChart(dailyLabels, dailyAssignedData, dailyCreatedData);
            }
        });
    });
    
    // Handle legend item clicks
    const legendItems = document.querySelectorAll('.legend-item.clickable');
    legendItems.forEach(item => {
        item.addEventListener('click', function() {
            const dataset = this.getAttribute('data-dataset');
            datasetVisibility[dataset] = !datasetVisibility[dataset];
            
            // Toggle visual state
            if (datasetVisibility[dataset]) {
                this.classList.remove('disabled');
            } else {
                this.classList.add('disabled');
            }
            
            // Refresh chart with current data
            createChart(currentLabels, currentAssignedData, currentCreatedData);
        });
    });
    
    // Logout confirmation
    document.getElementById('logout-btn').addEventListener('click', function(e) {
        e.preventDefault();
        const logoutModal = new bootstrap.Modal(document.getElementById('logoutModal'));
        logoutModal.show();
    });
});
</script>

<?php include '../../includes/footer.php'; ?> 