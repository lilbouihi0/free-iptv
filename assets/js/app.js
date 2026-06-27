/**
 * XC_VM Website Integration JavaScript
 * Manages the frontend UI states, modals, forms, and AJAX requests.
 */

// Base API URL
const API_URL = '/api/ajax.php';

// Active session state
let currentUser = null;
let statusInterval = null;

/**
 * Switch page visibility (Marketing view vs Client Portal)
 */
function showView(viewName) {
    const marketingView = document.getElementById('marketing-view');
    const portalView = document.getElementById('portal-view');
    const headerNav = document.getElementById('header-nav');
    
    if (viewName === 'portal') {
        if (marketingView) marketingView.style.display = 'none';
        if (portalView) portalView.style.display = 'flex';
        // Hide standard marketing nav links in header if in portal mode
        document.querySelectorAll('.marketing-nav-link').forEach(el => el.style.display = 'none');
        document.getElementById('portal-nav-link').style.display = 'inline-block';
        document.getElementById('header-auth-buttons').style.display = 'none';
        document.getElementById('header-user-badge').style.display = 'flex';
        document.getElementById('header-user-username').textContent = currentUser;
        const portalUsername = document.getElementById('portal-username');
        if (portalUsername) portalUsername.textContent = currentUser;
        
        // Start system stats monitoring
        fetchSystemStatus();
        if (statusInterval) clearInterval(statusInterval);
        statusInterval = setInterval(fetchSystemStatus, 15000); // refresh every 15s
    } else {
        if (marketingView) marketingView.style.display = 'block';
        if (portalView) portalView.style.display = 'none';
        
        document.querySelectorAll('.marketing-nav-link').forEach(el => el.style.display = 'inline-block');
        document.getElementById('portal-nav-link').style.display = 'none';
        document.getElementById('header-auth-buttons').style.display = 'flex';
        document.getElementById('header-user-badge').style.display = 'none';
        
        if (statusInterval) {
            clearInterval(statusInterval);
            statusInterval = null;
        }
    }
}

/**
 * Modal Handling
 */
function openModal(modalId) {
    if (modalId === 'trial') {
        window.location.href = 'signup.html';
        return;
    }
    const modal = document.getElementById(`modal-${modalId}`);
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(`modal-${modalId}`);
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = 'initial';
    }
}

// Close modals when clicking overlay background
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                overlay.classList.remove('active');
                document.body.style.overflow = 'initial';
            }
        });
    });
});

/**
 * Generic API Caller
 */
async function callApi(action, data = {}) {
    const formData = new FormData();
    formData.append('action', action);
    
    for (const [key, value] of Object.entries(data)) {
        formData.append(key, value);
    }
    
    try {
        const response = await fetch(API_URL, {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        return await response.json();
    } catch (error) {
        console.error('API Call Exception:', error);
        return { success: false, message: 'Connection error: ' + error.message };
    }
}

/**
 * Activate Free Trial
 */
async function activateTrial() {
    const usernameInput = document.querySelector('#modal-trial input[name="username"]');
    const emailInput = document.querySelector('#modal-trial input[name="email"]');
    const submitBtn = document.querySelector('#modal-trial button[type="button"]');
    
    const username = usernameInput ? usernameInput.value.trim() : '';
    const email = emailInput ? emailInput.value.trim() : '';
    
    if (!username || !email) {
        alert('Please fill in all fields.');
        return;
    }
    
    // Disable inputs while processing
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Activating...';
    }
    
    const result = await callApi('trial', { username, email });
    
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Activate Trial';
    }
    
    if (result.success) {
        alert(`🎉 ${result.message}\n\nCredentials:\nUsername: ${result.data.username}\nPassword: ${result.data.password}\n\nUse these to log into your dashboard portal.`);
        
        // Auto fill and open login modal
        closeModal('trial');
        
        const loginUsernameInput = document.querySelector('#modal-login input[name="username"]');
        const loginPasswordInput = document.querySelector('#modal-login input[name="password"]');
        if (loginUsernameInput) loginUsernameInput.value = result.data.username;
        if (loginPasswordInput) loginPasswordInput.value = result.data.password;
        
        openModal('login');
    } else {
        alert('❌ Activation Failed: ' + result.message);
    }
}

/**
 * Set target package in registration modal when choosing plan from pricing cards
 */
function selectPackage(packageName) {
    const packageInput = document.querySelector('#modal-register input[name="package"]');
    if (packageInput) {
        packageInput.value = packageName;
    }
    // Update package text in modal header
    const packageTitle = document.getElementById('register-package-title');
    if (packageTitle) {
        const pkgNames = {
            '1month': 'Premium 1 Month Plan',
            '3month': 'Premium 3 Month Plan',
            '12month': 'Premium 12 Month Plan'
        };
        packageTitle.textContent = pkgNames[packageName] || packageName;
    }
    openModal('register');
}

/**
 * Register New Account
 */
async function doRegister() {
    const usernameInput = document.querySelector('#modal-register input[name="username"]');
    const emailInput = document.querySelector('#modal-register input[name="email"]');
    const passwordInput = document.querySelector('#modal-register input[name="password"]');
    const packageInput = document.querySelector('#modal-register input[name="package"]');
    const submitBtn = document.querySelector('#modal-register button[type="button"]');
    
    const username = usernameInput ? usernameInput.value.trim() : '';
    const email = emailInput ? emailInput.value.trim() : '';
    const password = passwordInput ? passwordInput.value.trim() : '';
    const package = packageInput ? packageInput.value : '1month';
    
    if (!username || !email || !password) {
        alert('Please fill in all fields.');
        return;
    }
    
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Creating Account...';
    }
    
    const result = await callApi('register', { username, email, password, package });
    
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Register & Checkout';
    }
    
    if (result.success) {
        alert(`🎉 Account created!\n\nUsername: ${username}\nPassword: ${password}\n\nClick OK to proceed to checkout mock payment. Once paid, log in using these credentials.`);
        closeModal('register');
        
        // Autologin to dashboard (or mock payment bypass)
        currentUser = username;
        showView('portal');
        updateDashboardInfo(result.data.playlist_url, result.data.expiry, package);
    } else {
        alert('❌ Registration Failed: ' + result.message);
    }
}

/**
 * User Login
 */
async function doLogin() {
    const usernameInput = document.querySelector('#modal-login input[name="username"]');
    const passwordInput = document.querySelector('#modal-login input[name="password"]');
    const submitBtn = document.querySelector('#modal-login button[type="button"]');
    
    const username = usernameInput ? usernameInput.value.trim() : '';
    const password = passwordInput ? passwordInput.value.trim() : '';
    
    if (!username || !password) {
        alert('Please enter your username and password.');
        return;
    }
    
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Logging in...';
    }
    
    const result = await callApi('login', { username, password });
    
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Log In';
    }
    
    if (result.success) {
        currentUser = result.data.username;
        closeModal('login');
        
        // Fetch and load database details automatically
        await checkLoginStatus();
    } else {
        alert('❌ Login Failed: ' + result.message);
    }
}

/**
 * User Logout
 */
async function doLogout() {
    const result = await callApi('logout');
    if (result.success) {
        currentUser = null;
        showView('marketing');
        // Clear login inputs
        const usernameInput = document.querySelector('#modal-login input[name="username"]');
        const passwordInput = document.querySelector('#modal-login input[name="password"]');
        if (usernameInput) usernameInput.value = '';
        if (passwordInput) passwordInput.value = '';
    }
}

/**
 * Fetch and Render XC_VM System Status
 */
async function fetchSystemStatus() {
    try {
        const response = await fetch('/api/ajax.php?action=status');
        const result = await response.json();
        
        if (result.success && result.data) {
            const stats = result.data;
            
            // Update CPU UI
            const cpuVal = document.getElementById('status-cpu-val');
            const cpuBar = document.getElementById('status-cpu-bar');
            if (cpuVal) cpuVal.textContent = `${stats.cpu}%`;
            if (cpuBar) cpuBar.style.width = `${stats.cpu}%`;
            
            // Update RAM UI
            const ramVal = document.getElementById('status-ram-val');
            const ramBar = document.getElementById('status-ram-bar');
            if (ramVal) ramVal.textContent = `${stats.memory_used}%`;
            if (ramBar) ramBar.style.width = `${stats.memory_used}%`;
            
            // Update Uptime UI
            const uptimeVal = document.getElementById('status-uptime-val');
            if (uptimeVal) uptimeVal.textContent = stats.uptime;
            
            // Update Disk UI
            const diskVal = document.getElementById('status-disk-val');
            const diskBar = document.getElementById('status-disk-bar');
            if (stats.disk && !stats.disk.error) {
                const disk = stats.disk;
                if (diskVal) diskVal.textContent = `${disk.percent || '0%'}`;
                if (diskBar) diskBar.style.width = `${disk.percent || '0%'}`;
            } else {
                if (diskVal) diskVal.textContent = 'N/A';
                if (diskBar) diskBar.style.width = '0%';
            }
        }
    } catch (e) {
        console.error('Error fetching system stats:', e);
    }
}

/**
 * Update HTML Dashboard Fields
 */
function updateDashboardInfo(playlistUrl, expiry, package, xtreamHost, xtreamUser, xtreamPass) {
    const dashboardPlaylist = document.getElementById('dashboard-playlist-url');
    const dashboardExpiry = document.getElementById('dashboard-expiry');
    const dashboardPackage = document.getElementById('dashboard-package');
    const dashboardAvatar = document.getElementById('dashboard-avatar-initial');
    const dashboardHost = document.getElementById('dashboard-xtream-host');
    const dashboardUser = document.getElementById('dashboard-xtream-user');
    const dashboardPass = document.getElementById('dashboard-xtream-pass');
    
    if (dashboardPlaylist) dashboardPlaylist.value = playlistUrl || '';
    if (dashboardExpiry) dashboardExpiry.textContent = expiry || 'Never';
    if (dashboardPackage) dashboardPackage.textContent = (package || 'TRIAL').toUpperCase();
    
    if (dashboardHost) dashboardHost.textContent = xtreamHost || 'http://178.18.248.202:80';
    if (dashboardUser) dashboardUser.textContent = xtreamUser || currentUser || '';
    if (dashboardPass) dashboardPass.textContent = xtreamPass || '••••••';
    
    if (dashboardAvatar && currentUser) {
        dashboardAvatar.textContent = currentUser.charAt(0).toUpperCase();
        const headerAvatar = document.getElementById('header-avatar-initial');
        if (headerAvatar) headerAvatar.textContent = currentUser.charAt(0).toUpperCase();
    }
}

/**
 * Copy Playlist URL to Clipboard Utility
 */
function copyPlaylistUrl() {
    const playlistInput = document.getElementById('dashboard-playlist-url');
    const copyBtn = document.getElementById('playlist-copy-btn');
    
    if (playlistInput) {
        playlistInput.select();
        playlistInput.setSelectionRange(0, 99999); // For mobile devices
        
        navigator.clipboard.writeText(playlistInput.value).then(() => {
            if (copyBtn) {
                const originalText = copyBtn.innerHTML;
                copyBtn.innerHTML = '<i class="ti ti-check"></i> Copied!';
                copyBtn.style.backgroundColor = 'var(--accent-emerald)';
                copyBtn.style.color = '#ffffff';
                
                setTimeout(() => {
                    copyBtn.innerHTML = originalText;
                    copyBtn.style.backgroundColor = '';
                    copyBtn.style.color = '';
                }, 2000);
            }
        }).catch(err => {
            console.error('Failed to copy text: ', err);
            alert('Failed to copy to clipboard. Please copy manually.');
        });
    }
}

/**
 * Check Login Session status on Page Load
 */
async function checkLoginStatus() {
    try {
        const response = await fetch('/api/check_session.php');
        const data = await response.json();
        
        if (data.logged_in) {
            currentUser = data.username;
            
            if (data.data) {
                const details = data.data;
                updateDashboardInfo(
                    details.playlist_url,
                    details.expiry,
                    details.package,
                    details.xtream_server_url,
                    details.xtream_username,
                    details.xtream_password
                );
            } else {
                // Fallback for default session info (e.g. legacy direct logins)
                const mockPlaylistUrl = `http://178.18.248.202:80/playlist/${currentUser}/******/m3u_plus?output=hls`;
                updateDashboardInfo(mockPlaylistUrl, 'Active', 'Standard', 'http://178.18.248.202:80', currentUser, '******');
            }
            showView('portal');
        } else {
            showView('marketing');
        }
    } catch (e) {
        console.error('Session check failed:', e);
        showView('marketing');
    }
}

// Bind functions to window scope for easy trigger from HTML template
window.openModal = openModal;
window.closeModal = closeModal;
window.activateTrial = activateTrial;
window.selectPackage = selectPackage;
window.doRegister = doRegister;
window.doLogin = doLogin;
window.doLogout = doLogout;
window.copyPlaylistUrl = copyPlaylistUrl;

// Initialize on DOM load
document.addEventListener('DOMContentLoaded', () => {
    checkLoginStatus();
    if (localStorage.getItem('open_login_modal') === 'true') {
        localStorage.removeItem('open_login_modal');
        openModal('login');
    }
});
