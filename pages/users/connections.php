<?php
require_once '../../includes/functions.php';
require_once '../../config/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    setMessage("You must be logged in to view connections.", "danger");
    redirect('../auth/login.php');
}

// Get user data
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$first_name = explode(' ', $user_name)[0];

// Generate CSRF token if it doesn't exist
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get all connections (accepted status)
$stmt = $conn->prepare("
    SELECT 
        c.connection_id, c.status,
        u.user_id, u.first_name, u.last_name, u.profile_image, u.bio,
        (SELECT COUNT(*) FROM connections 
         WHERE ((user_id = c.connected_user_id AND connected_user_id IN 
                (SELECT connected_user_id FROM connections WHERE user_id = ? AND status = 'accepted'))
                OR
                (connected_user_id = c.connected_user_id AND user_id IN 
                (SELECT connected_user_id FROM connections WHERE user_id = ? AND status = 'accepted')))
         AND status = 'accepted') as mutual_connections
    FROM connections c
    JOIN users u ON c.connected_user_id = u.user_id
    WHERE c.user_id = ? AND c.status = 'accepted' AND u.is_banned = 0 AND u.role != 'admin'
    
    UNION
    
    SELECT 
        c.connection_id, c.status,
        u.user_id, u.first_name, u.last_name, u.profile_image, u.bio,
        (SELECT COUNT(*) FROM connections 
         WHERE ((user_id = c.user_id AND connected_user_id IN 
                (SELECT connected_user_id FROM connections WHERE user_id = ? AND status = 'accepted'))
                OR
                (connected_user_id = c.user_id AND user_id IN 
                (SELECT connected_user_id FROM connections WHERE user_id = ? AND status = 'accepted')))
         AND status = 'accepted') as mutual_connections
    FROM connections c
    JOIN users u ON c.user_id = u.user_id
    WHERE c.connected_user_id = ? AND c.status = 'accepted' AND u.is_banned = 0 AND u.role != 'admin'
    
    ORDER BY mutual_connections DESC, first_name ASC
");
$stmt->execute([$user_id, $user_id, $user_id, $user_id, $user_id, $user_id]);
$connections = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get pending connection requests (sent and received)
$stmt = $conn->prepare("
    -- Pending requests I received
    SELECT 
        c.connection_id, c.status, 'received' as direction,
        u.user_id, u.first_name, u.last_name, u.profile_image, u.bio,
        (SELECT COUNT(*) FROM connections 
         WHERE ((user_id = c.user_id AND connected_user_id IN 
                (SELECT connected_user_id FROM connections WHERE user_id = ? AND status = 'accepted'))
                OR
                (connected_user_id = c.user_id AND user_id IN 
                (SELECT connected_user_id FROM connections WHERE user_id = ? AND status = 'accepted')))
         AND status = 'accepted') as mutual_connections
    FROM connections c
    JOIN users u ON c.user_id = u.user_id
    WHERE c.connected_user_id = ? AND c.status = 'pending' AND u.is_banned = 0 AND u.role != 'admin'
    
    UNION
    
    -- Pending requests I sent
    SELECT 
        c.connection_id, c.status, 'sent' as direction,
        u.user_id, u.first_name, u.last_name, u.profile_image, u.bio,
        (SELECT COUNT(*) FROM connections 
         WHERE ((user_id = c.connected_user_id AND connected_user_id IN 
                (SELECT connected_user_id FROM connections WHERE user_id = ? AND status = 'accepted'))
                OR
                (connected_user_id = c.connected_user_id AND user_id IN 
                (SELECT connected_user_id FROM connections WHERE user_id = ? AND status = 'accepted')))
         AND status = 'accepted') as mutual_connections
    FROM connections c
    JOIN users u ON c.connected_user_id = u.user_id
    WHERE c.user_id = ? AND c.status = 'pending' AND u.is_banned = 0 AND u.role != 'admin'
    
    ORDER BY mutual_connections DESC, first_name ASC
");
$stmt->execute([$user_id, $user_id, $user_id, $user_id, $user_id, $user_id]);
$pending_connections = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Page title
$page_title = "Connections";
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
                <li>
                    <a href="../core/dashboard.php"><i class="fas fa-home"></i> Home</a>
                </li>
                <li>
                    <a href="../projects/projects.php"><i class="fas fa-cube"></i> Projects</a>
                </li>
                <li>
                    <a href="../tasks/tasks.php"><i class="fas fa-clipboard-list"></i> Tasks</a>
                </li>
                <li>
                    <a href="../users/profile.php"><i class="fas fa-user"></i> Profile</a>
                </li>
                <li class="active">
                    <a href="../users/connections.php"><i class="fas fa-users"></i> Connections</a>
                </li>
            </ul>
        </nav>
        <div class="sidebar-footer">
            <a href="../core/settings.php"><i class="fas fa-cog"></i> Settings</a>
            <a href="#" id="logout-btn"><i class="fas fa-sign-out-alt"></i> Log Out</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navigation -->
        <div class="top-nav">
            <!-- Notification system is included in header.php -->
            <div class="top-nav-right-placeholder"></div>
        </div>

        <!-- Connections Content -->
        <div class="connections-container">
            <div class="connections-header">
                <div class="back-button">
                    <a href="../core/dashboard.php"><i class="fas fa-arrow-left"></i></a>
                </div>
                <h1><?php echo htmlspecialchars($first_name); ?>'s Connections</h1>
                <div class="connections-search">
                    <div class="search-container">
                        <input type="text" placeholder="Search connections" class="search-input">
                        <button class="search-button"><i class="fas fa-search"></i></button>
                    </div>
                </div>
            </div>

            <?php if (!empty($pending_connections)): ?>
            <div class="connections-section">
                <h2>Pending Requests</h2>
                <div class="connections-grid">
                    <?php foreach ($pending_connections as $connection): ?>
                        <div class="connection-card" data-name="<?php echo htmlspecialchars(strtolower($connection['first_name'] . ' ' . $connection['last_name'])); ?>">
                            <div class="connection-avatar">
                                <?php if (!empty($connection['profile_image'])): ?>
                                    <img src="../../<?php echo htmlspecialchars($connection['profile_image']); ?>" alt="Profile Photo">
                                <?php else: ?>
                                    <div class="default-avatar"><?php echo substr($connection['first_name'], 0, 1); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="connection-info">
                                <h3><a href="others_profile.php?id=<?php echo $connection['user_id']; ?>"><?php echo htmlspecialchars($connection['first_name'] . ' ' . $connection['last_name']); ?></a></h3>
                                <p class="connection-role"><?php echo htmlspecialchars($connection['bio'] ? substr($connection['bio'], 0, 50) . '...' : 'TaskMaster User'); ?></p>
                                <p class="mutual-connections"># <?php echo htmlspecialchars($connection['mutual_connections']); ?> mutual connections</p>
                            </div>
                            <div class="connection-actions">
                                <?php if ($connection['direction'] === 'received'): ?>
                                <button class="btn btn-sm btn-primary accept-connection" data-connection-id="<?php echo $connection['connection_id']; ?>">Accept</button>
                                <button class="btn btn-sm btn-outline-secondary reject-connection" data-connection-id="<?php echo $connection['connection_id']; ?>">Decline</button>
                                <?php else: ?>
                                <span class="pending-badge">Request Sent</span>
                                <button class="btn btn-sm btn-outline-danger cancel-request" data-connection-id="<?php echo $connection['connection_id']; ?>">Cancel</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="connections-section">
                <h2>Your Connections</h2>
                <?php if (empty($connections)): ?>
                <div class="no-connections-message">
                    <p>You don't have any connections yet. Search for other users to connect with them.</p>
                </div>
                <?php else: ?>
                <div class="connections-grid">
                    <?php foreach ($connections as $connection): ?>
                        <div class="connection-card" data-name="<?php echo htmlspecialchars(strtolower($connection['first_name'] . ' ' . $connection['last_name'])); ?>">
                            <div class="connection-avatar">
                                <?php if (!empty($connection['profile_image'])): ?>
                                    <img src="../../<?php echo htmlspecialchars($connection['profile_image']); ?>" alt="Profile Photo">
                                <?php else: ?>
                                    <div class="default-avatar"><?php echo substr($connection['first_name'], 0, 1); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="connection-info">
                                <h3><a href="others_profile.php?id=<?php echo $connection['user_id']; ?>"><?php echo htmlspecialchars($connection['first_name'] . ' ' . $connection['last_name']); ?></a></h3>
                                <p class="connection-role"><?php echo htmlspecialchars($connection['bio'] ? substr($connection['bio'], 0, 50) . '...' : 'TaskMaster User'); ?></p>
                                <p class="mutual-connections"># <?php echo htmlspecialchars($connection['mutual_connections']); ?> mutual connections</p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="find-connections-section">
                <h2>Find New Connections</h2>
                <div class="search-users-container">
                    <div class="search-users-form">
                        <input type="text" id="user-search-input" class="form-control" placeholder="Search for users by name or email">
                        <button id="search-users-btn" class="btn btn-primary">Search</button>
                    </div>
                    <div id="search-results-container" class="mt-3"></div>
                </div>
            </div>
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
                <a href="logout.php" class="btn btn-primary">Logout</a>
            </div>
        </div>
    </div>
</div>

<style>
/* Connections container */
.connections-container {
    padding: 20px;
}

/* Connections header */
.connections-header {
    display: flex;
    align-items: center;
    margin-bottom: 30px;
}

.back-button {
    font-size: 20px;
    margin-right: 15px;
}

.connections-header h1 {
    margin: 0;
    flex-grow: 1;
}

.connections-search {
    width: 300px;
}

.search-container {
    display: flex;
}

.search-input {
    flex-grow: 1;
    padding: 8px 15px;
    border: 1px solid #dee2e6;
    border-radius: 50px 0 0 50px;
    outline: none;
}

.search-button {
    background-color: #007bff;
    color: white;
    border: none;
    padding: 8px 15px;
    border-radius: 0 50px 50px 0;
    cursor: pointer;
}

/* Connections grid */
.connections-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
}

.connection-card {
    background-color: white;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    padding: 20px;
    display: flex;
    align-items: center;
    transition: transform 0.2s;
    cursor: pointer;
}

.connection-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.connection-avatar {
    width: 70px;
    height: 70px;
    border-radius: 50%;
    overflow: hidden;
    margin-right: 15px;
    background-color: #f0f0f0;
    display: flex;
    align-items: center;
    justify-content: center;
}

.connection-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.default-avatar {
    width: 100%;
    height: 100%;
    background-color: #6c757d;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 30px;
    font-weight: bold;
}

.connection-info {
    flex-grow: 1;
}

.connection-info h3 {
    margin: 0 0 5px 0;
    font-size: 18px;
}

.connection-info h3 a {
    color: #333;
    text-decoration: none;
}

.connection-info h3 a:hover {
    text-decoration: underline;
}

.connection-role {
    color: #6c757d;
    margin: 0 0 5px 0;
    font-size: 14px;
}

.mutual-connections {
    font-size: 12px;
    color: #007bff;
    margin: 0;
}

/* Connection actions */
.connection-actions {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.pending-badge {
    font-size: 12px;
    background-color: #ffc107;
    color: #212529;
    padding: 2px 8px;
    border-radius: 20px;
    text-align: center;
    margin-bottom: 5px;
}

/* Sections */
.connections-section,
.find-connections-section {
    margin-bottom: 40px;
}

.connections-section h2,
.find-connections-section h2 {
    font-size: 22px;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 1px solid #dee2e6;
}

/* No connections message */
.no-connections-message {
    background-color: white;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    padding: 30px;
    text-align: center;
    color: #6c757d;
}

/* Find connections section */
.search-users-container {
    background-color: white;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    padding: 20px;
}

.search-users-form {
    display: flex;
    gap: 10px;
}

#search-results-container {
    max-height: 400px;
    overflow-y: auto;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .connections-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .connections-search {
        width: 100%;
        margin-top: 15px;
    }
    
    .connections-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Search existing connections
    const searchInput = document.querySelector('.search-input');
    const connectionCards = document.querySelectorAll('.connection-card');
    
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        
        connectionCards.forEach(card => {
            const name = card.dataset.name;
            
            if (name.includes(searchTerm)) {
                card.style.display = 'flex';
            } else {
                card.style.display = 'none';
            }
        });
    });

    // Accept connection request
    document.querySelectorAll('.accept-connection').forEach(button => {
        button.addEventListener('click', function(e) {
            e.stopPropagation();
            const connectionId = this.dataset.connectionId;
            
            fetch('handle_connection.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=accept&connection_id=${connectionId}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Refresh the page to show updated connections
                    window.location.reload();
                } else {
                    alert('Failed to accept connection: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        });
    });

    // Reject connection request
    document.querySelectorAll('.reject-connection').forEach(button => {
        button.addEventListener('click', function(e) {
            e.stopPropagation();
            const connectionId = this.dataset.connectionId;
            
            fetch('handle_connection.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=reject&connection_id=${connectionId}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Refresh the page to show updated connections
                    window.location.reload();
                } else {
                    alert('Failed to reject connection: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        });
    });

    // Cancel connection request
    document.querySelectorAll('.cancel-request').forEach(button => {
        button.addEventListener('click', function(e) {
            e.stopPropagation();
            const connectionId = this.dataset.connectionId;
            
            fetch('handle_connection.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=cancel&connection_id=${connectionId}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Refresh the page to show updated connections
                    window.location.reload();
                } else {
                    alert('Failed to cancel request: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        });
    });

    // Search for users to connect with
    const userSearchInput = document.getElementById('user-search-input');
    const searchUsersBtn = document.getElementById('search-users-btn');
    const searchResultsContainer = document.getElementById('search-results-container');
    
    searchUsersBtn.addEventListener('click', function() {
        const searchTerm = userSearchInput.value.trim();
        
        if (searchTerm === '') {
            alert('Please enter a search term');
            return;
        }
        
        fetch(`search_users.php?term=${encodeURIComponent(searchTerm)}`)
            .then(response => response.json())
            .then(data => {
                searchResultsContainer.innerHTML = '';
                
                if (data.users && data.users.length > 0) {
                    data.users.forEach(user => {
                        const userCard = document.createElement('div');
                        userCard.className = 'connection-card';
                        
                        // Create avatar
                        const avatarDiv = document.createElement('div');
                        avatarDiv.className = 'connection-avatar';
                        
                        if (user.profile_image) {
                            const img = document.createElement('img');
                            img.src = '../../' + user.profile_image;
                            img.alt = 'Profile Photo';
                            avatarDiv.appendChild(img);
                        } else {
                            const defaultAvatar = document.createElement('div');
                            defaultAvatar.className = 'default-avatar';
                            defaultAvatar.textContent = user.first_name.charAt(0);
                            avatarDiv.appendChild(defaultAvatar);
                        }
                        
                        // Create info
                        const infoDiv = document.createElement('div');
                        infoDiv.className = 'connection-info';
                        
                        const nameHeading = document.createElement('h3');
                        const nameLink = document.createElement('a');
                        nameLink.href = `others_profile.php?id=${user.user_id}`;
                        nameLink.textContent = `${user.first_name} ${user.last_name}`;
                        nameHeading.appendChild(nameLink);
                        
                        const roleP = document.createElement('p');
                        roleP.className = 'connection-role';
                        roleP.textContent = user.bio ? (user.bio.length > 50 ? user.bio.substring(0, 50) + '...' : user.bio) : 'TaskMaster User';
                        
                        const mutualP = document.createElement('p');
                        mutualP.className = 'mutual-connections';
                        mutualP.textContent = `# ${user.mutual_connections} mutual connections`;
                        
                        infoDiv.appendChild(nameHeading);
                        infoDiv.appendChild(roleP);
                        infoDiv.appendChild(mutualP);
                        
                        // Create actions
                        const actionsDiv = document.createElement('div');
                        actionsDiv.className = 'connection-actions';
                        
                        const connectBtn = document.createElement('button');
                        
                        // Set button properties based on connection state
                        if (user.connection_state === 'connected') {
                            connectBtn.className = 'btn btn-sm btn-outline-primary';
                            connectBtn.textContent = 'Connected';
                            connectBtn.disabled = true;
                        } else if (user.connection_state === 'pending_sent') {
                            connectBtn.className = 'btn btn-sm btn-outline-secondary';
                            connectBtn.textContent = 'Request Sent';
                            connectBtn.disabled = true;
                        } else if (user.connection_state === 'pending_received') {
                            connectBtn.className = 'btn btn-sm btn-primary';
                            connectBtn.textContent = 'Accept Request';
                            // Would need connection_id to accept
                        } else if (user.connection_state === 'rejected_sent' || user.connection_state === 'rejected_received') {
                            // For previously rejected connections
                            connectBtn.className = 'btn btn-sm btn-outline-primary';
                            connectBtn.textContent = user.connection_text;
                            connectBtn.dataset.userId = user.user_id;
                            
                            // Add special attribute if we have a connection ID to retry
                            if (user.connection_id) {
                                connectBtn.dataset.connectionId = user.connection_id;
                            }
                            
                            // Use retry_request action for previously rejected connections
                            connectBtn.addEventListener('click', function(e) {
                                e.stopPropagation();
                                const userId = this.dataset.userId;
                                const connectionId = this.dataset.connectionId;
                                
                                // If we have a connection ID, use retry_request action
                                const action = connectionId ? 'retry_request' : 'send_request';
                                const payload = connectionId 
                                    ? `action=${action}&user_id=${userId}&connection_id=${connectionId}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
                                    : `action=${action}&user_id=${userId}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`;
                                
                                fetch('handle_connection.php', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/x-www-form-urlencoded',
                                    },
                                    body: payload
                                })
                                .then(response => response.json())
                                .then(data => {
                                    if (data.success) {
                                        // Update button to show pending
                                        this.textContent = 'Request Sent';
                                        this.disabled = true;
                                        this.className = 'btn btn-sm btn-outline-secondary';
                                    } else {
                                        alert('Failed to send connection request: ' + data.message);
                                    }
                                })
                                .catch(error => {
                                    console.error('Error:', error);
                                    alert('An error occurred. Please try again.');
                                });
                            });
                        } else {
                            // Default case - new connection
                            connectBtn.className = 'btn btn-sm btn-primary';
                            connectBtn.textContent = 'Connect';
                            connectBtn.dataset.userId = user.user_id;
                            
                            connectBtn.addEventListener('click', function(e) {
                                e.stopPropagation();
                                const userId = this.dataset.userId;
                                
                                fetch('handle_connection.php', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/x-www-form-urlencoded',
                                    },
                                    body: `action=send_request&user_id=${userId}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
                                })
                                .then(response => response.json())
                                .then(data => {
                                    if (data.success) {
                                        // Update button to show pending
                                        this.textContent = 'Request Sent';
                                        this.disabled = true;
                                        this.className = 'btn btn-sm btn-outline-secondary';
                                    } else {
                                        alert('Failed to send connection request: ' + data.message);
                                    }
                                })
                                .catch(error => {
                                    console.error('Error:', error);
                                    alert('An error occurred. Please try again.');
                                });
                            });
                        }
                        
                        actionsDiv.appendChild(connectBtn);
                        
                        // Append everything to the card
                        userCard.appendChild(avatarDiv);
                        userCard.appendChild(infoDiv);
                        userCard.appendChild(actionsDiv);
                        
                        // Add click event to the card (navigate to profile)
                        userCard.addEventListener('click', function() {
                            window.location.href = `others_profile.php?id=${user.user_id}`;
                        });
                        
                        searchResultsContainer.appendChild(userCard);
                    });
                } else {
                    searchResultsContainer.innerHTML = '<div class="alert alert-info">No users found matching your search.</div>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                searchResultsContainer.innerHTML = '<div class="alert alert-danger">An error occurred. Please try again.</div>';
            });
    });

    // Also allow searching on Enter key
    userSearchInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            searchUsersBtn.click();
        }
    });

    // Open user profile when clicking on connection card
    connectionCards.forEach(card => {
        card.addEventListener('click', function(e) {
            // Only navigate if we didn't click on a button
            if (!e.target.closest('button')) {
                const profileLink = this.querySelector('.connection-info h3 a');
                if (profileLink) {
                    window.location.href = profileLink.href;
                }
            }
        });
    });

    // Logout confirmation
    document.getElementById('logout-btn').addEventListener('click', function(e) {
        e.preventDefault();
        var logoutModal = new bootstrap.Modal(document.getElementById('logoutModal'));
        logoutModal.show();
    });
});
</script>

<?php include '../../includes/footer.php'; ?> 