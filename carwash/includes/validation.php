<?php
// Common validation functions

function validateNumberPlate($plate) {
    $plate = strtoupper(trim($plate));
    // Basic validation: alphanumeric, 3-8 characters
    if (preg_match('/^[A-Z0-9]{3,8}$/', $plate)) {
        return $plate;
    }
    return false;
}

function validateAmount($amount) {
    $amount = floatval($amount);
    return ($amount > 0 && $amount <= 99999.99) ? $amount : false;
}

function validateRequired($value, $fieldName) {
    if (empty(trim($value))) {
        return "Please enter $fieldName";
    }
    return true;
}

function validateSelect($value, $fieldName) {
    if (empty($value) || $value <= 0) {
        return "Please select $fieldName";
    }
    return true;
}

function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}
?>
