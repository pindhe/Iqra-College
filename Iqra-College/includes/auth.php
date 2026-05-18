<?php
/**
 * Authentication Functions
 * Session management and user authentication
 */

session_start();

/**
 * Check if user is logged in
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_role']);
}

/**
 * Check if user has specific role
 * @param string $role
 * @return bool
 */
function hasRole($role) {
    return isLoggedIn() && $_SESSION['user_role'] === $role;
}

/**
 * Check if user account is active
 * @return bool
 */
function isUserActive() {
    if (!isLoggedIn()) {
        return false;
    }
    
    try {
        require_once __DIR__ . '/../config/database.php';
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ?");
        $stmt->execute([getCurrentUserId()]);
        $user = $stmt->fetch();
        return $user && ($user['status'] ?? 'active') === 'active';
    } catch (Exception $e) {
        // If status column doesn't exist, assume active
        return true;
    }
}

/**
 * Require login - redirect if not logged in or account is inactive
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /Iqra-College/auth/login.php');
        exit();
    }
    
    // Check if user account is active
    if (!isUserActive()) {
        session_unset();
        session_destroy();
        header('Location: /Iqra-College/auth/login.php?error=account_disabled');
        exit();
    }
}

/**
 * Require specific role - redirect if user doesn't have role
 * @param string $role
 */
function requireRole($role) {
    requireLogin();
    if (!hasRole($role)) {
        header('Location: /Iqra-College/index.php');
        exit();
    }
}

/**
 * Get current user ID
 * @return int|null
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user role
 * @return string|null
 */
function getCurrentUserRole() {
    return $_SESSION['user_role'] ?? null;
}

/**
 * Get current user name
 * @return string|null
 */
function getCurrentUserName() {
    return $_SESSION['user_name'] ?? null;
}

/**
 * Logout user
 */
function logout() {
    session_unset();
    session_destroy();
    header('Location: /Iqra-College/auth/login.php');
    exit();
}
