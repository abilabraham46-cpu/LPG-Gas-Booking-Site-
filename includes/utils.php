<?php
/**
 * Utility Functions for LIAA LPG Gas Booking System
 * Common helper functions for validation, security, and operations
 */

/**
 * Generate random string
 * @param int $length
 * @return string
 */
function generateRandomString($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $randomString = '';
    
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, strlen($characters) - 1)];
    }
    
    return $randomString;
}

/**
 * Validate required fields
 * @param array $data
 * @param array $required
 * @return array
 */
function validateRequiredFields($data, $required) {
    $errors = [];
    
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty(trim($data[$field]))) {
            $errors[] = ucfirst($field) . " is required";
        }
    }
    
    return $errors;
}

/**
 * Format date
 * @param string $date
 * @param string $format
 * @return string
 */
function formatDate($date, $format = 'd M Y') {
    return date($format, strtotime($date));
}

/**
 * Format datetime
 * @param string $datetime
 * @param string $format
 * @return string
 */
function formatDateTime($datetime, $format = 'd M Y H:i') {
    return date($format, strtotime($datetime));
}

/**
 * Get time ago
 * @param string $datetime
 * @return string
 */
function timeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 2592000) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        $months = floor($diff / 2592000);
        return $months . ' month' . ($months > 1 ? 's' : '') . ' ago';
    }
}

/**
 * Generate order number
 * @return string
 */
function generateOrderNumber() {
    $prefix = 'LPG';
    $timestamp = time();
    $random = rand(1000, 9999);
    
    return $prefix . $timestamp . $random;
}

/**
 * Generate tracking number
 * @return string
 */
function generateTrackingNumber() {
    $prefix = 'TRK';
    $timestamp = time();
    $random = rand(100, 999);
    
    return $prefix . $timestamp . $random;
}

/**
 * Calculate delivery distance (simplified)
 * @param float $lat1
 * @param float $lon1
 * @param float $lat2
 * @param float $lon2
 * @return float
 */
function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    // Haversine formula for calculating distance between two points
    $earthRadius = 6371; // Earth's radius in kilometers
    
    $latDelta = deg2rad($lat2 - $lat1);
    $lonDelta = deg2rad($lon2 - $lon1);
    
    $a = sin($latDelta / 2) * sin($latDelta / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($lonDelta / 2) * sin($lonDelta / 2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    
    return $earthRadius * $c;
}

/**
 * Calculate delivery fee based on distance
 * @param float $distance
 * @param float $baseFee
 * @return float
 */
function calculateDeliveryFee($distance, $baseFee = 500) {
    if ($distance <= 5) {
        return $baseFee;
    } elseif ($distance <= 10) {
        return $baseFee + 200;
    } elseif ($distance <= 20) {
        return $baseFee + 400;
    } else {
        return $baseFee + 600;
    }
}

/**
 * Send SMS notification (placeholder)
 * @param string $phone
 * @param string $message
 * @return bool
 */
function sendSMS($phone, $message) {
    // This is a placeholder function
    // In a real implementation, you would integrate with an SMS service provider
    // like Twilio, Africa's Talking, etc.
    
    error_log("SMS to $phone: $message");
    return true;
}

/**
 * Send email notification (placeholder)
 * @param string $email
 * @param string $subject
 * @param string $message
 * @return bool
 */
function sendEmail($email, $subject, $message) {
    // This is a placeholder function
    // In a real implementation, you would use PHPMailer or similar library
    
    error_log("Email to $email - Subject: $subject - Message: $message");
    return true;
}

/**
 * Log activity
 * @param string $action
 * @param string $description
 * @param int $userId
 * @param string $userRole
 * @return bool
 */
function logActivity($action, $description, $userId = null, $userRole = null) {
    try {
        $sql = "INSERT INTO activity_logs (action, description, user_id, user_role, ip_address, user_agent, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())";
        
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        return insertData($sql, [$action, $description, $userId, $userRole, $ipAddress, $userAgent]) > 0;
    } catch (Exception $e) {
        error_log("Error logging activity: " . $e->getMessage());
        return false;
    }
}

/**
 * Get client IP address
 * @return string
 */
function getClientIP() {
    $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
    
    foreach ($ipKeys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/**
 * Check if request is AJAX
 * @return bool
 */
function isAjaxRequest() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
}

/**
 * Return JSON response
 * @param array $data
 * @param int $statusCode
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Return success response
 * @param string $message
 * @param array $data
 */
function successResponse($message = 'Success', $data = []) {
    jsonResponse([
        'success' => true,
        'message' => $message,
        'data' => $data
    ]);
}

/**
 * Return error response
 * @param string $message
 * @param array $errors
 * @param int $statusCode
 */
function errorResponse($message = 'Error occurred', $errors = [], $statusCode = 400) {
    jsonResponse([
        'success' => false,
        'message' => $message,
        'errors' => $errors
    ], $statusCode);
}

/**
 * Validate file upload
 * @param array $file
 * @param array $allowedTypes
 * @param int $maxSize
 * @return array
 */
function validateFileUpload($file, $allowedTypes = ['jpg', 'jpeg', 'png', 'pdf'], $maxSize = 5242880) {
    $errors = [];
    
    if (!isset($file['error']) || is_array($file['error'])) {
        $errors[] = 'Invalid file parameter';
        return $errors;
    }
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        switch ($file['error']) {
            case UPLOAD_ERR_INI_SIZE:
                $errors[] = 'File exceeds upload_max_filesize directive';
                break;
            case UPLOAD_ERR_FORM_SIZE:
                $errors[] = 'File exceeds MAX_FILE_SIZE directive';
                break;
            case UPLOAD_ERR_PARTIAL:
                $errors[] = 'File was only partially uploaded';
                break;
            case UPLOAD_ERR_NO_FILE:
                $errors[] = 'No file was uploaded';
                break;
            default:
                $errors[] = 'Unknown upload error';
        }
        return $errors;
    }
    
    if ($file['size'] > $maxSize) {
        $errors[] = 'File size exceeds maximum allowed size';
    }
    
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($fileExtension, $allowedTypes)) {
        $errors[] = 'File type not allowed. Allowed types: ' . implode(', ', $allowedTypes);
    }
    
    return $errors;
}

/**
 * Upload file
 * @param array $file
 * @param string $destination
 * @param string $filename
 * @return string|false
 */
function uploadFile($file, $destination, $filename = null) {
    if (!is_dir($destination)) {
        mkdir($destination, 0755, true);
    }
    
    if (!$filename) {
        $filename = uniqid() . '_' . basename($file['name']);
    }
    
    $filepath = $destination . '/' . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return $filepath;
    }
    
    return false;
}

/**
 * Delete file
 * @param string $filepath
 * @return bool
 */
function deleteFile($filepath) {
    if (file_exists($filepath)) {
        return unlink($filepath);
    }
    return false;
}

/**
 * Generate pagination links
 * @param int $currentPage
 * @param int $totalPages
 * @param string $baseUrl
 * @return string
 */
function generatePagination($currentPage, $totalPages, $baseUrl) {
    if ($totalPages <= 1) {
        return '';
    }
    
    $html = '<nav aria-label="Page navigation"><ul class="pagination justify-content-center">';
    
    // Previous button
    if ($currentPage > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '?page=' . ($currentPage - 1) . '">Previous</a></li>';
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link">Previous</span></li>';
    }
    
    // Page numbers
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);
    
    for ($i = $start; $i <= $end; $i++) {
        if ($i == $currentPage) {
            $html .= '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
        } else {
            $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '?page=' . $i . '">' . $i . '</a></li>';
        }
    }
    
    // Next button
    if ($currentPage < $totalPages) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '?page=' . ($currentPage + 1) . '">Next</a></li>';
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link">Next</span></li>';
    }
    
    $html .= '</ul></nav>';
    
    return $html;
}

/**
 * Escape HTML output
 * @param string $string
 * @return string
 */
function e($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Check if string contains substring
 * @param string $haystack
 * @param string $needle
 * @return bool
 */
function contains($haystack, $needle) {
    return strpos($haystack, $needle) !== false;
}

/**
 * Convert string to title case
 * @param string $string
 * @return string
 */
function titleCase($string) {
    return ucwords(strtolower($string));
}

/**
 * Format phone number
 * @param string $phone
 * @return string
 */
function formatPhone($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    if (strlen($phone) == 11 && substr($phone, 0, 1) == '0') {
        return '+234' . substr($phone, 1);
    } elseif (strlen($phone) == 10) {
        return '+234' . $phone;
    }
    
    return $phone;
}

/**
 * Mask sensitive data
 * @param string $data
 * @param string $type
 * @return string
 */
function maskData($data, $type = 'phone') {
    switch ($type) {
        case 'phone':
            if (strlen($data) >= 11) {
                return substr($data, 0, 4) . '****' . substr($data, -3);
            }
            return $data;
        case 'email':
            $parts = explode('@', $data);
            if (count($parts) == 2) {
                $username = $parts[0];
                $domain = $parts[1];
                if (strlen($username) > 2) {
                    $maskedUsername = substr($username, 0, 2) . str_repeat('*', strlen($username) - 2);
                } else {
                    $maskedUsername = $username;
                }
                return $maskedUsername . '@' . $domain;
            }
            return $data;
        default:
            return $data;
    }
}
?>
