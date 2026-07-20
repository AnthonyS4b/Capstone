<?php
/**
 * ajax/upload_product_image.php
 * Handles product image uploads via multipart/form-data.
 *
 * Accepts:  POST  file='image'  (jpg|jpeg|png|webp|gif, max 2 MB)
 * Returns:  JSON  { success, image_path, message }
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error.log');

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Auth check
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Validate request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
    echo json_encode(['success' => false, 'message' => 'No image file provided']);
    exit;
}

$file = $_FILES['image'];

// Check for upload errors
if ($file['error'] !== UPLOAD_ERR_OK) {
    $errors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server max upload size',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form max size',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder on server',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION  => 'Upload blocked by a PHP extension',
    ];
    $msg = $errors[$file['error']] ?? 'Unknown upload error';
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

// Validate file type
$allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);

if (!in_array($mimeType, $allowedMimes)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: JPG, PNG, WebP, GIF']);
    exit;
}

// Validate file size (2 MB max)
$maxSize = 2 * 1024 * 1024;
if ($file['size'] > $maxSize) {
    echo json_encode(['success' => false, 'message' => 'File too large. Maximum size is 2 MB']);
    exit;
}

// Validate extension
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
if (!in_array($ext, $allowedExts)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file extension']);
    exit;
}

// Build upload path
$uploadDir = __DIR__ . '/../assets/uploads/products/';

// Create directory if it doesn't exist
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Generate unique filename
$uniqueName = 'prod_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$destPath   = $uploadDir . $uniqueName;

// Move uploaded file
if (move_uploaded_file($file['tmp_name'], $destPath)) {
    // Return relative path (from project root)
    $relativePath = 'assets/uploads/products/' . $uniqueName;
    echo json_encode([
        'success'    => true,
        'image_path' => $relativePath,
        'message'    => 'Image uploaded successfully'
    ]);
} else {
    error_log("Failed to move uploaded file to: " . $destPath);
    echo json_encode(['success' => false, 'message' => 'Failed to save image file']);
}
