<?php
/**
 * Flash Message Helper
 * 
 * Provides functions to set and display flash messages that persist across page redirects.
 */

/**
 * Set a flash message
 * 
 * @param string $message The message to display
 * @param string $type The type of message (success, error, warning, info)
 * @return void
 */
function set_flash_message($message, $type = 'info') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Initialize messages array if it doesn't exist
    if (!isset($_SESSION['flash_messages'])) {
        $_SESSION['flash_messages'] = [];
    }
    
    // Add the message
    $_SESSION['flash_messages'][] = [
        'message' => $message,
        'type' => $type,
        'timestamp' => time()
    ];
    
    // Limit the number of stored messages to prevent session bloat
    if (count($_SESSION['flash_messages']) > 5) {
        array_shift($_SESSION['flash_messages']);
    }
}

/**
 * Display flash messages and clear them from the session
 * 
 * @param bool $clear_messages Whether to clear messages after displaying them
 * @return string HTML for the flash messages
 */
function display_flash_messages($clear_messages = true) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $output = '';
    
    if (!empty($_SESSION['flash_messages'])) {
        $output .= '<div class="flash-messages">';
        
        foreach ($_SESSION['flash_messages'] as $index => $flash) {
            $alert_class = 'alert-' . $flash['type'];
            $timestamp = date('H:i:s', $flash['timestamp']);
            
            $output .= sprintf(
                '<div class="alert %s alert-dismissible fade show" role="alert">
                    <strong>%s</strong> %s
                    <small class="text-muted ms-2">%s</small>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>',
                htmlspecialchars($alert_class),
                ucfirst(htmlspecialchars($flash['type'])) . '!',
                htmlspecialchars($flash['message']),
                htmlspecialchars($timestamp)
            );
        }
        
        $output .= '</div>';
        
        // Clear messages if requested
        if ($clear_messages) {
            unset($_SESSION['flash_messages']);
        }
    }
    
    return $output;
}

/**
 * Check if there are any flash messages
 * 
 * @return bool True if there are messages, false otherwise
 */
function has_flash_messages() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    return !empty($_SESSION['flash_messages']);
}

/**
 * Get the appropriate Bootstrap icon for a message type
 * 
 * @param string $type Message type
 * @return string Icon HTML
 */
function get_alert_icon($type) {
    $icons = [
        'success' => 'check-circle',
        'error' => 'exclamation-triangle',
        'warning' => 'exclamation-triangle',
        'info' => 'info-circle',
        'primary' => 'info-circle',
        'secondary' => 'info-circle',
        'light' => 'info-circle',
        'dark' => 'info-circle'
    ];
    
    $icon = $icons[$type] ?? 'info-circle';
    return '<i class="fas fa-' . $icon . ' me-2"></i>';
}

// Auto-display flash messages at the top of the page if they exist
function auto_display_flash_messages() {
    if (has_flash_messages()) {
        echo display_flash_messages();
    }
}

// Register auto-display function to run at the end of the output buffer
function register_flash_messages() {
    ob_start(function($buffer) {
        // Only inject if it's an HTML page
        if (stripos($buffer, '<!DOCTYPE html>') !== false || 
            stripos($buffer, '<html') !== false || 
            stripos($buffer, '</body>') !== false) {
                
            // Insert before closing body tag if it exists
            if (stripos($buffer, '</body>') !== false) {
                $messages = display_flash_messages();
                return str_ireplace('</body>', $messages . '\n</body>', $buffer);
            }
            
            // Or at the beginning of the body if no body close tag is found
            if (preg_match('/<body[^>]*>/i', $buffer, $matches, PREG_OFFSET_CAPTURE)) {
                $pos = $matches[0][1] + strlen($matches[0][0]);
                return substr($buffer, 0, $pos) . '\n' . display_flash_messages() . substr($buffer, $pos);
            }
        }
        
        return $buffer;
    });
}

// Initialize flash messages if not already done
if (!function_exists('flash_init_done')) {
    function flash_init_done() {
        return true;
    }
    
    // Auto-start session if needed
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Register auto-display
    if (!defined('NO_AUTO_FLASH') || !NO_AUTO_FLASH) {
        register_flash_messages();
    }
}
?>
